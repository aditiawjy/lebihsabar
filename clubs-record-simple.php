<?php
date_default_timezone_set('Asia/Jakarta');

// -- Market options ------------------------------------------------------------
$marketOptions = [
    '0.5'       => ['label' => 'Under 0.5',       'short' => 'U0.5',  'class' => 'bg-blue-500 text-white'],
    '1.5'       => ['label' => 'Under 1.5',       'short' => 'U1.5',  'class' => 'bg-sky-500 text-white'],
    '2.5'       => ['label' => 'Under 2.5',       'short' => 'U2.5',  'class' => 'bg-cyan-500 text-white'],
    'fhg0.5'    => ['label' => 'FHG Under 0.5',   'short' => 'FHG',   'class' => 'bg-violet-500 text-white'],
    'shg0.5'    => ['label' => 'SHG Under 0.5',   'short' => 'SHG',   'class' => 'bg-fuchsia-500 text-white'],
    'odd_goal'  => ['label' => 'Odd Goal',        'short' => 'ODD',   'class' => 'bg-orange-500 text-white'],
    'even_goal' => ['label' => 'Even Goal',       'short' => 'EVEN',  'class' => 'bg-emerald-500 text-white'],
    '!2-3'      => ['label' => '!2-3 Goal',        'short' => '!2-3',  'class' => 'bg-red-600 text-white'],
];

function csvCheckMarket(array $m, string $mkt): bool {
    $ftH = (int)$m['ft_home']; $ftA = (int)$m['ft_away'];
    $fhH = (int)$m['fh_home']; $fhA = (int)$m['fh_away'];
    return match($mkt) {
        '0.5'       => ($ftH + $ftA) < 1,
        '1.5'       => ($ftH + $ftA) < 2,
        '2.5'       => ($ftH + $ftA) < 3,
        'fhg0.5'    => ($fhH + $fhA) < 1,
        'shg0.5'    => (($ftH - $fhH) + ($ftA - $fhA)) < 1,
        'odd_goal'  => ($ftH + $ftA) % 2 !== 0,
        'even_goal' => ($ftH + $ftA) % 2 === 0,
        '!2-3'      => ($ftH + $ftA) !== 2 && ($ftH + $ftA) !== 3,
        default     => false,
    };
}
function csvHasFT(array $m): bool {
    return $m['ft_home'] !== '' && $m['ft_away'] !== ''
        && is_numeric($m['ft_home']) && is_numeric($m['ft_away']);
}

function csvNormalizeTime(string $value, string $fallback): string {
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
}

function csvTimeInRange(string $time, string $from, string $to): bool {
    if ($from <= $to) {
        return $time >= $from && $time <= $to;
    }
    return $time >= $from || $time <= $to;
}

function csvDateTimeTimestamp(string $date, string $time): ?int {
    $ts = strtotime($date . ' ' . $time . ':00');
    return $ts === false ? null : $ts;
}

function csvTimestampInRange(?int $ts, int $startTs, int $endTs): bool {
    return $ts !== null && $ts >= $startTs && $ts <= $endTs;
}

function csvBumpDailyMax(array &$dailyCounts, array &$maxByKey, string $key, string $date): void {
    $dailyCounts[$key][$date] = ($dailyCounts[$key][$date] ?? 0) + 1;
    $newCount = $dailyCounts[$key][$date];
    if (!isset($maxByKey[$key]) || $newCount >= $maxByKey[$key]['count']) {
        $maxByKey[$key] = ['count' => $newCount, 'date' => $date];
    }
}

function csvDateSpanDays(string $from, string $to): int {
    $fromTs = strtotime($from . ' 00:00:00');
    $toTs = strtotime($to . ' 00:00:00');
    if ($fromTs === false || $toTs === false) {
        return 1;
    }
    return max(1, (int)floor(abs($toTs - $fromTs) / 86400) + 1);
}

function csvRangeMaxFromDailyCounts(array $dailyCounts, int $windowDays): array {
    $windowDays = max(1, $windowDays);
    if (!$dailyCounts) {
        return ['count' => 0, 'date' => '', 'end_date' => '', 'times' => 0];
    }

    ksort($dailyCounts);
    $items = [];
    foreach ($dailyCounts as $date => $count) {
        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false || (int)$count <= 0) {
            continue;
        }
        $items[] = ['date' => $date, 'ts' => $ts, 'count' => (int)$count];
    }

    $maxCount = 0;
    $maxStart = '';
    $maxEnd = '';
    $maxTimes = 0;
    foreach ($items as $start) {
        $endTs = strtotime('+' . ($windowDays - 1) . ' days', $start['ts']);
        if ($endTs === false) {
            continue;
        }
        $sum = 0;
        foreach ($items as $item) {
            if ($item['ts'] < $start['ts']) {
                continue;
            }
            if ($item['ts'] > $endTs) {
                break;
            }
            $sum += $item['count'];
        }

        if ($sum > $maxCount) {
            $maxCount = $sum;
            $maxStart = $start['date'];
            $maxEnd = date('Y-m-d', $endTs);
            $maxTimes = 1;
        } elseif ($sum === $maxCount) {
            $maxStart = $start['date'];
            $maxEnd = date('Y-m-d', $endTs);
            $maxTimes++;
        }
    }

    return ['count' => $maxCount, 'date' => $maxStart, 'end_date' => $maxEnd, 'times' => $maxTimes];
}

function csvAnchoredRangeMaxFromEventTimes(array $eventTimes, string $anchorTime, int $durationSeconds): array {
    if (!$eventTimes) {
        return ['count' => 0, 'date' => '', 'end_date' => '', 'start_at' => '', 'end_at' => '', 'times' => 0];
    }

    sort($eventTimes, SORT_NUMERIC);
    $durationSeconds = max(0, $durationSeconds);
    $candidateStarts = [];
    foreach ($eventTimes as $ts) {
        $date = date('Y-m-d', $ts);
        $sameDayStart = strtotime($date . ' ' . $anchorTime . ':00');
        $prevDayStart = strtotime('-1 day', $sameDayStart);
        if ($sameDayStart !== false) {
            $candidateStarts[$sameDayStart] = true;
        }
        if ($prevDayStart !== false) {
            $candidateStarts[$prevDayStart] = true;
        }
    }

    $starts = array_keys($candidateStarts);
    sort($starts, SORT_NUMERIC);
    $maxCount = 0;
    $maxStartTs = null;
    $maxEndTs = null;
    $maxTimes = 0;
    $left = 0;
    $right = 0;
    $n = count($eventTimes);

    foreach ($starts as $startTs) {
        $endTs = $startTs + $durationSeconds;
        while ($left < $n && $eventTimes[$left] < $startTs) {
            $left++;
        }
        if ($right < $left) {
            $right = $left;
        }
        while ($right < $n && $eventTimes[$right] <= $endTs) {
            $right++;
        }
        $count = $right - $left;

        if ($count > $maxCount) {
            $maxCount = $count;
            $maxStartTs = $startTs;
            $maxEndTs = $endTs;
            $maxTimes = 1;
        } elseif ($count === $maxCount && $count > 0) {
            $maxStartTs = $startTs;
            $maxEndTs = $endTs;
            $maxTimes++;
        }
    }

    return [
        'count' => $maxCount,
        'date' => $maxStartTs === null ? '' : date('Y-m-d', $maxStartTs),
        'end_date' => $maxEndTs === null ? '' : date('Y-m-d', $maxEndTs),
        'start_at' => $maxStartTs === null ? '' : date('Y-m-d H:i', $maxStartTs),
        'end_at' => $maxEndTs === null ? '' : date('Y-m-d H:i', $maxEndTs),
        'times' => $maxTimes,
    ];
}

function csvReadMatches(string $csvPath, callable $onMatch): void {
    if (!is_readable($csvPath) || ($fh = fopen($csvPath, 'r')) === false) {
        return;
    }
    $hdrs = fgetcsv($fh);
    if (!is_array($hdrs)) {
        fclose($fh);
        return;
    }

    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($hdrs)) {
            continue;
        }
        $raw = array_combine($hdrs, $row);
        if (!$raw) {
            continue;
        }
        $home = trim($raw['home_team'] ?? '');
        $away = trim($raw['away_team'] ?? '');
        if ($home === '' || $away === '') {
            continue;
        }
        $dt = $raw['match_time'] ?? '';
        $onMatch([
            'date' => substr($dt, 0, 10),
            'time' => substr($dt, 11, 5),
            'home' => $home,
            'away' => $away,
            'league' => trim($raw['league'] ?? ''),
            'fh_home' => $raw['fh_home'] ?? '',
            'fh_away' => $raw['fh_away'] ?? '',
            'ft_home' => $raw['ft_home'] ?? '',
            'ft_away' => $raw['ft_away'] ?? '',
        ]);
    }
    fclose($fh);
}

$mktParam = $_GET['under'] ?? '0.5';
if (!array_key_exists($mktParam, $marketOptions)) {
    $mktParam = '0.5';
}

if (!array_key_exists('under', $_GET)) {
    $mktParam = '0.5';
}

