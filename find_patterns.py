import csv, re, sys
from collections import Counter
sys.stdout.reconfigure(encoding='utf-8')

rows = list(csv.DictReader(open('goal_log.csv', encoding='utf-8')))
matches = []
for row in rows:
    goals_str = row['goals'].strip()
    if not goals_str: continue
    lg = '20min' if '20 Mins' in row['league'] else ('16min' if '16 Mins' in row['league'] else '15min')
    entries = [g.strip() for g in goals_str.split('|') if g.strip()]
    goals_1h, goals_2h = [], []
    for entry in entries:
        m = re.match(r"(1H|2H)\s+(\d+)'\s*\((\d+)-(\d+)\)", entry, re.I)
        if m:
            half = m.group(1).upper()
            mn, h, a = int(m.group(2)), int(m.group(3)), int(m.group(4))
            if half == '1H': goals_1h.append({'min': mn, 'h': h, 'a': a})
            else: goals_2h.append({'min': mn, 'h': h, 'a': a})
    if not goals_1h: continue
    ht_h = goals_1h[-1]['h']; ht_a = goals_1h[-1]['a']
    has_2h = len(goals_2h) > 0
    scorers = []
    ph = pa = 0
    for g in goals_1h:
        if g['h'] > ph: scorers.append('H')
        if g['a'] > pa: scorers.append('A')
        ph, pa = g['h'], g['a']
    gaps = [goals_1h[i]['min'] - goals_1h[i-1]['min'] for i in range(1, len(goals_1h))]
    max_gap = max(gaps) if gaps else 0
    min_gap = min(gaps) if gaps else 99
    fm = goals_1h[0]['min']; lm = goals_1h[-1]['min']
    span = lm - fm
    switches = sum(1 for i in range(1, len(scorers)) if scorers[i] != scorers[i-1])
    total_1h = ht_h + ht_a
    matches.append({
        'lg': lg, 'home': row['home_team'].strip(), 'away': row['away_team'].strip(),
        'dt': row['datetime'], 'ht_h': ht_h, 'ht_a': ht_a, 'has_2h': has_2h,
        'scorers': scorers, 'sc_str': ''.join(scorers),
        'gaps': gaps, 'max_gap': max_gap, 'min_gap': min_gap,
        'n1h': len(goals_1h), 'fm': fm, 'lm': lm, 'span': span,
        'switches': switches, 'total_1h': total_1h,
        'selisih': abs(ht_h - ht_a),
    })

total = len(matches)
has_2h_total = sum(1 for m in matches if m['has_2h'])
print(f'Matches: {total}, has 2H: {has_2h_total} ({has_2h_total/total*100:.0f}%)')
print()

candidates = []

def test(label, grp):
    t = len(grp)
    if t < 10: return
    p = sum(1 for x in grp if x['has_2h'])
    if t >= 15 and p/t >= 0.90:
        candidates.append((p/t, p, t, label))
    elif t >= 12 and p/t >= 0.92:
        candidates.append((p/t, p, t, label + ' [small]'))

# HT score
for h in range(0,5):
    for a in range(0,5):
        for lg in [None,'15min','16min','20min']:
            grp = [m for m in matches if m['ht_h']==h and m['ht_a']==a and (lg is None or m['lg']==lg)]
            test('HT=%d-%d%s'%(h,a,(' '+lg if lg else '')), grp)

# n1h patterns
for n in range(1,7):
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['n1h']==n and (lg is None or m['lg']==lg)]
        test('n1h=%d%s'%(n,(' '+lg if lg else '')), grp)
        for mg in [2,3]:
            g2 = [m for m in grp if m['min_gap']>=mg]
            test('n1h=%d+min_gap>=%d%s'%(n,mg,(' '+lg if lg else '')), g2)
        for sp in [3,4,5,6]:
            g2 = [m for m in grp if m['span']>=sp]
            test('n1h=%d+span>=%d%s'%(n,sp,(' '+lg if lg else '')), g2)
        for mg in [3,4,5]:
            g2 = [m for m in grp if m['max_gap']>=mg]
            test('n1h=%d+max_gap>=%d%s'%(n,mg,(' '+lg if lg else '')), g2)

# lm patterns
for lm in range(0,11):
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['lm']==lm and (lg is None or m['lg']==lg)]
        test('lm=%d%s'%(lm,(' '+lg if lg else '')), grp)
        for last in ['H','A']:
            g2 = [m for m in grp if m['scorers'] and m['scorers'][-1]==last]
            test('lm=%d+last=%s%s'%(lm,last,(' '+lg if lg else '')), g2)
        for n in [1,2]:
            g2 = [m for m in grp if m['n1h']==n]
            test('lm=%d+n=%d%s'%(lm,n,(' '+lg if lg else '')), g2)
        for mg in [2,3]:
            g2 = [m for m in grp if m['n1h']>=2 and m['min_gap']>=mg]
            test('lm=%d+min_gap>=%d%s'%(lm,mg,(' '+lg if lg else '')), g2)

# min_gap
for mg in [2,3,4]:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['min_gap']>=mg and m['n1h']>=2 and (lg is None or m['lg']==lg)]
        test('min_gap>=%d%s'%(mg,(' '+lg if lg else '')), grp)
        for n in [3,4]:
            g2 = [m for m in grp if m['n1h']>=n]
            test('min_gap>=%d+n>=%d%s'%(mg,n,(' '+lg if lg else '')), g2)
        for sp in [3,4,5,6]:
            g2 = [m for m in grp if m['span']>=sp]
            test('min_gap>=%d+span>=%d%s'%(mg,sp,(' '+lg if lg else '')), g2)

