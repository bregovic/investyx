<?php
// market.php – Přehled trhu z broker_live_quotes (živá data z Google Finance)
// Zobrazuje poslední dostupné ceny, změny, P/E ratio, market cap atd.

session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) { header("Location: ../index.html"); exit; }

// ===== AJAX Handler for Google Finance Import =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        // This is a JSON request - handle import
        header('Content-Type: application/json; charset=utf-8');
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action']) || $input['action'] !== 'import_google_finance') {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        $ticker = strtoupper(trim($input['ticker'] ?? ''));
        if (empty($ticker)) {
            echo json_encode(['success' => false, 'message' => 'Ticker je povinný']);
            exit;
        }
        
        // Database connection for AJAX
        $paths = [__DIR__.'/../env.local.php', __DIR__.'/env.local.php', __DIR__.'/php/env.local.php'];
        foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }
        
        if (!defined('DB_HOST')) {
            echo json_encode(['success' => false, 'message' => 'Chyba konfigurace databáze']);
            exit;
        }
        
        try {
            $pdoAjax = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // Simple direct fetch from Google Finance
            $success = false;
            $resultData = [];
            
            // Try different exchange combinations for US stocks
            $exchanges = ['NYSE', 'NASDAQ', 'NYSEARCA', 'NYSEAMERICAN', ''];
            
            // Special handling for known tickers
            $knownExchanges = [
                'BA' => 'NYSE',      // Boeing
                'AAPL' => 'NASDAQ',  // Apple
                'MSFT' => 'NASDAQ',  // Microsoft
                'GOOGL' => 'NASDAQ', // Google
                'AMZN' => 'NASDAQ',  // Amazon
                'TSLA' => 'NASDAQ',  // Tesla
                'JPM' => 'NYSE',     // JP Morgan
                'V' => 'NYSE',       // Visa
                'WMT' => 'NYSE',     // Walmart
                'DIS' => 'NYSE',     // Disney
                'NVDA' => 'NASDAQ',  // Nvidia
                'META' => 'NASDAQ',  // Meta
                'BRK.B' => 'NYSE',   // Berkshire
                'JNJ' => 'NYSE',     // Johnson & Johnson
                'PG' => 'NYSE',      // Procter & Gamble
            ];
            
            // If we know the exchange, try it first
            if (isset($knownExchanges[$ticker])) {
                array_unshift($exchanges, $knownExchanges[$ticker]);
                $exchanges = array_unique($exchanges);
            }
            
            $lastError = '';
            foreach ($exchanges as $ex) {
                $symbol = $ex ? $ticker . ':' . $ex : $ticker;
                $url = 'https://www.google.com/finance/quote/' . urlencode($symbol);
                
                $opts = [
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 10,
                        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                                  "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                                  "Accept-Language: en-US,en;q=0.5\r\n"
                    ]
                ];
                
                $html = @file_get_contents($url, false, stream_context_create($opts));
                
                if ($html) {
                    // Try multiple patterns for price
                    $patterns = [
                        '/<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>([^<]+)<\/div>/i',
                        '/<div[^>]+class="[^"]*YMlKec[^"]*"[^>]*>([^<]+)<\/div>/i',
                        '/<div[^>]+data-last-price="([^"]+)"/i',
                        '/data-last-price="([^"]+)"/i'
                    ];
                    
                    $price = 0;
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $html, $m)) {
                            $priceText = isset($m[1]) ? $m[1] : '';
                            $priceText = str_replace(['$', ',', ' ', "\xc2\xa0"], '', $priceText);
                            $price = (float)$priceText;
                            if ($price > 0) break;
                        }
                    }
                    
                    if ($price > 0) {
                        // Get company name if available
                        $company = $ticker;
                        if (preg_match('/<div[^>]+class="[^"]*zzDege[^"]*"[^>]*>([^<]+)<\/div>/i', $html, $cm)) {
                            $company = trim($cm[1]);
                        }
                        
                        // Get change percentage if available
                        $changePercent = 0;
                        if (preg_match('/\(([+\-]?\d+\.?\d*)%\)/', $html, $chm)) {
                            $changePercent = (float)$chm[1];
                        }
                        
                        // Save to database
                        $sql = "INSERT INTO broker_live_quotes (id, source, current_price, change_percent, company_name, exchange, last_fetched, status)
                                VALUES (:id, 'google_finance', :price, :change, :company, :exchange, NOW(), 'active')
                                ON DUPLICATE KEY UPDATE
                                current_price = VALUES(current_price),
                                change_percent = VALUES(change_percent),
                                company_name = VALUES(company_name),
                                exchange = VALUES(exchange),
                                last_fetched = NOW()";
                        
                        $stmt = $pdoAjax->prepare($sql);
                        $stmt->execute([
                            ':id' => $ticker,
                            ':price' => $price,
                            ':change' => $changePercent,
                            ':company' => $company,
                            ':exchange' => $ex ?: 'UNKNOWN'
                        ]);
                        
                        $resultData = [
                            'price' => $price,
                            'change' => $changePercent,
                            'company' => $company,
                            'exchange' => $ex
                        ];
                        $success = true;
                        break;
                    } else {
                        $lastError = "Cena nebyla nalezena na {$ex}";
                    }
                } else {
                    $lastError = "Nepodařilo se načíst stránku pro {$symbol}";
                }
            }
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Data importována', 'data' => $resultData]);
            } else {
                // Try to give more helpful error message
                $suggestions = '';
                if (strlen($ticker) < 2) {
                    $suggestions = ' Ticker musí mít alespoň 2 znaky.';
                } elseif (preg_match('/[^A-Z0-9\.]/', $ticker)) {
                    $suggestions = ' Ticker obsahuje neplatné znaky.';
                } else {
                    $suggestions = ' Zkuste např. AAPL, MSFT, GOOGL, AMZN, nebo BA (Boeing).';
                }
                echo json_encode(['success' => false, 'message' => 'Nepodařilo se získat data pro ' . $ticker . '.' . $suggestions]);
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Chyba: ' . $e->getMessage()]);
        }
        exit; // Important - stop execution after AJAX response
    }
}

