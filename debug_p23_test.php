<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original P23
$filtered = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] === 1 && 
           $m['h1_first'] >= 3 && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "ORIGINAL: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";

// Opsi F: h1c === 1 AND h1_first >= 4 AND h1_last >= 4
$filtered7 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] === 1 && 
           $m['h1_first'] >= 4 && 
           $m['h1_last'] >= 4 &&
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total7 = count($filtered7);
$hits7 = count(array_filter($filtered7, function($m) { return $m['h2c'] > 0; }));
echo "OPSI F (first>=4 && last>=4): $total7 match, $hits7 hit (" . ($total7 > 0 ? round($hits7/$total7*100) : 0) . "%)\n";

// Opsi G: h1c >= 2
$filtered8 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] >= 2 && 
           $m['h1_first'] >= 3 && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total8 = count($filtered8);
$hits8 = count(array_filter($filtered8, function($m) { return $m['h2c'] > 0; }));
echo "OPSI G (h1c>=2): $total8 match, $hits8 hit (" . ($total8 > 0 ? round($hits8/$total8*100) : 0) . "%)\n";

// Opsi H: h1c >= 3
$filtered9 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] >= 3 && 
           $m['h1_first'] >= 3 && 
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total9 = count($filtered9);
$hits9 = count(array_filter($filtered9, function($m) { return $m['h2c'] > 0; }));
echo "OPSI H (h1c>=3): $total9 match, $hits9 hit (" . ($total9 > 0 ? round($hits9/$total9*100) : 0) . "%)\n";

// Opsi I: h1c === 1 AND sc_h > sc_a (HOME lead)
$filtered10 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] === 1 && 
           $m['h1_first'] >= 3 && 
           $m['sc_h'] > $m['sc_a'] &&
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total10 = count($filtered10);
$hits10 = count(array_filter($filtered10, function($m) { return $m['h2c'] > 0; }));
echo "OPSI I (HOME lead): $total10 match, $hits10 hit (" . ($total10 > 0 ? round($hits10/$total10*100) : 0) . "%)\n";

// Opsi J: h1c === 1 AND h1_last <= 6
$filtered11 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] === 1 && 
           $m['h1_first'] >= 3 && 
           $m['h1_last'] <= 6 &&
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total11 = count($filtered11);
$hits11 = count(array_filter($filtered11, function($m) { return $m['h2c'] > 0; }));
echo "OPSI J (last<=6): $total11 match, $hits11 hit (" . ($total11 > 0 ? round($hits11/$total11*100) : 0) . "%)\n";

// Opsi K: h1c === 1 AND h1_last <= 5
$filtered12 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && 
           $m['h1c'] === 1 && 
           $m['h1_first'] >= 3 && 
           $m['h1_last'] <= 5 &&
           count($m['h1s']) > 0 && 
           $m['h1s'][0] === 'H';
});
$total12 = count($filtered12);
$hits12 = count(array_filter($filtered12, function($m) { return $m['h2c'] > 0; }));
echo "OPSI K (last<=5): $total12 match, $hits12 hit (" . ($total12 > 0 ? round($hits12/$total12*100) : 0) . "%)\n";