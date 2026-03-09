<?php
/**
 * Script untuk menambahkan Index pada Tabel Matches
 * Untuk mempercepat query next/last match dan agregasi
 */

require_once 'koneksi.php';

echo "==============================================\n";
echo "Menambahkan Index pada Tabel Matches\n";
echo "==============================================\n\n";

// Array index yang akan dibuat
$indexes = [
    'idx_match_time' => 'match_time',
    'idx_ft_home_match_time' => 'ft_home, match_time',
    'idx_league_match_time' => 'league, match_time',
    'idx_home_team_match_time' => 'home_team, match_time',
    'idx_away_team_match_time' => 'away_team, match_time'
];

$success_count = 0;
$skip_count = 0;

foreach ($indexes as $index_name => $columns) {
    // Cek apakah index sudah ada
    $check_sql = "SHOW INDEX FROM matches WHERE Key_name = '$index_name'";
    $result = $conn->query($check_sql);

    if ($result && $result->num_rows > 0) {
        echo "⏭  Index '$index_name' sudah ada, dilewati.\n";
        $skip_count++;
        continue;
    }

    // Buat index
    $sql = "CREATE INDEX $index_name ON matches($columns)";

    if ($conn->query($sql)) {
        echo "✓ Index '$index_name' berhasil dibuat pada kolom: $columns\n";
        $success_count++;
    } else {
        echo "✗ Gagal membuat index '$index_name': " . $conn->error . "\n";
    }
}

echo "\n==============================================\n";
echo "Selesai!\n";
echo "Index baru dibuat: $success_count\n";
echo "Index sudah ada: $skip_count\n";
echo "==============================================\n\n";

// Tampilkan semua index yang ada
echo "Daftar Index pada Tabel Matches:\n";
echo "==============================================\n";

$sql = "SHOW INDEX FROM matches";
$result = $conn->query($sql);

if ($result) {
    $current_index = '';
    while ($row = $result->fetch_assoc()) {
        if ($current_index != $row['Key_name']) {
            $current_index = $row['Key_name'];
            echo "\n" . $row['Key_name'] . ":\n";
        }
        echo "  - " . $row['Column_name'] . "\n";
    }
}

echo "\n==============================================\n";

$conn->close();
?>
