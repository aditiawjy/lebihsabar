<?php
// CSV Export handler — must run before any output
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $csvPathEx = __DIR__ . '/matches.csv';
    $exportMatches = [];
    if (is_readable($csvPathEx) && ($hEx = fopen($csvPathEx, 'r')) !== false) {
        $hdrs = fgetcsv($hEx);
        if (is_array($hdrs)) {
            while (($rowEx = fgetcsv($hEx)) !== false) {
                if (count($rowEx) !== count($hdrs)) continue;
                $m = array_combine($hdrs, $rowEx);
                if ($m === false) continue;
                // Apply same filters as main view
                $exDate = substr((string)($m['match_time'] ?? ''), 0, 10);
                $df = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
                $dt = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
                if ($df > $dt) [$df, $dt] = [$dt, $df];
                if (!empty($df) && $exDate < $df) continue;
                if (!empty($dt) && $exDate > $dt) continue;
                if (!empty($_GET['league']) && ($m['league'] ?? '') !== $_GET['league']) continue;
                if (!empty($_GET['search'])) {
                    $s = $_GET['search'];
                    if (stripos($m['home_team'] ?? '', $s) === false && stripos($m['away_team'] ?? '', $s) === false) continue;
                }
                if (!empty($_GET['home_team']) && stripos($m['home_team'] ?? '', $_GET['home_team']) === false) continue;
                if (!empty($_GET['away_team']) && stripos($m['away_team'] ?? '', $_GET['away_team']) === false) continue;
                // Status filter
                $exStatus = $_GET['status'] ?? '';
                if (in_array($exStatus, ['upcoming','finished'], true)) {
                    $exHasFt = ($m['ft_home'] ?? '') !== '' && ($m['ft_away'] ?? '') !== '';
                    if ($exStatus === 'finished' && !$exHasFt) continue;
                    if ($exStatus === 'upcoming' && $exHasFt) continue;
                }
                // Time filter
                $exTf = preg_match('/^\d{2}:\d{2}$/', $_GET['time_from'] ?? '') ? $_GET['time_from'] : '';
                $exTt = preg_match('/^\d{2}:\d{2}$/', $_GET['time_to'] ?? '') ? $_GET['time_to'] : '';
                if ($exTf !== '' || $exTt !== '') {
                    $exDt = (string)($m['match_time'] ?? '');
                    $exTime = strlen($exDt) >= 16 ? substr($exDt, 11, 5) : '';
                    if ($exTime !== '') {
                        $tf2 = $exTf !== '' ? $exTf : '00:00';
                        $tt2 = $exTt !== '' ? $exTt : '23:59';
                        if ($tf2 <= $tt2) { if ($exTime < $tf2 || $exTime > $tt2) continue; }
                        else { if ($exTime < $tf2 && $exTime > $tt2) continue; }
                    }
                }
                $exportMatches[] = $m;
            }
        }
        fclose($hEx);
    }
    $filename = 'matches_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    if (!empty($exportMatches)) {
        fputcsv($out, array_keys($exportMatches[0]));
        foreach ($exportMatches as $row) fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Pagination setup
$p = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$perPageOptions = [15, 25, 50, 100];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions) ? (int)$_GET['per_page'] : 15;
$offset = ($p - 1) * $perPage;

$leagueFilter = trim((string)($_GET['league'] ?? ''));
$searchFilter = trim((string)($_GET['search'] ?? ''));
$homeTeamFilter = trim((string)($_GET['home_team'] ?? ''));
$awayTeamFilter = trim((string)($_GET['away_team'] ?? ''));
$h2hHomeFilter = trim((string)($_GET['h2h_home'] ?? ''));
$h2hAwayFilter = trim((string)($_GET['h2h_away'] ?? ''));

// Date defaults (Default to today if not set)
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
// Auto-swap if date_from is after date_to
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

// Sorting parameters
$allowedSorts = ['match_time', 'home_team', 'away_team', 'league'];
$sort = $_GET['sort'] ?? 'match_time';
$order = $_GET['order'] ?? 'desc';
if (!in_array($sort, $allowedSorts)) $sort = 'match_time';
if (!in_array($order, ['asc', 'desc'])) $order = 'desc';

// Display timezone label shown next to match times
define('MATCH_TZ_LABEL', 'WIB');

// Status filter: upcoming (no FT score) | finished (has FT score) | '' (all)
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'upcoming', 'finished'])) $statusFilter = '';

// Time filter HH:MM
$timeFrom = preg_match('/^\d{2}:\d{2}$/', $_GET['time_from'] ?? '') ? $_GET['time_from'] : '';
$timeTo   = preg_match('/^\d{2}:\d{2}$/', $_GET['time_to']   ?? '') ? $_GET['time_to']   : '';

function matchesBuildQuery(array $overrides = []): string {
    $allowedKeys = [
        'page', 'p', 'per_page', 'date_from', 'date_to', 'sort', 'order', 'status',
        'time_from', 'time_to', 'league', 'search', 'home_team', 'away_team', 'h2h_home', 'h2h_away', 'export',
    ];

    $base = ['page' => 'matches'];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $_GET)) {
            continue;
        }

        $value = $_GET[$key];
        if (is_array($value)) {
            continue;
        }

        $base[$key] = (string)$value;
    }

    $query = array_merge($base, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return 'index.php?' . http_build_query($query);
}

