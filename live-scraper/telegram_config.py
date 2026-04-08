#!/usr/bin/env python3
"""
Telegram Configuration untuk Live Scraper
"""

# Konfigurasi Telegram Bot (sama dengan bridgebrowser)
TELEGRAM_BOT_TOKEN = "8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA"
TELEGRAM_CHAT_ID = "6801623296"

# Pengaturan Alert
ALERT_SETTINGS = {
    "enable_match_updates": True,  # Alert untuk update match
    "enable_score_alerts": True,   # Alert untuk perubahan skor
    "enable_new_match_alerts": True,  # Alert untuk match baru
    "alert_cooldown_minutes": 2  # Minimum waktu antar alert
}
