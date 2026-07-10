<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

$odds = $payload['odds'] ?? null;
if (empty($odds) || !is_array($odds)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No odds data provided']);
    exit;
}

$csvFile = __DIR__ . '/odds_log.csv';
$headers = ['datetime', 'league', 'home_team', 'away_team', 'status', 'score', 'market', 'selection', 'odd_value', 'prev_value'];

// Append-only: event log tidak butuh merge seperti goal_log.csv, cukup tambah baris.
$lockFile = $csvFile . '.lock';
$lock = fopen($lockFile, 'c');
flock($lock, LOCK_EX);

$writeHeader = !is_file($csvFile) || filesize($csvFile) === 0;
$fh = fopen($csvFile, 'a');
if ($writeHeader) {
    fputcsv($fh, $headers);
}

$written = 0;
foreach ($odds as $event) {
    if (!is_array($event)) continue;

    $homeTeam = trim((string)($event['home_team'] ?? ''));
    $awayTeam = trim((string)($event['away_team'] ?? ''));
    $selection = trim((string)($event['selection'] ?? ''));
    $oddValue = $event['odd_value'] ?? null;
    if ($homeTeam === '' || $awayTeam === '' || $selection === '' || !is_numeric($oddValue)) continue;

    $ts = $event['timestamp'] ?? date('c');
    try {
        $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));
    } catch (Exception $e) {
        $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    }

    fputcsv($fh, [
        $dt->format('d/m/Y H:i:s'),
        trim((string)($event['league'] ?? '')),
        $homeTeam,
        $awayTeam,
        trim((string)($event['status'] ?? '')),
        trim((string)($event['score'] ?? '')),
        trim((string)($event['market'] ?? '')),
        $selection,
        (string)$oddValue,
        is_numeric($event['prev_value'] ?? null) ? (string)$event['prev_value'] : '',
    ]);
    $written++;
}

fclose($fh);
flock($lock, LOCK_UN);
fclose($lock);

echo json_encode(['ok' => true, 'written' => $written]);
