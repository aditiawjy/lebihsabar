<?php
// Simpan atau ambil snapshot akurasi pattern per jam
// Dipanggil oleh dashboard.php

date_default_timezone_set('Asia/Jakarta');
$snapshotFile = __DIR__ . '/pattern_snapshots.json';

function saveSnapshot($patterns_data) {
    global $snapshotFile;
    $now = time();
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

function getSnapshotHourAgo() {
    global $snapshotFile;
    if (!file_exists($snapshotFile)) return null;
    $existing = json_decode(file_get_contents($snapshotFile), true) ?: [];
    if (!$existing) return null;
    $now = time();
    $target = $now - 3600; // 1 jam lalu
    // Ambil snapshot paling dekat dengan 1 jam lalu
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($existing as $s) {
        $diff = abs($s['time'] - $target);
        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $best = $s;
        }
    }
    // Hanya pakai jika minimal 5 menit lalu, max 90 menit
    if ($best && ($now - $best['time']) >= 300 && ($now - $best['time']) <= 5400) {
        return $best;
    }
    return null;
}
