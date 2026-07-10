<?php
// Endpoint ringan untuk autocomplete nama club di halaman clubs.
// Daftar nama di-cache per versi matches.csv (mtime+size) agar CSV 22MB
// hanya di-scan sekali setelah setiap update data.
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q === '' || mb_strlen($q) > 100) {
    echo '[]';
    exit;
}

$csvPath = __DIR__ . '/matches.csv';
if (!is_file($csvPath) || !is_readable($csvPath)) {
    echo '[]';
    exit;
}

$cacheDir = __DIR__ . '/cache/clubs';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/clubnames_' . md5(filemtime($csvPath) . '|' . filesize($csvPath)) . '.json';

$names = null;
if (is_file($cacheFile)) {
    $decoded = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($decoded)) {
        $names = $decoded;
    }
}

if ($names === null) {
    $names = [];
    $set = [];
    if (($fh = fopen($csvPath, 'r')) !== false) {
        $hdrs = fgetcsv($fh);
        if (is_array($hdrs)) {
            $homeIdx = array_search('home_team', $hdrs, true);
            $awayIdx = array_search('away_team', $hdrs, true);
            if ($homeIdx !== false && $awayIdx !== false) {
                while (($row = fgetcsv($fh)) !== false) {
                    $home = trim($row[$homeIdx] ?? '');
                    $away = trim($row[$awayIdx] ?? '');
                    if ($home !== '') $set[$home] = true;
                    if ($away !== '') $set[$away] = true;
                }
            }
        }
        fclose($fh);
    }
    $names = array_keys($set);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    @file_put_contents($cacheFile, json_encode($names, JSON_UNESCAPED_UNICODE), LOCK_EX);
    // Bersihkan cache nama dari versi CSV lama
    foreach ((glob($cacheDir . '/clubnames_*.json') ?: []) as $old) {
        if ($old !== $cacheFile) {
            @unlink($old);
        }
    }
}

$qLower = mb_strtolower($q, 'UTF-8');
$matches = [];
foreach ($names as $name) {
    if (mb_strpos(mb_strtolower($name, 'UTF-8'), $qLower) !== false) {
        $matches[] = $name;
        if (count($matches) >= 10) {
            break;
        }
    }
}

echo json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
