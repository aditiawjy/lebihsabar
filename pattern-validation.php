<?php
/**
 * Validasi pola berbasis riwayat terhadap matches.csv (read-only).
 * Untuk tiap pola: hit rate vs base rate liga (lift), uji z, koreksi FDR (Benjamini–Hochberg),
 * pemisahan train (lama) / test (baru), dan odds minimum agar impas.
 * Ditambah uji independensi: korelasi lag-1 total gol antar laga berurutan per liga dan per tim.
 *
 * Pemakaian: php pattern-validation.php [hari_test=30]   atau buka di browser (?days=30).
 */
ini_set('memory_limit', '1024M');
set_time_limit(300);
$cli = PHP_SAPI === 'cli';
$testDays = max(1, (int)($cli ? ($argv[1] ?? 30) : ($_GET['days'] ?? 30)));

$fh = fopen(__DIR__ . '/matches.csv', 'r');
$head = fgetcsv($fh);
$col = array_flip($head);
$rows = [];
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < 9 || $r[$col['ft_home']] === '' || $r[$col['ft_away']] === '') continue;
    $ts = strtotime($r[$col['match_time']]);
    if (!$ts) continue;
    $fhH = $r[$col['fh_home']]; $fhA = $r[$col['fh_away']];
    $rows[] = [
        't' => $ts, 'league' => $r[$col['league']],
        'home' => trim($r[$col['home_team']]), 'away' => trim($r[$col['away_team']]),
        'ftH' => (int)$r[$col['ft_home']], 'ftA' => (int)$r[$col['ft_away']],
        'ht' => $fhH !== '' && $fhA !== '' ? [(int)$fhH, (int)$fhA] : null,
    ];
}
fclose($fh);
usort($rows, fn($a, $b) => $a['t'] <=> $b['t']);
$cutoff = end($rows)['t'] - $testDays * 86400;

$markets = [
    'FT U1.5' => fn($m) => $m['ftH'] + $m['ftA'] < 2,
    'FT 0-0'  => fn($m) => $m['ftH'] + $m['ftA'] === 0,
    'FT O2.5' => fn($m) => $m['ftH'] + $m['ftA'] > 2,
    '2H O0.5' => fn($m) => $m['ht'] !== null ? ($m['ftH'] + $m['ftA'] - $m['ht'][0] - $m['ht'][1] > 0) : null,
];

// Kondisi: menerima konteks riwayat sebelum kickoff, mengembalikan market yang diprediksi atau null.
$patterns = [
    'Tim kandang: laga sebelumnya FT U1.5'           => ['FT U1.5', fn($c) => ($p = $c['prevHome']) && $p['ftH'] + $p['ftA'] < 2],
    'Kedua tim: laga sebelumnya FT U1.5'             => ['FT U1.5', fn($c) => ($a = $c['prevHome']) && ($b = $c['prevAway']) && $a['ftH'] + $a['ftA'] < 2 && $b['ftH'] + $b['ftA'] < 2],
    'Kedua tim: laga sebelumnya FT 0-0'              => ['FT 0-0',  fn($c) => ($a = $c['prevHome']) && ($b = $c['prevAway']) && $a['ftH'] + $a['ftA'] === 0 && $b['ftH'] + $b['ftA'] === 0],
    'H2H terakhir FT U1.5'                           => ['FT U1.5', fn($c) => ($h = $c['h2h'][0] ?? null) && $h['ftH'] + $h['ftA'] < 2],
    '2 H2H terakhir FT sama (prediksi U1.5 bila skornya U1.5)' => ['FT U1.5', fn($c) => count($c['h2h']) >= 2 && $c['h2h'][0]['key'] === $c['h2h'][1]['key'] && $c['h2h'][0]['ftH'] + $c['h2h'][0]['ftA'] < 2],
    '2 H2H terakhir FT sama (prediksi O2.5 bila skornya O2.5)' => ['FT O2.5', fn($c) => count($c['h2h']) >= 2 && $c['h2h'][0]['key'] === $c['h2h'][1]['key'] && $c['h2h'][0]['ftH'] + $c['h2h'][0]['ftA'] > 2],
    'Tim sudah ≥2× FT 0-0 hari ini'                  => ['FT 0-0',  fn($c) => max($c['zeroToday']) >= 2],
    'Slot sebelumnya di liga: semua laga FT 0-0'     => ['FT 0-0',  fn($c) => $c['prevSlot'] && !array_filter($c['prevSlot'], fn($m) => $m['ftH'] + $m['ftA'] > 0)],
    'Slot sebelumnya di liga: semua laga FT U1.5'    => ['FT U1.5', fn($c) => $c['prevSlot'] && !array_filter($c['prevSlot'], fn($m) => $m['ftH'] + $m['ftA'] > 1)],
    'Tim kandang: laga sebelumnya 2H tanpa gol'      => ['2H O0.5', fn($c) => ($p = $c['prevHome']) && $p['ht'] && $p['ftH'] + $p['ftA'] === $p['ht'][0] + $p['ht'][1]],
    'Laga ini HT 0-0 (in-play)'                      => ['2H O0.5', fn($c) => $c['m']['ht'] === [0, 0]],
    'Laga ini HT total ≥2 (in-play)'                 => ['2H O0.5', fn($c) => $c['m']['ht'] && $c['m']['ht'][0] + $c['m']['ht'][1] >= 2],
];

