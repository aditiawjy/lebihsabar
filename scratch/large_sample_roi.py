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
    
    processed.append({
        'match': f"{m['home_team']} vs {m['away_team']}",
        'league': m['league'],
        'c1': c1, 'c2': c2, 'tot': tot,
        'ko': ko, 'ko_raw': ko_raw,
        'h1_min': h1, 'h2_min': h2,
        'first_h1': min(h1) if h1 else 99,
        'first_h2': min(h2) if h2 else 99
    })

total_all = len(processed)
print(f"TOTAL POPULASI MATCH: {total_all} pertandingan\n")

def test_large_sample(name, target_desc, timing, cond_fn, target_fn, avg_odds):
    filt = [m for m in processed if cond_fn(m)]
    if not filt: return
    win = [m for m in filt if target_fn(m)]
    win_cnt = len(win)
    loss_cnt = len(filt) - win_cnt
    win_rate = win_cnt / len(filt) * 100
    
    pct_coverage = len(filt) / total_all * 100
    profit = (win_cnt * (avg_odds - 1.0)) - (loss_cnt * 1.0)
    roi = (profit / len(filt)) * 100
    
    print(f"==================================================")
    print(f"STRATEGI SAMPELE BESAR: {name}")
    print(f"==================================================")
    print(f" Timing Entry    : {timing}")
    print(f" Target Bet      : {target_desc}")
    print(f" Jumlah Sampel   : {len(filt)} dari {total_all} match ({pct_coverage:.1f}% dari SELURUH match!)")
    print(f" Win Rate        : {win_rate:.1f}% ({win_cnt} Win / {loss_cnt} Loss)")
    print(f" Estimasi Odds   : {avg_odds:.2f}")
    print(f" Net Profit      : {profit:+.2f} Unit")
    print(f" ESTIMASI ROI    : {roi:+.2f}%")
    print(f"--------------------------------------------------\n")

# Strategy 1: Over 0.5 2H pada SEMUA match yang 1H minimal 1 gol (Sample 92% match)
test_large_sample("1. Over 0.5 Babak 2 (Tiap 1H Ada Gol >= 1)",
                  "Over 0.5 Babak 2 (2H >= 1 Gol)",
                  "Menit 50'-55' (Odds ~ 1.30)",
                  lambda m: m['c1'] >= 1,
                  lambda m: m['c2'] >= 1,
                  1.30)

# Strategy 2: Over 0.5 2H pada SEMUA match yang KO Line >= 5.0 (Sample 85% match)
test_large_sample("2. Over 0.5 Babak 2 (Tiap KO Line >= 5.0)",
                  "Over 0.5 Babak 2 (2H >= 1 Gol)",
                  "Menit 50'-55' (Odds ~ 1.32)",
                  lambda m: m['ko'] >= 5.0,
                  lambda m: m['c2'] >= 1,
                  1.32)

# Strategy 3: Over 0.5 2H pada SEMUA match 1H minimal 2 gol (Sample 80% match)
test_large_sample("3. Over 0.5 Babak 2 (Tiap 1H Ada Gol >= 2)",
                  "Over 0.5 Babak 2 (2H >= 1 Gol)",
                  "Menit 50'-55' (Odds ~ 1.35)",
                  lambda m: m['c1'] >= 2,
                  lambda m: m['c2'] >= 1,
                  1.35)

# Strategy 4: Over 1.5 2H pada SEMUA match KO Line >= 5.5 (Sample 70% match)
test_large_sample("4. Over 1.5 Babak 2 (Tiap KO Line >= 5.5)",
                  "Over 1.5 Babak 2 (2H >= 2 Gol)",
                  "Awal Babak 2 Menit 46' (Odds ~ 1.65)",
                  lambda m: m['ko'] >= 5.5,
                  lambda m: m['c2'] >= 2,
                  1.65)

