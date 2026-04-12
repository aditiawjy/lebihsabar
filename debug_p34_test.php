<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Opsi A winner: span >= 6 (91%, 11 match)
// Opsi E winner: selisih <= 1 (89%, 19 match)
// Try combinations

// Opsi G: span >= 6 + min_gap >= 1
$f1 = array_filter($matches, function($m) {
    return $m['league'] === '15min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'A' && 
           $m['h1s'][count($m['h1s']) - 1] === 'H' && 
           ($m['h1_last'] - $m['h1_first']) >= 6 &&
           $m['min_gap'] >= 1;
});
$t1 = count($f1); $h1 = count(array_filter($f1, fn($m) => $m['h2c'] > 0));
echo "OPSI G (span>=6+min_gap>=1): $t1 match, $h1 hit (" . ($t1 > 0 ? round($h1/$t1*100) : 0) . "%)\n";

// Opsi H: span >= 6 + selisih <= 1
$f2 = array_filter($matches, function($m) {
    return $m['league'] === '15min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'A' && 
           $m['h1s'][count($m['h1s']) - 1] === 'H' && 
           ($m['h1_last'] - $m['h1_first']) >= 6 &&
           abs($m['sc_h'] - $m['sc_a']) <= 1;
});
$t2 = count($f2); $h2 = count(array_filter($f2, fn($m) => $m['h2c'] > 0));
echo "OPSI H (span>=6+selisih<=1): $t2 match, $h2 hit (" . ($t2 > 0 ? round($h2/$t2*100) : 0) . "%)\n";

// Opsi I: span >= 6 + h1_first >= 2
$f3 = array_filter($matches, function($m) {
    return $m['league'] === '15min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'A' && 
           $m['h1s'][count($m['h1s']) - 1] === 'H' && 
           ($m['h1_last'] - $m['h1_first']) >= 6 &&
           $m['h1_first'] >= 2;
});
$t3 = count($f3); $h3 = count(array_filter($f3, fn($m) => $m['h2c'] > 0));
echo "OPSI I (span>=6+first>=2): $t3 match, $h3 hit (" . ($t3 > 0 ? round($h3/$t3*100) : 0) . "%)\n";

// Opsi J: selisih <= 1 + min_gap >= 1
$f4 = array_filter($matches, function($m) {
    return $m['league'] === '15min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'A' && 
           $m['h1s'][count($m['h1s']) - 1] === 'H' && 
           ($m['h1_last'] - $m['h1_first']) >= 5 &&
           abs($m['sc_h'] - $m['sc_a']) <= 1 &&
           $m['min_gap'] >= 1;
});
$t4 = count($f4); $h4 = count(array_filter($f4, fn($m) => $m['h2c'] > 0));
echo "OPSI J (selisih<=1+min_gap>=1): $t4 match, $h4 hit (" . ($t4 > 0 ? round($h4/$t4*100) : 0) . "%)\n";

// Opsi K: span >= 7
$f5 = array_filter($matches, function($m) {
    return $m['league'] === '15min' && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'A' && 
           $m['h1s'][count($m['h1s']) - 1] === 'H' && 
           ($m['h1_last'] - $m['h1_first']) >= 7;
});
$t5 = count($f5); $h5 = count(array_filter($f5, fn($m) => $m['h2c'] > 0));
echo "OPSI K (span>=7): $t5 match, $h5 hit (" . ($t5 > 0 ? round($h5/$t5*100) : 0) . "%)\n";