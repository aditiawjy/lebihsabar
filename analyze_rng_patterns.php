<?php
/**
 * analyze_rng_patterns.php
 * ---------------------------------------------------------------------------
 * Uji apakah virtual soccer (SABA / V-Soccer) menunjukkan DEPENDENSI antar-match
 * atau konsisten dgn RNG independen.
 *
 * Untuk tiap liga & tiap market biner (Over2.5, Over1.5, BTTS, Odd, dst):
 *   - base rate p
 *   - conditional P(X_t=1 | X_{t-1}=1) vs P(X_t=1 | X_{t-1}=0)
 *   - lag-1 autokorelasi
 *   - chi-square test independensi (2x2) + p-value
 * Sekuens = urutan match dalam liga menurut match_time (yg dilihat pemain di feed).
 *
 * RNG murni  -> conditional ≈ base, autokorelasi ≈ 0, chi-square TIDAK signifikan.
 * Ada pola   -> selisih conditional besar & p-value < 0.05 (idealnya < 0.001).
 *
 * Jalankan: php analyze_rng_patterns.php
 * ---------------------------------------------------------------------------
 */
$csvPath = __DIR__ . '/matches.csv';
if (($fh = fopen($csvPath, 'r')) === false) { fwrite(STDERR, "gagal buka csv\n"); exit(1); }
$hdr = fgetcsv($fh);
$idx = array_flip($hdr);

// Kumpulkan match per liga (played only): [id, match_time, ft_home, ft_away, fh_home, fh_away]
$byLeague = [];
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($hdr)) continue;
    $lg = $row[$idx['league']] ?? '';
    $fth = $row[$idx['ft_home']] ?? ''; $fta = $row[$idx['ft_away']] ?? '';
    if ($fth === '' || $fta === '' || !is_numeric($fth) || !is_numeric($fta)) continue;
    $byLeague[$lg][] = [
        'id' => (int)($row[$idx['id']] ?? 0),
        't'  => $row[$idx['match_time']] ?? '',
        'h'  => (int)$fth, 'a' => (int)$fta,
    ];
}
fclose($fh);

// Market biner: fn(match) -> 0/1
$markets = [
    'Over2.5'  => fn($m) => ($m['h'] + $m['a']) > 2 ? 1 : 0,
    'Over1.5'  => fn($m) => ($m['h'] + $m['a']) > 1 ? 1 : 0,
    'Over3.5'  => fn($m) => ($m['h'] + $m['a']) > 3 ? 1 : 0,
    'BTTS'     => fn($m) => ($m['h'] >= 1 && $m['a'] >= 1) ? 1 : 0,
    'OddTotal' => fn($m) => (($m['h'] + $m['a']) % 2 === 1) ? 1 : 0,
    'HomeWin'  => fn($m) => $m['h'] > $m['a'] ? 1 : 0,
    'Draw'     => fn($m) => $m['h'] === $m['a'] ? 1 : 0,
];

// erfc via aproksimasi Abramowitz & Stegun 7.1.26 (akurat ~1e-7).
function erfc_approx($x) {
    $z = abs($x);
    $t = 1.0 / (1.0 + 0.5 * $z);
    $ans = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 +
        $t * (0.09678418 + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 +
        $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));
    return $x >= 0 ? $ans : 2.0 - $ans;
}

// Chi-square 2x2 dgn koreksi Yates. Return [chi2, p_approx].
function chi2_2x2($a, $b, $c, $d) {
    $n = $a + $b + $c + $d;
    if ($n === 0) return [0.0, 1.0];
    $r1 = $a + $b; $r2 = $c + $d; $c1 = $a + $c; $c2 = $b + $d;
    if ($r1 == 0 || $r2 == 0 || $c1 == 0 || $c2 == 0) return [0.0, 1.0];
    $num = abs($a * $d - $b * $c) - $n / 2.0;
    if ($num < 0) $num = 0;
    $chi2 = ($n * $num * $num) / ($r1 * $r2 * $c1 * $c2);
    // p-value utk df=1: p = erfc(sqrt(chi2/2))
    $p = erfc_approx(sqrt($chi2 / 2.0));
    return [$chi2, $p];
}

// Analisis satu liga
function analyzeLeague($name, $matches, $markets) {
    // urutkan sesuai feed: match_time lalu id
    usort($matches, fn($x, $y) => ($x['t'] <=> $y['t']) ?: ($x['id'] <=> $y['id']));
    $n = count($matches);
    printf("\n=== %s  (n=%d match) ===\n", $name, $n);
    printf("%-9s | base%%  | P(X|prev=1) | P(X|prev=0) | selisih | autokor | chi2   | p-value    | catatan\n", 'market');
    echo str_repeat('-', 108) . "\n";
    foreach ($markets as $mk => $fn) {
        $seq = array_map($fn, $matches);
        $tot = array_sum($seq);
        $base = $n > 0 ? $tot / $n : 0;
        // 2x2: prev, next
        $a = $b = $c = $d = 0; // a: prev1&next1, b: prev1&next0, c: prev0&next1, d: prev0&next0
        for ($i = 1; $i < $n; $i++) {
            $p = $seq[$i - 1]; $x = $seq[$i];
            if ($p === 1 && $x === 1) $a++;
            elseif ($p === 1 && $x === 0) $b++;
            elseif ($p === 0 && $x === 1) $c++;
            else $d++;
        }
        $pGiven1 = ($a + $b) > 0 ? $a / ($a + $b) : null;
        $pGiven0 = ($c + $d) > 0 ? $c / ($c + $d) : null;
        $diff = ($pGiven1 !== null && $pGiven0 !== null) ? ($pGiven1 - $pGiven0) : null;
        // autokorelasi lag-1 (Pearson pada seri biner)
        $m = $base; $numA = 0; $den = 0;
        for ($i = 0; $i < $n; $i++) { $den += ($seq[$i] - $m) ** 2; }
        for ($i = 1; $i < $n; $i++) { $numA += ($seq[$i] - $m) * ($seq[$i - 1] - $m); }
        $auto = $den > 0 ? $numA / $den : 0;
        [$chi2, $pval] = chi2_2x2($a, $b, $c, $d);
        $flag = $pval < 0.001 ? '*** SIGNIFIKAN' : ($pval < 0.05 ? '* lemah' : 'acak (RNG)');
        printf("%-9s | %5.1f  | %-11s | %-11s | %+6.1f%% | %+6.3f | %6.1f | %.3e | %s\n",
            $mk, $base * 100,
            $pGiven1 === null ? '-' : sprintf('%.1f%%', $pGiven1 * 100),
            $pGiven0 === null ? '-' : sprintf('%.1f%%', $pGiven0 * 100),
            $diff === null ? 0 : $diff * 100,
            $auto, $chi2, $pval, $flag);
    }
}

// Jalankan utk liga terbesar + semua liga V-Soccer
$targets = [];
foreach ($byLeague as $lg => $ms) {
    if (count($ms) >= 500 || stripos($lg, 'V-Soccer') !== false) $targets[$lg] = count($ms);
}
arsort($targets);
foreach (array_keys($targets) as $lg) analyzeLeague($lg, $byLeague[$lg], $markets);

echo "\n[Interpretasi] p-value >= 0.05 = tak ada bukti dependensi (konsisten RNG independen).\n";
echo "'selisih' kecil (<2-3%) + autokor ~0 memperkuat kesimpulan independen.\n";
