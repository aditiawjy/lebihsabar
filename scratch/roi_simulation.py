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
        'c1': c1, 'c2': c2, 'tot': tot,
        'ko': ko, 'ko_raw': ko_raw,
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff
    })

def simulate_roi(name, filter_fn, win_fn, avg_odds):
    filt = [m for m in processed if filter_fn(m)]
    if not filt: return
    win = [m for m in filt if win_fn(m)]
    loss_count = len(filt) - len(win)
    win_count = len(win)
    win_rate = win_count / len(filt) * 100
    
    # Flat stake = 1 unit per bet
    # Profit = (win_count * (avg_odds - 1)) - (loss_count * 1)
    profit = (win_count * (avg_odds - 1.0)) - (loss_count * 1.0)
    total_staked = len(filt) * 1.0
    roi = (profit / total_staked) * 100
    
    print(f"=== STRATEGI: {name} ===")
    print(f" Sample Size : {len(filt)} bets")
    print(f" Win Rate    : {win_rate:.1f}% ({win_count} Win / {loss_count} Loss)")
    print(f" Estimasi Odds: {avg_odds:.2f}")
    print(f" Net Profit  : {profit:+.2f} unit")
    print(f" ESTIMASI ROI: {roi:+.2f}%\n")

# Strategy 1: Over 0.5 2H pada 1H >= 2 Gol (Odds ~ 1.25)
simulate_roi("1. Safe Over 0.5 2H (saat 1H >= 2 gol)", 
             lambda m: m['c1'] >= 2, 
             lambda m: m['c2'] >= 1, 
             1.25)

# Strategy 2: Over 1.5 2H pada 1H Skor 2-1 / 1-2 (Odds ~ 1.70)
simulate_roi("2. Value Over 1.5 2H (saat 1H = 2-1 / 1-2)", 
             lambda m: m['c1'] == 3 and m['h1_diff'] == 1, 
             lambda m: m['c2'] >= 2, 
             1.70)

# Strategy 3: Over 1.5 2H pada KO Line >= 7.0 (Odds ~ 1.75)
simulate_roi("3. Value Over 1.5 2H (saat KO Line >= 7.0)", 
             lambda m: m['ko'] >= 7.0, 
             lambda m: m['c2'] >= 2, 
             1.75)

# Strategy 4: High Odds Over 0.5 Late Goal Menit 75'+ saat 1H = 2 atau 3 gol (Odds ~ 1.95)
simulate_roi("4. High Odds Late Goal 75'+ (saat 1H = 2 atau 3 gol)", 
             lambda m: m['c1'] in [2, 3], 
             lambda m: m['c2'] >= 1, 
             1.95)

