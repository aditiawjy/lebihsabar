<?php
require __DIR__ . '/dashboard_cache.php';

$csvFile = __DIR__ . '/goal_log.csv';
$matches = [];
$fh = fopen($csvFile, 'r');
fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 7) continue;
    $row = array_pad($row, 10, '');
    if (trim($row[8] ?? '') !== 'OK' || trim($row[9] ?? '') !== 'OK') continue;
    $goalsStr = trim($row[4] ?? '');
    if ($goalsStr === '' && (int)($row[5] ?? 0) === 0 && (int)($row[6] ?? 0) === 0) continue;
    $matches[] = $row;
}
fclose($fh);
$parsed = parseMatches($matches);
$patterns = computePatterns($parsed);

// Candidate signatures
function sigA($m){ return $m['league']==='16min' && $m['h1_first']===1 && $m['h1_last']===6 && $m['h1s']===['A','H'] && $m['sc_h']===1 && $m['sc_a']===1; } // ambiguous (AH 1-1 1-6)
function sigB($m){ return $m['league']==='16min' && $m['h1_first']===4 && $m['h1_last']===7 && $m['h1s']===['A','H','A'] && $m['sc_h']===1 && $m['sc_a']===2; } // AHA 1-2 4-7
function sigC($m){ return $m['league']==='20min' && $m['h1_first']===0 && $m['h1_last']===2 && $m['h1s']===['A','A'] && $m['sc_h']===0 && $m['sc_a']===2; } // AA 0-2 0-2

// Debug sigC against P83 miss
foreach ($patterns as $p) {
    if ($p['id']!=='P83') continue;
    foreach ($p['data'] as $m) {
        if ($m['h2c']>0) continue;
        if ($m['league']==='20min' && $m['sc_h']===0 && $m['sc_a']===2) {
            echo "P83 MISS DETAIL: ".$m['home']." vs ".$m['away']." | ".sig($m)." | h1s_raw=[".implode(',',$m['h1s'])."]\n";
        }
    }
}
echo "\n";

// Verify resulting accuracy with proposed exclusions
$res = [];
foreach ($patterns as $p) {
    if (!in_array($p['id'], ['P81','P82','P83'])) continue;
    $d = $p['data'];
    if ($p['id']==='P81') $d = array_filter($d, fn($m)=>!sigA($m));
    if ($p['id']==='P82') $d = array_filter($d, fn($m)=>!sigA($m) && !sigB($m));
    if ($p['id']==='P83') $d = array_filter($d, fn($m)=>!sigA($m) && !sigB($m) && !sigC($m));
    $d = array_values($d);
    $t=count($d); $h=count(array_filter($d, fn($m)=>$m['h2c']>0));
    echo "PROPOSED {$p['id']}: total=$t hits=$h => ".($t?round($h/$t*100,1):0)."%\n";
}
echo "\n";

foreach ($patterns as $p) {
    if (!in_array($p['id'], ['P81','P82','P83'])) continue;
    foreach (['A'=>'sigA','B'=>'sigB','C'=>'sigC'] as $name=>$fn) {
        $grp = array_values(array_filter($p['data'], $fn));
        if (count($grp)===0) continue;
        $gh = count(array_filter($grp, fn($m)=>$m['h2c']>0));
        $gm = count($grp)-$gh;
        echo "{$p['id']} sig$name: in-pattern n=".count($grp)." hits=$gh misses=$gm ".($gm>0 && $gh===0 ? "[CLEAN-miss-only]" : ($gh>0&&$gm>0?"[AMBIGUOUS]":"[hits-only]"))."\n";
    }
}
echo "\n";


function sig($m){
    return sprintf("lg=%s first=%d last=%d h1c=%d sc=%d-%d run=%d maxgap=%d mingap=%d sw=%d h1s=%s",
        $m['league'], $m['h1_first'], $m['h1_last'], $m['h1c'], $m['sc_h'], $m['sc_a'],
        $m['max_run'], $m['max_gap'], $m['min_gap'], $m['switches'], implode('', $m['h1s']));
}

$targets = ['P81','P82','P83'];
foreach ($patterns as $p) {
    if (!in_array($p['id'], $targets)) continue;
    $data = $p['data'];
    $t = count($data);
    $h = count(array_filter($data, fn($m) => $m['h2c'] > 0));
    echo "=== {$p['id']} total=$t hits=$h misses=".($t-$h)." => ".($t?round($h/$t*100,1):0)."% ===\n";
    echo "--- MISSES ---\n";
    foreach ($data as $m) {
        if ($m['h2c'] > 0) continue;
        echo "  ".$m['datetime']." | ".$m['home']." vs ".$m['away']." | ".sig($m)."\n";
    }
    echo "\n";
}
