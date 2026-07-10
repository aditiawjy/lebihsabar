<?php
date_default_timezone_set('Asia/Jakarta');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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

// Goal log khusus Virtual Soccer (1x2aaa.com), terpisah dari goal_log.csv utama.
$csvFile = __DIR__ . '/goal_log_vsoccer.csv';
$headers = ['datetime', 'league', 'home_team', 'away_team', 'goals', 'final_home', 'final_away', '1h3', '2h1', '2h7'];

function parseMinute(string $minute): array {
    if (preg_match('/^(1H|2H)\s+(\d+)\'/i', $minute, $m)) {
        return ['half' => strtoupper($m[1]), 'min' => (int)$m[2]];
    }
    return ['half' => '', 'min' => -1];
}

function parseCsvDatetime(string $val): array {
    $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
    if (!$dt) $dt = new DateTime($val);
    return [
        'date'   => $dt->format('Y-m-d'),
        'hour'   => $dt->format('H'),
        'minute' => $dt->format('i'),
    ];
}

function extractGoalSnapshots(string $goals): array {
    $matches = [];
    preg_match_all('/(1H|2H)\s+(\d+)\'\s*\((\d+)-(\d+)\)/', $goals, $matches, PREG_SET_ORDER);
    return $matches;
}

function hasValidGoalProgression(string $goals): bool {
    $goals = trim($goals);
    if ($goals === '') return true;

    $leftovers = preg_replace('/(?:^|\|)\s*(?:1H|2H)\s+\d+\'\s*\(\d+-\d+\)\s*/', '', $goals);
    if (trim((string)$leftovers, " \t\n\r\0\x0B|") !== '') return false;

    $snapshots = extractGoalSnapshots($goals);
    if (!$snapshots) return false;

    $prevHome = 0;
    $prevAway = 0;

    foreach ($snapshots as $index => $snapshot) {
        $home = (int)$snapshot[3];
        $away = (int)$snapshot[4];

        if ($index === 0 && !(($home === 1 && $away === 0) || ($home === 0 && $away === 1))) {
            return false;
        }

        $deltaHome = $home - $prevHome;
        $deltaAway = $away - $prevAway;
        if (!(($deltaHome === 1 && $deltaAway === 0) || ($deltaHome === 0 && $deltaAway === 1))) {
            return false;
        }

        $prevHome = $home;
        $prevAway = $away;
    }

    return true;
}

function getLastGoalSnapshot(string $goals): ?array {
    $snapshots = extractGoalSnapshots($goals);
    if (!$snapshots) return null;
    $last = $snapshots[count($snapshots) - 1];
    return ['home' => (int)$last[3], 'away' => (int)$last[4]];
}

function shouldKeepPendingRow(array $row): bool {
    if (trim((string)($row['goals'] ?? '')) !== '') return true;
    if (trim((string)($row['2h7'] ?? '')) !== '') return true;

    $dt = DateTime::createFromFormat('d/m/Y H:i', (string)($row['datetime'] ?? ''));
    if (!$dt) {
        try {
            $dt = new DateTime((string)($row['datetime'] ?? 'now'));
        } catch (Exception $e) {
            return false;
        }
    }

    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return ($now->getTimestamp() - $dt->getTimestamp()) <= 7200;
}

// Find existing row key for same teams on same date (within +/-30 min window).
function findExistingKey(array $rows, string $dateOnly, string $homeTeam, string $awayTeam, \DateTime $dt): ?string {
    $tsIncoming = $dt->getTimestamp();
    $teamSuffix = '|' . $homeTeam . '|' . $awayTeam;
    $datePrefix = $dateOnly . '|';
    foreach ($rows as $key => $_) {
        if (strpos($key, $datePrefix) !== 0) continue;
        if (substr($key, -strlen($teamSuffix)) !== $teamSuffix) continue;
        $parts = explode('|', $key);
        if (count($parts) < 2) continue;
        $existingDt = DateTime::createFromFormat('Y-m-d H:i', $dateOnly . ' ' . $parts[1]);
        if (!$existingDt) continue;
        if (abs($existingDt->getTimestamp() - $tsIncoming) <= 1800) {
            return $key;
        }
    }
    return null;
}

$lockFile = $csvFile . '.lock';
$lock = fopen($lockFile, 'c');
flock($lock, LOCK_EX);

$rows = [];
if (is_file($csvFile) && is_readable($csvFile)) {
    $fh = fopen($csvFile, 'r');
    fgetcsv($fh);
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

// Register new matches (kickoff, no goal yet)
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
        $homeScore = array_key_exists('home_score', $m) ? trim((string)$m['home_score']) : null;
        $awayScore = array_key_exists('away_score', $m) ? trim((string)$m['away_score']) : null;
        if ($homeTeam === '' || $awayTeam === '') continue;
        $exactKey = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
        $key = isset($rows[$exactKey]) ? $exactKey : (findExistingKey($rows, $dateOnly, $homeTeam, $awayTeam, $dt) ?? $exactKey);
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'datetime'   => $datetime,
                'league'     => $league,
                'home_team'  => $homeTeam,
                'away_team'  => $awayTeam,
                'goals'      => '',
                'final_home' => '0',
                'final_away' => '0',
                '1h3'        => '',
                '2h1'        => '',
                '2h7'        => '',
            ];
        }

        if ($homeScore !== null && $homeScore !== '') $rows[$key]['final_home'] = $homeScore;
        if ($awayScore !== null && $awayScore !== '') $rows[$key]['final_away'] = $awayScore;
    }
}

