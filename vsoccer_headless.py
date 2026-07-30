#!/usr/bin/env python3
"""
V-Soccer Headless Collector (native) — TANPA extension.

Playwright membuka halaman V-Soccer di Chrome headless, lalu Python:
  1. Tiap 2.5 detik memanggil JS di halaman untuk membuka accordion tertutup &
     membaca semua event (liga, tim, babak, menit, skor, odds O/U).
  2. Menjalankan logika deteksi gol (lacak dari kickoff 0-0, tandai accurate).
  3. POST ke goal-log-save-vsoccer.php pakai requests (Python bebas mixed-content
     & bebas throttling — tidak bergantung service worker extension).

Kelebihan vs extension: content script MV3 tidak ke-inject di headless; cara ini
menghindari masalah itu sepenuhnya. Cocok ditinggal berjam-jam tanpa buka Chrome.

Pakai:
    python vsoccer_headless.py
Syarat: pip install playwright requests ; Chrome terpasang ; Apache (XAMPP) jalan.
"""

import os
import csv
import sys
import json
import time
import shutil
import signal
from collections import deque
from datetime import datetime
from pathlib import Path

import requests
from playwright.sync_api import sync_playwright

BASE_DIR = Path(__file__).resolve().parent
PROFILE_ROOT = BASE_DIR / ".vsoccer-profile"
USER_DATA_DIR = PROFILE_ROOT / f"session-{os.getpid()}"
LOG_FILE = BASE_DIR / "vsoccer_headless.log"
LIVE_FILE = BASE_DIR / "vsoccer_live.json"   # snapshot untuk vsoccer-live.php
SIGNAL_LOG = BASE_DIR / "signal_log_vsoccer.csv"  # odds market saat sinyal muncul

TARGET_HOST = "1x2aaa.com"
TARGET_URL = os.environ.get(
    "VSOCCER_URL",
    "https://prod20191-101527338.1x2aaa.com/en/asian-view/today/Virtual-Soccer",
)
ENDPOINT = os.environ.get("VSOCCER_ENDPOINT", "http://localhost/lebihsabar/goal-log-save-vsoccer.php")
CHANNEL = os.environ.get("VSOCCER_CHANNEL", "chrome") or None

