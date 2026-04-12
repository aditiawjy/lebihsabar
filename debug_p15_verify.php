<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$patterns = computePatterns($matches);

foreach ($patterns as $p) {
    if ($p['id'] !== 'P15') continue;
    
    $total = count($p['data']);
    $hits = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $pct = $total > 0 ? round($hits / $total * 100) : 0;
    
    echo "P15 (UPDATED): $total matches, $hits hit 2H goal ($pct%)\n";
    echo "Label: {$p['label']}\n";
    
    if ($total <= 15) {
        echo "\nMatches:\n";
        foreach ($p['data'] as $m) {
            $status = $m['h2c'] > 0 ? 'HIT' : 'MISS';
            echo "  [$status] {$m['home']} vs {$m['away']} | HT: {$m['sc_h']}-{$m['sc_a']} | max_gap: {$m['max_gap']}\n";
        }
    }
}