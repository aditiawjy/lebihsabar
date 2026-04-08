#!/usr/bin/env python3
"""
Telegram Notifier untuk Live Scraper
"""

import requests
import re
from datetime import datetime
from telegram_config import TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, ALERT_SETTINGS


class TelegramNotifier:
    def __init__(self):
        self.bot_token = TELEGRAM_BOT_TOKEN
        self.chat_id = TELEGRAM_CHAT_ID
        self.base_url = f"https://api.telegram.org/bot{self.bot_token}"
        self.sent_alerts = set()
        # Track score history per match: match_name -> {"last_score": (0,0), "goal_minutes": [(half, minute)]}
        self.match_score_history = {}

    def get_match_name(self, match_data):
        """Ambil nama match yang stabil untuk display dan dedup."""
        teams = (match_data.get("teams") or "").strip()
        if teams:
            return teams

        home_team = (match_data.get("homeTeam") or "").strip()
        away_team = (match_data.get("awayTeam") or "").strip()
        if home_team and away_team:
            return f"{home_team} vs {away_team}"

        return "Unknown"

    def send_message(self, message, retries=3):
        """Kirim pesan ke Telegram dengan retry"""
        url = f"{self.base_url}/sendMessage"
        data = {"chat_id": self.chat_id, "text": message, "parse_mode": "HTML"}

        for attempt in range(retries):
            try:
                response = requests.post(url, data=data, timeout=30)
                if response.status_code == 200:
                    print(f"[OK] Telegram message sent")
                    return True
                else:
                    print(f"[WARNING] Telegram API response: {response.status_code}")
                    if attempt < retries - 1:
                        continue
                    return False
            except Exception as e:
                print(f"[ERROR] Attempt {attempt + 1} failed: {e}")
                if attempt < retries - 1:
                    continue
                return False

        return False

    def send_match_update(self, match_data):
        """Kirim update match ke Telegram"""
        if not ALERT_SETTINGS["enable_match_updates"]:
            return False

        current_time = datetime.now().strftime("%H:%M:%S")
        match_name = self.get_match_name(match_data)

        message = f"📊 <b>LIVE MATCH UPDATE</b>\n\n"
        message += f"⚽ <b>{match_name}</b>\n"
        message += f"📊 Score: <b>{match_data.get('score', '0-0')}</b>\n"
        message += f"🏆 League: {match_data.get('league', 'N/A')}\n"
        message += f"⏰ Status: {match_data.get('status', 'Live')}\n"
        message += f"📅 Time: {current_time}\n\n"

        # Tambahkan odds jika ada
        if match_data.get("odds"):
            message += f"📈 <b>Odds:</b>\n"
            for odd in match_data["odds"][:3]:
                message += f"• {odd}\n"

        return self.send_message(message)

    def should_send_second_half_zero_zero_alert(self, match_data):
        """Cek apakah match sudah lewat 2H 2' dan masih 0-0"""
        status = match_data.get("status", "").strip()
        score = match_data.get("score", "0-0").strip()

        if score not in {"0-0", "0 - 0"}:
            return False

        second_half_match = re.fullmatch(r"2H\s+(\d+)'", status)
        if not second_half_match:
            return False

        minute = int(second_half_match.group(1))
        return minute >= 2

    def _parse_score(self, score_str):
        """Parse '1 - 0' -> (1, 0)"""
        parts = re.split(r"\s*-\s*", score_str.strip())
        if len(parts) == 2:
            try:
                return (int(parts[0].strip()), int(parts[1].strip()))
            except ValueError:
                pass
        return (0, 0)

    def _parse_status_half_minute(self, status_str):
        """Parse '1H 3'' -> (1, 3), '2H 0'' -> (2, 0), else None"""
        m = re.fullmatch(r"([12])H\s+(\d+)'", status_str.strip())
        if m:
            return (int(m.group(1)), int(m.group(2)))
        return None

    def track_goal_minutes(self, match_data):
        """Catat menit goal setiap kali score berubah naik."""
        match_name = self.get_match_name(match_data)
        status = match_data.get("status", "").strip()
        score_str = match_data.get("score", "0-0")

        current_score = self._parse_score(score_str)
        parsed = self._parse_status_half_minute(status)

        if match_name not in self.match_score_history:
            self.match_score_history[match_name] = {
                "last_score": (0, 0),
                "goal_minutes": [],
            }

        history = self.match_score_history[match_name]
        last_home, last_away = history["last_score"]
        cur_home, cur_away = current_score
        new_goals = (cur_home + cur_away) - (last_home + last_away)

        if new_goals > 0 and parsed is not None:
            for _ in range(new_goals):
                history["goal_minutes"].append(parsed)
            history["last_score"] = current_score
            print(f"[TRACK] Goal recorded at {status} for {match_name} | goals so far: {history['goal_minutes']}")
        elif new_goals > 0:
            # Bisa catat tapi tanpa menit yang valid
            history["last_score"] = current_score
        # Jika score tidak naik, tidak update goal_minutes

    def should_send_early_goal_2h_start_alert(self, match_data):
        """
        Cek apakah perlu kirim alert: sudah 2H 0'-2' dan hanya ada 1 goal
        yang terjadi di babak pertama (menit awal).
        """
        status = match_data.get("status", "").strip()
        score_str = match_data.get("score", "0-0")

        parsed = self._parse_status_half_minute(status)
        if parsed is None:
            return False

        half, minute = parsed
        if half != 2 or minute > 2:
            return False

        cur_home, cur_away = self._parse_score(score_str)
        total_goals = cur_home + cur_away
        if total_goals != 1:
            return False

        return True

    def check_and_alert_early_goal_2h_start(self, match_data):
        """Kirim alert saat 2H 0' jika hanya ada 1 goal di babak pertama."""
        if not self.should_send_early_goal_2h_start_alert(match_data):
            return False

        match_name = self.get_match_name(match_data)
        alert_key = f"{match_name}_early_goal_2h_start"

        if alert_key in self.sent_alerts:
            return False

        status = match_data.get("status", "").strip()
        score_str = match_data.get("score", "0-0")

        # Cari menit goal dari history
        history = self.match_score_history.get(match_name, {})
        goal_minutes = history.get("goal_minutes", [])

        if goal_minutes:
            goal_half, goal_minute = goal_minutes[0]
            goal_info = f"{goal_half}H {goal_minute}'"
        else:
            goal_info = "babak pertama"

        current_time = datetime.now().strftime("%H:%M:%S")

        message = f"🔔 <b>EARLY GOAL ALERT - BABAK 2 MULAI</b>\n\n"
        message += f"⚽ <b>{match_name}</b>\n"
        message += f"📊 Score: <b>{score_str}</b>\n"
        message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
        message += f"⏰ Status: {status}\n"
        message += f"📅 Alert Time: {current_time}\n\n"
        message += f"🎯 <i>Hanya ada 1 goal di menit {goal_info}!</i>\n"
        message += f"💡 <i>Babak kedua baru mulai — pantau pergerakan odds.</i>"

        if match_data.get("odds"):
            message += f"\n\n📈 <b>Current Odds:</b>\n"
            for odd in match_data["odds"][:3]:
                message += f"• {odd}\n"

        success = self.send_message(message)
        if success:
            self.sent_alerts.add(alert_key)
            print(f"[ALERT] Early goal 2H start alert sent for: {match_name} (goal at {goal_info})")
        return success

    def send_test_message(self):
        """Kirim pesan test"""
        test_message = f"🤖 <b>Live Scraper Bot Test</b>\n\n"
        test_message += f"✅ Bot is working!\n"
        test_message += (
            f"📅 Test time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
        )
        test_message += f"🔔 Live scraper is active."

        return self.send_message(test_message)

    def check_and_alert_second_half_zero_zero(self, match_data):
        """Cek dan kirim alert jika sudah lewat 2H 2' dan skor masih 0-0"""
        if not self.should_send_second_half_zero_zero_alert(match_data):
            return False

        status = match_data.get("status", "").strip()
        match_name = self.get_match_name(match_data)
        match_id = f"{match_name}_secondhalf_2h2_0-0"

        if match_id in self.sent_alerts:
            return False

        current_time = datetime.now().strftime("%H:%M:%S")

        message = f"⚠️ <b>SECOND HALF 0-0 ALERT!</b> ⚠️\n\n"
        message += f"⚽ <b>{match_name}</b>\n"
        message += f"📊 Score: <b>0-0</b>\n"
        message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
        message += f"⏰ Status: {status}\n"
        message += f"📅 Alert Time: {current_time}\n\n"
        message += "🔥 <i>Babak kedua tepat 2H 2' dan skor masih 0-0.</i>\n\n"

        if match_data.get("odds"):
            message += f"📈 <b>Current Odds:</b>\n"
            for odd in match_data["odds"][:3]:
                message += f"• {odd}\n"
            message += "\n"

        message += "💡 <i>Pantau terus untuk peluang betting terbaik!</i>"

        success = self.send_message(message)
        if success:
            self.sent_alerts.add(match_id)
            print(f"[ALERT] Second half 0-0 alert sent for: {match_name}")
        return success


# Test function
def test_telegram():
    notifier = TelegramNotifier()
    print("Sending test message...")
    success = notifier.send_test_message()

    if success:
        print("[OK] Test message sent successfully!")
    else:
        print("[ERROR] Test failed.")


if __name__ == "__main__":
    test_telegram()
