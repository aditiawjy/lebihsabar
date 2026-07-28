import csv
from collections import defaultdict

file_path = 'goal_log_vsoccer.csv'

with open(file_path, mode='r', encoding='utf-8') as f:
    matches = list(csv.DictReader(f))

league_stats = defaultdict(lambda: {'total_21_12': 0, 'win_2h_over15': 0, 'matches': []})

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
        league = m.get('league', '').replace(' - 12 mins [V]', '').strip()
        h2_count = len(h2_goals)
        league_stats[league]['total_21_12'] += 1
        
        if h2_count >= 2:
            league_stats[league]['win_2h_over15'] += 1
            league_stats[league]['matches'].append({
                'datetime': m.get('datetime', ''),
                'home': m.get('home_team', ''),
                'away': m.get('away_team', ''),
                'h1_score': h1_last_score,
                'h2_goals_count': h2_count,
                'final_home': m.get('final_home', ''),
                'final_away': m.get('final_away', '')
            })

# Sort leagues by number of winning matches descending
sorted_leagues = sorted(league_stats.items(), key=lambda x: (x[1]['win_2h_over15'], x[1]['total_21_12']), reverse=True)

print("="*80)
print("REKAPITULASI DAN URUTAN LIGA (SKOR 1H = 2-1/1-2 DAN 2H >= 2 GOAL)")
print("="*80)

for rank, (league, data) in enumerate(sorted_leagues, 1):
    win_cnt = data['win_2h_over15']
    tot_cnt = data['total_21_12']
    rate = (win_cnt / tot_cnt * 100) if tot_cnt > 0 else 0
    print(f"\n[LIGA #{rank:02d}] {league.upper()}")
    print(f"    Statistik: {win_cnt} Win dari {tot_cnt} Match 1H (2-1/1-2) | Win Rate: {rate:.1f}%")
    print(f"    Daftar Pertandingan:")
    for i, g in enumerate(data['matches'], 1):
        print(f"      {i}. [{g['datetime']}] {g['home']} vs {g['away']} -> 1H: {g['h1_score']} | 2H: {g['h2_goals_count']} gol (FT: {g['final_home']}-{g['final_away']})")
