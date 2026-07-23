<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * V-Soccer Pattern Validator — uji jujur klaim "pola win-rate tinggi".
 * Membaca goal_log_vsoccer.csv (match selesai). Untuk tiap pola:
 *   win-rate bersyarat vs BASE RATE (lift), interval kepercayaan Wilson,
 *   uji signifikansi, dan cek stabilitas out-of-sample (lama vs baru).
 * Tujuan: memisahkan pola nyata dari base-rate/overfitting. Read-only.
 */

const FINISH_MIN = 15; // match dianggap selesai bila kickoff >= 15 menit lalu

$file = __DIR__ . '/goal_log_vsoccer.csv';
$now = time();

function snaps($goals) {
    preg_match_all("/(1H|2H)\s+(\d+)'\s*\((\d+)-(\d+)\)/", (string)$goals, $m, PREG_SET_ORDER);
    return $m;
}
function midline($s) {
    $p = array_values(array_filter(array_map('trim', explode('/', (string)$s)),
        fn($x) => $x !== '' && is_numeric($x)));
    if (!$p) return null;
    return array_sum(array_map('floatval', $p)) / count($p);
}

// ---- Muat & turunkan per-match ----
$M = [];
if (is_file($file) && ($fh = fopen($file, 'r'))) {
    $hdr = fgetcsv($fh); $ix = array_flip($hdr ?: []);
    while (($r = fgetcsv($fh)) !== false) {
        $fhh = $r[$ix['final_home']] ?? ''; $faa = $r[$ix['final_away']] ?? '';
        $kl  = $r[$ix['ko_line']] ?? '';
        if ($fhh === '' || $faa === '' || !is_numeric($fhh) || !is_numeric($faa)) continue;
        $ko = midline($kl); if ($ko === null) continue;
        // hanya match selesai
        $dt = DateTime::createFromFormat('d/m/Y H:i', trim($r[$ix['datetime']] ?? ''));
        if ($dt && ($now - $dt->getTimestamp()) < FINISH_MIN * 60) continue;

        $s = snaps($r[$ix['goals']] ?? '');
        $g1 = 0; $late = false; $first = null;
        foreach ($s as $t) {
            $half = $t[1]; $min = (int)$t[2];
            if ($first === null) $first = ($half === '1H') ? $min : 45 + $min;
            if ($half === '1H') $g1++;
            if ($half === '2H' && $min >= 75) $late = true;
        }
        $tot = (int)$fhh + (int)$faa;
        $g2 = $tot - $g1;
        $M[] = ['ko'=>$ko, 'g1'=>$g1, 'g2'=>$g2, 'tot'=>$tot, 'first'=>$first, 'late'=>$late,
                'dt'=>$dt ? $dt->getTimestamp() : 0];
    }
    fclose($fh);
}
usort($M, fn($a, $b) => $a['dt'] <=> $b['dt']);
$N = count($M);

// ---- Statistik ----
function wilson($k, $n) {
    if ($n == 0) return [0, 0];
    $z = 1.96; $p = $k / $n;
    $den = 1 + $z*$z/$n;
    $c = ($p + $z*$z/(2*$n)) / $den;
    $half = ($z * sqrt($p*(1-$p)/$n + $z*$z/(4*$n*$n))) / $den;
    return [max(0,$c-$half)*100, min(1,$c+$half)*100];
}
function ztest($k1,$n1,$k0,$n0) { // proporsi bersyarat vs base
    if ($n1==0||$n0==0) return 0;
    $p1=$k1/$n1; $p0=$k0/$n0; $p=($k1+$k0)/($n1+$n0);
    $se=sqrt($p*(1-$p)*(1/$n1+1/$n0));
    return $se>0 ? ($p1-$p0)/$se : 0;
}

/**
 * Evaluasi satu pola.
 * $cond  = fn(match)->bool  (kondisi/sinyal)
 * $tgt   = fn(match)->bool  (target/hasil yg dipertaruhkan)
 * $base  = fn(match)->bool  (himpunan pembanding base-rate; null = semua match)
 */
