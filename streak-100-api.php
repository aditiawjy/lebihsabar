<?php
/**
 * streak-100-api.php — data streak untuk badge "Peluang 100%" di vsoccer-live.php.
 *
 * Mengembalikan baris payload streak HANYA untuk tim yang sedang bertanding,
 * supaya ringan (payload penuh 6 MB; subset tim live biasanya < 200 KB).
 * Penyaringan 100%-nya sendiri dilakukan di browser lewat assets/streak100.js,
 * jadi aturannya persis sama dengan tabel di index.php?page=streak.
 *
 * PENTING: endpoint ini TIDAK PERNAH membangun ulang cache. Build cache streak
 * butuh memory_limit 2 GB + ratusan detik; kalau halaman live memicunya tiap
 * beberapa detik, server bisa OOM. Kalau cache belum ada, endpoint menjawab
 * ok:false dan meminta halaman streak dibuka sekali untuk membangunnya.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Jakarta');

function keluar(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$cacheDir = __DIR__ . '/cache/streak';
$files = is_dir($cacheDir) ? glob($cacheDir . '/*.cache') : [];
if (!$files) {
    keluar(['ok' => false, 'reason' => 'Cache streak belum ada. Buka index.php?page=streak sekali untuk membangunnya.']);
}

// Pakai cache TERBARU yang sudah ada. Sengaja tidak memakai kunci cache milik
// streak-analysis.php: kalau matches.csv baru bertambah, kunci itu meleset dan
// kita akan terpancing membangun ulang — mahal. Data agak lama masih berguna,
// umurnya dilaporkan lewat "age_min" supaya halaman live bisa menandainya.
usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
$cacheFile = $files[0];

$raw = @file_get_contents($cacheFile);
$payload = $raw !== false && $raw !== '' ? @unserialize($raw, ['allowed_classes' => false]) : null;
if (!is_array($payload) || !isset($payload['rows'])) {
    keluar(['ok' => false, 'reason' => 'Cache streak tidak terbaca.']);
}

// Daftar tim: dari ?teams=A|B|C, atau (default) tim yang sedang main di snapshot live.
$teams = [];
if (isset($_GET['teams']) && $_GET['teams'] !== '') {
    $teams = array_values(array_filter(array_map('trim', explode('|', (string)$_GET['teams']))));
} else {
    $live = @json_decode((string)@file_get_contents(__DIR__ . '/vsoccer_live.json'), true);
    foreach (($live['matches'] ?? []) as $m) {
        if (!empty($m['home'])) $teams[] = trim($m['home']);
        if (!empty($m['away'])) $teams[] = trim($m['away']);
    }
    $teams = array_values(array_unique($teams));
}

$wanted = array_flip($teams);
$rows = [];
$ligas = [];
foreach ($payload['rows'] as $r) {
    if (!isset($r['t'], $wanted[$r['t']])) continue;
    if (strpos((string)($r['l'] ?? ''), 'V-Soccer') === false) continue;   // badge live khusus V-Soccer
    $rows[] = $r;
    $ligas[$r['l']] = true;
}

// Baseline hanya untuk liga yang terpakai, supaya balasan tetap kecil.
$baseOutLg = [];
foreach (array_keys($ligas) as $lg) {
    if (isset($payload['baseOutLg'][$lg])) $baseOutLg[$lg] = $payload['baseOutLg'][$lg];
}

keluar([
    'ok' => true,
    'builtAt' => $payload['builtAt'] ?? '',
    'age_min' => (int)round((time() - filemtime($cacheFile)) / 60),
    'teams_asked' => count($teams),
    'rows' => $rows,
    'baseOut' => $payload['baseOut'] ?? [],
    'baseOutLg' => $baseOutLg,
]);
