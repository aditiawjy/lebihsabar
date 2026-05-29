<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/auth_guard.php';
guard_request(['POST']); // butuh X-API-Key + method POST

require_once 'koneksi.php';

try {
    if (!isset($conn) || !$conn || !empty($db_error)) {
        throw new Exception('Database connection failed');
    }

    // Get count before truncate
    $result = $conn->query("SELECT COUNT(*) as total FROM matches");
    $total = $result->fetch_assoc()['total'];
    
    // Truncate table
    $conn->query("TRUNCATE TABLE matches");
    
    echo json_encode([
        'success' => true,
        'message' => "✅ Berhasil menghapus $total pertandingan dari database!"
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
