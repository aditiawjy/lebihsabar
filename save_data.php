<?php
header('Content-Type: application/json');
include 'koneksi.php';

// Memastikan tabel ada
checkAndCreateMatchesTable($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mengambil data JSON dari request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data || !is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid atau kosong']);
        exit;
    }

    // Menyiapkan statement untuk insert
    $stmt = $conn->prepare("INSERT INTO matches (match_time, home_team, away_team, fh_home, fh_away, ft_home, ft_away) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan statement: ' . $conn->error]);
        exit;
    }

    $success_count = 0;
    $errors = [];

    foreach ($data as $index => $match) {
        // Format waktu pertandingan ke format MySQL DATETIME
        $match_time = date('Y-m-d H:i:s', strtotime($match['datetime']));
        $home_team = $match['homeClub'];
        $away_team = $match['awayClub'];
        $fh_home = (int)$match['halfTime']['home'];
        $fh_away = (int)$match['halfTime']['away'];
        $ft_home = (int)$match['fullTime']['home'];
        $ft_away = (int)$match['fullTime']['away'];
        
        $stmt->bind_param("sssiiii", $match_time, $home_team, $away_team, $fh_home, $fh_away, $ft_home, $ft_away);
        
        if ($stmt->execute()) {
            $success_count++;
        } else {
            $errors[] = "Pertandingan " . ($index + 1) . ": " . $stmt->error;
        }
    }
    
    $stmt->close();
    
    if ($success_count > 0) {
        echo json_encode([
            'success' => true, 
            'message' => "Berhasil menyimpan $success_count pertandingan.",
            'errors' => $errors
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Gagal menyimpan data pertandingan.',
            'errors' => $errors
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan']);
}

$conn->close();
?>
