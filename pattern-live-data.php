<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function patternLiveMarkets(array $odds): array {
    $markets = [];
    foreach ($odds as $odd) {
        if (!is_string($odd) || !preg_match('/^(FT|1H|2H)\.\s*O\/U\s*:\s*(.+)$/i', trim($odd), $market)) continue;
        $locked = stripos($market[2], '[LOCKED]') !== false;
        $selections = [];
        if (!$locked) foreach (explode('|', $market[2]) as $selection) {
            if (preg_match('/^\s*([ou])\s+(\d+(?:\.\d+)?(?:\/\d+(?:\.\d+)?)?)\s*:\s*(-?\d+(?:\.\d+)?)\s*$/i', $selection, $parsed)) {
                $selections[] = ['side'=>strtoupper($parsed[1]), 'line'=>$parsed[2], 'price'=>$parsed[3]];
            }
        }
        $markets[] = ['period'=>strtoupper($market[1]), 'locked'=>$locked, 'selections'=>$selections];
    }
    return $markets;
}
function patternLivePayload(array $data, ?int $now = null): array {
    $now = $now ?? time();
    $timestamp = $data['timestamp'] ?? null;
    $age = null;
    if (is_string($timestamp) && $timestamp !== '') {
        try {
            $parsed = new DateTimeImmutable($timestamp, new DateTimeZone('Asia/Jakarta'));
            $age = max(0, $now - $parsed->getTimestamp());
        } catch (Exception $e) { /* Unknown age remains stale. */ }
    }
    $matches = [];
    $leagues = [
        'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play' => '15',
        'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play' => '16',
        'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play' => '20',
    ];
    $normalize = function ($text) { return strtolower(trim(preg_replace('/\s+/', ' ', (string) $text))); };
    $leagueIds = [];
    foreach ($leagues as $name => $id) $leagueIds[$normalize($name)] = $id;
    foreach (($data['matches'] ?? []) as $match) {
        if (!is_array($match)) continue;
        $leagueId = $leagueIds[$normalize($match['league'] ?? '')] ?? null;
        if ($leagueId === null) continue;
        $status = trim((string) ($match['status'] ?? ''));
        if (!preg_match("/^(?:[12]H\s+\d+|H\.?\s*Time\b|HT$|Half[ -]?Time\b)/i", $status)) continue;
        $home = trim((string) ($match['homeTeam'] ?? ''));
        $away = trim((string) ($match['awayTeam'] ?? ''));
        if ($home === '' || $away === '') continue;
        // Same match key used in live-scraper/chrome_extension/lib/utils.js.
        $key = json_encode(['league' => $match['league'] ?? 'N/A', 'teams' => $match['teams'] ?? ($home . ' vs ' . $away)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ht = $data['htScores'][$key] ?? null;
        if ($ht === null && preg_match('/^(?:H\.?\s*Time|HT$|Half[ -]?Time)/i', $status)) $ht = $match['score'] ?? null;
        $matches[] = ['league' => $leagueId, 'home' => $home, 'away' => $away, 'status' => $status,
            'score' => $match['score'] ?? null, 'ht' => $ht,
            'markets' => patternLiveMarkets(is_array($match['odds'] ?? null) ? $match['odds'] : [])];
    }
    return ['online' => true, 'fresh' => $age !== null && $age <= 30, 'age' => $age,
        'timestamp' => $timestamp, 'matches' => $matches, 'count' => count($matches)];
}

if (!defined('PATTERN_LIVE_TEST')) {
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents('http://127.0.0.1:5000/api/live-data', false, $context);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
        echo json_encode(['online' => false, 'fresh' => false, 'matches' => [], 'count' => 0,
            'message' => 'Live-scraper belum terhubung. Jalankan API dan scraper melalui live-scraper/start_headless.bat.']);
    } else {
        echo json_encode(patternLivePayload($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
