<?php
header('Content-Type: application/json');

require_once __DIR__ . '/auth_guard.php';
guard_same_origin_request(['GET', 'POST']); // anti-CSRF, dipanggil dari frontend

// Kill python process running api_server.py on port 5000
// Find PID listening on port 5000
$output = [];
exec('netstat -ano | findstr :5000', $output);

$killed = 0;
foreach ($output as $line) {
    if (preg_match('/\s+(\d+)$/', trim($line), $m)) {
        $pid = $m[1];
        if ($pid && $pid > 4) {
            exec("taskkill /PID $pid /F 2>&1");
            $killed++;
        }
    }
}

if ($killed > 0) {
    echo json_encode(['success' => true, 'message' => "API dihentikan ($killed proses)."]);
} else {
    echo json_encode(['success' => false, 'message' => 'Tidak ada proses yang ditemukan di port 5000.']);
}
