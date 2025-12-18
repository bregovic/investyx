<?php
require_once 'php/env.local.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,  
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Check total count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM broker_live_quotes");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "📊 Celkem záznamů v broker_live_quotes: " . $count['total'] . "\n\n";
    
    // Show latest 10 records
    $stmt = $pdo->query("
        SELECT id, current_price, currency, company_name, last_fetched, status 
        FROM broker_live_quotes 
        ORDER BY last_fetched DESC 
        LIMIT 10
    ");
    
    echo "🕐 Posledních 10 záznamů:\n";
    echo str_repeat("=", 100) . "\n";
    printf("%-10s %-15s %-10s %-30s %-20s %-10s\n", 
        "Ticker", "Cena", "Měna", "Název", "Načteno", "Status");
    echo str_repeat("-", 100) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-10s %-15s %-10s %-30s %-20s %-10s\n",
            $row['id'],
            $row['current_price'] ?? 'NULL',
            $row['currency'] ?? 'NULL',
            substr($row['company_name'] ?? 'NULL', 0, 28),
            $row['last_fetched'],
            $row['status']
        );
    }
    
    // Check today's records
    echo "\n📅 Dnešní záznamy:\n";
    $stmt = $pdo->query("
        SELECT COUNT(*) as today_count 
        FROM broker_live_quotes 
        WHERE DATE(last_fetched) = CURDATE()
    ");
    $today = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Počet dnešních záznamů: " . $today['today_count'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Chyba: " . $e->getMessage() . "\n";
}
