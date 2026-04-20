<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function apiStatusOnline(): bool {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);
    $check = @file_get_contents('http://127.0.0.1:5000/api/status', false, $context);
    return $check !== false;
}

function resolvePythonExecutable(): ?string {
    $powershellPython = trim((string) @shell_exec('powershell -Command "(Get-Command python -ErrorAction SilentlyContinue).Source"'));
    $candidates = [
        $powershellPython,
        'C:\\Users\\user\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
        'python',
    ];

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        if (str_contains($candidate, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $candidate)) {
            if (file_exists($candidate)) {
                return $candidate;
            }
            continue;
        }

        $resolved = trim((string) @shell_exec('where ' . escapeshellarg($candidate) . ' 2>NUL'));
        if ($resolved !== '') {
            $first = trim(strtok($resolved, PHP_EOL));
            if ($first !== '') {
                return $first;
            }
        }
    }

    return null;
}

$apiScript = __DIR__ . '/live-scraper/api_server.py';
if (!file_exists($apiScript)) {
    echo json_encode(['success' => false, 'error' => 'File not found: ' . $apiScript]);
    exit;
}

if (apiStatusOnline()) {
    echo json_encode(['success' => true, 'already_running' => true, 'message' => 'API sudah berjalan']);
    exit;
}

$pythonExe = resolvePythonExecutable();
if (!$pythonExe) {
    echo json_encode(['success' => false, 'error' => 'Python executable tidak ditemukan untuk menjalankan api_server.py']);
    exit;
}

$workingDir = __DIR__ . '/live-scraper';
$command = 'powershell -Command "Start-Process -FilePath ' . "'" . str_replace("'", "''", $pythonExe) . "'" . ' -ArgumentList ' . "'api_server.py'" . ' -WorkingDirectory ' . "'" . str_replace("'", "''", $workingDir) . "'" . ' -WindowStyle Hidden"';
@pclose(@popen($command, 'r'));

for ($i = 0; $i < 8; $i++) {
    usleep(500000);
    if (apiStatusOnline()) {
        echo json_encode(['success' => true, 'message' => 'API berhasil dijalankan']);
        exit;
    }
}

echo json_encode([
    'success' => false,
    'error' => 'Proses start dikirim, tetapi API belum merespons di port 5000.',
    'message' => 'API belum online. Cek Python/server log atau jalankan manual dari live-scraper/api_server.py',
]);