# Sinyal babak kedua. Semua pattern: aktif hanya selama babak kedua dan langsung
# hilang begitu ada gol di babak kedua.
#   SUPER = gol pertama <= 6' + line awal >= 5.5/6 (=5.75) + selisih HT <= 1;
#           kalau HT seri, boleh asal gol kedua <= 25' dan gol terakhir 1H >= 35'
#           (syarat selisih HT <= 1 berasal dari rumus historis O25-4; tanpa itu
#            akurasi log turun 78,4% (58/74) -> dengan itu 97,4% (37/38))
#   P1    = selisih HT tepat 1 + gol pertama <= 12' + line awal >= 5.5/6 (=5.75)
#   P2    = HT tepat 2-1 / 1-2 + gol pertama <= 15' + line awal >= 5.5
SUPER_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER_FIRST_GOAL_MAX", "6"))
SUPER_MIN_LINE = float(os.environ.get("VSOCCER_SUPER_MIN_LINE", "5.75"))
SUPER_SECOND_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER_SECOND_GOAL_MAX", "25"))
SUPER_LAST_1H_MIN = int(os.environ.get("VSOCCER_SUPER_LAST_1H_MIN", "35"))
SUPER_MAX_HT_DIFF = int(os.environ.get("VSOCCER_SUPER_MAX_HT_DIFF", "1"))
SUPER_NON_DRAW_SECOND_GOAL_MIN = int(os.environ.get("VSOCCER_SUPER_NON_DRAW_SECOND_GOAL_MIN", "9"))
SUPER_NON_DRAW_SECOND_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER_NON_DRAW_SECOND_GOAL_MAX", "30"))
SUPER1_TOTAL_HT = int(os.environ.get("VSOCCER_SUPER1_TOTAL_HT", "3"))
SUPER1_MIN_LINE = float(os.environ.get("VSOCCER_SUPER1_MIN_LINE", "6.75"))
SUPER1_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER1_FIRST_GOAL_MAX", "25"))
SUPER1_ONE_SIDED_MIN_LINE = float(os.environ.get("VSOCCER_SUPER1_ONE_SIDED_MIN_LINE", "7.5"))
SUPER2_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER2_FIRST_GOAL_MAX", "8"))
SUPER2_MIN_LINE = float(os.environ.get("VSOCCER_SUPER2_MIN_LINE", "7.25"))
SUPER2_TOTAL5_SECOND_GOAL_MIN = int(os.environ.get("VSOCCER_SUPER2_TOTAL5_SECOND_GOAL_MIN", "9"))
SUPER2_TOTAL5_SECOND_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER2_TOTAL5_SECOND_GOAL_MAX", "30"))
SUPER2_DRAW4_SECOND_GOAL_MIN = int(os.environ.get("VSOCCER_SUPER2_DRAW4_SECOND_GOAL_MIN", "14"))
SUPER2_DRAW4_SECOND_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER2_DRAW4_SECOND_GOAL_MAX", "30"))
SLOW_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_SLOW_FIRST_GOAL_MAX", "8"))
SLOW_MIN_LINE = float(os.environ.get("VSOCCER_SLOW_MIN_LINE", "5.75"))
SLOW_TOTAL3_EARLY_FIRST_MAX = int(os.environ.get("VSOCCER_SLOW_TOTAL3_EARLY_FIRST_MAX", "4"))
SLOW_TOTAL3_EARLY_MIN_LINE = float(os.environ.get("VSOCCER_SLOW_TOTAL3_EARLY_MIN_LINE", "7.25"))
SLOW_DRAW4_LATE_FIRST_MIN = int(os.environ.get("VSOCCER_SLOW_DRAW4_LATE_FIRST_MIN", "7"))
SLOW_DRAW4_EARLY_SECOND_MAX = int(os.environ.get("VSOCCER_SLOW_DRAW4_EARLY_SECOND_MAX", "10"))
SLOW_DRAW4_MAX_LINE = float(os.environ.get("VSOCCER_SLOW_DRAW4_MAX_LINE", "6.25"))
SLOW_TOTAL5_EARLY_FIRST_MAX = int(os.environ.get("VSOCCER_SLOW_TOTAL5_EARLY_FIRST_MAX", "5"))
SLOW_TOTAL5_LAST_GOAL_MIN = int(os.environ.get("VSOCCER_SLOW_TOTAL5_LAST_GOAL_MIN", "30"))
SLOW_TOTAL6_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_SLOW_TOTAL6_FIRST_GOAL_MAX", "7"))
P1_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P1_FIRST_GOAL_MAX", "12"))
P1_MIN_LINE = float(os.environ.get("VSOCCER_P1_MIN_LINE", "5.75"))
P1_LOW_TOTAL_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P1_LOW_TOTAL_FIRST_GOAL_MAX", "6"))
P1_HIGH_TOTAL_SECOND_GOAL_MIN = int(os.environ.get("VSOCCER_P1_HIGH_TOTAL_SECOND_GOAL_MIN", "9"))
P1_HIGH_TOTAL_SECOND_GOAL_MAX = int(os.environ.get("VSOCCER_P1_HIGH_TOTAL_SECOND_GOAL_MAX", "30"))
P2_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P2_FIRST_GOAL_MAX", "15"))
P2_MIN_LINE = float(os.environ.get("VSOCCER_P2_MIN_LINE", "5.75"))
P2_MAX_LINE = float(os.environ.get("VSOCCER_P2_MAX_LINE", "7.5"))
P2_EARLY_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P2_EARLY_FIRST_GOAL_MAX", "4"))
P2_EARLY_MIN_LINE = float(os.environ.get("VSOCCER_P2_EARLY_MIN_LINE", "7.25"))
P3_TOTAL_HT = int(os.environ.get("VSOCCER_P3_TOTAL_HT", "3"))
P3_FIRST_GOAL_MIN = int(os.environ.get("VSOCCER_P3_FIRST_GOAL_MIN", "5"))
P3_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P3_FIRST_GOAL_MAX", "9"))
P3_MIN_LINE = float(os.environ.get("VSOCCER_P3_MIN_LINE", "5.5"))
P3_MAX_LINE = float(os.environ.get("VSOCCER_P3_MAX_LINE", "7.5"))
P4_FIRST_GOAL_MIN = int(os.environ.get("VSOCCER_P4_FIRST_GOAL_MIN", "15"))
P4_MIN_LINE = float(os.environ.get("VSOCCER_P4_MIN_LINE", "5.5"))
P5_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P5_FIRST_GOAL_MAX", "18"))
P6_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P6_FIRST_GOAL_MAX", "8"))
P6_MAX_LINE = float(os.environ.get("VSOCCER_P6_MAX_LINE", "6.25"))     # 6/6.5
P7_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P7_FIRST_GOAL_MAX", "8"))
P8_MIN_LINE = float(os.environ.get("VSOCCER_P8_MIN_LINE", "6"))
P10_MIN_LINE = float(os.environ.get("VSOCCER_P10_MIN_LINE", "5.75"))   # 5.5/6
P11_TOTAL_HT = int(os.environ.get("VSOCCER_P11_TOTAL_HT", "3"))
P11_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P11_FIRST_GOAL_MAX", "12"))
P11_MIN_LINE = float(os.environ.get("VSOCCER_P11_MIN_LINE", "5.75"))

