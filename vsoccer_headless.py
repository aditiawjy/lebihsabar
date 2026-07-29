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

TARGET_HOST = "1x2aaa.com"
TARGET_URL = os.environ.get(
    "VSOCCER_URL",
    "https://prod20191-101527338.1x2aaa.com/en/asian-view/today/Virtual-Soccer",
)
ENDPOINT = os.environ.get("VSOCCER_ENDPOINT", "http://localhost/lebihsabar/goal-log-save-vsoccer.php")
CHANNEL = os.environ.get("VSOCCER_CHANNEL", "chrome") or None

# Sinyal babak kedua. Semua pattern: aktif hanya selama babak kedua dan langsung
# hilang begitu ada gol di babak kedua.
#   SUPER = gol pertama <= 8' + line awal >= 5.5/6 (=5.75) + HT tidak seri;
#           kalau HT seri, boleh asal gol kedua <= 25' dan gol terakhir 1H >= 35'
#   P1    = selisih HT tepat 1 + gol pertama <= 12' + line awal >= 5.5/6 (=5.75)
#   P2    = HT tepat 2-1 / 1-2 + gol pertama <= 15' + line awal >= 5.5
SUPER_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER_FIRST_GOAL_MAX", "8"))
SUPER_MIN_LINE = float(os.environ.get("VSOCCER_SUPER_MIN_LINE", "5.75"))
SUPER_SECOND_GOAL_MAX = int(os.environ.get("VSOCCER_SUPER_SECOND_GOAL_MAX", "25"))
SUPER_LAST_1H_MIN = int(os.environ.get("VSOCCER_SUPER_LAST_1H_MIN", "35"))
P1_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P1_FIRST_GOAL_MAX", "12"))
P1_MIN_LINE = float(os.environ.get("VSOCCER_P1_MIN_LINE", "5.75"))
P2_FIRST_GOAL_MAX = int(os.environ.get("VSOCCER_P2_FIRST_GOAL_MAX", "15"))
P2_MIN_LINE = float(os.environ.get("VSOCCER_P2_MIN_LINE", "5.5"))

PATTERNS = [
    {"code": "SUPER",
     "desc": f"HT tidak seri (kalau seri: gol-2 ≤ {SUPER_SECOND_GOAL_MAX}' "
             f"dan gol terakhir 1H ≥ {SUPER_LAST_1H_MIN}')",
     "ht": "super", "first_goal_max": SUPER_FIRST_GOAL_MAX, "min_line": SUPER_MIN_LINE},
    {"code": "P1", "desc": "selisih HT tepat 1", "ht": "diff1",
     "first_goal_max": P1_FIRST_GOAL_MAX, "min_line": P1_MIN_LINE},
    {"code": "P2", "desc": "HT 2-1 / 1-2", "ht": "21",
     "first_goal_max": P2_FIRST_GOAL_MAX, "min_line": P2_MIN_LINE},
]

POLL_SEC = float(os.environ.get("VSOCCER_POLL_SEC", "2.5"))
RELOAD_SEC = int(os.environ.get("VSOCCER_RELOAD_SEC", str(20 * 60)))
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
    if not st.get("track"):
        return False, "tidak dilacak dari kickoff"
    ht_h, ht_a = st.get("ht_h"), st.get("ht_a")
    if ht_h is None or ht_a is None:
        return False, "skor HT tak terekam"
    if pat["ht"] == "diff1" and abs(ht_h - ht_a) != 1:
        return False, f"selisih HT {abs(ht_h - ht_a)}"
    if pat["ht"] == "21" and {ht_h, ht_a} != {1, 2}:
        return False, f"HT {ht_h}-{ht_a}"
    if pat["ht"] == "super" and ht_h == ht_a:
        # HT seri masih boleh, asal gol kedua cepat & gol terakhir 1H terjadi telat.
        g1h = st.get("goal_mins_1h") or []
        if len(g1h) < 2:
            return False, f"HT seri {ht_h}-{ht_a}, gol 1H cuma {len(g1h)}"
        if g1h[1] > SUPER_SECOND_GOAL_MAX:
            return False, f"HT seri, gol kedua {g1h[1]}'"
        if g1h[-1] < SUPER_LAST_1H_MIN:
            return False, f"HT seri, gol terakhir 1H {g1h[-1]}'"
    fg = st.get("first_goal_min")
    if fg is None:
        return False, "belum ada gol"
    if fg > pat["first_goal_max"]:
        return False, f"gol pertama {fg}'"
    lv = line_value(st.get("ko_line"))
    if lv is None:
        return False, "line awal tak ada"
    if lv < pat["min_line"]:
        return False, f"line awal {st.get('ko_line')}"
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
                          "goal_mins_1h": []}
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
                    f"| sekarang {r['half']} {r['minute']}' {r['score']}")
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
                      "first_goal_max": p["first_goal_max"], "min_line": p["min_line"]}
                     for p in PATTERNS],
        "recent_goals": list(recent_goals),
    }
    tmp = LIVE_FILE.with_suffix(".json.tmp")
    try:
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False)
        os.replace(tmp, LIVE_FILE)
    except Exception as e:
        log(f"Gagal tulis live snapshot: {e}")


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
