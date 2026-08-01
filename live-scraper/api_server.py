#!/usr/bin/env python3
"""
API Server untuk Live Scraper
Menerima data dari Chrome Extension dan kirim ke Telegram
"""

import os
import sys
import re
import threading
import csv
import json
import time
import signal
import subprocess
from pathlib import Path

from flask import Flask, request, jsonify
from flask_cors import CORS
from datetime import datetime
from telegram_notifier import TelegramNotifier
from telegram_config import ALERT_SETTINGS, TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID

app = Flask(__name__)
CORS(app)

# Initialize Telegram notifier
notifier = TelegramNotifier()

# Aturan alert (selaras constants.js extension): Over 0.75 >= 1.95.
TG_TARGET_SELECTION = os.environ.get("TG_TARGET_SELECTION", "o0.75")
TG_TARGET_MIN = float(os.environ.get("TG_TARGET_MIN", "1.95"))


@app.route("/api/telegram/status", methods=["GET"])
def api_telegram_status():
    chat = str(TELEGRAM_CHAT_ID or "")
    return jsonify({
        "configured": bool(TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID),
        "chat_id_masked": ("***" + chat[-4:]) if len(chat) >= 4 else chat,
        "settings": ALERT_SETTINGS,
        "target_selection": TG_TARGET_SELECTION,
        "target_min": TG_TARGET_MIN,
        "tracked_matches": len(notifier.match_score_history),
    })

# --- Kelola proses headless scraper (start/stop dari dashboard) -----------
BASE_DIR = Path(__file__).resolve().parent
RUNNER_SCRIPT = BASE_DIR / "headless_runner.py"
DEFAULT_URL = os.environ.get("BPVM_URL", "https://g943gp.bpvmr7u6.com/")
AUTO_START_SCRAPER = os.environ.get("AUTO_START_SCRAPER", "1") != "0"

_scraper = {"proc": None, "url": DEFAULT_URL, "started_at": None}


def scraper_running() -> bool:
    p = _scraper["proc"]
    return p is not None and p.poll() is None


def start_scraper(url: str = None):
    if scraper_running():
        return False, "Scraper sudah berjalan"
    if not RUNNER_SCRIPT.exists():
        return False, f"Tidak menemukan {RUNNER_SCRIPT.name}"

    target = (url or _scraper["url"] or DEFAULT_URL).strip()
    env = dict(os.environ)
    browsers = BASE_DIR / ".playwright"
    if browsers.exists():
        env["PLAYWRIGHT_BROWSERS_PATH"] = str(browsers)
    env["BPVM_URL"] = target

    flags = 0
    if os.name == "nt":
        flags = subprocess.CREATE_NEW_PROCESS_GROUP  # agar bisa kirim CTRL_BREAK

    proc = subprocess.Popen(
        [sys.executable, str(RUNNER_SCRIPT)],
        cwd=str(BASE_DIR),
        env=env,
        creationflags=flags,
    )
    _scraper.update(proc=proc, url=target, started_at=datetime.now().isoformat())
    return True, "Scraper dijalankan"


def stop_scraper():
    if not scraper_running():
        _scraper["proc"] = None
        return False, "Scraper tidak berjalan"

    proc = _scraper["proc"]
    try:
        if os.name == "nt":
            proc.send_signal(signal.CTRL_BREAK_EVENT)
        else:
            proc.terminate()
        try:
            proc.wait(timeout=12)
        except subprocess.TimeoutExpired:
            proc.kill()
    except Exception:
        try:
            proc.kill()
        except Exception:
            pass
    finally:
        _scraper["proc"] = None
    return True, "Scraper dihentikan"


def scraper_status():
    return {
        "running": scraper_running(),
        "url": _scraper["url"],
        "started_at": _scraper["started_at"],
    }


@app.route("/api/scraper/status", methods=["GET"])
def api_scraper_status():
    return jsonify(scraper_status())


@app.route("/api/scraper/start", methods=["POST"])
def api_scraper_start():
    data = request.get_json(silent=True) or {}
    ok, msg = start_scraper(data.get("url"))
    return jsonify({"success": ok, "message": msg, **scraper_status()})


