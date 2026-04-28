<?php
require __DIR__ . '/dashboard_cache.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function pattern_signature(array $m): string {
    return ($m['league'] ?? '') . '|'
        . ($m['h1_first'] ?? '') . '|'
        . ($m['h1_last'] ?? '') . '|'
        . implode('', $m['h1s'] ?? []) . '|'
        . ($m['sc_h'] ?? '') . '-' . ($m['sc_a'] ?? '');
}

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$patterns = $data['patterns'] ?? [];
$out = [
    'ok' => true,
    'generated_at' => $data['generated_at'] ?? null,
    'patterns' => [
        'P72' => [],
    ],
];

foreach ($patterns as $pattern) {
    $id = $pattern['id'] ?? '';
    if (!isset($out['patterns'][$id])) continue;

    $signatures = [];
    foreach (($pattern['data'] ?? []) as $m) {
        $sig = pattern_signature($m);
        if ($sig !== '') $signatures[$sig] = true;
    }
    $out['patterns'][$id] = array_keys($signatures);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
