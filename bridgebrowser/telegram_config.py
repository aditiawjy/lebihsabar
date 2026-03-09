#!/usr/bin/env python3
"""
Telegram Configuration
Ganti CHAT_ID dengan Chat ID Telegram Anda
"""

# Konfigurasi Telegram Bot
TELEGRAM_BOT_TOKEN = "8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA"
TELEGRAM_CHAT_ID = "6801623296"  # Ganti dengan Chat ID Anda

# Cara mendapatkan Chat ID:
# 1. Kirim pesan ke bot Anda di Telegram
# 2. Buka URL: https://api.telegram.org/bot<TOKEN>/getUpdates
# 3. Cari "chat":{"id":XXXXXXX} dalam response
# 4. Ganti YOUR_CHAT_ID_HERE dengan angka tersebut

# Contoh:
# TELEGRAM_CHAT_ID = "123456789"

# Pengaturan Alert
ALERT_SETTINGS = {
    "enable_halftime_alerts": False,  # Temporarily disabled
    "enable_early_first_half": False,  # Disabled
    "enable_second_half_start": True,
    "enable_score_alerts": False,
    "enable_new_match_alerts": False,
    "enable_fhg_alert": False,  # Temporarily disabled - FHG prediction alerts
    "enable_second_half_prediction": False,  # Temporarily disabled - Second half prediction alerts
    "enable_consecutive_0_0_alert": True,  # Consecutive 0-0 matches alert
    "enable_consecutive_under15_alert": True,  # Consecutive under 1.5 goals alert
    "enable_1h40_alert": True,  # NEW: Alert untuk 1H 40' masih 0-0
    "alert_cooldown_minutes": 5  # Minimum waktu antar alert untuk match yang sama
}

# Menit-menit early first half yang akan memicu alert (hanya 1H 4')
EARLY_FIRST_HALF_MINUTES = [4]