@app.route("/api/scraper/stop", methods=["POST"])
def api_scraper_stop():
    ok, msg = stop_scraper()
    return jsonify({"success": ok, "message": msg, **scraper_status()})

# --- Logging match selesai ke CSV (ala goal_log_vsoccer.csv) -----------------
# Match dianggap selesai saat hilang dari payload dashboard >= FINALIZE_AFTER_SEC
# (tahan gap reload halaman). Ditulis sekali per match (dedup in-memory + isi file).
BPVM_GOAL_LOG = BASE_DIR.parent / "goal_log_bpvm.csv"
BPVM_FINALIZE_AFTER_SEC = 90
# Liga yang tidak ikut dicatat maupun ditampilkan. Dicocokkan sebagai potongan
# teks tanpa peduli huruf besar/kecil, supaya variasi penamaan kecil tetap kena.
# Override lewat env: BPVM_EXCLUDE_LEAGUES="teks satu;teks dua"
BPVM_EXCLUDED_LEAGUES = [
    s.strip().lower()
    for s in os.environ.get(
        "BPVM_EXCLUDE_LEAGUES",
        "SABA ELITE FRIENDLY Virtual PES 21 - 5 Mins Play",
    ).split(";")
    if s.strip()
]


def _bpvm_league_excluded(league):
    name = str(league or "").lower()
    return any(bad in name for bad in BPVM_EXCLUDED_LEAGUES)


def _bpvm_filter_matches(matches):
    """Buang match dari liga yang dikecualikan sebelum disimpan/ditampilkan."""
    return [m for m in (matches or []) if not _bpvm_league_excluded(m.get("league"))]


_bpvm_seen = {}     # matchKey -> snapshot terakhir
_bpvm_missing = {}  # matchKey -> waktu pertama hilang
_bpvm_logged = set()


def _bpvm_key(m):
    """Replika createMatchKey() extension: JSON compact {league, teams}."""
    teams = m.get("teams") or f"{m.get('homeTeam') or 'Unknown'} vs {m.get('awayTeam') or 'Unknown'}"
    return json.dumps({"league": m.get("league") or "N/A", "teams": teams},
                      ensure_ascii=False, separators=(",", ":"))


def _bpvm_pick_odds(m, prefix):
    for s in m.get("odds") or []:
        s = str(s)
        if s.startswith(prefix + ":"):
            nilai = s[len(prefix) + 2:]
            # Situs mengunci market (close-price) tepat saat gol dan beberapa
            # detik sesudahnya; extension menulisnya sebagai [LOCKED]. Itu bukan
            # harga, jadi diperlakukan sebagai "belum ada" supaya polling
            # berikutnya bisa mengisinya dengan angka sungguhan.
            if "[LOCKED]" in nilai:
                return ""
            return nilai
    return ""


BPVM_HEADER = ["datetime", "league", "home_team", "away_team", "ht",
               "final_home", "final_away", "goal_minutes", "ou_ft", "ou_1h",
               # ht_* = market + odds pada saat status "H.Time". ou_ft/ou_1h di
               # atas adalah snapshot terakhir (menjelang match bubar), bukan
               # harga yang bisa dipasang di jeda babak.
               "ht_market", "ht_ou_ft", "ht_ou_1h",
               # ko_* = market + odds + skor pada awal match (status 1H 0'/1').
               # Dipakai untuk membandingkan harga kickoff vs harga jeda babak.
               "ko_market", "ko_ou_ft", "ko_ou_1h", "ko_score",
               # Market + odds pada DETIK tiap gol terjadi, dipisah " | ".
               # Formatnya menyusul goal_markets di goal_log_vsoccer.csv.
               "goal_markets"]


# Flask dev server melayani permintaan secara paralel, jadi dua POST dashboard
# bisa menulis CSV bersamaan. Semua akses tulis dilindungi satu kunci.
_bpvm_file_lock = threading.Lock()


