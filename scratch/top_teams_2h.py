import csv
from collections import defaultdict

file_path = 'goal_log_vsoccer.csv'

with open(file_path, mode='r', encoding='utf-8') as f:
    matches = list(csv.DictReader(f))

# Track teams participation in matches where 1H was 2-1/1-2
team_stats = defaultdict(lambda: {'total_21_12': 0, 'win_2h_over15': 0, 'matches': []})

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
        home = m.get('home_team', '').replace(' [V]', '').strip()
        away = m.get('away_team', '').replace(' [V]', '').strip()
        h2_count = len(h2_goals)
        
        # Add to home team
        team_stats[home]['total_21_12'] += 1
        if h2_count >= 2:
            team_stats[home]['win_2h_over15'] += 1
            team_stats[home]['matches'].append((m.get('datetime', ''), f"{home} vs {away}", h1_last_score, h2_count, f"{m.get('final_home', '')}-{m.get('final_away', '')}"))
            
        # Add to away team
        team_stats[away]['total_21_12'] += 1
        if h2_count >= 2:
            team_stats[away]['win_2h_over15'] += 1
            team_stats[away]['matches'].append((m.get('datetime', ''), f"{home} vs {away}", h1_last_score, h2_count, f"{m.get('final_home', '')}-{m.get('final_away', '')}"))

# Sort teams by number of winning matches (win_2h_over15) descending
sorted_teams = sorted(team_stats.items(), key=lambda x: (x[1]['win_2h_over15'], x[1]['total_21_12']), reverse=True)

print("="*80)
print("TOP TIM PALING BANYAK BERHASIL (1H = 2-1/1-2 DAN BABAK 2 TERJADI >= 2 GOL TOTAL)")
print("="*80)

for rank, (team, data) in enumerate(sorted_teams[:20], 1):
    win_cnt = data['win_2h_over15']
    tot_cnt = data['total_21_12']
    rate = (win_cnt / tot_cnt * 100) if tot_cnt > 0 else 0
    print(f"\n[TIM #{rank:02d}] {team.upper()}")
    print(f"   Statistik : {win_cnt} Kali Win dari {tot_cnt} Pertandingan 1H (2-1/1-2) | Win Rate: {rate:.1f}%")
    print(f"   Rincian Pertandingan:")
    for dt, match_name, h1_score, h2_cnt, ft in data['matches']:
        print(f"     • [{dt}] {match_name} -> Skor 1H: {h1_score} | Gol 2H: {h2_cnt} gol (FT: {ft})")

