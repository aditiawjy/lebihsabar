<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * V-Soccer RNG Analysis — uji keacakan / deteksi pola.
 * Sumber:
 *   matches.csv          -> uji berbasis hasil FT (Poisson, chi-square, autokorelasi, runs, streak)
 *   goal_events_vsoccer.csv -> distribusi menit gol + pergerakan odds (tumbuh seiring waktu)
 *
 * Filosofi: tes dirancang untuk MENEMUKAN pola. Bila tak ditemukan -> bukti kuat RNG murni.
 */

// ---------- 1. Baca matches.csv (hanya V-Soccer) ----------
$csv = __DIR__ . '/matches.csv';
$byLeague = [];   // league => list of ['t'=>total,'time'=>..,'id'=>..]
$totalDist = [];
$res = ['H'=>0,'D'=>0,'A'=>0];
$n = 0; $sumGoals = 0;

if (is_file($csv) && ($fh = fopen($csv, 'r'))) {
    $hdr = fgetcsv($fh); $ix = array_flip($hdr ?: []);
    while (($r = fgetcsv($fh)) !== false) {
        $lg = $r[$ix['league']] ?? '';
        if (strpos($lg, '[V]') === false) continue;
        $h = $r[$ix['ft_home']] ?? ''; $a = $r[$ix['ft_away']] ?? '';
        if ($h === '' || $a === '' || !is_numeric($h) || !is_numeric($a)) continue;
        $h = (int)round($h); $a = (int)round($a); $t = $h + $a;
        $name = trim(str_replace([' - 12 mins [V]', 'V-Soccer '], '', $lg));
        $byLeague[$name][] = ['t'=>$t, 'time'=>$r[$ix['match_time']] ?? '', 'id'=>(int)($r[$ix['id']] ?? 0)];
        $totalDist[$t] = ($totalDist[$t] ?? 0) + 1;
        if ($h > $a) $res['H']++; elseif ($h < $a) $res['A']++; else $res['D']++;
        $n++; $sumGoals += $t;
    }
    fclose($fh);
}
$lambda = $n ? $sumGoals / $n : 0;

// ---------- Uji 1: Poisson fit + chi-square ----------
function poissonP($k, $lam) { return exp(-$lam) * pow($lam, $k) / gmpFact($k); }
function gmpFact($k) { $f = 1; for ($i = 2; $i <= $k; $i++) $f *= $i; return $f; }
$chi = 0; $dof = -1;
$poissonRows = [];
$maxG = $n ? max(array_keys($totalDist)) : 0;
for ($g = 0; $g <= min($maxG, 11); $g++) {
    $obs = $totalDist[$g] ?? 0;
    $exp = poissonP($g, $lambda) * $n;
    $poissonRows[] = ['g'=>$g, 'obs'=>$obs/$n*100, 'exp'=>poissonP($g,$lambda)*100];
    if ($exp >= 5) { $chi += pow($obs - $exp, 2) / $exp; $dof++; }
}

// ---------- Uji 2: autokorelasi lag-1, runs test, streak (per liga, digabung) ----------
$acc = ['acSum'=>0, 'acCnt'=>0, 'afterOver'=>[0,0], 'afterUnder'=>[0,0], 'runsZ'=>[]];
foreach ($byLeague as $name => $arr) {
    usort($arr, fn($x,$y) => [$x['time'],$x['id']] <=> [$y['time'],$y['id']]);
    $tot = array_column($arr, 't');
    $m = count($tot); if ($m < 30) continue;
    $mean = array_sum($tot)/$m;
    $var = 0; foreach ($tot as $v) $var += ($v-$mean)**2; $var /= $m;
    // autokorelasi lag-1
    $num = 0; for ($i=0;$i<$m-1;$i++) $num += ($tot[$i]-$mean)*($tot[$i+1]-$mean);
    if ($var>0) { $acc['acSum'] += $num/($var*($m-1)); $acc['acCnt']++; }
    // over2.5 sequence
    $ov = array_map(fn($t)=>$t>=3?1:0, $tot);
    for ($i=0;$i<$m-1;$i++) {
        if ($ov[$i]==1){ $acc['afterOver'][1]++; if($ov[$i+1]==1)$acc['afterOver'][0]++; }
        else { $acc['afterUnder'][1]++; if($ov[$i+1]==1)$acc['afterUnder'][0]++; }
    }
    // runs test
    $runs=1; for($i=1;$i<$m;$i++) if($ov[$i]!=$ov[$i-1])$runs++;
    $n1=array_sum($ov); $n0=$m-$n1;
    if($n1>0&&$n0>0){ $er=2*$n1*$n0/$m+1; $vr=(2*$n1*$n0*(2*$n1*$n0-$m))/($m*$m*($m-1)); if($vr>0)$acc['runsZ'][]=($runs-$er)/sqrt($vr); }
}
$acAvg = $acc['acCnt'] ? $acc['acSum']/$acc['acCnt'] : 0;
$pAO = $acc['afterOver'][1] ? $acc['afterOver'][0]/$acc['afterOver'][1]*100 : 0;
$pAU = $acc['afterUnder'][1] ? $acc['afterUnder'][0]/$acc['afterUnder'][1]*100 : 0;
$runsZavg = $acc['runsZ'] ? array_sum($acc['runsZ'])/count($acc['runsZ']) : 0;
$acCrit = $n ? 2/sqrt($n) : 1;

