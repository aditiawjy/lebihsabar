<?php
require 'dashboard_cache.php';
$d = buildDashboardData('goal_log.csv', file_exists('goal_log.csv'));
foreach ($d['patterns'] as $p) {
    if ($p['id'] === 'P62') {
        echo $p['label'], PHP_EOL;
        $total = count($p['data']);
        $hit = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
        echo "$hit/$total\n";
        foreach ($p['data'] as $m) {
            echo ($m['h2c'] > 0 ? 'W' : 'L') . ' ' . $m['datetime'] . ' ' . $m['league'] . ' ' . $m['home'] . ' vs ' . $m['away'] . ' HT ' . $m['sc_h'] . '-' . $m['sc_a'] . ' seq ' . implode('', $m['h1s']) . ' first ' . $m['h1_first'] . ' last ' . $m['h1_last'] . ' span ' . ($m['h1_last']-$m['h1_first']) . ' sw ' . $m['switches'] . ' max_run ' . $m['max_run'] . ' min_gap ' . $m['min_gap'] . ' max_gap ' . $m['max_gap'] . ' h2c ' . $m['h2c'] . PHP_EOL;
        }
    }
}
