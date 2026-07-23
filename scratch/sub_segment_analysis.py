import csv

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
    
    h1_last_score = "0-0"
    for p in parts:
        if p.startswith('1H'):
            h1_last_score = p.split('(')[1].replace(')', '')
            
    if h1_last_score != "0-0":
        sh, sa = map(int, h1_last_score.split('-'))
    else: sh, sa = 0, 0
        
    h1_diff = abs(sh - sa)
    
    processed.append({
        'match': f"{m['home_team']} vs {m['away_team']}",
        'league': m['league'],
        'c1': c1, 'c2': c2, 'tot': tot,
        'ko': ko, 'ko_raw': ko_raw,
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff
    })

# Sample: 1H >= 2 Goals (101 matches)
base = [m for m in processed if m['c1'] >= 2]
losses = [m for m in base if m['c2'] < 3]

# Sub-segment 1: 1H = 2-1 or 1-2
sub1 = [m for m in base if m['c1'] == 3 and m['h1_diff'] == 1]
sub1_win = [m for m in sub1 if m['c2'] >= 3]

# Sub-segment 2: 1H = 2-0 / 0-2 (2 goals only, no away goal or draw)
sub2 = [m for m in base if m['c1'] == 2 and m['h1_diff'] == 2]
sub2_win = [m for m in sub2 if m['c2'] >= 3]

print(f"Sub-segment 1 (1H Skor 2-1 / 1-2): Sample {len(sub1)} match | Win (2H >= 3): {len(sub1_win)} ({len(sub1_win)/len(sub1)*100:.1f}%)")
print(f"Sub-segment 2 (1H Skor 2-0 / 0-2): Sample {len(sub2)} match | Win (2H >= 3): {len(sub2_win)} ({len(sub2_win)/len(sub2)*100:.1f}%)")

