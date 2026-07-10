<?php
// Daftar lengkap nama klub untuk autocomplete di extension popup (Team target).
// Sumber: matches.csv + goal_log.csv (union), keduanya pakai penamaan "(V)" yang
// sama dengan situs scraper. Hasil di-cache; regenerasi bila salah satu CSV berubah.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$matchesCsv = __DIR__ . '/matches.csv';
$goalLogCsv = __DIR__ . '/goal_log.csv';
$cacheFile  = __DIR__ . '/cache/club_names.json';

$sig = [];
foreach ([$matchesCsv, $goalLogCsv] as $f) {
    $sig[] = is_file($f) ? (string)filemtime($f) . ':' . (string)filesize($f) : '0';
}
$signature = implode('|', $sig);

if (is_file($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['sig'] ?? null) === $signature) {
        echo json_encode(['ok' => true, 'count' => count($cached['teams']), 'teams' => $cached['teams']]);
        exit;
    }
}

// matches.csv: home_team idx 2, away_team idx 3. goal_log.csv: home_team idx 2, away_team idx 3.
$names = [];
foreach ([$matchesCsv, $goalLogCsv] as $f) {
    if (!is_file($f) || !is_readable($f)) continue;
    $fh = fopen($f, 'r');
    if ($fh === false) continue;
    fgetcsv($fh); // header
    while (($row = fgetcsv($fh)) !== false) {
        $h = isset($row[2]) ? trim((string)$row[2]) : '';
        $a = isset($row[3]) ? trim((string)$row[3]) : '';
        if ($h !== '') $names[$h] = true;
        if ($a !== '') $names[$a] = true;
    }
    fclose($fh);
}

$teams = array_keys($names);
sort($teams, SORT_NATURAL | SORT_FLAG_CASE);

if (is_dir(dirname($cacheFile)) || @mkdir(dirname($cacheFile), 0777, true)) {
    @file_put_contents($cacheFile, json_encode(['sig' => $signature, 'teams' => $teams]));
}

echo json_encode(['ok' => true, 'count' => count($teams), 'teams' => $teams]);
