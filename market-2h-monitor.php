<?php
/**
 * Bagian render halaman pemantau. Konstanta, aturan, evaluate(), dan kedua
 * loader ada di market-lib.php supaya forward-test.php memakai definisi yang
 * sama persis -- menyalin ulang logikanya pernah melahirkan masalah paritas.
 */

require __DIR__ . '/market-lib.php';

/** "2,5' (15m) / 3,3' (20m)" — ambang skala-90 diterjemahkan ke jam SABA. */
function sabaAmbang(array $durasi, int $menit90, string $babak = '1H'): string
{
    $dalamBabak = $babak === '2H' ? $menit90 - 45 : $menit90;
    $bagian = [];
    foreach ($durasi as $total => $halfLen) {
        $bagian[] = number_format($dalamBabak / 45 * $halfLen, 1, ',', '.') . "' ({$total}m)";
    }
    return implode(' / ', $bagian);
}

// Label khusus SABA: ambang menit ditulis dalam jam SABA, bukan skala 90.
// Tanpa ini tabel menyebut "≤ 15'" padahal babak pertama SABA cuma sampai 7'.
$sabaLabel = [];
if ($sabaDurasi) {
    $sabaLabel['R3'] = 'R1 + gol pertama ≤ ' . sabaAmbang($sabaDurasi, R3_FIRST_GOAL_MAX);
    $sabaLabel['R4'] = 'Gol 2H ≤ ' . sabaAmbang($sabaDurasi, R4_FIRST_2H_MAX, '2H') . ' → Over (line saat gol itu)';
    $r11Ambang = [];
    foreach ($sabaDurasi as $total => $halfLen) {
        $operator = (int)$total === 20 ? '≤' : '>';
        $r11Ambang[] = $operator . ' ' . number_format($halfLen / 3, 1, ',', '.') . "' (liga {$total}m)";
    }
    $sabaLabel['R11'] = 'R10 + gol pertama ' . implode(' / ', $r11Ambang) . ' → Over';
}

// Hubungan gol 1H vs gol 2H: dasar mekanisme R1/R2. Kalau r mengecil ke nol,
// alasan memakai aturan-aturan itu ikut hilang.
$corr = null;
if (count($rows) > 3) {
    $x = array_column($rows, 'ht_total');
    $y = array_column($rows, 'goals_2h');
    $n = count($x);
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxy = 0.0;
    $sx = 0.0;
    $sy = 0.0;
    for ($k = 0; $k < $n; $k++) {
        $sxy += ($x[$k] - $mx) * ($y[$k] - $my);
        $sx += ($x[$k] - $mx) ** 2;
        $sy += ($y[$k] - $my) ** 2;
    }
    if ($sx > 0 && $sy > 0) {
        $rr = $sxy / sqrt($sx * $sy);
        $t = abs($rr) < 1 ? $rr * sqrt(($n - 2) / (1 - $rr * $rr)) : INF;
        $corr = ['r' => $rr, 't' => $t, 'n' => $n, 'sig' => abs($t) > 1.96];
    }
}

// Market vs kenyataan per total gol HT (V-Soccer).
$byHt = [];
foreach ($rows as $r) {
    $k = $r['ht_total'] >= 6 ? '6+' : (string)$r['ht_total'];
    $byHt[$k]['n'] = ($byHt[$k]['n'] ?? 0) + 1;
    $byHt[$k]['demand'] = ($byHt[$k]['demand'] ?? 0) + $r['demand'];
    $byHt[$k]['actual'] = ($byHt[$k]['actual'] ?? 0) + $r['goals_2h'];
}
uksort($byHt, static fn($a, $b) => (float)$a <=> (float)$b);

