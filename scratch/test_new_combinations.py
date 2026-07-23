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
    
    # Get 1H score
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
        'h1_min': h1, 'h2_min': h2,
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff, 'h1_is_draw': h1_is_draw,
        'first_h1': min(h1) if h1 else 99,
        'last_h1': max(h1) if h1 else -1,
        'first_h2': min(h2) if h2 else 99
    })

def test(name, target_desc, cond_fn, target_fn):
    filt = [m for m in processed if cond_fn(m)]
    if len(filt) >= 4:
        win = [m for m in filt if target_fn(m)]
        rate = len(win) / len(filt) * 100
        print(f"==================================================")
        print(f"[POLA ACCURACY HIGH] {name}")
        print(f"==================================================")
        print(f" Target: {target_desc}")
        print(f" Sample: {len(filt)} match | Win: {len(win)} | WIN RATE: {rate:.1f}%")
        print(f"--------------------------------------------------")
        for m in win[:4]:
            print(f"  • {m['league']} | {m['match']} -> 1H ({m['sh']}-{m['sa']}) | 2H: {m['c2']} gol | KO: {m['ko_raw']}")
        print("\n")

# Combination 1: Babak 1 Ketinggalan Tipis 1 Gol (Selisih 1 Gol) + KO Line >= 6.0
test("Kombinasi 1: Babak 1 Selisih Tepat 1 Gol (1H Diff = 1) + KO Line >= 6.0",
     "Babak 2 Minimal Terjadi 2 Gol (2H >= 2)",
     lambda m: m['h1_diff'] == 1 and m['ko'] >= 6.0,
     lambda m: m['c2'] >= 2)

# Combination 2: Gol Akhir Babak 1 Terjadi Menit 30'-45' + KO Line >= 5.5
test("Kombinasi 2: Gol Babak 1 Terjadi di Penghujung (Menit 30'-45') + KO Line >= 5.5",
     "Gol Cepat Babak 2 Langsung Terjadi di Menit 45'-60'",
     lambda m: 30 <= m['last_h1'] <= 45 and m['ko'] >= 5.5,
     lambda m: m['first_h2'] <= 60)

# Combination 3: Babak 1 Terjadi Minimal 2 Gol + Gol Pertama 1H <= 15'
test("Kombinasi 3: Gol Cepat di Babak 1 (<= 15') DAN Total 1H >= 2 Gol + KO Line >= 5.5",
     "Babak 2 Minimal Terjadi 2 Gol (2H >= 2)",
     lambda m: m['first_h1'] <= 15 and m['c1'] >= 2 and m['ko'] >= 5.5,
     lambda m: m['c2'] >= 2)

# Combination 4: Babak 1 Imbang / Draw (1-1 atau 2-2) + KO Line >= 5.5
test("Kombinasi 4: Babak 1 Berakhir Imbang (Draw 1-1 / 2-2) + KO Line >= 5.5",
     "Babak 2 Minimal Terjadi 2 Gol (2H >= 2)",
     lambda m: m['h1_is_draw'] and m['c1'] > 0 and m['ko'] >= 5.5,
     lambda m: m['c2'] >= 2)

# Combination 5: Babak 1 Terjadi 4 Gol (1H = 4 Gol)
test("Kombinasi 5: Babak 1 Meledak 4 Gol (1H = 4 Gol) + KO Line >= 5.5",
     "Babak 2 Tetap Meledak Minimal 3 Gol (2H >= 3)",
     lambda m: m['c1'] == 4 and m['ko'] >= 5.5,
     lambda m: m['c2'] >= 3)
