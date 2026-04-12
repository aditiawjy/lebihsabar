<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

echo "=== NG3 Next Goal Testing ===\n\n";

// Current: 47% HOME next
$f = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['h1c'] >= 1 && $m['sc_h'] == 1 && $m['sc_a'] == 0 && $m['h1_last'] >= 3;
});
$t = count($f); 
$h = count(array_filter($f, fn($m) => $m['next_goal'] === 'H'));
echo "CURRENT: $t match, H next: $h (" . ($t > 0 ? round($h/$t*100) : 0) . "%)\n";

// Opsi: sc_h >= 2 (HOME scored 2+ in 1H)
$f2 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_h'] >= 2 && $m['sc_a'] == 0 && $m['h1_last'] >= 3;
});
$t2 = count($f2); 
$h2 = count(array_filter($f2, fn($m) => $m['next_goal'] === 'H'));
echo "OPSI sc_h>=2+last>=3: $t2 match, H next: $h2 (" . ($t2 > 0 ? round($h2/$t2*100) : 0) . "%)\n";

// Opsi: h1_last == 3
$f3 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['h1c'] >= 1 && $m['sc_h'] == 1 && $m['sc_a'] == 0 && $m['h1_last'] == 3;
});
$t3 = count($f3); 
$h3 = count(array_filter($f3, fn($m) => $m['next_goal'] === 'H'));
echo "OPSI last==3: $t3 match, H next: $h3 (" . ($t3 > 0 ? round($h3/$t3*100) : 0) . "%)\n";

// Opsi: h1c >= 2 (multiple goals in 1H)
$f4 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['h1c'] >= 2 && $m['sc_h'] == 1 && $m['sc_a'] == 0 && $m['h1_last'] >= 3;
});
$t4 = count($f4); 
$h4 = count(array_filter($f4, fn($m) => $m['next_goal'] === 'H'));
echo "OPSI h1c>=2: $t4 match, H next: $h4 (" . ($t4 > 0 ? round($h4/$t4*100) : 0) . "%)\n";

// Opsi: score 2-0 (HOME lead by 2)
$f5 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_h'] == 2 && $m['sc_a'] == 0 && $m['h1_last'] >= 3;
});
$t5 = count($f5); 
$h5 = count(array_filter($f5, fn($m) => $m['next_goal'] === 'H'));
echo "OPSI sc_h==2+sc_a==0: $t5 match, H next: $h5 (" . ($t5 > 0 ? round($h5/$t5*100) : 0) . "%)\n";

// Opsi: HT 1-0 + last scorer HOME
$f6 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['h1c'] >= 1 && $m['sc_h'] == 1 && $m['sc_a'] == 0 && $m['h1_last'] >= 3 && count($m['h1s']) > 0 && $m['h1s'][count($m['h1s'])-1] === 'H';
});
$t6 = count($f6); 
$h6 = count(array_filter($f6, fn($m) => $m['next_goal'] === 'H'));
echo "OPSI last-scorer-H: $t6 match, H next: $h6 (" . ($t6 > 0 ? round($h6/$t6*100) : 0) . "%)\n";

// Opsi: Change prediction to OVER instead of HOME
echo "\n--- Change to OVER prediction ---\n";
$f7 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['h1c'] >= 1 && $m['sc_h'] == 1 && $m['sc_a'] == 0 && $m['h1_last'] >= 3;
});
$t7 = count($f7); 
$h7 = count(array_filter($f7, fn($m) => $m['h2c'] > 0));
echo "OVER prediction: $t7 match, any goal: $h7 (" . ($t7 > 0 ? round($h7/$t7*100) : 0) . "%)\n";

// Opsi: score 2-0 HOME lead + last >= 3
$f8 = array_filter($matches, function($m) {
    return $m['league'] === '16min' && $m['sc_h'] >= 2 && $m['sc_a'] == 0 && $m['h1_last'] >= 3;
});
$t8 = count($f8); 
$h8 = count(array_filter($f8, fn($m) => $m['next_goal'] === 'H'));
echo "OPSI sc_h>=2+sc_a==0: $t8 match, H next: $h8 (" . ($t8 > 0 ? round($h8/$t8*100) : 0) . "%)\n";