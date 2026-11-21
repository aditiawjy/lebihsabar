<?php
/**
 * Uji edge: apakah profil tim (dari matches.csv, hanya laga SEBELUM kickoff) mengalahkan odds bandar?
 * Odds kickoff Over DAN Under + skor akhir diambil dari baris yang sama di goal_log_bpvm.csv (hanya ko_score 0 - 0).
 * goal_log.csv tidak dipakai: kolom ko_line di sana tercatat saat laga sudah berjalan (line = gol akhir + ~0.75).
 * Pemakaian: php analyze-odds-edge.php
 */
ini_set('memory_limit', '1024M');
$wib = new DateTimeZone('Asia/Jakarta');
$bets = []; $seen = [];
$add = function ($dt, $L, $h, $a, $line, $over, $under, $fh, $fa) use (&$bets, &$seen, $wib) {
    $d = DateTime::createFromFormat('d/m/Y H:i', $dt, $wib);
    if (!$d || $line <= 0 || $over <= 1 || $under <= 1 || $fh === '' || $fa === '') return;
    $key = $L . '|' . $h . '|' . $a . '|' . intdiv($d->getTimestamp(), 1800);
    if (isset($seen[$key])) return; $seen[$key] = 1;
    $bets[] = ['ts' => $d->getTimestamp(), 'league' => $L, 'home' => $h, 'away' => $a, 'line' => (float)$line, 'over' => (float)$over, 'under' => (float)$under, 'goals' => (int)$fh + (int)$fa];
};
$fh = fopen(__DIR__ . '/goal_log_bpvm.csv', 'r'); $c = array_flip(fgetcsv($fh));
while (($r = fgetcsv($fh)) !== false) {
    if (($r[$c['ko_market']] ?? '') !== 'FT. O/U' || trim($r[$c['ko_score']] ?? '') !== '0 - 0') continue;
    if (!preg_match('/^o ([\d.]+):([\d.]+) \| u ([\d.]+):([\d.]+)$/', trim($r[$c['ko_ou_ft']]), $m) || $m[1] !== $m[3]) continue;
    $add($r[0], $r[1], trim($r[2]), trim($r[3]), $m[1], $m[2], $m[4], $r[$c['final_home']], $r[$c['final_away']]);
}
fclose($fh);
usort($bets, fn($a, $b) => $a['ts'] <=> $b['ts']);

$fh = fopen(__DIR__ . '/matches.csv', 'r'); fgetcsv($fh); $hist = [];
$sg = new DateTimeZone('Asia/Singapore');
while (($r = fgetcsv($fh)) !== false) {
    if ($r[7] === '' || $r[8] === '') continue;
    $hist[] = [(new DateTime($r[1], $sg))->getTimestamp(), $r[4], trim($r[2]), trim($r[3]), (int)$r[7] + (int)$r[8]];
}
fclose($fh);
usort($hist, fn($a, $b) => $a[0] <=> $b[0]);

$team = []; $pair = []; $lg = []; $i = 0; $n = count($hist);
$avg = fn($x) => $x && $x[1] ? $x[0] / $x[1] : null;
foreach ($bets as &$b) {
    while ($i < $n && $hist[$i][0] < $b['ts'] - 3600) {
        [, $L, $h, $a, $g] = $hist[$i++];
        foreach ([$L . '|' . $h, $L . '|' . $a] as $k) { $team[$k][0] = ($team[$k][0] ?? 0) + $g; $team[$k][1] = ($team[$k][1] ?? 0) + 1; }
        $k = $L . '|' . $h . '|' . $a; $pair[$k][0] = ($pair[$k][0] ?? 0) + $g; $pair[$k][1] = ($pair[$k][1] ?? 0) + 1;
        $lg[$L][0] = ($lg[$L][0] ?? 0) + $g; $lg[$L][1] = ($lg[$L][1] ?? 0) + 1;
    }
    $L = $b['league']; $th = $team[$L . '|' . $b['home']] ?? null; $ta = $team[$L . '|' . $b['away']] ?? null; $l = $avg($lg[$L] ?? null);
    $b['pred'] = $th && $ta && $th[1] >= 100 && $ta[1] >= 100 ? $avg($th) + $avg($ta) - $l : null;
    $p = $pair[$L . '|' . $b['home'] . '|' . $b['away']] ?? null;
    $b['pairPred'] = $p && $p[1] >= 15 ? $avg($p) : null;
}
unset($b);