/** Satu baris tabel performa aturan. */
function barisAturan(string $code, array $res, int $jumlahHari, array $labelKhusus): string
{
    $a = $res['all'];
    $kelas = !empty($res['control']) ? ' class="ctrl"' : '';
    $tagLemah = !empty($res['weak'])
        ? ' <span class="tag weak" title="Dibangun di atas patokan line titik masuk (sinyal-A). Uji menunjukkan patokan line kickoff lebih baik.">lemah</span>'
        : '';
    $label = e($labelKhusus[$code] ?? $res['label']);
    $html = "<tr{$kelas}><td><b>" . e($code) . "</b>{$tagLemah}</td><td>{$label}</td>";
    if (!$a) {
        return $html . '<td class="num" colspan="9"><span class="muted">tidak ada sampel</span></td></tr>';
    }
    $push = $a['push'] ? ' <span class="muted">(' . angka($a['push']) . ' push)</span>' : '';
    $plKelas = $a['pl'] >= 0 ? 'pos' : 'neg';
    $plTeks = ($a['pl'] >= 0 ? '+' : '−') . number_format(abs($a['pl']), 2, ',', '.');
    // Tiga tingkat, bukan lolos/tidak. Lihat T_BONFERRONI di market-lib.php.
    if ($a['proven']) {
        [$tagKelas, $tagTeks] = ['yes', 'TERBUKTI'];
    } elseif ($a['lewat95']) {
        [$tagKelas, $tagTeks] = ['weak', 'LEWAT 95%'];
    } else {
        [$tagKelas, $tagTeks] = ['no', 'BELUM'];
    }
    $html .= '<td class="num">' . $a['n'] . '</td>'
        . '<td class="num">' . angka($a['win']) . $push . '</td>'
        . '<td class="num" title="Atas ' . angka($a['n_decided']) . ' taruhan yang diputuskan; push tidak dihitung.">'
        . pct($a['winrate']) . '</td>'
        . '<td class="num" title="Uji-t atas P/L per taruhan. Terbukti butuh t &gt; ' . T_BONFERRONI . '.">'
        . number_format($a['t'], 2, ',', '.') . '</td>'
        . '<td class="num">' . pct($a['breakeven'], 0) . '</td>'
        . '<td class="num ' . $plKelas . '">' . $plTeks . '</td>'
        . '<td class="num ' . ($a['roi'] >= 0 ? 'pos' : 'neg') . '">' . signed($a['roi']) . '</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . $tagKelas . '">' . $tagTeks . '</span></td>';
    return $html . '</tr>';
}

/** Satu baris tabel ROI per hari. */
function barisHarian(string $code, array $res, array $hari, array $labelKhusus): string
{
    $kelas = !empty($res['control']) ? ' class="ctrl"' : '';
    $label = e($labelKhusus[$code] ?? $res['label']);
    $html = "<tr{$kelas}><td><b>" . e($code) . "</b></td><td>{$label}</td>";
    foreach ($hari as $d) {
        $x = $res['per_day'][$d] ?? null;
        $kls = $x ? ($x['roi'] >= 0 ? 'pos' : 'neg') : '';
        $isi = $x ? signed($x['roi']) . ' <span class="muted">(' . $x['n'] . ')</span>' : '–';
        $html .= '<td class="num ' . $kls . '">' . $isi . '</td>';
    }
    return $html . '</tr>';
}
/** Baris tabel untuk prediksi paritas hasil akhir. */
function barisParitas(string $code, array $res, int $jumlahHari): string
{
    $a = $res['all'];
    $label = e($res['label']);
    if (!$a) {
        return '<tr><td><b>' . e($code) . '</b></td><td>' . $label
            . '</td><td class="num" colspan="7"><span class="muted">tidak ada sampel</span></td></tr>';
    }
    $status = $a['proven'] ? 'YA' : 'BELUM';
    return '<tr><td><b>' . e($code) . '</b></td><td>' . $label . '</td>'
        . '<td class="num">' . $a['n'] . '</td>'
        . '<td class="num">' . $a['correct'] . '/' . $a['n'] . '</td>'
        . '<td class="num">' . pct($a['accuracy']) . '</td>'
        . '<td class="num">' . pct($a['ci_lo'], 0) . ' - ' . pct($a['ci_hi'], 0) . '</td>'
        . '<td class="num muted">N/A</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . ($a['proven'] ? 'yes' : 'no') . '">' . $status . '</span></td></tr>';
}

