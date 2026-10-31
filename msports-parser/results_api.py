"""Fetch the native MSports Results JSON without a browser or extension."""
import argparse
from datetime import date, datetime, time, timedelta, timezone
import json
import os
from pathlib import Path
import sys
import re
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen

ORIGIN = 'https://msports.m88m8idtop.com'
CONFIG = Path(os.environ.get('LOCALAPPDATA', str(Path.home()))) / 'Sabaraja' / 'msports-session.bin'


def protect_local(data, decrypt=False):
    """Windows DPAPI: only the current Windows account can decrypt this file."""
    import ctypes
    from ctypes import wintypes
    class Blob(ctypes.Structure):
        _fields_ = [('size', wintypes.DWORD), ('data', ctypes.POINTER(ctypes.c_byte))]
    buffer = ctypes.create_string_buffer(data)
    source = Blob(len(data), ctypes.cast(buffer, ctypes.POINTER(ctypes.c_byte)))
    target = Blob()
    crypt = ctypes.windll.crypt32
    if decrypt:
        ok = crypt.CryptUnprotectData(ctypes.byref(source), None, None, None, None, 1, ctypes.byref(target))
    else:
        ok = crypt.CryptProtectData(ctypes.byref(source), 'Sabaraja MSports session', None, None, None, 1, ctypes.byref(target))
    if not ok:
        raise ValueError('Konfigurasi sesi Windows tidak dapat diproses.')
    try:
        return ctypes.string_at(target.data, target.size)
    finally:
        ctypes.windll.kernel32.LocalFree(target.data)


def import_curl(path, config_path=CONFIG):
    # Parse text only. Never execute a pasted command.
    source = path.read_text(encoding='utf-8-sig').replace('^', '')
    url = re.search(r'https://[^"\s]+/match', source)
    if not url or not url[0].startswith(ORIGIN + '/api/v1/report/result/'):
        raise ValueError('File bukan request Results MSports yang didukung.')
    headers = dict(re.findall(r'(?:--header|-H)\s+"([^:"]+):\s*((?:\\.|[^"\r\n])*)"', source))
    headers = {k: v.replace('\\"', '"') for k, v in headers.items()}
    cookie = re.search(r'(?:--cookie|-b)\s+"([^"\r\n]*)"', source)
    if cookie:
        headers['Cookie'] = cookie[1]
    if not any(k.lower() == 'authorization' for k in headers):
        raise ValueError('Header Authorization tidak ditemukan.')
    allowed = {'accept', 'accept-language', 'authorization', 'content-type', 'origin',
               'referer', 'priority', 'user-agent', 'cookie', 'sec-ch-ua',
               'sec-ch-ua-mobile', 'sec-ch-ua-platform', 'sec-fetch-dest',
               'sec-fetch-mode', 'sec-fetch-site'}
    headers = {k: v for k, v in headers.items() if k.lower() in allowed}
    config = {'url': url[0], 'headers': headers}
    config_path.parent.mkdir(parents=True, exist_ok=True)
    config_path.write_bytes(protect_local(json.dumps(config).encode()))


def load_config(config_path=CONFIG):
    if not config_path.exists():
        return None
    return json.loads(protect_local(config_path.read_bytes(), decrypt=True))


def result_payload(day, language='en-US', sport=10, utc_offset=8):
    tz = timezone(timedelta(hours=utc_offset))
    return {
        'sport_id': sport,
        'language_id': language,
        'league_id': 0,
        'from_date': datetime.combine(day, time.min, tz).isoformat(timespec='milliseconds'),
        'to_date': datetime.combine(day, time(23, 59, 59, 999000), tz).isoformat(timespec='milliseconds'),
    }


def fetch_results(payload, environ=None, opener=urlopen, config=None):
    env = os.environ if environ is None else environ
    required = ['MSPORTS_OPCODE', 'MSPORTS_SESSION', 'MSPORTS_BEARER']
    missing = [key for key in required if not env.get(key, '').strip()]
    if missing and config is None:
        raise ValueError('Konfigurasi sesi belum lengkap: ' + ', '.join(missing))
    opcode, session = (quote(env.get(key, '').strip(), safe='') for key in required[:2])
    token = env.get('MSPORTS_BEARER', '').strip()
    if token.lower().startswith('bearer '):
        token = token[7:]
    url = config['url'] if config else f'{ORIGIN}/api/v1/report/result/{opcode}/{session}/match'
    if not url.startswith(ORIGIN + '/api/v1/report/result/') or not url.endswith('/match'):
        raise ValueError('Alamat Results tidak valid.')
    headers = config['headers'] if config else {'Authorization': 'Bearer ' + token,
                 'Content-Type': 'application/json', 'Accept': 'application/json',
                 'Origin': ORIGIN, 'Referer': ORIGIN + '/app/v2/index.html'}
    body = json.dumps(payload).encode('utf-8')
    request = Request(url, data=body, method='POST', headers=headers)
    try:
        with opener(request, timeout=30) as response:
            result = json.load(response)
    except HTTPError as error:
        # Never print the request URL: it contains the session identifier.
        if error.code in (401, 403):
            raise ValueError(f'API menolak sesi (HTTP {error.code}); perbarui sesi login.') from None
        else:
            raise ValueError(f'API mengembalikan HTTP {error.code}.') from None
    except (URLError, TimeoutError):
        raise ValueError('Koneksi ke API Results gagal atau timeout.') from None
    except (ValueError, UnicodeError):
        raise ValueError('Respons API bukan JSON yang valid.') from None
    if not isinstance(result, dict) or str(result.get('status')) != '1':
        raise ValueError('API belum mengembalikan hasil sukses (status bukan 1).')
    if not isinstance(result.get('data'), list):
        raise ValueError('Struktur data Results berubah; respons tidak disimpan.')
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--date', type=date.fromisoformat,
                        default=datetime.now(timezone(timedelta(hours=8))).date())
    parser.add_argument('--language', default='en-US')
    parser.add_argument('--sport', type=int, default=10)
    parser.add_argument('--utc-offset', type=int, choices=range(-12, 15), default=8)
    default_dir = Path(os.environ.get('LOCALAPPDATA', str(Path.home()))) / 'Sabaraja' / 'results'
    parser.add_argument('--output', type=Path, default=None)
    parser.add_argument('--session-file', type=Path, default=CONFIG)
    parser.add_argument('--import-curl', type=Path, help='Impor sesi cURL ke konfigurasi Windows terenkripsi')
    args = parser.parse_args()
    try:
        if args.import_curl:
            import_curl(args.import_curl, args.session_file)
            print('Konfigurasi sesi disimpan terenkripsi untuk akun Windows ini.')
        data = fetch_results(result_payload(args.date, args.language, args.sport, args.utc_offset), config=load_config(args.session_file))
        output = args.output or default_dir / f'msports-{args.date}.json'
        output.parent.mkdir(parents=True, exist_ok=True)
        temporary = output.with_suffix(output.suffix + '.tmp')
        temporary.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
        temporary.replace(output)
        league16 = [row for row in data['data'] if str(row.get('events')) == '10031403']
        print(f'Results tersimpan: {output} ({len(data["data"])} pertandingan; {len(league16)} League 16).')
        return 0
    except (ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
