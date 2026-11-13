import csv,re,collections,datetime,json,itertools
from pathlib import Path
root=Path(__file__).resolve().parent.parent
rows=[];seen=set()
for r in csv.DictReader((root/'matches.csv').open(encoding='utf-8-sig',newline='')):
    if r['league']!='SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play':continue
    try:
        fh=[int(r['fh_home']),int(r['fh_away'])];ft=[int(r['ft_home']),int(r['ft_away'])]
        if any(a>b for a,b in zip(fh,ft)):continue
    except (ValueError,TypeError):continue
    names=[re.sub(r'\s*\(V\)','',r[k],flags=re.I).strip() for k in ['home_team','away_team']]
    t=datetime.datetime.fromisoformat(r['match_time']).timestamp();key=(t,*names)
    if key in seen:continue
    seen.add(key);rows.append(dict(t=t,time=r['match_time'],teams=names,fh=fh,ft=ft))
slots=collections.defaultdict(list)
for r in rows:slots[r['t']].append(r)
history={};events=[]
for t,group in sorted(slots.items()):
    for r in group:
        if 'Liverpool' not in r['teams']:continue
        features={};side=r['teams'].index('Liverpool')
        for label,index in [('Liverpool',side),('Lawan',1-side)]:
            previous=history.get(r['teams'][index])
            if not previous or not 1800<=t-previous['t']<=10800:continue
            own=previous['teams'].index(r['teams'][index]);ht=sum(previous['fh']);ft=sum(previous['ft'])
            values={'total HT':ht,'total FT':ft,'total 2H':ft-ht,'gol pribadi FT':previous['ft'][own]}
            for metric,value in values.items():
                for limit in range(0,9):
                    features[f'{label} previous {metric} <= {limit}']=value<=limit
                    features[f'{label} previous {metric} >= {limit}']=value>=limit
        events.append(dict(time=r['time'],win=sum(r['ft'])>=2,features=features))
    for r in group:
        for team in r['teams']:history[team]=r
split='2026-10-12';end='2026-10-19'
trainmask=testmask=winmask=0;masks=collections.defaultdict(int)
for i,e in enumerate(events):
    bit=1<<i
    if e['time']<split:trainmask|=bit
    elif e['time']<end:testmask|=bit
    if e['win']:winmask|=bit
    for name,active in e['features'].items():
        if active:masks[name]|=bit
def stats(mask):
    n=mask.bit_count();w=(mask&winmask).bit_count()
    return dict(win=w,fail=n-w,total=n,pct=round(100*w/n,2) if n else None)
base=stats(trainmask);eligible=[(name,mask) for name,mask in masks.items() if (mask&trainmask).bit_count()>=base['total']*.6]
candidates=[];signatures=set()
for items in itertools.chain(((a,) for a in eligible),itertools.combinations(eligible,2)):
    mask=items[0][1]
    if len(items)==2:mask&=items[1][1]
    tr=mask&trainmask
    if tr in signatures:continue
    signatures.add(tr);s=stats(tr)
    if s['total']<base['total']*.6 or s['pct']<=base['pct']:continue
    candidates.append(dict(conditions=[a[0] for a in items],train=s,test=stats(mask&testmask),overall=stats(mask)))
candidates.sort(key=lambda c:(-c['train']['pct'],-c['train']['total'],len(c['conditions'])))
report=dict(split=split,test_end_exclusive=end,retention_min=.6,baseline=stats((1<<len(events))-1),train=base,test=stats(testmask),note='Filters ranked by older training data only. Earlier research reused recent data. Historical source times assumed consistent within league. No production changes.',candidates=candidates[:8])
(root/'research/liverpool_broad_filters.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
for c in report['candidates']:
    for s in [c['train'],c['test'],c['overall']]:assert s['win']+s['fail']==s['total']
print(json.dumps(report))
