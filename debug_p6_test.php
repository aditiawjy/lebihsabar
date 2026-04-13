<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

$base = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7);

echo "=== Deeper combo filters ===\n";
$combos = [
    'span>=4 + switches>=1' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4 && $m['switches']>=1,
    'span>=4 + max_gap>=2' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4 && $m['max_gap']>=2,
    'span>=4 + min_gap>=2' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4 && $m['min_gap']>=2,
    'span>=4 + h1s=AH' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4 && count($m['h1s'])==2 && $m['h1s'][0]==='A' && $m['h1s'][1]==='H',
    'span>=4 + h1s=HA' => fn($m) => ($m['h1_last']-$m['h1_first'])>=4 && count($m['h1s'])==2 && $m['h1s'][0]==='H' && $m['h1s'][1]==='A',
    'h1s=AH + first!=1 + min_gap>=2' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='A' && $m['h1s'][1]==='H' && $m['h1_first']!=1 && $m['min_gap']>=2,
    'h1s=AH + first>=2 + min_gap>=2' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='A' && $m['h1s'][1]==='H' && $m['h1_first']>=2 && $m['min_gap']>=2,
    'h1s=AH + span>=3' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='A' && $m['h1s'][1]==='H' && ($m['h1_last']-$m['h1_first'])>=3,
    'h1s=AH + span>=4' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='A' && $m['h1s'][1]==='H' && ($m['h1_last']-$m['h1_first'])>=4,
    'h1s=HA + first!=1 + min_gap>=2' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='H' && $m['h1s'][1]==='A' && $m['h1_first']!=1 && $m['min_gap']>=2,
    'h1s=HA + span>=3' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='H' && $m['h1s'][1]==='A' && ($m['h1_last']-$m['h1_first'])>=3,
    'h1s=HA + span>=4' => fn($m) => count($m['h1s'])==2 && $m['h1s'][0]==='H' && $m['h1s'][1]==='A' && ($m['h1_last']-$m['h1_first'])>=4,
    'min_gap>=2 + first!=1' => fn($m) => $m['min_gap']>=2 && $m['h1_first']!=1,
    'min_gap>=2 + league!=20min' => fn($m) => $m['min_gap']>=2 && $m['league']!=='20min',
    'min_gap>=3 + first!=1' => fn($m) => $m['min_gap']>=3 && $m['h1_first']!=1,
    'min_gap>=3 + span>=4' => fn($m) => $m['min_gap']>=3 && ($m['h1_last']-$m['h1_first'])>=4,
    'max_gap<=4' => fn($m) => $m['max_gap']<=4,
    'max_gap<=5' => fn($m) => $m['max_gap']<=5,
    'min_gap<=5' => fn($m) => $m['min_gap']<=5,
    'min_gap>=2 + max_gap<=5' => fn($m) => $m['min_gap']>=2 && $m['max_gap']<=5,
    'first!=1 + span>=4' => fn($m) => $m['h1_first']!=1 && ($m['h1_last']-$m['h1_first'])>=4,
    'league=20min + span>=4' => fn($m) => $m['league']==='20min' && ($m['h1_last']-$m['h1_first'])>=4,
    'league=15min + span>=4' => fn($m) => $m['league']==='15min' && ($m['h1_last']-$m['h1_first'])>=4,
    'league=16min + span>=4' => fn($m) => $m['league']==='16min' && ($m['h1_last']-$m['h1_first'])>=4,
    'switches>=1 + span>=4' => fn($m) => $m['switches']>=1 && ($m['h1_last']-$m['h1_first'])>=4,
];

foreach ($combos as $label => $fn) {
    $filtered = array_filter($base, $fn);
    $t = count($filtered);
    $h = count(array_filter($filtered, fn($m) => $m['h2c']>0));
    $pct = $t > 0 ? round($h/$t*100) : 0;
    echo "  $label: $h/$t = $pct%\n";
}

echo "\n=== Check span>=4 detail ===\n";
$span4 = array_filter($base, fn($m) => ($m['h1_last']-$m['h1_first'])>=4);
foreach ($span4 as $m) {
    $ok = $m['h2c']>0 ? 'PASS' : 'FAIL';
    echo "  [$ok] {$m['home']} vs {$m['away']} | league={$m['league']} | h1s=" . json_encode($m['h1s']) . " | first={$m['h1_first']} last={$m['h1_last']} gap={$m['max_gap']}/{$m['min_gap']} sw={$m['switches']}\n";
}