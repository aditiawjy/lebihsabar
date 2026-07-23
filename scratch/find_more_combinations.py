import csv
from collections import defaultdict

with open('goal_log_vsoccer.csv', mode='r', encoding='utf-8') as f:
    matches = [m for m in csv.DictReader(f) if m['goals']]

processed = []
for m in matches:
    parts = [p.strip() for p in m['goals'].split('|')]
    h1 = []
    h2 = []
    for p in parts:
        h = p.split(' ')[0]
        mn = int(p.split(' ')[1].replace("'", ""))
        if h == '1H': h1.append(mn)
        elif h == '2H': h2.append(mn)
        
    c1 = len(h1)
    c2 = len(h2)
    tot = c1 + c2
    
    ko_raw = m['ko_line']
    try:
        if '/' in ko_raw:
            p1, p2 = ko_raw.split('/')
            ko = (float(p1) + float(p2))/2.0
        else: ko = float(ko_raw)
    except: ko = 0.0
    
    ko_over = float(m['ko_over']) if m['ko_over'] else 0.0
    ko_under = float(m['ko_under']) if m['ko_under'] else 0.0
    
    # Check score pattern in 1H (e.g. Home leading, Away leading, Draw in 1H)
    f_home = int(m['final_home']) if m['final_home'] else 0
    f_away = int(m['final_away']) if m['final_away'] else 0
    
    # Get 1H score
    # Look at last 1H goal score
    h1_last_score = "0-0"
    for p in parts:
        if p.startswith('1H'):
            h1_last_score = p.split('(')[1].replace(')', '')
            
    if h1_last_score != "0-0":
        sh, sa = map(int, h1_last_score.split('-'))
    else:
        sh, sa = 0, 0
        
    h1_diff = abs(sh - sa)
    h1_is_draw = (sh == sa)
    
    processed.append({
        'match': f"{m['home_team']} vs {m['away_team']}",
        'league': m['league'],
        'c1': c1, 'c2': c2, 'tot': tot,
        'ko': ko, 'ko_raw': ko_raw,
        'ko_over': ko_over, 'ko_under': ko_under,
        'h1_min': h1, 'h2_min': h2,
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff, 'h1_is_draw': h1_is_draw,
        'first_h1': min(h1) if h1 else 99,
        'last_h1': max(h1) if h1 else -1,
        'first_h2': min(h2) if h2 else 99
    })

print(f"Total Matches: {len(processed)}\n")

# Exhaustive Search for Rules with Sample >= 5 and Win Rate >= 80%
high_rules = []

# Generate candidate conditions:
def eval_rule(name, cond, target, target_name):
    filt = [m for m in processed if cond(m)]
    if len(filt) >= 5:
        win = [m for m in filt if target(m)]
        rate = len(win) / len(filt) * 100
        if rate >= 78.0:
            high_rules.append({
                'name': name,
                'target': target_name,
                'sample': len(filt),
                'win': len(win),
                'rate': rate,
                'matches': win
            })

# 1. 2H >= 2 goals
eval_rule("Babak 1 Seri / Draw (1H Draw) + KO Line >= 5.5", 
          lambda m: m['h1_is_draw'] and m['ko'] >= 5.5,
          lambda m: m['c2'] >= 2, "2H minimal 2 gol (2H >= 2)")

eval_rule("Gol Pertama Terjadi di Menit 1-10' + KO Line >= 6.0", 
          lambda m: m['first_h1'] <= 10 and m['ko'] >= 6.0,
          lambda m: m['c2'] >= 2, "2H minimal 2 gol (2H >= 2)")

eval_rule("Babak 1 Selisih 1 Gol (1H Diff = 1) + KO Line >= 6.0", 
          lambda m: m['h1_diff'] == 1 and m['ko'] >= 6.0,
          lambda m: m['c2'] >= 2, "2H minimal 2 gol (2H >= 2)")

eval_rule("Gol Terakhir Babak 1 di Menit 35-45' (Late 1H Goal) + KO Line >= 5.5", 
          lambda m: 35 <= m['last_h1'] <= 45 and m['ko'] >= 5.5,
          lambda m: m['c2'] >= 2, "2H minimal 2 gol (2H >= 2)")

# 2. 2H >= 3 goals
eval_rule("Babak 1 Terjadi 4 Gol (1H = 4 Gol) + KO Line >= 5.5", 
          lambda m: m['c1'] == 4 and m['ko'] >= 5.5,
          lambda m: m['c2'] >= 3, "2H meledak minimal 3 gol (2H >= 3)")

eval_rule("Babak 1 Seri / Draw (1H Draw) + KO Line >= 6.5", 
          lambda m: m['h1_is_draw'] and m['ko'] >= 6.5,
          lambda m: m['c2'] >= 3, "2H meledak minimal 3 gol (2H >= 3)")

eval_rule("Babak 1 Selisih >= 2 Gol (Tim unggul telak) + KO Line >= 6.0", 
          lambda m: m['h1_diff'] >= 2 and m['ko'] >= 6.0,
          lambda m: m['c2'] >= 3, "2H meledak minimal 3 gol (2H >= 3)")

# 3. Early 2H Goal (Gol Cepat Babak 2: Menit 45'-60')
eval_rule("Babak 1 Terjadi 2, 3, atau 4 Gol + KO Line >= 5.5", 
          lambda m: m['c1'] in [2, 3, 4] and m['ko'] >= 5.5,
          lambda m: m['first_h2'] <= 60, "Ada gol cepat di awal Babak 2 (menit 45'-60')")

eval_rule("Gol Terakhir Babak 1 di Menit 30-45' + KO Line >= 5.5", 
          lambda m: 30 <= m['last_h1'] <= 45 and m['ko'] >= 5.5,
          lambda m: m['first_h2'] <= 60, "Ada gol cepat di awal Babak 2 (menit 45'-60')")

# Sort rules by win rate
high_rules.sort(key=lambda x: x['rate'], reverse=True)

for i, r in enumerate(high_rules, 1):
    print(f"[{i}] {r['name']}")
    print(f"    Target: {r['target']}")
    print(f"    Sample: {r['sample']} match | Win: {r['win']} | WIN RATE: {r['rate']:.1f}%")
    print("-" * 55)
