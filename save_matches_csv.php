<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: application/json');
require_once __DIR__.'/sync-history-lib.php';

$CSV_FILE = getenv('MATCHES_CSV_FILE') ?: (__DIR__ . '/matches.csv');
$MAX_MATCHES_PER_REQUEST = (int)(getenv('MAX_MATCHES_PER_REQUEST') ?: 5000);
$MAX_CSV_BACKUPS = (int)(getenv('MAX_CSV_BACKUPS') ?: 20);
$CSV_HEADER = ['id', 'match_time', 'home_team', 'away_team', 'league', 'fh_home', 'fh_away', 'ft_home', 'ft_away', 'created_at', 'updated_at'];

function normalize_csv_value($value) {
    $text = trim((string)$value);
    return preg_replace('/\s+/', ' ', $text);
}

function parse_match_datetime($value) {
    $ts = strtotime((string)$value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function parse_score_or_empty($value) {
    if ($value === null) {
        return '';
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    return is_numeric($text) ? (int)$text : '';
}

function build_duplicate_key($matchTime, $home, $away, $league) {
    // API and manual imports can differ only in the virtual-team suffix.
    $home = preg_replace('/\s*\(V\)/i', '', (string)$home);
    $away = preg_replace('/\s*\(V\)/i', '', (string)$away);
    return strtolower(
        normalize_csv_value($matchTime) . '|' .
        normalize_csv_value($home) . '|' .
        normalize_csv_value($away) . '|' .
        normalize_csv_value($league)
    );
}

function read_existing_rows($csvFile, $lockedHandle = null) {
    global $CSV_HEADER;

    $existingRows = [];
    $rows = [];
    $maxId = 0;

    if (!file_exists($csvFile)) {
        return [$existingRows, $rows, $maxId, $CSV_HEADER];
    }

    $fh = $lockedHandle ?: fopen($csvFile, 'r');
    if (!$fh) {
        throw new Exception('Tidak bisa membaca matches.csv');
    }

    rewind($fh);
    $header = fgetcsv($fh);
    if (!is_array($header) || count($header) === 0) {
        $header = $CSV_HEADER;
    }

    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 9) {
            continue;
        }

        $id = (int)($row[0] ?? 0);
        $matchTime = $row[1] ?? '';
        $home = $row[2] ?? '';
        $away = $row[3] ?? '';
        $league = $row[4] ?? '';

        if ($id > $maxId) {
            $maxId = $id;
        }

        $normalizedRow = array_pad($row, count($header), '');
        $rowIndex = count($rows);
        $rows[] = $normalizedRow;
        $existingRows[build_duplicate_key($matchTime, $home, $away, $league)] = $rowIndex;
    }
    if (!$lockedHandle) fclose($fh);

    return [$existingRows, $rows, $maxId, $header];
}

function score_value_changed($oldValue, $newValue) {
    $old = trim((string)$oldValue);
    $new = trim((string)$newValue);

    if ($old === '' && $new === '') {
        return false;
    }

    if ($old === '' || $new === '') {
        return true;
    }

    return (int)$old !== (int)$new;
}

function create_csv_backup($csvFile, $maxBackups) {
    if (!file_exists($csvFile) || filesize($csvFile) === 0) {
        return null;
    }

    $backupDir = __DIR__ . '/csv_backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new Exception('Tidak bisa membuat folder backup CSV');
    }

    $backupPath = $backupDir . '/matches_' . date('Ymd_His') . '.csv.bak';
    if (!copy($csvFile, $backupPath)) {
        throw new Exception('Gagal membuat backup CSV');
    }

    $maxBackups = max(1, (int)$maxBackups);
    $existingBackups = glob($backupDir . '/matches_*.csv.bak') ?: [];
    if (count($existingBackups) > $maxBackups) {
        usort($existingBackups, static function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });
        $toDelete = array_slice($existingBackups, 0, count($existingBackups) - $maxBackups);
        foreach ($toDelete as $oldBackup) {
            @unlink($oldBackup);
        }
    }

    return $backupPath;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new Exception('Metode request tidak diizinkan');
    }

    $json = file_get_contents('php://input');
    if (!$json) {
        http_response_code(400);
        throw new Exception('Tidak ada data yang diterima');
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        throw new Exception('Format JSON tidak valid');
    }
    if (empty($data['matches']) || !is_array($data['matches'])) {
        http_response_code(400);
        throw new Exception('Data matches kosong');
    }

    if (count($data['matches']) > $MAX_MATCHES_PER_REQUEST) {
        http_response_code(400);
        throw new Exception('Jumlah matches melebihi batas per request: ' . $MAX_MATCHES_PER_REQUEST);
    }


    $fileExists = file_exists($CSV_FILE);
    $fh = false;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $fh = @fopen($CSV_FILE, 'c+');
        if ($fh !== false) break;
        usleep(250000);
    }
    if (!$fh) {
        http_response_code(503);
        throw new Exception('matches.csv sedang digunakan proses lain. Tutup aplikasi yang membuka file CSV, lalu coba simpan kembali.');
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new Exception('Tidak bisa lock file matches.csv');
    }

    [$existingRows, $rows, $maxId, $header] = read_existing_rows($CSV_FILE, $fh);
    $offsetIndex = array_search('source_utc_offset', $header, true);
    foreach ($data['matches'] as $incoming) {
        if (($incoming['source_utc_offset'] ?? '') === '+08:00' && $offsetIndex === false) {
            $header[] = 'source_utc_offset';
            $offsetIndex = count($header) - 1;
            break;
        }
    }
    $backupPath = create_csv_backup($CSV_FILE, $MAX_CSV_BACKUPS);

    $inserted = 0;
    $updated = 0;
    $skippedDuplicate = 0;
    $skippedInvalid = 0;
    $invalidRows = [];
    $now = date('Y-m-d H:i:s');

    foreach ($data['matches'] as $idx => $match) {
        $matchTime = parse_match_datetime($match['match_time'] ?? '');
        $home = normalize_csv_value($match['home_team'] ?? '');
        $away = normalize_csv_value($match['away_team'] ?? '');
        $league = normalize_csv_value($match['league'] ?? '');

        if ($matchTime === null || $home === '' || $away === '') {
            $skippedInvalid++;
            if (count($invalidRows) < 20) {
                $invalidRows[] = 'Row ' . ($idx + 1) . ': required field kosong atau datetime invalid';
            }
            continue;
        }

        $key = build_duplicate_key($matchTime, $home, $away, $league);
        $fhHome = parse_score_or_empty($match['fh_home'] ?? '');
        $fhAway = parse_score_or_empty($match['fh_away'] ?? '');
        $ftHome = parse_score_or_empty($match['ft_home'] ?? '');
        $ftAway = parse_score_or_empty($match['ft_away'] ?? '');
        $sourceOffset = ($match['source_utc_offset'] ?? '') === '+08:00' ? '+08:00' : '';

        if (isset($existingRows[$key])) {
            $rowIndex = $existingRows[$key];
            $existingRow = $rows[$rowIndex];
            if (($match['preserve_missing_ht'] ?? false) === true && ($fhHome === '' || $fhAway === '')) {
                $fhHome = $existingRow[5] ?? '';
                $fhAway = $existingRow[6] ?? '';
            }

            $hasChanges = score_value_changed($existingRow[5] ?? '', $fhHome)
                || score_value_changed($existingRow[6] ?? '', $fhAway)
                || score_value_changed($existingRow[7] ?? '', $ftHome)
                || score_value_changed($existingRow[8] ?? '', $ftAway)
                || ($sourceOffset !== '' && ($existingRow[$offsetIndex] ?? '') !== $sourceOffset);

            if ($hasChanges) {
                $rows[$rowIndex][4] = $league;
                $rows[$rowIndex][5] = $fhHome;
                $rows[$rowIndex][6] = $fhAway;
                $rows[$rowIndex][7] = $ftHome;
                $rows[$rowIndex][8] = $ftAway;
                $rows[$rowIndex][10] = $now;
                if ($sourceOffset !== '') $rows[$rowIndex][$offsetIndex] = $sourceOffset;
                $updated++;
            } else {
                $skippedDuplicate++;
            }

            continue;
        }

        $maxId++;
        $rows[] = [
            $maxId,
            $matchTime,
            $home,
            $away,
            $league,
            $fhHome,
            $fhAway,
            $ftHome,
            $ftAway,
            $now,
            $now
        ];

        $existingRows[$key] = count($rows) - 1;
        if ($sourceOffset !== '') $rows[count($rows)-1][$offsetIndex] = $sourceOffset;
        $inserted++;
    }

    ftruncate($fh, 0);
    rewind($fh);

    fputcsv($fh, $header ?: $CSV_HEADER);
    foreach ($rows as $row) {
        fputcsv($fh, array_pad($row, count($header ?: $CSV_HEADER), ''));
    }

    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $message = "BERHASIL: inserted=$inserted, updated=$updated, duplicate=$skippedDuplicate, invalid=$skippedInvalid";
    $scope = syncHistoryScope($data['matches']);
    $historySaved = syncHistoryAppend(array_merge($scope, ['status'=>$skippedInvalid ? 'partial' : 'success','source'=>'CSV','stage'=>'save', 'inserted'=>$inserted,'updated'=>$updated,'unchanged'=>$skippedDuplicate,'invalid'=>$skippedInvalid]));

    ob_clean();
    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped_duplicate' => $skippedDuplicate,
        'skipped_invalid' => $skippedInvalid,
        'history_saved' => $historySaved,
        'backup_file' => $backupPath,
        'invalid_rows' => $invalidRows,
        'message' => $message
    ]);
} catch (Exception $e) {
    if (!empty($data['matches']) && is_array($data['matches'])) syncHistoryAppend(array_merge(syncHistoryScope($data['matches']), ['status'=>'failed','source'=>'CSV','stage'=>'save','reason'=>'unknown']));
    ob_clean();
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan: ' . $e->getMessage()
    ]);
}
?>
