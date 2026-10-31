// ==UserScript==
// @name         SABA FC24 League 16 Only | Master 41 Pola + Early Watch
// @namespace    local.liverpool.saba
// @version      1.33.0
// @description  Hanya League 16 FC24 (41 pola) yang berjalan dan tampil. League 15/20 nonaktif sementara; storage aman.
// @match        https://msports.m88m8idtop.com/app/v2/sports.html*
// @grant        GM_getValue
// @grant        GM_setValue
// @run-at       document-idle
// @noframes
// ==/UserScript==
(function () {
  'use strict';

  const LEAGUE = 'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play';
  const BELGIUM_LEAGUE = 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play';
  const ALGERIA_LEAGUE = 'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play';
  const KEY = 'liverpool-saba-results-v1';
  // Sakelar sementara: formula dan arsip League 15/20 tetap disimpan, tidak dijalankan.
  const ENABLE_LEAGUE_15 = false;
  const ENABLE_LEAGUE_20 = false;
  const ENABLE_LEAGUE_16 = true;
  const ENABLED_LEAGUE_NAMES = [
    ...(ENABLE_LEAGUE_15 ? [LEAGUE] : []),
    ...(ENABLE_LEAGUE_16 ? [BELGIUM_LEAGUE] : []),
    ...(ENABLE_LEAGUE_20 ? [ALGERIA_LEAGUE] : []),
  ];

  // Daftar pola yang dinonaktifkan (Data lama tetap aman di storage)
  const DISABLED_L15 = new Set(['awayHighHT', 'chelseaAwayHT', 'tottenhamAwayHT', 'tottenhamAwayPrevNoTeam2H']);
  const DISABLED_L20 = new Set(['l20NetherlandsHomeHT', 'l20ColombiaHome2H', 'l20MexicoHome2H']);
  const DISABLED_L16_EXTRA = new Set(['Italy', 'Portugal']);
  const ENABLE_L16_NETHERLANDS = false;

  const FRESH_MS = 2 * 60 * 1000;
  const GRACE_MS = 5 * 60 * 1000;
  let historyRecords = [];
  let lastRenderHash = '';
  let lastScanTimeStr = '--:--:--';

  // State UI Terpusat
  let activeTab = 'l16'; // 'l16' | 'legacy'; League 15/20 disembunyikan sementara
  let isMinimized = false;
  let l15MonitorFilter = 'priority';
  let l20MonitorFilter = 'all';
  let l16CodeSearch = '';
  let l16GlossaryInitialized = false;
  const openDetailsSet = new Set();
  const nestedScrollMap = new Map();

  // Injeksikan CSS Modern untuk Panel UI
  const styleEl = document.createElement('style');
  styleEl.textContent = `
    #saba-monitor-box {
      position: fixed; right: 14px; bottom: 14px; z-index: 2147483647;
      width: 420px; max-width: calc(100vw - 28px); max-height: 78vh;
      display: flex; flex-direction: column;
      background: #0f172a; color: #f8fafc;
      border: 1px solid #38bdf8; border-radius: 12px;
      font: 12px/1.45 system-ui, -apple-system, sans-serif;
      box-shadow: 0 10px 30px rgba(0,0,0,0.65); overflow: hidden;
      transition: height 0.2s ease, width 0.2s ease;
    }
    #saba-monitor-box.minimized { width: 260px; max-height: 44px; }
    .saba-hdr {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px; background: #1e293b; border-bottom: 1px solid #334155;
      user-select: none;
    }
    .saba-hdr-title { font-weight: 800; font-size: 13px; color: #38bdf8; display: flex; align-items: center; gap: 6px; }
    .saba-hdr-actions { display: flex; gap: 6px; align-items: center; }
    .saba-btn {
      background: #334155; color: #e2e8f0; border: 1px solid #475569;
      border-radius: 6px; padding: 4px 8px; font-size: 11px; font-weight: 600;
      cursor: pointer; transition: background 0.15s;
    }
    .saba-btn:hover { background: #475569; }
    .saba-btn.active { background: #0284c7; border-color: #38bdf8; color: #fff; }
    .saba-tabs {
      display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
      background: #1e293b; padding: 0 10px 8px; gap: 4px; border-bottom: 1px solid #334155;
    }
    .saba-tab {
      background: #0f172a; color: #94a3b8; border: 1px solid #334155;
      border-radius: 6px; padding: 5px 4px; font-size: 11px; font-weight: 700;
      text-align: center; cursor: pointer;
    }
    .saba-tab.active { background: #0d9488; color: #fff; border-color: #2dd4bf; }
    .saba-body {
      padding: 10px 12px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 8px;
    }
    .saba-body::-webkit-scrollbar, .saba-scroll-list::-webkit-scrollbar { width: 5px; }
    .saba-body::-webkit-scrollbar-thumb, .saba-scroll-list::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
    .saba-card {
      background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 9px;
    }
    .saba-stat-bar {
      background: #0f172a; border: 1px solid #1e293b; border-radius: 6px;
      padding: 7px 9px; font-size: 11px; color: #cbd5e1; margin: 5px 0;
    }
    .saba-details {
      border-bottom: 1px solid #334155; padding: 6px 0;
    }
    .saba-details:last-child { border-bottom: none; }
    .saba-details > summary {
      cursor: pointer; font-size: 12px; display: flex; justify-content: space-between;
      align-items: center; list-style: none; font-weight: 700;
    }
    .saba-details > summary::-webkit-details-marker { display: none; }
    .saba-badge {
      display: inline-block; padding: 2px 6px; border-radius: 4px;
      font-size: 10px; font-weight: 700;
    }
    .saba-badge.ok { background: #065f46; color: #6ee7b7; }
    .saba-badge.fail { background: #7f1d1d; color: #fca5a5; }
    .saba-badge.wait { background: #78350f; color: #fcd34d; }
    .saba-scroll-list { max-height: 190px; overflow-y: auto; margin-top: 6px; padding-right: 4px; }
  `;
  document.head.append(styleEl);

  const recordLeague = r => r.league || LEAGUE;
  const duration = r => (recordLeague(r) === ALGERIA_LEAGUE ? 20 : recordLeague(r) === BELGIUM_LEAGUE ? 16 : 15) * 60 * 1000;

  function usablePending(r, now = Date.now()) {
    return !r.complete && Number.isFinite(r.lastSeen) && now - r.lastSeen <= FRESH_MS &&
      !/CANCEL|POSTPON|ABANDON|VOID/i.test(r.status || '') && now <= r.t + duration(r) + GRACE_MS;
  }

  function selectTarget(matches, now = Date.now()) {
    const ordered = [...matches].sort((a, b) => a.t - b.t);
    const pending = ordered.filter(r => usablePending(r, now));
    return pending.filter(r => r.t <= now).at(-1) || pending.find(r => r.t > now) ||
      ordered.filter(r => r.complete).at(-1);
  }

  function checkedHistory(previous, target) {
    return !!previous && recordLeague(previous) === recordLeague(target) && previous.t < target.t;
  }

  const norm = s => s.replace(/\s+/g, ' ').trim();
  const score = s => { const m = norm(s).match(/^(\d+)\s*[-:]\s*(\d+)$/); return m ? [+m[1], +m[2]] : null; };
  const extraTeam = s => norm(s).replace(/^(?:e-)/i, '').replace(/\s*\(V\)$/i, '').toLowerCase();

  const isLiverpool = s => /^Liverpool(?:\s*\(V\))?$/i.test(norm(s));
  const isEverton = s => /^Everton(?:\s*\(V\))?$/i.test(norm(s));
  const isPSG = s => /^(?:PSG|Paris Saint Germain)(?:\s*\(V\))?$/i.test(norm(s.replace(/-/g, ' ')));
  const isSociedad = s => /^Real Sociedad(?:\s*\(V\))?$/i.test(norm(s));
  const isBelgium = s => /^(?:e-)?Belgium(?:\s*\(V\))?$/i.test(norm(s));
  const isNetherlands = s => /^(?:e-)?Netherlands(?:\s*\(V\))?$/i.test(norm(s));
  const isNorway = s => /^(?:e-)?Norway(?:\s*\(V\))?$/i.test(norm(s));
  const isHungary = s => /^(?:e-)?Hungary(?:\s*\(V\))?$/i.test(norm(s));

  const EXTRA_RULES = [
    { name: 'Ukraina', team: 'Ukraine', trigger: 'highFT', ht: [1] },
    { name: 'Portugal', team: 'Portugal', trigger: 'quiet2H', ht: null },
    { name: 'Meksiko', team: 'Mexico', trigger: 'lowFT', ht: [1, 2] },
    { name: 'Italia', team: 'Italy', trigger: 'quiet2H', ht: null }
  ].filter(rule => !DISABLED_L16_EXTRA.has(rule.team));

  function validFinalScore(r) {
    return !!(r && r.complete && Array.isArray(r.fh) && Array.isArray(r.ft) &&
      r.fh.length === 2 && r.ft.length === 2 &&
      r.fh.every((g, i) => Number.isInteger(g) && g >= 0 && Number.isInteger(r.ft[i]) && r.ft[i] >= g));
  }

  function latestBefore(items, t) {
    let low = 0, high = items.length;
    while (low < high) {
      const mid = (low + high) >>> 1;
      if (items[mid].t < t) low = mid + 1;
      else high = mid;
    }
    return low > 0 ? items[low - 1] : null;
  }

  function extraEvaluation(records, target, rule) {
    const hasTeam = r => r.teams?.some(t => extraTeam(t) === rule.team.toLowerCase());
    const previous = records.filter(r => hasTeam(r) && r.t < target.t).sort((a, b) => b.t - a.t)[0];
    if (!previous || !previous.complete || !previous.ft || (rule.trigger === 'quiet2H' && !previous.fh)) return { active: false, previous, reason: 'hasil laga terakhir belum cukup' };
    if (!checkedHistory(previous, target)) return { active: false, previous, reason: 'DATA RIWAYAT BELUM CUKUP', result: null, goals: null };
    const total = previous.ft[0] + previous.ft[1];
    const trigger = rule.trigger === 'highFT' ? total >= 4 : rule.trigger === 'lowFT' ? total <= 1 : previous.fh[0] === previous.ft[0] && previous.fh[1] === previous.ft[1];
    if (!trigger) return { active: false, previous, reason: 'hasil laga terakhir tidak memenuhi pemicu' };
    if (rule.ht && !target.fh) return { active: false, previous, reason: 'pemicu terpenuhi; menunggu skor HT sekarang' };
    if (rule.ht && !rule.ht.includes(target.fh[0] + target.fh[1])) return { active: false, previous, reason: 'total HT sekarang tidak memenuhi syarat' };
    const goals = target.complete && target.fh && target.ft ? target.ft[0] + target.ft[1] - target.fh[0] - target.fh[1] : null;
    return { active: true, previous, goals };
  }

  const LIVERPOOL_RULES = [
    { id: 'homeMatchQuiet2H', name: 'LIVERPOOL — POLA LAMA', description: 'Kandang • HT = FT di kandang sebelumnya', homeOnly: true },
    { id: 'teamQuiet2H', name: 'LIVERPOOL — POLA BARU', description: 'Venue sama • Liverpool tidak mencetak gol 2H', homeOnly: false }
  ];

  function liverpoolEvaluation(records, target, rule) {
    if (![0, 1].includes(target.side) || (rule.homeOnly && target.side !== 0))
      return { active: false, reason: 'venue tidak sesuai aturan', previous: null, result: null };
    const previous = records.filter(r => r.side === target.side && r.t < target.t).sort((a, b) => b.t - a.t)[0];
    if (!validFinalScore(previous))
      return { active: false, previous, reason: 'data HT/FT laga sebelumnya pada venue yang sama belum cukup atau tidak valid', result: null };
    if (!checkedHistory(previous, target)) return { active: false, previous, reason: 'DATA RIWAYAT BELUM CUKUP', result: null, goals: null };
    const teamGoals = previous.ft[target.side] - previous.fh[target.side];
    const matchGoals = previous.ft[0] + previous.ft[1] - previous.fh[0] - previous.fh[1];
    const active = rule.id === 'homeMatchQuiet2H' ? matchGoals === 0 : teamGoals === 0;
    const result = active && validFinalScore(target) ? (target.ft[0] + target.ft[1] >= 2 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null;
    return { active, previous, teamGoals, matchGoals, result, reason: active ? 'pemicu terpenuhi' : 'pemicu tidak terpenuhi' };
  }

  function hungaryEvaluation(records, target) {
    const previous = records.filter(r => r.hungarySide >= 0 && r.t < target.t).sort((a, b) => b.t - a.t)[0];
    if (!previous || !previous.complete || !previous.ft) return { active: false, previous, reason: 'hasil FT laga Hungaria terakhir belum cukup' };
    if (!checkedHistory(previous, target)) return { active: false, previous, reason: 'DATA RIWAYAT BELUM CUKUP', result: null, goals: null };
    if (previous.ft[0] + previous.ft[1] < 4) return { active: false, previous, reason: 'total FT laga Hungaria terakhir kurang dari 4' };
    if (!target.fh) return { active: false, previous, reason: 'pemicu terpenuhi; menunggu skor HT laga sekarang' };
    const ht = target.fh[0] + target.fh[1];
    if (ht !== 0 && ht !== 1) return { active: false, previous, reason: 'total HT laga sekarang bukan 0 atau 1' };
    const goals = target.complete && target.ft ? target.ft[0] + target.ft[1] - ht : null;
    return { active: true, previous, reason: 'pemicu dan HT terpenuhi', goals };
  }

  // Kandidat eksplorasi 3 minggu; status UJI, belum validasi prospektif.
  const L16_DISCOVERED_RULES = [
  {
    "id": "L16-40",
    "n": 40,
    "name": "UJI • norway semua + away prev own 2H ≤1 + slot sebelumnya total 2H ≥4",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "norway semua + away prev own 2H ≤1 + slot sebelumnya total 2H ≥4"
  },
  {
    "id": "L16-41",
    "n": 41,
    "name": "UJI • morocco semua + away prev own HT ≥2",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "morocco semua + away prev own HT ≥2"
  },
  {
    "id": "L16-43",
    "n": 43,
    "name": "UJI • portugal semua + away prev own HT ≥1 + HT 0–1",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "portugal semua + away prev own HT ≥1 + HT 0–1"
  },
  {
    "id": "L16-45",
    "n": 45,
    "name": "UJI • romania kandang + home prev FT ≤3 + slot sebelumnya total 2H ≥3",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "romania kandang + home prev FT ≤3 + slot sebelumnya total 2H ≥3"
  },
  {
    "id": "L16-47",
    "n": 47,
    "name": "UJI • portugal tandang + home prev HT ≤1 + HT 0–1",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "portugal tandang + home prev HT ≤1 + HT 0–1"
  },
  {
    "id": "L16-48",
    "n": 48,
    "name": "UJI • norway semua + away prev 2H ≤1 + slot sebelumnya total 2H ≥3",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "norway semua + away prev 2H ≤1 + slot sebelumnya total 2H ≥3"
  },
  {
    "id": "L16-49",
    "n": 49,
    "name": "UJI • new zealand semua + away prev own HT ≤1 + slot sebelumnya total HT ≥3",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "new zealand semua + away prev own HT ≤1 + slot sebelumnya total HT ≥3"
  },
  {
    "id": "L16-50",
    "n": 50,
    "name": "UJI • germany semua + home same venue FT ≤2 + slot sebelumnya total HT ≥3",
    "market": "2H Over 0.5",
    "experimental": true,
    "condition": "germany semua + home same venue FT ≤2 + slot sebelumnya total HT ≥3"
  },
  {
    "id": "L16-51",
    "n": 51,
    "name": "UJI • Norway + Home Previous HT ≥2 (jeda ≥30m) + Own 2H ≤2 + Away Previous FT ≥2",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "norway semua + home prev 30m HT ≥2 + home prev own 2H ≤2 + away prev FT ≥2"
  },
  {
    "id": "L16-52",
    "n": 52,
    "name": "UJI • Ghana Semua + Away Same Venue 2H ≤2 + Home Previous FT ≥2 (jeda ≥30m)",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "ghana semua + away same venue 2H ≤2 + home prev 30m FT ≥2"
  },
  {
    "id": "L16-53",
    "n": 53,
    "name": "UJI • Ghana Semua + Away Same Venue FT ≤4 + Home Previous FT ≥2 (jeda ≥30m)",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "ghana semua + away same venue FT ≤4 + home prev 30m FT ≥2"
  },
  {
    "id": "L16-54",
    "n": 54,
    "name": "UJI • morocco semua + away same venue FT ≤4 + slot 30 menit sebelumnya total HT ≥3",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "morocco semua + away same venue FT ≤4 + slot 30 menit sebelumnya total HT ≥3"
  },
  {
    "id": "L16-55",
    "n": 55,
    "name": "UJI • Argentina Semua + Away Same Venue Own HT ≤0 + Home Prev FT ≤3 + Away Prev 2H ≤4 (jeda ≥30m)",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "argentina semua + away same venue own HT ≤0 + home prev 30m FT ≤3 + away prev 30m 2H ≤4"
  },
  {
    "id": "L16-56",
    "n": 56,
    "name": "UJI • ukraine semua + home prev FT ≥3 + slot 30 menit sebelumnya total HT ≥2",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "ukraine semua + home prev FT ≥3 + slot 30 menit sebelumnya total HT ≥2"
  },
  {
    "id": "L16-57",
    "n": 57,
    "name": "UJI • USA Kandang + Away Previous FT ≤3 / Own 2H ≤1 + Previous HT ≤1 dan 2H ≥1 (jeda ≥30m)",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "usa kandang + away prev FT ≤3 + away prev own 2H ≤1 + away prev 30m HT ≤1 + away prev 30m 2H ≥1"
  },
  {
    "id": "L16-58",
    "n": 58,
    "name": "UJI • argentina semua + away same venue own HT ≤0 + slot 30 menit sebelumnya total HT ≥1",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "argentina semua + away same venue own HT ≤0 + slot 30 menit sebelumnya total HT ≥1"
  },
  {
    "id": "L16-60",
    "n": 60,
    "name": "UJI • Spain + Away Previous Own 2H 0 + Home Previous FT ≥3",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "spain semua + away prev own 2H ≤0 + home prev FT ≥3"
  },
  {
    "id": "L16-61",
    "n": 61,
    "name": "UJI • morocco semua + home prev own 2H ≤0 + slot 30 menit sebelumnya total HT ≥3",
    "market": "HT Over 0.5",
    "experimental": true,
    "condition": "morocco semua + home prev own 2H ≤0 + slot 30 menit sebelumnya total HT ≥3"
  }
];

  // ===== MASTER RESMI LEAGUE 16: 41 POLA =====
  // Ini daftar tunggal untuk badge, rekap, dan log. Tidak memakai daftar 25 pola lama.
  // ID dibekukan; ID 09/17/18/19/21/23 tidak aktif.
  const L16_ACTIVE_RULES = [
    { id:'L16-01', n:1, name:"Norway Kandang + Lawan Previous 2H ≤3", market:"FT Over 1.5" },
    { id:'L16-02', n:2, name:"Norway Recovery Kandang — pertandingan Norway sebelumnya di kandang, tanpa gol 2H", market:"2H Over 0.5", win:13, fail:0 },
    { id:'L16-03', n:3, name:"Hungary Previous FT ≥4 & HT ≤1", market:"2H Over 0.5", win:41, fail:4 },
    { id:'L16-04', n:4, name:"Ukraine Previous FT ≥4 & HT 1", market:"2H Over 0.5", win:28, fail:1 },
    { id:'L16-07', n:7, name:"Spain Previous Signal WIN — HT 2–3, sebelumnya ada gol 2H", market:"2H Over 0.5", win:42, fail:2 },
    { id:'L16-08', n:8, name:"Italy Tandang Previous FT ≥3 + Previous 2H ≤2 (≥30m) + HT Tidak Imbang", market:"2H Over 0.5" },
    { id:'L16-11', n:11, name:"Mexico Sebelumnya Kandang + HT Tidak Imbang", market:"2H Over 0.5" },
    { id:'L16-12', n:12, name:"Norway Kandang HT 2 Gol", market:"2H Over 0.5", win:52, fail:4 },
    { id:'L16-14', n:14, name:"Hungary HT 2 + Sebelumnya Kandang", market:"2H Over 0.5", win:24, fail:3 },
    { id:'L16-16', n:16, name:"Belgium Kandang HT ≥2 + Home Previous 2H ≤3 (≥30m)", market:"2H Over 0.5" },
    { id:'L16-27', n:27, name:"Previous Session FT ≥5 + HT ≥3, Slot :30 + Home Previous 2H ≤1 (≥30m)", market:"2H Over 0.5" },
    { id:'L16-30', n:30, name:"Previous Session 2H 3–5 + HT Tepat 3 + Away Previous Tandang 2H ≤3", market:"2H Over 0.5" },
    { id:'L16-31', n:31, name:"Previous Session Both HT 0–0 — minimal satu gol 2H dalam dua laga berikutnya", market:"2H Over 0.5", win:66, fail:7 },
    { id:'L16-32', n:32, name:"USA HT ≥1 + Home Sebelumnya Tandang + Home Prev HT ≤1 + Away Prev 2H ≤2 (≥30m)", market:"2H Over 0.5" },
    { id:'L16-33', n:33, name:"Ukraine HT Ganjil + Previous Session 2H ≥2", market:"2H Over 0.5", win:29, fail:5 },
    { id:'L16-34', n:34, name:"Norway Kandang + Lawan Previous HT ≥1 + HT ≤2 + Home Previous FT ≤4 (≥30m)", market:"2H Over 0.5" },
    { id:'L16-35', n:35, name:"France Previous Session FT ≥5 Gol + HT ≤1", market:"2H Over 0.5" },
    { id:'L16-36', n:36, name:"France + Tim Tandang Previous Gol 2H ≥1 + HT Imbang", market:"2H Over 0.5" },
    { id:'L16-37', n:37, name:"Netherlands HT ≥1 + Home Previous 2H ≥2 / FT ≥3 + Away Previous 2H ≤2", market:"2H Over 0.5" },
    { id:'L16-38', n:38, name:"Argentina HT ≥1 + Home Previous HT ≥2 / Own 2H ≤1 + Previous Slot FT ≥5", market:"2H Over 0.5" },
    { id:'L16-39', n:39, name:"France Previous Session HT ≥2 + Home Prev 2H ≥2 + Away Prev 2H ≤3 (≥30m)", market:"2H Over 0.5" },
  ];
  L16_ACTIVE_RULES.push(...L16_DISCOVERED_RULES);
  const L16_DELETED_IDS = new Set(['L16-05','L16-06','L16-09','L16-10','L16-13','L16-15','L16-17','L16-18','L16-19','L16-20','L16-21','L16-22','L16-23','L16-42','L16-44','L16-46','L16-59','L16-62']);
  if (L16_ACTIVE_RULES.length !== 39 || L16_ACTIVE_RULES.some(r => L16_DELETED_IDS.has(r.id)))
    throw new Error('Konfigurasi master League 16 tidak konsisten: harus 39 pola.');
  const L16_RULE_BY_NUMBER = new Map(L16_ACTIVE_RULES.map(x => [x.n, x]));
  // Urutan TUNGGAL untuk RANKING MASTER dan REKAP HARI INI.
  // Jangan urutkan menggunakan ID, nomor pola, maupun jumlah WIN.
  const l16Accuracy = r => Number.isFinite(r.win) && r.win + r.fail > 0 ? r.win / (r.win + r.fail) : -1;
  const l16RankCompare = (a,b) => l16Accuracy(b) - l16Accuracy(a) ||
    ((b.win || 0)+(b.fail || 0)) - ((a.win || 0)+(a.fail || 0)) || String(a.id).localeCompare(String(b.id));
  const L16_REFERENCE_GHANA = {
    id:'L16-21', name:'Ghana Kandang Mencetak Gol HT',
    win:45, fail:12, reference:true
  };
  const L16_RANKED_RULES = [...L16_ACTIVE_RULES].sort(l16RankCompare);
  const L16_RANKED_ALL = [...L16_RANKED_RULES, L16_REFERENCE_GHANA].sort(l16RankCompare);
  const L16_RANK_BY_ID = new Map(L16_RANKED_ALL.map((r,i)=>[r.id, i+1]));
  const L16_PREVIOUS_MAX_MS = 3 * 60 * 60 * 1000;
  // WATCH adalah pemberitahuan awal, bukan sinyal resmi atau bagian statistik.
  const earlyWatchNotified = new Set();
  const L16_SLOT_MS = 15 * 60 * 1000;
  // Satu "hari sesi" = 12:00 s.d. 00:45 hari berikutnya, mengikuti arsip pengguna.
  const l16SessionDay = ts => new Date(ts + 8 * 3600000 - 12 * 3600000).toISOString().slice(0,10);
  const l16CalendarDate = ts => new Date(ts + 8 * 3600000).toISOString().slice(0,10);
  const l16Key = team => extraTeam(team || '');
  const l16HT = r => {
    if (!r?.complete) {
      if (/UPCOMING|SCHEDULED|NOT.?STARTED|PRE.?MATCH/i.test(r?.status || '')) return null;
      // Jangan menyebut skor FH parsial saat kickoff sebagai skor halftime final.
      const hasHTStatus = /HALF.?TIME|SECOND.?HALF|2ND|\bHT\b|\b2H\b/i.test(r?.status || '');
      if (!hasHTStatus && Number.isFinite(r?.t) && Date.now() < r.t + 7*60000) return null;
    }
    return Array.isArray(r?.fh) && r.fh.length === 2 && r.fh.every(Number.isInteger) ? r.fh[0]+r.fh[1] : null;
  };
  const l16FT = r => validFinalScore(r) ? r.ft[0]+r.ft[1] : null;
  const l16H2 = r => validFinalScore(r) ? r.ft[0]+r.ft[1]-r.fh[0]-r.fh[1] : null;
  const l16PreviousTeamGoal2H = (p, team) => {
    if (!validFinalScore(p)) return null;
    const side = p.teams?.findIndex(x => l16Key(x) === team) ?? -1;
    return side >= 0 ? p.ft[side]-p.fh[side] : null;
  };

  // Kalkulasi kronologis tunggal: previous team dan slot sebelumnya hanya membaca
  // pertandingan yang waktunya lebih kecil dan skornya sudah selesai.
  function l16DiscoveredEvents(games, byTime) {
    const index = new Map();
    for (const r of games) {
      if (!validFinalScore(r)) continue;
      for (const side of [0, 1]) {
        const name = l16Key(r.teams[side]);
        if (!index.has(name)) index.set(name, { any: [], home: [], away: [] });
        index.get(name).any.push(r);
        index.get(name)[side === 0 ? 'home' : 'away'].push(r);
      }
    }
    const value = (r, metric, side) => metric === 'HT' ? l16HT(r) : metric === 'FT' ? l16FT(r) :
      metric === '2H' ? l16H2(r) : metric === 'own HT' ? r.fh[side] : r.ft[side] - r.fh[side];
    function matches(r, text, isHT) {
      const fh = r.fh;
      if (text.startsWith('HT ') || text.includes('unggul HT')) {
        if (!Array.isArray(fh) || fh.length !== 2 || !fh.every(Number.isInteger)) return false;
        const [a,b] = fh, ht = a+b;
        if (text === 'HT tidak imbang') return a !== b;
        if (text === 'HT imbang') return a === b;
        if (text === 'HT kedua tim cetak') return a>0 && b>0;
        if (text === 'home unggul HT') return a>b;
        if (text === 'away unggul HT') return a<b;
        const range = text.match(/^HT (\d)–(\d)$/);
        if (range) return ht >= +range[1] && ht <= +range[2];
        const exact = text.match(/^HT tepat (\d)$/);
        if (exact) return ht === +exact[1];
      }
      const slot = text.match(/^slot (30 menit )?sebelumnya total (HT|FT|2H) ≥(\d)$/);
      if (slot) {
        const prev = byTime.get(r.t - (slot[1] ? 30 : 15) * 60000) || [];
        return prev.length === 2 && prev.every(validFinalScore) &&
          prev.reduce((sum,p) => sum + value(p,slot[2]),0) >= +slot[3];
      }
      const prev = text.match(/^(home|away) (prev|same venue) (?:((?:30)m) )?(own HT|own 2H|HT|FT|2H) (≥|≤)(\d)$/);
      if (!prev) return false;
      const side = prev[1] === 'home' ? 0 : 1;
      const name = l16Key(r.teams[side]);
      const bucket = index.get(name);
      const pool = bucket?.[prev[2] === 'prev' ? 'any' : side === 0 ? 'home' : 'away'] || [];
      const p = latestBefore(pool, r.t);
      if (!p || r.t-p.t > L16_PREVIOUS_MAX_MS || (isHT && r.t-p.t < 16 * 60000) || (prev[3] && r.t-p.t < 30*60000)) return false;
      const ownSide = p.teams.findIndex(t => l16Key(t) === name);
      const actual = value(p,prev[4],ownSide);
      return prev[5] === '≥' ? actual >= +prev[6] : actual <= +prev[6];
    }
    const events = [];
    for (const rule of L16_DISCOVERED_RULES) {
      const [base,...conditions] = rule.condition.split(' + ');
      const cut = base.lastIndexOf(' '), name = base.slice(0,cut), venue = base.slice(cut+1);
      const isHT = rule.market.startsWith('HT');
      const line = Number(rule.market.split(' ').at(-1));
      for (const r of games) {
        const teamNames = r.teams.map(l16Key);
        if (!teamNames.includes(name) || (venue !== 'semua' && teamNames[venue === 'kandang' ? 0 : 1] !== name)) continue;
        if (!conditions.every(text => matches(r,text,isHT))) continue;
        let result = null;
        // HT results settle after completion; 2H requires valid HT and FT.
        if (isHT && r.complete && Array.isArray(r.fh) && r.fh.length === 2 && r.fh.every(x => Number.isInteger(x) && x>=0))
          result = l16HT(r) > line ? 'BERHASIL' : 'TIDAK TERPENUHI';
        else if (!isHT && validFinalScore(r)) result = l16H2(r) > line ? 'BERHASIL' : 'TIDAK TERPENUHI';
        events.push({ rule, r, check: { active: true, result, info: 'Kandidat UJI dari eksplorasi historis; ' + rule.condition } });
      }
    }
    return events;
  }

  function l16BuildEvents(records) {
    const games = records.filter(r => recordLeague(r) === BELGIUM_LEAGUE &&
      Array.isArray(r.teams) && r.teams.length === 2 && Number.isFinite(r.t))
      .sort((a,b) => a.t-b.t || String(a.id).localeCompare(String(b.id)));
    const byTime = new Map();
    for (const r of games) {
      if (!byTime.has(r.t)) byTime.set(r.t, []);
      byTime.get(r.t).push(r);
    }
    const previousTeams = new Map();
    const previousAwayTeams = new Map();
    const events=[];
    let lastSpainBaseWin = null;
    let currentSession = null;
    const put = (n,r,active,extra={}) => {
      if (!active) return;
      const rule = L16_RULE_BY_NUMBER.get(n);
      if (!rule) return;
      const goals=l16H2(r);
      const result = validFinalScore(r)
        ? (n===1 ? (l16FT(r)>=2 ? 'BERHASIL':'TIDAK TERPENUHI') : (goals>=1 ? 'BERHASIL':'TIDAK TERPENUHI'))
        : null;
      events.push({rule,r,check:{active:true,goals,result,...extra}});
    };
    for (const [t, group] of byTime) {
      const session=l16SessionDay(t);
      if (session !== currentSession) { currentSession=session; lastSpainBaseWin=null; }
      const prevSlot=byTime.get(t-L16_SLOT_MS) || [];
      const prevSlotReady = prevSlot.length===2 && prevSlot.every(validFinalScore);
      const prevSlotFT=prevSlotReady ? prevSlot.reduce((s,x)=>s+l16FT(x),0):null;
      const prevSlotHT=prevSlotReady ? prevSlot.reduce((s,x)=>s+l16HT(x),0):null;
      const prevSlot2H=prevSlotReady ? prevSlot.reduce((s,x)=>s+l16H2(x),0):null;
      const getPrevious = team => {
        const p=previousTeams.get(team);
        return p && p.t < t && t-p.t <= L16_PREVIOUS_MAX_MS ? p : null;
      };
      const baseSpainCandidates=[];
      for (const r of group) {
        const home=l16Key(r.teams[0]),away=l16Key(r.teams[1]);
        const ht=l16HT(r),h1=ht===null?null:r.fh[0],h2=ht===null?null:r.fh[1];
        const ph=getPrevious(home), pa=getPrevious(away);
        const awayHistory=previousAwayTeams.get(away);
        const paAway=awayHistory && awayHistory.t<t && t-awayHistory.t<=L16_PREVIOUS_MAX_MS ? awayHistory : null;
        const team=tname=>home===tname||away===tname;
        const pt=tname=>getPrevious(tname);
        const prevSpain=pt('spain'),prevHungary=pt('hungary'),prevMexico=pt('mexico'),prevUkraine=pt('ukraine'),prevGhana=pt('ghana');
        const prevHT=p=>p&&validFinalScore(p)?l16HT(p):null;
        const prevFT=p=>p&&validFinalScore(p)?l16FT(p):null;
        const prev2H=p=>p&&validFinalScore(p)?l16H2(p):null;
        const ready30=p=>p && t-p.t>=30*60000;
        const ownHungaryPrev2H=l16PreviousTeamGoal2H(prevHungary,'hungary');
        put(1,r,home==='norway' && pa && t-pa.t>=16*60000 && prev2H(pa)!==null && prev2H(pa)<=3);
        put(2,r,home==='norway' && ph && l16Key(ph.teams[0])==='norway' && prev2H(ph)===0);
        put(3,r,team('hungary') && prevFT(prevHungary)!==null && prevFT(prevHungary)>=4 && ht!==null && ht<=1);
        put(4,r,team('ukraine') && prevFT(prevUkraine)!==null && prevFT(prevUkraine)>=4 && ht===1);
        put(5,r,team('mexico') && prevFT(prevMexico)!==null && prevFT(prevMexico)<=1 && (ht===1||ht===2));
        const spanishBasic=team('spain') && prev2H(prevSpain)!==null && prev2H(prevSpain)>=1 && (ht===2||ht===3);
        put(7,r,spanishBasic && lastSpainBaseWin===true, {info:'Filter: sinyal dasar Spanyol sebelumnya WIN'});
        if (spanishBasic && validFinalScore(r)) baseSpainCandidates.push(l16H2(r)>=1);
        put(8,r,away==='italy' && prevFT(pa)!==null && prevFT(pa)>=3 && ready30(pa) && prev2H(pa)!==null && prev2H(pa)<=2 && ht!==null && h1!==h2);
        put(11,r,team('mexico') && prevMexico && l16Key(prevMexico.teams[0])==='mexico' && ht!==null && h1!==h2);
        put(12,r,home==='norway' && ht===2);
        put(14,r,team('hungary') && ht===2 && prevHungary && l16Key(prevHungary.teams[0])==='hungary');
        put(16,r,home==='belgium' && ht!==null && ht>=2 && ready30(ph) && prev2H(ph)!==null && prev2H(ph)<=3);
        put(27,r,prevSlotReady && prevSlotFT>=5 && ht!==null && ht>=3 && new Date(t+8*3600000).getUTCMinutes()===30 && ready30(ph) && prev2H(ph)!==null && prev2H(ph)<=1);
        put(30,r,prevSlotReady && prevSlot2H>=3 && prevSlot2H<=5 && ht===3 && prev2H(paAway)!==null && prev2H(paAway)<=3);
        put(32,r,team('usa') && ht!==null && ht>=1 && ph && l16Key(ph.teams[1])===home && ready30(ph) && ready30(pa) && prevHT(ph)!==null && prevHT(ph)<=1 && prev2H(pa)!==null && prev2H(pa)<=2);
        put(33,r,team('ukraine') && ht!==null && ht%2===1 && prevSlotReady && prevSlot2H>=2);
        put(34,r,home==='norway' && pa && prevHT(pa)!==null && prevHT(pa)>=1 && ht!==null && ht<=2 && ready30(ph) && prevFT(ph)!==null && prevFT(ph)<=4);
        put(35,r,team('france') && prevSlotReady && prevSlotFT>=5 && ht!==null && ht<=1);
        put(36,r,team('france') && pa && prev2H(pa)!==null && prev2H(pa)>=1 && ht!==null && h1===h2);
        put(37,r,team('netherlands') && ht!==null && ht>=1 && ph && prev2H(ph)!==null && prev2H(ph)>=2 && prevFT(ph)!==null && prevFT(ph)>=3 && prev2H(pa)!==null && prev2H(pa)<=2);
        put(38,r,team('argentina') && ht!==null && ht>=1 && ph && prevHT(ph)!==null && prevHT(ph)>=2 &&
          l16PreviousTeamGoal2H(ph,home)!==null && l16PreviousTeamGoal2H(ph,home)<=1 && prevSlotReady && prevSlotFT>=5);
        put(39,r,team('france') && prevSlotReady && prevSlotHT>=2 && ready30(ph) && ready30(pa) && prev2H(ph)!==null && prev2H(ph)>=2 && prev2H(pa)!==null && prev2H(pa)<=3);
      }
      // ID 31 = satu sinyal per SLOT target, bukan per pertandingan.
      if (prevSlotReady && prevSlot.every(p=>l16HT(p)===0) && group.length===2) {
        const target=group[0];
        let result=null;
        if (group.every(validFinalScore))
          result=group.some(r=>l16H2(r)>=1)?'BERHASIL':'TIDAK TERPENUHI';
        const rule=L16_RULE_BY_NUMBER.get(31);
        const matchLabel=group.map(r=>r.teams.map(l16Key).join(' vs ')).join(' + ');
        const scoreLabel=group.map(r=>'HT '+(r.fh?.join('-')||'?')+' → FT '+(r.ft?.join('-')||'?')).join(' | ');
        events.push({rule,r:target,group,check:{active:true,result,matchLabel,scoreLabel,
          info:'Satu sinyal gabungan; minimal 1 gol babak kedua dalam dua laga target'}});
      }
      // Gerbang 07 hanya boleh membaca sinyal dasar yang sudah selesai di waktu sebelumnya.
      if (baseSpainCandidates.length) lastSpainBaseWin=baseSpainCandidates[baseSpainCandidates.length-1];
      for (const r of group) if (validFinalScore(r)) {
        previousTeams.set(l16Key(r.teams[0]),r);
        previousTeams.set(l16Key(r.teams[1]),r);
        previousAwayTeams.set(l16Key(r.teams[1]),r);
      }
    }
    events.push(...l16DiscoveredEvents(games, byTime));
    events.sort((a,b) => a.r.t-b.r.t || a.rule.n-b.rule.n);
    return {rules:L16_ACTIVE_RULES, events};
  }

  function norwayEvaluation(records, target) {
    if (recordLeague(target) !== BELGIUM_LEAGUE || target.norwaySide !== 0)
      return { active: false, recovery: false, goals: null };
    const previous = records.filter(r => recordLeague(r) === BELGIUM_LEAGUE && r.norwaySide === 0 && r.t < target.t)
      .sort((a, b) => b.t - a.t)[0];
    const recovery = !!(checkedHistory(previous, target) && validFinalScore(previous) &&
      previous.fh[0] === previous.ft[0] && previous.fh[1] === previous.ft[1]);
    const goals = validFinalScore(target) ? target.ft[0] + target.ft[1] - target.fh[0] - target.fh[1] : null;
    return { active: recovery, recovery, goals, previous, reason: recovery ? 'HT = FT pada laga kandang Norway sebelumnya' : 'pemicu recovery tidak terpenuhi' };
  }

  function netherlandsEvaluation(records, target) {
    const previous = records.filter(r => r.netherlandsSide >= 0 && r.t < target.t).sort((a, b) => b.t - a.t)[0];
    if (!previous || !previous.complete || !previous.ft) return { active: false, previous, reason: 'hasil FT laga Belanda terakhir belum cukup' };
    if (!checkedHistory(previous, target)) return { active: false, previous, reason: 'DATA RIWAYAT BELUM CUKUP', result: null, goals: null };
    if (previous.ft[0] + previous.ft[1] < 4) return { active: false, previous, reason: 'total FT laga Belanda terakhir kurang dari 4' };
    if (!target.fh) return { active: false, previous, reason: 'pemicu terpenuhi; menunggu skor HT laga sekarang' };
    const ht = target.fh[0] + target.fh[1];
    if (ht !== 1 && ht !== 2) return { active: false, previous, reason: 'total HT laga sekarang bukan 1 atau 2' };
    const goals = target.complete && target.ft ? target.ft[0] + target.ft[1] - ht : null;
    return { active: true, previous, reason: 'pemicu dan HT terpenuhi', goals };
  }

  function sociedadEvaluation(records, target) {
    const previous = records.filter(r => r.sociedadSide === 0 && r.t < target.t).sort((a, b) => b.t - a.t)[0];
    if (target.sociedadSide !== 0) return { active: false, previous, reason: 'Real Sociedad tandang' };
    if (!previous || !previous.complete || !Array.isArray(previous.fh) || !Array.isArray(previous.ft)) return { active: false, previous, reason: 'data HT/FT kandang terakhir belum cukup' };
    if (!checkedHistory(previous, target)) return { active: false, previous, reason: 'DATA RIWAYAT BELUM CUKUP', result: null, goals: null };
    const active = previous.fh[0] === previous.ft[0] && previous.fh[1] === previous.ft[1];
    return { active, previous, reason: active ? 'HT sama dengan FT pada kedua tim' : 'ada perubahan skor pada babak kedua' };
  }

  const PSG_RULES = [
    { id: 'awayHighHT', name: 'PSG — POLA LAMA', description: 'PSG sekarang tandang • HT laga terakhir ≥2 gol', awayOnly: true },
    { id: 'sameVenueQuiet2H', name: 'PSG — POLA BARU', description: 'Venue sama • HT = FT pada laga sebelumnya', awayOnly: false }
  ].filter(rule => !DISABLED_L15.has(rule.id));

  function psgRuleEvaluation(records, target, rule) {
    if (![0, 1].includes(target.psgSide) || (rule.awayOnly && target.psgSide !== 1))
      return { active: false, previous: null, reason: 'venue tidak sesuai aturan', result: null };
    const previous = records.filter(r => r.psgSide >= 0 && r.t < target.t &&
      (rule.awayOnly || r.psgSide === target.psgSide)).sort((a, b) => b.t - a.t)[0];
    if (!validFinalScore(previous)) return { active: false, previous, reason: 'hasil HT/FT laga PSG sebelumnya belum cukup atau tidak valid', result: null };
    if (!checkedHistory(previous, target)) return { active: false, previous, reason: 'DATA RIWAYAT BELUM CUKUP', result: null };
    const active = rule.awayOnly ? previous.fh[0] + previous.fh[1] >= 2 :
      previous.fh[0] === previous.ft[0] && previous.fh[1] === previous.ft[1];
    const validHT = Array.isArray(target.fh) && target.fh.length === 2 && target.fh.every(g => Number.isInteger(g) && g >= 0);
    const result = active && target.complete && validHT ? (target.fh[0] + target.fh[1] >= 1 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null;
    return { active, previous, result, reason: active ? 'pemicu terpenuhi' : 'pemicu tidak terpenuhi' };
  }

  const AWAY_HT_RULES = [
    { id: 'chelseaAwayHT', name: 'CHELSEA TANDANG', team: 'Chelsea', awayPrevious: true, description: 'Tandang • total HT laga tandang Chelsea sebelumnya ≥2' },
    { id: 'tottenhamAwayHT', name: 'TOTTENHAM TANDANG', team: 'Tottenham', awayPrevious: false, description: 'Tandang • total gol 2H laga Tottenham terakhir (venue bebas) ≥2' }
  ].filter(rule => !DISABLED_L15.has(rule.id));

  function awayTeamSide(r, rule) {
    return r.teams?.findIndex(t => extraTeam(t) === rule.team.toLowerCase()) ?? -1;
  }

  function awayHTEvaluation(records, target, rule) {
    if (recordLeague(target) !== LEAGUE || awayTeamSide(target, rule) !== 1)
      return { active: false, previous: null, result: null, reason: 'target harus tandang di PES21 15 menit' };
    const previous = records.filter(r => recordLeague(r) === LEAGUE && r.t < target.t &&
      (rule.awayPrevious ? awayTeamSide(r, rule) === 1 : awayTeamSide(r, rule) >= 0))
      .sort((a, b) => b.t - a.t)[0];
    if (!validFinalScore(previous))
      return { active: false, previous, result: null, reason: 'HT/FT laga sebelumnya belum selesai atau tidak valid' };
    const ht = previous.fh[0] + previous.fh[1];
    const goals2H = previous.ft[0] + previous.ft[1] - ht;
    const active = rule.awayPrevious ? ht >= 2 : goals2H >= 2;
    const validHT = Array.isArray(target.fh) && target.fh.length === 2 && target.fh.every(g => Number.isInteger(g) && g >= 0);
    const result = active && target.complete && validHT ? (target.fh[0] + target.fh[1] >= 1 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null;
    return { active, previous, result, ht, goals2H, reason: active ? 'pemicu terpenuhi' : 'pemicu tidak terpenuhi' };
  }

  function evertonEvaluation(records, target) {
    if (recordLeague(target) !== LEAGUE || !target.teams?.some(isEverton))
      return { active: false, previous: null, result: null, reason: 'bukan pertandingan Everton League 15' };
    const previous = records.filter(r => recordLeague(r) === LEAGUE && r.t < target.t &&
      r.teams?.some(isEverton)).sort((a, b) => b.t - a.t)[0];
    if (!validFinalScore(previous))
      return { active: false, previous, result: null, reason: 'hasil HT/FT pertandingan Everton sebelumnya belum tersedia atau tidak valid' };
    if (!checkedHistory(previous, target))
      return { active: false, previous, result: null, reason: 'riwayat antar-liga tidak cocok' };
    const active = previous.fh[0] === previous.ft[0] && previous.fh[1] === previous.ft[1];
    const goals2H = validFinalScore(target) ? target.ft[0] + target.ft[1] - target.fh[0] - target.fh[1] : null;
    return {
      active, previous, goals2H,
      result: active && goals2H !== null ? (goals2H >= 1 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null,
      reason: active ? 'pemicu terpenuhi: laga Everton sebelumnya HT = FT' : 'pemicu tidak terpenuhi'
    };
  }

  const NEW_LEAGUE15_RULES = [
    { id: 'tottenhamSameVenueLowFT', name: 'TOTTENHAM — VENUE SAMA FT RENDAH', team: 'Tottenham', market: 'FT Over 1.5', description: 'Tottenham kandang/tandang • FT laga Tottenham sebelumnya pada venue yang sama maksimal 1 gol' },
    { id: 'manCityAwayPrevHighFT', name: 'MAN CITY TANDANG — PREV FT >=4', team: 'Manchester City', market: 'FT Over 1.5', description: 'Manchester City sekarang tandang • total FT laga Manchester City sebelumnya minimal 4 gol' },
    { id: 'tottenhamAwayPrevNoTeam2H', name: 'TOTTENHAM TANDANG — PREV TIDAK CETAK GOL 2H', team: 'Tottenham', market: '2H Over 0.5', description: 'Tottenham sekarang tandang • Tottenham tidak mencetak gol pribadi di 2H laga sebelumnya (venue bebas)' }
  ].filter(rule => !DISABLED_L15.has(rule.id));

  function league15TeamSide(r, team) {
    const names = r?.teams || [];
    return names.findIndex(s => {
      const n = extraTeam(s);
      return team === 'Tottenham' ? (n === 'tottenham' || n === 'tottenham hotspur') : n === 'manchester city';
    });
  }

  const newLeague15IndexCache = new WeakMap();
  function newLeague15Index(records) {
    let index = newLeague15IndexCache.get(records);
    if (index) return index;
    index = { tottenham: [], tottenhamHome: [], tottenhamAway: [], manCity: [] };
    for (const r of records) {
      if (recordLeague(r) !== LEAGUE || !Number.isFinite(r.t)) continue;
      const tSide = league15TeamSide(r, 'Tottenham');
      if (tSide >= 0) {
        index.tottenham.push(r);
        index[tSide === 0 ? 'tottenhamHome' : 'tottenhamAway'].push(r);
      }
      if (league15TeamSide(r, 'Manchester City') >= 0) index.manCity.push(r);
    }
    for (const arr of Object.values(index)) arr.sort((a, b) => a.t - b.t);
    newLeague15IndexCache.set(records, index);
    return index;
  }

  function newLeague15Evaluation(records, target, rule) {
    if (recordLeague(target) !== LEAGUE)
      return { active: false, result: null, previous: null, reason: 'bukan League 15' };
    const side = league15TeamSide(target, rule.team);
    if (side < 0 || (rule.id !== 'tottenhamSameVenueLowFT' && side !== 1))
      return { active: false, result: null, previous: null, reason: 'tim/venue saat ini tidak sesuai' };
    const idx = newLeague15Index(records);
    const pool = rule.id === 'tottenhamSameVenueLowFT'
      ? idx[side === 0 ? 'tottenhamHome' : 'tottenhamAway']
      : rule.id === 'manCityAwayPrevHighFT' ? idx.manCity : idx.tottenham;
    const previous = latestBefore(pool, target.t);
    if (!validFinalScore(previous))
      return { active: false, result: null, previous, reason: 'riwayat pertandingan terakhir belum selesai atau skor tidak valid' };
    const prevFT = previous.ft[0] + previous.ft[1];
    const prevTeamSide = league15TeamSide(previous, rule.team);
    const prevTeamGoals2H = previous.ft[prevTeamSide] - previous.fh[prevTeamSide];
    const active = rule.id === 'tottenhamSameVenueLowFT' ? prevFT <= 1 :
      rule.id === 'manCityAwayPrevHighFT' ? prevFT >= 4 : prevTeamGoals2H === 0;
    const targetValid = validFinalScore(target);
    const goals2H = targetValid ? target.ft[0] + target.ft[1] - target.fh[0] - target.fh[1] : null;
    const marketWin = targetValid ? (rule.market === 'FT Over 1.5' ? target.ft[0] + target.ft[1] >= 2 : goals2H >= 1) : null;
    const result = active && marketWin !== null ? (marketWin ? 'BERHASIL' : 'TIDAK TERPENUHI') : null;
    return { active, result, previous, prevFT, prevTeamGoals2H, goals2H, reason: active ? 'pemicu terpenuhi' : 'pemicu tidak terpenuhi' };
  }

  function norwayFTEvaluation(records, target) {
    const active = recordLeague(target) === BELGIUM_LEAGUE && target.norwaySide === 0;
    const validFT = target.complete && Array.isArray(target.ft) && target.ft.length === 2 && target.ft.every(g => Number.isInteger(g) && g >= 0);
    return { active, result: active && validFT ? (target.ft[0] + target.ft[1] >= 2 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null };
  }

  const NEW_FC_RULES = [
    { id: 'norwayFT', name: 'NORWEGIA KANDANG FT', description: 'Setiap laga kandang Norway • tanpa pemicu sebelumnya', evaluate: norwayFTEvaluation, market: 'FT Over 1.5' }
  ];

  const SPAIN_ITALY_RULES = [
    { id: 'spainAwayHigh2H', name: 'SPANYOL TANDANG — PREV 2H >=2', team: 'Spain', awayOnly: true, criterion: 'previous2H2', description: 'Spanyol tandang; laga Spanyol sebelumnya minimal 2 gol babak kedua' },
    { id: 'italyAwayPrevFT3', name: 'ITALIA TANDANG — PREV FT >=3', team: 'Italy', awayOnly: true, criterion: 'previousFT3', description: 'Italia tandang; total FT laga Italia sebelumnya minimal 3 gol' },
    { id: 'spainHT23PrevGoal', name: 'SPANYOL — HT 2-3 & PREV GOL 2H', team: 'Spain', awayOnly: false, criterion: 'previousGoal2H_HT23', description: 'Spanyol kandang/tandang; total HT saat ini 2-3 dan laga Spanyol sebelumnya ada gol babak kedua' }
  ];

  function spainItalyEvaluation(records, target, rule) {
    if (recordLeague(target) !== BELGIUM_LEAGUE ||
      (rule.awayOnly ? extraTeam(target.teams?.[1] || '') !== rule.team.toLowerCase() :
        !target.teams?.some(t => extraTeam(t) === rule.team.toLowerCase())))
      return { active: false, previous: null, result: null, reason: 'tim/venue tidak sesuai' };
    const previous = records.filter(r => recordLeague(r) === BELGIUM_LEAGUE && r.t < target.t &&
      r.teams?.some(t => extraTeam(t) === rule.team.toLowerCase())).sort((a, b) => b.t - a.t)[0];
    if (!validFinalScore(previous))
      return { active: false, previous, result: null, reason: 'riwayat negara sebelumnya belum valid' };
    const prev2H = previous.ft[0] + previous.ft[1] - previous.fh[0] - previous.fh[1];
    const prevFT = previous.ft[0] + previous.ft[1];
    let active = rule.criterion === 'previous2H2' ? prev2H >= 2 :
      rule.criterion === 'previousFT3' ? prevFT >= 3 : prev2H >= 1;
    if (active && rule.criterion === 'previousGoal2H_HT23') {
      if (!Array.isArray(target.fh) || target.fh.length !== 2 || !target.fh.every(Number.isInteger))
        return { active: false, previous, result: null, reason: 'pemicu riwayat ada, menunggu HT target' };
      const ht = target.fh[0] + target.fh[1];
      active = ht === 2 || ht === 3;
    }
    const goals = validFinalScore(target) ? target.ft[0] + target.ft[1] - target.fh[0] - target.fh[1] : null;
    return {
      active, previous, goals, prev2H, prevFT,
      result: active && goals !== null ? (goals >= 1 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null,
      reason: active ? 'pemicu terpenuhi' : 'pemicu tidak terpenuhi'
    };
  }

  const ALGERIA_RULES = [
    { id: 'algeriaFT', name: 'ALJAZAIR — FT OVER 1.5', market: 'FT Over 1.5' },
    { id: 'algeria2H', name: 'ALJAZAIR — 2H OVER 0.5', market: '2H Over 0.5' }
  ];

  function isAlgeriaMatch(r) {
    return recordLeague(r) === ALGERIA_LEAGUE && r.teams?.some(t => extraTeam(t) === 'algeria');
  }

  function algeriaEvaluation(records, target, rule) {
    const active = !!isAlgeriaMatch(target);
    let result = null;
    if (active && target.complete) {
      if (rule.id === 'algeriaFT' && Array.isArray(target.ft) && target.ft.length === 2 && target.ft.every(g => Number.isInteger(g) && g >= 0))
        result = target.ft[0] + target.ft[1] >= 2 ? 'BERHASIL' : 'GAGAL';
      if (rule.id === 'algeria2H' && validFinalScore(target))
        result = target.ft[0] + target.ft[1] - target.fh[0] - target.fh[1] >= 1 ? 'BERHASIL' : 'GAGAL';
    }
    return { active, result };
  }

  // === POLA BERSYARAT LEAGUE 20 ===
  const L20_NEW_RULES = [
    { id: 'l20CroatiaAwayHT', team: 'Croatia', venue: 'away', history: 'any', predicate: 'prevFT3', name: 'KROASIA TANDANG — PREV FT ≥3', market: 'HT Over 0.5', group: 'priority', description: 'Kroasia sekarang tandang; total FT laga Kroasia terakhir (venue bebas) ≥3' },
    { id: 'l20SwedenHomeHT', team: 'Sweden', venue: 'home', history: 'any', predicate: 'prevOwnHT0', name: 'SWEDIA KANDANG — PREV HT TIM 0', market: 'HT Over 0.5', group: 'watch', description: 'Swedia sekarang kandang; Swedia tidak mencetak gol di HT laga sebelumnya (venue bebas)' },
    { id: 'l20AlgeriaHome2H', team: 'Algeria', venue: 'home', history: 'same', predicate: 'prev2H2', name: 'ALJAZAIR KANDANG — PREV 2H ≥2', market: '2H Over 0.5', group: 'watch', description: 'Aljazair sekarang kandang; laga kandang Aljazair sebelumnya memiliki ≥2 gol total babak kedua' },
    { id: 'l20UruguayHomeFT', team: 'Uruguay', venue: 'home', history: 'same', predicate: 'prevHT2', name: 'URUGUAY KANDANG — PREV HT ≥2', market: 'FT Over 1.5', group: 'research', description: 'Uruguay sekarang kandang; total gol HT laga kandang Uruguay sebelumnya ≥2' },
    { id: 'l20SwissHome2H', team: 'Switzerland', venue: 'home', history: 'any', predicate: 'prevOwn2H0', name: 'SWISS KANDANG — PREV TIM 2H 0', market: '2H Over 0.5', group: 'watch', description: 'Swiss sekarang kandang; Swiss tidak mencetak gol pribadi di 2H laga sebelumnya (venue bebas)' },
    { id: 'l20NetherlandsHomeHT', team: 'Netherlands', venue: 'home', history: 'same', predicate: 'prevFT3', name: 'BELANDA KANDANG — PREV FT ≥3', market: 'HT Over 0.5', group: 'research', description: 'Belanda sekarang kandang; FT laga kandang Belanda sebelumnya ≥3 gol' },
    { id: 'l20GermanyAway2H', team: 'Germany', venue: 'away', history: 'same', predicate: 'prev2H2', name: 'JERMAN TANDANG — PREV 2H ≥2', market: '2H Over 0.5', group: 'research', description: 'Jerman sekarang tandang; laga tandang Jerman sebelumnya memiliki ≥2 gol total babak kedua' },
    { id: 'l20MexicoHome2H', team: 'Mexico', venue: 'home', history: 'any', predicate: 'prevOwnHT0', name: 'MEKSIKO KANDANG — PREV HT TIM 0', market: '2H Over 0.5', group: 'research', description: 'Meksiko sekarang kandang; Meksiko tidak mencetak gol HT laga sebelumnya (venue bebas)' },
    { id: 'l20ColombiaHome2H', team: 'Colombia', venue: 'home', history: 'any', predicate: 'prevOwn2H0', name: 'KOLOMBIA KANDANG — PREV TIM 2H 0', market: '2H Over 0.5', group: 'research', description: 'Kolombia sekarang kandang; Kolombia tidak mencetak gol pribadi di 2H laga sebelumnya (venue bebas)' }
  ].filter(rule => !DISABLED_L20.has(rule.id));

  const L20_PROSPECTIVE_KEY = 'liverpool-saba-league20-new-prospective-v120';
  const L20_GROUP_NAMES = { priority: 'PRIORITAS UJI', watch: 'PANTAU', research: 'RISET SAJA' };

  const l20IndexCache = new WeakMap();
  function l20MakeIndex(records) {
    let index = l20IndexCache.get(records);
    if (index) return index;
    index = new Map();
    for (const r of records) {
      if (recordLeague(r) !== ALGERIA_LEAGUE || !Number.isFinite(r.t) || !Array.isArray(r.teams)) continue;
      for (const side of [0, 1]) {
        const team = extraTeam(r.teams[side] || '');
        if (!team) continue;
        if (!index.has(team)) index.set(team, { any: [], home: [], away: [] });
        const bucket = index.get(team);
        bucket.any.push(r);
        bucket[side === 0 ? 'home' : 'away'].push(r);
      }
    }
    for (const bucket of index.values()) for (const arr of Object.values(bucket))
      arr.sort((a, b) => a.t - b.t || String(a.id).localeCompare(String(b.id)));
    l20IndexCache.set(records, index);
    return index;
  }

  function l20MarketResult(target, market) {
    if (!validFinalScore(target)) return null;
    const ht = target.fh[0] + target.fh[1], ft = target.ft[0] + target.ft[1];
    const win = market === 'HT Over 0.5' ? ht >= 1 : market === 'FT Over 1.5' ? ft >= 2 : ft - ht >= 1;
    return win ? 'BERHASIL' : 'TIDAK TERPENUHI';
  }

  function l20Evaluate(index, target, rule) {
    if (recordLeague(target) !== ALGERIA_LEAGUE || !Array.isArray(target.teams))
      return { active: false, previous: null, reason: 'bukan League 20' };
    const side = rule.venue === 'home' ? 0 : 1;
    if (extraTeam(target.teams[side] || '') !== rule.team.toLowerCase())
      return { active: false, previous: null, reason: 'tim atau venue sekarang tidak cocok' };
    const bucket = index.get(rule.team.toLowerCase());
    const pool = bucket ? bucket[rule.history === 'any' ? 'any' : rule.venue] : [];
    const previous = latestBefore(pool, target.t);
    if (!validFinalScore(previous))
      return { active: false, previous, reason: 'hasil HT/FT laga terakhir yang sesuai belum lengkap' };
    if (!checkedHistory(previous, target))
      return { active: false, previous, reason: 'riwayat liga/tanggal tidak cocok' };
    const prevSide = (previous.teams || []).findIndex(x => extraTeam(x) === rule.team.toLowerCase());
    if (prevSide < 0) return { active: false, previous, reason: 'riwayat tim tidak cocok' };
    const prevHT = previous.fh[0] + previous.fh[1];
    const prevFT = previous.ft[0] + previous.ft[1];
    const prev2H = prevFT - prevHT;
    const prevOwnHT = previous.fh[prevSide];
    const prevOwn2H = previous.ft[prevSide] - previous.fh[prevSide];
    const active = rule.predicate === 'prevFT3' ? prevFT >= 3 :
      rule.predicate === 'prevHT2' ? prevHT >= 2 :
        rule.predicate === 'prev2H2' ? prev2H >= 2 :
          rule.predicate === 'prevOwnHT0' ? prevOwnHT === 0 :
            rule.predicate === 'prevOwn2H0' ? prevOwn2H === 0 : false;
    return {
      active, previous, prevHT, prevFT, prev2H, prevOwnHT, prevOwn2H,
      result: active ? l20MarketResult(target, rule.market) : null,
      reason: active ? 'pemicu terpenuhi' : 'hasil laga sebelumnya tidak memenuhi pemicu'
    };
  }

  function l20Report(records, now = Date.now()) {
    const history = records.filter(r => recordLeague(r) === ALGERIA_LEAGUE).sort((a, b) => a.t - b.t);
    const ix = l20MakeIndex(history);
    const rules = L20_NEW_RULES.map(rule => ({ ...rule, events: [] }));
    const byId = new Map(rules.map(rule => [rule.id, rule]));
    const today = l15SiteDay(now);
    const daily = { day: today, triggers: 0, wins: 0, failed: 0, pending: 0, unique: new Set() };
    for (const r of history) {
      for (const rule of L20_NEW_RULES) {
        const ch = l20Evaluate(ix, r, rule);
        if (!ch.active) continue;
        const event = {
          id: r.id, t: r.t, date: r.date, teams: r.teams, fh: r.fh, ft: r.ft,
          ruleId: rule.id, market: rule.market, result: ch.result, previous: ch.previous
        };
        byId.get(rule.id).events.push(event);
        if (l15SiteDay(r.t) === today) {
          daily.triggers++; daily.unique.add(r.id);
          if (ch.result === 'BERHASIL') daily.wins++;
          else if (ch.result === 'TIDAK TERPENUHI') daily.failed++;
          else daily.pending++;
        }
      }
    }
    for (const rule of rules) rule.stats = l15Summarize(rule.events, now);
    return { history, ix, rules, byId, today: { ...daily, unique: daily.unique.size } };
  }

  function l20ProspectiveUpdate(report, visible, now = Date.now()) {
    let store = GM_getValue(L20_PROSPECTIVE_KEY, {});
    if (!store || typeof store !== 'object' || Array.isArray(store)) store = {};
    let changed = false;
    const byId = new Map(report.history.map(r => [r.id, r]));
    for (const { r } of visible) {
      if (recordLeague(r) !== ALGERIA_LEAGUE || r.complete || !usablePending(r, now) || now > r.t) continue;
      for (const rule of L20_NEW_RULES) {
        const ch = l20Evaluate(report.ix, r, rule);
        if (!ch.active || !validFinalScore(ch.previous)) continue;
        const known = ch.previous.completedSeenAt;
        if (!Number.isFinite(known) || known > now || known > r.t) continue;
        const key = rule.id + '|' + r.id;
        if (!store[key]) {
          store[key] = {
            id: r.id, ruleId: rule.id, market: rule.market, matchTime: r.t, observedAt: now,
            previousId: ch.previous.id, previousCompletedSeenAt: known, date: r.date, teams: r.teams, result: null
          };
          changed = true;
        }
      }
    }
    for (const p of Object.values(store)) {
      if (!p || !p.id || !p.ruleId || p.result !== null) continue;
      const r = byId.get(p.id);
      if (!r || !validFinalScore(r)) continue;
      const rule = L20_NEW_RULES.find(x => x.id === p.ruleId);
      if (!rule) continue;
      p.result = l20MarketResult(r, rule.market);
      changed = true;
    }
    if (changed) GM_setValue(L20_PROSPECTIVE_KEY, store);
    return store;
  }

  // Helper UI untuk membuat elemen dengan pelacakan state buka/tutup dan scroll
  const l15El = (tag, content, css = '', cls = '') => {
    const el = document.createElement(tag);
    if (content !== undefined && content !== null) el.textContent = String(content);
    if (css) el.style.cssText = css;
    if (cls) el.className = cls;
    return el;
  };

  function makeTrackedDetails(stateId, cls = 'saba-details') {
    const d = document.createElement('details');
    d.className = cls;
    d.dataset.stateId = stateId;
    d.open = openDetailsSet.has(stateId);
    d.addEventListener('toggle', () => {
      if (d.open) openDetailsSet.add(stateId);
      else openDetailsSet.delete(stateId);
    });
    return d;
  }

  function makeScrollList(scrollId) {
    const div = document.createElement('div');
    div.className = 'saba-scroll-list';
    div.dataset.scrollId = scrollId;
    div.addEventListener('scroll', () => nestedScrollMap.set(scrollId, div.scrollTop), { passive: true });
    return div;
  }

  function l20QualityPanel(report, prospective = {}) {
    const root = l15El('div', null, '', 'saba-card');
    root.append(l15El('div', `LEAGUE 20 • ${report.rules.length} POLA BERSYARAT`, 'font-weight:800;font-size:13px;color:#38bdf8'));
    const d = report.today;
    root.append(l15El('div', `${d.day} GMT+8 • ${d.triggers} pemicu • ${d.wins} sukses • ${d.failed} gagal • ${d.pending} tunggu (${d.unique} laga)`, '', 'saba-stat-bar'));

    const controls = l15El('div', null, 'display:flex;gap:4px;flex-wrap:wrap;margin:6px 0');
    const panes = [];
    for (const [val, label] of [['all', 'Semua'], ['priority', 'Prioritas'], ['watch', 'Pantau'], ['research', 'Riset']]) {
      const btn = l15El('button', label, '', 'saba-btn' + (l20MonitorFilter === val ? ' active' : ''));
      btn.type = 'button';
      btn.onclick = () => {
        l20MonitorFilter = val;
        for (const x of panes) x.el.style.display = val === 'all' || x.group === val ? 'block' : 'none';
        for (const b of controls.querySelectorAll('button')) b.classList.toggle('active', b === btn);
      };
      controls.append(btn);
    }
    root.append(controls);

    const fmt = x => `${x.wins}/${x.n}${x.n ? ' (' + (100 * x.wins / x.n).toFixed(1) + '%)' : ''}`;
    for (const rule of report.rules) {
      const st = rule.stats;
      const panel = makeTrackedDetails(`l20-q-${rule.id}`);
      panel.style.display = l20MonitorFilter === 'all' || l20MonitorFilter === rule.group ? 'block' : 'none';
      panes.push({ el: panel, group: rule.group });

      const head = l15El('summary');
      head.append(l15El('span', rule.name));
      head.append(l15El('span', `${st.wins}/${st.finished.length} • ${rule.market}`, 'color:#38bdf8;font-size:11px'));
      panel.append(head);

      panel.append(l15El('div', `${L20_GROUP_NAMES[rule.group]}${st.warning ? ' • TREN TURUN' : ''} — ${rule.description}`, 'font-size:10px;color:#94a3b8;margin:4px 0'));
      panel.append(l15El('div', `7H: ${fmt(st.last7)}  |  30H: ${fmt(st.last30)}  |  10 Akhir: ${fmt(st.last10)}`, 'font-size:11px;color:#e2e8f0'));
      panel.append(l15El('div', `Sesudah gagal: ${fmt(st.afterFail)}`, 'font-size:10px;color:#94a3b8'));
      if (st.failures.length) {
        panel.append(l15El('div', 'Kegagalan Terakhir:', 'font-size:10px;color:#fca5a5;font-weight:700;margin-top:4px'));
        for (const e of st.failures.slice(0, 5))
          panel.append(l15El('div', `${e.date} • ${(e.teams || []).join(' v ')} (HT ${e.fh?.join('-') || '?'} → FT ${e.ft?.join('-') || '?'})`, 'font-size:10px;color:#fca5a5;padding:2px 0'));
      }
      root.append(panel);
    }

    const prospects = Object.values(prospective || {}).filter(x => x && report.byId.has(x.ruleId));
    const concluded = prospects.filter(p => p.result !== null);
    const win = concluded.filter(p => p.result === 'BERHASIL').length;
    const live = makeTrackedDetails('l20-prospective-log');
    const liveSum = l15El('summary');
    liveSum.append(l15El('span', 'LOG PRA-KICKOFF L20'));
    liveSum.append(l15El('span', `${win}/${concluded.length} sukses • ${prospects.length - concluded.length} tunggu`, 'color:#6ee7b7;font-size:11px'));
    live.append(liveSum);

    const list = makeScrollList('l20-prospective-scroll');
    for (const p of prospects.sort((a, b) => b.observedAt - a.observedAt).slice(0, 15)) {
      const rule = report.byId.get(p.ruleId);
      list.append(l15El('div', `${new Date(p.observedAt).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' })} WIB • ${rule?.name || p.ruleId} • ${(p.teams || []).join(' v ')} • ${p.result || 'MENUNGGU'}`, 'font-size:10px;border-bottom:1px solid #334155;padding:4px 0'));
    }
    if (!prospects.length) list.append(l15El('div', 'Belum ada sinyal League 20 yang tercatat sebelum kickoff.', 'font-size:10px;color:#94a3b8'));
    live.append(list);
    root.append(live);
    return root;
  }

  function isSessionTarget(record, targetTime) {
    return record.league === LEAGUE && record.t === targetTime && usablePending(record);
  }

  function sessionSignals(records, market = 'FT') {
    records = records.filter(r => recordLeague(r) === LEAGUE);
    const sessions = new Map();
    for (const r of records) {
      if (!sessions.has(r.t)) sessions.set(r.t, []);
      sessions.get(r.t).push(r);
    }
    const signals = [];
    for (const [t, source] of sessions) {
      if (!source.length || !source.every(validFinalScore)) continue;
      if (source.filter(r => r.total < 2).length !== 2) continue;
      const targetTime = t + 15 * 60 * 1000;
      const target = sessions.get(targetTime) || [];
      const targetVerified = target.length === source.length;
      const succeeded = targetVerified && target.some(r => validFinalScore(r) && (market === '2H' ? r.ft[0] + r.ft[1] - r.fh[0] - r.fh[1] >= 1 : r.total >= 2));
      const finished = targetVerified && target.every(validFinalScore);
      const knownAt = source.every(r => Number.isFinite(r.completedSeenAt)) ? Math.max(...source.map(r => r.completedSeenAt)) : null;
      signals.push({ t, source, targetTime, target, targetVerified, knownAt, result: !targetVerified ? 'JUMLAH LAGA TARGET BELUM SESUAI DATA SUMBER' : succeeded ? 'BERHASIL' : finished ? 'TIDAK TERPENUHI' : 'MENUNGGU HASIL' });
    }
    return signals.sort((a, b) => b.t - a.t);
  }

  function fourRuleReport(records, predicate = () => true) {
    const saba = records.filter(r => recordLeague(r) === LEAGUE).sort((a, b) => a.t - b.t);
    const specs = [
      ...LIVERPOOL_RULES.map(rule => ({ rule, evaluate: liverpoolEvaluation, market: 'FT Over 1.5' })),
      ...PSG_RULES.map(rule => ({ rule, evaluate: psgRuleEvaluation, market: 'HT Over 0.5' })),
      ...AWAY_HT_RULES.map(rule => ({ rule, evaluate: awayHTEvaluation, market: 'HT Over 0.5' }))
    ];
    const histories = Object.fromEntries(specs.map(({ rule }) => [rule.id, []]));
    const unique = new Map();
    let triggered = 0;
    for (const r of saba) {
      if (!predicate(r)) continue;
      for (const { rule, evaluate, market } of specs) {
        const check = evaluate(saba, r, rule);
        if (!check.active) continue;
        triggered++;
        const entry = { matchId: r.id, date: r.date, teams: r.teams, previousId: check.previous.id, market, result: check.result || 'MENUNGGU HASIL' };
        histories[rule.id].push(entry);
        if (!unique.has(r.id)) unique.set(r.id, { results: [], rules: [] });
        unique.get(r.id).rules.push(rule.id);
        unique.get(r.id).results.push(check.result);
      }
    }
    let won = 0, failed = 0, pending = 0, mixed = 0;
    for (const { results } of unique.values()) {
      if (results.some(result => result === null)) pending++;
      else if (results.every(result => result === 'BERHASIL')) won++;
      else if (results.every(result => result === 'TIDAK TERPENUHI')) failed++;
      else mixed++;
    }
    return { specs, histories, triggered, matches: unique.size, won, failed, pending, mixed };
  }

  function dailyCounts(records, now = Date.now()) {
    historyRecords = records;
    const day = t => new Date(t + 8 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const today = day(now);
    const names = [...L20_NEW_RULES.map(r => r.name), ...ALGERIA_RULES.map(r => r.name), ...LIVERPOOL_RULES.map(r => r.name),
      'Sesi 2 Under', 'Sesi 2 Under → gol 2H', 'EVERTON — GOL 2H', ...NEW_LEAGUE15_RULES.map(r => r.name),
      ...PSG_RULES.map(r => r.name), ...AWAY_HT_RULES.map(r => r.name), 'Real Sociedad kandang',
      ...L16_ACTIVE_RULES.map(r => r.name)];
    const counts = Object.fromEntries(names.map(name => [name, { failed: 0, checked: 0 }]));
    const add = (name, failed) => { counts[name].checked++; if (failed) counts[name].failed++; };
    const saba = records.filter(r => !r.league || r.league === LEAGUE).sort((a, b) => a.t - b.t);
    const fc24 = records.filter(r => r.league === BELGIUM_LEAGUE).sort((a, b) => a.t - b.t);
    for (const r of saba) {
      if (!r.complete || day(r.t) !== today) continue;
      for (const rule of LIVERPOOL_RULES) {
        const check = liverpoolEvaluation(saba, r, rule);
        if (check.result !== null) add(rule.name, check.result === 'TIDAK TERPENUHI');
      }
      for (const rule of PSG_RULES) {
        const check = psgRuleEvaluation(saba, r, rule);
        if (check.result !== null) add(rule.name, check.result === 'TIDAK TERPENUHI');
      }
      for (const rule of AWAY_HT_RULES) {
        const check = awayHTEvaluation(saba, r, rule);
        if (check.result !== null) add(rule.name, check.result === 'TIDAK TERPENUHI');
      }
      if (r.sociedadSide === 0 && Number.isInteger(r.total) && sociedadEvaluation(saba, r).active) add('Real Sociedad kandang', r.total < 2);
      const evertonCheck = evertonEvaluation(saba, r);
      if (evertonCheck.result !== null) add('EVERTON — GOL 2H', evertonCheck.result === 'TIDAK TERPENUHI');
      for (const rule of NEW_LEAGUE15_RULES) {
        const check = newLeague15Evaluation(saba, r, rule);
        if (check.result !== null) add(rule.name, check.result === 'TIDAK TERPENUHI');
      }
    }
    for (const s of sessionSignals(saba)) {
      if (day(s.targetTime) === today && ['BERHASIL', 'TIDAK TERPENUHI'].includes(s.result)) add('Sesi 2 Under', s.result === 'TIDAK TERPENUHI');
    }
    for (const signal of sessionSignals(saba, '2H')) {
      if (day(signal.targetTime) === today && ['BERHASIL', 'TIDAK TERPENUHI'].includes(signal.result)) add('Sesi 2 Under → gol 2H', signal.result === 'TIDAK TERPENUHI');
    }
    for (const e of l16BuildEvents(fc24).events) {
      if (l16SessionDay(e.r.t) !== l16SessionDay(now) || e.check.result === null) continue;
      add(e.rule.name, e.check.result === 'TIDAK TERPENUHI');
    }
    for (const r of records.filter(isAlgeriaMatch)) {
      if (day(r.t) !== today) continue;
      for (const rule of ALGERIA_RULES) {
        const check = algeriaEvaluation(records, r, rule);
        if (check.result !== null) add(rule.name, check.result === 'GAGAL');
      }
    }
    const l20DailyReport = l20Report(records, now);
    for (const rule of l20DailyReport.rules) {
      for (const event of rule.events) {
        if (l15SiteDay(event.t) !== today || event.result === null) continue;
        add(rule.name, event.result === 'TIDAK TERPENUHI');
      }
    }
    return { today, counts };
  }

  function time(s) {
    const m = s.match(/(\d{4})-(\d{2})-(\d{2})\s+(\d{1,2}):(\d{2})\s*(AM|PM)/i);
    if (!m) return null;
    return Date.UTC(+m[1], +m[2] - 1, +m[3], (+m[4] % 12) + (m[6].toUpperCase() === 'PM' ? 12 : 0) - 8, +m[5]);
  }

  // === LEAGUE 15 QUALITY MONITOR ===
  const L15_PROSPECTIVE_KEY = 'liverpool-saba-league15-prospective-v119';
  const L15_MS_DAY = 86400000;
  const L15_MONITOR_DEFS = [
    { id: 'tottenhamSameVenueLowFT', name: 'Tottenham venue sama', market: 'FT Over 1.5', group: 'priority' },
    { id: 'manCityAwayPrevHighFT', name: 'Man City tandang', market: 'FT Over 1.5', group: 'priority' },
    { id: 'teamQuiet2H', name: 'Liverpool venue sama', market: 'FT Over 1.5', group: 'priority' },
    { id: 'sameVenueQuiet2H', name: 'PSG venue sama', market: 'HT Over 0.5', group: 'watch' },
    { id: 'chelseaAwayHT', name: 'Chelsea tandang', market: 'HT Over 0.5', group: 'watch' },
    { id: 'evertonQuiet2H', name: 'Everton setelah 2H sepi', market: '2H Over 0.5', group: 'watch' },
    { id: 'sociedadHomeQuiet2H', name: 'Sociedad kandang', market: 'FT Over 1.5', group: 'watch' },
    { id: 'homeMatchQuiet2H', name: 'Liverpool kandang lama', market: 'FT Over 1.5', group: 'watch' },
    { id: 'tottenhamAwayHT', name: 'Tottenham HT lama', market: 'HT Over 0.5', group: 'watch' },
    { id: 'tottenhamAwayPrevNoTeam2H', name: 'Tottenham tandang 2H', market: '2H Over 0.5', group: 'research' },
    { id: 'awayHighHT', name: 'PSG tandang HT', market: 'HT Over 0.5', group: 'research' }
  ].filter(rule => !DISABLED_L15.has(rule.id));

  const L15_GROUP_NAMES = { priority: 'PRIORITAS UJI', watch: 'PANTAU', research: 'RISET SAJA' };
  const l15RuleLabel = id => {
    const def = L15_MONITOR_DEFS.find(r => r.id === id);
    return def ? L15_GROUP_NAMES[def.group] : 'PANTAU';
  };
  const l15SiteDay = t => new Date(t + 8 * 3600000).toISOString().slice(0, 10);

  const l15IndexCache = new WeakMap();
  function l15MakeIndex(records) {
    let ix = l15IndexCache.get(records);
    if (ix) return ix;
    ix = { liverpool: [[], []], psg: [[], []], chelseaAway: [], spurs: [], sociedadHome: [], everton: [] };
    for (const r of records) {
      if (recordLeague(r) !== LEAGUE || !Number.isFinite(r.t)) continue;
      const teams = r.teams || [];
      const ls = teams.findIndex(isLiverpool);
      const ps = teams.findIndex(isPSG);
      if (ls >= 0) ix.liverpool[ls].push(r);
      if (ps >= 0) ix.psg[ps].push(r);
      if (extraTeam(teams[1] || '') === 'chelsea') ix.chelseaAway.push(r);
      if (teams.some(t => ['tottenham', 'tottenham hotspur'].includes(extraTeam(t)))) ix.spurs.push(r);
      if (isSociedad(teams[0] || '')) ix.sociedadHome.push(r);
      if (teams.some(isEverton)) ix.everton.push(r);
    }
    for (const set of [...ix.liverpool, ...ix.psg, ix.chelseaAway, ix.spurs, ix.sociedadHome, ix.everton])
      set.sort((a, b) => a.t - b.t || String(a.id).localeCompare(String(b.id)));
    l15IndexCache.set(records, ix);
    return ix;
  }

  function l15Check(ix, records, r, def) {
    const id = def.id, teams = r.teams || [];
    let prev = null, active = false;
    if (id === 'tottenhamSameVenueLowFT' || id === 'manCityAwayPrevHighFT' || id === 'tottenhamAwayPrevNoTeam2H') {
      const rule = NEW_LEAGUE15_RULES.find(q => q.id === id);
      const ch = newLeague15Evaluation(records, r, rule);
      return { active: ch.active, previous: ch.previous, result: ch.result, reason: ch.reason };
    }
    if (id === 'homeMatchQuiet2H' || id === 'teamQuiet2H') {
      const side = teams.findIndex(isLiverpool);
      if (side < 0 || (id === 'homeMatchQuiet2H' && side !== 0)) return { active: false };
      prev = latestBefore(ix.liverpool[side], r.t);
      if (validFinalScore(prev)) {
        const goals = id === 'homeMatchQuiet2H' ? prev.ft[0] + prev.ft[1] - prev.fh[0] - prev.fh[1] : prev.ft[side] - prev.fh[side];
        active = goals === 0;
      }
    } else if (id === 'awayHighHT' || id === 'sameVenueQuiet2H') {
      const side = teams.findIndex(isPSG);
      if (side < 0 || (id === 'awayHighHT' && side !== 1)) return { active: false };
      if (id === 'awayHighHT') {
        const a = latestBefore(ix.psg[0], r.t), b = latestBefore(ix.psg[1], r.t);
        prev = (!a ? b : !b ? a : a.t >= b.t ? a : b);
      } else prev = latestBefore(ix.psg[side], r.t);
      if (validFinalScore(prev)) active = id === 'awayHighHT' ? prev.fh[0] + prev.fh[1] >= 2 : prev.fh[0] === prev.ft[0] && prev.fh[1] === prev.ft[1];
    } else if (id === 'chelseaAwayHT') {
      if (extraTeam(teams[1] || '') !== 'chelsea') return { active: false };
      prev = latestBefore(ix.chelseaAway, r.t);
      if (validFinalScore(prev)) active = prev.fh[0] + prev.fh[1] >= 2;
    } else if (id === 'tottenhamAwayHT') {
      if (!['tottenham', 'tottenham hotspur'].includes(extraTeam(teams[1] || ''))) return { active: false };
      prev = latestBefore(ix.spurs, r.t);
      if (validFinalScore(prev)) active = prev.ft[0] + prev.ft[1] - prev.fh[0] - prev.fh[1] >= 2;
    } else if (id === 'sociedadHomeQuiet2H') {
      if (!isSociedad(teams[0] || '')) return { active: false };
      prev = latestBefore(ix.sociedadHome, r.t);
      if (validFinalScore(prev)) active = prev.fh.every((v, i) => v === prev.ft[i]);
    } else if (id === 'evertonQuiet2H') {
      if (!teams.some(isEverton)) return { active: false };
      prev = latestBefore(ix.everton, r.t);
      if (validFinalScore(prev)) active = prev.fh.every((v, i) => v === prev.ft[i]);
    }
    if (!active) return { active: false, previous: prev };
    let result = null;
    if (validFinalScore(r)) {
      const ht = r.fh[0] + r.fh[1], ft = r.ft[0] + r.ft[1];
      const win = def.market === 'HT Over 0.5' ? ht >= 1 : def.market === '2H Over 0.5' ? ft - ht >= 1 : ft >= 2;
      result = win ? 'BERHASIL' : 'TIDAK TERPENUHI';
    }
    return { active: true, previous: prev, result };
  }

  function l15Summarize(events, now = Date.now()) {
    const finished = events.filter(e => e.result !== null).sort((a, b) => a.t - b.t);
    const wins = xs => xs.filter(e => e.result === 'BERHASIL').length;
    const last7 = finished.filter(e => e.t >= now - 7 * L15_MS_DAY && e.t <= now);
    const last30 = finished.filter(e => e.t >= now - 30 * L15_MS_DAY && e.t <= now);
    const last10 = finished.slice(-10);
    let afterFailWins = 0, afterFailN = 0, afterFirstWins = 0, afterFirstN = 0;
    const byDay = new Map();
    for (const e of finished) {
      const day = l15SiteDay(e.t);
      if (!byDay.has(day)) byDay.set(day, []);
      byDay.get(day).push(e);
    }
    for (const arr of byDay.values()) {
      arr.sort((a, b) => a.t - b.t);
      const firstFail = arr.findIndex(e => e.result === 'TIDAK TERPENUHI');
      if (firstFail >= 0 && firstFail < arr.length - 1) {
        afterFirstN++;
        if (arr[firstFail + 1].result === 'BERHASIL') afterFirstWins++;
      }
      for (let i = 1; i < arr.length; i++) if (arr[i - 1].result === 'TIDAK TERPENUHI') {
        afterFailN++;
        if (arr[i].result === 'BERHASIL') afterFailWins++;
      }
    }
    const historicalRate = finished.length ? wins(finished) / finished.length : null;
    const lastRate = last10.length ? wins(last10) / last10.length : null;
    const warning = last10.length >= 10 && finished.length >= 30 && lastRate < historicalRate - 0.15;
    return {
      finished, wins: wins(finished), last7: { wins: wins(last7), n: last7.length },
      last30: { wins: wins(last30), n: last30.length }, last10: { wins: wins(last10), n: last10.length }, warning,
      afterFail: { wins: afterFailWins, n: afterFailN }, afterFirstFail: { wins: afterFirstWins, n: afterFirstN },
      failures: finished.filter(e => e.result === 'TIDAK TERPENUHI').slice(-8).reverse()
    };
  }

  function l15Report(records, now = Date.now()) {
    const saba = records.filter(r => recordLeague(r) === LEAGUE).sort((a, b) => a.t - b.t);
    const ix = l15MakeIndex(saba);
    const rules = L15_MONITOR_DEFS.map(def => ({ ...def, events: [] }));
    const byId = new Map(rules.map(r => [r.id, r]));
    const recentDay = l15SiteDay(now);
    const todayMatches = new Set(), todayMarkets = new Set(), todayRules = [];
    for (const r of saba) for (const def of L15_MONITOR_DEFS) {
      const ch = l15Check(ix, saba, r, def);
      if (!ch.active) continue;
      const entry = {
        id: r.id, t: r.t, date: r.date, teams: r.teams, market: def.market,
        result: ch.result, fh: r.fh, ft: r.ft, prevId: ch.previous?.id || null, ruleId: def.id
      };
      byId.get(def.id).events.push(entry);
      if (l15SiteDay(r.t) === recentDay) {
        todayMatches.add(r.id);
        todayMarkets.add(r.id + '|' + def.market);
        todayRules.push(entry);
      }
    }
    for (const rule of rules) rule.stats = l15Summarize(rule.events, now);
    return {
      rules, byId, today: {
        matches: todayMatches.size, markets: todayMarkets.size, events: todayRules, day: recentDay,
        pending: todayRules.filter(e => e.result === null).length, triggers: todayRules.length,
        wins: todayRules.filter(e => e.result === 'BERHASIL').length, failed: todayRules.filter(e => e.result === 'TIDAK TERPENUHI').length
      },
      sessions: [
        {
          id: 'sessionFT', label: 'Sesi tepat 2 Under → FT O1.5', market: 'FT Over 1.5',
          stats: l15Summarize(sessionSignals(saba, 'FT').map(s => ({ t: s.targetTime, result: s.result === 'BERHASIL' || s.result === 'TIDAK TERPENUHI' ? s.result : null })), now)
        },
        {
          id: 'session2H', label: 'Sesi tepat 2 Under → gol 2H', market: '2H Over 0.5',
          stats: l15Summarize(sessionSignals(saba, '2H').map(s => ({ t: s.targetTime, result: s.result === 'BERHASIL' || s.result === 'TIDAK TERPENUHI' ? s.result : null })), now)
        }
      ], records: saba, ix
    };
  }

  function l15ProspectiveUpdate(report, visible, now = Date.now()) {
    let store = GM_getValue(L15_PROSPECTIVE_KEY, {});
    if (!store || typeof store !== 'object' || Array.isArray(store)) store = {};
    let changed = false;
    const byId = new Map(report.records.map(r => [r.id, r]));
    for (const { r } of visible) {
      if (recordLeague(r) !== LEAGUE || r.complete || !usablePending(r, now) || now > r.t) continue;
      for (const def of L15_MONITOR_DEFS) {
        const ch = l15Check(report.ix, report.records, r, def);
        if (!ch.active || !ch.previous || !validFinalScore(ch.previous)) continue;
        const prevKnown = ch.previous.completedSeenAt;
        if (!Number.isFinite(prevKnown) || prevKnown > now || prevKnown > r.t) continue;
        const key = def.id + '|' + r.id;
        if (!store[key]) {
          store[key] = {
            id: r.id, ruleId: def.id, market: def.market, matchTime: r.t,
            observedAt: now, previousId: ch.previous.id, previousCompletedSeenAt: prevKnown,
            teams: r.teams, date: r.date, result: null
          };
          changed = true;
        }
      }
    }
    for (const value of Object.values(store)) {
      if (!value || !value.id || !value.ruleId) continue;
      const r = byId.get(value.id);
      if (!r || !validFinalScore(r) || value.result !== null) continue;
      const def = L15_MONITOR_DEFS.find(q => q.id === value.ruleId);
      if (!def) continue;
      const ht = r.fh[0] + r.fh[1], ft = r.ft[0] + r.ft[1];
      const success = def.market === 'HT Over 0.5' ? ht >= 1 : def.market === '2H Over 0.5' ? ft - ht >= 1 : ft >= 2;
      value.result = success ? 'BERHASIL' : 'TIDAK TERPENUHI';
      changed = true;
    }
    if (changed) GM_setValue(L15_PROSPECTIVE_KEY, store);
    return Object.values(store).filter(e => e && e.ruleId);
  }

  function l15Dashboard(report, prospective) {
    const wrap = document.createDocumentFragment();
    const today = report.today;

    // Ringkasan Hari Ini Card
    const dailyCard = l15El('div', null, '', 'saba-card');
    dailyCard.append(l15El('div', `REKAP HARI INI • ${today.day} (GMT+8)`, 'font-weight:800;font-size:13px;color:#38bdf8'));
    dailyCard.append(l15El('div', `${today.triggers} pemicu • ${today.wins} sukses • ${today.failed} gagal • ${today.pending} tunggu (${today.matches} laga unik)`, '', 'saba-stat-bar'));

    for (const def of report.rules) {
      const entries = today.events.filter(e => e.ruleId === def.id).sort((a, b) => b.t - a.t);
      const success = entries.filter(e => e.result === 'BERHASIL').length;
      const failed = entries.filter(e => e.result === 'TIDAK TERPENUHI').length;
      const pending = entries.filter(e => e.result === null).length;

      const row = makeTrackedDetails(`l15-daily-${def.id}`);
      const summary = l15El('summary');
      summary.append(l15El('span', `${def.name} (${def.market})`));
      summary.append(l15El('span', `${success}S / ${failed}G / ${pending}T`, 'font-size:11px;color:#cbd5e1'));
      row.append(summary);

      const list = makeScrollList(`l15-daily-scroll-${def.id}`);
      for (const e of entries) {
        const label = e.result === 'BERHASIL' ? 'SUKSES' : e.result === 'TIDAK TERPENUHI' ? 'GAGAL' : 'MENUNGGU';
        const color = e.result === 'BERHASIL' ? '#6ee7b7' : e.result === 'TIDAK TERPENUHI' ? '#fca5a5' : '#fcd34d';
        list.append(l15El('div', `${e.date} | ${(e.teams || []).join(' v ')} | HT ${e.fh?.join('-') || '?'} → FT ${e.ft?.join('-') || '?'} | ${label}`, 'font-size:10px;padding:3px 0;color:' + color));
      }
      if (!entries.length) list.append(l15El('div', 'Belum ada pemicu hari ini.', 'font-size:10px;color:#94a3b8'));
      row.append(list);
      dailyCard.append(row);
    }
    wrap.append(dailyCard);

    // Monitor Kualitas Historis Card
    const qualCard = l15El('div', null, '', 'saba-card');
    qualCard.append(l15El('div', 'MONITOR KUALITAS HISTORIS L15', 'font-weight:800;font-size:13px;color:#38bdf8;margin-bottom:6px'));

    const controls = l15El('div', null, 'display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px');
    const panes = [];
    for (const [id, label] of [['priority', 'Prioritas'], ['watch', 'Pantau'], ['research', 'Riset'], ['all', 'Semua']]) {
      const btn = l15El('button', label, '', 'saba-btn' + (l15MonitorFilter === id ? ' active' : ''));
      btn.type = 'button';
      btn.onclick = () => {
        l15MonitorFilter = id;
        for (const p of panes) p.el.style.display = id === 'all' || p.group === id ? 'block' : 'none';
        for (const b of controls.querySelectorAll('button')) b.classList.toggle('active', b === btn);
      };
      controls.append(btn);
    }
    qualCard.append(controls);

    const winFmt = v => `${v.wins}/${v.n}${v.n ? ' (' + (100 * v.wins / v.n).toFixed(1) + '%)' : ''}`;
    for (const def of report.rules) {
      const stats = def.stats;
      const row = makeTrackedDetails(`l15-qual-${def.id}`);
      panes.push({ el: row, group: def.group });
      row.style.display = l15MonitorFilter === 'all' || l15MonitorFilter === def.group ? 'block' : 'none';

      const sum = l15El('summary');
      sum.append(l15El('span', def.name));
      sum.append(l15El('span', `${stats.wins}/${stats.finished.length} • ${def.market}`, 'color:#38bdf8;font-size:11px'));
      row.append(sum);

      row.append(l15El('div', `${L15_GROUP_NAMES[def.group]}${stats.warning ? ' • TREN TURUN' : ''}`, `font-size:10px;margin:3px 0;color:${stats.warning ? '#fca5a5' : '#94a3b8'}`));
      row.append(l15El('div', `7H: ${winFmt(stats.last7)}  |  30H: ${winFmt(stats.last30)}  |  10 Akhir: ${winFmt(stats.last10)}`, 'font-size:11px'));
      row.append(l15El('div', `Sesudah gagal: ${winFmt(stats.afterFail)} • Gagal ke-1 hari itu: ${winFmt(stats.afterFirstFail)}`, 'font-size:10px;color:#cbd5e1;margin:2px 0'));

      if (stats.failures.length) {
        row.append(l15El('div', 'Kegagalan Terbaru:', 'font-size:10px;font-weight:700;color:#fca5a5;margin-top:4px'));
        for (const e of stats.failures.slice(0, 5))
          row.append(l15El('div', `${e.date} • ${(e.teams || []).join(' v ')}`, 'font-size:10px;border-left:2px solid #ef4444;margin:3px 0;padding-left:6px;color:#fca5a5'));
      }
      qualCard.append(row);
    }

    const sess = makeTrackedDetails('l15-sess-summary');
    const sessSum = l15El('summary');
    sessSum.append(l15El('span', 'Pola Sesi (Analisis Kelompok)'));
    sess.append(sessSum);
    for (const group of report.sessions) {
      const x = group.stats;
      sess.append(l15El('div', `${group.label}: ${x.wins}/${x.finished.length} (7H: ${x.last7.wins}/${x.last7.n} • 30H: ${x.last30.wins}/${x.last30.n})`, 'font-size:11px;margin:4px 0'));
    }
    qualCard.append(sess);

    const known = prospective.filter(p => p.result !== null);
    const won = known.filter(p => p.result === 'BERHASIL').length;
    const live = makeTrackedDetails('l15-prospective-log');
    const liveSum = l15El('summary');
    liveSum.append(l15El('span', 'LOG PRA-KICKOFF L15'));
    liveSum.append(l15El('span', `${won}/${known.length} selesai • ${prospective.length - known.length} tunggu`, 'color:#6ee7b7;font-size:11px'));
    live.append(liveSum);

    const liveList = makeScrollList('l15-prospective-scroll');
    const sorted = [...prospective].sort((a, b) => b.observedAt - a.observedAt);
    for (const p of sorted.slice(0, 15)) {
      const def = report.byId.get(p.ruleId);
      liveList.append(l15El('div', `${new Date(p.observedAt).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' })} WIB • ${def?.name || p.ruleId} • ${(p.teams || []).join(' v ')} • ${p.result || 'MENUNGGU'}`, 'font-size:10px;border-bottom:1px solid #334155;padding:4px 0'));
    }
    if (!sorted.length) liveList.append(l15El('div', 'Belum ada sinyal pra-kickoff yang terekam.', 'font-size:10px;color:#94a3b8'));
    live.append(liveList);
    qualCard.append(live);

    wrap.append(qualCard);
    return wrap;
  }

  function otherLeagueDaily(records, league, now = Date.now()) {
    if (league === BELGIUM_LEAGUE) {
      const report=l16BuildEvents(records);
      const today=l16SessionDay(now);
      return {today, specs:L16_RANKED_RULES.map(rule=>({
        ...rule, entries:report.events.filter(e=>e.rule.n===rule.n && l16SessionDay(e.r.t)===today)
          .map(e=>({r:e.r,check:e.check})).reverse()
      }))};
    }
    const history = records.filter(r => recordLeague(r) === league).sort((a, b) => a.t - b.t);
    const today = l15SiteDay(now);
    const goalResult = ch => ({
      ...ch, result: ch.active && Number.isInteger(ch.goals) && ch.goals >= 0
        ? (ch.goals >= 1 ? 'BERHASIL' : 'TIDAK TERPENUHI') : null
    });
    const specs = [...ALGERIA_RULES.map(rule=>({...rule,evaluate:r=>algeriaEvaluation(history,r,rule)})),
      ...L20_NEW_RULES.map(rule=>({...rule,evaluate:r=>l20Evaluate(l20MakeIndex(history),r,rule)}))];
    return {
      today, specs: specs.map(spec => ({
        ...spec, entries: history.filter(r => l15SiteDay(r.t) === today)
          .map(r => ({ r, check: spec.evaluate(r) })).filter(e => e.check.active).reverse()
      }))
    };
  }

  function l16ElOverview(records) {
    const root=l15El('div',null,'','saba-card');
    root.append(l15El('div','LEAGUE 16 • 41 POLA AKTIF • RANKING 42 POLA • v1.33',
      'font-weight:800;font-size:12px;color:#6ee7b7'));
    root.append(l15El('div','Nonaktif: 09, 17, 18, 19, 21 (Ghana HT; tetap ditampilkan pada ranking referensi), 23. Satu sinyal pada ID31 dihitung per slot dua pertandingan. Riwayat master di bawah berasal dari backtest sementara, bukan jaminan.',
      'font-size:10px;color:#cbd5e1;margin-top:4px'));
    const w=L16_ACTIVE_RULES.reduce((s,r)=>s+r.win,0), f=L16_ACTIVE_RULES.reduce((s,r)=>s+r.fail,0);
    root.append(l15El('div',`MASTER SEMENTARA • ${w} WIN / ${f} FAIL • ${(100*w/(w+f)).toFixed(1)}% • ${L16_ACTIVE_RULES.length} pola`,
      'font-size:11px;font-weight:700;color:#7dd3fc;margin-top:6px'));
    // Kamus kode: nama penuh untuk semua 41 pola AKTIF. Bisa dicari tanpa
    // meninggalkan SABA Monitor, dan tidak menambah tab League 15/20.
    if (!l16GlossaryInitialized) {
      openDetailsSet.add('l16-code-glossary');
      l16GlossaryInitialized = true;
    }
    const codeGuide=makeTrackedDetails('l16-code-glossary');
    codeGuide.append(l15El('summary', '📖 DAFTAR KODE L16-xx • 41 POLA AKTIF (ketuk untuk tutup/buka)'));
    codeGuide.append(l15El('div', 'L16-37 = Netherlands HT ≥1; Home Previous 2H ≥2 dan FT ≥3; Away Previous 2H ≤2. Kode tetap sama meskipun urutan ranking berubah.',
      'font-size:10px;color:#fcd34d;margin:5px 0'));
    const codeSearch=l15El('input');
    codeSearch.type='search';
    codeSearch.placeholder='Cari kode, mis. L16-37 / Netherlands / Italy';
    codeSearch.value=l16CodeSearch;
    codeSearch.setAttribute('aria-label','Cari kode atau nama pola League 16');
    codeSearch.style.cssText='box-sizing:border-box;width:100%;background:#0f172a;color:#f8fafc;border:1px solid #475569;border-radius:6px;padding:6px;font-size:11px;margin:4px 0 5px';
    codeGuide.append(codeSearch);
    const codeList=makeScrollList('l16-code-glossary-scroll');
    codeList.style.maxHeight='240px';
    const codeRows=[];
    for (const def of L16_ACTIVE_RULES) {
      const entry=l15El('div',`${def.id} • ${def.name}`,
        'font-size:11px;padding:5px 2px;border-bottom:1px solid #334155;color:#e2e8f0');
      codeList.append(entry);
      codeRows.push({entry, value:(def.id+' '+def.name).toLocaleLowerCase('id-ID')});
    }
    const applyCodeSearch=()=>{
      const q=l16CodeSearch.trim().toLocaleLowerCase('id-ID');
      for (const item of codeRows) item.entry.style.display=item.value.includes(q)?'block':'none';
    };
    codeSearch.addEventListener('input',()=>{l16CodeSearch=codeSearch.value;applyCodeSearch();});
    applyCodeSearch();
    codeGuide.append(codeList);
    root.append(codeGuide);
    const details=makeTrackedDetails('l16-master-rank');
    const summary=l15El('summary','Ranking akurasi 42 pola (41 aktif + 1 referensi)'); details.append(summary);
    const list=makeScrollList('l16-master-rank-scroll');
    const ranked = L16_RANKED_ALL;
    list.append(l15El('div','RANK | NAMA POLA | WIN | FAIL | TOTAL | AKURASI',
      'font-size:10px;font-weight:800;color:#7dd3fc;padding:4px 2px;border-bottom:1px solid #475569'));
    for (const [index,r] of ranked.entries()) {
      const total=r.win+r.fail;
      const accuracy=(100*r.win/total).toFixed(1).replace('.',',')+'%';
      const entry=l15El('div',null,
        'font-size:10px;padding:5px 3px;border-bottom:1px solid #334155;line-height:1.5'+
        (r.reference?';color:#fcd34d':''));
      entry.append(l15El('div',`${index+1}. ${r.id} • ${r.name}${r.reference?' [REFERENSI / NONAKTIF]':''}`,
        'font-weight:650'));
      entry.append(l15El('div',`${r.win} WIN  •  ${r.fail} FAIL  •  ${total} TOTAL  •  ${accuracy}`,
        'color:#cbd5e1;font-variant-numeric:tabular-nums'));
      list.append(entry);
    }
    details.append(list);root.append(details);
    return root;
  }

  function otherLeagueDailyPanel(records, league, label) {
    const report = otherLeagueDaily(records, league);
    const root = l15El('div', null, '', 'saba-card');
    root.append(l15El('div', `${label} • REKAP HARI INI (${report.today})`, 'font-weight:800;font-size:13px;color:#38bdf8;margin-bottom:4px'));
    if (league === BELGIUM_LEAGUE) root.append(l15El('div',
      'Diurutkan berdasarkan AKURASI MASTER tertinggi → terendah. Ghana ID21 hanya ada di tabel ranking referensi (nonaktif).',
      'font-size:10px;color:#cbd5e1;margin-bottom:5px'));

    for (const spec of report.specs) {
      const success = spec.entries.filter(e => e.check.result === 'BERHASIL').length;
      const failed = spec.entries.filter(e => ['GAGAL', 'TIDAK TERPENUHI'].includes(e.check.result)).length;
      const pending = spec.entries.length - success - failed;

      const row = makeTrackedDetails(`other-${league}-${spec.id || spec.name}`);
      const summary = l15El('summary');
      const rank = league === BELGIUM_LEAGUE ? L16_RANK_BY_ID.get(spec.id) : null;
      const accuracy = league === BELGIUM_LEAGUE && Number.isFinite(spec.win) && Number.isFinite(spec.fail)
        ? ` • ${(100 * l16Accuracy(spec)).toFixed(1).replace('.',',')}%` : '';
      summary.append(l15El('span', `${rank ? '#'+rank+' • ' : ''}${spec.id ? spec.id+' • ' : ''}${spec.name} (${spec.market})${accuracy}`));
      summary.append(l15El('span', `${success}S / ${failed}G / ${pending}T`, 'color:#cbd5e1;font-size:11px'));
      row.append(summary);

      const list = makeScrollList(`other-scroll-${league}-${spec.id || spec.name}`);
      for (const { r, check } of spec.entries) {
        const status = check.result === 'BERHASIL' ? 'SUKSES' : ['GAGAL', 'TIDAK TERPENUHI'].includes(check.result) ? 'GAGAL' : 'MENUNGGU';
        const color = status === 'SUKSES' ? '#6ee7b7' : status === 'GAGAL' ? '#fca5a5' : '#fcd34d';
        list.append(l15El('div', `${r.date} | ${check.matchLabel || (r.teams || []).join(' v ')} | ${check.scoreLabel || `HT ${r.fh?.join('-') || '?'} → FT ${r.ft?.join('-') || '?'}`} | ${status}`, 'font-size:10px;padding:3px 0;color:' + color));
      }
      if (!spec.entries.length) list.append(l15El('div', 'Belum ada pemicu hari ini.', 'font-size:10px;color:#94a3b8'));
      row.append(list);
      root.append(row);
    }
    return root;
  }

  // Konfigurasi Kotak Utama UI
  const box = document.createElement('section');
  box.id = 'saba-monitor-box';
  const patternTableHost = document.getElementById('pattern-table-host');
  if (!patternTableHost) document.body.append(box);
  let l15LastRenderLines = [];
  let patternLeague = '16';
  let patternSortColumn = 'accuracy';
  let patternSortDirection = 'desc';
  const patternDayFormatter = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' });
  const patternRecordDay = r => r.patternDay || (r.patternDay = r.league === BELGIUM_LEAGUE
    ? l16SessionDay(r.t) : patternDayFormatter.format(new Date(r.t)));
  let patternMatchDate = l16SessionDay(Date.now());
  let patternDateExplicit = false;
  let patternAllDates = false;
  let patternVisibleMatches = 100;
  let patternStatsRange = 'all';
  let patternSelectedRule = null;
  let patternSearch = '';
  const patternRuleMatches = new Map();
  const patternStatsDay = () => patternLeague === '16' ? l16SessionDay(Date.now()) : patternDayFormatter.format(new Date());
  function patternStatsIncludes(record) {
    if (patternStatsRange === 'all') return true;
    const day = patternRecordDay(record);
    if (patternStatsRange === 'date') return day === patternMatchDate;
    const end = patternStatsDay();
    const start = new Date(Date.parse(end + 'T00:00:00Z') - (patternStatsRange === 'month' ? 29 : 6) * 86400000).toISOString().slice(0, 10);
    return day >= start && day <= end;
  }
  let patternTableLines = [];
  let patternCsvRecords = null;
  let patternCsvStatus = 'Memuat matches.csv…';
  const patternCsvStats = new Map();
  const patternLeagueRecords = new Map();
  const patternEvaluatedRules = new Map();
  const patternTriggerIndexes = new Map();
  let patternRadarCache = null, patternRadarTimer = null;
  let patternL16Events = null;
  let patternCacheDb = null, patternCacheVersion = null, patternCacheTimer = null;
  const patternCacheRequest = request => new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  function patternParseRows(rows) {
    const pair = (a, b) => /^\d+$/.test(a) && /^\d+$/.test(b) ? [+a, +b] : null;
    return rows.map((r, i) => {
        const teams = [r.home_team, r.away_team];
        const fh = pair(r.fh_home, r.fh_away), ft = pair(r.ft_home, r.ft_away);
        const sourceOffset = r.source_utc_offset === '+08:00' ? '+08:00' : r.league === BELGIUM_LEAGUE ? '+08:00' : '+07:00';
        const t = Date.parse(r.match_time.replace(' ', 'T') + sourceOffset);
        return { id: String(i), league: r.league, t, teams, fh, ft,
          side: teams.findIndex(isLiverpool), psgSide: teams.findIndex(isPSG),
          complete: !!ft, total: ft ? ft[0] + ft[1] : null };
      }).filter(r => Number.isFinite(r.t)).sort((a, b) => a.t - b.t);
  }
  let patternHistoryCheckAt = 0, patternHistoryReloading = false;
  async function refreshPatternHistory(force = false) {
    if (!patternCsvRecords || patternHistoryReloading || (!force && Date.now()-patternHistoryCheckAt < 30000)) return false;
    patternHistoryReloading = true;
    patternHistoryCheckAt = Date.now();
    try {
      const response = await fetch('pattern-data.php?version=1', {cache:'no-store'});
      if (!response.ok) throw new Error('Tidak bisa memeriksa versi CSV');
      const meta = await response.json();
      if (!meta.version || meta.version === patternCacheVersion) return false;
      const dataResponse = await fetch('pattern-data.php', {cache:'no-store'});
      const data = await dataResponse.json();
      if (!dataResponse.ok || !Array.isArray(data.rows)) throw new Error(data.error || 'Riwayat CSV belum dapat dimuat ulang');
      const records = patternParseRows(data.rows);
      patternCsvRecords = records;
      patternCacheVersion = meta.version;
      patternCsvStats.clear(); patternLeagueRecords.clear(); patternEvaluatedRules.clear(); patternTriggerIndexes.clear();
      patternL16Events = null; patternRadarCache = null;
      patternCsvStatus = records.length + ' pertandingan · Riwayat CSV diperbarui otomatis.';
      schedulePatternCache();
      renderPatternTable(patternTableLines);
      return true;
    } finally { patternHistoryReloading = false; }
  }
  async function savePatternCache() {
    if (!patternCacheDb || !patternCsvRecords) return;
    try {
      const tx = patternCacheDb.transaction('cache', 'readwrite');
      tx.objectStore('cache').put({ version: patternCacheVersion, records: patternCsvRecords,
        evaluated: [...patternEvaluatedRules], l16: patternL16Events }, 'snapshot');
    } catch (_) { /* Cache is optional; the CSV remains the source. */ }
  }
  function schedulePatternCache() {
    clearTimeout(patternCacheTimer);
    patternCacheTimer = setTimeout(savePatternCache, 300);
  }
  if (patternTableHost) {
    (async () => {
      const versionResponse = await fetch('pattern-data.php?version=1', { cache: 'no-store' });
      const metadata = await versionResponse.json();
      patternCacheVersion = metadata.version;
      try {
        const request = indexedDB.open('sabaraja-pattern-cache', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('cache');
        patternCacheDb = await patternCacheRequest(request);
        const cached = await patternCacheRequest(patternCacheDb.transaction('cache').objectStore('cache').get('snapshot'));
        if (metadata.version && cached?.version === metadata.version) {
          patternCsvRecords = cached.records;
          for (const [key, value] of cached.evaluated) patternEvaluatedRules.set(key, value);
          patternL16Events = cached.l16;
          patternCsvStatus = patternCsvRecords.length + ' pertandingan · Cache browser sesuai versi CSV terbaru.';
          return;
        }
      } catch (_) { /* Continue without persistent caching if unavailable. */ }
      const response = await fetch('pattern-data.php', { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Gagal membaca matches.csv');
      patternCsvRecords = patternParseRows(data.rows);
      patternCsvStatus = patternCsvRecords.length + ' pertandingan dari matches.csv · League 15/20: WIB, 00:00–23:59 · League 16: sesi SABA UTC+8.';
      schedulePatternCache();
    })().catch(error => { patternCsvStatus = error.message; }).finally(() => renderPatternTable(patternTableLines));
  }

  const EXTRA_RESEARCH_RULES = [
    {id:'L15-UJI-MU-FT',league:'15',team:'Manchester United',venue:'any',experimental:true,name:'UJI • Manchester United + Away Previous HT Tepat 1 + Total 2H ≥3',market:'FT Over 1.5',conditionType:'mu'},
    {id:'L15-UJI-PSG-2H',league:'15',team:'Paris Saint Germain',venue:'any',experimental:true,name:'UJI • PSG + HT Imbang + Away Previous Own HT ≥2',market:'2H Over 0.5',conditionType:'psg'},
    {id:'L15-UJI-CHE-2H',league:'15',team:'Chelsea',venue:'away',experimental:true,name:'UJI • Chelsea Tandang + HT Imbang + Previous Total HT ≤1',market:'2H Over 0.5',conditionType:'chelsea'},
    {id:'L15-UJI-RMA-2H',league:'15',team:'Real Madrid',venue:'away',experimental:true,name:'UJI • Real Madrid Tandang + HT Imbang + Previous FT ≤2',market:'2H Over 0.5',conditionType:'madrid'},
    {id:'L20-UJI-QAT-2H',league:'20',team:'Qatar',venue:'any',experimental:true,name:'UJI • Qatar + Home Previous Own HT ≤2 + Total 2H ≥3',market:'2H Over 0.5',conditionType:'qatar'}
  ];
  const extraResearchIndexes = new WeakMap();
  function extraResearchEvaluate(records,target,rule) {
    const key = name => String(name).replace(/\s*\(V\)/gi,'').trim().toLowerCase();
    const teams=(target.teams || []).map(key);
    const expectedLeague=rule.league==='15'?LEAGUE:ALGERIA_LEAGUE;
    if(target.league!==expectedLeague || !teams.includes(key(rule.team)) || (rule.venue==='away' && teams[1]!==key(rule.team)))return {active:false};
    if(rule.conditionType!=='mu' && (!Array.isArray(target.fh) || target.fh.length!==2))return {active:false};
    if(!['qatar','mu'].includes(rule.conditionType) && target.fh[0]!==target.fh[1])return {active:false};
    let index=extraResearchIndexes.get(records);
    if(!index){
      index=new Map();
      for(const r of records)if(r.league===expectedLeague && validFinalScore(r))for(const team of r.teams){const k=key(team);if(!index.has(k))index.set(k,[]);index.get(k).push(r);}
      for(const pool of index.values())pool.sort((a,b)=>a.t-b.t);
      extraResearchIndexes.set(records,index);
    }
    const side=rule.conditionType==='qatar'?0:1;
    const previous=latestBefore(index.get(teams[side]) || [],target.t);
    if(!previous || target.t-previous.t<30*60000 || target.t-previous.t>3*3600000)return {active:false};
    const ht=previous.fh[0]+previous.fh[1],ft=previous.ft[0]+previous.ft[1];
    const own=previous.teams.findIndex(t=>key(t)===teams[side]);
    const active=rule.conditionType==='psg'?previous.fh[own]>=2:rule.conditionType==='chelsea'?ht<=1:rule.conditionType==='madrid'?ft<=2:rule.conditionType==='mu'?ht===1 && ft-ht>=3:previous.fh[own]<=2 && ft-ht>=3;
    const achieved=validFinalScore(target) && (rule.conditionType==='mu'?target.ft[0]+target.ft[1]>=2:target.ft[0]+target.ft[1]-target.fh[0]-target.fh[1]>=1);
    return {active,previous,result:active && validFinalScore(target)?(achieved?'BERHASIL':'TIDAK TERPENUHI'):null};
  }
  function csvRuleStats(rule) {
    if (patternCsvRecords === null) return { win: null, fail: null, entries: [], active: [] };
    const key = patternLeague + ':' + patternStatsRange + ':' + (patternStatsRange === 'date' ? patternMatchDate : patternStatsDay()) + ':' + rule.id;
    if (patternCsvStats.has(key)) {
      const cached = patternCsvStats.get(key);
      cached.active = cached.active.filter(e => Date.now() - e.r.t <= 45 * 60000);
      return cached;
    }
    const entries = [], active = [];
    const collect = (r, result, group) => {
      const entry = { r, result, group };
      if (!r.complete && result == null && Date.now() >= r.t && Date.now() - r.t <= 45 * 60000) active.push(entry);
      if (patternStatsIncludes(r)) entries.push(entry);
    };
    const matchedIds = new Set();
    patternRuleMatches.set(key, matchedIds);
    if (patternLeague === '16') {
      if (patternL16Events === null) {
        patternL16Events = l16BuildEvents(patternCsvRecords).events;
        schedulePatternCache();
      }
      let win = 0, fail = 0;
      for (const event of patternL16Events) {
        if (event.rule.id !== rule.id) continue;
        collect(event.r, event.check.result, event.group);
        if (!patternStatsIncludes(event.r)) continue;
        for (const match of event.group || [event.r]) matchedIds.add(match.id);
        if (event.check.result === 'BERHASIL') win++;
        else if (event.check.result === 'TIDAK TERPENUHI') fail++;
      }
      const stats = { win, fail, entries, active };
      patternCsvStats.set(key, stats);
      return stats;
    }
    const leagueName = patternLeague === '15' ? LEAGUE : ALGERIA_LEAGUE;
    if (!patternLeagueRecords.has(leagueName)) patternLeagueRecords.set(leagueName, patternCsvRecords.filter(r => r.league === leagueName));
    const records = patternLeagueRecords.get(leagueName);
    const evaluationKey = patternLeague + ':' + rule.id;
    if (!patternEvaluatedRules.has(evaluationKey)) {
      const index = patternLeague === '20' ? l20MakeIndex(records) : null;
      let pool = records, evaluate;
      if (EXTRA_RESEARCH_RULES.some(r=>r.id===rule.id)) {
        pool=records.filter(r=>r.teams.some(t=>extraTeam(t)===rule.team.toLowerCase()));
        evaluate=r=>extraResearchEvaluate(records,r,rule);
      } else if (patternLeague === '20') {
        pool = records.filter(r => r.teams.some(t => extraTeam(t) === (rule.team || 'Algeria').toLowerCase()));
        evaluate = ALGERIA_RULES.some(r => r.id === rule.id)
          ? r => algeriaEvaluation(records, r, rule) : r => l20Evaluate(index, r, rule);
      } else if (LIVERPOOL_RULES.some(r => r.id === rule.id)) {
        pool = records.filter(r => r.side >= 0);
        evaluate = r => liverpoolEvaluation(pool, r, rule);
      } else if (PSG_RULES.some(r => r.id === rule.id)) {
        pool = records.filter(r => r.psgSide >= 0);
        evaluate = r => psgRuleEvaluation(pool, r, rule);
      } else if (AWAY_HT_RULES.some(r => r.id === rule.id)) {
        pool = records.filter(r => awayTeamSide(r, rule) >= 0);
        evaluate = r => awayHTEvaluation(pool, r, rule);
      } else {
        pool = records.filter(r => league15TeamSide(r, rule.team) >= 0);
        evaluate = r => newLeague15Evaluation(records, r, rule);
      }
      const evaluated = [];
      for (const target of pool) {
        const check = evaluate(target);
        if (check.active) evaluated.push({ target, result: check.result });
      }
      patternEvaluatedRules.set(evaluationKey, evaluated);
      schedulePatternCache();
    }
    let win = 0, fail = 0;
    for (const { target, result } of patternEvaluatedRules.get(evaluationKey)) {
      collect(target, result);
      if (!patternStatsIncludes(target)) continue;
      matchedIds.add(target.id);
      if (result === 'BERHASIL') win++;
      else if (['GAGAL', 'TIDAK TERPENUHI'].includes(result)) fail++;
    }
    const stats = { win, fail, entries, active };
    patternCsvStats.set(key, stats);
    return stats;
  }

  function l16DeadSlotMoment(history, target) {
    if (!target.preciseTime) return null;
    const previousTime = target.t - 15*60000;
    const unique = new Map();
    for (const row of history) {
      if (row.t !== previousTime || !row.complete || !row.fh || !validFinalScore(row)) continue;
      unique.set(row.teams.map(l16Key).join('|'), row);
    }
    const slot = [...unique.values()];
    // League 16 uses two fixtures per slot; a partial slot is not a dry slot.
    if (slot.length !== 2) return null;
    const ft = slot.reduce((sum,r) => sum+r.ft[0]+r.ft[1],0);
    const secondHalf = slot.reduce((sum,r) => sum+r.ft[0]+r.ft[1]-r.fh[0]-r.fh[1],0);
    if (secondHalf !== 0 && ft > 1) return null;
    const firstHalf = target.phase === '1H';
    const total = target.currentScore ? target.currentScore[0]+target.currentScore[1] : null;
    const goals = firstHalf ? total : total !== null && target.fh ? total-target.fh[0]-target.fh[1] : null;
    return {id:'L16-DSR', name:'Dead Slot Rebound', experimental:true,
      market:firstHalf ? 'HT Over 0.5' : '2H Over 0.5',
      state:goals === null ? 'waiting' : goals > 0 ? 'achieved' : 'watch',
      previousSlot:new Date(previousTime).toISOString(),
      totalFT:ft, total2H:secondHalf, samples:slot.length};
  }

  function l16AfterL1(events, ruleId, targetTime) {
    const settled = events.filter(event => event.rule.id === ruleId &&
      event.r.t >= targetTime-30*86400000 && event.r.t < targetTime &&
      (event.group || [event.r]).every(r => r.t < targetTime && validFinalScore(r)) &&
      ['BERHASIL','TIDAK TERPENUHI'].includes(event.check.result))
      .sort((a,b) => a.r.t-b.r.t);
    const total = settled.length;
    const wins = settled.filter(event => event.check.result === 'BERHASIL').length;
    if (total < 40 || wins/total < 0.92 ||
      settled.at(-1)?.check.result !== 'TIDAK TERPENUHI' || settled.at(-2)?.check.result !== 'BERHASIL') return null;
    return {total,wins,accuracy:100*wins/total,lastFail:new Date(settled.at(-1).r.t).toISOString()};
  }

  function l16AdditionalMoments(history, target, signals, events, afterL1Events = events) {
    if (!target.preciseTime) return [];
    const moments = [];
    const previous = new Map();
    for (const row of history) if (row.t === target.t-15*60000 && validFinalScore(row)) previous.set(row.teams.map(l16Key).join('|'), row);
    const slot = [...previous.values()];
    if (slot.length === 2) {
      const ft = slot.reduce((sum,r) => sum+r.ft[0]+r.ft[1],0);
      const ht = slot.reduce((sum,r) => sum+r.fh[0]+r.fh[1],0);
      if (ft-ht >= 3) {
        const first = target.phase === '1H';
        const total = target.currentScore ? target.currentScore[0]+target.currentScore[1] : null;
        const goals = first ? total : total !== null && target.fh ? total-target.fh[0]-target.fh[1] : null;
        moments.push({id:'L16-PEC',name:'Post-Explosion Carryover',market:first ? 'HT Over 0.5' : '2H Over 0.5',
          state:goals === null ? 'waiting' : goals > 0 ? 'achieved' : 'watch',
          detail:`Slot sebelumnya: 2 laga selesai · HT ${ht} · FT ${ft} · gol 2H ${ft-ht} (ambang ≥3).`});
      }
    }
    for (const signal of signals.filter(s => s.state === 'active')) {
      const stats = l16AfterL1(afterL1Events, signal.id, target.t);
      if (stats) moments.push({id:'L16-L1:'+signal.id,name:'Momen Entry (After L1)',market:signal.market,state:'watch',
        detail:`${signal.id} · 30 hari sebelum kickoff: ${stats.wins}/${stats.total} (${stats.accuracy.toFixed(1)}%) · hasil terakhir W–L, pemicu muncul kembali.`,stats});
    }
    const minute = target.phaseMinute;
    const tight = target.fh && target.fh.every(g => g === 0 || g === 1);
    const early = target.phase === 'HT' || (target.phase === '2H' && Number.isFinite(minute) && minute <= 2);
    const active2H = signals.filter(s => s.market === '2H Over 0.5' && s.state === 'active');
    const total = target.currentScore ? target.currentScore[0]+target.currentScore[1] : null;
    if (early && tight && total !== null && total === target.fh[0]+target.fh[1] && active2H.length) {
      moments.push({id:'L16-HTT',name:'Half-Time Trap',market:'2H Over 0.5',state:'watch',
        detail:`HT ${target.fh.join('–')} · belum ada gol 2H · pemicu aktif: ${active2H.map(s=>s.id).join(', ')}.`});
    }
    return moments;
  }

  function l16EvaluateLive(payload, now = Date.now()) {
    const output = { ready: patternCsvRecords !== null, matches: [], activeRuleIds: [], note: '' };
    const elapsedAge = payload.fetchedAt ? Math.max(0, (now - payload.fetchedAt) / 1000) : 0;
    if (!payload.online || !payload.fresh || payload.age == null || payload.age + elapsedAge > 30) {
      output.note = 'Sinyal dinonaktifkan: sumber live offline atau data sudah lama.';
      return output;
    }
    if (!output.ready) { output.note = 'Menunggu riwayat CSV untuk mengecek pemicu pola.'; return output; }
    const parseScore = text => {
      const match = String(text || '').match(/^(\d+)\s*[-:–]\s*(\d+)$/);
      return match ? [+match[1], +match[2]] : null;
    };
    const history = patternCsvRecords.filter(r => r.league === BELGIUM_LEAGUE && r.t + 16 * 60000 <= now && validFinalScore(r));
    const targets = [];
    for (const [index, match] of (payload.matches || []).entries()) {
      const status = String(match.status || '').trim();
      const minute = status.match(/^([12])H\s+(\d+)/i);
      const isHT = /^(?:H\.?\s*Time|HT$|Half[ -]?Time)/i.test(status);
      const phase = isHT ? 'HT' : minute ? minute[1] + 'H' : null;
      if (!phase) continue;
      const teams = [match.home, match.away];
      const totalScore = parseScore(match.score);
      let ht = phase === '1H' ? null : parseScore(match.ht);
      const approximateMinutes = phase === 'HT' ? 8 : +minute[2] + (phase === '2H' ? 8 : 0);
      const matchingCsv = patternCsvRecords.filter(r => r.league === BELGIUM_LEAGUE &&
        r.t <= now && now - r.t <= 25 * 60000 &&
        Math.abs((now-r.t)/60000 - approximateMinutes) <= 2 &&
        r.teams.every((team,i) => l16Key(team) === l16Key(teams[i]))).sort((a,b) => b.t-a.t)[0];
      const api = typeof window !== 'undefined' ? window.SabarajaPatternResults : null;
      const apiAge = api ? now - (api.fetchedAt ?? api.loadedAt ?? 0) : Infinity;
      const apiIsForToday = api && (!api.date || api.date === l16CalendarDate(now));
      const apiCandidates = api && apiIsForToday && apiAge >= 0 && apiAge <= 120000 ? (api.fixtures || []).filter(f => {
        const t = Date.parse(f.time);
        const elapsed = (now-t)/60000;
        // Use only the same ordered, still-scheduled fixture shown in Results.
        // A live clock can begin late, so allow a bounded start delay.
        return f.status === 'Belum selesai' && Number.isFinite(t) && t <= now && elapsed <= 35 &&
          elapsed-approximateMinutes >= -2 && elapsed-approximateMinutes <= 12 &&
          [f.home,f.away].every((team,i) => l16Key(team) === l16Key(teams[i]));
      }) : [];
      const apiFixture = apiCandidates.length === 1 ? apiCandidates[0] : null;
      if (!ht && phase !== '1H' && apiFixture) ht = parseScore(apiFixture.ht);
      const t = apiFixture ? Date.parse(apiFixture.time) : matchingCsv?.t ?? now - approximateMinutes * 60000;
      targets.push({ id: 'live:' + index, league: BELGIUM_LEAGUE, t, teams, fh: ht, ft: null,
        complete: false, phase, phaseMinute:minute ? +minute[2] : null, currentScore: totalScore, preciseTime: !!apiFixture || !!matchingCsv,
        kickoffSource: apiFixture ? 'API' : matchingCsv ? 'CSV' : null, index });
    }
    // Exclude current fixtures from completed history even if CSV already contains a result.
    const merged = history.filter(r => !targets.some(t => t.teams.every((team,i) => l16Key(team) === l16Key(r.teams[i])) && Math.abs(t.t-r.t) < 10*60000));
    const report = l16BuildEvents([...merged, ...targets]);
    const earliestTarget = targets.length ? Math.min(...targets.map(target => target.t)) : now;
    const afterL1History = patternCsvRecords.filter(r => r.league === BELGIUM_LEAGUE &&
      r.t >= earliestTarget-30*86400000-6*3600000 && r.t < now && validFinalScore(r));
    const afterL1Report = l16BuildEvents(afterL1History);
    const slotRules = new Set([27,30,31,33,35,38,39]);
    for (const target of targets) {
      const signals = [];
      for (const event of report.events) {
        if (!(event.group || [event.r]).some(r => r.id === target.id)) continue;
        const rule = event.rule;
        if (!target.preciseTime && (slotRules.has(rule.n) || rule.condition?.includes('slot '))) continue;
        const firstHalf = rule.market.startsWith('HT');
        const secondHalf = rule.market.startsWith('2H');
        if (firstHalf && target.phase !== '1H') continue;
        if (secondHalf && (!['HT','2H'].includes(target.phase) || !target.fh)) continue;
        const line = Number(rule.market.split(' ').at(-1));
        const currentTotal = target.currentScore ? target.currentScore[0] + target.currentScore[1] : null;
        const goals = secondHalf && currentTotal !== null && target.fh ? currentTotal-target.fh[0]-target.fh[1] : currentTotal;
        if (goals !== null && goals < 0) continue;
        const achieved = goals !== null && goals > line;
        const settled = afterL1Report.events.filter(previous => previous.rule.id === rule.id &&
          previous.r.t < target.t && previous.r.t >= target.t-30*86400000 &&
          ['BERHASIL','TIDAK TERPENUHI'].includes(previous.check.result));
        const wins = settled.filter(previous => previous.check.result === 'BERHASIL').length;
        signals.push({ id: rule.id, name: rule.name, market: rule.market, experimental: !!rule.experimental,
          accuracy: settled.length ? 100*wins/settled.length : null, samples:settled.length,
          target: firstHalf ? 'Gol babak pertama' : secondHalf ? 'Gol babak kedua' : 'Total gol pertandingan',
          state: achieved ? 'achieved' : 'active' });
        if (!achieved) output.activeRuleIds.push(rule.id);
      }
      output.matches.push({ index: target.index, signals, preciseTime: target.preciseTime, kickoffSource:target.kickoffSource,
        kickoff:target.preciseTime ? new Date(target.t).toISOString() : null, htAvailable: !!target.fh, phase: target.phase,
        moment:l16DeadSlotMoment(merged, target), moments:l16AdditionalMoments(merged, target, signals, report.events, afterL1Report.events) });
    }
    const latest = history.reduce((max,r) => Math.max(max,r.t),0);
    output.note = latest && now-latest <= 3*3600000
      ? 'Pemicu dari riwayat CSV + skor live. Sinyal adalah kondisi pola, bukan jaminan gol.'
      : 'Riwayat CSV 3 jam terakhir belum tersedia; pola yang memerlukan laga/slot sebelumnya mungkin belum dapat dipicu.';
    return output;
  }
  let scheduledMomentCache = null;
  function l16ScanUpcoming(now = Date.now()) {
    const api = typeof window !== 'undefined' ? window.SabarajaPatternResults : null;
    const apiAge = api ? now - (api.fetchedAt ?? api.loadedAt ?? 0) : Infinity;
    if (!api || apiAge<0 || apiAge>120000 || (api.date && api.date!==l16CalendarDate(now)) || !patternCsvRecords) return [];
    const key = api.fetchedAt+':'+patternCacheVersion;
    if (scheduledMomentCache?.key===key && now-scheduledMomentCache.at<30000) return scheduledMomentCache.rows.filter(r=>r.t>now);
    const history=patternCsvRecords.filter(r=>r.league===BELGIUM_LEAGUE && validFinalScore(r) && r.t+16*60000<=now);
    const targets=api.fixtures.filter(f=>f.status==='Belum selesai' && Date.parse(f.time)>now && Date.parse(f.time)<=now+45*60000)
      .map((f,i)=>({id:'upcoming:'+i,league:BELGIUM_LEAGUE,t:Date.parse(f.time),teams:[f.home,f.away],fh:null,ft:null,complete:false}));
    const report=l16BuildEvents([...history,...targets]);
    const rows=targets.flatMap(target=>{
      const rules=report.events.filter(e=>(e.group||[e.r]).some(r=>r.id===target.id)).map(e=>({id:e.rule.id,market:e.rule.market}));
      return rules.length?[{t:target.t,teams:target.teams,rules}]:[];
    });
    scheduledMomentCache={key,at:now,rows};return rows;
  }
  if (typeof window !== 'undefined') window.SabarajaPatternEngine = { evaluateLive: l16EvaluateLive, refreshHistory: refreshPatternHistory, scanUpcoming:l16ScanUpcoming };

  function patternMatchTriggers(rules) {
    if (!patternCsvRecords) return new Map();
    if (patternTriggerIndexes.has(patternLeague)) return patternTriggerIndexes.get(patternLeague);
    // Build once per loaded CSV, independently of date/statistics/search filters.
    for (const rule of rules) csvRuleStats(rule);
    const index = new Map();
    const add = (match, rule, result) => {
      if (!index.has(match.id)) index.set(match.id, []);
      index.get(match.id).push({ rule, result });
    };
    if (patternLeague === '16') {
      for (const event of patternL16Events || []) {
        for (const match of event.group || [event.r]) add(match, event.rule, event.check.result);
      }
    } else {
      for (const rule of rules) {
        for (const event of patternEvaluatedRules.get(patternLeague + ':' + rule.id) || []) add(event.target, rule, event.result);
      }
    }
    patternTriggerIndexes.set(patternLeague, index);
    return index;
  }

  function l16NextSlotRadar(records, now = Date.now()) {
    const completed = records.filter(r => r.league === BELGIUM_LEAGUE && validFinalScore(r) && r.t+16*60000 <= now);
    if (!completed.length) return null;
    const last = Math.max(...completed.map(r => r.t));
    let next = last+15*60000;
    const sourceTime = new Date(next+8*3600000);
    // League 16 archive runs 12:00–00:45; do not invent a 01:00 slot after the session ends.
    if ((sourceTime.getUTCHours() === 0 && sourceTime.getUTCMinutes() > 45) ||
        (sourceTime.getUTCHours() >= 1 && sourceTime.getUTCHours() < 12)) {
      sourceTime.setUTCHours(12,0,0,0);
      next = sourceTime.getTime()-8*3600000;
    }
    const history = completed.filter(r => r.t >= next-36*3600000);
    const teams = [...new Set(completed.flatMap(r => r.teams.map(l16Key)))].sort();
    const halfScores = [null,[0,0],[0,1],[1,0],[1,1],[0,2],[2,0],[0,3],[3,0],[1,2],[2,1],[2,2]];
    const hypothetical = [];
    for (const home of teams) for (const away of teams) {
      if (home === away) continue;
      for (const fh of halfScores) hypothetical.push({ id: 'radar:' + hypothetical.length, league: BELGIUM_LEAGUE,
        t: next, teams: [home,away], fh, ft:null, complete:false });
    }
    // All hypothetical targets remain incomplete: no hypothetical result may become history.
    const forecast = l16BuildEvents([...history,...hypothetical]);
    const groups = new Map();
    const currentOnly = new Set([12,16]);
    for (const event of forecast.events) {
      if (!event.r.id.startsWith('radar:') || currentOnly.has(event.rule.n) || event.rule.n === 31) continue;
      if (!groups.has(event.rule.id)) groups.set(event.rule.id, { rule:event.rule, pairs:new Map() });
      groups.get(event.rule.id).pairs.set(event.r.teams.join('|'),event.r.teams);
    }
    const slot = completed.filter(r => r.t === last);
    if (next-last === 15*60000 && slot.length === 2 && slot.every(r => l16HT(r) === 0)) {
      const rule = L16_RULE_BY_NUMBER.get(31);
      if (rule) groups.set(rule.id,{rule,pairs:new Map(),slot:true});
    }
    return { last, next, teams, items:[...groups.values()].map(x=>({...x,pairs:[...x.pairs.values()]})) };
  }

  function renderPatternRadar() {
    const host = document.getElementById('pattern-radar-host');
    if (!host) return;
    if (!patternCsvRecords) { host.textContent = 'Radar menunggu data CSV…'; return; }
    if (!patternRadarCache) patternRadarCache = l16NextSlotRadar(patternCsvRecords);
    const radar = patternRadarCache;
    host.replaceChildren();
    const heading = document.createElement('h2');
    heading.textContent = 'Potensi Sinyal Aktif (Berdasarkan Hasil Terakhir)';
    host.append(heading);
    if (!radar) { host.append(document.createTextNode('Belum ada hasil lengkap League 16 untuk memeriksa slot berikutnya.')); return; }
    const format = t => new Date(t).toLocaleString('en-GB',{timeZone:'Asia/Singapore',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false});
    const meta = document.createElement('p');
    meta.className = 'pattern-note';
    const old = Date.now()-radar.next > 30*60000;
    meta.textContent = 'Hasil terakhir: '+format(radar.last)+' · Simulasi slot berikutnya: '+format(radar.next)+' (UTC+8). '+
      (old ? 'SNAPSHOT LAMA — bukan radar sesi live saat ini. ' : '')+
      'Potensi bersyarat; jadwal dan HT target belum diketahui. Akurasi mengikuti rentang statistik yang dipilih.';
    host.append(meta);
    const items = radar.items.map(item=>({...item,stats:csvRuleStats(item.rule)})).sort((a,b)=>{
      const accuracy = x => x.stats.win+x.stats.fail ? x.stats.win/(x.stats.win+x.stats.fail) : -1;
      return accuracy(b)-accuracy(a) || a.rule.n-b.rule.n;
    });
    if (!items.length) { host.append(document.createTextNode('Belum ada pemicu dari hasil terakhir; menunggu hasil slot berikutnya.')); return; }
    const list = document.createElement('div');
    list.className = 'pattern-radar-list';
    for (const item of items) {
      const entry = document.createElement('div');
      entry.className = 'pattern-radar-entry';
      const title = document.createElement('strong');
      const total = item.stats.win+item.stats.fail;
      title.textContent = item.rule.id+' · '+item.rule.market+' · '+(total ? (item.stats.win/total*100).toFixed(1)+'% ('+total+' sampel)' : 'Belum ada sampel')+(item.rule.experimental?' · UJI':'');
      const text = document.createElement('p');
      if (item.slot) text.textContent = 'Slot terakhir berisi dua laga HT 0–0. Pantau dua laga pada slot berikutnya; minimal satu gol 2H pada pasangan laga target. Tunggu pasangan target dan HT keduanya.';
      else {
        const homes = [...new Set(item.pairs.map(p=>p[0]))], aways = [...new Set(item.pairs.map(p=>p[1]))];
        const pretty = team => team.replace(/\b\w/g,c=>c.toUpperCase());
        let trigger;
        if (homes.length===1) trigger='Jika '+pretty(homes[0])+' muncul kandang'+(aways.length<radar.teams.length-1 ? ' melawan '+aways.map(pretty).join(', ') : '');
        else if (aways.length===1) trigger='Jika '+pretty(aways[0])+' muncul tandang'+(homes.length<radar.teams.length-1 ? ' melawan '+homes.map(pretty).join(', ') : '');
        else {
          const common=radar.teams.find(t=>item.pairs.every(p=>p.includes(t)));
          trigger=common ? 'Jika '+pretty(common)+' muncul (posisi/lawan tetap mengikuti syarat pola)' : 'Jika pasangan '+item.pairs.slice(0,3).map(p=>p.map(pretty).join(' vs ')).join('; ')+(item.pairs.length>3?' atau '+(item.pairs.length-3)+' pasangan lain':'');
        }
        text.textContent=trigger+', potensi '+item.rule.id+'. '+(item.rule.market.startsWith('2H')?'Konfirmasi setelah HT target tersedia. ':'Konfirmasi riwayat sebelum kickoff. ')+item.rule.name;
      }
      entry.append(title,text);list.append(entry);
    }
    host.append(list);
  }

  const patternDisplayCache = new Map();
  function patternDisplayRecords(leagueName, leagueId) {
    const display = typeof window !== 'undefined' ? window.SabarajaPatternDisplay : null;
    const cached = patternDisplayCache.get(leagueId);
    if (cached?.csv === patternCsvRecords && cached?.display === display) return cached.rows;
    const rows = (patternCsvRecords || []).filter(r => r.league === leagueName);
    const key = (t, teams) => t + '|' + teams.map(l16Key).join('|');
    const byKey = new Map(rows.map((r,i) => [key(r.t,r.teams), i]));
    const score = value => {
      const parsed = String(value || '').match(/^(\d+)\s*[-–:]\s*(\d+)$/);
      return parsed ? [+parsed[1],+parsed[2]] : null;
    };
    const snapshot = display?.snapshots.find(item => String(item.league) === leagueId);
    for (const fixture of snapshot?.displayMatches || []) {
      const t = Date.parse(fixture.time);
      if (!Number.isFinite(t)) continue;
      const teams = [fixture.home,fixture.away];
      const matchKey = key(t,teams), index = byKey.get(matchKey);
      const old = index === undefined ? null : rows[index];
      // Completed CSV remains authoritative; schedules and running scores are display-only.
      if (old?.complete) continue;
      const complete = fixture.status === 'Selesai' && !!score(fixture.ft);
      const live = fixture.live;
      const fh = /^1H\b/i.test(live?.status || '') ? null : score(live?.ht || fixture.ht);
      const record = {...old, id:old?.id || 'api:' + leagueId + ':' + matchKey, league:leagueName, t, teams,
        fh, ft:complete ? score(fixture.ft) : null, complete,
        live, displayScore:live?.score || null,
        displayStatus:live ? live.status + (live.fresh ? '' : ' · lama') : complete ? 'FT' : fixture.status === 'Ditunda' ? 'Ditunda' : 'Jadwal API'};
      if (index === undefined) { byKey.set(matchKey,rows.length); rows.push(record); }
      else rows[index] = record;
    }
    rows.sort((a,b) => Number(!!b.live)-Number(!!a.live) || a.t-b.t);
    patternDisplayCache.set(leagueId,{csv:patternCsvRecords,display,rows});
    return rows;
  }
  const patternDisplayTriggerCache = new Map();
  function patternDisplayedTriggers(rules, records) {
    const base = patternMatchTriggers(rules);
    const cached = patternDisplayTriggerCache.get(patternLeague);
    if (cached?.base === base && cached?.records === records) return cached.index;
    const index = new Map(base);
    const byId = new Map(rules.map(rule => [rule.id,rule]));
    for (const match of records) {
      if (!match.live?.fresh) continue;
      for (const signal of match.live.signals || []) {
        const rule = byId.get(signal.id);
        if (!rule) continue;
        const existing = index.get(match.id) || [];
        index.set(match.id,[...existing.filter(trigger=>trigger.rule.id !== rule.id),
          {rule,result:null,liveState:signal.state}]);
      }
    }
    // Newly completed API results may appear before the automatic CSV save finishes.
    // Evaluate those rows against completed history only; live goals are never FT.
    if (patternLeague === '16' && records.some(match => match.complete && String(match.id).startsWith('api:'))) {
      const completed = records.filter(match=>match.complete);
      for (const event of l16BuildEvents(completed).events) {
        for (const match of event.group || [event.r]) {
          if (!String(match.id).startsWith('api:')) continue;
          const existing = index.get(match.id) || [];
          index.set(match.id,[...existing.filter(trigger=>trigger.rule.id !== event.rule.id),
            {rule:event.rule,result:event.check.result}]);
        }
      }
    }
    patternDisplayTriggerCache.set(patternLeague,{base,records,index});
    return index;
  }
  function renderPatternTable(lines) {
    patternTableLines = lines;
    if (patternTableHost?.hidden) return;
    if (!patternDateExplicit) patternMatchDate = patternLeague === '16' ? l16SessionDay(Date.now()) : patternDayFormatter.format(new Date());
    const tabs = document.createElement('div');
    tabs.className = 'pattern-league-tabs';
    tabs.setAttribute('role', 'group');
    tabs.setAttribute('aria-label', 'Filter league analisis pertandingan dan pattern');
    for (const league of ['15', '16', '20']) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'League ' + league;
      button.setAttribute('aria-pressed', String(patternLeague === league));
      button.onclick = () => {
        patternLeague = league;
        if (!patternDateExplicit) patternMatchDate = league === '16' ? l16SessionDay(Date.now()) : patternDayFormatter.format(new Date());
        renderPatternTable(patternTableLines);
      };
      tabs.append(button);
    }
    const leagueRules = {
      '15': [
        ...LIVERPOOL_RULES.map(r => ({ ...r, market: 'FT Over 1.5' })),
        ...PSG_RULES.map(r => ({ ...r, market: 'HT Over 0.5' })),
        ...AWAY_HT_RULES.map(r => ({ ...r, market: 'HT Over 0.5' })),
        ...NEW_LEAGUE15_RULES,
        ...EXTRA_RESEARCH_RULES.filter(r=>r.league==='15'),
      ],
      '16': L16_ACTIVE_RULES,
      '20': [...ALGERIA_RULES, ...L20_NEW_RULES, ...EXTRA_RESEARCH_RULES.filter(r=>r.league==='20')],
    };
    const rules = leagueRules[patternLeague];
    const note = document.createElement('p');
    note.className = 'pattern-note';
    note.textContent = rules.length + ' pola League ' + patternLeague + ' • ' + ({ all: 'Semua waktu', date: patternMatchDate, week: '7 hari terakhir sampai ' + patternStatsDay(), month: 'Sebulan terakhir sampai ' + patternStatsDay() }[patternStatsRange]) +
      (patternLeague === '16' ? ' (sesi SABA, mulai 12:00 UTC+8 / 11:00 WIB; laga selepas tengah malam sumber masuk sesi sebelumnya).' : ' (tanggal kalender WIB).') +
      ' Statistik dari matches.csv. Riwayat sebelumnya tetap dipakai sebagai pemicu.';
    note.title = note.textContent;
    note.textContent = rules.length + ' pola · ' + ({ all: 'Semua waktu', date: patternMatchDate, week: '7 hari terakhir', month: 'Sebulan terakhir' }[patternStatsRange]) + ' ⓘ';
    const wrap = document.createElement('div');
    wrap.className = 'pattern-table-wrap';
    const table = document.createElement('table');
    table.className = 'pattern-rules-table';
    const head = table.createTHead().insertRow();
    for (const label of ['Kode', 'Pattern', 'Market', 'WIN', 'FAIL', 'Akurasi', 'Total']) {
      const cell = document.createElement('th');
      cell.scope = 'col';
      const sortKey = { WIN: 'win', FAIL: 'fail', Akurasi: 'accuracy' }[label];
      if (sortKey) {
        const active = patternSortColumn === sortKey;
        cell.setAttribute('aria-sort', active ? (patternSortDirection === 'desc' ? 'descending' : 'ascending') : 'none');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pattern-sort-button';
        button.textContent = label + (active ? (patternSortDirection === 'desc' ? ' ↓' : ' ↑') : ' ↕');
        button.onclick = () => {
          patternSortDirection = active && patternSortDirection === 'desc' ? 'asc' : 'desc';
          patternSortColumn = sortKey;
          renderPatternTable(patternTableLines);
        };
        cell.append(button);
      } else cell.textContent = label;
      head.append(cell);
    }
    const body = table.createTBody();
    const rankedRules = rules.filter(rule => (rule.id + ' ' + rule.name + ' ' + (rule.market || '')).toLowerCase().includes(patternSearch.trim().toLowerCase())).map(rule => {
      const stats = csvRuleStats(rule);
      const total = stats.win + stats.fail;
      return { rule, stats, accuracy: total > 0 ? stats.win / total : -1 };
    }).sort((a, b) => {
      const value = entry => patternSortColumn === 'accuracy'
        ? (entry.accuracy < 0 ? null : entry.accuracy) : entry.stats[patternSortColumn];
      const av = value(a), bv = value(b);
      if (av == null || bv == null) return av == null ? (bv == null ? 0 : 1) : -1;
      return (av - bv) * (patternSortDirection === 'asc' ? 1 : -1);
    });
    for (const { rule, stats } of rankedRules) {
      const row = body.insertRow();
      row.classList.add('pattern-clickable-row');
      row.dataset.patternId = rule.id;
      row.onclick = event => {
        if (event.target.closest('button')) return;
        patternSelectedRule = patternSelectedRule === patternLeague + ':' + rule.id ? null : patternLeague + ':' + rule.id;
        renderPatternTable(patternTableLines);
      };
      if (stats.active.length) row.classList.add('pattern-active');
      const total = stats.win + stats.fail;
      const values = [rule.id, rule.name, rule.market || '—', stats.win ?? '—', stats.fail ?? '—',
        total ? (stats.win / total * 100).toFixed(1) + '%' : '—', stats.win === null ? '—' : total];
      values.forEach((value, index) => {
        const cell = row.insertCell();
        if (index === 1) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'pattern-rule-button';
          const selected = patternSelectedRule === patternLeague + ':' + rule.id;
          const isTrial = /^UJI\s*[•·-]?\s*/i.test(String(value));
          if (isTrial) {
            const trialBadge = document.createElement('span');
            trialBadge.className = 'pattern-trial-badge'; trialBadge.textContent = 'UJI';
            button.append(trialBadge, document.createTextNode(' '));
          }
          const patternName = document.createElement('span');
          patternName.className = 'pattern-rule-name' + (isTrial ? ' is-trial' : '');
          patternName.textContent = isTrial ? String(value).replace(/^UJI\s*[•·-]?\s*/i, '') : value;
          button.append(patternName, document.createTextNode(' ↗'));
          button.title = 'Lihat detail pertandingan pemicu pola';
          button.setAttribute('aria-pressed', String(selected));
          if (selected) row.classList.add('pattern-selected');
          button.onclick = () => {
            patternSelectedRule = selected ? null : patternLeague + ':' + rule.id;
            renderPatternTable(patternTableLines);
          };
          cell.append(button);
          if (stats.active.length) {
            const signal = document.createElement('span');
            signal.className = 'pattern-signal';
            signal.textContent = 'PEMICU AKTIF · CSV';
            signal.title = 'Pemicu terpenuhi, hasil belum lengkap, kickoff dalam 45 menit terakhir. Berdasarkan snapshot CSV, bukan feed langsung.';
            cell.append(signal);
          }
        } else if (index === 2) {
          const badge = document.createElement('span');
          badge.className = 'pattern-market-badge ' + (String(value).startsWith('FT') ? 'market-ft' : String(value).startsWith('HT') ? 'market-ht' : String(value).startsWith('2H') ? 'market-2h' : 'market-other');
          badge.textContent = String(value).replace('Over ', 'O');
          badge.title = value;
          cell.append(badge);
        } else if (index === 0) {
          const badge = document.createElement('code');
          badge.className = 'pattern-code-badge';
          badge.textContent = value;
          cell.append(badge);
        } else if (index === 5) {
          const badge = document.createElement('span');
          const accuracy = total > 0 ? stats.win / total : null;
          badge.className = 'pattern-accuracy-badge ' + (accuracy === null ? 'accuracy-empty' : accuracy === 1 ? 'accuracy-perfect' : accuracy > 0.9 ? 'accuracy-high' : accuracy >= 0.85 ? 'accuracy-mid' : 'accuracy-low');
          badge.textContent = value;
          badge.title = total > 0 ? `${stats.win} WIN / ${stats.fail} FAIL · ${total} pertandingan` : 'Belum ada hasil dalam rentang statistik ini';
          cell.append(badge);
        } else cell.textContent = value;
        if (index === 0) cell.className = 'pattern-code';
      });
    }
    wrap.append(table);
    const selectedRule = rankedRules.find(({rule}) => patternSelectedRule === patternLeague + ':' + rule.id);
    if (selectedRule) {
      const details = document.createElement('details');
      details.open = true;
      details.className = 'pattern-history';
      const summary = document.createElement('summary');
      summary.textContent = selectedRule.rule.id + ' · Riwayat pemicu (' + selectedRule.stats.entries.length + ')';
      details.append(summary);
      const list = document.createElement('div');
      list.className = 'pattern-history-list';
      for (const entry of [...selectedRule.stats.entries].reverse()) {
        const item = document.createElement('p');
        const date = new Date(entry.r.t).toLocaleString('en-GB', { timeZone: patternLeague === '16' ? 'Asia/Singapore' : 'Asia/Jakarta', hour12: false });
        const fixtures = (entry.group || [entry.r]).map(r => r.teams.join(' vs ') + ' · HT ' + (r.fh?.join('–') || '—') + ' · FT ' + (r.ft?.join('–') || '—')).join(' / ');
        item.textContent = date + ' · ' + fixtures + ' · ' + (entry.result === 'BERHASIL' ? 'WIN' : ['GAGAL', 'TIDAK TERPENUHI'].includes(entry.result) ? 'FAIL' : 'Menunggu hasil');
        list.append(item);
      }
      if (!selectedRule.stats.entries.length) list.textContent = 'Belum ada pemicu dalam rentang statistik ini.';
      details.append(list);
      wrap.append(details);
    }
    const status = document.createElement('p');
    status.className = 'pattern-note';
    status.style.marginTop = '16px';
    status.textContent = patternCsvStatus;
    const grid = document.createElement('div');
    grid.className = 'pattern-grid';
    const matchesPanel = document.createElement('section');
    const matchesTitle = document.createElement('h2');
    matchesTitle.textContent = 'Pertandingan ⓘ';
    matchesTitle.title = 'Hasil dari matches.csv, dilengkapi jadwal dan skor live API. Skor live belum dihitung sebagai hasil FT. League 16: sesi UTC+8 mulai 12:00; laga setelah tengah malam masuk sesi sebelumnya.';
    matchesPanel.append(matchesTitle);
    const filters = document.createElement('div');
    filters.className = 'pattern-match-filters';
    const modeLabel = document.createElement('label');
    modeLabel.textContent = 'Rentang pertandingan';
    const modeSelect = document.createElement('select');
    for (const [value, label] of [['date', 'Pilih tanggal'], ['all', 'Semua tanggal']]) {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      modeSelect.append(option);
    }
    modeSelect.value = patternAllDates ? 'all' : 'date';
    modeSelect.onchange = () => { patternAllDates = modeSelect.value === 'all'; renderPatternTable(patternTableLines); };
    modeLabel.append(modeSelect);
    filters.append(modeLabel);
    const dateLabel = document.createElement('label');
    dateLabel.textContent = patternLeague === '16' ? 'Tanggal / sesi League 16' : 'Tanggal pertandingan';
    const dateInput = document.createElement('input');
    dateInput.type = 'date';
    dateInput.disabled = patternAllDates && patternStatsRange !== 'date';
    dateInput.value = patternMatchDate;
    dateInput.onchange = () => {
      if (!dateInput.value) { dateInput.value = patternMatchDate; return; }
      patternMatchDate = dateInput.value;
      patternDateExplicit = true;
      renderPatternTable(patternTableLines);
    };
    dateLabel.append(dateInput);
    filters.append(dateLabel);
    matchesPanel.append(filters);
    const selectedLeague = { '15': LEAGUE, '16': BELGIUM_LEAGUE, '20': ALGERIA_LEAGUE }[patternLeague];
    const selectedId = patternSelectedRule?.startsWith(patternLeague + ':') ? patternSelectedRule.slice(patternLeague.length + 1) : null;
    const displayRecords = patternDisplayRecords(selectedLeague,patternLeague);
    const triggerIndex = patternDisplayedTriggers(rules,displayRecords);
    const periodMatches = displayRecords.filter(r => patternAllDates || patternRecordDay(r) === patternMatchDate);
    const matches = selectedId ? periodMatches.filter(r => (triggerIndex.get(r.id) || []).some(t => t.rule.id === selectedId)) : periodMatches;
    if (selectedId) {
      const selection = document.createElement('div');
      selection.className = 'pattern-match-selection';
      const label = document.createElement('span');
      label.textContent = selectedId + ' · ' + matches.length + ' pertandingan pemicu';
      const reset = document.createElement('button');
      reset.type = 'button';
      reset.textContent = 'Tampilkan semua laga';
      reset.onclick = () => { patternSelectedRule = null; patternVisibleMatches = 100; renderPatternTable(patternTableLines); };
      selection.append(label, reset);
      matchesPanel.append(selection);
    }
    if (!matches.length) {
      const empty = document.createElement('div');
      empty.className = 'pattern-empty';
      empty.textContent = patternCsvRecords === null ? patternCsvStatus : selectedId && periodMatches.length
        ? 'Tidak ada laga pemicu ' + selectedId + ' dalam rentang pertandingan yang dipilih.'
        : 'Belum ada hasil CSV atau jadwal API pada tanggal / sesi yang dipilih.';
      if (patternCsvRecords !== null) {
        const available = displayRecords.filter(r => !selectedId || (triggerIndex.get(r.id) || []).some(t => t.rule.id === selectedId));
        const lastDate = available.reduce((latest, r) => {
          const day = patternRecordDay(r);
          return day > latest ? day : latest;
        }, '');
        if (lastDate) {
          const shortcut = document.createElement('button');
          shortcut.type = 'button';
          shortcut.className = 'pattern-last-date';
          shortcut.textContent = 'Lihat tanggal terakhir yang memiliki data · ' + lastDate;
          shortcut.onclick = () => {
            patternMatchDate = lastDate;
            patternDateExplicit = true;
            patternAllDates = false;
            patternVisibleMatches = 100;
            renderPatternTable(patternTableLines);
          };
          empty.append(shortcut);
        }
      }
      matchesPanel.append(empty);
    } else {
      const matchesWrap = document.createElement('div');
      matchesWrap.className = 'pattern-table-wrap';
      const matchesTable = document.createElement('table');
      matchesTable.className = 'pattern-matches-table';
      const matchColumns = [
        ...(patternAllDates ? [{ label: 'Tanggal / sesi', className: 'match-date' }] : []),
        { label: 'Jam', className: 'match-time' },
        { label: 'Pertandingan', className: 'match-teams' },
        { label: 'HT', className: 'match-score' },
        { label: 'Skor', className: 'match-score' },
      ];
      const header = matchesTable.createTHead().insertRow();
      for (const column of matchColumns) {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column.label;
        th.className = column.className;
        if (column.label === 'Skor') th.title = 'Skor berjalan saat live; FT untuk pertandingan selesai';
        if (column.className === 'match-time') th.title = selectedLeague === BELGIUM_LEAGUE ? 'Waktu SABA (UTC+8)' : 'WIB (UTC+7)';
        header.append(th);
      }
      const tbody = matchesTable.createTBody();
      const timeFormatter = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hour12: false });
      const sabaTimeFormatter = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Singapore', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
      const scoreText = value => value ? value.join('–') : '—';
      for (const match of matches.slice(0, patternVisibleMatches)) {
        const row = tbody.insertRow();
        if (selectedId) row.classList.add('pattern-trigger-match');
        const displayedTime = (match.league === BELGIUM_LEAGUE ? sabaTimeFormatter : timeFormatter).format(new Date(match.t));
        const teamNames = match.teams.map(team => team.replace(/\s*\(V\)/gi, '').trim()).join(' vs ');
        const values = [...(patternAllDates ? [patternRecordDay(match)] : []), displayedTime, teamNames, scoreText(match.fh), match.displayScore || scoreText(match.ft)];
        values.forEach((value, index) => {
          const cell = row.insertCell();
          cell.className = matchColumns[index].className;
          cell.textContent = value;
          if (cell.className === 'match-time' && match.displayStatus) {
            const state = document.createElement('small'); state.className = 'pattern-match-state';
            state.textContent = match.displayStatus; cell.append(state);
            if (match.live) row.classList.add(match.live.fresh ? 'pattern-match-live' : 'pattern-match-stale');
          }
          if (cell.className === 'match-score' && index === values.length-1 && match.live) cell.title = 'Skor berjalan · belum merupakan hasil FT';
          if (cell.className === 'match-teams') {
            window.SabarajaLiveResults?.appendMarkets(cell, match.live);
            const triggers = triggerIndex.get(match.id) || [];
            if (triggers.length) {
              const badges = document.createElement('div');
              badges.className = 'pattern-trigger-badges';
              for (const trigger of triggers) {
                const win = trigger.result === 'BERHASIL';
                const fail = ['GAGAL', 'TIDAK TERPENUHI'].includes(trigger.result);
                const badge = document.createElement('button');
                badge.type = 'button';
                const liveLabel = trigger.liveState === 'active' ? 'SINYAL LIVE' : trigger.liveState === 'achieved' ? 'TARGET TERCAPAI · LIVE' : null;
                badge.className = 'pattern-trigger-badge ' + (trigger.liveState ? 'trigger-live' : win ? 'trigger-win' : fail ? 'trigger-fail' : 'trigger-pending');
                badge.textContent = trigger.rule.id + (liveLabel ? ' · ' + liveLabel : win ? ' ✓ WIN' : fail ? ' ✕ FAIL' : ' …');
                badge.title = trigger.rule.name + ' · ' + trigger.rule.market + ' · ' + (liveLabel || (win ? 'WIN' : fail ? 'FAIL' : 'Menunggu hasil')) +
                  (trigger.rule.n === 31 ? ' · Hasil gabungan dua laga dalam satu slot' : '');
                badge.setAttribute('aria-pressed', String(selectedId === trigger.rule.id));
                badge.onclick = () => {
                  patternSelectedRule = selectedId === trigger.rule.id ? null : patternLeague + ':' + trigger.rule.id;
                  patternVisibleMatches = 100;
                  renderPatternTable(patternTableLines);
                };
                const triggerBlock = document.createElement('div'); triggerBlock.className = 'pattern-trigger-block';
                const description = document.createElement('small'); description.className = 'pattern-trigger-description';
                description.textContent = trigger.rule.name + ' · ' + trigger.rule.market;
                triggerBlock.append(badge,description); badges.append(triggerBlock);
              }
              cell.append(badges);
            } else if (match.live && patternLeague === '16') {
              const explanation = document.createElement('small'); explanation.className = 'pattern-trigger-description';
              explanation.textContent = !match.live.fresh ? 'Data lama · sinyal tidak diaktifkan.' :
                !match.live.evaluationReady ? 'Menunggu evaluator dan riwayat pola…' :
                match.live.phase === '1H' ? 'Belum ada pola aktif · pola 2H menunggu HT.' :
                !match.live.htAvailable ? 'Skor HT belum tersedia untuk mengecek pola 2H.' : 'Belum ada pemicu pola yang cocok.';
              cell.append(explanation);
            }
          }
        });
      }
      matchesWrap.append(matchesTable);
      matchesPanel.append(matchesWrap);
      if (matches.length > patternVisibleMatches) {
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'saba-btn';
        more.textContent = 'Tampilkan 100 berikutnya (' + Math.min(patternVisibleMatches, matches.length) + '/' + matches.length + ')';
        more.onclick = () => { patternVisibleMatches += 100; renderPatternTable(patternTableLines); };
        matchesPanel.append(more);
      }
    }
    const rulesPanel = document.createElement('section');
    const rulesTitle = document.createElement('h2');
    rulesTitle.textContent = 'Pattern · ' + { win: 'WIN', fail: 'FAIL', accuracy: 'Akurasi' }[patternSortColumn] +
      (patternSortDirection === 'desc' ? ' tertinggi' : ' terendah');
    const statsControls = document.createElement('div');
    statsControls.className = 'pattern-stats-segments';
    statsControls.setAttribute('role', 'group');
    statsControls.setAttribute('aria-label', 'Rentang statistik pola');
    for (const [value, label] of [['date', 'Tanggal terpilih'], ['week', '7 hari terakhir'], ['month', 'Sebulan terakhir'], ['all', 'Semua waktu']]) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.setAttribute('aria-pressed', String(patternStatsRange === value));
      button.onclick = () => { patternStatsRange = value; renderPatternTable(patternTableLines); };
      statsControls.append(button);
    }
    const searchLabel = document.createElement('label');
    searchLabel.className = 'pattern-search';
    searchLabel.textContent = 'Cari pola';
    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.placeholder = 'Kode atau tim, misalnya Norway, France…';
    searchInput.value = patternSearch;
    searchInput.oninput = () => {
      const position = searchInput.selectionStart;
      patternSearch = searchInput.value;
      renderPatternTable(patternTableLines);
      const next = patternTableHost.querySelector('.pattern-search input');
      next.focus({ preventScroll: true });
      if (position !== null) next.setSelectionRange(position, position);
    };
    searchLabel.append(searchInput);
    if (!rankedRules.length) {
      const empty = document.createElement('p');
      empty.className = 'pattern-empty';
      empty.textContent = 'Tidak ada pola yang cocok dengan pencarian.';
      wrap.append(empty);
    }
    rulesPanel.append(rulesTitle, statsControls, note, searchLabel, wrap);
    grid.append(matchesPanel, rulesPanel);
    const analysisHeader = document.createElement('header');
    analysisHeader.className = 'pattern-analysis-header';
    const analysisIntro = document.createElement('div');
    const analysisTitle = document.createElement('h2');
    analysisTitle.textContent = 'Analisis Pertandingan & Pattern';
    const analysisHint = document.createElement('p');
    analysisHint.textContent = 'Tab liga memfilter pertandingan dan pola di bagian analisis ini.';
    analysisIntro.append(analysisTitle, analysisHint);
    analysisHeader.append(analysisIntro, tabs);
    patternTableHost.replaceChildren(analysisHeader, grid, status);
    clearTimeout(patternRadarTimer);
    window.SabarajaPatternLive?.refreshView();
    document.dispatchEvent(new CustomEvent('pattern-league-change', { detail: { league: patternLeague } }));
  }

  document.addEventListener('pattern-results-rendered', () => {
    if (patternTableHost) renderPatternTable(patternTableLines);
  });
  function render(lines, force = false) {
    if (patternTableHost) {
      renderPatternTable(lines);
      return;
    }
    l15LastRenderLines = lines;
    const bodyEl = box.querySelector('.saba-body');
    const prevBodyScroll = bodyEl ? bodyEl.scrollTop : 0;

    // Bangun Header & Tab
    const hdr = l15El('div', null, '', 'saba-hdr');
    const titleWrap = l15El('div', null, '', 'saba-hdr-title');
    titleWrap.append(l15El('span', '⚡ SABA Monitor v1.33 • League 16 Only • 41 Pola'));
    titleWrap.append(l15El('span', `(${historyRecords.filter(r => recordLeague(r) === BELGIUM_LEAGUE).length} data L16 • ${lastScanTimeStr})`, 'font-size:10px;color:#94a3b8;font-weight:500'));
    hdr.append(titleWrap);

    const actions = l15El('div', null, '', 'saba-hdr-actions');
    const refreshBtn = l15El('button', '↻ Reload', '', 'saba-btn');
    refreshBtn.onclick = () => refreshResults(true);
    const minBtn = l15El('button', isMinimized ? '▢' : '—', '', 'saba-btn');
    minBtn.title = 'Minimize / Restore';
    minBtn.onclick = () => {
      isMinimized = !isMinimized;
      box.classList.toggle('minimized', isMinimized);
      render(l15LastRenderLines, true);
    };
    actions.append(refreshBtn, minBtn);
    hdr.append(actions);

    if (isMinimized) {
      box.replaceChildren(hdr);
      return;
    }

    const tabs = l15El('div', null, '', 'saba-tabs');
    for (const [id, label] of [['l16','League 16'], ['legacy','Log']]) {
      const t = l15El('div', label, '', 'saba-tab' + (activeTab === id ? ' active' : ''));
      t.onclick = () => { activeTab = id; render(l15LastRenderLines, true); };
      tabs.append(t);
    }

    const body = l15El('div', null, '', 'saba-body');

    if (activeTab === 'l16') {
      const l16Count = l16ElOverview(historyRecords);
      body.append(l16Count);
      body.append(otherLeagueDailyPanel(historyRecords, BELGIUM_LEAGUE, 'LEAGUE 16 (FC 24 - 16m)'));
    } else if (activeTab === 'legacy') {
      const logCard = l15El('div', null, '', 'saba-card');
      logCard.append(l15El('div', 'STATUS TARGET & LOG HARIAN', 'font-weight:800;font-size:13px;color:#38bdf8;margin-bottom:6px'));
      const logList = makeScrollList('legacy-lines-scroll');
      logList.style.maxHeight = '320px';
      for (const line of lines) {
        logList.append(l15El('div', line, 'font-size:11px;border-bottom:1px solid #334155;padding:4px 0;color:#cbd5e1'));
      }
      logCard.append(logList);
      body.append(logCard);
    }

    box.replaceChildren(hdr, tabs, body);

    // Pulihkan posisi scroll di setiap kontainer
    body.scrollTop = prevBodyScroll;
    for (const listEl of body.querySelectorAll('[data-scroll-id]')) {
      const id = listEl.dataset.scrollId;
      if (nestedScrollMap.has(id)) listEl.scrollTop = nestedScrollMap.get(id);
    }
  }

  function scan(forceRender = false) {
    try {
      const d = document.querySelector('#fr1')?.contentDocument;
      if (!d) return render(['Menunggu iframe Results (#fr1)...']);
      const headings = [...d.querySelectorAll('[class*="style_trLeague__"]')].filter(e =>
        ENABLED_LEAGUE_NAMES.includes(norm(e.querySelector('[class*="style_txt__"]')?.textContent || ''))
      );
      if (!headings.length) return render(['Buka halaman Results SABA INTERNATIONAL FRIENDLY Virtual FC24 League 16. League 15 dan 20 sedang dinonaktifkan.']);

      const records = GM_getValue(KEY, {});
      const visible = [];
      let dataChanged = false;
      const now = Date.now();
      lastScanTimeStr = new Date(now).toLocaleTimeString('id-ID', { hour12: false });

      for (const heading of headings) {
        const league = norm(heading.querySelector('[class*="style_txt__"]').textContent);
        for (const table of heading.nextElementSibling?.querySelectorAll('table') || []) {
          const cells = table.querySelectorAll('tbody tr:first-child td');
          if (cells.length !== 3) continue;
          const teams = norm(cells[0].textContent).split(/\s+v\s+/i);
          if (teams.length !== 2) continue;
          const side = isLiverpool(teams[0]) ? 0 : isLiverpool(teams[1]) ? 1 : -1;
          const psgSide = isPSG(teams[0]) ? 0 : isPSG(teams[1]) ? 1 : -1;
          const sociedadSide = isSociedad(teams[0]) ? 0 : isSociedad(teams[1]) ? 1 : -1;
          const date = table.querySelector('[class*="style_date__"]')?.textContent || '';
          const t = time(date); if (t === null) continue;
          const status = norm(table.querySelector('[class*="style_status__"]')?.textContent || '');
          const fh = score(cells[1].textContent), ft = score(cells[2].textContent);
          const complete = status === 'COMPLETED';
          const valid = complete && fh && ft && ft[0] >= fh[0] && ft[1] >= fh[1];
          const id = league === LEAGUE ? `${t}|${teams.join('|')}` : `${league}|${t}|${teams.join('|')}`;
          const belgiumSide = isBelgium(teams[0]) ? 0 : isBelgium(teams[1]) ? 1 : -1;
          const netherlandsSide = isNetherlands(teams[0]) ? 0 : isNetherlands(teams[1]) ? 1 : -1;
          const norwaySide = isNorway(teams[0]) ? 0 : isNorway(teams[1]) ? 1 : -1;
          const hungarySide = isHungary(teams[0]) ? 0 : isHungary(teams[1]) ? 1 : -1;

          const prevRec = records[id];
          const r = {
            id, league, t, status, lastSeen: now, date: norm(date), teams,
            side, psgSide, sociedadSide, belgiumSide, netherlandsSide, norwaySide, hungarySide,
            fh, ft, htTotal: fh ? fh[0] + fh[1] : null, complete,
            goals: valid && side >= 0 ? ft[side] - fh[side] : null,
            total: complete && ft ? ft[0] + ft[1] : null,
            completedSeenAt: complete ? (prevRec?.completedSeenAt ?? now) : null
          };

          if (!prevRec || prevRec.status !== r.status || String(prevRec.fh) !== String(r.fh) || String(prevRec.ft) !== String(r.ft)) {
            dataChanged = true;
          }
          records[id] = r;
          visible.push({ r, table });
        }
      }

      if (dataChanged) GM_setValue(KEY, records);
      historyRecords = Object.values(records);

      // Fingerprint untuk mencegah render ulang yang tidak perlu
      const currentHash = visible.map(v => `${v.r.id}:${v.r.status}:${v.r.fh?.join('-')}:${v.r.ft?.join('-')}`).join('||');
      const fcRecords = historyRecords.filter(r => recordLeague(r) === BELGIUM_LEAGUE).sort((a,b)=>a.t-b.t);
      const stale = fcRecords.filter(r => !r.complete && !usablePending(r, now)).length;
      // L15/L20 tidak memproses sinyal, tidak menulis prospective store dan tidak masuk log.
      const lines = [`League 16 saja • 41 pola aktif • League 15/20 nonaktif sementara`,
        `Data League 16 belum selesai yang usang/tidak layak: ${stale}; diabaikan.`];

      // Bersihkan dan pasang ulang badge pada tabel iframe
      d.querySelectorAll('[data-l16-active-signal], [data-new-league20-signal], [data-new-league15-signal], [data-spain-italy-signal], [data-algeria-signal], [data-new-fc-signal], [data-session-2h-signal], [data-away-ht-signal], [data-everton-signal], [data-liverpool-signal], [data-session-signal], [data-psg-signal], [data-sociedad-signal], [data-belgium-signal], [data-netherlands-signal], [data-norway-signal], [data-hungary-signal], [data-extra-signal], [data-l16-early-info], [data-l16-new-pattern], [data-l16-research-info]').forEach(e => e.remove());

      // Badge L16: satu sumber kebenaran berisi TEPAT 41 aturan resmi.
      const l16Report = l16BuildEvents(fcRecords);
      // Hitung FAIL hanya dari sinyal resmi yang sudah selesai pada sesi hari ini.
      // WATCH yang belum memiliki syarat HT tidak dihitung FAIL.
      const todayL16 = l16SessionDay(now);
      const completedL16 = l16Report.events.filter(e =>
        l16SessionDay(e.r.t) === todayL16 && e.check.result !== null);
      const winL16 = completedL16.filter(e => e.check.result === 'BERHASIL').length;
      const failL16 = completedL16.filter(e => e.check.result === 'TIDAK TERPENUHI').length;
      const l16FailTodayByRule = new Map();
      for (const e of completedL16) {
        if (e.check.result !== 'TIDAK TERPENUHI') continue;
        l16FailTodayByRule.set(e.rule.id, (l16FailTodayByRule.get(e.rule.id) || 0) + 1);
      }
      // Gunakan rekap yang sama untuk label sinyal RESMI dan label WATCH.
      const l16DailyFailSuffix = ruleId =>
        ` • GAGAL hari ini ${ruleId}: ${l16FailTodayByRule.get(ruleId) || 0} | Total 41 pola: ${failL16}`;
      for (const e of l16Report.events) {
        // Hanya pertandingan yang BELUM selesai. Riwayat selesai tampil dalam rekap.
        if (e.r.complete || !usablePending(e.r,now)) continue;
        const targets=e.group || [e.r];
        for (const {r,table} of visible) {
          if (!targets.some(t=>t.id===r.id)) continue;
          const isSlot=e.rule.n===31;
          const kind=e.r.fh ? 'HT TERBACA' : 'PANTAU SESI / RIWAYAT';
          const badge=l15El('div',`${e.rule.id} • ${e.rule.name} → ${e.rule.market.toUpperCase()} (${isSlot?'1 SINYAL GABUNGAN, 2 LAGA':kind})${l16DailyFailSuffix(e.rule.id)}`,
            'background:#164e63;color:#cffafe;padding:6px 8px;font-weight:bold;border:1px solid #0891b2;border-radius:4px;margin-top:4px');
          badge.dataset.l16ActiveSignal=e.rule.id;
          table.parentElement.append(badge);
        }
      }
      // WATCH awal 11 pola. Sinyal resmi tetap dihitung hanya oleh l16BuildEvents.
      const watchRules = new Map([
        [3, 'Hungary previous FT ≥4'],
        [4, 'Ukraine previous FT ≥4'],
        [7, 'Spain previous 2H ≥1 + last Spain basic WIN'],
        [14, 'Hungary previous kandang'],
        [27, 'Slot :30 previous sesi FT ≥5'],
        [30, 'Previous slot 2H ≥3'],
        [32, 'USA home previous tandang'],
        [33, 'Ukraine previous slot 2H ≥2'],
        [37, 'Netherlands home previous 2H ≥2'],
        [38, 'Argentina home previous HT ≥2']
      ]);
      const groupByTime = new Map();
      for (const rec of fcRecords) {
        if (!groupByTime.has(rec.t)) groupByTime.set(rec.t, []);
        groupByTime.get(rec.t).push(rec);
      }
      const previousOf = (team, t) => [...fcRecords].reverse().find(p =>
        p.t < t && t - p.t <= L16_PREVIOUS_MAX_MS &&
        p.teams?.some(x => l16Key(x) === team)) || null;
      const previousSlot = t => {
        const pair = groupByTime.get(t - L16_SLOT_MS) || [];
        return pair.length === 2 && pair.every(validFinalScore) ? pair : null;
      };
      // For ID07, emulate the original lastSpainBaseWin chronological filter:
      // basic Spain requires previous 2H >=1 and HT 2-3, regardless of filter.
      const lastSpainBasicBefore = t => {
        let last = null, lastSession = null;
        for (const old of fcRecords) {
          if (old.t >= t) break;
          const session = l16SessionDay(old.t);
          if (session !== lastSession) { lastSession = session; last = null; }
          if (!old.teams?.some(x => l16Key(x) === 'spain') || !validFinalScore(old)) continue;
          const prev = previousOf('spain', old.t);
          const ht = l16HT(old);
          if (prev && validFinalScore(prev) && l16H2(prev) >= 1 && (ht === 2 || ht === 3))
            last = l16H2(old) >= 1;
        }
        return last;
      };
      const watchMessages = [];
      for (const {r, table} of visible) {
        if (recordLeague(r) !== BELGIUM_LEAGUE || r.complete || !usablePending(r,now)) continue;
        const home=l16Key(r.teams[0]), away=l16Key(r.teams[1]);
        const has=team=>home===team || away===team;
        const pTeam=team=>previousOf(team,r.t);
        const pHome=()=>pTeam(home);
        const slot=previousSlot(r.t);
        const slotFT=slot?.reduce((sum,x)=>sum+l16FT(x),0);
        const slot2H=slot?.reduce((sum,x)=>sum+l16H2(x),0);
        const candidates = [];
        const add=(n,ok,detail)=>{ if (ok) candidates.push({n,detail}); };
        const ph=pTeam('hungary'), pu=pTeam('ukraine'), pm=pTeam('mexico');
        const ps=pTeam('spain'), pn=pTeam('netherlands'), pa=pTeam('argentina');
        add(3,has('hungary') && validFinalScore(ph) && l16FT(ph)>=4,
          `Hungary previous FT ${ph?.ft?.join('-') || '-'} (${l16FT(ph)} gol)`);
        add(4,has('ukraine') && validFinalScore(pu) && l16FT(pu)>=4,
          `Ukraine previous FT ${pu?.ft?.join('-') || '-'} (${l16FT(pu)} gol)`);
        add(7,has('spain') && validFinalScore(ps) && l16H2(ps)>=1 &&
          lastSpainBasicBefore(r.t)===true,
          `Spain previous 2H ${l16H2(ps)} gol; last Spain basic WIN`);
        add(14,has('hungary') && validFinalScore(ph) && l16Key(ph.teams[0])==='hungary',
          `Hungary sebelumnya kandang: ${ph?.teams?.join(' v ')}`);
        const minute=new Date(r.t+8*3600000).getUTCMinutes();
        add(27,minute===30 && slot && slotFT>=5,
          `Previous slot FT ${slotFT} gol; slot sekarang :30`);
        add(30,slot && slot2H>=3,`Previous slot 2H ${slot2H} gol`);
        const homePrev=pHome();
        add(32,has('usa') && validFinalScore(homePrev) && l16Key(homePrev.teams[1])===home,
          `Home (${r.teams[0]}) sebelumnya bermain tandang`);
        add(33,has('ukraine') && slot && slot2H>=2,`Previous slot 2H ${slot2H} gol`);
        add(37,has('netherlands') && validFinalScore(homePrev) && l16H2(homePrev)>=2,
          `Home previous 2H ${l16H2(homePrev)} gol`);
        add(38,has('argentina') && validFinalScore(homePrev) && l16HT(homePrev)>=2,
          `Home previous HT ${l16HT(homePrev)} gol`);
        for (const {n,detail} of candidates) {
          const rule=L16_RULE_BY_NUMBER.get(n);
          if (!rule || !watchRules.has(n)) continue;
          const ht=l16HT(r);
          const failSuffix = l16DailyFailSuffix(rule.id);
          const msg=`🔔 WATCH ${rule.id} (${rule.name}) • ${r.teams.join(' v ')} • ${detail} • `+
            (ht===null?'HT belum tersedia':`HT ${ht} gol`)+' • saring manual'+failSuffix;
          watchMessages.push(msg);
          const badge=l15El('div',msg,
            'background:#78350f;color:#fef3c7;padding:7px 9px;font-weight:700;border:1px solid #f59e0b;border-radius:6px;margin-top:5px');
          badge.dataset.l16EarlyInfo=rule.id;
          table.parentElement.append(badge);
          // Once per ID + match per page load; notifications need pre-granted permission.
          const key=`${rule.id}|${r.id}`;
          if (!earlyWatchNotified.has(key)) {
            earlyWatchNotified.add(key);
            if (typeof Notification!=='undefined' && Notification.permission==='granted') {
              try { new Notification(`SABA WATCH • ${rule.id} • ${rule.name}`, {
                body:`${r.teams.join(' v ')} | ${detail}. Cek HT sendiri.${failSuffix}`,
                tag:key
              }); } catch (_) {}
            }
          }
        }
      }
      if (watchMessages.length) lines.push(...watchMessages);
      // Rekap log hanya League 16. Legacy League 15/20 sengaja dilewati.
      lines.unshift(`Rekap League 16 — sesi ${todayL16}: ${winL16} WIN / ${failL16} FAIL dari ${completedL16.length} sinyal`);

      // Hanya render ulang kotak panel jika ada perubahan data atau diminta secara eksplisit
      if (forceRender || dataChanged || currentHash !== lastRenderHash) {
        lastRenderHash = currentHash;
        render(lines);
      } else {
        // Update jam scan saja tanpa merusak DOM
        const timeSpan = box.querySelector('.saba-hdr-title span:last-child');
        if (timeSpan) timeSpan.textContent = `(${fcRecords.length} data L16 • ${lastScanTimeStr})`;
      }
    } catch (e) {
      render(['Pembacaan gagal: ' + e.message], true);
    }
  }

  function refreshResults(manual = false) {
    scan(manual);
    const d = document.querySelector('#fr1')?.contentDocument;
    if (!d) return;
    const headings = [...d.querySelectorAll('[class*="style_trLeague__"]')].filter(e =>
      ENABLED_LEAGUE_NAMES.includes(norm(e.querySelector('[class*="style_txt__"]')?.textContent || ''))
    );
    for (const heading of headings) {
      const refreshIcon = heading?.querySelector('svg[class*="style_reloadIcon__"]');
      if (refreshIcon) refreshIcon.dispatchEvent(new d.defaultView.MouseEvent('click', { bubbles: true }));
    }
    setTimeout(() => scan(manual), 1500);
  }

  scan(true);
  if (!patternTableHost) {
    setInterval(() => refreshResults(false), 10000);
    setInterval(() => scan(false), 3000);
  }
})();
