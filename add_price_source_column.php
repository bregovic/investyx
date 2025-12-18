<?php
require_once 'php/env.local.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Přidávám sloupec 'price_source' do tabulky 'broker_ticker_mapping'...\n";
    
    // Zkusíme přidat sloupec (pokud neexistuje)
    try {
        $pdo->exec("
            ALTER TABLE broker_ticker_mapping
            ADD COLUMN price_source ENUM('google', 'manual') DEFAULT 'google' AFTER currency
        ");
        echo "Sloupec byl úspěšně přidán.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Sloupec 'price_source' už existuje.\n";
        } else {
            throw $e;
        }
    }

    echo "Aktualizuji Fio tituly na 'manual'...\n";
    // Heuristika: Pokud je měna CZK, nastavíme manual, protože Google Finance má s BCPP často problém nebo používá jiné kódy.
    $stmt = $pdo->exec("
        UPDATE broker_ticker_mapping 
        SET price_source = 'manual' 
        WHERE currency = 'CZK' OR ticker IN ('CEZ', 'KB', 'MONETA', 'ERSTE', 'KOFOLA', 'TABAK', 'PHILIP MORRIS', 'O2')
    ");
    
    echo "Aktualizováno $stmt záznamů.\n";

} catch (Exception $e) {
    die("Chyba: " . $e->getMessage());
}
