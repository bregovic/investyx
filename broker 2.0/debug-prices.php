<?php
// Debug script to show raw data from Google and Yahoo Finance
header('Content-Type: text/html; charset=utf-8');

function fetchUrl($url) {
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 5,
            'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                        "Accept: text/html,application/xhtml+xml,application/json\r\n"
        ]
    ]);
    return @file_get_contents($url, false, $context);
}

$ticker = $_GET['ticker'] ?? 'AAPL';
// Basic mapping for test
if ($ticker === 'BTC') $ticker = 'BTC-USD'; 

echo "<style>body{font-family:sans-serif; padding:20px;} textarea{font-family:monospace; background:#f4f4f4; border:1px solid #ccc;}</style>";
echo "<h1>Debug Price Fetch: $ticker</h1>";
echo "<form><input name='ticker' value='$ticker'><button>Check</button></form>";

// --- GOOGLE ---
echo "<h2>1. Google Finance (Scraping)</h2>";
// Heuristic: Try NASDAQ first. Code logic iterates options, here we simplify.
$googleTicker = ($ticker === 'BTC-USD') ? 'BTC-USD' : $ticker . ":NASDAQ"; 
$gUrl = "https://www.google.com/finance/quote/" . urlencode($googleTicker) . "?hl=en";
echo "<p>URL: <a href='$gUrl' target='_blank'>$gUrl</a></p>";

$gHtml = fetchUrl($gUrl);
if ($gHtml) {
    echo "<p>Status: OK (Length: ".strlen($gHtml).")</p>";
    echo "<textarea style='width:100%; height:200px;'>" . htmlspecialchars(substr($gHtml, 0, 10000)) . "... (truncated)</textarea>";
    
    // Try extract price using CURRENT regex classes from code
    // YMlKec fxKbKc
    if (preg_match('~<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>(.*?)</div>~s', $gHtml, $m)) {
        echo "<p style='color:green'><strong>Extracted Price Logic Match:</strong> " . htmlspecialchars($m[1]) . "</p>";
    } else {
        echo "<p style='color:red'><strong>Price Regex Failed!</strong> CSS classes likely changed.</p>";
    }
} else {
    echo "<p style='color:red'>Failed to fetch Google Finance (might be blocked or timeout).</p>";
}

// --- YAHOO ---
echo "<h2>2. Yahoo Finance (API)</h2>";
$yUrl = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($ticker) . "?interval=1d&range=1d";
echo "<p>URL: <a href='$yUrl' target='_blank'>$yUrl</a></p>";

$yJson = fetchUrl($yUrl);
if ($yJson) {
    echo "<textarea style='width:100%; height:200px;'>" . htmlspecialchars($yJson) . "</textarea>";
    $data = json_decode($yJson, true);
    if ($data) {
        $res = $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? 'N/A';
        echo "<p style='color:green'><strong>Parsed Price:</strong> $res " . ($data['chart']['result'][0]['meta']['currency'] ?? '') . "</p>";
    }
} else {
    echo "<p style='color:red'>Failed to fetch Yahoo Finance.</p>";
}
?>
