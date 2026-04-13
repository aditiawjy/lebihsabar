<?php
require_once __DIR__ . '/dashboard_cache.php';

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];

$filtered = array_filter($matches, function($m) {
    return in_array($m['league'], ['15min','16min']) && 
           $m['h1c'] == 2 && 
           $m['sc_h'] == 1 && 
           $m['sc_a'] == 1 && 
           $m['h1s'] == ['A','H'] && 
           ($m['h1'][1]['min'] - $m['h1'][0]['min']) >= 4 && 
           $m['h1_last'] >= 6;
});
$total = count($filtered);
$hits = count(array_filter($filtered, function($m) { return $m['h2c'] > 0; }));
echo "gap>=4+last>=6: $total match, $hits hit (" . ($total > 0 ? round($hits/$total*100) : 0) . "%)\n";