PATTERNS = [
    {"code": "SUPER",
     "desc": f"selisih HT ≤ {SUPER_MAX_HT_DIFF} (kalau seri: gol-2 ≤ {SUPER_SECOND_GOAL_MAX}' "
             f"dan gol terakhir 1H ≥ {SUPER_LAST_1H_MIN}'; total HT 3/5: gol-2 "
             f"{SUPER_NON_DRAW_SECOND_GOAL_MIN}'–{SUPER_NON_DRAW_SECOND_GOAL_MAX}')",
     "ht": "super", "first_goal_max": SUPER_FIRST_GOAL_MAX, "min_line": SUPER_MIN_LINE},
    # Catatan: legenda live = desc + ambang gol pertama/line yang ditempel otomatis,
    # jadi desc TIDAK boleh mengulang kedua hal itu (nanti tampil dobel).
    {"code": "SUPER1",
     "desc": f"total gol HT tepat {SUPER1_TOTAL_HT} (HT timpang 3-0/0-3 wajib "
             f"line ≥ {SUPER1_ONE_SIDED_MIN_LINE})",
     "ht": "total", "total_ht": SUPER1_TOTAL_HT, "first_goal_max": SUPER1_FIRST_GOAL_MAX,
     "min_line": SUPER1_MIN_LINE},
    # SUPER2 = S-LOW dengan syarat line lebih tinggi (>= 7/7.5).
    {"code": "SUPER2",
     "desc": f"selisih HT ≤ 1 (total HT 5: gol-2 {SUPER2_TOTAL5_SECOND_GOAL_MIN}'–"
             f"{SUPER2_TOTAL5_SECOND_GOAL_MAX}'; HT 2-2: gol-2 "
             f"{SUPER2_DRAW4_SECOND_GOAL_MIN}'–{SUPER2_DRAW4_SECOND_GOAL_MAX}')",
     "ht": "diff_le1",
     "first_goal_max": SUPER2_FIRST_GOAL_MAX, "min_line": SUPER2_MIN_LINE},
    {"code": "S-LOW",
     "desc": "formula ketat 100%: tanpa total HT 1; total HT 3 + gol-1 ≤ 4' wajib line ≥ 7.25; HT 2-2 + gol-1 ≥ 7' + gol-2 ≤ 10' wajib line ≤ 6.25; total HT 5 + gol-1 ≤ 5' wajib gol terakhir 1H ≥ 30'; total HT 6 wajib gol-1 ≤ 7'", "ht": "super",
     "first_goal_max": SLOW_FIRST_GOAL_MAX, "min_line": SLOW_MIN_LINE},
    {"code": "P1",
     "desc": f"selisih HT tepat 1 (total HT 1: gol-1 ≤ {P1_LOW_TOTAL_FIRST_GOAL_MAX}'; "
             f"total HT 5: gol-2 {P1_HIGH_TOTAL_SECOND_GOAL_MIN}'–{P1_HIGH_TOTAL_SECOND_GOAL_MAX}')",
     "ht": "diff1",
     "first_goal_max": P1_FIRST_GOAL_MAX, "min_line": P1_MIN_LINE},
    {"code": "P2", "desc": "HT 2-1 / 1-2; gol-1 ≤ 4' wajib line ≥ 7.25", "ht": "21",
     "first_goal_max": P2_FIRST_GOAL_MAX, "min_line": P2_MIN_LINE, "max_line": P2_MAX_LINE},
    {"code": "P3", "desc": f"total gol HT tepat {P3_TOTAL_HT}", "ht": "total",
     "total_ht": P3_TOTAL_HT, "first_goal_min": P3_FIRST_GOAL_MIN,
     "first_goal_max": P3_FIRST_GOAL_MAX, "min_line": P3_MIN_LINE, "max_line": P3_MAX_LINE},
    {"code": "P4", "desc": "HT 1-1", "ht": "score", "score": (1, 1),
     "first_goal_min": P4_FIRST_GOAL_MIN, "first_goal_max": None, "min_line": P4_MIN_LINE},
    # P5-P10: skor HT persis (home-away, jadi 1-3 tidak sama dengan 3-1).
    {"code": "P5", "desc": "HT 3-0", "ht": "score", "score": (3, 0),
     "first_goal_max": P5_FIRST_GOAL_MAX, "min_line": None},
    {"code": "P6", "desc": "HT 2-2", "ht": "score", "score": (2, 2),
     "first_goal_max": P6_FIRST_GOAL_MAX, "min_line": None, "max_line": P6_MAX_LINE},
    {"code": "P7", "desc": "HT 3-2", "ht": "score", "score": (3, 2),
     "first_goal_max": P7_FIRST_GOAL_MAX, "min_line": None},
    {"code": "P8", "desc": "HT 1-3", "ht": "score", "score": (1, 3),
     "first_goal_max": None, "min_line": P8_MIN_LINE},
    {"code": "P9", "desc": "HT 3-3 (tanpa syarat tambahan)", "ht": "score", "score": (3, 3),
     "first_goal_max": None, "min_line": None},
    {"code": "P10", "desc": "HT 2-3", "ht": "score", "score": (2, 3),
     "first_goal_max": None, "min_line": P10_MIN_LINE},
    # P11 "low": seperti P3 tapi jendela menit lebih longgar + line minimal.
    {"code": "P11", "desc": f"total gol HT tepat {P11_TOTAL_HT} (low)", "ht": "total",
     "total_ht": P11_TOTAL_HT, "first_goal_max": P11_FIRST_GOAL_MAX, "min_line": P11_MIN_LINE},
    # HAH: urutan gol babak pertama Home - Away - Home, berakhir HT 2-1.
    # Tanpa syarat menit gol pertama & tanpa syarat line awal.
    {"code": "HAH", "desc": "urutan gol 1H Home–Away–Home, HT 2-1", "ht": "hah",
     "first_goal_max": None, "min_line": None},
]

POLL_SEC = float(os.environ.get("VSOCCER_POLL_SEC", "2.5"))
RELOAD_SEC = int(os.environ.get("VSOCCER_RELOAD_SEC", str(20 * 60)))
SIGNAL_START_2H_MINUTE = int(os.environ.get("VSOCCER_SIGNAL_START_2H_MINUTE", "60"))
PAGE_LOAD_TIMEOUT_MS = 60_000

_stop = False

