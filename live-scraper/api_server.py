#!/usr/bin/env python3
"""
API Server untuk Live Scraper
Menerima data dari Chrome Extension dan kirim ke Telegram
"""

import os
import sys
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
        matches = data.get("matches", [])

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
