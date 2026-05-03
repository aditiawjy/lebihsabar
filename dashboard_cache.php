<?php
date_default_timezone_set('Asia/Jakarta');

$csvFile = __DIR__ . '/goal_log.csv';
$cacheFile = __DIR__ . '/dashboard_cache.json';

function getCachedDashboardData(string $csvFile, string $cacheFile): array {
    $csvExists = file_exists($csvFile) && is_readable($csvFile);
    $cacheExists = file_exists($cacheFile);
    $codeMtime = filemtime(__FILE__);
    $csvMeta = getCsvMeta($csvFile, $csvExists);

    if ($cacheExists) {
        $cacheMtime = filemtime($cacheFile);
        $csvMtime = $csvMeta['time'] ?? 0;

        if ($csvExists && $cacheMtime >= $csvMtime && $cacheMtime >= $codeMtime) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['generated_at']) && isCacheFreshForCsv($cached, $csvMeta)) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }
    }

    $data = buildDashboardData($csvFile, $csvExists);
    $cacheContent = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($cacheFile, $cacheContent, LOCK_EX);
    $data['from_cache'] = false;
    return $data;
}

function buildDashboardData(string $csvFile, bool $csvExists): array {
    $matches = [];
    $csvMeta = getCsvMeta($csvFile, $csvExists);

    if ($csvExists) {
        $fh = fopen($csvFile, 'r');
        if ($fh !== false) {
            fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 7) continue;
                $row = array_pad($row, 10, '');
                $goalsStr = trim($row[4] ?? '');
                if ($goalsStr === '' && (int)($row[5] ?? 0) === 0 && (int)($row[6] ?? 0) === 0) continue;
                $matches[] = $row;
            }
            fclose($fh);
        }
    }

    $parsedMatches = parseMatches($matches);
    $patterns = computePatterns($parsedMatches);
    $nextPatterns = computeNextPatterns($parsedMatches);
    $latePatterns = computeLatePatterns($parsedMatches);
    $no2hPatterns = computeNo2hPatterns($parsedMatches);

    return [
        'generated_at' => time(),
        'csv_exists' => $csvExists,
        'csv_time' => $csvMeta['time'],
        'csv_size' => $csvMeta['size'],
        'csv_hash' => $csvMeta['hash'],
        'total_matches' => count($parsedMatches),
        'with_2h' => count(array_filter($parsedMatches, fn($m) => $m['h2c'] > 0)),
        'patterns' => $patterns,
        'next_patterns' => $nextPatterns,
        'late_patterns' => $latePatterns,
        'no2h_patterns' => $no2hPatterns,
        'all_matches' => $parsedMatches,
    ];
}

function getCsvMeta(string $csvFile, bool $csvExists): array {
    if (!$csvExists) {
        return ['time' => null, 'size' => null, 'hash' => null];
    }

    $size = filesize($csvFile);
    $hash = md5_file($csvFile);

    return [
        'time' => filemtime($csvFile),
        'size' => $size === false ? null : $size,
        'hash' => $hash === false ? null : $hash,
    ];
}

function isCacheFreshForCsv(array $cached, array $csvMeta): bool {
    if (($cached['csv_exists'] ?? false) !== true) {
        return false;
    }

    if (($cached['csv_hash'] ?? null) !== ($csvMeta['hash'] ?? null)) {
        return false;
    }

    if (($cached['csv_size'] ?? null) !== ($csvMeta['size'] ?? null)) {
        return false;
    }

    return ($cached['csv_time'] ?? null) === ($csvMeta['time'] ?? null);
}

function parseMatches(array $rows): array {
    $matches = [];
    foreach ($rows as $row) {
        $goals = parseGoals($row[4] ?? '');
        $fhScore = (int)($row[5] ?? 0);
        $faScore = (int)($row[6] ?? 0);
        $h1 = array_values(array_filter($goals, fn($g) => $g['half'] === '1H'));
        $h2 = array_values(array_filter($goals, fn($g) => $g['half'] === '2H'));
        $sh = end($h1) ?: null;
        $league = getLeagueType($row[1] ?? '');
        $h1s = getH1Scorers($h1);
        $dateParts = parseMatchDateParts($row[0] ?? '');

        $matches[] = [
            'home' => $row[2] ?? '',
            'away' => $row[3] ?? '',
            'datetime' => $row[0] ?? '',
            'league' => $league,
            'h1' => $h1,
            'h1c' => count($h1),
            'h2c' => count($h2),
            'sc_h' => $sh ? $sh['home'] : 0,
            'sc_a' => $sh ? $sh['away'] : 0,
            'h1_first' => $h1[0]['min'] ?? -1,
            'h1_last' => end($h1)['min'] ?? -1,
            'fh' => $fhScore,
            'fa' => $faScore,
            'h1s' => $h1s,
            'switches' => countSwitches($h1s),
            'max_gap' => maxGap($h1),
            'min_gap' => minGap($h1),
            'max_run' => maxRun($h1s),
            'all_gaps_ge3' => allGapsGe($h1, 3),
            'h2' => $h2,
            'h2_first_min' => count($h2) ? $h2[0]['min'] : -1,
            'has_after_2h4' => count(array_filter($h2, fn($g) => $g['min'] > 4)) > 0,
            'has_after_early_2h' => count($h2) >= 2 && count(array_filter($h2, fn($g) => $g['min'] > ($h2[0]['min'] ?? 99))) > 0,
            'has_late' => count(array_filter($h2, fn($g) => $g['min'] >= 7)) > 0,
            'next_goal' => computeNextGoal($h2, $sh ? $sh['home'] : 0, $sh ? $sh['away'] : 0),
            'kickoff_hour' => $dateParts['hour'],
            'kickoff_minute' => $dateParts['minute'],
            'kickoff_dow' => $dateParts['dow'],
            'kickoff_dow_num' => $dateParts['dow_num'],
        ];
    }
    return $matches;
}

function parseMatchDateParts(string $value): array {
    $dt = DateTime::createFromFormat('d/m/Y H:i', trim($value));
    if (!$dt) {
        return ['hour' => -1, 'minute' => -1, 'dow' => '', 'dow_num' => -1];
    }

    return [
        'hour' => (int)$dt->format('G'),
        'minute' => (int)$dt->format('i'),
        'dow' => $dt->format('D'),
        'dow_num' => (int)$dt->format('w'),
    ];
}

function parseGoals(string $gs): array {
    $goals = [];
    $parts = explode('|', $gs);
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/(1H|2H)\s+(\d+)\'\s*\((\d+)-(\d+)\)/', $part, $m)) {
            $goals[] = ['half' => $m[1], 'min' => (int)$m[2], 'home' => (int)$m[3], 'away' => (int)$m[4]];
        }
    }
    return $goals;
}

function getLeagueType(string $s): string {
    if (strpos($s, '15 Mins') !== false) return '15min';
    if (strpos($s, '16 Mins') !== false) return '16min';
    if (strpos($s, '20 Mins') !== false) return '20min';
    return 'other';
}

function getH1Scorers(array $h1): array {
    $scorers = [];
    $ph = 0; $pa = 0;
    foreach ($h1 as $g) {
        if ($g['home'] > $ph) $scorers[] = 'H';
        if ($g['away'] > $pa) $scorers[] = 'A';
        $ph = $g['home']; $pa = $g['away'];
    }
    return $scorers;
}

function countSwitches(array $scorers): int {
    $sw = 0;
    for ($i = 1; $i < count($scorers); $i++) {
        if ($scorers[$i] !== $scorers[$i-1]) $sw++;
    }
    return $sw;
}

function maxGap(array $goals): int {
    if (count($goals) < 2) return 0;
    $max = 0;
    for ($i = 1; $i < count($goals); $i++) {
        $gap = $goals[$i]['min'] - $goals[$i-1]['min'];
        if ($gap > $max) $max = $gap;
    }
    return $max;
}

function minGap(array $goals): int {
    if (count($goals) < 2) return 99;
    $min = 99;
    for ($i = 1; $i < count($goals); $i++) {
        $gap = $goals[$i]['min'] - $goals[$i-1]['min'];
        if ($gap < $min) $min = $gap;
    }
    return $min;
}

function maxRun(array $scorers): int {
    if (count($scorers) === 0) return 0;
    $max = 1; $cur = 1;
    for ($i = 1; $i < count($scorers); $i++) {
        $cur = ($scorers[$i] === $scorers[$i-1]) ? $cur + 1 : 1;
        if ($cur > $max) $max = $cur;
    }
    return $max;
}

function allGapsGe(array $goals, int $min): bool {
    for ($i = 1; $i < count($goals); $i++) {
        if (($goals[$i]['min'] - $goals[$i-1]['min']) < $min) return false;
    }
    return true;
}

function computeNextGoal(array $h2, int $scH, int $scA): ?string {
    if (!count($h2)) return null;
    if ($h2[0]['home'] > $scH) return 'H';
    if ($h2[0]['away'] > $scA) return 'A';
    return null;
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function buildSnapshotSignature(string $id, string $label, string $extra = ''): string {
    return md5($id . '|' . $label . '|' . $extra);
}

function parseMatchDateTime(?string $value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;

    $tz = new DateTimeZone('Asia/Jakarta');
    $dt = DateTime::createFromFormat('d/m/Y H:i', $value, $tz);
    if (!$dt) return null;

    return $dt->getTimestamp();
}

function buildRangeDelta(array $matches, callable $isHit, ?int $rangeStart, ?int $rangeEnd): array {
    if (!$rangeStart || !$rangeEnd || $rangeEnd < $rangeStart) {
        return ['html' => '<span style="color:#484f58">&mdash;</span>', 'deltaT' => 0, 'deltaH' => 0];
    }

    $rangeMatches = array_values(array_filter($matches, function($m) use ($rangeStart, $rangeEnd) {
        $ts = parseMatchDateTime($m['datetime'] ?? '');
        return $ts !== null && $ts >= $rangeStart && $ts <= $rangeEnd;
    }));

    $deltaT = count($rangeMatches);
    $deltaH = count(array_filter($rangeMatches, $isHit));

    if ($deltaT <= 0) {
        return ['html' => '<span style="color:#484f58">tidak berubah</span>', 'deltaT' => 0, 'deltaH' => 0];
    }

    return [
        'html' => '<span style="color:#3fb950;font-weight:600;">+' . $deltaT . ' sample (+' . $deltaH . ' hit)</span>',
        'deltaT' => $deltaT,
        'deltaH' => $deltaH,
    ];
}

function buildDelta(string $id, string $signature, int $total, int $hits, array $oldSnapData): array {
    $html = '<span style="color:#484f58">&mdash;</span>';
    $deltaT = 0;
    $deltaH = 0;
    if ($oldSnapData && isset($oldSnapData[$id])) {
        $old = $oldSnapData[$id];
        if (($old['sig'] ?? null) !== $signature) {
            return [
                'html' => '<span style="color:#8b949e">rule berubah</span>',
                'deltaT' => 0,
                'deltaH' => 0,
            ];
        }
        $deltaT = $total - $old['t'];
        $deltaH = $hits - $old['h'];
        if ($deltaT > 0) {
            $sign = $deltaH >= 0 ? '+' : '';
            $col = $deltaH > 0 ? '#3fb950' : ($deltaH < 0 ? '#f85149' : '#8b949e');
            $html = "<span style=\"color:{$col};font-weight:600;\">+{$deltaT} sample ({$sign}{$deltaH} hit)</span>";
        } elseif ($deltaT == 0) {
            $html = '<span style="color:#484f58">tidak berubah</span>';
        }
    }
    return ['html' => $html, 'deltaT' => $deltaT, 'deltaH' => $deltaH];
}

function getTeamConfig(): array {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/dashboard_config.php';
    }
    return $config;
}

function matchesP2(array $m): bool {
    $league = $m['league'] ?? '';
    $first = $m['h1_first'] ?? -1;
    $last = $m['h1_last'] ?? -1;
    $seq = implode('', $m['h1s'] ?? []);

    if ($league !== '16min'
        || ($m['h1c'] ?? 0) < 2
        || abs(($m['sc_h'] ?? 0) - ($m['sc_a'] ?? 0)) < 2
        || $first > 1
        || $last < 6
        || $last > 8
        || !($m['all_gaps_ge3'] ?? false)
        || ($m['max_run'] ?? 99) > 2) {
        return false;
    }

    return !(($m['kickoff_dow_num'] ?? -1) === 0
        && ($m['kickoff_hour'] ?? -1) === 12
        && $seq === 'HH'
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 1
        && $last === 7);
}

function matchesP12Base(array $m): bool {
    return ($m['h1c'] ?? 0) >= 4
        && (($m['h1_last'] ?? -1) - ($m['h1_first'] ?? -1)) >= 6
        && ($m['min_gap'] ?? 0) >= 1
        && ($m['h1_last'] ?? -1) <= 9
        && ($m['h1_first'] ?? -1) >= 2
        && ($m['max_run'] ?? 99) <= 2
        && !(($m['league'] ?? '') === '15min'
            && ($m['h1_first'] ?? -1) === 2
            && ($m['h1_last'] ?? -1) === 8
            && ($m['sc_h'] ?? -1) === 2
            && ($m['sc_a'] ?? -1) === 2
            && ($m['h1s'] ?? []) === ['A', 'H', 'H', 'A']);
}

