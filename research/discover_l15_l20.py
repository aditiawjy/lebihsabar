"""Exploratory score-pattern mining; never edits production rules."""
import csv,re,collections,datetime,itertools,json,hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
LEAGUES={'15':'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play','20':'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play'}
split='2026-10-12';test_end='2026-10-19';reports={}
raw=list(csv.DictReader((ROOT/'matches.csv').open(encoding='utf-8-sig',newline='')))
def stats(mask,wins):
    n=mask.bit_count();w=(mask&wins).bit_count();return {'win':w,'fail':n-w,'total':n,'pct':round(100*w/n,2) if n else None}
for league,name in LEAGUES.items():
    rows=[];seen=set()
    for r in raw:
        if r['league']!=name:continue
        try:
            fh=[int(r['fh_home']),int(r['fh_away'])];ft=[int(r['ft_home']),int(r['ft_away'])]
            if any(a>b for a,b in zip(fh,ft)):continue
        except (ValueError,TypeError):continue
        teams=[re.sub(r'\s*\(V\)','',r[k],flags=re.I).strip() for k in ['home_team','away_team']]
        t=datetime.datetime.fromisoformat(r['match_time']).timestamp();key=(t,*teams)
        if key in seen:continue
        seen.add(key);rows.append(dict(time=r['match_time'],t=t,teams=teams,fh=fh,ft=ft))
    slots=collections.defaultdict(list)
    for r in rows:slots[r['t']].append(r)
    features=collections.defaultdict(int);bases=collections.defaultdict(int);wins=collections.defaultdict(int);train=test=0;history={}
    ordered=[]
    for t,group in sorted(slots.items()):
        for r in group:
            i=len(ordered);ordered.append(r);bit=1<<i
            if r['time']<split:train|=bit
            elif r['time']<test_end:test|=bit
            for side,team in enumerate(r['teams']):
                bases[(team,'any')]|=bit;bases[(team,'home' if side==0 else 'away')]|=bit
            for market,ok in [('FT O1.5',sum(r['ft'])>=2),('HT O0.5',sum(r['fh'])>=1),('2H O0.5',sum(r['ft'])-sum(r['fh'])>=1)]:
                if ok:wins[market]|=bit
            for side,label in [(0,'home'),(1,'away')]:
                p=history.get(r['teams'][side])
                if not p or not 1800<=t-p['t']<=10800:continue
                ps=p['teams'].index(r['teams'][side]);values={'FT':sum(p['ft']),'HT':sum(p['fh']),'2H':sum(p['ft'])-sum(p['fh']),'own HT':p['fh'][ps]}
                for metric,value in values.items():
                    for limit in [1,2,3]:
                        if value<=limit:features[f'{label} prev {metric} <= {limit}']|=bit
                        if value>=limit:features[f'{label} prev {metric} >= {limit}']|=bit
            a,b=r['fh']
            for condition,active in [('HT draw',a==b),('HT not draw',a!=b),('HT total <= 2',a+b<=2),('HT total >= 2',a+b>=2),('HT home ahead',a>b),('HT away ahead',b>a)]:
                if active:features[condition]|=bit
        for r in group:
            for team in r['teams']:history[team]=r
    candidates=[];trials=0
    for (team,venue),base in bases.items():
        if (base&train).bit_count()<100:continue
        for market,winmask in wins.items():
            names=[n for n,m in features.items() if (market=='2H O0.5' or not n.startswith('HT ')) and (base&m&train).bit_count()>=60]
            ranked=[];signatures=set()
            for conditions in itertools.chain(((n,) for n in names),itertools.combinations(names,2)):
                trials+=1;mask=base
                for condition in conditions:mask&=features[condition]
                tr=mask&train
                if tr in signatures:continue
                signatures.add(tr);s=stats(tr,winmask)
                if s['total']<60 or s['pct']<90:continue
                ranked.append((s['pct'],s['total'],conditions,mask,s))
            ranked.sort(key=lambda c:(-c[0],-c[1],len(c[2])))
            # Keep training-selected finalists; inspecting their test results is exploratory.
            for _,_,conditions,mask,s in ranked[:3]:
                ts=stats(mask&test,winmask)
                if ts['total']<12 or ts['pct']<85:continue
                candidates.append(dict(team=team,venue=venue,market=market,conditions=conditions,train=s,test=ts,overall=stats(mask,winmask),baseline=stats(base,winmask)))
    # Avoid near-identical variants of the same team/market in the shortlist.
    candidates.sort(key=lambda c:(-c['test']['pct'],-c['test']['total'],-c['train']['pct']))
    shortlist=[];keys=set()
    for c in candidates:
        key=(c['team'],c['market'])
        if key in keys:continue
        keys.add(key);shortlist.append(c)
    reports[league]={'rows':len(rows),'trials':trials,'eligible_finalists':len(candidates),'shortlist':shortlist[:12]}
    print(json.dumps({'league':league,**reports[league]}),flush=True)
report={'split':split,'test_end_exclusive':test_end,'minimum_train':60,'minimum_test':12,'min_train_pct':90,'min_test_pct':85,'csv_sha256':hashlib.sha256((ROOT/'matches.csv').read_bytes()).hexdigest(),'warning':'Exploratory multiple testing; shortlist uses recent test results, so not independent holdout. All history features require a 30-minute to 3-hour gap. Current HT only allowed for 2H markets. No production changes.','leagues':reports}
(ROOT/'research/discovered_l15_l20.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
