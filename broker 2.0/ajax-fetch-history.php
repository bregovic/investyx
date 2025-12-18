<?php
// ajax-fetch-history.php
// Robustní verze 3.0 (Restored & Enhanced)
// Stahuje Historii (v8/chart) + Fundamenty (v7/quote)
// Počítá EMA a aktualizuje statistiky

ini_set('display_errors', 0); // Vypneme output do browseru (JSON only)
// ini_set('log_errors', 1); ini_set('error_log', 'fetch_debug.log'); // Debug

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Konfigurace a DB
    $envPaths = [
        __DIR__ . '/env.local.php',
        __DIR__ . '/../env.local.php',
        $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
        __DIR__ . '/../../env.local.php',
        __DIR__ . '/php/env.local.php',
        __DIR__ . '/env.php',
        __DIR__ . '/../env.php',
        __DIR__ . '/../../env.php',
        $_SERVER['DOCUMENT_ROOT'] . '/env.php'
    ];
    foreach ($envPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
    
    if (!defined('DB_HOST')) {
        if (file_exists(__DIR__ . '/db.php')) require_once __DIR__ . '/db.php';
        else if (file_exists(__DIR__ . '/php/db.php')) require_once __DIR__ . '/php/db.php';
    }

    if (!defined('DB_HOST')) throw new Exception("DB Config not found");

    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 2. Vstupy
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);
    $action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '');
    $ticker = $_GET['ticker'] ?? $_POST['ticker'] ?? ($input['ticker'] ?? '');
    $period = $_GET['period'] ?? $_POST['period'] ?? ($input['period'] ?? '1y');

    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id FROM live_quotes WHERE status='active' ORDER BY id");
        echo json_encode(['success' => true, 'tickers' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        exit;
    }

    if (empty($ticker)) throw new Exception("No Ticker specified");
    if ($ticker === 'ALL' && empty($action)) $ticker = 'ALL'; // Handle logic below

    // --- HELPERY ---
    function mapTickerToYahoo($t) {
        $t = strtoupper(trim($t));
        if (strpos($t, ':') !== false) { $parts = explode(':', $t); $t = $parts[1] ?? $parts[0]; }
        
        // Yahoo special cases
        if ($t === 'BRK.B') return 'BRK-B';
        if ($t === 'BTC') return 'BTC-USD'; 
        if ($t === 'ETH') return 'ETH-USD';

        // ETF Mapping for Yahoo Finance
        // Mapping based on common European listings (Xetra/London)
        $etfMap = [
            'ZPRV' => 'ZPRV.DE', // SPDR MSCI USA Small Cap Value Weighted UCITS ETF
            'CNDX' => 'CNDX.L',  // iShares NASDAQ 100 UCITS ETF (Acc)
            'CSPX' => 'CSPX.L',  // iShares Core S&P 500 UCITS ETF
            'IWVL' => 'IWVL.L',  // iShares Edge MSCI World Value Factor
            'VWRA' => 'VWRA.L',  // Vanguard FTSE All-World UCITS ETF
            'EQQQ' => 'EQQQ.DE', // Invesco EQQQ NASDAQ-100 UCITS ETF
            'EUNL' => 'EUNL.DE', // iShares Core MSCI World UCITS ETF
            'IS3N' => 'IS3N.DE', // iShares Core MSCI EM IMI UCITS ETF
            'SXR8' => 'SXR8.DE', // iShares Core S&P 500 UCITS ETF (Acc)
            'RBOT' => 'RBOT.L',  // iShares Automation & Robotics
            'RENW' => 'RENW.L'   // iShares Global Clean Energy
        ];

        if (isset($etfMap[$t])) return $etfMap[$t];
        
        // Prague Stock Exchange
        $czStocks = ['CEZ', 'KB', 'MONET', 'ERBAG', 'KOMB', 'PHILIP', 'COLT', 'KOFOL'];
        if (in_array($t, $czStocks)) return $t . '.PR';
        
        return $t;
    }

    function calcEMA($values, $period) {
        $count = count($values);
        if ($count < $period) return null;
        $sum = 0;
        for ($i = 0; $i < $period; $i++) $sum += $values[$i];
        $ema = $sum / $period;
        $k = 2 / ($period + 1);
        for ($i = $period; $i < $count; $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    function processTicker($pdo, $ticker, $period) {
        $originalTicker = $ticker;
        
        // Check if this ticker is an alias for another ticker
        try {
            $stmt = $pdo->prepare("SELECT alias_of FROM ticker_mapping WHERE ticker = ? AND alias_of IS NOT NULL AND alias_of != '' LIMIT 1");
            $stmt->execute([$ticker]);
            $aliasOf = $stmt->fetchColumn();
            
            if ($aliasOf) {
                // Use the canonical ticker for fetching
                $ticker = $aliasOf;
            }
        } catch (Exception $e) {
            // Column might not exist, ignore
        }
        
        $yahooTicker = mapTickerToYahoo($ticker);
        
        // A. CHARTS FETCH
        $end = time();
        $start = 0;
        
        // Zjistíme, odkdy stahovat
        if ($period !== 'max') {
            $stmtMax = $pdo->prepare("SELECT MAX(date) FROM tickers_history WHERE ticker = ?");
            $stmtMax->execute([$ticker]);
            $lastDate = $stmtMax->fetchColumn();

            // Zkontrolujeme počet záznamů pro EMA 212
            // Pokud je záznamů málo (např. importovali jsme nově), musíme stáhnout delší historii,
            // jinak by se stále stahoval jen přírůstek a EMA by se nikdy nespočítala.
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tickers_history WHERE ticker = ?");
            $stmtCount->execute([$ticker]);
            $histCount = $stmtCount->fetchColumn();
            
            if ($lastDate && $histCount >= 220) {
                // Máme dost historie, stačí incremental update
                // Stahujeme vždy alespoň posledních 7 dní pro update
                // (Overlap fetch) - eliminuje "missing data" a "corrections"
                $start = strtotime($lastDate) - (7 * 86400); 
            } else {
                // Nemáme dost dat (nebo žádná), stahneme 2 roky pro výpočet EMA
                $start = strtotime('-2 years');
            }
        } else {
            $start = strtotime('-20 years'); // Max fetch
            if (strpos($yahooTicker, 'BTC') !== false) $start = strtotime('2014-09-15');
        }

        // Fetch URL Logic with Retry
        $fetchUrl = function($symbol) use ($start, $end) {
            $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$symbol}?period1={$start}&period2={$end}&interval=1d&events=history&includeAdjustedClose=true";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $json = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['json' => $json, 'http' => $http];
        };

        $res = $fetchUrl($yahooTicker);
        $json = $res['json'];
        $http = $res['http'];

        // Retry mechanism for Dot vs Hyphen (e.g. BRK.B -> BRK-B) if 404
        if ($http === 404 && strpos($yahooTicker, '.') !== false) {
             // Only try if it's not a known exchange suffix (heuristic)
             // Exchanges: .PR, .DE, .L, .MI, .PA, etc. Usually 2 letters.
             // Classes: .A, .B.
             $suffix = substr(strrchr($yahooTicker, '.'), 1);
             if (strlen($suffix) === 1) { // It's likely a class share
                 $altTicker = str_replace('.', '-', $yahooTicker);
                 $res = $fetchUrl($altTicker);
                 if ($res['http'] === 200) {
                     $json = $res['json'];
                     $http = $res['http'];
                     $yahooTicker = $altTicker; // Update for subsequent usage
                 }
             }
        }
        
        $conversionFactor = 1.0;
        $chartSuccess = false;

        // DB Transaction for History
        if ($http === 200 && $json) {
            $data = json_decode($json, true);
            if (!empty($data['chart']['result'][0]['timestamp'])) {
                $chartSuccess = true;
                $res = $data['chart']['result'][0];
                $ts = $res['timestamp'];
                $c = $res['indicators']['quote'][0]['close'];
                
                // Curr Conv Logic
                $yCur = strtoupper($res['meta']['currency'] ?? 'USD');
                $stmtC = $pdo->prepare("SELECT currency FROM live_quotes WHERE id=?");
                $stmtC->execute([$ticker]);
                $tCur = strtoupper($stmtC->fetchColumn() ?: '');
                
                // GBp fix
                if ($yCur === 'GBP' && $tCur === 'GBP') {
                     $lp = end($c);
                     $stmtP = $pdo->prepare("SELECT current_price FROM live_quotes WHERE id=?");
                     $stmtP->execute([$ticker]);
                     $curP = (float)$stmtP->fetchColumn();
                     if ($curP > 0 && abs($lp/$curP - 100) < 50) $conversionFactor = 0.01; // Ratio ~100
                }
                
                // Insert History
                $sqlHist = "INSERT INTO tickers_history (ticker, date, price, source) VALUES (:t, :d, :p, 'yahoo') ON DUPLICATE KEY UPDATE price=VALUES(price), source=VALUES(source)";
                $stmtHist = $pdo->prepare($sqlHist);
                
                if(!$pdo->inTransaction()) $pdo->beginTransaction();
                for($i=0; $i<count($ts); $i++) {
                    if (isset($c[$i]) && $c[$i] !== null) {
                        $dateStr = date('Y-m-d', $ts[$i]);
                        $price = $c[$i] * $conversionFactor;
                        // Save under canonical ticker
                        $stmtHist->execute([':t'=>$ticker, ':d'=>$dateStr, ':p'=>$price]);
                        // Also save under original ticker if it's an alias
                        if ($originalTicker !== $ticker) {
                            $stmtHist->execute([':t'=>$originalTicker, ':d'=>$dateStr, ':p'=>$price]);
                        }
                    }
                }
                if($pdo->inTransaction()) $pdo->commit();
            }
        }

        // B. CALCULATE STATS (ATH, EMA)
        // Fetch All History Sorted
        $stmtAll = $pdo->prepare("SELECT price FROM tickers_history WHERE ticker=? ORDER BY date ASC");
        $stmtAll->execute([$ticker]);
        $allPrices = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
        
        $maxPrice = 0; $minPrice = 0; $emaValue = null;
        if (!empty($allPrices)) {
            $maxPrice = max($allPrices);
            $minPrice = min($allPrices);
            
            // EMA Calculation
            // UI says "Trend (EMA) - EMA 212", so we use 212.
            $emaValue = calcEMA($allPrices, 212);
        }

        // C. QUOTE FETCH (Fundamentals)
        $qUrl = "https://query1.finance.yahoo.com/v7/finance/quote?symbols=" . urlencode($yahooTicker);
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $qUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $qJson = curl_exec($ch2);
        curl_close($ch2);

        $qC = [];
        if ($qJson) {
            $qd = json_decode($qJson, true);
            $qC = $qd['quoteResponse']['result'][0] ?? [];
        }

        // D. FINAL UPDATE
        // Map Quote fields
        $fields = [
            'dividendYield' => ['dividend_yield', true], // name, isPercent
            'trailingPE' => ['pe_ratio', false],
            'marketCap' => ['market_cap', false, true], // name, isPerc, isMoney
            'regularMarketPreviousClose' => ['previous_close', false, true],
            'regularMarketOpen' => ['open_price', false, true],
            'regularMarketDayLow' => ['day_low', false, true],
            'regularMarketDayHigh' => ['day_high', false, true],
            'regularMarketVolume' => ['volume', false, false],
            'fiftyTwoWeekHigh' => ['week_52_high', false, true],
            'fiftyTwoWeekLow' => ['week_52_low', false, true],
            'regularMarketChange' => ['change_amount', false, true],
            'regularMarketChangePercent' => ['change_percent', false, false]
        ];
        
        // Resilience / Phoenix Score Calculation
        // Logic: Drop > 60% from ATH to ATL, and Current Price > 70% of ATH (Recovery)
        // Or: Current Price > ATL + (ATH-ATL)*0.7 ? 
        // Tooltip says: "Pád >60%, následný návrat k 70% maxima" => Back to 70% of ATH level.
        $resilienceScore = 0;
        if ($maxPrice > 0 && $minPrice > 0) {
            // Check if it's a "Phoenix" candidate (Deep drop in history)
            $drop = ($maxPrice - $minPrice) / $maxPrice; // e.g. (100 - 30)/100 = 0.70 drop
            
            if ($drop >= 0.60) {
                // Check recovery
                // Current price needed. Use data from yahoo quote or last history point.
                $curP = $qC['regularMarketPrice'] ?? end($allPrices);
                
                if ($curP > 0) {
                    $recoveryLevel = $curP / $maxPrice; // e.g. 75 / 100 = 0.75
                    if ($recoveryLevel >= 0.70) {
                        $resilienceScore = 1; // It is a Phoenix
                    }
                }
            }
        }

        // Build SQL
        $updParts = [];
        $updParams = [
            ':id' => $ticker, 
            ':ath' => $maxPrice, 
            ':atl' => $minPrice, 
            ':ema' => $emaValue,
            ':res' => $resilienceScore
        ];
        
        // Add static parts
        $updParts[] = "all_time_high = GREATEST(COALESCE(all_time_high,0), :ath)";
        $updParts[] = "all_time_low = LEAST(COALESCE(all_time_low,999999), :atl)";
        $updParts[] = "ema_212 = :ema"; // Save EMA
        $updParts[] = "resilience_score = :res"; 
        $updParts[] = "last_fetched = NOW()";

        foreach ($fields as $yKey => $def) {
            $dbCol = $def[0];
            $val = $qC[$yKey] ?? null;
            
            // Fallback for PE
            if ($yKey === 'trailingPE' && $val === null) $val = $qC['forwardPE'] ?? null;
            
            if ($val !== null) {
                if (($def[1] ?? false) && $val < 1.0) $val *= 100; // Percent fix
                if (($def[2] ?? false) && $conversionFactor != 1.0) $val *= $conversionFactor; // Money conv
                
                $paramName = ":p_".$dbCol;
                $updParts[] = "$dbCol = $paramName";
                $updParams[$paramName] = $val;
            }
        }

        $sqlFinal = "UPDATE live_quotes SET " . implode(', ', $updParts) . " WHERE id = :id";
        $pdo->prepare($sqlFinal)->execute($updParams);

        return 1;
    }

    // --- MAIN LOOP ---
    if ($ticker === 'ALL') {
        $ids = $pdo->query("SELECT id FROM live_quotes WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0; $fail = 0;
        foreach($ids as $t) {
            try { processTicker($pdo, $t, $period); $ok++; } catch (Exception $e) { $fail++; }
        }
        echo json_encode(['success'=>true, 'stats'=>['ok'=>$ok, 'fail'=>$fail]]);
    } else {
        processTicker($pdo, $ticker, $period);
        echo json_encode(['success'=>true]);
    }

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
?>