$base = [];   // [period][league][market] => [hit, n]
$stats = [];  // [pattern][period] => [hit, n, expected, variance]
$lastTeam = []; $h2h = []; $zeroDay = []; $slots = []; $lastSlot = [];
$seqLeague = []; $seqTeam = [];

$i = 0; $n = count($rows);
while ($i < $n) {
    $t = $rows[$i]['t']; $j = $i;
    while ($j < $n && $rows[$j]['t'] === $t) $j++;
    $batch = array_slice($rows, $i, $j - $i);
    // Evaluasi semua laga di slot ini dengan riwayat sebelum kickoff.
    foreach ($batch as $m) {
        $L = $m['league']; $period = $m['t'] >= $cutoff ? 'test' : 'train';
        $day = date('Y-m-d', $m['t']);
        $pairKey = $L . '|' . $m['home'] . '|' . $m['away'];
        $ctx = [
            'm' => $m,
            'prevHome' => $lastTeam[$L . '|' . $m['home']] ?? null,
            'prevAway' => $lastTeam[$L . '|' . $m['away']] ?? null,
            'h2h' => $h2h[$pairKey] ?? [],
            'zeroToday' => [$zeroDay[$L . '|' . $m['home'] . '|' . $day] ?? 0, $zeroDay[$L . '|' . $m['away'] . '|' . $day] ?? 0],
            'prevSlot' => $lastSlot[$L] ?? null,
        ];
        foreach ($markets as $mk => $fn) {
            $v = $fn($m); if ($v === null) continue;
            $base[$period][$L][$mk][0] = ($base[$period][$L][$mk][0] ?? 0) + (int)$v;
            $base[$period][$L][$mk][1] = ($base[$period][$L][$mk][1] ?? 0) + 1;
        }
        foreach ($patterns as $name => [$mk, $cond]) {
            if (!$cond($ctx)) continue;
            $v = $markets[$mk]($m); if ($v === null) continue;
            $stats[$name][$period]['rows'][] = [$L, (int)$v];
        }
    }
    // Perbarui riwayat setelah slot selesai.
    $bySlotLeague = [];
    foreach ($batch as $m) {
        $L = $m['league']; $day = date('Y-m-d', $m['t']); $goals = $m['ftH'] + $m['ftA'];
        $lastTeam[$L . '|' . $m['home']] = $m; $lastTeam[$L . '|' . $m['away']] = $m;
        $pairKey = $L . '|' . $m['home'] . '|' . $m['away'];
        $h2h[$pairKey] = array_slice([$m + ['key' => $m['ftH'] . ':' . $m['ftA']], ...($h2h[$pairKey] ?? [])], 0, 2);
        if ($goals === 0) foreach ([$m['home'], $m['away']] as $team) { $k = $L . '|' . $team . '|' . $day; $zeroDay[$k] = ($zeroDay[$k] ?? 0) + 1; }
        $bySlotLeague[$L][] = $m;
        $seqLeague[$L][] = $goals;
        $seqTeam[$L . '|' . $m['home']][] = $goals; $seqTeam[$L . '|' . $m['away']][] = $goals;
    }
    foreach ($bySlotLeague as $L => $ms) $lastSlot[$L] = $ms;
    $i = $j;
}

