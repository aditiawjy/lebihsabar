<?php
/**
 * API Endpoint untuk menerima data dari Chrome Extension msports-parser
 * Auto-insert/update ke table matches
 */
// Enable error logging to file
ini_set('log_errors', 1);
ini_set('error_log', 'sabar_error.log');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Custom logger
function sabar_log($msg) {
    $date = date('Y-m-d H:i:s');
    file_put_contents('sabar_debug.log', "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// Set timezone ke WIB (UTC+7)
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json');
function cors_allowed_origins() {
    $raw = getenv('CORS_ALLOWED_ORIGINS') ?: '';
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function apply_cors_headers() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = cors_allowed_origins();
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

function require_api_key() {
    $apiToken = getenv('API_TOKEN') ?: '';
    if ($apiToken === '') {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server API token is not configured.'
        ]);
        exit;
    }

    $providedToken = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($providedToken === '' || !hash_equals($apiToken, $providedToken)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ]);
        exit;
    }
}

apply_cors_headers();

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

require_api_key();

// Ensure DB connection stays alive
function ensure_db_connection($conn) {
    global $db_host, $db_user, $db_pass, $db_name, $db_port;
    $allowLocalInfile = getenv('DB_ALLOW_LOCAL_INFILE') === '1';

    if (!$conn || !$conn->ping()) {
        sabar_log("Reconnecting to database...");
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_LOCAL_INFILE, $allowLocalInfile ? 1 : 0);
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 30);
        $conn->options(MYSQLI_OPT_READ_TIMEOUT, 120);
        if (!$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port)) {
            throw new Exception("Database reconnect failed: " . mysqli_connect_error());
        }
        $conn->set_charset("utf8mb4");
        $conn->query("SET SESSION wait_timeout=300");
        sabar_log("Reconnected successfully");
    }

    return $conn;
}

function sabar_query($conn, $sql) {
    $result = $conn->query($sql);
    if ($result === false && in_array($conn->errno, [2006, 2013], true)) {
        $conn = ensure_db_connection($conn);
        $result = $conn->query($sql);
    }

    return [$conn, $result];
}

function normalize_team_key($team) {
    $value = strtolower(trim((string)$team));
    return preg_replace('/\s+/', ' ', $value);
}

function build_match_lock_key($homeTeam, $awayTeam, $datetime) {
    $teams = [normalize_team_key($homeTeam), normalize_team_key($awayTeam)];
    sort($teams, SORT_STRING);

    $ts = strtotime($datetime) ?: time();
    $bucket = (int)floor($ts / 600); // bucket 10 menit agar selaras dengan dedup window.

    return 'match_lock_' . sha1($teams[0] . '|' . $teams[1] . '|' . $bucket);
}

function acquire_match_lock($conn, $lockKey, $timeout = 2) {
    $timeout = max(1, (int)$timeout);
    $lockEsc = $conn->real_escape_string($lockKey);
    [$conn, $result] = sabar_query($conn, "SELECT GET_LOCK('$lockEsc', $timeout) AS lock_status");
    if (!$result) {
        return [$conn, false];
    }

    $row = $result->fetch_assoc();
    return [$conn, isset($row['lock_status']) && (int)$row['lock_status'] === 1];
}

function release_match_lock($conn, $lockKey) {
    $lockEsc = $conn->real_escape_string($lockKey);
    sabar_query($conn, "SELECT RELEASE_LOCK('$lockEsc')");
}