# JS yang dijalankan di halaman: buka accordion tertutup + kembalikan snapshot event.
SNAPSHOT_JS = r"""
() => {
  const q = (el, s) => el.querySelector(s);
  const qa = (el, s) => Array.from(el.querySelectorAll(s));
  const txt = (el) => (el ? el.innerText.trim() : '');
  // buka accordion V-Soccer yang tertutup
  document.querySelectorAll('[class*="EventListLeague_container"]').forEach(c => {
    const name = txt(q(c, '[class*="leagueName"]'));
    if (!/mins \[V\]/i.test(name)) return;
    const arrow = q(c, '[class*="expandCollapseArrow"]');
    const expanded = arrow && /Expanded/.test(arrow.className);
    const hasEv = c.querySelector('[class*="singleEvent"]');
    if (!expanded && !hasEv) {
      (q(c, '[class*="expandCollapse"]') || q(c, '[class*="EventListLeague_header"]'))?.click();
    }
  });
  const out = [];
  Array.from(document.querySelectorAll('[class*="leagueName"]'))
    .filter(h => /mins \[V\]/i.test(h.innerText))
    .forEach(h => {
      const league = h.innerText.replace(/\s*\(\d+\)\s*$/, '').trim();
      let n = h, cont = null;
      for (let d = 0; d < 8 && n; d++) { n = n.parentElement; if (n && n.querySelector('[class*="singleEvent"]')) { cont = n; break; } }
      if (!cont) return;
      qa(cont, '[class*="singleEvent"]').forEach(ev => {
        const teams = qa(ev, '[class*="teamNameText"]').map(t => t.innerText.trim()).filter(x => x && x.toLowerCase() !== 'draw');
        if (teams.length < 2) return;
        const scoreLive = txt(q(ev, '[class*="EventTime_scoreLive"]'));
        const part = txt(q(ev, '[class*="EventTime_gamePart"]'));
        const prog = q(ev, '[class*="EventTime_gameProgress"]');
        let minute = -1;
        if (prog) { const m = prog.innerText.match(/(\d+)'/); if (m) minute = parseInt(m[1], 10); }
        const sm = scoreLive.match(/(\d+)\s*:\s*(\d+)/);
        if (!sm) return;
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
        out.push({ league, home: teams[0], away: teams[1], half: part.toUpperCase(), minute,
                   h: parseInt(sm[1], 10), a: parseInt(sm[2], 10), line, over, under });
      });
    });
  return out;
}
"""

state = {}  # key -> {"home","away","track"}

recent_goals = deque(maxlen=40)   # gol terakhir untuk live view
active_signals = set()            # match yang sedang kena Pattern 1
stats = {"started_at": "", "cycles": 0, "sent_goals": 0, "sent_matches": 0,
         "last_post": "", "last_error": "", "launches": 0, "fails": 0}


def to_decimal(v):
    """Normalisasi odds ke DESIMAL. Situs bisa menampilkan format:
       - Desimal   : >=1.0            -> pakai apa adanya (mis. 1.87)
       - Indonesia : <=-1.0 (favorit) -> 1 + 1/|v|   (mis. -1.15 -> 1.87)
       - Hong Kong : 0<v<1            -> 1 + v       (mis. 0.87  -> 1.87)
       - Malay neg : -1<v<0           -> 1 + 1/|v|
       Kembalikan '' bila tak bisa di-parse / 0.
    """
    try:
        f = float(str(v).strip())
    except (ValueError, TypeError):
        return ""
    if f >= 1.0:
        d = f
    elif f <= -1.0:
        d = 1.0 + 1.0 / abs(f)
    elif 0 < f < 1.0:
        d = 1.0 + f
    elif -1.0 < f < 0:
        d = 1.0 + 1.0 / abs(f)
    else:
        return ""
    return f"{d:.2f}"


def line_value(v):
    """Nilai numerik line O/U. '5.5/6' -> 5.75, '6.5' -> 6.5, '7/7.5' -> 7.25."""
    parts = [p.strip() for p in str(v or "").split("/") if p.strip()]
    nums = []
    for p in parts:
        try:
            nums.append(float(p))
        except ValueError:
            return None
    return sum(nums) / len(nums) if nums else None