// -- Hidden leagues config -----------------------------------------------------
$hiddenLeaguesConfig = __DIR__ . '/config_hidden_leagues.php';
$hiddenLeaguesRaw = is_file($hiddenLeaguesConfig) ? (require $hiddenLeaguesConfig) : [];
$hiddenLeagues = [];
if (is_array($hiddenLeaguesRaw)) {
    foreach ($hiddenLeaguesRaw as $lg) {
        $name = trim((string)$lg);
        if ($name !== '') {
            $hiddenLeagues[$name] = true;
        }
    }
}

// -- Default date ---------------------------------------------------------------
$csvPath   = __DIR__ . '/matches.csv';
$_csvDefaultDate = date('Y-m-d');

$dateRange   = $_GET['date_range'] ?? '';
$dateFromRaw = $_GET['date_from'] ?? '';
$dateToRaw   = $_GET['date_to'] ?? '';

if ($dateRange !== '') {
    $parts = explode(' to ', $dateRange);
    $dateFromRaw = trim($parts[0] ?? '');
    $dateToRaw = trim($parts[1] ?? $dateFromRaw);
} elseif ($dateFromRaw !== '' && $dateToRaw !== '') {
    $dateRange = $dateFromRaw === $dateToRaw ? $dateFromRaw : $dateFromRaw . ' to ' . $dateToRaw;
}

$dateFromValid = $dateFromRaw !== '' && strtotime($dateFromRaw) !== false;
$dateToValid = $dateToRaw !== '' && strtotime($dateToRaw) !== false;
$hasDateFromInput = ($dateRange !== '') || (array_key_exists('date_from', $_GET) && trim((string)$_GET['date_from']) !== '');
$hasDateToInput = ($dateRange !== '') || (array_key_exists('date_to', $_GET) && trim((string)$_GET['date_to']) !== '');

// -- Filters -------------------------------------------------------------------
$today      = date('Y-m-d');
$dateFrom   = $dateFromValid ? $dateFromRaw : $_csvDefaultDate;
$dateTo     = $dateToValid ? $dateToRaw : $_csvDefaultDate;
$timeFrom   = csvNormalizeTime($_GET['time_from'] ?? '00:00', '00:00');
$timeTo     = csvNormalizeTime($_GET['time_to']   ?? '23:59', '23:59');
$lgFilter   = trim($_GET['league'] ?? '');
$searchTerm = array_key_exists('search', $_GET) ? trim((string)$_GET['search']) : '';
$sortCol    = $_GET['sort']  ?? 'hits_ratio';
$sortOrder  = $_GET['order'] ?? 'desc';
$pg         = max(1, (int)($_GET['pg'] ?? 1));
$perPageOpt = [25, 50, 100, 200];
$perPageRaw = (int)($_GET['per_page'] ?? 50);
$perPage    = in_array($perPageRaw, $perPageOpt) ? $perPageRaw : 50;
$showNearAllTimeMax = false; // Disabled sementara waktu

if (!in_array($sortCol, ['team', 'under_count', 'max_count', 'max_date', 'hits_ratio'], true)) {
    $sortCol = 'hits_ratio';
}
if (!in_array($sortOrder, ['asc', 'desc'], true)) {
    $sortOrder = 'desc';
}

if (!strtotime($dateFrom)) {
    $dateFrom = $_csvDefaultDate;
}
if (!strtotime($dateTo)) {
    $dateTo = $_csvDefaultDate;
}
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
$rangeDays = csvDateSpanDays($dateFrom, $dateTo);
$useContinuousWindow = $dateFrom !== $dateTo && $timeFrom === $timeTo;
$rangeStartTs = csvDateTimeTimestamp($dateFrom, $timeFrom) ?? time();
$rangeEndTs = csvDateTimeTimestamp($dateTo, $timeTo) ?? $rangeStartTs;
if ($rangeEndTs < $rangeStartTs) {
    $rangeEndTs = strtotime('+1 day', $rangeEndTs);
}
$rangeDurationSeconds = max(0, $rangeEndTs - $rangeStartTs);

// -- Single pass CSV scan: track all markets simultaneously to optimize performance -
$_allMkts = array_keys($marketOptions);
$_mmAllTime   = []; // mkt => key => ['count','date']
$_mmDaily     = []; // mkt => key => [date => count]
$_mmPeriod    = []; // mkt => key => ['count','date']
$_mmPeriodDly = []; // mkt => key => [date => count]
$_mmPeriodTotal = []; // mkt => key => total count across the selected range
$_mmEvents = []; // mkt => key => scored match timestamps for continuous same-time windows
foreach ($_allMkts as $_mk) {
    $_mmAllTime[$_mk] = [];
    $_mmDaily[$_mk]   = [];
    $_mmPeriod[$_mk]  = [];
    $_mmPeriodDly[$_mk] = [];
    $_mmPeriodTotal[$_mk] = [];
    $_mmEvents[$_mk] = [];
}

$nextMatch       = [];  // key => match
$lastMatch       = [];  // key => last completed match with score
$inRange         = [];  // key => ['team','league','under_count']
$clubSet         = [];
$leagueSet       = [];
$clubNameSet     = [];
$csvMinDate      = null;
$csvMaxDate      = null;
$csvDatesWithData = [];

csvReadMatches($csvPath, function(array $m) use (
    $lgFilter,
    $today,
    $mktParam,
    $hiddenLeagues,
    $dateFrom,
    $dateTo,
    $timeFrom,
    $timeTo,
    $useContinuousWindow,
    $rangeStartTs,
    $rangeEndTs,
    $_allMkts,
    &$_mmAllTime,
    &$_mmDaily,
    &$_mmPeriod,
    &$_mmPeriodDly,
    &$_mmPeriodTotal,
    &$_mmEvents,
    &$nextMatch,
    &$lastMatch,
    &$inRange,
    &$clubSet,
    &$leagueSet,
    &$clubNameSet,
    &$csvMinDate,
    &$csvMaxDate,
    &$csvDatesWithData
): void {
    // If the active market is 2.5 and this is a hidden league, skip general tracking but still process other markets
    $isMkt25Hidden = ($mktParam === '2.5' && isset($hiddenLeagues[$m['league']]));

    $hKey = $m['home'].'|'.$m['league'];
    $aKey = $m['away'].'|'.$m['league'];
    $matchTs = csvDateTimeTimestamp($m['date'], $m['time']);
    $inSelectedTime = csvTimeInRange($m['time'], $timeFrom, $timeTo);
    $inSelectedPeriod = $useContinuousWindow
        ? csvTimestampInRange($matchTs, $rangeStartTs, $rangeEndTs)
        : ($m['date'] >= $dateFrom && $m['date'] <= $dateTo && $inSelectedTime);

    // 1. Process all-time and period stats for all markets (equivalent to scan #2)
    $hasFT = csvHasFT($m);
    if ($hasFT) {
        if (!($lgFilter && $m['league'] !== $lgFilter)) {
            foreach ($_allMkts as $_mk) {
                // If it is 2.5 market and the league is hidden, skip it
                if ($_mk === '2.5' && isset($hiddenLeagues[$m['league']])) continue;
                if (!csvCheckMarket($m, $_mk) || (!$useContinuousWindow && !$inSelectedTime)) continue;
                
                foreach ([$hKey, $aKey] as $key) {
                    csvBumpDailyMax($_mmDaily[$_mk], $_mmAllTime[$_mk], $key, $m['date']);
                    if ($useContinuousWindow && $matchTs !== null) {
                        $_mmEvents[$_mk][$key][] = $matchTs;
                    }
                    if ($inSelectedPeriod) {
                        csvBumpDailyMax($_mmPeriodDly[$_mk], $_mmPeriod[$_mk], $key, $m['date']);
                        $_mmPeriodTotal[$_mk][$key] = ($_mmPeriodTotal[$_mk][$key] ?? 0) + 1;
                    }
                }
            }
        }
    }

    // 2. If it's a hidden league match under active market 2.5, stop here (do not perform general tracking)
    if ($isMkt25Hidden) {
        return;
    }

    // 3. General tracking (equivalent to scan #1)
    if ($m['league'] !== '') {
        $leagueSet[$m['league']] = true;
    }

    if ($lgFilter && $m['league'] !== $lgFilter) {
        return;
    }

    $clubNameSet[$m['home']] = true;
    $clubNameSet[$m['away']] = true;

    $clubSet[$hKey] = ['team' => $m['home'], 'league' => $m['league']];
    $clubSet[$aKey] = ['team' => $m['away'], 'league' => $m['league']];

    if ($hasFT) {
        if ($csvMinDate === null || $m['date'] < $csvMinDate) $csvMinDate = $m['date'];
        if ($csvMaxDate === null || $m['date'] > $csvMaxDate) $csvMaxDate = $m['date'];
        $csvDatesWithData[$m['date']] = true;

        // Track last played match (any result) by date+time for both teams
        $homeMatchInfo = ['vs_home' => $m['away'], 'vs_away' => $m['home'], 'date' => $m['date'], 'time' => $m['time'], 'ft_home' => $m['ft_home'], 'ft_away' => $m['ft_away'], 'fh_home' => $m['fh_home'], 'fh_away' => $m['fh_away']];
        $awayMatchInfo = ['vs_home' => $m['home'], 'vs_away' => $m['away'], 'date' => $m['date'], 'time' => $m['time'], 'ft_home' => $m['ft_home'], 'ft_away' => $m['ft_away'], 'fh_home' => $m['fh_home'], 'fh_away' => $m['fh_away']];
        
        if (!isset($lastMatch[$hKey]) || ($m['date'].$m['time']) > ($lastMatch[$hKey]['date'].($lastMatch[$hKey]['time'] ?? ''))) {
            $lastMatch[$hKey] = $homeMatchInfo;
        }
        if (!isset($lastMatch[$aKey]) || ($m['date'].$m['time']) > ($lastMatch[$aKey]['date'].($lastMatch[$aKey]['time'] ?? ''))) {
            $lastMatch[$aKey] = $awayMatchInfo;
        }

        // Track $inRange count for active market (under_count)
        if ($inSelectedPeriod && csvCheckMarket($m, $mktParam)) {
            $teamsToAdd = [$hKey => $m['home'], $aKey => $m['away']];
            foreach ($teamsToAdd as $key => $team) {
                if (!isset($inRange[$key])) {
                    $inRange[$key] = ['team' => $team, 'league' => $m['league'], 'under_count' => 0];
                }
                $inRange[$key]['under_count']++;
            }
        }
        return;
    }

    if (
        $m['date'] < $today ||
        !$inSelectedPeriod
    ) {
        return;
    }

    $homeNext = ['vs' => $m['away'], 'date' => $m['date'], 'time' => $m['time']];
    $awayNext = ['vs' => $m['home'], 'date' => $m['date'], 'time' => $m['time']];

    if (!isset($nextMatch[$hKey]) || ($m['date'].$m['time']) < ($nextMatch[$hKey]['date'].$nextMatch[$hKey]['time'])) {
        $nextMatch[$hKey] = $homeNext;
    }
    if (!isset($nextMatch[$aKey]) || ($m['date'].$m['time']) < ($nextMatch[$aKey]['date'].$nextMatch[$aKey]['time'])) {
        $nextMatch[$aKey] = $awayNext;
    }
});