function matchesP12Expanded(array $m): bool {
    if (matchesP12Base($m)) {
        return true;
    }

    if (($m['h1c'] ?? 0) < 4) {
        return false;
    }

    $league = $m['league'] ?? '';
    $first = $m['h1_first'] ?? -1;
    $last = $m['h1_last'] ?? -1;
    $seq = implode('', $m['h1s'] ?? []);

    if ($league === '20min'
        && $seq === 'AAAH'
        && $first === 0
        && $last === 10
        && ($m['sc_h'] ?? -1) === 1
        && ($m['sc_a'] ?? -1) === 3
        && ($m['min_gap'] ?? -1) === 0) {
        return false;
    }

    return
        ($league === '15min' && $seq === 'HAHA' && $first <= 3 && $last >= 6)
        || ($league === '20min' && $seq === 'AHAH' && $first <= 2 && $last >= 8)
        || ($league === '15min' && $seq === 'AHAH' && $first <= 2)
        || ($league === '15min' && $seq === 'AHHH' && $first <= 1)
        || ($league === '20min' && $seq === 'HHAA' && $first <= 1)
        || ($league === '20min' && $seq === 'HAAH' && $first <= 3 && $last >= 6)
        || ($league === '20min' && $seq === 'AHAA' && $first <= 1 && $last >= 5)
        || ($league === '15min' && $seq === 'HHHA' && $first === 0 && $last >= 6)
        || ($league === '20min' && $seq === 'AAAH' && $first <= 1)
        || ($league === '15min' && $seq === 'AAAA' && $first <= 4 && $last >= 7);
}

