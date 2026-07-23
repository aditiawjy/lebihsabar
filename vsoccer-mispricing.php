<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * V-Soccer Mispricing Detector.
 * Baca goal_log_vsoccer.csv — baris yang punya odds kickoff (ko_line/ko_over/ko_under)
 * DAN hasil FT (final_home/final_away). Bandingkan peluang Over/Under riil vs odds,
 * hitung EV, tandai kombinasi (liga x line) ber-EV positif = kandidat operator salah harga.
 *
 * Perbandingan SAHIH: odds kickoff vs hasil FT (sama-sama dari menit 0).
 */

const MIN_SAMPLE = 50;   // ambang sampel agar tidak tertipu noise
const FINISH_MIN = 15;   // match dianggap selesai bila kickoff >= 15 menit lalu (durasi ~12 mnt)

$file = __DIR__ . '/goal_log_vsoccer.csv';
$nowTs = time();
$skippedLive = 0;

// Hitung hasil satu leg O/U. Return [win, push, lose] pecahan (rata2 antar leg utk split line).
function ouResult(float $total, array $lines, bool $isOver): array {
    $w = 0; $p = 0; $l = 0; $k = count($lines);
    foreach ($lines as $ln) {
        if (abs($total - $ln) < 1e-9) $p++;
        elseif (($total > $ln) === $isOver) $w++;
        else $l++;
    }
    return [$w / $k, $p / $k, $l / $k];
}
function parseLines(string $s): array {
    $parts = array_filter(array_map('trim', explode('/', $s)), fn($x) => $x !== '' && is_numeric($x));
    return array_map('floatval', array_values($parts));
}

// agregat: key = league || line
$agg = [];       // key => ['league','line','n','over'=>['ev'=>,'w'=>,'imp'=>],'under'=>[...]]
$rowsWithOdds = 0; $rowsTotal = 0;

if (is_file($file) && ($fh = fopen($file, 'r'))) {
    $hdr = fgetcsv($fh); $ix = array_flip($hdr ?: []);
    $need = ['league','final_home','final_away','ko_line','ko_over','ko_under'];
    $ok = !array_filter($need, fn($c) => !isset($ix[$c]));
    while ($ok && ($r = fgetcsv($fh)) !== false) {
        $rowsTotal++;
        $koLine = trim($r[$ix['ko_line']] ?? '');
        $koOver = $r[$ix['ko_over']] ?? '';
        $koUnder = $r[$ix['ko_under']] ?? '';
        $fh_ = $r[$ix['final_home']] ?? ''; $fa_ = $r[$ix['final_away']] ?? '';
        if ($koLine === '' || !is_numeric($koOver) || !is_numeric($koUnder) || !is_numeric($fh_) || !is_numeric($fa_)) continue;
        $lines = parseLines($koLine);
        if (!$lines) continue;
        // Lewati match yang kemungkinan MASIH BERJALAN (skor belum final).
        $dtRow = DateTime::createFromFormat('d/m/Y H:i', trim($r[$ix['datetime']] ?? ''));
        if ($dtRow && ($nowTs - $dtRow->getTimestamp()) < FINISH_MIN * 60) { $skippedLive++; continue; }
        $total = (float)$fh_ + (float)$fa_;
        $koOver = (float)$koOver; $koUnder = (float)$koUnder;
        $league = trim($r[$ix['league']]);
        $leagueShort = trim(str_replace([' - 12 mins [V]', 'V-Soccer '], '', $league));
        $rowsWithOdds++;

        $key = $leagueShort . '||' . $koLine;
        if (!isset($agg[$key])) $agg[$key] = ['league'=>$leagueShort,'line'=>$koLine,'n'=>0,
            'over'=>['ev'=>0,'w'=>0,'imp'=>0], 'under'=>['ev'=>0,'w'=>0,'imp'=>0]];
        $A = &$agg[$key]; $A['n']++;

        [$w,$p,$l] = ouResult($total, $lines, true);
        $A['over']['ev']  += $w*($koOver-1) + $l*(-1);
        $A['over']['w']   += $w;
        $A['over']['imp'] += 1/$koOver;

        [$w2,$p2,$l2] = ouResult($total, $lines, false);
        $A['under']['ev']  += $w2*($koUnder-1) + $l2*(-1);
        $A['under']['w']   += $w2;
        $A['under']['imp'] += 1/$koUnder;
        unset($A);
    }
    fclose($fh);
}

