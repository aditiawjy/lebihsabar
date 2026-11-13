"""Fetch V-Soccer Results by WIB date and sync completed games through the CSV writer."""
import argparse
import json
import re
import time
from datetime import date, timedelta
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
BASE = 'http://localhost/lebihsabar/'
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--start', type=date.fromisoformat, required=True)
parser.add_argument('--end', type=date.fromisoformat, required=True)
parser.add_argument('--report', type=Path, required=True)
parser.add_argument('--delay', type=float, default=2.0)
args = parser.parse_args()
if args.end < args.start:
    parser.error('Tanggal akhir sebelum tanggal awal')
report = {'start':str(args.start), 'end':str(args.end), 'timezone':'WIB (UTC+7)', 'days':[],
          'inserted':0, 'updated':0, 'unchanged':0, 'invalid':0, 'saved_batches':0}
matches = {}

def write_report():
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')

def request_json(path, data=None):
    body = None if data is None else json.dumps(data, ensure_ascii=False).encode('utf-8')
    request = Request(BASE+path, data=body, method='POST', headers={'Content-Type':'application/json; charset=utf-8'})
    try:
        with urlopen(request, timeout=120) as response:
            return json.load(response)
    except HTTPError as error:
        try:
            detail = json.load(error).get('error', 'Permintaan gagal')
        except (ValueError, OSError):
            detail = 'Permintaan gagal'
        raise RuntimeError(f'HTTP {error.code}: {detail}') from None
    except (URLError, TimeoutError, ValueError, OSError):
        raise RuntimeError('Koneksi atau respons server lokal gagal') from None

def key_of(match):
    def clean(name):
        return re.sub(r'\s*\(V\)', '', re.sub(r'\s+', ' ', name).strip(), flags=re.I).strip().lower()
    return (match['match_time'], clean(match['home_team']), clean(match['away_team']), match['league'].strip().lower())

for offset in range((args.end-args.start).days+1):
    day = str(args.start+timedelta(days=offset))
    try:
        payload = request_json('vsoccer-results-data.php?date='+day, {})
        if payload.get('date') != day or not isinstance(payload.get('saveMatches'),list):
            raise RuntimeError('Respons Results tidak sesuai format')
        rows = payload['saveMatches']
        for match in rows:
            matches[key_of(match)] = match
        entry = {'date':day, 'matches':len(rows), 'missing_ht':sum(row.get('fh_home') is None or row.get('fh_away') is None for row in rows)}
    except (RuntimeError, KeyError, TypeError) as error:
        entry = {'date':day, 'error':str(error)}
    report['days'].append(entry)
    write_report()
    print(json.dumps(entry, ensure_ascii=False), flush=True)
    if offset < (args.end-args.start).days:
        time.sleep(max(0,args.delay))

all_matches = list(matches.values())
report['unique_completed'] = len(all_matches)
report['leagues'] = {}
for match in all_matches:
    report['leagues'][match['league']] = report['leagues'].get(match['league'],0)+1
for start in range(0, len(all_matches), 4000):
    try:
        result = request_json('save_matches_csv.php', {'matches':all_matches[start:start+4000]})
        if not result.get('success'):
            raise RuntimeError(result.get('error','Gagal menyimpan CSV'))
    except RuntimeError as error:
        report['save_error'] = str(error)
        write_report()
        print(json.dumps({'save_error':str(error)}, ensure_ascii=False), flush=True)
        raise SystemExit(1)
    for target,source in [('inserted','inserted'),('updated','updated'),('unchanged','skipped_duplicate'),('invalid','skipped_invalid')]:
        report[target] += result.get(source,0)
    report['saved_batches'] += 1
    write_report()
    print(json.dumps({'saved_batch':report['saved_batches'],'new':result.get('inserted'),
                      'updated':result.get('updated'),'unchanged':result.get('skipped_duplicate')}),flush=True)

report['failed_dates'] = [day['date'] for day in report['days'] if 'error' in day]
report['empty_dates'] = [day['date'] for day in report['days'] if day.get('matches') == 0]
report['complete'] = not report['failed_dates'] and not report['empty_dates'] and not report['invalid']
write_report()
print(json.dumps({'complete':report['complete'],'unique_completed':len(all_matches),
                  'inserted':report['inserted'],'updated':report['updated'],
                  'unchanged':report['unchanged'],'invalid':report['invalid'],
                  'empty_dates':report['empty_dates'],'failed_dates':report['failed_dates']},ensure_ascii=False),flush=True)
