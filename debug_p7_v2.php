<?php
$csvFile = __DIR__ . '/goal_log.csv';
$fh = fopen($csvFile, 'r');
fgetcsv($fh);
$p7Matches = [];
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 7) continue;
    $goalsStr = trim($row[4] ?? '');
    if ($goalsStr === '' && (int)($row[5] ?? 0) === 0 && (int)($row[6] ?? 0) === 0) continue;
    
    // Parse goals
    $goals = [];
    $parts = explode('|', $goalsStr);
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/(1H|2H)\s+(\d+)\'\s*\((\d+)-(\d+)\)/', $part, $m)) {
            $goals[] = ['half' => $m[1], 'min' => (int)$m[2], 'home' => (int)$m[3], 'away' => (int)$m[4]];
        }
    }
    
    $h1 = array_values(array_filter($goals, fn($g) => $g['half'] === '1H'));
    $h2 = array_values(array_filter($goals, fn($g) => $g['half'] === '2H'));
    
    $sh = end($h1) ?: null;
    $scH = $sh ? $sh['home'] : 0;
    $scA = $sh ? $sh['away'] : 0;
    
    if (count($h1) == 2 && $scH == 1 && $scA == 1) {
        $gap = $h1[1]['min'] - $h1[0]['min'];
        $firstMin = $h1[0]['min'];
        if ($gap >= 5 && $firstMin !== 1) {
            $p7Matches[] = [
                'datetime' => $row[0],
                'home' => $row[2],
                'away' => $row[3],
                'goals' => $goalsStr,
                'h2c' => count($h2),
                'h2_goals' => $h2,
                'gap' => $gap,
                'first_min' => $firstMin,
            ];
        }
    }
}
fclose($fh);

echo "P7 matches found: " . count($p7Matches) . PHP_EOL;
$missCount = 0;
foreach ($p7Matches as $m) {
    echo $m['datetime'] . ' | ' . $m['home'] . ' vs ' . $m['away'] . ' | h2c=' . $m['h2c'] . ' | gap=' . $m['gap'] . ' | first=' . $m['first_min'] . PHP_EOL;
    if ($m['h2c'] == 0) {
        echo '  *** NO 2H GOALS - SHOULD BE A MISS ***' . PHP_EOL;
        $missCount++;
    }
}
echo "Total misses (h2c==0): " . $missCount . PHP_EOL;
echo "Accuracy: " . (count($p7Matches) - $missCount) . "/" . count($p7Matches) . " = " . round(((count($p7Matches) - $missCount) / count($p7Matches)) * 100, 1) . "%" . PHP_EOL;
