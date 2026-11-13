<?php
define('PATTERN_LIVE_TEST', true);
require __DIR__ . '/../pattern-live-data.php';
$league = 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play';
$live = ['league' => $league, 'homeTeam' => 'Germany (V)', 'awayTeam' => 'Netherlands (V)', 'status' => "2H 3'", 'score' => '2 - 1'];
$key = json_encode(['league' => $league, 'teams' => 'Germany (V) vs Netherlands (V)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$now = (new DateTimeImmutable('2026-10-17T12:00:00+07:00'))->getTimestamp();
$data = ['timestamp' => '2026-10-17T11:59:55', 'matches' => [
    $live,
    array_merge($live, ['league' => 'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play']),
    array_merge($live, ['league' => 'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play']),
    array_merge($live, ['league' => 'Other competition']),
    array_merge($live, ['status' => 'FT']),
    array_merge($live, ['status' => 'Upcoming']),
    array_merge($live, ['status' => 'H.Time', 'score' => '0 - 0', 'homeTeam' => 'France']),
], 'htScores' => [$key => '1 - 0']];
$result = patternLivePayload($data, $now);
if ($result['count'] !== 4 || $result['age'] !== 5 || !$result['fresh'] || $result['matches'][0]['ht'] !== '1 - 0' || $result['matches'][3]['ht'] !== '0 - 0') throw new Exception('Filtering / HT mapping failed');
if (array_column($result['matches'], 'league') !== ['16', '15', '20', '16']) throw new Exception('League identities lost');
if ($result['matches'][1]['ht'] !== null || $result['matches'][2]['ht'] !== null) throw new Exception('HT leaked between leagues');
$data['timestamp'] = '2026-10-17T11:00:00';
if (patternLivePayload($data, $now)['fresh']) throw new Exception('Stale data marked fresh');
$data['timestamp'] = null;
if (patternLivePayload($data, $now)['fresh']) throw new Exception('Missing timestamp marked fresh');
echo "League filter, in-play status, HT mapping and snapshot freshness checks passed.\n";
$markets = patternLiveMarkets(['FT. 1X2: Home:2.10 | Draw:2.70 | Away:3.25',
    'FT. O/U: o 2.75:1.81 | u 2.75:1.95', '1H. O/U: o 0.5:1.83 | u 0.5:1.93',
    '2H. O/U: o 1.5:[LOCKED] | u 1.5:[LOCKED]']);
if (count($markets) !== 3 || $markets[0]['period'] !== 'FT' || $markets[0]['selections'][0] !== ['side'=>'O','line'=>'2.75','price'=>'1.81']
    || $markets[1]['period'] !== '1H' || !$markets[2]['locked'] || $markets[2]['selections']) throw new Exception('O/U parsing or locked market handling failed');
$data['matches'] = [array_merge($live, ['odds'=>['FT. O/U: o 2.75:1.81 | u 2.75:1.95']])];
$data['timestamp'] = '2026-10-17T11:59:55';
if (patternLivePayload($data,$now)['matches'][0]['markets'][0]['selections'][1]['side'] !== 'U') throw new Exception('Odds not forwarded to live payload');
if (patternLiveMarkets([]) !== []) throw new Exception('Missing odds must stay missing');
echo "O/U line, Over/Under prices, phase labels, ignored unrelated markets and locked/missing markets passed.\n";
