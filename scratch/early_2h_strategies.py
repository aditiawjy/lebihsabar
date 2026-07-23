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
        'first_h2': min(h2) if h2 else 99
    })

print("=== DEEP SEARCH STRATEGI UNTUK ENTRY DI AWAL / TENGAH BABAK 2 (MENIT 45'-65') ===\n")

def test_early_strategy(name, cond_fn, target_fn, target_desc, avg_odds):
    filt = [m for m in processed if cond_fn(m)]
    if len(filt) >= 5:
        win = [m for m in filt if target_fn(m)]
        loss_cnt = len(filt) - len(win)
        win_cnt = len(win)
        win_rate = win_cnt / len(filt) * 100
        
        profit = (win_cnt * (avg_odds - 1.0)) - (loss_cnt * 1.0)
        roi = (profit / len(filt)) * 100
        
        print(f"==================================================")
        print(f"[REKOMENDASI EARLY ENTRY] {name}")
        print(f"==================================================")
        print(f" Target Bet      : {target_desc}")
        print(f" Timing Entry    : Awal / Tengah Babak 2 (Menit 45'-60')")
        print(f" Sample Size     : {len(filt)} pertandingan")
        print(f" Win Rate        : {win_rate:.1f}% ({win_cnt} Win / {loss_cnt} Loss)")
        print(f" Estimasi Odds   : {avg_odds:.2f}")
        print(f" ESTIMASI ROI    : {roi:+.2f}%")
        print(f"--------------------------------------------------\n")

# Strategy A: Over 1.5 2H di menit 45'-50' jika 1H = 2 atau 3 gol & KO Line >= 6.5
test_early_strategy("STRATEGI A: Over 1.5 Babak 2 (Entry Menit 46'-50')",
                    lambda m: m['c1'] in [2, 3] and m['ko'] >= 6.5,
                    lambda m: m['c2'] >= 2,
                    "Over 1.5 Babak 2 (2H >= 2 Gol)",
                    1.75)

# Strategy B: Over 1.5 2H di menit 45'-50' jika 1H = 2-1 atau 1-2
test_early_strategy("STRATEGI B: Over 1.5 Babak 2 pada Skor 1H 2-1 / 1-2 (Entry Menit 46'-50')",
                    lambda m: m['c1'] == 3 and m['h1_diff'] == 1,
                    lambda m: m['c2'] >= 2,
                    "Over 1.5 Babak 2 (2H >= 2 Gol)",
                    1.70)

# Strategy C: Over 0.5 2H di menit 55'-60' jika 1H >= 2 gol (Market Masih Buka Lembar Murah)
test_early_strategy("STRATEGI C: Over 0.5 Babak 2 (Entry Menit 55'-60')",
                    lambda m: m['c1'] >= 2,
                    lambda m: m['c2'] >= 1,
                    "Over 0.5 Babak 2 (2H >= 1 Gol)",
                    1.35)

# Strategy D: Over 1.5 2H di menit 45'-50' jika KO Line >= 7.0
test_early_strategy("STRATEGI D: Over 1.5 Babak 2 pada KO Line >= 7.0 (Entry Menit 46'-50')",
                    lambda m: m['ko'] >= 7.0,
                    lambda m: m['c2'] >= 2,
                    "Over 1.5 Babak 2 (2H >= 2 Gol)",
                    1.75)

