<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$apiUrl = 'http://127.0.0.1:5000/api/live-data';

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 5,
        'ignore_errors' => true,
    ],
]);

$response = @file_get_contents($apiUrl, false, $context);

if ($response === false) {
    echo json_encode([
        'online' => false,
        'matches' => [],
        'count' => 0,
        'timestamp' => date('c'),
        'error' => 'API offline',
    ]);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    echo json_encode([
        'online' => false,
        'matches' => [],
        'count' => 0,
        'timestamp' => date('c'),
        'error' => 'Invalid API response',
    ]);
    exit;
}

$data['online'] = true;
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
