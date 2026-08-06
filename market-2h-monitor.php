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

/** Rincian match yang dipakai satu aturan, untuk simulator stake di browser. */
function simulasiMatchRows(array $rows, callable $pick): array
{
    $matches = [];
    foreach ($rows as $r) {
        $out = $pick($r);
        if ($out === null) {
            continue;
        }
        if (is_array($out)) {
            [$side, $lineText, $odds] = $out;
        } else {
            $side = $out;
            $lineText = $r['line'];
            $odds = $side === 'over' ? $r['over'] : $r['under'];
        }
        $detail = settleRinci((int)$r['ft'], (string)$lineText, (float)$odds, (string)$side);
        if ($detail === null) {
            continue;
        }
        $pl = (float)$detail['pl'];
        $matches[] = [
            'date' => (string)($r['datetime'] ?? ''),
            'home' => (string)($r['home'] ?? ''),
            'away' => (string)($r['away'] ?? ''),
            'ht' => (string)($r['ht'] ?? ''),
            'goals2h' => (int)($r['goals_2h'] ?? 0),
            'ft' => (int)($r['ft'] ?? 0),
            'side' => strtoupper((string)$side),
            'line' => (string)$lineText,
            'odds' => (float)$odds,
            'pl' => $pl,
            'outcome' => $pl > 0 ? 'WIN' : ($pl < 0 ? 'LOSE' : 'PUSH'),
        ];
    }
    return $matches;
}

$SIMULATION_REGISTRY = [];

/** Satu baris tabel performa aturan. */
function barisAturan(
    string $code,
    array $res,
    int $jumlahHari,
    array $labelKhusus,
    array $rows = [],
    string $simId = '',
    string $marketLabel = 'Market'
): string
{
    global $SIMULATION_REGISTRY;
    $a = $res['all'];
    $kelas = !empty($res['control']) ? ' class="ctrl"' : '';
    $tagLemah = !empty($res['weak'])
        ? ' <span class="tag weak" title="Dibangun di atas patokan line titik masuk (sinyal-A). Uji menunjukkan patokan line kickoff lebih baik.">lemah</span>'
        : '';
    // Aturan yang lahir dari mencari sel terbaik di data ini juga. Angkanya di
    // sini otomatis terlalu bagus; penilaian sebenarnya ada di forward-test.php.
    if (!empty($res['insample'])) {
        $tagLemah .= ' <span class="tag no" title="Diturunkan dari data ini juga, jadi angkanya di tabel ini terlalu bagus. Penilaian sebenarnya ada di halaman Tes maju.">in-sample</span>';
    }
    $label = e($labelKhusus[$code] ?? $res['label']);
    if ($a && $rows && $simId !== '' && isset($res['pick'])) {
        $SIMULATION_REGISTRY[$simId] = simulasiMatchRows($rows, $res['pick']);
    }
    $html = "<tr{$kelas}><td><b>" . e($code) . "</b>{$tagLemah}</td><td>{$label}</td>";
    if (!$a) {
        return $html . '<td class="num" colspan="11"><span class="muted">tidak ada sampel</span></td></tr>';
    }
    $xp = $res['ex_peak'] ?? null;
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
        . '<td class="num ' . ($xp ? ($xp['roi'] >= 0 ? 'pos' : 'neg') : 'muted') . '"'
        . ' title="' . ($xp
            ? 'ROI setelah hari ' . e((string)$res['peak_day']) . ' (penyumbang terbesar) dibuang, sisa '
              . $xp['n'] . ' taruhan. Kalau angka ini runtuh, keunggulannya cuma bertumpu satu hari.'
            : 'Butuh lebih dari satu hari data.') . '">'
        . ($xp ? signed($xp['roi']) : '–') . '</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . $tagKelas . '">' . $tagTeks . '</span></td>'
        . '<td class="sim-cell"><button type="button" class="sim-btn"'
        . ' data-simulate'
        . ' data-sim-id="' . e($simId) . '"'
        . ' data-sim-market="' . e($marketLabel) . '"'
        . ' data-sim-code="' . e($code) . '"'
        . ' data-sim-label="' . e($labelKhusus[$code] ?? $res['label']) . '"'
        . ' data-sim-n="' . e($a['n']) . '"'
        . ' data-sim-roi="' . e(number_format((float)$a['roi'], 6, '.', '')) . '">'
        . 'Coba</button></td>';
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
        . '<td class="num" title="Odds minimal supaya akurasi ini menghasilkan untung. Di bawah angka ini, tebakan benar pun tetap rugi.">'
        . ($a['odds_min'] === null ? '–' : number_format($a['odds_min'], 2, ',', '.')) . '</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . ($a['proven'] ? 'yes' : 'no') . '">' . $status . '</span></td></tr>';
}

