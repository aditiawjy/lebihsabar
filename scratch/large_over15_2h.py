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

print("=== DEEP SEARCH STRATEGI OVER 1.5 BABAK 2 (2H >= 2 GOL) SAMPELE BESAR & ROI TINGGI ===\n")

def test_large_2h_over15(name, filter_fn, avg_odds):
    filt = [m for m in processed if filter_fn(m)]
    if not filt: return
    # Target MUST be 2H >= 2 goals (Babak 2 minimal 2 gol / diatas 1 gol)
    win = [m for m in filt if m['c2'] >= 2]
    win_cnt = len(win)
    loss_cnt = len(filt) - win_cnt
    win_rate = win_cnt / len(filt) * 100
    
    coverage = len(filt) / total_all * 100
    profit = (win_cnt * (avg_odds - 1.0)) - (loss_cnt * 1.0)
    roi = (profit / len(filt)) * 100
    
    print(f"==================================================")
    print(f"[LARGE SAMPLE OVER 1.5 2H] {name}")
    print(f"==================================================")
    print(f" Target Bet      : Over 1.5 Babak 2 (2H minimal 2 gol)")
    print(f" Timing Entry    : Awal Babak 2 Menit 46' (Kick-Off 2H)")
    print(f" Jumlah Sampel   : {len(filt)} dari {total_all} match ({coverage:.1f}% dari SELURUH match!)")
    print(f" Win Rate        : {win_rate:.1f}% ({win_cnt} Win / {loss_cnt} Loss)")
    print(f" Estimasi Odds   : {avg_odds:.2f}")
    print(f" Net Profit      : {profit:+.2f} Unit")
    print(f" ESTIMASI ROI    : {roi:+.2f}%")
    print(f"--------------------------------------------------\n")

# Filter 1: KO Line >= 5.5 (Cover 72.9% match)
test_large_2h_over15("1. Tiap Match KO Line >= 5.5", 
                     lambda m: m['ko'] >= 5.5, 
                     1.65)

# Filter 2: 1H minimal 2 gol (Cover 81.4% match)
test_large_2h_over15("2. Tiap Match 1H Minimal 2 Gol (1H >= 2)", 
                     lambda m: m['c1'] >= 2, 
                     1.65)

# Filter 3: 1H minimal 1 gol & KO Line >= 5.5 (Cover 70.3% match)
test_large_2h_over15("3. 1H Ada Gol (1H >= 1) DAN KO Line >= 5.5", 
                     lambda m: m['c1'] >= 1 and m['ko'] >= 5.5, 
                     1.65)

# Filter 4: KO Line >= 6.0 (Cover 47.5% match)
test_large_2h_over15("4. Tiap Match KO Line >= 6.0", 
                     lambda m: m['ko'] >= 6.0, 
                     1.70)

