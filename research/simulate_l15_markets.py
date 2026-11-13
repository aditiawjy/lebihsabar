"""Historical settlement and hypothetical flat-price scenarios, NOT actual betting returns."""
import csv,json,collections,math,hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
distribution=collections.Counter();seen=set();invalid=duplicates=0
for r in csv.DictReader((ROOT/'matches.csv').open(encoding='utf-8-sig',newline='')):
    if r['league']!='SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play':continue
    key=(r['match_time'],r['home_team'].strip().lower(),r['away_team'].strip().lower())
    if key in seen:duplicates+=1;continue
    seen.add(key)
    try:
        hh,ha,h,a=[int(r[k]) for k in ['fh_home','fh_away','ft_home','ft_away']]
        if not (0<=hh<=h and 0<=ha<=a):raise ValueError()
    except (ValueError,TypeError):invalid+=1;continue
    distribution[(r['match_time'][:7],hh,ha,h,a)]+=1
N=sum(distribution.values());markets=[]
def binary(ok):return (1.,0.,0.) if ok else (0.,1.,0.)
def asian(margin):
    # Quarter lines split equally into adjacent whole/half lines.
    parts=[margin-.25,margin+.25] if abs(margin*2-round(margin*2))>1e-8 else [margin]
    n=len(parts);return (sum(x>0 for x in parts)/n,sum(x<0 for x in parts)/n,sum(x==0 for x in parts)/n)
def add(name,family,fn):
    monthly=collections.defaultdict(lambda:[0.,0.,0.,0]);full=collections.Counter()
    for (month,hh,ha,h,a),count in distribution.items():
        w,l,p=fn(hh,ha,h,a);assert abs(w+l+p-1)<1e-9
        values=monthly[month];values[0]+=count*w;values[1]+=count*l;values[2]+=count*p;values[3]+=count
        full[(w,l,p)]+=count
    W=sum(v[0] for v in monthly.values());L=sum(v[1] for v in monthly.values());P=sum(v[2] for v in monthly.values())
    assert abs(W+L+P-N)<1e-7
    be=1+L/W if W else None
    worst=max((1+v[1]/v[0] if v[0] else math.inf for v in monthly.values()),default=math.inf)
    scenario={}
    for odds in [1.05,1.10,1.20,1.50,1.80,1.90,2.,3.,5.,10.]:
        profits={m:round(v[0]*(odds-1)-v[1],4) for m,v in sorted(monthly.items())}
        profit=W*(odds-1)-L
        scenario[str(odds)]={'profit_units':round(profit,4),'roi_pct':round(100*profit/N,4),'positive_months':sum(p>0 for p in profits.values()),'negative_months':sum(p<0 for p in profits.values()),'monthly':profits}
    markets.append(dict(name=name,family=family,samples=N,winning_stake=W,losing_stake=L,returned_stake=P,
        full_win=full[(1.,0.,0.)],half_win=full[(.5,0.,.5)],push=full[(0.,0.,1.)],half_loss=full[(0.,.5,.5)],full_loss=full[(0.,1.,0.)],
        break_even_decimal=round(be,6) if be else None,worst_month_break_even_decimal=round(worst,6) if math.isfinite(worst) else None,months=len(monthly),scenario=scenario))
periods={'FT':lambda hh,ha,h,a:(h,a),'1H':lambda hh,ha,h,a:(hh,ha),'2H':lambda hh,ha,h,a:(h-hh,a-ha)}
for period,get in periods.items():
    # Only FT/1H O/U were observed. 2H O/U is additional research, clearly marked.
    for line in ([.5,1.,1.25,1.5,2.25,2.5,2.75,3.5,4.5,5.5] if period=='FT' else [.5,1.,1.25,1.5,2.5,3.5]):
        for side in ['O','U']:
            add(f'{period} {side}{line:g}','Total goals'+(' (2H research)' if period=='2H' else ''),lambda hh,ha,h,a,get=get,line=line,side=side:asian((sum(get(hh,ha,h,a))-line)*(1 if side=='O' else -1)))
    scoregrid=[(h,a) for h in range(5 if period=='FT' else 4) for a in range(5 if period=='FT' else 4)]
    # Full grids are sensitivity analysis; availability/AOS boundaries must be checked with provider.
    for target in scoregrid:add(f'{period} Correct Score {target[0]}-{target[1]}','Correct score',lambda hh,ha,h,a,get=get,target=target:binary(get(hh,ha,h,a)==target))
for period,get in [('FT',periods['FT']),('1H',periods['1H'])]:
    for parity in [0,1]:add(f'{period} '+('Even' if parity==0 else 'Odd'),'Odd/Even',lambda hh,ha,h,a,get=get,parity=parity:binary(sum(get(hh,ha,h,a))%2==parity))
    for side in ['Home','Away']:
        for line in [-1.,-.75,-.5,-.25,.25,.5,.75,1.]:
            add(f'{period} AH {side} {line:+g}','Asian handicap',lambda hh,ha,h,a,get=get,side=side,line=line:asian((get(hh,ha,h,a)[0]-get(hh,ha,h,a)[1])*(1 if side=='Home' else -1)+line))
def outcome(h,a):return 'Home' if h>a else 'Away' if h<a else 'Draw'
for ht in ['Home','Draw','Away']:
    for ft in ['Home','Draw','Away']:add(f'HT/FT {ht}/{ft}','Half time/full time',lambda hh,ha,h,a,ht=ht,ft=ft:binary(outcome(hh,ha)==ht and outcome(h,a)==ft))
for hp in [0,1]:
    for fp in [0,1]:add(f'HT/FT {"Odd" if hp else "Even"}/{"Odd" if fp else "Even"}','HT/FT Odd/Even',lambda hh,ha,h,a,hp=hp,fp=fp:binary((hh+ha)%2==hp and (h+a)%2==fp))
