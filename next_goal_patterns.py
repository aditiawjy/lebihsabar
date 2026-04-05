import csv, re, sys
sys.stdout.reconfigure(encoding='utf-8')

rows = list(csv.DictReader(open('goal_log.csv', encoding='utf-8')))
matches = []
for row in rows:
    goals_str = row['goals'].strip()
    if not goals_str: continue
    lg = '20min' if '20 Mins' in row['league'] else ('16min' if '16 Mins' in row['league'] else '15min')
    entries = [g.strip() for g in goals_str.split('|') if g.strip()]
    goals_1h, goals_2h_entries = [], []
    for entry in entries:
        m = re.match(r'(1H|2H)\s+(\d+)\'\s*\((\d+)-(\d+)\)', entry, re.I)
        if m:
            if m.group(1).upper() == '1H':
                goals_1h.append({'min': int(m.group(2)), 'h': int(m.group(3)), 'a': int(m.group(4))})
            else:
                goals_2h_entries.append({'min': int(m.group(2)), 'h': int(m.group(3)), 'a': int(m.group(4))})
    if not goals_1h: continue
    ht_h = goals_1h[-1]['h']; ht_a = goals_1h[-1]['a']
    fm = goals_1h[0]['min']; lm = goals_1h[-1]['min']
    span = lm - fm
    gaps = [goals_1h[i]['min'] - goals_1h[i-1]['min'] for i in range(1, len(goals_1h))]
    max_gap = max(gaps) if gaps else 0
    min_gap = min(gaps) if gaps else 99
    n1h = len(goals_1h)
    scorers = []
    ph = pa = 0
    for g in goals_1h:
        if g['h'] > ph: scorers.append('H')
        if g['a'] > pa: scorers.append('A')
        ph, pa = g['h'], g['a']
    has_2h = len(goals_2h_entries) > 0
    if goals_2h_entries:
        first_2h = goals_2h_entries[0]
        if first_2h['h'] > ht_h:
            next_goal = 'H'
        elif first_2h['a'] > ht_a:
            next_goal = 'A'
        else:
            next_goal = '?'
    else:
        next_goal = None
    matches.append({
        'lg': lg, 'ht_h': ht_h, 'ht_a': ht_a, 'has_2h': has_2h,
        'next_goal': next_goal, 'fm': fm, 'lm': lm, 'span': span,
        'max_gap': max_gap, 'min_gap': min_gap, 'n1h': n1h,
        'scorers': scorers, 'sc_str': ''.join(scorers),
        'selisih': abs(ht_h - ht_a), 'total_1h': ht_h + ht_a,
    })

total = len(matches)
has_2h_cnt = sum(1 for m in matches if m['has_2h'])
next_h = sum(1 for m in matches if m['next_goal']=='H')
next_a = sum(1 for m in matches if m['next_goal']=='A')
print(f'Total matches: {total}, has 2H: {has_2h_cnt} ({has_2h_cnt/total*100:.0f}%)')
print(f'Next goal HOME: {next_h}/{has_2h_cnt} ({next_h/has_2h_cnt*100:.0f}%), AWAY: {next_a}/{has_2h_cnt} ({next_a/has_2h_cnt*100:.0f}%)')
print()

candidates = []
def test(label, grp, target):
    t = len(grp)
    if t < 10: return
    p = sum(1 for x in grp if x['next_goal']==target)
    acc = p/t
    if t >= 15 and acc >= 0.75:
        candidates.append((acc, p, t, label, target))
    elif t >= 12 and acc >= 0.80:
        candidates.append((acc, p, t, label + ' [small]', target))

with_2h = [m for m in matches if m['has_2h']]

for h in range(0,5):
    for a in range(0,5):
        for lg in [None,'15min','16min','20min']:
            grp = [m for m in with_2h if m['ht_h']==h and m['ht_a']==a and (lg is None or m['lg']==lg)]
            for tgt in ['H','A']:
                test('HT=%d-%d%s'%(h,a,' '+lg if lg else ''), grp, tgt)

