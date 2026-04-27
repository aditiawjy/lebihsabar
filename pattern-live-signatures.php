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

function shape_signature(array $m): string {
    return ($m['league'] ?? '') . '|'
        . ($m['h1c'] ?? '') . '|'
        . ($m['h1_first'] ?? '') . '|'
        . ($m['h1_last'] ?? '') . '|'
        . ($m['sc_h'] ?? '') . '-' . ($m['sc_a'] ?? '') . '|'
        . ($m['switches'] ?? '') . '|'
        . ($m['min_gap'] ?? '') . '|'
        . ($m['max_gap'] ?? '');
}

$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$patterns = $data['patterns'] ?? [];
$out = [
    'ok' => true,
    'generated_at' => $data['generated_at'] ?? null,
    'patterns' => [
        'P72' => [],
        'P73' => [],
        'P74' => [],
    ],
];

foreach ($patterns as $pattern) {
    $id = $pattern['id'] ?? '';
    if (!isset($out['patterns'][$id])) continue;

    $signatures = [];
    foreach (($pattern['data'] ?? []) as $m) {
        $sig = $id === 'P74' ? shape_signature($m) : pattern_signature($m);
        if ($sig !== '') $signatures[$sig] = true;
    }
    $out['patterns'][$id] = array_keys($signatures);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
