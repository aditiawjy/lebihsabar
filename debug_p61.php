<?php
require_once __DIR__ . "/dashboard_cache.php";
$csvFile = __DIR__ . "/goal_log.csv";
$cacheFile = __DIR__ . "/dashboard_cache.json";
$data = getCachedDashboardData($csvFile, $cacheFile);
$matches = $data["all_matches"];
$tc = require __DIR__ . "/dashboard_config.php";
$p61_teams = $tc["p61_teams"];

$p61 = array_values(array_filter($matches, fn($m) =>
    ($m['league']==='15min' && in_array(trim($m['away']), $p61_teams) && $m['h1_last']>=5 && abs($m['sc_h']-$m['sc_a'])<=1 && !($m['h1c']===1 && $m['h1_last']===5) && !($m['h1c']===1 && $m['h1_first']===6 && $m['h1_last']===6 && $m['h1s']===['A'] && $m['fh']===0 && $m['fa']===1) && !($m['h1c']===1 && $m['h1_first']===6 && $m['h1_last']===6 && $m['h1s']===['H'] && $m['fh']===1 && $m['fa']===0) && !($m['h1_first']===3 && $m['h1_last']===5 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_first']===4 && $m['h1_last']===6 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_first']===3 && $m['h1_last']===5 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_first']===3 && $m['h1_last']===7 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1) && !($m['h1_last']===7 && $m['min_gap']===0 && $m['h1s']===['A','H','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===0 && $m['h1_last']===5 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===0 && $m['h1_last']===6 && $m['h1c']===3 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===5 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===1 && $m['h1_last']===5 && $m['h1s']===['H','H','H'] && $m['sc_h']===3 && $m['sc_a']===0) && !($m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','H','H'] && $m['sc_h']===2 && $m['sc_a']===1) && !($m['h1_first']===2 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===6 && $m['h1_last']===6 && $m['h1s']===['H','A'] && $m['sc_h']===1 && $m['sc_a']===1 && $m['min_gap']===0) && !($m['h1_first']===3 && $m['h1_last']===7 && $m['h1s']===['A','A','H'] && $m['sc_h']===1 && $m['sc_a']===2) && !($m['h1_first']===2 && $m['h1_last']===6 && $m['h1s']===['H','A','H','A'] && $m['sc_h']===2 && $m['sc_a']===2 && $m['max_gap']<=2))
));

$total = count($p61);
$hits = count(array_filter($p61, fn($m) => $m["h2c"] > 0));
$misses = array_values(array_filter($p61, fn($m) => $m["h2c"] === 0));

echo "P61 Current: {$hits}/{$total} = " . round($hits/$total*100,2) . "%\n\n";
echo "Misses:\n";
foreach ($misses as $m) {
    echo "  home={$m["home"]} away={$m["away"]} league={$m["league"]} h1c={$m["h1c"]} first={$m["h1_first"]} last={$m["h1_last"]} sc_h={$m["sc_h"]} sc_a={$m["sc_a"]} h1s=[" . implode(",",$m["h1s"]) . "] switches={$m["switches"]} min_gap={$m["min_gap"]} max_gap={$m["max_gap"]}\n";
}
