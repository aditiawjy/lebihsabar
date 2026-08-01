<?php
/**
 * check-signal-pnl.php — papan skor UANG, bukan papan skor tebakan.
 *
 * Bedanya dengan check-super-accuracy.php:
 *   - Halaman itu mengukur target INTERNAL ("gol babak kedua >= 2/3") pada
 *     seluruh riwayat match. Target itu tidak dijual di pasar, jadi 100% di
 *     sana tidak berarti untung.
 *   - Halaman ini hanya memakai sinyal yang BENAR-BENAR muncul live
 *     (signal_log_vsoccer.csv, lengkap dengan line & odds saat itu), lalu
 *     menghitung hasilnya kalau tiap sinyal dipasang 1 satuan pada line pasar.
 *
 * HIT di sini = total gol akhir melewati LINE PASAR saat sinyal muncul.
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

const COCOK_JAM = 3;          // toleransi pencocokan sinyal <-> hasil akhir (jam)
const STAKE = 1.0;            // 1 satuan per sinyal

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function midline($v): ?float
{
    $p = array_values(array_filter(array_map('trim', explode('/', (string)$v)),
        static fn($x) => $x !== '' && is_numeric($x)));
    return $p ? array_sum(array_map('floatval', $p)) / count($p) : null;
}

function pct(?float $v): string { return $v === null ? '–' : number_format($v, 1, ',', '.') . '%'; }

function patternLabel(string $code): string
{
    return $code === 'HAH' ? 'HAH (aturan lama)' : $code;
}

function wilson95(int $hits, int $total): array
{
    if ($total === 0) return [null, null];
    $z = 1.96; $p = $hits / $total;
    $d = 1 + ($z * $z / $total);
    $c = ($p + ($z * $z / (2 * $total))) / $d;
    $m = ($z * sqrt(($p * (1 - $p) / $total) + ($z * $z / (4 * $total * $total)))) / $d;
    return [max(0, $c - $m) * 100, min(1, $c + $m) * 100];
}

// ---- hasil akhir tiap match ------------------------------------------------
$hasil = [];   // "home|away" => [[timestamp, total gol], ...]
$logFile = __DIR__ . '/goal_log_vsoccer.csv';
if (is_file($logFile) && ($fh = fopen($logFile, 'r')) !== false) {
    $hdr = fgetcsv($fh);
    $ix = array_flip($hdr ?: []);
    while (($row = fgetcsv($fh)) !== false) {
        $fhome = $row[$ix['final_home']] ?? '';
        $faway = $row[$ix['final_away']] ?? '';
        if (!is_numeric($fhome) || !is_numeric($faway)) continue;
        $dt = DateTime::createFromFormat('d/m/Y H:i', trim((string)($row[$ix['datetime']] ?? '')));
        if (!$dt) continue;
        $k = trim((string)$row[$ix['home_team']]) . '|' . trim((string)$row[$ix['away_team']]);
        $hasil[$k][] = [$dt->getTimestamp(), (int)round((float)$fhome + (float)$faway)];
    }
    fclose($fh);
}

// ---- sinyal yang pernah muncul live ---------------------------------------
$taruhan = [];
$tanpaHasil = 0;
$sigFile = __DIR__ . '/signal_log_vsoccer.csv';
$error = null;
if (!is_file($sigFile)) {
    $error = 'signal_log_vsoccer.csv belum ada — runner belum pernah mencatat sinyal.';
} elseif (($fh = fopen($sigFile, 'r')) !== false) {
    $hdr = fgetcsv($fh);
    $ix = array_flip($hdr ?: []);
    while (($row = fgetcsv($fh)) !== false) {
        $home = trim((string)($row[$ix['home_team']] ?? ''));
        $away = trim((string)($row[$ix['away_team']] ?? ''));
        if ($home === '' || $home === 'A') continue;          // baris uji
        $dt = DateTime::createFromFormat('d/m/Y H:i', trim((string)($row[$ix['logged_at']] ?? '')));
        if (!$dt) continue;
        $live = midline($row[$ix['live_line']] ?? '');
        $butuh = midline($row[$ix['needed_line']] ?? '');
        $odds = (float)($row[$ix['live_over']] ?? 0);
        if ($live === null || $odds <= 1) continue;

        $totalFt = null; $selisih = PHP_INT_MAX;
        foreach ($hasil[$home . '|' . $away] ?? [] as [$ts, $tot]) {
            $d = abs($dt->getTimestamp() - $ts);
            if ($d <= COCOK_JAM * 3600 && $d < $selisih) { $selisih = $d; $totalFt = $tot; }
        }
        if ($totalFt === null) { $tanpaHasil++; continue; }

        $menang = $totalFt > $live;
        $taruhan[] = [
            'code' => trim((string)($row[$ix['code']] ?? '')),
            'ts' => $dt->getTimestamp(),
            'waktu' => $dt->format('d/m H:i'),
            'match' => $home . ' vs ' . $away,
            'skor' => trim((string)($row[$ix['score']] ?? '')),
            'menit' => (int)($row[$ix['minute']] ?? 0),
            'live' => $live, 'butuh' => $butuh, 'odds' => $odds,
            'total_ft' => $totalFt, 'menang' => $menang,
            'target_kita' => $butuh !== null && $totalFt > $butuh,
            'pnl' => $menang ? ($odds - 1) * STAKE : -STAKE,
        ];
    }
    fclose($fh);
}

usort($taruhan, static fn($a, $b) => $b['ts'] <=> $a['ts']);

function ringkas(array $rows): array
{
    $n = count($rows);
    $menang = 0; $pnl = 0.0; $odds = 0.0; $targetKita = 0;
    foreach ($rows as $r) {
        $menang += $r['menang'] ? 1 : 0;
        $pnl += $r['pnl'];
        $odds += $r['odds'];
        $targetKita += $r['target_kita'] ? 1 : 0;
    }
    return [
        'n' => $n, 'menang' => $menang, 'pnl' => $pnl,
        'win' => $n ? $menang / $n * 100 : null,
        'odds' => $n ? $odds / $n : null,
        'roi' => $n ? $pnl / $n * 100 : null,
        'impas' => $n && $odds ? 100 / ($odds / $n) : null,   // win% minimal agar tidak rugi
        'target_kita' => $n ? $targetKita / $n * 100 : null,
    ];
}

$perPola = [];
foreach ($taruhan as $t) $perPola[$t['code']][] = $t;
$ringkasPola = [];
foreach ($perPola as $kode => $rows) $ringkasPola[$kode] = ringkas($rows);
uasort($ringkasPola, static fn($a, $b) => $b['roi'] <=> $a['roi']);

$total = ringkas($taruhan);
// satu match bisa memicu beberapa pola sekaligus — hitung sekali juga
$unikRows = [];
foreach ($taruhan as $t) $unikRows[$t['match'] . '|' . floor($t['ts'] / 3600)] = $t;
$unik = ringkas(array_values($unikRows));

// seberapa sering line pasar sudah di atas kebutuhan kita
$lebihTinggi = 0; $selisihLine = []; $adaButuh = 0;
foreach ($taruhan as $t) {
    if ($t['butuh'] === null) continue;
    $adaButuh++;
    if ($t['live'] > $t['butuh'] + 0.001) $lebihTinggi++;
    $selisihLine[] = $t['live'] - $t['butuh'];
}
$rataSelisih = $selisihLine ? array_sum($selisihLine) / count($selisihLine) : null;
$ci = wilson95($total['menang'], $total['n']);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Hasil Taruhan Sinyal V-Soccer</title>
<style>
:root{--bg:#0c1016;--card:#151b24;--border:#2b3543;--text:#e7edf5;--muted:#92a0b3;--green:#59db8b;--red:#ff7185;--yellow:#f3c969;--blue:#78b7ff}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:1180px;margin:auto;padding:24px 16px 48px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
h1{font-size:24px;margin:0}.sub{color:var(--muted);margin:5px 0 0;max-width:760px}
.actions{display:flex;gap:8px}.btn{color:var(--text);text-decoration:none;border:1px solid var(--border);background:#111720;padding:7px 11px;border-radius:7px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px}
.warn{margin:18px 0;padding:14px 16px;border-left:4px solid var(--yellow);background:var(--card);border-radius:12px}
.warn b{color:var(--yellow)}
.verdict{padding:18px;margin:16px 0}.verdict.rugi{border-color:#9c3545}.verdict.untung{border-color:#29794a}
.verdict .tag{font-size:12px;font-weight:800;letter-spacing:.08em}.rugi .tag{color:var(--red)}.untung .tag{color:var(--green)}
.verdict .big{font-size:30px;font-weight:800;margin:3px 0}.muted{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
.stat{padding:14px}.stat .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}
.stat .value{font:700 22px/1.25 ui-monospace,Consolas,monospace;margin-top:4px}
.section{margin-top:22px}.section h2{font-size:15px;margin:0 0 8px}
.tablebox{overflow:auto;border:1px solid var(--border);border-radius:10px}
table{width:100%;border-collapse:collapse;white-space:nowrap;background:#10161e}
th,td{padding:8px 10px;border-bottom:1px solid #222c38;text-align:left}
th{color:var(--muted);font-size:11px;text-transform:uppercase}
.num{text-align:right;font-variant-numeric:tabular-nums}
.win{color:var(--green);font-weight:700}.lose{color:var(--red);font-weight:700}
.pos{color:var(--green);font-weight:800}.neg{color:var(--red);font-weight:800}
.kecil{color:var(--muted);font-size:11px}
.error{background:#421923;border:1px solid #8d3041;color:#ffbac5;padding:12px;border-radius:8px;margin:15px 0}
.foot{color:var(--muted);font-size:12px;margin-top:16px}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div>
      <h1>Hasil Taruhan Sinyal V-Soccer</h1>
      <p class="sub">Bukan papan akurasi. Ini menghitung hasil kalau tiap sinyal yang pernah muncul
        dipasang 1 satuan pada <b>line dan odds yang ditawarkan pasar saat itu</b>. HIT = total gol akhir
        melewati line pasar, bukan melewati target internal kita.</p>
    </div>
    <div class="actions">
      <a class="btn" href="vsoccer-live.php">← Live</a>
      <a class="btn" href="check-super-accuracy.php">Akurasi pola</a>
      <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
    </div>
  </div>

  <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($total['n'] > 0): ?>
  <div class="warn">
    <b>Kenapa angka di sini jauh lebih rendah daripada halaman akurasi.</b><br>
    Saat sinyal muncul di menit 60-an, pasar sudah melihat babak pertama yang sama dan menaikkan line-nya.
    Dari <?= $adaButuh ?> sinyal, <b><?= $lebihTinggi ?></b> di antaranya line pasar sudah <b>lebih tinggi</b>
    daripada yang kita butuhkan<?= $rataSelisih !== null ? ' (rata-rata ' . number_format($rataSelisih, 2, ',', '.') . ' gol lebih tinggi)' : '' ?>.
    Artinya target yang kita hitung tidak dijual; yang tersedia selalu versi lebih berat.
    <br><span class="muted">Catatan penting: sinyal lama di log ini dihasilkan aturan pola versi lama —
    aturan sudah beberapa kali diperketat. Untuk penilaian bersih, pakai sinyal sejak aturan terakhir dikunci.</span>
  </div>

  <section class="card verdict <?= $total['pnl'] >= 0 ? 'untung' : 'rugi' ?>">
    <div class="tag"><?= $total['pnl'] >= 0 ? 'UNTUNG' : 'RUGI' ?></div>
    <div class="big"><?= ($total['roi'] >= 0 ? '+' : '−') . number_format(abs($total['roi']), 1, ',', '.') ?>% ROI</div>
    <div class="muted">
      <?= $total['n'] ?> taruhan · menang <?= $total['menang'] ?> (<?= pct($total['win']) ?>) ·
      odds rata <?= number_format($total['odds'], 2, ',', '.') ?> ·
      hasil <?= ($total['pnl'] >= 0 ? '+' : '−') . number_format(abs($total['pnl']), 2, ',', '.') ?> satuan.
      Untuk impas pada odds itu dibutuhkan menang <?= pct($total['impas']) ?>.
      Selang keyakinan 95% untuk win rate: <?= pct($ci[0]) ?> – <?= pct($ci[1]) ?>.
    </div>
  </section>

  <section class="grid">
    <div class="card stat"><div class="label">Taruhan tercatat</div><div class="value"><?= $total['n'] ?></div><div class="muted"><?= $unik['n'] ?> match unik</div></div>
    <div class="card stat"><div class="label">Menang vs line pasar</div><div class="value"><?= pct($total['win']) ?></div><div class="muted">butuh <?= pct($total['impas']) ?> untuk impas</div></div>
    <div class="card stat"><div class="label">Target internal tercapai</div><div class="value"><?= pct($total['target_kita']) ?></div><div class="muted">inilah yang diukur halaman akurasi</div></div>
    <div class="card stat"><div class="label">Hasil per match unik</div><div class="value <?= $unik['pnl'] >= 0 ? 'pos' : 'neg' ?>"><?= ($unik['pnl'] >= 0 ? '+' : '−') . number_format(abs($unik['roi']), 1, ',', '.') ?>%</div><div class="muted">satu match dihitung sekali</div></div>
  </section>

  <section class="section">
    <h2>Per pola — diurutkan dari ROI terbaik</h2>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Pola</th><th class="num">Taruhan</th><th class="num">Menang</th><th class="num">Win%</th>
        <th class="num">Odds rata</th><th class="num">Butuh impas</th><th class="num">Hasil</th><th class="num">ROI</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ringkasPola as $kode => $s): ?>
        <tr>
          <td><b><?= e(patternLabel($kode)) ?></b><?= $s['n'] < 10 ? ' <span class="kecil">sampel kecil</span>' : '' ?></td>
          <td class="num"><?= $s['n'] ?></td>
          <td class="num"><?= $s['menang'] ?></td>
          <td class="num"><?= pct($s['win']) ?></td>
          <td class="num"><?= number_format($s['odds'], 2, ',', '.') ?></td>
          <td class="num"><?= pct($s['impas']) ?></td>
          <td class="num <?= $s['pnl'] >= 0 ? 'pos' : 'neg' ?>"><?= ($s['pnl'] >= 0 ? '+' : '−') . number_format(abs($s['pnl']), 2, ',', '.') ?></td>
          <td class="num <?= $s['roi'] >= 0 ? 'pos' : 'neg' ?>"><?= ($s['roi'] >= 0 ? '+' : '−') . number_format(abs($s['roi']), 1, ',', '.') ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="foot">Pola dengan taruhan &lt; 10 hampir tidak berarti apa-apa — ROI positif di situ lebih mungkin kebetulan daripada keunggulan.</p>
  </section>

  <section class="section">
    <h2>Semua taruhan (terbaru di atas)</h2>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Waktu</th><th>Pola</th><th>Match</th><th class="num">Menit</th><th class="num">Skor</th>
        <th class="num">Butuh line</th><th class="num">Line pasar</th><th class="num">Odds</th>
        <th class="num">Total akhir</th><th>Hasil</th><th class="num">P/L</th>
      </tr></thead>
      <tbody>
      <?php foreach ($taruhan as $t): ?>
        <tr>
          <td><?= e($t['waktu']) ?></td>
          <td><b><?= e(patternLabel($t['code'])) ?></b></td>
          <td><?= e($t['match']) ?></td>
          <td class="num"><?= $t['menit'] ?>'</td>
          <td class="num"><?= e($t['skor']) ?></td>
          <td class="num"><?= $t['butuh'] === null ? '–' : number_format($t['butuh'], 2, ',', '.') ?></td>
          <td class="num"><?= number_format($t['live'], 2, ',', '.') ?></td>
          <td class="num"><?= number_format($t['odds'], 2, ',', '.') ?></td>
          <td class="num"><?= $t['total_ft'] ?></td>
          <td class="<?= $t['menang'] ? 'win' : 'lose' ?>"><?= $t['menang'] ? 'MENANG' : 'KALAH' ?></td>
          <td class="num <?= $t['pnl'] >= 0 ? 'pos' : 'neg' ?>"><?= ($t['pnl'] >= 0 ? '+' : '−') . number_format(abs($t['pnl']), 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php elseif ($error === null): ?>
    <div class="card stat"><div class="muted">Belum ada sinyal yang hasil akhirnya bisa dicocokkan.</div></div>
  <?php endif; ?>

  <p class="foot">Sumber: signal_log_vsoccer.csv (line &amp; odds saat sinyal) dicocokkan dengan hasil akhir di
    goal_log_vsoccer.csv, toleransi <?= COCOK_JAM ?> jam. Sinyal tanpa hasil akhir yang cocok: <?= $tanpaHasil ?>.
    Taruhan diasumsikan 1 satuan pada Over di line pasar. Halaman refresh otomatis tiap 2 menit.</p>
</main>
</body>
</html>
