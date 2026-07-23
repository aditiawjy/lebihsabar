<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * V-Soccer "Pick Hari Ini" — panduan taruhan poin/voucher.
 * Menghitung win-rate Over per (liga × garis) dari matches.csv (16.878 match V-Soccer),
 * lalu menyorot rekomendasi paling aman. Untuk game poin (tanpa rugi uang):
 * pilih Over garis rendah di liga gol tinggi.
 */

$csv = __DIR__ . '/matches.csv';
$lg = [];
if (is_file($csv) && ($fh = fopen($csv, 'r'))) {
    $hdr = fgetcsv($fh); $ix = array_flip($hdr ?: []);
    while (($r = fgetcsv($fh)) !== false) {
        if (strpos($r[$ix['league']] ?? '', '[V]') === false) continue;
        $h = $r[$ix['ft_home']] ?? ''; $a = $r[$ix['ft_away']] ?? '';
        if (!is_numeric($h) || !is_numeric($a)) continue;
        $name = trim(str_replace([' - 12 mins [V]', 'V-Soccer '], '', $r[$ix['league']]));
        $lg[$name][] = (int)round($h) + (int)round($a);
    }
    fclose($fh);
}

// [garis, total gol minimal untuk menang Over] — pakai string agar kunci tak dibulatkan PHP
$LINES = [['1.5',2], ['2.5',3], ['3.5',4], ['4.5',5]];
$rows = [];
foreach ($lg as $name => $ts) {
    $n = count($ts); if ($n < 200) continue;
    $avg = array_sum($ts) / $n;
    $wr = [];
    foreach ($LINES as [$line, $need]) {
        $wr[$line] = array_sum(array_map(fn($t) => $t >= $need ? 1 : 0, $ts)) / $n * 100;
    }
    $rows[] = ['name'=>$name, 'n'=>$n, 'avg'=>$avg, 'wr'=>$wr];
}
usort($rows, fn($x, $y) => $y['avg'] <=> $x['avg']);
$top = $rows[0] ?? null; // liga paling subur = rekomendasi utama

