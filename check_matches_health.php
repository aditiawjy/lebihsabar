<?php
// Endpoint health metrics untuk halaman parser.
// Input  : JSON { matches: [ {match_time, home_team, away_team, league, ...}, ... ] } (opsional)
// Output : JSON { success, daily_metrics: {total_today, league_count_today, pending_score},
//                 duplicate_indexes: [index payload yang sudah ada di matches.csv] }
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$csvPath = __DIR__ . '/matches.csv';
$today   = date('Y-m-d');

$payload = json_decode(file_get_contents('php://input') ?: '', true);
$payloadMatches = is_array($payload['matches'] ?? null) ? $payload['matches'] : [];

// Kunci duplikat dari payload: tanggal|home|away (waktu bisa bergeser antar sumber)
$dupKey = static function (?string $matchTime, string $home, string $away): ?string {
    $ts = $matchTime !== null && $matchTime !== '' ? strtotime($matchTime) : false;
    if ($ts === false) return null;
    return date('Y-m-d', $ts) . '|' . mb_strtolower(trim($home)) . '|' . mb_strtolower(trim($away));
};

$wanted = []; // dupKey => [payload indexes]
foreach ($payloadMatches as $i => $pm) {
    $key = $dupKey($pm['match_time'] ?? null, (string)($pm['home_team'] ?? ''), (string)($pm['away_team'] ?? ''));
    if ($key !== null) $wanted[$key][] = $i;
}

$totalToday    = 0;
$leaguesToday  = [];
$pendingScore  = 0;
$duplicateIdx  = [];

$fh = @fopen($csvPath, 'r');
if ($fh === false) {
    echo json_encode(['success' => false, 'error' => 'matches.csv tidak dapat dibaca']);
    exit;
}

$header = fgetcsv($fh);
$col = $header !== false ? array_flip($header) : [];
$need = ['match_time', 'home_team', 'away_team', 'league', 'ft_home', 'ft_away'];
foreach ($need as $c) {
    if (!isset($col[$c])) {
        fclose($fh);
        echo json_encode(['success' => false, 'error' => "Kolom $c tidak ditemukan di matches.csv"]);
        exit;
    }
}

while (($row = fgetcsv($fh)) !== false) {
    $matchTime = $row[$col['match_time']] ?? '';
    $date = substr($matchTime, 0, 10);

    if ($date === $today) {
        $totalToday++;
        $league = $row[$col['league']] ?? '';
        if ($league !== '') $leaguesToday[$league] = true;
        $ftH = $row[$col['ft_home']] ?? '';
        $ftA = $row[$col['ft_away']] ?? '';
        if ($ftH === '' || $ftA === '' || !is_numeric($ftH) || !is_numeric($ftA)) {
            $pendingScore++;
        }
    }

    if ($wanted !== []) {
        $key = $dupKey($matchTime, (string)($row[$col['home_team']] ?? ''), (string)($row[$col['away_team']] ?? ''));
        if ($key !== null && isset($wanted[$key])) {
            foreach ($wanted[$key] as $i) $duplicateIdx[$i] = true;
            unset($wanted[$key]);
        }
    }
}
fclose($fh);

echo json_encode([
    'success' => true,
    'daily_metrics' => [
        'total_today'        => $totalToday,
        'league_count_today' => count($leaguesToday),
        'pending_score'      => $pendingScore,
    ],
    'duplicate_indexes' => array_keys($duplicateIdx),
]);
