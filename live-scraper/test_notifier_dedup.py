#!/usr/bin/env python3

import unittest

from telegram_notifier import TelegramNotifier


class TelegramNotifierDedupTest(unittest.TestCase):
    def test_missing_teams_uses_home_and_away_for_unique_alert_identity(self):
        notifier = TelegramNotifier()
        sent_messages = []

        def fake_send_message(message, retries=3):
            sent_messages.append(message)
            return True

        notifier.send_message = fake_send_message

        first_match = {
            "homeTeam": "Tunisia (V)",
            "awayTeam": "Ukraine (V)",
            "score": "0 - 0",
            "league": "Virtual International Friendly",
            "status": "2H 3'",
        }
        second_match = {
            "homeTeam": "Pakistan (V)",
            "awayTeam": "India (V)",
            "score": "0 - 0",
            "league": "Virtual International Friendly",
            "status": "2H 4'",
        }

        first_sent = notifier.check_and_alert_second_half_zero_zero(first_match)
        second_sent = notifier.check_and_alert_second_half_zero_zero(second_match)

        self.assertTrue(first_sent)
        self.assertTrue(second_sent)
        self.assertEqual(2, len(sent_messages))
        self.assertIn("Tunisia (V) vs Ukraine (V)", sent_messages[0])
        self.assertIn("Pakistan (V) vs India (V)", sent_messages[1])


if __name__ == "__main__":
    unittest.main()
