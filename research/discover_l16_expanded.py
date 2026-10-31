"""Retrospective pattern exploration. Does not modify active application rules."""
import itertools, hashlib
from pathlib import Path
exec((Path(__file__).parent/'discover_l16.py').read_text(encoding='utf-8').split('results=[]; searched=0')[0])
F=collections.defaultdict(set)
previous={};samevenue={}
lookup={id(g):i for i,g in enumerate(targets)}
for t in sorted(slots):
    for g in slots[t]:
        i=lookup.get(id(g))
        if i is None:continue
        for side,label in [(0,'home'),(1,'away')]:
            name=g['teams'][side]
            for history,p in [('prev',previous.get(name)),('same venue',samevenue.get((name,side)))]:
                if not p or not dt.timedelta(0)<t-p['t']<=dt.timedelta(hours=3):continue
                ps=p['teams'].index(name)
                values={'FT':p['ft'],'HT':p['ht'],'2H':p['h2'],'own HT':p['fh'][ps],'own 2H':p['final'][ps]-p['fh'][ps]}
                for metric,value in values.items():
                    thresholds=range(0,5) if metric=='FT' else range(0,4)
                    for n in thresholds:
                        for op in ['≥','≤']:
                            if not (value>=n if op=='≥' else value<=n):continue
                            key=f'{label} {history} {metric} {op}{n}'
                            F[('past2h',key)].add(i)
                            if t-p['t']>=dt.timedelta(minutes=16):F[('pastht',key)].add(i)
        for n in range(5):
            if g['ht']==n:F[('current',f'HT tepat {n}')].add(i)
        for label,ok in [('home unggul HT',g['fh'][0]>g['fh'][1]),('away unggul HT',g['fh'][0]<g['fh'][1]),('HT kedua tim cetak',min(g['fh'])>0)]:
            if ok:F[('current',label)].add(i)
    for g in slots[t]:
        for side,name in enumerate(g['teams']):previous[name]=g;samevenue[(name,side)]=g
for (k,l),ids in features.items():
    if k in ['current','slot','slot_ht']:F[(k,l)]=ids

selected=[];searched=0;eligible=0
teams=[(label,ids) for (k,label),ids in features.items() if k=='team']
for metric,line in itertools.product(['ht','h2'],[.5,1.5,2.5]):
    market=('HT' if metric=='ht' else '2H')+f' Over {line}'
    winning={i for i,g in enumerate(targets) if g[metric]>line}
    fs=[(label,ids,k) for (k,label),ids in F.items() if k in (['pastht','slot_ht'] if metric=='ht' else ['past2h','slot','current'])]
    candidates=[];seen=set()
    for teamlabel,teamids in teams:
        usable=[]
        for label,ids,k in fs:
            pool=teamids&ids;tr=pool&train
            if len(tr)>=15:usable.append((label,pool,k))
        combos=[(label,pool) for label,pool,k in usable]
        # Two independent information groups; avoid stacking thresholds of the same variable.
        for a,b in itertools.combinations(usable,2):
            if a[2]==b[2]:continue
            combos.append((a[0]+' + '+b[0],a[1]&b[1]))
        for condition,pool in combos:
            searched+=1;tr=pool&train;n=len(tr)
            if n<15:continue
            sig=tuple(sorted(pool))
            if sig in seen:continue
            seen.add(sig);w=len(tr&winning)
            if w/n<.85 or len({targets[i]['day'] for i in tr})<4:continue
            eligible+=1
            st=stats(tr,metric,line)
            candidates.append(dict(rule=teamlabel+' + '+condition,train=st,score=lower(st),pool=pool,team=teamlabel))
    candidates.sort(key=lambda x:(x['score'],x['train']['total']),reverse=True)
    # Up to two per team per market selected on training only. Test results remain exploratory.
    perteam=collections.Counter()
    for c in candidates:
        name=c['team'].rsplit(' ',1)[0]
        if perteam[name]>=2:continue
        perteam[name]+=1;pool=c.pop('pool');c.pop('score');c.pop('team')
        c.update(market=market,test=stats(pool&test,metric,line),overall=stats(pool,metric,line),baseline_test=stats(test,metric,line))
        c['ids']=sorted(pool)
        selected.append(c)