// Merge incoming goal events
foreach (($hasGoals ? $payload['goals'] : []) as $goal) {
    $ts = $goal['timestamp'] ?? date('c');
    $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));

    $dateOnly   = $dt->format('Y-m-d');
    $hourOnly   = $dt->format('H');
    $minuteOnly = $dt->format('i');
    $datetime   = $dt->format('d/m/Y H:i');

    $homeTeam   = trim($goal['home_team']    ?? '');
    $awayTeam   = trim($goal['away_team']    ?? '');
    $league     = trim($goal['league']       ?? '');
    $minute     = trim($goal['minute']       ?? '');
    $scoreAfter = trim($goal['score_after']  ?? '');
    $homeFinal  = trim($goal['home_score']   ?? '');
    $awayFinal  = trim($goal['away_score']   ?? '');

    if ($homeTeam === '' || $awayTeam === '') continue;

    $exactKey = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
    $key = isset($rows[$exactKey]) ? $exactKey : (findExistingKey($rows, $dateOnly, $homeTeam, $awayTeam, $dt) ?? $exactKey);
    $goalEntry = $minute . ' (' . $scoreAfter . ')';

    $existingGoals = trim((string)($rows[$key]['goals'] ?? ''));
    $candidateGoals = $existingGoals;
    if ($candidateGoals === '') {
        $candidateGoals = $goalEntry;
    } elseif (strpos($candidateGoals, $goalEntry) === false) {
        $candidateGoals .= ' | ' . $goalEntry;
    }

    if (!hasValidGoalProgression($candidateGoals)) continue;

    if (!isset($rows[$key])) {
        $rows[$key] = [
            'datetime'   => $datetime,
            'league'     => $league,
            'home_team'  => $homeTeam,
            'away_team'  => $awayTeam,
            'goals'      => $candidateGoals,
            'final_home' => $homeFinal,
            'final_away' => $awayFinal,
            '1h3'        => '',
            '2h1'        => '',
            '2h7'        => '',
        ];
    } else {
        $rows[$key]['goals'] = $candidateGoals;
        $rows[$key]['final_home'] = $homeFinal;
        $rows[$key]['final_away'] = $awayFinal;
    }

    $pm = parseMinute($minute);
    if ($pm['half'] === '1H' && $pm['min'] >= 3) $rows[$key]['1h3'] = 'OK';
    if ($pm['half'] === '2H') $rows[$key]['1h3'] = 'OK';
    if ($pm['half'] === '2H' && $pm['min'] >= 1) $rows[$key]['2h1'] = 'OK';
    if ($pm['half'] === '2H' && $pm['min'] >= 7) $rows[$key]['2h7'] = 'OK';
}

// Apply milestone events
if ($hasMilestones) {
    foreach ($payload['milestones'] as $ms) {
        $ts = $ms['timestamp'] ?? date('c');
        $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $datetime   = $dt->format('d/m/Y H:i');
        $dateOnly   = $dt->format('Y-m-d');
        $hourOnly   = $dt->format('H');
        $minuteOnly = $dt->format('i');
        $league     = trim($ms['league'] ?? '');
        $homeTeam   = trim($ms['home_team'] ?? '');
        $awayTeam   = trim($ms['away_team'] ?? '');
        $msId       = trim($ms['milestone'] ?? '');
        if ($homeTeam === '' || $awayTeam === '') continue;
        if (!in_array($msId, ['1h3', '2h1', '2h7'], true)) continue;
        $exactKey = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
        $key = isset($rows[$exactKey]) ? $exactKey : (findExistingKey($rows, $dateOnly, $homeTeam, $awayTeam, $dt) ?? $exactKey);
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'datetime'   => $datetime,
                'league'     => $league,
                'home_team'  => $homeTeam,
                'away_team'  => $awayTeam,
                'goals'      => '',
                'final_home' => trim($ms['home_score'] ?? '0'),
                'final_away' => trim($ms['away_score'] ?? '0'),
                '1h3'        => '',
                '2h1'        => '',
                '2h7'        => '',
            ];
        }

        $rows[$key][$msId] = 'OK';
        if ($msId === '2h1' || $msId === '2h7') $rows[$key]['1h3'] = 'OK';
        if ($msId === '2h7') $rows[$key]['2h1'] = 'OK';
        $hscore = trim($ms['home_score'] ?? '');
        $ascore = trim($ms['away_score'] ?? '');
        if ($hscore !== '' && $rows[$key]['final_home'] === '') $rows[$key]['final_home'] = $hscore;
        if ($ascore !== '' && $rows[$key]['final_away'] === '') $rows[$key]['final_away'] = $ascore;
    }
}

$rows = array_filter($rows, static fn(array $row): bool => shouldKeepPendingRow($row));

$rows = array_filter($rows, static function (array $row): bool {
    $goals = trim((string)($row['goals'] ?? ''));
    $finalHome = (int)($row['final_home'] ?? 0);
    $finalAway = (int)($row['final_away'] ?? 0);

    if ($goals === '' && ($finalHome + $finalAway) > 0) {
        return false;
    }

    if ($goals !== '') {
        $lastSnapshot = getLastGoalSnapshot($goals);
        if (!$lastSnapshot) return false;
        if ($lastSnapshot['home'] !== $finalHome || $lastSnapshot['away'] !== $finalAway) {
            return false;
        }
    }

    return $goals === '' || hasValidGoalProgression($goals);
});

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

flock($lock, LOCK_UN);
fclose($lock);

echo json_encode([
    'ok' => true,
    'goals' => count($payload['goals'] ?? []),
    'matches' => count($payload['matches'] ?? []),
    'milestones' => count($payload['milestones'] ?? [])
]);
