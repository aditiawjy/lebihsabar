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

print(f"Total dataset: {len(processed)} matches\n")
print("=== SEARCHING FOR RULES WITH > 90% WIN RATE ===\n")

rules_90 = []

def eval_90(name, target_desc, cond_fn, target_fn):
    filt = [m for m in processed if cond_fn(m)]
    if len(filt) >= 5: # Sample size at least 5
        win = [m for m in filt if target_fn(m)]
        rate = len(win) / len(filt) * 100
        if rate >= 90.0:
            rules_90.append({
                'name': name,
                'target': target_desc,
                'sample': len(filt),
                'win': len(win),
                'rate': rate,
                'matches': win
            })

# Let's test a broad set of conditions for different targets:

# Target: 2H >= 1 Goal (Minimal 1 gol di Babak 2)
eval_90("1. KO Line >= 5.5 (Minimal 1 gol di 2H)", 
        "Babak 2 terjadi MINIMAL 1 GOL (2H >= 1)", 
        lambda m: m['ko'] >= 5.5, 
        lambda m: m['c2'] >= 1)

eval_90("2. Babak 1 ada 2 gol atau lebih (1H >= 2)", 
        "Babak 2 terjadi MINIMAL 1 GOL (2H >= 1)", 
        lambda m: m['c1'] >= 2, 
        lambda m: m['c2'] >= 1)

eval_90("3. KO Line >= 6.0 DAN 1H ada minimal 1 gol", 
        "Babak 2 terjadi MINIMAL 1 GOL (2H >= 1)", 
        lambda m: m['ko'] >= 6.0 and m['c1'] >= 1, 
        lambda m: m['c2'] >= 1)

# Target: 2H >= 2 Goals (Minimal 2 gol di Babak 2)
eval_90("4. KO Line >= 7.0 DAN 1H ada gol cepat (<= 15')", 
        "Babak 2 terjadi MINIMAL 2 GOL (2H >= 2)", 
        lambda m: m['ko'] >= 7.0 and m['first_h1'] <= 15, 
        lambda m: m['c2'] >= 2)

eval_90("5. KO Line >= 7.5 (Semua match KO Line >= 7.5)", 
        "Babak 2 terjadi MINIMAL 2 GOL (2H >= 2)", 
        lambda m: m['ko'] >= 7.5, 
        lambda m: m['c2'] >= 2)

eval_90("6. Babak 1 terjadi persis 3 gol DAN KO Line >= 6.0", 
        "Babak 2 terjadi MINIMAL 2 GOL (2H >= 2)", 
        lambda m: m['c1'] == 3 and m['ko'] >= 6.0, 
        lambda m: m['c2'] >= 2)

# Target: Ada Gol di Menit 60'-90'+ (Gol Babak 2)
eval_90("7. KO Line >= 6.5 DAN 1H ada minimal 2 gol", 
        "Ada gol di Babak 2 menit 60'-90'+", 
        lambda m: m['ko'] >= 6.5 and m['c1'] >= 2, 
        lambda m: any(g >= 60 for g in m['h2_min']))

eval_90("8. Babak 1 berakhir 2-1 atau 1-2 (Selisih 1 gol, total 3 gol)", 
        "Babak 2 terjadi MINIMAL 2 GOL (2H >= 2)", 
        lambda m: m['c1'] == 3 and m['h1_diff'] == 1, 
        lambda m: m['c2'] >= 2)

# Print results sorted by win rate
rules_90.sort(key=lambda x: (x['rate'], x['sample']), reverse=True)

for i, r in enumerate(rules_90, 1):
    print(f"[GOLDEN RULE >90%] #{i}: {r['name']}")

    print(f"   Target Bet  : {r['target']}")
    print(f"   Win Rate    : {r['rate']:.1f}%")
    print(f"   Sample Size : {r['sample']} match | WIN: {r['win']} | LOSS: {r['sample'] - r['win']}")
    print(f"   Contoh Match:")
    for m in r['matches'][:3]:
        print(f"     • {m['league']} | {m['match']} (1H: {m['c1']} gol, 2H: {m['c2']} gol, KO: {m['ko_raw']})")
    print("=" * 60 + "\n")

