<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Current
echo "CURRENT: 25 match, 22 hit (88%)\n";

// Opsi D: switches >= 1 (92%, 13 match) - best!
// Try combinations with switches >= 1

// Opsi G: switches >= 1 + min_gap >= 1
$f = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_a'] > $m['sc_h'] && ($m['h1c'] >= 2 || ($m['sc_a'] - $m['sc_h']) >= 2) && ($m['h1_last'] - $m['h1_first']) >= 3 && $m['switches'] >= 1 && $m['min_gap'] >= 1;
});
$t = count($f); $h = count(array_filter($f, fn($m) => $m['h2c'] > 0));
echo "OPSI G (switches>=1+min_gap>=1): $t match, $h hit (" . ($t > 0 ? round($h/$t*100) : 0) . "%)\n";

// Opsi H: switches >= 1 + h1c >= 2
$f2 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_a'] > $m['sc_h'] && $m['h1c'] >= 2 && ($m['h1_last'] - $m['h1_first']) >= 3 && $m['switches'] >= 1;
});
$t2 = count($f2); $h2 = count(array_filter($f2, fn($m) => $m['h2c'] > 0));
echo "OPSI H (switches>=1+h1c>=2): $t2 match, $h2 hit (" . ($t2 > 0 ? round($h2/$t2*100) : 0) . "%)\n";

// Opsi I: unggul >= 2 (strict)
$f3 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && ($m['sc_a'] - $m['sc_h']) >= 2 && ($m['h1_last'] - $m['h1_first']) >= 3;
});
$t3 = count($f3); $h3 = count(array_filter($f3, fn($m) => $m['h2c'] > 0));
echo "OPSI I (unggul>=2): $t3 match, $h3 hit (" . ($t3 > 0 ? round($h3/$t3*100) : 0) . "%)\n";

// Opsi J: switches >= 1 + selisih <= 1
$f4 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_a'] > $m['sc_h'] && ($m['h1c'] >= 2 || ($m['sc_a'] - $m['sc_h']) >= 2) && ($m['h1_last'] - $m['h1_first']) >= 3 && $m['switches'] >= 1 && ($m['sc_a'] - $m['sc_h']) <= 1;
});
$t4 = count($f4); $h4 = count(array_filter($f4, fn($m) => $m['h2c'] > 0));
echo "OPSI J (switches>=1+selisih<=1): $t4 match, $h4 hit (" . ($t4 > 0 ? round($h4/$t4*100) : 0) . "%)\n";

// Opsi K: selisih <= 1 + h1c >= 2
$f5 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_a'] > $m['sc_h'] && $m['h1c'] >= 2 && ($m['h1_last'] - $m['h1_first']) >= 3 && ($m['sc_a'] - $m['sc_h']) <= 1;
});
$t5 = count($f5); $h5 = count(array_filter($f5, fn($m) => $m['h2c'] > 0));
echo "OPSI K (h1c>=2+selisih<=1): $t5 match, $h5 hit (" . ($t5 > 0 ? round($h5/$t5*100) : 0) . "%)\n";