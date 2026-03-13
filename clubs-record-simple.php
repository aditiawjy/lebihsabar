<?php
date_default_timezone_set('Asia/Jakarta');

// -- Market options ------------------------------------------------------------
$marketOptions = [
    '0.5'     => ['label' => 'Under 0.5',     'short' => 'U0.5',  'class' => 'bg-blue-500 text-white'],
    '1.5'     => ['label' => 'Under 1.5',     'short' => 'U1.5',  'class' => 'bg-sky-500 text-white'],
    '2.5'     => ['label' => 'Under 2.5',     'short' => 'U2.5',  'class' => 'bg-cyan-500 text-white'],
    'fhg0.5'  => ['label' => 'FHG Under 0.5', 'short' => 'FHG',   'class' => 'bg-violet-500 text-white'],
    'shg0.5'  => ['label' => 'SHG Under 0.5', 'short' => 'SHG',   'class' => 'bg-fuchsia-500 text-white'],
    'draw_ft' => ['label' => 'Draw FT',        'short' => 'DRAW',  'class' => 'bg-indigo-500 text-white'],
];

function csvCheckMarket(array $m, string $mkt): bool {
    $ftH = (int)$m['ft_home']; $ftA = (int)$m['ft_away'];
    $fhH = (int)$m['fh_home']; $fhA = (int)$m['fh_away'];
    return match($mkt) {
        '0.5'     => ($ftH + $ftA) < 1,
        '1.5'     => ($ftH + $ftA) < 2,
        '2.5'     => ($ftH + $ftA) < 3,
        'fhg0.5'  => ($fhH + $fhA) < 1,
        'shg0.5'  => (($ftH - $fhH) + ($ftA - $fhA)) < 1,
        'draw_ft' => $ftH === $ftA,
        default   => false,
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

function csvPushRecent(array &$bucket, string $key, array $match): void {
    if (!isset($bucket[$key])) {
        $bucket[$key] = [];
    }

    $matchTs = $match['date'] . $match['time'];
    $insertAt = count($bucket[$key]);
    foreach ($bucket[$key] as $idx => $existing) {
        $existingTs = $existing['date'] . $existing['time'];
        if ($matchTs > $existingTs) {
            $insertAt = $idx;
            break;
        }
    }

    array_splice($bucket[$key], $insertAt, 0, [$match]);
    if (count($bucket[$key]) > 5) {
        array_pop($bucket[$key]);
    }
}

function csvBumpDailyMax(array &$dailyCounts, array &$maxByKey, string $key, string $date): void {
    $dailyCounts[$key][$date] = ($dailyCounts[$key][$date] ?? 0) + 1;
    $newCount = $dailyCounts[$key][$date];
    if (!isset($maxByKey[$key]) || $newCount > $maxByKey[$key]['count']) {
        $maxByKey[$key] = ['count' => $newCount, 'date' => $date];
    }
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

$dateFromRaw = $_GET['date_from'] ?? '';
$dateToRaw = $_GET['date_to'] ?? '';
$dateFromValid = $dateFromRaw !== '' && strtotime($dateFromRaw) !== false;
$dateToValid = $dateToRaw !== '' && strtotime($dateToRaw) !== false;

// -- Filters -------------------------------------------------------------------
$today      = date('Y-m-d');
$dateFrom   = $dateFromValid ? $dateFromRaw : $_csvDefaultDate;
$dateTo     = $dateToValid ? $dateToRaw : $_csvDefaultDate;
$timeFrom   = csvNormalizeTime($_GET['time_from'] ?? '00:00', '00:00');
$timeTo     = csvNormalizeTime($_GET['time_to']   ?? '23:59', '23:59');
$lgFilter   = trim($_GET['league'] ?? '');
$searchTerm = trim($_GET['search'] ?? '');
$sortCol    = $_GET['sort']  ?? 'hits_ratio';
$sortOrder  = $_GET['order'] ?? 'desc';
$pg         = max(1, (int)($_GET['pg'] ?? 1));
$perPageOpt = [25, 50, 100, 200];
$perPageRaw = (int)($_GET['per_page'] ?? 50);
$perPage    = in_array($perPageRaw, $perPageOpt) ? $perPageRaw : 50;
$showOnlyMax = isset($_GET['show_max']) && $_GET['show_max'] === '1';

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

// -- CSV scan #2: build stats without loading full file in memory -------------
$allTimeDailyMkt = [];  // key => [date => count]
$allTimeMaxByKey = []; // key => ['count' => int, 'date' => string]
$lastHome        = [];  // key => latest 5 matches (desc)
$lastAway        = [];
$nextMatch       = [];  // key => match
$inRange = [];  // key => ['team','league','under_count']
$inRangeDailyMkt = []; // key => [date => count]
$periodMaxByKey = [];  // key => ['count' => int, 'date' => string]
$leagueSet       = [];
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
    &$allTimeDailyMkt,
    &$allTimeMaxByKey,
    &$lastHome,
    &$lastAway,
    &$nextMatch,
    &$inRange,
    &$inRangeDailyMkt,
    &$periodMaxByKey,
    &$leagueSet,
    &$csvMinDate,
    &$csvMaxDate,
    &$csvDatesWithData
): void {
    if ($mktParam === '2.5' && isset($hiddenLeagues[$m['league']])) {
        return;
    }

    if ($m['league'] !== '') {
        $leagueSet[$m['league']] = true;
    }

    if ($lgFilter && $m['league'] !== $lgFilter) {
        return;
    }

    $hKey = $m['home'].'|'.$m['league'];
    $aKey = $m['away'].'|'.$m['league'];

    $hasFT = csvHasFT($m);
    if ($hasFT) {
        if ($csvMinDate === null || $m['date'] < $csvMinDate) $csvMinDate = $m['date'];
        if ($csvMaxDate === null || $m['date'] > $csvMaxDate) $csvMaxDate = $m['date'];
        $csvDatesWithData[$m['date']] = true;
        $isMarketHit = csvCheckMarket($m, $mktParam);
        if ($isMarketHit) {
            csvBumpDailyMax($allTimeDailyMkt, $allTimeMaxByKey, $hKey, $m['date']);
            csvBumpDailyMax($allTimeDailyMkt, $allTimeMaxByKey, $aKey, $m['date']);
        }

        csvPushRecent($lastHome, $hKey, $m);
        csvPushRecent($lastAway, $aKey, $m);

        if (
            $m['date'] >= $dateFrom &&
            $m['date'] <= $dateTo &&
            csvTimeInRange($m['time'], $timeFrom, $timeTo)
        ) {
            foreach ([$hKey => $m['home'], $aKey => $m['away']] as $key => $team) {
                if (!isset($inRange[$key])) {
                    $inRange[$key] = ['team' => $team, 'league' => $m['league'], 'under_count' => 0];
                }
                if ($isMarketHit) {
                    $inRange[$key]['under_count']++;
                    csvBumpDailyMax($inRangeDailyMkt, $periodMaxByKey, $key, $m['date']);
                }
            }
        }
        return;
    }

    if ($m['date'] < $today) {
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

$leagueList = array_keys($leagueSet);
sort($leagueList);

// -- Build final rows -----------------------------------------------------------
$rows = [];
foreach ($inRange as $key => $club) {
    $maxCnt = $allTimeMaxByKey[$key]['count'] ?? 0;
    $maxDate = $allTimeMaxByKey[$key]['date'] ?? '';
    $periodMaxCnt = $periodMaxByKey[$key]['count'] ?? 0;
    $periodMaxDate = $periodMaxByKey[$key]['date'] ?? '';

    $periodCnt = $club['under_count'];
    $isMax = $maxCnt > 0 && $periodMaxCnt >= $maxCnt;

    $rows[] = [
        'team'        => $club['team'],
        'league'      => $club['league'],
        'under_count' => $periodCnt,
        'period_max_count' => $periodMaxCnt,
        'period_max_date' => $periodMaxDate,
        'max_count'   => $maxCnt,
        'hits_ratio'  => $maxCnt > 0 ? round(($periodCnt / $maxCnt) * 100, 1) : null,
        'max_date'    => $maxDate,
        'is_max'      => $isMax,
        'last_home'   => $lastHome[$key] ?? [],
        'last_away'   => $lastAway[$key] ?? [],
        'next_match'  => $nextMatch[$key] ?? null,
    ];
}

if ($searchTerm) {
    $searchLower = mb_strtolower($searchTerm, 'UTF-8');
    $rows = array_values(array_filter($rows, fn($r) => 
        mb_strpos(mb_strtolower($r['team'], 'UTF-8'), $searchLower) !== false ||
        mb_strpos(mb_strtolower($r['league'], 'UTF-8'), $searchLower) !== false
    ));
}

// Record Breakers = teams where max harian pada periode filter = all-time max
$recordBreakers = array_values(array_filter($rows, fn($r) => $r['is_max'] && $r['max_count'] > 0));

// Filter: only max if requested
if ($showOnlyMax) {
    $rows = array_values(array_filter($rows, fn($r) => $r['is_max']));
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

// Helper: build URL preserving all current GET params
function csvUrl(array $extra = []): string {
    $allowedKeys = ['page', 'search', 'date_from', 'date_to', 'time_from', 'time_to', 'league', 'under', 'sort', 'order', 'pg', 'per_page', 'show_max'];
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
function csvMatchCell(array $matches, string $mkt): string {
    if (!$matches) return '<span class="text-slate-300 text-xs">-</span>';
    $parts = [];
    foreach ($matches as $m) {
        $hit  = csvHasFT($m) && csvCheckMarket($m, $mkt);
        $ftH  = csvHasFT($m) ? $m['ft_home'] : '?';
        $ftA  = csvHasFT($m) ? $m['ft_away'] : '?';
        $fhH  = $m['fh_home'] !== '' ? $m['fh_home'] : '?';
        $fhA  = $m['fh_away'] !== '' ? $m['fh_away'] : '?';
        $score = htmlspecialchars("({$fhH}-{$fhA}) {$ftH}-{$ftA}");
        $date  = htmlspecialchars(substr($m['date'], 5));
        $cls   = $hit ? 'text-emerald-700 font-bold' : 'text-slate-500';
        $parts[] = "<span class=\"text-xs {$cls}\" title=\"{$date}\">{$score}</span>";
    }
    return implode('<br>', $parts);
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

$mktLabel = $marketOptions[$mktParam]['label'];
$mktShort = $marketOptions[$mktParam]['short'];
$mktClass = $marketOptions[$mktParam]['class'];
?>
<div class="p-4 md:p-8 space-y-6 page-fade-in">
    <?php
    // Calculate stats
    $totalRecordBreakers = count($recordBreakers);
    $maxHits = $rows ? max(array_column($rows, 'under_count')) : 0;
    $avgHits = $rows ? round(array_sum(array_column($rows, 'under_count')) / count($rows), 1) : 0;
    ?>

    <!-- Broadcast Header -->
    <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-5 md:p-6 shadow-xl">
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

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Clubs</p>
            <p class="mt-2 text-2xl font-black text-slate-900"><?= $totalClubs ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Record Breakers</p>
            <p class="mt-2 text-2xl font-black text-rose-600"><?= $totalRecordBreakers ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Max Hits</p>
            <p class="mt-2 text-2xl font-black text-emerald-600"><?= $maxHits ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Avg Hits</p>
            <p class="mt-2 text-2xl font-black text-blue-600"><?= $avgHits ?></p>
        </div>
    </div>

    <!-- Filter Form -->
    <form method="GET" class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6 transition-all">
        <input type="hidden" name="page" value="clubs">
        
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Cari club..." 
                    class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all">
            </div>
            <div class="flex gap-1">
                <?php
                $today = date('Y-m-d');
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                $weekEnd = date('Y-m-d', strtotime('sunday this week'));
                ?>
                <a href="<?= htmlspecialchars(csvUrl(['date_from' => $today, 'date_to' => $today, 'pg' => 1])) ?>" 
                   class="px-3 py-3 text-xs font-medium rounded-xl border <?= $dateFrom === $today && $dateTo === $today ? 'bg-slate-900 text-white border-slate-900' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100' ?>">
                    Today
                </a>
                <a href="<?= htmlspecialchars(csvUrl(['date_from' => $weekStart, 'date_to' => $weekEnd, 'pg' => 1])) ?>" 
                   class="px-3 py-3 text-xs font-medium rounded-xl border <?= $dateFrom === $weekStart && $dateTo === $weekEnd ? 'bg-slate-900 text-white border-slate-900' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100' ?>">
                    This Week
                </a>
                <a href="<?= htmlspecialchars(csvUrl(['date_from' => '', 'date_to' => '', 'league' => '', 'under' => '0.5', 'pg' => 1])) ?>" 
                   class="px-3 py-3 text-xs font-medium rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-100">
                    Reset
                </a>
            </div>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Jam Mulai</label>
                <input type="text" name="time_from" value="<?= htmlspecialchars($timeFrom) ?>" placeholder="00:00" maxlength="5" class="px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Jam Selesai</label>
                <input type="text" name="time_to" value="<?= htmlspecialchars($timeTo) ?>" placeholder="23:59" maxlength="5" class="px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Dari Tanggal</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all h-[46px]">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Sampai Tanggal</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all h-[46px]">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Market</label>
                <select name="under" class="px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all appearance-none cursor-pointer h-[46px]">
                    <?php foreach ($marketOptions as $val => $opt): ?>
                        <option value="<?= $val ?>" <?= $mktParam === $val ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Liga</label>
                <select name="league" class="px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all appearance-none cursor-pointer h-[46px]">
                    <option value="">Semua Liga</option>
                    <?php foreach ($leagueList as $lg): ?>
                        <option value="<?= htmlspecialchars($lg) ?>" <?= $lgFilter === $lg ? 'selected' : '' ?>><?= htmlspecialchars($lg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-4 mt-4 border-t border-slate-100">
            <div class="flex items-center gap-2">
                <input type="checkbox" name="show_max" value="1" id="show_max" <?= $showOnlyMax ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <label for="show_max" class="text-sm text-slate-600 cursor-pointer">Hanya tampilkan MAX</label>
            </div>
            <button type="submit" class="w-full sm:w-auto bg-slate-900 text-white rounded-xl px-6 py-3 text-sm font-bold hover:bg-slate-800 transition-all shadow-lg active:scale-95 flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                Terapkan Filter
            </button>
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
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
        <button type="button" onclick="this.nextElementSibling.classList.toggle('hidden')"
            class="w-full flex items-center justify-between px-4 py-3 hover:bg-slate-50 transition-colors">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-slate-700 uppercase tracking-wide">Monitoring Tanggal Data CSV</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold
                    <?= $daysGap > 0 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' ?>">
                    <?= $daysGap > 0 ? $daysGap.' tanggal kosong' : 'Lengkap' ?>
                </span>
            </div>
            <div class="flex items-center gap-4 text-xs text-slate-400">
                <span><?= $csvMinDate ?? '-' ?> &rarr; <?= $csvMaxDate ?? '-' ?></span>
                <span class="text-slate-300">&#x25BC;</span>
            </div>
        </button>
        <div class="hidden border-t border-slate-100">
            <div class="px-4 py-3 flex gap-6 text-xs text-slate-500 border-b border-slate-100 bg-slate-50">
                <span>Total rentang: <strong class="text-slate-700"><?= $totalDays ?></strong> hari</span>
                <span>Ada data: <strong class="text-emerald-600"><?= $daysWithData ?></strong> hari</span>
                <span>Kosong / gap: <strong class="text-amber-600"><?= $daysGap ?></strong> hari</span>
            </div>
            <?php if ($daysGap === 0): ?>
            <div class="px-4 py-6 text-center text-sm text-emerald-600 font-medium">
                Semua tanggal dalam rentang <?= htmlspecialchars($csvMinDate ?? '') ?> &ndash; <?= htmlspecialchars($csvMaxDate ?? '') ?> sudah ada datanya.
            </div>
            <?php else: ?>
            <div class="px-4 py-3 space-y-3 max-h-64 overflow-y-auto">
                <?php foreach ($gapByMonth as $month => $dates): ?>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"><?= htmlspecialchars($month) ?></div>
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

    <!-- Record Breakers -->
    <?php if ($recordBreakers): ?>
    <div class="bg-white rounded-2xl shadow-md border-0 overflow-hidden">
        <div class="px-5 py-4 bg-rose-600 text-white flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span class="text-sm font-bold uppercase tracking-wide">Record Breakers</span>
                <span class="text-xs text-rose-200 bg-rose-700/50 px-2 py-1 rounded-lg"><?= htmlspecialchars($mktLabel) ?></span>
            </div>
            <span class="text-xs text-rose-100"><?= count($recordBreakers) ?> clubs</span>
        </div>
        <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="bg-rose-50 text-rose-900 sticky top-0 z-10">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">#</th>
                            <th class="px-4 py-3 text-left font-bold">Market</th>
                            <th class="px-4 py-3 text-left font-bold">Club</th>
                            <th class="px-4 py-3 text-center font-bold">Period Max</th>
                            <th class="px-4 py-3 text-center font-bold">All-Time Max</th>
                            <th class="px-4 py-3 text-center font-bold">Hits / Max %</th>
                            <th class="px-4 py-3 text-center font-bold">Tgl Max</th>
                            <th class="px-4 py-3 text-center font-bold">Next Match</th>
                        </tr>
                    </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($recordBreakers as $i => $r): ?>
                <tr class="hover:bg-rose-50/30 transition-all">
                    <td class="px-4 py-3 text-slate-400 font-medium"><?= $i + 1 ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-1 rounded-lg text-[10px] font-bold <?= $mktClass ?>"><?= $mktShort ?></span></td>
                    <td class="px-4 py-3">
                        <div class="font-bold text-slate-900"><?= htmlspecialchars($r['team']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= htmlspecialchars($r['league']) ?></div>
                    </td>
                        <td class="px-4 py-3 text-center"><span class="px-3 py-1 rounded-full bg-rose-100 text-rose-700 font-black text-sm"><?= $r['period_max_count'] ?></span></td>
                        <td class="px-4 py-3 text-center"><span class="px-3 py-1 rounded-full bg-violet-100 text-violet-700 font-black text-sm"><?= $r['max_count'] ?></span></td>
                        <td class="px-4 py-3 text-center text-xs">
                            <?php
                            $recordRatio = $r['max_count'] > 0
                                ? round(($r['period_max_count'] / $r['max_count']) * 100, 1)
                                : null;
                            ?>
                            <span class="px-3 py-1.5 rounded-full text-xs font-black <?= csvRatioBadgeClass($recordRatio) ?>">
                                <?= htmlspecialchars(csvFormatRatio($recordRatio)) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-slate-600 font-medium"><?= htmlspecialchars(date('d-m-y', strtotime($r['max_date']))) ?></td>
                        <td class="px-4 py-3 text-center text-slate-600">
                        <?php if ($r['next_match']): ?>
                            <div class="font-bold text-slate-800"><?= htmlspecialchars($r['next_match']['vs']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars($r['next_match']['date'].' '.$r['next_match']['time']) ?></div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Clubs Table -->
    <div class="bg-white rounded-2xl shadow-md border-0 overflow-hidden">
        <!-- Header & Per page -->
        <div class="px-5 py-4 bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3">
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
        <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
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
                    <th class="px-4 py-3 text-center font-bold">Next Match</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (!$pageRows): ?>
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-400 font-medium">
                    <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    Tidak ada data untuk filter ini.
                </td></tr>
            <?php else: ?>
                <?php foreach ($pageRows as $i => $r): ?>
                <tr class="hover:bg-blue-50/30 transition-all duration-200 <?= $r['is_max'] ? 'bg-rose-50/30' : '' ?>">
                    <td class="px-4 py-3 text-slate-500 font-medium"><?= $offset + $i + 1 ?></td>
                    <td class="px-4 py-3">
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
                    <td class="px-4 py-3 text-center text-slate-600 font-medium"><?= $r['max_date'] ? htmlspecialchars(date('d-m-y', strtotime($r['max_date']))) : '-' ?></td>
                    <td class="px-4 py-3 text-center text-slate-600">
                        <?php if ($r['next_match']): ?>
                            <div class="font-bold text-slate-800 text-xs"><?= htmlspecialchars($r['next_match']['vs']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars($r['next_match']['date'].' '.$r['next_match']['time']) ?></div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

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
