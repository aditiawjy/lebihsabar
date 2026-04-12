<?php
$fh = fopen('goal_log.csv', 'r');
fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 7) continue;
    $goalsStr = trim($row[4] ?? '');
    $fh2score = (int)($row[5] ?? 0);
    $fa2score = (int)($row[6] ?? 0);
    if ($goalsStr === '' && $fh2score === 0 && $fa2score === 0) continue;

    preg_match_all('/(1H|2H)\s+(\d+)\'\s*\((\d+)-(\d+)\)/', $goalsStr, $matches, PREG_SET_ORDER);
    $h1 = [];
    $h2 = [];
    foreach ($matches as $m) {
        $entry = ['half' => $m[1], 'min' => (int)$m[2], 'home' => (int)$m[3], 'away' => (int)$m[4]];
        if ($m[1] === '1H') $h1[] = $entry;
        else $h2[] = $entry;
    }

    $h1c = count($h1);
    if ($h1c !== 2) continue;

    $lastH1 = end($h1);
    $sc_h = $lastH1['home'];
    $sc_a = $lastH1['away'];
    if ($sc_h !== 1 || $sc_a !== 1) continue;

    $max_gap = $h1[1]['min'] - $h1[0]['min'];
    if ($max_gap < 5) continue;

    $first_min = $h1[0]['min'];
    if ($first_min === 1) continue;

    $h2c = count($h2);

    // scorer sequence
    $scorers = [];
    $ph = 0; $pa = 0;
    foreach ($h1 as $g) {
        if ($g['home'] > $ph) $scorers[] = 'H';
        if ($g['away'] > $pa) $scorers[] = 'A';
        $ph = $g['home']; $pa = $g['away'];
    }

    // next goal info
    $nextInfo = 'No 2H goal';
    $nextGoalSide = '';
    if ($h2c > 0) {
        $ng = $h2[0];
        if ($ng['home'] > $sc_h) $nextGoalSide = 'H';
        elseif ($ng['away'] > $sc_a) $nextGoalSide = 'A';
        $nextInfo = "2H {$ng['min']}' ({$ng['home']}-{$ng['away']}) -> {$nextGoalSide}";
    }

    $goalDetail = '';
    foreach ($h1 as $g) {
        $goalDetail .= "{$g['half']} {$g['min']}' ({$g['home']}-{$g['away']}) ";
    }

    echo "{$row[0]} | {$row[2]} vs {$row[3]} | 1H: {$goalDetail}| scorers: " . implode(',', $scorers) . " | first_min: {$first_min} | max_gap: {$max_gap} | 2H goals: {$h2c} | next: {$nextInfo}" . PHP_EOL;
}
fclose($fh);