function zToP(float $z): float { // p-value satu sisi (atas) via erfc
    $x = $z / sqrt(2); $t = 1 / (1 + 0.5 * abs($x));
    $erfc = $t * exp(-$x * $x - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));
    $erfc = $x >= 0 ? $erfc : 2 - $erfc;
    return $erfc / 2;
}
$report = [];
foreach ($patterns as $name => [$mk]) {
    $row = ['name' => $name, 'market' => $mk];
    foreach (['train', 'test'] as $period) {
        $hit = 0; $cnt = 0; $exp = 0.0; $var = 0.0;
        foreach ($stats[$name][$period]['rows'] ?? [] as [$L, $v]) {
            [$bh, $bn] = $base[$period][$L][$mk] ?? [0, 0];
            $p = $bn ? $bh / $bn : 0; $hit += $v; $cnt++; $exp += $p; $var += $p * (1 - $p);
        }
        $z = $var > 0 ? ($hit - $exp) / sqrt($var) : 0;
        $row[$period] = ['n' => $cnt, 'hit' => $cnt ? $hit / $cnt : 0, 'base' => $cnt ? $exp / $cnt : 0, 'z' => $z, 'p' => zToP($z)];
    }
    $report[] = $row;
}
// Benjamini–Hochberg pada p-value test.
$ps = array_map(fn($r) => $r['test']['p'], $report); asort($ps); $m = count($ps); $prev = 1.0;
$sorted = array_keys($ps); $q = [];
for ($k = $m - 1; $k >= 0; $k--) { $idx = $sorted[$k]; $prev = min($prev, $ps[$idx] * $m / ($k + 1)); $q[$idx] = $prev; }
foreach ($report as $idx => &$r) {
    $r['q'] = $q[$idx];
    $lift = $r['test']['base'] > 0 ? $r['test']['hit'] / $r['test']['base'] : 0;
    $r['lift'] = $lift;
    $r['verdict'] = $r['test']['n'] < 100 ? 'Sampel kecil'
        : ($r['q'] < 0.05 && $lift > 1.03 && $r['train']['z'] > 2 ? 'Efek nyata (perlu cek odds)'
        : ($r['q'] < 0.05 && $lift < 0.97 ? 'Lebih buruk dari rata-rata' : 'Tidak berbeda dari acak'));
}
unset($r);

function lagCorr(array $seq): array {
    $n = count($seq) - 1; if ($n < 30) return [0.0, $n];
    $x = array_slice($seq, 0, -1); $y = array_slice($seq, 1);
    $mx = array_sum($x) / $n; $my = array_sum($y) / $n; $sxy = $sxx = $syy = 0;
    for ($k = 0; $k < $n; $k++) { $dx = $x[$k] - $mx; $dy = $y[$k] - $my; $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy; }
    return [$sxx && $syy ? $sxy / sqrt($sxx * $syy) : 0.0, $n];
}
$indep = [];
foreach ($seqLeague as $L => $seq) {
    [$r, $cnt] = lagCorr($seq);
    $teamR = []; $teamN = 0;
    foreach ($seqTeam as $k => $s) if (str_starts_with($k, $L . '|')) { [$tr, $tn] = lagCorr($s); if ($tn >= 30) { $teamR[] = $tr * $tn; $teamN += $tn; } }
    $indep[] = ['league' => $L, 'n' => $cnt, 'r' => $r, 'z' => $r * sqrt(max($cnt, 1)), 'teamR' => $teamN ? array_sum($teamR) / $teamN : 0, 'teamN' => $teamN];
}
usort($indep, fn($a, $b) => $b['n'] <=> $a['n']);

