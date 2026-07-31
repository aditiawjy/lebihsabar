# Pencari pattern: grid search kombinasi kondisi, validasi out-of-sample.
# Train = < 29/07/2026 (in-sample lama), Test = >= 29/07/2026.
import csv, re, math, itertools
from datetime import datetime

LOCK = datetime(2026, 7, 29)
FINISH_MIN = 15
now = datetime.now()

rows = []
with open('goal_log_vsoccer.csv', newline='', encoding='utf-8') as f:
    r = csv.DictReader(f)
    for row in r:
        try:
            fh, fa = int(row['final_home']), int(row['final_away'])
        except ValueError:
            continue
        dt = datetime.strptime(row['datetime'], '%d/%m/%Y %H:%M')
        if (now - dt).total_seconds() < FINISH_MIN * 60:
            continue
        snaps = re.findall(r"(1H|2H)\s+(\d+)'\s*\((\d+)-(\d+)\)", row['goals'])
        if not snaps:
            continue
        ko_parts = [p for p in row['ko_line'].split('/') if p.strip()]
        if not ko_parts:
            continue
        ko = sum(float(p) for p in ko_parts) / len(ko_parts)

        m1h, sides, htH, htA, g2h = [], [], 0, 0, 0
        for half, mn, h, a in snaps:
            if half == '1H':
                h, a = int(h), int(a)
                if h > htH: sides.append('H')
                elif a > htA: sides.append('A')
                htH, htA = h, a
                m1h.append(int(mn))
            else:
                g2h += 1
        g2h = max(g2h, (fh + fa) - (htH + htA))
        if not m1h:
            continue
        rows.append(dict(dt=dt, eval=dt >= LOCK, htH=htH, htA=htA,
                         tot=htH + htA, diff=abs(htH - htA),
                         g1=m1h[0], g2=m1h[1] if len(m1h) > 1 else None,
                         last1h=m1h[-1], ko=ko, sides=''.join(sides), g2h=g2h))

print(f"match terbaca: {len(rows)}")

def wilson(h, t):
    if t == 0: return (0, 0)
    z = 1.96; p = h / t; d = 1 + z*z/t
    c = (p + z*z/(2*t)) / d
    m = z*math.sqrt(p*(1-p)/t + z*z/(4*t*t)) / d
    return (max(0, c-m), min(1, c+m))

def report(name, pred, target):
    sel = [x for x in rows if pred(x)]
    if not sel: return None
    tr = [x for x in sel if not x['eval']]
    te = [x for x in sel if x['eval']]
    def acc(g): 
        h = sum(1 for x in g if x['g2h'] >= target); t = len(g)
        return h, t, (h/t if t else None)
    ah, at, ar = acc(sel); th, tt, trate = acc(tr); eh, et, erate = acc(te)
    # baseline target yg sama di semua match & di test set
    bte = [x for x in rows if x['eval']]
    bh = sum(1 for x in bte if x['g2h'] >= target)
    brate = bh/len(bte) if bte else 0
    lo, hi = wilson(eh, et)
    return dict(name=name, n=at, hits=ah, rate=ar, tr_n=tt, tr_h=th, tr_r=trate,
                te_n=et, te_h=eh, te_r=erate, ci_lo=lo, ci_hi=hi, base_te=brate,
                edge_te=(erate-brate) if erate is not None else None)

# baseline
for tgt in (2, 3):
    h = sum(1 for x in rows if x['g2h'] >= tgt)
    he = sum(1 for x in rows if x['eval'] and x['g2h'] >= tgt)
    te_n = sum(1 for x in rows if x['eval'])
    print(f"baseline 2H>={tgt}: all {h}/{len(rows)} ({h/len(rows):.1%}) | eval {he}/{te_n} ({he/te_n:.1%})")

# ---- grid search ----
G1 = [4,5,6,7,8,9,10,12,15,18,20]
KO_MIN = [4.75,5,5.5,5.75,6,6.25,6.5,6.75,7,7.25]
KO_MAX = [5.75,6,6.25,6.5,6.75,7,7.25,7.5,99]
TOT = [None,1,2,3,4,5,6,7]
DIFF = [None,0,1,2,3]
G2WIN = [None,(0,25),(5,25),(9,25),(9,30),(14,30)]
LAST1H = [None,25,30,34,35,38,42]
LAST1H_MAX = [None,40]

results = []
for target in (2,3):
    for tot,diff,g1m,kmin,kmax,g2w,l1min,l1max in itertools.product(
            TOT,DIFF,G1,KO_MIN,KO_MAX,G2WIN,LAST1H,LAST1H_MAX):
        if kmin > kmax: continue
        if tot is None and diff is None: continue
        def pred(x, tot=tot,diff=diff,g1m=g1m,kmin=kmin,kmax=kmax,g2w=g2w,l1min=l1min,l1max=l1max):
            if tot is not None and x['tot'] != tot: return False
            if diff is not None and x['diff'] != diff: return False
            if x['g1'] > g1m: return False
            if not (kmin <= x['ko'] <= kmax): return False
            if g2w is not None and (x['g2'] is None or not (g2w[0] <= x['g2'] <= g2w[1])): return False
            if l1min is not None and x['last1h'] < l1min: return False
            if l1max is not None and x['last1h'] > l1max: return False
            return True
        rep = report(f"tgt{target} tot={tot} diff={diff} g1<={g1m} ko={kmin}-{kmax} g2={g2w} last1h>={l1min} last1h<={l1max}", pred, target)
        if rep is None: continue
        # syarat: test sample >= 8, train >= 8, edge test > 0, CI bawah test > baseline test
        if rep['te_n'] < 8 or rep['tr_n'] < 8: continue
        if rep['te_r'] is None or rep['edge_te'] is None or rep['edge_te'] <= 0: continue
        results.append(rep)

# ranking: edge test, lalu CI bawah test vs baseline
results.sort(key=lambda r: (r['edge_te'], r['ci_lo']), reverse=True)
print("\n=== TOP 20 (urut edge pada data evaluasi >=29/07) ===")
print(f"{'pattern':70s} {'all':>10s} {'train':>12s} {'eval':>12s} {'CIlo':>6s} {'edge':>7s} {'base':>6s}")
for r in results[:20]:
    print(f"{r['name']:70s} {r['hits']:>3d}/{r['n']:<4d} {r['tr_h']:>3d}/{r['tr_n']:<3d} {r['tr_r'] or 0:5.1%} "
          f"{r['te_h']:>3d}/{r['te_n']:<3d} {r['te_r'] or 0:5.1%} {r['ci_lo']:5.1%} {r['edge_te']:+6.1%} {r['base_te']:5.1%}")

print("\n=== TOP 20 ketat (CI bawah eval > baseline eval) ===")
strict = [r for r in results if r['ci_lo'] > r['base_te']]
for r in strict[:20]:
    print(f"{r['name']:70s} {r['hits']:>3d}/{r['n']:<4d} {r['tr_h']:>3d}/{r['tr_n']:<3d} {r['tr_r'] or 0:5.1%} "
          f"{r['te_h']:>3d}/{r['te_n']:<3d} {r['te_r'] or 0:5.1%} {r['ci_lo']:5.1%} {r['edge_te']:+6.1%}")
print(f"total kandidat lolos filter: {len(results)}, ketat: {len(strict)}")
