<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$patterns = computePatterns($matches);

foreach ($patterns as $p) {
    if ($p['id'] !== 'P26') continue;
    
    echo "=== P26 MISS Analysis ===\n\n";
    
    foreach ($p['data'] as $m) {
        if ($m['h2c'] == 0) {
            echo "MISS: {$m['home']} vs {$m['away']}\n";
            echo "  HT: {$m['sc_h']}-{$m['sc_a']}\n";
            echo "  h1c: {$m['h1c']}, h1_last: {$m['h1_last']}\n";
            echo "  h1s: " . json_encode($m['h1s']) . "\n";
            echo "  max_gap: {$m['max_gap']}, min_gap: {$m['min_gap']}, max_run: {$m['max_run']}\n";
            echo "  switches: {$m['switches']}\n";
            
            // Check if 2H has any goal
            $has_2h = count($m['h2']) > 0;
            echo "  2H goals: " . ($has_2h ? "yes" : "no") . "\n";
            
            if (!empty($m['h2'])) {
                echo "  2H detail: ";
                foreach ($m['h2'] as $g) {
                    echo "{$g['min']}'({$g['home']}-{$g['away']}) ";
                }
                echo "\n";
            }
            echo "\n";
        }
    }
    
    echo "=== COMPARING WITH HITS (sample) ===\n\n";
    $hit_count = 0;
    foreach ($p['data'] as $m) {
        if ($m['h2c'] > 0 && $hit_count < 10) {
            echo "HIT: {$m['home']} vs {$m['away']}\n";
            echo "  HT: {$m['sc_h']}-{$m['sc_a']}\n";
            echo "  h1c: {$m['h1c']}, h1_last: {$m['h1_last']}\n";
            echo "  h1s: " . json_encode($m['h1s']) . "\n";
            echo "  max_gap: {$m['max_gap']}, min_gap: {$m['min_gap']}, max_run: {$m['max_run']}\n";
            echo "  switches: {$m['switches']}\n";
            echo "  2H goals: " . count($m['h2']) . " ";
            foreach ($m['h2'] as $g) {
                echo "{$g['min']}'({$g['home']}-{$g['away']}) ";
            }
            echo "\n\n";
            $hit_count++;
        }
    }
}