function matchesBuildH2hTimeSummary(array $allMatches, array $targetMatch): array {
    $homeTeam = trim((string)($targetMatch['home_team'] ?? ''));
    $awayTeam = trim((string)($targetMatch['away_team'] ?? ''));
    $matchDatetime = (string)($targetMatch['match_time'] ?? '');
    $kickoffTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';

    $summary = [
        'time' => $kickoffTime,
        'total_meetings' => 0,
        'finished_meetings' => 0,
        'under_05' => 0,
        'over_05' => 0,
        'over_15' => 0,
        'over_25' => 0,
        'btts' => 0,
        'no_btts' => 0,
        'w1' => 0,
        'x' => 0,
        'w2' => 0,
    ];

    if ($homeTeam === '' || $awayTeam === '' || $kickoffTime === '') {
        return $summary;
    }

    foreach ($allMatches as $match) {
        $candidateHome = trim((string)($match['home_team'] ?? ''));
        $candidateAway = trim((string)($match['away_team'] ?? ''));
        $candidateDatetime = (string)($match['match_time'] ?? '');
        $candidateTime = strlen($candidateDatetime) >= 16 ? substr($candidateDatetime, 11, 5) : '';

        $sameOrder = strcasecmp($candidateHome, $homeTeam) === 0 && strcasecmp($candidateAway, $awayTeam) === 0;
        $reverseOrder = strcasecmp($candidateHome, $awayTeam) === 0 && strcasecmp($candidateAway, $homeTeam) === 0;
        if ((!$sameOrder && !$reverseOrder) || $candidateTime !== $kickoffTime) {
            continue;
        }

        $summary['total_meetings']++;

        if (($match['ft_home'] ?? null) === null || ($match['ft_away'] ?? null) === null) {
            continue;
        }

        $summary['finished_meetings']++;
        $ftHome = (int)$match['ft_home'];
        $ftAway = (int)$match['ft_away'];
        $totalGoals = $ftHome + $ftAway;

        $team1Goals = strcasecmp($candidateHome, $homeTeam) === 0 ? $ftHome : $ftAway;
        $team2Goals = strcasecmp($candidateAway, $awayTeam) === 0 ? $ftAway : $ftHome;
        if ($team1Goals > $team2Goals) {
            $summary['w1']++;
        } elseif ($team1Goals < $team2Goals) {
            $summary['w2']++;
        } else {
            $summary['x']++;
        }

        if ($totalGoals === 0) {
            $summary['under_05']++;
        }
        if ($totalGoals > 0) {
            $summary['over_05']++;
        }
        if ($totalGoals > 1) {
            $summary['over_15']++;
        }
        if ($totalGoals > 2) {
            $summary['over_25']++;
        }
        if ($ftHome > 0 && $ftAway > 0) {
            $summary['btts']++;
        } else {
            $summary['no_btts']++;
        }
    }

    return $summary;
}

$csvPath = __DIR__ . '/matches.csv';
$allMatches = [];

if (is_readable($csvPath) && ($handle = fopen($csvPath, 'r')) !== false) {
    $headers = fgetcsv($handle);
    if (is_array($headers)) {
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }

            $match = array_combine($headers, $row);
            if ($match === false) {
                continue;
            }

            foreach (['id', 'fh_home', 'fh_away', 'ft_home', 'ft_away'] as $intField) {
                if (isset($match[$intField]) && $match[$intField] !== '') {
                    $match[$intField] = is_numeric($match[$intField]) ? (int)$match[$intField] : null;
                } else {
                    $match[$intField] = null;
                }
            }

            $allMatches[] = $match;
        }
    }
    fclose($handle);
}

$filteredMatches = array_filter($allMatches, function ($match) use ($date_from, $date_to, $statusFilter, $timeFrom, $timeTo, $leagueFilter, $searchFilter, $homeTeamFilter, $awayTeamFilter) {
    if ($leagueFilter !== '' && ($match['league'] ?? '') !== $leagueFilter) {
        return false;
    }

    if ($searchFilter !== '') {
        $search = $searchFilter;
        $home = $match['home_team'] ?? '';
        $away = $match['away_team'] ?? '';
        if (stripos($home, $search) === false && stripos($away, $search) === false) {
            return false;
        }
    }

    if ($homeTeamFilter !== '') {
        $home = $match['home_team'] ?? '';
        if (stripos($home, $homeTeamFilter) === false) {
            return false;
        }
    }

    if ($awayTeamFilter !== '') {
        $away = $match['away_team'] ?? '';
        if (stripos($away, $awayTeamFilter) === false) {
            return false;
        }
    }

    $matchDatetime = (string)($match['match_time'] ?? '');
    $matchDate = substr($matchDatetime, 0, 10);
    if (!empty($date_from) && $matchDate < $date_from) {
        return false;
    }
    if (!empty($date_to) && $matchDate > $date_to) {
        return false;
    }

    // Status filter
    if ($statusFilter !== '') {
        $hasFt = $match['ft_home'] !== null && $match['ft_away'] !== null;
        if ($statusFilter === 'finished' && !$hasFt) return false;
        if ($statusFilter === 'upcoming' && $hasFt) return false;
    }

    // Time filter (HH:MM from match_time column, e.g. "2025-10-15 13:30:00")
    if ($timeFrom !== '' || $timeTo !== '') {
        $matchTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';
        if ($matchTime !== '') {
            $tf = $timeFrom !== '' ? $timeFrom : '00:00';
            $tt = $timeTo   !== '' ? $timeTo   : '23:59';
            if ($tf <= $tt) {
                if ($matchTime < $tf || $matchTime > $tt) return false;
            } else {
                // overnight range
                if ($matchTime < $tf && $matchTime > $tt) return false;
            }
        }
    }

    return true;
});

$filteredMatches = array_values($filteredMatches);

usort($filteredMatches, function ($left, $right) use ($sort, $order) {
    $a = $left[$sort] ?? '';
    $b = $right[$sort] ?? '';

    if ($sort === 'match_time') {
        $a = strtotime((string)$a) ?: 0;
        $b = strtotime((string)$b) ?: 0;
    } else {
        $a = mb_strtolower((string)$a);
        $b = mb_strtolower((string)$b);
    }

    if ($a === $b) {
        return 0;
    }

    $result = ($a < $b) ? -1 : 1;
    return $order === 'asc' ? $result : -$result;
});

$total = count($filteredMatches);
$totalPages = max(1, (int)ceil($total / $perPage));
$p = min($p, $totalPages);
$offset = ($p - 1) * $perPage;
$pagedMatches = array_slice($filteredMatches, $offset, $perPage);

// Get unique leagues for filter
$leagues = [];
foreach ($allMatches as $match) {
    $league = trim((string)($match['league'] ?? ''));
    if ($league !== '') {
        $leagues[$league] = true;
    }
}
$leagues = array_keys($leagues);
sort($leagues);

// Get unique teams for autocomplete
$teams = [];
foreach ($allMatches as $match) {
    $home = trim((string)($match['home_team'] ?? ''));
    $away = trim((string)($match['away_team'] ?? ''));
    if ($home !== '') $teams[$home] = true;
    if ($away !== '') $teams[$away] = true;
}
$teams = array_keys($teams);
sort($teams);

$h2hStats = [
    'active' => $h2hHomeFilter !== '' && $h2hAwayFilter !== '',
    'home' => $h2hHomeFilter,
    'away' => $h2hAwayFilter,
    'total_meetings' => 0,
    'finished_meetings' => 0,
    'over_05' => 0,
    'over_15' => 0,
    'over_25' => 0,
    'w1' => 0,
    'x' => 0,
    'w2' => 0,
];
$h2hMatches = [];

