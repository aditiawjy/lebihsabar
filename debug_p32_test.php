<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

$base = array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=9 && abs($m['sc_h']-$m['sc_a'])<=3 && $m['min_gap']>=3);
echo "Base P32: " . count($base) . " matches\n\n";

$fail = array_filter($base, fn($m) => $m['h2c'] == 0);
echo "=== FAIL matches ===\n";
foreach ($fail as $m) {
    echo "  {$m['home']} vs {$m['away']} | h1s=" . json_encode($m['h1s']) . " | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} sc={$m['sc_h']}-{$m['sc_a']} max_gap={$m['max_gap']} min_gap={$m['min_gap']} sw={$m['switches']} mr={$m['max_run']}\n";
}

echo "\n=== Testing filters ===\n";
$filters = [
    'switches>=1' => fn($m) => $m['switches']>=1,
    'switches>=2' => fn($m) => $m['switches']>=2,
    'max_run<=2' => fn($m) => $m['max_run']<=2,
    'max_run<=1' => fn($m) => $m['max_run']<=1,
    'selisih<=1' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1,
    'selisih<=2' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=2,
    'h1c==2' => fn($m) => $m['h1c']==2,
    'h1c>=3' => fn($m) => $m['h1c']>=3,
    'first>=2' => fn($m) => $m['h1_first']>=2,
    'first!=1' => fn($m) => $m['h1_first']!=1,
    'span>=10' => fn($m) => ($m['h1_last']-$m['h1_first'])>=10,
    'min_gap>=4' => fn($m) => $m['min_gap']>=4,
    'max_gap>=4' => fn($m) => $m['max_gap']>=4,
    'sc_h==sc_a' => fn($m) => $m['sc_h']==$m['sc_a'],
    'sc_h>sc_a' => fn($m) => $m['sc_h']>$m['sc_a'],
    'sc_a>sc_h' => fn($m) => $m['sc_a']>$m['sc_h'],
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
    'switches>=1 + selisih<=2' => fn($m) => $m['switches']>=1 && abs($m['sc_h']-$m['sc_a'])<=2,
    'switches>=1 + selisih<=1' => fn($m) => $m['switches']>=1 && abs($m['sc_h']-$m['sc_a'])<=1,
    'switches>=1 + h1c==2' => fn($m) => $m['switches']>=1 && $m['h1c']==2,
    'switches>=1 + first!=1' => fn($m) => $m['switches']>=1 && $m['h1_first']!=1,
    'selisih<=2 + h1c==2' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=2 && $m['h1c']==2,
    'selisih<=1 + h1c==2' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1 && $m['h1c']==2,
    'max_run<=2 + selisih<=2' => fn($m) => $m['max_run']<=2 && abs($m['sc_h']-$m['sc_a'])<=2,
    'max_run<=1 + selisih<=2' => fn($m) => $m['max_run']<=1 && abs($m['sc_h']-$m['sc_a'])<=2,
    'first!=1 + switches>=1' => fn($m) => $m['h1_first']!=1 && $m['switches']>=1,
    'first>=2 + switches>=1' => fn($m) => $m['h1_first']>=2 && $m['switches']>=1,
    'switches>=1 + min_gap>=4' => fn($m) => $m['switches']>=1 && $m['min_gap']>=4,
    'selisih<=1 + first!=1' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=1 && $m['h1_first']!=1,
    'selisih<=2 + first!=1' => fn($m) => abs($m['sc_h']-$m['sc_a'])<=2 && $m['h1_first']!=1,
    'span>=10 + switches>=1' => fn($m) => ($m['h1_last']-$m['h1_first'])>=10 && $m['switches']>=1,
    'span>=10 + selisih<=2' => fn($m) => ($m['h1_last']-$m['h1_first'])>=10 && abs($m['sc_h']-$m['sc_a'])<=2,
];

foreach ($combos as $label => $fn) {
    $filtered = array_filter($base, $fn);
    $t = count($filtered);
    $h = count(array_filter($filtered, fn($m) => $m['h2c']>0));
    $pct = $t > 0 ? round($h/$t*100) : 0;
    echo "  $label: $h/$t = $pct%\n";
}