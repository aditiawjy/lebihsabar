<?php
// Update jika waktu dan tim sama, insert jika baru
// Matikan output error ke browser agar tidak merusak JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Tangkap output buffer
ob_start();

header('Content-Type: application/json');

require_once 'koneksi.php';

try {
    // Get data
    $json = file_get_contents('php://input');
    if (!$json) {
        throw new Exception("Tidak ada data yang diterima");
    }
    
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Format JSON tidak valid");
    }

    $inserted = 0;
    $updated = 0;

    if (isset($data['matches']) && is_array($data['matches'])) {
        foreach ($data['matches'] as $match) {
            // Convert datetime
            $date = DateTime::createFromFormat('Y-m-d h:i A', $match['match_time']);
            // Fallback jika format beda atau gagal parse
            if ($date) {
                $datetime = $date->format('Y-m-d H:i:s');
            } else {
                // Coba strtotime sebagai fallback
                $ts = strtotime($match['match_time']);
                $datetime = $ts ? date('Y-m-d H:i:s', $ts) : $match['match_time'];
            }
            
            // Escape values
            $home_team = $conn->real_escape_string($match['home_team']);
            $away_team = $conn->real_escape_string($match['away_team']);
            $league = $conn->real_escape_string($match['league'] ?? '');
            
            // Check if match exists with same teams AND same datetime (exact match)
            $check_sql = "SELECT id, match_time, ft_home, ft_away, fh_home, fh_away 
                          FROM matches 
                          WHERE ((home_team = '$home_team' AND away_team = '$away_team') 
                               OR (home_team = '$away_team' AND away_team = '$home_team'))
                          AND match_time = '$datetime'";
            
            $result = $conn->query($check_sql);
            
            if ($result && $result->num_rows > 0) {
                // Exact match exists (same teams + same datetime) - update scores
                $existing = $result->fetch_assoc();
                
                // Helper untuk handle NULL value di SQL
                $formatVal = function($val) {
                    return ($val === null || $val === '') ? 'NULL' : (int)$val;
                };

                $fh_home = $formatVal($match['fh_home'] ?? null);
                $fh_away = $formatVal($match['fh_away'] ?? null);
                $ft_home = $formatVal($match['ft_home'] ?? null);
                $ft_away = $formatVal($match['ft_away'] ?? null);
                
                // Logic update: Cek apakah ada perubahan skor
                $should_update = false;
                
                // Bandingkan field per field
                $fields = [
                    'fh_home' => $match['fh_home'] ?? null,
                    'fh_away' => $match['fh_away'] ?? null,
                    'ft_home' => $match['ft_home'] ?? null,
                    'ft_away' => $match['ft_away'] ?? null
                ];
                
                foreach ($fields as $key => $newVal) {
                    $oldVal = $existing[$key];
                    
                    // Normalisasi ke null jika kosong
                    if ($newVal === '') $newVal = null;
                    
                    // Jika oldVal null dan newVal tidak null -> Update
                    if (is_null($oldVal) && !is_null($newVal)) {
                        $should_update = true;
                        break;
                    }
                    
                    // Jika keduanya tidak null dan berbeda -> Update
                    if (!is_null($oldVal) && !is_null($newVal) && (int)$oldVal !== (int)$newVal) {
                        $should_update = true;
                        break;
                    }
                }
                
                if ($should_update) {
                    $sql = "UPDATE matches SET " .
                           "fh_home = $fh_home, " .
                           "fh_away = $fh_away, " .
                           "ft_home = $ft_home, " .
                           "ft_away = $ft_away, " .
                           "league = '$league' " .
                           "WHERE id = " . $existing['id'];
                    
                    if ($conn->query($sql)) {
                        $updated++;
                    }
                }
            } else {
                // New match - insert
                $formatVal = function($val) {
                    return ($val === null || $val === '') ? 'NULL' : (int)$val;
                };

                $fh_home = $formatVal($match['fh_home'] ?? null);
                $fh_away = $formatVal($match['fh_away'] ?? null);
                $ft_home = $formatVal($match['ft_home'] ?? null);
                $ft_away = $formatVal($match['ft_away'] ?? null);
                
                $sql = "INSERT INTO matches (match_time, home_team, away_team, league, fh_home, fh_away, ft_home, ft_away) VALUES " .
                       "('$datetime', '$home_team', '$away_team', '$league', " .
                       "$fh_home, $fh_away, $ft_home, $ft_away)";
                
                if ($conn->query($sql)) {
                    $inserted++;
                }
            }
        }
    }

    $message = [];
    if ($inserted > 0) $message[] = "$inserted pertandingan baru ditambahkan";
    if ($updated > 0) $message[] = "$updated pertandingan diperbarui";
    if (empty($message)) $message[] = "Tidak ada perubahan data";

    // Bersihkan buffer output sebelum kirim JSON
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'message' => "✅ BERHASIL! " . implode(', ', $message),
        'refreshLeagues' => true
    ]);

} catch (Exception $e) {
    // Tangkap error dan kirim sebagai JSON
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}
?>