def signal_check(e, st, pat):
    """Cek satu pattern pada satu event. Kembalikan (aktif: bool, alasan gagal: str)."""
    if e["half"] != "2H":
        return False, "belum 2H"
    if e.get("minute", -1) < SIGNAL_START_2H_MINUTE:
        return False, f"menunggu menit {SIGNAL_START_2H_MINUTE}"
    if not st.get("track"):
        return False, "tidak dilacak dari kickoff"
    ht_h, ht_a = st.get("ht_h"), st.get("ht_a")
    if ht_h is None or ht_a is None:
        return False, "skor HT tak terekam"
    if pat["ht"] == "diff1" and abs(ht_h - ht_a) != 1:
        return False, f"selisih HT {abs(ht_h - ht_a)}"
    if pat["ht"] == "diff_le1" and abs(ht_h - ht_a) > 1:
        return False, f"selisih HT {abs(ht_h - ht_a)}"
    if pat["ht"] == "total" and (ht_h + ht_a) != pat["total_ht"]:
        return False, f"total HT {ht_h + ht_a}"
    if pat["ht"] == "score" and (ht_h, ht_a) != tuple(pat["score"]):
        return False, f"HT {ht_h}-{ht_a}"
    if pat["ht"] == "21" and {ht_h, ht_a} != {1, 2}:
        return False, f"HT {ht_h}-{ht_a}"
    if pat["code"] == "SUPER1" and abs(ht_h - ht_a) == 3:
        lv = line_value(st.get("ko_line"))
        if lv is None or lv < SUPER1_ONE_SIDED_MIN_LINE:
            return False, f"HT {ht_h}-{ht_a}, line awal {st.get('ko_line')}"
    if pat["code"] == "S-LOW":
        total_ht = ht_h + ht_a
        g1h = st.get("goal_mins_1h") or []
        fg = g1h[0] if g1h else None
        sg = g1h[1] if len(g1h) >= 2 else None
        lv = line_value(st.get("ko_line"))
        if total_ht == 1:
            return False, "total HT 1 dibuang"
        if total_ht == 3 and fg is not None and fg <= SLOW_TOTAL3_EARLY_FIRST_MAX:
            if lv is None or lv < SLOW_TOTAL3_EARLY_MIN_LINE:
                return False, f"total HT 3, gol pertama {fg}', line {st.get('ko_line')}"
        if ht_h == ht_a and total_ht == 4 and fg is not None and sg is not None:
            if fg >= SLOW_DRAW4_LATE_FIRST_MIN and sg <= SLOW_DRAW4_EARLY_SECOND_MAX:
                if lv is None or lv > SLOW_DRAW4_MAX_LINE:
                    return False, f"HT 2-2, gol 1/2 {fg}'/{sg}', line {st.get('ko_line')}"
        if total_ht == 5 and fg is not None and fg <= SLOW_TOTAL5_EARLY_FIRST_MAX:
            if not g1h or g1h[-1] < SLOW_TOTAL5_LAST_GOAL_MIN:
                return False, f"total HT 5, gol terakhir 1H {g1h[-1] if g1h else 'tak terekam'}"
        if total_ht == 6 and fg is not None and fg > SLOW_TOTAL6_FIRST_GOAL_MAX:
            return False, f"total HT 6, gol pertama {fg}'"
    if pat["code"] == "SUPER2":
        total_ht = ht_h + ht_a
        g1h = st.get("goal_mins_1h") or []
        if total_ht == 5:
            if len(g1h) < 2 or not SUPER2_TOTAL5_SECOND_GOAL_MIN <= g1h[1] <= SUPER2_TOTAL5_SECOND_GOAL_MAX:
                minute = g1h[1] if len(g1h) >= 2 else "tak terekam"
                return False, f"total HT 5, gol kedua {minute}"
        if ht_h == ht_a and total_ht == 4:
            if len(g1h) < 2 or not SUPER2_DRAW4_SECOND_GOAL_MIN <= g1h[1] <= SUPER2_DRAW4_SECOND_GOAL_MAX:
                minute = g1h[1] if len(g1h) >= 2 else "tak terekam"
                return False, f"HT 2-2, gol kedua {minute}"
    if pat["code"] == "P1":
        total_ht = ht_h + ht_a
        g1h = st.get("goal_mins_1h") or []
        if total_ht == 1 and g1h and g1h[0] > P1_LOW_TOTAL_FIRST_GOAL_MAX:
            return False, f"total HT 1, gol pertama {g1h[0]}'"
        if total_ht == 5:
            if len(g1h) < 2:
                return False, "total HT 5, gol kedua tak terekam"
            if not P1_HIGH_TOTAL_SECOND_GOAL_MIN <= g1h[1] <= P1_HIGH_TOTAL_SECOND_GOAL_MAX:
                return False, f"total HT 5, gol kedua {g1h[1]}'"
    if pat["ht"] == "super":
        # Selisih HT harus rapat. Kalau timpang >= 2 gol, mesin cenderung
        # "kehabisan kuota" dan babak kedua kering (15 dari 16 MISS di log).
        if abs(ht_h - ht_a) > SUPER_MAX_HT_DIFF:
            return False, f"selisih HT {abs(ht_h - ht_a)}"
        if ht_h == ht_a:
            # HT seri masih boleh, asal gol kedua cepat & gol terakhir 1H terjadi telat.
            g1h = st.get("goal_mins_1h") or []
            if len(g1h) < 2:
                return False, f"HT seri {ht_h}-{ht_a}, gol 1H cuma {len(g1h)}"
            if g1h[1] > SUPER_SECOND_GOAL_MAX:
                return False, f"HT seri, gol kedua {g1h[1]}'"
            if g1h[-1] < SUPER_LAST_1H_MIN:
                return False, f"HT seri, gol terakhir 1H {g1h[-1]}'"
        elif (ht_h + ht_a) in (3, 5):
            g1h = st.get("goal_mins_1h") or []
            if len(g1h) < 2:
                return False, f"total HT {ht_h + ht_a}, gol 1H cuma {len(g1h)}"
            if not SUPER_NON_DRAW_SECOND_GOAL_MIN <= g1h[1] <= SUPER_NON_DRAW_SECOND_GOAL_MAX:
                return False, f"total HT {ht_h + ht_a}, gol kedua {g1h[1]}'"
    if pat["ht"] == "hah":
        # Home cetak gol - Away menyamakan - Home cetak lagi, berakhir HT 2-1.
        if (ht_h, ht_a) != (2, 1):
            return False, f"HT {ht_h}-{ht_a}"
        sides = st.get("goal_sides_1h") or []
        if sides != ["home", "away", "home"]:
            urut = "-".join(s[0].upper() for s in sides) or "belum ada gol 1H"
            return False, f"urutan {urut}"
    fg = st.get("first_goal_min")
    if fg is None:
        return False, "belum ada gol"
    if pat["code"] == "P2" and fg <= P2_EARLY_FIRST_GOAL_MAX:
        lv = line_value(st.get("ko_line"))
        if lv is None or lv < P2_EARLY_MIN_LINE:
            return False, f"P2 gol pertama {fg}', line awal {st.get('ko_line')}"
    if pat["first_goal_max"] is not None and fg > pat["first_goal_max"]:
        return False, f"gol pertama {fg}'"
    if pat.get("first_goal_min") is not None and fg < pat["first_goal_min"]:
        return False, f"gol pertama {fg}'"
    if pat["min_line"] is not None or pat.get("max_line") is not None:
        lv = line_value(st.get("ko_line"))
        if lv is None:
            return False, "line awal tak ada"
        if pat["min_line"] is not None and lv < pat["min_line"]:
            return False, f"line awal {st.get('ko_line')}"
        if pat.get("max_line") is not None and lv > pat["max_line"]:
            return False, f"line awal {st.get('ko_line')} (di atas batas)"
    if (e["h"] + e["a"]) > (ht_h + ht_a):
        return False, "sudah ada gol 2H"
    return True, ""


def match_signals(e, st):
    """Semua pattern yang aktif untuk satu match.

    Kembalikan (kode aktif: list, alasan per pattern yang tidak aktif: str).
    """
    hits, why = [], []
    for pat in PATTERNS:
        ok, reason = signal_check(e, st, pat)
        if ok:
            hits.append(pat["code"])
        else:
            why.append(f"{pat['code']}: {reason}")
    return hits, " · ".join(why)


