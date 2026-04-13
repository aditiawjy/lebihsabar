<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

$base = array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']==7 && ($m['h1_last']-$m['h1_first'])>=3);
echo "Base P16: " . count($base) . " matches\n\n";

$fail = array_filter($base, fn($m) => $m['h2c'] == 0);
echo "=== FAIL matches (" . count($fail) . ") ===\n";
foreach ($fail as $m) {
    echo "  {$m['home']} vs {$m['away']} | league={$m['league']} | h1s=" . json_encode($m['h1s']) . " | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} sc={$m['sc_h']}-{$m['sc_a']} max_gap={$m['max_gap']} min_gap={$m['min_gap']} sw={$m['switches']} mr={$m['max_run']}\n";
}

echo "\n=== ALL matches ===\n";
foreach ($base as $m) {
    $ok = $m['h2c']>0 ? 'PASS' : 'FAIL';
    echo "  [$ok] {$m['home']} vs {$m['away']} | h1s=" . json_encode($m['h1s']) . " | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} sc={$m['sc_h']}-{$m['sc_a']} max_gap={$m['max_gap']} min_gap={$m['min_gap']} sw={$m['switches']} mr={$m['max_run']}\n";
}

echo "\n=== Testing filters ===\n";
$filters = [
    'switches>=1' => fn($m) => $m['switches']>=1,
    'switches>=2' => fn($m) => $m['switches']>=2,
    'max_run<=2' => fn($m) => $m['max_run']<=2,
    'max_run<=1' => fn($m) => $m['max_run']<=1,
    'selisih<=1' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1,
    'selisih<=2' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=2,
    'selisih<=0' => fn($m) => abs($m['sc_h']-$m['sc_a'])==0,
    'min_gap>=2' => fn($m) => $m['min_gap']>=2,
    'min_gap>=3' => fn($m) => $m['min_gap']>=3,
    'span>=4' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4,
    'span>=5' => fn($m) => ($m['h1_last']-$m['h1_first'])>=5,
    'span>=6' => fn($m) => ($m['h1_last']-$m['h1_first'])>=6,
    'first>=2' => fn($m) => $m['h1_first']>=2,
    'first>=3' => fn($m) => $m['h1_first']>=3,
    'first!=1' => fn($m) => $m['h1_first']!=1,
    'h1c>=2' => fn($m) => $m['h1c']>=2,
    'h1c>=3' => fn($m) => $m['h1c']>=3,
    'max_gap>=2' => fn($m) => $m['max_gap']>=2,
    'max_gap>=3' => fn($m) => $m['max_gap']>=3,
    'sc_h==sc_a' => fn($m) => $m['sc_h']==$m['sc_a'],
    'sc_h>sc_a' => fn($m) => $m['sc_h']>$m['sc_a'],
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
    'switches>=1 + selisih<=1' => fn($m) => $m['switches']>=1 && abs($m['sc_h']-$m['sc_a'])<=1,
    'switches>=1 + min_gap>=2' => fn($m) => $m['switches']>=1 && $m['min_gap']>=2,
    'switches>=1 + first!=1' => fn($m) => $m['switches']>=1 && $m['h1_first']!=1,
    'switches>=1 + span>=4' => fn($m) => $m['switches']>=1 && ($m['h1_last']-$m['h1_first'])>=4,
    'selisih<=1 + min_gap>=2' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1 && $m['min_gap']>=2,
    'selisih<=1 + first!=1' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1 && $m['h1_first']!=1,
    'selisih<=1 + span>=4' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1 && ($m['h1_last']-$m['h1_first'])>=4,
    'min_gap>=2 + span>=4' => fn($m) => $m['min_gap']>=2 && ($m['h1_last']-$m['h1_first'])>=4,
    'min_gap>=2 + first!=1' => fn($m) => $m['min_gap']>=2 && $m['h1_first']!=1,
    'first!=1 + span>=4' => fn($m) => $m['h1_first']!=1 && ($m['h1_last']-$m['h1_first'])>=4,
    'first!=1 + selisih<=2' => fn($m) => $m['h1_first']!=1 && abs($m['sc_h']-$m['sc_a'])<=2,
    'switches>=1 + selisih<=2' => fn($m) => $m['switches']>=1 && abs($m['sc_h']-$m['sc_a'])<=2,
    'h1c>=2 + switches>=1' => fn($m) => $m['h1c']>=2 && $m['switches']>=1,
    'max_gap>=2 + selisih<=1' => fn($m) => $m['max_gap']>=2 && abs($m['sc_h']-$m['sc_a'])<=1,
    'max_gap>=3 + switches>=1' => fn($m) => $m['max_gap']>=3 && $m['switches']>=1,
    'h1c>=2 + selisih<=1' => fn($m) => $m['h1c']>=2 && abs($m['sc_h']-$m['sc_a'])<=1,
    'h1c>=2 + first!=1' => fn($m) => $m['h1c']>=2 && $m['h1_first']!=1,
    'h1c>=2 + min_gap>=2' => fn($m) => $m['h1c']>=2 && $m['min_gap']>=2,
    'max_run<=1 + selisih<=1' => fn($m) => $m['max_run']<=1 && abs($m['sc_h']-$m['sc_a'])<=1,
    'selisih<=1 + span>=5' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1 && ($m['h1_last']-$m['h1_first'])>=5,
];

foreach ($combos as $label => $fn) {
    $filtered = array_filter($base, $fn);
    $t = count($filtered);
    $h = count(array_filter($filtered, fn($m) => $m['h2c']>0));
    $pct = $t > 0 ? round($h/$t*100) : 0;
    echo "  $label: $h/$t = $pct%\n";
}