/** Baris paritas dengan lebar kolom tabel performa Over/Under. */
function barisParitasLebar(string $code, array $res, int $jumlahHari): string
{
    $a = $res['all'];
    if (!$a) {
        return '<tr><td><b>' . e($code) . '</b></td><td>' . e($res['label'])
            . '</td><td class="num" colspan="9"><span class="muted">tidak ada sampel</span></td></tr>';
    }
    $status = $a['proven'] ? 'YA' : 'BELUM';
    return '<tr><td><b>' . e($code) . '</b></td><td>' . e($res['label']) . '</td>'
        . '<td class="num">' . $a['n'] . '</td>'
        . '<td class="num">' . $a['correct'] . '/' . $a['n'] . '</td>'
        . '<td class="num">' . pct($a['accuracy']) . '</td>'
        . '<td class="num">' . pct($a['ci_lo'], 0) . ' - ' . pct($a['ci_hi'], 0) . '</td>'
        . '<td class="num">-</td><td class="num">-</td><td class="num muted">N/A</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . ($a['proven'] ? 'yes' : 'no') . '">' . $status . '</span></td></tr>';
}

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Pemantau ROI Market Babak Kedua</title>
<style>
:root{--bg:#0c1016;--card:#151b24;--border:#2b3543;--text:#e7edf5;--muted:#92a0b3;--green:#59db8b;--red:#ff7185;--yellow:#f3c969;--blue:#78b7ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:1240px;margin:auto;padding:24px 16px 48px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
h1{font-size:24px;margin:0}.sub{color:var(--muted);margin:5px 0 0}
.actions{display:flex;gap:8px}.btn{color:var(--text);text-decoration:none;border:1px solid var(--border);background:#111720;padding:7px 11px;border-radius:7px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px}
.note{margin:18px 0;padding:14px 16px;border-left:4px solid var(--yellow);background:var(--card);border-radius:12px}
.note b{color:var(--yellow)}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
.stat{padding:14px}.stat .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}
.stat .value{font:700 22px/1.25 ui-monospace,Consolas,monospace;margin-top:4px}
.muted{color:var(--muted)}.section{margin-top:26px}.section h2{font-size:15px;margin:0 0 4px}
.section .hint{color:var(--muted);font-size:12px;margin:0 0 10px}
.tablebox{overflow:auto;border:1px solid var(--border);border-radius:10px}
table{width:100%;border-collapse:collapse;white-space:nowrap;background:#10161e}
th,td{padding:8px 10px;border-bottom:1px solid #222c38;text-align:left}
th{color:var(--muted);font-size:11px;text-transform:uppercase}
.num{text-align:right;font-variant-numeric:tabular-nums}
.pos{color:var(--green);font-weight:700}.neg{color:var(--red);font-weight:700}
.ctrl td{background:#131820;color:var(--muted)}
.tag{display:inline-block;font-size:10px;font-weight:800;letter-spacing:.06em;padding:2px 6px;border-radius:4px}
.tag.no{background:#3a1f27;color:var(--red)}.tag.yes{background:#1d3d2a;color:var(--green)}
.tag.weak{background:#2b2620;color:var(--yellow);font-weight:600}
.switch{display:flex;gap:8px;align-items:center;margin:14px 0 4px;flex-wrap:wrap}
.switch .btn.on{background:#1d3d2a;border-color:#2f7d54;color:var(--green);font-weight:700}
.market-head{margin:26px 0 0;padding:10px 14px;border-radius:10px;background:#131a24;border:1px solid var(--border);border-left:4px solid var(--blue)}
.market-head h2{margin:0;font-size:16px;color:var(--text);text-transform:none;letter-spacing:0}
.market-head .src{color:var(--muted);font-size:12px}
.error{background:#421923;border:1px solid #8d3041;color:#ffbac5;padding:12px;border-radius:8px;margin:15px 0}
.foot{color:var(--muted);font-size:12px;margin-top:18px}
.empty{padding:18px;color:var(--muted)}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div>
      <h1>Pemantau ROI Market Babak Kedua</h1>
      <p class="sub">Aturan diukur terhadap taruhan yang benar-benar bisa dipasang di titik masuk babak kedua.</p>
    </div>
    <div class="actions">
      <a class="btn" href="vsoccer-live.php">← Live</a>
      <a class="btn" href="check-super-accuracy.php">Akurasi pattern</a>
      <a class="btn" href="forward-test.php">Tes maju</a>
      <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
    </div>
  </div>

  <div class="switch">
    <span class="muted">Pasar:</span>
    <?php foreach (['vsoccer' => 'V-Soccer', 'saba' => 'SABA', 'semua' => 'Bandingkan dua-duanya'] as $kode => $nama): ?>
      <a class="btn<?= $pasarAktif === $kode ? ' on' : '' ?>" href="?pasar=<?= e($kode) ?>"><?= e($nama) ?></a>
    <?php endforeach; ?>
    <span class="muted" style="margin-left:auto">
      V-Soccer <?= count($rows) ?> match · SABA <?= count($sabaRows) ?> match
    </span>
  </div>

  <div class="note">
    <b>Cara membaca halaman ini.</b> ROI tinggi bukan bukti. Yang menentukan tiga hal:
    <b>(1)</b> kolom <b>t</b> harus melewati <?= T_BONFERRONI ?> — itu uji-t atas P/L per taruhan,
    sudah diperketat karena halaman ini menguji sekitar 50 kombinasi aturan × durasi
    sehingga t 1,96 saja akan meloloskan dua-tiga aturan kosong;
    <b>(2)</b> ROI harus positif di banyak hari berbeda, bukan cuma satu; dan
    <b>(3)</b> kedua kaki (Over dan Under) sebaiknya sama-sama positif — aturan yang untungnya
    menumpuk di satu kaki saja biasanya cuma mengikuti arah yang kebetulan panas.
    Baris <span class="muted">KONTROL</span> adalah aturan tanpa logika apa pun — kalau aturan sungguhan
    tidak jelas mengalahkannya, aturan itu tidak menambah nilai. Kalau kedua kontrol
    berkebalikan tajam antar hari, yang terlihat rentetan gol, bukan edge.
    <br><br>
    <b>Win rate di sini tidak menghitung push di penyebut</b>, supaya sebanding dengan breakeven
    yang dihitung dari odds. Line seperempat yang berakhir setengah-menang dihitung 0,5 —
    itu sebabnya kolom menang kadang berkoma.
    <br><br>
    Aturan bertanda <span class="tag weak">lemah</span> (R1–R5) memakai patokan line titik masuk.
    Uji perbandingan menunjukkan patokan <b>line kickoff</b> (R6–R8) lebih baik. Utamakan R6–R8.
    <br><br>
    <b>Menit.</b> Ambang menit ditulis dalam skala sepakbola 90 menit. Jam SABA jauh lebih pendek
    (babak pertama 0–7 untuk liga 15 menit), jadi menitnya diskalakan lebih dulu dan label di tabel
    SABA menampilkan menit versi SABA.
  </div>

  <?php if ($tampilVsoccer): ?>
  <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <div class="market-head">
    <h2>V-Soccer</h2>
    <span class="src">goal_log_vsoccer.csv · titik masuk: line m46 (menit 46 babak kedua) · jam 0–90</span>
  </div>

  <section class="grid">
    <div class="card stat">
      <div class="label">Match siap dipakai</div>
      <div class="value"><?= count($rows) ?></div>
      <div class="muted">dari <?= $stats['total'] ?> baris CSV</div>
    </div>
    <div class="card stat">
      <div class="label">Hari terkumpul</div>
      <div class="value"><?= count($days) ?></div>
      <div class="muted"><?= count($days) < 5 ? 'butuh ≥ 5 hari' : 'cukup untuk uji lintas hari' ?></div>
    </div>
    <div class="card stat">
      <div class="label">Korelasi gol 1H ↔ 2H</div>
      <div class="value"><?= $corr ? ($corr['r'] >= 0 ? '+' : '−') . number_format(abs($corr['r']), 3, ',', '.') : '–' ?></div>
      <div class="muted"><?= $corr ? ($corr['sig'] ? 'signifikan (t=' . number_format($corr['t'], 2, ',', '.') . ')' : 'tidak signifikan') : '–' ?></div>
    </div>
    <div class="card stat">
      <div class="label">Baris dibuang</div>
      <div class="value"><?= $stats['no_goals'] + $stats['bad_odds'] + $stats['no_line'] ?></div>
      <div class="muted">gol kosong <?= $stats['no_goals'] ?> · odds <?= $stats['bad_odds'] ?> · line <?= $stats['no_line'] ?></div>
    </div>
  </section>

  <section class="section">
    <h2>Performa aturan (seluruh data)</h2>
    <p class="hint">Kolom <b>TERBUKTI</b> hanya menyala kalau t &gt; <?= T_BONFERRONI ?>.
      <span class="tag weak">LEWAT 95%</span> berarti t &gt; 1,96 — menarik, tapi belum tahan
      terhadap banyaknya aturan yang diuji di halaman ini.</p>
    <?php if (!$rows): ?>
      <div class="card empty">Belum ada match yang bisa dipakai.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Aturan</th><th class="num">n</th><th class="num">Menang</th>
        <th class="num" title="Push tidak dihitung di penyebut.">Win rate</th><th class="num" title="Uji-t atas P/L per taruhan.">t</th><th class="num">Breakeven</th>
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num">Hari +</th><th>Terbukti</th>
      </tr></thead>
      <tbody>
      <?php foreach ($results as $code => $res) {
          echo barisAturan($code, $res, count($days), []);
      } ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>ROI per hari — ini ukuran konsistensi</h2>
    <p class="hint">Aturan yang nyata positif di banyak hari. Positif di satu hari saja tidak berarti apa-apa.</p>
    <?php if (!$days): ?>
      <div class="card empty">Belum ada data.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr><th>Kode</th><th>Aturan</th>
        <?php foreach ($days as $d): ?><th class="num"><?= e(substr($d, 0, 5)) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($results as $code => $res) {
          echo barisHarian($code, $res, $days, []);
      } ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>V-Soccer — market vs kenyataan per total gol babak pertama</h2>
    <p class="hint">Dasar mekanisme R1/R2: kolom tuntutan market cenderung rata, kolom aktual naik mengikuti gol 1H. Kalau pola itu hilang, R1/R2 kehilangan alasannya.</p>
    <?php if (!$byHt): ?>
      <div class="card empty">Belum ada data.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr><th>Gol HT</th><th class="num">n</th><th class="num">Market tuntut</th><th class="num">Aktual gol 2H</th><th class="num">Selisih</th></tr></thead>
      <tbody>
      <?php foreach ($byHt as $k => $v):
        $dem = $v['demand'] / $v['n'];
        $act = $v['actual'] / $v['n'];
        $dif = $act - $dem; ?>
        <tr>
          <td><?= e($k) ?></td>
          <td class="num"><?= $v['n'] ?></td>
          <td class="num"><?= number_format($dem, 2, ',', '.') ?></td>
          <td class="num"><?= number_format($act, 2, ',', '.') ?></td>
          <td class="num <?= $dif >= 0 ? 'pos' : 'neg' ?>"><?= ($dif >= 0 ? '+' : '−') . number_format(abs($dif), 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; /* tampilVsoccer */ ?>

  <?php if ($tampilSaba): ?>
  <?php if ($sabaError !== null): ?><div class="error"><?= e($sabaError) ?></div><?php endif; ?>

  <div class="market-head">
    <h2>SABA</h2>
    <span class="src">
      goal_log_bpvm.csv · titik masuk: market O/U saat status H.Time ·
      jam <?= $sabaDurasi ? e(implode(' / ', array_map(static fn($h, $t) => "0–" . (int)$h . " ({$t}m)", $sabaDurasi, array_keys($sabaDurasi)))) : 'pendek' ?>
    </span>
  </div>

  <?php if (!$sabaRows && $sabaError === null): ?>
    <div class="card empty" style="margin-top:12px">
      Belum ada match SABA yang siap dipakai (dari <?= $sabaStats['total'] ?> baris:
      <?= $sabaStats['no_ht'] ?> tanpa skor HT, <?= $sabaStats['bad_odds'] ?> odds H.Time tidak sah,
      <?= $sabaStats['invalid_ht_line'] ?> line H.Time di bawah skor HT).
    </div>
  <?php elseif ($sabaRows): ?>
  <section class="section">
    <h2>Performa aturan per durasi SABA</h2>
    <p class="hint">Evaluasi dipisah berdasarkan durasi liga. Jangan mencampur 15m, 16m, dan 20m saat mengambil keputusan.</p>
    <p class="hint"><b>R2/R10/R11 SABA dikalibrasi terpisah:</b> R2 memakai ambang htTot ≥ 3 pada
      15m/16m dan ≥ 4 pada 20m. R10/R11 pada 15m/16m memakai konsensus positif
      tanpa ambang absolut 1; 20m tetap memakai HT 4-5. Menit R11 juga mengikuti durasi.
      Ini hanya evaluasi monitor, bukan sinyal live.</p>
    <?php foreach ($sabaPerDurasi as $durasi => $bagian):
        $hasilDurasi = $bagian['results'];
        $hariDurasi = $bagian['days'];
        $labelDurasi = $durasi === 'lain' ? 'durasi tidak dikenal' : $durasi . ' menit'; ?>
    <div class="market-head">
      <h2>SABA <?= e($labelDurasi) ?></h2>
      <span class="src"><?= count($bagian['rows']) ?> match · <?= count($hariDurasi) ?> hari</span>
    </div>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Aturan</th><th class="num">n</th><th class="num">Menang</th>
        <th class="num" title="Push tidak dihitung di penyebut.">Win rate</th><th class="num" title="Uji-t atas P/L per taruhan.">t</th><th class="num">Breakeven</th>
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num">Hari +</th><th>Terbukti</th>
      </tr></thead>
      <tbody>
      <?php foreach ($hasilDurasi as $code => $res) {
          echo barisAturan($code, $res, count($hariDurasi), $sabaLabel);
      } ?>
       <?php foreach (($sabaParityPerDurasi[$durasi]['results'] ?? []) as $code => $res) {
           echo barisParitasLebar(
               $code, $res, count($sabaParityPerDurasi[$durasi]['days'] ?? [])
           );
       } ?>
      </tbody>
    </table></div>
    <h3 style="margin:14px 0 4px;font-size:14px">ROI per hari — SABA <?= e($labelDurasi) ?></h3>
    <?php if (!$hariDurasi): ?>
      <div class="card empty">Belum ada data harian.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr><th>Kode</th><th>Aturan</th>
        <?php foreach ($hariDurasi as $d): ?><th class="num"><?= e(substr($d, 0, 5)) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($hasilDurasi as $code => $res) {
          echo barisHarian($code, $res, $hariDurasi, $sabaLabel);
      } ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php endforeach; ?>
  </section>

  <section class="section">
    <h2>Gabungan semua durasi (referensi saja)</h2>
    <p class="hint">
      <?= count($sabaRows) ?> match siap dipakai dari <?= $sabaStats['total'] ?> baris ·
      dibuang: <?= $sabaStats['no_ht'] ?> tanpa skor HT, <?= $sabaStats['bad_odds'] ?> odds H.Time tidak sah/terkunci,
      <?= $sabaStats['invalid_ht_line'] ?> line H.Time di bawah skor HT ·
      <?= $sabaStats['no_ko'] ?> tanpa odds kickoff (R6–R8 melewatinya).
      Ambang menit di R3/R4 sudah diterjemahkan ke jam SABA. Gunakan tabel per durasi di atas untuk keputusan.
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Aturan</th><th class="num">n</th><th class="num">Menang</th>
        <th class="num" title="Push tidak dihitung di penyebut.">Win rate</th><th class="num" title="Uji-t atas P/L per taruhan.">t</th><th class="num">Breakeven</th>
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num">Hari +</th><th>Terbukti</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sabaResults as $code => $res) {
          echo barisAturan($code, $res, count($sabaDays), $sabaLabel);
      } ?>
      <?php foreach ($sabaParityResults as $code => $res) {
          echo barisParitasLebar($code, $res, count($sabaDays));
      } ?>
      </tbody>
    </table></div>
  </section>

  <section class="section">
    <h2>ROI per hari — gabungan semua durasi (referensi)</h2>
    <div class="tablebox"><table>
      <thead><tr><th>Kode</th><th>Aturan</th>
        <?php foreach ($sabaDays as $d): ?><th class="num"><?= e(substr($d, 0, 5)) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($sabaResults as $code => $res) {
          echo barisHarian($code, $res, $sabaDays, $sabaLabel);
      } ?>
      </tbody>
    </table></div>
    <p class="foot">
      Aturan yang nyata mestinya bertahan di <b>dua pasar</b>, bukan cuma satu — bandingkan dengan tabel V-Soccer.
    </p>
  </section>
  <section class="section">
    <h2>Prediksi hasil genap/ganjil SABA</h2>
    <p class="hint">
      <b>R2C</b> bukan taruhan Over/Under. Prediksi hasil akhir: jika HT total
      >= 4, targetnya <b>genap</b>; jika HT total <= 3, targetnya <b>ganjil</b>.
      Akurasi dihitung dari total gol FT home + away.
      ROI tidak dihitung karena CSV belum menyimpan odds market Odd/Even.
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Formula</th><th class="num">n</th><th class="num">Tepat</th>
        <th class="num">Akurasi</th><th class="num">CI 95%</th><th class="num">ROI</th><th class="num">Hari >=50%</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sabaParityResults as $code => $res) {
          echo barisParitas($code, $res, count($sabaDays));
      } ?>
      </tbody>
    </table></div>
  </section>

  <?php endif; ?>
  <?php endif; /* tampilSaba */ ?>

  <p class="foot">
    Baca-saja · baris tanpa hasil yang bisa diverifikasi dan odds di luar
    <?= number_format(ODDS_BOOK_MIN, 2, ',', '.') ?>–<?= number_format(ODDS_BOOK_MAX, 2, ',', '.') ?>
    (implied probability) dibuang · halaman refresh otomatis tiap 2 menit.
  </p>
</main>
</body>
</html>