$_mmRangeMax = [];
foreach ($_allMkts as $_mk) {
    $_mmRangeMax[$_mk] = [];
    if ($useContinuousWindow) {
        foreach ($_mmEvents[$_mk] as $key => $eventTimes) {
            $_mmRangeMax[$_mk][$key] = csvAnchoredRangeMaxFromEventTimes($eventTimes, $timeFrom, $rangeDurationSeconds);
        }
    } else {
        foreach ($_mmDaily[$_mk] as $key => $dailyCounts) {
            $_mmRangeMax[$_mk][$key] = csvRangeMaxFromDailyCounts($dailyCounts, $rangeDays);
        }
    }
}

// Map active market variables from multi-market structures to preserve downstream logic
$allTimeDailyMkt = $_mmDaily[$mktParam] ?? [];
$allTimeMaxByKey = $_mmRangeMax[$mktParam] ?? [];
$inRangeDailyMkt = $_mmPeriodDly[$mktParam] ?? [];
$periodMaxByKey  = $_mmPeriod[$mktParam] ?? [];
$periodTotalByKey = $_mmPeriodTotal[$mktParam] ?? [];

$leagueList = array_keys($leagueSet);
sort($leagueList);
$clubNameList = array_keys($clubNameSet);
sort($clubNameList, SORT_NATURAL | SORT_FLAG_CASE);

// -- Build final rows -----------------------------------------------------------
$rows = [];
$recordBreakers = [];
$rowSource = $searchTerm ? $clubSet : $inRange;
foreach ($rowSource as $key => $club) {
    $maxCnt = $allTimeMaxByKey[$key]['count'] ?? 0;
    $maxDate = $allTimeMaxByKey[$key]['date'] ?? '';
    $maxEndDate = $allTimeMaxByKey[$key]['end_date'] ?? $maxDate;
    $maxStartAt = $allTimeMaxByKey[$key]['start_at'] ?? '';
    $maxEndAt = $allTimeMaxByKey[$key]['end_at'] ?? '';
    $periodMaxCnt = $periodMaxByKey[$key]['count'] ?? 0;
    $periodMaxDate = $periodMaxByKey[$key]['date'] ?? '';
    $allTimeTotal = array_sum($allTimeDailyMkt[$key] ?? []);

    $periodCnt = $periodTotalByKey[$key] ?? ($inRange[$key]['under_count'] ?? 0);
    if (!$searchTerm && $periodCnt <= 0) {
        continue;
    }

    $isMax = $maxCnt > 0 && $periodCnt >= $maxCnt;

    $rows[] = [
        'team'        => $club['team'],
        'league'      => $club['league'],
        'under_count' => $periodCnt,
        'period_count' => $periodCnt,
        'period_max_count' => $periodMaxCnt,
        'period_max_date' => $periodMaxDate,
        'max_count'   => $maxCnt,
        'all_time_total' => $allTimeTotal,
        'hits_ratio'  => $maxCnt > 0 ? round(($periodCnt / $maxCnt) * 100, 1) : null,
        'max_date'    => $maxDate,
        'max_end_date' => $maxEndDate,
        'max_start_at' => $maxStartAt,
        'max_end_at' => $maxEndAt,
        'is_max'      => $isMax,
        'next_match'  => $nextMatch[$key] ?? null,
        'last_match'  => $lastMatch[$key] ?? null,
    ];

    if ($isMax && $maxCnt > 0) {
        $recordBreakers[] = [
            'team' => $club['team'],
            'league' => $club['league'],
            'under_count' => $periodCnt,
            'period_count' => $periodCnt,
            'period_max_count' => $periodMaxCnt,
            'period_max_date' => $periodMaxDate,
            'max_count' => $maxCnt,
            'all_time_total' => $allTimeTotal,
            'hits_ratio' => $maxCnt > 0 ? round(($periodCnt / $maxCnt) * 100, 1) : null,
            'max_date' => $maxDate,
            'max_end_date' => $maxEndDate,
            'max_start_at' => $maxStartAt,
            'max_end_at' => $maxEndAt,
            'is_max' => $isMax,
            'next_match' => $nextMatch[$key] ?? null,
            'last_match' => $lastMatch[$key] ?? null,
        ];
    }
}

if ($searchTerm) {
    $searchLower = mb_strtolower($searchTerm, 'UTF-8');
    $rows = array_values(array_filter($rows, fn($r) => 
        mb_strpos(mb_strtolower($r['team'], 'UTF-8'), $searchLower) !== false ||
        mb_strpos(mb_strtolower($r['league'], 'UTF-8'), $searchLower) !== false
    ));
    $recordBreakers = array_values(array_filter($recordBreakers, fn($r) => 
        mb_strpos(mb_strtolower($r['team'], 'UTF-8'), $searchLower) !== false ||
        mb_strpos(mb_strtolower($r['league'], 'UTF-8'), $searchLower) !== false
    ));
}

if (!$searchTerm) {
    // Filter: U0.5 shows all with max >= 1; others require hits/max >= 60%
    if ($mktParam === '0.5') {
        $rows = array_values(array_filter($rows, fn($r) => ($r['max_count'] ?? 0) >= 1 && ($r['under_count'] ?? 0) >= ($r['max_count'] ?? 0) - 2 && ($r['hits_ratio'] ?? 0) > 50));
    } else {
        $rows = array_values(array_filter($rows, fn($r) => ($r['hits_ratio'] ?? 0) >= 60));
    }
}

// Sort
usort($rows, function($a, $b) use ($sortCol, $sortOrder) {
    $cmp = match($sortCol) {
        'team'      => strcmp($a['team'], $b['team']),
        'max_count' => $a['max_count'] <=> $b['max_count'],
        'hits_ratio' => ($a['hits_ratio'] ?? -1) <=> ($b['hits_ratio'] ?? -1),
        'max_date'  => strcmp($a['max_date'], $b['max_date']),
        default     => $a['under_count'] <=> $b['under_count'],
    };
    if ($cmp === 0) $cmp = strcmp($a['team'], $b['team']);
    return $sortOrder === 'asc' ? $cmp : -$cmp;
});

$totalClubs = count($rows);
$totalPages = max(1, (int)ceil($totalClubs / $perPage));
$pg         = min($pg, $totalPages);
$offset     = ($pg - 1) * $perPage;
$pageRows   = array_slice($rows, $offset, $perPage);