for label,predicate in {
    'Total Goal 0-1':lambda h,a:h+a<=1,'Total Goal 2-3':lambda h,a:2<=h+a<=3,'Total Goal 4-6':lambda h,a:4<=h+a<=6,'Total Goal 7+':lambda h,a:h+a>=7,
    'Both score':lambda h,a:h>0 and a>0,'One team scores':lambda h,a:(h>0)!=(a>0),'Neither scores':lambda h,a:h+a==0,
    'Double Chance Home/Draw':lambda h,a:h>=a,'Double Chance Home/Away':lambda h,a:h!=a,'Double Chance Away/Draw':lambda h,a:a>=h,
    'Clean Sheet Home Yes':lambda h,a:a==0,'Clean Sheet Home No':lambda h,a:a>0,'Clean Sheet Away Yes':lambda h,a:h==0,'Clean Sheet Away No':lambda h,a:h>0,
    'Win To Nil Home':lambda h,a:h>0 and a==0,'Win To Nil Away':lambda h,a:a>0 and h==0,
    'Margin Home 1':lambda h,a:h-a==1,'Margin Home 2':lambda h,a:h-a==2,'Margin Home 3+':lambda h,a:h-a>=3,
    'Margin Away 1':lambda h,a:a-h==1,'Margin Away 2':lambda h,a:a-h==2,'Margin Away 3+':lambda h,a:a-h>=3,
    'Score Draw excluding 0-0':lambda h,a:h==a and h>0,
    '3-way Hcap Home -1':lambda h,a:h-a>1,'3-way Hcap Draw -1':lambda h,a:h-a==1,'3-way Hcap Away +1':lambda h,a:h-a<1,
}.items():add(label,'Other score-derived markets',lambda hh,ha,h,a,predicate=predicate:binary(predicate(h,a)))
# Compound HT/FT score and exact total goals: outcomes observed in the CSV.
joint_scores=sorted({(hh,ha,h,a) for _,hh,ha,h,a in distribution})
for target in joint_scores:add('HT/FT Score %d-%d/%d-%d'%target,'HT/FT Correct Score',lambda hh,ha,h,a,target=target:binary((hh,ha,h,a)==target))
for target in sorted({(hh+ha,h+a) for _,hh,ha,h,a in distribution}):add(f'HT/FT Exact Goals {target[0]}/{target[1]}','HT/FT Exact Total Goals',lambda hh,ha,h,a,target=target:binary((hh+ha,h+a)==target))
report={'samples':N,'duplicates_ignored':duplicates,'invalid_ignored':invalid,'months':sorted({k[0] for k in distribution}),
    'csv_sha256':hashlib.sha256((ROOT/'matches.csv').read_bytes()).hexdigest(),
    'assumptions':['One unit per fixture; constant hypothetical DECIMAL odds; no staking progression.','Markets/lines not necessarily offered historically. No historical odds, commissions, liquidity, live entry state or timestamps of goal events.','Same dataset used for exploration; no independent prospective profitability validation. Zero-goal counts as Even.','Asian lines settle as whole/half/quarter pre-match bets. Live handicap rules may differ.','Score Box payment scheme and First/Last Goal cannot be backtested from this CSV. AOS depends on actual offered score grid, omitted.'],
    'markets':markets}
folder=ROOT/'research';(folder/'league15_market_simulation.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
selected=['FT O0.5','FT O1.5','FT O2.25','FT U2.25','FT O2.5','FT U2.5','1H O1','1H U1','1H O1.25','1H U1.25','FT U5.5','1H U3.5','Both score','Double Chance Home/Draw']
md=f'# Simulasi market League 15\n\n{N:,} hasil valid; {len(report["months"])} bulan kalender ({report["months"][0]}–{report["months"][-1]}), bulan terakhir belum selesai. {len(markets)} pilihan/line diuji. Ini analisis impas dan skenario hipotetis, **bukan bukti profit aktual atau konsisten**.\n\n'
md+='\n'.join('- '+a for a in report['assumptions'])+'\n\nProfit = unit stake menang × (odds desimal − 1) − unit stake kalah. Odds impas = 1 + unit kalah / unit menang. Odds impas bulan terburuk bukan jaminan batas masa depan.\n\n'
md+='## Ringkasan\n\n|Market|Odds impas|Odds impas bulan terburuk|Profit asumsi odds 1.90 (unit)|Bulan positif/total|\n|---|---:|---:|---:|---:|\n'
for name in selected:
    m=next(m for m in markets if m['name']==name);p=m['scenario']['1.9'];md+=f'|{name}|{m["break_even_decimal"]}|{m["worst_month_break_even_decimal"]}|{p["profit_units"]:+,.2f}|{p["positive_months"]}/{m["months"]}|\n'
md+='\n**Odds 1.90 di atas hanya asumsi matematika yang sama untuk semua market, bukan harga yang tersedia. Membandingkan profit antarmarket pada harga seragam tidak menunjukkan market terbaik.**\n\n## Semua pilihan yang bisa dihitung\n\n|Market|Full win|Half win|Push|Half loss|Full loss|Odds impas|\n|---|---:|---:|---:|---:|---:|---:|\n'
for m in markets:md+='|'+'|'.join(str(m[k]) for k in ['name','full_win','half_win','push','half_loss','full_loss','break_even_decimal'])+'|\n'
(folder/'league15_market_simulation.md').write_text(md,encoding='utf-8')
print(json.dumps({'samples':N,'selections':len(markets),'months':report['months'],'summary':[{k:m[k] for k in ['name','break_even_decimal','worst_month_break_even_decimal','winning_stake','losing_stake']} for m in markets if m['name'] in selected]}))
