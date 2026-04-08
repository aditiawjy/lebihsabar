<?php
header('Content-Type: application/json');

require_once 'koneksi.php';

try {
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
