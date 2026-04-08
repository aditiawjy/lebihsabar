<?php
/**
 * Konfigurasi Koneksi Database
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Selalu ambil kredensial dari environment.
// Nilai default hanya untuk host/port agar tidak mengandung secret di source code.
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_user = getenv('DB_USER') ?: '';
$db_pass = getenv('DB_PASSWORD') ?: '';
$db_name = getenv('DB_NAME') ?: '';
$db_port = (int)(getenv('DB_PORT') ?: 3306);

$allowLocalInfile = getenv('DB_ALLOW_LOCAL_INFILE') === '1';

// Membuat koneksi menggunakan mysqli
$conn = mysqli_init();
$conn->options(MYSQLI_OPT_LOCAL_INFILE, $allowLocalInfile ? 1 : 0);
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 30);
$conn->options(MYSQLI_OPT_READ_TIMEOUT, 120);

if ($db_user === '' || $db_name === '') {
    $db_error = 'Missing DB_USER or DB_NAME in environment.';
} elseif (!$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port)) {
    // Jangan output HTML, simpan error saja
    $db_error = mysqli_connect_error();
} else {
    // Set charset ke utf8mb4 untuk mendukung karakter khusus
    $conn->set_charset('utf8mb4');
    // Set wait_timeout agar koneksi tidak putus
    $conn->query('SET SESSION wait_timeout=300');
    $conn->query('SET SESSION interactive_timeout=300');
}

// Fungsi untuk membuat tabel jika belum ada (opsional, untuk memastikan struktur database siap)
function checkAndCreateMatchesTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS matches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        match_time DATETIME,
        home_team VARCHAR(255),
        away_team VARCHAR(255),
        league VARCHAR(255) DEFAULT NULL,
        fh_home INT,
        fh_away INT,
        ft_home INT,
        ft_away INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    return $conn->query($sql);
}

// Fungsi untuk membuat index optimal pada tabel matches
function createMatchesIndexes($conn) {
    $indexes = [
        'idx_match_time' => 'match_time',
        'idx_ft_home_match_time' => 'ft_home, match_time',
        'idx_league_match_time' => 'league, match_time',
        'idx_home_team_match_time' => 'home_team, match_time',
        'idx_away_team_match_time' => 'away_team, match_time'
    ];

    $created = 0;
    foreach ($indexes as $index_name => $columns) {
        // Cek apakah index sudah ada
        $check = $conn->query("SHOW INDEX FROM matches WHERE Key_name = '$index_name'");
        if ($check && $check->num_rows == 0) {
            $sql = "CREATE INDEX $index_name ON matches($columns)";
            if ($conn->query($sql)) {
                $created++;
            }
        }
    }
    return $created;
}
?>
