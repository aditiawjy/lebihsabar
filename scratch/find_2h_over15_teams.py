import csv

file_path = 'goal_log_vsoccer.csv'

with open(file_path, mode='r', encoding='utf-8') as f:
    matches = list(csv.DictReader(f))

matching_games = []
all_21_12 = []

for m in matches:
    goals_str = m.get('goals', '')
    if not goals_str:
        continue
    
    parts = [p.strip() for p in goals_str.split('|')]
    
    h1_goals = []
    h2_goals = []
    h1_last_score = '0-0'
    
    for p in parts:
        h = p.split(' ')[0]
        if h == '1H':
            h1_goals.append(p)
            if '(' in p and ')' in p:
                h1_last_score = p.split('(')[1].replace(')', '').strip()
        elif h == '2H':
            h2_goals.append(p)
            
    if h1_last_score in ['2-1', '1-2']:
        all_21_12.append(m)
        h2_count = len(h2_goals)
        if h2_count >= 2:
            matching_games.append({
                'datetime': m.get('datetime', ''),
                'league': m.get('league', ''),
                'home': m.get('home_team', ''),
                'away': m.get('away_team', ''),
                'h1_score': h1_last_score,
                'h2_goals_count': h2_count,
                'final_home': m.get('final_home', ''),
                'final_away': m.get('final_away', ''),
                'goals': goals_str
            })

print(f"Total Match dengan Skor Babak 1 = 2-1 / 1-2: {len(all_21_12)}")
print(f"Match yang Terjadi Minimal 2 Goal di Babak Kedua: {len(matching_games)}")
if len(all_21_12) > 0:
    print(f"Persentase Keberhasilan (Win Rate): {len(matching_games)/len(all_21_12)*100:.1f}%\n")

print("="*80)
print("DAFTAR TIM DAN PERTANDINGAN (1H = 2-1/1-2 DAN 2H >= 2 GOAL):")
print("="*80)

for i, g in enumerate(matching_games, 1):
    print(f"{i}. [{g['datetime']}] {g['league']}")
    print(f"   MATCH   : {g['home']} vs {g['away']}")
    print(f"   SKOR    : Skor 1H = {g['h1_score']} | Total Gol Babak 2 = {g['h2_goals_count']} gol | Skor Akhir = {g['final_home']}-{g['final_away']}")
    print(f"   RINCIAN : {g['goals']}")
    print("-" * 80)
