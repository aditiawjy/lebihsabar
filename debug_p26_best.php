<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

echo "=== BEST OPTION: h1c >= 2 ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['h1c'] >= 2
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n";
echo "Matches:\n";
foreach ($filtered as $m) {
    echo "  {$m['home']} vs {$m['away']} | HT: {$m['sc_h']}-{$m['sc_a']}\n";
}

echo "\n=== FALLBACK: h1c = 1 (less accurate) ===\n";
$filtered = array_filter($matches, fn($m) => 
    $m['league'] === '16min' && 
    ($m['sc_h'] + $m['sc_a']) % 2 === 1 && 
    $m['h1_last'] >= 6 &&
    $m['h1c'] === 1
);
$total = count($filtered);
$hits = count(array_filter($filtered, fn($m) => $m['h2c'] > 0));
echo "Total: $total, Hit: $hits, Acc: " . round($hits/$total*100) . "%\n";