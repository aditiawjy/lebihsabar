<?php
// Start live-scraper api_server.py via bat file
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$batFile = __DIR__ . '/live-scraper/start_api_server.bat';

if (!file_exists($batFile)) {
    echo json_encode(['success' => false, 'error' => 'File not found: ' . $batFile]);
    exit;
}

// Check if already running
$check = @file_get_contents('http://127.0.0.1:5000/api/status');
if ($check !== false) {
    echo json_encode(['success' => true, 'already_running' => true, 'message' => 'API sudah berjalan']);
    exit;
}

// Launch bat file detached (non-blocking)
$cmd = 'start "" /B cmd /c "' . $batFile . '"';
pclose(popen($cmd, 'r'));

// Wait a moment then check
sleep(2);
$check = @file_get_contents('http://127.0.0.1:5000/api/status');
if ($check !== false) {
    echo json_encode(['success' => true, 'message' => 'API berhasil dijalankan']);
} else {
    echo json_encode(['success' => true, 'message' => 'Perintah dikirim, tunggu beberapa detik...']);
}
