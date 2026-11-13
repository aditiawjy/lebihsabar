"""Local-only Telegram notifier for repeated completed FT 0-0 results."""
import argparse,json,os,re,sys,time,msvcrt
from pathlib import Path
from datetime import datetime,timedelta,timezone
from urllib.request import Request,urlopen
from urllib.error import HTTPError,URLError
from results_api import protect_local

BASE=Path(os.environ.get('LOCALAPPDATA',str(Path.home())))/'Sabaraja'
CONFIG=BASE/'telegram-config.bin';STATE=BASE/'telegram-zerozero-state.json'
TZ=timezone(timedelta(hours=8))
IDS={'10026214':'15','10031403':'16','10026211':'20'}
THRESHOLDS={'15':5,'16':4,'20':3}
HEALTH=BASE/'telegram-health-state.json'
def health_transition(previous,status,now,source):
    current=dict(previous or {})
    if current.get('status')!=status:current.update(status=status,since=now,count=0)
    current['count']=current.get('count',0)+1
    event=None
    if status=='ok':
        if current.get('alerted'):event='recovered'
        current['alerted']=False
        current.pop('alerted_status',None)
    elif (source=='api' and status=='denied') or (current['count']>=2 and now-current['since']>=30):
        if current.get('alerted_status')!=status:
            event='outage';current.update(alerted=True,alerted_status=status)
    return current,event
def report_health(config,payload):
    source=payload.get('source');status=payload.get('status')
    allowed={'api':{'ok','denied','error','stale'},'scraper':{'ok','offline','stale'}}
    if source not in allowed or status not in allowed[source]:raise ValueError('Status kesehatan tidak valid.')
    state=json.loads(HEALTH.read_text()) if HEALTH.exists() else {}
    current,event=health_transition(state.get(source),status,time.time(),source)
    if event:
        component='API Results' if source=='api' else 'Live scraper'
        descriptions={'denied':'Sesi API ditolak (401/403). Perbarui sesi login/cURL.','error':'Pembaruan API gagal berulang. Data baru belum bisa dipastikan.','stale':'Data melewati batas usia aman. Jangan menganggap sinyal lama sebagai sinyal baru.','offline':'Koneksi scraper terputus berulang. Menunggu koneksi pulih.'}
        message=('✅ Sabaraja · '+component+' pulih. Data terbaru kembali tersedia.') if event=='recovered' else ('⚠️ Sabaraja · '+component+' bermasalah\n'+descriptions[status])
        api(config['token'],'sendMessage',{'chat_id':config['chat_id'],'text':message})
    state[source]=current;save_json(HEALTH,state)
    return {'success':True,'sent':int(bool(event)),'component':source,'status':status}
def api(token,method,payload=None):
    request=Request('https://api.telegram.org/bot'+token+'/'+method,data=json.dumps(payload or {}).encode(),headers={'Content-Type':'application/json'})
    try:
        with urlopen(request,timeout=15) as response: data=json.load(response)
    except HTTPError as error:raise ValueError(f'Telegram {method}: HTTP {error.code}.') from None
    except (URLError,TimeoutError,OSError):raise ValueError(f'Telegram {method}: koneksi gagal atau timeout.') from None
    if not data.get('ok'):raise ValueError('Telegram menolak permintaan.')
    return data['result']
def save_json(path,value):
    temporary=path.with_suffix('.tmp');temporary.write_text(json.dumps(value),encoding='utf-8');os.replace(temporary,path)
def collect(now=None,folder=None):
    now=now or datetime.now(TZ);today=now.date();session=today if now.hour>=12 else today-timedelta(days=1)
    folder=Path(folder or os.environ.get('SABARAJA_RESULTS_DIR',BASE/'results'))
    merged={};fresh=False
    for day in [today-timedelta(days=1),today]:
        file=folder/f'msports-{day}.json'
        if not file.exists():continue
        if time.time()-file.stat().st_mtime<=300:fresh=True
        data=json.loads(file.read_text(encoding='utf-8'))
        for row in data.get('data',[]):
            key=str(row.get('events'))+'|'+str(row.get('no_partai') or (str(row.get('match_date'))+'|'+str(row.get('home_name'))+'|'+str(row.get('away_name'))))
            merged[key]=row
    counts={f'{league}|{session if league=="16" else today}':{} for league in THRESHOLDS}
    for row in merged.values():
        league=IDS.get(str(row.get('events')))
        if not league or row.get('st_fs')!='Y' or row.get('st_postponed')!='N':continue
        score=re.fullmatch(r'(\d+):(\d+)_(\d+):(\d+)',row.get('fs_home') or '')
        if not score or int(score[3])+int(score[4])!=0:continue
        try:
            at=datetime.fromisoformat(row['match_date'].replace('Z','+00:00'))
            if at.tzinfo is None:continue
            at=at.astimezone(TZ)
        except (KeyError,ValueError):continue
        start=datetime.combine(session if league=='16' else today,datetime.min.time(),TZ)+(timedelta(hours=12) if league=='16' else timedelta())
        end=start+(timedelta(hours=12,minutes=46) if league=='16' else timedelta(days=1))
        if not start<=at<end or at>now:continue
        scope=f'{league}|{session if league=="16" else today}'
        for field in ['home_name','away_name']:
            team=re.sub(r'\s+',' ',re.sub(r'\s*\(V\)','',row.get(field) or '',flags=re.I)).strip()
            if team:counts[scope][team]=counts[scope].get(team,0)+1
    return counts,fresh
