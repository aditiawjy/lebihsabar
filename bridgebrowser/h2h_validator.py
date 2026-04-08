import requests
import json

class H2HValidator:
    def __init__(self, api_base_url="http://localhost/test/bridgebrowser"):
        self.api_base_url = api_base_url.rstrip('/')
    
    def validate_under15_condition(self, team1, team2):
        """
        Validate if the match between team1 and team2 meets under 1.5 goals criteria
        based on H2H history and team averages
        """
        try:
            # Clean team names (remove [V] and extra spaces)
            team1_clean = self.clean_team_name(team1)
            team2_clean = self.clean_team_name(team2)
            
            # Call the validation API
            url = f"{self.api_base_url}/match_history_api.php/validate-under15"
            params = {
                'team1': team1_clean,
                'team2': team2_clean
            }
            
            response = requests.get(url, params=params, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    return data.get('is_valid_for_under15', False), data
                else:
                    print(f"[WARNING] API validation failed: {data.get('error', 'Unknown error')}")
                    return False, None
            else:
                print(f"[WARNING] API request failed with status {response.status_code}")
                return False, None
                
        except requests.exceptions.RequestException as e:
            print(f"[WARNING] H2H validation request failed: {e}")
            return False, None
        except Exception as e:
            print(f"[WARNING] H2H validation error: {e}")
            return False, None
    
    def save_match_result(self, team1, team2, score, league="Unknown"):
        """
        Save match result to database for future H2H analysis
        """
        try:
            # Clean team names
            team1_clean = self.clean_team_name(team1)
            team2_clean = self.clean_team_name(team2)
            
            # Parse score
            team1_score, team2_score = self.parse_score(score)
            if team1_score is None or team2_score is None:
                return False
            
            # Call the save API
            url = f"{self.api_base_url}/match_history_api.php/save-match"
            data = {
                'team1': team1_clean,
                'team2': team2_clean,
                'team1_score': team1_score,
                'team2_score': team2_score,
                'league': league
            }
            
            response = requests.post(url, json=data, timeout=10)
            
            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    print(f"[INFO] Match saved: {team1_clean} vs {team2_clean} ({score})")
                    return True
                else:
                    print(f"[WARNING] Failed to save match: {result.get('error', 'Unknown error')}")
                    return False
            else:
                print(f"[WARNING] Save match request failed with status {response.status_code}")
                return False
                
        except Exception as e:
            print(f"[WARNING] Save match error: {e}")
            return False
    
    def clean_team_name(self, team_name):
        """
        Clean team name by removing [V] markers and extra spaces
        """
        if not team_name:
            return ""
        
        # Remove [V] markers
        cleaned = team_name.replace('[V]', '').strip()
        
        # Remove extra spaces
        cleaned = ' '.join(cleaned.split())
        
        return cleaned
    
    def parse_score(self, score_str):
        """
        Parse score string to get individual team scores
        """
        try:
            if ':' in score_str:
                parts = score_str.split(':')
                team1_score = int(parts[0].strip())
                team2_score = int(parts[1].strip())
                return team1_score, team2_score
            elif '-' in score_str:
                parts = score_str.split('-')
                team1_score = int(parts[0].strip())
                team2_score = int(parts[1].strip())
                return team1_score, team2_score
            else:
                return None, None
        except:
            return None, None
    
    def create_database_tables(self):
        """
        Create database tables if they don't exist
        """
        try:
            url = f"{self.api_base_url}/match_history_api.php/create-tables"
            response = requests.post(url, timeout=10)
            
            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    print("[INFO] Database tables created successfully")
                    return True
                else:
                    print(f"[WARNING] Failed to create tables: {result.get('error', 'Unknown error')}")
                    return False
            else:
                print(f"[WARNING] Create tables request failed with status {response.status_code}")
                return False
                
        except Exception as e:
            print(f"[WARNING] Create tables error: {e}")
            return False
    
    def get_h2h_history(self, team1, team2):
        """
        Get H2H history between two teams
        """
        try:
            # Clean team names
            team1_clean = self.clean_team_name(team1)
            team2_clean = self.clean_team_name(team2)
            
            # Call the H2H API
            url = f"{self.api_base_url}/match_history_api.php/h2h-check"
            params = {
                'team1': team1_clean,
                'team2': team2_clean
            }
            
            response = requests.get(url, params=params, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    # Add last_match_under_10 check
                    h2h_data = data.get('h2h_data', {})
                    if h2h_data.get('total_matches', 0) > 0:
                        # Check if last match was under 1-0 (0-0, 1-0, 0-1)
                        last_match_under_10 = self.check_last_match_under_10(team1_clean, team2_clean)
                        h2h_data['last_match_under_10'] = last_match_under_10
                    return h2h_data
                else:
                    print(f"[WARNING] H2H API failed: {data.get('error', 'Unknown error')}")
                    return None
            else:
                print(f"[WARNING] H2H API request failed with status {response.status_code}")
                return None
                
        except requests.exceptions.RequestException as e:
            print(f"[WARNING] H2H request failed: {e}")
            return None
        except Exception as e:
            print(f"[WARNING] H2H error: {e}")
            return None
    
    def get_team_average_goals(self, team, last_n_matches=5):
        """
        Get team's average goals in recent matches
        """
        try:
            # Clean team name
            team_clean = self.clean_team_name(team)
            
            # Call the team average API
            url = f"{self.api_base_url}/match_history_api.php/team-average"
            params = {
                'team': team_clean,
                'matches': last_n_matches
            }
            
            response = requests.get(url, params=params, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    return data.get('team_data', {})
                else:
                    print(f"[WARNING] Team average API failed: {data.get('error', 'Unknown error')}")
                    return None
            else:
                print(f"[WARNING] Team average API request failed with status {response.status_code}")
                return None
                
        except requests.exceptions.RequestException as e:
            print(f"[WARNING] Team average request failed: {e}")
            return None
        except Exception as e:
            print(f"[WARNING] Team average error: {e}")
            return None
    
    def check_last_match_under_10(self, team1, team2):
        """
        Check if the last H2H match between team1 and team2 ended under 1-0
        (i.e., 0-0, 1-0, or 0-1)
        """
        try:
            # Get H2H matches data
            url = f"{self.api_base_url}/match_history_api.php/h2h-check"
            params = {
                'team1': team1,
                'team2': team2
            }
            
            response = requests.get(url, params=params, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    matches = data.get('matches', [])
                    if matches and len(matches) > 0:
                        # Get the most recent match (first in the list since it's ordered by date DESC)
                        last_match = matches[0]
                        total_goals = last_match.get('total_goals', 0)
                        
                        # Check if last match was under 1-0 (total goals <= 1)
                        return total_goals <= 1
            
            return False
                
        except Exception as e:
            print(f"[WARNING] Error checking last match under 1-0: {e}")
            return False
    
    def check_h2h_draws(self, team1, team2, min_draws=2):
        """
        Check if H2H history has 2 or more consecutive draw matches
        """
        try:
            # Get H2H matches data directly from API
            url = f"{self.api_base_url}/match_history_api.php/h2h-check"
            params = {
                'team1': self.clean_team_name(team1),
                'team2': self.clean_team_name(team2)
            }
            
            response = requests.get(url, params=params, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    matches = data.get('matches', [])
                    if not matches or len(matches) < min_draws:
                        return False
                    
                    # Check for consecutive draws from the most recent matches
                    consecutive_draws = 0
                    for match in matches:
                        team1_score = int(match.get('team1_score', 0))
                        team2_score = int(match.get('team2_score', 0))
                        
                        if team1_score == team2_score:  # Draw
                            consecutive_draws += 1
                            if consecutive_draws >= min_draws:
                                return True
                        else:
                            # Reset counter if not consecutive
                            consecutive_draws = 0
                    
                    return False
            
            return False
                
        except Exception as e:
            print(f"Error checking H2H consecutive draws: {e}")
            return False