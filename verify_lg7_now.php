<?php
require __DIR__ . '/dashboard_cache.php';

$csvFile = __DIR__ . '/goal_log.csv';
$matches = [];
if (file_exists($csvFile)) {
    $fh = fopen($csvFile, 'r');
    if ($fh !== false) {
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 7) continue;
            $row = array_pad($row, 10, '');
            $goalsStr = trim($row[4] ?? '');
            if ($goalsStr === '' && (int)($row[5] ?? 0) === 0 && (int)($row[6] ?? 0) === 0) continue;
            $matches[] = $row;
        }
        fclose($fh);
    }
}

$parsedMatches = parseMatches($matches);
$latePatterns = computeLatePatterns($parsedMatches);

foreach ($latePatterns as $p) {
    if ($p['id'] !== 'LG7') continue;
    $total = count($p['data']);
    $hits = 0;
    $misses = [];
    foreach ($p['data'] as $m) {
        if ($m['h2c'] > 0) $hits++;
        else $misses[] = $m;
    }
    $acc = $total > 0 ? round($hits / $total * 100, 1) : 0;
    echo "LG7: $hits/$total ($acc%)\n";
    
    if (count($misses) > 0) {
        echo "\nMisses:\n";
        foreach ($misses as $m) {
            echo sprintf("  %s vs %s | h1c=%d h1s=%s first=%d last=%d sc=%d-%d h2c=%d | league=%s min_gap=%d max_gap=%d switches=%d max_run=%d\n",
                $m['home'], $m['away'], $m['h1c'], json_encode($m['h1s']),
                $m['h1_first'], $m['h1_last'], $m['sc_h'], $m['sc_a'], $m['h2c'],
                $m['league'], $m['min_gap'], $m['max_gap'], $m['switches'], $m['max_run']);
        }
    } else {
        echo "No misses.\n";
    }
}

echo "\nDone.\n";
