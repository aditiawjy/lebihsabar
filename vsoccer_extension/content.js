/*
 * Content script — V-Soccer Goal Logger untuk situs 1x2 (DOM eventlist_asia_fe_*).
 *
 * Tiap beberapa detik:
 *  1. Buka accordion liga V-Soccer yang tertutup (agar event ter-render di DOM).
 *  2. Baca tiap event: liga, tim, babak, menit (jam sepak bola simulasi), skor.
 *  3. Deteksi GOL BARU (skor bertambah dibanding poll sebelumnya) -> kirim event gol
 *     berformat "1H 20' (1-0)" ke goal-log-save-vsoccer.php.
 *  4. Kirim milestone 1h3 / 2h1 / 2h7 saat match melewati titik itu (termasuk 0-0).
 *
 * Fetch tidak dilakukan di sini (mixed-content), tapi diteruskan ke background.js.
 */
(function () {
  'use strict';

  const POLL_MS = 2500;

  // Anti-throttle: Chrome memperlambat setInterval di tab background (~1x/menit),
  // bikin gol tertangkap menumpuk 1 poll (menit sama). Audio senyap membuat tab
  // dianggap "aktif" sehingga timer tetap 2.5s walau tab tak dilihat. Best-effort.
  let audioCtx = null;
  function keepAwake() {
    try {
      if (!audioCtx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        audioCtx = new AC();
        const osc = audioCtx.createOscillator();
        const g = audioCtx.createGain();
        g.gain.value = 0.0008; // sangat pelan, praktis tak terdengar
        osc.frequency.value = 40;
        osc.connect(g); g.connect(audioCtx.destination); osc.start();
      }
      if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    } catch (e) { /* abaikan */ }
  }
  // Hanya buat/resume AudioContext SETELAH gesture user (kebijakan autoplay browser).
  // Tidak dipanggil langsung saat load agar tak muncul warning "AudioContext was not allowed".
  ['click', 'keydown', 'pointerdown', 'touchstart'].forEach(ev =>
    document.addEventListener(ev, keepAwake, { passive: true }));

  const q = (el, s) => el.querySelector(s);
  const qa = (el, s) => Array.from(el.querySelectorAll(s));
  const txt = (el) => (el ? el.innerText.trim() : '');

  // Normalisasi odds ke DESIMAL (situs bisa tampilkan Indo/Malay/HK).
  // Desimal>=1 apa adanya; Indo<=-1 -> 1+1/|v|; HK 0<v<1 -> 1+v; Malay -1<v<0 -> 1+1/|v|.
  function toDecimal(v) {
    const f = parseFloat(String(v).trim());
    if (isNaN(f)) return '';
    let d;
    if (f >= 1.0) d = f;
    else if (f <= -1.0) d = 1 + 1 / Math.abs(f);
    else if (f > 0 && f < 1.0) d = 1 + f;
    else if (f > -1.0 && f < 0) d = 1 + 1 / Math.abs(f);
    else return '';
    return d.toFixed(2);
  }

  // state per match dalam satu siklus (12 menit). key = league|home|away
  // { home, away, ms:{'1h3','2h1','2h7'} }
  const state = new Map();

  function expandCollapsed() {
    let opened = 0;
    document.querySelectorAll('[class*="EventListLeague_container"]').forEach(c => {
      const name = txt(q(c, '[class*="leagueName"]'));
      if (!/mins \[V\]/i.test(name)) return;
      const arrow = q(c, '[class*="expandCollapseArrow"]');
      const isExpanded = arrow && /Expanded/.test(arrow.className);
      const hasEvents = c.querySelector('[class*="singleEvent"]');
      if (!isExpanded && !hasEvents) {
        (q(c, '[class*="expandCollapse"]') || q(c, '[class*="EventListLeague_header"]'))?.click();
        opened++;
      }
    });
    return opened;
  }

  function parseEvent(ev, league) {
    const teams = qa(ev, '[class*="teamNameText"]')
      .map(t => t.innerText.trim())
      .filter(n => n && n.toLowerCase() !== 'draw');
    if (teams.length < 2) return null;

    const scoreLive = txt(q(ev, '[class*="EventTime_scoreLive"]')); // "2 : 0"
    const part = txt(q(ev, '[class*="EventTime_gamePart"]'));        // "1H"/"2H"
    const prog = q(ev, '[class*="EventTime_gameProgress"]');
    let minute = -1;
    if (prog) { const m = prog.innerText.match(/(\d+)'/); if (m) minute = parseInt(m[1], 10); }

    const sm = scoreLive.match(/(\d+)\s*:\s*(\d+)/);
    if (!sm) return null; // belum live (masih jam pre-match)

    // Odds Over/Under (kolom kedua): "4.5/5" | over | "U" | under
    const oucell = q(ev, '[class*="secondMarket"]');
    let line = '', over = '', under = '';
    if (oucell) {
      qa(oucell, '[class*="singleMarket"]').forEach(c => {
        const label = txt(q(c, '[class*="singleLeftLive"], [class*="singleCell"]'));
        const odd = txt(q(c, '[class*="oddsArrowNumber"]')) || txt(q(c, '[class*="betCell"]'));
        if (/^\d/.test(label)) { line = label; over = odd; }
        else if (/^U/i.test(label)) { under = odd; }
      });
    }

    return {
      league, home: teams[0], away: teams[1],
      half: part.toUpperCase(), minute,
      h: parseInt(sm[1], 10), a: parseInt(sm[2], 10),
      line, over: toDecimal(over), under: toDecimal(under),
    };
  }

  function nowIso() { return new Date().toISOString(); }

  function collect() {
    const goals = [], matches = [], milestones = [];
    const headers = Array.from(document.querySelectorAll('[class*="leagueName"]'))
      .filter(h => /mins \[V\]/i.test(h.innerText));

    headers.forEach(h => {
      const league = h.innerText.replace(/\s*\(\d+\)\s*$/, '').trim();
      let n = h, cont = null;
      for (let d = 0; d < 8 && n; d++) { n = n.parentElement; if (n && n.querySelector('[class*="singleEvent"]')) { cont = n; break; } }
      if (!cont) return;

      qa(cont, '[class*="singleEvent"]').forEach(ev => {
        const m = parseEvent(ev, league);
        if (!m || !m.half) return;
        const key = league + '|' + m.home + '|' + m.away;
        let st = state.get(key);

        // siklus baru: skor turun dibanding sebelumnya -> match baru, reset state
        if (st && (m.h + m.a) < (st.home + st.away)) st = null;

        if (!st) {
          // Baru pertama kali terlihat. HANYA lacak bila benar-benar kickoff (1H, 0-0).
          // Kalau scraper gabung di tengah match (skor sudah jalan), menit gol yang
          // terlewat TIDAK diketahui -> jangan dikarang. Simpan baseline, tandai skip.
          const track = (m.half === '1H' && m.h === 0 && m.a === 0);
          st = { home: m.h, away: m.a, ms: {}, track, pendingMarkets: [] };
          state.set(key, st);
          if (track) matches.push({
            league, home_team: m.home, away_team: m.away,
            home_score: '0', away_score: '0',
            ko_line: m.line, ko_over: m.over, ko_under: m.under, // odds awal (kickoff)
            timestamp: nowIso(),
          });
          return; // tidak mencatat gol pada observasi pertama
        }

        // Match yang tidak dilacak (gabung di tengah): perbarui baseline saja, jangan log gol palsu.
        if (!st.track) { st.home = m.h; st.away = m.a; return; }

        // GOL BARU pada match yang dilacak dari kickoff: catat di menit yang teramati saat ini.
        const marketReady = !!(m.line && m.over && m.under);
        if (marketReady && st.pendingMarkets && st.pendingMarkets.length) {
          st.pendingMarkets.forEach(g => goals.push(Object.assign({}, g, {
            ou_line: m.line, over_odd: m.over, under_odd: m.under,
            home_score: String(m.h), away_score: String(m.a), timestamp: nowIso(), market_update: 1,
          })));
          st.pendingMarkets = [];
        }
        let ch = st.home, ca = st.away;
        const jump = (m.h - st.home) + (m.a - st.away);   // gol tertangkap dalam 1 poll
        const accurate = jump <= 2 ? 1 : 0;               // >=3 = ciri tab ke-throttle, menit tak andal
        const minuteStr = m.half + ' ' + Math.max(m.minute, 0) + "'";
        while (ch < m.h || ca < m.a) {
          // dahulukan sisi yang masih tertinggal dari target; default home
          let side;
          if (ch < m.h) { ch++; side = 'home'; } else { ca++; side = 'away'; }
          const goal = {
            league, home_team: m.home, away_team: m.away,
            minute: minuteStr, half: m.half, min_num: Math.max(m.minute, 0),
            side, score_after: ch + '-' + ca, accurate, // 1=menit andal, 0=diragukan (throttle)
            ou_line: m.line, over_odd: m.over, under_odd: m.under, // odds saat gol
            home_score: String(m.h), away_score: String(m.a), timestamp: nowIso(),
          };
          goals.push(goal);
          if (!marketReady) st.pendingMarkets.push(Object.assign({}, goal));
        }
        st.home = m.h; st.away = m.a;
      });
    });

    return { goals, matches, milestones };
  }

  // True bila konteks extension masih valid (belum di-reload/di-nonaktifkan).
  function ctxAlive() {
    try { return !!(chrome.runtime && chrome.runtime.id); } catch (e) { return false; }
  }

  let timer = null;
  function stop(reason) {
    if (timer) clearInterval(timer);
    timer = null;
    console.warn('[v-soccer] scraper berhenti:', reason, '— reload halaman ini untuk memulai lagi.');
  }

  function tick() {
    if (!ctxAlive()) { stop('extension context invalidated (extension di-reload)'); return; }
    if (expandCollapsed()) { setTimeout(run, 600); return; }
    run();
  }

  function run() {
    if (!ctxAlive()) { stop('extension context invalidated'); return; }
    const payload = collect();
    const total = payload.goals.length + payload.matches.length + payload.milestones.length;
    if (!total) return;
    try {
      chrome.runtime.sendMessage({ type: 'vsoccer', payload }, (res) => {
        if (chrome.runtime.lastError) { console.warn('[v-soccer] bg error:', chrome.runtime.lastError.message); return; }
        if (res && res.ok) console.log('[v-soccer] tersimpan:', payload.goals.length, 'gol,', payload.matches.length, 'match,', payload.milestones.length, 'ms ->', res.data);
        else console.warn('[v-soccer] gagal:', res && res.error);
      });
    } catch (e) {
      stop(e.message);
    }
  }

  timer = setInterval(tick, POLL_MS);
  tick();
  console.log('[v-soccer] Goal Logger aktif. Poll', POLL_MS + 'ms. Data -> goal_log_vsoccer.csv');
})();
