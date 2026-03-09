#!/usr/bin/env python3
"""
Telegram Notifier untuk Live Scraper
"""

import requests
import json
from datetime import datetime
from telegram_config import TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, ALERT_SETTINGS

class TelegramNotifier:
    def __init__(self):
        self.bot_token = TELEGRAM_BOT_TOKEN
        self.chat_id = TELEGRAM_CHAT_ID
        self.base_url = f"https://api.telegram.org/bot{self.bot_token}"
        self.sent_alerts = set()
    
    def send_message(self, message, retries=3):
        """Kirim pesan ke Telegram dengan retry"""
        url = f"{self.base_url}/sendMessage"
        data = {
            "chat_id": self.chat_id,
            "text": message,
            "parse_mode": "HTML"
        }
        
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
        
        message = f"📊 <b>LIVE MATCH UPDATE</b>\n\n"
        message += f"⚽ <b>{match_data.get('teams', 'Unknown')}</b>\n"
        message += f"📊 Score: <b>{match_data.get('score', '0-0')}</b>\n"
        message += f"🏆 League: {match_data.get('league', 'N/A')}\n"
        message += f"⏰ Status: {match_data.get('status', 'Live')}\n"
        message += f"📅 Time: {current_time}\n\n"
        
        # Tambahkan odds jika ada
        if match_data.get('odds'):
            message += f"📈 <b>Odds:</b>\n"
            for odd in match_data['odds'][:3]:
                message += f"• {odd}\n"
        
        return self.send_message(message)
    
    def send_test_message(self):
        """Kirim pesan test"""
        test_message = f"🤖 <b>Live Scraper Bot Test</b>\n\n"
        test_message += f"✅ Bot is working!\n"
        test_message += f"📅 Test time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
        test_message += f"🔔 Live scraper is active."
        
        return self.send_message(test_message)
    
    def check_and_alert_first_half_zero_zero(self, match_data):
        """Cek dan kirim alert jika babak pertama 0-0"""
        status = match_data.get('status', '')
        score = match_data.get('score', '0-0')
        
        # Cek kondisi: babak pertama (1H) dan skor 0-0
        is_first_half = status.startswith('1H') or status == 'Live'
        is_zero_zero = score == '0-0' or score == '0 - 0'
        
        if is_first_half and is_zero_zero:
            # Buat unique ID untuk match ini
            match_id = f"{match_data.get('teams', 'Unknown')}_{score}_firsthalf"
            
            # Cek apakah sudah pernah kirim alert untuk match ini
            if match_id in self.sent_alerts:
                return False
            
            current_time = datetime.now().strftime("%H:%M:%S")
            
            message = f"⚠️ <b>FIRST HALF 0-0 ALERT!</b> ⚠️\n\n"
            message += f"⚽ <b>{match_data.get('teams', 'Unknown Teams')}</b>\n"
            message += f"📊 Score: <b>0-0</b>\n"
            message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ Status: {status}\n"
            message += f"📅 Alert Time: {current_time}\n\n"
            message += f"🔥 <i>Babak pertama masih 0-0! Waktunya untuk analisis dan prediksi!</i>\n\n"
            
            # Tambahkan odds jika ada
            if match_data.get('odds'):
                message += f"📈 <b>Current Odds:</b>\n"
                for odd in match_data['odds'][:3]:
                    message += f"• {odd}\n"
                message += "\n"
            
            message += f"💡 <i>Pantau terus untuk peluang betting terbaik!</i>"
            
            success = self.send_message(message)
            if success:
                self.sent_alerts.add(match_id)
                print(f"[ALERT] First half 0-0 alert sent for: {match_data.get('teams', 'Unknown')}")
            return success
        
        return False

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
