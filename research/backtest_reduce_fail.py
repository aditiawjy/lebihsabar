import json,datetime as dt,re,collections,itertools,math,csv,hashlib
from pathlib import Path
root=Path(__file__).resolve().parent
source=json.loads((root/'fail_reduction_source.json').read_text(encoding='utf-8'))
now=dt.datetime.fromisoformat(source['generatedAt'].replace('Z','+00:00'))
end=(now+dt.timedelta(hours=8-12)).date();start=end-dt.timedelta(days=29);split=end-dt.timedelta(days=6)
rows=source['rows'];byid={r['id']:r for r in rows};slots=collections.defaultdict(list)
for r in rows:
 r['teams']=[re.sub(r'\s*\(v\)$','',x.lower().strip()).removeprefix('e-') for x in r['teams']]
 r['day']=(dt.datetime.fromtimestamp(r['t']/1000,dt.timezone.utc)+dt.timedelta(hours=8-12)).date()
 if r['complete'] and r['fh'] and r['ft'] and all(b>=a for a,b in zip(r['fh'],r['ft'])):slots[r['t']].append(r)
history=sorted([r for slot in slots.values() for r in slot],key=lambda r:r['t'])
def stats(es):
 n=len(es);w=sum(e['win'] for e in es);return dict(win=w,fail=n-w,total=n,accuracy=round(w/n*100,2) if n else None)
def lower(es):
 n=len(es);p=sum(e['win'] for e in es)/n;z=1.96
 return (p+z*z/(2*n)-z*math.sqrt(p*(1-p)/n+z*z/(4*n*n)))/(1+z*z/n)
reports=[];trials=0
for code in ['L16-51','L16-01','L16-30']:
 events=[dict(e,row=byid[e['id']]) for e in source['events'] if e['code']==code and start<=byid[e['id']]['day']<=end]
 train={i for i,e in enumerate(events) if e['row']['day']<split};test=set(range(len(events)))-train
 features=collections.defaultdict(set)
 for i,e in enumerate(events):
  r=e['row'];atHT=code=='L16-30'
  for side,role in [(0,'home'),(1,'away')]:
   name=r['teams'][side]
   for same in [False,True]:
    pool=[p for p in history if p['t']<r['t'] and name in p['teams'] and (not same or p['teams'][side]==name)]
    if not pool:continue
    p=pool[-1];gap=r['t']-p['t']
    if gap>3*3600000 or (not atHT and gap<16*60000):continue
    ps=p['teams'].index(name)
    vals={'HT':sum(p['fh']),'FT':sum(p['ft']),'2H':sum(p['ft'])-sum(p['fh']),'own HT':p['fh'][ps],'own 2H':p['ft'][ps]-p['fh'][ps]}
    for metric,value in vals.items():
     for threshold in range(0,5 if metric=='FT' else 4):
      for op in ['>=','<=']:
       if value>=threshold if op=='>=' else value<=threshold:features[(f"{role} {'same venue' if same else 'prev'} {metric} {op}{threshold}",'pre')].add(i)
  prev=slots.get(r['t']-(15 if atHT else 30)*60000,[])
  if len(prev)==2:
   for metric in ['HT','FT','2H']:
    val=sum(sum(p['fh']) if metric=='HT' else sum(p['ft']) if metric=='FT' else sum(p['ft'])-sum(p['fh']) for p in prev)
    for threshold in range(1,7):
     for op in ['>=','<=']:
      if val>=threshold if op=='>=' else val<=threshold:features[(f'slot {-15 if atHT else -30}m {metric} {op}{threshold}','pre')].add(i)
  source_time=dt.datetime.fromtimestamp(r['t']/1000,dt.timezone.utc)+dt.timedelta(hours=8)
  features[(f'menit slot :{source_time.minute:02d}','pre')].add(i)
  if 'norway' in r['teams']:features[('Norway '+('kandang' if r['teams'][0]=='norway' else 'tandang'),'pre')].add(i)
  if code=='L16-30':
   a,b=r['fh']
   for label,ok in [('HT 0-3/3-0',min(a,b)==0),('HT 1-2/2-1',min(a,b)==1),('home unggul HT',a>b),('away unggul HT',a<b)]:
    if ok:features[(label,'HT')].add(i)
  if code=='L16-01':
   # Exclude HT>=2: FT Over1.5 is already settled, so it cannot be a predictive filter.
   ht=sum(r['fh'])
   for label,ok in [('HT total 0',ht==0),('HT total 1',ht==1),('HT total <=1',ht<=1)]:
    if ok:features[(label,'HT')].add(i)
 candidates=[];seen=set();features=list(features.items())
 combinations=[([label],stage,ids) for (label,stage),ids in features]
 combinations += [([a[0][0],b[0][0]],'HT' if 'HT' in [a[0][1],b[0][1]] else 'pre',a[1]&b[1]) for a,b in itertools.combinations(features,2) if a[0][0].split(' ')[0]!=b[0][0].split(' ')[0]]
 for labels,stage,pool in combinations:
  trials+=1;tr=pool&train
  if len(tr)<20 or len(tr)<len(train)*.5 or len(tr)==len(train):continue
  sig=tuple(sorted(pool))
  if sig in seen:continue
  seen.add(sig);tr_events=[events[i] for i in tr];base=[events[i] for i in train]
  if stats(tr_events)['accuracy']<=stats(base)['accuracy']:continue
  candidates.append(dict(filter=' + '.join(labels),entry=('HT' if code=='L16-30' or stage=='HT' else 'pre'),pool=pool,score=lower(tr_events),train=stats(tr_events)))
 candidates.sort(key=lambda c:(c['score'],c['train']['total']),reverse=True)
 picks=[]
 for c in candidates[:3]:
  pool=c.pop('pool');c.pop('score');c['test']=stats([events[i] for i in pool&test]);c['overall']=stats([events[i] for i in pool]);c['removed']=stats([events[i] for i in set(range(len(events)))-pool]);c['ids']=[events[i]['id'] for i in sorted(pool)];picks.append(c)
 reports.append(dict(code=code,base=stats(events),train=stats([events[i] for i in train]),test=stats([events[i] for i in test]),candidates=picks))
