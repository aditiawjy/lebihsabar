import csv, datetime as dt, collections, math, json, re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
START, SPLIT, END = '2026-09-26', '2026-10-12', '2026-10-16'
LEAGUE = 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play'
def team(s):
    return re.sub(r'\s*\(v\)$', '', s.lower().strip()).removeprefix('e-')
games, slots = [], collections.defaultdict(list)
with (ROOT / 'matches.csv').open(encoding='utf-8-sig', newline='') as f:
    for row in csv.DictReader(f):
        if row['league'] != LEAGUE: continue
        t = dt.datetime.fromisoformat(row['match_time'])
        try:
            fh = [int(row['fh_home']), int(row['fh_away'])]
            ft = [int(row['ft_home']), int(row['ft_away'])]
        except ValueError: continue
        if any(b < a for a, b in zip(fh, ft)): continue
        g = dict(t=t, teams=[team(row['home_team']), team(row['away_team'])], ht=sum(fh), ft=sum(ft), h2=sum(ft)-sum(fh), fh=fh, final=ft)
        g['day'] = (t-dt.timedelta(hours=12)).date().isoformat()
        games.append(g); slots[t].append(g)
games.sort(key=lambda x:x['t'])
previous = {}
features = collections.defaultdict(set)
targets = []
for t in sorted(slots):
    group = slots[t]
    for g in group:
        if START <= g['day'] <= END:
            i=len(targets); targets.append(g)
            for side, name in enumerate(g['teams']):
                for venue in ['semua', 'kandang' if side==0 else 'tandang']:
                    features[('team', name+' '+venue)].add(i)
                p = previous.get(name)
                if p and dt.timedelta(0) < t-p['t'] <= dt.timedelta(hours=3):
                    for metric, label, thresholds in [('ft','FT',[1,3,4]),('ht','HT',[0,1,2]),('h2','2H',[0,1,2])]:
                        for n in thresholds:
                            for op in ['≥','≤']:
                                if (p[metric]>=n if op=='≥' else p[metric]<=n): 
                                    key=f'laga {name} sebelumnya {label} {op}{n}'
                                    features[('prev',key)].add(i)
                                    if t-p['t'] >= dt.timedelta(minutes=16): features[('prev_ht',key)].add(i)
            ps=slots.get(t-dt.timedelta(minutes=15), [])
            if len(ps)==2:
                for metric,label,thresholds in [('ft','FT',[4,5,6]),('ht','HT',[1,2,3]),('h2','2H',[2,3,4])]:
                    for n in thresholds:
                        if sum(p[metric] for p in ps)>=n:features[('slot',f'slot sebelumnya total {label} ≥{n}')].add(i)
            older=slots.get(t-dt.timedelta(minutes=30), [])
            if len(older)==2:
                for metric,label,thresholds in [('ft','FT',[4,5,6]),('ht','HT',[1,2,3]),('h2','2H',[2,3,4])]:
                    for n in thresholds:
                        if sum(p[metric] for p in older)>=n: features[('slot_ht',f'slot 30 menit sebelumnya total {label} ≥{n}')].add(i)
            for label, ok in [('HT 0–1',g['ht']<=1),('HT 1–2',1<=g['ht']<=2),('HT 2–3',2<=g['ht']<=3),('HT imbang',g['fh'][0]==g['fh'][1]),('HT tidak imbang',g['fh'][0]!=g['fh'][1])]:
                if ok:features[('current',label)].add(i)
    for g in group:
        for name in g['teams']:previous[name]=g
train={i for i,g in enumerate(targets) if g['day']<SPLIT}
test=set(range(len(targets)))-train
def stats(ids,metric,line):
    w=sum(targets[i][metric]>line for i in ids)
    n=len(ids)
    return dict(win=w,fail=n-w,total=n,accuracy=w/n if n else None,sessions=len({targets[i]['day'] for i in ids}))
def lower(s):
    n=s['total'];p=s['accuracy'];z=1.96
    return (p+z*z/(2*n)-z*math.sqrt(p*(1-p)/n+z*z/(4*n*n)))/(1+z*z/n)
