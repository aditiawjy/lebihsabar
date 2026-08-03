<?php
/**
 * Pencatat tes maju.
 *
 * Semua angka di market-2h-monitor.php dihitung atas data yang sama dengan data
 * yang melahirkan aturannya. Halaman ini melakukan satu hal yang tidak bisa
 * dilakukan halaman itu: mengukur aturan HANYA pada match yang terjadi setelah
 * aturannya dikunci, dengan ambang lolos yang ditetapkan di muka.
 *
 * Ambang tidak boleh ditentukan setelah melihat hasil. Karena itu ambang di sini
 * dihitung dari simpangan baku data in-sample dan target jumlah taruhan, bukan
 * dari performa pasca-kunci.
 */

$pasarAktif = 'semua';
require __DIR__ . '/market-lib.php';

// Data yang ada berhenti 02/08/2026, jadi semuanya sudah terlihat saat aturan
// dibuat. Semua match sejak tanggal ini belum pernah dipakai menyusun aturan.
const TES_MULAI_DEFAULT = '03/08/2026';
// Satu sisi, 95%. Ambang ROI = Z * sd / sqrt(target).
const Z_SATU_SISI = 1.645;

$mulaiTeks = (string)($_GET['mulai'] ?? TES_MULAI_DEFAULT);
$mulaiDt = DateTime::createFromFormat('d/m/Y', $mulaiTeks);
if (!$mulaiDt || $mulaiDt->format('d/m/Y') !== $mulaiTeks) {
    $mulaiTeks = TES_MULAI_DEFAULT;
    $mulaiDt = DateTime::createFromFormat('d/m/Y', $mulaiTeks);
}
$mulaiTs = $mulaiDt->setTime(0, 0)->getTimestamp();

/**
 * Kandidat yang sedang diuji. Target dan alasannya sengaja ditulis di sini
 * supaya terlihat bahwa keduanya ditetapkan sebelum datanya ada.
 */
$KANDIDAT = [
    [
        'kode' => 'R1A', 'durasi' => '15', 'target' => 200,
        'alasan' => 'Kedua kaki positif (Under +16,0% n=49, Over +15,0% n=68), kedua hari '
            . 'positif, sampel terbesar, dan mekanismenya masuk akal: hanya pasang saat line '
            . 'tinggal ≤1 gol di atas skor. Kandidat terkuat sejauh ini.',
    ],
    [
        'kode' => 'R2A', 'durasi' => '20', 'target' => 100,
        'alasan' => 'ROI tertinggi, tapi 84% taruhannya cuma kaki Under dan pembagi genap/ganjil '
            . 'tidak punya mekanisme. Sel HT 2 (+15,7%) merusak klaim paritasnya. Diuji justru '
            . 'karena besar kemungkinan gugur.',
    ],
    [
        'kode' => 'R2', 'durasi' => '20', 'target' => 100,
        'alasan' => 'Pembanding untuk R2A: kalau R2A memang menambah nilai di atas R2, R2A harus '
            . 'lolos sementara R2 tidak.',
    ],
    [
        'kode' => 'R10', 'durasi' => null, 'target' => 100,
        'alasan' => 'Satu-satunya aturan yang positif di ketiga durasi SABA (+11,3% / +28,9% / '
            . '+44,3%). Konsistensi lintas durasi lebih meyakinkan daripada ROI tinggi di satu tempat.',
    ],
];

/** Baris SABA untuk satu kandidat: durasi tertentu, atau semua durasi. */
function barisKandidat(?string $durasi, array $perDurasi, array $semua): array
{
    if ($durasi === null) {
        return $semua;
    }
    return $perDurasi[$durasi]['rows'] ?? [];
}

/** Log per taruhan supaya bisa dicocokkan satu per satu dengan taruhan nyata. */
function logTaruhan(array $rows, callable $pick): array
{
    $log = [];
    foreach ($rows as $r) {
        $out = $pick($r);
        if ($out === null) {
            continue;
        }
        if (is_array($out)) {
            [$side, $lineText, $od] = $out;
        } else {
            $side = $out;
            $lineText = $r['line'];
            $od = $side === 'over' ? $r['over'] : $r['under'];
        }
        $d = settleRinci($r['ft'], $lineText, (float)$od, $side);
        if ($d === null) {
            continue;
        }
        $log[] = [
            'waktu' => $r['datetime'], 'match' => $r['home'] . ' v ' . $r['away'],
            'ht' => $r['ht'], 'line' => $lineText, 'mid' => $r['mid'],
            'sisi' => $side, 'odds' => $od, 'ft' => $r['ft'], 'pl' => $d['pl'],
        ];
    }
    return $log;
}