function matchesP42(array $m): bool {
    $league = $m['league'] ?? '';
    $first = $m['h1_first'] ?? -1;
    $last = $m['h1_last'] ?? -1;
    $seq = implode('', $m['h1s'] ?? []);

    if ($league !== '20min'
        || $first < 2
        || ($last - $first) < 6
        || ($m['min_gap'] ?? -1) < 3
        || $seq === 'HA') {
        return false;
    }

    if ($first === 2
        && $last === 9
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 1
        && $seq === 'HHA') {
        return false;
    }

    if (($m['max_gap'] ?? 0) >= 7
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_a'] ?? 0) > ($m['sc_h'] ?? 0)) {
        return false;
    }

    if (($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 2
        && $last === 9
        && $seq === 'HH') {
        return false;
    }

    if (($m['kickoff_hour'] ?? -1) === 7
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 0
        && ($m['sc_a'] ?? -1) === 2
        && $first === 2
        && $last === 8
        && $seq === 'AA') {
        return false;
    }

    if ($seq === 'HHH'
        && ($m['sc_h'] ?? -1) === 3
        && ($m['sc_a'] ?? -1) === 0
        && $first >= 3
        && $last >= 10
        && ($m['max_run'] ?? 0) >= 3
        && ($m['max_gap'] ?? 99) <= 4) {
        return false;
    }

    if (($m['kickoff_dow_num'] ?? -1) === 0
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 2
        && $last === 8
        && $seq === 'HH') {
        return false;
    }

    if (($m['kickoff_dow_num'] ?? -1) === 0
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 2
        && $last === 10
        && $seq === 'HH') {
        return false;
    }

    return true;
}

function matchesP41(array $m): bool {
    $league = $m['league'] ?? '';
    $first = $m['h1_first'] ?? -1;
    $last = $m['h1_last'] ?? -1;
    $seq = implode('', $m['h1s'] ?? []);

    if (abs(($m['sc_h'] ?? 0) - ($m['sc_a'] ?? 0)) < 2
        || $first < 2
        || ($last - $first) < 6
        || ($m['max_gap'] ?? -1) < 5) {
        return false;
    }

    if (($m['h1c'] ?? 0) === 2
        && ($m['max_gap'] ?? 0) >= 7
        && ($m['sc_a'] ?? 0) > ($m['sc_h'] ?? 0)) {
        return false;
    }

    if ($league === '20min'
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 2
        && $last === 9
        && $seq === 'HH') {
        return false;
    }

    if ($league === '20min'
        && $first >= 2
        && $last >= 10
        && $seq === 'HHH') {
        return false;
    }

    if ($league === '20min'
        && ($m['kickoff_hour'] ?? -1) === 7
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 0
        && ($m['sc_a'] ?? -1) === 2
        && $first === 2
        && $last === 8
        && $seq === 'AA') {
        return false;
    }

    if ($league === '20min'
        && ($m['kickoff_dow_num'] ?? -1) === 0
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 2
        && $last === 8
        && $seq === 'HH') {
        return false;
    }

    if ($league === '20min'
        && ($m['kickoff_dow_num'] ?? -1) === 0
        && ($m['h1c'] ?? 0) === 2
        && ($m['sc_h'] ?? -1) === 2
        && ($m['sc_a'] ?? -1) === 0
        && $first === 2
        && $last === 10
        && $seq === 'HH') {
        return false;
    }

    return true;
}

function matchesP65(array $m, array $p65_teams): bool {
    $home = trim($m['home'] ?? '');
    $first = $m['h1_first'] ?? -1;
    $last = $m['h1_last'] ?? -1;
    $seq = implode('', $m['h1s'] ?? []);

    if (($m['league'] ?? '') !== '15min'
        || !in_array($home, $p65_teams, true)
        || ($m['h1c'] ?? 0) < 1
        || $first > 1) {
        return false;
    }

    if ($home === 'Leicester City (V)' && $first === 0 && $last === 5 && $seq === 'HH') return false;
    if ($home === 'Leicester City (V)' && $first === 1 && $last === 5 && ($m['sc_h'] ?? -1) === 0 && ($m['sc_a'] ?? -1) === 2 && $seq === 'AA') return false;
    if ($home === 'Napoli (V)' && ($m['h1c'] ?? 0) === 1 && $first === 1 && ($m['sc_h'] ?? -1) === 0 && ($m['sc_a'] ?? -1) === 1 && $seq === 'A') return false;
    if ($home === 'Napoli (V)' && ($m['h1c'] ?? 0) === 2 && $first === 1 && $last === 5 && ($m['sc_h'] ?? -1) === 0 && ($m['sc_a'] ?? -1) === 2 && $seq === 'AA') return false;
    if ($home === 'Olympique Lyonnais (V)' && ($m['h1c'] ?? 0) === 1 && $first === 1 && ($m['sc_h'] ?? -1) === 1 && ($m['sc_a'] ?? -1) === 0 && $seq === 'H') return false;
    if ($first === 1 && $last === 3 && $seq === 'HH' && ($m['sc_h'] ?? -1) === 2 && ($m['sc_a'] ?? -1) === 0) return false;
    if ($first === 1 && $last === 3 && $seq === 'AA' && ($m['sc_h'] ?? -1) === 0 && ($m['sc_a'] ?? -1) === 2) return false;

    if (($m['kickoff_hour'] ?? -1) === 7
        && ($m['h1c'] ?? 0) === 1
        && $first === 0
        && $last === 0
        && ($m['sc_h'] ?? -1) === 1
        && ($m['sc_a'] ?? -1) === 0
        && $seq === 'H') {
        return false;
    }

    return true;
}

function p67NoTeamSignature(array $m): string {
    $seq = implode('', $m['h1s'] ?? []);
    $score = ($m['sc_h'] ?? 0) . '-' . ($m['sc_a'] ?? 0);

    return implode('|', [
        $seq,
        $score,
        (int)($m['h1c'] ?? 0),
        (int)($m['h1_first'] ?? -1),
        (int)($m['h1_last'] ?? -1),
        (int)($m['min_gap'] ?? 99),
        (int)($m['max_gap'] ?? 0),
        (int)($m['max_run'] ?? 0),
        (int)($m['switches'] ?? 0),
        (int)($m['kickoff_dow_num'] ?? -1),
        (int)($m['kickoff_hour'] ?? -1),
    ]);
}

function matchesP67(array $m): bool {
    if (($m['league'] ?? '') !== '20min' || ($m['h1_first'] ?? 99) > 1 || ($m['h1_last'] ?? -1) < 5) {
        return false;
    }

    static $keys = [
        'AAAHHA|2-4|6|1|9|1|3|3|2|0|18' => true,
        'AAAH|1-3|4|0|5|0|4|3|1|5|21' => true,
        'AAA|0-3|3|1|6|1|4|3|0|3|14' => true,
        'AAHAA|1-4|5|1|8|1|3|2|2|6|11' => true,
        'AAHA|1-3|4|0|9|2|5|2|2|0|12' => true,
        'AAHH|2-2|4|1|8|1|4|2|1|5|5' => true,
        'AAH|1-2|3|0|6|2|4|2|1|4|8' => true,
        'AAH|1-2|3|0|8|3|5|2|1|6|14' => true,
        'AA|0-2|2|0|8|8|8|2|0|3|14' => true,
        'AA|0-2|2|1|8|7|7|2|0|6|12' => true,
        'AHAA|1-3|4|1|9|1|5|2|2|3|4' => true,
        'AHAHAA|2-4|6|1|8|1|3|2|4|0|11' => true,
        'AHAH|2-2|4|1|9|2|4|1|3|4|15' => true,
        'AHA|1-2|3|1|5|1|3|1|2|6|15' => true,
        'AHA|1-2|3|1|5|2|2|1|2|4|11' => true,
        'AHA|1-2|3|1|6|1|4|1|2|4|12' => true,
        'AHA|1-2|3|1|7|3|3|1|2|5|2' => true,
        'AHA|1-2|3|1|8|2|5|1|2|6|22' => true,
        'AHA|1-2|3|1|8|3|4|1|2|1|16' => true,
        'AHA|1-2|3|1|9|4|4|1|2|6|13' => true,
        'AHHAA|2-3|5|0|9|0|4|2|2|0|17' => true,
        'AHHAA|2-3|5|1|9|0|4|2|2|4|7' => true,
        'AHHA|2-2|4|0|6|2|2|2|2|6|15' => true,
        'AHHA|2-2|4|1|8|0|5|2|2|2|11' => true,
        'AHH|2-1|3|0|6|2|4|2|1|6|10' => true,
        'AHH|2-1|3|1|9|1|7|2|1|1|14' => true,
        'AH|1-1|2|1|5|4|4|1|1|4|9' => true,
        'AH|1-1|2|1|5|4|4|1|1|6|5' => true,
        'AH|1-1|2|1|9|8|8|1|1|4|2' => true,
        'HAA|1-2|3|0|8|0|8|2|1|3|21' => true,
        'HAA|1-2|3|0|8|2|6|2|1|1|5' => true,
        'HAA|1-2|3|0|8|2|6|2|1|6|13' => true,
        'HAA|1-2|3|0|9|1|8|2|1|3|12' => true,
        'HAA|1-2|3|1|5|0|4|2|1|2|20' => true,
        'HAA|1-2|3|1|7|3|3|2|1|6|20' => true,
        'HAHAAH|3-3|6|1|8|0|3|2|4|4|11' => true,
        'HAHAA|2-3|5|1|7|1|2|2|3|6|15' => true,
        'HAHA|2-2|4|0|9|1|6|1|3|4|21' => true,
        'HAHA|2-2|4|1|8|2|3|1|3|4|13' => true,
        'HAHA|2-2|4|1|9|1|5|1|3|6|8' => true,
        'HAH|2-1|3|1|5|1|3|1|2|4|20' => true,
        'HAH|2-1|3|1|6|1|4|1|2|4|11' => true,
        'HAH|2-1|3|1|6|2|3|1|2|5|0' => true,
        'HAH|2-1|3|1|8|3|4|1|2|0|14' => true,
        'HA|1-1|2|0|5|5|5|1|1|2|19' => true,
        'HA|1-1|2|0|8|8|8|1|1|2|8' => true,
        'HA|1-1|2|0|8|8|8|1|1|4|23' => true,
        'HA|1-1|2|0|9|9|9|1|1|3|12' => true,
        'HA|1-1|2|0|9|9|9|1|1|6|13' => true,
        'HA|1-1|2|1|7|6|6|1|1|1|9' => true,
        'HA|1-1|2|1|9|8|8|1|1|3|14' => true,
        'HHA|2-1|3|0|7|1|6|2|1|4|21' => true,
        'HHA|2-1|3|0|7|2|5|2|1|6|20' => true,
        'HHA|2-1|3|0|8|1|7|2|1|0|14' => true,
        'HHA|2-1|3|0|8|1|7|2|1|2|21' => true,
        'HHA|2-1|3|0|9|1|8|2|1|6|6' => true,
        'HHHH|4-0|4|1|6|0|3|4|0|3|21' => true,
        'HHHH|4-0|4|1|9|1|5|4|0|4|23' => true,
        'HHH|3-0|3|0|7|1|6|3|0|3|21' => true,
        'HHH|3-0|3|1|6|2|3|3|0|4|21' => true,
        'HHH|3-0|3|1|7|1|5|3|0|5|4' => true,
        'HHH|3-0|3|1|8|1|6|3|0|6|14' => true,
        'HHH|3-0|3|1|8|2|5|3|0|1|17' => true,
        'HH|2-0|2|0|5|5|5|2|0|2|13' => true,
        'HH|2-0|2|0|5|5|5|2|0|6|9' => true,
        'HH|2-0|2|0|6|6|6|2|0|3|21' => true,
        'HH|2-0|2|0|8|8|8|2|0|6|15' => true,
        'HH|2-0|2|0|9|9|9|2|0|6|15' => true,
        'HH|2-0|2|1|5|4|4|2|0|1|11' => true,
        'HH|2-0|2|1|5|4|4|2|0|6|13' => true,
        'HH|2-0|2|1|8|7|7|2|0|6|2' => true,
        'HH|2-0|2|1|9|8|8|2|0|3|11' => true,
        'HH|2-0|2|1|9|8|8|2|0|4|9' => true,
    ];

    return isset($keys[p67NoTeamSignature($m)]);
}

function p77SingleGoalSignature(array $m): string {
    $seq = implode('', $m['h1s'] ?? []);
    $score = ($m['sc_h'] ?? 0) . '-' . ($m['sc_a'] ?? 0);

    return implode('|', [
        $m['league'] ?? '',
        (int)($m['h1c'] ?? 0),
        $seq,
        $score,
        (int)($m['h1_first'] ?? -1),
        (int)($m['kickoff_hour'] ?? -1),
    ]);
}

function matchesP77(array $m): bool {
    if (($m['h1c'] ?? 0) !== 1) {
        return false;
    }

    static $keys = [
        '15min|1|H|1-0|1|22' => true,
        '20min|1|A|0-1|9|20' => true,
        '15min|1|A|0-1|5|15' => true,
        '15min|1|H|1-0|4|7' => true,
        '20min|1|A|0-1|6|19' => true,
        '20min|1|H|1-0|7|21' => true,
        '15min|1|A|0-1|2|11' => true,
        '15min|1|A|0-1|3|11' => true,
        '15min|1|A|0-1|4|23' => true,
        '15min|1|A|0-1|5|11' => true,
        '15min|1|H|1-0|1|14' => true,
        '15min|1|H|1-0|1|18' => true,
        '15min|1|H|1-0|3|16' => true,
        '16min|1|A|0-1|4|11' => true,
        '16min|1|A|0-1|5|21' => true,
        '16min|1|H|1-0|1|14' => true,
        '16min|1|H|1-0|4|20' => true,
        '20min|1|A|0-1|2|9' => true,
        '20min|1|A|0-1|5|14' => true,
        '20min|1|A|0-1|9|19' => true,
        '20min|1|H|1-0|1|13' => true,
        '20min|1|H|1-0|1|15' => true,
        '20min|1|H|1-0|4|21' => true,
        '20min|1|H|1-0|6|19' => true,
        '20min|1|H|1-0|7|23' => true,
        '20min|1|H|1-0|8|10' => true,
        '20min|1|H|1-0|8|13' => true,
        '20min|1|H|1-0|8|14' => true,
    ];

    return isset($keys[p77SingleGoalSignature($m)]);
}

function matchesP78(array $m): bool {
    $h1c = (int)($m['h1c'] ?? 0);
    $first = (int)($m['h1_first'] ?? -1);
    $hour = (int)($m['kickoff_hour'] ?? -1);
    $span = $h1c >= 2 ? (int)($m['h1_last'] ?? -1) - $first : 0;
    $maxGap = (int)($m['max_gap'] ?? 0);

    return $h1c >= 1
        && $hour >= 16 && $hour <= 19
        && $first >= 2
        && ($span >= 6 || $maxGap >= 5);
}

function computePatterns(array $matches): array {
    $tc = getTeamConfig();
    $p24_teams = $tc['p24_teams'];
    $p25_teams = $tc['p25_teams'];
    $p28_teams = $tc['p28_teams'];
    $p35_teams = $tc['p35_teams'];
    $p36_teams = $tc['p36_teams'];
    $p61_teams = $tc['p61_teams'];
    $p62_teams = $tc['p62_teams'];
    $p63_teams = $tc['p63_teams'];
    $p64_teams = $tc['p64_teams'];
    $p65_teams = $tc['p65_teams'];
    $p66_teams = $tc['p66_teams'];

    return [
        ['id'=>'P2',  'label'=>'16min + selisih 2+ + first<=1 + last mnt 6-8 + gap>=3 + max_run<=2, bukan Minggu jam 12 HH 2-0 mnt 1-7, tanpa team block', 'data'=>array_values(array_filter($matches, fn($m) => matchesP2($m)))],
        [
            'id' => 'P6',
            'label' => 'Seri 1-1 + gol penyama mnt 7\' + span>=5 + first!=1 + home!=Manchester City/Atletico/England + bukan 15min HA saat first=0 + bukan AH saat first=0',
            'data' => array_values(array_filter($matches, fn($m) =>
                $m['h1c'] == 2 &&
                $m['sc_h'] == 1 &&
                $m['sc_a'] == 1 &&
                $m['h1_last'] == 7 &&
                ($m['h1_last'] - $m['h1_first']) >= 5 &&
                $m['h1_first'] != 1 &&
                !in_array(trim($m['home']), ['Manchester City (V)', 'Atletico de Madrid (V)', 'England (V)'], true) &&
                !(
                    $m['league'] === '15min' &&
                    $m['h1_first'] === 0 &&
                    count($m['h1s']) === 2 &&
                    $m['h1s'][0] === 'H' &&
                    $m['h1s'][1] === 'A'
                ) &&
                !(
                    $m['h1_first'] === 0 &&
                    count($m['h1s']) === 2 &&
                    $m['h1s'][0] === 'A' &&
                    $m['h1s'][1] === 'H'
                )
            )),
        ],
        ['id'=>'P7',  'label'=>'Seri 1-1 + gap >= 5 mnt + first goal >=3, bukan 16min AH 1-1 mnt 3-8, bukan Netherlands AH 1-1 mnt 4-9', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['max_gap']>=5 && $m['h1_first']>=3 && !($m['league']==='16min' && $m['h1_first']===3 && $m['h1_last']===8 && $m['h1s']===['A','H']) && !(trim($m['home'])==='Netherlands (V)' && $m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)))],
        ['id'=>'P9',  'label'=>'AH seri 1-1 + gap >= 5 mnt + first goal >=3, bukan 16min AH 1-1 mnt 3-8', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && $m['max_gap']>=5 && $m['h1_first']>=3 && !($m['league']==='16min' && $m['h1_first']===3 && $m['h1_last']===8 && $m['h1s']===['A','H'])))],
        [
            'id' => 'P12',
            'label' => 'Total gol 1H >= 4 + span >= 6 mnt + min_gap>=1 + lm<=9 + first>=2 + max_run<=2 + struktur multi-branch (HAHA/AHAH/AHHH/HHAA/HAAH/AHAA/HHHA/AAAH/AAAA), bukan 20min AAAH 1-3 mnt 0-10 min_gap=0, tanpa team block',
            'data' => array_values(array_filter($matches, fn($m) => matchesP12Expanded($m))),
        ],
        ['id'=>'P13', 'label'=>'First 2\' + last 7\' + selisih <=2 + min_gap>=3 + switches>=1, kecuali Man City vs Liverpool dan England vs Spain', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']===2 && $m['h1_last']===7 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['min_gap']>=3 && $m['switches']>=1 && !(trim($m['home'])==='Manchester City (V)' && trim($m['away'])==='Liverpool (V)') && !(trim($m['home'])==='England (V)' && trim($m['away'])==='Spain (V)')))],
        ['id'=>'P14', 'label'=>'Seri + gap >= 4 mnt + span >= 5 mnt + first goal >=3 + min_gap>=2, bukan 16min AH 1-1 mnt 3-8, bukan Netherlands AH 1-1 mnt 4-9', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['sc_h']===$m['sc_a'] && $m['sc_h']>0 && $m['max_gap']>=4 && ($m['h1_last']-$m['h1_first'])>=5 && $m['h1_first']>=3 && $m['min_gap']>=2 && !($m['league']==='16min' && $m['h1_first']===3 && $m['h1_last']===8 && $m['h1s']===['A','H']) && !(trim($m['home'])==='Netherlands (V)' && $m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)))],
        ['id'=>'P15', 'label'=>'HT 2-2 + max_gap<=2 + bukan last=8 atau (first=0 dan last=5), bukan 15min AHHA mnt 1-2-4-6, bukan 20min HAAH 2-2 mnt 0-5, atau 20min + HT 2-2 + max_gap=3 + first=0 + last<=7, atau 16min + HT 2-2 + max_gap=3 + first>=2, atau 15min + HT 2-2 + max_gap=3 + first=1 + last<=6', 'data'=>array_values(array_filter($matches, fn($m) => (($m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']<=2 && $m['h1_last']!=8 && !($m['h1_first']===0 && $m['h1_last']===5)) || ($m['league']==='20min' && $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']===3 && $m['h1_first']===0 && $m['h1_last']<=7) || ($m['league']==='16min' && $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']===3 && $m['h1_first']>=2) || ($m['league']==='15min' && $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']===3 && $m['h1_first']===1 && $m['h1_last']<=6)) && !($m['league']==='15min' && $m['h1s']===['A','H','H','A'] && array_map(fn($g) => $g['min'], $m['h1'])===[1,2,4,6]) && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','A','A','H'] && $m['sc_h']===2 && $m['sc_a']===2)))],
        ['id'=>'P17', 'label'=>'First 1H mnt 1-2 + last mnt 7 + min_gap>=2 + switches>=1 + first scorer AWAY + (15min first=1, atau 20min first=2 / away unggul HT), kecuali Bordeaux vs Lyon', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']>=1 && $m['h1_first']<=2 && $m['h1_last']==7 && $m['max_gap']>=2 && $m['min_gap']>=2 && $m['switches']>=1 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && (($m['league']==='15min' && $m['h1_first']===1) || ($m['league']==='20min' && ($m['h1_first']===2 || $m['sc_a']>$m['sc_h']))) && !(trim($m['home'])==='Girondins de Bordeaux (V)' && trim($m['away'])==='Olympique Lyonnais (V)')))],
        ['id'=>'P19', 'label'=>'20min + last scorer HOME + first<=1 + switches>=1, lalu: last=2, atau last=3 (first=0 atau gol 1H>=3), atau last=4 (AWAY unggul HT / switches>=2), atau AH 1-1 last=1/7, atau last=9 first=1 h1c=3 (AAH 1-2 / AHH 2-1), bukan HHHAHH 5-1 mnt 1-3', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && $m['h1c']>=2 && (($m['h1_last']===2) || (($m['h1_last']===3) && ($m['h1_first']===0 || $m['h1c']>=3)) || ($m['h1_last']===4 && ($m['sc_a']>$m['sc_h'] || $m['switches']>=2)) || (in_array($m['h1_last'], [1, 7], true) && $m['sc_h']===1 && $m['sc_a']===1 && $m['h1s']===['A','H']) || ($m['h1_first']===1 && $m['h1_last']===9 && $m['h1c']===3 && (($m['h1s']===['A','A','H'] && $m['sc_h']===1 && $m['sc_a']===2 && $m['min_gap']>=2) || ($m['h1s']===['A','H','H'] && $m['sc_h']===2 && $m['sc_a']===1 && $m['min_gap']>=1)))) && $m['h1_first']<=1 && $m['switches']>=1 && !($m['h1_first']===1 && $m['h1_last']===3 && $m['h1s']===['H','H','H','A','H','H'] && $m['sc_h']===5 && $m['sc_a']===1)))],
        ['id'=>'P53', 'label'=>'20min + last gol 1H mnt 3 + last scorer HOME + min_gap>=1 + gol 1H==2 + (first=0 atau away HT=0), bukan Cyprus HH 2-0 mnt 0-3, atau AH 1-1 mnt 1-3 kecuali Paraguay vs Bosnia-Herzegovina, atau 20min + last=4 + last scorer AWAY + min_gap>=1 + gol 1H<=2 + (first=0 atau away HT=0), kecuali Greece vs Ukraine', 'data'=>array_values(array_filter($matches, fn($m) => (($m['league']==='20min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && $m['min_gap']>=1 && $m['h1c']===2 && ($m['h1_first']===0 || $m['sc_a']===0)) || ($m['league']==='20min' && $m['h1_first']===1 && $m['h1_last']===3 && $m['h1c']===2 && $m['sc_h']===1 && $m['sc_a']===1 && $m['h1s']===['A','H'] && !(trim($m['home'])==='Paraguay (V)' && trim($m['away'])==='Bosnia-Herzegovina (V)')) || ($m['league']==='20min' && $m['h1_last']===4 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['min_gap']>=1 && $m['h1c']<=2 && ($m['h1_first']===0 || $m['sc_a']===0))) && !(trim($m['home'])==='Cyprus (V)' && $m['h1_first']===0 && $m['h1_last']===3 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0) && !(trim($m['home'])==='Greece (V)' && trim($m['away'])==='Ukraine (V)')))],
        ['id'=>'P20', 'label'=>'Last gol 1H mnt 3, last AWAY, 16min + (first goal<=1 atau gol 1H>=2), kecuali first=0 + scorer AA + sc_h=0 atau first=1 + h1c=2 + scorer HA', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && ($m['h1_first']<=1 || $m['h1c']>=2) && !($m['h1_first']===0 && $m['h1s']===['A','A'] && $m['sc_h']===0) && !($m['h1_first']===1 && $m['h1c']===2 && $m['h1s']===['H','A'])))],
        ['id'=>'P21', 'label'=>'Last gol 1H mnt 5, last AWAY, 15min, max_gap>=2 + min_gap>=1 + sw>=1 + max_run<=2 + first>=1 (n1h>=3 atau AWAY unggul), bukan first=1 last=5 min_gap=1 max_run=2 away lead', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1_last']===5 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=2 && $m['min_gap']>=1 && ($m['h1c']>=3 || $m['sc_a']>$m['sc_h']) && $m['switches']>=1 && $m['max_run']<=2 && $m['h1_first']>=1 && !($m['h1_first']===1 && $m['h1_last']===5 && $m['min_gap']===1 && $m['max_run']===2 && $m['sc_a']>$m['sc_h'])))],
        [
            'id'=>'P24',
            'label'=>'HOME shortlist: Arminia / Osasuna / Arsenal / Leicester / Dortmund / Liverpool / Monaco / Marseille / Atalanta / Spurs / Everton (15min, lm>=4, selisih<=1, HOME cetak>=1, fm>=4, first scorer HOME, Everton khusus lm<=5, bukan single HOME mnt 4/5, Arminia bukan single goal mnt 5-6, bukan Arsenal HA 1-1 mnt 6-7, bukan Marseille vs Udinese single HOME mnt 6, bukan HAA 1-2 mnt 5-7, kecuali Marseille vs Liverpool)',
            'data'=>array_values(array_filter($matches, fn($m) =>
                $m['league']==='15min' && in_array(trim($m['home']), $p24_teams) && $m['h1c']>=1 && $m['h1_last']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['sc_h']>=1 && $m['h1_first']>=4 && count($m['h1s'])>0 && $m['h1s'][0]==='H' && (trim($m['home'])!=='Everton (V)' || $m['h1_last']<=5) && (trim($m['home'])!=='Arminia Bielefeld (V)' || !($m['h1c']===1 && $m['h1_first']>=5 && $m['h1_last']<=6)) && !($m['h1c']===1 && $m['h1_first']===4 && $m['h1_last']===4 && $m['h1s']===['H'] && $m['sc_h']===1 && $m['sc_a']===0) && !($m['h1c']===1 && $m['h1_last']===5) && !(trim($m['home'])==='Arsenal (V)' && $m['h1_first']===6 && $m['h1_last']===7 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1) && !(trim($m['home'])==='Olympique de Marseille (V)' && trim($m['away'])==='Udinese (V)' && $m['h1c']===1 && $m['h1_first']===6 && $m['h1s']===['H'] && $m['sc_h']===1 && $m['sc_a']===0) && !($m['h1_first']===5 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !(trim($m['home'])==='Olympique de Marseille (V)' && trim($m['away'])==='Liverpool (V)')
            )),
        ],
        [
            'id'=>'P25',
            'label'=>'AWAY: Real Sociedad / France / Netherlands / Ukraine (lm>=2, selisih<=1, span>=3, min_gap>=2, last scorer AWAY, bukan h1c=2 span=3 first=4, bukan 20min HA 1-1 mnt 0-5)',
            'data'=>array_values(array_filter($matches, fn($m) =>
                in_array(trim($m['away']), $p25_teams) && $m['h1_last']>=2 && abs($m['sc_h']-$m['sc_a'])<=1 && ($m['h1_last']-$m['h1_first'])>=3 && $m['min_gap']>=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && !($m['h1c']===2 && ($m['h1_last']-$m['h1_first'])===3 && $m['h1_first']===4) && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1)
            )),
        ],
        ['id'=>'P26', 'label'=>'HT total ganjil (1,3,5...), 16min, last mnt >=6, gol 1H >=2, away unggul + max_run<=2 + (first!=1 atau max_gap>=4), bukan HAA 1-2 mnt 3-8/5-8, atau 16min exact odd away-lead strong groups 2+ sample', 'data'=>array_values(array_filter($matches, fn($m) =>
            ($m['league']==='16min' && ($m['sc_h']+$m['sc_a'])%2===1 && $m['h1_last']>=6 && $m['h1c']>=2 && $m['sc_a']>$m['sc_h'] && $m['max_run']<=2 && ($m['h1_first']!=1 || $m['max_gap']>=4) && !($m['h1_last']===8 && in_array($m['h1_first'], [3,5], true) && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2))
            || in_array($m['league'] . '|' . $m['h1_first'] . '|' . $m['h1_last'] . '|' . implode('', $m['h1s']) . '|' . $m['sc_h'] . '-' . $m['sc_a'], ['16min|0|6|HAA|1-2','16min|3|7|AAA|0-3'], true)
        ))],
        ['id'=>'P27', 'label'=>'Gol terakhir 1H dicetak AWAY, 16min, max_gap>=3, first!=1, span>=6 + (switches>=1 atau max_gap>=6)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=3 && $m['h1_first']!=1 && ($m['h1_last']-$m['h1_first'])>=6 && ($m['switches']>=1 || $m['max_gap']>=6)))],
        ['id'=>'P28', 'label'=>'Croatia atau France main + last mnt >=3 + span >=3 + switches>=1 + (target team away atau first>=2), bukan 20min AHA 1-2 first=4 last=8, bukan 20min HAH 2-1 first=2 last>=9, bukan 16min HAA 1-2 mnt 5-8', 'data'=>array_values(array_filter($matches, fn($m) => (in_array(trim($m['home']), $p28_teams) || in_array(trim($m['away']), $p28_teams)) && $m['h1_last']>=3 && ($m['h1_last']-$m['h1_first'])>=3 && $m['switches']>=1 && (in_array(trim($m['away']), $p28_teams) || $m['h1_first']>=2) && !($m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===8 && $m['sc_h']===1 && $m['sc_a']===2 && $m['h1s']===['A','H','A']) && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']>=9 && $m['sc_h']===2 && $m['sc_a']===1 && $m['h1s']===['H','A','H']) && !($m['league']==='16min' && $m['h1_first']===5 && $m['h1_last']===8 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2)))],
        ['id'=>'P32', 'label'=>'Span 1H >=9 mnt + gol >=2 + HT seri + min_gap>=3 + switches>=1 + first!=1, 20min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=9 && $m['sc_h']===$m['sc_a'] && $m['min_gap']>=3 && $m['switches']>=1 && $m['h1_first']!=1))],
        [
            'id'=>'P54',
            'label'=>'20min + AWAY unggul HT + last gol 1H mnt 9 + span>=4 + first>=2 + h1c<=4, bukan first=2 + scorer AAH, bukan AA 0-2 mnt 2-9/4-9, bukan China AA 0-2 mnt 5-9, bukan HAA 1-2 mnt 5-9',
            'data'=>array_values(array_filter($matches, fn($m) =>
                $m['league']==='20min' && $m['sc_a']>$m['sc_h'] && $m['h1_last']===9 && ($m['h1_last']-$m['h1_first'])>=4 && $m['h1_first']>=2 && $m['h1c']<=4 && !($m['h1_first']===2 && $m['h1s']===['A','A','H']) && !($m['h1_first']===2 && $m['h1_last']===9 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !($m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !(trim($m['home'])==='China (V)' && $m['h1_first']===5 && $m['h1_last']===9 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !($m['h1_first']===5 && $m['h1_last']===9 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2)
            )),
        ],
        [
            'id'=>'P33',
            'label'=>'Total gol 1H >=4 + selisih HT <=1 + min_gap>=1 + last gol 1H>=6 + (switches>=2 atau first goal>=1), 15min, bukan AHHA max_gap=2 + (last>=8 atau first<=1), bukan AAHH 2-2 mnt 3-8 max_gap=2',
            'data'=>array_values(array_filter($matches, fn($m) =>
                $m['league']==='15min' && $m['h1c']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['min_gap']>=1 && $m['h1_last']>=6 && ($m['switches']>=2 || $m['h1_first']>=1) && !($m['h1s']===['A','H','H','A'] && $m['max_gap']===2 && ($m['h1_last']>=8 || $m['h1_first']<=1)) && !($m['h1_first']===3 && $m['h1_last']===8 && $m['h1s']===['A','A','H','H'] && $m['sc_h']===2 && $m['sc_a']===2 && $m['max_gap']===2)
            )),
        ],
        ['id'=>'P34', 'label'=>'First AWAY + last HOME + gol 1H >=4, 15min + (span >=6 atau first=1 + last=6), bukan AAHH 2-2 mnt 0-7 min_gap=2, bukan AHAHH 3-2 mnt 0-7', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='H' && $m['h1c']>=4 && ((($m['h1_last']-$m['h1_first'])>=6) || ($m['h1_first']===1 && $m['h1_last']===6)) && !($m['h1_first']===0 && $m['h1_last']===7 && $m['h1s']===['A','A','H','H'] && $m['sc_h']===2 && $m['sc_a']===2 && $m['min_gap']===2) && !($m['h1_first']===0 && $m['h1_last']===7 && $m['h1s']===['A','H','A','H','H'] && $m['sc_h']===3 && $m['sc_a']===2)))],
        [
            'id'=>'P35',
            'label'=>'AWAY shortlist: Mexico / Belgium / Germany / AS Monaco / Wales / Portugal / Osasuna / Austria / Poland / Croatia / Algeria + gol 1H>=2 + first>=3 + max_run<=2 + last gol 1H>=5 + min_gap>=2 + bukan HH mnt 3&5 + bukan 16min HH 2-0 mnt 4-6 + bukan 16min HH 2-0 first>=5 last=7 + bukan Poland 16min AA 0-2 mnt 5-7 + bukan 20min AA 0-2 mnt 4-9 + bukan HA 1-1 mnt 3-5 + bukan HAA mnt 3-7 + bukan 20min AH 1-1 mnt 4-6, atau 16min shortlist first=1 + h1c>=3, atau shortlist first=0 + h1c>=3 + last>=6 + last scorer AWAY + bukan 20min AHA 1-2 mnt 0-7, atau Portugal single AWAY bukan mnt 0, atau h1c=3 scorer HHA min_gap=1',
            'data'=>array_values(array_filter($matches, fn($m) =>
                (in_array(trim($m['away']), $p35_teams) && $m['h1c']>=2 && $m['h1_first']>=3 && $m['max_run']<=2 && $m['h1_last']>=5 && $m['min_gap']>=2 && !(count($m['h1s'])===2 && $m['h1s'][0]==='H' && $m['h1s'][1]==='H' && $m['h1_first']===3 && $m['h1_last']===5) && !($m['league']==='16min' && $m['h1c']===2 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H'] && $m['h1_first']===4 && $m['h1_last']===6) && !($m['league']==='16min' && $m['h1c']===2 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H'] && $m['h1_first']>=5 && $m['h1_last']===7) && !(trim($m['away'])==='Poland (V)' && $m['league']==='16min' && $m['h1_first']===5 && $m['h1_last']===7 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !($m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1c']===2 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A']) && !($m['h1_first']===3 && $m['h1_last']===5 && $m['h1c']===2 && $m['sc_h']===1 && $m['sc_a']===1 && $m['h1s']===['H','A']) && !($m['h1_first']===3 && $m['h1_last']===7 && $m['h1s']===['H','A','A']) && !($m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===6 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1))
                || ($m['league']==='16min' && in_array(trim($m['away']), $p35_teams) && $m['h1c']>=3 && $m['h1_first']===1 && $m['max_run']<=2 && $m['h1_last']>=5 && $m['min_gap']>=2)
                || (in_array(trim($m['away']), $p35_teams) && $m['h1c']>=3 && $m['h1_first']===0 && $m['max_run']<=2 && $m['h1_last']>=6 && $m['min_gap']>=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && !($m['league']==='20min' && $m['h1_last']===7 && $m['h1s']===['A','H','A'] && $m['sc_h']===1 && $m['sc_a']===2))
                || (trim($m['away'])==='Portugal (V)' && $m['h1c']===1 && $m['h1s']===['A'] && !($m['h1_first']===0 && $m['h1_last']===0 && $m['sc_h']===0 && $m['sc_a']===1))
                || (in_array(trim($m['away']), $p35_teams) && $m['h1c']===3 && $m['h1s']===['H','H','A'] && $m['min_gap']===1)
            )),
        ],
        ['id'=>'P37', 'label'=>'16min + first & last scorer AWAY + first<=1 + gol 1H>=2 + tanpa balas HOME + last gol 1H<=4, kecuali first=0 + scorer AA + sc_h=0', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']>=2 && $m['h1_first']<=1 && $m['h1_last']<=4 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='A' && $m['switches']===0 && !($m['h1_first']===0 && $m['h1s']===['A','A'] && $m['sc_h']===0)))],
        ['id'=>'P39', 'label'=>'Gol 1H >=3 + span >=7 mnt + selisih <=3 + fm>=2 + min_gap>=3, 20min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=3 && ($m['h1_last']-$m['h1_first'])>=7 && abs($m['sc_h']-$m['sc_a'])<=3 && $m['min_gap']>=3 && $m['h1_first']>=2))],
        ['id'=>'P40', 'label'=>'16min + selisih HT tepat 2 + first goal <=1 + span>=5 + (min_gap>=1 atau max_gap>=4), bukan HH 2-0 mnt 0-5/1-7, bukan last AWAY + home lead>=2 + max_run>=3 + max_gap<=3, bukan jam 18 + AAHA 1-3 mnt 1-6 max_gap<=2', 'data'=>array_values(array_filter($matches, fn($m) =>
            $m['league']==='16min' && abs($m['sc_h']-$m['sc_a'])===2 && $m['h1_first']<=1 && ($m['h1_last']-$m['h1_first'])>=5 && ($m['min_gap']>=1 || $m['max_gap']>=4) && !(($m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0) || ($m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)) && !(count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && ($m['sc_h']-$m['sc_a'])>=2 && $m['max_run']>=3 && $m['max_gap']<=3) && !(($m['kickoff_hour'] ?? -1)===18 && $m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','A','H','A'] && $m['sc_h']===1 && $m['sc_a']===3 && $m['max_gap']<=2)
        ))],
        ['id'=>'P41', 'label'=>'Selisih HT >=2 + first goal >=2 + span >=6 + max_gap>=5, kecuali h1c=2 + max_gap>=7 + away unggul HT, atau 20min HH 2-0 mnt 2-9, bukan 20min HHH first>=2 last>=10, bukan jam 07 AA 0-2 mnt 2-8, bukan Minggu 20min HH 2-0 mnt 2-8/2-10', 'data'=>array_values(array_filter($matches, fn($m) => matchesP41($m)))],
        ['id'=>'P42', 'label'=>'20min + first goal >=2 + span >=6 + min_gap>=3, bukan scorer HA, bukan HHA 2-1 mnt 2-9, kecuali max_gap>=7 + h1c=2 + away unggul HT, atau HH 2-0 mnt 2-9, bukan jam 07 AA 0-2 mnt 2-8, bukan HHH 3-0 first>=3 last>=10 max_gap<=4, bukan Minggu HH 2-0 mnt 2-8/2-10', 'data'=>array_values(array_filter($matches, fn($m) => matchesP42($m)))],
        ['id'=>'P43', 'label'=>'Away unggul HT + span >=6 + last scorer HOME + diff==1 + gol 1H<=3, bukan 20min AAH 1-2 mnt 0-9/1-8/2-9', 'data'=>array_values(array_filter($matches, fn($m) => $m['sc_a']>$m['sc_h'] && ($m['h1_last']-$m['h1_first'])>=6 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['sc_a']-$m['sc_h'])===1 && $m['h1c']<=3 && !($m['league']==='20min' && $m['sc_h']===1 && $m['sc_a']===2 && $m['h1s']===['A','A','H'] && (($m['h1_first']===0 && $m['h1_last']===9) || ($m['h1_first']===1 && $m['h1_last']===8) || ($m['h1_first']===2 && $m['h1_last']===9)))))],
        ['id'=>'P44', 'label'=>'Selisih HT >=2 + first goal >=2 + switches>=1 + max_run<=2 + (20min atau last>=8 atau HT 3-1), bukan 20min AHAA 1-3 mnt 2-7', 'data'=>array_values(array_filter($matches, fn($m) => abs($m['sc_h']-$m['sc_a'])>=2 && $m['h1_first']>=2 && $m['switches']>=1 && $m['max_run']<=2 && ($m['league']==='20min' || $m['h1_last']>=8 || ($m['sc_h']===3 && $m['sc_a']===1)) && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']===7 && $m['h1s']===['A','H','A','A'] && $m['sc_h']===1 && $m['sc_a']===3)))],
        ['id'=>'P45', 'label'=>'16min + first goal 0\' + span >=6 + gap>=6 + max_run<=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_first']===0 && ($m['h1_last']-$m['h1_first'])>=6 && $m['max_gap']>=6 && $m['max_run']<=2))],
        ['id'=>'P46', 'label'=>'16min + span >=6 + min_gap>=2 + h1c==2 + (first=0 atau switches=0), bukan HH 2-0 mnt 1-7', 'data'=>array_values(array_filter($matches, fn($m) =>
            $m['league']==='16min' && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=2 && $m['h1c']===2 && ($m['h1_first']===0 || $m['switches']===0) && !($m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
        ))],
        ['id'=>'P47', 'label'=>'HT seri + first goal !=1 + switches>=2 + last gol 1H>=6 + min_gap>=1 + max_gap>=3, bukan 20min AHAH mnt first=2 last=7', 'data'=>array_values(array_filter($matches, fn($m) => $m['sc_h']===$m['sc_a'] && $m['h1_first']!=1 && $m['switches']>=2 && $m['h1_last']>=6 && $m['min_gap']>=1 && $m['max_gap']>=3 && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']===7 && $m['h1s']===['A','H','A','H'])))],
        [
            'id'=>'P48',
            'label'=>'HT seri + span >=7 + switches>=2 + (first goal 0 / span tepat 7 / last scorer HOME), bukan 20min AHHA 2-2 mnt 0-7 min_gap=0, bukan 20min HAHA 2-2 mnt 0-9 min_gap=0',
            'data'=>array_values(array_filter($matches, fn($m) =>
                $m['sc_h']===$m['sc_a'] && ($m['h1_last']-$m['h1_first'])>=7 && $m['switches']>=2 && ($m['h1_first']===0 || ($m['h1_last']-$m['h1_first'])===7 || (count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H')) && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===7 && $m['h1c']===4 && $m['sc_h']===2 && $m['sc_a']===2 && $m['min_gap']===0 && $m['h1s']===['A','H','H','A']) && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===9 && $m['h1c']===4 && $m['sc_h']===2 && $m['sc_a']===2 && $m['min_gap']===0 && $m['h1s']===['H','A','H','A'])
            )),
        ],
        ['id'=>'P49', 'label'=>'16min + selisih HT >=2 + span >=6 + first>=1, bukan HH 2-0 mnt 1-7', 'data'=>array_values(array_filter($matches, fn($m) =>
            $m['league']==='16min' && abs($m['sc_h']-$m['sc_a'])>=2 && ($m['h1_last']-$m['h1_first'])>=6 && $m['h1_first']>=1 && !($m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
        ))],
        ['id'=>'P50', 'label'=>'16min + away unggul HT + span >=6 + max_run<=2 + (first=0 atau max_gap>=6)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_a']>$m['sc_h'] && ($m['h1_last']-$m['h1_first'])>=6 && $m['max_run']<=2 && ($m['h1_first']===0 || $m['max_gap']>=6)))],
        ['id'=>'P51', 'label'=>'16min + switches>=2 + first!=1, bukan AHHAH 3-2 mnt 0-8 min_gap=0', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['switches']>=2 && $m['h1_first']!=1 && !($m['h1_first']===0 && $m['h1_last']===8 && $m['h1s']===['A','H','H','A','H'] && $m['sc_h']===3 && $m['sc_a']===2 && $m['min_gap']===0)))],
        ['id'=>'P52', 'label'=>'16min + span >=6 + min_gap>=3 + selisih HT>=2, bukan HH 2-0 mnt 1-7', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=3 && abs($m['sc_h']-$m['sc_a'])>=2 && !($m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)))],
        ['id'=>'P55', 'label'=>'16min + last gol 1H mnt 8 + AWAY unggul HT, bukan first=3 + scorer HAA + HT 1-2, bukan HAA 1-2 mnt 5-8 min_gap=0, bukan single AWAY mnt 8', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===8 && $m['sc_a']>$m['sc_h'] && !($m['h1_first']===3 && $m['sc_h']===1 && $m['sc_a']===2 && $m['h1s']===['H','A','A']) && !($m['h1_first']===5 && $m['h1_last']===8 && $m['sc_h']===1 && $m['sc_a']===2 && $m['h1s']===['H','A','A'] && $m['min_gap']===0) && !($m['h1c']===1 && $m['h1_first']===8 && $m['h1s']===['A'] && $m['sc_h']===0 && $m['sc_a']===1)))],
        ['id'=>'P56', 'label'=>'16min + max_gap>=6 + last scorer AWAY', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['max_gap']>=6 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A'))],
        [
            'id'=>'P57',
            'label'=>'First goal 1H mnt 0 + last gol 1H mnt 6 + first scorer AWAY + (15min atau 16min atau gol 1H>=3) + (switches>=2 atau max_gap>=4) + bukan scorer AHH/AAA + bukan 15min AA 0-2 mnt 0-6, bukan 16min AAAA 0-4 mnt 0-6',
            'data'=>array_values(array_filter($matches, fn($m) =>
                $m['h1_first']===0 && $m['h1_last']===6 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && ($m['league']==='15min' || $m['league']==='16min' || $m['h1c']>=3) && ($m['switches']>=2 || $m['max_gap']>=4) && $m['h1s']!==['A','H','H'] && $m['h1s']!==['A','A','A'] && !($m['league']==='15min' && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !($m['league']==='16min' && $m['h1s']===['A','A','A','A'] && $m['sc_h']===0 && $m['sc_a']===4)
            )),
        ],
        [
            'id'=>'P58',
            'label'=>'First goal 1H >=3 + span>=5 + min_gap>=3 + bukan first=4 + span=5 + scorer HH + switches=0, atau 20min + first=2 + span>=5 + min_gap>=3 + (last scorer HOME atau gol 1H>=3) + bukan HH mnt 2-9, atau 16min + first=1 + span>=5 + min_gap>=3 + max_gap>=4, atau 16min + first=0 + span>=5 + min_gap>=3 + selisih HT>=2, bukan 20min AH 1-1 mnt 4-9 jam 22 menit<=14, bukan 20min HH 2-0 mnt 2-7, bukan 20min HHA 2-1 mnt 2-9, bukan 20min AA 0-2 mnt 4-9, bukan 20min HHH 3-0 first>=3 last>=10 max_gap<=4, bukan Minggu 20min HH 2-0 mnt 2-8/2-10, bukan HH 2-0 mnt 0-5/1-7, bukan 16min single AWAY mnt 1/8, bukan 15min HAA 1-2 mnt 2-7, bukan 16min AH 1-1 mnt 3-8, atau 15min h1c=3 + first=2 + span=5 + min_gap=1, atau 15min first=0 + last=6 + span=6 + min_gap=2',
            'data'=>array_values(array_filter($matches, fn($m) =>
                (
                    ($m['h1_first']>=3 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && !($m['h1_first']===4 && ($m['h1_last']-$m['h1_first'])===5 && $m['h1s']===['H','H'] && $m['switches']===0))
                    || ($m['league']==='20min' && $m['h1_first']===2 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && ((count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H') || $m['h1c']>=3) && !($m['h1_last']===9 && $m['h1s']===['H','H'] && $m['switches']===0))
                    || ($m['league']==='16min' && $m['h1_first']===1 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && $m['max_gap']>=4)
                    || ($m['league']==='16min' && $m['h1_first']===0 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && abs($m['sc_h']-$m['sc_a'])>=2 && !($m['sc_h']===2 && $m['sc_a']===0 && $m['h1_last']===5 && $m['h1s']===['H','H']))
                    || ($m['league']==='15min' && $m['h1c']===3 && $m['h1_first']===2 && ($m['h1_last']-$m['h1_first'])===5 && $m['min_gap']===1)
                    || ($m['league']==='15min' && $m['h1_first']===0 && $m['h1_last']===6 && ($m['h1_last']-$m['h1_first'])===6 && $m['min_gap']===2)
                )
                && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']===7 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H'])
                && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']===9 && $m['sc_h']===2 && $m['sc_a']===1 && $m['h1s']===['H','H','A'])
                && !($m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1c']===2 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A'])
                && !($m['league']==='20min' && ($m['kickoff_hour'] ?? -1)===22 && ($m['kickoff_minute'] ?? -1)<=14 && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)
                && !($m['league']==='20min' && $m['h1_first']>=3 && $m['h1_last']>=10 && $m['h1s']===['H','H','H'] && $m['sc_h']===3 && $m['sc_a']===0 && $m['max_run']>=3 && $m['max_gap']<=4)
                && !($m['league']==='20min' && ($m['kickoff_dow_num'] ?? -1)===0 && $m['h1c']===2 && $m['h1_first']===2 && $m['h1_last']===8 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                && !($m['league']==='20min' && ($m['kickoff_dow_num'] ?? -1)===0 && $m['h1c']===2 && $m['h1_first']===2 && $m['h1_last']===10 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                && !($m['league']==='16min' && $m['h1c']===1 && $m['sc_h']===0 && $m['sc_a']===1 && in_array($m['h1_first'], [1, 8], true))
                && !($m['league']==='16min' && $m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                && !($m['league']==='15min' && $m['h1_first']===2 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2)
                && !($m['league']==='16min' && $m['h1_first']===3 && $m['h1_last']===8 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)
            )),
        ],
        [
            'id' => 'P59',
            'label' => 'Last gol 1H mnt 9 + switches>=2 + last scorer AWAY + (first scorer AWAY atau max_gap>=6) + bukan first=1/h1c=3/scorer AHA + bukan h1c=5/first=2 + bukan 20min HAHA 2-2 mnt 0-9 min_gap=0',
            'data' => array_values(array_filter($matches, fn($m) =>
                $m['h1_last'] === 9 &&
                $m['switches'] >= 2 &&
                count($m['h1s']) > 0 &&
                $m['h1s'][count($m['h1s']) - 1] === 'A' &&
                ($m['h1s'][0] === 'A' || $m['max_gap'] >= 6) &&
                !(
                    $m['h1_first'] === 1 &&
                    $m['h1c'] === 3 &&
                    count($m['h1s']) === 3 &&
                    $m['h1s'][0] === 'A' &&
                    $m['h1s'][1] === 'H' &&
                    $m['h1s'][2] === 'A'
                ) &&
                !($m['h1c'] === 5 && $m['h1_first'] === 2) &&
                !($m['league'] === '20min' && $m['h1_first'] === 0 && $m['h1_last'] === 9 && $m['h1c'] === 4 && $m['sc_h'] === 2 && $m['sc_a'] === 2 && $m['min_gap'] === 0 && $m['h1s'] === ['H','A','H','A'])
            )),
        ],
        [
            'id'=>'P60',
            'label'=>'20min + first goal 1H mnt 3 + HT seri + last gol 1H>=5 + bukan Denmark HOME HA 1-1 mnt 3-5, bukan scorer HA saat last=7, bukan AH 1-1 mnt 3-6',
            'data'=>array_values(array_filter($matches, fn($m) =>
                $m['league']==='20min' && $m['h1_first']===3 && $m['sc_h']===$m['sc_a'] && $m['h1_last']>=5 && !(trim($m['home'])==='Denmark (V)' && $m['h1_last']===5 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_last']===7 && $m['h1s']===['H','A']) && !($m['h1_last']===6 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)
            )),
        ],
        [
            'id'=>'P61',
            'label'=>'AWAY shortlist: Chelsea / Lille / Juventus / Monaco (15min, last>=5, diff<=1, kecuali Bordeaux vs Lille dan Monaco HA 1-1 mnt 3-5, bukan single goal mnt 5, bukan single AWAY 0-1 mnt 6, bukan AH 1-1 mnt 3-5/4-6/3-7, bukan AHA 1-2 last=7 min_gap=0, bukan HAA 1-2 mnt 0-5, bukan HAA 1-2 mnt 5-7, bukan HHH 3-0 mnt 1-5, bukan AHH 2-1 mnt 1-6, bukan Lille HAA 1-2 mnt 0-6, bukan HAA 1-2 mnt 2-7, bukan HA 1-1 mnt 6 min_gap=0) atau umum: first<=1 + last>=6 + first&last scorer HOME + diff<=1',
            'data'=>array_values(array_filter($matches, fn($m) =>
                ($m['league']==='15min' && in_array(trim($m['away']), $p61_teams) && $m['h1_last']>=5 && abs($m['sc_h']-$m['sc_a'])<=1 && !(trim($m['home'])==='Girondins de Bordeaux (V)' && trim($m['away'])==='Lille OSC (V)') && !(trim($m['away'])==='AS Monaco (V)' && $m['h1_first']===3 && $m['h1_last']===5 && $m['h1c']===2 && $m['h1s']===['H','A']) && !($m['h1c']===1 && $m['h1_last']===5) && !($m['h1c']===1 && $m['h1_first']===6 && $m['h1_last']===6 && $m['h1s']===['A'] && $m['fh']===0 && $m['fa']===1) && !($m['h1_first']===4 && $m['h1_last']===6 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_first']===3 && $m['h1_last']===5 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_first']===3 && $m['h1_last']===7 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_last']===7 && $m['min_gap']===0 && $m['h1s']===['A','H','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===5 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===1 && $m['h1_last']===5 && $m['h1s']===['H','H','H'] && $m['sc_h']===3 && $m['sc_a']===0) && !($m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','H','H'] && $m['sc_h']===2 && $m['sc_a']===1) && !(trim($m['away'])==='Lille OSC (V)' && $m['h1_first']===0 && $m['h1_last']===6 && $m['h1c']===3 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===2 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===6 && $m['h1_last']===6 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1 && $m['min_gap']===0))
                || ($m['league']==='15min' && $m['h1_first']<=1 && $m['h1_last']>=6 && abs($m['sc_h']-$m['sc_a'])<=1 && count($m['h1s'])>0 && $m['h1s'][0]==='H' && $m['h1s'][count($m['h1s'])-1]==='H')
            )),
        ],
        [
            'id'=>'P62',
            'label'=>'HOME shortlist: Getafe / Osasuna / FC Koln / Lazio / Leicester / Napoli / Sevilla / Udinese (bukan first=1 + last=4 + scorer AAH, bukan Leicester HH 2-0 mnt 0-5, bukan Leicester AA 0-2 mnt 1-5, bukan AA 0-2 mnt 0-5, bukan 15min AA 0-2 mnt 1-5), atau 15min umum: first<=1 + last>=6 + switches>=2 + diff<=2 + last scorer HOME, bukan scorer HHAH, atau 15min first=3 + last=7 + diff=2 + last HOME, atau 15min HH 2-0 mnt 2-4, atau 16min: first<=1 + last>=7 + diff<=2 + last scorer HOME, atau 20min: first<=1 + last>=3 + switches>=2 + HT seri + last scorer HOME, atau 20min HH 2-0 mnt 1-4, kecuali Getafe away dengan scorer AHA, bukan Lazio HH 2-0 mnt 2-4, bukan 15min h1c=5 + HT 3-2 + first=0 + last>=7 + min_gap=0, bukan 15min HAAA 1-3 mnt 0-8, bukan 15min HH 2-0 mnt 2-4 hari Minggu, bukan 16min HH 2-0 mnt 1-7, bukan 16min AHHAH 3-2 mnt 0-8, bukan 20min HAAH 2-2 mnt 0-5',
            'data'=>array_values(array_filter($matches, fn($m) =>
                (($m['league']==='15min' && in_array(trim($m['home']), $p62_teams) && $m['h1_first']<=1 && $m['h1_last']>=4 && ($m['h1_first']===0 || trim($m['home'])!=='FC Koln (V)') && ($m['switches']>=1 || $m['h1c']<=3 || $m['h1_last']>=7) && !($m['h1_first']===1 && $m['h1_last']===4 && $m['h1s']===['A','A','H']) && !(trim($m['home'])==='Leicester City (V)' && $m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','H']) && !(trim($m['home'])==='Leicester City (V)' && $m['h1_first']===1 && $m['h1_last']===5 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A']) && !($m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !($m['h1_first']===1 && $m['h1_last']===5 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2))
                || ($m['league']==='15min' && $m['h1_first']<=1 && $m['h1_last']>=6 && $m['switches']>=2 && abs($m['sc_h']-$m['sc_a'])<=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && !($m['h1s']===['H','A','H','H']) && !($m['h1s']===['H','H','A','H']))
                || ($m['league']==='15min' && $m['h1_first']===3 && $m['h1_last']===7 && abs($m['sc_h']-$m['sc_a'])===2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H')
                || ($m['league']==='15min' && $m['h1_first']===2 && $m['h1_last']===4 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H'])
                || ($m['league']==='16min' && $m['h1_first']<=1 && $m['h1_last']>=7 && abs($m['sc_h']-$m['sc_a'])<=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H')
                || ($m['league']==='20min' && $m['h1_first']<=1 && $m['h1_last']>=3 && $m['switches']>=2 && $m['sc_h']===$m['sc_a'] && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H')
                || ($m['league']==='20min' && $m['h1_first']===1 && $m['h1_last']===4 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H']))
                && !(trim($m['away'])==='Getafe CF (V)' && $m['h1s']===['A','H','A'])
                && !(trim($m['home'])==='Lazio (V)' && $m['league']==='15min' && $m['h1_first']===2 && $m['h1_last']===4 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                && !($m['league']==='15min' && $m['h1c']===5 && $m['h1_first']===0 && $m['h1_last']>=7 && $m['min_gap']===0 && $m['sc_h']===3 && $m['sc_a']===2)
                && !($m['league']==='15min' && $m['h1_first']===0 && $m['h1_last']===8 && $m['h1s']===['H','A','A','A'] && $m['sc_h']===1 && $m['sc_a']===3 && $m['min_gap']===2 && $m['max_run']===3 && ($m['kickoff_dow_num'] ?? -1)===0)
                && !($m['league']==='15min' && $m['h1_first']===2 && $m['h1_last']===4 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0 && ($m['kickoff_dow_num'] ?? -1)===0)
                && !($m['league']==='16min' && $m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                && !($m['league']==='16min' && $m['h1_first']===0 && $m['h1_last']===8 && $m['h1s']===['A','H','H','A','H'] && $m['sc_h']===3 && $m['sc_a']===2)
                && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','A','A','H'] && $m['sc_h']===2 && $m['sc_a']===2)
            )),
        ],
        [
            'id'=>'P72',
            'label'=>'Structural HOME P62 tanpa team: 15min first<=1 + last>=6 + switches>=2 + diff<=2 + last HOME, atau 15min first=3 last=7 diff=2 last HOME, atau 15min HH 2-0 mnt 2-4, atau 16min first<=1 last>=7 diff<=2 last HOME, atau 20min first<=1 last>=3 switches>=2 HT seri last HOME, atau 20min HH 2-0 mnt 1-4; bukan HAHH/HHAH, bukan 15min HT 3-2 first=0 last>=7 min_gap=0, bukan Minggu 15min HH 2-0 mnt 2-4, bukan 16min HH 2-0 mnt 1-7, bukan 16min AHHAH 3-2 mnt 0-8, bukan 20min HAAH 2-2 mnt 0-5',
            'data'=>array_values(array_filter($matches, function($m) {
                $lastScorer = count($m['h1s']) > 0 ? $m['h1s'][count($m['h1s']) - 1] : null;
                $diff = abs($m['sc_h'] - $m['sc_a']);
                $base =
                    ($m['league']==='15min' && $m['h1_first']<=1 && $m['h1_last']>=6 && $m['switches']>=2 && $diff<=2 && $lastScorer==='H' && !($m['h1s']===['H','A','H','H']) && !($m['h1s']===['H','H','A','H']))
                    || ($m['league']==='15min' && $m['h1_first']===3 && $m['h1_last']===7 && $diff===2 && $lastScorer==='H')
                    || ($m['league']==='15min' && $m['h1_first']===2 && $m['h1_last']===4 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H'])
                    || ($m['league']==='16min' && $m['h1_first']<=1 && $m['h1_last']>=7 && $diff<=2 && $lastScorer==='H')
                    || ($m['league']==='20min' && $m['h1_first']<=1 && $m['h1_last']>=3 && $m['switches']>=2 && $m['sc_h']===$m['sc_a'] && $lastScorer==='H')
                    || ($m['league']==='20min' && $m['h1_first']===1 && $m['h1_last']===4 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H']);
                return $base
                    && !($m['league']==='15min' && $m['h1c']===5 && $m['h1_first']===0 && $m['h1_last']>=7 && $m['min_gap']===0 && $m['sc_h']===3 && $m['sc_a']===2)
                    && !($m['league']==='15min' && ($m['kickoff_dow_num'] ?? -1)===0 && $m['h1_first']===2 && $m['h1_last']===4 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                    && !($m['league']==='16min' && $m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
                    && !($m['league']==='16min' && $m['h1_first']===0 && $m['h1_last']===8 && $m['h1s']===['A','H','H','A','H'] && $m['sc_h']===3 && $m['sc_a']===2)
                    && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','A','A','H'] && $m['sc_h']===2 && $m['sc_a']===2);
            })),
        ],
        [
            'id'=>'P73',
            'label'=>'Experimental 2H: gol 1H>=1 + start 15-29 + scorer AH + span>=4, tanpa team block',
            'data'=>array_values(array_filter($matches, fn($m) =>
                ($m['h1c'] ?? 0) >= 1
                && (($m['kickoff_minute'] ?? -1) >= 15 && ($m['kickoff_minute'] ?? -1) <= 29)
                && ($m['h1s'] ?? []) === ['A','H']
                && (($m['h1_last'] ?? -1) - ($m['h1_first'] ?? -1)) >= 4
            )),
        ],
        [
            'id'=>'P74',
            'label'=>'Experimental 2H: gol 1H>=1 + hari Kamis + start 00-14 + last>=8, tanpa team block',
            'data'=>array_values(array_filter($matches, fn($m) =>
                ($m['h1c'] ?? 0) >= 1
                && (($m['kickoff_dow_num'] ?? -1) === 4)
                && (($m['kickoff_minute'] ?? -1) >= 0 && ($m['kickoff_minute'] ?? -1) <= 14)
                && ($m['h1_last'] ?? -1) >= 8
            )),
        ],
        [
            'id'=>'P75',
            'label'=>'Experimental 2H: gol 1H>=1 + jam 16-19 + first>=2 + max_gap>=5, tanpa team block',
            'data'=>array_values(array_filter($matches, fn($m) =>
                ($m['h1c'] ?? 0) >= 1
                && (($m['kickoff_hour'] ?? -1) >= 16 && ($m['kickoff_hour'] ?? -1) <= 19)
                && ($m['h1_first'] ?? -1) >= 2
                && ($m['max_gap'] ?? -1) >= 5
            )),
        ],
        [
            'id'=>'P76',
            'label'=>'Experimental 2H: gol 1H>=1 + jam 19 + max_gap>=3 + diff=1, tanpa team block',
            'data'=>array_values(array_filter($matches, fn($m) =>
                ($m['h1c'] ?? 0) >= 1
                && (($m['kickoff_hour'] ?? -1) === 19)
                && ($m['max_gap'] ?? -1) >= 3
                && abs(($m['sc_h'] ?? 0) - ($m['sc_a'] ?? 0)) === 1
            )),
        ],
        [
            'id'=>'P77',
            'label'=>'Experimental 2H: gol 1H tunggal stabil (h1c=1 + skor 1-0/0-1 + scorer H/A + first minute/jam stabil per league, 28 cabang; contoh 15min H mnt1 jam22, 15min A mnt5 jam15, 20min A mnt9 jam20, 20min H mnt7 jam21), tanpa team block',
            'data'=>array_values(array_filter($matches, fn($m) => matchesP77($m))),
        ],
        [
            'id'=>'P78',
            'label'=>'Experimental 2H: jam 16-19 + first>=2 + (span>=6 atau max_gap>=5), gol 1H>=1, tanpa team block',
            'data'=>array_values(array_filter($matches, fn($m) => matchesP78($m))),
        ],
        ['id'=>'P63', 'label'=>'HOME shortlist: Belgium / Germany / Netherlands / Norway / Ghana / Mexico / Poland / Portugal (16min, first<=1, last>=6, bukan HHA 2-1 mnt 1-6, bukan AHHAH 3-2 mnt 0-8, bukan last AWAY + home lead>=2 + max_run>=3 + max_gap<=3, bukan jam 18 + AAHA 1-3 mnt 1-6 max_gap<=2)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && in_array(trim($m['home']), $p63_teams) && $m['h1_first']<=1 && $m['h1_last']>=6 && !($m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['H','H','A'] && $m['sc_h']===2 && $m['sc_a']===1) && !($m['h1_first']===0 && $m['h1_last']===8 && $m['h1s']===['A','H','H','A','H'] && $m['sc_h']===3 && $m['sc_a']===2) && !(count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && ($m['sc_h']-$m['sc_a'])>=2 && $m['max_run']>=3 && $m['max_gap']<=3) && !(($m['kickoff_hour'] ?? -1)===18 && $m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','A','H','A'] && $m['sc_h']===1 && $m['sc_a']===3 && $m['max_gap']<=2)))],
        ['id'=>'P64', 'label'=>'AWAY shortlist: Liverpool / Napoli / Bayern / FC Koln / FSV Mainz / Lille (15min, first<=1, last>=4, Napoli khusus max_run<=2, kecuali Napoli h1s=[A,A,H], bukan Lille AAA 0-3 mnt 1-6, bukan Lille HAA 1-2 mnt 0-6, bukan AH 1-1 mnt 1-4/1-5, bukan HA 1-1 mnt 1-7, bukan FC Koln HH 2-0 mnt 0-4) atau umum: first=1 + last>=7 + scorer AH, bukan h1c=2 last=7 first=1', 'data'=>array_values(array_filter($matches, fn($m) => (($m['league']==='15min' && in_array(trim($m['away']), $p64_teams) && $m['h1_first']<=1 && $m['h1_last']>=4 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2) && !(trim($m['away'])==='Napoli (V)' && $m['h1s']===['A','A','H']) && !(trim($m['away'])==='Lille OSC (V)' && $m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','A','A']) && !(trim($m['away'])==='Lille OSC (V)' && $m['h1_first']===0 && $m['h1_last']===6 && $m['h1c']===3 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===1 && $m['h1_last']===4 && $m['h1c']===2 && $m['h1s']===['A','H']) && !($m['h1_first']===1 && $m['h1_last']===5 && $m['h1c']===2 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_first']===1 && $m['h1_last']===7 && $m['h1c']===2 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1) && !(trim($m['away'])==='FC Koln (V)' && $m['h1c']===2 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1_first']===0 && $m['h1_last']===4 && $m['h1s']===['H','H'])) || ($m['league']==='15min' && $m['h1_first']===1 && $m['h1_last']>=7 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='H' && !($m['h1c']===2 && $m['h1_last']===7 && $m['h1_first']===1))) && !($m['league']==='15min' && $m['h1_first']===1 && $m['h1_last']===7 && $m['h1c']===2 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1)))],
        [
            'id'=>'P65',
            'label'=>'HOME shortlist: Leicester / Napoli / Udinese / Lyon (15min, gol 1H>=1, first<=1, bukan Leicester HH 2-0 mnt 0-5, bukan Leicester AA 0-2 mnt 1-5, bukan Napoli single AWAY mnt 1, bukan Napoli AA 0-2 mnt 1-5, bukan Lyon single HOME mnt 1, bukan HH 2-0 mnt 1-3, bukan AA 0-2 mnt 1-3, bukan jam 07 single HOME mnt 0)',
            'data'=>array_values(array_filter($matches, fn($m) =>
                matchesP65($m, $p65_teams)
            )),
        ],
        [
            'id'=>'P66',
            'label'=>'AWAY shortlist: Mainz / Getafe / Lille / Liverpool / Lyon / Juventus / Dortmund / Napoli / Bayern / FC Koln / Chelsea (15min, first<=1, last>=5, Napoli max_run<=2, Chelsea first scorer AWAY, bukan AA 0-2 mnt 0-7, bukan h1c=2 scorer AH span>=6, bukan AH 1-1 mnt 1-5, bukan HA 1-1 mnt 1-7, bukan Lille AAA 0-3 mnt 1-6), atau 15min: first=1 + last=4 + h1c=3 + diff=1 + last scorer AWAY, atau 16min: first=0 + last>=6 + diff<=2 + last scorer AWAY, atau 20min: first=0 + last>=7 + switches>=2 + diff<=2 + last scorer AWAY, kecuali Getafe away dengan scorer AHA, bukan 15min h1c=4 + away unggul 2+ + first=1 last=5, bukan 15min h1c=5 + away unggul 3+ + first=1 last=7 + max_run>=3, bukan 20min AHHA 2-2 mnt 0-7 min_gap=0, bukan 20min AHA mnt 0-4-8, bukan 20min HAHA 2-2 mnt 0-9 min_gap=0, bukan 20min AHA 1-2 mnt 0-7, bukan Lille HAA 1-2 mnt 0-6, bukan 20min HAAHA 2-3 mnt 0-9',
            'data'=>array_values(array_filter($matches, function($m) use ($p66_teams) {
                $lastScorer = count($m['h1s']) > 0 ? $m['h1s'][count($m['h1s']) - 1] : null;
                $base = ($m['league']==='15min' && in_array(trim($m['away']), $p66_teams, true) && $m['h1_first']<=1 && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2) && (trim($m['away'])!=='Chelsea (V)' || (count($m['h1s'])>0 && $m['h1s'][0]==='A')) && !($m['h1_first']===0 && $m['h1_last']===7 && $m['h1c']===2 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A']) && !($m['h1c']===2 && $m['h1s']===['A','H'] && ($m['h1_last']-$m['h1_first'])>=6) && !(trim($m['away'])==='Lille OSC (V)' && $m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','A','A']))
                    || ($m['league']==='15min' && $m['h1_first']===1 && $m['h1_last']===4 && $m['h1c']===3 && abs($m['sc_h']-$m['sc_a'])===1 && $lastScorer==='A')
                    || ($m['league']==='16min' && $m['h1_first']===0 && $m['h1_last']>=6 && abs($m['sc_h']-$m['sc_a'])<=2 && $lastScorer==='A')
                    || ($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']>=7 && $m['switches']>=2 && abs($m['sc_h']-$m['sc_a'])<=2 && $lastScorer==='A');
                return $base
                    && !(trim($m['away'])==='Getafe CF (V)' && $m['h1s']===['A','H','A'])
                    && !($m['league']==='15min' && $m['h1c']===4 && $m['h1_first']===1 && $m['h1_last']===5 && ($m['sc_a']-$m['sc_h'])>=2)
                    && !($m['league']==='15min' && $m['h1c']===5 && $m['h1_first']===1 && $m['h1_last']===7 && ($m['sc_a']-$m['sc_h'])>=3 && $m['max_run']>=3)
                    && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===7 && $m['h1c']===4 && $m['sc_h']===2 && $m['sc_a']===2 && $m['min_gap']===0 && $m['h1s']===['A','H','H','A'])
                    && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===8 && $m['h1c']===3 && $m['h1s']===['A','H','A'])
                    && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===9 && $m['h1c']===4 && $m['sc_h']===2 && $m['sc_a']===2 && $m['min_gap']===0 && $m['h1s']===['H','A','H','A'])
                    && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===7 && $m['h1c']===3 && $m['h1s']===['A','H','A'] && $m['sc_h']===1 && $m['sc_a']===2)
                    && !($m['league']==='15min' && $m['h1_first']===1 && $m['h1_last']===5 && $m['h1c']===2 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)
                    && !($m['league']==='15min' && $m['h1_first']===1 && $m['h1_last']===7 && $m['h1c']===2 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1)
                    && !($m['league']==='15min' && trim($m['away'])==='Lille OSC (V)' && $m['h1_first']===0 && $m['h1_last']===6 && $m['h1c']===3 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2)
                    && !($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===9 && $m['h1s']===['H','A','A','H','A'] && $m['sc_h']===2 && $m['sc_a']===3);
            })),
        ],
        [
            'id'=>'P67',
            'label'=>'20min tanpa team block: first<=1 + last>=5 + struktur 1H masuk daftar stabil (scorer sequence + HT score + h1c + first-last + min_gap/max_gap + max_run + switches + hari + jam). Cabang aktif meliputi HH/AA 2-0/0-2, HA/AH 1-1, HAA/AHA/AAH/HHA/HAH 1-2/2-1, HAHA/AHAH/AHHA/AAHH 2-2, HHH/HHHH/AAA/AAAH 3-0/4-0/0-3/1-3; bukan semua shape bebas dan tanpa nama team.',
            'data'=>array_values(array_filter($matches, fn($m) => matchesP67($m))),
        ],
        ['id'=>'P68', 'label'=>'HOME single: Leicester City (15min, gol 1H>=1, first<=1, bukan HH 2-0 mnt 0-5, bukan AA 0-2 mnt 1-5)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && trim($m['home'])==='Leicester City (V)' && $m['h1c']>=1 && $m['h1_first']<=1 && !($m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0) && !($m['h1_first']===1 && $m['h1_last']===5 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2)))],
        ['id'=>'P69', 'label'=>'HOME single: Denmark (20min, first<=1, last>=5, bukan AAH 1-2 mnt 0-5, bukan AAA 0-3 mnt 1-8)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && trim($m['home'])==='Denmark (V)' && $m['h1_first']<=1 && $m['h1_last']>=5 && !($m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['A','A','H'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===1 && $m['h1_last']===8 && $m['h1s']===['A','A','A'] && $m['sc_h']===0 && $m['sc_a']===3)))],
        ['id'=>'P70', 'label'=>'AWAY single: Liverpool (15min, first<=1, last>=5)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && trim($m['away'])==='Liverpool (V)' && $m['h1_first']<=1 && $m['h1_last']>=5))],
        ['id'=>'P71', 'label'=>'AWAY single: Germany (20min, away lead HT, last>=6, bukan AA 0-2 mnt 4-9, bukan AA 0-2 first>=8 last=9)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && trim($m['away'])==='Germany (V)' && $m['sc_a']>$m['sc_h'] && $m['h1_last']>=6 && !($m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2) && !($m['h1_first']>=8 && $m['h1_last']===9 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2)))],
    ];
}

function computeNextPatterns(array $matches): array {
    $ng11_groups = [];
    foreach ($matches as $m) {
        if (($m['h1c'] ?? 0) <= 0) continue;
        $signature = $m['league'] . '|' . $m['h1c'] . '|' . $m['h1_first'] . '|' . $m['h1_last'] . '|' . $m['sc_h'] . '-' . $m['sc_a'] . '|' . $m['switches'] . '|' . $m['min_gap'] . '|' . $m['max_gap'];
        $ng11_groups[$signature][] = $m;
    }
    $ng11_keys = [];
    foreach ($ng11_groups as $signature => $group) {
        if (count($group) < 3) continue;
        $hits = count(array_filter($group, fn($m) => ($m['next_goal'] ?? '') === 'A'));
        if ($hits === count($group)) $ng11_keys[] = $signature;
    }

    return [
        ['id'=>'NG6','label'=>'20min + seri 1-1 + scorer AH + ((last gol 1H mnt 7 + span>=5 + first!=1) atau first=0 + last>=7), kecuali Colombia vs Greece','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && (($m['h1_last']==7 && ($m['h1_last']-$m['h1_first'])>=5 && $m['h1_first']!==1) || ($m['h1_first']===0 && $m['h1_last']>=7)) && !(trim($m['home'])==='Colombia (V)' && trim($m['away'])==='Greece (V)')))],
        ['id'=>'NG7','label'=>'Gol 1H >=3 + max_gap>=5 + selisih HT tepat 2 + last gol 1H mnt 8-9, bukan 20min HAHH 3-1 mnt 1-9','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=3 && $m['max_gap']>=5 && abs($m['sc_h']-$m['sc_a'])===2 && $m['h1_last']>=8 && $m['h1_last']<=9 && !($m['league']==='20min' && $m['h1_first']===1 && $m['h1_last']===9 && $m['h1c']===4 && $m['h1s']===['H','A','H','H'] && $m['sc_h']===3 && $m['sc_a']===1)))],
        ['id'=>'NG8','label'=>'First goal 1H mnt 3 + span>=6 + min_gap>=3, bukan 20min HA 1-1 mnt 3-10','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['h1_first']===3 && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=3 && !($m['league']==='20min' && $m['h1_last']===10 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1)))],
        ['id'=>'NG9','label'=>'20min + away lead HT + last gol 1H mnt 9 + selisih<=1 + switches>=2, kecuali Spain vs Uruguay, bukan AAHAH 2-3 mnt 2-9, bukan HAAHA 2-3 mnt 0-9','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_a']>$m['sc_h'] && $m['h1_last']===9 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['switches']>=2 && !(trim($m['home'])==='Spain (V)' && trim($m['away'])==='Uruguay (V)') && !($m['h1_first']===2 && $m['h1_last']===9 && $m['h1s']===['A','A','H','A','H'] && $m['sc_h']===2 && $m['sc_a']===3) && !($m['h1_first']===0 && $m['h1_last']===9 && $m['h1s']===['H','A','A','H','A'] && $m['sc_h']===2 && $m['sc_a']===3)))],
        ['id'=>'NG10','label'=>'20min + scorer AH + first goal 1H mnt 4 + last gol 1H mnt 9','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']==['A','H']))],
        ['id'=>'NG11','label'=>'Shape stats 1H 3+ sample: league + h1c + first/last + HT score + switches + min_gap + max_gap, target next goal AWAY','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => ($m['h1c'] ?? 0) > 0 && in_array($m['league'] . '|' . $m['h1c'] . '|' . $m['h1_first'] . '|' . $m['h1_last'] . '|' . $m['sc_h'] . '-' . $m['sc_a'] . '|' . $m['switches'] . '|' . $m['min_gap'] . '|' . $m['max_gap'], $ng11_keys, true)))],
    ];
}

function matchesLG8(array $m, array $lg8Teams): bool {
    if (
        ($m['league'] ?? '') !== '20min' ||
        !in_array(trim($m['away'] ?? ''), $lg8Teams, true) ||
        (int)($m['sc_a'] ?? 0) <= (int)($m['sc_h'] ?? 0) ||
        (int)($m['h1_last'] ?? -1) < 6 ||
        (int)($m['h1c'] ?? 0) < 2
    ) {
        return false;
    }

    $seq = implode('', $m['h1s'] ?? []);
    $first = (int)($m['h1_first'] ?? -1);
    $last = (int)($m['h1_last'] ?? -1);
    $minGap = (int)($m['min_gap'] ?? 0);
    $maxGap = (int)($m['max_gap'] ?? 0);

    if (in_array($seq, ['AAH', 'AAHA', 'AHHAA'], true)) {
        return true;
    }

    if ($seq === 'AA') {
        return ($maxGap >= 5 && !($first === 0 && $last === 9))
            || ($last >= 9 && $maxGap <= 3);
    }

    if ($seq === 'AAA' || $seq === 'AAAA') {
        return $first >= 2 && $last >= 8;
    }

    if ($seq === 'AHA') {
        return !($first === 4 && $last === 6 && $minGap === 0)
            && !($first === 1 && $last === 9);
    }

    if ($seq === 'HAA') {
        return !($first === 2 && $last === 6 && $maxGap === 2);
    }

    return false;
}

function computeLatePatterns(array $matches): array {
    $tc = require __DIR__ . '/dashboard_config.php';
    $lg4_teams = $tc['lg4_teams'];
    $lg5_teams = $tc['lg5_teams'];
    $lg6_teams = $tc['lg6_teams'];
    $lg7_teams = $tc['lg7_teams'];
    $lg8_teams = $tc['lg8_teams'];

    $latePatterns = [
        [
            'id' => 'LG1',
            'label' => 'Last gol 1H mnt 9 + first<=1 + AWAY unggul HT 2+ + gol 1H>=3',
            'data' => array_values(array_filter($matches, fn($m) => $m['h1_last'] === 9 && $m['h1_first'] <= 1 && ($m['sc_a'] - $m['sc_h']) >= 2 && $m['h1c'] >= 3)),
        ],
        [
            'id' => 'LG2',
            'label' => 'Last gol 1H mnt 9 + span>=7 + AWAY unggul HT 2+ + first goal<=1 + gol 1H>=3',
            'data' => array_values(array_filter($matches, fn($m) => $m['h1_last'] === 9 && ($m['h1_last'] - $m['h1_first']) >= 7 && ($m['sc_a'] - $m['sc_h']) >= 2 && $m['h1_first'] <= 1 && $m['h1c'] >= 3)),
        ],
        [
            'id' => 'LG3',
            'label' => '16min + gol 1H>=3 + first scorer AWAY + last gol 1H mnt 6 + max_gap!=3',
            'data' => array_values(array_filter($matches, fn($m) => $m['league'] === '16min' && $m['h1c'] >= 3 && count($m['h1s']) > 0 && $m['h1s'][0] === 'A' && $m['h1_last'] === 6 && $m['max_gap'] !== 3)),
        ],
        [
            'id' => 'LG4',
            'label' => 'AWAY shortlist: Norway / Uruguay / Algeria / Nigeria / Romania (20min, away lead HT, last gol 1H 9, bukan h1c=3 first=1, bukan single AWAY mnt 9, bukan HAAA 1-3 mnt 0-9)',
            'data' => array_values(array_filter($matches, fn($m) =>
                $m['league'] === '20min' && in_array(trim($m['away']), $lg4_teams, true) && $m['sc_a'] > $m['sc_h'] && $m['h1_last'] === 9 && !($m['h1c'] === 3 && $m['h1_first'] === 1) && !($m['h1c'] === 1 && $m['h1_first'] === 9) && !($m['h1_first'] === 0 && $m['h1_last'] === 9 && $m['h1s'] === ['H','A','A','A'] && $m['sc_h'] === 1 && $m['sc_a'] === 3)
            )),
        ],
        [
            'id' => 'LG5',
            'label' => 'HOME shortlist: France / Spain / Israel / Morocco (away lead HT tepat 1, last gol 1H >=6, first>=2, bukan AAHHA 2-3 mnt 4-7)',
            'data' => array_values(array_filter($matches, fn($m) =>
                in_array(trim($m['home']), $lg5_teams, true) && ($m['sc_a'] - $m['sc_h']) === 1 && $m['h1_last'] >= 6 && $m['h1_first'] >= 2 && !($m['h1_first'] === 4 && $m['h1_last'] === 7 && $m['h1s'] === ['A','A','H','H','A'] && $m['sc_h'] === 2 && $m['sc_a'] === 3)
            )),
        ],
        [
            'id' => 'LG6',
            'label' => 'AWAY shortlist: Indonesia / Algeria / Slovakia / Slovenia (20min, first<=1, last gol 1H >=8, first=0 atau away unggul HT, bukan h1c=2 scorer AA, bukan AHA mnt 0-4-8, bukan AAAA 0-4 mnt 0-9, bukan HH 2-0 mnt 0-9), atau Argentina (first=0, last>=7, h1c>=3, selisih<=1)',
            'data' => array_values(array_filter($matches, fn($m) =>
                $m['league'] === '20min' && ((in_array(trim($m['away']), $lg6_teams, true) && trim($m['away']) !== 'Argentina (V)' && $m['h1_first'] <= 1 && $m['h1_last'] >= 8 && ($m['h1_first'] === 0 || $m['sc_a'] > $m['sc_h']) && !($m['h1c'] === 2 && $m['h1s'] === ['A', 'A']) && !($m['h1c'] === 3 && $m['h1_first'] === 0 && $m['h1_last'] === 8 && $m['h1s'] === ['A','H','A']) && !($m['h1_first'] === 0 && $m['h1_last'] === 9 && $m['h1s'] === ['A','A','A','A'] && $m['sc_h'] === 0 && $m['sc_a'] === 4) && !($m['h1_first'] === 0 && $m['h1_last'] === 9 && $m['h1s'] === ['H','H'] && $m['sc_h'] === 2 && $m['sc_a'] === 0)) || (trim($m['away']) === 'Argentina (V)' && $m['h1_first'] === 0 && $m['h1_last'] >= 7 && $m['h1c'] >= 3 && abs($m['sc_h'] - $m['sc_a']) <= 1))
            )),
        ],
        [
            'id' => 'LG7',
            'label' => 'AWAY shortlist: Nigeria / Qatar / Slovenia (away lead HT, gol 1H<=3, dan gol 1H>=2 atau last gol 1H>=7, bukan h1c=2 first=0, bukan single AWAY mnt 8, bukan single AWAY mnt 7 jam 20, bukan single goal 2H mnt <7, bukan AA 0-2 mnt 2-9)',
            'data' => array_values(array_filter($matches, fn($m) =>
                in_array(trim($m['away']), $lg7_teams, true) && $m['sc_a'] > $m['sc_h'] && $m['h1_last'] >= 6 && $m['h1c'] <= 3 && ($m['h1c'] >= 2 || $m['h1_last'] >= 7) && !($m['h1c'] === 2 && $m['h1_first'] === 0) && !($m['h1c'] === 1 && $m['h1_first'] === 8 && $m['h1_last'] === 8 && $m['h1s'] === ['A'] && $m['sc_h'] === 0 && $m['sc_a'] === 1) && !($m['h1c'] === 1 && $m['h1_first'] === 7 && $m['h1_last'] === 7 && $m['h1s'] === ['A'] && (int)($m['kickoff_hour'] ?? -1) === 20) && !($m['h2c'] === 1 && $m['h2_first_min'] < 7) && !($m['h1_first'] === 2 && $m['h1_last'] === 9 && $m['h1s'] === ['A','A'] && $m['sc_h'] === 0 && $m['sc_a'] === 2)
            )),
        ],
        [
            'id' => 'LG8',
            'label' => 'AWAY shortlist: Norway / Nigeria / Poland / Slovenia / Romania / Argentina / India / Belgium (20min, away lead HT, last gol 1H>=6, gol 1H>=2; branch AA max_gap>=5 bukan first=0 last=9 atau AA last>=9 max_gap<=3; AAH/AAHA/AHHAA; AAA/AAAA first>=2 last>=8; AHA bukan first=4 last=6 min_gap=0 dan bukan first=1 last=9; HAA bukan first=2 last=6 max_gap=2)',
            'data' => array_values(array_filter($matches, fn($m) => matchesLG8($m, $lg8_teams))),
        ],
    ];

    usort($latePatterns, function($a, $b) {
        $ta = count($a['data']);
        $tb = count($b['data']);
        $targetA = $a['target'] ?? 'has_late';
        $targetB = $b['target'] ?? 'has_late';
        $ha = $ta > 0 ? count(array_filter($a['data'], fn($m) => $m[$targetA] ?? false)) / $ta : 0;
        $hb = $tb > 0 ? count(array_filter($b['data'], fn($m) => $m[$targetB] ?? false)) / $tb : 0;
        if ($hb != $ha) return $hb <=> $ha;
        return $tb <=> $ta;
    });

    return $latePatterns;
}

function matchesNo2hPattern1(array $m): bool {
    $seq = implode('', $m['h1s'] ?? []);
    $h1s = $m['h1s'] ?? [];
    $firstScorer = count($h1s) ? $h1s[0] : null;
    $lastScorer = count($h1s) ? $h1s[count($h1s) - 1] : null;
    $span = ($m['h1_last'] ?? -1) - ($m['h1_first'] ?? -1);
    $diff = abs(($m['sc_h'] ?? 0) - ($m['sc_a'] ?? 0));

    return
        (($m['league'] ?? '') === '15min' && ($m['h1c'] ?? 0) === 4 && ($m['sc_h'] ?? 0) > ($m['sc_a'] ?? 0) && $span <= 5 && ($m['max_run'] ?? 0) === 3)
        || (($m['league'] ?? '') === '15min' && $seq === 'HH' && ($m['h1_first'] ?? -1) === 5 && ($m['h1_last'] ?? -1) <= 6)
        || (($m['league'] ?? '') === '20min' && $seq === 'HH' && ($m['h1_first'] ?? -1) === 0 && ($m['h1_last'] ?? -1) <= 2)
        || (($m['league'] ?? '') === '20min' && ($m['h1c'] ?? 0) === 2 && $lastScorer === 'A' && ($m['h1_first'] ?? -1) === 1 && ($m['h1_last'] ?? -1) === 4)
        || (($m['league'] ?? '') === '15min' && ($m['h1c'] ?? 0) <= 2 && $lastScorer === 'A' && ($m['h1_first'] ?? -1) >= 4 && ($m['h1_last'] ?? -1) === 8)
        || (($m['league'] ?? '') === '20min' && $diff === 1 && $lastScorer === 'H' && ($m['h1_first'] ?? -1) === 0 && ($m['h1_last'] ?? -1) >= 9)
        || (($m['league'] ?? '') === '15min' && ($m['h1c'] ?? 0) >= 4 && ($m['sc_a'] ?? 0) > ($m['sc_h'] ?? 0) && ($m['max_gap'] ?? -1) === 1 && ($m['max_run'] ?? 99) <= 4)
        || (($m['league'] ?? '') === '15min' && ($m['h1c'] ?? 0) === 4 && $firstScorer === 'H' && $lastScorer === 'A' && ($m['h1_last'] ?? -1) === 5 && ($m['max_run'] ?? 99) <= 3);
}

function computeNo2hPatterns(array $matches): array {
    return [
        [
            'id' => 'N2H1',
            'label' => 'No 2H Goal: family reverse dari pola 1H (15/20min, struktur HH/last scorer/gap/run), tanpa team block',
            'target' => 'no_2h_goal',
            'data' => array_values(array_filter($matches, fn($m) => matchesNo2hPattern1($m))),
        ],
    ];
}

function computeSnapshotData(array $patterns, array $nextPatterns, array $latePatterns = [], array $no2hPatterns = []): array {
    $snap = [];
    foreach ($patterns as $p) {
        $t = count($p['data']);
        $h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
        $snap[$p['id']] = ['t' => $t, 'h' => $h, 'sig' => buildSnapshotSignature($p['id'], $p['label'])];
    }
    foreach ($nextPatterns as $ng) {
        $tgt = $ng['next'];
        $t = count($ng['data']);
        $h = count(array_filter($ng['data'], fn($m) => ($tgt==='HOME' ? $m['next_goal']==='H' : $m['next_goal']==='A')));
        $snap[$ng['id']] = ['t' => $t, 'h' => $h, 'sig' => buildSnapshotSignature($ng['id'], $ng['label'], $tgt)];
    }
    foreach ($latePatterns as $lp) {
        $t = count($lp['data']);
        $target = $lp['target'] ?? 'has_late';
        $h = count(array_filter($lp['data'], fn($m) => $m[$target] ?? false));
        $snap[$lp['id']] = ['t' => $t, 'h' => $h, 'sig' => buildSnapshotSignature($lp['id'], $lp['label'], $target)];
    }
    foreach ($no2hPatterns as $np) {
        $t = count($np['data']);
        $h = count(array_filter($np['data'], fn($m) => ($m['h2c'] ?? 0) === 0));
        $snap[$np['id']] = ['t' => $t, 'h' => $h, 'sig' => buildSnapshotSignature($np['id'], $np['label'], 'no_2h_goal')];
    }
    return $snap;
}
