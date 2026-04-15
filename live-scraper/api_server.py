#!/usr/bin/env python3
"""
API Server untuk Live Scraper
Menerima data dari Chrome Extension dan kirim ke Telegram
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
from datetime import datetime
from telegram_notifier import TelegramNotifier

app = Flask(__name__)
CORS(app)

# Initialize Telegram notifier
notifier = TelegramNotifier()

# Store latest dashboard payload
last_payload = {
    "matches": [],
    "allGoalMinutes": {},
    "allGoalScorers": {},
    "all2HGoalMinutes": {},
    "all2HScorers": {},
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

            # Alert: 2H mulai dan hanya ada 1 goal di babak pertama
            if notifier.should_send_early_goal_2h_start_alert(match):
                notifier.check_and_alert_early_goal_2h_start(match)
                continue

            # Alert: 2H 2'+ dan skor masih 0-0
            is_second_half_zero_zero = notifier.should_send_second_half_zero_zero_alert(
                match
            )
            if is_second_half_zero_zero:
                notifier.check_and_alert_second_half_zero_zero(match)
                continue

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
    response["timestamp"] = datetime.now().isoformat()
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
    print("\nServer running on http://127.0.0.1:5000")
    print("=" * 60)

    app.run(host="127.0.0.1", port=5000, debug=False)