passing=[c for c in selected if c['test']['total']>=8 and c['test']['accuracy']>=.8 and c['overall']['total']>=25 and c['overall']['accuracy']>=.8]
passing.sort(key=lambda c:(c['market'],-c['test']['total'],-c['test']['accuracy']))
output=dict(start=START,end=END,split=SPLIT,matches=len(targets),searched=searched,eligible_train=eligible,shortlist=len(selected),passing=passing,all_shortlisted=selected,csv_sha256=hashlib.sha256((ROOT/'matches.csv').read_bytes()).hexdigest())
(ROOT/'research/pola_baru_l16_expanded.json').write_text(json.dumps(output,ensure_ascii=False,indent=2),encoding='utf-8')
md='# Eksplorasi diperluas pola League 16\n\nRentang sesi 26 September–16 Oktober 2026; pemilihan sampai 11 Oktober, pemeriksaan 12–16 Oktober. '+str(len(targets))+' laga. '+str(searched)+' kombinasi ditelusuri, '+str(len(selected))+' kandidat dipilih berdasarkan data latih; '+str(len(passing))+' memiliki akurasi uji dan keseluruhan ≥80%, minimal 8 sampel uji dan 25 keseluruhan.\n\nIni eksplorasi retrospektif lanjutan pada data uji yang sudah pernah dilihat. Angka bukan estimasi tak bias, dan bukan validasi baru. Banyak percobaan serta pola saling tumpang tindih meningkatkan risiko overfit. Perlu data masa depan. Tidak ada odds atau timestamp kapan hasil benar-benar tersedia. Durasi pertandingan diasumsikan 16 menit. Market HT hanya memakai hasil laga yang sudah selesai sebelum kickoff; market 2H dapat memakai HT target.\n\nHome/away berarti tim kandang/tandang pada pertandingan target. Prev adalah laga terakhir tim itu, same venue adalah laga terakhir pada posisi kandang/tandang yang sama, maksimal 3 jam. Own HT/2H berarti gol tim itu sendiri. Slot sebelumnya: dua laga tepat 15 menit sebelumnya untuk 2H; untuk prediksi HT memakai slot 30 menit sebelumnya. Semua kondisi HT target dievaluasi saat jeda, bukan sebelum kickoff.\n\n| Market | Kandidat | Latih W/L | Uji W/L | Akurasi uji | Semua W/L | Akurasi semua |\n|---|---|---:|---:|---:|---:|---:|\n'
for c in passing:
    a,b,d=c['train'],c['test'],c['overall'];md+=f"| {c['market']} | {c['rule']} | {a['win']}/{a['fail']} | {b['win']}/{b['fail']} | {b['accuracy']*100:.1f}% | {d['win']}/{d['fail']} | {d['accuracy']*100:.1f}% |\n"
(ROOT/'research/pola_baru_l16_expanded.md').write_text(md,encoding='utf-8')
with (ROOT/'research/pola_baru_l16_expanded_matches.csv').open('w',encoding='utf-8-sig',newline='') as f:
    writer=csv.writer(f);writer.writerow(['candidate','market','rule','session','time','teams','HT','2H','split','win'])
    for n,c in enumerate(passing,1):
        metric='ht' if c['market'].startswith('HT') else 'h2';line=float(c['market'].split()[-1])
        for i in c['ids']:
            g=targets[i];writer.writerow([n,c['market'],c['rule'],g['day'],str(g['t']),' vs '.join(g['teams']),g['ht'],g['h2'],'train' if i in train else 'test',g[metric]>line])
print(json.dumps({k:v for k,v in output.items() if k not in ['passing','all_shortlisted']}))
for c in passing:print(json.dumps({k:v for k,v in c.items() if k!='ids'},ensure_ascii=True))
