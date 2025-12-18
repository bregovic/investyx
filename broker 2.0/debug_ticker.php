<?php
// debug_ticker.php
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "START DEBUG\n";

// 1. Load Config
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php'
];
$configLoaded = false;
foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        echo "Config loaded: $path\n";
        $configLoaded = true;
        break;
    }
}
if (!$configLoaded) die("ERROR: Config not found!\n");

// 2. Connect DB
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "DB Connected: ".DB_HOST."\n";
} catch (Exception $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}

// 3. Check Tables
$tables = ['broker_ticker_mapping', 'broker_watch', 'transactions', 'live_quotes'];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "Table '$t' check: OK ($count rows)\n";
    } catch (Exception $e) {
        echo "Table '$t' check: FAILED (" . $e->getMessage() . ")\n";
    }
}

// 4. Test GoogleFinanceService
$servicePath = __DIR__ . '/googlefinanceservice.php';
if (file_exists($servicePath)) {
    require_once $servicePath;
    echo "Service file found.\n";
    if (class_exists('GoogleFinanceService')) {
        echo "Class GoogleFinanceService exists.\n";
        
        try {
            $service = new GoogleFinanceService($pdo, 0);
            echo "Service instantiated.\n";
            
            $ticker = isset($_GET['ticker']) ? $_GET['ticker'] : 'AVWS';
            echo "Fetching $ticker...\n";
            $data = $service->getQuote($ticker, true); // Force fresh
            
            if ($data) {
                echo "Fetch SUCCESS: " . print_r($data, true) . "\n";
            } else {
                echo "Fetch FAILED (returns false/null)\n";
            }
            
        } catch (Exception $e) {
            echo "Service Error: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "Class GoogleFinanceService NOT found.\n";
    }
} else {
    echo "Service file NOT found at $servicePath\n";
    // Check capitalization
    $files = scandir(__DIR__);
    foreach($files as $f) {
        if (stripos($f, 'googlefinance') !== false) {
            echo "Found similar file: $f\n";
        }
    }
}

echo "END DEBUG";
?>
