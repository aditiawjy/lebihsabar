<?php
// Simpan atau ambil snapshot akurasi pattern per jam
// Dipanggil oleh dashboard.php

date_default_timezone_set('Asia/Jakarta');
$snapshotFile = __DIR__ . '/pattern_snapshots.json';

function saveSnapshot($patterns_data, ?int $now = null) {
    global $snapshotFile;
    $now = $now ?? time();
    $existing = [];
    if (file_exists($snapshotFile)) {
        $existing = json_decode(file_get_contents($snapshotFile), true) ?: [];
    }
    // Simpan snapshot baru
    $snapshot = ['time' => $now, 'data' => $patterns_data];
    $existing[] = $snapshot;
    // Hapus snapshot lebih dari 2 jam
    $existing = array_filter($existing, fn($s) => ($now - $s['time']) <= 7200);
    $existing = array_values($existing);
    file_put_contents($snapshotFile, json_encode($existing));
}

function getSnapshotHourAgo(?int $now = null) {
    global $snapshotFile;
    if (!file_exists($snapshotFile)) return null;
    $existing = json_decode(file_get_contents($snapshotFile), true) ?: [];
    if (!$existing) return null;
    $now = $now ?? time();
    $target = $now - 3600; // 1 jam lalu
    // Pakai snapshot terbaru yang tidak lebih baru dari target 1 jam lalu.
    // Jika belum ada history yang cukup tua, jangan tampilkan range agar tidak menyesatkan.
    $candidates = array_values(array_filter($existing, fn($s) => $s['time'] <= $target));
    if ($candidates) {
        usort($candidates, fn($a, $b) => $b['time'] <=> $a['time']);
        $best = $candidates[0];
        // Batasi maksimum 2 jam agar perbandingan tetap relevan.
        if (($now - $best['time']) <= 7200) {
            return $best;
        }
    }
    return null;
}