# span
for sp in [4,5,6,7,8]:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['span']>=sp and (lg is None or m['lg']==lg)]
        test('span>=%d%s'%(sp,(' '+lg if lg else '')), grp)
        for n in [2,3]:
            g2 = [m for m in grp if m['n1h']>=n]
            test('span>=%d+n>=%d%s'%(sp,n,(' '+lg if lg else '')), g2)
        for mg in [2,3]:
            g2 = [m for m in grp if m['n1h']>=2 and m['min_gap']>=mg]
            test('span>=%d+min_gap>=%d%s'%(sp,mg,(' '+lg if lg else '')), g2)

# fm+lm
for fm in range(0,4):
    for lm in range(5,11):
        for lg in [None,'15min','16min','20min']:
            grp = [m for m in matches if m['fm']==fm and m['lm']==lm and (lg is None or m['lg']==lg)]
            test('fm=%d+lm=%d%s'%(fm,lm,(' '+lg if lg else '')), grp)
            for mg in [2,3]:
                g2 = [m for m in grp if m['n1h']>=2 and m['min_gap']>=mg]
                test('fm=%d+lm=%d+min_gap>=%d%s'%(fm,lm,mg,(' '+lg if lg else '')), g2)

# ganjil/genap
for parity in [0,1]:
    pname = 'genap' if parity==0 else 'ganjil'
    for lg in ['15min','16min','20min']:
        for lm_min in [0,3,4,5,6]:
            grp = [m for m in matches if m['total_1h']%2==parity and m['lg']==lg and m['lm']>=lm_min]
            test('total_%s+lm>=%d %s'%(pname,lm_min,lg), grp)
        for n in [2,3]:
            grp = [m for m in matches if m['total_1h']%2==parity and m['lg']==lg and m['n1h']>=n]
            test('total_%s+n>=%d %s'%(pname,n,lg), grp)

# switches
for sw in [1,2,3]:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['switches']>=sw and (lg is None or m['lg']==lg)]
        test('switches>=%d%s'%(sw,(' '+lg if lg else '')), grp)
        for mg in [2,3]:
            g2 = [m for m in grp if m['min_gap']>=mg]
            test('switches>=%d+min_gap>=%d%s'%(sw,mg,(' '+lg if lg else '')), g2)
        for lm_min in [5,6,7]:
            g2 = [m for m in grp if m['lm']>=lm_min]
            test('switches>=%d+lm>=%d%s'%(sw,lm_min,(' '+lg if lg else '')), g2)

# scorer sequence
for sc_str in ['A','H','AA','HH','AH','HA','AAA','HHH','AHA','HAH','AHAA','HAHH','AHH','HAA']:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['sc_str']==sc_str and (lg is None or m['lg']==lg)]
        test('sc=%s%s'%(sc_str,(' '+lg if lg else '')), grp)

# first+last scorer
for first in ['H','A']:
    for last in ['H','A']:
        for lg in [None,'15min','16min','20min']:
            grp = [m for m in matches if m['scorers'] and m['scorers'][0]==first and m['scorers'][-1]==last and (lg is None or m['lg']==lg)]
            test('first=%s+last=%s%s'%(first,last,(' '+lg if lg else '')), grp)
            for mg in [2,3]:
                g2 = [m for m in grp if m['n1h']>=2 and m['min_gap']>=mg]
                test('first=%s+last=%s+min_gap>=%d%s'%(first,last,mg,(' '+lg if lg else '')), g2)
            for sp in [4,5,6]:
                g2 = [m for m in grp if m['span']>=sp]
                test('first=%s+last=%s+span>=%d%s'%(first,last,sp,(' '+lg if lg else '')), g2)

# total_1h >= N
for n in [2,3,4,5]:
    for lg in [None,'15min','16min','20min']:
        grp = [m for m in matches if m['total_1h']>=n and (lg is None or m['lg']==lg)]
        test('total_1h>=%d%s'%(n,(' '+lg if lg else '')), grp)
        for mg in [2,3]:
            g2 = [m for m in grp if m['min_gap']>=mg]
            test('total_1h>=%d+min_gap>=%d%s'%(n,mg,(' '+lg if lg else '')), g2)
        for sp in [4,5,6]:
            g2 = [m for m in grp if m['span']>=sp]
            test('total_1h>=%d+span>=%d%s'%(n,sp,(' '+lg if lg else '')), g2)

# home leading HT
for lg in [None,'15min','16min','20min']:
    grp = [m for m in matches if m['ht_h']>m['ht_a'] and (lg is None or m['lg']==lg)]
    test('home_win_ht%s'%(' '+lg if lg else ''), grp)
    for mg in [2,3]:
        g2 = [m for m in grp if m['n1h']>=2 and m['min_gap']>=mg]
        test('home_win_ht+min_gap>=%d%s'%(mg,' '+lg if lg else ''), g2)

candidates.sort(reverse=True)
seen = set()
unique = []
for acc, p, t, label in candidates:
    key = (p, t)
    if key not in seen:
        seen.add(key)
        unique.append((acc, p, t, label))

print(f'Top unique candidates (>=90% acc): {len(unique)}')
print()
for acc, p, t, label in unique[:60]:
    print(f'  {p}/{t}={acc*100:.0f}%  {label}')
