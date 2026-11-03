<?php
function patternUnder15StreakThresholds(string $file, string $leagueName, bool $session, int $maxGoals = 1): array {
    if (!is_file($file)) return [];
    clearstatcache(true, $file);
    $version = '2:'.filemtime($file).':'.filesize($file).':'.(int)$session.':'.$maxGoals;
    $cache = sys_get_temp_dir().'/sabaraja-u15-streak-'.md5($file.'|'.$leagueName.'|'.$maxGoals).'.json';
    $cached = is_file($cache) ? json_decode(file_get_contents($cache), true) : null;
    if (($cached['version'] ?? '') === $version && is_array($cached['thresholds'] ?? null)) return $cached['thresholds'];
    $handle = @fopen($file, 'r'); if (!$handle) return [];
    flock($handle, LOCK_SH);
    $header = fgetcsv($handle); if (!$header) { fclose($handle); return []; }
    $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
    $rows=[]; $seen=[]; $zone=new DateTimeZone('Asia/Singapore');
    $clean=static fn($s)=>strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\(V\)/i', '', $s))));
    while (($values=fgetcsv($handle)) !== false) {
        if (count($values)!==count($header)) continue;
        $r=array_combine($header,$values);
        if (($r['league'] ?? '') !== $leagueName || !ctype_digit($r['ft_home'] ?? '') || !ctype_digit($r['ft_away'] ?? '')) continue;
        $home=$clean($r['home_team'] ?? ''); $away=$clean($r['away_team'] ?? '');
        if ($home==='' || $away==='') continue;
        try {
            $raw=$r['match_time'] ?? ''; $offset=$r['source_utc_offset'] ?? '';
            if (preg_match('/^[+-]\d{2}:\d{2}$/D',$offset) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$raw)) $raw.=$offset;
            $time=(new DateTimeImmutable($raw,$zone))->setTimezone($zone);
        } catch (Exception $e) { continue; }
        $minute=(int)$time->format('H')*60+(int)$time->format('i');
        if ($session && $minute>45 && $minute<720) continue;
        $period=($session && $minute<=45 ? $time->modify('-1 day') : $time)->format('Y-m-d');
        $identity=$time->format('c').'|'.$home.'|'.$away;
        if (isset($seen[$identity])) continue; $seen[$identity]=true;
        $rows[]=['time'=>$time->format('c'),'period'=>$period,'teams'=>[$home,$away],'under'=>(int)$r['ft_home']+(int)$r['ft_away']<=$maxGoals];
    }
    flock($handle,LOCK_UN); fclose($handle);
    usort($rows,static fn($a,$b)=>strcmp($a['time'],$b['time']));
    $runs=[]; $periods=[]; $maxima=[];
    foreach ($rows as $row) foreach ($row['teams'] as $team) {
        if (($periods[$team] ?? null)!==$row['period']) $runs[$team]=0;
        $periods[$team]=$row['period'];
        $runs[$team]=$row['under'] ? ($runs[$team] ?? 0)+1 : 0;
        $maxima[$team]=max($maxima[$team] ?? 0,$runs[$team]);
    }
    $maxima=array_filter($maxima,static fn($n)=>$n>0);
    $temp=tempnam(sys_get_temp_dir(),'u15-streak-');
    if ($temp!==false) { file_put_contents($temp,json_encode(['version'=>$version,'thresholds'=>$maxima]),LOCK_EX); if (!@rename($temp,$cache)) @unlink($temp); }
    return $maxima;
}
