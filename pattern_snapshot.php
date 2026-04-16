<?php
// Simpan atau ambil snapshot akurasi pattern per jam
// Dipanggil oleh dashboard.php

date_default_timezone_set('Asia/Jakarta');
$snapshotFile = __DIR__ . '/pattern_snapshots.json';

function getSnapshotBucketTime(int $timestamp): int {
    return (int)(floor($timestamp / 300) * 300);
}

function normalizeSnapshots(array $snapshots): array {
    $normalized = [];

    foreach ($snapshots as $snapshot) {
        if (!is_array($snapshot) || !isset($snapshot['time'], $snapshot['data'])) {
            continue;
        }

        $bucketTime = getSnapshotBucketTime((int)$snapshot['time']);
        $normalized[$bucketTime] = [
            'time' => $bucketTime,
            'data' => $snapshot['data'],
        ];
    }

    ksort($normalized);
    return array_values($normalized);
}

function saveSnapshot($patterns_data, ?int $now = null) {
    global $snapshotFile;
    $now = $now ?? time();
    $bucketTime = getSnapshotBucketTime($now);
    $existing = [];
    if (file_exists($snapshotFile)) {
        $existing = json_decode(file_get_contents($snapshotFile), true) ?: [];
    }

    $existing = normalizeSnapshots($existing);

    // Simpan/replace snapshot per bucket 5 menit agar stabil saat refresh.
    $existing[] = ['time' => $bucketTime, 'data' => $patterns_data];
    $existing = normalizeSnapshots($existing);

    // Hapus snapshot lebih dari 2 jam
    $existing = array_filter($existing, fn($s) => ($bucketTime - $s['time']) <= 7200);
    $existing = array_values($existing);
    file_put_contents($snapshotFile, json_encode($existing));
}

function getSnapshotHourAgo(?int $now = null) {
    global $snapshotFile;
    if (!file_exists($snapshotFile)) return null;
    $existing = normalizeSnapshots(json_decode(file_get_contents($snapshotFile), true) ?: []);
    if (!$existing) return null;
    $now = $now ?? time();
    $target = $now - 3600; // target 1 jam lalu

    // Ambil snapshot yang masih relevan dalam 2 jam terakhir dan bukan snapshot yang terlalu baru.
    $candidates = array_values(array_filter(
        $existing,
        fn($s) => isset($s['time']) && ($now - $s['time']) >= 300 && ($now - $s['time']) <= 7200
    ));

    if ($candidates) {
        // Pilih snapshot yang paling dekat ke target 1 jam lalu.
        usort($candidates, function($a, $b) use ($target) {
            $diffA = abs($a['time'] - $target);
            $diffB = abs($b['time'] - $target);
            if ($diffA !== $diffB) return $diffA <=> $diffB;
            return $a['time'] <=> $b['time'];
        });

        $best = $candidates[0];
        // Jangan tampilkan kalau snapshot terdekat masih terlalu jauh dari target 1 jam lalu.
        if (abs($best['time'] - $target) <= 1800) {
            return $best;
        }
    }

    return null;
}
