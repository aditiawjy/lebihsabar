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
  .stk { display:inline-block; background:#3a2a10; color:#ffc95e; border:1px solid #7a5a1c;
         border-radius:4px; padding:1px 6px; font-size:11px; font-weight:700; cursor:help; margin:1px 2px 1px 0; }
  .stk.away { background:#2a1030; color:#e0a6ff; border-color:#6a3a7a; }
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
    <span id="stk" class="pill warn" title="Sumber kolom Peluang 100%: cache tabel streak (index.php?page=streak).">STREAK: –</span>
    <span class="muted" id="meta"></span>
    <span class="muted" id="err"></span>
  </div>
  <p class="muted" id="legend" style="margin:8px 2px 0">
    <b>SUPER</b> — selisih HT ≤ 1 (kalau seri: gol-2 ≤ 25' dan gol terakhir 1H ≥ 35') · gol pertama ≤ 5' · line awal ≥ 5.75 · semua HT tidak seri: gol-2 9'–25' · total HT 3 + gol-1 ≤ 4': line ≥ 7.25 · total HT 5: gol terakhir 1H ≥ 30' · mulai menit 60 babak kedua<br>
    <b>SUPER1</b> <span class="noko">NONAKTIF</span> — total gol HT tepat 3 · gol pertama ≤ 18' · line awal 6.75–7.5 · line 7.5: gol pertama wajib ≤ 9' · HT 3-0/0-3: line tepat 7.5<br>
    <b>SUPER2</b> <span class="noko">NONAKTIF</span> — selisih HT ≤ 1 · gol pertama ≤ 8' · line awal ≥ 7.25 · total HT 3: gol pertama ≤ 6' · total HT 5: gol-2 menit 9'–30' · HT 2-2: gol-2 menit 14'–30' · total HT ≥ 7: line awal ≥ 7.5<br>
    <b>S-LOW</b> <span class="noko">NONAKTIF</span> — selisih HT ≤ 1 (kalau seri: gol-2 ≤ 25' dan gol terakhir 1H ≥ 35') · gol pertama ≤ 5' · line awal ≥ 5.75 · tanpa total HT 1 · semua HT tidak seri: gol-2 9'–25' · total HT 3: line ≤ 7.5 dan gol-1 ≤ 4' wajib line ≥ 7.25 · total HT 5: gol terakhir 1H ≥ 30'<br>
    <b>SUPER3</b> <span class="noko">NONAKTIF</span> — selisih skor HT tepat 3 · line awal ≥ 6 · gol terakhir 1H ≥ 42'<br>
    <b>SUPER4</b> <span class="noko">NONAKTIF</span> — total gol HT tepat 5 · urutan X–Y–X–X–bebas · tanpa syarat menit / line<br>
    <b>P12</b> — total gol HT tepat 5 · line awal ≥ 6.5 · gol kedua ≥ 8'<br>
    <b>P1</b> — selisih HT tepat 1 · total HT maksimal 5 · gol pertama ≤ 12' · line awal ≥ 5.75 · total HT 1: gol-1 ≤ 6' · total HT 3: line ≤ 7.5 dan gol-1 ≤ 4' wajib line ≥ 7.25 · total HT 5: gol-2 9'–30'<br>
    <b>P2</b> — HT 2-1 / 1-2 · gol pertama ≤ 15' · line awal 5.75–7.5 · gol pertama ≤ 4': line wajib ≥ 7.25<br>
    <b>P3</b> — total gol HT tepat 3 · gol pertama 5'–9' · line awal 5.5–7.5 · HT 3-0/0-3: line wajib ≥ 6.5<br>
    <b>P4</b> — HT 1-1 · gol pertama ≥ 15' · line awal ≥ 5.5<br>
    <b>P5</b> — HT 3-0 · gol pertama ≤ 18' · gol terakhir 1H ≤ 40'<br>
    <b>P6</b> — HT 2-2 · gol pertama ≤ 8' · line awal ≤ 6.25<br>
    <b>P7</b> — HT 3-2 · gol pertama ≤ 8'<br>
    <b>P8</b> — HT 1-3 · line awal ≥ 6<br>
    <b>P9</b> — HT 3-3 · gol kedua ≥ 12' · gol terakhir 1H ≥ 34'<br>
    <b>P10</b> — HT 2-3 · line awal ≥ 5.75<br>
    <b>P11</b> — total gol HT tepat 3 · gol pertama ≤ 12' · line awal 5.75–7.5 · gol pertama ≤ 4': line wajib ≥ 7.25 · HT 3-0/0-3: line wajib ≥ 7.5<br>
    <b>HAH</b> — urutan gol 1H Home–Away–Home · HT 2-1 · gol kedua harus setelah gol pertama · gol ketiga/terakhir 1H ≥ 26' · line awal ≥ 4.75; jika line lebih rendah, gol ketiga wajib ≥ 38'<br>
    SUPER dan HAH aktif mulai menit 60 babak kedua; P1-P12 mulai menit 65; SUPER1/SUPER2/S-LOW/SUPER3/SUPER4 dinonaktifkan. Semua sinyal hilang begitu ada gol di babak kedua.
  </p>

  <div class="grid">
    <div>
      <h2>Match berjalan (<span id="cnt">0</span>)</h2>
      <table>
        <thead><tr>
          <th>Sinyal</th>
          <th title="Peluang 100% dari tabel streak (index.php?page=streak), khusus market Over. H = tim kandang, A = tim tandang.">Peluang 100%</th>
          <th>Match</th><th>Babak</th><th class="num">Menit</th><th class="num">Skor</th>
          <th class="num">HT</th><th class="num">Gol-1</th><th class="num">Gol 1H</th><th class="num">Urutan</th>
          <th class="num">Tot</th><th class="num">Line KO</th><th class="num">O/U KO</th>
          <th class="num">Line</th><th class="num">Over</th><th class="num">Under</th>
        </tr></thead>
        <tbody id="tb"><tr><td colspan="16" class="empty">Menunggu data…</td></tr></tbody>
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

<script src="assets/streak100.js?v=<?= filemtime(__DIR__ . '/assets/streak100.js') ?>"></script>
<script>
var esc = function (s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
  });
};
var shortLeague = function (s) {
  return String(s || '').replace('V-Soccer ', '').replace(' - 12 mins [V]', '').trim();
};

