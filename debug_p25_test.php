<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = getTeamConfig();
$p25_teams = $tc['p25_teams'];

// Current P25 (min_gap>=1)
$filtered = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           ($m['h1_last'] - $m['h1_first']) !== 2 &&
           $m['min_gap'] >= 1;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "CURRENT (min_gap>=1): $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi A: last >= 3 + min_gap>=1
$filtered2 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 3 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['min_gap'] >= 1;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI A (last>=3+min_gap>=1): $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi B: span >= 3 + min_gap>=1
$filtered3 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           ($m['h1_last'] - $m['h1_first']) >= 3 &&
           $m['min_gap'] >= 1;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI B (span>=3+min_gap>=1): $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi C: h1c >= 2 + min_gap>=1
$filtered4 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['h1c'] >= 2 &&
           $m['min_gap'] >= 1;
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI C (h1c>=2+min_gap>=1): $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi D: selisih = 0 + min_gap>=1
$filtered5 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 2 && 
           $m['sc_h'] === $m['sc_a'] &&
           $m['min_gap'] >= 1;
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI D (seri+min_gap>=1): $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";

// Opsi E: span >= 3 (no min_gap)
$filtered6 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 2 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           ($m['h1_last'] - $m['h1_first']) >= 3;
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI E (span>=3): $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";

// Opsi F: last >= 3 (no min_gap filter)
$filtered7 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 3 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1;
});
$total7 = count($filtered7);
$hits7 = count(array_filter($filtered7, function($m) { return $m['h2c'] > 0; }));
echo "OPSI F (last>=3): $total7 match, $hits7 hit (" . ($total7 > 0 ? round($hits7/$total7*100) : 0) . "%)\n";

// Opsi G: last >= 4 + min_gap>=1
$filtered8 = array_filter($matches, function($m) use($p25_teams) {
    return in_array(trim($m['away']), $p25_teams) && 
           $m['h1_last'] >= 4 && 
           abs($m['sc_h'] - $m['sc_a']) <= 1 && 
           $m['min_gap'] >= 1;
});
$total8 = count($filtered8);
$hits8 = count(array_filter($filtered8, function($m) { return $m['h2c'] > 0; }));
echo "OPSI G (last>=4+min_gap>=1): $total8 match, $hits8 hit (" . ($total8 > 0 ? round($hits8/$total8*100) : 0) . "%)\n";