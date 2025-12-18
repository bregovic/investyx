<?php
/**
 * AJAX endpoint for batch fetching live prices
 * Returns JSON with current prices for requested tickers
 */

session_start();

// Authentication check
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;

if (!$isLoggedIn && !$isAnonymous) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database connection
$pdo = null;
try {
    $paths = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/php/env.local.php',
        '../env.local.php',
        'php/env.local.php',
        '../php/env.local.php',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            require_once $p;
            break;
        }
    }
    if (defined('DB_HOST')) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Load Google Finance Service
$googleFinance = null;
$servicePath = __DIR__ . '/googlefinanceservice.php';
if (file_exists($servicePath)) {
    require_once $servicePath;
    try {
        $googleFinance = new GoogleFinanceService($pdo, 0); // TTL = 0 means use today's data or fetch new
    } catch (Exception $e) {
        error_log('GoogleFinanceService init error: ' . $e->getMessage());
    }
}

// Parse request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['tickers']) || !is_array($data['tickers'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request format. Expected: {tickers: [{ticker, currency}, ...]}']);
    exit;
}

$tickers = $data['tickers'];
$results = [];

// Process each ticker
foreach ($tickers as $item) {
    if (!isset($item['ticker']) || !isset($item['currency'])) {
        continue;
    }
    
    $ticker = trim($item['ticker']);
    $currency = trim($item['currency']);
    
    if (empty($ticker)) {
        continue;
    }
    
    $price = null;
    $success = false;
    $error = null;
    
    try {
        if ($googleFinance !== null) {
            // Try Google Finance Service
            $quoteData = $googleFinance->getQuote($ticker, false, $currency); // use cache if available (today's data)
            
            if ($quoteData !== null && isset($quoteData['current_price']) && $quoteData['current_price'] > 0) {
                // Check if currency matches requested currency
                $fetchedCurrency = strtoupper($quoteData['currency'] ?? '');
                $requestedCurrency = strtoupper($currency);
                
                if ($fetchedCurrency === $requestedCurrency) {
                    $price = (float)$quoteData['current_price'];
                    $success = true;
                } else {
                    $error = "Currency mismatch: fetched $fetchedCurrency, requested $requestedCurrency";
                    $success = false;
                }
            } else {
                $error = 'Price not available';
            }
        } else {
            $error = 'Google Finance service not available';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Error fetching price for $ticker: " . $error);
    }
    
    $results[$ticker] = [
        'price' => $price,
        'currency' => $currency,
        'success' => $success,
        'error' => $error
    ];
}

// Return results
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
