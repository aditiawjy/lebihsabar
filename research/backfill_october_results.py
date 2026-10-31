"""Fetch API history and upsert completed results via the existing CSV writer."""
import sys,json,time,os,hashlib,argparse
from pathlib import Path
from datetime import datetime,date,timedelta,timezone
from urllib.request import Request,urlopen
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'msports-parser'))
from results_api import fetch_results,result_payload,load_config
import re
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--start',type=date.fromisoformat,default=date(2026,10,1))
parser.add_argument('--end',type=date.fromisoformat,default=datetime.now(timezone(timedelta(hours=8))).date())
parser.add_argument('--session-file',type=Path)
parser.add_argument('--results-dir',type=Path,default=Path(os.environ['LOCALAPPDATA'])/'Sabaraja/results')
parser.add_argument('--report',type=Path,default=ROOT/'research/october_api_backfill_report.json')
parser.add_argument('--delay',type=float,default=2)
parser.add_argument('--all-saba-efootball',action='store_true',help='Include all SABA and E-FOOTBALL leagues returned by the API')
args=parser.parse_args()
if args.end<args.start:parser.error('End date precedes start date')
TZ=timezone(timedelta(hours=8));start=args.start;end=args.end
folder=args.results_dir;folder.mkdir(parents=True,exist_ok=True)
event_ids={'10031619','10026214','10031403','10026211','10031501','10031502','10034432','10034496'}
report={'start':str(start),'end':str(end),'days':[],'inserted':0,'updated':0,'unchanged':0,'invalid':0}
matches={};config=load_config(args.session_file) if args.session_file else load_config()
def save_report():
    args.report.write_text(json.dumps(report,indent=2),encoding='utf-8')
for offset in range((end-start).days+2):
    day=start-timedelta(days=1)+timedelta(days=offset)
    try:payload=fetch_results(result_payload(day,'en-US',10,8),config=config)
    except ValueError as error:
        entry={'date':str(day),'error':str(error)};report['days'].append(entry);save_report();print(json.dumps(entry),flush=True)
        if '401' in str(error) or '403' in str(error):break
        continue
    file=folder/f'msports-{day}.json';tmp=file.with_suffix('.backfill.tmp');tmp.write_text(json.dumps(payload,ensure_ascii=False),encoding='utf-8');os.replace(tmp,file)
    valid=0;dates=[];extras=0;missing_ht=0
    for r in payload['data']:
        event_name=str(r.get('event_name') or '').strip()
        selected=event_name.upper().startswith(('SABA ','E-FOOTBALL ')) if args.all_saba_efootball else str(r.get('events')) in event_ids
        if not selected or r.get('st_fs')!='Y' or r.get('st_postponed')!='N':continue
        if r.get('st_hs')!='Y':missing_ht+=1;continue
        score=re.fullmatch(r'(\d+):(\d+)_(\d+):(\d+)',r.get('fs_home') or '')
        if not score:continue
        fh,fa,ft,at=map(int,score.groups())
        if ft<fh or at<fa:continue
        try:
            t=datetime.fromisoformat(r['match_date'].replace('Z','+00:00'))
            if t.tzinfo is None:continue
            t=t.astimezone(TZ)
        except (KeyError,ValueError):continue
        if not start<=t.date()<=end:continue
        home=re.sub(r'\s+',' ',str(r.get('home_name') or '')).strip();away=re.sub(r'\s+',' ',str(r.get('away_name') or '')).strip();league=str(r.get('event_name') or '').strip()
        if not home or not away or not league:continue
        match={'match_time':t.strftime('%Y-%m-%d %H:%M:%S'),'home_team':home,'away_team':away,'league':league,'fh_home':fh,'fh_away':fa,'ft_home':ft,'ft_away':at,'source_utc_offset':'+08:00'}
        clean=lambda s:re.sub(r'\s*\(V\)','',s,flags=re.I).strip().lower()
        key=(match['match_time'],clean(home),clean(away),league.lower());matches[key]=match;valid+=1;dates.append(str(t.date()))
        if str(r['events']) in {'10031501','10031502','10034432','10034496'}:extras+=1
    entry={'date':str(day),'api_rows':len(payload['data']),'completed_in_month':valid,'efootball_completed':extras,'missing_ht_in_session':missing_ht,'returned_dates':sorted(set(dates))};report['days'].append(entry);save_report();print(json.dumps(entry),flush=True)
    time.sleep(max(0,args.delay))
all_matches=list(matches.values());report['unique_completed']=len(all_matches)
report['leagues']={}
for match in all_matches:report['leagues'][match['league']]=report['leagues'].get(match['league'],0)+1
for offset in range(0,len(all_matches),1000):
    chunk=all_matches[offset:offset+1000]
    req=Request('http://localhost/lebihsabar/save_matches_csv.php',data=json.dumps({'matches':chunk}).encode(),headers={'Content-Type':'application/json'})
    with urlopen(req,timeout=90) as response:saved=json.load(response)
    if not saved.get('success'):raise RuntimeError('CSV save failed')
    for target,source in [('inserted','inserted'),('updated','updated'),('unchanged','skipped_duplicate'),('invalid','skipped_invalid')]:report[target]+=saved.get(source,0)
    print(json.dumps({'saved_chunk':offset//1000+1,'new':saved.get('inserted'),'updated':saved.get('updated'),'unchanged':saved.get('skipped_duplicate')}),flush=True)
report['csv_sha256_after']=hashlib.sha256((ROOT/'matches.csv').read_bytes()).hexdigest()
report['failed_days']=[r['date'] for r in report['days'] if 'error' in r]
report['complete']=not report['failed_days'] and len(report['days'])==(end-start).days+2
save_report()
print(json.dumps({'complete':report['complete'],'unique_completed':len(all_matches),**{k:report[k] for k in ['inserted','updated','unchanged','invalid']}}),flush=True)
