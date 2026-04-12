<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = getTeamConfig();
$p24_teams = $tc['p24_teams'];

// Original P24
$filtered = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] >= 1 && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 1;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi G: h1_first >= 3
$filtered7 = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] >= 1 && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 3;
});
$total7 = count($filtered7);
$hits7 = count(array_filter($filtered7, function($m) { return $m['h2c'] > 0; }));
echo "OPSI G (first>=3): $total7 match, $hits7 hit (" . ($total7 > 0 ? round($hits7/$total7*100) : 0) . "%)\n";

// Opsi H: h1_last >= 6
$filtered8 = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] >= 1 && 
           $m['h1_last'] >= 6 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 1;
});
$total8 = count($filtered8);
$hits8 = count(array_filter($filtered8, function($m) { return $m['h2c'] > 0; }));
echo "OPSI H (last>=6): $total8 match, $hits8 hit (" . ($total8 > 0 ? round($hits8/$total8*100) : 0) . "%)\n";

// Opsi I: h1_first >= 4
$filtered9 = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] >= 1 && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 4;
});
$total9 = count($filtered9);
$hits9 = count(array_filter($filtered9, function($m) { return $m['h2c'] > 0; }));
echo "OPSI I (first>=4): $total9 match, $hits9 hit (" . ($total9 > 0 ? round($hits9/$total9*100) : 0) . "%)\n";

// Opsi J: sc_h > sc_a (HOME lead)
$filtered10 = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] >= 1 && 
           $m['h1_last'] >= 4 && 
           $m['sc_h'] > $m['sc_a'] && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 1;
});
$total10 = count($filtered10);
$hits10 = count(array_filter($filtered10, function($m) { return $m['h2c'] > 0; }));
echo "OPSI J (HOME lead): $total10 match, $hits10 hit (" . ($total10 > 0 ? round($hits10/$total10*100) : 0) . "%)\n";

// Opsi K: h1c === 1 (only 1 goal)
$filtered11 = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] === 1 && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 1;
});
$total11 = count($filtered11);
$hits11 = count(array_filter($filtered11, function($m) { return $m['h2c'] > 0; }));
echo "OPSI K (h1c===1): $total11 match, $hits11 hit (" . ($total11 > 0 ? round($hits11/$total11*100) : 0) . "%)\n";

// Opsi L: min_gap >= 1
$filtered12 = array_filter($matches, function($m) {
    global $p24_teams;
    return $m['league'] === '15min' && 
           in_array(trim($m['home']), $p24_teams) &&
           $m['h1c'] >= 1 && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['sc_h'] >= 1 && 
           $m['h1_first'] >= 1 &&
           $m['min_gap'] >= 1;
});
$total12 = count($filtered12);
$hits12 = count(array_filter($filtered12, function($m) { return $m['h2c'] > 0; }));
echo "OPSI L (min_gap>=1): $total12 match, $hits12 hit (" . ($total12 > 0 ? round($hits12/$total12*100) : 0) . "%)\n";