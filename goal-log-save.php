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

$hasGoals      = !empty($payload['goals'])      && is_array($payload['goals']);
$hasMatches    = !empty($payload['matches'])    && is_array($payload['matches']);
$hasMilestones = !empty($payload['milestones']) && is_array($payload['milestones']);

if (!$hasGoals && !$hasMatches && !$hasMilestones) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No data provided']);
    exit;
}

$csvFile = __DIR__ . '/goal_log.csv';
$headers = ['datetime', 'league', 'home_team', 'away_team', 'goals', 'final_home', 'final_away', '1h3', '2h1', '2h7'];

function parseMinute(string $minute): array {
    if (preg_match('/^(1H|2H)\s+(\d+)\'/i', $minute, $m)) {
        return ['half' => strtoupper($m[1]), 'min' => (int)$m[2]];
    }
    return ['half' => '', 'min' => -1];
}

// Parse datetime stored as "d/m/Y H:i" → Y-m-d and H for key
function parseCsvDatetime(string $val): array {
    $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
    if (!$dt) $dt = new DateTime($val); // fallback
    return [
        'date'   => $dt->format('Y-m-d'),
        'hour'   => $dt->format('H'),
        'minute' => $dt->format('i'),
    ];
}

// Load existing rows keyed by (Y-m-d|HH:MM|home_team|away_team)
$rows = [];
if (is_file($csvFile) && is_readable($csvFile)) {
    $fh = fopen($csvFile, 'r');
    fgetcsv($fh); // skip header
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 7) continue;
        $parsed = parseCsvDatetime($row[0]);
        $key = $parsed['date'] . '|' . $parsed['hour'] . ':' . $parsed['minute'] . '|' . $row[2] . '|' . $row[3];
        $rows[$key] = [
            'datetime'   => $row[0],
            'league'     => $row[1],
            'home_team'  => $row[2],
            'away_team'  => $row[3],
            'goals'      => $row[4],
            'final_home' => $row[5],
            'final_away' => $row[6],
            '1h3'        => $row[7] ?? '',
            '2h1'        => $row[8] ?? '',
            '2h7'        => $row[9] ?? '',
        ];
    }
    fclose($fh);
}

// Register new matches (no goal yet, just kicked off)
if ($hasMatches) {
    foreach ($payload['matches'] as $m) {
        $ts = $m['timestamp'] ?? date('c');
        $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $dateOnly   = $dt->format('Y-m-d');
        $hourOnly   = $dt->format('H');
        $minuteOnly = $dt->format('i');
        $datetime   = $dt->format('d/m/Y H:i');
        $homeTeam = trim($m['home_team'] ?? '');
        $awayTeam = trim($m['away_team'] ?? '');
        $league   = trim($m['league']   ?? '');
        if ($homeTeam === '' || $awayTeam === '') continue;
        $key = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'datetime'   => $datetime,
                'league'     => $league,
                'home_team'  => $homeTeam,
                'away_team'  => $awayTeam,
                'goals'      => '',
                'final_home' => '',
                'final_away' => '',
                '1h3'        => '',
                '2h1'        => '',
                '2h7'        => '',
            ];
        }
    }
}

// Merge incoming goal events into rows
foreach (($hasGoals ? $payload['goals'] : []) as $goal) {
    $ts = $goal['timestamp'] ?? date('c');
    $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));

    $dateOnly   = $dt->format('Y-m-d');
    $hourOnly   = $dt->format('H');
    $minuteOnly = $dt->format('i');
    $datetime   = $dt->format('d/m/Y H:i'); // stored format — parseable by createFromFormat

    $homeTeam   = trim($goal['home_team']    ?? '');
    $awayTeam   = trim($goal['away_team']    ?? '');
    $league     = trim($goal['league']       ?? '');
    $minute     = trim($goal['minute']       ?? '');
    $scoreAfter = trim($goal['score_after']  ?? '');
    $homeFinal  = trim($goal['home_score']   ?? '');
    $awayFinal  = trim($goal['away_score']   ?? '');

    if ($homeTeam === '' || $awayTeam === '') continue;

    $key       = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
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
            '1h3'        => '',
            '2h1'        => '',
            '2h7'        => '',
        ];
    } else {
        $existing = $rows[$key]['goals'];
        if (strpos($existing, $goalEntry) === false) {
            $rows[$key]['goals'] = $existing . ' | ' . $goalEntry;
        }
        $rows[$key]['final_home'] = $homeFinal;
        $rows[$key]['final_away'] = $awayFinal;
    }

    // Auto-derive milestone flags from goal minute
    $pm = parseMinute($minute);
    if ($pm['half'] === '1H' && $pm['min'] >= 3) $rows[$key]['1h3'] = '✓';
    if ($pm['half'] === '2H') $rows[$key]['1h3'] = '✓'; // 2H means 1H fully passed
    if ($pm['half'] === '2H' && $pm['min'] >= 1) $rows[$key]['2h1'] = '✓';
    if ($pm['half'] === '2H' && $pm['min'] >= 7) $rows[$key]['2h7'] = '✓';
}

// Apply milestone events
if ($hasMilestones) {
    foreach ($payload['milestones'] as $ms) {
        $ts = $ms['timestamp'] ?? date('c');
        $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $dateOnly   = $dt->format('Y-m-d');
        $hourOnly   = $dt->format('H');
        $minuteOnly = $dt->format('i');
        $homeTeam   = trim($ms['home_team'] ?? '');
        $awayTeam   = trim($ms['away_team'] ?? '');
        $msId       = trim($ms['milestone'] ?? '');
        if ($homeTeam === '' || $awayTeam === '') continue;
        if (!in_array($msId, ['1h3', '2h1', '2h7'], true)) continue;
        $key = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
        if (isset($rows[$key])) {
            $rows[$key][$msId] = '✓';
            // 2H milestones imply 1H 3' was also reached
            if ($msId === '2h1' || $msId === '2h7') $rows[$key]['1h3'] = '✓';
            if ($msId === '2h7') $rows[$key]['2h1'] = '✓';
        }
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
        $row['1h3'] ?? '',
        $row['2h1'] ?? '',
        $row['2h7'] ?? '',
    ]);
}
fclose($fh);

echo json_encode(['ok' => true, 'goals' => count($payload['goals'] ?? []), 'matches' => count($payload['matches'] ?? []), 'milestones' => count($payload['milestones'] ?? [])]);