# Target tiap pattern dalam jumlah gol babak kedua. Harus sama dengan
# $targetGoals di check-super-accuracy.php.
PATTERN_TARGET_2H = {"SUPER": 3, "SUPER1": 3, "SUPER2": 3, "S-LOW": 3}
SIGNAL_LOG_HEADER = [
    "logged_at", "code", "league", "home_team", "away_team",
    "half", "minute", "score", "ht", "ht_total",
    "target_2h_goals", "needed_line",
    "live_line", "live_over", "live_under",
    "ko_line", "ko_over", "ko_under",
]


def st_ht_total(row):
    """Total gol HT dari string "2-1" -> 3. None kalau HT belum terekam."""
    parts = (row.get("ht") or "").split("-")
    if len(parts) != 2:
        return None
    try:
        return int(parts[0]) + int(parts[1])
    except ValueError:
        return None


def append_signal_log(row):
    """Catat odds market pada detik sinyal muncul.

    Tanpa ini akurasi pattern tidak bisa diterjemahkan jadi untung/rugi: odds
    di goal_log_vsoccer.csv adalah odds kickoff full-match, bukan odds yang
    benar-benar tersedia saat taruhan dipasang di babak kedua.
    """
    try:
        new_file = not SIGNAL_LOG.exists() or SIGNAL_LOG.stat().st_size == 0
        with open(SIGNAL_LOG, "a", encoding="utf-8", newline="") as fh:
            w = csv.writer(fh, quoting=csv.QUOTE_MINIMAL)
            if new_file:
                w.writerow(SIGNAL_LOG_HEADER)
            w.writerow([row.get(k, "") for k in SIGNAL_LOG_HEADER])
    except Exception as exc:
        log(f"Gagal catat signal log: {exc}")


def log(msg):
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except Exception:
        pass


def _stop_handler(signum, frame):
    global _stop
    _stop = True
    log("Sinyal stop diterima, menutup...")


def now_iso():
    return datetime.now().isoformat()


def build_payload(events):
    """Terapkan logika deteksi gol pada snapshot -> payload {matches, goals}."""
    matches, goals = [], []
    for e in events:
        if not e.get("half"):
            continue
        key = f"{e['league']}|{e['home']}|{e['away']}"
        st = state.get(key)
        tot = e["h"] + e["a"]
        if st and tot < (st["home"] + st["away"]):
            st = None  # siklus/match baru -> reset
        ko_over, ko_under = to_decimal(e["over"]), to_decimal(e["under"])
        ko_ok = bool(e["line"] and ko_over and ko_under)
        if st is None:
            track = (e["half"] == "1H" and e["h"] == 0 and e["a"] == 0)
            state[key] = {"home": e["h"], "away": e["a"], "track": track, "ko": ko_ok,
                          "ko_line": e["line"] if ko_ok else "",
                          "ko_over": ko_over if ko_ok else "",
                          "ko_under": ko_under if ko_ok else "",
                          "first_goal_min": None, "ht_h": None, "ht_a": None,
                          "goal_mins_1h": [], "goal_sides_1h": []}
            # Hanya daftarkan match yang line awalnya sudah valid; kalau belum, tunggu
            # siklus berikutnya (selama skor masih 0-0) lewat cabang isi-susulan di bawah.
            if track and ko_ok:
                matches.append({
                    "league": e["league"], "home_team": e["home"], "away_team": e["away"],
                    "home_score": "0", "away_score": "0",
                    "ko_line": e["line"], "ko_over": ko_over, "ko_under": ko_under,
                    "timestamp": now_iso(),
                })
            continue
        if not st["track"]:
            st["home"], st["away"] = e["h"], e["a"]
            continue
        # Odds kickoff belum sempat terekam (invalid saat mulai) & match masih 0-0:
        # kirim ulang saat odds sudah valid; endpoint hanya mengisi kolom ko_* yang masih kosong.
        if not st.get("ko") and ko_ok and (e["h"] + e["a"]) == 0:
            st["ko"] = True
            st["ko_line"], st["ko_over"], st["ko_under"] = e["line"], ko_over, ko_under
            matches.append({
                "league": e["league"], "home_team": e["home"], "away_team": e["away"],
                "home_score": "0", "away_score": "0",
                "ko_line": e["line"], "ko_over": ko_over, "ko_under": ko_under,
                "timestamp": now_iso(),
            })
        # Skor HT = skor terakhir yang terlihat selagi masih babak pertama.
        if e["half"] == "1H":
            st["ht_h"], st["ht_a"] = e["h"], e["a"]
        jump = (e["h"] - st["home"]) + (e["a"] - st["away"])
        accurate = 1 if jump <= 2 else 0
        minute_str = f"{e['half']} {max(e['minute'], 0)}'"
        ch, ca = st["home"], st["away"]
        while ch < e["h"] or ca < e["a"]:
            if ch < e["h"]:
                ch += 1; side = "home"
            else:
                ca += 1; side = "away"
            if st["first_goal_min"] is None:
                st["first_goal_min"] = max(e["minute"], 0)
            if e["half"] == "1H":
                st["goal_mins_1h"].append(max(e["minute"], 0))
                st["goal_sides_1h"].append(side)
            goals.append({
                "league": e["league"], "home_team": e["home"], "away_team": e["away"],
                "minute": minute_str, "half": e["half"], "min_num": max(e["minute"], 0),
                "side": side, "score_after": f"{ch}-{ca}", "accurate": accurate,
                "ou_line": e["line"], "over_odd": to_decimal(e["over"]), "under_odd": to_decimal(e["under"]),
                "home_score": str(e["h"]), "away_score": str(e["a"]), "timestamp": now_iso(),
            })
        st["home"], st["away"] = e["h"], e["a"]
    return matches, goals


