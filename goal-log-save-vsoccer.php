<?php
date_default_timezone_set('Asia/Jakarta');

// Lama sebuah match boleh bertahan di CSV tanpa gol tercatat. Lewat ini, baris
// tanpa gol dianggap pelacakan gagal dan dibuang, bukan pertandingan 0-0.
const PENDING_GRACE_SECONDS = 7200;
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
$headers = ['datetime', 'league', 'home_team', 'away_team', 'goals', 'goal_minutes', 'goal_markets', 'final_home', 'final_away', 'ko_line', 'ko_over', 'ko_under', 'm46_minute', 'm46_line', 'm46_over', 'm46_under'];

// Ambil menit gol saja dari kolom goals, mis "1H 20' (1-0) | 2H 3' (2-0)" -> "1H 20' | 2H 3'".
function extractGoalMinutes(string $goals): string {
    if (preg_match_all("/(1H|2H)\s+(\d+)'/", $goals, $m, PREG_SET_ORDER)) {
        return implode(' | ', array_map(fn($x) => $x[1] . ' ' . $x[2] . "'", $m));
    }
    return '';
}

function upsertGoalMarket(string $markets, string $goalEntry, array $goal): string {
    $line = trim((string)($goal['ou_line'] ?? ''));
    $over = trim((string)($goal['over_odd'] ?? ''));
    $under = trim((string)($goal['under_odd'] ?? ''));
    if ($line === '' || $over === '' || $under === '') return $markets;

    $entry = $goalEntry . ' Line ' . $line . ' O ' . $over . ' U ' . $under;
    if ((string)($goal['deviation_extreme'] ?? '') === '1') $entry .= ' DEV!';
    $prefix = $goalEntry . ' Line ';
    $items = trim($markets) === '' ? [] : explode(' | ', $markets);
    foreach ($items as $index => $item) {
        if (strpos($item, $prefix) === 0) {
            $items[$index] = $entry;
            return implode(' | ', $items);
        }
    }
    $items[] = $entry;
    return implode(' | ', $items);
}

