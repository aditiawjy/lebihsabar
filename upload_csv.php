<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$TARGET_CSV = __DIR__ . '/matches.csv';
$MAX_UPLOAD_BYTES = (int)(getenv('MAX_CSV_UPLOAD_BYTES') ?: 5242880); // 5MB default
$MAX_CSV_BACKUPS = (int)(getenv('MAX_CSV_BACKUPS') ?: 20);

function normalize_csv_text($value) {
    $text = trim((string)$value);
    return preg_replace('/\s+/', ' ', $text);
}

function parse_nullable_int($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    return is_numeric($text) ? (int)$text : '';
}

function parse_match_time($value) {
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function build_row_key($matchTime, $homeTeam, $awayTeam, $league) {
    return strtolower(
        normalize_csv_text($matchTime) . '|' .
        normalize_csv_text($homeTeam) . '|' .
        normalize_csv_text($awayTeam) . '|' .
        normalize_csv_text($league)
    );
}

function load_existing_keys_and_max_id($path) {
    $keys = [];
    $maxId = 0;

    if (!is_readable($path)) {
        return [$keys, $maxId];
    }

    $fh = fopen($path, 'r');
    if (!$fh) {
        return [$keys, $maxId];
    }

    fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 9) {
            continue;
        }
        $id = (int)($row[0] ?? 0);
        $matchTime = $row[1] ?? '';
        $homeTeam = $row[2] ?? '';
        $awayTeam = $row[3] ?? '';
        $league = $row[4] ?? '';
        if ($id > $maxId) {
            $maxId = $id;
        }
        $keys[build_row_key($matchTime, $homeTeam, $awayTeam, $league)] = true;
    }
    fclose($fh);

    return [$keys, $maxId];
}

function create_csv_backup($csvPath, $maxBackups) {
    if (!file_exists($csvPath) || filesize($csvPath) === 0) {
        return null;
    }

    $backupDir = __DIR__ . '/csv_backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new Exception('Cannot create backup directory');
    }

    $backupPath = $backupDir . '/matches_' . date('Ymd_His') . '.csv.bak';
    if (!copy($csvPath, $backupPath)) {
        throw new Exception('Failed to create CSV backup');
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
        throw new Exception('Method not allowed');
    }

    if (!isset($_FILES['csvFile'])) {
        http_response_code(400);
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['csvFile'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        throw new Exception('Upload error: ' . (int)$file['error']);
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $MAX_UPLOAD_BYTES) {
        http_response_code(400);
        throw new Exception('Invalid file size. Max bytes: ' . $MAX_UPLOAD_BYTES);
    }

    $originalName = strtolower((string)($file['name'] ?? ''));
    if (substr($originalName, -4) !== '.csv') {
        http_response_code(400);
        throw new Exception('Only .csv file is allowed');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMime = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        http_response_code(400);
        throw new Exception('Invalid MIME type: ' . $mime);
    }

    $uploaded = fopen($file['tmp_name'], 'r');
    if (!$uploaded) {
        throw new Exception('Cannot read uploaded file');
    }

    $rawHeader = fgetcsv($uploaded);
    if (!is_array($rawHeader) || count($rawHeader) === 0) {
        fclose($uploaded);
        http_response_code(400);
        throw new Exception('CSV header is missing');
    }

    $headerMap = [];
    foreach ($rawHeader as $idx => $name) {
        $headerMap[strtolower(trim((string)$name))] = $idx;
    }

    foreach (['match_time', 'home_team', 'away_team'] as $requiredField) {
        if (!array_key_exists($requiredField, $headerMap)) {
            fclose($uploaded);
            http_response_code(400);
            throw new Exception("Missing required column: $requiredField");
        }
    }

    [$existingKeys, $maxId] = load_existing_keys_and_max_id($TARGET_CSV);

    $targetExists = file_exists($TARGET_CSV);
    $target = fopen($TARGET_CSV, $targetExists ? 'a+' : 'w+');
    if (!$target) {
        fclose($uploaded);
        throw new Exception('Cannot open matches.csv');
    }

    if (!flock($target, LOCK_EX)) {
        fclose($uploaded);
        fclose($target);
        throw new Exception('Cannot lock matches.csv');
    }

    $backupPath = create_csv_backup($TARGET_CSV, $MAX_CSV_BACKUPS);

    if (!$targetExists || filesize($TARGET_CSV) === 0) {
        fputcsv($target, ['id', 'match_time', 'home_team', 'away_team', 'league', 'fh_home', 'fh_away', 'ft_home', 'ft_away', 'created_at', 'updated_at']);
    }

    $inserted = 0;
    $skippedDuplicate = 0;
    $skippedInvalid = 0;
    $errors = [];
    $now = date('Y-m-d H:i:s');

    while (($row = fgetcsv($uploaded)) !== false) {
        $matchTime = parse_match_time($row[$headerMap['match_time']] ?? '');
        $homeTeam = normalize_csv_text($row[$headerMap['home_team']] ?? '');
        $awayTeam = normalize_csv_text($row[$headerMap['away_team']] ?? '');
        $league = normalize_csv_text($row[$headerMap['league']] ?? '');

        if ($matchTime === null || $homeTeam === '' || $awayTeam === '') {
            $skippedInvalid++;
            if (count($errors) < 20) {
                $errors[] = 'Skipped invalid row: required value missing or bad datetime';
            }
            continue;
        }

        $key = build_row_key($matchTime, $homeTeam, $awayTeam, $league);
        if (isset($existingKeys[$key])) {
            $skippedDuplicate++;
            continue;
        }

        $maxId++;
        $fhHome = parse_nullable_int($row[$headerMap['fh_home']] ?? '');
        $fhAway = parse_nullable_int($row[$headerMap['fh_away']] ?? '');
        $ftHome = parse_nullable_int($row[$headerMap['ft_home']] ?? '');
        $ftAway = parse_nullable_int($row[$headerMap['ft_away']] ?? '');

        fputcsv($target, [
            $maxId,
            $matchTime,
            $homeTeam,
            $awayTeam,
            $league,
            $fhHome,
            $fhAway,
            $ftHome,
            $ftAway,
            $now,
            $now
        ]);

        $existingKeys[$key] = true;
        $inserted++;
    }

    fflush($target);
    flock($target, LOCK_UN);
    fclose($target);
    fclose($uploaded);

    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'skipped_duplicate' => $skippedDuplicate,
        'skipped_invalid' => $skippedInvalid,
        'backup_file' => $backupPath,
        'errors' => $errors,
        'message' => "Berhasil import CSV: inserted=$inserted, duplicate=$skippedDuplicate, invalid=$skippedInvalid"
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
