<?php
date_default_timezone_set('Asia/Jakarta');

$csvFile = __DIR__ . '/goal_log.csv';
$cacheFile = __DIR__ . '/dashboard_cache.json';

function getCachedDashboardData(string $csvFile, string $cacheFile): array {
    $csvExists = file_exists($csvFile) && is_readable($csvFile);
    $cacheExists = file_exists($cacheFile);

    if ($cacheExists) {
        $cacheMtime = filemtime($cacheFile);
        $csvMtime = $csvExists ? filemtime($csvFile) : 0;

        if ($csvExists && $cacheMtime >= $csvMtime) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['generated_at'])) {
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

    if ($csvExists) {
        $fh = fopen($csvFile, 'r');
        if ($fh !== false) {
            fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 7) continue;
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

    return [
        'generated_at' => time(),
        'csv_exists' => $csvExists,
        'csv_time' => $csvExists ? filemtime($csvFile) : null,
        'total_matches' => count($parsedMatches),
        'with_2h' => count(array_filter($parsedMatches, fn($m) => $m['h2c'] > 0)),
        'patterns' => $patterns,
        'next_patterns' => $nextPatterns,
        'all_matches' => $parsedMatches,
    ];
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

function buildDelta(string $id, int $total, int $hits, array $oldSnapData): array {
    $html = '<span style="color:#484f58">—</span>';
    $deltaT = 0;
    $deltaH = 0;
    if ($oldSnapData && isset($oldSnapData[$id])) {
        $old = $oldSnapData[$id];
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

    return [
        ['id'=>'P2',  'label'=>'Selisih 2+ & last mnt 7\' & gap >=3 & max_run<=2, 16min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c'] >= 2 && abs($m['sc_h']-$m['sc_a']) >= 2 && $m['h1_last'] == 7 && $m['all_gaps_ge3'] && $m['max_run'] <= 2))],
        ['id'=>'P3',  'label'=>'AH gap >=4 mnt + lm>=4, 15min/16min', 'data'=>array_values(array_filter($matches, fn($m) => in_array($m['league'],['15min','16min']) && $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && ($m['h1'][1]['min']-$m['h1'][0]['min']) >= 4 && $m['h1_last']>=4))],
        ['id'=>'P6',  'label'=>'Seri 1-1 + gol penyama mnt 7\' + span>=4 + first!=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7 && ($m['h1_last']-$m['h1_first'])>=4 && $m['h1_first']!=1))],
        ['id'=>'P7',  'label'=>'Seri 1-1 + gap >= 5 mnt + first goal != mnt 1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['max_gap']>=5 && $m['h1_first']!==1))],
        ['id'=>'P9',  'label'=>'AH seri 1-1 + gap >= 5 mnt + first goal != mnt 1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && $m['max_gap']>=5 && $m['h1_first']!==1))],
        ['id'=>'P12', 'label'=>'Total gol 1H >= 4 + span >= 6 mnt + min_gap>=1 + lm<=9 + fm>=1 + first!=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c'] >= 4 && ($m['h1_last']-$m['h1_first']) >= 6 && $m['min_gap'] >= 1 && $m['h1_last'] <= 9 && $m['h1_first'] >= 1 && $m['h1_first'] != 1))],
        ['id'=>'P13', 'label'=>'First 0-2\' + last 7\' + selisih <=2 + min_gap>=3 + switches>=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']<=2 && $m['h1_last']===7 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['min_gap']>=3 && $m['switches']>=1))],
        ['id'=>'P14', 'label'=>'Seri + gap >= 4 mnt + span >= 5 mnt + first goal != mnt 1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['sc_h']==$m['sc_a'] && $m['sc_h']>0 && $m['max_gap']>=4 && ($m['h1_last']-$m['h1_first'])>=5 && $m['h1_first']!==1))],
        ['id'=>'P15', 'label'=>'HT 2-2 + max_gap<=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']<=3))],
        ['id'=>'P16', 'label'=>'Last gol 1H mnt 7 + span >=3, 16min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']==7 && ($m['h1_last']-$m['h1_first'])>=3))],
        ['id'=>'P17', 'label'=>'First 1H mnt 1-2 + last mnt 7 + min_gap>=2 + switches>=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']>=1 && $m['h1_first']<=2 && $m['h1_last']==7 && $m['max_gap']>=2 && $m['min_gap']>=2 && $m['switches']>=1))],
        ['id'=>'P18', 'label'=>'Span 1H >= 6 mnt + gol 1H >= 3 + selisih <=2 + sw>=2 + min_gap>=1, fm>=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=3 && ($m['h1_last']-$m['h1_first'])>=6 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['max_run']<=2 && $m['switches']>=2 && $m['min_gap']>=1 && $m['h1_first']>=1))],
        ['id'=>'P19', 'label'=>'Last gol 1H mnt 3-4, last HOME, 20min, gap>=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && in_array($m['h1_last'],[3,4]) && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['h1c']===1 || $m['max_gap']>=2)))],
        ['id'=>'P20', 'label'=>'Last gol 1H mnt 3, last AWAY, 16min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A'))],
        ['id'=>'P21', 'label'=>'Last gol 1H mnt 5, last AWAY, 15min, max_gap>=2 + sw>=1 (n1h>=3 atau AWAY unggul)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1_last']===5 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=2 && ($m['h1c']>=3 || $m['sc_a']>$m['sc_h']) && $m['switches']>=1))],
        ['id'=>'P22', 'label'=>'Away menang HT, 16min, gol>=2, span>=3, balas>=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_a'] > $m['sc_h'] && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=3 && $m['switches']>=1))],
        ['id'=>'P23', 'label'=>'1 gol 1H HOME, mnt pertama >=3, 16min, gol>=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']>=3 && $m['h1_first']>=3 && count($m['h1s'])>0 && $m['h1s'][0]==='H'))],
        ['id'=>'P24', 'label'=>'HOME 15min: Arminia Bielefeld / CA Osasuna / FC Koln / Leicester City / Man United / Dortmund / Liverpool (lm>=4, selisih <=1, HOME cetak >=1, fm>=4)', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && in_array(trim($m['home']), $p24_teams) && $m['h1c']>=1 && $m['h1_last']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['sc_h']>=1 && $m['h1_first']>=4))],
        ['id'=>'P25', 'label'=>'AWAY: Real Sociedad / France / Netherlands / Ukraine (lm>=2, selisih<=1, span>=3)', 'data'=>array_values(array_filter($matches, fn($m) => in_array(trim($m['away']), $p25_teams) && $m['h1_last']>=2 && abs($m['sc_h']-$m['sc_a'])<=1 && ($m['h1_last']-$m['h1_first'])>=3))],
        ['id'=>'P26', 'label'=>'HT total ganjil (1,3,5...), 16min, last mnt >=6, gol 1H >=2', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && ($m['sc_h']+$m['sc_a'])%2===1 && $m['h1_last']>=6 && $m['h1c']>=2))],
        ['id'=>'P27', 'label'=>'Gol terakhir 1H dicetak AWAY, 16min, max_gap>=3, first!=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=3 && $m['h1_first']!=1))],
        ['id'=>'P28', 'label'=>'Croatia atau France main (home atau away) + last mnt >=3 + span >=3', 'data'=>array_values(array_filter($matches, fn($m) => (in_array(trim($m['home']), $p28_teams) || in_array(trim($m['away']), $p28_teams)) && $m['h1_last']>=3 && ($m['h1_last']-$m['h1_first'])>=3))],
        ['id'=>'P29', 'label'=>'Balas >=2x + last mnt >=6 + min_gap>=2 + span>=6 + selisih<=1, gol>=3', 'data'=>array_values(array_filter($matches, fn($m) => $m['switches'] >= 2 && $m['h1_last'] >= 6 && $m['min_gap'] >= 2 && ($m['h1_last']-$m['h1_first']) >= 6 && abs($m['sc_h']-$m['sc_a']) <= 1 && $m['h1c'] >= 3))],
        ['id'=>'P32', 'label'=>'Span 1H >=9 mnt + gol >=2 + selisih <=3 + min_gap>=3 + switches>=1 + first!=1, 20min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=9 && abs($m['sc_h']-$m['sc_a'])<=3 && $m['min_gap']>=3 && $m['switches']>=1 && $m['h1_first']!=1))],
        ['id'=>'P33', 'label'=>'Total gol 1H >=4 + selisih HT <=2 + min_gap>=1, 15min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1c']>=4 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['min_gap']>=1))],
        ['id'=>'P34', 'label'=>'First AWAY + last HOME + span >=6, 15min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='15min' && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['h1_last']-$m['h1_first'])>=6))],
        ['id'=>'P35', 'label'=>'AWAY: Mexico / Belgium / Germany / Man City (last mnt >=4 + selisih HT <=1, fm>=2)', 'data'=>array_values(array_filter($matches, fn($m) => in_array(trim($m['away']), $p35_teams) && $m['h1_last']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['h1_first']>=2))],
        ['id'=>'P36', 'label'=>'HOME: PSG / Germany / Mexico / Belgium (gol>=2, span>=1, selisih<=1)', 'data'=>array_values(array_filter($matches, fn($m) => in_array(trim($m['home']), $p36_teams) && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=1 && abs($m['sc_h']-$m['sc_a'])<=1))],
        ['id'=>'P37', 'label'=>'First & last scorer AWAY + span >=7 mnt (n1h>=3 atau lm<=7) + min_gap>=1', 'data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=2 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='A' && ($m['h1_last']-$m['h1_first'])>=7 && ($m['h1c']>=3 || $m['h1_last']<=7) && $m['min_gap']>=1))],
        ['id'=>'P39', 'label'=>'Gol 1H >=3 + span >=7 mnt + selisih <=3 + fm>=1, 20min', 'data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=3 && ($m['h1_last']-$m['h1_first'])>=7 && abs($m['sc_h']-$m['sc_a'])<=3 && $m['min_gap']>=1 && $m['h1_first']>=1))],
    ];
}

function computeNextPatterns(array $matches): array {
    return [
        ['id'=>'NG1','label'=>'HT 1-0 + gol terakhir mnt 3','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['h1c']>=1 && $m['sc_h']==1 && $m['sc_a']==0 && $m['h1_last']==3))],
        ['id'=>'NG2','label'=>'HOME unggul HT + gol terakhir mnt 3, 16min','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']>=1 && $m['sc_h']>$m['sc_a'] && $m['h1_last']==3))],
        ['id'=>'NG3','label'=>'HT 1-0, league 16min, gol di mnt 3','next'=>'HOME','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_h']==1 && $m['sc_a']==0 && $m['h1_last']==3))],
        ['id'=>'NG5','label'=>'Seri 1-1 + scorer HA + gol terakhir mnt 7','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['sc_h']==1 && $m['sc_a']==1 && $m['h1c']==2 && count($m['h1s'])>=2 && $m['h1s'][0]==='H' && $m['h1s'][count($m['h1s'])-1]==='A' && $m['h1_last']==7))],
        ['id'=>'NG6','label'=>'Seri 1-1 + gol terakhir mnt 7, league 20min','next'=>'AWAY','data'=>array_values(array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7))],
    ];
}

function computeSnapshotData(array $patterns, array $nextPatterns): array {
    $snap = [];
    foreach ($patterns as $p) {
        $t = count($p['data']);
        $h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
        $snap[$p['id']] = ['t' => $t, 'h' => $h];
    }
    foreach ($nextPatterns as $ng) {
        $tgt = $ng['next'];
        $t = count($ng['data']);
        $h = count(array_filter($ng['data'], fn($m) => ($tgt==='HOME' ? $m['next_goal']==='H' : $m['next_goal']==='A')));
        $snap[$ng['id']] = ['t' => $t, 'h' => $h];
    }
    return $snap;
}
