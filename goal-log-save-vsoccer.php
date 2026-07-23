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
$headers = ['datetime', 'league', 'home_team', 'away_team', 'goals', 'goal_minutes', 'final_home', 'final_away', 'ko_line', 'ko_over', 'ko_under'];

// Ambil menit gol saja dari kolom goals, mis "1H 20' (1-0) | 2H 3' (2-0)" -> "1H 20' | 2H 3'".
function extractGoalMinutes(string $goals): string {
    if (preg_match_all("/(1H|2H)\s+(\d+)'/", $goals, $m, PREG_SET_ORDER)) {
        return implode(' | ', array_map(fn($x) => $x[1] . ' ' . $x[2] . "'", $m));
    }
    return '';
}

// Append satu baris per gol ke goal_events_vsoccer.csv, dedup per gol/hari.
function logGoalEvent(array $g): void {
    static $seen = null;
    $file = __DIR__ . '/goal_events_vsoccer.csv';
    $header = ['logged_at', 'league', 'home_team', 'away_team', 'half', 'minute', 'side', 'score_after', 'ou_line', 'over_odd', 'under_odd'];
    if ($seen === null) {
        $seen = [];
        if (is_file($file) && ($rf = fopen($file, 'r'))) {
            $h = fgetcsv($rf);
            while (($r = fgetcsv($rf)) !== false) {
                // kunci: tanggal(logged_at) | teams | score_after
                $day = substr((string)($r[0] ?? ''), 0, 10);
                $seen[$day . '|' . ($r[2] ?? '') . '|' . ($r[3] ?? '') . '|' . ($r[7] ?? '')] = true;
            }
            fclose($rf);
        }
    }
    $day = substr((string)$g['logged_at'], 0, 10); // selaras cara baca file di atas
    $key = $day . '|' . $g['home_team'] . '|' . $g['away_team'] . '|' . $g['score_after'];
    if (isset($seen[$key]) || $g['score_after'] === '') return;
    $seen[$key] = true;
    $isNew = !is_file($file) || filesize($file) === 0;
    if ($out = fopen($file, 'a')) {
        if ($isNew) fputcsv($out, $header);
        fputcsv($out, [$g['logged_at'], $g['league'], $g['home_team'], $g['away_team'], $g['half'], $g['minute'], $g['side'], $g['score_after'], $g['ou_line'] ?? '', $g['over_odd'] ?? '', $g['under_odd'] ?? '']);
        fclose($out);
    }
}

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
    $hdr = fgetcsv($fh);
    $ci = is_array($hdr) ? array_flip($hdr) : [];
    // Kompat: format lama (tanpa goal_minutes, ada 1h3/2h1/2h7). Pakai nama kolom, fallback ke indeks lama.
    $get = function (array $row, string $name, int $legacyIdx) use ($ci) {
        if (isset($ci[$name]) && isset($row[$ci[$name]])) return $row[$ci[$name]];
        return $row[$legacyIdx] ?? '';
    };
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 5) continue;
        $home = $get($row, 'home_team', 2);
        $away = $get($row, 'away_team', 3);
        $parsed = parseCsvDatetime($get($row, 'datetime', 0));
        $key = $parsed['date'] . '|' . $parsed['hour'] . ':' . $parsed['minute'] . '|' . $home . '|' . $away;
        $rows[$key] = [
            'datetime'   => $get($row, 'datetime', 0),
            'league'     => $get($row, 'league', 1),
            'home_team'  => $home,
            'away_team'  => $away,
            'goals'      => $get($row, 'goals', 4),
            'final_home' => $get($row, 'final_home', 5),
            'final_away' => $get($row, 'final_away', 6),
            'ko_line'    => $get($row, 'ko_line', -1),
            'ko_over'    => $get($row, 'ko_over', -1),
            'ko_under'   => $get($row, 'ko_under', -1),
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
                'ko_line'    => '',
                'ko_over'    => '',
                'ko_under'   => '',
            ];
        }

        if ($homeScore !== null && $homeScore !== '') $rows[$key]['final_home'] = $homeScore;
        if ($awayScore !== null && $awayScore !== '') $rows[$key]['final_away'] = $awayScore;
        // Odds awal (kickoff) — hanya diisi sekali, saat pertama match diregistrasi.
        if (($rows[$key]['ko_line'] ?? '') === '' && isset($m['ko_line'])) $rows[$key]['ko_line'] = trim((string)$m['ko_line']);
        if (($rows[$key]['ko_over'] ?? '') === '' && isset($m['ko_over'])) $rows[$key]['ko_over'] = trim((string)$m['ko_over']);
        if (($rows[$key]['ko_under'] ?? '') === '' && isset($m['ko_under'])) $rows[$key]['ko_under'] = trim((string)$m['ko_under']);
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

    // Log per-gol (satu baris = satu gol) ke goal_events_vsoccer.csv.
    logGoalEvent([
        'logged_at'   => $datetime,
        'league'      => $league,
        'home_team'   => $homeTeam,
        'away_team'   => $awayTeam,
        'half'        => $pm['half'],
        'minute'      => $pm['min'] >= 0 ? $pm['min'] : ($goal['min_num'] ?? ''),
        'side'        => trim($goal['side'] ?? ''),
        'score_after' => $scoreAfter,
        'ou_line'     => trim((string)($goal['ou_line'] ?? '')),
        'over_odd'    => trim((string)($goal['over_odd'] ?? '')),
        'under_odd'   => trim((string)($goal['under_odd'] ?? '')),
        'date'        => $dateOnly,
    ]);
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
        extractGoalMinutes((string)($row['goals'] ?? '')),
        $row['final_home'],
        $row['final_away'],
        $row['ko_line']  ?? '',
        $row['ko_over']  ?? '',
        $row['ko_under'] ?? '',
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
