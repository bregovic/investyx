<?php
require_once 'php/env.local.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "\n====== FIO PARSER ANALÝZA ======\n";

    // 1. Základní statistika
    $stmt = $pdo->query("SELECT COUNT(*) FROM broker_trans WHERE platform = 'Fio'");
    $total = $stmt->fetchColumn();
    echo "Celkem transakcí: $total\n\n";

    // 2. UNKNOWN Tickers
    echo "--- 1. Neznámé tickery (UNKNOWN) ---\n";
    $stmt = $pdo->query("SELECT id, date, trans_type, amount, price, currency, notes FROM broker_trans WHERE platform = 'Fio' AND (id = 'UNKNOWN' OR id = 'IN' OR id IS NULL) ORDER BY date DESC");
    $unknowns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($unknowns) > 0) {
        echo "NALEZENO: " . count($unknowns) . " případů\n";
        foreach ($unknowns as $row) {
            echo "  {$row['date']} | {$row['trans_type']} | Qty: {$row['amount']} | Price: {$row['price']} {$row['currency']} | Note: {$row['notes']}\n";
        }
    } else {
        echo "OK: Žádné neznámé tickery.\n";
    }
    echo "\n";

    // 3. Nulové nebo divné množství u akcií (Buy/Sell)
    echo "--- 2. Podezřelé množství (Buy/Sell Stocks <= 0) ---\n";
    $stmt = $pdo->query("SELECT id, date, trans_type, amount, price FROM broker_trans WHERE platform = 'Fio' AND trans_type IN ('Buy', 'Sell') AND amount <= 0 ORDER BY date DESC");
    $badQty = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($badQty) > 0) {
         echo "NALEZENO: " . count($badQty) . " případů\n";
         foreach ($badQty as $row) {
             echo "  {$row['date']} | {$row['id']} | {$row['trans_type']} | Qty: {$row['amount']}\n";
         }
    } else {
        echo "OK: Žádné nulové obchody.\n";
    }
    echo "\n";

    // 4. Nulová cena
    echo "--- 3. Nulová cena ---\n";
    $stmt = $pdo->query("SELECT id, date, trans_type, amount FROM broker_trans WHERE platform = 'Fio' AND price = 0 AND trans_type IN ('Buy', 'Sell', 'Dividend') ORDER BY date DESC");
    $zeroPrice = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($zeroPrice) > 0) {
        echo "NALEZENO: " . count($zeroPrice) . " případů\n";
         foreach ($zeroPrice as $row) {
             echo "  {$row['date']} | {$row['id']} | {$row['trans_type']} | Qty: {$row['amount']}\n";
         }
    } else {
        echo "OK: Žádné transakce s nulovou cenou.\n";
    }
    echo "\n";

    // 5. Obchody bez poplatků
    echo "--- 4. Obchody bez poplatků (Buy/Sell fees = 0) ---\n";
    $stmt = $pdo->query("SELECT id, date, trans_type, amount FROM broker_trans WHERE platform = 'Fio' AND trans_type IN ('Buy', 'Sell') AND fees = 0 ORDER BY date DESC LIMIT 10");
    $zeroFees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($zeroFees) > 0) {
        echo "NALEZENO: " . count($zeroFees) . " (zobrazeno max 10)\n";
         foreach ($zeroFees as $row) {
             echo "  {$row['date']} | {$row['id']} | {$row['trans_type']}\n";
         }
         echo "  (Může být OK pro malé obchody nebo specifické trhy, ale stojí za kontrolu)\n";
    } else {
        echo "OK: Všechny obchody mají poplatky.\n";
    }
    echo "\n";

    // 6. Analýza Dividends
    echo "--- 5. Kvalita Dividend ---\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM broker_trans WHERE platform = 'Fio' AND trans_type = 'Dividend' AND amount = 1.0");
    $fallbackDividends = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM broker_trans WHERE platform = 'Fio' AND trans_type = 'Dividend' AND amount > 1.0");
    $goodDividends = $stmt->fetchColumn();

    echo "Dividendy s množstvím = 1 (Fallback/Nerozpoznané množství): $fallbackDividends\n";
    echo "Dividendy s množstvím > 1 (Úspěšně parsované množství): $goodDividends\n";

    if ($fallbackDividends > 0) {
        echo "Vypisuji posledních 5 'Fallback' dividend:\n";
        $stmt = $pdo->query("SELECT date, id, price, amount_cur FROM broker_trans WHERE platform = 'Fio' AND trans_type = 'Dividend' AND amount = 1.0 ORDER BY date DESC LIMIT 5");
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['date']} | {$row['id']} | Price: {$row['price']} | Total: {$row['amount_cur']}\n";
        }
    }

} catch (Exception $e) {
    echo "Chyba: " . $e->getMessage() . "\n";
}
?>
