<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Current P13
$f = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['h1_first'] <= 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 2 && $m['min_gap'] >= 2 && $m['switches'] >= 1;
});
$t = count($f); $h = count(array_filter($f, fn($m) => $m['h2c'] > 0));
echo "CURRENT: $t match, $h hit (" . ($t > 0 ? round($h/$t*100) : 0) . "%)\n";

// Opsi A: min_gap >= 3
$f2 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['h1_first'] <= 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 2 && $m['min_gap'] >= 3 && $m['switches'] >= 1;
});
$t2 = count($f2); $h2 = count(array_filter($f2, fn($m) => $m['h2c'] > 0));
echo "OPSI A (min_gap>=3): $t2 match, $h2 hit (" . ($t2 > 0 ? round($h2/$t2*100) : 0) . "%)\n";

// Opsi B: selisih <= 1
$f3 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['h1_first'] <= 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 1 && $m['min_gap'] >= 2 && $m['switches'] >= 1;
});
$t3 = count($f3); $h3 = count(array_filter($f3, fn($m) => $m['h2c'] > 0));
echo "OPSI B (selisih<=1): $t3 match, $h3 hit (" . ($t3 > 0 ? round($h3/$t3*100) : 0) . "%)\n";

// Opsi C: switches >= 2
$f4 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['h1_first'] <= 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 2 && $m['min_gap'] >= 2 && $m['switches'] >= 2;
});
$t4 = count($f4); $h4 = count(array_filter($f4, fn($m) => $m['h2c'] > 0));
echo "OPSI C (switches>=2): $t4 match, $h4 hit (" . ($t4 > 0 ? round($h4/$t4*100) : 0) . "%)\n";

// Opsi D: h1c >= 3
$f5 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && $m['h1_first'] <= 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 2 && $m['min_gap'] >= 2 && $m['switches'] >= 1;
});
$t5 = count($f5); $h5 = count(array_filter($f5, fn($m) => $m['h2c'] > 0));
echo "OPSI D (h1c>=3): $t5 match, $h5 hit (" . ($t5 > 0 ? round($h5/$t5*100) : 0) . "%)\n";

// Opsi E: first >= 1
$f6 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['h1_first'] >= 1 && $m['h1_first'] <= 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 2 && $m['min_gap'] >= 2 && $m['switches'] >= 1;
});
$t6 = count($f6); $h6 = count(array_filter($f6, fn($m) => $m['h2c'] > 0));
echo "OPSI E (first>=1): $t6 match, $h6 hit (" . ($t6 > 0 ? round($h6/$t6*100) : 0) . "%)\n";

// Opsi F: first = 2
$f7 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['h1_first'] === 2 && $m['h1_last'] === 7 && abs($m['sc_h'] - $m['sc_a']) <= 2 && $m['min_gap'] >= 2 && $m['switches'] >= 1;
});
$t7 = count($f7); $h7 = count(array_filter($f7, fn($m) => $m['h2c'] > 0));
echo "OPSI F (first=2): $t7 match, $h7 hit (" . ($t7 > 0 ? round($h7/$t7*100) : 0) . "%)\n";