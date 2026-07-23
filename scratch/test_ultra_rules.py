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
        'h1_min': h1, 'h2_min': h2,
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff,
        'first_h1': min(h1) if h1 else 99,
        'last_h1': max(h1) if h1 else -1,
        'first_h2': min(h2) if h2 else 99
    })

print("=== TESTING ULTRA HIGH ACCURACY RULES (>75% WIN RATE) ===\n")

def check(name, target_desc, cond_fn, target_fn):
    filt = [m for m in processed if cond_fn(m)]
    if len(filt) >= 5:
        win = [m for m in filt if target_fn(m)]
        rate = len(win) / len(filt) * 100
        if rate >= 75.0:
            print(f"[WIN RATE: {rate:.1f}%] {name}")
            print(f" Target: {target_desc}")
            print(f" Sample Size: {len(filt)} match | Win: {len(win)}")
            print(f" Matches: {[m['match'] for m in win[:3]]}\n")

# Rule A: KO Line >= 6.5 DAN Babak 1 Meledak (>= 3 Gol) -> 2H Minimal 2 Gol
check("Kombinasi A: KO Line >= 6.5 DAN 1H >= 3 Gol",
      "Babak 2 Minimal 2 Gol (2H >= 2)",
      lambda m: m['ko'] >= 6.5 and m['c1'] >= 3,
      lambda m: m['c2'] >= 2)

# Rule B: Babak 1 Ada Gol di Menit 30-45' DAN KO Line >= 6.0 -> 2H Ada Gol Cepat (45'-60')
check("Kombinasi B: Gol 1H di Penghujung (30-45') DAN KO Line >= 6.0",
      "Gol Cepat Babak 2 (Menit 45'-60')",
      lambda m: 30 <= m['last_h1'] <= 45 and m['ko'] >= 6.0,
      lambda m: m['first_h2'] <= 60)

# Rule C: Babak 1 Selisih 1 Gol DAN KO Line >= 6.0 -> 2H Minimal 2 Gol
check("Kombinasi C: Babak 1 Selisih Tepat 1 Gol DAN KO Line >= 6.0",
      "Babak 2 Minimal 2 Gol (2H >= 2)",
      lambda m: m['h1_diff'] == 1 and m['ko'] >= 6.0,
      lambda m: m['c2'] >= 2)

# Rule D: Total Match Gol >= 6 di Liga Pilihan (Champions League / Premier League / Women)
check("Kombinasi D: Champions League / Premier League / Women's League + KO Line >= 5.5",
      "Babak 2 Minimal 2 Gol (2H >= 2)",
      lambda m: any(l in m['league'] for l in ['Champions', 'Premier', 'Women']) and m['ko'] >= 5.5,
      lambda m: m['c2'] >= 2)

# Rule E: KO Line >= 6.0 DAN 1H ada 2 atau 3 gol -> Late Goal 75'+
check("Kombinasi E: KO Line >= 6.0 DAN 1H = 2 atau 3 Gol",
      "Ada Gol di Menit 75' ke atas (Late Goal)",
      lambda m: m['ko'] >= 6.0 and m['c1'] in [2, 3],
      lambda m: any(g >= 75 for g in m['h2_min']))
