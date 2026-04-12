<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Original P26: league 16min + total ganjil + last >= 6
// Try variations to improve accuracy

echo "=== ORIGINAL P26 ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 1: Exclude h1c=1 (only 1 goal in 1H) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['h1c'] >= 2
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 2: Add max_gap >= 2 ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['max_gap'] >= 2
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 3: Add min_gap >= 1 (gap between goals) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['min_gap'] >= 1
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 4: Add switches >= 1 (scorers change) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['switches'] >= 1
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 5: h1c >= 2 (at least 2 goals in 1H) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['h1c'] >= 2 &&
    $m['max_gap'] >= 1
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 6: HT away leads (sc_a > sc_h) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['sc_a'] > $m['sc_h']
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";

echo "=== VARIATION 7: h1_last >= 7 (exclude 6) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 7
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n\n";