// finalisasi rata-rata
$rows = [];
foreach ($agg as $A) {
    $n = $A['n'];
    $rows[] = [
        'league'=>$A['league'], 'line'=>$A['line'], 'n'=>$n,
        'over_real'=>$A['over']['w']/$n*100, 'over_imp'=>$A['over']['imp']/$n*100, 'over_ev'=>$A['over']['ev']/$n*100,
        'under_real'=>$A['under']['w']/$n*100, 'under_imp'=>$A['under']['imp']/$n*100, 'under_ev'=>$A['under']['ev']/$n*100,
    ];
}
// urut: sampel cukup dulu, lalu EV terbaik
usort($rows, function($x,$y){
    $bx = max($x['over_ev'],$x['under_ev']); $by = max($y['over_ev'],$y['under_ev']);
    $rx = $x['n']>=MIN_SAMPLE?1:0; $ry = $y['n']>=MIN_SAMPLE?1:0;
    return [$ry,$by] <=> [$rx,$bx];
});
$candidates = array_filter($rows, fn($r)=>$r['n']>=MIN_SAMPLE && (max($r['over_ev'],$r['under_ev'])>0));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>V-Soccer Mispricing Detector</title>
<style>
:root{--bg:#0f1117;--card:#161b22;--border:#30363d;--txt:#e1e4e8;--txt2:#8b949e;--muted:#484f58;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--yellow:#d29922;}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--txt);font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;line-height:1.5;}
.container{max-width:1040px;margin:0 auto;padding:1.5rem 1.25rem 4rem;}
h1{font-size:1.5rem;}.subtitle{color:var(--txt2);font-size:.9rem;margin:.15rem 0 1.25rem;}
.mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,Consolas,monospace;}
.bar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;font-size:.8rem;}
.btn{background:#1c2129;color:var(--txt);border:1px solid var(--border);padding:.35rem .7rem;border-radius:6px;text-decoration:none;font-size:.8rem;}
.btn:hover{border-color:var(--accent);}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.2rem;margin-bottom:1.25rem;}
.card h2{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt2);margin-bottom:.9rem;}
table{width:100%;border-collapse:collapse;font-size:.84rem;}
th,td{padding:.45rem .5rem;text-align:right;border-bottom:1px solid var(--border);}
th:first-child,td:first-child,th:nth-child(2),td:nth-child(2){text-align:left;}
th{color:var(--txt2);font-size:.7rem;text-transform:uppercase;}
tbody tr:hover{background:#1a2030;}
.g{color:var(--green);}.r{color:var(--red);}.y{color:var(--yellow);}
.pill{padding:.1rem .5rem;border-radius:12px;font-size:.7rem;font-weight:700;}
.pill.g{background:rgba(63,185,80,.15);color:var(--green);}
.pill.dim{background:rgba(139,151,176,.12);color:var(--txt2);}
.warnbox{border-left:3px solid var(--yellow);background:rgba(210,153,34,.08);padding:.8rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;color:var(--txt2);margin-top:1rem;}
.warnbox b{color:var(--yellow);}
.empty{color:var(--muted);font-style:italic;padding:1rem 0;}
.foot{color:var(--muted);font-size:.76rem;text-align:center;margin-top:1.5rem;}
.lowsample{opacity:.5;}
</style>
</head>
<body>
<div class="container">
  <h1>V-Soccer Mispricing Detector</h1>
  <p class="subtitle">Bandingkan odds <b>kickoff</b> vs hasil <b>FT</b> (perbandingan sahih). EV positif dengan sampel cukup = kandidat operator salah harga.<br>
  <span class="mono" style="font-size:.82rem;"><?= number_format($rowsWithOdds) ?></b> match selesai dianalisis · <?= (int)$skippedLive ?> match dilewati (masih berjalan, skor belum final).</span></p>
  <div class="bar">
    <a class="btn" href="vsoccer-rng-analysis.php">← Analisis RNG</a>
    <a class="btn" href="vsoccer-dashboard.php">Dashboard</a>
    <a class="btn" href="javascript:location.reload()">&#x21BB; Refresh</a>
  </div>

  <?php if ($rowsWithOdds === 0): ?>
    <div class="card">
      <p class="empty">Belum ada baris dengan odds kickoff + hasil FT di goal_log_vsoccer.csv.</p>
      <p class="note" style="color:var(--txt2);font-size:.85rem;">Data terisi otomatis saat extension merekam match dari kickoff (menit 0) sampai selesai. Butuh minimal ~<?= MIN_SAMPLE ?> match per (liga × line) agar hasil bisa dipercaya.</p>
    </div>
  <?php else: ?>

  <div class="card">
    <h2>Kandidat celah (EV+ &amp; sampel ≥ <?= MIN_SAMPLE ?>)</h2>
    <?php if (!$candidates): ?>
      <p class="empty">Belum ada kombinasi ber-EV positif dengan sampel cukup. (Ini hasil yang DIHARAPKAN untuk RNG fair — house edge membuat EV negatif.)</p>
    <?php else: ?>
      <p class="note" style="color:var(--txt2);font-size:.84rem;margin-bottom:.6rem;">⚠ Kandidat berikut ber-EV positif. Tetap waspada: bisa jadi anomali sementara. Verifikasi dengan menambah data.</p>
      <table>
        <thead><tr><th>Liga</th><th>Line</th><th>n</th><th>Sisi</th><th>Riil%</th><th>Odds impl%</th><th>EV</th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $r): $side=$r['over_ev']>=$r['under_ev']?'over':'under';
          $ev=$r[$side.'_ev']; $real=$r[$side.'_real']; $imp=$r[$side.'_imp']; ?>
          <tr><td><?= htmlspecialchars($r['league']) ?></td><td class="mono"><?= htmlspecialchars($r['line']) ?></td>
            <td class="mono"><?= $r['n'] ?></td><td><?= strtoupper($side) ?></td>
            <td class="mono"><?= number_format($real,1) ?>%</td><td class="mono"><?= number_format($imp,1) ?>%</td>
            <td class="mono g"><b>+<?= number_format($ev,1) ?>%</b></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Semua kombinasi (liga × line kickoff) — <?= count($rows) ?> baris, <?= number_format($rowsWithOdds) ?> match</h2>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr>
        <th>Liga</th><th>Line</th><th>n</th>
        <th>Over riil%</th><th>Over impl%</th><th>EV Over</th>
        <th>Under riil%</th><th>Under impl%</th><th>EV Under</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $low=$r['n']<MIN_SAMPLE; ?>
        <tr class="<?= $low?'lowsample':'' ?>">
          <td><?= htmlspecialchars($r['league']) ?></td>
          <td class="mono"><?= htmlspecialchars($r['line']) ?></td>
          <td class="mono"><?= $r['n'] ?><?= $low?' <span class="pill dim">kurang</span>':'' ?></td>
          <td class="mono"><?= number_format($r['over_real'],1) ?>%</td>
          <td class="mono"><?= number_format($r['over_imp'],1) ?>%</td>
          <td class="mono <?= $r['over_ev']>0?'g':'r' ?>"><?= sprintf('%+.1f',$r['over_ev']) ?>%</td>
          <td class="mono"><?= number_format($r['under_real'],1) ?>%</td>
          <td class="mono"><?= number_format($r['under_imp'],1) ?>%</td>
          <td class="mono <?= $r['under_ev']>0?'g':'r' ?>"><?= sprintf('%+.1f',$r['under_ev']) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="warnbox">
      <b>Cara baca.</b> <b>Riil%</b> = seberapa sering menang dari data. <b>Impl%</b> = peluang tersirat odds (1/odds). Bila Riil% &gt; Impl% cukup jauh → EV positif (hijau). Baris pudar = sampel &lt; <?= MIN_SAMPLE ?> (belum bisa dipercaya, kemungkinan noise). Mispricing sejati bertahan saat data bertambah; noise hilang.
    </div>
  </div>

  <?php endif; ?>
  <p class="foot">Edukasi statistik, bukan ajakan bertaruh. Untuk RNG fair, EV jangka panjang tetap negatif sebesar margin operator.</p>
</div>
</body>
</html>
