#!/usr/bin/env python3
"""
Test Alert Second Half 2H 2' 0-0 untuk Live Scraper
"""

from telegram_notifier import TelegramNotifier

print("Testing Second Half 2H 2' 0-0 Alert...")
print("-" * 50)

notifier = TelegramNotifier()

# Test data - match dengan kondisi tepat 2H 2' dan skor 0-0
test_match = {
    "teams": "Atalanta (V) (PEN) vs Fiorentina (V) (PEN)",
    "score": "0-0",
    "league": "SABA CLUB FRIENDLY Virtual PES 23 - PENALTY SHOOTOUTS",
    "status": "2H 2'",
    "odds": ["FT. HDP: 0 @ 1.92 | 0 @ 1.74", "FT. O/U: o 5.25 @ 1.83 | u 5.25 @ 1.99"],
}

print(f"\nMatch: {test_match['teams']}")
print(f"Score: {test_match['score']}")
print(f"Status: {test_match['status']}")
print(f"League: {test_match['league']}")
print("\nSending 2H 2' 0-0 alert...")

success = notifier.check_and_alert_second_half_zero_zero(test_match)

print("\nSending duplicate alert to verify it is blocked...")
duplicate_success = notifier.check_and_alert_second_half_zero_zero(test_match)

if success:
    print("[OK] Second half 2H 2' 0-0 alert sent successfully!")
else:
    print("[INFO] Alert not sent (may be duplicate or conditions not met)")

if duplicate_success:
    print("[ERROR] Duplicate alert was sent again!")
else:
    print("[OK] Duplicate alert blocked for the same match!")

print("-" * 50)
print("\nTest completed!")
