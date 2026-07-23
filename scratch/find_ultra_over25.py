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
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff,
        'first_h1': min(h1) if h1 else 99,
        'last_h1': max(h1) if h1 else -1
    })

print("=== ALL COMBINATIONS TEST FOR OVER 2.5 2H (2H >= 3 GOL) ===")

# Test various feature combinations
combos = [
    ("KO Line >= 7.5 (Semua match KO Line 7.5/8)", lambda m: m['ko'] >= 7.5),
    ("Women's League & KO Line >= 7.0", lambda m: "Women" in m['league'] and m['ko'] >= 7.0),
    ("International Friendly & KO Line >= 7.0", lambda m: "Friendly" in m['league'] and m['ko'] >= 7.0),
    ("1H Ada 2 Gol (2-0 / 0-2 / 1-1) DAN KO Line >= 7.0", lambda m: m['c1'] == 2 and m['ko'] >= 7.0),
    ("1H skor 1-1 DAN KO Line >= 6.0", lambda m: m['sh'] == 1 and m['sa'] == 1 and m['ko'] >= 6.0),
    ("Gol pertama 1H meledak cepat <= 5' DAN KO Line >= 6.5", lambda m: m['first_h1'] <= 5 and m['ko'] >= 6.5)
]

for name, fn in combos:
    filt = [m for m in processed if fn(m)]
    if filt:
        win = [m for m in filt if m['c2'] >= 3]
        rate = len(win) / len(filt) * 100
        print(f"• {name}")
        print(f"  Sample: {len(filt)} match | Win: {len(win)} | Win Rate: {rate:.1f}%\n")

