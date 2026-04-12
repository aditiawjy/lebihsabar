<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original NG2
$filtered = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi A: h1c >= 2
$filtered2 = array_filter($matches, function($m) {
    return $m['h1c'] >= 2 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3;
});
$total2 = count($filtered2);
$hits2 = count(array_filter($filtered2, function($m) { return $m['h2c'] > 0; }));
echo "OPSI A (h1c>=2): $total2 match, $hits2 hit (" . ($total2 > 0 ? round($hits2/$total2*100) : 0) . "%)\n";

// Opsi B: h1_last >= 2 && h1_last <= 4
$filtered3 = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] >= 2 && $m['h1_last'] <= 4;
});
$total3 = count($filtered3);
$hits3 = count(array_filter($filtered3, function($m) { return $m['h2c'] > 0; }));
echo "OPSI B (last 2-4): $total3 match, $hits3 hit (" . ($total3 > 0 ? round($hits3/$total3*100) : 0) . "%)\n";

// Opsi C: h1c >= 3
$filtered4 = array_filter($matches, function($m) {
    return $m['h1c'] >= 3 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3;
});
$total4 = count($filtered4);
$hits4 = count(array_filter($filtered4, function($m) { return $m['h2c'] > 0; }));
echo "OPSI C (h1c>=3): $total4 match, $hits4 hit (" . ($total4 > 0 ? round($hits4/$total4*100) : 0) . "%)\n";

// Opsi D: h1_last >= 3 (bukan ==3)
$filtered5 = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] >= 3;
});
$total5 = count($filtered5);
$hits5 = count(array_filter($filtered5, function($m) { return $m['h2c'] > 0; }));
echo "OPSI D (last>=3): $total5 match, $hits5 hit (" . ($total5 > 0 ? round($hits5/$total5*100) : 0) . "%)\n";

// Opsi E: switches >= 1
$filtered6 = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3 && $m['switches'] >= 1;
});
$total6 = count($filtered6);
$hits6 = count(array_filter($filtered6, function($m) { return $m['h2c'] > 0; }));
echo "OPSI E (switches>=1): $total6 match, $hits6 hit (" . ($total6 > 0 ? round($hits6/$total6*100) : 0) . "%)\n";

// Opsi F: league 16min
$filtered7 = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3 && $m['league'] === '16min';
});
$total7 = count($filtered7);
$hits7 = count(array_filter($filtered7, function($m) { return $m['h2c'] > 0; }));
echo "OPSI F (16min): $total7 match, $hits7 hit (" . ($total7 > 0 ? round($hits7/$total7*100) : 0) . "%)\n";

// Opsi G: h1_first >= 2
$filtered8 = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3 && $m['h1_first'] >= 2;
});
$total8 = count($filtered8);
$hits8 = count(array_filter($filtered8, function($m) { return $m['h2c'] > 0; }));
echo "OPSI G (first>=2): $total8 match, $hits8 hit (" . ($total8 > 0 ? round($hits8/$total8*100) : 0) . "%)\n";

// Opsi H: max_gap >= 1
$filtered9 = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] > $m['sc_a'] && $m['h1_last'] == 3 && $m['max_gap'] >= 1;
});
$total9 = count($filtered9);
$hits9 = count(array_filter($filtered9, function($m) { return $m['h2c'] > 0; }));
echo "OPSI H (max_gap>=1): $total9 match, $hits9 hit (" . ($total9 > 0 ? round($hits9/$total9*100) : 0) . "%)\n";