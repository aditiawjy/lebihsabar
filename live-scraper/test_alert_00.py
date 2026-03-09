#!/usr/bin/env python3
"""
Test Alert Second Half >= 2' dengan skor 0-0 untuk Live Scraper
"""

from telegram_notifier import TelegramNotifier

print("Testing Second Half >= 2' 0-0 Alert...")
print("-" * 50)

notifier = TelegramNotifier()

# Test data - match dengan kondisi 2H 8' dan skor 0-0
test_match = {
    "teams": "Pakistan (V) vs India (V)",
    "score": "0 - 0",
    "league": "Virtual International Friendly",
    "status": "2H 8'",
    "odds": [
        "FT. 1X2: Home: 5.80 | Draw: 1.24 | Away: 5.80",
        "FT. HDP: 0 @ 1.88 | 0 @ 1.88",
        "FT. O/U: o 0.5 @ 3.22 | u 0.5 @ 1.29",
    ],
}

print(f"\nMatch: {test_match['teams']}")
print(f"Score: {test_match['score']}")
print(f"Status: {test_match['status']}")
print(f"League: {test_match['league']}")
print("\nSending 2H 8' 0-0 alert...")

success = notifier.check_and_alert_second_half_zero_zero(test_match)

print("\nSending duplicate alert to verify it is blocked...")
duplicate_success = notifier.check_and_alert_second_half_zero_zero(test_match)

if success:
    print("[OK] Second half >= 2' 0-0 alert sent successfully!")
else:
    print("[INFO] Alert not sent (may be duplicate or conditions not met)")

if duplicate_success:
    print("[ERROR] Duplicate alert was sent again!")
else:
    print("[OK] Duplicate alert blocked for the same match!")

print("-" * 50)
print("\nTest completed!")