// ===== Normal page processing continues here =====

/* ===== Resolve User ID ===== */
if (!function_exists('market_resolveUserIdFromSession')) {
  function market_resolveUserIdFromSession(): array {
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
}
list($currentUserId,) = market_resolveUserIdFromSession();
$userName = $_SESSION['name'] ?? ($_SESSION['user']['name'] ?? 'Uživatel');
$currentPage = 'market';

/* ===== DB připojení ===== */
$bootstrapCandidates = [
  'db.php','config/db.php','includes/db.php','inc/db.php',
  'config.php','includes/config.php','inc/config.php',
];
foreach ($bootstrapCandidates as $inc) {
  $p = __DIR__ . DIRECTORY_SEPARATOR . $inc;
  if (file_exists($p)) { require_once $p; }
}

$pdo    = isset($pdo)    ? $pdo    : (isset($db) && $db instanceof PDO ? $db : null);
$mysqli = isset($mysqli) ? $mysqli : (isset($conn) && $conn instanceof mysqli ? $conn : (isset($db) && $db instanceof mysqli ? $db : null));

/* ENV fallback */
if (!($pdo instanceof PDO) && !($mysqli instanceof mysqli)) {
  try {
    $paths=[__DIR__.'/../env.local.php',__DIR__.'/env.local.php',__DIR__.'/php/env.local.php','../env.local.php','php/env.local.php','../php/env.local.php'];
    foreach($paths as $p){ if(file_exists($p)){ require_once $p; break; } }
    if (defined('DB_HOST')) {
      $pdo=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      ]);
    }
  } catch(Throwable $e) { /* tichý fallback */ }
}

$hasDb  = ($pdo instanceof PDO) || ($mysqli instanceof mysqli);

/* ===== UI parametry ===== */
$q        = isset($_GET['q'])        ? trim((string)$_GET['q'])        : '';
$source   = isset($_GET['source'])   ? trim((string)$_GET['source'])   : '';
$currency = isset($_GET['currency']) ? strtoupper(trim((string)$_GET['currency'])) : '';
$exchange = isset($_GET['exchange']) ? strtoupper(trim((string)$_GET['exchange'])) : '';
$perPage  = isset($_GET['per'])      ? max(10, min(500, (int)$_GET['per'])) : 100;
$page     = isset($_GET['page'])     ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $perPage;
$ajaxRows = isset($_GET['ajax_rows']) && $_GET['ajax_rows'] === '1';

