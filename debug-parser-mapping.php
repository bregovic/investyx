<?php
/**
 * Debug script to test Revolut parser mapping extraction
 * Shows what the parser found in the PDF
 */

header('Content-Type: application/json');

// Read last import response or a sample
$testFile = __DIR__ . '/Výpisy/2025 REVOLUT/výpis tom revolut.pdf';

if (!file_exists($testFile)) {
    echo json_encode(['error' => 'PDF file not found', 'path' => $testFile]);
    exit;
}

// Use PDF.js or simple text extraction
// For now, let's check what was sent in last import
echo json_encode([
    'status' => 'Parser debugging',
    'note' => 'Check browser console during import for parser output',
    'instructions' => [
        '1. Open import.php',
        '2. Open browser DevTools (F12)',
        '3. Go to Console tab',
        '4. Import the Revolut PDF',
        '5. Look for console.log messages from parser',
        '6. Share screenshot of console output'
    ],
    'also_check' => 'Check if transactions have isin and company_name fields in import response'
]);
