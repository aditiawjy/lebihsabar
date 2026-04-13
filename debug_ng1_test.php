<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

// Check structure: does "next" field support "OVER"?
$f = array_filter($matches, function($m) {
    return $m['h1c'] >= 1 && $m['sc_h'] == 1 && $m['sc_a'] == 0 && $m['h1_last'] == 3;
});
echo "Sample next_goal values:\n";
foreach (array_slice($f, 0, 5) as $m) {
    echo "  " . $m['home'] . " vs " . $m['away'] . " -> next_goal=" . ($m['next_goal'] ?? 'N/A') . "\n";
}

// How does computeSnapshotData handle next patterns?
echo "\nChecking dashboard_cache.php for OVER support...\n";