def _bpvm_migrate_header():
    """Tambahkan kolom baru ke file lama tanpa mengubah isi baris lama."""
    if not BPVM_GOAL_LOG.exists():
        return
    try:
        with open(BPVM_GOAL_LOG, newline="", encoding="utf-8-sig") as fh:
            rows = list(csv.reader(fh))
    except Exception:
        return
    if not rows or rows[0] == BPVM_HEADER:
        return
    missing = len(BPVM_HEADER) - len(rows[0])
    if missing <= 0:
        return

    # Tulis ke file sementara lalu ganti secara atomik. Versi sebelumnya membuka
    # file tujuan dengan mode "w" -- itu memotong isinya lebih dulu, sehingga
    # gangguan sekecil apa pun di tengah proses (atau pembaca lain yang kebetulan
    # membaca saat itu) meninggalkan file berisi header saja. Data pernah hilang
    # karena ini.
    tmp = BPVM_GOAL_LOG.with_suffix(".csv.tmp")
    try:
        with open(tmp, "w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            w.writerow(BPVM_HEADER)
            for r in rows[1:]:
                w.writerow(r + [""] * (len(BPVM_HEADER) - len(r)))
            fh.flush()
            os.fsync(fh.fileno())

        # Jaring pengaman: hasil migrasi tidak boleh kehilangan satu baris pun.
        with open(tmp, newline="", encoding="utf-8-sig") as fh:
            hasil = list(csv.reader(fh))
        if len(hasil) < len(rows):
            print(f"[GOALLOG] migrasi DIBATALKAN: {len(hasil)} baris < {len(rows)} baris asal")
            tmp.unlink(missing_ok=True)
            return

        os.replace(tmp, BPVM_GOAL_LOG)
        print(f"[GOALLOG] header {BPVM_GOAL_LOG.name} dimigrasi (+{missing} kolom, {len(rows) - 1} baris utuh)")
    except Exception as e:
        tmp.unlink(missing_ok=True)
        print(f"[GOALLOG] gagal migrasi header: {e}")


def _bpvm_load_existing():
    if _bpvm_logged or not BPVM_GOAL_LOG.exists():
        return
    try:
        with open(BPVM_GOAL_LOG, newline="", encoding="utf-8-sig") as fh:
            for row in csv.DictReader(fh):
                _bpvm_logged.add((row.get("league"), row.get("home_team"), row.get("away_team"),
                                  row.get("final_home"), row.get("final_away"), row.get("goal_minutes")))
    except Exception:
        pass


def _bpvm_write_row(snap):
    # Jaring pengaman: snapshot liga yang dikecualikan bisa masih tersisa di
    # memori dari sebelum filter dipasang / sebelum konfigurasi berubah.
    if _bpvm_league_excluded(snap.get("league")):
        return

    parts = str(snap["score"] or "0 - 0").split("-")
    fh_score = parts[0].strip() if parts else "0"
    fa_score = parts[1].strip() if len(parts) > 1 else "0"

    def fmt_mins(vals, half):
        out = []
        for x in vals or []:
            try:
                out.append(f"{half}H {int(x)}'")
            except (TypeError, ValueError):
                pass
        return out

    goal_minutes = " | ".join(fmt_mins(snap["g1"], 1) + fmt_mins(snap["g2"], 2))

    # Syarat masuk: match harus terlacak sejak kickoff (status 1H 0'/1') dan
    # market awalnya terekam. Tanpa harga awal, match tidak bisa dianalisis --
    # tidak ada patokan untuk membandingkan pergerakan harga, dan biasanya
    # skor HT serta menit gol babak pertama ikut hilang karena match baru
    # terlihat saat sudah berjalan. Match seperti itu dianggap rusak.
    if not str(snap.get("ko_market") or "").strip():
        print(f"[GOALLOG] ditolak (tanpa market kickoff, match tidak terlacak "
              f"dari awal): {snap['home']} vs {snap['away']} {fh_score}-{fa_score}")
        return

    ident = (snap["league"], snap["home"], snap["away"], fh_score, fa_score, goal_minutes)
    _bpvm_load_existing()
    if ident in _bpvm_logged:
        return

    with _bpvm_file_lock:
        _bpvm_write_row_locked(snap, fh_score, fa_score, goal_minutes, ident)


def _bpvm_write_row_locked(snap, fh_score, fa_score, goal_minutes, ident):
    _bpvm_migrate_header()
    new_file = not BPVM_GOAL_LOG.exists()
    try:
        with open(BPVM_GOAL_LOG, "a", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            if new_file:
                w.writerow(BPVM_HEADER)
            w.writerow([datetime.now().strftime("%d/%m/%Y %H:%M"), snap["league"], snap["home"],
                        snap["away"], snap["ht"], fh_score, fa_score, goal_minutes,
                        snap["ou_ft"], snap["ou_1h"],
                        snap.get("ht_market", ""), snap.get("ht_ou_ft", ""), snap.get("ht_ou_1h", ""),
                        snap.get("ko_market", ""), snap.get("ko_ou_ft", ""), snap.get("ko_ou_1h", ""),
                        snap.get("ko_score", ""),
                        _bpvm_format_goal_markets(snap.get("goal_markets"))])
        _bpvm_logged.add(ident)
        print(f"[GOALLOG] {snap['home']} vs {snap['away']} {fh_score}-{fa_score} -> {BPVM_GOAL_LOG.name}")
    except Exception as e:
        print(f"[GOALLOG] gagal tulis: {e}")


def _bpvm_is_halftime(status):
    """Status jeda babak di situs SABA ditulis "H.Time"."""
    return bool(re.match(r"^h\.?\s*time$", str(status or "").strip(), re.I))


def _bpvm_is_kickoff(status):
    """Awal match: "1H 0'" atau "1H 1'" (selaras isKickoffMinute() extension)."""
    return bool(re.match(r"^1H\s+[01]'$", str(status or "").strip(), re.I))


def _bpvm_goal_market_entries(prev, fresh):
    """Catat market + odds pada saat gol baru terdeteksi.

    Payload hanya membawa DAFTAR menit gol, bukan event per gol. Jadi gol baru
    dikenali dari daftar yang bertambah panjang dibanding snapshot sebelumnya,
    lalu odds yang sedang berlaku saat itu ikut direkam. Tanpa ini, satu-satunya
    odds yang tersimpan adalah odds kickoff, jeda babak, dan akhir match --
    pergerakan harga tepat saat gol tidak terlihat sama sekali.
    """
    entries = [dict(e) for e in ((prev or {}).get("goal_markets") or [])]
    market = "FT. O/U" if fresh["ou_ft"] else ("1H. O/U" if fresh["ou_1h"] else "")
    odds = fresh["ou_ft"] or fresh["ou_1h"] or ""
    score = str(fresh.get("score") or "").replace(" ", "")

    # Market biasanya terkunci persis saat gol, jadi entri baru sering lahir
    # tanpa harga. Isi susulan pada polling pertama yang harganya sudah terbuka,
    # dan tandai "+" supaya jelas itu harga sesaat SESUDAH gol, bukan saat gol.
    if market and odds:
        for e in entries:
            if not e.get("odds"):
                e["market"] = market
                e["odds"] = odds
                e["late"] = True

    for field, half in (("g1", 1), ("g2", 2)):
        old_n = len((prev or {}).get(field) or [])
        for minute in (fresh.get(field) or [])[old_n:]:
            try:
                minute = int(minute)
            except (TypeError, ValueError):
                continue
            entries.append({"half": half, "minute": minute, "score": score,
                            "market": market, "odds": odds, "late": False})
    return entries


def _bpvm_format_goal_markets(entries):
    """Ubah entri gol jadi satu teks CSV. Entri tanpa harga tetap ditulis
    menitnya, supaya jelas golnya ada tapi market sedang tertutup."""
    out = []
    for e in entries or []:
        bagian = f"{e.get('half')}H {e.get('minute')}' ({e.get('score')})"
        if e.get("market") and e.get("odds"):
            bagian += f" {e['market']}{'+' if e.get('late') else ''} {e['odds']}"
        out.append(bagian)
    return " | ".join(out)


def _bpvm_half_length(league):
    """Panjang satu babak dalam menit, dari nama liga ("15 Mins Play" -> 7,5)."""
    m = re.search(r"(\d+)\s*Mins\s*Play", str(league or ""), re.I)
    if not m:
        return None
    durasi = float(m.group(1))
    return durasi / 2 if durasi > 0 else None


def _bpvm_remaining_seconds(snap):
    """Perkiraan sisa waktu match dari status terakhir yang terlihat.

    Match hilang dari daftar bukan berarti selesai -- liga bisa tertutup lagi,
    halaman bisa reload, atau slide sedang bergeser. Dulu absen 90 detik saja
    sudah cukup untuk membekukan match, sehingga pertandingan yang masih jalan
    di menit 2H 6' ikut ditulis sebagai selesai. Sekarang penantian minimal
    ditambah sisa waktu match, jadi hanya match yang memang sudah waktunya
    bubar yang difinalisasi.
    """
    halfLen = _bpvm_half_length(snap.get("league"))
    if not halfLen:
        return 0

    status = str(snap.get("status") or "").strip()
    if _bpvm_is_halftime(status):
        return int(halfLen * 60)

    m = re.match(r"^(1H|2H)\s+(\d+)'?$", status, re.I)
    if not m:
        return 0
    babak = m.group(1).upper()
    menit = float(m.group(2))
    sisa = (halfLen - menit) + halfLen if babak == "1H" else halfLen - menit
    # Dibatasi supaya baris tidak tertahan selamanya kalau statusnya aneh.
    return int(max(0.0, min(sisa, 25.0)) * 60)


def _bpvm_score_total(score):
    total = 0
    for part in str(score or "0 - 0").split("-"):
        try:
            total += int(part.strip())
        except ValueError:
            pass
    return total


def _bpvm_merge_snapshot(old, new):
    """Gabungkan snapshot lama dan baru untuk RONDE yang sama.

    Kunci match cuma liga+nama tim, tanpa waktu, sedangkan match virtual memakai
    pasangan tim yang sama berulang kali. Extension juga sesekali me-reset state
    satu match di tengah jalan. Akibatnya snapshot bisa berubah jadi lebih miskin
    (menit gol / skor HT / odds mendadak kosong) tepat sebelum baris ditulis, dan
    datanya hilang. Karena itu nilai yang sudah terisi tidak boleh ditimpa yang
    kosong, dan daftar menit gol yang lebih panjang selalu dimenangkan.
    """
    if not old:
        return new
    merged = dict(new)
    for field in ("g1", "g2", "goal_markets"):
        if len(old.get(field) or []) > len(new.get(field) or []):
            merged[field] = old[field]
    # ht_* hanya terisi pada satu momen singkat (status "H.Time"), jadi wajib
    # dikunci -- kalau tidak, snapshot menit berikutnya langsung mengosongkannya.
    for field in ("ht", "ou_ft", "ou_1h", "ht_market", "ht_ou_ft", "ht_ou_1h",
                  "ko_market", "ko_ou_ft", "ko_ou_1h", "ko_score", "status"):
        if not str(new.get(field) or "").strip() and str(old.get(field) or "").strip():
            merged[field] = old[field]
    return merged


def _update_bpvm_goal_log(payload):
    matches = payload.get("matches") or []
    ht_scores = payload.get("htScores") or {}
    g1 = payload.get("allGoalMinutes") or {}
    g2 = payload.get("all2HGoalMinutes") or {}
    now = time.time()

    current = set()
    for m in matches:
        key = _bpvm_key(m)
        current.add(key)
        fresh = {
            "league": m.get("league") or "",
            "home": m.get("homeTeam") or "",
            "away": m.get("awayTeam") or "",
            "score": m.get("score") or "0 - 0",
            # Status terakhir dipakai memperkirakan sisa waktu saat match hilang
            # dari daftar; tanpa itu match yang masih jalan ikut difinalisasi.
            "status": m.get("status") or "",
            "ht": ht_scores.get(key) or "",
            "g1": g1.get(key) or [],
            "g2": g2.get(key) or [],
            "ou_ft": _bpvm_pick_odds(m, "FT. O/U"),
            "ou_1h": _bpvm_pick_odds(m, "1H. O/U"),
            # Odds saat turun minum. ou_ft/ou_1h di atas selalu ikut snapshot
            # terakhir, jadi isinya odds menjelang match bubar -- bukan harga
            # yang bisa dipasang di jeda babak. Dua kolom ini merekam momen
            # status "H.Time" saja, lalu dikunci oleh _bpvm_merge_snapshot().
            "ht_ou_ft": "",
            "ht_ou_1h": "",
            "ht_market": "",
            "ko_ou_ft": "",
            "ko_ou_1h": "",
            "ko_market": "",
            "ko_score": "",
        }
        if _bpvm_is_halftime(m.get("status")):
            fresh["ht_ou_ft"] = fresh["ou_ft"]
            fresh["ht_ou_1h"] = fresh["ou_1h"]
            fresh["ht_market"] = "FT. O/U" if fresh["ou_ft"] else ("1H. O/U" if fresh["ou_1h"] else "")
        if _bpvm_is_kickoff(m.get("status")):
            fresh["ko_ou_ft"] = fresh["ou_ft"]
            fresh["ko_ou_1h"] = fresh["ou_1h"]
            fresh["ko_market"] = "FT. O/U" if fresh["ou_ft"] else ("1H. O/U" if fresh["ou_1h"] else "")
            fresh["ko_score"] = fresh["score"]
        prev = _bpvm_seen.get(key)
        # Gol baru dikenali dari daftar menit yang bertambah; odds saat itu
        # direkam sebelum snapshot lama ditimpa.
        if prev and _bpvm_score_total(fresh["score"]) >= _bpvm_score_total(prev["score"]):
            fresh["goal_markets"] = _bpvm_goal_market_entries(prev, fresh)
        else:
            fresh["goal_markets"] = _bpvm_goal_market_entries(None, fresh)
        # Ronde baru = pasangan tim yang sama main lagi dari awal. Ronde lama
        # harus ditulis dulu, kalau tidak hasilnya tertimpa dan hilang tanpa
        # jejak -- tidak akan pernah tertangkap jalur "hilang 90 detik" karena
        # key-nya tidak pernah menghilang.
        #
        # Syaratnya TIDAK cukup "skor turun". Saat turun minum situs sesekali
        # menampilkan skor kosong/0-0 sesaat; dulu itu ikut dianggap ronde baru,
        # sehingga match dibekukan di H.Time dan skor babak kedua tidak pernah
        # tercatat (43% baris berakhir dengan FT == HT). Karena itu status harus
        # benar-benar menunjukkan kickoff.
        turun = prev and _bpvm_score_total(fresh["score"]) < _bpvm_score_total(prev["score"])
        if turun and _bpvm_is_kickoff(m.get("status")):
            _bpvm_write_row(prev)
            _bpvm_seen[key] = fresh
        elif turun:
            # Skor mundur tanpa kickoff = pembacaan meleset. Pertahankan angka
            # tertinggi yang pernah terlihat, jangan turunkan.
            fresh["score"] = prev["score"]
            _bpvm_seen[key] = _bpvm_merge_snapshot(prev, fresh)
        else:
            _bpvm_seen[key] = _bpvm_merge_snapshot(prev, fresh)
        _bpvm_missing.pop(key, None)

    for key in list(_bpvm_seen):
        if key in current:
            continue
        _bpvm_missing.setdefault(key, now)
        snap = _bpvm_seen[key]
        tunggu = BPVM_FINALIZE_AFTER_SEC + _bpvm_remaining_seconds(snap)
        if now - _bpvm_missing[key] < tunggu:
            continue
        _bpvm_seen.pop(key, None)
        _bpvm_missing.pop(key, None)
        _bpvm_write_row(snap)


# Store latest dashboard payload
last_payload = {
    "matches": [],
    "allGoalMinutes": {},
    "allGoalScorers": {},
    "all2HGoalMinutes": {},
    "all2HScorers": {},
    "kickoffTimes": {},
    "patternSignals": {},
    "htScores": {},
    "timestamp": None,
}


@app.route("/api/live-data", methods=["POST"])
def receive_live_data():
    """Terima data untuk notifikasi Telegram"""
    try:
        data = request.get_json() or {}
        matches = data.get("matches", [])

        # Heartbeat: extension mem-post ke endpoint ini tiap siklus (bahkan saat
        # 0 match, karena POST dashboard di-skip bila kosong). Cap timestamp di
        # sini juga supaya halaman live tidak terlihat STALE padahal scraper hidup.
        last_payload["timestamp"] = datetime.now().isoformat()

        # Kirim notifikasi untuk match baru atau update
        for match in matches:
            # Selalu track perubahan score untuk rekam menit goal
            notifier.track_goal_minutes(match)

        return jsonify(
            {
                "success": True,
                "message": f"Received {len(matches)} matches",
                "timestamp": datetime.now().isoformat(),
            }
        )
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500


@app.route("/api/dashboard-live-data", methods=["POST"])
def receive_dashboard_live_data():
    """Terima data live lengkap untuk dashboard tanpa kirim Telegram."""
    try:
        data = request.get_json() or {}
        matches = _bpvm_filter_matches(data.get("matches", []))

        global last_payload
        last_payload = {
            "matches": matches,
            "allGoalMinutes": data.get("allGoalMinutes", {}) or {},
            "allGoalScorers": data.get("allGoalScorers", {}) or {},
            "all2HGoalMinutes": data.get("all2HGoalMinutes", {}) or {},
            "all2HScorers": data.get("all2HScorers", {}) or {},
            "kickoffTimes": data.get("kickoffTimes", {}) or {},
            "patternSignals": data.get("patternSignals", {}) or {},
            "htScores": data.get("htScores", {}) or {},
            "timestamp": data.get("timestamp") or datetime.now().isoformat(),
        }
        _update_bpvm_goal_log(last_payload)

        return jsonify(
            {
                "success": True,
                "message": f"Stored {len(matches)} dashboard matches",
                "timestamp": datetime.now().isoformat(),
            }
        )
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500


@app.route("/api/live-data", methods=["GET"])
def get_live_data():
    """Ambil data match terakhir"""
    response = dict(last_payload)
    response["count"] = len(last_payload.get("matches", []))
    # timestamp = waktu data diterima dari extension (jangan ditimpa waktu serve,
    # supaya data basi tidak terlihat fresh); served_at = waktu respons ini.
    response["served_at"] = datetime.now().isoformat()
    return jsonify(response)


@app.route("/api/test-telegram", methods=["POST"])
def test_telegram():
    """Test kirim pesan ke Telegram"""
    success = notifier.send_test_message()
    return jsonify(
        {
            "success": success,
            "message": "Test message sent" if success else "Failed to send",
        }
    )


@app.route("/api/status", methods=["GET"])
def get_status():
    """Cek status server"""
    return jsonify(
        {
            "status": "online",
            "timestamp": datetime.now().isoformat(),
            "matches_count": len(last_payload.get("matches", [])),
        }
    )


if __name__ == "__main__":
    print("=" * 60)
    print("Live Scraper API Server")
    print("=" * 60)
    print("\nEndpoints:")
    print("  POST /api/live-data              - Kirim data notifikasi Telegram")
    print("  POST /api/dashboard-live-data    - Simpan data live untuk dashboard")
    print("  GET  /api/live-data              - Ambil data match dashboard")
    print("  POST /api/test-telegram          - Test Telegram")
    print("  GET  /api/status                 - Cek status")
    print("  GET  /api/scraper/status         - Status headless scraper")
    print("  POST /api/scraper/start|stop     - Start/stop scraper")
    print("\nServer running on http://127.0.0.1:5000")
    print("=" * 60)

    if AUTO_START_SCRAPER:
        ok, msg = start_scraper()
        print(f"[scraper] {msg}")

    try:
        app.run(host="127.0.0.1", port=5000, debug=False)
    finally:
        if scraper_running():
            stop_scraper()
            print("[scraper] dihentikan saat server ditutup.")
