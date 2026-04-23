<?php
require_once __DIR__ . "/dashboard_cache.php";
$csvFile = __DIR__ . "/goal_log.csv";
$cacheFile = __DIR__ . "/dashboard_cache.json";
$data = getCachedDashboardData($csvFile, $cacheFile);
$matches = $data["all_matches"];
$tc = require __DIR__ . "/dashboard_config.php";
$p62_teams = $tc["p62_teams"];

$p62 = array_values(array_filter($matches, fn($m) => (($m["league"]==="15min" && in_array(trim($m["home"]), $p62_teams) && $m["h1_first"]<=1 && $m["h1_last"]>=4 && ($m["h1_first"]===0 || trim($m["home"])!=="FC Koln (V)") && ($m["switches"]>=1 || $m["h1c"]<=3 || $m["h1_last"]>=7)) || ($m["league"]==="15min" && $m["h1_first"]<=1 && $m["h1_last"]>=6 && $m["switches"]>=2 && abs($m["sc_h"]-$m["sc_a"])<=2 && count($m["h1s"])>0 && $m["h1s"][count($m["h1s"])-1]==="H") || ($m["league"]==="16min" && $m["h1_first"]<=1 && $m["h1_last"]>=7 && abs($m["sc_h"]-$m["sc_a"])<=2 && count($m["h1s"])>0 && $m["h1s"][count($m["h1s"])-1]==="H") || ($m["league"]==="20min" && $m["h1_first"]<=1 && $m["h1_last"]>=3 && $m["switches"]>=2 && $m["sc_h"]===$m["sc_a"] && count($m["h1s"])>0 && $m["h1s"][count($m["h1s"])-1]==="H")) && !(trim($m["away"])==="Getafe CF (V)" && $m["h1s"]===["A","H","A"]))));

$total = count($p62);
$hits = count(array_filter($p62, fn($m) => $m["h2c"] > 0));
$misses = array_values(array_filter($p62, fn($m) => $m["h2c"] === 0));

echo "P62 Current: {$hits}/{$total} = " . round($hits/$total*100,2) . "%\n\n";
echo "Misses:\n";
foreach ($misses as $m) {
    $src = "";
    if ($m["league"]==="15min" && in_array(trim($m["home"]), $p62_teams) && $m["h1_first"]<=1 && $m["h1_last"]>=4) $src = "team";
    elseif ($m["league"]==="15min" && $m["h1_first"]<=1 && $m["h1_last"]>=6 && $m["switches"]>=2) $src = "15umum";
    elseif ($m["league"]==="16min" && $m["h1_first"]<=1 && $m["h1_last"]>=7) $src = "16umum";
    elseif ($m["league"]==="20min" && $m["h1_first"]<=1 && $m["h1_last"]>=3 && $m["switches"]>=2 && $m["sc_h"]===$m["sc_a"]) $src = "20umum";
    echo "  src={$src} home={$m["home"]} away={$m["away"]} league={$m["league"]} h1c={$m["h1c"]} first={$m["h1_first"]} last={$m["h1_last"]} sc_h={$m["sc_h"]} sc_a={$m["sc_a"]} h1s=[" . implode(",",$m["h1s"]) . "] switches={$m["switches"]} max_run={$m["max_run"]}\n";
}
