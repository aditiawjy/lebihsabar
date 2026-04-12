<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original P18
$filtered = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && 
           ($m['h1_last'] - $m['h1_first']) >= 6 && 
           abs($m['sc_h'] - $m['sc_a']) <= 2 && 
           $m['max_run'] <= 2 && 
           $m['switches'] >= 2 && 
           $m['min_gap'] >= 1;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi F: h1_first >= 1
$filtered2 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && 
           ($m['h1_last'] - $m['h1_first']) >= 6 && 
           abs($m['sc_h'] - $m['sc_a']) <= 2 && 
           $m['max_run'] <= 2 && 
           $m['switches'] >= 2 && 
           $m['min_gap'] >= 1 &&
           $m['h1_first'] >= 1;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI F (first>=1): $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi G: h1_first >= 2
$filtered3 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && 
           ($m['h1_last'] - $m['h1_first']) >= 6 && 
           abs($m['sc_h'] - $m['sc_a']) <= 2 && 
           $m['max_run'] <= 2 && 
           $m['switches'] >= 2 && 
           $m['min_gap'] >= 1 &&
           $m['h1_first'] >= 2;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI G (first>=2): $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi H: h1_last >= 6
$filtered4 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && 
           ($m['h1_last'] - $m['h1_first']) >= 6 && 
           abs($m['sc_h'] - $m['sc_a']) <= 2 && 
           $m['max_run'] <= 2 && 
           $m['switches'] >= 2 && 
           $m['min_gap'] >= 1 &&
           $m['h1_last'] >= 6;
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI H (last>=6): $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi I: without max_run filter
$filtered5 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && 
           ($m['h1_last'] - $m['h1_first']) >= 6 && 
           abs($m['sc_h'] - $m['sc_a']) <= 2 && 
           $m['switches'] >= 2 && 
           $m['min_gap'] >= 1;
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI I (no max_run): $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";