/* ===== Query helper ===== */
if (!function_exists('market_qAll')) {
  function market_qAll(string $sql, array $params = []) {
    global $pdo, $mysqli;
    if ($pdo instanceof PDO) {
      $st = $pdo->prepare($sql);
      foreach ($params as $k=>$v) {
        $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $st->bindValue(is_int($k)?$k+1:$k, $v, $type);
      }
      $st->execute();
      return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($mysqli instanceof mysqli) {
      if ($params) {
        foreach ($params as $k=>$v) {
          $safe = $mysqli->real_escape_string((string)$v);
          $sql = str_replace($k, "'$safe'", $sql);
        }
      }
      $res = $mysqli->query($sql);
      if (!$res) return [];
      $rows = [];
      while ($row = $res->fetch_assoc()) $rows[] = $row;
      return $rows;
    }
    return [];
  }
}

/* ===== Data z broker_live_quotes ===== */
$rows = []; $sources = $currs = $exchanges = []; $totalItems = 0; $totalPages = 1; $stats = [];

if ($hasDb) {
  $where = []; $params = [];
  
  // Pouze aktivní záznamy
  $where[] = "status = 'active'";
  
  if ($q !== '')        { $where[] = "(id LIKE :q OR company_name LIKE :q)"; $params[':q'] = "%$q%"; }
  if ($source !== '')   { $where[] = "source = :src";   $params[':src'] = $source; }
  if ($currency !== '') { $where[] = "currency = :ccy"; $params[':ccy'] = $currency; }
  if ($exchange !== '') { $where[] = "exchange = :exch"; $params[':exch'] = $exchange; }
  
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // Hlavní query - data bez řazení (řazení bude pomocí JS)
  $sql = "
  SELECT
    id, source, current_price, previous_close, open_price,
    day_low, day_high, week_52_low, week_52_high,
    change_amount, change_percent, volume, avg_volume,
    market_cap, pe_ratio, dividend_yield, eps, beta,
    currency, exchange, company_name, last_fetched,
    TIMESTAMPDIFF(MINUTE, last_fetched, NOW()) AS age_minutes
  FROM broker_live_quotes
  " . $whereSql . "
  ORDER BY id ASC
  LIMIT :lim OFFSET :off
  ";
  
  $params2 = $params;
  $params2[':lim'] = (int)$perPage;
  $params2[':off'] = (int)$offset;

  $rows = market_qAll($sql, $params2);
  
  // Dropdown options
  $sources = array_column(market_qAll("SELECT DISTINCT source FROM broker_live_quotes WHERE status='active' ORDER BY source"), 'source');
  $currs   = array_column(market_qAll("SELECT DISTINCT currency FROM broker_live_quotes WHERE status='active' ORDER BY currency"), 'currency');
  $exchanges = array_column(market_qAll("SELECT DISTINCT exchange FROM broker_live_quotes WHERE status='active' AND exchange IS NOT NULL ORDER BY exchange"), 'exchange');
  
  // Celkový počet
  $totalItems = (int) (market_qAll("SELECT COUNT(*) AS c FROM broker_live_quotes " . $whereSql, $params)[0]['c'] ?? 0);
  $totalPages = max(1, (int)ceil($totalItems / $perPage));
  
  // Statistiky
  $stats = market_qAll("
    FROM broker_live_quotes 
    WHERE status = 'active'
  ")[0] ?? [];

  // ================= AJAX RETURN FOR INFINITE SCROLL =================
  if ($ajaxRows) {
    if (empty($rows)) {
      exit; // Empty response means no more data
    }
    // Render just the TRs
    foreach ($rows as $r) {
      $age = (int)($r['age_minutes'] ?? 999);
      $ageBadgeClass = $age <= 10 ? 'fresh' : ($age <= 60 ? '' : 'stale');
      $changePercent = (float)($r['change_percent'] ?? 0);
      $changeAmount = (float)($r['change_amount'] ?? 0);
      // NOTE: We duplicate the TR rendering logic here.Ideally refactor to a function/template.
      ?>
      <tr
        data-ticker="<?php echo htmlspecialchars($r['id']); ?>"
        data-company_name="<?php echo htmlspecialchars($r['company_name'] ?? ''); ?>"
        data-exchange="<?php echo htmlspecialchars($r['exchange'] ?? ''); ?>"
        data-current_price="<?php echo (float)($r['current_price'] ?? 0); ?>"
        data-change_amount="<?php echo $changeAmount; ?>"
        data-change_percent="<?php echo $changePercent; ?>"
        data-volume="<?php echo (float)($r['volume'] ?? 0); ?>"
        data-market_cap="<?php echo (float)($r['market_cap'] ?? 0); ?>"
        data-pe_ratio="<?php echo (float)($r['pe_ratio'] ?? 0); ?>"
        data-dividend_yield="<?php echo (float)($r['dividend_yield'] ?? 0); ?>"
        data-age_minutes="<?php echo $age; ?>"
      >
        <td>
          <div class="ticker-cell"><?php echo htmlspecialchars($r['id']); ?></div>
        </td>
        <td>
          <?php if ($r['company_name']): ?>
            <div class="company-name"><?php echo htmlspecialchars($r['company_name']); ?></div>
          <?php else: ?>
            <span style="color: #cbd5e1;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['exchange']): ?>
            <span class="badge-exchange"><?php echo htmlspecialchars($r['exchange']); ?></span>
          <?php else: ?>
            <span style="color: #cbd5e1;">—</span>
          <?php endif; ?>
        </td>
        <td class="num">
          <strong><?php echo market_fmtNum($r['current_price'], 2); ?></strong>
          <span style="color: #94a3b8; font-size: 11px;"><?php echo htmlspecialchars($r['currency'] ?? ''); ?></span>
        </td>
        <td class="num <?php echo $changeAmount > 0 ? 'positive' : ($changeAmount < 0 ? 'negative' : ''); ?>">
          <?php echo $changeAmount > 0 ? '+' : ''; ?><?php echo market_fmtNum($changeAmount, 2); ?>
        </td>
        <td class="num <?php echo $changePercent > 0 ? 'positive' : ($changePercent < 0 ? 'negative' : ''); ?>">
          <strong>
            <?php if ($r['change_percent'] !== null): ?>
              <?php echo $changePercent > 0 ? '+' : ''; ?><?php echo market_fmtNum($changePercent, 2); ?>%
            <?php else: ?>
              —
            <?php endif; ?>
          </strong>
        </td>
        <td class="num" style="color: #64748b;">
          <?php echo market_fmtVolume($r['volume']); ?>
        </td>
        <td class="num" style="color: #64748b;">
          <?php echo market_fmtMarketCap($r['market_cap']); ?>
        </td>
        <td class="num" style="color: #64748b;">
          <?php echo market_fmtNum($r['pe_ratio'], 2); ?>
        </td>
        <td class="num" style="color: #64748b;">
          <?php if ($r['dividend_yield'] !== null && $r['dividend_yield'] > 0): ?>
            <?php echo market_fmtNum($r['dividend_yield'], 2); ?>%
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <span class="age-badge <?php echo $ageBadgeClass; ?>">
            <?php
              if ($age < 60) echo $age . ' min';
              elseif ($age < 1440) echo round($age/60) . ' hod';
              else echo round($age/1440) . ' dní';
            ?>
          </span>
        </td>
      </tr>
      <?php
    }
    // Output a hidden span to indicate if more pages exist
    if ($page < $totalPages) {
      echo '<tr class="load-more-indicator" data-next-page="'.($page+1).'" style="display:none;"></tr>';
    }
    exit; // Stop full page render
  }
}

/* helpers */
if (!function_exists('market_fmtNum')) {
  function market_fmtNum($v, $dec = 2) {
    if ($v === null || $v === '') return '—';
    if (!is_numeric($v)) return htmlspecialchars((string)$v);
    $v = (float)$v;
    if ($v !== 0.0 && abs($v) < 1) $dec = max($dec, 4);
    return number_format($v, $dec, ',', ' ');
  }
}

if (!function_exists('market_fmtMarketCap')) {
  function market_fmtMarketCap($val) {
    if ($val === null || $val === '' || $val == 0) return '—';
    $v = (float)$val;
    if ($v >= 1000000000000) return number_format($v/1000000000000, 2, ',', ' ') . ' T';
    if ($v >= 1000000000) return number_format($v/1000000000, 2, ',', ' ') . ' B';
    if ($v >= 1000000) return number_format($v/1000000, 2, ',', ' ') . ' M';
    if ($v >= 1000) return number_format($v/1000, 2, ',', ' ') . ' K';
    return number_format($v, 0, ',', ' ');
  }
}

if (!function_exists('market_fmtVolume')) {
  function market_fmtVolume($val) {
    if ($val === null || $val === '' || $val == 0) return '—';
    $v = (float)$val;
    if ($v >= 1000000000) return number_format($v/1000000000, 2, ',', ' ') . ' B';
    if ($v >= 1000000) return number_format($v/1000000, 2, ',', ' ') . ' M';
    if ($v >= 1000) return number_format($v/1000, 2, ',', ' ') . ' K';
    return number_format($v, 0, ',', ' ');
  }
}

/* Calculate summary statistics */
$totalPositive = 0;
$totalNegative = 0;
$totalZero = 0;
$sumPercentChange = 0;

foreach ($rows as $row) {
  $change = (float)($row['change_percent'] ?? 0);
  $sumPercentChange += $change;
  if ($change > 0) $totalPositive++;
  elseif ($change < 0) $totalNegative++;
  else $totalZero++;
}

$avgChange = count($rows) > 0 ? $sumPercentChange / count($rows) : 0;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Přehled trhu - Broker</title>
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="css/broker-overrides.css">
  <style>
    /* Additional styles for market page */
    
    /* Import button styling */
    .btn-import-data {
      background: linear-gradient(135deg, #059669, #16a34a) !important;
      color: white !important;
      font-weight: 600;
      padding: 10px 20px !important;
      border: none;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
    }
    
    .btn-import-data:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }
    
    /* Table styling matching sal.php */
    .table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      background: white;
      margin-top: 20px;
    }
    
    .table thead th {
      background: #f8fafc;
      padding: 12px 8px;
      text-align: left;
      font-weight: 600;
      color: #475569;
      border-bottom: 2px solid #e2e8f0;
      cursor: pointer;
      user-select: none;
      white-space: nowrap;
    }
    
    .table thead th:hover {
      background: #f1f5f9;
    }
    
    .table thead th .th-sort {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    .table thead th .dir {
      margin-left: 5px;
      color: #3b82f6;
      font-size: 12px;
    }
    
    .table tbody tr {
      border-bottom: 1px solid #e2e8f0;
    }
    
    .table tbody tr:hover {
      background: #f8fafc;
    }
    
    .table tbody td {
      padding: 10px 8px;
      color: #334155;
    }
    
    .ticker-cell {
      font-weight: 600;
      color: #1e293b;
    }
    
    .company-name {
      font-size: 12px;
      color: #64748b;
      margin-top: 2px;
    }
    
    .positive { color: #059669; }
    .negative { color: #ef4444; }
    
    .age-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
      background: #e2e8f0;
      color: #64748b;
    }
    
    .age-badge.fresh {
      background: #dcfce7;
      color: #166534;
    }
    
    .age-badge.stale {
      background: #fef2f2;
      color: #991b1b;
    }
    
    .badge-exchange {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 10px;
      font-weight: 600;
      background: #dbeafe;
      color: #1e40af;
      text-transform: uppercase;
    }
    
    /* Filter grid - matching sal.php */
    .filter-grid {
      display: grid;
      gap: 16px 20px;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      align-items: end;
      margin-bottom: 20px;
    }
    
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    
    .filter-group label {
      font-size: 13px;
      color: #475569;
      font-weight: 500;
    }
    
    .filter-group .input,
    .filter-group select {
      width: 100%;
      height: 44px;
      padding: 8px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 14px;
      transition: all 0.2s;
    }
    
    .filter-group .input:focus,
    .filter-group select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .filter-buttons {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    
    .results-count {
      padding: 6px 12px;
      background: #f1f5f9;
      border-radius: 6px;
      font-size: 13px;
      color: #475569;
      font-weight: 500;
    }
    
    /* Summary box */
    .summary-box {
      background: white;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      border: 1px solid #e2e8f0;
      margin-bottom: 30px;
    }
    
    .summary-title {
      font-size: 18px;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .summary-icon {
      width: 32px;
      height: 32px;
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 16px;
    }
    
    /* Pagination */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 5px;
      margin-top: 30px;
      padding: 20px;
    }
    
    .pagination a,
    .pagination span {
      padding: 8px 12px;
      border-radius: 6px;
      text-decoration: none;
      color: #475569;
      font-weight: 500;
      transition: all 0.2s;
    }
    
    .pagination a:hover {
      background: #f1f5f9;
      color: #3b82f6;
    }
    
    .pagination span.active {
      background: #3b82f6;
      color: white;
    }
    
    .pagination span.muted {
      color: #cbd5e1;
    }
  </style>
</head>
<body>

<header class="header">
  <nav class="nav-container">
    <a href="broker.php" class="logo">Portfolio Tracker</a>
    <ul class="nav-menu">
      <li class="nav-item">
        <a href="portfolio.php" class="nav-link<?php echo $currentPage === 'portfolio' ? ' active' : ''; ?>">Transakce</a>
      </li>
      <li class="nav-item">
        <a href="bal.php" class="nav-link<?php echo $currentPage === 'bal' ? ' active' : ''; ?>">Aktuální portfolio</a>
      </li>
      <li class="nav-item">
        <a href="sal.php" class="nav-link<?php echo $currentPage === 'sal' ? ' active' : ''; ?>">Realizované P&amp;L</a>
      </li>
      <li class="nav-item">
        <a href="import.php" class="nav-link<?php echo $currentPage === 'import' ? ' active' : ''; ?>">Import</a>
      </li>
      <li class="nav-item">
        <a href="rates.php" class="nav-link<?php echo $currentPage === 'rates' ? ' active' : ''; ?>">Směnné kurzy</a>
      </li>
      <li class="nav-item">
        <a href="div.php" class="nav-link<?php echo $currentPage === 'div' ? ' active' : ''; ?>">Dividendy</a>
      </li>
      <li class="nav-item">
        <a href="market.php" class="nav-link<?php echo $currentPage === 'market' ? ' active' : ''; ?>">Přehled trhu</a>
      </li>
    </ul>
    <div class="user-section">
      <span class="user-name">Uživatel: <?php echo htmlspecialchars($userName); ?></span>
      <a href="broker.php" class="btn btn-secondary">Menu</a>
      <a href="../php/logout.php" class="btn btn-danger">Odhlásit se</a>
    </div>
  </nav>
</header> 

<main class="main-content">
  
  <!-- Page Header -->
  <div class="page-header">
  </div>
  
  <?php if (!$hasDb): ?>
    <div class="alert alert-danger">
      <strong>⚠️ Chybí připojení k databázi</strong><br>
      Zkontrolujte konfiguraci v <code>env.local.php</code> nebo <code>db.php</code>.
    </div>
  <?php else: ?>
  
  <!-- Import Section - New Box -->
  <div class="content-box" style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 2px solid #0284c7; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
      <div>
        <h3 style="margin: 0; color: #0c4a6e; font-size: 18px;">📈 Import dat z Google Finance</h3>
        <p style="margin: 5px 0 0 0; color: #64748b; font-size: 14px;">Importujte aktuální ceny akcií přímo z Google Finance</p>
      </div>
      <div style="display: flex; gap: 10px; align-items: center;">
        <input type="text" id="quickImportTicker" placeholder="Ticker (např. AAPL)" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; width: 150px;">
        <button type="button" class="btn btn-success" onclick="quickImport()" style="padding: 8px 20px;">
          <span id="quickImportBtnText">🚀 Rychlý import</span>
        </button>
        <button type="button" class="btn btn-primary" onclick="showImportDialog()" style="padding: 8px 20px;">
          ⚙️ Pokročilé možnosti
        </button>
      </div>
    </div>
    <div id="quickImportResult" style="display: none; margin-top: 15px; padding: 10px; border-radius: 6px;"></div>
  </div>
  
  <!-- Statistics Summary -->
  <?php if (!empty($stats)): ?>
  <div class="content-box">
    <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;">Přehled statistik</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
      <div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Celkem titulů</div>
        <div style="font-size: 20px; font-weight: 600;"><?php echo number_format((int)($stats['total_tickers'] ?? 0), 0, ',', ' '); ?></div>
      </div>
      <div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Rostoucí</div>
        <div style="font-size: 20px; font-weight: 600; color: #059669;">↑ <?php echo number_format((int)($stats['positive'] ?? 0), 0, ',', ' '); ?></div>
      </div>
      <div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Klesající</div>
        <div style="font-size: 20px; font-weight: 600; color: #ef4444;">↓ <?php echo number_format((int)($stats['negative'] ?? 0), 0, ',', ' '); ?></div>
      </div>
      <div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Průměrná změna</div>
        <div style="font-size: 20px; font-weight: 600; color: <?php echo $avgChange >= 0 ? '#059669' : '#ef4444'; ?>">
          <?php echo market_fmtNum($avgChange, 2); ?>%
        </div>
      </div>
      <div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Poslední update</div>
        <div style="font-size: 20px; font-weight: 600;">
          <?php echo date('H:i', strtotime($stats['last_update'] ?? 'now')); ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Filter Form -->
  <div class="content-box">
    <form method="get" id="filterForm" class="filter-grid">
      <div class="filter-group">
        <label for="q">Hledat ticker/název</label>
        <input type="text" id="q" name="q" class="input" placeholder="např. AAPL, Apple..." value="<?php echo htmlspecialchars($q); ?>">
      </div>
      
      <div class="filter-group">
        <label for="exchange">Burza</label>
        <select id="exchange" name="exchange" class="input">
          <option value="">Všechny burzy</option>
          <?php foreach ($exchanges as $e): ?>
            <option value="<?php echo htmlspecialchars($e); ?>" <?php echo $e === $exchange ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($e); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="currency">Měna</label>
        <select id="currency" name="currency" class="input">
          <option value="">Všechny měny</option>
          <?php foreach ($currs as $c): ?>
            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $c === $currency ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="per">Počet záznamů</label>
        <select id="per" name="per" class="input">
          <?php foreach ([50,100,200,500] as $p): ?>
            <option value="<?php echo $p; ?>" <?php echo $perPage === $p ? 'selected' : ''; ?>>
              <?php echo $p; ?> položek
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label>&nbsp;</label>
        <div class="filter-buttons">
          <button type="submit" class="btn btn-primary">Filtrovat</button>
          <?php if ($q || $exchange || $currency): ?>
            <a href="market.php" class="btn btn-light">Zrušit filtry</a>
          <?php endif; ?>
          <span class="results-count"><?php echo number_format($totalItems, 0, ',', ' '); ?> titulů</span>
        </div>
      </div>
    </form>
  </div>
  
  <!-- Import Dialog -->
  <div id="importDialog" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
      <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;">📊 Import dat z Google Finance</h3>
      
      <div style="margin-bottom: 20px;">
        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Ticker / ISIN:</label>
        <input type="text" id="importTicker" class="input" placeholder="např. AAPL, GOOGL, US0378331005" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
      </div>
      
      <div style="margin-bottom: 20px;">
        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Typ importu:</label>
        <select id="importType" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" onchange="toggleDateInputs()">
          <option value="today">Dnešní cena</option>
          <option value="date">Konkrétní datum</option>
          <option value="range">Rozsah dat</option>
        </select>
      </div>
      
      <div id="singleDateInput" style="display: none; margin-bottom: 20px;">
        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Datum:</label>
        <input type="date" id="importDate" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" value="<?php echo date('Y-m-d'); ?>">
      </div>
      
      <div id="dateRangeInputs" style="display: none;">
        <div style="margin-bottom: 15px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Od data:</label>
          <input type="date" id="importDateFrom" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" value="<?php echo date('Y-m-d', strtotime('-1 month')); ?>">
        </div>
        <div style="margin-bottom: 20px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Do data:</label>
          <input type="date" id="importDateTo" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      
      <div id="importProgress" style="display: none; margin-bottom: 20px;">
        <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
          <div id="importProgressBar" style="height: 100%; background: linear-gradient(90deg, #059669, #16a34a); width: 0%; transition: width 0.3s;"></div>
        </div>
        <div id="importStatus" style="margin-top: 10px; color: #64748b; font-size: 14px;"></div>
      </div>
      
      <div id="importResult" style="display: none; padding: 12px; border-radius: 8px; margin-bottom: 20px;"></div>
      
      <div style="display: flex; gap: 10px; justify-content: flex-end;">
        <button type="button" class="btn btn-light" onclick="closeImportDialog()">Zrušit</button>
        <button type="button" class="btn btn-primary" onclick="startImport()">
          <span id="importBtnText">🚀 Importovat</span>
        </button>
      </div>
      
      <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0; font-size: 12px; color: #94a3b8;">
          <strong>Tip:</strong> Funkce používá Google Finance API podobně jako =GOOGLEFINANCE() v Google Sheets.
          Podporované jsou akcie z hlavních burz (NYSE, NASDAQ, LSE, atd.).
        </p>
      </div>
    </div>
  </div>
  
  <!-- Market Data Table -->
  <div class="content-box">
    <div class="table-scroll-top"><div style="width: 1600px; height: 1px;"></div></div>
    <div class="table-container">
      <table class="table" id="marketTable">
        <thead>
          <tr>
            <th class="w-ticker" data-key="ticker" data-type="text">
              <span class="th-sort">Ticker <span class="dir"></span></span>
            </th>
            <th data-key="company_name" data-type="text">
              <span class="th-sort">Název společnosti <span class="dir"></span></span>
            </th>
            <th data-key="exchange" data-type="text">
              <span class="th-sort">Burza <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="current_price" data-type="number">
              <span class="th-sort">Cena <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="change_amount" data-type="number">
              <span class="th-sort">Změna <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="change_percent" data-type="number">
              <span class="th-sort">Změna % <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="volume" data-type="number">
              <span class="th-sort">Objem <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="market_cap" data-type="number">
              <span class="th-sort">Market Cap <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="pe_ratio" data-type="number">
              <span class="th-sort">P/E <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="dividend_yield" data-type="number">
              <span class="th-sort">Dividenda <span class="dir"></span></span>
            </th>
            <th data-key="age_minutes" data-type="number">
              <span class="th-sort">Aktualizace <span class="dir"></span></span>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="11" style="text-align: center; padding: 40px; color: #94a3b8;">
                Žádná data k zobrazení
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
              $age = (int)($r['age_minutes'] ?? 999);
              $ageBadgeClass = $age <= 10 ? 'fresh' : ($age <= 60 ? '' : 'stale');
              $changePercent = (float)($r['change_percent'] ?? 0);
              $changeAmount = (float)($r['change_amount'] ?? 0);
            ?>
              <tr
                data-ticker="<?php echo htmlspecialchars($r['id']); ?>"
                data-company_name="<?php echo htmlspecialchars($r['company_name'] ?? ''); ?>"
                data-exchange="<?php echo htmlspecialchars($r['exchange'] ?? ''); ?>"
                data-current_price="<?php echo (float)($r['current_price'] ?? 0); ?>"
                data-change_amount="<?php echo $changeAmount; ?>"
                data-change_percent="<?php echo $changePercent; ?>"
                data-volume="<?php echo (float)($r['volume'] ?? 0); ?>"
                data-market_cap="<?php echo (float)($r['market_cap'] ?? 0); ?>"
                data-pe_ratio="<?php echo (float)($r['pe_ratio'] ?? 0); ?>"
                data-dividend_yield="<?php echo (float)($r['dividend_yield'] ?? 0); ?>"
                data-age_minutes="<?php echo $age; ?>"
              >
                <td>
                  <div class="ticker-cell"><?php echo htmlspecialchars($r['id']); ?></div>
                </td>
                <td>
                  <?php if ($r['company_name']): ?>
                    <div class="company-name"><?php echo htmlspecialchars($r['company_name']); ?></div>
                  <?php else: ?>
                    <span style="color: #cbd5e1;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['exchange']): ?>
                    <span class="badge-exchange"><?php echo htmlspecialchars($r['exchange']); ?></span>
                  <?php else: ?>
                    <span style="color: #cbd5e1;">—</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <strong><?php echo market_fmtNum($r['current_price'], 2); ?></strong>
                  <span style="color: #94a3b8; font-size: 11px;"><?php echo htmlspecialchars($r['currency'] ?? ''); ?></span>
                </td>
                <td class="num <?php echo $changeAmount > 0 ? 'positive' : ($changeAmount < 0 ? 'negative' : ''); ?>">
                  <?php echo $changeAmount > 0 ? '+' : ''; ?><?php echo market_fmtNum($changeAmount, 2); ?>
                </td>
                <td class="num <?php echo $changePercent > 0 ? 'positive' : ($changePercent < 0 ? 'negative' : ''); ?>">
                  <strong>
                    <?php if ($r['change_percent'] !== null): ?>
                      <?php echo $changePercent > 0 ? '+' : ''; ?><?php echo market_fmtNum($changePercent, 2); ?>%
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </strong>
                </td>
                <td class="num" style="color: #64748b;">
                  <?php echo market_fmtVolume($r['volume']); ?>
                </td>
                <td class="num" style="color: #64748b;">
                  <?php echo market_fmtMarketCap($r['market_cap']); ?>
                </td>
                <td class="num" style="color: #64748b;">
                  <?php echo market_fmtNum($r['pe_ratio'], 2); ?>
                </td>
                <td class="num" style="color: #64748b;">
                  <?php if ($r['dividend_yield'] !== null && $r['dividend_yield'] > 0): ?>
                    <?php echo market_fmtNum($r['dividend_yield'], 2); ?>%
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <span class="age-badge <?php echo $ageBadgeClass; ?>">
                    <?php
                      if ($age < 60) echo $age . ' min';
                      elseif ($age < 1440) echo round($age/60) . ' hod';
                      else echo round($age/1440) . ' dní';
                    ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $base = $_GET;
        unset($base['page']);
        $mk = function($p) use ($base) {
          $base['page'] = $p;
          return 'market.php?' . http_build_query($base);
        };
      ?>
      
      <?php if ($page > 1): ?>
        <a href="<?php echo htmlspecialchars($mk($page - 1)); ?>">&laquo; Předchozí</a>
      <?php else: ?>
        <span class="muted">&laquo; Předchozí</span>
      <?php endif; ?>
      
      <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="active"><?php echo $p; ?></span>
        <?php else: ?>
          <a href="<?php echo htmlspecialchars($mk($p)); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      
      <?php if ($page < $totalPages): ?>
        <a href="<?php echo htmlspecialchars($mk($page + 1)); ?>">Další &raquo;</a>
      <?php else: ?>
        <span class="muted">Další &raquo;</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  
  <?php endif; // hasDb ?>
  
</main>

<script>
/* Sorting functionality for market table */
(function() {
  const table = document.getElementById('marketTable');
  if (!table) return;
  
  const thead = table.querySelector('thead');
  const tbody = table.querySelector('tbody');
  let currentKey = null;
  let currentDir = 1;
  
  function getVal(tr, key, type) {
    const raw = tr.dataset[key];
    if (type === 'number') {
      const n = parseFloat(raw);
      return isNaN(n) ? 0 : n;
    }
    if (type === 'date') {
      const t = Date.parse(raw);
      return isNaN(t) ? 0 : t;
    }
    return (raw || '').toString().toLowerCase();
  }
  
  function setIndicator(th, dir) {
    // Clear all indicators
    thead.querySelectorAll('th .dir').forEach(s => s.textContent = '');
    // Set the current one
    const span = th.querySelector('.dir');
    if (span) {
      span.textContent = dir === 1 ? '▲' : '▼';
    }
  }
  
  thead.addEventListener('click', function(e) {
    const th = e.target.closest('th');
    if (!th) return;
    
    const key = th.dataset.key;
    const type = th.dataset.type || 'text';
    if (!key) return;
    
    // Toggle direction or set new key
    if (currentKey === key) {
      currentDir *= -1;
    } else {
      currentKey = key;
      currentDir = 1;
    }
    
    setIndicator(th, currentDir);
    
    // Get all rows and sort them
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    rows.sort((a, b) => {
      const va = getVal(a, key, type);
      const vb = getVal(b, key, type);
      
      if (va < vb) return -1 * currentDir;
      if (va > vb) return 1 * currentDir;
      return 0;
    });
    
    // Reappend sorted rows
    rows.forEach(r => tbody.appendChild(r));
  });
})();

/* Auto-submit on filter change (matching sal.php behavior) */
(function() {
  const form = document.getElementById('filterForm');
  if (!form) return;
  
  const selects = form.querySelectorAll('select');
  selects.forEach(select => {
    select.addEventListener('change', function() {
      form.submit();
    });
  });
})();

/* Synchronized scrolling for top scrollbar */
(function() {
  const scrollTop = document.querySelector('.table-scroll-top');
  const container = document.querySelector('.table-container');
  
  if (scrollTop && container) {
    // Set the inner div width to match table width
    const table = container.querySelector('table');
    if (table) {
      const innerDiv = scrollTop.querySelector('div');
      if (innerDiv) {
        innerDiv.style.width = table.scrollWidth + 'px';
      }
    }
    
    // Sync scrolling
    scrollTop.addEventListener('scroll', function() {
      container.scrollLeft = scrollTop.scrollLeft;
    });
    
    container.addEventListener('scroll', function() {
      scrollTop.scrollLeft = container.scrollLeft;
    });
  }
})();

/* Google Finance Import Functions */
function showQuickResult(type, message) {
  const resultEl = document.getElementById('quickImportResult');
  if (resultEl) {
    resultEl.style.display = 'block';
    if (type === 'success') {
      resultEl.style.background = '#dcfce7';
      resultEl.style.border = '1px solid #86efac';
      resultEl.style.color = '#166534';
    } else {
      resultEl.style.background = '#fee2e2';
      resultEl.style.border = '1px solid #fca5a5';
      resultEl.style.color = '#991b1b';
    }
    resultEl.innerHTML = message;
    
    setTimeout(() => {
      resultEl.style.display = 'none';
    }, 5000);
  }
}

function quickImport() {
  const ticker = document.getElementById('quickImportTicker').value.trim();
  if (!ticker) {
    showQuickResult('error', '❌ Zadejte prosím ticker');
    return;
  }
  
  document.getElementById('quickImportBtnText').textContent = '⏳ Importuji...';
  
  // Use the new simpler endpoint
  fetch('ajax_import_ticker.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      ticker: ticker.toUpperCase()
    })
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      let message = `✅ <strong>${ticker.toUpperCase()}</strong> úspěšně importováno`;
      if (data.data) {
        if (data.data.price) {
          message += ` - Cena: <strong>$${data.data.price}</strong>`;
        }
        if (data.data.change !== undefined && data.data.change != 0) {
          const color = parseFloat(data.data.change) >= 0 ? '#059669' : '#ef4444';
          const sign = parseFloat(data.data.change) >= 0 ? '+' : '';
          message += ` <span style="color: ${color}; font-weight: bold;">(${sign}${data.data.change}%)</span>`;
        }
        if (data.data.company && data.data.company !== ticker.toUpperCase()) {
          message += `<br><small style="color: #64748b;">${data.data.company}</small>`;
        }
      }
      showQuickResult('success', message);
      
      // Reload after delay
      setTimeout(() => {
        window.location.reload();
      }, 2000);
    } else {
      showQuickResult('error', '❌ ' + (data.message || 'Chyba při importu'));
    }
  })
  .catch(error => {
    console.error('Import error:', error);
    // Better error message
    if (error.message.includes('JSON')) {
      showQuickResult('error', '❌ Server nevrátil platná data. Zkontrolujte, že soubor ajax_import_ticker.php je správně nahrán.');
    } else {
      showQuickResult('error', '❌ Chyba připojení k serveru');
    }
  })
  .finally(() => {
    document.getElementById('quickImportBtnText').textContent = '🚀 Rychlý import';
  });
}