if ($h2hStats['active']) {
    foreach ($allMatches as $match) {
        $home = trim((string)($match['home_team'] ?? ''));
        $away = trim((string)($match['away_team'] ?? ''));
        $matchDatetime = (string)($match['match_time'] ?? '');
        $matchDate = substr($matchDatetime, 0, 10);

        if ($leagueFilter !== '' && ($match['league'] ?? '') !== $leagueFilter) {
            continue;
        }

        if (!empty($date_from) && $matchDate < $date_from) {
            continue;
        }
        if (!empty($date_to) && $matchDate > $date_to) {
            continue;
        }

        if ($timeFrom !== '' || $timeTo !== '') {
            $matchTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';
            if ($matchTime !== '') {
                $tf = $timeFrom !== '' ? $timeFrom : '00:00';
                $tt = $timeTo   !== '' ? $timeTo   : '23:59';
                if ($tf <= $tt) {
                    if ($matchTime < $tf || $matchTime > $tt) {
                        continue;
                    }
                } else {
                    if ($matchTime < $tf && $matchTime > $tt) {
                        continue;
                    }
                }
            }
        }

        if ($statusFilter !== '') {
            $hasFt = $match['ft_home'] !== null && $match['ft_away'] !== null;
            if ($statusFilter === 'finished' && !$hasFt) continue;
            if ($statusFilter === 'upcoming' && $hasFt) continue;
        }

        $sameOrder = strcasecmp($home, $h2hHomeFilter) === 0 && strcasecmp($away, $h2hAwayFilter) === 0;
        $reverseOrder = strcasecmp($home, $h2hAwayFilter) === 0 && strcasecmp($away, $h2hHomeFilter) === 0;
        if (!$sameOrder && !$reverseOrder) {
            continue;
        }

        $h2hStats['total_meetings']++;
        $h2hMatches[] = $match;
        if ($match['ft_home'] === null || $match['ft_away'] === null) {
            continue;
        }

        $h2hStats['finished_meetings']++;
        $ftHome = (int)$match['ft_home'];
        $ftAway = (int)$match['ft_away'];
        $totalGoals = $ftHome + $ftAway;

        // W1/X/W2 dihitung relatif terhadap urutan tim yang dipilih (Home filter = W1, Away filter = W2).
        $team1Goals = strcasecmp($home, $h2hHomeFilter) === 0 ? $ftHome : $ftAway;
        $team2Goals = strcasecmp($away, $h2hAwayFilter) === 0 ? $ftAway : $ftHome;
        if ($team1Goals > $team2Goals) {
            $h2hStats['w1']++;
        } elseif ($team1Goals < $team2Goals) {
            $h2hStats['w2']++;
        } else {
            $h2hStats['x']++;
        }

        if ($totalGoals > 0) $h2hStats['over_05']++;
        if ($totalGoals > 1) $h2hStats['over_15']++;
        if ($totalGoals > 2) $h2hStats['over_25']++;
    }
}

if (!empty($h2hMatches)) {
    usort($h2hMatches, function ($left, $right) {
        $a = strtotime((string)($left['match_time'] ?? '')) ?: 0;
        $b = strtotime((string)($right['match_time'] ?? '')) ?: 0;
        return $b <=> $a;
    });
}

$h2hFinishedDenominator = max(1, $h2hStats['finished_meetings']);
$h2hPctOver05 = $h2hStats['finished_meetings'] > 0 ? round(($h2hStats['over_05'] / $h2hFinishedDenominator) * 100, 1) : 0;
$h2hPctOver15 = $h2hStats['finished_meetings'] > 0 ? round(($h2hStats['over_15'] / $h2hFinishedDenominator) * 100, 1) : 0;
$h2hPctOver25 = $h2hStats['finished_meetings'] > 0 ? round(($h2hStats['over_25'] / $h2hFinishedDenominator) * 100, 1) : 0;

// Build date pagination: collect all unique dates with data, then group by year-month
$datesWithData = [];
foreach ($allMatches as $match) {
    $d = substr((string)($match['match_time'] ?? ''), 0, 10);
    if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $datesWithData[$d] = true;
    }
}
ksort($datesWithData);
// Group by year-month
$datesByMonth = [];
foreach (array_keys($datesWithData) as $d) {
    $ym = substr($d, 0, 7);
    $datesByMonth[$ym][] = $d;
}
// Determine which month to display: prefer month of current date_from, fallback to latest month
$activeDateYm = substr($date_from, 0, 7);
if (!isset($datesByMonth[$activeDateYm])) {
    $activeDateYm = array_key_last($datesByMonth) ?? '';
}
$monthKeys = array_keys($datesByMonth);
?>

