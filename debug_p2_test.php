<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original P2
$filtered = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] == 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 2;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi A: h1c >= 3
$filtered2 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] == 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 2;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI A (h1c>=3): $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi B: switches >= 1
$filtered3 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] == 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 2 &&
           $m['switches'] >= 1;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI B (switches>=1): $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi C: league 16min
$filtered4 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] == 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 2 &&
           $m['league'] === '16min';
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI C (16min): $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi D: min_gap >= 1
$filtered5 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] == 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 2 &&
           $m['min_gap'] >= 1;
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI D (min_gap>=1): $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";

// Opsi E: h1_last >= 7 (7-8)
$filtered6 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] >= 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 2;
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI E (last>=7): $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";

// Opsi F: h1c >= 2 AND max_run <= 1
$filtered7 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) >= 2 && 
           $m['h1_last'] == 7 && 
           $m['all_gaps_ge3'] && 
           $m['max_run'] <= 1;
});
$total7 = count($filtered7);
$hits7 = count(array_filter($filtered7, function($m) { return $m['h2c'] > 0; }));
echo "OPSI F (max_run<=1): $total7 match, $hits7 hit (" . ($total7 > 0 ? round($hits7/$total7*100) : 0) . "%)\n";