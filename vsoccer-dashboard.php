<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * V-Soccer Analytics Dashboard
 * Membaca matches.csv (hanya liga "[V]" / 1x2 Virtual Soccer), menghitung
 * statistik distribusi gol, tabel per liga, rating tim, dan pengecek EV.
 * Berdiri sendiri — tidak memakai cache/goal_log SABA PES.
 */

$csvFile = __DIR__ . '/matches.csv';

$leagues = [];   // name => ['n','goals','H','D','A','o15','o25','o35','o45','btts','dist'=>[]]
$teams   = [];   // name => ['for','against','g','league']
$totalN  = 0;
$globalDist = [];
$csvTime = is_file($csvFile) ? filemtime($csvFile) : null;

if (is_file($csvFile) && ($fh = fopen($csvFile, 'r'))) {
    $header = fgetcsv($fh);
    $idx = array_flip($header ?: []);
    while (($row = fgetcsv($fh)) !== false) {
        $lg = $row[$idx['league']] ?? '';
        if (strpos($lg, '[V]') === false) continue;
        $h = $row[$idx['ft_home']] ?? '';
        $a = $row[$idx['ft_away']] ?? '';
        if ($h === '' || $a === '' || !is_numeric($h) || !is_numeric($a)) continue;
        $h = (int)round((float)$h); $a = (int)round((float)$a);
        $tg = $h + $a;
        $name = trim(str_replace([' - 12 mins [V]', 'V-Soccer '], '', $lg));

        if (!isset($leagues[$name])) {
            $leagues[$name] = ['n'=>0,'goals'=>0,'H'=>0,'D'=>0,'A'=>0,'o15'=>0,'o25'=>0,'o35'=>0,'o45'=>0,'btts'=>0,'dist'=>[]];
        }
        $L = &$leagues[$name];
        $L['n']++; $L['goals'] += $tg;
        if ($h > $a) $L['H']++; elseif ($h < $a) $L['A']++; else $L['D']++;
        if ($tg >= 2) $L['o15']++;
        if ($tg >= 3) $L['o25']++;
        if ($tg >= 4) $L['o35']++;
        if ($tg >= 5) $L['o45']++;
        if ($h > 0 && $a > 0) $L['btts']++;
        $L['dist'][$tg] = ($L['dist'][$tg] ?? 0) + 1;
        $globalDist[$tg] = ($globalDist[$tg] ?? 0) + 1;
        unset($L);

        $ht = $row[$idx['home_team']] ?? ''; $at = $row[$idx['away_team']] ?? '';
        foreach ([[$ht,$h,$a],[$at,$a,$h]] as [$tn,$gf,$ga]) {
            if ($tn === '') continue;
            if (!isset($teams[$tn])) $teams[$tn] = ['for'=>0,'against'=>0,'g'=>0,'league'=>$name];
            $teams[$tn]['for'] += $gf; $teams[$tn]['against'] += $ga; $teams[$tn]['g']++;
        }
        $totalN++;
    }
    fclose($fh);
}

// urut liga by rata-rata gol desc
uasort($leagues, fn($x,$y) => ($y['goals']/max($y['n'],1)) <=> ($x['goals']/max($x['n'],1)));

$globalGoals = array_sum(array_map(fn($l)=>$l['goals'], $leagues));
$avgAll = $totalN ? $globalGoals / $totalN : 0;

// rating tim (min 40 game)
$teamRows = [];
foreach ($teams as $tn => $t) {
    if ($t['g'] >= 40) $teamRows[] = ['name'=>$tn,'gf'=>$t['for']/$t['g'],'ga'=>$t['against']/$t['g'],'g'=>$t['g'],'lg'=>$t['league']];
}
usort($teamRows, fn($x,$y) => $y['gf'] <=> $x['gf']);
$topTeams = array_slice($teamRows, 0, 10);
$botTeams = array_slice($teamRows, -8);

// data distribusi utk JS (per liga)
$distJs = [];
foreach ($leagues as $name => $L) {
    $max = $L['dist'] ? max(array_keys($L['dist'])) : 0;
    $arr = [];
    for ($g = 0; $g <= $max; $g++) $arr[] = $L['dist'][$g] ?? 0;
    $distJs[$name] = ['n'=>$L['n'], 'counts'=>$arr];
}