function evalPattern($M, $cond, $tgt, $base = null) {
    $ck=0;$cn=0;    // bersyarat
    $bk=0;$bn=0;    // base
    $oldk=0;$oldn=0;$newk=0;$newn=0; // out-of-sample (urut waktu)
    $half = intdiv(count($M), 2);
    foreach ($M as $i => $m) {
        $inBase = $base ? $base($m) : true;
        if ($inBase) { $bn++; if ($tgt($m)) $bk++; }
        if ($cond($m)) {
            $cn++; $hit = $tgt($m) ? 1 : 0; $ck += $hit;
            if ($i < $half) { $oldn++; $oldk += $hit; } else { $newn++; $newk += $hit; }
        }
    }
    // base rate DI LUAR kondisi (untuk lift jujur): pakai base set minus... sederhana: base set penuh
    $winRate = $cn ? $ck/$cn*100 : 0;
    $baseRate = $bn ? $bk/$bn*100 : 0;
    $ci = wilson($ck, $cn);
    // z: kondisi vs base-yang-tidak-memenuhi-kondisi
    $z = ztest($ck, $cn, $bk-$ck, max(0,$bn-$cn));
    $oldR = $oldn ? $oldk/$oldn*100 : null;
    $newR = $newn ? $newk/$newn*100 : null;
    return compact('ck','cn','winRate','baseRate','ci','z','oldR','newR','oldn','newn');
}

// ---- Definisi 3 pola ----
$patterns = [
    [
        'title' => 'Pola 1 — KO line ≥ 7 → 2H ≥ 2 gol',
        'claim' => '81.2% (13/16)',
        'cond'  => fn($m) => $m['ko'] >= 7.0,
        'tgt'   => fn($m) => $m['g2'] >= 2,
        'base'  => null, // vs semua match
        'baseLabel' => 'P(2H≥2) semua match',
    ],
    [
        'title' => 'Pola 2 — 1H = 2 atau 3 gol → ada gol menit ≥ 75\'',
        'claim' => '80.5% (33/41)',
        'cond'  => fn($m) => $m['g1'] === 2 || $m['g1'] === 3,
        'tgt'   => fn($m) => $m['late'],
        'base'  => null,
        'baseLabel' => 'P(gol ≥75\') semua match',
    ],
    [
        'title' => 'Pola 3 — 1H = 3 gol & KO ≥ 5.5 → 2H ≥ 3 gol',
        'claim' => '76.9% (10/13)',
        'cond'  => fn($m) => $m['g1'] === 3 && $m['ko'] >= 5.5,
        'tgt'   => fn($m) => $m['g2'] >= 3,
        'base'  => null,
        'baseLabel' => 'P(2H≥3) semua match',
    ],
];

