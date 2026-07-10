<?php
require_once __DIR__ . "/dashboard_cache.php";
$csvFile = __DIR__ . "/goal_log.csv";
$cacheFile = __DIR__ . "/dashboard_cache.json";
$data = getCachedDashboardData($csvFile, $cacheFile);
$matches = $data["all_matches"];

$p58 = array_values(array_filter($matches, fn($m) =>
    (
        ($m['h1_first']>=3 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && !($m['h1_first']===4 && ($m['h1_last']-$m['h1_first'])===5 && $m['h1s']===['H','H'] && $m['switches']===0))
        || ($m['league']==='20min' && $m['h1_first']===2 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && ((count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H') || $m['h1c']>=3) && !($m['h1_last']===9 && $m['h1s']===['H','H'] && $m['switches']===0))
        || ($m['league']==='16min' && $m['h1_first']===1 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && $m['max_gap']>=4)
        || ($m['league']==='16min' && $m['h1_first']===0 && ($m['h1_last']-$m['h1_first'])>=5 && $m['min_gap']>=3 && abs($m['sc_h']-$m['sc_a'])>=2 && !($m['sc_h']===2 && $m['sc_a']===0 && $m['h1_last']===5 && $m['h1s']===['H','H']))
        || ($m['league']==='15min' && $m['h1c']===3 && $m['h1_first']===2 && ($m['h1_last']-$m['h1_first'])===5 && $m['min_gap']===1)
        || ($m['league']==='15min' && $m['h1_first']===0 && $m['h1_last']===6 && ($m['h1_last']-$m['h1_first'])===6 && $m['min_gap']===2)
    )
    && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']===7 && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1s']===['H','H'])
    && !($m['league']==='20min' && $m['h1_first']===2 && $m['h1_last']===9 && $m['sc_h']===2 && $m['sc_a']===1 && $m['h1s']===['H','H','A'])
    && !($m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1c']===2 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A'])
    && !($m['league']==='20min' && $m['h1_first']===4 && $m['h1_last']===10 && $m['h1c']===2 && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1s']===['A','A'])
    && !($m['league']==='20min' && ($m['kickoff_hour'] ?? -1)===22 && ($m['kickoff_minute'] ?? -1)<=14 && $m['h1_first']===4 && $m['h1_last']===9 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)
    && !($m['league']==='20min' && $m['h1_first']>=3 && $m['h1_last']>=10 && $m['h1s']===['H','H','H'] && $m['sc_h']===3 && $m['sc_a']===0 && $m['max_run']>=3 && $m['max_gap']<=4)
    && !($m['league']==='20min' && ($m['kickoff_dow_num'] ?? -1)===0 && $m['h1c']===2 && $m['h1_first']===2 && $m['h1_last']===8 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
    && !($m['league']==='20min' && ($m['kickoff_dow_num'] ?? -1)===0 && $m['h1c']===2 && $m['h1_first']===2 && $m['h1_last']===10 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
    && !($m['league']==='20min' && $m['h1c']===2 && $m['h1_first']===2 && $m['h1_last']===8 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0 && ($m['kickoff_hour'] ?? -1) >= 20)
    && !(($m['kickoff_dow_num'] ?? -1)===0 && $m['league']==='16min' && $m['h1_first']===0 && $m['h1_last']===6 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
    && !($m['league']==='16min' && $m['h1c']===1 && $m['sc_h']===0 && $m['sc_a']===1 && in_array($m['h1_first'], [1, 8], true))
    && !($m['league']==='16min' && $m['h1_first']===1 && $m['h1_last']===7 && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0)
    && !($m['league']==='15min' && $m['h1_first']===2 && $m['h1_last']===7 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2)
    && !($m['league']==='15min' && $m['h1s']===['A','A','H'] && $m['sc_h']===1 && $m['sc_a']===2 && $m['h1_first']===2 && $m['h1_last']===7 && ($m['kickoff_hour'] ?? -1) <= 6)
    && !($m['league']==='16min' && $m['h1_first']===3 && $m['h1_last']===8 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1)
    && !isP82StructuralMiss($m)
    && !($m['league']==='15min' && ($m['kickoff_hour'] ?? -1)===10 && ($m['kickoff_minute'] ?? -1)===31 && $m['h1_first']===0 && $m['h1_last']===6 && $m['h1s']===['H','A','A'] && $m['sc_h']===1 && $m['sc_a']===2 && $m['min_gap']===2 && $m['max_gap']===4)
    && !($m['league']==='20min' && $m['h1s']===['H','H'] && $m['sc_h']===2 && $m['sc_a']===0 && $m['h1_first']===3 && $m['h1_last']===9)
    && !($m['league']==='20min' && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2 && $m['h1_first']===3 && $m['h1_last']===9)
));

$total = count($p58);
$hits = count(array_filter($p58, fn($m) => $m["h2c"] > 0));
$misses = array_values(array_filter($p58, fn($m) => $m["h2c"] === 0));

echo "P58 Current: {$hits}/{$total} = " . round($hits/$total*100,2) . "%\n\n";
echo "Misses:\n";
foreach ($misses as $m) {
    echo "  home={$m["home"]} away={$m["away"]} league={$m["league"]} h1c={$m["h1c"]} first={$m["h1_first"]} last={$m["h1_last"]} sc_h={$m["sc_h"]} sc_a={$m["sc_a"]} h1s=[" . implode(",",$m["h1s"]) . "] switches={$m["switches"]} min_gap={$m["min_gap"]} max_gap={$m["max_gap"]} kickoff_hour={$m["kickoff_hour"]} kickoff_minute={$m["kickoff_minute"]}\n";
}