function showImportDialog() {
  document.getElementById('importDialog').style.display = 'block';
  document.getElementById('importTicker').focus();
}

function closeImportDialog() {
  document.getElementById('importDialog').style.display = 'none';
  document.getElementById('importTicker').value = '';
  document.getElementById('importType').value = 'today';
  document.getElementById('singleDateInput').style.display = 'none';
  document.getElementById('dateRangeInputs').style.display = 'none';
  document.getElementById('importProgress').style.display = 'none';
  document.getElementById('importResult').style.display = 'none';
}

function toggleDateInputs() {
  const type = document.getElementById('importType').value;
  document.getElementById('singleDateInput').style.display = type === 'date' ? 'block' : 'none';
  document.getElementById('dateRangeInputs').style.display = type === 'range' ? 'block' : 'none';
}

function startImport() {
  const ticker = document.getElementById('importTicker').value.trim();
  const type = document.getElementById('importType').value;
  
  if (!ticker) {
    showImportResult('error', 'Zadejte prosím ticker nebo ISIN');
    return;
  }
  
  // Show progress
  document.getElementById('importProgress').style.display = 'block';
  document.getElementById('importProgressBar').style.width = '0%';
  document.getElementById('importStatus').textContent = 'Připojování k Google Finance...';
  document.getElementById('importBtnText').textContent = '⏳ Importuji...';
  
  // Animate progress
  setTimeout(() => {
    document.getElementById('importProgressBar').style.width = '30%';
    document.getElementById('importStatus').textContent = 'Získávám data pro ' + ticker + '...';
  }, 500);
  
  setTimeout(() => {
    document.getElementById('importProgressBar').style.width = '60%';
    document.getElementById('importStatus').textContent = 'Zpracovávám data...';
  }, 1000);
  
  // For now, only support today's price
  if (type !== 'today') {
    showImportResult('error', 'Historická data zatím nejsou podporována. Použijte "Dnešní cena".');
    document.getElementById('importBtnText').textContent = '🚀 Importovat';
    document.getElementById('importProgress').style.display = 'none';
    return;
  }
  
  // Use the new simpler endpoint
  fetch('ajax_import_ticker.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      ticker: ticker
    })
  })
  .then(response => {
    return response.text().then(text => {
      try {
        return JSON.parse(text);
      } catch (e) {
        console.error('Response text:', text);
        throw new Error('Server nevrátil platné JSON');
      }
    });
  })
  .then(data => {
    document.getElementById('importProgressBar').style.width = '100%';
    document.getElementById('importStatus').textContent = 'Hotovo!';
    
    if (data.success) {
      let message = `✅ Úspěšně importováno: ${ticker}`;
      if (data.data) {
        if (data.data.company) {
          message += ` - ${data.data.company}`;
        }
        if (data.data.price) {
          message += `<br>Cena: $${data.data.price}`;
        }
        if (data.data.change !== undefined && data.data.change !== 0) {
          const changeColor = data.data.change >= 0 ? 'green' : 'red';
          message += ` <span style="color: ${changeColor}">(${data.data.change > 0 ? '+' : ''}${data.data.change}%)</span>`;
        }
        if (data.data.exchange) {
          message += `<br><small>Burza: ${data.data.exchange}</small>`;
        }
      }
      showImportResult('success', message);
      
      // Reload page after 1.5 seconds
      setTimeout(() => {
        window.location.reload();
      }, 1500);
    } else {
      showImportResult('error', '❌ ' + (data.message || 'Chyba při importu dat'));
      document.getElementById('importProgressBar').style.width = '0%';
    }
  })
  .catch(error => {
    console.error('Import error:', error);
    showImportResult('error', '❌ Chyba: ' + error.message);
    document.getElementById('importProgressBar').style.width = '0%';
  })
  .finally(() => {
    document.getElementById('importBtnText').textContent = '🚀 Importovat';
    setTimeout(() => {
      document.getElementById('importProgress').style.display = 'none';
    }, 3000);
  });
}