$allTimeMaxMultiMarket = [];
foreach ($_allMkts as $_mk) {
    foreach ($_mmRangeMax[$_mk] as $key => $atm) {
        if (($atm['count'] ?? 0) <= 0) {
            continue;
        }
        $pm = $_mmPeriod[$_mk][$key] ?? ['count' => 0, 'date' => ''];
        $periodCount = $_mmPeriodTotal[$_mk][$key] ?? 0;
        $minPeriodCount = $showNearAllTimeMax ? $atm['count'] - 1 : $atm['count'];
        if ($periodCount > 0 && $periodCount >= $minPeriodCount && isset($nextMatch[$key])) {
            [$team, $league] = explode('|', $key, 2);
            $allTimeMaxMultiMarket[] = [
                'team'             => $team,
                'league'           => $league,
                'market'           => $_mk,
                'max_count'        => $atm['count'],
                'max_date'         => $atm['date'],
                'max_end_date'     => $atm['end_date'] ?? $atm['date'],
                'max_start_at'     => $atm['start_at'] ?? '',
                'max_end_at'       => $atm['end_at'] ?? '',
                'max_times'        => $atm['times'] ?? 0,
                'period_count'     => $periodCount,
                'period_max_count' => $pm['count'],
                'period_max_date'  => $pm['date'],
                'next_match'       => $nextMatch[$key] ?? null,
                'last_match'       => $lastMatch[$key] ?? null,
            ];
        }
    }
}
usort($allTimeMaxMultiMarket, function($a, $b) {
    $aEqual = ($a['period_count'] >= $a['max_count']);
    $bEqual = ($b['period_count'] >= $b['max_count']);
    if ($aEqual !== $bEqual) {
        return $aEqual ? -1 : 1;
    }
    if ($b['max_count'] !== $a['max_count']) {
        return $b['max_count'] <=> $a['max_count'];
    }
    return strcmp($a['team'], $b['team']);
});

// Helper: build URL preserving all current GET params
function csvUrl(array $extra = []): string {
    $allowedKeys = ['page', 'search', 'date_range', 'date_from', 'date_to', 'time_from', 'time_to', 'league', 'under', 'sort', 'order', 'pg', 'per_page', 'show_near_all_time_max'];
    $params = ['page' => 'clubs'];

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $_GET)) {
            continue;
        }

        $value = $_GET[$key];
        if (is_array($value)) {
            continue;
        }

        $params[$key] = (string)$value;
    }

    if (array_key_exists('date_from', $extra) || array_key_exists('date_to', $extra)) {
        unset($params['date_range']);
    }
    if (array_key_exists('date_range', $extra)) {
        unset($params['date_from'], $params['date_to']);
    }

    $params = array_merge($params, $extra);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return 'index.php?' . http_build_query($params);
}
function csvSortUrl(string $col, string $cur, string $curOrder): string {
    $o = ($cur === $col && $curOrder === 'desc') ? 'asc' : 'desc';
    return csvUrl(['sort' => $col, 'order' => $o, 'pg' => 1]);
}
function csvFormatRatio(?float $ratio): string {
    if ($ratio === null) {
        return '-';
    }

    return rtrim(rtrim(number_format($ratio, 1, '.', ''), '0'), '.') . '%';
}
function csvRatioBadgeClass(?float $ratio): string {
    if ($ratio === null) {
        return 'bg-slate-100 text-slate-500';
    }
    if ($ratio >= 100) {
        return 'bg-emerald-100 text-emerald-700';
    }
    if ($ratio >= 75) {
        return 'bg-blue-100 text-blue-700';
    }
    if ($ratio >= 50) {
        return 'bg-amber-100 text-amber-700';
    }

    return 'bg-rose-100 text-rose-700';
}

function csvShortDate(?string $date, string $format = 'd/m/y'): string {
    if (!$date || strtotime($date) === false) {
        return '-';
    }

    return date($format, strtotime($date));
}

function csvShortDateRange(?string $start, ?string $end, string $format = 'd/m/y'): string {
    if (!$start || strtotime($start) === false) {
        return '-';
    }
    if (!$end || $end === $start || strtotime($end) === false) {
        return csvShortDate($start, $format);
    }
    return csvShortDate($start, $format) . ' - ' . csvShortDate($end, $format);
}

function csvShortDateTimeRange(?string $startAt, ?string $endAt): string {
    $startTs = $startAt ? strtotime($startAt) : false;
    if ($startTs === false) {
        return '-';
    }
    $endTs = $endAt ? strtotime($endAt) : false;
    if ($endTs === false || $endTs === $startTs) {
        return date('d/m/y H:i', $startTs);
    }
    return date('d/m/y H:i', $startTs) . ' - ' . date('d/m/y H:i', $endTs);
}

function csvRecordWindowText(array $row, string $dateFormat = 'd/m/y'): string {
    if (($row['max_start_at'] ?? '') !== '') {
        return csvShortDateTimeRange($row['max_start_at'], $row['max_end_at'] ?? null);
    }
    return csvShortDateRange($row['max_date'] ?? null, $row['max_end_date'] ?? ($row['max_date'] ?? null), $dateFormat);
}

function csvDaysSince(?string $date): string {
    if (!$date || strtotime($date) === false) return '';
    $days = (int)floor((time() - strtotime($date)) / 86400);
    return $days > 0 ? $days.' hari' : 'hari ini';
}

function csvMatchScoreText(?array $match): string {
    if (!$match) {
        return '-';
    }

    return $match['vs_home'].' '.$match['ft_home'].'-'.$match['ft_away'].' '.$match['vs_away'];
}

function csvNextMatchText(?array $match): string {
    if (!$match) {
        return '-';
    }

    return $match['vs'].' - '.csvShortDate($match['date'], 'd/m').' '.$match['time'];
}

function csvDisplayTimeMinusOneHour(string $date, string $time): string {
    $timestamp = strtotime(trim($date.' '.$time));
    if ($timestamp === false) {
        return $time;
    }

    return date('H:i', $timestamp - 3600);
}

$mktLabel = $marketOptions[$mktParam]['label'];
$mktShort = $marketOptions[$mktParam]['short'];
$mktClass = $marketOptions[$mktParam]['class'];
$recordRangeLabel = $useContinuousWindow
    ? 'Record ' . max(1, (int)ceil($rangeDurationSeconds / 3600)) . ' Jam ' . $timeFrom . '-' . $timeTo
    : (($rangeDays > 1 ? 'Record '.$rangeDays.' Hari' : 'Record Harian') . ' ' . $timeFrom . '-' . $timeTo);