// ---- Peluang 100% dari tabel streak ---------------------------------------
// Baris streak diambil dari streak-100-api.php (cache halaman streak, TIDAK
// memicu rebuild), lalu disaring memakai assets/streak100.js — modul yang sama
// dengan index.php?page=streak, jadi hasilnya tidak mungkin beda aturan.
// Khusus market Over, sesuai pattern SUPER/P1-P12 yang semuanya bertaruh Over.
var STREAK = {};          // nama tim -> daftar baris 100%
var streakKey = '';       // daftar tim yang terakhir diminta
var streakBusy = false;
var OVER_OUTS = ['o35', 'o45', 'o55', 'o65', 'o75'];
var STK_MAX_BADGE = 3;    // badge per tim, sisanya diringkas jadi "+n"

function streakStatus(d) {
  var el = document.getElementById('stk');
  if (!el) return;
  if (!d) { el.className = 'pill warn'; el.textContent = 'STREAK: memuat…'; return; }
  if (!d.ok) {
    el.className = 'pill bad';
    el.textContent = 'STREAK: tidak ada data';
    el.title = d.reason || 'Cache streak belum tersedia.';
    return;
  }
  var n = 0;
  for (var k in STREAK) n += STREAK[k].length;
  var basi = d.age_min > 180;   // cache > 3 jam
  el.className = 'pill ' + (n ? (basi ? 'warn' : 'ok') : 'bad');
  el.textContent = 'STREAK: ' + n + ' peluang 100%' + (basi ? ' (cache ' + d.age_min + ' mnt)' : '');
  el.title = 'Cache streak dibangun ' + (d.builtAt || '-') + ' (' + d.age_min + ' menit lalu). '
    + 'Buka index.php?page=streak untuk menyegarkan.';
}