function showImportResult(type, message) {
  const resultEl = document.getElementById('importResult');
  resultEl.style.display = 'block';
  resultEl.className = type === 'success' ? 'alert alert-success' : 'alert alert-danger';
  resultEl.innerHTML = message;
}

// Close dialog on ESC key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeImportDialog();
  }
});

// Close dialog on background click
document.getElementById('importDialog')?.addEventListener('click', function(e) {
  if (e.target === this) {
    closeImportDialog();
  }
});

/* Infinite Scroll Logic */
(function() {
  const table = document.getElementById('marketTable');
  if (!table) return;

  const pagination = document.querySelector('.pagination');
  if (pagination) {
    pagination.style.display = 'none'; // Hide classical pagination
  }

  // Create sentinel
  const sentinel = document.createElement('div');
  sentinel.id = 'infinite-scroll-sentinel';
  sentinel.style.height = '10px';
  sentinel.style.marginTop = '20px';
  sentinel.innerHTML = '';
  
  const spinner = document.createElement('div');
  spinner.className = 'spinner';
  spinner.style.display = 'none';
  spinner.style.width = '24px';
  spinner.style.height = '24px';
  spinner.style.borderWidth = '2px';
  sentinel.appendChild(spinner);

  // Insert sentinel after table container
  const container = document.querySelector('.table-container');
  if (container) {
    container.parentNode.insertBefore(sentinel, container.nextSibling);
  }

  let currentPage = <?php echo json_encode($page); ?>;
  const totalPages = <?php echo json_encode($totalPages); ?>;
  let isLoading = false;
  
  // Use current params
  const urlParams = new URLSearchParams(window.location.search);

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !isLoading && currentPage < totalPages) {
      loadNextPage();
    }
  }, { rootMargin: '200px' });

  observer.observe(sentinel);

  function loadNextPage() {
    isLoading = true;
    spinner.style.display = 'block';
    
    // Prepare URL
    urlParams.set('page', currentPage + 1);
    urlParams.set('ajax_rows', '1');
    const url = 'market.php?' + urlParams.toString();

    fetch(url)
      .then(response => response.text())
      .then(html => {
        if (!html.trim()) {
          observer.disconnect(); // No more data
          return;
        }

        // Append rows
        const tbody = table.querySelector('tbody');
        // We can append HTML string by using temp div or insertAdjacentHTML
        // However, we want to check for the .load-more-indicator to know if we really have more pages
        // But since we track pages in JS, it's fine.
        
        tbody.insertAdjacentHTML('beforeend', html);
        
        currentPage++;
        
        // Check if there's actually a next page indicator from server
        // (optional verification)
        
        if (currentPage >= totalPages) {
          observer.disconnect();
          sentinel.style.display = 'none';
        }
      })
      .catch(err => console.error('Load more error:', err))
      .finally(() => {
        isLoading = false;
        spinner.style.display = 'none';
      });
  }
})();
</script>

</body>
</html>