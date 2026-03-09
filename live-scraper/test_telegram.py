#!/usr/bin/env python3
"""
Test Telegram untuk Live Scraper
"""

from telegram_notifier import TelegramNotifier

print("Testing Live Scraper Telegram...")
print("-" * 50)

notifier = TelegramNotifier()

# Test kirim pesan
print("\n1. Sending test message...")
success = notifier.send_test_message()

if success:
    print("[OK] Telegram berfungsi dengan baik!")
else:
    print("[ERROR] Gagal mengirim pesan. Cek token dan chat ID.")

print("-" * 50)
