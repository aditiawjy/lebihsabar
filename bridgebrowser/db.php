<?php
/**
 * Koneksi Database (PDO) untuk bridgebrowser.
 *
 * Kredensial WAJIB diambil dari environment variable.
 * Jangan pernah hardcode host/user/password di file ini.
 *
 * Set variabel berikut di environment server (mis. Apache SetEnv,
 * systemd, panel hosting, atau file .env yang di-load sebelum PHP):
 *   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT (opsional, default 3306)
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_name = getenv('DB_NAME') ?: '';
$db_user = getenv('DB_USER') ?: '';
$db_pass = getenv('DB_PASSWORD') ?: '';
$db_port = (int)(getenv('DB_PORT') ?: 3306);

if ($db_name === '' || $db_user === '') {
    http_response_code(500);
    error_log('bridgebrowser/db.php: Missing DB_NAME or DB_USER in environment.');
    echo json_encode([
        'success' => false,
        'error'   => 'Database not configured. Set DB_HOST, DB_NAME, DB_USER, DB_PASSWORD in environment.',
    ]);
    exit;
}

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $conn = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Jangan bocorkan detail koneksi ke client; cukup log di server.
    http_response_code(500);
    error_log('bridgebrowser/db.php: Connection failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Database connection failed.',
    ]);
    exit;
}
