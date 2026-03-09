#!/usr/bin/env python3
"""
Test Alert First Half 0-0 untuk Live Scraper
"""

from telegram_notifier import TelegramNotifier

print("Testing First Half 0-0 Alert...")
print("-" * 50)

notifier = TelegramNotifier()

# Test data - match dengan kondisi 0-0 di babak pertama
test_match = {
    "teams": "Atalanta (V) (PEN) vs Fiorentina (V) (PEN)",
    "score": "0-0",
    "league": "SABA CLUB FRIENDLY Virtual PES 23 - PENALTY SHOOTOUTS",
    "status": "1H 5'",
    "odds": [
        "FT. HDP: 0 @ 1.92 | 0 @ 1.74",
        "FT. O/U: o 5.25 @ 1.83 | u 5.25 @ 1.99"
    ]
}

print(f"\nMatch: {test_match['teams']}")
print(f"Score: {test_match['score']}")
print(f"Status: {test_match['status']}")
print(f"League: {test_match['league']}")
print("\nSending 0-0 first half alert...")

success = notifier.check_and_alert_first_half_zero_zero(test_match)

if success:
    print("[OK] First half 0-0 alert sent successfully!")
else:
    print("[INFO] Alert not sent (may be duplicate or conditions not met)")

print("-" * 50)
print("\nTest completed!")
