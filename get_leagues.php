<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

$CSV_FILE = __DIR__ . '/matches.csv';

try {
    $leagues = [];

    if (file_exists($CSV_FILE)) {
        $fh = fopen($CSV_FILE, 'r');
        fgetcsv($fh); // skip header
        while (($row = fgetcsv($fh)) !== false) {
            // kolom index 4 = league
            if (isset($row[4]) && $row[4] !== '') {
                $leagues[$row[4]] = true;
            }
        }
        fclose($fh);
        $leagues = array_keys($leagues);
        sort($leagues);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'leagues' => $leagues
    ]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'leagues' => [],
        'error' => $e->getMessage()
    ]);
}
?>