def post(matches, goals):
    if not matches and not goals:
        return
    try:
        r = requests.post(ENDPOINT, json={"matches": matches, "goals": goals}, timeout=10)
        stats["sent_goals"] += len(goals)
        stats["sent_matches"] += len(matches)
        stats["last_post"] = now_iso()
        log(f"kirim {len(goals)} gol, {len(matches)} match -> {r.status_code} {r.text[:120]}")
    except Exception as e:
        stats["last_error"] = f"POST gagal: {e}"
        log(f"POST gagal: {e}")


def write_live(events, goals, status="running", note=""):
    """Tulis snapshot terakhir ke vsoccer_live.json (dibaca vsoccer-live.php)."""
    for g in goals:
        recent_goals.appendleft({
            "time": datetime.now().strftime("%H:%M:%S"),
            "league": g["league"], "home_team": g["home_team"], "away_team": g["away_team"],
            "minute": g["minute"], "side": g["side"], "score_after": g["score_after"],
            "accurate": g["accurate"],
        })
    rows = []
    for e in events or []:
        st = state.get(f"{e['league']}|{e['home']}|{e['away']}") or {}
        hits, why = match_signals(e, st)
        ht = "" if st.get("ht_h") is None else f"{st['ht_h']}-{st['ht_a']}"
        rows.append({
            "signal": bool(hits), "hits": hits, "signal_why": why, "ht": ht,
            "first_goal_min": st.get("first_goal_min"),
            "goal_mins_1h": list(st.get("goal_mins_1h") or []),
            "goal_seq_1h": "-".join(s[0].upper() for s in (st.get("goal_sides_1h") or [])),
            "league": e["league"], "home": e["home"], "away": e["away"],
            "half": e["half"], "minute": e["minute"],
            "score": f"{e['h']}-{e['a']}", "total": e["h"] + e["a"],
            "line": e["line"], "over": to_decimal(e["over"]), "under": to_decimal(e["under"]),
            "ko_line": st.get("ko_line", ""), "ko_over": st.get("ko_over", ""),
            "ko_under": st.get("ko_under", ""), "tracked": bool(st.get("track")),
        })
    # Sinyal aktif ditaruh paling atas + dicatat ke log sekali saat muncul.
    rows.sort(key=lambda r: (not r["signal"], r["league"], r["home"]))
    live_keys, by_code = set(), {}
    for r in rows:
        for code in r["hits"]:
            by_code[code] = by_code.get(code, 0) + 1
            k = f"{code}|{r['home']}|{r['away']}"
            live_keys.add(k)
            if k not in active_signals:
                log(f"SINYAL {code}: {r['home']} vs {r['away']} | HT {r['ht']} "
                    f"| gol-1 {r['first_goal_min']}' | line awal {r['ko_line']} "
                    f"| sekarang {r['half']} {r['minute']}' {r['score']} "
                    f"| line hidup {r['line']} O{r['over']}/U{r['under']}")
                # Taruhan "≥ N gol di 2H" dipasang sebagai Over pada line
                # full-match = total HT + (N - 0.5). Line itu dicatat supaya
                # nanti bisa dibandingkan dengan line yang benar-benar
                # ditawarkan (r['line']) beserta odds-nya.
                target2h = PATTERN_TARGET_2H.get(code, 2)
                ht_total = ""
                needed_line = ""
                if st_ht_total(r) is not None:
                    ht_total = st_ht_total(r)
                    needed_line = ht_total + target2h - 0.5
                append_signal_log({
                    "logged_at": datetime.now().strftime("%d/%m/%Y %H:%M"),
                    "code": code, "league": r["league"],
                    "home_team": r["home"], "away_team": r["away"],
                    "half": r["half"], "minute": r["minute"],
                    "score": r["score"], "ht": r["ht"], "ht_total": ht_total,
                    "target_2h_goals": target2h, "needed_line": needed_line,
                    "live_line": r["line"], "live_over": r["over"],
                    "live_under": r["under"],
                    "ko_line": r["ko_line"], "ko_over": r["ko_over"],
                    "ko_under": r["ko_under"],
                })
    for gone in active_signals - live_keys:
        code, teams = gone.split("|", 1)
        log(f"Sinyal {code} hilang: {teams.replace('|', ' vs ')}")
    active_signals.clear()
    active_signals.update(live_keys)

    payload = {
        "ts": now_iso(),
        "epoch": int(time.time()),
        "status": status,
        "note": note,
        "started_at": stats["started_at"],
        "poll_sec": POLL_SEC,
        "signal_start_2h_minute": SIGNAL_START_2H_MINUTE,
        "target_url": TARGET_URL,
        "endpoint": ENDPOINT,
        "cycles": stats["cycles"],
        "sent_goals": stats["sent_goals"],
        "sent_matches": stats["sent_matches"],
        "last_post": stats["last_post"],
        "last_error": stats["last_error"],
        "matches": rows,
        "signals": sum(1 for r in rows if r["signal"]),
        "signals_by_code": by_code,
        "patterns": [{"code": p["code"], "desc": p["desc"],
                      "first_goal_min": p.get("first_goal_min"),
                      "first_goal_max": p["first_goal_max"], "min_line": p["min_line"],
                      "max_line": p.get("max_line")}
                     for p in PATTERNS],
        "recent_goals": list(recent_goals),
    }
    # Tulis ke .tmp lalu replace. Di Windows replace kadang gagal karena file
    # sedang dibuka pembaca (PHP/antivirus) -> coba lagi beberapa kali, kalau
    # tidak snapshot lama tertinggal dan live view tampak macet.
    tmp = LIVE_FILE.with_suffix(".json.tmp")
    try:
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False)
    except Exception as e:
        log(f"Gagal tulis live snapshot: {e}")
        return
    for attempt in range(8):
        try:
            os.replace(tmp, LIVE_FILE)
            return
        except OSError as e:
            last = e
            time.sleep(0.05)
    log(f"Gagal tukar live snapshot setelah 8 percobaan: {last}")


