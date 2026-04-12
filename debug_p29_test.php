<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Current P29
$f = array_filter($matches, function($m) {
    return $m['switches'] >= 2 && $m['h1_last'] >= 6 && $m['min_gap'] >= 1 && ($m['h1_last']-$m['h1_first']) >= 6 && abs($m['sc_h']-$m['sc_a']) <= 1;
});
$t = count($f); $h = count(array_filter($f, fn($m) => $m['h2c'] > 0));
echo "CURRENT: $t match, $h hit (" . ($t > 0 ? round($h/$t*100) : 0) . "%)\n";

// Best: min_gap >= 2 (92%, 12 match)
// Try combinations

// Opsi G: span >= 7 + min_gap >= 2
$f2 = array_filter($matches, function($m) {
    return $m['switches'] >= 2 && $m['h1_last'] >= 6 && $m['min_gap'] >= 2 && ($m['h1_last']-$m['h1_first']) >= 7 && abs($m['sc_h']-$m['sc_a']) <= 1;
});
$t2 = count($f2); $h2 = count(array_filter($f2, fn($m) => $m['h2c'] > 0));
echo "OPSI G (span>=7+min_gap>=2): $t2 match, $h2 hit (" . ($t2 > 0 ? round($h2/$t2*100) : 0) . "%)\n";

// Opsi H: min_gap >= 2 + last >= 7
$f3 = array_filter($matches, function($m) {
    return $m['switches'] >= 2 && $m['h1_last'] >= 7 && $m['min_gap'] >= 2 && ($m['h1_last']-$m['h1_first']) >= 6 && abs($m['sc_h']-$m['sc_a']) <= 1;
});
$t3 = count($f3); $h3 = count(array_filter($f3, fn($m) => $m['h2c'] > 0));
echo "OPSI H (last>=7+min_gap>=2): $t3 match, $h3 hit (" . ($t3 > 0 ? round($h3/$t3*100) : 0) . "%)\n";

// Opsi I: min_gap >= 2 + switches >= 3
$f4 = array_filter($matches, function($m) {
    return $m['switches'] >= 3 && $m['h1_last'] >= 6 && $m['min_gap'] >= 2 && ($m['h1_last']-$m['h1_first']) >= 6 && abs($m['sc_h']-$m['sc_a']) <= 1;
});
$t4 = count($f4); $h4 = count(array_filter($f4, fn($m) => $m['h2c'] > 0));
echo "OPSI I (switches>=3+min_gap>=2): $t4 match, $h4 hit (" . ($t4 > 0 ? round($h4/$t4*100) : 0) . "%)\n";

// Opsi J: min_gap >= 2 + first >= 1
$f5 = array_filter($matches, function($m) {
    return $m['switches'] >= 2 && $m['h1_last'] >= 6 && $m['min_gap'] >= 2 && ($m['h1_last']-$m['h1_first']) >= 6 && abs($m['sc_h']-$m['sc_a']) <= 1 && $m['h1_first'] >= 1;
});
$t5 = count($f5); $h5 = count(array_filter($f5, fn($m) => $m['h2c'] > 0));
echo "OPSI J (min_gap>=2+first>=1): $t5 match, $h5 hit (" . ($t5 > 0 ? round($h5/$t5*100) : 0) . "%)\n";

// Opsi K: min_gap >= 2 + h1c >= 3
$f6 = array_filter($matches, function($m) {
    return $m['switches'] >= 2 && $m['h1_last'] >= 6 && $m['min_gap'] >= 2 && ($m['h1_last']-$m['h1_first']) >= 6 && abs($m['sc_h']-$m['sc_a']) <= 1 && $m['h1c'] >= 3;
});
$t6 = count($f6); $h6 = count(array_filter($f6, fn($m) => $m['h2c'] > 0));
echo "OPSI K (min_gap>=2+h1c>=3): $t6 match, $h6 hit (" . ($t6 > 0 ? round($h6/$t6*100) : 0) . "%)\n";