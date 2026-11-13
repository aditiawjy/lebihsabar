import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'msports-parser'))
import results_api as api

calls = []
def fake(payload, config=None):
    calls.append(payload['league_id'])
    return {'status': 1, 'data': [
        {'events': str(payload['league_id'])},
        {'events': '10026214'}, {'events': '10026211'}
    ]}

result = api.fetch_enabled_results({'league_id': 0}, fetcher=fake)
assert calls == list(api.ENABLED_RESULT_LEAGUES)
assert 0 not in calls and 10026214 not in calls and 10026211 not in calls
assert len(result['data']) == 6
assert {int(row['events']) for row in result['data']} == set(calls)
print('PASS: only enabled leagues requested and retained; League 15/20 excluded even if provider returns them')