// Append satu baris per gol ke goal_events_vsoccer.csv, dedup per gol/hari.
function logGoalEvent(array $g): void {
    static $rows = null;
    static $headerCurrent = false;
    $file = __DIR__ . '/goal_events_vsoccer.csv';
    $header = ['logged_at', 'league', 'home_team', 'away_team', 'half', 'minute', 'side', 'score_after', 'ou_line', 'over_odd', 'under_odd', 'accurate', 'projected_line', 'line_deviation', 'deviation_extreme'];
    if ($rows === null) {
        $rows = [];
        if (is_file($file) && ($rf = fopen($file, 'r'))) {
            $oldHeader = fgetcsv($rf) ?: [];
            $headerCurrent = $oldHeader === $header;
            while (($raw = fgetcsv($rf)) !== false) {
                $row = array_fill_keys($header, '');
                foreach ($oldHeader as $i => $name) if (array_key_exists($name, $row)) $row[$name] = $raw[$i] ?? '';
                $rows[] = $row;
            }
            fclose($rf);
        }
    }
    $timeBucket = substr((string)$g['logged_at'], 0, 16);
    $key = $timeBucket . '|' . $g['league'] . '|' . $g['home_team'] . '|' . $g['away_team'] . '|' . $g['score_after'];
    if ($g['score_after'] === '') return;
    $incoming = array_fill_keys($header, '');
    foreach ($header as $name) $incoming[$name] = (string)($g[$name] ?? '');
    $found = false;
    foreach ($rows as &$row) {
        $rowKey = substr($row['logged_at'], 0, 16) . '|' . $row['league'] . '|' . $row['home_team'] . '|' . $row['away_team'] . '|' . $row['score_after'];
        if ($rowKey !== $key) continue;
        $found = true;
        // Reopened-market updates enrich the original event; never duplicate/replace its identity.
        foreach (['ou_line','over_odd','under_odd','projected_line','line_deviation','deviation_extreme'] as $name) {
            if ($incoming[$name] !== '') $row[$name] = $incoming[$name];
        }
        break;
    }
    unset($row);
    if (!$found && $headerCurrent) {
        $rows[] = $incoming;
        if ($out = fopen($file, 'a')) {
            fputcsv($out, array_map(fn($name) => $incoming[$name], $header));
            fclose($out);
        }
        return;
    }
    if (!$found) $rows[] = $incoming;
    if ($out = fopen($file, 'w')) {
        fputcsv($out, $header);
        foreach ($rows as $row) fputcsv($out, array_map(fn($name) => $row[$name] ?? '', $header));
        fclose($out);
        $headerCurrent = true;
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

function isStillPending(array $row): bool {
    $dt = DateTime::createFromFormat('d/m/Y H:i', (string)($row['datetime'] ?? ''));
    if (!$dt) {
        try {
            $dt = new DateTime((string)($row['datetime'] ?? 'now'));
        } catch (Exception $e) {
            return false;
        }
    }
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return ($now->getTimestamp() - $dt->getTimestamp()) <= PENDING_GRACE_SECONDS;
}

function shouldKeepPendingRow(array $row): bool {
    if (trim((string)($row['goals'] ?? '')) !== '') return true;

    // Baris tanpa gol tercatat TIDAK boleh kebal hanya karena market sudah
    // terekam. Dulu m46_line/2h7 yang terisi membuat baris bertahan permanen,
    // sehingga match yang pelacakannya hilang tersimpan sebagai pertandingan
    // 0-0 tanpa gol -- terlihat seperti data sah dan meracuni analisis.
    // Sekarang baris seperti itu hanya ditahan selama jendela pending di bawah,
    // lalu dibuang: match tanpa gol tercatat tidak bisa diverifikasi hasilnya.

    $dt = DateTime::createFromFormat('d/m/Y H:i', (string)($row['datetime'] ?? ''));
    if (!$dt) {
        try {
            $dt = new DateTime((string)($row['datetime'] ?? 'now'));
        } catch (Exception $e) {
            return false;
        }
    }

    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return ($now->getTimestamp() - $dt->getTimestamp()) <= PENDING_GRACE_SECONDS;
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
$abortLocked = static function (int $status, string $message) use ($lock): void {
    flock($lock, LOCK_UN);
    fclose($lock);
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
};

$rows = [];
if (is_file($csvFile)) {
    if (!is_readable($csvFile)) $abortLocked(503, 'CSV exists but is not readable');
    $existingSize = filesize($csvFile) ?: 0;
    $fh = fopen($csvFile, 'r');
    if ($fh === false) $abortLocked(503, 'CSV could not be opened for reading');
    $hdr = fgetcsv($fh);
    if (!is_array($hdr) || count(array_intersect(
        ['datetime', 'league', 'home_team', 'away_team', 'goals'],
        $hdr
    )) !== 5) {
        fclose($fh);
        $abortLocked(503, 'CSV header is invalid; refusing destructive rewrite');
    }
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
            'goal_markets' => $get($row, 'goal_markets', -1),
            'final_home' => $get($row, 'final_home', 5),
            'final_away' => $get($row, 'final_away', 6),
            'ko_line'    => $get($row, 'ko_line', -1),
            'ko_over'    => $get($row, 'ko_over', -1),
            'ko_under'   => $get($row, 'ko_under', -1),
            'm46_minute' => $get($row, 'm46_minute', -1),
            'm46_line'   => $get($row, 'm46_line', -1),
            'm46_over'   => $get($row, 'm46_over', -1),
            'm46_under'  => $get($row, 'm46_under', -1),
        ];
    }
    fclose($fh);
    if ($existingSize > 256 && count($rows) === 0) {
        $abortLocked(503, 'CSV had data but no rows could be read; refusing destructive rewrite');
    }
}

// Register new matches (kickoff, no goal yet)
$skippedNoKo = 0;   // match/gol yang dilewati karena tidak punya line awal
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
            // Wajib ada line awal (kickoff): match tanpa ko_line tidak diregistrasi sama sekali.
            if (trim((string)($m['ko_line'] ?? '')) === '') { $skippedNoKo++; continue; }
            $rows[$key] = [
                'datetime'   => $datetime,
                'league'     => $league,
                'home_team'  => $homeTeam,
                'away_team'  => $awayTeam,
                'goals'      => '',
                'goal_markets' => '',
                'final_home' => '0',
                'final_away' => '0',
                'ko_line'    => '',
                'ko_over'    => '',
                'ko_under'   => '',
                'm46_minute' => '',
                'm46_line'   => '',
                'm46_over'   => '',
                'm46_under'  => '',
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

// Simpan snapshot market pertama pada awal 2H (menit aktual pertama >= 46).
$savedMilestones = 0;
if ($hasMilestones) {
    foreach ($payload['milestones'] as $milestone) {
        if (($milestone['kind'] ?? '') !== 'm46') continue;
        $ts = $milestone['timestamp'] ?? date('c');
        $dt = (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $dateOnly = $dt->format('Y-m-d');
        $hourOnly = $dt->format('H');
        $minuteOnly = $dt->format('i');
        $homeTeam = trim((string)($milestone['home_team'] ?? ''));
        $awayTeam = trim((string)($milestone['away_team'] ?? ''));
        if ($homeTeam === '' || $awayTeam === '') continue;

        $exactKey = $dateOnly . '|' . $hourOnly . ':' . $minuteOnly . '|' . $homeTeam . '|' . $awayTeam;
        $key = isset($rows[$exactKey]) ? $exactKey : findExistingKey($rows, $dateOnly, $homeTeam, $awayTeam, $dt);
        if ($key === null || !isset($rows[$key])) continue;

        $line = trim((string)($milestone['line'] ?? ''));
        $over = trim((string)($milestone['over_odd'] ?? ''));
        $under = trim((string)($milestone['under_odd'] ?? ''));
        if ($line === '' || $over === '' || $under === '') continue;
        if (trim((string)($rows[$key]['m46_line'] ?? '')) !== '') {
            $savedMilestones++;
            continue;
        }

        $rows[$key]['m46_minute'] = trim((string)($milestone['minute'] ?? '46'));
        $rows[$key]['m46_line'] = $line;
        $rows[$key]['m46_over'] = $over;
        $rows[$key]['m46_under'] = $under;
        $savedMilestones++;
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

    // Match yang tidak pernah teregistrasi = tidak punya line awal -> jangan bikin baris baru
    // di goal_log_vsoccer.csv (per-gol tetap dicatat di goal_events_vsoccer.csv di bawah).
    if (!isset($rows[$key])) {
        $skippedNoKo++;
    } else {
        $rows[$key]['goals'] = $candidateGoals;
        $rows[$key]['goal_markets'] = upsertGoalMarket(
            (string)($rows[$key]['goal_markets'] ?? ''),
            $goalEntry,
            $goal
        );
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
        'accurate'    => (isset($goal['accurate']) && (int)$goal['accurate'] === 0) ? '0' : '1',
        'projected_line' => trim((string)($goal['projected_line'] ?? '')),
        'line_deviation' => trim((string)($goal['line_deviation'] ?? '')),
        'deviation_extreme' => trim((string)($goal['deviation_extreme'] ?? '')),
        'date'        => $dateOnly,
    ]);
}

$rows = array_filter($rows, static fn(array $row): bool => shouldKeepPendingRow($row));

// Jaring pengaman: baris tanpa line awal tidak boleh masuk log.
$rows = array_filter($rows, static fn(array $row): bool => trim((string)($row['ko_line'] ?? '')) !== '');

$rows = array_filter($rows, static function (array $row): bool {
    $goals = trim((string)($row['goals'] ?? ''));
    $finalHome = (int)($row['final_home'] ?? 0);
    $finalAway = (int)($row['final_away'] ?? 0);

    if ($goals === '' && ($finalHome + $finalAway) > 0) {
        return false;
    }

    // goals kosong + skor 0-0: hanya sah selama match memang masih berjalan
    // (sudah disaring shouldKeepPendingRow). Kalau match sudah lewat jendela
    // pending dan gol tetap tidak tercatat, baris ini bukan pertandingan 0-0
    // sungguhan melainkan pelacakan yang gagal -> jangan disimpan.
    if ($goals === '' && !isStillPending($row)) {
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

$tmpCsv = $csvFile . '.tmp';
$fh = fopen($tmpCsv, 'w');
if ($fh === false) $abortLocked(503, 'Temporary CSV could not be opened for writing');
fputcsv($fh, $headers);
foreach ($rows as $row) {
    fputcsv($fh, [
        $row['datetime'],
        $row['league'],
        $row['home_team'],
        $row['away_team'],
        $row['goals'],
        extractGoalMinutes((string)($row['goals'] ?? '')),
        $row['goal_markets'] ?? '',
        $row['final_home'],
        $row['final_away'],
        $row['ko_line']  ?? '',
        $row['ko_over']  ?? '',
        $row['ko_under'] ?? '',
        $row['m46_minute'] ?? '',
        $row['m46_line']   ?? '',
        $row['m46_over']   ?? '',
        $row['m46_under']  ?? '',
    ]);
}
fclose($fh);

// Selalu pertahankan satu salinan valid sebelum mengganti file utama.
if (is_file($csvFile) && filesize($csvFile) > 256) {
    @copy($csvFile, $csvFile . '.bak');
}
if (!@copy($tmpCsv, $csvFile)) {
    @unlink($tmpCsv);
    $abortLocked(503, 'Failed to replace CSV from temporary file');
}
@unlink($tmpCsv);

flock($lock, LOCK_UN);
fclose($lock);

echo json_encode([
    'ok' => true,
    'goals' => count($payload['goals'] ?? []),
    'matches' => count($payload['matches'] ?? []),
    'milestones' => count($payload['milestones'] ?? []),
    'milestones_saved' => $savedMilestones,
    'skipped_no_ko_line' => $skippedNoKo
]);
