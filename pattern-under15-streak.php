<?php
// CSV-only streaks, ordered across all days/sessions. Unknown FT breaks a run.
function patternCsvStreakStats(string $file, string $leagueName, string $before): array {
    $empty = ['under15'=>[], 'zero'=>[]];
    if (!is_file($file)) return $empty;
    clearstatcache(true, $file);
    $version = '3:'.filemtime($file).':'.filesize($file).':'.$before;
    $cache = sys_get_temp_dir().'/sabaraja-csv-streak-'.md5($file.'|'.$leagueName).'.json';
    $stored = is_file($cache) ? json_decode(file_get_contents($cache), true) : null;
    if (($stored['version'] ?? null) === $version) return $stored['data'];
    $fh = fopen($file, 'r'); if (!$fh) return $empty;
    if (!flock($fh, LOCK_SH)) { fclose($fh); return $empty; }
    $header = fgetcsv($fh); if (!$header) { flock($fh, LOCK_UN); fclose($fh); return $empty; }
    $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
    $zone = new DateTimeZone('Asia/Singapore'); $cutoff = new DateTimeImmutable($before);
    $clean = static fn($name) => trim(preg_replace('/\s+/', ' ', preg_replace('/\s*(?:\(V\)|\[V\])/i', '', $name)));
    $fixtures = [];
    while (($values = fgetcsv($fh)) !== false) {
        if (count($values) !== count($header)) continue;
        $r = array_combine($header, $values);
        if (($r['league'] ?? '') !== $leagueName) continue;
        try {
            $raw = $r['match_time'] ?? ''; if (!$raw) continue;
            if (preg_match('/^[+-]\d{2}:\d{2}$/D', $r['source_utc_offset'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $raw)) $raw .= $r['source_utc_offset'];
            $time = (new DateTimeImmutable($raw, $zone))->setTimezone($zone);
        } catch (Exception $e) { continue; }
        if ($time >= $cutoff) continue;
        $home = $clean($r['home_team'] ?? ''); $away = $clean($r['away_team'] ?? '');
        if (!$home || !$away) continue;
        $goals = ctype_digit($r['ft_home'] ?? '') && ctype_digit($r['ft_away'] ?? '') ? (int)$r['ft_home'] + (int)$r['ft_away'] : null;
        $id = $time->format('c').'|'.strtolower($home).'|'.strtolower($away);
        if (isset($fixtures[$id]) && $fixtures[$id]['goals'] !== $goals) $goals = null;
        $fixtures[$id] = ['time'=>$time->format('c'), 'teams'=>[$home,$away], 'goals'=>$goals];
    }
    flock($fh, LOCK_UN); fclose($fh);
    uasort($fixtures, static fn($a,$b) => strcmp($a['time'], $b['time']));
    $data = $empty;
    foreach ($fixtures as $row) foreach ($row['teams'] as $name) {
        $key = strtolower($name);
        foreach (['under15'=>1, 'zero'=>0] as $market=>$maximum) {
            $entry = $data[$market][$key] ?? ['name'=>$name, 'current'=>0, 'record'=>0, 'lastTime'=>null];
            $entry['current'] = $row['goals'] !== null && $row['goals'] <= $maximum ? $entry['current'] + 1 : 0;
            $entry['record'] = max($entry['record'], $entry['current']);
            $entry['lastTime'] = $row['time'];
            $data[$market][$key] = $entry;
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'csv-streak-');
    if ($tmp !== false) { file_put_contents($tmp, json_encode(['version'=>$version, 'data'=>$data]), LOCK_EX); if (!@rename($tmp, $cache)) @unlink($tmp); }
    return $data;
}
function patternUnder15StreakThresholds(string $file, string $leagueName, bool $session, int $maxGoals = 1): array {
    $stats = patternCsvStreakStats($file, $leagueName, '9999-12-31T00:00:00+08:00');
    return array_map(static fn($entry) => $entry['record'], $stats[$maxGoals === 0 ? 'zero' : 'under15']);
}
