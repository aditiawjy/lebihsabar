<?php
$timeTo = '23:59';
$slots = [];
for ($h = 0; $h < 24; $h++) {
    for ($m = 0; $m < 60; $m += 30) {
        $hh = str_pad($h, 2, '0', STR_PAD_LEFT);
        $mm = str_pad($m, 2, '0', STR_PAD_LEFT);
        $slots[] = "$hh:$mm";
    }
}
$slots[] = '23:59';

echo "Total slots: " . count($slots) . "\n";
echo "Last 3 slots:\n";
$last3 = array_slice($slots, -3);
foreach ($last3 as $s) {
    $sel = ($timeTo === $s) ? 'SELECTED' : '';
    echo "  value='$s' $sel\n";
}

echo "\nFirst 3 slots:\n";
$first3 = array_slice($slots, 0, 3);
$timeFrom = '00:00';
foreach ($first3 as $s) {
    $sel = ($timeFrom === $s) ? 'SELECTED' : '';
    echo "  value='$s' $sel\n";
}