def cleanup_stale():
    try:
        if PROFILE_ROOT.exists():
            for c in PROFILE_ROOT.iterdir():
                if c.is_dir() and c.name.startswith("session-") and c != USER_DATA_DIR:
                    shutil.rmtree(c, ignore_errors=True)
    except Exception:
        pass


def run():
    cleanup_stale()   # profil dibuat per-launch di open_ctx()
    log("=" * 60)
    log("V-Soccer Headless Collector (native, tanpa extension)")
    log(f"Target : {TARGET_URL}")
    log(f"Endpoint: {ENDPOINT}")
    log(f"Poll   : {POLL_SEC}s | Reload: {RELOAD_SEC}s")
    log(f"Live   : {LIVE_FILE.name} -> http://localhost/lebihsabar/vsoccer-live.php")
    log("=" * 60)
    stats["started_at"] = now_iso()
    write_live([], [], status="starting", note="Membuka browser headless...")

    with sync_playwright() as p:
        ctx = None
        page = None
        profile_dir = USER_DATA_DIR

        def close_ctx():
            """Tutup context yang ada (kalau masih hidup) & buang profil-nya."""
            nonlocal ctx, page
            try:
                if ctx is not None:
                    ctx.close()
            except Exception:
                pass
            ctx, page = None, None
            shutil.rmtree(profile_dir, ignore_errors=True)

        def open_ctx():
            """Launch browser headless baru dengan profil bersih."""
            nonlocal ctx, page, profile_dir
            close_ctx()
            stats["launches"] += 1
            profile_dir = PROFILE_ROOT / f"session-{os.getpid()}-{stats['launches']}"
            profile_dir.mkdir(parents=True, exist_ok=True)
            ctx = p.chromium.launch_persistent_context(
                user_data_dir=str(profile_dir), headless=False, channel=CHANNEL,
                args=["--headless=new", "--no-sandbox", "--disable-gpu", "--mute-audio",
                      "--disable-blink-features=AutomationControlled"],
            )
            page = ctx.pages[0] if ctx.pages else ctx.new_page()
            log(f"Browser headless dijalankan (launch #{stats['launches']}).")

        def load():
            try:
                log("Membuka halaman target...")
                page.goto(TARGET_URL, wait_until="domcontentloaded", timeout=PAGE_LOAD_TIMEOUT_MS)
                time.sleep(5)
                log("Halaman ter-load.")
                return True
            except Exception as e:
                log(f"Gagal load: {e}")
                return False

        open_ctx()
        load()
        last_reload = time.time()
        while not _stop:
            try:
                if TARGET_HOST not in (page.url or ""):
                    load(); last_reload = time.time(); continue
                events = page.evaluate(SNAPSHOT_JS)
                matches, goals = build_payload(events)
                post(matches, goals)
                stats["cycles"] += 1
                write_live(events, goals)
                if time.time() - last_reload >= RELOAD_SEC:
                    log("Reload terjadwal.")
                    write_live(events, [], status="reloading", note="Reload halaman terjadwal.")
                    page.reload(wait_until="domcontentloaded", timeout=PAGE_LOAD_TIMEOUT_MS)
                    time.sleep(5)
                    last_reload = time.time()
            except Exception as e:
                stats["last_error"] = f"Loop error: {e}"
                write_live([], [], status="recovering", note=str(e)[:200])
                log(f"Loop error: {e}; coba pulih.")
                ok = False
                # 1) coba tab baru di context yang sama (murah)
                try:
                    page = ctx.new_page()
                    ok = load()
                except Exception as e2:
                    log(f"Tab baru gagal: {e2}")
                # 2) browser/context-nya sendiri mati -> relaunch browser
                if not ok and not _stop:
                    log("Relaunch browser headless...")
                    write_live([], [], status="recovering", note="Relaunch browser headless...")
                    try:
                        open_ctx()
                        ok = load()
                    except Exception as e3:
                        stats["last_error"] = f"Relaunch gagal: {e3}"
                        log(f"Relaunch gagal: {e3}")
                last_reload = time.time()
                if not ok:
                    # jeda sebelum percobaan berikutnya (maks 60 detik)
                    stats["fails"] += 1
                    backoff = min(5 * stats["fails"], 60)
                    write_live([], [], status="recovering",
                               note=f"Gagal pulih ({stats['fails']}x), coba lagi {backoff}s lagi.")
                    log(f"Belum pulih; tunggu {backoff}s.")
                    end = time.time() + backoff
                    while time.time() < end and not _stop:
                        time.sleep(0.2)
                else:
                    stats["fails"] = 0
                    log("Pulih.")
            # tidur responsif
            end = time.time() + POLL_SEC
            while time.time() < end and not _stop:
                time.sleep(0.2)

        log("Menutup context.")
        write_live([], [], status="stopped", note="Runner dihentikan.")
        close_ctx()
    shutil.rmtree(USER_DATA_DIR, ignore_errors=True)


if __name__ == "__main__":
    signal.signal(signal.SIGINT, _stop_handler)
    signal.signal(signal.SIGTERM, _stop_handler)
    if hasattr(signal, "SIGBREAK"):
        signal.signal(signal.SIGBREAK, _stop_handler)
    run()
