<?php
/**
 * streak_kl2_api.php
 * Endpoint JSON untuk notifikasi Telegram market percobaan:
 *   "Kalah 2x beruntun -> Over 1.5"
 *
 * Mengembalikan daftar tim yang SAAT INI sedang kalah >=2x beruntun, punya
 * jadwal next match, dan rekor historis (setelah kalah 2x) Over 1.5 >= ambang.
 * Logika sama dengan halaman index.php?page=streak (mode kl_2 + output o15).
 *
 * Query params (opsional):
 *   mode = 'kl2'  -> Kalah 2x beruntun  (default)
 *          'u15x3' -> Under 1.5 3x beruntun
 *   pct  = ambang persentase Over 1.5 minimal (default 85)
 *   min  = minimal sampel (default 30)
 *
 * Contoh: http://localhost/lebihsabar/streak_kl2_api.php?mode=u15x3&pct=85&min=10
 */
date_default_timezone_set('Asia/Jakarta');
// Jangan biarkan warning PHP (mis. CSV sedang ditulis saat dibaca) mencemari JSON.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$csvPath = __DIR__ . '/matches.csv';
$today   = date('Y-m-d');
$nowStr  = date('Y-m-d H:i:s');

$mode      = in_array($_GET['mode'] ?? 'kl2', ['kl2', 'u15x3', 'dr2', 'dr3', 'u05x2'], true) ? $_GET['mode'] : 'kl2';
$streakLen = in_array($mode, ['u15x3', 'dr3'], true) ? 3 : 2; // panjang beruntun (u15x3/dr3=3, lainnya=2)
$outMkt    = ($_GET['out'] ?? 'o15') === 'o05' ? 'o05' : 'o15'; // hasil yg dihitung
$overMin   = $outMkt === 'o05' ? 1 : 2;          // Over 0.5 = total>=1, Over 1.5 = total>=2
$minPct    = max(0, min(100, (float)($_GET['pct'] ?? 85)));
$minSample = max(1, (int)($_GET['min'] ?? 30));

$team      = [];   // "tim|liga" => [ [sortkey, isLoss, total, isU15], ... ]
$nextMatch = [];   // "tim|liga" => ['vs'=>lawan, 'dt'=>match_time]

if (is_readable($csvPath) && ($fh = fopen($csvPath, 'r')) !== false) {
    $hdr = fgetcsv($fh);
    if (is_array($hdr)) {
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($hdr)) continue;
            $r = @array_combine($hdr, $row);
            if (!$r) continue;
            $h = trim($r['home_team'] ?? ''); $a = trim($r['away_team'] ?? '');
            if ($h === '' || $a === '') continue;
            $lg = trim($r['league'] ?? '');
            $sk = $r['match_time'] ?? '';
            $fth = $r['ft_home'] ?? ''; $fta = $r['ft_away'] ?? '';
            $played = !($fth === '' || $fta === '' || !is_numeric($fth) || !is_numeric($fta));
            if (!$played) {
                if ($sk !== '' && $sk >= $nowStr) {
                    $hk = $h . '|' . $lg; $ak = $a . '|' . $lg;
                    if (!isset($nextMatch[$hk]) || $sk < $nextMatch[$hk]['dt']) $nextMatch[$hk] = ['vs' => $a, 'dt' => $sk];
                    if (!isset($nextMatch[$ak]) || $sk < $nextMatch[$ak]['dt']) $nextMatch[$ak] = ['vs' => $h, 'dt' => $sk];
                }
                continue;
            }
            $ih = (int)$fth; $ia = (int)$fta;
            $tot = $ih + $ia;
            $loseH = $ih < $ia ? 1 : 0;
            $loseA = $ia < $ih ? 1 : 0;
            $u15  = $tot < 2 ? 1 : 0;                  // Under 1.5 (total < 2)
            $draw = $ih === $ia ? 1 : 0;               // seri (sama utk kedua tim)
            $u05  = $tot < 1 ? 1 : 0;                  // Under 0.5 (0-0)
            $team[$h . '|' . $lg][] = [$sk, $loseH, $tot, $u15, $draw, $u05];
            $team[$a . '|' . $lg][] = [$sk, $loseA, $tot, $u15, $draw, $u05];
        }
    }
    fclose($fh);
}

// index flag per match: kl2 isLoss (1), u15x3 isU15 (3), dr2 isDraw (4), u05x2 isU05 (5)
$flagIdx = $mode === 'u15x3' ? 3 : (in_array($mode, ['dr2', 'dr3'], true) ? 4 : ($mode === 'u05x2' ? 5 : 1));

$out = [];
foreach ($team as $key => $arr) {
    usort($arr, fn($x, $y) => strcmp($x[0], $y[0])); // terlama dulu
    $n = count($arr);
    if ($n < 30) continue;

    // setelah `streakLen`x beruntun (sesuai mode), match berikutnya Over 1.5?
    $total = 0; $over = 0;
    for ($i = $streakLen; $i < $n; $i++) {
        $ok = true;
        for ($j = 1; $j <= $streakLen; $j++) { if (!$arr[$i - $j][$flagIdx]) { $ok = false; break; } }
        if (!$ok) continue;
        $total++;
        if ($arr[$i][2] >= $overMin) $over++;            // match ini Over (sesuai $out)
    }
    if ($total < $minSample) continue;
    $pct = round($over / $total * 100, 1);
    if ($pct < $minPct) continue;

    // current streak berjalan (dari akhir mundur)
    $cur = 0;
    for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][$flagIdx]) $cur++; else break; }
    if ($cur < $streakLen) continue;                      // harus sedang beruntun >= streakLen

    $nx = $nextMatch[$key] ?? null;
    if (!$nx) continue;                                   // harus ada jadwal next match

    [$tm, $lg] = array_pad(explode('|', $key, 2), 2, '');
    $ts = strtotime($nx['dt']);
    $out[] = [
        'team'      => $tm,
        'league'    => $lg,
        'opponent'  => $nx['vs'],
        'next_dt'   => $nx['dt'],
        'next_date' => substr($nx['dt'], 0, 10),
        'next_time' => $ts ? date('H:i', $ts - 3600) : substr($nx['dt'], 11, 5), // tampilan -1 jam spt halaman
        'pct'       => $pct,
        'over'      => $over,
        'total'     => $total,
        'curL'      => $cur,
    ];
}

usort($out, fn($a, $b) => $b['pct'] <=> $a['pct']);

echo json_encode([
    'ok'      => true,
    'date'    => $today,
    'now'     => date('H:i'),
    'mode'    => $mode,
    'out'     => $outMkt,
    'market'  => $mode . '_' . $outMkt,
    'pct'     => $minPct,
    'min'     => $minSample,
    'count'   => count($out),
    'teams'   => $out,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