function pct($num, $den) { return $den ? number_format($num/$den*100, 1) : '0.0'; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>V-Soccer Analytics Dashboard</title>
<style>
:root{
  --bg:#0f1117;--card:#161b22;--header:#1c2129;--deep:#0d1117;--border:#30363d;
  --txt:#e1e4e8;--txt2:#8b949e;--muted:#484f58;--accent:#58a6ff;
  --green:#3fb950;--yellow:#d29922;--red:#f85149;--teal:#39c5bb;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--txt);font-family:system-ui,'Segoe UI',sans-serif;line-height:1.5;font-size:14px;}
.container{max-width:1100px;margin:0 auto;padding:1.5rem 1.25rem 4rem;}
h1{font-size:1.5rem;letter-spacing:-.01em;}
.subtitle{color:var(--txt2);font-size:.9rem;margin:.15rem 0 1.25rem;}
.mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,Consolas,monospace;}
.bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1.25rem;font-size:.8rem;color:var(--txt2);}
.badge{background:#1a3a22;color:var(--green);border:1px solid #238636;padding:.15rem .5rem;border-radius:12px;font-size:.72rem;font-weight:700;}
.btn{background:var(--header);color:var(--txt);border:1px solid var(--border);padding:.35rem .7rem;border-radius:6px;cursor:pointer;text-decoration:none;font-size:.8rem;}
.btn:hover{border-color:var(--accent);}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.25rem;}
@media(max-width:640px){.kpis{grid-template-columns:repeat(2,1fr);}}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:.9rem 1rem;}
.kpi .k{font-size:.72rem;color:var(--txt2);text-transform:uppercase;letter-spacing:.05em;}
.kpi .v{font-size:1.6rem;font-weight:700;margin-top:.15rem;}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.2rem;margin-bottom:1.25rem;}
.card h2{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt2);margin-bottom:.9rem;}
table{width:100%;border-collapse:collapse;font-size:.85rem;}
th,td{padding:.5rem .6rem;text-align:right;border-bottom:1px solid var(--border);}
th:first-child,td:first-child{text-align:left;}
th{color:var(--txt2);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;cursor:default;}
tbody tr:hover{background:#1a2030;}
.heat{display:inline-block;min-width:44px;padding:.1rem .3rem;border-radius:4px;}
.split{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
@media(max-width:760px){.split{grid-template-columns:1fr;}}
select,input{width:100%;padding:.5rem .6rem;background:var(--deep);color:var(--txt);border:1px solid var(--border);border-radius:6px;font-size:.9rem;font-variant-numeric:tabular-nums;}
select:focus,input:focus{outline:2px solid var(--accent);outline-offset:1px;}
label{display:block;font-size:.72rem;color:var(--txt2);margin-bottom:.3rem;}
.ctrl{display:grid;grid-template-columns:2fr 1fr 1fr;gap:.8rem;}
@media(max-width:560px){.ctrl{grid-template-columns:1fr;}}
canvas{width:100%;height:180px;display:block;margin-top:.5rem;}
.ev{font-size:1.25rem;font-weight:700;}
.g{color:var(--green);}.r{color:var(--red);}.y{color:var(--yellow);}
.verdict{background:var(--deep);border:1px solid var(--border);border-radius:8px;padding:.9rem 1rem;}
.vrow{display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px dashed var(--border);}
.vrow:last-child{border-bottom:0;}
.vrow .k{color:var(--txt2);font-size:.82rem;}
.warnbox{border-left:3px solid var(--yellow);background:rgba(210,153,34,.08);padding:.8rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;color:var(--txt2);margin-top:1rem;}
.warnbox b{color:var(--yellow);}
.pill{padding:.1rem .5rem;border-radius:12px;font-size:.72rem;font-weight:700;}
.pill.g{background:rgba(63,185,80,.15);color:var(--green);}
.pill.r{background:rgba(248,81,73,.15);color:var(--red);}
.pill.y{background:rgba(210,153,34,.15);color:var(--yellow);}
.foot{color:var(--muted);font-size:.76rem;text-align:center;margin-top:1.5rem;}
</style>
</head>
<body>
<div class="container">
  <h1>V-Soccer Analytics Dashboard</h1>
  <p class="subtitle">Analisis 1x2 Virtual Soccer (liga <span class="mono">[V]</span>) berdasarkan <b><?= number_format($totalN) ?></b> pertandingan di matches.csv</p>

  <div class="bar">
    <span class="badge">&#x25CF; DATA</span>
    <span>Update CSV: <?= $csvTime ? date('d/m/Y H:i', $csvTime) : '—' ?></span>
    <a class="btn" href="javascript:location.reload()">&#x21BB; Refresh</a>
    <a class="btn" href="dashboard.php">← Dashboard SABA</a>
  </div>

  <?php if ($totalN === 0): ?>
    <div class="card"><b>Tidak ada data V-Soccer.</b> Pastikan matches.csv berisi baris dengan liga mengandung <span class="mono">[V]</span>.</div>
  <?php else: ?>

  <div class="kpis">
    <div class="kpi"><div class="k">Total Match</div><div class="v mono"><?= number_format($totalN) ?></div></div>
    <div class="kpi"><div class="k">Rata-rata Gol</div><div class="v mono"><?= number_format($avgAll,2) ?></div></div>
    <div class="kpi"><div class="k">Jumlah Liga</div><div class="v mono"><?= count($leagues) ?></div></div>
    <div class="kpi"><div class="k">Over 2.5 (global)</div><div class="v mono"><?= pct(array_sum(array_map(fn($l)=>$l['o25'],$leagues)),$totalN) ?>%</div></div>
  </div>

  <div class="card">
    <h2>Statistik per liga</h2>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr>
        <th>Liga</th><th>Match</th><th>Gol/gm</th><th>Home</th><th>Draw</th><th>Away</th>
        <th>O1.5</th><th>O2.5</th><th>O3.5</th><th>BTTS</th>
      </tr></thead>
      <tbody>
      <?php foreach ($leagues as $name => $L): $n=$L['n']; $o25=$L['o25']/$n*100;
        $hue = max(0, min(120, ($o25-85)/13*120)); ?>
        <tr>
          <td><?= htmlspecialchars($name) ?></td>
          <td class="mono"><?= number_format($n) ?></td>
          <td class="mono"><b><?= number_format($L['goals']/$n,2) ?></b></td>
          <td class="mono"><?= pct($L['H'],$n) ?>%</td>
          <td class="mono"><?= pct($L['D'],$n) ?>%</td>
          <td class="mono"><?= pct($L['A'],$n) ?>%</td>
          <td class="mono"><?= pct($L['o15'],$n) ?>%</td>
          <td class="mono"><span class="heat" style="background:hsla(<?= round($hue) ?>,55%,45%,.22)"><?= number_format($o25,1) ?>%</span></td>
          <td class="mono"><?= pct($L['o35'],$n) ?>%</td>
          <td class="mono"><?= pct($L['btts'],$n) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="split">
    <div class="card">
      <h2>Top 10 tim — gol dicetak (min 40 gm)</h2>
      <table>
        <thead><tr><th>Tim</th><th>Gol/gm</th><th>Kbbl/gm</th><th>Gm</th></tr></thead>
        <tbody>
        <?php foreach ($topTeams as $t): ?>
          <tr><td><?= htmlspecialchars($t['name']) ?></td>
              <td class="mono g"><b><?= number_format($t['gf'],2) ?></b></td>
              <td class="mono"><?= number_format($t['ga'],2) ?></td>
              <td class="mono"><?= $t['g'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <h2>Bottom 8 tim — paling sedikit gol</h2>
      <table>
        <thead><tr><th>Tim</th><th>Gol/gm</th><th>Kbbl/gm</th><th>Gm</th></tr></thead>
        <tbody>
        <?php foreach ($botTeams as $t): ?>
          <tr><td><?= htmlspecialchars($t['name']) ?></td>
              <td class="mono r"><b><?= number_format($t['gf'],2) ?></b></td>
              <td class="mono"><?= number_format($t['ga'],2) ?></td>
              <td class="mono"><?= $t['g'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Distribusi gol &amp; pengecek Expected Value</h2>
    <div class="ctrl">
      <div><label>Liga</label><select id="league"></select></div>
      <div><label>Rata-rata gol</label><input id="avg" readonly class="mono"></div>
      <div><label>Sampel</label><input id="samp" readonly class="mono"></div>
    </div>
    <canvas id="chart"></canvas>
    <div class="ctrl" style="margin-top:1rem;grid-template-columns:1fr 1fr 1fr;">
      <div><label>Garis O/U (mis. 4.5 / 4/4.5)</label><input id="line" value="4/4.5"></div>
      <div><label>Odds OVER</label><input id="oOver" type="number" step="0.01" value="1.73"></div>
      <div><label>Odds UNDER</label><input id="oUnder" type="number" step="0.01" value="1.96"></div>
    </div>
    <div class="split" style="margin-top:1rem;">
      <div class="verdict" id="vOver"></div>
      <div class="verdict" id="vUnder"></div>
    </div>
    <div class="warnbox">
      <b>⚠ Penting.</b> Peluang riil dihitung dari total gol <b>full-time sejak menit 0</b>. Bila odds yang kamu masukkan diambil dari layar <b>LIVE</b> (match sudah berjalan), perbandingan tidak sah — garis live sudah menyesuaikan skor berjalan, sehingga "EV positif" yang muncul adalah artefak, bukan celah. Uji sahih perlu odds tercatat tepat di kickoff + hasil akhir.
    </div>
  </div>

  <?php endif; ?>

  <p class="foot">Edukasi statistik. Untuk RNG fair, house edge membuat EV jangka panjang tetap negatif.</p>
</div>

<script>
const DATA = <?= json_encode($distJs, JSON_UNESCAPED_UNICODE) ?>;
const $=id=>document.getElementById(id);
const sel=$('league');
function avgOf(k){const c=DATA[k].counts;let s=0;c.forEach((v,g)=>s+=v*g);return s/DATA[k].n;}
function overPct(k,line){let s=0;DATA[k].counts.forEach((v,g)=>{if(g>line)s+=v;});return s/DATA[k].n*100;}
Object.keys(DATA).sort((a,b)=>avgOf(b)-avgOf(a)).forEach(k=>{const o=document.createElement('option');o.value=k;o.textContent=k;sel.appendChild(o);});

function drawChart(k){
  const c=DATA[k].counts,cv=$('chart'),dpr=devicePixelRatio||1,rect=cv.getBoundingClientRect();
  cv.width=rect.width*dpr;cv.height=180*dpr;const x=cv.getContext('2d');x.scale(dpr,dpr);
  const w=rect.width,h=180,pad=22,bw=(w-pad*2)/c.length,max=Math.max(...c);
  x.clearRect(0,0,w,h);
  c.forEach((v,g)=>{const bh=(v/max)*(h-pad*2),bx=pad+g*bw,by=h-pad-bh;
    x.fillStyle='#39c5bb';x.globalAlpha=.85;x.fillRect(bx+1,by,bw-2,bh);x.globalAlpha=1;
    x.fillStyle='#8b949e';x.font='10px monospace';x.textAlign='center';x.fillText(g,bx+bw/2,h-pad+13);});
}
function legStats(k,line,isOver){let w=0,p=0,l=0;DATA[k].counts.forEach((cnt,g)=>{
  if(Math.abs(g-line)<1e-9)p+=cnt;else if((g>line)===isOver)w+=cnt;else l+=cnt;});
  const n=DATA[k].n;return{w:w/n,p:p/n,l:l/n};}
function evalSide(k,lineStr,odd,isOver){
  const lines=lineStr.split('/').map(s=>parseFloat(s.trim())).filter(x=>!isNaN(x));
  const use=lines.length?lines:[parseFloat(lineStr)];let w=0,p=0,l=0;
  use.forEach(ln=>{const s=legStats(k,ln,isOver);w+=s.w;p+=s.p;l+=s.l;});
  w/=use.length;p/=use.length;l/=use.length;
  return{w,p,l,ev:w*(odd-1)+l*(-1),implied:1/odd};}
function box(el,r,label){
  const evPct=r.ev*100,cls=r.ev>0.001?'g':(r.ev<-0.001?'r':'y');
  el.innerHTML=`<div class="vrow"><span class="k">${label}</span><span class="pill ${cls}">EV ${r.ev>=0?'+':''}${evPct.toFixed(1)}%</span></div>
    <div class="vrow"><span class="k">Peluang riil menang</span><span class="mono">${(r.w*100).toFixed(1)}%</span></div>
    <div class="vrow"><span class="k">Push (seri garis)</span><span class="mono">${(r.p*100).toFixed(1)}%</span></div>
    <div class="vrow"><span class="k">Peluang tersirat odds</span><span class="mono">${(r.implied*100).toFixed(1)}%</span></div>
    <div class="vrow"><span class="k">EV / Rp100.000</span><span class="ev mono ${cls}">${r.ev>=0?'+':''}Rp${Math.round(r.ev*100000).toLocaleString('id-ID')}</span></div>`;
}
function render(){
  const k=sel.value;
  $('avg').value=avgOf(k).toFixed(2)+' gol';
  $('samp').value=DATA[k].n.toLocaleString('id-ID');
  drawChart(k);
  const line=$('line').value;
  box($('vOver'),evalSide(k,line,parseFloat($('oOver').value)||1,true),'OVER '+line);
  box($('vUnder'),evalSide(k,line,parseFloat($('oUnder').value)||1,false),'UNDER '+line);
}
['change','input'].forEach(e=>{sel.addEventListener(e,render);['line','oOver','oUnder'].forEach(id=>$(id).addEventListener(e,render));});
addEventListener('resize',()=>drawChart(sel.value));
if(DATA['Germany Bundesliga'])sel.value='Germany Bundesliga';
render();
</script>
</body>
</html>
