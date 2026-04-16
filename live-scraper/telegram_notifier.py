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
        """Live match update umum dimatikan agar tidak spam Telegram."""
        return False

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
            print(
                f"[TRACK] Goal recorded at {status} for {match_name} | goals so far: {history['goal_minutes']}"
            )
        elif new_goals > 0:
            # Bisa catat tapi tanpa menit yang valid
            history["last_score"] = current_score
        # Jika score tidak naik, tidak update goal_minutes

    def send_test_message(self):
        """Kirim pesan test"""
        test_message = f"🤖 <b>Live Scraper Bot Test</b>\n\n"
        test_message += f"✅ Bot is working!\n"
        test_message += (
            f"📅 Test time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
        )
        test_message += f"🔔 Live scraper is active."

        return self.send_message(test_message)


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