out=dict(start=str(start),split=str(split),end=str(end),trials=trials,csv_sha256=hashlib.sha256((root.parent/'matches.csv').read_bytes()).hexdigest(),reports=reports)
(root/'reduce_fail_51_01_30.json').write_text(json.dumps(out,indent=2),encoding='utf-8')
md=f'# Backtest pengurangan FAIL L16-51, L16-01, L16-30\n\nRentang sesi {start}–{end}; pemilihan sebelum {split}, evaluasi akhir {split}–{end}. {trials} kombinasi; pilih maksimal 3 menurut batas bawah Wilson pada data awal, minimal 20 sampel latih dan 50% sinyal latih dipertahankan. Data akhir sudah pernah digunakan dalam riset lain, sehingga pemeriksaan ini eksploratif, bukan validasi prospektif. Penurunan FAIL karena membuang sinyal juga membuang WIN. Tanpa odds tidak dapat menentukan keuntungan. Filter HT untuk FT01 mengubah waktu masuk ke jeda; target yang sudah menang pada HT>=2 tidak digunakan. HT51 memakai hanya hasil yang tersedia sebelum kickoff.\n'
for r in reports:
 md+=f"\n## {r['code']}\n\nAsli sebulan: {r['base']}. Data akhir asli: {r['test']}.\n\n| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |\n|---|---|---:|---:|---:|---:|---:|---:|---:|\n"
 for c in r['candidates']:
  a,b,d=c['train'],c['test'],c['overall'];md+=f"| {c['filter']} | {c['entry']} | {a['win']}/{a['fail']} | {b['win']}/{b['fail']} | {b['accuracy']}% | {d['win']}/{d['fail']} | {d['accuracy']}% | {c['removed']['win']} | {c['removed']['fail']} |\n"
(root/'reduce_fail_51_01_30.md').write_text(md,encoding='utf-8')
print(json.dumps({**out,'reports':[{**r,'candidates':[{k:v for k,v in c.items() if k!='ids'} for c in r['candidates']]} for r in reports]}))
