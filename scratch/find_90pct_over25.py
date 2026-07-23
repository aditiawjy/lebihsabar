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

print("=== EXHAUSTIVE SEARCH FOR OVER 2.5 2H (2H >= 3 GOL) WITH WIN RATE > 90% ===\n")

rules_90_over25 = []

def eval_over25_90(name, cond_fn, avg_odds):
    filt = [m for m in processed if cond_fn(m)]
    if len(filt) >= 5: # Minimal sampel 5 match
        win = [m for m in filt if m['c2'] >= 3]
        win_cnt = len(win)
        loss_cnt = len(filt) - win_cnt
        win_rate = win_cnt / len(filt) * 100
        if win_rate >= 85.0: # Track top tier rules (85% - 100%)
            profit = (win_cnt * (avg_odds - 1.0)) - (loss_cnt * 1.0)
            roi = (profit / len(filt)) * 100
            rules_90_over25.append({
                'name': name,
                'sample': len(filt),
                'win': win_cnt,
                'loss': loss_cnt,
                'rate': win_rate,
                'odds': avg_odds,
                'roi': roi,
                'matches': win
            })

# Rule candidates:
# 1. 1H = 2-1 or 1-2 & KO Line >= 6.5
eval_over25_90("1H Skor 2-1 / 1-2 DAN KO Line >= 6.5", 
               lambda m: m['c1'] == 3 and m['h1_diff'] == 1 and m['ko'] >= 6.5, 
               2.20)

# 2. 1H = 3 Gol & KO Line >= 7.0
eval_over25_90("1H = Tepat 3 Gol DAN KO Line >= 7.0", 
               lambda m: m['c1'] == 3 and m['ko'] >= 7.0, 
               2.15)

# 3. Liga Champions League / Women's League DAN 1H = 2-1 / 1-2
eval_over25_90("Liga (Champions League / Women's) DAN 1H = 2-1 / 1-2", 
               lambda m: any(l in m['league'] for l in ['Champions', 'Women']) and m['c1'] == 3 and m['h1_diff'] == 1, 
               2.20)

# 4. 1H = 3 atau 4 Gol (Selisih 1 gol, misal 2-1, 1-2, 3-2, 2-3) & KO Line >= 6.0
eval_over25_90("1H Selisih 1 Gol (2-1, 1-2, 3-2, 2-3) DAN KO Line >= 6.0", 
               lambda m: m['h1_diff'] == 1 and m['c1'] in [3, 5] and m['ko'] >= 6.0, 
               2.25)

# 5. 1H = 4 Gol (Skor 2-2 atau 3-1) DAN KO Line >= 6.5
eval_over25_90("1H = Tepat 4 Gol (Skor 2-2 / 3-1) DAN KO Line >= 6.5", 
               lambda m: m['c1'] == 4 and m['ko'] >= 6.5, 
               2.25)

# Sort by rate
rules_90_over25.sort(key=lambda x: (x['rate'], x['sample']), reverse=True)

for i, r in enumerate(rules_90_over25, 1):
    print(f"[POLA TARGET OVER 2.5 2H] #{i}: {r['name']}")
    print(f"   Win Rate    : {r['rate']:.1f}%")
    print(f"   Sample Size : {r['sample']} match | WIN: {r['win']} | LOSS: {r['loss']}")
    print(f"   Estimasi Odds: {r['odds']:.2f}")
    print(f"   ESTIMASI ROI: {r['roi']:+.2f}%")
    print(f"   Contoh Match Win:")
    for m in r['matches'][:3]:
        print(f"     • {m['league']} | {m['match']} (1H: {m['c1']} gol, 2H: {m['c2']} gol, KO: {m['ko_raw']})")
    print("=" * 60 + "\n")

