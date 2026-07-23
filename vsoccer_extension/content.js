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
  const q = (el, s) => el.querySelector(s);
  const qa = (el, s) => Array.from(el.querySelectorAll(s));
  const txt = (el) => (el ? el.innerText.trim() : '');

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
      line, over, under,
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
          st = { home: m.h, away: m.a, ms: {}, track };
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
        let ch = st.home, ca = st.away;
        const minuteStr = m.half + ' ' + Math.max(m.minute, 0) + "'";
        while (ch < m.h || ca < m.a) {
          // dahulukan sisi yang masih tertinggal dari target; default home
          let side;
          if (ch < m.h) { ch++; side = 'home'; } else { ca++; side = 'away'; }
          goals.push({
            league, home_team: m.home, away_team: m.away,
            minute: minuteStr, half: m.half, min_num: Math.max(m.minute, 0),
            side, score_after: ch + '-' + ca,
            ou_line: m.line, over_odd: m.over, under_odd: m.under, // odds saat gol
            home_score: String(m.h), away_score: String(m.a), timestamp: nowIso(),
          });
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
