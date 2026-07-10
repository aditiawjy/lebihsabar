<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'koneksi.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'matches_data_helper.php';

header('Content-Type: application/json');

$payload = sabarajaDataBuildSummaryPayload($conn ?? null, $db_error ?? '');

if (($payload['dataSource'] ?? 'unavailable') === 'unavailable') {
    http_response_code(500);
}

echo json_encode($payload);

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
