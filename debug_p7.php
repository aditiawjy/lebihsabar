<?php
require_once __DIR__ . '/dashboard_cache.php';
$data = buildDashboardData(__DIR__ . '/goal_log.csv', true);

foreach ($data['patterns'] as $p) {
    if ($p['id'] === 'P7') {
        echo "P7: " . count($p['data']) . " matches\n";
        $hitCount = 0;
        $missCount = 0;
        foreach ($p['data'] as $i => $m) {
            $has2h = $m['h2c'] > 0 ? 'HIT' : 'MISS';
            if ($m['h2c'] > 0) $hitCount++; else $missCount++;
            echo sprintf(
                "%d: %s vs %s | h1c=%d | sc=%d-%d | h2c=%d | first=%d | gap=%d | scorers=%s | %s\n",
                $i+1, $m['home'], $m['away'],
                $m['h1c'], $m['sc_h'], $m['sc_a'],
                $m['h2c'], $m['h1_first'], $m['max_gap'],
                implode(',', $m['h1s']),
                $has2h
            );
        }
        echo "\nTotal: $hitCount hit, $missCount miss\n";
        $total = count($p['data']);
        echo "Accuracy: " . ($total > 0 ? round($hitCount/$total*100) : 0) . "%\n";
    }
}
