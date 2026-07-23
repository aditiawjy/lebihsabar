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
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff
    })

total_all = len(processed)
print(f"TOTAL POPULASI MATCH: {total_all} pertandingan\n")

print("=== DEEP SEARCH STRATEGI OVER 2.5 BABAK 2 (2H >= 3 GOL / MELEDAK) ===\n")

def test_over25_2h(name, filter_fn, avg_odds):
    filt = [m for m in processed if filter_fn(m)]
    if not filt: return
    # Target MUST be 2H >= 3 goals (Babak 2 Over 2.5)
    win = [m for m in filt if m['c2'] >= 3]
    win_cnt = len(win)
    loss_cnt = len(filt) - win_cnt
    win_rate = win_cnt / len(filt) * 100
    
    coverage = len(filt) / total_all * 100
    profit = (win_cnt * (avg_odds - 1.0)) - (loss_cnt * 1.0)
    roi = (profit / len(filt)) * 100
    
    print(f"==================================================")
    print(f"[TARGET BET: OVER 2.5 BABAK 2 (2H >= 3 GOL)] {name}")
    print(f"==================================================")
    print(f" Timing Entry    : Awal Babak 2 Menit 46' (Odds ~ {avg_odds:.2f})")
    print(f" Jumlah Sampel   : {len(filt)} dari {total_all} match ({coverage:.1f}% dari SELURUH match)")
    print(f" Win Rate        : {win_rate:.1f}% ({win_cnt} Win / {loss_cnt} Loss)")
    print(f" Net Profit      : {profit:+.2f} Unit")
    print(f" ESTIMASI ROI    : {roi:+.2f}%")
    print(f"--------------------------------------------------\n")

# Filter 1: 1H meledak 3 gol & KO Line >= 5.5 (Win Rate tinggi)
test_over25_2h("1. Babak 1 persis 3 Gol (1H = 3) & KO Line >= 5.5", 
               lambda m: m['c1'] == 3 and m['ko'] >= 5.5, 
               2.25)

# Filter 2: 1H 2 atau 3 gol & KO Line >= 6.5
test_over25_2h("2. Babak 1 Ada 2 atau 3 Gol & KO Line >= 6.5", 
               lambda m: m['c1'] in [2, 3] and m['ko'] >= 6.5, 
               2.20)

# Filter 3: KO Line Super Tinggi >= 7.0 (Sampel lumayan besar)
test_over25_2h("3. Semua Match KO Line Super Tinggi >= 7.0", 
               lambda m: m['ko'] >= 7.0, 
               2.15)

# Filter 4: Sampel Besar: Tiap Match KO Line >= 6.0
test_over25_2h("4. Sampel Besar: Tiap Match KO Line >= 6.0", 
               lambda m: m['ko'] >= 6.0, 
               2.25)

# Filter 5: Sampel Sangat Besar: Tiap Match 1H Minimal 2 Gol
test_over25_2h("5. Sampel Sangat Besar: Tiap Match 1H Minimal 2 Gol (1H >= 2)", 
               lambda m: m['c1'] >= 2, 
               2.35)