$hasil = [];
foreach ($KANDIDAT as $k) {
    $aturan = $SABA_RULES[$k['kode']] ?? null;
    if (!$aturan) {
        continue;
    }
    $semuaBaris = barisKandidat($k['durasi'], $sabaPerDurasi, $sabaRows);
    $sebelum = array_values(array_filter($semuaBaris, static fn($r) => $r['ts'] < $mulaiTs));
    $sesudah = array_values(array_filter($semuaBaris, static fn($r) => $r['ts'] >= $mulaiTs));

    $insample = $sebelum ? evaluate($sebelum, $aturan['pick']) : null;
    $maju = $sesudah ? evaluate($sesudah, $aturan['pick']) : null;

    // Ambang dihitung dari sd in-sample dan target -- bukan dari hasil pasca-kunci.
    $sd = $insample['sd'] ?? null;
    $ambang = $sd !== null && $k['target'] > 0
        ? Z_SATU_SISI * $sd / sqrt($k['target']) * 100
        : null;

    $n = $maju['n'] ?? 0;
    if ($n < $k['target']) {
        $status = 'BELUM CUKUP';
        $statusKelas = 'no';
    } elseif ($ambang !== null && $maju['roi'] >= $ambang) {
        $status = 'LOLOS';
        $statusKelas = 'yes';
    } else {
        $status = 'GUGUR';
        $statusKelas = 'no';
    }

    $hasil[] = [
        'k' => $k, 'label' => $aturan['label'], 'insample' => $insample, 'maju' => $maju,
        'ambang' => $ambang, 'status' => $status, 'kelas' => $statusKelas,
        'log' => logTaruhan($sesudah, $aturan['pick']),
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tes Maju — Aturan Babak Kedua</title>
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
.kand{margin-top:22px;padding:16px;background:var(--card);border:1px solid var(--border);border-radius:12px}
.kand h2{margin:0 0 2px;font-size:16px}
.kand .rule{color:var(--muted);font-size:12px;margin:0 0 10px}
.kand .why{color:var(--muted);font-size:12px;margin:10px 0 0;padding-left:10px;border-left:2px solid var(--border)}
.metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:10px}
@media(max-width:900px){.metrics{grid-template-columns:repeat(2,1fr)}}
.m{padding:10px;background:#10161e;border:1px solid var(--border);border-radius:8px}
.m .label{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}
.m .value{font:700 18px/1.3 ui-monospace,Consolas,monospace;margin-top:3px}
.bar{height:7px;background:#10161e;border:1px solid var(--border);border-radius:5px;overflow:hidden;margin-top:10px}
.bar i{display:block;height:100%;background:var(--blue)}
.tablebox{overflow:auto;border:1px solid var(--border);border-radius:10px;margin-top:12px}
table{width:100%;border-collapse:collapse;white-space:nowrap;background:#10161e}
th,td{padding:7px 10px;border-bottom:1px solid #222c38;text-align:left}
th{color:var(--muted);font-size:11px;text-transform:uppercase}
.num{text-align:right;font-variant-numeric:tabular-nums}
.pos{color:var(--green);font-weight:700}.neg{color:var(--red);font-weight:700}
.tag{display:inline-block;font-size:10px;font-weight:800;letter-spacing:.06em;padding:2px 6px;border-radius:4px}
.tag.no{background:#3a1f27;color:var(--red)}.tag.yes{background:#1d3d2a;color:var(--green)}
.muted{color:var(--muted)}
.empty{padding:14px;color:var(--muted);font-size:13px}
.foot{color:var(--muted);font-size:12px;margin-top:22px}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div>
      <h1>Tes Maju</h1>
      <p class="sub">Aturan diukur <b>hanya</b> pada match sejak <?= e($mulaiTeks) ?> — data yang belum pernah dipakai menyusun aturannya.</p>
    </div>
    <div class="actions">
      <a class="btn" href="market-2h-monitor.php?pasar=saba">← Monitor</a>
      <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
    </div>
  </div>

  <div class="note">
    <b>Kenapa halaman ini ada.</b> ROI di halaman monitor dihitung atas data yang sama dengan data
    yang melahirkan aturannya, jadi angka bagus di sana belum berarti apa-apa — aturan apa pun bisa
    dibuat bagus kalau boleh disetel sambil melihat jawabannya. Di sini aturannya tidak boleh diubah
    lagi, dan <b>ambang lolos sudah dikunci di muka</b>: ambang ROI = 1,645 × sd in-sample ÷ √target.
    Kalau ROI pasca-kunci tidak mencapai ambang setelah target taruhan terpenuhi, aturan itu gugur.
    <br><br>
    Yang membuat tes ini sah cuma satu hal: <b>ambangnya ditetapkan sebelum datanya ada.</b> Kalau
    nanti angkanya meleset sedikit lalu ambangnya diturunkan, tesnya kehilangan seluruh maknanya.
  </div>

  <?php if (!$sabaRows): ?>
    <div class="empty card">Belum ada data SABA yang bisa dipakai (<?= e($sabaError ?? 'goal_log_bpvm.csv kosong') ?>).</div>
  <?php endif; ?>

  <?php foreach ($hasil as $h):
      $k = $h['k'];
      $maju = $h['maju'];
      $n = $maju['n'] ?? 0;
      $persen = $k['target'] > 0 ? min(100, 100 * $n / $k['target']) : 0; ?>
    <section class="kand">
      <h2><?= e($k['kode']) ?> · SABA <?= $k['durasi'] === null ? 'semua durasi' : e($k['durasi']) . ' menit' ?>
        <span class="tag <?= $h['kelas'] ?>" style="margin-left:6px"><?= $h['status'] ?></span></h2>
      <p class="rule"><?= e($h['label']) ?></p>

      <div class="metrics">
        <div class="m">
          <div class="label">Taruhan terkumpul</div>
          <div class="value"><?= $n ?> <span class="muted" style="font-size:13px">/ <?= $k['target'] ?></span></div>
        </div>
        <div class="m">
          <div class="label">ROI pasca-kunci</div>
          <div class="value <?= $maju ? ($maju['roi'] >= 0 ? 'pos' : 'neg') : '' ?>">
            <?= $maju ? signed($maju['roi']) : '–' ?></div>
        </div>
        <div class="m">
          <div class="label">Ambang lolos</div>
          <div class="value"><?= $h['ambang'] === null ? '–' : signed($h['ambang']) ?></div>
        </div>
        <div class="m">
          <div class="label">Menang (tanpa push)</div>
          <div class="value"><?= $maju ? pct($maju['winrate']) : '–' ?></div>
        </div>
        <div class="m">
          <div class="label">In-sample <span class="muted">(pembanding)</span></div>
          <div class="value muted" style="font-size:15px">
            <?= $h['insample'] ? signed($h['insample']['roi']) . ' · n=' . $h['insample']['n'] : '–' ?></div>
        </div>
      </div>
      <div class="bar"><i style="width:<?= number_format($persen, 1, '.', '') ?>%"></i></div>

      <p class="why"><b>Kenapa diuji:</b> <?= e($k['alasan']) ?>
        <?php if ($h['ambang'] !== null && $h['insample']): ?>
          <br><b>Asal ambang:</b> sd in-sample <?= number_format($h['insample']['sd'], 3, ',', '.') ?>
          ÷ √<?= $k['target'] ?> × 1,645 = <?= signed($h['ambang']) ?>.
        <?php endif; ?>
      </p>

      <?php if (!$h['log']): ?>
        <div class="empty">Belum ada match yang memenuhi syarat sejak <?= e($mulaiTeks) ?>.
          Halaman ini akan terisi sendiri begitu data baru masuk.</div>
      <?php else: ?>
        <div class="tablebox"><table>
          <thead><tr>
            <th>Waktu</th><th>Match</th><th>HT</th><th>Line</th><th class="num">Mid</th>
            <th>Sisi</th><th class="num">Odds</th><th class="num">FT</th><th class="num">P/L</th>
          </tr></thead>
          <tbody>
          <?php foreach ($h['log'] as $b): ?>
            <tr>
              <td><?= e($b['waktu']) ?></td>
              <td><?= e($b['match']) ?></td>
              <td><?= e($b['ht']) ?></td>
              <td><?= e($b['line']) ?></td>
              <td class="num"><?= number_format($b['mid'], 2, ',', '.') ?></td>
              <td><?= $b['sisi'] === 'over' ? 'Over' : 'Under' ?></td>
              <td class="num"><?= number_format((float)$b['odds'], 2, ',', '.') ?></td>
              <td class="num"><?= (int)$b['ft'] ?></td>
              <td class="num <?= $b['pl'] > 0 ? 'pos' : ($b['pl'] < 0 ? 'neg' : 'muted') ?>">
                <?= abs($b['pl']) < 1e-9
                      ? 'push'
                      : ($b['pl'] > 0 ? '+' : '−') . number_format(abs($b['pl']), 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <p class="foot">
    Baca-saja · sumber <code>goal_log_bpvm.csv</code> · definisi aturan, loader, dan settlement
    dipakai bersama <code>market-2h-monitor.php</code> lewat <code>market-lib.php</code>, jadi
    tidak mungkin melenceng satu sama lain · ganti awal periode dengan <code>?mulai=dd/mm/yyyy</code>.
  </p>
</main>
</body>
</html>