?>
<div class="p-3 sm:p-4 md:p-8 space-y-4 md:space-y-6 page-fade-in">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Broadcast Header -->
    <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-4 md:p-6 shadow-xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.2em] text-amber-300 font-bold">Club Analytics</p>
                <h1 class="text-2xl md:text-3xl font-black tracking-tight">
                    Club <span class="text-amber-300">Record</span>
                </h1>
                <p class="text-slate-300 text-sm md:text-base">Analisis performa club berdasarkan market <?= htmlspecialchars($mktLabel) ?>.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/15 border border-emerald-400/30">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-200">Active</span>
                </div>
                <div class="px-3 py-2 rounded-lg bg-slate-700/70 border border-slate-600 text-xs font-bold text-slate-200"><?= date('d M Y') ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <form id="club-filter-form" method="GET" autocomplete="off" class="club-filter-card bg-white rounded-2xl p-4 md:p-5 transition-all">
        <input type="hidden" name="page" value="clubs">
        
        <div class="club-filter-top">
            <label for="club-search" class="sr-only">Cari club</label>
            <div class="relative min-w-0">
                <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input id="club-search" type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Cari club..." autocomplete="off"
                    class="club-filter-search-input">
                <div id="club-search-dropdown" class="absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-lg z-50 hidden max-h-60 overflow-y-auto"></div>
            </div>
            <div class="club-filter-actions">
                <?php
                $today = date('Y-m-d');
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                $weekEnd = date('Y-m-d', strtotime('sunday this week'));
                ?>
                <a href="<?= htmlspecialchars(csvUrl(['date_from' => $today, 'date_to' => $today, 'pg' => 1])) ?>" 
                   class="club-filter-action <?= $dateFrom === $today && $dateTo === $today ? 'club-filter-action-active' : 'club-filter-action-muted' ?>">
                    Today
                </a>
                <a href="<?= htmlspecialchars(csvUrl(['date_from' => $weekStart, 'date_to' => $weekEnd, 'pg' => 1])) ?>" 
                   class="club-filter-action <?= $dateFrom === $weekStart && $dateTo === $weekEnd ? 'club-filter-action-active' : 'club-filter-action-muted' ?>">
                    This Week
                </a>
                <a href="<?= htmlspecialchars(csvUrl(['date_from' => '', 'date_to' => '', 'league' => '', 'under' => '0.5', 'pg' => 1])) ?>" 
                   class="club-filter-action club-filter-action-muted">
                    Reset
                </a>
            </div>
        </div>
        
        <div class="club-filter-grid mt-3">
            <div class="club-filter-field">
                <label for="club-time-from" class="club-filter-label">Jam Mulai</label>
                <div class="club-filter-select-wrap">
                    <select id="club-time-from" name="time_from" autocomplete="off" class="club-filter-control club-filter-select">
                        <?php
                        $timeSlots = [];
                        for ($h = 0; $h < 24; $h++) {
                            for ($m = 0; $m < 60; $m += 30) {
                                $hh = str_pad($h, 2, '0', STR_PAD_LEFT);
                                $mm = str_pad($m, 2, '0', STR_PAD_LEFT);
                                $val = "$hh:$mm";
                                $timeSlots[] = $val;
                            }
                        }
                        $timeSlots[] = '23:59';
                        foreach ($timeSlots as $slot):
                        ?>
                            <option value="<?= $slot ?>" <?= $timeFrom === $slot ? 'selected' : '' ?>><?= $slot ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="club-filter-select-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="club-filter-field">
                <label for="club-time-to" class="club-filter-label">Jam Selesai</label>
                <div class="club-filter-select-wrap">
                    <select id="club-time-to" name="time_to" autocomplete="off" class="club-filter-control club-filter-select">
                        <?php
                        $timeSlotsTo = [];
                        for ($h = 0; $h < 24; $h++) {
                            for ($m = 0; $m < 60; $m += 30) {
                                $hh = str_pad($h, 2, '0', STR_PAD_LEFT);
                                $mm = str_pad($m, 2, '0', STR_PAD_LEFT);
                                $val = "$hh:$mm";
                                $timeSlotsTo[] = $val;
                            }
                        }
                        $timeSlotsTo[] = '23:59';
                        foreach ($timeSlotsTo as $slot):
                        ?>
                            <option value="<?= $slot ?>" <?= $timeTo === $slot ? 'selected' : '' ?>><?= $slot ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="club-filter-select-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="club-filter-field" style="grid-column: span 2;">
                <label for="club-date-range" class="club-filter-label">Rentang Tanggal</label>
                <input id="club-date-range" type="text" name="date_range" value="<?= htmlspecialchars($dateRange) ?>" class="club-filter-control" placeholder="Pilih rentang tanggal...">
            </div>
            <div class="club-filter-field">
                <label for="club-market" class="club-filter-label">Market</label>
                <div class="club-filter-select-wrap">
                    <select id="club-market" name="under" class="club-filter-control club-filter-select">
                        <?php foreach ($marketOptions as $val => $opt): ?>
                            <option value="<?= $val ?>" <?= $mktParam === $val ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="club-filter-select-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="club-filter-field">
                <label for="club-league" class="club-filter-label">Liga</label>
                <div class="club-filter-select-wrap">
                    <select id="club-league" name="league" class="club-filter-control club-filter-select">
                        <option value="">Semua Liga</option>
                        <?php foreach ($leagueList as $lg): ?>
                            <option value="<?= htmlspecialchars($lg) ?>" <?= $lgFilter === $lg ? 'selected' : '' ?>><?= htmlspecialchars($lg) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="club-filter-select-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <button type="submit" class="club-filter-submit">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                Filter
            </button>
        </div>
        
        <div class="club-filter-extra">
            <div class="flex items-center gap-2 opacity-50 cursor-not-allowed">
                <input type="checkbox" name="show_near_all_time_max" value="1" id="show_near_all_time_max" disabled class="club-filter-checkbox cursor-not-allowed">
                <label for="show_near_all_time_max" class="text-sm text-slate-600 cursor-not-allowed">Tampilkan juga All-Time Max - 1 (Disabled sementara)</label>
            </div>
        </div>
    </form>

    <!-- Date Coverage Monitor -->
    <?php
    $gapDates = [];
    if ($csvMinDate && $csvMaxDate) {
        $cursor = new DateTime($csvMinDate);
        $end    = new DateTime($csvMaxDate);
        while ($cursor <= $end) {
            $d = $cursor->format('Y-m-d');
            if (!isset($csvDatesWithData[$d])) {
                $gapDates[] = $d;
            }
            $cursor->modify('+1 day');
        }
    }
    $totalDays = $csvMinDate && $csvMaxDate
        ? (new DateTime($csvMinDate))->diff(new DateTime($csvMaxDate))->days + 1
        : 0;
    $daysWithData = count($csvDatesWithData);
    $daysGap      = count($gapDates);
    // Group gap dates by month
    $gapByMonth = [];
    foreach ($gapDates as $gd) {
        $month = substr($gd, 0, 7); // YYYY-MM
        $gapByMonth[$month][] = $gd;
    }
    ?>
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
        <button type="button" onclick="this.nextElementSibling.classList.toggle('hidden')"
            class="w-full grid gap-3 px-4 py-3 text-left hover:bg-slate-50 transition-colors md:grid-cols-[1fr_auto] md:items-center">
            <div class="flex flex-wrap items-center gap-2 md:gap-3">
                <span class="text-sm font-bold text-slate-700 uppercase tracking-wide">Monitoring Tanggal Data CSV</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold
                    <?= $daysGap > 0 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' ?>">
                    <?= $daysGap > 0 ? $daysGap.' tanggal kosong' : 'Lengkap' ?>
                </span>
            </div>
            <div class="flex items-center justify-between gap-4 text-xs text-slate-400 md:justify-end">
                <span><?= $csvMinDate ?? '-' ?> &rarr; <?= $csvMaxDate ?? '-' ?></span>
                <span class="text-slate-300">&#x25BC;</span>
            </div>
        </button>
        <div class="hidden border-t border-slate-100">
            <div class="grid gap-2 px-4 py-3 text-xs text-slate-500 border-b border-slate-100 bg-slate-50 md:grid-cols-3">
                <div class="rounded-xl bg-white border border-slate-200 px-3 py-2">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Rentang</span>
                    <strong class="text-lg text-slate-800"><?= $totalDays ?></strong> hari
                </div>
                <div class="rounded-xl bg-white border border-emerald-100 px-3 py-2">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-emerald-500">Ada Data</span>
                    <strong class="text-lg text-emerald-600"><?= $daysWithData ?></strong> hari
                </div>
                <div class="rounded-xl bg-white border border-amber-100 px-3 py-2">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-amber-500">Tanggal Kosong</span>
                    <strong class="text-lg text-amber-600"><?= $daysGap ?></strong> hari
                </div>
            </div>
            <?php if ($daysGap === 0): ?>
            <div class="px-4 py-6 text-center text-sm text-emerald-600 font-medium">
                Semua tanggal dalam rentang <?= htmlspecialchars($csvMinDate ?? '') ?> &ndash; <?= htmlspecialchars($csvMaxDate ?? '') ?> sudah ada datanya.
            </div>
            <?php else: ?>
            <div class="px-4 py-3 space-y-3 max-h-72 overflow-y-auto">
                <?php foreach ($gapByMonth as $month => $dates): ?>
                <div class="rounded-xl border border-slate-100 bg-white p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?= htmlspecialchars($month) ?></div>
                        <div class="text-[10px] font-semibold text-amber-600"><?= count($dates) ?> gap</div>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($dates as $gd): ?>
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-amber-50 text-amber-700 border border-amber-200">
                            <?= htmlspecialchars($gd) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- All-Time Max Table (All Markets) -->
    <?php if ($allTimeMaxMultiMarket): ?>
    <div class="bg-white rounded-2xl shadow-md border-0 overflow-hidden">
        <div class="px-4 md:px-5 py-4 bg-indigo-600 text-white flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                <span class="text-sm font-bold uppercase tracking-wide">All-Time Max</span>
                <span class="text-xs text-indigo-200 bg-indigo-700/50 px-2 py-1 rounded-lg">All Markets</span>
            </div>
            <span class="text-xs text-indigo-100"><?= count($allTimeMaxMultiMarket) ?> entries</span>
        </div>
        <div class="grid gap-3 p-3 md:hidden">
            <?php foreach ($allTimeMaxMultiMarket as $i => $r):
                $_rmkt = $marketOptions[$r['market']] ?? ['short'=>$r['market'],'class'=>'bg-slate-500 text-white'];
                $isHidden = $i >= 10;
            ?>
            <article class="rounded-xl border p-3 <?= $r['period_count'] >= $r['max_count'] ? 'border-emerald-200 bg-emerald-50' : 'border-indigo-100 bg-indigo-50/50' ?> all-time-max-hidden" <?= $isHidden ? 'style="display: none;"' : '' ?>>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black text-indigo-600">#<?= $i + 1 ?></span>
                            <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?= $_rmkt['class'] ?>"><?= htmlspecialchars($_rmkt['short']) ?></span>
                        </div>
                        <h2 class="mt-2 text-base font-black text-slate-900"><?= htmlspecialchars($r['team']) ?></h2>
                        <p class="mt-0.5 text-[10px] uppercase tracking-wide text-slate-500"><?= htmlspecialchars($r['league']) ?></p>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">All-Time Max</div>
                        <div class="text-xl font-black text-indigo-600"><?= $r['period_count'] ?></div>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-lg bg-white p-2">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Tgl Max</span>
                        <strong class="block text-slate-700"><?= htmlspecialchars(csvRecordWindowText($r)) ?></strong>
                        <?php if ($r['max_date']): ?><span class="text-[10px] text-slate-400"><?= csvDaysSince($r['max_date']) ?></span><?php endif; ?>
                    </div>
                    <div class="rounded-lg bg-white p-2">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= htmlspecialchars($recordRangeLabel) ?></span>
                        <strong class="block text-slate-700"><?= $r['max_count'] ?><?= ($r['max_times'] ?? 0) > 1 ? ' <span class="text-[10px] font-normal opacity-60">('.$r['max_times'].'x)</span>' : '' ?></strong>
                        <?php if (($r['period_max_count'] ?? 0) > 0): ?><span class="text-[10px] text-slate-400">Max harian range <?= $r['period_max_count'] ?></span><?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <div class="hidden overflow-x-auto md:block">
        <table class="min-w-[900px] w-full text-xs">
            <thead class="bg-indigo-50 text-indigo-900 sticky top-0 z-10">
                <tr>
                    <th class="px-4 py-3 text-left font-bold">#</th>
                    <th class="px-4 py-3 text-left font-bold">Club</th>
                    <th class="px-4 py-3 text-center font-bold">Market</th>
                    <th class="px-4 py-3 text-center font-bold">All-Time Max</th>
                    <th class="px-4 py-3 text-center font-bold"><?= htmlspecialchars($recordRangeLabel) ?></th>
                    <th class="px-4 py-3 text-center font-bold">Tgl Max</th>
                    <th class="px-4 py-3 text-center font-bold">Last Match</th>
                    <th class="px-4 py-3 text-center font-bold">Next Match</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($allTimeMaxMultiMarket as $i => $r):
                $_rmkt = $marketOptions[$r['market']] ?? ['short'=>$r['market'],'class'=>'bg-slate-500 text-white'];
                $isHidden = $i >= 10;
            ?>
                <tr class="hover:bg-indigo-50/30 transition-all <?= $r['period_count'] >= $r['max_count'] ? 'bg-emerald-50' : '' ?> all-time-max-hidden" <?= $isHidden ? 'style="display: none;"' : '' ?>>
                    <td class="px-4 py-3 text-slate-400 font-medium"><?= $i + 1 ?></td>
                    <td class="px-4 py-3 min-w-[220px]">
                        <div class="font-bold text-slate-900"><?= htmlspecialchars($r['team']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= htmlspecialchars($r['league']) ?></div>
                    </td>
                    <td class="px-4 py-3 text-center"><span class="px-2 py-1 rounded-lg text-[10px] font-bold <?= $_rmkt['class'] ?>"><?= htmlspecialchars($_rmkt['short']) ?></span></td>
                    <td class="px-4 py-3 text-center"><span class="px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 font-black text-sm"><?= $r['period_count'] ?></span></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-3 py-1 rounded-full bg-blue-100 text-blue-700 font-black text-sm"><?= $r['max_count'] ?><?= ($r['max_times'] ?? 0) > 1 ? ' <span class="text-[10px] font-normal opacity-70">('.$r['max_times'].'x)</span>' : '' ?></span>
                        <?php if (($r['period_max_count'] ?? 0) > 0): ?><div class="mt-1 text-[10px] text-slate-400">Max harian range <?= $r['period_max_count'] ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-600 font-medium">
                        <?= htmlspecialchars(csvRecordWindowText($r)) ?>
                        <?php if ($r['max_date']): ?><div class="text-[10px] text-slate-400"><?= csvDaysSince($r['max_date']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-600 <?= ($r['last_match'] ?? null) ? 'bg-sky-50/70 border-l border-sky-100' : '' ?>">
                        <?php if ($r['last_match'] ?? null): ?>
                            <div class="inline-block rounded-lg px-2 py-1">
                            <div class="text-[10px] font-bold text-slate-800"><?= htmlspecialchars(csvMatchScoreText($r['last_match'])) ?></div>
                            <div class="text-[10px] text-slate-500">(HT <?= $r['last_match']['fh_home'].'-'.$r['last_match']['fh_away'] ?>)</div>
                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars(csvShortDate($r['last_match']['date'])) ?></div>
                            </div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-600 <?= $r['next_match'] ? 'bg-amber-50/80 border-l border-amber-100' : '' ?>">
                        <?php if ($r['next_match']): ?>
                            <div class="inline-block rounded-lg px-2 py-1">
                            <div class="font-bold text-slate-900 text-xs max-w-[120px] truncate mx-auto" title="<?= htmlspecialchars($r['next_match']['vs']) ?>"><?= htmlspecialchars($r['next_match']['vs']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars(csvShortDate($r['next_match']['date'], 'd/m').' '.csvDisplayTimeMinusOneHour($r['next_match']['date'], $r['next_match']['time'])) ?></div>
                            </div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (count($allTimeMaxMultiMarket) > 10): ?>
        <div class="px-5 py-4 border-t border-slate-100 text-center bg-slate-50/50">
            <button type="button" id="btn-toggle-all-time-max" data-count="<?= count($allTimeMaxMultiMarket) - 10 ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold transition-all text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                <span id="btn-toggle-all-time-max-text">Tampilkan Semua (<?= count($allTimeMaxMultiMarket) - 10 ?> Lainnya)</span>
                <svg id="btn-toggle-all-time-max-icon" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Record Breakers -->
    <?php if ($recordBreakers): ?>
    <div class="bg-white rounded-2xl shadow-md border-0 overflow-hidden">
        <div class="px-4 md:px-5 py-4 bg-rose-600 text-white flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span class="text-sm font-bold uppercase tracking-wide">Record Breakers</span>
                <span class="text-xs text-rose-200 bg-rose-700/50 px-2 py-1 rounded-lg"><?= htmlspecialchars($mktLabel) ?></span>
            </div>
            <span class="text-xs text-rose-100"><?= count($recordBreakers) ?> clubs</span>
        </div>
        <div class="grid gap-3 p-3 md:hidden">
            <?php foreach ($recordBreakers as $i => $r): 
                $isHidden = $i >= 10;
            ?>
            <article class="rounded-xl border border-rose-100 bg-rose-50/50 p-3 record-breakers-hidden" <?= $isHidden ? 'style="display: none;"' : '' ?>>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black text-rose-600">#<?= $i + 1 ?></span>
                            <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?= $mktClass ?>"><?= $mktShort ?></span>
                            <span class="px-2 py-1 rounded-full text-[10px] font-black <?= csvRatioBadgeClass($r['hits_ratio']) ?>"><?= htmlspecialchars(csvFormatRatio($r['hits_ratio'])) ?></span>
                        </div>
                        <h2 class="mt-2 text-base font-black text-slate-900"><?= htmlspecialchars($r['team']) ?></h2>
                        <p class="mt-0.5 text-[10px] uppercase tracking-wide text-slate-500"><?= htmlspecialchars($r['league']) ?></p>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">All-Time Max</div>
                        <div class="text-xl font-black text-rose-600"><?= $r['period_count'] ?>/<?= $r['max_count'] ?></div>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-lg bg-white p-2">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Last</span>
                        <strong class="block text-slate-700"><?= htmlspecialchars(csvMatchScoreText($r['last_match'] ?? null)) ?></strong>
                        <?php if ($r['last_match'] ?? null): ?>
                            <span class="text-slate-400"><?= htmlspecialchars(csvShortDate($r['last_match']['date'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-lg bg-white p-2">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Next</span>
                        <strong class="block text-slate-700"><?= htmlspecialchars(csvNextMatchText($r['next_match'] ?? null)) ?></strong>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <div class="hidden overflow-x-auto md:block">
        <table class="min-w-[980px] w-full text-xs">
            <thead class="bg-rose-50 text-rose-900 sticky top-0 z-10">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">#</th>
                            <th class="px-4 py-3 text-left font-bold">Market</th>
                            <th class="px-4 py-3 text-left font-bold">Club</th>
                            <th class="px-4 py-3 text-center font-bold">All-Time Max</th>
                            <th class="px-4 py-3 text-center font-bold"><?= htmlspecialchars($recordRangeLabel) ?></th>
                            <th class="px-4 py-3 text-center font-bold">Hits / Max %</th>
                            <th class="px-4 py-3 text-center font-bold">Tgl Max</th>
                            <th class="px-4 py-3 text-center font-bold">Last Match</th>
                            <th class="px-4 py-3 text-center font-bold">Next Match</th>
                        </tr>
                    </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($recordBreakers as $i => $r): 
                $isHidden = $i >= 10;
            ?>
                <tr class="hover:bg-rose-50/30 transition-all record-breakers-hidden" <?= $isHidden ? 'style="display: none;"' : '' ?>>
                    <td class="px-4 py-3 text-slate-400 font-medium"><?= $i + 1 ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-1 rounded-lg text-[10px] font-bold <?= $mktClass ?>"><?= $mktShort ?></span></td>
                    <td class="px-4 py-3 min-w-[220px]">
                        <div class="font-bold text-slate-900"><?= htmlspecialchars($r['team']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= htmlspecialchars($r['league']) ?></div>
                    </td>
                        <td class="px-4 py-3 text-center"><span class="px-3 py-1 rounded-full bg-rose-100 text-rose-700 font-black text-sm"><?= $r['period_count'] ?></span></td>
                        <td class="px-4 py-3 text-center"><span class="px-3 py-1 rounded-full bg-violet-100 text-violet-700 font-black text-sm"><?= $r['max_count'] ?></span></td>
                        <td class="px-4 py-3 text-center text-xs">
                            <span class="px-3 py-1.5 rounded-full text-xs font-black <?= csvRatioBadgeClass($r['hits_ratio']) ?>">
                                <?= htmlspecialchars(csvFormatRatio($r['hits_ratio'])) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-slate-600 font-medium"><?= htmlspecialchars(csvRecordWindowText($r, 'd-m-y')) ?></td>
                        <td class="px-4 py-3 text-center text-slate-600 <?= ($r['last_match'] ?? null) ? 'bg-sky-50/70 border-l border-sky-100' : '' ?>">
                        <?php if ($r['last_match'] ?? null): ?>
                            <div class="inline-block rounded-lg px-2 py-1">
                            <div class="text-[10px] font-bold text-slate-800"><?= htmlspecialchars(csvMatchScoreText($r['last_match'])) ?></div>
                            <div class="text-[10px] text-slate-500">(HT <?= $r['last_match']['fh_home'].'-'.$r['last_match']['fh_away'] ?>)</div>
                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars(csvShortDate($r['last_match']['date'])) ?></div>
                            </div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                        <td class="px-4 py-3 text-center text-slate-600 <?= $r['next_match'] ? 'bg-amber-50/80 border-l border-amber-100' : '' ?>">
                        <?php if ($r['next_match']): ?>
                            <div class="inline-block rounded-lg px-2 py-1">
                            <div class="font-bold text-slate-900 text-xs max-w-[120px] truncate mx-auto" title="<?= htmlspecialchars($r['next_match']['vs']) ?>"><?= htmlspecialchars($r['next_match']['vs']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars(csvShortDate($r['next_match']['date'], 'd/m').' '.$r['next_match']['time']) ?></div>
                            </div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (count($recordBreakers) > 10): ?>
        <div class="px-5 py-4 border-t border-slate-100 text-center bg-slate-50/50">
            <button type="button" id="btn-toggle-record-breakers" data-count="<?= count($recordBreakers) - 10 ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold transition-all text-xs focus:outline-none focus:ring-2 focus:ring-rose-500/50">
                <span id="btn-toggle-record-breakers-text">Tampilkan Semua (<?= count($recordBreakers) - 10 ?> Lainnya)</span>
                <svg id="btn-toggle-record-breakers-icon" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Main Clubs Table -->
    <div class="bg-white rounded-2xl shadow-md border-0 overflow-hidden">
        <!-- Header & Per page -->
        <div class="px-4 md:px-5 py-4 bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span class="text-sm font-bold uppercase tracking-wide">Data Clubs</span>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <?php if ($totalClubs > 0): ?>
                    <span class="text-slate-300"><?= $offset + 1 ?>-<?= min($offset + $perPage, $totalClubs) ?> / <?= $totalClubs ?></span>
                <?php else: ?>
                    <span class="text-slate-300">0 clubs</span>
                <?php endif; ?>
                <span class="text-slate-500">|</span>
                <div class="flex items-center gap-1">
                    <?php foreach ($perPageOpt as $pp): ?>
                        <a href="<?= htmlspecialchars(csvUrl(['per_page' => $pp, 'pg' => 1])) ?>"
                           class="px-2 py-1 rounded-lg text-xs font-bold <?= $perPage === $pp ? 'bg-amber-500 text-slate-900' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' ?>">
                            <?= $pp ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="grid gap-3 p-3 md:hidden">
            <?php if (!$pageRows): ?>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-6 text-center text-sm font-medium text-slate-400">
                    Tidak ada data untuk filter ini.
                </div>
            <?php else: ?>
                <?php foreach ($pageRows as $i => $r): 
                    $isHidden = $i >= 10;
                ?>
                <article class="rounded-xl border <?= $r['is_max'] ? 'border-rose-100 bg-rose-50/40' : 'border-slate-100 bg-white' ?> p-3 shadow-sm main-clubs-hidden" <?= $isHidden ? 'style="display: none;"' : '' ?>>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-black text-slate-400">#<?= $offset + $i + 1 ?></span>
                                <?php if ($r['is_max']): ?>
                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-rose-700">MAX</span>
                                <?php endif; ?>
                            </div>
                            <h2 class="mt-2 text-base font-black text-slate-900"><?= htmlspecialchars($r['team']) ?></h2>
                            <p class="mt-0.5 text-[10px] uppercase tracking-wide text-slate-500"><?= htmlspecialchars($r['league']) ?></p>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1.5 text-xs font-black <?= csvRatioBadgeClass($r['hits_ratio']) ?>">
                            <?= htmlspecialchars(csvFormatRatio($r['hits_ratio'])) ?>
                        </span>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-lg bg-slate-50 p-2">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Hits</span>
                            <strong class="text-lg text-emerald-600"><?= $r['under_count'] ?></strong>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-2">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Max</span>
                            <strong class="text-lg text-violet-600"><?= $r['max_count'] ?: '-' ?></strong>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-2">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Tgl Max</span>
                            <strong class="text-sm text-slate-700"><?= htmlspecialchars(csvRecordWindowText($r)) ?></strong>
                            <?php if ($r['max_date']): ?><span class="text-[10px] text-slate-400"><?= csvDaysSince($r['max_date']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="rounded-lg border border-slate-100 p-2">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Last Match</span>
                            <strong class="block text-slate-700"><?= htmlspecialchars(csvMatchScoreText($r['last_match'] ?? null)) ?></strong>
                            <?php if ($r['last_match'] ?? null): ?>
                                <span class="text-slate-400">(HT <?= $r['last_match']['fh_home'].'-'.$r['last_match']['fh_away'] ?>) <?= htmlspecialchars(csvShortDate($r['last_match']['date'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="rounded-lg border border-slate-100 p-2">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Next Match</span>
                            <strong class="block text-slate-700"><?= htmlspecialchars(csvNextMatchText($r['next_match'] ?? null)) ?></strong>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="hidden overflow-x-auto md:block">
        <table class="min-w-[920px] w-full text-xs">
            <thead class="bg-slate-50 text-slate-700 sticky top-0 z-10">
                <tr>
                    <th class="px-4 py-3 text-left font-bold">#</th>
                    <th class="px-4 py-3 text-left">
                        <a href="<?= htmlspecialchars(csvSortUrl('team', $sortCol, $sortOrder)) ?>" class="flex items-center gap-1 hover:text-amber-600 font-bold">Club <?= $sortCol==='team' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                    </th>
                    <th class="px-4 py-3 text-center">
                        <a href="<?= htmlspecialchars(csvSortUrl('under_count', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold">Hits <?= $sortCol==='under_count' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                    </th>
                    <th class="px-4 py-3 text-center">
                        <a href="<?= htmlspecialchars(csvSortUrl('max_count', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold">Max <?= $sortCol==='max_count' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                    </th>
                    <th class="px-4 py-3 text-center">
                        <a href="<?= htmlspecialchars(csvSortUrl('hits_ratio', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold">Hits / Max % <?= $sortCol==='hits_ratio' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                    </th>
                    <th class="px-4 py-3 text-center">
                        <a href="<?= htmlspecialchars(csvSortUrl('max_date', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold">Tgl Max <?= $sortCol==='max_date' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                    </th>
                    <th class="px-4 py-3 text-center font-bold">Last Match</th>
                    <th class="px-4 py-3 text-center font-bold">Next Match</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (!$pageRows): ?>
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-400 font-medium">
                    <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    Tidak ada data untuk filter ini.
                </td></tr>
            <?php else: ?>
                <?php foreach ($pageRows as $i => $r): 
                    $isHidden = $i >= 10;
                ?>
                <tr class="hover:bg-blue-50/30 transition-all duration-200 <?= $r['is_max'] ? 'bg-rose-50/30' : '' ?> main-clubs-hidden" <?= $isHidden ? 'style="display: none;"' : '' ?>>
                    <td class="px-4 py-3 text-slate-500 font-medium"><?= $offset + $i + 1 ?></td>
                    <td class="px-4 py-3 min-w-[220px]">
                        <div class="font-bold text-slate-900"><?= htmlspecialchars($r['team']) ?></div>
                        <div class="text-[10px] text-slate-500 uppercase tracking-wide"><?= htmlspecialchars($r['league']) ?></div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-3 py-1.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-700"><?= $r['under_count'] ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-3 py-1.5 rounded-full text-xs font-black bg-violet-100 text-violet-700"><?= $r['max_count'] ?: '-' ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span
                            class="px-3 py-1.5 rounded-full text-xs font-black <?= csvRatioBadgeClass($r['hits_ratio']) ?>"
                            title="<?= (int)$r['under_count'] ?>/<?= (int)$r['max_count'] ?> (<?= htmlspecialchars(csvFormatRatio($r['hits_ratio'])) ?>)"
                        ><?= htmlspecialchars(csvFormatRatio($r['hits_ratio'])) ?></span>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-600 font-medium">
                        <?= htmlspecialchars(csvRecordWindowText($r, 'd-m-y')) ?>
                        <?php if ($r['max_date']): ?><div class="text-[10px] text-slate-400"><?= csvDaysSince($r['max_date']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-600 <?= ($r['last_match'] ?? null) ? 'bg-sky-50/70 border-l border-sky-100' : '' ?>">
                        <?php if ($r['last_match'] ?? null): ?>
                            <div class="inline-block rounded-lg px-2 py-1">
                            <div class="text-[10px] font-bold text-slate-800"><?= htmlspecialchars(csvMatchScoreText($r['last_match'])) ?></div>
                            <div class="text-[10px] text-slate-500">(HT <?= $r['last_match']['fh_home'].'-'.$r['last_match']['fh_away'] ?>)</div>
                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars(csvShortDate($r['last_match']['date'])) ?></div>
                            </div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-600 <?= $r['next_match'] ? 'bg-amber-50/80 border-l border-amber-100' : '' ?>">
                        <?php if ($r['next_match']): ?>
                            <div class="inline-block rounded-lg px-2 py-1">
                            <div class="font-bold text-slate-900 text-xs max-w-[120px] truncate mx-auto" title="<?= htmlspecialchars($r['next_match']['vs']) ?>"><?= htmlspecialchars($r['next_match']['vs']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars(csvShortDate($r['next_match']['date'], 'd/m').' '.$r['next_match']['time']) ?></div>
                            </div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if (count($pageRows) > 10): ?>
        <div class="px-5 py-4 border-t border-slate-100 text-center bg-slate-50/50">
            <button type="button" id="btn-toggle-main-clubs" data-count="<?= count($pageRows) - 10 ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold transition-all text-xs focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                <span id="btn-toggle-main-clubs-text">Tampilkan Semua (<?= count($pageRows) - 10 ?> Lainnya)</span>
                <svg id="btn-toggle-main-clubs-icon" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-5 py-4 border-t border-slate-100 flex flex-wrap items-center justify-center gap-2 text-sm">
            <?php if ($pg > 1): ?>
                <a href="<?= htmlspecialchars(csvUrl(['pg' => $pg-1])) ?>" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-slate-700 transition-all">&lt; Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1,$pg-2); $p <= min($totalPages,$pg+2); $p++): ?>
                <a href="<?= htmlspecialchars(csvUrl(['pg' => $p])) ?>"
                   class="px-4 py-2 rounded-xl font-bold transition-all <?= $p===$pg ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
            <?php if ($pg < $totalPages): ?>
                <a href="<?= htmlspecialchars(csvUrl(['pg' => $pg+1])) ?>" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-slate-700 transition-all">Next &gt;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. All-Time Max Toggle
    const btnAllTimeMax = document.getElementById('btn-toggle-all-time-max');
    if (btnAllTimeMax) {
        const textSpan = document.getElementById('btn-toggle-all-time-max-text');
        const iconSvg = document.getElementById('btn-toggle-all-time-max-icon');
        const hiddenItems = document.querySelectorAll('.all-time-max-hidden');
        let isExpanded = false;
        const totalHidden = btnAllTimeMax.dataset.count;

        btnAllTimeMax.addEventListener('click', function() {
            isExpanded = !isExpanded;
            hiddenItems.forEach(el => {
                if (isExpanded) {
                    el.style.display = el.tagName === 'TR' ? 'table-row' : '';
                } else {
                    el.style.display = 'none';
                }
            });
            if (isExpanded) {
                textSpan.textContent = 'Tampilkan Lebih Sedikit';
                iconSvg.classList.add('rotate-180');
            } else {
                textSpan.textContent = 'Tampilkan Semua (' + totalHidden + ' Lainnya)';
                iconSvg.classList.remove('rotate-180');
                btnAllTimeMax.closest('.bg-white').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    // 2. Record Breakers Toggle
    const btnRecordBreakers = document.getElementById('btn-toggle-record-breakers');
    if (btnRecordBreakers) {
        const textSpan = document.getElementById('btn-toggle-record-breakers-text');
        const iconSvg = document.getElementById('btn-toggle-record-breakers-icon');
        const hiddenItems = document.querySelectorAll('.record-breakers-hidden');
        let isExpanded = false;
        const totalHidden = btnRecordBreakers.dataset.count;

        btnRecordBreakers.addEventListener('click', function() {
            isExpanded = !isExpanded;
            hiddenItems.forEach(el => {
                if (isExpanded) {
                    el.style.display = el.tagName === 'TR' ? 'table-row' : '';
                } else {
                    el.style.display = 'none';
                }
            });
            if (isExpanded) {
                textSpan.textContent = 'Tampilkan Lebih Sedikit';
                iconSvg.classList.add('rotate-180');
            } else {
                textSpan.textContent = 'Tampilkan Semua (' + totalHidden + ' Lainnya)';
                iconSvg.classList.remove('rotate-180');
                btnRecordBreakers.closest('.bg-white').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    // 3. Main Clubs Toggle
    const btnMainClubs = document.getElementById('btn-toggle-main-clubs');
    if (btnMainClubs) {
        const textSpan = document.getElementById('btn-toggle-main-clubs-text');
        const iconSvg = document.getElementById('btn-toggle-main-clubs-icon');
        const hiddenItems = document.querySelectorAll('.main-clubs-hidden');
        let isExpanded = false;
        const totalHidden = btnMainClubs.dataset.count;

        btnMainClubs.addEventListener('click', function() {
            isExpanded = !isExpanded;
            hiddenItems.forEach(el => {
                if (isExpanded) {
                    el.style.display = el.tagName === 'TR' ? 'table-row' : '';
                } else {
                    el.style.display = 'none';
                }
            });
            if (isExpanded) {
                textSpan.textContent = 'Tampilkan Lebih Sedikit';
                iconSvg.classList.add('rotate-180');
            } else {
                textSpan.textContent = 'Tampilkan Semua (' + totalHidden + ' Lainnya)';
                iconSvg.classList.remove('rotate-180');
                btnMainClubs.closest('.bg-white').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    // Loader indicator function
    function showLoader() {
        const pageLoader = document.getElementById('pageLoader');
        if (pageLoader) {
            pageLoader.classList.remove('hidden');
        }
    }

    // Show loader on page navigation/filter/sorting/pagination links
    document.querySelectorAll('.page-fade-in a').forEach(function(link) {
        const href = link.getAttribute('href');
        if (href && (href.startsWith('index.php') || href.includes('page='))) {
            link.addEventListener('click', showLoader);
        }
    });

    // Hook submit event on the filter form
    const filterForm = document.getElementById('club-filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', showLoader);
    }

    // Auto-submit dropdowns with loading state
    const marketSelect = document.getElementById('club-market');
    const leagueSelect = document.getElementById('club-league');
    const timeFromSelect = document.getElementById('club-time-from');
    const timeToSelect   = document.getElementById('club-time-to');
    if (marketSelect && filterForm) {
        marketSelect.addEventListener('change', function() {
            showLoader();
            filterForm.submit();
        });
    }
    if (leagueSelect && filterForm) {
        leagueSelect.addEventListener('change', function() {
            showLoader();
            filterForm.submit();
        });
    }
    if (timeFromSelect && filterForm) {
        timeFromSelect.addEventListener('change', function() {
            showLoader();
            filterForm.submit();
        });
    }
    if (timeToSelect && filterForm) {
        timeToSelect.addEventListener('change', function() {
            showLoader();
            filterForm.submit();
        });
    }

    // 4. Custom Autocomplete Suggestion Logic
    const clubNames = <?= json_encode($clubNameList) ?>;
    const searchInput = document.getElementById('club-search');
    const dropdown = document.getElementById('club-search-dropdown');
    let activeIndex = -1;

    function closeDropdown() {
        if (dropdown) {
            dropdown.classList.add('hidden');
            dropdown.innerHTML = '';
        }
        activeIndex = -1;
    }

    if (searchInput && dropdown) {
        searchInput.addEventListener('input', function() {
            const val = this.value.trim().toLowerCase();
            if (!val) {
                closeDropdown();
                return;
            }
            
            const matches = clubNames.filter(name => name.toLowerCase().includes(val)).slice(0, 10);
            if (matches.length === 0) {
                closeDropdown();
                return;
            }
            
            dropdown.innerHTML = '';
            dropdown.classList.remove('hidden');
            
            matches.forEach((name, idx) => {
                const item = document.createElement('div');
                item.className = 'px-4 py-2.5 hover:bg-slate-100 cursor-pointer text-slate-800 text-xs font-semibold transition-colors duration-150';
                item.textContent = name;
                item.addEventListener('click', function() {
                    searchInput.value = name;
                    closeDropdown();
                    showLoader();
                    searchInput.form.submit();
                });
                dropdown.appendChild(item);
            });
        });

        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target !== searchInput && e.target !== dropdown) {
                closeDropdown();
            }
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            const items = dropdown.querySelectorAll('div');
            if (dropdown.classList.contains('hidden') || items.length === 0) return;
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = (activeIndex + 1) % items.length;
                highlightItem(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = (activeIndex - 1 + items.length) % items.length;
                highlightItem(items);
            } else if (e.key === 'Enter') {
                if (activeIndex > -1) {
                    e.preventDefault();
                    items[activeIndex].click();
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });
    }

    function highlightItem(items) {
        items.forEach((item, idx) => {
            if (idx === activeIndex) {
                item.classList.add('bg-slate-100');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('bg-slate-100');
            }
        });
    }

    // 5. Flatpickr Date Range Picker Initialization
    flatpickr("#club-date-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        onClose: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
                showLoader();
                instance.element.form.submit();
            }
        }
    });
});
</script>
