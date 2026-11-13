<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$path = getenv('MATCHES_CSV_FILE') ?: __DIR__ . '/matches.csv';
if (isset($_GET['version'])) {
    echo json_encode(['version' => is_file($path) ? filemtime($path) . '-' . filesize($path) . '-' . filemtime(__DIR__ . '/patternsaba.js') : null]);
    exit;
}
if (!is_readable($path) || ($fh = @fopen($path, 'r')) === false) {
    http_response_code(404);
    echo json_encode(['error' => 'matches.csv belum tersedia. Simpan data melalui halaman parser terlebih dahulu.']);
    exit;
}
if (!flock($fh, LOCK_SH)) {
    fclose($fh);
    http_response_code(503);
    echo json_encode(['error'=>'Riwayat CSV sedang diperbarui. Coba kembali.']);
    exit;
}
$header = fgetcsv($fh);
$required = ['match_time', 'home_team', 'away_team', 'league', 'fh_home', 'fh_away', 'ft_home', 'ft_away'];
if (!$header || array_diff($required, $header)) {
    fclose($fh);
    http_response_code(422);
    echo json_encode(['error' => 'Kolom matches.csv tidak sesuai format parser.']);
    exit;
}
$rows = [];
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($header)) continue;
    $entry = array_combine($header, $row);
    $rows[] = array_intersect_key($entry, array_flip(array_merge($required, ['source_utc_offset'])));
}
fclose($fh);
echo json_encode(['rows' => $rows], JSON_INVALID_UTF8_SUBSTITUTE);
