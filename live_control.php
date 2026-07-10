<?php
// Proxy kontrol scraper: teruskan status/start/stop ke api_server (port 5000).
header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'status';
$map = [
    'status'    => ['path' => 'scraper/status',  'method' => 'GET'],
    'start'     => ['path' => 'scraper/start',   'method' => 'POST'],
    'stop'      => ['path' => 'scraper/stop',    'method' => 'POST'],
    'tg_status' => ['path' => 'telegram/status', 'method' => 'GET'],
    'tg_test'   => ['path' => 'test-telegram',   'method' => 'POST'],
];
$cfg = $map[$action] ?? $map['status'];
$url = 'http://127.0.0.1:5000/api/' . $cfg['path'];

$httpOpts = [
    'method' => $cfg['method'],
    'timeout' => 20,
    'ignore_errors' => true,
    'header' => "Content-Type: application/json\r\n",
];
if ($cfg['method'] === 'POST') {
    $body = file_get_contents('php://input');
    $httpOpts['content'] = ($body !== false && $body !== '') ? $body : '{}';
}

$response = @file_get_contents($url, false, stream_context_create(['http' => $httpOpts]));

if ($response === false) {
    echo json_encode(['success' => false, 'running' => false, 'error' => 'API server offline (jalankan start_headless.bat)']);
    exit;
}

echo $response;
