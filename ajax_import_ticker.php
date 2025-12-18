<?php
// ajax_import_ticker.php - Handler pro import tickeru pomocí GoogleFinanceService
session_start();

// Check authentication
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    if (!isset($_SESSION['anonymous']) || $_SESSION['anonymous'] !== true) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'Neautorizovaný přístup']));
    }
}

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$ticker = isset($input['ticker']) ? strtoupper(trim($input['ticker'])) : '';

if (empty($ticker)) {
    die(json_encode(['success' => false, 'message' => 'Ticker je povinný']));
}

// Database connection
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    __DIR__ . '/php/env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/../../../env.local.php'
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!defined('DB_HOST')) {
    die(json_encode(['success' => false, 'message' => 'Chyba konfigurace databáze']));
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Try to use GoogleFinanceService
    $servicePaths = [
        __DIR__ . '/googlefinanceservice.php',
        __DIR__ . '/GoogleFinanceService.php',
        __DIR__ . '/lib/GoogleFinanceService.php',
        __DIR__ . '/includes/googlefinanceservice.php'
    ];
    
    $serviceLoaded = false;
    foreach ($servicePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $serviceLoaded = true;
            break;
        }
    }
    
    if ($serviceLoaded && class_exists('GoogleFinanceService')) {
        // Use the service
        $service = new GoogleFinanceService($pdo, 0);
        $data = $service->getQuote($ticker, true); // Force refresh
        
        if ($data && isset($data['current_price']) && $data['current_price'] > 0) {
            // Success - data was fetched and saved by the service
            
            // Auto-add to watchlist if user is logged in
            $userId = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? ($_SESSION['user']['id'] ?? null));
            if ($userId) {
                try {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO broker_watch (user_id, ticker) VALUES (:uid, :ticker)");
                    $stmt->execute([':uid' => $userId, ':ticker' => $ticker]);
                } catch (Exception $ignore) {}
            }

            echo json_encode([
                'success' => true,
                'message' => 'Data úspěšně importována',
                'data' => [
                    'ticker' => $ticker,
                    'price' => number_format($data['current_price'], 2, '.', ''),
                    'company' => $data['company_name'] ?? $ticker,
                    'change' => isset($data['change_percent']) ? number_format($data['change_percent'], 2, '.', '') : 0,
                    'exchange' => $data['exchange'] ?? 'UNKNOWN'
                ]
            ]);
            exit;
        } else {
            // Service couldn't get data
            echo json_encode([
                'success' => false,
                'message' => "GoogleFinanceService nemohl získat data pro {$ticker}"
            ]);
            exit;
        }
    } else {
        // GoogleFinanceService not available
        echo json_encode([
            'success' => false,
            'message' => 'GoogleFinanceService není dostupný. Zkontrolujte, že soubor googlefinanceservice.php existuje.'
        ]);
        exit;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Chyba: ' . $e->getMessage()
    ]);
    exit;
}
?>