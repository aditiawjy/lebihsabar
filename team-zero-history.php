<?php
// Shared market definitions keep daily counts and historical records consistent.
function teamDailyMarkets(): array {
    static $markets;
    return $markets ?? ($markets = json_decode(file_get_contents(__DIR__.'/team-markets.json'),true,512,JSON_THROW_ON_ERROR));
}
// Calendar-day records, using the same UTC+8 boundary as the daily table.
function teamZeroHistory(string $csvFile, array $snapshotFiles, string $metric = 'zero'): array {
    $market = teamDailyMarkets()[$metric] ?? null;
    if (!$market) throw new InvalidArgumentException('Pilihan statistik tidak valid.');
    $qualifies = static fn($home,$away) => $market['comparison'] === 'over' ? $home + $away >= $market['goals'] : $home + $away <= $market['goals'];
    $names = [
        'vietnam15'=>'SABA VIETNAM V LEAGUE 1 Virtual PES 24 - 15 Mins Play',
        '15'=>'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play',
        '16'=>'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play',
        '20'=>'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play',
        'f24-club'=>'E-FOOTBALL F24 ELITE CLUB FRIENDLY - 16 Mins Play',
        'f24-international'=>'E-FOOTBALL F24 INTERNATIONAL FRIENDLY - 16 Mins Play',
        'f26-club'=>'E-FOOTBALL F26 ELITE CLUB FRIENDLY - 16 Mins Play',
        'f26-international'=>'E-FOOTBALL F26 INTERNATIONAL FRIENDLY - 16 Mins Play',
    ];
    $ids = ['10031619'=>'vietnam15','10026214'=>'15','10031403'=>'16','10026211'=>'20','10031501'=>'f24-club',
        '10031502'=>'f24-international','10034432'=>'f26-club','10034496'=>'f26-international'];
    $nameIds = array_flip($names);
    $zone = new DateTimeZone('Asia/Singapore');
    $clean = static fn($value) => trim(preg_replace('/\s+/', ' ', preg_replace('/\s*(?:\(V\)|\[V\])/i', '', $value)));
    $fixtures = [];
    $add = static function($league, $time, $home, $away, $zero) use (&$fixtures, $clean, $zone) {
        $league = (string)$league;
        $home = $clean($home); $away = $clean($away);
        if ($home === '' || $away === '' || !$time) return;
        try { $when = (new DateTimeImmutable($time, $zone))->setTimezone($zone); }
        catch (Exception $e) { return; }
        $identity = json_encode([$league,$when->format('Y-m-d H:i:s'),strtolower($home),strtolower($away)]);
        // API archives override CSV scores and repeated snapshots of the same fixture.
        if (!$zero) { unset($fixtures[$identity]); return; }
        $fixtures[$identity] = ['league'=>(string)$league,'date'=>$when->format('Y-m-d'),'teams'=>[$home,$away]];
    };
    $handle = is_readable($csvFile) ? fopen($csvFile, 'r') : false;
    if ($handle) {
        flock($handle, LOCK_SH);
        $header = fgetcsv($handle);
        if ($header) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($header)) continue;
                $row = array_combine($header, $values);
                $leagueName = trim($row['league'] ?? '');
                $league = $nameIds[$leagueName] ?? (stripos($leagueName, 'V-Soccer ') === 0 ? 'vsoccer:'.$leagueName : null);
                if ($league === null || !ctype_digit($row['ft_home'] ?? '') || !ctype_digit($row['ft_away'] ?? '')) continue;
                $homeColumn = $market['period'] === 'HT' ? 'fh_home' : 'ft_home';
                $awayColumn = $market['period'] === 'HT' ? 'fh_away' : 'ft_away';
                if (!ctype_digit($row[$homeColumn] ?? '') || !ctype_digit($row[$awayColumn] ?? '')) continue;
                if ($market['period'] === 'HT' && ((int)$row[$homeColumn] > (int)$row['ft_home'] || (int)$row[$awayColumn] > (int)$row['ft_away'])) continue;
                $offset = $row['source_utc_offset'] ?? '';
                $time = $row['match_time'] ?? '';
                if (preg_match('/^[+-]\d{2}:\d{2}$/D', $offset) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $time)) $time .= $offset;
                $add($league,$time,$row['home_team'] ?? '',$row['away_team'] ?? '',$qualifies((int)$row[$homeColumn],(int)$row[$awayColumn]));
            }
        }
        flock($handle, LOCK_UN); fclose($handle);
    }
    // Read older copies first so the latest snapshot wins when scores are corrected.
    usort($snapshotFiles, static fn($a,$b) => filemtime($a) <=> filemtime($b) ?: strcmp($a,$b));
    foreach ($snapshotFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        foreach ($data['data'] ?? [] as $row) {
            $league = $ids[$row['events'] ?? ''] ?? null;
            if ($league === null || ($row['st_fs'] ?? '') !== 'Y' || ($row['st_postponed'] ?? '') !== 'N') continue;
            if (!preg_match('/^(\d+):(\d+)_(\d+):(\d+)$/D', $row['fs_home'] ?? '', $score)) continue;
            if ($market['period'] === 'HT' && (($row['st_hs'] ?? '') !== 'Y' || (int)$score[1] > (int)$score[3] || (int)$score[2] > (int)$score[4])) continue;
            $home = $market['period'] === 'HT' ? (int)$score[1] : (int)$score[3];
            $away = $market['period'] === 'HT' ? (int)$score[2] : (int)$score[4];
            $add($league,$row['match_date'] ?? '',$row['home_name'] ?? '',$row['away_name'] ?? '',$qualifies($home,$away));
        }
    }
    $days = []; $labels = [];
    foreach ($fixtures as $fixture) foreach ($fixture['teams'] as $name) {
        $key = json_encode([$fixture['league'],strtolower($name)]);
        $labels[$key] = ['league'=>$fixture['league'],'name'=>$name];
        $date = $fixture['date'];
        $days[$key][$date] = ($days[$key][$date] ?? 0) + 1;
    }
    $records = [];
    foreach ($days as $key=>$counts) {
        $max = max($counts);
        $dates = array_keys(array_filter($counts, static fn($count) => $count === $max));
        sort($dates, SORT_STRING);
        $records[] = array_merge($labels[$key], ['max'=>$max,'dates'=>$dates]);
    }
    return ['records'=>$records,'daily'=>$days,'source'=>'matches.csv dan arsip API tersimpan','timezone'=>'UTC+8'];
}
function teamZeroHistoryCached(string $csvFile, array $snapshotFiles, string $metric = 'zero'): array {
    $versions = [];
    foreach (array_merge([$csvFile], $snapshotFiles) as $file) if (is_file($file)) $versions[$file] = [filemtime($file),filesize($file)];
    ksort($versions);
    $version = hash('sha256', '6:'.$metric.':'.json_encode(teamDailyMarkets()[$metric] ?? null).':'.json_encode($versions));
    $cache = sys_get_temp_dir().'/sabaraja-team-zero-'.md5($csvFile.'|'.$metric).'.json';
    $stored = is_file($cache) ? json_decode(file_get_contents($cache),true) : null;
    if (($stored['version'] ?? '') === $version && isset($stored['data']['daily'])) return $stored['data'];
    $data = teamZeroHistory($csvFile,$snapshotFiles,$metric);
    $temporary = tempnam(sys_get_temp_dir(),'zero-history-');
    if ($temporary !== false) {
        file_put_contents($temporary,json_encode(['version'=>$version,'data'=>$data]),LOCK_EX);
        if (!@rename($temporary,$cache)) @unlink($temporary);
    }
    return $data;
}
if (defined('TEAM_ZERO_HISTORY_LIBRARY')) return;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$local = getenv('LOCALAPPDATA') ?: ((getenv('USERPROFILE') ?: '') . '/AppData/Local');
$directory = getenv('SABARAJA_RESULTS_DIR') ?: $local . '/Sabaraja/results';
$metric = $_GET['metric'] ?? 'zero';
if (!isset(teamDailyMarkets()[$metric])) { http_response_code(400); echo json_encode(['error'=>'Pilihan statistik tidak valid.']); exit; }
$history = teamZeroHistoryCached(__DIR__.'/matches.csv', glob($directory.'/msports-????-??-??.json') ?: [], $metric);
$history['metric'] = $metric;
unset($history['daily']);
echo json_encode($history, JSON_UNESCAPED_UNICODE);
