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
            'has_late' => count(array_filter($h2, fn($g) => $g['min'] >= 7)) > 0,
            'next_goal' => computeNextGoal($h2, $sh ? $sh['home'] : 0, $sh ? $sh['away'] : 0),
        ];
    }
    return $matches;
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
    $p67_teams = $tc['p67_teams'];

    return [
        ['id'=>'P2',  'label'=>'Selisih 2+ & last mnt 7\' & gap >=3 & max_run<=2 + fm<=1, 16min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c'] >= 2 && abs($m['sc_h']-$m['sc_a']) >= 2 && $m['h1_last'] == 7 && $m['all_gaps_ge3'] && $m['max_run'] <= 2 && $m['h1_first'] <= 1))],
        [
            'id' => 'P6',
            'label' => 'Seri 1-1 + gol penyama mnt 7\' + span>=5 + first!=1 + home!=Manchester City/Atletico/England + bukan AH saat first=0',
            'data' => array_values(array_filter($matches, fn($m) =>
                $m['h1c'] == 2 &&
                $m['sc_h'] == 1 &&
                $m['sc_a'] == 1 &&
                $m['h1_last'] == 7 &&
                ($m['h1_last'] - $m['h1_first']) >= 5 &&
                $m['h1_first'] != 1 &&
                !in_array(trim($m['home']), ['Manchester City (V)', 'Atletico de Madrid (V)', 'England (V)'], true) &&
                !(
                    $m['h1_first'] === 0 &&
                    count($m['h1s']) === 2 &&
                    $m['h1s'][0] === 'A' &&
                    $m['h1s'][1] === 'H'
                )
            )),
        ],
        ['id'=>'P7',  'label'=>'Seri 1-1 + gap >= 5 mnt + first goal >=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['max_gap']>=5 && $m['h1_first']>=3))],
        ['id'=>'P9',  'label'=>'AH seri 1-1 + gap >= 5 mnt + first goal >=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && $m['max_gap']>=5 && $m['h1_first']>=3))],
        ['id'=>'P12', 'label'=>'Total gol 1H >= 4 + span >= 6 mnt + min_gap>=1 + lm<=9 + fm>=1 + first!=1 + max_run<=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c'] >= 4 && ($m['h1_last']-$m['h1_first']) >= 6 && $m['min_gap'] >= 1 && $m['h1_last'] <= 9 && $m['h1_first'] >= 1 && $m['h1_first'] != 1 && $m['max_run'] <= 2))],
        ['id'=>'P13', 'label'=>'First 2\' + last 7\' + selisih <=2 + min_gap>=3 + switches>=1, kecuali Man City vs Liverpool dan England vs Spain', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']===2 && $m['h1_last']===7 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['min_gap']>=3 && $m['switches']>=1 && !(trim($m['home'])==='Manchester City (V)' && trim($m['away'])==='Liverpool (V)') && !(trim($m['home'])==='England (V)' && trim($m['away'])==='Spain (V)')))],
        ['id'=>'P14', 'label'=>'Seri + gap >= 4 mnt + span >= 5 mnt + first goal >=3 + min_gap>=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['sc_h']==$m['sc_a'] && $m['sc_h']>0 && $m['max_gap']>=4 && ($m['h1_last']-$m['h1_first'])>=5 && $m['h1_first']>=3 && $m['min_gap']>=2))],
        ['id'=>'P15', 'label'=>'HT 2-2 + max_gap<=2 + bukan last=8 atau (first=0 dan last=5), atau 20min + HT 2-2 + max_gap=3 + first=0 + last<=7, atau 16min + HT 2-2 + max_gap=3 + first>=2, atau 15min + HT 2-2 + max_gap=3 + first=1 + last<=6', 'data'=>array_values(array_filter($matches, fn($m) => ($m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']<=2 && $m['h1_last']!=8 && !($m['h1_first']===0 && $m['h1_last']===5)) || ($m['league']==='20min' && $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']===3 && $m['h1_first']===0 && $m['h1_last']<=7) || ($m['league']==='16min' && $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']===3 && $m['h1_first']>=2) || ($m['league']==='15min' && $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']===3 && $m['h1_first']===1 && $m['h1_last']<=6)))],
        ['id'=>'P17', 'label'=>'First 1H mnt 1-2 + last mnt 7 + min_gap>=2 + switches>=1 + first scorer AWAY + (15min first=1, atau 20min first=2 / away unggul HT)', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']>=1 && $m['h1_first']<=2 && $m['h1_last']==7 && $m['max_gap']>=2 && $m['min_gap']>=2 && $m['switches']>=1 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && (($m['league']==='15min' && $m['h1_first']===1) || ($m['league']==='20min' && ($m['h1_first']===2 || $m['sc_a']>$m['sc_h'])))))],
        ['id'=>'P19', 'label'=>'20min + last scorer HOME + first<=1 + switches>=1, lalu: last=3 (first=0 atau gol 1H>=3) atau last=4 (AWAY unggul HT / switches>=2)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && $m['h1c']>=2 && ((($m['h1_last']===3) && ($m['h1_first']===0 || $m['h1c']>=3)) || ($m['h1_last']===4 && ($m['sc_a']>$m['sc_h'] || $m['switches']>=2))) && $m['h1_first']<=1 && $m['switches']>=1))],
        ['id'=>'P53', 'label'=>'20min + last gol 1H mnt 3 + last scorer HOME + min_gap>=1 + gol 1H<=2 + (first=0 atau away HT=0), atau AH 1-1 mnt 1-3 kecuali Paraguay vs Bosnia-Herzegovina, atau 20min + last=4 + last scorer AWAY + min_gap>=1 + gol 1H<=2 + (first=0 atau away HT=0), kecuali Greece vs Ukraine', 'data'=>array_values(array_filter($matches, fn($m) => (($m['league']==='20min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && $m['min_gap']>=1 && $m['h1c']<=2 && ($m['h1_first']===0 || $m['sc_a']===0)) || ($m['league']==='20min' && $m['h1_first']===1 && $m['h1_last']===3 && $m['h1c']===2 && $m['sc_h']===1 && $m['sc_a']===1 && $m['h1s']===['A','H'] && !(trim($m['home'])==='Paraguay (V)' && trim($m['away'])==='Bosnia-Herzegovina (V)')) || ($m['league']==='20min' && $m['h1_last']===4 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['min_gap']>=1 && $m['h1c']<=2 && ($m['h1_first']===0 || $m['sc_a']===0))) && !(trim($m['home'])==='Greece (V)' && trim($m['away'])==='Ukraine (V)')))],
        ['id'=>'P20', 'label'=>'Last gol 1H mnt 3, last AWAY, 16min + (first goal<=1 atau gol 1H>=2)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && ($m['h1_first']<=1 || $m['h1c']>=2)))],
        ['id'=>'P21', 'label'=>'Last gol 1H mnt 5, last AWAY, 15min, max_gap>=2 + min_gap>=1 + sw>=1 + max_run<=2 + first>=1 (n1h>=3 atau AWAY unggul)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1_last']===5 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=2 && $m['min_gap']>=1 && ($m['h1c']>=3 || $m['sc_a']>$m['sc_h']) && $m['switches']>=1 && $m['max_run']<=2 && $m['h1_first']>=1))],
        ['id'=>'P24', 'label'=>'HOME shortlist: Arminia / Osasuna / Arsenal / Leicester / Dortmund / Liverpool / Monaco / Marseille / Atalanta / Spurs / Everton (15min, lm>=4, selisih<=1, HOME cetak>=1, fm>=4, first scorer HOME, Everton khusus lm<=5, Arminia bukan single goal mnt 5, bukan single goal mnt 5, kecuali Marseille vs Liverpool)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && in_array(trim($m['home']), $p24_teams) && $m['h1c']>=1 && $m['h1_last']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['sc_h']>=1 && $m['h1_first']>=4 && count($m['h1s'])>0 && $m['h1s'][0]==='H' && (trim($m['home'])!=='Everton (V)' || $m['h1_last']<=5) && (trim($m['home'])!=='Arminia Bielefeld (V)' || !($m['h1c']===1 && $m['h1_first']===5 && $m['h1_last']===5)) && !($m['h1c']===1 && $m['h1_last']===5) && !(trim($m['home'])==='Olympique de Marseille (V)' && trim($m['away'])==='Liverpool (V)')))],
        ['id'=>'P25', 'label'=>'AWAY: Real Sociedad / France / Netherlands / Ukraine (lm>=2, selisih<=1, span>=3, min_gap>=2, last scorer AWAY, bukan h1c=2 span=3 first=4)', 'data'=>array_values(array_filter($matches, fn($m) => in_array(trim($m['away']), $p25_teams) && $m['h1_last']>=2 && abs($m['sc_h']-$m['sc_a'])<=1 && ($m['h1_last']-$m['h1_first'])>=3 && $m['min_gap']>=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && !($m['h1c']===2 && ($m['h1_last']-$m['h1_first'])===3 && $m['h1_first']===4)))],
        ['id'=>'P26', 'label'=>'HT total ganjil (1,3,5...), 16min, last mnt >=6, gol 1H >=2, away unggul + max_run<=2 + (first!=1 atau max_gap>=4)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && ($m['sc_h']+$m['sc_a'])%2===1 && $m['h1_last']>=6 && $m['h1c']>=2 && $m['sc_a']>$m['sc_h'] && $m['max_run']<=2 && ($m['h1_first']!=1 || $m['max_gap']>=4)))],
        ['id'=>'P27', 'label'=>'Gol terakhir 1H dicetak AWAY, 16min, max_gap>=3, first!=1, span>=6 + (switches>=1 atau max_gap>=6)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=3 && $m['h1_first']!=1 && ($m['h1_last']-$m['h1_first'])>=6 && ($m['switches']>=1 || $m['max_gap']>=6)))],
        ['id'=>'P28', 'label'=>'Croatia atau France main + last mnt >=3 + span >=3 + switches>=1 + (target team away atau first>=2)', 'data'=>array_values(array_filter($matches, fn($m) => (in_array(trim($m['home']), $p28_teams) || in_array(trim($m['away']), $p28_teams)) && $m['h1_last']>=3 && ($m['h1_last']-$m['h1_first'])>=3 && $m['switches']>=1 && (in_array(trim($m['away']), $p28_teams) || $m['h1_first']>=2)))],
        ['id'=>'P32', 'label'=>'Span 1H >=9 mnt + gol >=2 + HT seri + min_gap>=3 + switches>=1 + first!=1, 20min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=9 && $m['sc_h']===$m['sc_a'] && $m['min_gap']>=3 && $m['switches']>=1 && $m['h1_first']!=1))],
        ['id'=>'P54', 'label'=>'20min + AWAY unggul HT + last gol 1H mnt 9 + span>=4 + first>=2 + h1c<=4, kecuali Argentina vs Ukraine', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_a']>$m['sc_h'] && $m['h1_last']===9 && ($m['h1_last']-$m['h1_first'])>=4 && $m['h1_first']>=2 && $m['h1c']<=4 && !(trim($m['home'])==='Argentina (V)' && trim($m['away'])==='Ukraine (V)')))],
        ['id'=>'P33', 'label'=>'Total gol 1H >=4 + selisih HT <=1 + min_gap>=1 + last gol 1H>=6 + (switches>=2 atau first goal>=1), 15min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1c']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['min_gap']>=1 && $m['h1_last']>=6 && ($m['switches']>=2 || $m['h1_first']>=1)))],
        ['id'=>'P34', 'label'=>'First AWAY + last HOME + span >=6 + gol 1H >=4, 15min, kecuali AS Roma vs Udinese', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['h1_last']-$m['h1_first'])>=6 && $m['h1c']>=4 && !(trim($m['home'])==='AS Roma (V)' && trim($m['away'])==='Udinese (V)')))],
        ['id'=>'P35', 'label'=>'AWAY shortlist: Mexico / Belgium / Germany / AS Monaco / Wales / Portugal / Osasuna / Austria / Poland / Croatia / Algeria + gol 1H>=2 + first>=3 + max_run<=2 + last gol 1H>=5 + min_gap>=2 + bukan HH mnt 3&5 + bukan Monaco HA 1-1 mnt 3-5, atau 16min shortlist first=1 + h1c>=3, atau shortlist first=0 + h1c>=3 + last>=6 + last scorer AWAY', 'data'=>array_values(array_filter($matches, fn($m) => (in_array(trim($m['away']), $p35_teams) && $m['h1c']>=2 && $m['h1_first']>=3 && $m['max_run']<=2 && $m['h1_last']>=5 && $m['min_gap']>=2 && !(count($m['h1s'])===2 && $m['h1s'][0]==='H' && $m['h1s'][1]==='H' && $m['h1_first']===3 && $m['h1_last']===5) && !(trim($m['away'])==='AS Monaco (V)' && $m['h1_first']===3 && $m['h1_last']===5 && $m['h1c']===2 && $m['sc_h']===1 && $m['sc_a']===1 && $m['h1s']===['H','A'])) || ($m['league']==='16min' && in_array(trim($m['away']), $p35_teams) && $m['h1c']>=3 && $m['h1_first']===1 && $m['max_run']<=2 && $m['h1_last']>=5 && $m['min_gap']>=2) || (in_array(trim($m['away']), $p35_teams) && $m['h1c']>=3 && $m['h1_first']===0 && $m['max_run']<=2 && $m['h1_last']>=6 && $m['min_gap']>=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A')))],
        ['id'=>'P37', 'label'=>'16min + first & last scorer AWAY + first<=1 + gol 1H>=2 + tanpa balas HOME + last gol 1H<=4', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']>=2 && $m['h1_first']<=1 && $m['h1_last']<=4 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='A' && $m['switches']===0))],
        ['id'=>'P39', 'label'=>'Gol 1H >=3 + span >=7 mnt + selisih <=3 + fm>=2 + min_gap>=3, 20min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=3 && ($m['h1_last']-$m['h1_first'])>=7 && abs($m['sc_h']-$m['sc_a'])<=3 && $m['min_gap']>=3 && $m['h1_first']>=2))],
        ['id'=>'P40', 'label'=>'16min + selisih HT tepat 2 + first goal <=1 + span>=5 + (min_gap>=1 atau max_gap>=4)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && abs($m['sc_h']-$m['sc_a'])===2 && $m['h1_first']<=1 && ($m['h1_last']-$m['h1_first'])>=5 && ($m['min_gap']>=1 || $m['max_gap']>=4)))],
        ['id'=>'P41', 'label'=>'Selisih HT >=2 + first goal >=2 + span >=6 + max_gap>=5, kecuali h1c=2 + max_gap>=7 + away unggul HT', 'data'=>array_values(array_filter($matches, fn($m) => abs($m['sc_h']-$m['sc_a'])>=2 && $m['h1_first']>=2 && ($m['h1_last']-$m['h1_first'])>=6 && $m['max_gap']>=5 && !($m['h1c']===2 && $m['max_gap']>=7 && $m['sc_a']>$m['sc_h'])))],
        ['id'=>'P42', 'label'=>'20min + first goal >=2 + span >=6 + min_gap>=3, bukan scorer HA, kecuali max_gap>=7 + h1c=2 + away unggul HT', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1_first']>=2 && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=3 && implode('',$m['h1s'])!=='HA' && !($m['max_gap']>=7 && $m['h1c']===2 && $m['sc_a']>$m['sc_h'])))],
        ['id'=>'P43', 'label'=>'Away unggul HT + span >=6 + last scorer HOME + diff==1 + gol 1H<=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['sc_a']>$m['sc_h'] && ($m['h1_last']-$m['h1_first'])>=6 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['sc_a']-$m['sc_h'])===1 && $m['h1c']<=3))],
        ['id'=>'P44', 'label'=>'Selisih HT >=2 + first goal >=2 + switches>=1 + max_run<=2 + (20min atau last>=8 atau HT 3-1)', 'data'=>array_values(array_filter($matches, fn($m) => abs($m['sc_h']-$m['sc_a'])>=2 && $m['h1_first']>=2 && $m['switches']>=1 && $m['max_run']<=2 && ($m['league']==='20min' || $m['h1_last']>=8 || ($m['sc_h']===3 && $m['sc_a']===1))))],
        ['id'=>'P45', 'label'=>'16min + first goal 0\' + span >=6 + gap>=6 + max_run<=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_first']===0 && ($m['h1_last']-$m['h1_first'])>=6 && $m['max_gap']>=6 && $m['max_run']<=2))],
        ['id'=>'P46', 'label'=>'16min + span >=6 + min_gap>=2 + h1c==2 + (first=0 atau switches=0)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=2 && $m['h1c']===2 && ($m['h1_first']===0 || $m['switches']===0)))],
        ['id'=>'P47', 'label'=>'HT seri + first goal !=1 + switches>=2 + last gol 1H>=6 + min_gap>=1 + max_gap>=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['sc_h']===$m['sc_a'] && $m['h1_first']!=1 && $m['switches']>=2 && $m['h1_last']>=6 && $m['min_gap']>=1 && $m['max_gap']>=3))],
        ['id'=>'P48', 'label'=>'HT seri + span >=7 + switches>=2 + (first goal 0 / span tepat 7 / last scorer HOME)', 'data'=>array_values(array_filter($matches, fn($m) => $m['sc_h']===$m['sc_a'] && ($m['h1_last']-$m['h1_first'])>=7 && $m['switches']>=2 && ($m['h1_first']===0 || ($m['h1_last']-$m['h1_first'])===7 || (count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H'))))],
        ['id'=>'P49', 'label'=>'16min + selisih HT >=2 + span >=6 + first>=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && abs($m['sc_h']-$m['sc_a'])>=2 && ($m['h1_last']-$m['h1_first'])>=6 && $m['h1_first']>=1))],
        ['id'=>'P50', 'label'=>'16min + away unggul HT + span >=6 + max_run<=2 + (first=0 atau max_gap>=6)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_a']>$m['sc_h'] && ($m['h1_last']-$m['h1_first'])>=6 && $m['max_run']<=2 && ($m['h1_first']===0 || $m['max_gap']>=6)))],
        ['id'=>'P51', 'label'=>'16min + switches>=2 + first!=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['switches']>=2 && $m['h1_first']!=1))],
        ['id'=>'P52', 'label'=>'16min + span >=6 + min_gap>=3 + selisih HT>=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=3 && abs($m['sc_h']-$m['sc_a'])>=2))],
        ['id'=>'P55', 'label'=>'16min + last gol 1H mnt 8 + AWAY unggul HT', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===8 && $m['sc_a']>$m['sc_h']))],
        ['id'=>'P56', 'label'=>'16min + max_gap>=6 + last scorer AWAY', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['max_gap']>=6 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A'))],
        ['id'=>'P57', 'label'=>'First goal 1H mnt 0 + last gol 1H mnt 6 + first scorer AWAY + (15min atau 16min atau gol 1H>=3) + (switches>=2 atau max_gap>=4) + bukan scorer AHH', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1_first']===0 && $m['h1_last']===6 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && ($m['league']==='15min' || $m['league']==='16min' || $m['h1c']>=3) && ($m['switches']>=2 || $m['max_gap']>=4) && $m['h1s']!==['A','H','H']))],
        ['id'=>'P58', 'label'=>'First goal 1H >=3 + span>=5 + min_gap>=3 + bukan first=4 + span=5 + scorer HH + switches=0, atau 20min + first=2 + span>=5 + min_gap>=3 + (last scorer HOME atau gol 1H>=3), atau 16min + first=1 + span>=5 + min_gap>=3 + max_gap>=4, atau 16min + first=0 + span>=5 + min_gap>=3 + selisih HT>=2', 'data'=>array_values(array_filter($matches, fn($m) => ($m['h1_first']>=3 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && !($m['h1_first']===4 && ($m['h1_last']-$m['h1_first'])===5 && $m['h1s']===['H','H'] && $m['switches']===0)) || ($m['league']==='20min' && $m['h1_first']===2 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && ((count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H') || $m['h1c']>=3)) || ($m['league']==='16min' && $m['h1_first']===1 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && $m['max_gap']>=4) || ($m['league']==='16min' && $m['h1_first']===0 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && abs($m['sc_h']-$m['sc_a'])>=2)))],
        [
            'id' => 'P59',
            'label' => 'Last gol 1H mnt 9 + switches>=2 + last scorer AWAY + (first scorer AWAY atau max_gap>=6) + bukan first=1/h1c=3/scorer AHA + bukan h1c=5/first=2',
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
                !($m['h1c'] === 5 && $m['h1_first'] === 2)
            )),
        ],
        ['id'=>'P60', 'label'=>'20min + first goal 1H mnt 3 + HT seri + last gol 1H>=5 + bukan scorer HA saat last=7', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1_first']===3 && $m['sc_h']===$m['sc_a'] && $m['h1_last']>=5 && !($m['h1_last']===7 && $m['h1s']===['H','A'])))],
        ['id'=>'P61', 'label'=>'AWAY shortlist: Chelsea / Lille / Juventus / Monaco (15min, last>=5, diff<=1, kecuali Bordeaux vs Lille dan Monaco HA 1-1 mnt 3-5, bukan single goal mnt 5) atau umum: first<=1 + last>=6 + first&last scorer HOME + diff<=1', 'data'=>array_values(array_filter($matches, fn($m) => ($m['league']==='15min' && in_array(trim($m['away']), $p61_teams) && $m['h1_last']>=5 && abs($m['sc_h']-$m['sc_a'])<=1 && !(trim($m['home'])==='Girondins de Bordeaux (V)' && trim($m['away'])==='Lille OSC (V)') && !(trim($m['away'])==='AS Monaco (V)' && $m['h1_first']===3 && $m['h1_last']===5 && $m['h1c']===2 && $m['h1s']===['H','A']) && !($m['h1c']===1 && $m['h1_last']===5)) || ($m['league']==='15min' && $m['h1_first']<=1 && $m['h1_last']>=6 && abs($m['sc_h']-$m['sc_a'])<=1 && count($m['h1s'])>0 && $m['h1s'][0]==='H' && $m['h1s'][count($m['h1s'])-1]==='H')))],
        ['id'=>'P62', 'label'=>'HOME shortlist: Getafe / Osasuna / FC Koln / Lazio / Leicester / Napoli / Sevilla / Udinese, atau 15min umum: first<=1 + last>=6 + switches>=2 + diff<=2 + last scorer HOME, atau 16min: first<=1 + last>=7 + diff<=2 + last scorer HOME, atau 20min: first<=1 + last>=3 + switches>=2 + HT seri + last scorer HOME, kecuali Getafe away dengan scorer AHA', 'data'=>array_values(array_filter($matches, fn($m) => (($m['league']==='15min' && in_array(trim($m['home']), $p62_teams) && $m['h1_first']<=1 && $m['h1_last']>=4 && ($m['h1_first']===0 || trim($m['home'])!=='FC Koln (V)') && ($m['switches']>=1 || $m['h1c']<=3 || $m['h1_last']>=7)) || ($m['league']==='15min' && $m['h1_first']<=1 && $m['h1_last']>=6 && $m['switches']>=2 && abs($m['sc_h']-$m['sc_a'])<=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H') || ($m['league']==='16min' && $m['h1_first']<=1 && $m['h1_last']>=7 && abs($m['sc_h']-$m['sc_a'])<=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H') || ($m['league']==='20min' && $m['h1_first']<=1 && $m['h1_last']>=3 && $m['switches']>=2 && $m['sc_h']===$m['sc_a'] && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H')) && !(trim($m['away'])==='Getafe CF (V)' && $m['h1s']===['A','H','A'])))],
        ['id'=>'P63', 'label'=>'HOME shortlist: Belgium / Germany / Netherlands / Norway / Ghana / Mexico / Poland / Portugal (16min, first<=1, last>=6)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && in_array(trim($m['home']), $p63_teams) && $m['h1_first']<=1 && $m['h1_last']>=6))],
        ['id'=>'P64', 'label'=>'AWAY shortlist: Liverpool / Napoli / Bayern / FC Koln / FSV Mainz / Lille (15min, first<=1, last>=4, Napoli khusus max_run<=2) atau umum: first=1 + last>=7 + scorer AH, bukan h1c=2 last=7 first=1', 'data'=>array_values(array_filter($matches, fn($m) => ($m['league']==='15min' && in_array(trim($m['away']), $p64_teams) && $m['h1_first']<=1 && $m['h1_last']>=4 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2)) || ($m['league']==='15min' && $m['h1_first']===1 && $m['h1_last']>=7 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='H' && !($m['h1c']===2 && $m['h1_last']===7 && $m['h1_first']===1))))],
        ['id'=>'P65', 'label'=>'HOME shortlist: Leicester / Napoli / Udinese / Lyon (15min, gol 1H>=1, first<=1)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && in_array(trim($m['home']), $p65_teams) && $m['h1c']>=1 && $m['h1_first']<=1))],
        ['id'=>'P66', 'label'=>'AWAY shortlist: Mainz / Getafe / Lille / Liverpool / Lyon / Juventus / Dortmund / Napoli / Bayern / FC Koln / Chelsea (15min, first<=1, last>=5, Napoli max_run<=2, Chelsea first scorer AWAY, bukan AA 0-2 mnt 0-7, bukan h1c=2 scorer AH span>=6), atau 16min: first=0 + last>=6 + diff<=2 + last scorer AWAY, atau 20min: first=0 + last>=7 + switches>=2 + diff<=2 + last scorer AWAY, kecuali Getafe away dengan scorer AHA', 'data'=>array_values(array_filter($matches, fn($m) => (($m['league']==='15min' && in_array(trim($m['away']), $p66_teams) && $m['h1_first']<=1 && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2) && (trim($m['away'])!=='Chelsea (V)' || (count($m['h1s'])>0 && $m['h1s'][0]==='A')) && !($m['h1_first']===0 && $m['h1_last']===7 && $m['h1c']===2 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A']) && !($m['h1c']===2 && $m['h1s']===['A','H'] && ($m['h1_last']-$m['h1_first'])>=6)) || ($m['league']==='16min' && $m['h1_first']===0 && $m['h1_last']>=6 && abs($m['sc_h']-$m['sc_a'])<=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A') || ($m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']>=7 && $m['switches']>=2 && abs($m['sc_h']-$m['sc_a'])<=2 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A')) && !(trim($m['away'])==='Getafe CF (V)' && $m['h1s']===['A','H','A'])))],
        ['id'=>'P67', 'label'=>'HOME shortlist: Argentina / Denmark / Germany / Russia / Korea Republic / Croatia / Brazil / Nigeria / Serbia (20min, first<=1, last>=5)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && in_array(trim($m['home']), $p67_teams) && $m['h1_first']<=1 && $m['h1_last']>=5))],
        ['id'=>'P68', 'label'=>'HOME single: Leicester City (15min, gol 1H>=1, first<=1)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && trim($m['home'])==='Leicester City (V)' && $m['h1c']>=1 && $m['h1_first']<=1))],
        ['id'=>'P69', 'label'=>'HOME single: Denmark (20min, first<=1, last>=5)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && trim($m['home'])==='Denmark (V)' && $m['h1_first']<=1 && $m['h1_last']>=5))],
        ['id'=>'P70', 'label'=>'AWAY single: Liverpool (15min, first<=1, last>=5)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && trim($m['away'])==='Liverpool (V)' && $m['h1_first']<=1 && $m['h1_last']>=5))],
        ['id'=>'P71', 'label'=>'AWAY single: Germany (20min, away lead HT, last>=6)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && trim($m['away'])==='Germany (V)' && $m['sc_a']>$m['sc_h'] && $m['h1_last']>=6))],
    ];
}

function computeNextPatterns(array $matches): array {
    return [
        ['id'=>'NG6','label'=>'20min + seri 1-1 + scorer AH + last gol 1H mnt 7 + span>=5 + first!=1, kecuali Colombia vs Greece','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7 && ($m['h1_last']-$m['h1_first'])>=5 && $m['h1s']==['A','H'] && $m['h1_first']!==1 && !(trim($m['home'])==='Colombia (V)' && trim($m['away'])==='Greece (V)')))],
        ['id'=>'NG7','label'=>'Gol 1H >=3 + max_gap>=5 + selisih HT tepat 2 + last gol 1H mnt 8-9','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=3 && $m['max_gap']>=5 && abs($m['sc_h']-$m['sc_a'])===2 && $m['h1_last']>=8 && $m['h1_last']<=9))],
        ['id'=>'NG8','label'=>'First goal 1H mnt 3 + span>=6 + min_gap>=3','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['h1_first']===3 && ($m['h1_last']-$m['h1_first'])>=6 && $m['min_gap']>=3))],
        ['id'=>'NG9','label'=>'20min + away lead HT + last gol 1H mnt 9 + selisih<=1 + switches>=2, kecuali Spain vs Uruguay','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_a']>$m['sc_h'] && $m['h1_last']===9 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['switches']>=2 && !(trim($m['home'])==='Spain (V)' && trim($m['away'])==='Uruguay (V)')))],
        ['id'=>'NG10','label'=>'20min + scorer AH + first goal 1H mnt 4 + last gol 1H mnt 9','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']==['A','H']))],
    ];
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
            'label' => 'AWAY shortlist: Norway / Uruguay / Algeria / Nigeria / Romania (20min, away lead HT, last gol 1H 9, bukan h1c=3 first=1)',
            'data' => array_values(array_filter($matches, fn($m) => $m['league'] === '20min' && in_array(trim($m['away']), $lg4_teams, true) && $m['sc_a'] > $m['sc_h'] && $m['h1_last'] === 9 && !($m['h1c'] === 3 && $m['h1_first'] === 1))),
        ],
        [
            'id' => 'LG5',
            'label' => 'HOME shortlist: France / Spain / Israel / Morocco (away lead HT tepat 1, last gol 1H >=6, first>=2)',
            'data' => array_values(array_filter($matches, fn($m) => in_array(trim($m['home']), $lg5_teams, true) && ($m['sc_a'] - $m['sc_h']) === 1 && $m['h1_last'] >= 6 && $m['h1_first'] >= 2)),
        ],
        [
            'id' => 'LG6',
            'label' => 'AWAY shortlist: Indonesia / Algeria / Slovakia / Slovenia (20min, first<=1, last gol 1H >=8, first=0 atau away unggul HT, bukan h1c=2 scorer AA)',
            'data' => array_values(array_filter($matches, fn($m) => $m['league'] === '20min' && in_array(trim($m['away']), $lg6_teams, true) && $m['h1_first'] <= 1 && $m['h1_last'] >= 8 && ($m['h1_first'] === 0 || $m['sc_a'] > $m['sc_h']) && !($m['h1c'] === 2 && $m['h1s'] === ['A', 'A']))),
        ],
        [
            'id' => 'LG7',
            'label' => 'AWAY shortlist: Nigeria / Qatar / Slovenia (away lead HT, gol 1H<=3, dan gol 1H>=2 atau last gol 1H>=7, bukan h1c=2 first=0)',
            'data' => array_values(array_filter($matches, fn($m) => in_array(trim($m['away']), $lg7_teams, true) && $m['sc_a'] > $m['sc_h'] && $m['h1_last'] >= 6 && $m['h1c'] <= 3 && ($m['h1c'] >= 2 || $m['h1_last'] >= 7) && !($m['h1c'] === 2 && $m['h1_first'] === 0))),
        ],
        [
            'id' => 'LG8',
            'label' => 'AWAY shortlist: Norway / Nigeria / Poland / Slovenia (20min, away lead HT, last gol 1H >=6, gol 1H>=2, bukan h1c=2 first=0)',
            'data' => array_values(array_filter($matches, fn($m) => $m['league'] === '20min' && in_array(trim($m['away']), $lg8_teams, true) && $m['sc_a'] > $m['sc_h'] && $m['h1_last'] >= 6 && $m['h1c'] >= 2 && !($m['h1c'] === 2 && $m['h1_first'] === 0))),
        ],
    ];

    usort($latePatterns, function($a, $b) {
        $ta = count($a['data']);
        $tb = count($b['data']);
        $ha = $ta > 0 ? count(array_filter($a['data'], fn($m) => $m['has_late'])) / $ta : 0;
        $hb = $tb > 0 ? count(array_filter($b['data'], fn($m) => $m['has_late'])) / $tb : 0;
        if ($hb != $ha) return $hb <=> $ha;
        return $tb <=> $ta;
    });

    return $latePatterns;
}

function computeSnapshotData(array $patterns, array $nextPatterns, array $latePatterns = []): array {
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
        $h = count(array_filter($lp['data'], fn($m) => $m['has_late']));
        $snap[$lp['id']] = ['t' => $t, 'h' => $h, 'sig' => buildSnapshotSignature($lp['id'], $lp['label'])];
    }
    return $snap;
}
