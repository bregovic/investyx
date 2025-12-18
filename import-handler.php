<?php
// /broker/import-handler.php
// Generic receiver for normalized transactions coming from JS importers.
// - Resolves user_id from session robustly
// - Deduplicates (fingerprint if available, else tolerant fallback)
// - Resolves FX rate from broker_exrates (fallback: CNB XML for the day)
// - Computes amount_czk if missing
// - Validates IDs (Crypto: musí obsahovat alespoň jedno písmeno; povolí 1INCH apod.)
// - Normalizes SELL amounts to positive
// - Returns counts and messages as JSON

header('Content-Type: application/json; charset=utf-8');
session_start();

/* ===================== User ===================== */
function resolveUserIdFromSession() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return [(int)$_SESSION[$k], $k];
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) {
            foreach (['user_id','id','uid','userid'] as $k) {
                if (isset($u[$k]) && is_numeric($u[$k]) && (int)$u[$k] > 0) return [(int)$u[$k], 'user['.$k.']'];
            }
        } elseif (is_object($u)) {
            foreach (['user_id','id','uid','userid'] as $k) {
                if (isset($u->$k) && is_numeric($u->$k) && (int)$u->$k > 0) return [(int)$u->$k, 'user->'.$k];
            }
        }
    }
    return [null, null];
}
list($currentUserId, $userKey) = resolveUserIdFromSession();
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Nelze určit ID uživatele ze session.']);
    exit;
}

/* ===================== DB ===================== */
try {
    $paths = [__DIR__.'/../env.local.php', __DIR__.'/env.local.php', __DIR__.'/php/env.local.php','../env.local.php','php/env.local.php','../php/env.local.php'];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; break; } }
    if (!defined('DB_HOST')) throw new Exception('DB config nenalezen');
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Chyba DB: '.$e->getMessage()]);
    exit;
}

/* ===================== Detect fingerprint column ===================== */
$hasFingerprint = false;
try {
    $res = $pdo->query("SHOW COLUMNS FROM broker_trans LIKE 'fingerprint'");
    $hasFingerprint = (bool)$res->fetch();
} catch (Exception $e) {
    $hasFingerprint = false;
}

/* ===================== Helpers: rounding, canon, fingerprint ===================== */
function roundTo($n, $d) { return $n === null ? null : round((float)$n, $d); }

