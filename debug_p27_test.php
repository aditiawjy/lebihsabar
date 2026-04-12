<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original P27
$filtered = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][count($m['h1s']) - 1] === 'A' && 
           $m['max_gap'] >= 3;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi: h1_first >= 1 (first goal minute)
$filtered2 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][count($m['h1s']) - 1] === 'A' && 
           $m['max_gap'] >= 3 &&
           $m['h1_first'] >= 1;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI first>=1: $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi: h1_first >= 2
$filtered3 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][count($m['h1s']) - 1] === 'A' && 
           $m['max_gap'] >= 3 &&
           $m['h1_first'] >= 2;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI first>=2: $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi: h1_first != 1 (bukan menit 1)
$filtered4 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][count($m['h1s']) - 1] === 'A' && 
           $m['max_gap'] >= 3 &&
           $m['h1_first'] != 1;
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI first!=1: $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi: last goal minute >= 4
$filtered5 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][count($m['h1s']) - 1] === 'A' && 
           $m['max_gap'] >= 3 &&
           $m['h1_last'] >= 4;
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI last>=4: $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";

// Opsi: h1c >= 1 (only 1 goal)
$filtered6 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][count($m['h1s']) - 1] === 'A' && 
           $m['max_gap'] >= 3 &&
           $m['h1c'] == 1;
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI h1c==1: $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";