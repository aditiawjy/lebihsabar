<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = getTeamConfig();
$p36_teams = $tc['p36_teams'];

// Original P36
$filtered = array_filter($matches, function($m) use($p36_teams) {
    return in_array(trim($m['home']), $p36_teams) && 
           $m['h1c'] >= 2 && 
           ($m['h1_last'] - $m['h1_first']) >= 1;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi G: span >= 3
$filtered2 = array_filter($matches, function($m) use($p36_teams) {
    return in_array(trim($m['home']), $p36_teams) && 
           $m['h1c'] >= 2 && 
           ($m['h1_last'] - $m['h1_first']) >= 3;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI G (span>=3): $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi H: h1c >= 2 + span >= 2 + min_gap >= 1
$filtered3 = array_filter($matches, function($m) use($p36_teams) {
    return in_array(trim($m['home']), $p36_teams) && 
           $m['h1c'] >= 2 && 
           ($m['h1_last'] - $m['h1_first']) >= 2 &&
           $m['min_gap'] >= 1;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI H (span>=2+min_gap>=1): $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi I: h1c == 2 (only 2 goals)
$filtered4 = array_filter($matches, function($m) use($p36_teams) {
    return in_array(trim($m['home']), $p36_teams) && 
           $m['h1c'] == 2 && 
           ($m['h1_last'] - $m['h1_first']) >= 1;
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI I (h1c==2): $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi J: sc_h >= sc_a (HOME lead or draw)
$filtered5 = array_filter($matches, function($m) use($p36_teams) {
    return in_array(trim($m['home']), $p36_teams) && 
           $m['h1c'] >= 2 && 
           ($m['h1_last'] - $m['h1_first']) >= 1 &&
           $m['sc_h'] >= $m['sc_a'];
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI J (HOME lead/draw): $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";

// Opsi K: sc_h > sc_a (HOME lead)
$filtered6 = array_filter($matches, function($m) use($p36_teams) {
    return in_array(trim($m['home']), $p36_teams) && 
           $m['h1c'] >= 2 && 
           ($m['h1_last'] - $m['h1_first']) >= 1 &&
           $m['sc_h'] > $m['sc_a'];
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI K (HOME lead): $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";