<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

if (!isset($payload['goals']) || !is_array($payload['goals']) || !count($payload['goals'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No goals provided']);
    exit;
}

$csvFile = __DIR__ . '/goal_log.csv';
$headers = ['datetime', 'league', 'home_team', 'away_team', 'goals', 'final_home', 'final_away'];

// Parse datetime stored as "d/m/Y H:i" → Y-m-d and H for key
function parseCsvDatetime(string $val): array {
    $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
    if (!$dt) $dt = new DateTime($val); // fallback
    return [
        'date' => $dt->format('Y-m-d'),
        'hour' => $dt->format('H'),
    ];
}

// Load existing rows keyed by (Y-m-d|H|home_team|away_team)
$rows = [];
if (is_file($csvFile) && is_readable($csvFile)) {
    $fh = fopen($csvFile, 'r');
    fgetcsv($fh); // skip header
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 7) continue;
        $parsed = parseCsvDatetime($row[0]);
        $key = $parsed['date'] . '|' . $parsed['hour'] . '|' . $row[2] . '|' . $row[3];
        $rows[$key] = [
            'datetime'   => $row[0],
            'league'     => $row[1],
            'home_team'  => $row[2],
            'away_team'  => $row[3],
            'goals'      => $row[4],
            'final_home' => $row[5],
            'final_away' => $row[6],
        ];
    }
    fclose($fh);
}

// Merge incoming goal events into rows
foreach ($payload['goals'] as $goal) {
    $ts = $goal['timestamp'] ?? date('c');
    $dt = new DateTime($ts);

    $dateOnly = $dt->format('Y-m-d');
    $hourOnly = $dt->format('H');
    $datetime = $dt->format('d/m/Y H:i'); // stored format — parseable by createFromFormat

    $homeTeam   = trim($goal['home_team']    ?? '');
    $awayTeam   = trim($goal['away_team']    ?? '');
    $league     = trim($goal['league']       ?? '');
    $minute     = trim($goal['minute']       ?? '');
    $scoreAfter = trim($goal['score_after']  ?? '');
    $homeFinal  = trim($goal['home_score']   ?? '');
    $awayFinal  = trim($goal['away_score']   ?? '');

    if ($homeTeam === '' || $awayTeam === '') continue;

    $key       = $dateOnly . '|' . $hourOnly . '|' . $homeTeam . '|' . $awayTeam;
    $goalEntry = $minute . ' (' . $scoreAfter . ')';

    if (!isset($rows[$key])) {
        $rows[$key] = [
            'datetime'   => $datetime,
            'league'     => $league,
            'home_team'  => $homeTeam,
            'away_team'  => $awayTeam,
            'goals'      => $goalEntry,
            'final_home' => $homeFinal,
            'final_away' => $awayFinal,
        ];
    } else {
        $existing = $rows[$key]['goals'];
        if (strpos($existing, $goalEntry) === false) {
            $rows[$key]['goals'] = $existing . ' | ' . $goalEntry;
        }
        $rows[$key]['final_home'] = $homeFinal;
        $rows[$key]['final_away'] = $awayFinal;
    }
}

// Write CSV
$fh = fopen($csvFile, 'w');
fputcsv($fh, $headers);
foreach ($rows as $row) {
    fputcsv($fh, [
        $row['datetime'],
        $row['league'],
        $row['home_team'],
        $row['away_team'],
        $row['goals'],
        $row['final_home'],
        $row['final_away'],
    ]);
}
fclose($fh);

echo json_encode(['ok' => true, 'saved' => count($payload['goals'])]);
