#!/usr/bin/env python3
"""
Telegram Alert Monitor - Monitor live matches dan kirim notifikasi untuk kondisi spesifik
"""

import requests
import time
import json
from datetime import datetime
from telegram_config import TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, ALERT_SETTINGS
from telegram_notifier import TelegramNotifier
from h2h_validator import H2HValidator

class MatchAlertMonitor:
    def __init__(self):
        self.notifier = TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID)
        self.api_url = "http://localhost:5000/api/live-matches"
        self.sent_alerts = set()  # Track sent alerts untuk avoid duplicates
        self.monitoring = True
        self.h2h_validator = H2HValidator()
        
        # Initialize database tables
        try:
            self.h2h_validator.create_database_tables()
            print("[INFO] H2H database tables initialized successfully")
        except Exception as e:
            print(f"[WARNING] Failed to initialize H2H database: {e}")
        
    def get_live_matches(self):
        """Ambil data live matches dari API dengan fallback ke JSON"""
        try:
            response = requests.get(self.api_url, timeout=5)  # Kurangi timeout
            if response.status_code == 200:
                return response.json()
            else:
                print(f"[WARNING] API response: {response.status_code}")
                return self.get_fallback_data()
        except Exception as e:
            print(f"[ERROR] Error fetching live matches: {e}")
            return self.get_fallback_data()
    
    def get_fallback_data(self):
        """Ambil data dari file JSON sebagai fallback"""
        try:
            import os
            import json
            json_file_path = os.path.join(os.path.dirname(__file__), 'live_data.json')
            if os.path.exists(json_file_path):
                with open(json_file_path, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                print("[INFO] Using fallback data from JSON file")
                return data
            else:
                print("[WARNING] No fallback data available")
                return None
        except Exception as e:
            print(f"[ERROR] Error loading fallback data: {e}")
            return None
    
    def parse_time_status(self, time_str):
        """Parse waktu pertandingan untuk menentukan menit"""
        if not time_str:
            return None, None
            
        time_str = time_str.strip().upper()
        
        # Format: 1H 40' atau 1H40' atau 40'
        if "1H" in time_str:
            # Extract menit dari format 1H XX'
            parts = time_str.replace("1H", "").strip()
            if "'" in parts:
                try:
                    minute = int(parts.replace("'", "").strip())
                    return "1H", minute
                except:
                    return "1H", None
            return "1H", None
        elif "2H" in time_str:
            # Extract menit dari format 2H XX'
            parts = time_str.replace("2H", "").strip()
            if "'" in parts:
                try:
                    minute = int(parts.replace("'", "").strip())
                    return "2H", minute
                except:
                    return "2H", None
            return "2H", None
        elif "'" in time_str and "H" not in time_str:
            # Format: 40'
            try:
                minute = int(time_str.replace("'", "").strip())
                return "1H", minute  # Assume first half
            except:
                return None, None
        
        return None, None
    
    def check_under15_condition(self, match):
        """Check apakah match memenuhi kondisi under 1.5 goals di 2H 60'"""
        # Check score under 1.5 (0-0, 1-0, 0-1)
        score = match.get('score', '')
        
        # Parse score to check total goals
        if ':' in score:
            try:
                parts = score.split(':')
                home_goals = int(parts[0].strip())
                away_goals = int(parts[1].strip())
                total_goals = home_goals + away_goals
            except:
                return False
        else:
            # Try format like "0-0"
            if '-' in score:
                try:
                    parts = score.split('-')
                    home_goals = int(parts[0].strip())
                    away_goals = int(parts[1].strip())
                    total_goals = home_goals + away_goals
                except:
                    return False
            else:
                return False
        
        # Check if under 1.5 goals (total goals <= 1)
        if total_goals > 1:
            return False
            
        # Check time conditions - only 2H 60'
        time_str = match.get('time', '')
        half, minute = self.parse_time_status(time_str)
        
        # Only 2H 60'
        if half == "2H" and minute is not None and minute >= 60:
            return True
            
        return False
    
    def check_1h30_condition(self, match):
        """Check Under 1.5 condition at 1H 30' with H2H and team average validation"""
        try:
            # Extract match information
            time_str = match.get('time', '')
            score = match.get('score', '')
            home_team = match.get('home_team', match.get('team1', ''))
            away_team = match.get('away_team', match.get('team2', ''))
            
            # Check if it's 1H 30' (first half, minute 30+)
            if '1H' not in time_str:
                return False
                
            # Extract minute from time string
            minute = 0
            try:
                if "'" in time_str:
                    minute_part = time_str.split("'")[0]
                    if 'H' in minute_part:
                        minute = int(minute_part.split('H')[-1].strip())
                    else:
                        minute = int(minute_part.strip())
            except:
                return False
                
            # Check if minute is 30 or more in first half
            if minute < 30:
                return False
                
            # Check Under 1.5 condition (0-0, 1-0, 0-1)
            if score not in ['0 : 0', '1 : 0', '0 : 1', '0-0', '1-0', '0-1']:
                return False
                
            # H2H Validation: Check if their last H2H match ended under 1-0
            if home_team and away_team:
                try:
                    h2h_data = self.h2h_validator.get_h2h_history(home_team, away_team)
                    if h2h_data and h2h_data.get('total_matches', 0) > 0:
                        # Check if last H2H match was under 1-0 (0-0 or 1-0 or 0-1)
                        last_match_under_10 = h2h_data.get('last_match_under_10', False)
                        if not last_match_under_10:
                            print(f"[DEBUG] H2H validation failed for {home_team} vs {away_team}: last match not under 1-0")
                            return False
                except Exception as e:
                    print(f"[WARNING] H2H validation error for {home_team} vs {away_team}: {e}")
                    
                # Team Average Validation: Check if recent matches with other teams ended under 2 goals
                try:
                    home_avg = self.h2h_validator.get_team_average_goals(home_team, 5)
                    away_avg = self.h2h_validator.get_team_average_goals(away_team, 5)
                    
                    # Check if both teams have low scoring averages (under 2 goals per match)
                    if home_avg and away_avg:
                        if home_avg.get('average_total_goals', 3) >= 2 or away_avg.get('average_total_goals', 3) >= 2:
                            print(f"[DEBUG] Team average validation failed: {home_team} avg={home_avg.get('average_total_goals', 'N/A')}, {away_team} avg={away_avg.get('average_total_goals', 'N/A')}")
                            return False
                except Exception as e:
                    print(f"[WARNING] Team average validation error: {e}")
                    
            return True
            
        except Exception as e:
            print(f"[ERROR] Error in check_1h30_condition: {e}")
            return False
    
    def check_2h60_condition(self, match):
        """Check if match meets 2H 60' 0-0 condition with H2H draw validation"""
        try:
            time_str = match.get('time', '')
            score = match.get('score', '')
            home_team = match.get('home_team', match.get('team1', ''))
            away_team = match.get('away_team', match.get('team2', ''))
            
            # Check if it's 2H 60'+ and score is 0-0
            if '2H' not in time_str:
                return False
                
            # Extract minute from time string
            minute = 0
            try:
                if "'" in time_str:
                    minute_part = time_str.split("'")[0]
                    if 'H' in minute_part:
                        minute = int(minute_part.split('H')[-1].strip())
                    else:
                        minute = int(minute_part.strip())
            except:
                return False
                
            # Check if minute is 60 or more in second half
            if minute < 60:
                return False
                
            # Check if score is 0-0
            if score not in ['0 : 0', '0-0']:
                return False
                
            # H2H validation: check if there are 2 or more draws in H2H history
            if home_team and away_team:
                try:
                    h2h_draws_valid = self.h2h_validator.check_h2h_draws(home_team, away_team, min_draws=2)
                    if not h2h_draws_valid:
                        print(f"[DEBUG] H2H draws validation failed for {home_team} vs {away_team}: less than 2 draws in history")
                        return False
                except Exception as e:
                    print(f"[WARNING] H2H draws validation error for {home_team} vs {away_team}: {e}")
                    return False
                    
            return True
            
        except Exception as e:
            print(f"[ERROR] Error in check_2h60_condition: {e}")
            return False
    
    def send_1h30_alert(self, match):
        """Kirim alert untuk kondisi under 1.5 goals di 1H 30' dengan validasi H2H"""
        try:
            home_team = match.get('home_team', match.get('team1', 'Team A'))
            away_team = match.get('away_team', match.get('team2', 'Team B'))
            score = match.get('score', '0-0')
            time_str = match.get('time', '')
            league = match.get('league', match.get('competition', 'Unknown League'))
            
            # Create unique identifier untuk mencegah duplikat
            match_id = f"{home_team}_vs_{away_team}_1h30_alert"
            
            # Check if already sent
            if match_id in self.sent_alerts:
                return False
            
            # Create alert message
            alert_msg = f"🔥 <b>1H 30' UNDER 1.5 ALERT!</b>\n\n"
            alert_msg += f"⚽ <b>{home_team} vs {away_team}</b>\n"
            alert_msg += f"📊 <b>Score:</b> {score}\n"
            alert_msg += f"⏰ <b>Time:</b> {time_str}\n"
            alert_msg += f"🏆 <b>League:</b> {league}\n\n"
            alert_msg += f"✅ <b>Validasi H2H:</b> Pertandingan terakhir mereka berakhir dibawah 1-0\n"
            alert_msg += f"✅ <b>Rata-rata Gol:</b> Kedua tim memiliki rata-rata gol rendah\n\n"
            alert_msg += f"💡 <i>Pertandingan ini memenuhi semua kondisi untuk Under 1.5 Goals di babak pertama 30'+</i>"
            
            # Send alert
            success = self.notifier.send_message(alert_msg)
            if success:
                self.sent_alerts.add(match_id)
                print(f"[OK] 1H 30' alert sent for: {home_team} vs {away_team} ({score}) at {time_str}")
            else:
                print(f"[ERROR] Failed to send 1H 30' alert for: {home_team} vs {away_team}")
                
            return success
            
        except Exception as e:
            print(f"[ERROR] Error sending 1H 30' alert: {e}")
            return False
    
    def send_2h60_alert(self, match):
        """Kirim alert untuk kondisi 0-0 di 2H 60' dengan validasi H2H draw"""
        try:
            home_team = match.get('home_team', match.get('team1', 'Team A'))
            away_team = match.get('away_team', match.get('team2', 'Team B'))
            score = match.get('score', '0-0')
            time_str = match.get('time', '')
            league = match.get('league', match.get('competition', 'Unknown League'))
            
            # Create unique identifier
            match_id = f"{home_team}_vs_{away_team}_2h60_draw_alert"
            
            # Check if already sent
            if match_id in self.sent_alerts:
                return False
            
            # Create alert message
            alert_msg = f"🎯 <b>2H 60' DRAW POTENTIAL ALERT!</b>\n\n"
            alert_msg += f"⚽ <b>{home_team} vs {away_team}</b>\n"
            alert_msg += f"📊 <b>Score:</b> {score}\n"
            alert_msg += f"⏰ <b>Time:</b> {time_str}\n"
            alert_msg += f"🏆 <b>League:</b> {league}\n\n"
            alert_msg += f"✅ <b>Validasi H2H:</b> Riwayat menunjukkan 2+ pertandingan draw\n"
            alert_msg += f"🎯 <b>Kondisi:</b> Skor 0-0 di menit 60+ babak kedua\n\n"
            alert_msg += f"📈 <i>Tinggi kemungkinan pertandingan berakhir draw berdasarkan riwayat H2H</i>"
            
            # Send alert
            success = self.notifier.send_message(alert_msg)
            if success:
                self.sent_alerts.add(match_id)
                print(f"[OK] 2H 60' draw alert sent for: {home_team} vs {away_team} ({score}) at {time_str}")
            else:
                print(f"[ERROR] Failed to send 2H 60' draw alert for: {home_team} vs {away_team}")
                
            return success
            
        except Exception as e:
            print(f"[ERROR] Error sending 2H 60' alert: {e}")
            return False
    
    def send_under15_alert(self, match):
        """Kirim alert untuk kondisi under 1.5 goals di 2H 60'"""
        # Get team names
        team1 = match.get('team1', 'Unknown')
        team2 = match.get('team2', 'Unknown')
        teams = f"{team1} vs {team2}"
        
        # Create unique identifier
        match_id = f"{teams}_{match.get('league', '')}_under15_alert"
        
        # Check if already sent
        if match_id in self.sent_alerts:
            return False
            
        current_time = datetime.now().strftime("%H:%M:%S")
        score = match.get('score', '0-0')
        time_status = match.get('time', 'Live')
        
        message = f"🚨 <b>UNDER 1.5 GOALS ALERT!</b>\n\n"
        message += f"⚽ <b>{teams}</b>\n"
        message += f"📊 Score: <b>{score}</b>\n"
        message += f"🏆 League: {match.get('league', 'Unknown League')}\n"
        message += f"⏰ Status: {match.get('status', 'Live')}\n"
        message += f"🕐 Time: <b>{time_status}</b>\n"
        message += f"📅 Alert Time: {current_time}\n\n"
        
        # Message for 2H 50'+ only
        if score in ['0-0', '0 : 0']:
            message += f"🔥 <i>Sudah menit 50+ babak kedua masih 0-0! Peluang terakhir untuk bet Over 0.5 Goals!</i>\n\n"
            message += f"💡 <b>RECOMMENDATION:</b> Pertimbangkan bet Over 0.5 Goals atau BTTS (URGENT!)\n"
        else:
            message += f"🔥 <i>Sudah menit 50+ babak kedua masih under 1.5 goals! Peluang terakhir untuk bet Over 1.5 Goals!</i>\n\n"
            message += f"💡 <b>RECOMMENDATION:</b> Pertimbangkan bet Over 1.5 Goals (URGENT!)\n"
        message += f"⚡ <b>TIMING:</b> Menit 50+ babak kedua adalah peluang terakhir untuk entry bet!"
        
        success = self.notifier.send_message(message)
        if success:
            self.sent_alerts.add(match_id)
            print(f"[OK] Under 1.5 alert sent for: {teams} ({score}) at {time_status}")
        else:
            print(f"[ERROR] Failed to send under 1.5 alert for: {teams}")
            
        return success
    
    def monitor_matches(self):
        """Main monitoring loop"""
        print("[INFO] Starting Telegram Alert Monitor...")
        print(f"[INFO] Monitoring for Under 1.5 Goals conditions (2H 60')")
        print(f"[INFO] API URL: {self.api_url}")
        print(f"[INFO] Press Ctrl+C to stop\n")
        
        # Send startup message
        startup_msg = f"🤖 <b>Telegram Alert Monitor Started!</b>\n\n"
        startup_msg += f"🎯 <b>Monitoring Conditions:</b>\n"
        startup_msg += f"⏰ <b>1H 30'+:</b> Under 1.5 Goals dengan validasi H2H & rata-rata gol\n"
        startup_msg += f"🎯 <b>2H 60'+:</b> Skor 0-0 dengan validasi 2+ H2H draws\n"
        startup_msg += f"⚽ <b>2H 50'+:</b> Under 1.5 Goals (standar)\n\n"
        startup_msg += f"🔍 <b>H2H Validations:</b>\n"
        startup_msg += f"   • 1H 30': Pertandingan terakhir under 1-0\n"
        startup_msg += f"   • 2H 60': Riwayat memiliki 2+ pertandingan draw\n\n"
        startup_msg += f"📡 <b>API:</b> {self.api_url}\n"
        startup_msg += f"📅 <b>Started:</b> {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
        startup_msg += f"🔔 <i>Sistem monitoring triple condition aktif - semua berjalan independen!</i>"
        
        self.notifier.send_message(startup_msg)
        
        try:
            while self.monitoring:
                # Get live matches
                matches_data = self.get_live_matches()
                
                if matches_data and 'live_matches' in matches_data:
                    live_matches = matches_data['live_matches']
                    print(f"[INFO] Checking {len(live_matches)} live matches...")
                    
                    for match in live_matches:
                        # Check 1H 30' condition (independent) - DISABLED
                        # if self.check_1h30_condition(match):
                        #     team1 = match.get('team1', 'Unknown')
                        #     team2 = match.get('team2', 'Unknown')
                        #     teams = f"{team1} vs {team2}"
                        #     score = match.get('score', 'N/A')
                        #     time_str = match.get('time', 'N/A')
                        #     print(f"[ALERT] Found 1H 30' Under 1.5 match: {teams} ({score}) at {time_str}")
                        #     self.send_1h30_alert(match)
                        
                        # Check 2H 60' draw condition (independent)
                        if self.check_2h60_condition(match):
                            team1 = match.get('team1', 'Unknown')
                            team2 = match.get('team2', 'Unknown')
                            teams = f"{team1} vs {team2}"
                            score = match.get('score', 'N/A')
                            time_str = match.get('time', 'N/A')
                            print(f"[ALERT] Found 2H 60' Draw Potential match: {teams} ({score}) at {time_str}")
                            self.send_2h60_alert(match)
                        
                        # Check Under 1.5 condition (2H 60') - independent
                        if self.check_under15_condition(match):
                            team1 = match.get('team1', 'Unknown')
                            team2 = match.get('team2', 'Unknown')
                            teams = f"{team1} vs {team2}"
                            score = match.get('score', 'N/A')
                            time_str = match.get('time', 'N/A')
                            print(f"[ALERT] Found Under 1.5 match: {teams} ({score}) at {time_str}")
                            self.send_under15_alert(match)
                        else:
                            # Debug info
                            team1 = match.get('team1', 'Unknown')
                            team2 = match.get('team2', 'Unknown')
                            teams = f"{team1} vs {team2}"
                            scores = match.get('score', 'N/A')
                            time_str = match.get('time', 'N/A')
                            league = match.get('league', 'Unknown League')
                            print(f"[DEBUG] {teams}: {scores} at {time_str} ({league})")
                else:
                    print("[WARNING] No live matches data received")
                
                # Wait before next check
                print(f"[INFO] Waiting 5 seconds before next check...\n")
                time.sleep(5)
                
        except KeyboardInterrupt:
            print("\n[INFO] Monitoring stopped by user")
            self.monitoring = False
            
            # Send stop message
            stop_msg = f"🛑 <b>Telegram Alert Monitor Stopped</b>\n\n"
            stop_msg += f"📅 <b>Stopped:</b> {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n"
            stop_msg += f"📊 <b>Total alerts sent:</b> {len(self.sent_alerts)}\n\n"
            stop_msg += f"👋 <i>Monitor dihentikan oleh user. Terima kasih!</i>"
            
            self.notifier.send_message(stop_msg)
        
        except Exception as e:
            print(f"[ERROR] Monitoring error: {e}")
            error_msg = f"❌ <b>Monitor Error!</b>\n\n"
            error_msg += f"🐛 <b>Error:</b> {str(e)}\n"
            error_msg += f"📅 <b>Time:</b> {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
            error_msg += f"🔄 <i>Silakan restart monitor!</i>"
            
            self.notifier.send_message(error_msg)
    
    def test_alert(self):
        """Test alert functionality"""
        print("[TEST] Testing 1H40 alert...")
        
        # Create sample match data
        test_match = {
            "team1": "Test Team A",
            "team2": "Test Team B",
            "score": "0-0",
            "time": "1H 40'",
            "status": "Live",
            "league": "Test League"
        }
        
        success = self.send_1h40_alert(test_match)
        if success:
            print("[OK] Test alert sent successfully!")
        else:
            print("[ERROR] Test alert failed!")
        
        return success

def main():
    """Main function"""
    monitor = MatchAlertMonitor()
    
    # Check if user wants to test first
    import sys
    if len(sys.argv) > 1 and sys.argv[1] == "test":
        monitor.test_alert()
    else:
        monitor.monitor_matches()

if __name__ == "__main__":
    main()