results=[]; searched=0
for metric in ['ht','h2']:
    for line in [.5,1.5,2.5]:
        market=('HT' if metric=='ht' else '2H')+f' Over {line}'
        base=stats(train,metric,line); candidates=[];seen=set()
        for (kind,label),ids in features.items():
            if kind!='team':continue
            name=label.rsplit(' ',1)[0]
            for (kind2,label2),ids2 in features.items():
                if kind2 not in (['prev_ht','slot_ht'] if metric=='ht' else ['prev','slot']):continue
                if kind2 in ['prev','prev_ht'] and not label2.startswith('laga '+name+' sebelumnya'):continue
                combined=ids&ids2
                variations=[('',combined)]
                if metric=='h2':variations += [(' + '+l,combined&ix) for (k,l),ix in features.items() if k=='current']
                for suffix,pool in variations:
                    searched+=1;tr=pool&train
                    sig=tuple(sorted(tr))
                    if sig in seen:continue
                    seen.add(sig)
                    st=stats(tr,metric,line)
                    if st['total']<20 or st['sessions']<4 or st['accuracy']<base['accuracy']+.04:continue
                    candidates.append(dict(rule=label+' + '+label2+suffix,train=st,score=lower(st),pool=pool))
        candidates.sort(key=lambda x:(x['score'],x['train']['total']),reverse=True)
        # Freeze shortlist using training scores only; do not select again on test results.
        for candidate in candidates[:3]:
            pool=candidate.pop('pool');candidate.pop('score')
            candidate.update(market=market,test=stats(pool&test,metric,line),overall=stats(pool,metric,line),baseline_train=base,baseline_test=stats(test,metric,line))
            candidate['matches']=[dict(time=str(targets[i]['t']),teams=' vs '.join(targets[i]['teams']),ht=targets[i]['ht'],h2=targets[i]['h2'],split='latih' if i in train else 'uji',win=targets[i][metric]>line) for i in sorted(pool)]
            results.append(candidate)
output=dict(start=START,end=END,split=SPLIT,matches=len(targets),searched=searched,results=results)
(ROOT/'research/pola_baru_l16.json').write_text(json.dumps(output,ensure_ascii=False,indent=2),encoding='utf-8')
md='# Kandidat pola baru League 16\n\nSesi 26 September–16 Oktober 2026 (UTC+8, cutoff 12:00). Pemilihan: sampai 11 Oktober; uji: 12–16 Oktober. Cakupan mengikuti snapshot CSV saat skrip dijalankan. Riwayat tim dibatasi 3 jam, untuk pasar 2H slot sebelumnya tepat 15 menit dan dua laga lengkap. Untuk pasar HT, hasil tim sebelumnya berjarak minimal 16 menit dan slot rujukan 30 menit sebelum target agar hasil sudah selesai saat kickoff (asumsi durasi 16 menit).\n\nPencarian '+str(searched)+' kombinasi. Shortlist dibekukan dari data latih (minimal 20 sampel, 4 sesi, akurasi melebihi baseline market ≥4 poin), maksimal 3 per market, sebelum melihat data uji. Banyak percobaan meningkatkan risiko overfit. Karena data uji pernah dianalisis sebelumnya dalam sesi kerja ini, ini holdout retrospektif, bukan validasi independen/prospektif. Data tidak mencatat kapan skor tersedia; tidak menjamin sinyal dapat diambil tepat waktu. Tidak ada odds: akurasi bukan ukuran profit. HT pasar babak pertama tidak memakai HT laga target. Kandidat 2H memakai HT, sehingga baru tersedia saat jeda. Pola HT/2H Over 1.5 atau 2.5 hanya menang bila total babak tersebut minimal 2 atau 3 gol; bukan total FT.\n\n| Market | Kandidat | Latih W/L | Uji W/L | Akurasi uji | Semua W/L |\n|---|---|---:|---:|---:|---:|\n'
for r in results:
    a,b,c=r['train'],r['test'],r['overall'];acc='—' if b['accuracy'] is None else f"{b['accuracy']*100:.1f}%"
    md+=f"| {r['market']} | {r['rule']} | {a['win']}/{a['fail']} | {b['win']}/{b['fail']} | {acc} | {c['win']}/{c['fail']} |\n"
(ROOT/'research/pola_baru_l16.md').write_text(md,encoding='utf-8')
for r in results:
    assert r['overall']['total']==r['train']['total']+r['test']['total']
    print(json.dumps({k:v for k,v in r.items() if k!='matches'},ensure_ascii=True))