try {
    require_once 'koneksi.php';

    if (!isset($conn) || !$conn || !empty($db_error)) {
        throw new Exception('Database connection failed');
    }

    // Cek koneksi DB
    $conn = ensure_db_connection($conn);

    $json = file_get_contents('php://input');
    if (!$json) {
        throw new Exception("Tidak ada data yang diterima (Empty Input)");
    }
    
    // Log metadata request saja, bukan payload mentah.
    sabar_log('Received payload bytes: ' . strlen($json));

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Format JSON tidak valid: " . json_last_error_msg());
    }

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;

    if (!is_array($data)) {
        throw new Exception("Data harus berupa array");
    }

    foreach ($data as $leagueGroup) {
        $league = $leagueGroup['league'] ?? '';
        $items = $leagueGroup['items'] ?? [];
        
        foreach ($items as $match) {
            $timeStr = trim($match['time'] ?? '');
            $status = $match['status'] ?? '';
            $home_raw = $match['home_team'] ?? '';
            $away_raw = $match['away_team'] ?? '';

            // Debug log disabled untuk performa
            // sabar_log("Processing: time='$timeStr' status='$status' home='$home_raw' away='$away_raw'");

            if (empty($timeStr)) {
                sabar_log("Skipped: empty time");
                $skipped++;
                continue;
            }
            
            // --- TIME PARSING LOGIC ---
            $today = date('Y-m-d');
            $datetime = null;

            // Clean timeStr: hapus karakter tak terlihat (nbsp, etc)
            $timeStr = preg_replace('/\xc2\xa0/', ' ', $timeStr); // nbsp
            $timeStr = preg_replace('/\s+/', ' ', $timeStr); // multiple spaces
            $timeStr = trim($timeStr);

            // 1. Format lengkap: YYYY-MM-DD HH:MM AM/PM (contoh: 2026-01-15 12:00 PM)
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})\s*([AP]M)$/i', $timeStr, $m)) {
                $datepart = $m[1];
                $timepart = $m[2] . ' ' . $m[3];
                $ts = strtotime("$datepart $timepart");
                if ($ts) $datetime = date('Y-m-d H:i:s', $ts);
            }
            // 2. Coba format HH:MM (24h)
            else if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $m)) {
                $hour = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $minute = $m[2];
                $datetime = "$today $hour:$minute:00";
            }
            // 3. Coba format HH:MM AM/PM
            else if (preg_match('/^(\d{1,2}):(\d{2})\s*([AP]M)$/i', $timeStr, $m)) {
                $ts = strtotime("$today $timeStr");
                if ($ts) $datetime = date('Y-m-d H:i:s', $ts);
            }
            // 4. Fallback: strtotime standar
            else {
                $ts = strtotime($timeStr);
                if ($ts) $datetime = date('Y-m-d H:i:s', $ts);
            }
            
            if (!$datetime) {
                sabar_log("Failed to parse time: '$timeStr' (len=" . strlen($timeStr) . ")");
                $skipped++;
                continue;
            }

            // sabar_log("Parsed datetime: $datetime (from '$timeStr')");

            // Handle Cross-Day Logic (Simple)
            // Jika waktu match > waktu sekarang + 12 jam, mungkin itu kemarin? 
            // Atau jika waktu match < waktu sekarang - 12 jam, mungkin itu besok?
            // Untuk amannya, kita percaya tanggal hari ini dulu, karena Msports biasanya menampilkan "Today"
            
            // --- TEAM PARSING ---
            $home_team = trim($match['home_team'] ?? '');
            $away_team = trim($match['away_team'] ?? '');
            $raw_teams = trim($match['raw_teams'] ?? '');

            if (empty($home_team) || empty($away_team)) {
                if (!empty($raw_teams)) {
                    $parts = preg_split('/\r?\n/', $raw_teams);
                    if (count($parts) < 2) {
                        $parts = preg_split('/\s+v\s+/i', $raw_teams);
                    }
                    if (count($parts) < 2) {
                        $parts = preg_split('/\s+vs\.?\s+/i', $raw_teams);
                    }

                    if (count($parts) >= 2) {
                        $home_team = trim($parts[0]);
                        $away_team = trim($parts[1]);
                    }
                }
            }

            if (empty($home_team) || empty($away_team)) {
                sabar_log("Skipped (missing team): time=$timeStr status=$status raw=$raw_teams");
                $skipped++;
                continue;
            }
            
            // --- SCORE PARSING ---
            $score_fh = $match['score_fh'] ?? '-';
            $score_ft = $match['score_ft'] ?? '-';
            
            $fh_home = null; $fh_away = null;
            $ft_home = null; $ft_away = null;
            
            if (preg_match('/^(\d+)\s*[-:]\s*(\d+)$/', $score_fh, $m)) {
                $fh_home = (int)$m[1];
                $fh_away = (int)$m[2];
            }
            
            if (preg_match('/^(\d+)\s*[-:]\s*(\d+)$/', $score_ft, $m)) {
                $ft_home = (int)$m[1];
                $ft_away = (int)$m[2];
            }
            
            // --- DB OPERATIONS ---
            $home_team_esc = $conn->real_escape_string($home_team);
            $away_team_esc = $conn->real_escape_string($away_team);
            $league_esc = $conn->real_escape_string($league);
            $lockKey = build_match_lock_key($home_team, $away_team, $datetime);

            [$conn, $lockAcquired] = acquire_match_lock($conn, $lockKey, 2);
            if (!$lockAcquired) {
                sabar_log("Skipped: could not acquire lock for key=$lockKey");
                $skipped++;
                continue;
            }
            
            try {
                // Cek Duplikat
                // Strategi: Cari match dengan TIM SAMA dalam rentang waktu +/- 10 MENIT
                // Range ketat karena virtual match bisa repeat setiap 15-20 menit dengan tim sama
                // 10 menit cukup untuk toleransi perbedaan waktu parsing
                $check_sql = "SELECT id, match_time, ft_home, ft_away, fh_home, fh_away
                              FROM matches
                              WHERE ((home_team = '$home_team_esc' AND away_team = '$away_team_esc')
                                   OR (home_team = '$away_team_esc' AND away_team = '$home_team_esc'))
                              AND match_time >= DATE_SUB('$datetime', INTERVAL 10 MINUTE)
                              AND match_time <= DATE_ADD('$datetime', INTERVAL 10 MINUTE)
                              ORDER BY ABS(TIMESTAMPDIFF(SECOND, match_time, '$datetime')) ASC
                              LIMIT 1";

                [$conn, $result] = sabar_query($conn, $check_sql);

                if (!$result) {
                    sabar_log("SQL Error (Check): " . $conn->error);
                    $errors++;
                    continue;
                }

                $formatVal = function($val) { return ($val === null) ? 'NULL' : (int)$val; };

                if ($result->num_rows > 0) {
                    // UPDATE
                    $existing = $result->fetch_assoc();

                    $fields = [
                        'fh_home' => $fh_home, 'fh_away' => $fh_away,
                        'ft_home' => $ft_home, 'ft_away' => $ft_away
                    ];

                    $should_update = false;
                    foreach ($fields as $key => $newVal) {
                        $oldVal = $existing[$key];
                        if ($newVal === null) continue;
                        if ($oldVal === null || (int)$oldVal !== (int)$newVal) {
                            $should_update = true;
                            break;
                        }
                    }

                    if ($should_update) {
                        $sql = "UPDATE matches SET " .
                               "fh_home = " . $formatVal($fh_home) . ", " .
                               "fh_away = " . $formatVal($fh_away) . ", " .
                               "ft_home = " . $formatVal($ft_home) . ", " .
                               "ft_away = " . $formatVal($ft_away) . ", " .
                               "league = '$league_esc', " .
                               "updated_at = NOW() " .
                               "WHERE id = " . $existing['id'];

                        [$conn, $updateResult] = sabar_query($conn, $sql);
                        if ($updateResult) {
                            $updated++;
                        } else {
                            sabar_log("SQL Error (Update): " . $conn->error);
                            $errors++;
                        }
                    } else {
                        $skipped++;
                    }
                } else {
                    // INSERT
                    $sql = "INSERT INTO matches (match_time, home_team, away_team, league, fh_home, fh_away, ft_home, ft_away) VALUES " .
                           "('$datetime', '$home_team_esc', '$away_team_esc', '$league_esc', " .
                           $formatVal($fh_home) . ", " . $formatVal($fh_away) . ", " .
                           $formatVal($ft_home) . ", " . $formatVal($ft_away) . ")";

                    [$conn, $insertResult] = sabar_query($conn, $sql);
                    if ($insertResult) {
                        $inserted++;
                    } else {
                        sabar_log("SQL Error (Insert): " . $conn->error);
                        $errors++;
                    }
                }
            } finally {
                release_match_lock($conn, $lockKey);
            }
        }
    }

    $msg = "Synced: $inserted new, $updated updated, $skipped skipped, $errors errors";
    sabar_log($msg);
    
    echo json_encode([
        'success' => true,
        'result' => [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => $msg
        ]
    ]);

} catch (Exception $e) {
    sabar_log("FATAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
