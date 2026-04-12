<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = getTeamConfig();
$p35_teams = $tc['p35_teams'];

// Opsi F v2: seri + last >= 4
$f8 = array_filter($matches, function($m) use($p35_teams) {
    return in_array(trim($m['away']), $p35_teams) && 
           $m['h1_last'] >= 4 && 
           $m['sc_h'] === $m['sc_a'];
});
$t8 = count($f8); $h8 = count(array_filter($f8, fn($m) => $m['h2c'] > 0));
echo "OPSI G (seri+last>=4): $t8 match, $h8 hit (" . ($t8 > 0 ? round($h8/$t8*100) : 0) . "%)\n";

// Opsi H: seri + last >= 4 + h1c >= 2
$f9 = array_filter($matches, function($m) use($p35_teams) {
    return in_array(trim($m['away']), $p35_teams) && 
           $m['h1_last'] >= 4 && 
           $m['sc_h'] === $m['sc_a'] &&
           $m['h1c'] >= 2;
});
$t9 = count($f9); $h9 = count(array_filter($f9, fn($m) => $m['h2c'] > 0));
echo "OPSI H (seri+h1c>=2): $t9 match, $h9 hit (" . ($t9 > 0 ? round($h9/$t9*100) : 0) . "%)\n";

// Opsi I: last >= 4 + selisih <= 1 + first >= 2
$f10 = array_filter($matches, function($m) use($p35_teams) {
    return in_array(trim($m['away']), $p35_teams) && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['h1_first'] >= 2;
});
$t10 = count($f10); $h10 = count(array_filter($f10, fn($m) => $m['h2c'] > 0));
echo "OPSI I (first>=2): $t10 match, $h10 hit (" . ($t10 > 0 ? round($h10/$t10*100) : 0) . "%)\n";

// Opsi J: last >= 4 + selisih <= 1 + first != 1 + switches >= 1
$f11 = array_filter($matches, function($m) use($p35_teams) {
    return in_array(trim($m['away']), $p35_teams) && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['h1_first'] != 1 &&
           $m['switches'] >= 1;
});
$t11 = count($f11); $h11 = count(array_filter($f11, fn($m) => $m['h2c'] > 0));
echo "OPSI J (switches>=1+first!=1): $t11 match, $h11 hit (" . ($t11 > 0 ? round($h11/$t11*100) : 0) . "%)\n";

// Opsi K: seri + first != 1 + h1c >= 2
$f12 = array_filter($matches, function($m) use($p35_teams) {
    return in_array(trim($m['away']), $p35_teams) && 
           $m['h1_last'] >= 4 && 
           $m['sc_h'] === $m['sc_a'] && 
           $m['h1_first'] != 1 &&
           $m['h1c'] >= 2;
});
$t12 = count($f12); $h12 = count(array_filter($f12, fn($m) => $m['h2c'] > 0));
echo "OPSI K (seri+first!=1+h1c>=2): $t12 match, $h12 hit (" . ($t12 > 0 ? round($h12/$t12*100) : 0) . "%)\n";