$pct = fn($v) => number_format($v * 100, 1) . '%';
$odds = fn($v) => $v > 0 ? number_format(1 / $v, 2) : '—';
if ($cli) {
    printf("Data: %d laga · test = %d hari terakhir (sejak %s)\n\n", $n, $testDays, date('Y-m-d', $cutoff));
    foreach ($report as $r) {
        printf("%-60s %-8s | train n=%-6d hit=%-6s base=%-6s z=%5.1f | test n=%-6d hit=%-6s base=%-6s lift=%.2f q=%.3f | impas @%s | %s\n",
            $r['name'], $r['market'], $r['train']['n'], $pct($r['train']['hit']), $pct($r['train']['base']), $r['train']['z'],
            $r['test']['n'], $pct($r['test']['hit']), $pct($r['test']['base']), $r['lift'], $r['q'], $odds($r['test']['hit']), $r['verdict']);
    }
    echo "\nIndependensi (korelasi lag-1 total gol):\n";
    foreach ($indep as $x) printf("%-60s n=%-6d r=%+.3f z=%+.1f | per tim r=%+.3f (n=%d)\n", $x['league'], $x['n'], $x['r'], $x['z'], $x['teamR'], $x['teamN']);
    exit;
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Validasi Pola</title>
<style>body{font:14px/1.5 system-ui,sans-serif;margin:24px;color:#0f172a}table{border-collapse:collapse;width:100%;margin:12px 0 28px;font-size:13px}th,td{border-bottom:1px solid #e2e8f0;padding:7px 8px;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left;white-space:normal}th{background:#f1f5f9}.ok{color:#166534;font-weight:600}.bad{color:#9a3412}.muted{color:#64748b}p{color:#475569;max-width:900px}</style></head><body>
<h1>Validasi Pola</h1>
<p><?= $e(number_format($n)) ?> laga dari matches.csv. Train = sebelum <?= $e(date('Y-m-d', $cutoff)) ?>, test = <?= $testDays ?> hari terakhir (<a href="?days=14">14</a> · <a href="?days=30">30</a> · <a href="?days=60">60</a>). Base rate = rata-rata market di liga dan periode yang sama. q = p-value setelah koreksi FDR. "Impas @" = odds minimum agar hit rate test tidak rugi.</p>
<table><thead><tr><th>Pola</th><th>Market</th><th>Train n</th><th>Train hit</th><th>Train z</th><th>Test n</th><th>Test hit</th><th>Base</th><th>Lift</th><th>q</th><th>Impas @</th><th>Kesimpulan</th></tr></thead><tbody>
<?php foreach ($report as $r): $cls = str_starts_with($r['verdict'], 'Efek') ? 'ok' : (str_starts_with($r['verdict'], 'Lebih') ? 'bad' : 'muted'); ?>
<tr><td><?= $e($r['name']) ?></td><td><?= $e($r['market']) ?></td><td><?= $r['train']['n'] ?></td><td><?= $pct($r['train']['hit']) ?></td><td><?= number_format($r['train']['z'], 1) ?></td><td><?= $r['test']['n'] ?></td><td><?= $pct($r['test']['hit']) ?></td><td><?= $pct($r['test']['base']) ?></td><td><?= number_format($r['lift'], 2) ?></td><td><?= number_format($r['q'], 3) ?></td><td><?= $odds($r['test']['hit']) ?></td><td class="<?= $cls ?>"><?= $e($r['verdict']) ?></td></tr>
<?php endforeach; ?></tbody></table>
<h2>Independensi hasil</h2>
<p>Korelasi lag-1 total gol antar laga berurutan. Nilai r mendekati 0 berarti hasil sebelumnya tidak memberi informasi tentang laga berikutnya. |z| &gt; 3 baru layak dicurigai.</p>
<table><thead><tr><th>Liga</th><th>n</th><th>r liga</th><th>z</th><th>r per tim (rata-rata)</th><th>n tim</th></tr></thead><tbody>
<?php foreach ($indep as $x): ?><tr><td><?= $e($x['league']) ?></td><td><?= $x['n'] ?></td><td><?= sprintf('%+.3f', $x['r']) ?></td><td><?= sprintf('%+.1f', $x['z']) ?></td><td><?= sprintf('%+.3f', $x['teamR']) ?></td><td><?= $x['teamN'] ?></td></tr><?php endforeach; ?>
</tbody></table></body></html>