// ---------- 3. goal_events_vsoccer.csv (menit gol + odds) ----------
$geFile = __DIR__ . '/goal_events_vsoccer.csv';
$minuteBuckets = ['1H'=>array_fill(0,10,0), '2H'=>array_fill(0,10,0)]; // bucket per 5 menit (0-45 -> 9 bucket)
$geN = 0; $withOdds = 0; $overShorten = 0;
if (is_file($geFile) && ($gf = fopen($geFile, 'r'))) {
    $gh = fgetcsv($gf); $gi = array_flip($gh ?: []);
    while (($r = fgetcsv($gf)) !== false) {
        // Abaikan gol dengan menit tak andal (accurate=0, akibat tab ke-throttle).
        if (isset($gi['accurate']) && ($r[$gi['accurate']] ?? '1') === '0') continue;
        $half = $r[$gi['half']] ?? ''; $min = (int)($r[$gi['minute']] ?? -1);
        if (($half==='1H'||$half==='2H') && $min>=0) {
            $b = min(9, intdiv($min, 5));
            $minuteBuckets[$half][$b]++;
            $geN++;
        }
        if (isset($gi['over_odd']) && is_numeric($r[$gi['over_odd']] ?? '')) $withOdds++;
    }
    fclose($gf);
}

function verdict($ok) { return $ok ? ['ACAK','g'] : ['ADA PENYIMPANGAN','r']; }
$vAc = verdict(abs($acAvg) < $acCrit);
$vStreak = verdict(abs($pAO-$pAU) < 3);
$vRuns = verdict(abs($runsZavg) < 1.96);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>V-Soccer RNG Analysis</title>
<style>
:root{--bg:#0f1117;--card:#161b22;--border:#30363d;--txt:#e1e4e8;--txt2:#8b949e;--muted:#484f58;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--yellow:#d29922;--teal:#39c5bb;}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--txt);font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;line-height:1.5;}
.container{max-width:1000px;margin:0 auto;padding:1.5rem 1.25rem 4rem;}
h1{font-size:1.5rem;}.subtitle{color:var(--txt2);font-size:.9rem;margin:.15rem 0 1.25rem;}
.mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,Consolas,monospace;}
.bar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;font-size:.8rem;}
.btn{background:#1c2129;color:var(--txt);border:1px solid var(--border);padding:.35rem .7rem;border-radius:6px;text-decoration:none;font-size:.8rem;}
.btn:hover{border-color:var(--accent);}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.2rem;margin-bottom:1.25rem;}
.card h2{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt2);margin-bottom:.9rem;}
.verdicts{display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.25rem;}
@media(max-width:680px){.verdicts{grid-template-columns:1fr;}}
.vcard{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1rem;}
.vcard .t{font-size:.8rem;color:var(--txt2);}
.vcard .p{font-size:1.05rem;font-weight:700;margin:.3rem 0;}
.vcard .d{font-size:.78rem;color:var(--txt2);}
.pill{padding:.1rem .55rem;border-radius:12px;font-size:.72rem;font-weight:700;}
.g{color:var(--green);}.r{color:var(--red);}.y{color:var(--yellow);}
.pill.g{background:rgba(63,185,80,.15);color:var(--green);}
.pill.r{background:rgba(248,81,73,.15);color:var(--red);}
table{width:100%;border-collapse:collapse;font-size:.85rem;}
th,td{padding:.4rem .55rem;text-align:right;border-bottom:1px solid var(--border);}
th:first-child,td:first-child{text-align:left;}
th{color:var(--txt2);font-size:.72rem;text-transform:uppercase;}
.barrow{display:flex;align-items:center;gap:.5rem;margin:.15rem 0;}
.barrow .lbl{width:70px;font-size:.78rem;color:var(--txt2);}
.barrow .track{flex:1;background:#0d1117;border-radius:3px;overflow:hidden;height:14px;}
.barrow .fill{height:100%;background:var(--teal);}
.barrow .val{width:70px;text-align:right;font-size:.75rem;}
.note{font-size:.82rem;color:var(--txt2);}
.foot{color:var(--muted);font-size:.76rem;text-align:center;margin-top:1.5rem;}
.empty{color:var(--muted);font-style:italic;padding:1rem 0;}
</style>
</head>
<body>
<div class="container">
  <h1>V-Soccer RNG Analysis</h1>
  <p class="subtitle">Uji keacakan berbasis <b><?= number_format($n) ?></b> pertandingan (matches.csv). Tes dirancang untuk <b>menemukan</b> pola — bila tak ada, itu bukti RNG murni.</p>
  <div class="bar">
    <a class="btn" href="vsoccer-dashboard.php">← Dashboard</a>
    <a class="btn" href="javascript:location.reload()">&#x21BB; Refresh</a>
  </div>

  <div class="verdicts">
    <div class="vcard">
      <div class="t">Autokorelasi lag-1</div>
      <div class="p mono"><?= sprintf('%+.4f', $acAvg) ?> <span class="pill <?= $vAc[1] ?>"><?= $vAc[0] ?></span></div>
      <div class="d">Match sebelumnya memprediksi berikutnya? Ambang ±<?= number_format($acCrit,3) ?>. Dekat 0 = tanpa memori.</div>
    </div>
    <div class="vcard">
      <div class="t">Streak (gambler's fallacy)</div>
      <div class="p mono"><?= number_format(abs($pAO-$pAU),1) ?>p <span class="pill <?= $vStreak[1] ?>"><?= $vStreak[0] ?></span></div>
      <div class="d">Over stlh Over <?= number_format($pAO,1) ?>% vs stlh Under <?= number_format($pAU,1) ?>%. Beda ~0 = independen.</div>
    </div>
    <div class="vcard">
      <div class="t">Runs test (urutan O/U)</div>
      <div class="p mono">z=<?= sprintf('%+.2f', $runsZavg) ?> <span class="pill <?= $vRuns[1] ?>"><?= $vRuns[0] ?></span></div>
      <div class="d">|z|&lt;1.96 = urutan Over/Under acak.</div>
    </div>
  </div>

  <div class="card">
    <h2>Distribusi total gol vs Poisson (teori acak)</h2>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr><th>Total gol</th><th>Aktual %</th><th>Poisson %</th><th>Selisih</th></tr></thead>
      <tbody>
      <?php foreach ($poissonRows as $p): $d=$p['obs']-$p['exp']; ?>
        <tr><td class="mono"><?= $p['g'] ?></td>
            <td class="mono"><?= number_format($p['obs'],2) ?>%</td>
            <td class="mono"><?= number_format($p['exp'],2) ?>%</td>
            <td class="mono <?= abs($d)>1.5?'y':'' ?>"><?= sprintf('%+.2f',$d) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="note" style="margin-top:.7rem;">λ (rata-rata gol) = <b class="mono"><?= number_format($lambda,3) ?></b> · chi-square = <b class="mono"><?= number_format($chi,1) ?></b> (dof <?= $dof ?>). Semakin dekat Aktual≈Poisson, semakin murni keacakannya.</p>
  </div>

  <div class="card">
    <h2>Distribusi menit gol (dari goal_events_vsoccer.csv)</h2>
    <?php if ($geN === 0): ?>
      <p class="empty">Belum ada data gol live. Panel ini terisi otomatis seiring extension merekam gol. Butuh beberapa ratus gol agar polanya stabil.</p>
    <?php else: ?>
      <p class="note" style="margin-bottom:.7rem;"><?= number_format($geN) ?> gol terekam. Bucket per 5 menit (skala sepak bola simulasi).</p>
      <?php foreach (['1H','2H'] as $half): $mx=max(1,max($minuteBuckets[$half])); ?>
        <div style="margin-bottom:.6rem;"><b class="mono"><?= $half ?></b></div>
        <?php for ($b=0;$b<9;$b++): $c=$minuteBuckets[$half][$b]; $lo=$b*5; $hi=$lo+4; ?>
          <div class="barrow">
            <span class="lbl mono"><?= $lo ?>-<?= $hi ?>'</span>
            <span class="track"><span class="fill" style="width:<?= round($c/$mx*100) ?>%"></span></span>
            <span class="val mono"><?= $c ?></span>
          </div>
        <?php endfor; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Kesimpulan</h2>
    <?php $allRandom = ($vAc[1]==='g' && $vStreak[1]==='g' && $vRuns[1]==='g'); ?>
    <?php if ($allRandom): ?>
      <p class="note"><b class="g">Ketiga uji keacakan lolos.</b> Tidak ditemukan pola urutan yang bisa dipakai memprediksi hasil berikutnya — konsisten dengan RNG murni. "Pola" yang tampak di mata (tim tertentu banyak gol, liga tertentu over) hanyalah <b>rata-rata jangka panjang</b> yang sudah tercermin di odds, bukan celah.</p>
    <?php else: ?>
      <p class="note"><b class="y">Ada uji yang menunjukkan penyimpangan.</b> Ini bisa berarti (a) sampel belum cukup besar, (b) fluktuasi acak biasa, atau (c) — jarang — anomali nyata yang perlu diselidiki. Tambah data lalu cek ulang; penyimpangan sejati akan bertahan, noise akan hilang.</p>
    <?php endif; ?>
  </div>

  <p class="foot">Edukasi statistik. Untuk RNG fair, house edge membuat EV jangka panjang tetap negatif.</p>
</div>
</body>
</html>
