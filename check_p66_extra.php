<?php
require_once __DIR__ . '/dashboard_cache.php';
$data = getCachedDashboardData(__DIR__ . '/goal_log.csv', __DIR__ . '/dashboard_cache.json');
$matches = $data['all_matches'] ?? [];
$tc = require __DIR__ . '/dashboard_config.php';
$p66_teams = $tc['p66_teams'];

$all = array_values(array_filter($matches, fn($m) => $m['league']==='15min' && in_array(trim($m['away']), $p66_teams)));

echo "Current P66: " . count(array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && (trim($m['away'])!=='Napoli (V)' || $m['max_run']<=2)))) . " matches\n\n";

echo "Test: first<=1 + last>=5 + max_run<=2 (ALL teams)\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && $m['max_run']<=2));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']}\n"; }

echo "\nTest: first<=1 + last>=5 + h1c>=2\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && $m['h1c']>=2));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1_c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']}\n"; }

echo "\nTest: first<=1 + last>=5 + switches>=1\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && $m['switches']>=1));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']}\n"; }

echo "\nTest: first<=1 + last>=5 + min_gap>=1\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && $m['min_gap']>=1));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1c={$m['h1c']} h1s=" . json_encode($m['h1s']) . " max_run={$m['max_run']}\n"; }

echo "\nTest: first<=1 + last>=5 + span>=2\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && ($m['h1_last']-$m['h1_first'])>=2));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} span=".($m['h1_last']-$m['h1_first'])." h1s=" . json_encode($m['h1s']) . "\n"; }

echo "\nTest: first<=1 + last>=5 + last scorer != H\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]!=='H'));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1s=" . json_encode($m['h1s']) . "\n"; }

echo "\nTest: first<=1 + last>=5 + first scorer == A\n";
$r = array_values(array_filter($all, fn($m) => $m['h1_first']<=1 && $m['h1_last']>=5 && count($m['h1s'])>0 && $m['h1s'][0]==='A'));
$h = count(array_filter($r, fn($m) => $m['h2c']>0));
echo "Result: $h/" . count($r) . " (" . (count($r)-$h) . " FAIL)\n";
foreach ($r as $m) { if ($m['h2c']==0) echo "  FAIL: {$m['home']} vs {$m['away']} | first={$m['h1_first']} last={$m['h1_last']} h1s=" . json_encode($m['h1s']) . "\n"; }
