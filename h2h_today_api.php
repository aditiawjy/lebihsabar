<?php
/**
 * h2h_today_api.php
 * Endpoint JSON untuk notifikasi Telegram (dipanggil chrome_extension/background.js).
 *
 * Mengembalikan daftar match HARI INI (belum dimainkan) yang punya rekor H2H
 * Over 0.5 kuat berdasarkan matches.csv — logika sama dengan h2h-over05.php.
 *
 * Query params (semua opsional):
 *   min  = minimal jumlah pertemuan H2H (default 10)
 *   pct  = ambang persentase minimal (default 80)
 *   mkt  = 'over05' (default) atau 'shg05'
 *
 * Contoh: http://localhost/lebihsabar/h2h_today_api.php?min=10&pct=80
 */
date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', '0'); // cegah warning PHP mencemari output JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$csvPath = __DIR__ . '/matches.csv';
$today   = date('Y-m-d');
$nowHM   = date('H:i');

$minMatches = max(1, (int)($_GET['min'] ?? 10));
$minPct     = max(0, min(100, (float)($_GET['pct'] ?? 80)));
$mkt        = ($_GET['mkt'] ?? 'over05') === 'shg05' ? 'shg05' : 'over05';

function h2hKey(string $a, string $b): string {
    $t = [$a, $b];
    sort($t);
    return implode(' vs ', $t);
}

$stats     = []; // key => [total, hits, last_date, last_score]
$nextToday = []; // key => earliest upcoming match HARI INI

if (is_readable($csvPath) && ($fh = fopen($csvPath, 'r')) !== false) {
    $hdr = fgetcsv($fh);
    if (is_array($hdr)) {
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($hdr)) continue;
            $r = @array_combine($hdr, $row);
            if (!$r) continue;
            $home = trim($r['home_team'] ?? '');
            $away = trim($r['away_team'] ?? '');
            if ($home === '' || $away === '') continue;
            $league = trim($r['league'] ?? '');
            $dt     = $r['match_time'] ?? '';
            $date   = substr($dt, 0, 10);
            $time   = substr($dt, 11, 5);
            $fth = $r['ft_home'] ?? ''; $fta = $r['ft_away'] ?? '';
            $fhh = $r['fh_home'] ?? ''; $fha = $r['fh_away'] ?? '';
            $played = !($fth === '' || $fta === '' || !is_numeric($fth) || !is_numeric($fta));
            $key = h2hKey($home, $away);

            if (!$played) {
                // Kandidat next match: hanya HARI INI & belum lewat jam kickoff
                if ($date === $today && $time >= $nowHM) {
                    if (!isset($nextToday[$key]) || $time < $nextToday[$key]['time']) {
                        $nextToday[$key] = [
                            'home' => $home, 'away' => $away,
                            'league' => $league, 'date' => $date, 'time' => $time,
                        ];
                    }
                }
                continue;
            }

            $ftH = (int)$fth; $ftA = (int)$fta;
            $fhH = is_numeric($fhh) ? (int)$fhh : 0;
            $fhA = is_numeric($fha) ? (int)$fha : 0;
            $isHit = $mkt === 'shg05'
                ? (($ftH - $fhH) + ($ftA - $fhA)) >= 1
                : ($ftH + $ftA) >= 1;

            if (!isset($stats[$key])) {
                $stats[$key] = ['total' => 0, 'hits' => 0, 'last_date' => '', 'last_score' => ''];
            }
            $stats[$key]['total']++;
            if ($isHit) $stats[$key]['hits']++;
            if ($date > $stats[$key]['last_date']) {
                $stats[$key]['last_date']  = $date;
                $stats[$key]['last_score'] = $home . ' ' . $ftH . '-' . $ftA . ' ' . $away;
            }
        }
    }
    fclose($fh);
}

// Gabungkan: hanya match hari ini yang memenuhi ambang
$out = [];
foreach ($nextToday as $key => $nx) {
    $s = $stats[$key] ?? null;
    if (!$s || $s['total'] < $minMatches) continue;
    $pct = round($s['hits'] / $s['total'] * 100, 1);
    if ($pct < $minPct) continue;
    $out[] = [
        'key'        => $key,
        'home'       => $nx['home'],
        'away'       => $nx['away'],
        'league'     => $nx['league'],
        'date'       => $nx['date'],
        'time'       => $nx['time'],
        'pct'        => $pct,
        'hits'       => $s['hits'],
        'total'      => $s['total'],
        'last_score' => $s['last_score'],
    ];
}

// Urut: kickoff terdekat dulu, lalu pct tertinggi
usort($out, function ($a, $b) {
    if ($a['time'] !== $b['time']) return strcmp($a['time'], $b['time']);
    return $b['pct'] <=> $a['pct'];
});

echo json_encode([
    'ok'      => true,
    'date'    => $today,
    'now'     => $nowHM,
    'market'  => $mkt,
    'min'     => $minMatches,
    'pct'     => $minPct,
    'count'   => count($out),
    'matches' => $out,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
