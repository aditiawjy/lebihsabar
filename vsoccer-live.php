<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * V-Soccer Live View
 * Menampilkan snapshot terakhir dari vsoccer_headless.py (vsoccer_live.json):
 * daftar match yang sedang berjalan + gol terakhir yang terdeteksi + status runner.
 * Halaman ini hanya membaca; runner tetap dijalankan lewat start_vsoccer_headless.bat.
 */

$liveFile = __DIR__ . '/vsoccer_live.json';

// mode data: dipanggil oleh JS tiap beberapa detik
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!is_file($liveFile)) {
        echo json_encode(['status' => 'offline', 'note' => 'vsoccer_live.json belum ada — runner belum pernah jalan.', 'matches' => [], 'recent_goals' => []]);
        exit;
    }
    $raw = @file_get_contents($liveFile);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        echo json_encode(['status' => 'offline', 'note' => 'Snapshot tidak terbaca.', 'matches' => [], 'recent_goals' => []]);
        exit;
    }
    $data['age'] = max(0, time() - (int)($data['epoch'] ?? time()));
    $data['server_time'] = date('H:i:s');
    echo json_encode($data);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>V-Soccer Live View</title>
<style>
  body { margin:0; background:#0f1216; color:#e6e9ef; font:14px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif; }
  .wrap { max-width:1200px; margin:0 auto; padding:16px; }
  h1 { font-size:18px; margin:0 0 12px; }
  h2 { font-size:14px; margin:22px 0 8px; color:#9fb0c6; text-transform:uppercase; letter-spacing:.06em; }
  .bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; background:#161b22; border:1px solid #232b36; border-radius:8px; padding:10px 12px; }
  .pill { padding:3px 10px; border-radius:99px; font-weight:600; font-size:12px; }
  .ok { background:#0f3d24; color:#5ee39b; }
  .warn { background:#4a3a10; color:#ffd166; }
  .bad { background:#4a1720; color:#ff8095; }
  .muted { color:#8b97a8; font-size:12px; }
  table { width:100%; border-collapse:collapse; margin-top:6px; }
  th, td { padding:6px 8px; border-bottom:1px solid #202834; text-align:left; white-space:nowrap; }
  th { color:#8b97a8; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
  td.num { text-align:right; font-variant-numeric:tabular-nums; }
  tr.lg td { background:#141a22; color:#9fb0c6; font-weight:600; }
  .score { font-weight:700; font-variant-numeric:tabular-nums; }
  .goal { color:#5ee39b; }
  .inacc { color:#ffd166; }
  .ko { color:#8ecbff; font-weight:600; }
  .noko { color:#ff8095; font-size:11px; }
  .sig { background:#0f3d24; color:#5ee39b; border:1px solid #2f7d54; border-radius:4px;
         padding:1px 7px; font-size:11px; font-weight:700; letter-spacing:.04em; }
  .sig.supr { background:#4a3a10; color:#ffd166; border-color:#8a6a1c; }
  .sig.slow { background:#10304a; color:#8ecbff; border-color:#2b5f8a; }
  .seq { color:#c9a0ff; letter-spacing:.05em; }
  .nosig { color:#4c5666; cursor:help; }
  tr.hit td { background:#12291d; }
  tr.hit td:first-child { box-shadow:inset 3px 0 0 #5ee39b; }
  .empty { color:#8b97a8; padding:14px 4px; }
  .grid { display:grid; grid-template-columns:1.6fr 1fr; gap:20px; align-items:start; }
  @media (max-width:900px) { .grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <h1>V-Soccer Live View</h1>
  <div class="bar">
    <span id="st" class="pill warn">memuat…</span>
    <span id="sig" class="pill bad">SINYAL: 0</span>
    <span class="muted" id="meta"></span>
    <span class="muted" id="err"></span>
  </div>
  <p class="muted" id="legend" style="margin:8px 2px 0">
    <b>SUPER</b> — HT tidak seri (kalau seri: gol-2 ≤ 25' dan gol terakhir 1H ≥ 35') · gol pertama ≤ 8' · line awal ≥ 5.75<br>
    <b>SUPER1</b> — total gol HT tepat 3 (tanpa syarat menit) · line awal ≥ 6.75<br>
    <b>SUPER2</b> — selisih HT ≤ 1 (termasuk seri) · gol pertama ≤ 8' · line awal ≥ 7.25<br>
    <b>S-LOW</b> — selisih HT ≤ 1 (termasuk seri) · gol pertama ≤ 8' · line awal ≥ 5.75<br>
    <b>P1</b> — selisih HT tepat 1 · gol pertama ≤ 12' · line awal ≥ 5.75<br>
    <b>P2</b> — HT 2-1 / 1-2 · gol pertama ≤ 15' · line awal ≥ 5.5<br>
    <b>P3</b> — total gol HT tepat 3 · gol pertama 5'–9'<br>
    <b>P4</b> — HT 1-1 · gol pertama ≥ 15' · line awal ≥ 5.5<br>
    <b>P5</b> — HT 3-0 · gol pertama ≤ 18'<br>
    <b>P6</b> — HT 2-2 · gol pertama ≤ 8' · line awal ≤ 6.25<br>
    <b>P7</b> — HT 3-2 · gol pertama ≤ 8'<br>
    <b>P8</b> — HT 1-3 · line awal ≥ 6<br>
    <b>P9</b> — HT 3-3 (tanpa syarat tambahan)<br>
    <b>P10</b> — HT 2-3 · line awal ≥ 5.75<br>
    <b>P11</b> — total gol HT tepat 3 (low) · gol pertama ≤ 12'<br>
    <b>HAH</b> — urutan gol 1H Home–Away–Home, HT 2-1 (tanpa syarat menit / line)<br>
    Semua sinyal muncul selama babak kedua dan hilang begitu ada gol di babak kedua.
  </p>

  <div class="grid">
    <div>
      <h2>Match berjalan (<span id="cnt">0</span>)</h2>
      <table>
        <thead><tr>
          <th>Sinyal</th><th>Match</th><th>Babak</th><th class="num">Menit</th><th class="num">Skor</th>
          <th class="num">HT</th><th class="num">Gol-1</th><th class="num">Gol 1H</th><th class="num">Urutan</th>
          <th class="num">Tot</th><th class="num">Line KO</th><th class="num">O/U KO</th>
          <th class="num">Line</th><th class="num">Over</th><th class="num">Under</th>
        </tr></thead>
        <tbody id="tb"><tr><td colspan="15" class="empty">Menunggu data…</td></tr></tbody>
      </table>
    </div>
    <div>
      <h2>Gol terakhir terdeteksi</h2>
      <table>
        <thead><tr><th>Jam</th><th>Match</th><th>Menit</th><th class="num">Skor</th></tr></thead>
        <tbody id="gb"><tr><td colspan="4" class="empty">Belum ada.</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<script>
var esc = function (s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
  });
};
var shortLeague = function (s) {
  return String(s || '').replace('V-Soccer ', '').replace(' - 12 mins [V]', '').trim();
};

function render(d) {
  var st = document.getElementById('st');
  var age = d.age == null ? 999 : d.age;
  var s = d.status || 'offline';
  var cls = 'bad', label = 'OFFLINE';
  if (s === 'running' && age <= 15) { cls = 'ok'; label = 'RUNNING'; }
  else if (s === 'running') { cls = 'warn'; label = 'STALE (' + age + 's)'; }
  else if (s === 'starting' || s === 'reloading' || s === 'recovering') { cls = 'warn'; label = s.toUpperCase(); }
  else if (s === 'stopped') { cls = 'bad'; label = 'STOPPED'; }
  st.className = 'pill ' + cls;
  st.textContent = label;

  document.getElementById('meta').textContent =
    'update ' + (d.ts ? String(d.ts).substr(11, 8) : '-') + ' (' + age + 's lalu)' +
    ' · siklus ' + (d.cycles || 0) +
    ' · terkirim ' + (d.sent_goals || 0) + ' gol / ' + (d.sent_matches || 0) + ' match' +
    (d.note ? ' · ' + d.note : '');
  document.getElementById('err').textContent = d.last_error ? '⚠ ' + d.last_error : '';

  var m = d.matches || [];
  var nSig = d.signals || 0;
  var byCode = d.signals_by_code || {};
  var pats = d.patterns || [];
  var perCode = pats.map(function (p) { return p.code + ' ' + (byCode[p.code] || 0); }).join(' · ');
  var sigEl = document.getElementById('sig');
  sigEl.className = 'pill ' + (nSig ? 'ok' : 'bad');
  sigEl.textContent = 'SINYAL: ' + nSig + (perCode ? ' (' + perCode + ')' : '');
  document.title = (nSig ? '(' + nSig + ') ' : '') + 'V-Soccer Live View';
  if (pats.length) {
    document.getElementById('legend').innerHTML = pats.map(function (p) {
      var t = '<b>' + esc(p.code) + '</b> — ' + esc(p.desc);
      if (p.first_goal_min != null && p.first_goal_max != null) {
        t += ' · gol pertama ' + esc(p.first_goal_min) + "'–" + esc(p.first_goal_max) + "'";
      } else if (p.first_goal_min != null) {
        t += ' · gol pertama &ge; ' + esc(p.first_goal_min) + "'";
      } else if (p.first_goal_max != null) {
        t += ' · gol pertama &le; ' + esc(p.first_goal_max) + "'";
      }
      if (p.min_line != null) t += ' · line awal &ge; ' + esc(p.min_line);
      if (p.max_line != null) t += ' · line awal &le; ' + esc(p.max_line);
      return t;
    }).join('<br>') +
      '<br>Semua sinyal muncul selama babak kedua dan hilang begitu ada gol di babak kedua.';
  }
  document.getElementById('cnt').textContent = m.length;
  var tb = document.getElementById('tb'), rows = '', lastLg = null;
  if (!m.length) {
    rows = '<tr><td colspan="15" class="empty">Tidak ada match terbaca.</td></tr>';
  } else {
    for (var i = 0; i < m.length; i++) {
      var r = m[i];
      if (r.league !== lastLg) {
        lastLg = r.league;
        rows += '<tr class="lg"><td colspan="15">' + esc(shortLeague(r.league)) + '</td></tr>';
      }
      var koOu = (r.ko_over || r.ko_under) ? (esc(r.ko_over || '-') + ' / ' + esc(r.ko_under || '-')) : '-';
      var hits = r.hits || (r.signal ? ['P1'] : []);
      var sigCell = hits.length
        ? hits.map(function (c) {
            var extra = c.indexOf('SUPER') === 0 ? ' supr' : (c === 'S-LOW' ? ' slow' : '');
            return '<span class="sig' + extra + '">' + esc(c) + '</span>';
          }).join(' ')
        : '<span class="nosig" title="' + esc(r.signal_why || '') + '">–</span>';
      rows += '<tr' + (r.signal ? ' class="hit"' : '') + '>' +
        '<td>' + sigCell + '</td>' +
        '<td>' + esc(r.home) + ' vs ' + esc(r.away) + '</td>' +
        '<td>' + esc(r.half) + '</td>' +
        '<td class="num">' + (r.minute >= 0 ? r.minute + "'" : '-') + '</td>' +
        '<td class="num score">' + esc(r.score) + '</td>' +
        '<td class="num">' + esc(r.ht || '-') + '</td>' +
        '<td class="num">' + (r.first_goal_min == null ? '-' : r.first_goal_min + "'") + '</td>' +
        '<td class="num" title="menit gol babak pertama">' +
          ((r.goal_mins_1h && r.goal_mins_1h.length) ? esc(r.goal_mins_1h.join(', ')) : '-') + '</td>' +
        '<td class="num seq" title="urutan pencetak gol babak pertama (H=home, A=away)">' +
          esc(r.goal_seq_1h || '-') + '</td>' +
        '<td class="num">' + (r.total == null ? '-' : r.total) + '</td>' +
        '<td class="num ' + (r.ko_line ? 'ko' : 'noko') + '">' + esc(r.ko_line || 'belum ada') + '</td>' +
        '<td class="num">' + koOu + '</td>' +
        '<td class="num">' + esc(r.line || '-') + '</td>' +
        '<td class="num">' + esc(r.over || '-') + '</td>' +
        '<td class="num">' + esc(r.under || '-') + '</td>' +
        '</tr>';
    }
  }
  tb.innerHTML = rows;

  var g = d.recent_goals || [], gr = '';
  if (!g.length) {
    gr = '<tr><td colspan="4" class="empty">Belum ada.</td></tr>';
  } else {
    for (var j = 0; j < g.length; j++) {
      var x = g[j];
      gr += '<tr>' +
        '<td>' + esc(x.time) + '</td>' +
        '<td>' + esc(x.home_team) + ' vs ' + esc(x.away_team) + '</td>' +
        '<td>' + esc(x.minute) + '</td>' +
        '<td class="num ' + (x.accurate ? 'goal' : 'inacc') + '">' + esc(x.score_after) + '</td>' +
        '</tr>';
    }
  }
  document.getElementById('gb').innerHTML = gr;
}

function tick() {
  fetch('vsoccer-live.php?json=1&t=' + Date.now(), { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(render)
    .catch(function (e) {
      var st = document.getElementById('st');
      st.className = 'pill bad';
      st.textContent = 'GAGAL AMBIL DATA';
      document.getElementById('err').textContent = '⚠ ' + e;
    });
}
tick();
setInterval(tick, 2000);
</script>
</body>
</html>
