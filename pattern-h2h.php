<?php
function patternH2hKey($home, $away) {
    $teams = array_map(static fn($name) => strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\(V\)/i', '', $name)))), [$home,$away]);
    sort($teams, SORT_STRING);
    return implode('|', $teams);
}
function patternH2hLatestBefore(array $stats, string $before): ?array {
    $latest = null;
    foreach ($stats['meetings'] ?? [] as $meeting) {
        if ($meeting['time'] < $before && ($latest === null || $meeting['time'] > $latest['time'])) $latest = $meeting;
    }
    return $latest;
}
function patternH2hRecentBefore(array $stats, string $before, int $limit = 2): array {
    $byTime = [];
    foreach ($stats['meetings'] ?? [] as $meeting) {
        $time = $meeting['time'] ?? '';
        if ($time === '' || $time >= $before) continue;
        if (isset($byTime[$time]) && $byTime[$time]['totalGoals'] !== ($meeting['totalGoals'] ?? null)) {
            // Conflicting duplicates cannot establish a reliable result.
            $byTime[$time]['totalGoals'] = null;
            $byTime[$time]['ft'] = 'hasil berbeda';
        } elseif (!isset($byTime[$time])) $byTime[$time] = $meeting;
    }
    krsort($byTime, SORT_STRING);
    return array_slice(array_values($byTime), 0, $limit);
}
function patternH2hStats() {
    $file = __DIR__.'/matches.csv';
    $cache = sys_get_temp_dir().'/sabaraja-h2h-'.md5(__DIR__).'.json';
    clearstatcache(true, $file);
    $version = '8:'.filemtime($file).':'.filesize($file);
    $stored = is_file($cache) ? json_decode(file_get_contents($cache), true) : null;
    if (($stored['version'] ?? null) === $version) return $stored['stats'];
    $stats = []; $seen = [];
    $handle = @fopen($file,'r');
    if (!$handle) return [];
    if (!flock($handle, LOCK_SH)) { fclose($handle); return []; }
    $header = fgetcsv($handle); $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
    while (($values = fgetcsv($handle)) !== false) {
        if (count($values) !== count($header)) continue;
        $row = array_combine($header,$values);
        if (!ctype_digit($row['ft_home']) || !ctype_digit($row['ft_away'])) continue;
        $identity = implode('|',[$row['league'],$row['match_time'],$row['home_team'],$row['away_team']]);
        if (isset($seen[$identity])) continue;
        $seen[$identity] = true;
        $key = $row['league'].'|'.patternH2hKey($row['home_team'],$row['away_team']);
        if (!isset($stats[$key])) $stats[$key] = ['total'=>0,'over'=>0,'over05'=>0,'markets'=>[]];
        $stats[$key]['total']++;
        $ft = (int)$row['ft_home']+(int)$row['ft_away'];
        $meetingTime = null;
        if (!empty($row['match_time']) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D', $row['match_time'])) {
            try {
                $offset = $row['source_utc_offset'] ?? '';
                $rawTime = $row['match_time'];
                if (preg_match('/^[+-]\d{2}:\d{2}$/D',$offset) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$rawTime)) $rawTime .= $offset;
                $zone = new DateTimeZone('Asia/Singapore');
                $meetingTime = (new DateTimeImmutable($rawTime,$zone))->setTimezone($zone)->format('c');
                $validHt = ctype_digit($row['fh_home']) && ctype_digit($row['fh_away']) && (int)$row['fh_home'] <= (int)$row['ft_home'] && (int)$row['fh_away'] <= (int)$row['ft_away'];
                $stats[$key]['meetings'][] = ['time'=>$meetingTime,'home'=>trim(preg_replace('/\s*\(V\)/i','',$row['home_team'])),'away'=>trim(preg_replace('/\s*\(V\)/i','',$row['away_team'])),'ft'=>$row['ft_home'].'–'.$row['ft_away'],'totalGoals'=>$ft,'htHome'=>$validHt ? (int)$row['fh_home'] : null,'htAway'=>$validHt ? (int)$row['fh_away'] : null];
            } catch (Exception $e) { /* A row without a valid date cannot be the last meeting. */ }
        }
        $values = ['FT'=>$ft];
        if (ctype_digit($row['fh_home']) && ctype_digit($row['fh_away']) && (int)$row['fh_home'] <= (int)$row['ft_home'] && (int)$row['fh_away'] <= (int)$row['ft_away']) {
            $ht = (int)$row['fh_home']+(int)$row['fh_away'];
            $values['HT']=$ht; $values['2H']=$ft-$ht;
        }
        foreach ($values as $period=>$goals) foreach ($period === 'FT' ? [0.5,1.5,3.5,4.5,5.5] : [0.5] as $line) {
            foreach ($period === 'FT' && $line >= 3.5 ? ['O','U'] : ['O'] as $side) {
                $market = $period.' '.$side.$line;
                if (!isset($stats[$key]['markets'][$market])) $stats[$key]['markets'][$market]=['total'=>0,'wins'=>0,'period'=>$period,'side'=>$side,'line'=>$line];
                $stats[$key]['markets'][$market]['total']++;
                if ($side === 'O' ? $goals > $line : $goals < $line) $stats[$key]['markets'][$market]['wins']++;
                if ($meetingTime !== null) {
                    $day = substr($meetingTime,0,10);
                    $entry =& $stats[$key]['markets'][$market];
                    $entry['firstDate'] = min($entry['firstDate'] ?? $day, $day);
                    $entry['lastDate'] = max($entry['lastDate'] ?? $day, $day);
                    unset($entry);
                } else {
                    $stats[$key]['markets'][$market]['undated'] = ($stats[$key]['markets'][$market]['undated'] ?? 0) + 1;
                }
            }
        }
        if ((int)$row['ft_home']+(int)$row['ft_away'] >= 1) $stats[$key]['over05']++;
        if ((int)$row['ft_home']+(int)$row['ft_away'] >= 2) $stats[$key]['over']++;
    }
    $complete = feof($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    clearstatcache(true, $file);
    if (!$complete || $version !== '8:'.filemtime($file).':'.filesize($file)) return [];
    $temporary = tempnam(sys_get_temp_dir(), 'sabaraja-h2h-');
    if ($temporary !== false) {
        if (file_put_contents($temporary,json_encode(['version'=>$version,'stats'=>$stats]),LOCK_EX) === false || !@rename($temporary,$cache)) @unlink($temporary);
    }
    return $stats;
}
