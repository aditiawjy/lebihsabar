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
import time
import shutil
import signal
from datetime import datetime
from pathlib import Path

import requests
from playwright.sync_api import sync_playwright

BASE_DIR = Path(__file__).resolve().parent
PROFILE_ROOT = BASE_DIR / ".vsoccer-profile"
USER_DATA_DIR = PROFILE_ROOT / f"session-{os.getpid()}"
LOG_FILE = BASE_DIR / "vsoccer_headless.log"

TARGET_HOST = "1x2aaa.com"
TARGET_URL = os.environ.get(
    "VSOCCER_URL",
    "https://prod20191-101527338.1x2aaa.com/en/asian-view/today/Virtual-Soccer",
)
ENDPOINT = os.environ.get("VSOCCER_ENDPOINT", "http://localhost/lebihsabar/goal-log-save-vsoccer.php")
CHANNEL = os.environ.get("VSOCCER_CHANNEL", "chrome") or None

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
            state[key] = {"home": e["h"], "away": e["a"], "track": track, "ko": ko_ok}
            if track:
                matches.append({
                    "league": e["league"], "home_team": e["home"], "away_team": e["away"],
                    "home_score": "0", "away_score": "0",
                    "ko_line": e["line"] if ko_ok else "", "ko_over": ko_over, "ko_under": ko_under,
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
            matches.append({
                "league": e["league"], "home_team": e["home"], "away_team": e["away"],
                "home_score": "0", "away_score": "0",
                "ko_line": e["line"], "ko_over": ko_over, "ko_under": ko_under,
                "timestamp": now_iso(),
            })
        jump = (e["h"] - st["home"]) + (e["a"] - st["away"])
        accurate = 1 if jump <= 2 else 0
        minute_str = f"{e['half']} {max(e['minute'], 0)}'"
        ch, ca = st["home"], st["away"]
        while ch < e["h"] or ca < e["a"]:
            if ch < e["h"]:
                ch += 1; side = "home"
            else:
                ca += 1; side = "away"
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
        log(f"kirim {len(goals)} gol, {len(matches)} match -> {r.status_code} {r.text[:120]}")
    except Exception as e:
        log(f"POST gagal: {e}")


def cleanup_stale():
    try:
        if PROFILE_ROOT.exists():
            for c in PROFILE_ROOT.iterdir():
                if c.is_dir() and c.name.startswith("session-") and c != USER_DATA_DIR:
                    shutil.rmtree(c, ignore_errors=True)
    except Exception:
        pass


def run():
    cleanup_stale()
    USER_DATA_DIR.mkdir(parents=True, exist_ok=True)
    log("=" * 60)
    log("V-Soccer Headless Collector (native, tanpa extension)")
    log(f"Target : {TARGET_URL}")
    log(f"Endpoint: {ENDPOINT}")
    log(f"Poll   : {POLL_SEC}s | Reload: {RELOAD_SEC}s")
    log("=" * 60)

    with sync_playwright() as p:
        ctx = p.chromium.launch_persistent_context(
            user_data_dir=str(USER_DATA_DIR), headless=False, channel=CHANNEL,
            args=["--headless=new", "--no-sandbox", "--disable-gpu", "--mute-audio",
                  "--disable-blink-features=AutomationControlled"],
        )
        page = ctx.pages[0] if ctx.pages else ctx.new_page()

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

        load()
        last_reload = time.time()
        while not _stop:
            try:
                if TARGET_HOST not in (page.url or ""):
                    load(); last_reload = time.time(); continue
                events = page.evaluate(SNAPSHOT_JS)
                matches, goals = build_payload(events)
                post(matches, goals)
                if time.time() - last_reload >= RELOAD_SEC:
                    log("Reload terjadwal.")
                    page.reload(wait_until="domcontentloaded", timeout=PAGE_LOAD_TIMEOUT_MS)
                    time.sleep(5)
                    last_reload = time.time()
            except Exception as e:
                log(f"Loop error: {e}; coba pulih.")
                try:
                    page = ctx.new_page(); load(); last_reload = time.time()
                except Exception as e2:
                    log(f"Gagal pulih: {e2}"); time.sleep(5)
            # tidur responsif
            end = time.time() + POLL_SEC
            while time.time() < end and not _stop:
                time.sleep(0.2)

        log("Menutup context.")
        try:
            ctx.close()
        except Exception:
            pass
    shutil.rmtree(USER_DATA_DIR, ignore_errors=True)


if __name__ == "__main__":
    signal.signal(signal.SIGINT, _stop_handler)
    signal.signal(signal.SIGTERM, _stop_handler)
    if hasattr(signal, "SIGBREAK"):
        signal.signal(signal.SIGBREAK, _stop_handler)
    run()
