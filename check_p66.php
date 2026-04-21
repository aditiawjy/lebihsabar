<?php
require_once __DIR__ . '/dashboard_cache.php';
$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = require __DIR__ . '/dashboard_config.php';
$p66_teams = $tc['p66_teams'];

$all = array_values(array_filter($matches, fn($m) => $m['league']==='15min' && in_array(trim($m['away']), $p66_teams)));
$cur = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2)));

echo "Current P66: " . count(array_filter($cur, fn($m)=>$m['h2c']>0)) . "/" . count($cur) . "\n\n";

echo "=== Excluded matches (15min + P66 AWAY team, not in current) ===\n";
$excluded = array_values(array_filter($all, fn($m) => !($m['h1_first']<=1 && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2))));

$passExcluded = array_values(array_filter($excluded, fn($m) => $m['h2c'] > 0));
echo "\nExcluded PASS (potential additions): " . count($passExcluded) . "\n";
foreach ($passExcluded as $m) {
    $r = '';
    if ($m['h1_first'] > 1) $r .= 'first>1 ';
    if ($m['h1_last'] < 5) $r .= 'last<5 ';
    if (trim($m['away'])==='Napoli (V)' && $m['max_run'] > 2) $r .= 'Napoli_max_run>2';
    echo "  {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} h2c={$m['h2c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']} | Reason: $r\n";
}

$failExcluded = array_values(array_filter($excluded, fn($m) => $m['h2c'] === 0));
echo "\nExcluded FAIL (must avoid): " . count($failExcluded) . "\n";
foreach ($failExcluded as $m) {
    $r = '';
    if ($m['h1_first'] > 1) $r .= 'first>1 ';
    if ($m['h1_last'] < 5) $r .= 'last<5 ';
    if (trim($m['away'])==='Napoli (V)' && $m['max_run'] > 2) $r .= 'Napoli_max_run>2';
    echo "  {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']} | Reason: $r\n";
}

echo "\n=== Loosening tests ===\n";
$tests = [
    'first<=2 + last>=5 + Napoli restrict' => fn($m) => $m['h1_first']<=2 && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2),
    'first<=1 + last>=4 + Napoli restrict' => fn($m) => $m['h1_first']<=1 && $m['h1_last']>=4 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2),
    'first<=2 + last>=4 + Napoli restrict' => fn($m) => $m['h1_first']<=2 && $m['h1_last']>=4 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2),
    'first<=1 + last>=5 + no Napoli restrict' => fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5,
    'first<=1 + last>=4 + no Napoli restrict' => fn($m) => $m['h1_first']<=1 && $m['h1_last']>=4,
    'first<=2 + last>=5 + no Napoli restrict' => fn($m) => $m['h1_first']<=2 && $m['h1_last']>=5,
    'first<=2 + last>=4 + no Napoli restrict' => fn($m) => $m['h1_first']<=2 && $m['h1_last']>=4,
    'first<=1 + (last>=5 or last>=4+h1c>=2) + Napoli restrict' => fn($m) => $m['h1_first']<=1 && ($m['h1_last']>=5 || ($m['h1_last']>=4 && $m['h1c']>=2)) && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2),
    'first<=1 or (first==2+h1c>=2) + last>=5 + Napoli restrict' => fn($m) => ($m['h1_first']<=1 || ($m['h1_first']==2 && $m['h1c']>=2)) && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2),
    'first<=1 or (first==2+last>=6) + last>=5 + Napoli restrict' => fn($m) => ($m['h1_first']<=1 || ($m['h1_first']==2 && $m['h1_last']>=6)) && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2),
    'first<=2 + last>=4 + no Napoli restrict + h1c>=2' => fn($m) => $m['h1_first']<=2 && $m['h1_last']>=4 && $m['h1c']>=2,
];

foreach ($tests as $label => $fn) {
    $r = array_values(array_filter($all, $fn));
    $h = count(array_filter($r, fn($m) => $m['h2c'] > 0));
    $f = count($r) - $h;
    echo sprintf("%-55s => %2d/%2d (%d FAIL)\n", $label, $h, count($r), $f);
    foreach ($r as $m) {
        if ($m['h2c'] == 0) {
            echo "    FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']}\n";
        }
    }
}
