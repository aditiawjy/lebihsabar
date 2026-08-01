<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

/**
 * BPVM Live View
 * Menampilkan data live dari live-scraper (api_server.py, port 5000):
 * daftar match SABA yang sedang berjalan + skor HT + menit gol.
 * Pola mengikuti vsoccer-live.php, tapi sumber data = Flask API
 * (bukan file snapshot). Runner tetap dijalankan lewat
 * live-scraper/start_headless.bat; halaman ini hanya membaca.
 */

const LIVE_API_URL = 'http://127.0.0.1:5000/api/live-data';

// mode data: dipanggil oleh JS tiap beberapa detik
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents(LIVE_API_URL, false, $ctx);

    if ($raw === false) {
        echo json_encode(['status' => 'offline', 'note' => 'API server offline — jalankan live-scraper/start_headless.bat.', 'matches' => [], 'count' => 0]);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['status' => 'offline', 'note' => 'Respons API tidak terbaca.', 'matches' => [], 'count' => 0]);
        exit;
    }

    // age = detik sejak data diterima api_server dari extension (bukan waktu serve)
    $age = null;
    $ts = $data['timestamp'] ?? null;
    if ((!is_string($ts) || $ts === '') && !empty($data['served_at'])) {
        // fallback: payload belum pernah keisi (fresh boot) -> pakai waktu serve
        $ts = $data['served_at'];
        $data['timestamp'] = $ts;
    }
    if (is_string($ts) && $ts !== '') {
        $epoch = strtotime($ts);
        if ($epoch !== false) {
            $age = max(0, time() - $epoch);
        }
    }
    $data['age'] = $age;
    $data['status'] = 'running';
    $data['server_time'] = date('H:i:s');
    if (!isset($data['matches']) || !is_array($data['matches'])) {
        $data['matches'] = [];
    }
    $data['count'] = count($data['matches']);
    echo json_encode($data);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BPVM Live View</title>
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
  .odds { color:#8ecbff; font-size:12px; white-space:normal; max-width:340px; }
  .goal { color:#5ee39b; }
  .empty { color:#8b97a8; padding:14px 4px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>BPVM Live View</h1>
  <div class="bar">
    <span id="st" class="pill warn">memuat…</span>
    <span class="muted" id="meta"></span>
    <span class="muted" id="err"></span>
  </div>

  <h2>Match berjalan (<span id="cnt">0</span>)</h2>
  <table>
    <thead><tr>
      <th>Match</th><th>Status</th><th class="num">Skor</th>
      <th class="num">HT</th><th class="num">Gol 1H</th><th class="num">Gol 2H</th>
      <th>1X2 FT</th><th>HDP FT</th><th>O/U FT</th>
      <th>1X2 1H</th><th>HDP 1H</th><th>O/U 1H</th><th>Next Goal</th>
    </tr></thead>
    <tbody id="tb"><tr><td colspan="13" class="empty">Menunggu data…</td></tr></tbody>
  </table>

  <h2>Tabel tambahan</h2>
  <table>
    <thead id="extra-head"><tr><th>—</th></tr></thead>
    <tbody id="extra-tb"><tr><td class="empty">Struktur siap — isi menyusul.</td></tr></tbody>
  </table>
</div>

<script>
var esc = function (s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
  });
};

// Replika createMatchKey() di chrome_extension/lib/utils.js — kunci untuk
// htScores / allGoalMinutes / all2HGoalMinutes / kickoffTimes.
var matchKey = function (m) {
  var teams = m.teams || ((m.homeTeam || 'Unknown') + ' vs ' + (m.awayTeam || 'Unknown'));
  return JSON.stringify({ league: m.league || 'N/A', teams: teams });
};

var fmtMins = function (v) {
  if (!v) return '-';
  if (!Array.isArray(v)) v = [v];
  if (!v.length) return '-';
  return esc(v.map(function (x) {
    if (x && typeof x === 'object') x = (x.minute != null ? x.minute : (x.min != null ? x.min : ''));
    return x === '' || x == null ? '' : x + "'";
  }).filter(Boolean).join(', ')) || '-';
};

var pickOdds = function (odds, prefix) {
  if (!Array.isArray(odds) || !odds.length) return '-';
  for (var i = 0; i < odds.length; i++) {
    var s = String(odds[i]);
    if (s.indexOf(prefix + ':') === 0) return esc(s.substring(prefix.length + 2));
  }
  return '-';
};

var fmtNextGoal = function (ng) {
  if (!ng || typeof ng !== 'object') return '-';
  var parts = [];
  for (var k in ng) {
    if (Object.prototype.hasOwnProperty.call(ng, k) && ng[k] != null && ng[k] !== '') {
      parts.push(esc(k) + ' ' + esc(ng[k]));
    }
  }
  return parts.length ? parts.join(' · ') : '-';
};

function render(d) {
  var st = document.getElementById('st');
  var age = d.age == null ? 999 : d.age;
  var s = d.status || 'offline';
  var cls = 'bad', label = 'OFFLINE';
  if (s === 'running' && age <= 15) { cls = 'ok'; label = 'RUNNING'; }
  else if (s === 'running') { cls = 'warn'; label = age === 999 ? 'STALE' : 'STALE (' + age + 's)'; }
  st.className = 'pill ' + cls;
  st.textContent = label;

  var ts = d.timestamp ? String(d.timestamp).replace('T', ' ').substr(11, 8) : '-';
  document.getElementById('meta').textContent =
    'update ' + ts + ' (' + (d.age == null ? '?' : d.age) + 's lalu)' +
    ' · ' + (d.count || 0) + ' match' +
    (d.note ? ' · ' + d.note : '');
  document.getElementById('err').textContent = (s === 'offline' && d.note) ? '⚠ ' + d.note : '';

  var m = d.matches || [];
  document.getElementById('cnt').textContent = m.length;
  var tb = document.getElementById('tb'), rows = '', lastLg = null;
  if (!m.length) {
    rows = '<tr><td colspan="13" class="empty">' +
      (s === 'offline' ? 'API server offline — jalankan live-scraper/start_headless.bat.' : 'Tidak ada match terbaca.') +
      '</td></tr>';
  } else {
    var htScores = d.htScores || {}, g1 = d.allGoalMinutes || {}, g2 = d.all2HGoalMinutes || {};
    for (var i = 0; i < m.length; i++) {
      var r = m[i];
      if (r.league !== lastLg) {
        lastLg = r.league;
        rows += '<tr class="lg"><td colspan="13">' + esc(r.league || 'Unknown League') + '</td></tr>';
      }
      var key = matchKey(r);
      rows += '<tr>' +
        '<td>' + esc(r.homeTeam || '?') + ' vs ' + esc(r.awayTeam || '?') + '</td>' +
        '<td>' + esc(r.status || '-') + '</td>' +
        '<td class="num score">' + esc(r.score || '-') + '</td>' +
        '<td class="num goal">' + esc(htScores[key] || '-') + '</td>' +
        '<td class="num" title="menit gol babak pertama">' + fmtMins(g1[key]) + '</td>' +
        '<td class="num" title="menit gol babak kedua">' + fmtMins(g2[key]) + '</td>' +
        '<td class="odds">' + pickOdds(r.odds, 'FT. 1X2') + '</td>' +
        '<td class="odds">' + pickOdds(r.odds, 'FT. HDP') + '</td>' +
        '<td class="odds">' + pickOdds(r.odds, 'FT. O/U') + '</td>' +
        '<td class="odds">' + pickOdds(r.odds, '1H. 1X2') + '</td>' +
        '<td class="odds">' + pickOdds(r.odds, '1H. HDP') + '</td>' +
        '<td class="odds">' + pickOdds(r.odds, '1H. O/U') + '</td>' +
        '<td class="odds">' + fmtNextGoal(r.nextGoalOdds) + '</td>' +
        '</tr>';
    }
  }
  tb.innerHTML = rows;

  renderExtra(d);
}

// Tabel tambahan: isi menyusul. Struktur sudah terhubung ke data (d).
function renderExtra(d) {
  // var tb = document.getElementById('extra-tb');
  // tb.innerHTML = ...;
}

function tick() {
  fetch('bpvm-live.php?json=1&t=' + Date.now(), { cache: 'no-store' })
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
