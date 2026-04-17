<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = getTeamConfig();
$p35_teams = $tc['p35_teams'];

$base = array_filter($matches, fn($m) => in_array(trim($m['away']), $p35_teams) && $m['h1_last']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['h1_first']>=2);
echo "Base P35: " . count($base) . " matches\n\n";

$fail = array_filter($base, fn($m) => $m['h2c'] == 0);
echo "=== FAIL matches (" . count($fail) . ") ===\n";
foreach ($fail as $m) {
    echo "  {$m['home']} vs {$m['away']} | league={$m['league']} | h1s=" . json_encode($m['h1s']) . " | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} sc={$m['sc_h']}-{$m['sc_a']} max_gap={$m['max_gap']} min_gap={$m['min_gap']} sw={$m['switches']} mr={$m['max_run']}\n";
}

echo "\n=== Testing filters ===\n";
$filters = [
    'switches>=1' => fn($m) => $m['switches']>=1,
    'switches>=2' => fn($m) => $m['switches']>=2,
    'max_run<=2' => fn($m) => $m['max_run']<=2,
    'max_run<=1' => fn($m) => $m['max_run']<=1,
    'selisih<=0' => fn($m) => abs($m['sc_h']-$m['sc_a'])==0,
    'min_gap>=2' => fn($m) => $m['min_gap']>=2,
    'min_gap>=3' => fn($m) => $m['min_gap']>=3,
    'span>=2' => fn($m) => ($m['h1_last']-$m['h1_first'])>=2,
    'span>=3' => fn($m) => ($m['h1_last']-$m['h1_first'])>=3,
    'span>=4' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4,
    'first>=3' => fn($m) => $m['h1_first']>=3,
    'first>=4' => fn($m) => $m['h1_first']>=4,
    'last>=5' => fn($m) => $m['h1_last']>=5,
    'last>=6' => fn($m) => $m['h1_last']>=6,
    'last>=7' => fn($m) => $m['h1_last']>=7,
    'max_gap>=2' => fn($m) => $m['max_gap']>=2,
    'max_gap>=3' => fn($m) => $m['max_gap']>=3,
    'sc_h==sc_a' => fn($m) => $m['sc_h']==$m['sc_a'],
    'sc_a>sc_h' => fn($m) => $m['sc_a']>$m['sc_h'],
    'h1c>=3' => fn($m) => $m['h1c']>=3,
    'h1c==2' => fn($m) => $m['h1c']==2,
    'league=16min' => fn($m) => $m['league']==='16min',
    'league=15min' => fn($m) => $m['league']==='15min',
    'league=20min' => fn($m) => $m['league']==='20min',
];

foreach ($filters as $label => $fn) {
    $filtered = array_filter($base, $fn);
    $t = count($filtered);
    $h = count(array_filter($filtered, fn($m) => $m['h2c']>0));
    $pct = $t > 0 ? round($h/$t*100) : 0;
    echo "  $label: $h/$t = $pct%\n";
}

echo "\n=== Combo filters ===\n";
$combos = [
    'switches>=1 + min_gap>=2' => fn($m) => $m['switches']>=1 && $m['min_gap']>=2,
    'switches>=1 + span>=2' => fn($m) => $m['switches']>=1 && ($m['h1_last']-$m['h1_first'])>=2,
    'switches>=1 + last>=5' => fn($m) => $m['switches']>=1 && $m['h1_last']>=5,
    'min_gap>=2 + span>=2' => fn($m) => $m['min_gap']>=2 && ($m['h1_last']-$m['h1_first'])>=2,
    'min_gap>=2 + last>=5' => fn($m) => $m['min_gap']>=2 && $m['h1_last']>=5,
    'min_gap>=2 + first>=3' => fn($m) => $m['min_gap']>=2 && $m['h1_first']>=3,
    'span>=2 + first>=3' => fn($m) => ($m['h1_last']-$m['h1_first'])>=2 && $m['h1_first']>=3,
    'span>=3 + last>=5' => fn($m) => ($m['h1_last']-$m['h1_first'])>=3 && $m['h1_last']>=5,
    'first>=3 + selisih<=0' => fn($m) => $m['h1_first']>=3 && abs($m['sc_h']-$m['sc_a'])==0,
    'first>=3 + last>=5' => fn($m) => $m['h1_first']>=3 && $m['h1_last']>=5,
    'max_run<=2 + min_gap>=2' => fn($m) => $m['max_run']<=2 && $m['min_gap']>=2,
    'max_run<=2 + switches>=1' => fn($m) => $m['max_run']<=2 && $m['switches']>=1,
    'last>=5 + min_gap>=2' => fn($m) => $m['h1_last']>=5 && $m['min_gap']>=2,
    'sc_h==sc_a + switches>=1' => fn($m) => $m['sc_h']==$m['sc_a'] && $m['switches']>=1,
    'selisih<=0 + min_gap>=2' => fn($m) => abs($m['sc_h']-$m['sc_a'])==0 && $m['min_gap']>=2,
    'switches>=1 + first>=3' => fn($m) => $m['switches']>=1 && $m['h1_first']>=3,
    'h1c>=3 + switches>=1' => fn($m) => $m['h1c']>=3 && $m['switches']>=1,
    'h1c>=3 + min_gap>=2' => fn($m) => $m['h1c']>=3 && $m['min_gap']>=2,
    'last>=5 + span>=2' => fn($m) => $m['h1_last']>=5 && ($m['h1_last']-$m['h1_first'])>=2,
    'min_gap>=3 + last>=5' => fn($m) => $m['min_gap']>=3 && $m['h1_last']>=5,
];

foreach ($combos as $label => $fn) {
    $filtered = array_filter($base, $fn);
    $t = count($filtered);
    $h = count(array_filter($filtered, fn($m) => $m['h2c']>0));
    $pct = $t > 0 ? round($h/$t*100) : 0;
    echo "  $label: $h/$t = $pct%\n";
}
