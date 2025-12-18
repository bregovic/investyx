<?php
require_once 'php/env.local.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $stmt = $pdo->query("
        SELECT date, trans_type, id AS ticker, quantity, price, currency, amount_czk, platform 
        FROM broker_trans 
        WHERE id = 'CBK' 
        ORDER BY date DESC
    ");
    
    echo "CBK transakce v databázi:\n";
    echo str_repeat("=", 100) . "\n";
    printf("%-20s %-10s %-10s %-15s %-10s %-10s %-15s\n", 
        "Datum", "Typ", "Ticker", "Množství", "Cena", "Měna", "Platform");
    echo str_repeat("-", 100) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-20s %-10s %-10s %-15s %-10s %-10s %-15s\n",
            $row['date'],
            $row['trans_type'],
            $row['ticker'],
            $row['quantity'],
            $row['price'],
            $row['currency'],
            $row['platform']
        );
    }
    
} catch (Exception $e) {
    echo "Chyba: " . $e->getMessage();
}