def notifications(counts,state):
    alerts=[]
    for scope,teams in counts.items():
        league,date=scope.split('|');previous=state.get(scope,{})
        for team,count in teams.items():
            if count>=THRESHOLDS[league] and count>previous.get(team,0):alerts.append((scope,team,count))
    return alerts
def run(mode):
    if not CONFIG.exists():return {'success':False,'error':'Telegram belum dikonfigurasi.'}
    config=json.loads(protect_local(CONFIG.read_bytes(),decrypt=True))
    if mode=='health':return report_health(config,json.load(sys.stdin))
    if mode=='health-test':
        api(config['token'],'sendMessage',{'chat_id':config['chat_id'],'text':'🧪 TES PERINGATAN GANGGUAN · Sabaraja\nPemantauan API Results dan live scraper sudah terhubung.\nPesan akan dikirim saat sesi ditolak, koneksi gagal berulang, data kedaluwarsa, dan saat pulih.\nIni hanya tes, bukan laporan gangguan nyata.'})
        return {'success':True,'test_sent':True}
    if mode=='test':
        result=api(config['token'],'sendMessage',{'chat_id':config['chat_id'],'text':'🧪 TES Sabaraja Pattern\nKoneksi Telegram berhasil.\nNotifikasi FT 0–0: L15 mulai 5×, L16 mulai 4×, L20 mulai 3× per tim.\nIni pesan tes, bukan sinyal pertandingan.'})
        return {'success':True,'test_sent':True,'message_id':result['message_id']}
    counts,fresh=collect()
    if not fresh:return {'success':True,'sent':0,'note':'Menunggu hasil API terbaru.'}
    state=json.loads(STATE.read_text()) if STATE.exists() else {}
    if mode=='baseline' or not state.get('initialized'):
        save_json(STATE,{'initialized':True,'scopes':counts});return {'success':True,'sent':0,'baseline':True}
    alerts=notifications(counts,state.get('scopes',{}))
    # Update state only after Telegram accepts the message; retry failures next cycle.
    if alerts:
        lines=['🔔 Sabaraja · FT 0–0 berulang']
        for scope,team,count in alerts:
            league,date=scope.split('|');lines.append(f'L{league} · {date} · {team}: {count}× FT 0–0'+(' (sesi 12:00–00:45 UTC+8)' if league=='16' else ' (hari UTC+8)'))
        lines.append('Hitungan hasil selesai dari API. Bukan jaminan hasil pertandingan berikutnya.')
        api(config['token'],'sendMessage',{'chat_id':config['chat_id'],'text':'\n'.join(lines)[:4000]})
    scopes=state.get('scopes',{});scopes.update(counts)
    scopes=dict(sorted(scopes.items(),key=lambda item:item[0].split('|')[1],reverse=True)[:15])
    save_json(STATE,{'initialized':True,'scopes':scopes})
    return {'success':True,'sent':len(alerts),'checked':sum(len(t) for t in counts.values())}
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--import-token',type=Path);parser.add_argument('--expected-bot',default='sabaraja_pattern_bot');parser.add_argument('--mode',choices=['test','baseline','notify','health','health-test'],default='notify');args=parser.parse_args()
    BASE.mkdir(parents=True,exist_ok=True)
    try:
        with (BASE/'telegram-notify.lock').open('a+b') as lock:
            lock.seek(0);lock.write(b'0');lock.flush();lock.seek(0)
            try:msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
            except OSError:print(json.dumps({'success':True,'sent':0,'note':'Pemeriksaan lain masih berjalan.'}));return
            if args.import_token:
                try:token=args.import_token.read_text().strip()
                finally:args.import_token.unlink(missing_ok=True)
                if api(token,'getMe').get('username')!=args.expected_bot:raise ValueError('Identitas bot tidak cocok.')
                updates=api(token,'getUpdates',{'timeout':0})
                chats=[u['message']['chat']['id'] for u in updates if u.get('message',{}).get('chat',{}).get('type')=='private' and u['message'].get('text')=='/start']
                if not chats:raise ValueError('Buka bot dan tekan Start terlebih dahulu.')
                CONFIG.write_bytes(protect_local(json.dumps({'token':token,'chat_id':chats[-1],'username':args.expected_bot}).encode()))
            print(json.dumps(run(args.mode)))
    except ValueError as error:
        print(json.dumps({'success':False,'error':str(error)}));sys.exit(1)
    except Exception:
        print(json.dumps({'success':False,'error':'Konfigurasi Telegram tidak dapat diproses.'}));sys.exit(1)
if __name__=='__main__':main()