function settle(float $line, int $g, float $odd, bool $over): float {
    $one = function (float $l) use ($g, $odd, $over) {
        if ($g == $l) return 0.0;
        return (($g > $l) === $over) ? $odd - 1 : -1.0;
    };
    return abs(fmod($line * 2, 1) - 0.5) < 1e-9 ? ($one($line - 0.25) + $one($line + 0.25)) / 2 : $one($line);
}
$roi = function (array $set, bool $over) {
    $x = array_map(fn($b) => settle($b['line'], $b['goals'], $over ? $b['over'] : $b['under'], $over), $set);
    $n = count($x); $m = array_sum($x) / $n; $v = array_sum(array_map(fn($y) => ($y - $m) ** 2, $x)) / max(1, $n - 1);
    return [$m, 1.96 * sqrt($v / $n)];
};
$show = function (string $label, array $set) use ($roi) {
    if (count($set) < 20) { printf("  %-42s n=%d (terlalu sedikit)\n", $label, count($set)); return; }
    [$o, $oe] = $roi($set, true); [$u, $ue] = $roi($set, false);
    printf("  %-42s n=%-5d line=%.2f gol=%.2f | ROI Over %+6.1f%% (+/-%.1f) | ROI Under %+6.1f%% (+/-%.1f)\n", $label, count($set),
        array_sum(array_column($set, 'line')) / count($set), array_sum(array_column($set, 'goals')) / count($set), 100 * $o, 100 * $oe, 100 * $u, 100 * $ue);
};
printf("Laga dengan odds kickoff Over+Under dan skor akhir: %d (%s s/d %s)\n\n", count($bets), date('Y-m-d', $bets[0]['ts']), date('Y-m-d', end($bets)['ts']));
echo "SEMUA\n"; $show('Semua laga', $bets);
$byL = []; foreach ($bets as $b) $byL[$b['league']][] = $b;
echo "\nPER LIGA\n"; foreach ($byL as $L => $set) $show(substr($L, 0, 42), $set);
foreach (['pred' => 'PROFIL TIM: prediksi gol - line bandar', 'pairPred' => 'PROFIL PASANGAN H2H: rata-rata gol - line bandar'] as $k => $title) {
    $set = array_values(array_filter($bets, fn($b) => $b[$k] !== null));
    usort($set, fn($a, $b) => ($a[$k] - $a['line']) <=> ($b[$k] - $b['line']));
    $q = count($set); echo "\n$title (5 kelompok; kelompok 1 = profil jauh DI BAWAH line -> kandidat Under)\n";
    if ($q < 100) { echo "  data kurang ($q)\n"; continue; }
    for ($j = 0; $j < 5; $j++) {
        $part = array_slice($set, intdiv($q * $j, 5), intdiv($q * ($j + 1), 5) - intdiv($q * $j, 5));
        $show(sprintf('Kelompok %d (selisih %+.2f..%+.2f)', $j + 1, $part[0][$k] - $part[0]['line'], end($part)[$k] - end($part)['line']), $part);
    }
}

echo "\nUJI KETAHANAN aturan: rata-rata gol pasangan H2H minimal 0.25 DI BAWAH line -> pasang Under\n";
$rule = array_values(array_filter($bets, fn($b) => $b['pairPred'] !== null && $b['pairPred'] - $b['line'] <= -0.25));
$rest = array_values(array_filter($bets, fn($b) => $b['pairPred'] !== null && $b['pairPred'] - $b['line'] > -0.25));
$show('Kena aturan', $rule); $show('Tidak kena aturan', $rest);
$mid = $bets[intdiv(count($bets), 2)]['ts'];
$show('Kena aturan, paruh waktu PERTAMA', array_values(array_filter($rule, fn($b) => $b['ts'] < $mid)));
$show('Kena aturan, paruh waktu KEDUA', array_values(array_filter($rule, fn($b) => $b['ts'] >= $mid)));
$byL = []; foreach ($rule as $b) $byL[$b['league']][] = $b;
foreach ($byL as $L => $set) $show('Kena aturan, ' . substr($L, 5, 28), $set);
foreach ([[0, 1.75], [1.76, 2.25], [2.26, 2.75], [2.76, 9]] as [$lo, $hi]) $show("Semua laga, line $lo-$hi", array_values(array_filter($bets, fn($b) => $b['line'] >= $lo && $b['line'] <= $hi)));
foreach ([[0, 1.75], [1.76, 2.25], [2.26, 2.75], [2.76, 9]] as [$lo, $hi]) $show("Kena aturan, line $lo-$hi", array_values(array_filter($rule, fn($b) => $b['line'] >= $lo && $b['line'] <= $hi)));
printf("  rata-rata odds Under kena aturan: %.2f\n", array_sum(array_column($rule, 'under')) / max(1, count($rule)));
