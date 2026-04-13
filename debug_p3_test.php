<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original P3
$filtered = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 3 && 
           $m['h1_last'] >= 4;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi A: last >= 5
$filtered2 = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 3 && 
           $m['h1_last'] >= 5;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI A (last>=5): $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi B: last >= 6
$filtered3 = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 3 && 
           $m['h1_last'] >= 6;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI B (last>=6): $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi C: gap >= 4
$filtered4 = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 4 && 
           $m['h1_last'] >= 4;
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI C (gap>=4): $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi F: gap>=4 + last>=6
$filtered6 = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 4 && 
           $m['h1_last'] >= 6;
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI F (gap>=4+last>=6): $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";

// Opsi D: min_gap >= 1
$filtered5 = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 3 && 
           $m['h1_last'] >= 4 &&
           $m['min_gap'] >= 1;
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI D (min_gap>=1): $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";

// Opsi E: h1c >= 2 (bukan == 2)
$filtered6 = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] >= 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 3 && 
           $m['h1_last'] >= 4;
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI E (h1c>=2): $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";