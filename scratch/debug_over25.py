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

def test(name, cond_fn):
    filt = [m for m in processed if cond_fn(m)]
    win = [m for m in filt if m['c2'] >= 3]
    rate = len(win)/len(filt)*100 if filt else 0
    print(f"Name: {name} | Sample: {len(filt)} | Win: {len(win)} | Rate: {rate:.1f}%")

test("Rule 1: 1H = 2-1 or 1-2 & KO >= 6.5", lambda m: m['c1'] == 3 and m['h1_diff'] == 1 and m['ko'] >= 6.5)
test("Rule 2: 1H = 3 & KO >= 7.0", lambda m: m['c1'] == 3 and m['ko'] >= 7.0)
test("Rule 3: Champions/Women & 1H = 2-1 or 1-2", lambda m: any(l in m['league'] for l in ['Champions', 'Women']) and m['c1'] == 3 and m['h1_diff'] == 1)
test("Rule 4: 1H diff = 1 & 1H >= 3 & KO >= 6.0", lambda m: m['h1_diff'] == 1 and m['c1'] >= 3 and m['ko'] >= 6.0)
test("Rule 5: 1H = 4 & KO >= 6.5", lambda m: m['c1'] == 4 and m['ko'] >= 6.5)

