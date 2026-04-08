#!/usr/bin/env python3
"""
Telegram Notifier - Sistem notifikasi Telegram untuk betting alerts
"""

import requests
import json
from datetime import datetime

class TelegramNotifier:
    def __init__(self, bot_token, chat_id):
        self.bot_token = bot_token
        self.chat_id = chat_id
        self.base_url = f"https://api.telegram.org/bot{bot_token}"
        self.sent_alerts = set()  # Track sent alerts to avoid duplicates
    
    def send_message(self, message):
        """Kirim pesan ke Telegram"""
        url = f"{self.base_url}/sendMessage"
        data = {
            "chat_id": self.chat_id,
            "text": message,
            "parse_mode": "HTML"
        }
        
        try:
            response = requests.post(url, data=data, timeout=10)
            if response.status_code == 200:
                print(f"[OK] Telegram message sent successfully")
                return True
            else:
                print(f"[WARNING] Telegram API response: {response.status_code}")
                return False
        except Exception as e:
            print(f"[ERROR] Error sending Telegram message: {e}")
            return False
    
    def send_halftime_alert(self, match_data, alert_type="halftime"):
        """Kirim alert untuk match 0-0 pada waktu spesifik seperti early first half, halftime, atau babak kedua"""
        # Create unique identifier for this match and alert type
        match_id = f"{match_data.get('teams', 'Unknown')}_{match_data.get('scores', '')}_{alert_type}"
        
        # Check if we already sent alert for this match and type
        if match_id in self.sent_alerts:
            return False
        
        current_time = datetime.now().strftime("%H:%M:%S")
        
        if alert_type == "early_first_half":
            message = f"🚨 <b>EARLY FIRST HALF 0-0 ALERT!</b>\n\n"
            message += f"⚽ <b>{match_data.get('teams', 'Unknown Teams')}</b>\n"
            message += f"📊 Score: <b>{match_data.get('scores', '0-0')}</b>\n"
            message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ Status: {match_data.get('status', 'Live')}\n"
            message += f"🕐 Time: {match_data.get('time', 'Live')}\n"
            message += f"📅 Alert Time: {current_time}\n\n"
            message += f"🔥 <i>Masih 0-0 di menit 5 babak pertama! Peluang awal bagus!</i>"
        elif alert_type == "second_half_start":
            message = f"🚨 <b>BABAK KEDUA 0-0 ALERT!</b>\n\n"
            message += f"⚽ <b>{match_data.get('teams', 'Unknown Teams')}</b>\n"
            message += f"📊 Score: <b>{match_data.get('scores', '0-0')}</b>\n"
            message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ Status: {match_data.get('status', 'Live')}\n"
            message += f"🕐 Time: {match_data.get('time', 'Live')}\n"
            message += f"📅 Alert Time: {current_time}\n\n"
            message += f"🔥 <i>Babak kedua dimulai masih 0-0! Peluang bagus untuk bet!</i>"
        elif alert_type == "second_half_prediction":
            message = f"🔮 <b>SECOND HALF PREDICTION ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> second_half_prediction_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>First Half Score:</b> {match_data.get('first_half_score', '0-0')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>PREDICTION ANALYSIS:</b>\n"
            message += f"🔥 Second Half Goals: <b>{match_data.get('prediction', 'Ya - Ada goal babak kedua')}</b>\n"
            message += f"📈 Confidence Level: <b>HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> API predicts goals in second half!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data dari second_half_prediction_api.php - Prediksi menunjukkan akan ada goal di babak kedua!</i>"
        elif alert_type == "fhg_prediction":
            fhg_percentage = match_data.get('fhg_percentage', 0)
            message = f"🎯 <b>FHG PREDICTION ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> fhg_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>FHG PREDICTION ANALYSIS:</b>\n"
            message += f"🔥 First Half Goal: <b>{match_data.get('fhg_prediction', 'Ya - Ada goal babak pertama')}</b>\n"
            message += f"📈 Probability: <b>{fhg_percentage}%</b> (Above 80% threshold)\n"
            message += f"📈 Confidence Level: <b>HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> Strong prediction for goal in first half!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data dari fhg_api.php - Prediksi {fhg_percentage}% akan ada goal di babak pertama!</i>"
        elif alert_type == "second_half_ai_prediction":
            message = f"🤖 <b>NOTES API - AI PREDICTION ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> notes_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>AI ANALYSIS FROM NOTES API:</b>\n"
            message += f"📈 Over 0.5 Goals: <b>{match_data.get('ai_prediction', '>95%')}</b>\n"
            message += f"🔥 Confidence Level: <b>VERY HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> Strong signal for Over 0.5 goals!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data langsung dari notes_api.php - Prediksi AI menunjukkan peluang goal sangat tinggi di babak kedua!</i>"
        elif alert_type == "draw_streak_alert":
            message = f"🔄 <b>DRAW STREAK ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> team_draw_streak_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>First Half Score:</b> {match_data.get('first_half_score', '0-0')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>DRAW STREAK ANALYSIS:</b>\n"
            message += f"🔥 {match_data.get('draw_streak_info', 'Tim sedang dalam streak draw terlama')}\n\n"
            message += f"📈 <b>PREDICTION:</b> {match_data.get('prediction', 'Pertandingan ini tidak akan berakhir imbang')}\n"
            message += f"📈 Confidence Level: <b>HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> Tim dalam streak draw terlama - peluang untuk tidak imbang!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data dari team_draw_streak_api.php - Tim sedang dalam periode streak draw terlama!</i>"
        elif alert_type == "goalless_streak_alert":
            message = f"⚽ <b>GOALLESS STREAK ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> team_goalless_streak_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>First Half Score:</b> {match_data.get('first_half_score', '0-0')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>GOALLESS STREAK ANALYSIS:</b>\n"
            message += f"🔥 {match_data.get('goalless_streak_info', 'Tim sedang dalam streak tidak mencetak goal terlama')}\n\n"
            message += f"📈 <b>PREDICTION:</b> {match_data.get('prediction', 'Tim akan mencetak goal')}\n"
            message += f"📈 Confidence Level: <b>HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> Tim dalam streak goalless terlama - peluang untuk mencetak goal!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data dari team_goalless_streak_api.php - Tim sedang dalam periode tidak mencetak goal terlama!</i>"
        elif alert_type == "consecutive_0_0_alert":
            message = f"🔥 <b>CONSECUTIVE 0-0 STREAK ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> consecutive_0_0_matches_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>First Half Score:</b> {match_data.get('first_half_score', '0-0')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>CONSECUTIVE 0-0 ANALYSIS:</b>\n"
            message += f"🔥 {match_data.get('consecutive_info', 'Streak 0-0 terlama dalam sejarah')}\n\n"
            message += f"📈 <b>PREDICTION:</b> {match_data.get('prediction', 'Akan ada goal')}\n"
            message += f"📈 Confidence Level: <b>VERY HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> Kedua tim dalam streak 0-0 terlama - sangat berpeluang ada goal!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data dari consecutive_0_0_matches_api.php - Streak 0-0 terlama dalam sejarah pertemuan!</i>"
        elif alert_type == "consecutive_under15_alert":
            message = f"🎯 <b>CONSECUTIVE UNDER 1.5 GOALS ALERT!</b>\n\n"
            message += f"📡 <b>Data Source:</b> consecutive_under15_api.php\n"
            message += f"⚽ <b>Match:</b> {match_data.get('teams', 'Unknown Teams')}\n"
            message += f"📊 <b>First Half Score:</b> {match_data.get('first_half_score', '0-0')}\n"
            message += f"📊 <b>Current Score:</b> {match_data.get('scores', '0-0')}\n"
            message += f"🏆 <b>League:</b> {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ <b>Match Status:</b> {match_data.get('status', 'Live')} - {match_data.get('time', 'Live')}\n\n"
            message += f"🎯 <b>CONSECUTIVE UNDER 1.5 GOALS ANALYSIS:</b>\n"
            message += f"🔥 {match_data.get('consecutive_under15_info', 'Streak under 1.5 goals terlama dalam sejarah')}\n\n"
            message += f"📈 <b>PREDICTION:</b> {match_data.get('prediction', 'Akan ada 2 goal')}\n"
            message += f"📈 Confidence Level: <b>VERY HIGH</b>\n\n"
            message += f"⚡ <b>RECOMMENDATION:</b> Kedua tim dalam streak under 1.5 goals terlama - sangat berpeluang ada 2+ goal!\n"
            message += f"📅 Alert Generated: {current_time}\n\n"
            message += f"🚀 <i>Data dari consecutive_under15_api.php - Streak under 1.5 goals terlama dalam sejarah pertemuan!</i>"
        else:
            message = f"🚨 <b>HALFTIME 0-0 ALERT!</b>\n\n"
            message += f"⚽ <b>{match_data.get('teams', 'Unknown Teams')}</b>\n"
            message += f"📊 Score: <b>{match_data.get('scores', '0-0')}</b>\n"
            message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
            message += f"⏰ Status: {match_data.get('status', 'Live')}\n"
            message += f"🕐 Time: {match_data.get('time', 'Live')}\n"
            message += f"📅 Alert Time: {current_time}\n\n"
            message += f"💡 <i>Perfect time to place your bet!</i>"
        
        success = self.send_message(message)
        if success:
            self.sent_alerts.add(match_id)
        
        return success
    
    def send_test_message(self):
        """Kirim pesan test untuk verifikasi koneksi"""
        test_message = f"🤖 <b>Betting Alert Bot Test</b>\n\n"
        test_message += f"✅ Bot is working correctly!\n"
        test_message += f"📅 Test time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
        test_message += f"🔔 You will receive alerts for 0-0 halftime matches."
        
        return self.send_message(test_message)
    
    def send_match_update(self, match_data, alert_type="general"):
        """Kirim update umum untuk match"""
        current_time = datetime.now().strftime("%H:%M:%S")
        
        if alert_type == "score_change":
            emoji = "⚽"
            title = "SCORE UPDATE"
        elif alert_type == "new_match":
            emoji = "🆕"
            title = "NEW MATCH STARTED"
        else:
            emoji = "📊"
            title = "MATCH UPDATE"
        
        message = f"{emoji} <b>{title}</b>\n\n"
        message += f"⚽ <b>{match_data.get('teams', 'Unknown Teams')}</b>\n"
        message += f"📊 Score: <b>{match_data.get('scores', 'N/A')}</b>\n"
        message += f"🏆 League: {match_data.get('league', 'Unknown League')}\n"
        message += f"⏰ Status: {match_data.get('status', 'Live')}\n"
        message += f"🕐 Time: {match_data.get('time', 'Live')}\n"
        message += f"📅 Update Time: {current_time}"
        
        return self.send_message(message)
    
    def clear_sent_alerts(self):
        """Clear sent alerts cache (useful for testing)"""
        self.sent_alerts.clear()
        print("[OK] Sent alerts cache cleared")

# Test function
def test_telegram_bot():
    """Test Telegram bot functionality"""
    # Replace with your actual token and chat ID
    BOT_TOKEN = "8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA"
    CHAT_ID = "YOUR_CHAT_ID_HERE"  # You need to get this from @userinfobot
    
    notifier = TelegramNotifier(BOT_TOKEN, CHAT_ID)
    
    # Send test message
    print("Sending test message...")
    success = notifier.send_test_message()
    
    if success:
        print("[OK] Test message sent successfully!")
        
        # Test halftime alert
        sample_match = {
            "teams": "Croatia vs Russia",
            "scores": "0-0",
            "time": "1H",
            "status": "Live",
            "league": "World Cup"
        }
        
        print("Sending halftime alert test...")
        notifier.send_halftime_alert(sample_match)
        
    else:
        print("[ERROR] Test failed. Check your bot token and chat ID.")

if __name__ == "__main__":
    test_telegram_bot()