function canon(array $r): array {
    $cur = strtoupper($r['currency'] ?? '');
    $pt  = strtolower($r['product_type'] ?? '');
    $qtyDec   = $pt === 'crypto' ? 8 : 4;
    $priceDec = $pt === 'crypto' ? 8 : 6;
    $amtDec   = $cur === 'JPY' ? 0 : 2;

    return [
        'date'       => substr($r['date'] ?? '', 0, 10),
        'platform'   => strtolower($r['platform'] ?? ''),
        'product'    => $pt,
        'type'       => strtolower($r['trans_type'] ?? ''),
        'id'         => strtoupper($r['id'] ?? ''),
        'currency'   => $cur,
        'amount'     => roundTo(abs($r['amount'] ?? 0), $qtyDec),
        'amount_cur' => roundTo($r['amount_cur'] ?? 0, $amtDec),
        'price'      => array_key_exists('price', $r) ? roundTo($r['price'], $priceDec) : null,
        'fee'        => array_key_exists('fees', $r) ? roundTo($r['fees'], $amtDec) : 0,
    ];
}
function txFingerprint(array $r): string {
    $platform = strtolower($r['platform'] ?? '');
    if (!empty($r['external_id'])) {
        return 'ext:'.$platform.':'.$r['external_id'];
    }
    return hash('sha256', json_encode(canon($r), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

/* ===================== Helpers: rates ===================== */
function getDbRate(PDO $pdo, string $date, string $currency): ?float {
    if (strtoupper($currency) === 'CZK') return 1.0;
    $sql = "SELECT rate, amount FROM broker_exrates WHERE currency=? AND date<=? ORDER BY date DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([strtoupper($currency), $date]);
    $row = $st->fetch();
    if (!$row) return null;
    $rate = (float)$row['rate']; $amount = (float)($row['amount'] ?: 1);
    if ($amount <= 0) $amount = 1;
    return $rate / $amount; // CZK per 1 unit
}

function fetchCnbAndStore(PDO $pdo, string $date): void {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return;
    $cnbDate = $dt->format('d.m.Y');
    $url = "https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.xml?date=".urlencode($cnbDate);
    $xmlStr = @file_get_contents($url);
    if (!$xmlStr) return;
    $xml = @simplexml_load_string($xmlStr);
    if (!$xml || !isset($xml->tabulka->radek)) return;
    $ins = $pdo->prepare("INSERT INTO broker_exrates (date,currency,rate,amount,source)
                          VALUES (?,?,?,?, 'CNB')
                          ON DUPLICATE KEY UPDATE rate=VALUES(rate), amount=VALUES(amount), source='CNB'");
    foreach ($xml->tabulka->radek as $r) {
        $cur = (string)$r['kod'];
        $rate = (float)str_replace(',', '.', (string)$r['kurz']);
        $amt  = (int)$r['mnozstvi'] ?: 1;
        if ($cur && $rate > 0) $ins->execute([$dt->format('Y-m-d'), strtoupper($cur), $rate, $amt]);
    }
}

function resolveRate(PDO $pdo, string $date, string $currency): ?float {
    $currency = strtoupper($currency ?: 'CZK');
    if ($currency === 'CZK') return 1.0;
    $r = getDbRate($pdo, $date, $currency);
    if ($r !== null) return $r;
    fetchCnbAndStore($pdo, $date);
    return getDbRate($pdo, $date, $currency);
}

/**
 * Save or update ticker mapping info
 */
function saveTickerMapping(PDO $pdo, array $txData): void {
    $ticker = strtoupper(trim($txData['id'] ?? ''));
    $currency = strtoupper(trim($txData['currency'] ?? ''));
    $isin = trim($txData['isin'] ?? '');
    $companyName = trim($txData['company_name'] ?? '');
    
    // Skip cash, fees, etc.
    if (empty($ticker) || preg_match('/^(CASH_|FEE_|FX_|CORP_ACTION)/', $ticker)) {
        return;
    }
    
    // Only save if we have ISIN or company name
    if (empty($isin) && empty($companyName)) {
        return;
    }
    
    try {
        $sql = "INSERT INTO broker_ticker_mapping 
                    (ticker, company_name, isin, currency, status, last_verified)
                VALUES 
                    (:ticker, :company, :isin, :currency, 'needs_review', NOW())
                ON DUPLICATE KEY UPDATE
                    company_name = COALESCE(NULLIF(:company, ''), company_name),
                    isin = COALESCE(NULLIF(:isin, ''), isin),
                    currency = COALESCE(NULLIF(:currency, ''), currency),
                    last_verified = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':ticker' => $ticker,
            ':company' => $companyName,
            ':isin' => $isin,
            ':currency' => $currency
        ]);
    } catch (Exception $e) {
        // Silent fail - mapping is optional
        error_log("saveTickerMapping failed for $ticker: " . $e->getMessage());
    }
}

/* ===================== Input ===================== */
$raw = file_get_contents('php://input');
if (!$raw) { echo json_encode(['success'=>false,'error'=>'Prázdné tělo požadavku.']); exit; }
$data = json_decode($raw, true);
if (!$data) { echo json_encode(['success'=>false,'error'=>'Neplatný JSON.']); exit; }

$provider = $data['provider'] ?? 'unknown';
$rows = $data['transactions'] ?? [];
if (!is_array($rows) || empty($rows)) {
    echo json_encode(['success'=>false,'error'=>'Žádné transakce k importu.']); exit;
}

/* ===================== Prepared statements ===================== */
if ($hasFingerprint) {
    $insStmt = $pdo->prepare(
        "INSERT INTO broker_trans
         (user_id, date, id, amount, price, ex_rate, amount_cur, currency, amount_czk, platform, product_type, trans_type, fees, notes, fingerprint)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $dupFpStmt = $pdo->prepare(
        "SELECT trans_id FROM broker_trans WHERE user_id=? AND fingerprint=? LIMIT 1"
    );
} else {
    // Fallback dedupe bez fingerprintu – ošetřuje NULL price a FX drift (amount_cur OR amount_czk)
    $insStmt = $pdo->prepare(
        "INSERT INTO broker_trans
         (user_id, date, id, amount, price, ex_rate, amount_cur, currency, amount_czk, platform, product_type, trans_type, fees, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $dupStmt = $pdo->prepare(
        "SELECT trans_id FROM broker_trans
         WHERE user_id=? AND date=? AND id=? AND ROUND(amount,8)=ROUND(?,8)
           AND ( (price IS NULL AND ? IS NULL) OR ROUND(price,6)=ROUND(?,6) )
           AND trans_type=? AND platform=? AND currency=?
           AND (ROUND(amount_cur,2)=ROUND(?,2) OR ROUND(amount_czk,2)=ROUND(?,2))
         LIMIT 1"
    );
}

/* ===================== Main loop ===================== */
$inserted=0; $skipped=0; $failed=0; $errors=[];
$skipped_dup=0; $skipped_invalidId=0;

foreach ($rows as $i => $r) {
    try {
        $date        = trim($r['date'] ?? '');
        $id          = strtoupper(trim($r['id'] ?? '')); // normalize
        $amount      = (float)($r['amount']      ?? 0);
        $price       = array_key_exists('price',$r) ? ( $r['price'] === null ? null : (float)$r['price'] ) : null;
        $ex_rate     = array_key_exists('ex_rate',$r) ? ( $r['ex_rate'] === null ? null : (float)$r['ex_rate'] ) : null;
        $amount_cur  = (float)($r['amount_cur']  ?? 0);
        $currency    = strtoupper(trim($r['currency'] ?? 'CZK'));
        $amount_czk  = array_key_exists('amount_czk',$r) ? ( $r['amount_czk'] === null ? null : (float)$r['amount_czk'] ) : null;
        $platform    = trim($r['platform']    ?? ($provider ?: ''));
        $product     = trim($r['product_type']?? '');
        $trans_type  = trim($r['trans_type']  ?? '');
        $fees        = array_key_exists('fees',$r) ? ( $r['fees'] === null ? 0.0 : (float)$r['fees'] ) : 0.0;
        $notes       = trim($r['notes']       ?? ('import: '.$provider));
        $external_id = isset($r['external_id']) ? trim((string)$r['external_id']) : '';

        if (!$date) { $failed++; $errors[]="řádek $i: chybí date"; continue; }

        // Skip garbage IDs – Crypto: povolir i číslo na začátku, ale vyžadovat aspoň 1 písmeno (1INCH ok, 300/914 ne)
        $isCrypto  = strcasecmp($product ?? '', 'Crypto') === 0;
        $allowedId = $isCrypto
          ? (bool)preg_match('/^(?=.*[A-Z])[A-Z0-9][A-Z0-9.\-]{0,19}$/', $id)
          : (bool)preg_match('/^([A-Z][A-Z0-9.\-]{0,19}|CASH_[A-Z]{3}|FEE_[A-Z0-9_]+|CORP_ACTION|FX_[A-Z]+)$/', $id);

        if (!$allowedId && !in_array($product, ['Cash','Fee','FX','Tax'], true)) {
            $skipped++; $skipped_invalidId++;
            $errors[]="řádek $i: nepřijaté ID '$id' (skip)";
            continue;
        }

        // Resolve FX/ex_rate
        if ($currency === 'CZK') { $ex_rate = 1.0; }
        if (!$ex_rate || $ex_rate <= 0) {
            $ex_rate = resolveRate($pdo, $date, $currency) ?? ($currency==='CZK'?1.0:null);
        }

        // amount_czk
        if ($amount_czk === null) {
            if ($currency === 'CZK') $amount_czk = $amount_cur;
            elseif ($ex_rate) $amount_czk = round($amount_cur * $ex_rate, 2);
            else $amount_czk = 0.0;
        }

        // SELL má být příjem => kladné částky (směr určuje trans_type)
        if (strcasecmp($trans_type, 'Sell') === 0) {
            if ($amount_cur < 0)  $amount_cur  = abs($amount_cur);
            if ($amount_czk !== null && $amount_czk < 0) $amount_czk = abs($amount_czk);
            if ($price !== null && $price < 0) $price = abs($price);
        }

        // Fingerprint input
        $txForFp = [
            'date' => $date, 'id' => $id, 'amount' => $amount, 'price' => $price,
            'ex_rate' => $ex_rate, 'amount_cur' => $amount_cur, 'currency' => $currency,
            'amount_czk' => $amount_czk, 'platform' => $platform, 'product_type' => $product,
            'trans_type' => $trans_type, 'fees' => $fees, 'notes' => $notes, 'external_id' => $external_id
        ];
        $fingerprint = $hasFingerprint ? txFingerprint($txForFp) : null;

        // duplicate check
        if ($hasFingerprint) {
            $dupFpStmt->execute([$currentUserId, $fingerprint]);
            if ($dupFpStmt->fetchColumn()) { $skipped++; $skipped_dup++; continue; }
        } else {
            $dupStmt->execute([
                $currentUserId,$date,$id,$amount,
                $price,$price,
                $trans_type,$platform,$currency,
                $amount_cur,$amount_czk
            ]);
            if ($dupStmt->fetchColumn()) { $skipped++; $skipped_dup++; continue; }
        }

        // insert
        if ($hasFingerprint) {
            try {
                $insStmt->execute([
                    $currentUserId,$date,$id,$amount,$price,$ex_rate,$amount_cur,$currency,$amount_czk,
                    $platform,$product,$trans_type,$fees,$notes,$fingerprint
                ]);
                $inserted++;
                
                // Save ticker mapping if we have ISIN or company name
                saveTickerMapping($pdo, $r);
            } catch (PDOException $e) {
                if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) { // duplicate key
                    $skipped++; $skipped_dup++;
                    continue;
                }
                throw $e;
            }
        } else {
            $insStmt->execute([
                $currentUserId,$date,$id,$amount,$price,$ex_rate,$amount_cur,$currency,$amount_czk,
                $platform,$product,$trans_type,$fees,$notes
            ]);
            $inserted++;
            
            // Save ticker mapping if we have ISIN or company name
            saveTickerMapping($pdo, $r);
        }
    } catch (Exception $e) {
        $failed++; $errors[] = "řádek $i: ".$e->getMessage();
    }
}

echo json_encode([
    'success'=>true,
    'message'=>"Import dokončen. Vloženo: $inserted, přeskočeno (dup/skip): $skipped, chyb: $failed",
    'inserted'=>$inserted,
    'skipped'=>$skipped,
    'failed'=>$failed,
    'errors'=>$errors,
    'user_id'=>$currentUserId,
    'provider'=>$provider,
    'dedupe_mode'=>$hasFingerprint ? 'fingerprint' : 'fallback',
    'skipped_reasons' => [
        'duplicate'  => $skipped_dup,
        'invalid_id' => $skipped_invalidId,
        'other'      => max(0, $skipped - $skipped_dup - $skipped_invalidId),
    ],
]);