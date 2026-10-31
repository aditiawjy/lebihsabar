<?php
function patternNoGoalThresholds(?string $csvFile = null, string $league = '15'): array {
    $leagues=['15'=>'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play','16'=>'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play','20'=>'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play'];
    if(!isset($leagues[$league]))return [];
    $file = $csvFile ?? __DIR__.'/matches.csv';
    if (!is_file($file)) return [];
    clearstatcache(true,$file);
    $version = '3:'.$league.':'.filemtime($file).':'.filesize($file);
    $cache = sys_get_temp_dir().'/sabaraja-no-goal-'.md5($file.'|'.$league).'.json';
    $stored = is_file($cache) ? json_decode(file_get_contents($cache),true) : null;
    if (($stored['version'] ?? null)===$version && is_array($stored['thresholds'] ?? null)) return $stored['thresholds'];
    $handle=fopen($file,'r');if (!$handle) return [];
    $header=fgetcsv($handle);if (!is_array($header)) {fclose($handle);return [];}
    $header[0]=ltrim($header[0],"\xEF\xBB\xBF");
    $rows=[];$seen=[];
    $clean=static fn($s)=>trim(preg_replace('/\s+/',' ',preg_replace('/\s*\(V\)/i','',$s)));
    while (($values=fgetcsv($handle))!==false) {
        if(count($values)!==count($header))continue;
        $r=array_combine($header,$values);
        if(($r['league'] ?? '')!==$leagues[$league] || !ctype_digit($r['ft_home'] ?? '') || !ctype_digit($r['ft_away'] ?? ''))continue;
        $home=$clean($r['home_team']);$away=$clean($r['away_team']);
        $key=$r['match_time'].'|'.strtolower($home).'|'.strtolower($away);
        if(isset($seen[$key]))continue;$seen[$key]=true;
        $period=substr($r['match_time'],0,10);
        if($league==='16') {
            $minute=(int)substr($r['match_time'],11,2)*60+(int)substr($r['match_time'],14,2);
            if($minute>45 && $minute<720)continue;
            if($minute<=45)$period=(new DateTimeImmutable($period))->modify('-1 day')->format('Y-m-d');
        }
        $rows[]=['time'=>$r['match_time'],'period'=>$period,'id'=>(int)$r['id'],'teams'=>[$home,$away],'scores'=>[(int)$r['ft_home'],(int)$r['ft_away']]];
    }
    fclose($handle);
    usort($rows,static fn($a,$b)=>strcmp($a['time'],$b['time']) ?: $a['id']<=>$b['id']);
    $runs=[];$days=[];$maxima=[];
    foreach($rows as $r)foreach($r['teams'] as $side=>$team){
        if($team==='')continue;$day=$r['period'];
        if(($days[$team] ?? null)!==$day)$runs[$team]=0;
        $days[$team]=$day;
        $runs[$team]=$r['scores'][$side]===0?($runs[$team] ?? 0)+1:0;
        $maxima[$team]=max($maxima[$team] ?? 0,$runs[$team]);
    }
    $maxima=array_filter($maxima,static fn($n)=>$n>0);ksort($maxima);
    // Concurrent API requests must never read a half-written cache file.
    $temporary=tempnam(sys_get_temp_dir(),'sabaraja-ng-');
    if($temporary!==false){file_put_contents($temporary,json_encode(['version'=>$version,'thresholds'=>$maxima]),LOCK_EX);if(!@rename($temporary,$cache))@unlink($temporary);}
    return $maxima;
}