for last in ['H','A']:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in with_2h if m['scorers'] and m['scorers'][-1]==last and (lg is None or m['lg']==lg)]
        for tgt in ['H','A']:
            test('last=%s%s'%(last,' '+lg if lg else ''), grp, tgt)
            for n in [1,2,3]:
                g2 = [m for m in grp if m['n1h']==n]
                test('last=%s+n=%d%s'%(last,n,' '+lg if lg else ''), g2, tgt)
            for sel in [0,1,2]:
                g2 = [m for m in grp if m['selisih']==sel]
                test('last=%s+sel=%d%s'%(last,sel,' '+lg if lg else ''), g2, tgt)
            for lm in range(0,11):
                g2 = [m for m in grp if m['lm']==lm]
                test('last=%s+lm=%d%s'%(last,lm,' '+lg if lg else ''), g2, tgt)

for lg in [None,'15min','16min','20min']:
    for grp,label in [
        ([m for m in with_2h if m['ht_h']>m['ht_a'] and (lg is None or m['lg']==lg)], 'home_lead'),
        ([m for m in with_2h if m['ht_a']>m['ht_h'] and (lg is None or m['lg']==lg)], 'away_lead'),
        ([m for m in with_2h if m['ht_h']==m['ht_a'] and (lg is None or m['lg']==lg)], 'draw'),
    ]:
        for tgt in ['H','A']:
            test(label+(' '+lg if lg else ''), grp, tgt)
            for sel in [1,2]:
                g2 = [m for m in grp if m['selisih']==sel]
                test(label+'+sel=%d%s'%(sel,' '+lg if lg else ''), g2, tgt)
            for lm_min in [4,5,6,7]:
                g2 = [m for m in grp if m['lm']>=lm_min]
                test(label+'+lm>=%d%s'%(lm_min,' '+lg if lg else ''), g2, tgt)

for sc in ['A','H','AA','HH','AH','HA','AAA','HHH','AHA','HAH','AHAA','HAHH','AHH','HAA','AHHAA']:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in with_2h if m['sc_str']==sc and (lg is None or m['lg']==lg)]
        for tgt in ['H','A']:
            test('sc=%s%s'%(sc,' '+lg if lg else ''), grp, tgt)

for n in [1,2,3]:
    for sel in [0,1,2]:
        for lg in [None,'15min','16min','20min']:
            grp = [m for m in with_2h if m['n1h']==n and m['selisih']==sel and (lg is None or m['lg']==lg)]
            for tgt in ['H','A']:
                test('n1h=%d+sel=%d%s'%(n,sel,' '+lg if lg else ''), grp, tgt)

for t_val in [1,2,3,4]:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in with_2h if m['total_1h']==t_val and (lg is None or m['lg']==lg)]
        for tgt in ['H','A']:
            test('total=%d%s'%(t_val,' '+lg if lg else ''), grp, tgt)

for sp in [0,1,2,3,4,5,6,7]:
    for last in ['H','A']:
        for lg in [None,'15min','16min','20min']:
            grp = [m for m in with_2h if m['span']==sp and m['scorers'] and m['scorers'][-1]==last and (lg is None or m['lg']==lg)]
            for tgt in ['H','A']:
                test('span=%d+last=%s%s'%(sp,last,' '+lg if lg else ''), grp, tgt)

for mg in [0,1,2,3,4,5,6,7]:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in with_2h if m['max_gap']==mg and (lg is None or m['lg']==lg)]
        for tgt in ['H','A']:
            test('max_gap=%d%s'%(mg,' '+lg if lg else ''), grp, tgt)
        for last in ['H','A']:
            g2 = [m for m in grp if m['scorers'] and m['scorers'][-1]==last]
            test('max_gap=%d+last=%s%s'%(mg,last,' '+lg if lg else ''), g2, tgt)

# first scorer
for first in ['H','A']:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in with_2h if m['scorers'] and m['scorers'][0]==first and (lg is None or m['lg']==lg)]
        for tgt in ['H','A']:
            test('first=%s%s'%(first,' '+lg if lg else ''), grp, tgt)
            for last in ['H','A']:
                g2 = [m for m in grp if m['scorers'][-1]==last]
                test('first=%s+last=%s%s'%(first,last,' '+lg if lg else ''), g2, tgt)

candidates.sort(reverse=True)
seen = set()
unique = []
for acc, p, t, label, tgt in candidates:
    key = (p, t, tgt)
    if key not in seen:
        seen.add(key)
        unique.append((acc, p, t, label, tgt))

print(f'Top candidates next goal HOME/AWAY >= 75%:')
print()
for acc, p, t, label, tgt in unique[:70]:
    print(f'  {p}/{t}={acc*100:.0f}%  next={tgt}  {label}')