// ---- Base rate global ----
function rate($M, $f) { $n=count($M); if(!$n) return 0; $k=0; foreach($M as $m) if($f($m)) $k++; return $k/$n*100; }
$brHi = array_values(array_filter($M, fn($m)=>$m['ko']>=7.0));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>V-Soccer Pattern Validator</title>
<style>
:root{--bg:#0f1117;--card:#161b22;--border:#30363d;--txt:#e1e4e8;--txt2:#8b949e;--muted:#484f58;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--yellow:#d29922;}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--txt);font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;line-height:1.5;}
.container{max-width:900px;margin:0 auto;padding:1.5rem 1.25rem 4rem;}
h1{font-size:1.5rem;}.subtitle{color:var(--txt2);font-size:.9rem;margin:.15rem 0 1.25rem;}
.mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,Consolas,monospace;}
.bar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;font-size:.8rem;}
.btn{background:#1c2129;color:var(--txt);border:1px solid var(--border);padding:.35rem .7rem;border-radius:6px;text-decoration:none;font-size:.8rem;}
.btn:hover{border-color:var(--accent);}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.2rem;margin-bottom:1.1rem;}
.card h2{font-size:1rem;margin-bottom:.2rem;}
.claim{color:var(--txt2);font-size:.82rem;margin-bottom:.8rem;}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin:.6rem 0;}
@media(max-width:640px){.grid{grid-template-columns:repeat(2,1fr);}}
.stat{background:#0d1117;border:1px solid var(--border);border-radius:8px;padding:.6rem .7rem;}
.stat .k{font-size:.68rem;color:var(--txt2);text-transform:uppercase;letter-spacing:.04em;}
.stat .v{font-size:1.2rem;font-weight:700;font-variant-numeric:tabular-nums;margin-top:.1rem;}
.verdict{display:inline-block;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:700;margin-top:.3rem;}
.g{color:var(--green);}.r{color:var(--red);}.y{color:var(--yellow);}
.vd-edge{background:rgba(63,185,80,.15);color:var(--green);}
.vd-base{background:rgba(210,153,34,.15);color:var(--yellow);}
.vd-small{background:rgba(139,151,176,.15);color:var(--txt2);}
.vd-overfit{background:rgba(248,81,73,.15);color:var(--red);}
.note{font-size:.82rem;color:var(--txt2);margin-top:.5rem;}
table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:.4rem;}
td,th{padding:.3rem .5rem;text-align:right;border-bottom:1px solid var(--border);}
td:first-child,th:first-child{text-align:left;color:var(--txt2);}
.foot{color:var(--muted);font-size:.76rem;text-align:center;margin-top:1.5rem;}
.warnbox{border-left:3px solid var(--yellow);background:rgba(210,153,34,.08);padding:.8rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;color:var(--txt2);margin-top:1rem;}
.warnbox b{color:var(--yellow);}
</style>
</head>
<body>
<div class="container">
  <h1>V-Soccer Pattern Validator</h1>
  <p class="subtitle">Uji jujur 3 klaim "pola win-rate tinggi" pada <b><?= $N ?></b> match selesai. Win-rate dibandingkan <b>base rate</b> — kalau sama, itu bukan pola, cuma efek liga gol-tinggi.</p>
  <div class="bar">
    <a class="btn" href="vsoccer-rng-analysis.php">← Analisis RNG</a>
    <a class="btn" href="vsoccer-mispricing.php">Mispricing</a>
    <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
  </div>

  <?php if ($N < 10): ?>
    <div class="card"><b>Data belum cukup</b> (<?= $N ?> match selesai). Biarkan collector jalan lalu refresh.</div>
  <?php else: ?>

  <div class="card">
    <h2 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt2);">Base rate global (peluang "gratis" tanpa syarat)</h2>
    <div class="grid">
      <div class="stat"><div class="k">P(2H ≥ 2 gol)</div><div class="v mono"><?= number_format(rate($M,fn($m)=>$m['g2']>=2),1) ?>%</div></div>
      <div class="stat"><div class="k">P(2H ≥ 3 gol)</div><div class="v mono"><?= number_format(rate($M,fn($m)=>$m['g2']>=3),1) ?>%</div></div>
      <div class="stat"><div class="k">P(gol ≥ 75')</div><div class="v mono"><?= number_format(rate($M,fn($m)=>$m['late']),1) ?>%</div></div>
      <div class="stat"><div class="k">P(2H≥2 | KO≥7)</div><div class="v mono"><?= $brHi ? number_format(rate($brHi,fn($m)=>$m['g2']>=2),1) : '–' ?>%</div></div>
    </div>
    <p class="note">Kalau win-rate sebuah pola ≈ base rate-nya → polanya <b>tidak menambah apa-apa</b> (kamu bisa dapat hasil sama dengan tebak buta).</p>
  </div>

  <?php foreach ($patterns as $p):
    $res = evalPattern($M, $p['cond'], $p['tgt'], $p['base']);
    $lift = $res['winRate'] - $res['baseRate'];
    // verdict
    if ($res['cn'] < 20) { $vd = ['SAMPEL KURANG','vd-small']; }
    elseif ($res['oldR']!==null && $res['newR']!==null && abs($res['oldR']-$res['newR']) > 20) { $vd = ['TIDAK STABIL (overfit)','vd-overfit']; }
    elseif (abs($res['z']) < 1.96 || $lift < 5) { $vd = ['HANYA BASE RATE','vd-base']; }
    else { $vd = ['EDGE NYATA','vd-edge']; }
  ?>
  <div class="card">
    <h2><?= htmlspecialchars($p['title']) ?></h2>
    <div class="claim">Klaim AI lain: <b><?= $p['claim'] ?></b> · <span class="verdict <?= $vd[1] ?>"><?= $vd[0] ?></span></div>
    <div class="grid">
      <div class="stat"><div class="k">Win-rate (data kita)</div><div class="v mono"><?= number_format($res['winRate'],1) ?>%</div></div>
      <div class="stat"><div class="k">Sampel</div><div class="v mono"><?= $res['ck'] ?>/<?= $res['cn'] ?></div></div>
      <div class="stat"><div class="k"><?= htmlspecialchars($p['baseLabel']) ?></div><div class="v mono"><?= number_format($res['baseRate'],1) ?>%</div></div>
      <div class="stat"><div class="k">Lift vs base</div><div class="v mono <?= $lift>=5?'g':($lift<=-5?'r':'y') ?>"><?= sprintf('%+.1f',$lift) ?>%</div></div>
    </div>
    <table>
      <tr><th>Metrik</th><th>Nilai</th></tr>
      <tr><td>Interval kepercayaan 95% (Wilson)</td><td class="mono"><?= number_format($res['ci'][0],0) ?>% – <?= number_format($res['ci'][1],0) ?>%</td></tr>
      <tr><td>Uji vs base (z)</td><td class="mono <?= abs($res['z'])>=1.96?'g':'y' ?>"><?= sprintf('%+.2f',$res['z']) ?> <?= abs($res['z'])>=1.96?'(signifikan)':'(belum signifikan)' ?></td></tr>
      <tr><td>Out-of-sample: separuh lama vs baru</td><td class="mono"><?= $res['oldR']!==null?number_format($res['oldR'],0).'%':'–' ?> (n<?= $res['oldn'] ?>) vs <?= $res['newR']!==null?number_format($res['newR'],0).'%':'–' ?> (n<?= $res['newn'] ?>)</td></tr>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="card">
    <h2 style="font-size:.9rem;">Cara baca verdict</h2>
    <p class="note">
      <span class="verdict vd-edge">EDGE NYATA</span> lift ≥5% & signifikan & stabil — kondisinya benar-benar menaikkan peluang.<br>
      <span class="verdict vd-base">HANYA BASE RATE</span> win-rate tinggi tapi ≈ base rate / tak signifikan — cuma efek liga gol tinggi, bukan pola.<br>
      <span class="verdict vd-small">SAMPEL KURANG</span> n&lt;20, belum bisa dipercaya.<br>
      <span class="verdict vd-overfit">TIDAK STABIL</span> win-rate separuh lama ≠ separuh baru — ciri overfitting.
    </p>
  </div>

  <div class="warnbox">
    <b>Untuk game poin.</b> Karena tanpa rugi uang, pola ber-win-rate tinggi tetap berguna kumpulkan poin — <b>tapi</b> hanya kalau verdict-nya bukan "sampel kurang/overfit". Kalau cuma "base rate", kamu bisa dapat hasil sama dengan aturan sederhana: pilih Over di match liga gol-tinggi, tanpa syarat rumit.
  </div>

  <?php endif; ?>
  <p class="foot">Validasi statistik atas klaim pola. Data bertambah → refresh untuk hasil lebih akurat.</p>
</div>
</body>
</html>