function streakCell(home, away) {
  var out = [];
  [['H', home, ''], ['A', away, ' away']].forEach(function (sisi) {
    var daftar = STREAK[sisi[1]] || [];
    daftar.slice(0, STK_MAX_BADGE).forEach(function (r) {
      var judul = sisi[1] + ' — ' + r.outT + ' ' + r.over + '% (' + r.hits + '/' + r.samp + ')'
        + '\nmode: ' + r.mk + (r.exp ? ' [eksperimental]' : '')
        + '\nstreak berjalan: ' + r.cur + 'x'
        + '\nkeandalan (Wilson 95%): ' + (r.lb === null ? '-' : r.lb + '%')
        + '\n± baseline liga: ' + (r.lift === null ? '-' : (r.lift > 0 ? '+' : '') + r.lift + '%')
        + '\nmeleset: ' + r.miss + 'x';
      out.push('<span class="stk' + sisi[2] + '" title="' + esc(judul) + '">'
        + sisi[0] + ' ' + esc(r.outT.replace('Over ', 'O')) + ' ' + r.over + '%</span>');
    });
    if (daftar.length > STK_MAX_BADGE) {
      out.push('<span class="nosig" title="' + esc(sisi[1] + ': ' + (daftar.length - STK_MAX_BADGE)
        + ' peluang lain disembunyikan') + '">+' + (daftar.length - STK_MAX_BADGE) + '</span>');
    }
  });
  return out.length ? out.join(' ') : '<span class="nosig" title="Tidak ada peluang 100% market Over untuk kedua tim">–</span>';
}

function refreshStreak(matches) {
  var teams = [];
  (matches || []).forEach(function (m) {
    if (m.home) teams.push(m.home);
    if (m.away) teams.push(m.away);
  });
  teams = teams.filter(function (v, i, a) { return a.indexOf(v) === i; }).sort();
  var key = teams.join('|');
  // Tarik ulang hanya kalau daftar tim berubah — data streak bergerak lambat,
  // tak ada gunanya menarik ~100 KB tiap 2 detik.
  if (!key || key === streakKey || streakBusy) return;
  streakBusy = true;
  fetch('streak-100-api.php?teams=' + encodeURIComponent(key), { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      streakKey = key;
      STREAK = {};
      if (d.ok && window.Streak100) {
        var baris = window.Streak100.compute100(d.rows, {
          baseOut: d.baseOut, baseOutLg: d.baseOutLg, outKeys: OVER_OUTS,
        });
        // Satu market bisa lolos lewat beberapa mode streak; simpan yang
        // keandalannya paling tinggi saja supaya badge tidak menumpuk.
        var terbaik = {};
        baris.forEach(function (r) {
          var k = r.t + '|' + r.outK;
          if (!terbaik[k] || (r.lb || 0) > (terbaik[k].lb || 0)) terbaik[k] = r;
        });
        Object.keys(terbaik).map(function (k) { return terbaik[k]; })
          .sort(function (a, b) { return (b.lb || 0) - (a.lb || 0); })
          .forEach(function (r) { (STREAK[r.t] = STREAK[r.t] || []).push(r); });
      }
      streakStatus(d);
      streakBusy = false;
    })
    .catch(function () { STREAK = {}; streakKey = ''; streakBusy = false; streakStatus({ ok: false, reason: 'Gagal memanggil streak-100-api.php' }); });
}

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
  refreshStreak(m);
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
      var disabled = p.disabled ? ' <span class="noko">NONAKTIF</span>' : '';
      var t = '<b>' + esc(p.code) + '</b>' + disabled + ' — ' + esc(p.desc);
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
      '<br>SUPER aktif mulai menit ' + esc(d.signal_start_2h_other_minute == null ? 60 : d.signal_start_2h_other_minute) +
      '; HAH mulai menit ' + esc(d.signal_start_2h_other_minute == null ? 60 : d.signal_start_2h_other_minute) +
      '; P1-P12 mulai menit ' + esc(d.signal_start_2h_p_minute == null ? 65 : d.signal_start_2h_p_minute) +
      '; SUPER1/SUPER2/S-LOW/SUPER3/SUPER4 dinonaktifkan' +
      ' babak kedua. Semua sinyal hilang begitu ada gol di babak kedua.';
  }
  document.getElementById('cnt').textContent = m.length;
  var tb = document.getElementById('tb'), rows = '', lastLg = null;
  if (!m.length) {
    rows = '<tr><td colspan="16" class="empty">Tidak ada match terbaca.</td></tr>';
  } else {
    for (var i = 0; i < m.length; i++) {
      var r = m[i];
      if (r.league !== lastLg) {
        lastLg = r.league;
        rows += '<tr class="lg"><td colspan="16">' + esc(shortLeague(r.league)) + '</td></tr>';
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
        '<td>' + streakCell(r.home, r.away) + '</td>' +
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