/** Baris paritas dengan lebar kolom tabel performa Over/Under. */
function barisParitasLebar(string $code, array $res, int $jumlahHari): string
{
    $a = $res['all'];
    if (!$a) {
        return '<tr><td><b>' . e($code) . '</b></td><td>' . e($res['label'])
            . '</td><td class="num" colspan="11"><span class="muted">tidak ada sampel</span></td></tr>';
    }
    $xpP = $res['ex_peak'] ?? null;
    $status = $a['proven'] ? 'YA' : 'BELUM';
    $plP = ($a['pl'] >= 0 ? '+' : '−') . number_format(abs($a['pl']), 2, ',', '.');
    return '<tr><td><b>' . e($code) . '</b></td><td>' . e($res['label']) . '</td>'
        . '<td class="num">' . $a['n'] . '</td>'
        . '<td class="num">' . $a['correct'] . '/' . $a['n'] . '</td>'
        . '<td class="num">' . pct($a['accuracy']) . '</td>'
        . '<td class="num">' . number_format($a['t'], 2, ',', '.') . '</td>'
        . '<td class="num">' . pct($a['breakeven'], 0) . '</td>'
        . '<td class="num ' . ($a['pl'] >= 0 ? 'pos' : 'neg') . '">' . $plP . '</td>'
        . '<td class="num ' . ($a['roi'] >= 0 ? 'pos' : 'neg') . '"'
        . ' title="Dihitung pada odds ASUMSI ' . number_format(ODDS_PARITAS, 2, ',', '.')
        . ' — CSV tidak menyimpan odds Odd/Even. Odds minimal agar untung: '
        . number_format($a['odds_min'], 2, ',', '.') . '.">' . signed($a['roi']) . '</td>'
        . '<td class="num ' . ($xpP ? ($xpP['roi'] >= 0 ? 'pos' : 'neg') : 'muted') . '">'
        . ($xpP ? signed($xpP['roi']) : '–') . '</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . ($a['proven'] ? 'yes' : 'no') . '">' . $status . '</span></td>'
        . '<td class="sim-cell"><span class="muted">N/A</span></td></tr>';
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
.sim-btn{border:1px solid #3a6b98;background:#162536;color:var(--blue);border-radius:5px;padding:4px 8px;font-family:inherit;font-size:11px;line-height:1.2;font-weight:700;cursor:pointer}
.sim-btn:hover,.sim-btn:focus-visible{background:#1d3a55;border-color:var(--blue);outline:none}
.sim-dialog{width:min(980px,calc(100% - 28px));padding:0;border:1px solid var(--border);border-radius:14px;background:var(--card);color:var(--text);box-shadow:0 24px 70px #0009}
.sim-dialog::backdrop{background:#05080dcc}
.sim-card{padding:20px}
.sim-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:16px}
.sim-kicker{color:var(--blue);font-size:10px;font-weight:800;letter-spacing:.1em;margin:0 0 3px;text-transform:uppercase}
.sim-head h2{font-size:18px;margin:0}.sim-close{border:0;background:transparent;color:var(--muted);font-size:24px;line-height:1;cursor:pointer;padding:0 2px}.sim-close:hover{color:var(--text)}
.sim-summary{color:var(--muted);font-size:12px;margin:0 0 16px}.sim-form-label{display:block;color:var(--muted);font-size:12px;font-weight:700;margin-bottom:6px}
.sim-input{width:100%;border:1px solid var(--border);border-radius:7px;background:#0d141d;color:var(--text);font:700 16px/1.2 ui-monospace,Consolas,monospace;padding:10px 11px}.sim-input:focus{border-color:var(--blue);outline:2px solid #78b7ff33}
.sim-results{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:16px}.sim-result{border:1px solid var(--border);border-radius:8px;background:#111a23;padding:10px}.sim-result .label{color:var(--muted);font-size:11px}.sim-result strong{display:block;font:700 15px/1.3 ui-monospace,Consolas,monospace;margin-top:4px}
.sim-result.pnl-positive{border-color:#2f7d54;background:#142a20}.sim-result.pnl-negative{border-color:#7d3042;background:#2a171d}
.sim-note{color:var(--muted);font-size:11px;margin:14px 0 0}.sim-actions{display:flex;justify-content:flex-end;margin-top:16px}.sim-actions .btn{cursor:pointer;font:inherit}
.sim-matches{margin-top:18px;border-top:1px solid var(--border);padding-top:14px}.sim-matches-head{display:flex;justify-content:space-between;gap:12px;align-items:baseline;margin-bottom:8px}.sim-matches-head strong{font-size:13px}.sim-matches-head span{color:var(--muted);font-size:11px}
.sim-match-list{max-height:250px;overflow:auto;border:1px solid var(--border);border-radius:8px;background:#10161e}.sim-match-table{width:100%;min-width:520px;border:0;font-size:11px}.sim-match-table th,.sim-match-table td{padding:7px 8px}.sim-match-table th{position:sticky;top:0;background:#151f2b;z-index:1}.sim-match-table td{vertical-align:top}.sim-match-table .match-name{white-space:normal;min-width:150px}.sim-match-table .score{color:var(--muted)}.sim-match-table .outcome{font-size:9px;font-weight:800;letter-spacing:.05em}.sim-match-table .outcome.win{color:var(--green)}.sim-match-table .outcome.lose{color:var(--red)}.sim-match-table .outcome.push{color:var(--yellow)}.sim-empty{padding:14px;color:var(--muted);font-size:12px}
@media(max-width:600px){.sim-dialog{width:calc(100% - 16px)}.sim-card{padding:16px}.sim-results{grid-template-columns:1fr 1fr}.sim-match-list{max-height:220px}}
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
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num" title="ROI setelah hari penyumbang terbesar dibuang. Kalau angka ini runtuh, keunggulannya cuma bertumpu satu hari.">ROI −hari terbaik</th><th class="num">Hari +</th><th>Terbukti</th><th>Simulasi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($results as $code => $res) {
          echo barisAturan($code, $res, count($days), [], $rows, 'vsoccer-' . $code, 'M46 market');
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

  <?php
  // Penghitung hari kering gol. Aturan seperti "HT 3-4 -> Under" tidak untung
  // sedikit-sedikit tiap hari; ia rugi tipis berhari-hari lalu dibayar besar
  // pada hari yang salah harga. Jadi yang menentukan untung-rugi jangka panjang
  // bukan ROI rata-rata, melainkan SEBERAPA SERING hari seperti itu datang.
  $hariKering = [];
  foreach ($rows as $r) {
      if ($r['ht_total'] < 3 || $r['ht_total'] > 4) {
          continue; // ukur pada kelompok yang memang ditaruhi
      }
      $d = $r['day'];
      $hariKering[$d]['n'] = ($hariKering[$d]['n'] ?? 0) + 1;
      $hariKering[$d]['minta'] = ($hariKering[$d]['minta'] ?? 0) + $r['demand'];
      $hariKering[$d]['nyata'] = ($hariKering[$d]['nyata'] ?? 0) + $r['goals_2h'];
      $s = settleRinci($r['ft'], $r['line'], (float)$r['under'], 'under');
      if ($s !== null) {
          $hariKering[$d]['pl'] = ($hariKering[$d]['pl'] ?? 0) + $s['pl'];
          $hariKering[$d]['nb'] = ($hariKering[$d]['nb'] ?? 0) + 1;
      }
  }
  $jmlKering = 0;
  $jmlHari = 0;
  $roiKering = [];
  $roiBiasa = [];
  foreach ($hariKering as $d => $v) {
      if ($v['n'] < 5) {
          continue;
      }
      $jmlHari++;
      $sel = $v['nyata'] / $v['n'] - $v['minta'] / $v['n'];
      $roi = !empty($v['nb']) ? 100 * $v['pl'] / $v['nb'] : 0;
      if ($sel <= HARI_KERING_AMBANG) {
          $jmlKering++;
          $roiKering[] = $roi;
      } else {
          $roiBiasa[] = $roi;
      }
      $hariKering[$d]['sel'] = $sel;
      $hariKering[$d]['roi'] = $roi;
      $hariKering[$d]['kering'] = $sel <= HARI_KERING_AMBANG;
  }
  $laju = $jmlHari > 0 ? $jmlKering / $jmlHari : null;
  $rKering = $roiKering ? array_sum($roiKering) / count($roiKering) : null;
  $rBiasa = $roiBiasa ? array_sum($roiBiasa) / count($roiBiasa) : null;
  // Laju impas: berapa sering hari kering harus datang supaya rata-ratanya nol.
  $lajuImpas = ($rKering !== null && $rBiasa !== null && $rKering > $rBiasa)
      ? -$rBiasa / ($rKering - $rBiasa) : null;
  ?>
  <?php if ($jmlHari > 1): ?>
  <section class="section">
    <h2>Penghitung hari kering gol — V-Soccer HT 3–4</h2>
    <p class="hint">
      Aturan "HT 3–4 → Under" tidak untung sedikit-sedikit tiap hari. Ia rugi tipis
      berhari-hari, lalu dibayar besar pada hari ketika gol babak kedua jauh di bawah
      tuntutan market. Karena itu yang menentukan untung-rugi jangka panjangnya bukan
      ROI rata-rata, melainkan <b>seberapa sering hari seperti itu datang</b>.
      Sebuah hari dihitung kering kalau selisihnya ≤ <?= number_format(HARI_KERING_AMBANG, 2, ',', '.') ?> gol
      (potongan bandar sudah tertutup pada sekitar −0,10, jadi ambang ini menandai
      salah harga yang jelas, bukan riak biasa).
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Hari</th><th class="num">n</th><th class="num">Market minta</th>
        <th class="num">Kenyataan</th><th class="num">Selisih</th><th class="num">ROI Under</th><th>Hari kering?</th>
      </tr></thead>
      <tbody>
      <?php foreach ($hariKering as $d => $v):
          if (!isset($v['sel'])) {
              continue;
          } ?>
        <tr>
          <td><?= e($d) ?></td>
          <td class="num"><?= $v['n'] ?></td>
          <td class="num"><?= number_format($v['minta'] / $v['n'], 2, ',', '.') ?></td>
          <td class="num"><?= number_format($v['nyata'] / $v['n'], 2, ',', '.') ?></td>
          <td class="num <?= $v['kering'] ? 'pos' : '' ?>">
            <?= ($v['sel'] >= 0 ? '+' : '−') . number_format(abs($v['sel']), 2, ',', '.') ?></td>
          <td class="num <?= $v['roi'] >= 0 ? 'pos' : 'neg' ?>"><?= signed($v['roi']) ?></td>
          <td><?= $v['kering']
              ? '<span class="tag yes">KERING</span>'
              : '<span class="muted">biasa</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="hint" style="margin-top:10px">
      <b>Laju sejauh ini: <?= $jmlKering ?> hari kering dari <?= $jmlHari ?> hari</b>
      (<?= $laju !== null ? pct($laju * 100, 0) : '–' ?>).
      <?php if ($rKering !== null && $rBiasa !== null): ?>
        Hari kering rata-rata <?= signed($rKering) ?>, hari biasa <?= signed($rBiasa) ?>.
        <?php if ($lajuImpas !== null): ?>
          <br><b>Laju impas: <?= pct($lajuImpas * 100, 1) ?></b> — kira-kira 1 hari kering
          dari setiap <?= number_format(1 / $lajuImpas, 0, ',', '.') ?> hari.
          Selama laju sebenarnya di atas itu, aturannya menguntungkan dalam jangka panjang;
          di bawahnya tidak.
        <?php endif; ?>
      <?php endif; ?>
      <br><br>
      <b>Angka ini butuh berminggu-minggu, bukan berhari-hari.</b> Dengan <?= $jmlHari ?> hari,
      laju yang terukur masih sangat kasar — beda antara "1 dari 4" dan "1 dari 30" belum bisa
      dibedakan, padahal keduanya berujung pada kesimpulan yang berlawanan.
    </p>
  </section>
  <?php endif; ?>

  <?php
  // Per liga. Tempo gol antar liga benar-benar berbeda (4,9 sampai 7,7 gol),
  // tapi bandar sudah menyesuaikan line-nya, jadi yang tersisa cuma selisih
  // kecil yang sejauh ini tidak terbedakan dari derau. Ditampilkan supaya bisa
  // diuji ulang sendiri saat datanya sudah jauh lebih banyak.
  $perLiga = [];
  foreach ($rows as $r) {
      $L = $r['league'] ?? '';
      if ($L === '') {
          continue;
      }
      $d = settleRinci($r['ft'], $r['line'], (float)$r['under'], 'under');
      if ($d === null) {
          continue;
      }
      $perLiga[$L]['n'] = ($perLiga[$L]['n'] ?? 0) + 1;
      $perLiga[$L]['gol'] = ($perLiga[$L]['gol'] ?? 0) + $r['ft'];
      $perLiga[$L]['line'] = ($perLiga[$L]['line'] ?? 0) + $r['mid'];
      $perLiga[$L]['pl'][] = $d['pl'];
  }
  $semuaPl = array_merge(...array_column($perLiga, 'pl') ?: [[]]);
  $chi = 0.0;
  $db = -1;
  if (count($semuaPl) > 2) {
      $mAll = array_sum($semuaPl) / count($semuaPl);
      $vAll = 0.0;
      foreach ($semuaPl as $s) {
          $vAll += ($s - $mAll) ** 2;
      }
      $sdAll = sqrt($vAll / (count($semuaPl) - 1));
      uasort($perLiga, static fn($a, $b) => array_sum($b['pl']) / $b['n'] <=> array_sum($a['pl']) / $a['n']);
      foreach ($perLiga as $v) {
          if ($v['n'] < 10 || $sdAll <= 0) {
              continue;
          }
          $chi += (array_sum($v['pl']) / $v['n'] - $mAll) ** 2 / ($sdAll * $sdAll / $v['n']);
          $db++;
      }
  }
  $kritis = $db > 0 ? $db + 1.645 * sqrt(2 * $db) : null;
  ?>
  <?php if ($db > 0): ?>
  <section class="section">
    <h2>V-Soccer per liga — apakah ada liga yang harus dihindari?</h2>
    <p class="hint">
      Tempo gol antar liga memang jauh berbeda, tapi <b>bandar sudah menyesuaikan line untuk tiap
      liga</b> — kolom selisih menunjukkan sisa ketidaktepatannya. Yang menentukan bukan liga mana
      yang ROI-nya tinggi, melainkan uji di bawah tabel: apakah sebarannya lebih lebar daripada
      kebetulan. Kalau tidak, menghindari liga hanya mengecilkan sampel tanpa menambah apa pun —
      dan besok liga lain yang gantian merah.
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Liga</th><th class="num">n</th><th class="num">Gol FT</th><th class="num">Line m46</th>
        <th class="num">Selisih</th><th class="num">ROI Under</th><th class="num">t</th>
      </tr></thead>
      <tbody>
      <?php foreach ($perLiga as $L => $v):
          if ($v['n'] < 10) {
              continue;
          }
          $m = array_sum($v['pl']) / $v['n'];
          $vv = 0.0;
          foreach ($v['pl'] as $s) {
              $vv += ($s - $m) ** 2;
          }
          $sd = sqrt($vv / ($v['n'] - 1));
          $t = $sd > 0 ? $m / ($sd / sqrt($v['n'])) : 0;
          $sel = $v['gol'] / $v['n'] - $v['line'] / $v['n']; ?>
        <tr>
          <td><?= e(str_replace([' - 12 mins [V]', 'V-Soccer '], '', $L)) ?></td>
          <td class="num"><?= $v['n'] ?></td>
          <td class="num"><?= number_format($v['gol'] / $v['n'], 2, ',', '.') ?></td>
          <td class="num"><?= number_format($v['line'] / $v['n'], 2, ',', '.') ?></td>
          <td class="num"><?= ($sel >= 0 ? '+' : '−') . number_format(abs($sel), 2, ',', '.') ?></td>
          <td class="num <?= $m >= 0 ? 'pos' : 'neg' ?>"><?= signed($m * 100) ?></td>
          <td class="num"><?= number_format($t, 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="hint" style="margin-top:10px">
      <b>Uji keseragaman:</b> chi-kuadrat <b><?= number_format($chi, 2, ',', '.') ?></b> pada
      derajat bebas <?= $db ?> (nilai harapan kalau semua liga sama = <?= $db ?>;
      nilai kritis 95% ≈ <?= number_format($kritis, 1, ',', '.') ?>).
      <?php if ($chi > $kritis): ?>
        <b>Sebarannya lebih lebar daripada kebetulan</b> — perbedaan antar liga mulai layak diselidiki.
      <?php else: ?>
        <b>Tidak ada bukti liga berbeda.</b> Sebaran ROI-nya wajar untuk kebetulan semata, jadi
        jangan menghindari liga mana pun berdasarkan tabel ini.
      <?php endif; ?>
    </p>
  </section>
  <?php endif; ?>

  <?php if ($parityResults): ?>
  <section class="section">
    <h2>Prediksi hasil genap/ganjil V-Soccer</h2>
    <p class="hint">
      Ditampilkan justru karena hasilnya negatif. Babak kedua V-Soccer menghasilkan
      rata-rata ~3,1 gol, dan pada tingkat itu paritas praktis lempar koin —
      keunggulan teoretisnya hanya <b>0,09% di atas 50%</b>. Kolom <b>Odds min</b>
      akan menunjukkan angka di sekitar 2,00 atau lebih, yang tidak pernah ditawarkan
      bandar. Artinya market genap/ganjil V-Soccer <b>pasti rugi</b>, bukan sekadar
      belum terbukti. Bandingkan dengan tabel SABA di bawah, yang babaknya jauh lebih pendek.
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Formula</th><th class="num">n</th><th class="num">Tepat</th>
        <th class="num">Akurasi</th><th class="num">CI 95%</th>
        <th class="num" title="Odds minimal supaya untung.">Odds min</th>
        <th class="num">Hari >=50%</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($parityResults as $code => $res) {
          echo barisParitas($code, $res, count($days));
      } ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>
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
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num" title="ROI setelah hari penyumbang terbesar dibuang. Kalau angka ini runtuh, keunggulannya cuma bertumpu satu hari.">ROI −hari terbaik</th><th class="num">Hari +</th><th>Terbukti</th><th>Simulasi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($hasilDurasi as $code => $res) {
          echo barisAturan(
              $code, $res, count($hariDurasi), $sabaLabel,
              $bagian['rows'], 'saba-' . $durasi . '-' . $code, 'H.Time market'
          );
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
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num" title="ROI setelah hari penyumbang terbesar dibuang. Kalau angka ini runtuh, keunggulannya cuma bertumpu satu hari.">ROI −hari terbaik</th><th class="num">Hari +</th><th>Terbukti</th><th>Simulasi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sabaResults as $code => $res) {
          echo barisAturan(
              $code, $res, count($sabaDays), $sabaLabel,
              $sabaRows, 'saba-all-' . $code, 'H.Time market'
          );
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
      Bukan taruhan Over/Under — yang ditebak paritas <b>total gol FT (home + away)</b>.
      Skor 2–1 → total 3 → ganjil; 2–2 → total 4 → genap.
      <b>ROI tidak bisa dihitung</b> karena CSV tidak menyimpan odds market Odd/Even;
      kolom <b>Odds min</b> menggantikannya — itu harga terendah yang masih membuat
      akurasi tersebut untung. Menang 58% pun tetap rugi kalau dibayar 1,55.
    </p>
    <p class="hint">
      <b>P-HT punya dasar matematis, bukan hasil mengaduk data.</b> Paritas FT =
      paritas HT + paritas gol babak kedua, dan skor HT sudah diketahui — jadi
      menebak paritas FT sama saja menebak paritas gol babak kedua saja. Untuk
      sebaran Poisson berrata-rata λ, peluang jumlah genap = (1 + e<sup>−2λ</sup>) / 2.
      Babak SABA sangat pendek (~0,9 gol pada liga 15 menit) sehingga condong genap
      ~58%; babak V-Soccer menghasilkan ~3,1 gol sehingga peluangnya 50,1% —
      lempar koin, dan mustahil menutup potongan bandar.
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Formula</th><th class="num">n</th><th class="num">Tepat</th>
        <th class="num">Akurasi</th><th class="num">CI 95%</th>
        <th class="num" title="Odds minimal supaya untung. CSV tidak menyimpan odds ganjil/genap, jadi ROI tak bisa dihitung.">Odds min</th>
        <th class="num">Hari >=50%</th><th>Status</th>
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
<dialog id="simulationDialog" class="sim-dialog" aria-labelledby="simulationTitle">
  <form method="dialog" class="sim-card">
    <div class="sim-head">
      <div>
        <p class="sim-kicker">Simulasi historis</p>
        <h2 id="simulationTitle">R1</h2>
      </div>
      <button class="sim-close" type="submit" value="cancel" aria-label="Tutup simulasi">&times;</button>
    </div>
    <p id="simulationSummary" class="sim-summary"></p>
    <label class="sim-form-label" for="simulationStake">Stake per taruhan (Rp)</label>
    <input id="simulationStake" class="sim-input" type="number" min="0" step="1000" value="100000" inputmode="numeric">
    <div class="sim-results">
      <div class="sim-result"><span class="label">Total taruhan</span><strong id="simulationTotalStake">Rp0</strong></div>
      <div id="simulationPnlBox" class="sim-result"><span class="label">P/L historis</span><strong id="simulationPnl">Rp0</strong></div>
      <div class="sim-result"><span class="label">Saldo akhir historis</span><strong id="simulationBalance">Rp0</strong></div>
      <div class="sim-result"><span class="label">ROI aturan</span><strong id="simulationRoi">0%</strong></div>
    </div>
    <div class="sim-matches">
      <div class="sim-matches-head"><strong>Match historis</strong><span id="simulationMatchCount"></span></div>
      <div class="sim-match-list">
        <table class="sim-match-table">
          <thead><tr><th>Tanggal</th><th>Match</th><th>Skor</th><th>Pick</th><th id="simulationMarketHeader">Market</th><th>Odd</th><th>P/L</th></tr></thead>
          <tbody id="simulationMatches"></tbody>
        </table>
      </div>
    </div>
    <p class="sim-note">Perkiraan ini mengalikan ROI historis aturan dengan total stake. Bukan jaminan hasil taruhan berikutnya.</p>
    <div class="sim-actions"><button class="btn" type="submit" value="close">Tutup</button></div>
  </form>
</dialog>
<script id="simulationData" type="application/json"><?= json_encode(
    $SIMULATION_REGISTRY,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script>
(() => {
  const dialog = document.getElementById('simulationDialog');
  const stakeInput = document.getElementById('simulationStake');
  const title = document.getElementById('simulationTitle');
  const summary = document.getElementById('simulationSummary');
  const totalStake = document.getElementById('simulationTotalStake');
  const pnl = document.getElementById('simulationPnl');
  const balance = document.getElementById('simulationBalance');
  const roi = document.getElementById('simulationRoi');
  const pnlBox = document.getElementById('simulationPnlBox');
  const marketHeader = document.getElementById('simulationMarketHeader');
  const matchCount = document.getElementById('simulationMatchCount');
  const matchesBody = document.getElementById('simulationMatches');
  const simulationData = JSON.parse(document.getElementById('simulationData').textContent || '{}');
  let activeSimulation = { n: 0, roi: 0, matches: [] };
  const rupiah = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0
  }).format(Math.round(value));
  const signedRupiah = (value) => `${value >= 0 ? '+' : '−'}${rupiah(Math.abs(value))}`;
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>\"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#039;'
  }[char]));
  const renderMatches = (stake) => {
    const matches = activeSimulation.matches;
    matchCount.textContent = `${matches.length} match yang masuk aturan`;
    if (!matches.length) {
      matchesBody.innerHTML = '<tr><td class="sim-empty" colspan="7">Tidak ada rincian match untuk aturan ini.</td></tr>';
      return;
    }
    matchesBody.innerHTML = matches.map((match) => {
      const matchPnl = Number(match.pl || 0) * stake;
      const outcomeClass = String(match.outcome || '').toLowerCase();
      return `<tr>
        <td>${escapeHtml(match.date)}</td>
        <td class="match-name">${escapeHtml(match.home)}<br><span class="muted">vs</span> ${escapeHtml(match.away)}</td>
        <td><b>${escapeHtml(match.ht)}</b><br><span class="score">2H ${escapeHtml(match.goals2h)} · FT ${escapeHtml(match.ft)}</span></td>
        <td><b>${escapeHtml(match.side)}</b><br><span class="outcome ${outcomeClass}">${escapeHtml(match.outcome)}</span></td>
        <td>${escapeHtml(match.line)}</td>
        <td>@ ${Number(match.odds).toFixed(2)}</td>
        <td class="${matchPnl >= 0 ? 'pos' : 'neg'}">${signedRupiah(matchPnl)}</td>
      </tr>`;
    }).join('');
  };
  const update = () => {
    const stake = Math.max(0, Number(stakeInput.value) || 0);
    const turnover = stake * activeSimulation.n;
    const resultPerUnit = activeSimulation.matches.reduce((sum, match) => sum + Number(match.pl || 0), 0);
    const result = activeSimulation.matches.length ? resultPerUnit * stake : turnover * activeSimulation.roi / 100;
    const effectiveRoi = turnover > 0 ? result / turnover * 100 : activeSimulation.roi;
    totalStake.textContent = rupiah(turnover);
    pnl.textContent = signedRupiah(result);
    balance.textContent = rupiah(turnover + result);
    roi.textContent = `${effectiveRoi >= 0 ? '+' : '-'}${Math.abs(effectiveRoi).toFixed(1)}%`;
    roi.textContent = `${activeSimulation.roi >= 0 ? '+' : '−'}${Math.abs(activeSimulation.roi).toFixed(1)}%`;
    pnlBox.classList.toggle('pnl-positive', result >= 0);
    pnlBox.classList.toggle('pnl-negative', result < 0);
    renderMatches(stake);
  };
  document.querySelectorAll('[data-simulate]').forEach((button) => {
    button.addEventListener('click', () => {
      const matches = simulationData[button.dataset.simId] || [];
      activeSimulation = {
        n: matches.length || Number(button.dataset.simN) || 0,
        roi: Number(button.dataset.simRoi) || 0,
        matches
      };
      marketHeader.textContent = button.dataset.simMarket || 'Market';
      title.textContent = `${button.dataset.simCode} · Simulasi`;
      summary.textContent = `${button.dataset.simLabel} · ${activeSimulation.n} taruhan historis`;
      update();
      dialog.showModal();
      stakeInput.focus();
      stakeInput.select();
    });
  });
  stakeInput.addEventListener('input', update);
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
})();
</script>
</body>
</html>