<div class="p-4 md:p-8 space-y-6 page-fade-in">
    <?php
    // Calculate quick stats
    $today = date('Y-m-d');
    $totalToday = 0;
    $finishedToday = 0;
    $pendingToday = 0;
    foreach ($allMatches as $m) {
        $mDate = substr($m['match_time'] ?? '', 0, 10);
        if ($mDate === $today) {
            $totalToday++;
            $hasFt = ($m['ft_home'] ?? '') !== '' && ($m['ft_away'] ?? '') !== '';
            if ($hasFt) $finishedToday++;
            else $pendingToday++;
        }
    }
    ?>
    
    <!-- Broadcast Header -->
    <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-5 md:p-6 shadow-xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.2em] text-amber-300 font-bold">Match Feed Monitor</p>
                <h1 class="text-2xl md:text-3xl font-black tracking-tight">
                    Semua <span class="text-amber-300">Pertandingan</span>
                </h1>
                <p class="text-slate-300 text-sm md:text-base">Monitoring data pertandingan real-time dari database.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/15 border border-emerald-400/30">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-200">Live</span>
                </div>
                <div class="px-3 py-2 rounded-lg bg-slate-700/70 border border-slate-600 text-xs font-bold text-slate-200"><?php echo date('d M Y'); ?></div>
            </div>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Hari Ini</p>
            <p class="mt-2 text-2xl font-black text-slate-900"><?php echo $totalToday; ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Selesai</p>
            <p class="mt-2 text-2xl font-black text-emerald-600"><?php echo $finishedToday; ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pending</p>
            <p class="mt-2 text-2xl font-black text-amber-600"><?php echo $pendingToday; ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Data</p>
            <p class="mt-2 text-2xl font-black text-blue-600"><?php echo number_format($total); ?></p>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6 transition-all">
        <form method="GET" class="space-y-5">
            <input type="hidden" name="page" value="matches">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-5">
                <!-- Search -->
                <div class="lg:col-span-3 space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Cari Tim
                    </label>
                    <div class="relative group">
                        <input type="text" 
                               id="teamSearch" 
                               name="search" 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" 
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all placeholder:text-slate-400 font-medium"
                               placeholder="Ketik nama tim..." 
                               autocomplete="off">
                        <div id="autocompleteResults" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-60 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>

                <!-- League Filter -->
                <div class="lg:col-span-2 space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        Liga
                    </label>
                    <div class="relative">
                        <select name="league" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm appearance-none transition-all cursor-pointer font-medium text-slate-700">
                            <option value="">Semua Liga</option>
                            <?php foreach ($leagues as $league): ?>
                                <option value="<?php echo htmlspecialchars($league); ?>" <?php echo ($_GET['league'] ?? '') == $league ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($league); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Date Range -->
                <div class="lg:col-span-3 grid grid-cols-2 gap-3">
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Dari</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                               class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]">
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Sampai</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                               class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]">
                    </div>
                </div>

                <!-- Time Range -->
                <div class="lg:col-span-2 grid grid-cols-2 gap-3">
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Jam</label>
                        <input type="text" name="time_from" value="<?php echo htmlspecialchars($timeFrom); ?>" placeholder="00:00" maxlength="5"
                               class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]">
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider block">-</label>
                        <input type="text" name="time_to" value="<?php echo htmlspecialchars($timeTo); ?>" placeholder="23:59" maxlength="5"
                               class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]">
                    </div>
                </div>

                <!-- Status Filter -->
                <div class="lg:col-span-2 space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Status
                    </label>
                    <div class="relative">
                        <select name="status" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm appearance-none transition-all cursor-pointer font-medium text-slate-700">
                            <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>Semua</option>
                            <option value="finished" <?php echo $statusFilter === 'finished' ? 'selected' : ''; ?>>Selesai</option>
                            <option value="upcoming" <?php echo $statusFilter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        </select>
                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">H2H Home Team</label>
                    <div class="relative group">
                        <input type="text"
                               id="homeTeamSearch"
                               name="h2h_home"
                               value="<?php echo htmlspecialchars($h2hHomeFilter, ENT_QUOTES, 'UTF-8'); ?>"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all placeholder:text-slate-400 font-medium"
                               placeholder="Pilih tim home untuk H2H..."
                               autocomplete="off">
                        <div id="homeAutocompleteResults" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-60 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">H2H Away Team</label>
                    <div class="relative group">
                        <input type="text"
                               id="awayTeamSearch"
                               name="h2h_away"
                               value="<?php echo htmlspecialchars($h2hAwayFilter, ENT_QUOTES, 'UTF-8'); ?>"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all placeholder:text-slate-400 font-medium"
                               placeholder="Pilih tim away untuk H2H..."
                               autocomplete="off">
                        <div id="awayAutocompleteResults" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-60 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="button"
                        id="h2hSwapBtn"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 text-xs font-bold uppercase tracking-wider hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h14m0 0l-3-3m3 3l-3 3M20 17H6m0 0l3-3m-3 3l3 3"/>
                    </svg>
                    Swap Home/Away
                </button>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-3 pt-2 flex-wrap">
                <input type="hidden" name="export" value="">
                <button type="submit" id="filterBtn"
                    class="flex-1 md:flex-none bg-slate-900 text-white px-8 py-3 rounded-xl font-bold text-sm hover:bg-slate-800 transition-all shadow-lg active:scale-95 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    Terapkan
                </button>
                <button type="button" id="exportBtn"
                    class="px-5 py-3 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition-all font-bold text-sm flex items-center gap-2 shadow-lg active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Export
                </button>
                <a href="index.php?page=matches" class="px-5 py-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-200 hover:text-slate-800 transition-all font-bold text-sm flex items-center gap-2">
                    <span>Reset</span>
                </a>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">H2H Over Summary</h2>
            <?php if ($h2hStats['active']): ?>
                <span class="text-xs font-semibold text-slate-500">
                    <?php echo htmlspecialchars($h2hStats['home'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($h2hStats['away'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($h2hStats['active']): ?>
            <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-slate-500">Total Pertemuan</p>
                    <p class="mt-1 text-2xl font-black text-slate-900"><?php echo (int)$h2hStats['total_meetings']; ?></p>
                    <p class="mt-1 text-xs font-bold text-slate-500 uppercase tracking-wider">
                        W1 <?php echo (int)$h2hStats['w1']; ?> | X <?php echo (int)$h2hStats['x']; ?> | W2 <?php echo (int)$h2hStats['w2']; ?>
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-slate-500">FT Available</p>
                    <p class="mt-1 text-2xl font-black text-slate-900"><?php echo (int)$h2hStats['finished_meetings']; ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-emerald-700">Over 0.5</p>
                    <p class="mt-1 text-xl font-black text-emerald-800"><?php echo (int)$h2hStats['over_05']; ?> <span class="text-sm font-bold text-emerald-700">/ <?php echo (int)$h2hStats['finished_meetings']; ?></span></p>
                    <p class="text-xs font-semibold text-emerald-700"><?php echo number_format($h2hPctOver05, 1); ?>%</p>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-blue-700">Over 1.5</p>
                    <p class="mt-1 text-xl font-black text-blue-800"><?php echo (int)$h2hStats['over_15']; ?> <span class="text-sm font-bold text-blue-700">/ <?php echo (int)$h2hStats['finished_meetings']; ?></span></p>
                    <p class="text-xs font-semibold text-blue-700"><?php echo number_format($h2hPctOver15, 1); ?>%</p>
                </div>
                <div class="rounded-xl border border-purple-200 bg-purple-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-purple-700">Over 2.5</p>
                    <p class="mt-1 text-xl font-black text-purple-800"><?php echo (int)$h2hStats['over_25']; ?> <span class="text-sm font-bold text-purple-700">/ <?php echo (int)$h2hStats['finished_meetings']; ?></span></p>
                    <p class="text-xs font-semibold text-purple-700"><?php echo number_format($h2hPctOver25, 1); ?>%</p>
                </div>
            </div>

            <div class="mt-4">
                <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">H2H Match List</h3>
                <?php if (!empty($h2hMatches)): ?>
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="max-h-80 overflow-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wider">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Waktu</th>
                                        <th class="px-3 py-2 text-left">Match</th>
                                        <th class="px-3 py-2 text-center">Score FT</th>
                                        <th class="px-3 py-2 text-center">Goals</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($h2hMatches as $h2hMatch): ?>
                                        <?php
                                            $mTime = (string)($h2hMatch['match_time'] ?? '');
                                            try { $h2hDate = new DateTime($mTime); } catch (\Exception $e) { $h2hDate = null; }
                                            $ftHome = $h2hMatch['ft_home'];
                                            $ftAway = $h2hMatch['ft_away'];
                                            $hasFt = $ftHome !== null && $ftAway !== null;
                                            $goals = $hasFt ? ((int)$ftHome + (int)$ftAway) : null;
                                        ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-3 py-2 text-slate-600 whitespace-nowrap">
                                                <?php echo $h2hDate ? $h2hDate->format('d M Y H:i') : htmlspecialchars($mTime, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-slate-800 font-semibold">
                                                <?php echo htmlspecialchars((string)($h2hMatch['home_team'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                <span class="text-slate-400 font-normal">vs</span>
                                                <?php echo htmlspecialchars((string)($h2hMatch['away_team'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <?php if ($hasFt): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold border border-emerald-200">
                                                        <?php echo (int)$ftHome; ?> - <?php echo (int)$ftAway; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-amber-50 text-amber-700 font-bold border border-amber-200">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2 text-center text-slate-700 font-bold">
                                                <?php echo $goals !== null ? (int)$goals : '-'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm font-medium text-slate-500">
                        Tidak ada match H2H untuk pasangan tim ini pada filter yang dipilih.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm font-medium text-slate-500">
                Isi kedua input H2H (Home dan Away) lalu klik <strong>Terapkan</strong> untuk melihat statistik Over 0.5 / 1.5 / 2.5.
            </div>
        <?php endif; ?>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="hidden fixed inset-0 z-[9999] bg-white/70 backdrop-blur-sm flex items-center justify-center">
        <div class="flex flex-col items-center gap-4 bg-white rounded-2xl shadow-xl px-10 py-8 border border-slate-100">
            <svg class="animate-spin w-10 h-10 text-blue-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>
            <span class="text-sm font-bold text-slate-600 tracking-wide">Memuat data...</span>
        </div>
    </div>

    <!-- Date Pagination -->
    <?php if (!empty($datesByMonth)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <!-- Month Tabs -->
        <div class="flex items-center gap-0 border-b border-slate-100 overflow-x-auto scrollbar-none" id="monthTabBar">
            <?php foreach ($monthKeys as $ym): ?>
            <?php
                [$yr, $mo] = explode('-', $ym);
                $monthLabel = DateTime::createFromFormat('Y-m', $ym)->format('M Y');
                $isActiveTab = $ym === $activeDateYm;
            ?>
            <button type="button"
                onclick="showMonth('<?= htmlspecialchars($ym) ?>')"
                id="tab-<?= $ym ?>"
                class="month-tab shrink-0 px-4 py-3 min-h-[44px] text-xs font-bold uppercase tracking-wide transition-all border-b-2 whitespace-nowrap
                    <?= $isActiveTab ? 'border-blue-600 text-blue-600 bg-blue-50' : 'border-transparent text-slate-400 hover:text-slate-700 hover:bg-slate-50' ?>">
                <?= htmlspecialchars($monthLabel) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <!-- Date Buttons per Month -->
        <?php foreach ($datesByMonth as $ym => $days): ?>
        <div id="month-<?= $ym ?>" class="month-panel px-4 py-3 <?= $ym !== $activeDateYm ? 'hidden' : '' ?>">
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($days as $d): ?>
                <?php
                    $dayNum   = (int)substr($d, 8, 2);
                    $dayName  = (new DateTime($d))->format('D');
                    $isSun    = (new DateTime($d))->format('N') == 7;
                    $isSat    = (new DateTime($d))->format('N') == 6;
                    $isActive = ($d === $date_from && $d === $date_to);
                    // Build URL preserving current filters but overriding dates and resetting page
                    $params = ['page' => 'matches', 'date_from' => $d, 'date_to' => $d, 'p' => '1'];
                    foreach (['search','home_team','away_team','h2h_home','h2h_away','league','sort','order','per_page','time_from','time_to','status'] as $k) {
                        if (!empty($_GET[$k])) $params[$k] = $_GET[$k];
                    }
                    $href = 'index.php?' . http_build_query($params);
                    if ($isActive) {
                        $btnClass = 'bg-blue-600 text-white border-blue-600 shadow-md shadow-blue-600/20';
                    } elseif ($isSun) {
                        $btnClass = 'bg-rose-50 text-rose-600 border-rose-200 hover:bg-rose-100';
                    } elseif ($isSat) {
                        $btnClass = 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100';
                    } else {
                        $btnClass = 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100 hover:border-slate-300';
                    }
                ?>
                <a href="<?= htmlspecialchars($href) ?>"
                   class="flex flex-col items-center px-2 py-2 rounded-lg border text-center min-w-[44px] min-h-[44px] justify-center transition-all <?= $btnClass ?>">
                    <span class="text-[9px] font-bold uppercase leading-none mb-0.5 opacity-70"><?= $dayName ?></span>
                    <span class="text-sm font-black leading-none"><?= $dayNum ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Results Area -->
    <div class="space-y-4">
        <?php if (count($pagedMatches) > 0): ?>
            
            <!-- Desktop Table View -->
            <div class="hidden md:block bg-white rounded-2xl shadow-md border-0 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[860px]">
                    <thead>
                        <tr class="bg-slate-900 text-white">
                            <th class="px-6 py-4 text-left text-[11px] font-bold uppercase tracking-wider">
                                <a href="<?php echo htmlspecialchars(matchesBuildQuery(['sort' => 'match_time', 'order' => ($sort == 'match_time' && $order == 'desc') ? 'asc' : 'desc', 'p' => '1'])); ?>"
                                    class="flex items-center gap-1 hover:text-amber-300 transition-colors">
                                    Waktu
                                    <?php if ($sort == 'match_time'): ?>
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <?php if ($order == 'asc'): ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            <?php else: ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-4 text-center text-[11px] font-bold uppercase tracking-wider">
                                <a href="<?php echo htmlspecialchars(matchesBuildQuery(['sort' => 'home_team', 'order' => ($sort == 'home_team' && $order == 'asc') ? 'desc' : 'asc', 'p' => '1'])); ?>"
                                    class="flex items-center justify-center gap-1 hover:text-amber-300 transition-colors">
                                    Pertandingan
                                    <?php if ($sort == 'home_team' || $sort == 'away_team'): ?>
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <?php if ($order == 'asc'): ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            <?php else: ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-6 py-4 text-center text-[11px] font-bold uppercase tracking-wider">Skor</th>
                            <th class="px-6 py-4 text-left text-[11px] font-bold uppercase tracking-wider">H2H Summary</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($pagedMatches as $match):
                            try { $date = new DateTime($match['match_time']); } catch (\Exception $e) { $date = null; }
                            $hasFt = ($match['ft_home'] ?? '') !== '' && ($match['ft_away'] ?? '') !== '';
                            $h2hTimeSummary = matchesBuildH2hTimeSummary($allMatches, $match);
                        ?>
                            <tr class="hover:bg-blue-50/50 transition-all duration-200 group">
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-xl <?php echo $hasFt ? 'bg-emerald-100 border border-emerald-200' : 'bg-amber-100 border border-amber-200'; ?> flex flex-col items-center justify-center">
                                            <span class="text-[10px] font-bold uppercase leading-none mb-0.5 <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?>"><?php echo $date ? $date->format('M') : '--'; ?></span>
                                            <span class="text-lg font-black <?php echo $hasFt ? 'text-emerald-700' : 'text-amber-700'; ?> leading-none"><?php echo $date ? $date->format('d') : '--'; ?></span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-xs font-bold text-slate-400 mb-0.5"><?php echo $date ? $date->format('Y') : '--'; ?></span>
                                            <span class="text-sm font-bold text-slate-700"><?php echo $date ? $date->format('H:i') : '--:--'; ?> <span class="text-slate-400 font-normal">WIB</span></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-center gap-4">
                                            <div class="flex-1 text-right">
                                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors line-clamp-1">
                                                    <?php echo htmlspecialchars($match['home_team']); ?>
                                                </h3>
                                            </div>
                                            <div class="w-8 h-8 rounded-full bg-slate-900 flex items-center justify-center shrink-0">
                                                <span class="text-[10px] font-black text-white">VS</span>
                                            </div>
                                            <div class="flex-1 text-left">
                                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors line-clamp-1">
                                                    <?php echo htmlspecialchars($match['away_team']); ?>
                                                </h3>
                                            </div>
                                        </div>
                                        <div class="flex justify-center">
                                            <span class="inline-block text-[9px] font-semibold text-slate-500 bg-slate-100/80 px-2.5 py-1 rounded-md border border-slate-200 uppercase tracking-[0.12em] truncate max-w-[260px]"
                                                  title="<?php echo htmlspecialchars($match['league']); ?>">
                                                <?php echo htmlspecialchars($match['league']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="flex items-center gap-2 <?php echo $hasFt ? 'bg-emerald-600' : 'bg-slate-400'; ?> px-4 py-2 rounded-xl shadow-sm">
                                            <span class="text-lg font-black text-white"><?php echo $match['ft_home'] !== null ? $match['ft_home'] : '-'; ?></span>
                                            <span class="text-slate-300 font-bold">:</span>
                                            <span class="text-lg font-black text-white"><?php echo $match['ft_away'] !== null ? $match['ft_away'] : '-'; ?></span>
                                        </div>
                                        <?php if ($match['fh_home'] !== null): ?>
                                            <span class="text-[10px] font-bold <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?> bg-white px-2 py-0.5 rounded-md border border-slate-200">
                                                HT: <?php echo $match['fh_home']; ?>-<?php echo $match['fh_away']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-5 align-top">
                                    <div class="min-w-[220px] space-y-2">
                                        <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                            <span class="inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700">
                                                Jam <?php echo htmlspecialchars($h2hTimeSummary['time'] !== '' ? $h2hTimeSummary['time'] : '--:--', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span><?php echo (int)$h2hTimeSummary['finished_meetings']; ?>/<?php echo (int)$h2hTimeSummary['total_meetings']; ?> selesai</span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 text-[11px] font-bold">
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700">
                                                Total: <?php echo (int)$h2hTimeSummary['total_meetings']; ?>
                                            </div>
                                            <div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">
                                                U0.5: <?php echo (int)$h2hTimeSummary['under_05']; ?>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 text-center">
                                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-amber-700">W1</p>
                                                <p class="mt-1 text-sm font-black text-amber-800"><?php echo (int)$h2hTimeSummary['w1']; ?></p>
                                            </div>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-700">X</p>
                                                <p class="mt-1 text-sm font-black text-slate-800"><?php echo (int)$h2hTimeSummary['x']; ?></p>
                                            </div>
                                            <div class="rounded-xl border border-cyan-200 bg-cyan-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-cyan-700">W2</p>
                                                <p class="mt-1 text-sm font-black text-cyan-800"><?php echo (int)$h2hTimeSummary['w2']; ?></p>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 text-center">
                                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">O0.5</p>
                                                <p class="mt-1 text-sm font-black text-emerald-800"><?php echo (int)$h2hTimeSummary['over_05']; ?></p>
                                            </div>
                                            <div class="rounded-xl border border-blue-200 bg-blue-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-blue-700">O1.5</p>
                                                <p class="mt-1 text-sm font-black text-blue-800"><?php echo (int)$h2hTimeSummary['over_15']; ?></p>
                                            </div>
                                            <div class="rounded-xl border border-violet-200 bg-violet-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-violet-700">O2.5</p>
                                                <p class="mt-1 text-sm font-black text-violet-800"><?php echo (int)$h2hTimeSummary['over_25']; ?></p>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 text-center">
                                            <div class="rounded-xl border border-teal-200 bg-teal-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-teal-700">BTTS</p>
                                                <p class="mt-1 text-sm font-black text-teal-800"><?php echo (int)$h2hTimeSummary['btts']; ?></p>
                                            </div>
                                            <div class="rounded-xl border border-orange-200 bg-orange-50 px-2 py-2">
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-orange-700">No BTTS</p>
                                                <p class="mt-1 text-sm font-black text-orange-800"><?php echo (int)$h2hTimeSummary['no_btts']; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Mobile Card View -->
            <div class="md:hidden space-y-3">
                <?php foreach ($pagedMatches as $match):
                    try { $date = new DateTime($match['match_time']); } catch (\Exception $e) { $date = null; }
                    $hasFt = ($match['ft_home'] ?? '') !== '' && ($match['ft_away'] ?? '') !== '';
                    $h2hTimeSummary = matchesBuildH2hTimeSummary($allMatches, $match);
                ?>
                    <div class="bg-white rounded-2xl p-4 border-0 shadow-md relative overflow-hidden">
                        <?php if ($hasFt): ?>
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-emerald-500"></div>
                        <?php else: ?>
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-amber-500"></div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between mb-3 pl-2">
                            <div class="flex items-center gap-2">
                                <div class="px-2 py-1 <?php echo $hasFt ? 'bg-emerald-100 border border-emerald-200' : 'bg-amber-100 border border-amber-200'; ?> rounded-lg">
                                    <span class="text-xs font-bold <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?>"><?php echo $date ? $date->format('d M') : '--'; ?></span>
                                </div>
                                <span class="text-xs font-bold text-slate-400"><?php echo $date ? $date->format('H:i') : '--:--'; ?></span>
                            </div>
                            <span class="text-[10px] font-bold <?php echo $hasFt ? 'text-emerald-600 bg-emerald-50' : 'text-amber-600 bg-amber-50'; ?> px-2 py-1 rounded border <?php echo $hasFt ? 'border-emerald-200' : 'border-amber-200'; ?> uppercase tracking-wide truncate max-w-[120px]">
                                <?php echo htmlspecialchars($match['league']); ?>
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-3 pl-2">
                            <!-- Home -->
                            <div class="flex-1 flex flex-col items-center text-center gap-1">
                                <span class="text-sm font-bold text-slate-900 leading-tight line-clamp-2"><?php echo htmlspecialchars($match['home_team']); ?></span>
                            </div>

                            <!-- Score -->
                            <div class="flex flex-col items-center gap-1 shrink-0">
                                <div class="flex items-center gap-1.5 <?php echo $hasFt ? 'bg-emerald-600' : 'bg-slate-400'; ?> px-3 py-1.5 rounded-lg shadow-sm">
                                    <span class="text-base font-black text-white"><?php echo $match['ft_home'] !== null ? $match['ft_home'] : '-'; ?></span>
                                    <span class="text-slate-300 font-bold">:</span>
                                    <span class="text-base font-black text-white"><?php echo $match['ft_away'] !== null ? $match['ft_away'] : '-'; ?></span>
                                </div>
                                <?php if ($match['fh_home'] !== null): ?>
                                    <span class="text-[10px] font-bold <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?>">HT <?php echo $match['fh_home']; ?>-<?php echo $match['fh_away']; ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Away -->
                            <div class="flex-1 flex flex-col items-center text-center gap-1">
                                <span class="text-sm font-bold text-slate-900 leading-tight line-clamp-2"><?php echo htmlspecialchars($match['away_team']); ?></span>
                            </div>
                        </div>

                        <div class="mt-4 ml-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center justify-between gap-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                <span>H2H Jam <?php echo htmlspecialchars($h2hTimeSummary['time'] !== '' ? $h2hTimeSummary['time'] : '--:--', ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><?php echo (int)$h2hTimeSummary['finished_meetings']; ?>/<?php echo (int)$h2hTimeSummary['total_meetings']; ?> selesai</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-center">
                                <div class="rounded-lg bg-white px-2 py-2">
                                    <p class="text-[10px] font-bold text-slate-600">Total</p>
                                    <p class="mt-1 text-sm font-black text-slate-800"><?php echo (int)$h2hTimeSummary['total_meetings']; ?></p>
                                </div>
                                <div class="rounded-lg bg-rose-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-rose-700">U0.5</p>
                                    <p class="mt-1 text-sm font-black text-rose-800"><?php echo (int)$h2hTimeSummary['under_05']; ?></p>
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-amber-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-amber-700">W1</p>
                                    <p class="mt-1 text-sm font-black text-amber-800"><?php echo (int)$h2hTimeSummary['w1']; ?></p>
                                </div>
                                <div class="rounded-lg bg-white px-2 py-2">
                                    <p class="text-[10px] font-bold text-slate-700">X</p>
                                    <p class="mt-1 text-sm font-black text-slate-800"><?php echo (int)$h2hTimeSummary['x']; ?></p>
                                </div>
                                <div class="rounded-lg bg-cyan-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-cyan-700">W2</p>
                                    <p class="mt-1 text-sm font-black text-cyan-800"><?php echo (int)$h2hTimeSummary['w2']; ?></p>
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-emerald-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-emerald-700">O0.5</p>
                                    <p class="mt-1 text-sm font-black text-emerald-800"><?php echo (int)$h2hTimeSummary['over_05']; ?></p>
                                </div>
                                <div class="rounded-lg bg-blue-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-blue-700">O1.5</p>
                                    <p class="mt-1 text-sm font-black text-blue-800"><?php echo (int)$h2hTimeSummary['over_15']; ?></p>
                                </div>
                                <div class="rounded-lg bg-violet-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-violet-700">O2.5</p>
                                    <p class="mt-1 text-sm font-black text-violet-800"><?php echo (int)$h2hTimeSummary['over_25']; ?></p>
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-center">
                                <div class="rounded-lg bg-teal-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-teal-700">BTTS</p>
                                    <p class="mt-1 text-sm font-black text-teal-800"><?php echo (int)$h2hTimeSummary['btts']; ?></p>
                                </div>
                                <div class="rounded-lg bg-orange-50 px-2 py-2">
                                    <p class="text-[10px] font-bold text-orange-700">No BTTS</p>
                                    <p class="mt-1 text-sm font-black text-orange-800"><?php echo (int)$h2hTimeSummary['no_btts']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 shadow-sm">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 border border-slate-100">
                    <svg class="w-10 h-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                </div>
                <h3 class="text-lg font-bold text-slate-900 mb-2">Tidak Ada Data Ditemukan</h3>
                <p class="text-slate-500 mb-6">Coba ubah filter pencarian Anda atau tambahkan data baru.</p>
                <a href="index.php?page=matches" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-xl font-bold text-sm hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20">
                    Reset Filter
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <div class="flex flex-col md:flex-row items-center justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center md:text-left">
            <div>
                Halaman <span class="text-slate-900"><?php echo $p; ?></span> dari <span class="text-slate-900"><?php echo $totalPages; ?></span>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-slate-400">Tampil:</label>
                <select onchange="window.location.href=this.value" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-xs font-medium text-slate-700 cursor-pointer hover:bg-slate-100 transition-colors">
                    <?php 
                    foreach ($perPageOptions as $option):
                        $url = "?page=matches&p=1";
                        foreach ($_GET as $key => $val) {
                            if ($key != 'p' && $key != 'per_page') $url .= '&' . urlencode($key) . '=' . urlencode($val);
                        }
                        $url .= '&per_page=' . $option;
                    ?>
                        <option value="<?php echo $url; ?>" <?php echo $perPage == $option ? 'selected' : ''; ?>>
                            <?php echo $option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="flex flex-wrap justify-center items-center gap-1.5">
                <?php
                $queryString = '';
                foreach ($_GET as $key => $val) {
                    if ($key != 'p') $queryString .= '&' . urlencode($key) . '=' . urlencode($val);
                }
                $navBtnBase = 'h-11 min-w-[44px] flex items-center justify-center text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100 px-2 text-xs font-bold';
                $navBtnDisabled = 'h-11 min-w-[44px] flex items-center justify-center text-slate-300 px-2 rounded-xl cursor-not-allowed text-xs font-bold';
                ?>

                <!-- First -->
                <?php if ($p > 1): ?>
                    <a href="?p=1<?php echo $queryString; ?>" class="<?php echo $navBtnBase; ?>" title="Halaman pertama">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                    </a>
                <?php else: ?>
                    <span class="<?php echo $navBtnDisabled; ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg></span>
                <?php endif; ?>

                <!-- Prev -->
                <?php if ($p > 1): ?>
                    <a href="?p=<?php echo $p - 1; ?><?php echo $queryString; ?>" class="w-11 h-11 flex items-center justify-center text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100" title="Sebelumnya">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                <?php endif; ?>

                <div class="hidden md:flex items-center gap-1.5">
                    <?php
                    $start = max(1, $p - 2);
                    $end = min($totalPages, $p + 2);
                    if ($start > 1) echo '<span class="text-slate-300 px-1">...</span>';
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="?p=<?php echo $i; ?><?php echo $queryString; ?>"
                           class="w-11 h-11 flex items-center justify-center rounded-xl text-sm font-bold transition-all border <?php echo $i == $p ? 'bg-slate-900 text-white border-slate-900 shadow-lg shadow-slate-900/20 scale-105' : 'bg-white text-slate-500 border-slate-200 hover:border-blue-200 hover:text-blue-600 hover:bg-blue-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($end < $totalPages) echo '<span class="text-slate-300 px-1">...</span>'; ?>
                </div>

                <!-- Mobile Simple Pagination -->
                <div class="md:hidden flex items-center gap-2">
                    <span class="text-sm font-bold text-slate-900 bg-slate-100 px-3 py-2 rounded-lg"><?php echo $p; ?></span>
                </div>

                <!-- Next -->
                <?php if ($p < $totalPages): ?>
                    <a href="?p=<?php echo $p + 1; ?><?php echo $queryString; ?>" class="w-11 h-11 flex items-center justify-center text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100" title="Berikutnya">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                <?php endif; ?>

                <!-- Last -->
                <?php if ($p < $totalPages): ?>
                    <a href="?p=<?php echo $totalPages; ?><?php echo $queryString; ?>" class="<?php echo $navBtnBase; ?>" title="Halaman terakhir">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                    </a>
                <?php else: ?>
                    <span class="<?php echo $navBtnDisabled; ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Refresh button spin
document.addEventListener('DOMContentLoaded', function () {
    const refreshBtn = document.querySelector('[title="Refresh data"]');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            const icon = document.getElementById('refreshIcon');
            if (icon) icon.classList.add('animate-spin');
        });
    }
});

// Loading overlay on filter submit
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[method="GET"]');
    const overlay = document.getElementById('loadingOverlay');
    const exportInput = form ? form.querySelector('input[name="export"]') : null;
    const exportBtn = document.getElementById('exportBtn');

    if (form && overlay) {
        form.addEventListener('submit', function () {
            if (exportInput && exportInput.value === 'csv') return; // skip overlay for export
            overlay.classList.remove('hidden');
        });
    }

    // Export button: set export=csv on the hidden input then submit
    if (exportBtn && form && exportInput) {
        exportBtn.addEventListener('click', function () {
            exportInput.value = 'csv';
            form.submit();
            // Reset after brief delay so normal filter submit still works
            setTimeout(function () { exportInput.value = ''; }, 500);
        });
    }
});

function showMonth(ym) {
    document.querySelectorAll('.month-panel').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.month-tab').forEach(el => {
        el.classList.remove('border-blue-600','text-blue-600','bg-blue-50');
        el.classList.add('border-transparent','text-slate-400');
    });
    const panel = document.getElementById('month-' + ym);
    const tab   = document.getElementById('tab-' + ym);
    if (panel) panel.classList.remove('hidden');
    if (tab) {
        tab.classList.remove('border-transparent','text-slate-400');
        tab.classList.add('border-blue-600','text-blue-600','bg-blue-50');
        tab.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    }
}

const TEAMS_DATA = <?php echo json_encode($teams, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function setupAutocomplete(inputId, dropdownId, teams) {
    const input = document.getElementById(inputId);
    const dropdown = document.getElementById(dropdownId);
    if (!input || !dropdown) return;

    const ITEM_CLASS = 'px-4 py-3 cursor-pointer hover:bg-blue-50 text-sm font-medium text-slate-700 transition-colors flex items-center justify-between group';
    const CHECK_SVG = '<svg class="w-4 h-4 text-blue-400 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';

    input.addEventListener('input', function () {
        const query = this.value.toLowerCase();
        dropdown.innerHTML = '';
        if (query.length < 2) { dropdown.classList.add('hidden'); return; }

        const matches = teams.filter(t => t.toLowerCase().includes(query)).slice(0, 10);
        if (matches.length === 0) { dropdown.classList.add('hidden'); return; }

        matches.forEach(team => {
            const div = document.createElement('div');
            div.className = ITEM_CLASS;
            div.innerHTML = `<span>${team}</span>${CHECK_SVG}`;
            div.addEventListener('click', function () {
                input.value = team;
                dropdown.classList.add('hidden');
            });
            dropdown.appendChild(div);
        });
        dropdown.classList.remove('hidden');
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    setupAutocomplete('teamSearch',     'autocompleteResults',     TEAMS_DATA);
    setupAutocomplete('homeTeamSearch', 'homeAutocompleteResults', TEAMS_DATA);
    setupAutocomplete('awayTeamSearch', 'awayAutocompleteResults', TEAMS_DATA);

    const swapBtn = document.getElementById('h2hSwapBtn');
    const homeInput = document.getElementById('homeTeamSearch');
    const awayInput = document.getElementById('awayTeamSearch');
    if (swapBtn && homeInput && awayInput) {
        swapBtn.addEventListener('click', function () {
            const tmp = homeInput.value;
            homeInput.value = awayInput.value;
            awayInput.value = tmp;
            homeInput.focus();
        });
    }
});
</script>