function heat($p) { // hijau (tinggi) -> merah (rendah)
    $hue = max(0, min(120, ($p - 55) / 45 * 120));
    return "background:hsla($hue,60%,45%,.22)";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>V-Soccer — Pick Hari Ini</title>
<style>
:root{--bg:#0f1117;--card:#161b22;--border:#30363d;--txt:#e1e4e8;--txt2:#8b949e;--muted:#484f58;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--yellow:#d29922;}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--txt);font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;line-height:1.5;}
.container{max-width:840px;margin:0 auto;padding:1.5rem 1.25rem 4rem;}
h1{font-size:1.5rem;}.subtitle{color:var(--txt2);font-size:.9rem;margin:.15rem 0 1.25rem;}
.mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,Consolas,monospace;}
.bar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;font-size:.8rem;}
.btn{background:#1c2129;color:var(--txt);border:1px solid var(--border);padding:.35rem .7rem;border-radius:6px;text-decoration:none;font-size:.8rem;}
.btn:hover{border-color:var(--accent);}
.hero{background:linear-gradient(135deg,#132a1c,#161b22);border:1px solid #238636;border-radius:12px;padding:1.3rem 1.4rem;margin-bottom:1.4rem;}
.hero .lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--green);font-weight:700;}
.hero .pick{font-size:1.4rem;font-weight:800;margin:.3rem 0;}
.hero .wr{font-size:2rem;font-weight:800;color:var(--green);font-variant-numeric:tabular-nums;}
.hero .sub{color:var(--txt2);font-size:.85rem;margin-top:.3rem;}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.2rem;margin-bottom:1.1rem;}
.card h2{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt2);margin-bottom:.8rem;}
table{width:100%;border-collapse:collapse;font-size:.86rem;}
th,td{padding:.45rem .5rem;text-align:center;border-bottom:1px solid var(--border);}
th:first-child,td:first-child{text-align:left;}
th{color:var(--txt2);font-size:.72rem;text-transform:uppercase;}
tbody tr:first-child{outline:2px solid #238636;outline-offset:-2px;}
.avg{color:var(--txt2);font-size:.8rem;}
.steps{list-style:none;counter-reset:s;}
.steps li{counter-increment:s;padding:.35rem 0 .35rem 2rem;position:relative;font-size:.9rem;}
.steps li::before{content:counter(s);position:absolute;left:0;top:.3rem;width:1.4rem;height:1.4rem;background:var(--accent);color:#0d1117;border-radius:50%;display:grid;place-items:center;font-size:.75rem;font-weight:700;}
.warnbox{border-left:3px solid var(--yellow);background:rgba(210,153,34,.08);padding:.8rem 1rem;border-radius:0 8px 8px 0;font-size:.82rem;color:var(--txt2);margin-top:.5rem;}
.warnbox b{color:var(--yellow);}
.foot{color:var(--muted);font-size:.76rem;text-align:center;margin-top:1.5rem;}
</style>
</head>
<body>
<div class="container">
  <h1>V-Soccer — Pick Hari Ini</h1>
  <p class="subtitle">Panduan pilih 1 taruhan poin/voucher paling aman. Dari <b><?= number_format(array_sum(array_map(fn($r)=>$r['n'],$rows))) ?></b> match historis.</p>
  <div class="bar">
    <a class="btn" href="vsoccer-dashboard.php">Dashboard</a>
    <a class="btn" href="vsoccer-pattern-validator.php">Validator</a>
    <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
  </div>

  <?php if ($top): ?>
  <div class="hero">
    <div class="lbl">★ Rekomendasi utama (paling aman)</div>
    <div class="pick"><?= htmlspecialchars($top['name']) ?> — <b>Over 1.5</b></div>
    <div class="wr"><?= number_format($top['wr']['1.5'],1) ?>% menang</div>
    <div class="sub">Liga paling subur (rata-rata <?= number_format($top['avg'],1) ?> gol/match). Kalau garis Over 1.5 tak ada, naik ke Over 2.5 (<?= number_format($top['wr']['2.5'],1) ?>%) atau 3.5 (<?= number_format($top['wr']['3.5'],1) ?>%).</div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Peluang menang Over per liga & garis</h2>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr><th>Liga (urut tersubur)</th><th>Rata2 gol</th><th>Over 1.5</th><th>Over 2.5</th><th>Over 3.5</th><th>Over 4.5</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="avg mono"><?= number_format($r['avg'],1) ?></td>
          <?php foreach (['1.5','2.5','3.5','4.5'] as $ln): $p=$r['wr'][$ln]; ?>
            <td class="mono" style="<?= heat($p) ?>"><?= number_format($p,0) ?>%</td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="avg" style="margin-top:.6rem;">Hijau = aman (menang sering), merah = berisiko. Makin rendah garis Over, makin sering menang.</p>
  </div>

  <div class="card">
    <h2>Cara pakai (1 pick sehari)</h2>
    <ol class="steps">
      <li>Buka situs, lihat liga V-Soccer apa yang sedang ada.</li>
      <li>Pilih liga <b>paling atas</b> di tabel ini yang tersedia (idealnya <b><?= $top ? htmlspecialchars($top['name']) : 'Women\'s Nations League' ?></b>).</li>
      <li>Pasang <b>Over di garis PALING RENDAH</b> yang ditawarkan (Over 1.5/2.5 kalau ada → ~99%).</li>
      <li>Cukup <b>1 taruhan</b>. Jangan tambah, jangan chase.</li>
    </ol>
    <div class="warnbox">
      <b>Ingat:</b> 99% ≠ 100% — sesekali tetap kalah, itu wajar (RNG). Angka ini peluang terbaik yang <b>nyata di data</b>, bukan jaminan. Jangan pertaruhkan semua poin di satu match.
    </div>
  </div>

  <p class="foot">Panduan berbasis 16.878 match historis. Bukan jaminan menang; untuk game poin/voucher.</p>
</div>
</body>
</html>
