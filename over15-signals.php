<?php
date_default_timezone_set('Asia/Jakarta');

// Redirect ke index.php jika diakses langsung (tanpa di-include)
if (!defined('SABARAJA_APP')) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    $sep = $q !== '' ? '&' : '';
    header('Location: index.php?page=o15-signals' . $sep . $q);
    exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'matches_data_helper.php';

function o15SignalHasFinished(array $match): bool
{
    return $match['ft_home'] !== null && $match['ft_away'] !== null;
}

function o15SignalTotalGoals(array $match): int
{
    return (int) ($match['ft_home'] ?? 0) + (int) ($match['ft_away'] ?? 0);
}

function o15SignalSmoothedMean(float $sum, int $count, float $prior, int $strength): float
{
    return ($sum + ($prior * $strength)) / max(1, $count + $strength);
}

function o15SignalExpectedTotal(array $match, array $leagueStats, array $homeStats, array $awayStats, float $globalAvg): array
{
    $league = $match['league'] ?? '';
    $home = $match['home_team'] ?? '';
    $away = $match['away_team'] ?? '';

    $leagueRow = $leagueStats[$league] ?? ['count' => 0, 'goals' => 0.0];
    $leagueAvg = o15SignalSmoothedMean((float) $leagueRow['goals'], (int) $leagueRow['count'], $globalAvg, 10);

    $homeRow = $homeStats[$home] ?? ['count' => 0, 'goals' => 0.0];
    $awayRow = $awayStats[$away] ?? ['count' => 0, 'goals' => 0.0];

    $homeAvg = o15SignalSmoothedMean((float) $homeRow['goals'], (int) $homeRow['count'], $leagueAvg, 6);
    $awayAvg = o15SignalSmoothedMean((float) $awayRow['goals'], (int) $awayRow['count'], $leagueAvg, 6);

    $score = (0.45 * $leagueAvg) + (0.275 * $homeAvg) + (0.275 * $awayAvg);
    $probability = 1 - exp(-$score) * (1 + $score);

    return [
        'score' => $score,
        'probability' => max(0.0, min(1.0, $probability)),
        'league_avg' => $leagueAvg,
        'home_avg' => $homeAvg,
        'away_avg' => $awayAvg,
        'league_sample' => (int) $leagueRow['count'],
        'home_sample' => (int) $homeRow['count'],
        'away_sample' => (int) $awayRow['count'],
    ];
}

function o15SignalProbabilityFromScore(float $score): float
{
    $score = max(0.0, $score);
    $probability = 1 - exp(-$score) * (1 + $score);

    return max(0.0, min(1.0, $probability));
}

function o15SignalPairKey(string $home, string $away): string
{
    $teams = [$home, $away];
    sort($teams);

    return implode('||', $teams);
}

function o15SignalHistoryAverage(array $values, float $fallback): float
{
    if ($values === []) {
        return $fallback;
    }

    return array_sum($values) / count($values);
}

function o15SignalUnder15Rate(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    $underCount = 0;
    foreach ($values as $value) {
        if ((int) $value < 2) {
            $underCount++;
        }
    }

    return $underCount / count($values);
}

function o15SignalStdDev(array $values): float
{
    $count = count($values);
    if ($count <= 1) {
        return 0.0;
    }

    $mean = array_sum($values) / $count;
    $sumSquares = 0.0;
    foreach ($values as $value) {
        $sumSquares += ($value - $mean) * ($value - $mean);
    }

    return sqrt($sumSquares / $count);
}

function o15SignalAdjustedMetrics(
    array $match,
    array $baseMetrics,
    array $recentHomeTotals,
    array $recentAwayTotals,
    array $recentPairTotals,
    array $recentTeamTotals,
    ?float $prevSlotAvg
): array {
    $league = $match['league'] ?? '';
    $home = $match['home_team'] ?? '';
    $away = $match['away_team'] ?? '';
    $pairKey = o15SignalPairKey($home, $away);

    $homeHistory = array_slice($recentHomeTotals[$league][$home] ?? [], -5);
    $awayHistory = array_slice($recentAwayTotals[$league][$away] ?? [], -5);
    $awayHistoryLong = array_slice($recentAwayTotals[$league][$away] ?? [], -10);
    $pairHistory = array_slice($recentPairTotals[$league][$pairKey] ?? [], -5);

    $homeRecentAvg = o15SignalHistoryAverage($homeHistory, (float) $baseMetrics['home_avg']);
    $awayRecentAvg = o15SignalHistoryAverage($awayHistory, (float) $baseMetrics['away_avg']);
    $pairFallback = ($homeRecentAvg + $awayRecentAvg) / 2;
    $pairRecentAvg = o15SignalHistoryAverage($pairHistory, $pairFallback);

    $recentAnchor = (0.45 * $homeRecentAvg) + (0.35 * $awayRecentAvg) + (0.20 * $pairRecentAvg);
    $recentGapPenalty = max(0.0, (float) $baseMetrics['score'] - $recentAnchor) * 0.35;
    $underPenalty = (0.30 * o15SignalUnder15Rate($awayHistory)) + (0.15 * o15SignalUnder15Rate($pairHistory));
    $volatilityPenalty = (0.10 * o15SignalStdDev($awayHistoryLong)) + (0.08 * o15SignalStdDev($pairHistory));

    $prevPenalty = 0.0;
    $homeTeamHistory = $recentTeamTotals[$league][$home] ?? [];
    $awayTeamHistory = $recentTeamTotals[$league][$away] ?? [];
    if ($homeTeamHistory !== [] && $awayTeamHistory !== []) {
        $sumPrev = (float) end($homeTeamHistory) + (float) end($awayTeamHistory);
        if ($sumPrev <= 2.0) {
            $prevPenalty += 0.08;
        } elseif ($sumPrev <= 4.0) {
            $prevPenalty += 0.04;
        } elseif ($sumPrev >= 7.0) {
            $prevPenalty -= 0.03;
        }
    }

    if ($prevSlotAvg !== null) {
        if ($prevSlotAvg <= 1.5) {
            $prevPenalty += 0.04;
        } elseif ($prevSlotAvg >= 3.5) {
            $prevPenalty -= 0.02;
        }
    }

    $rngPenalty = $recentGapPenalty + $underPenalty + $volatilityPenalty + $prevPenalty;
    $adjustedScore = max(0.0, (float) $baseMetrics['score'] - $rngPenalty);

    return [
        'score' => $adjustedScore,
        'probability' => o15SignalProbabilityFromScore($adjustedScore),
        'rng_penalty' => $rngPenalty,
        'recent_anchor' => $recentAnchor,
        'recent_gap_penalty' => $recentGapPenalty,
        'under_penalty' => $underPenalty,
        'volatility_penalty' => $volatilityPenalty,
        'previous_penalty' => $prevPenalty,
    ];
}

function o15SignalFormatPct(float $value): string
{
    return number_format($value * 100, 1) . '%';
}

function o15SignalFormatDec(float $value): string
{
    return number_format($value, 2);
}

function o15SignalBuildUrl(array $extra = []): string
{
    $params = [
        'page' => 'o15-signals',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'league' => $_GET['league'] ?? '',
        'threshold' => $_GET['threshold'] ?? '',
        'team_floor' => $_GET['team_floor'] ?? '',
        'min_history' => $_GET['min_history'] ?? '',
        'show_all' => $_GET['show_all'] ?? '',
    ];

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return 'index.php?' . http_build_query($params);
}

$today = date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? $today;
$dateTo = $_GET['date_to'] ?? $today;
$leagueFilter = trim((string) ($_GET['league'] ?? ''));
$threshold = (float) ($_GET['threshold'] ?? '2.40');
$teamFloor = (float) ($_GET['team_floor'] ?? '2.70');
$minHistory = (int) ($_GET['min_history'] ?? 300);
$showAll = ($_GET['show_all'] ?? '0') === '1';

if (strtotime($dateFrom) === false) {
    $dateFrom = $today;
}
if (strtotime($dateTo) === false) {
    $dateTo = $today;
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
if ($threshold < 1.50 || $threshold > 4.50) {
    $threshold = 2.40;
}
if ($teamFloor < 0.00 || $teamFloor > 4.50) {
    $teamFloor = 2.70;
}
if ($minHistory < 50) {
    $minHistory = 50;
}

$matches = [];
$leagueSet = [];

if (sabarajaDataCsvAvailable()) {
    sabarajaDataReadCsv(function (array $match) use (&$matches, &$leagueSet): void {
        $matches[] = $match;
        if (($match['league'] ?? '') !== '') {
            $leagueSet[$match['league']] = true;
        }
    });
}

usort($matches, static function (array $a, array $b): int {
    $cmp = strcmp((string) ($a['match_time'] ?? ''), (string) ($b['match_time'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    $cmp = strcmp((string) ($a['league'] ?? ''), (string) ($b['league'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    $cmp = strcmp((string) ($a['home_team'] ?? ''), (string) ($b['home_team'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    return strcmp((string) ($a['away_team'] ?? ''), (string) ($b['away_team'] ?? ''));
});

$leagueList = array_keys($leagueSet);
sort($leagueList);

$leagueStats = [];
$homeStats = [];
$awayStats = [];
$recentHomeTotals = [];
$recentAwayTotals = [];
$recentPairTotals = [];
$recentTeamTotals = [];
$lastSlotAvgByLeague = [];
$globalFinishedCount = 0;
$globalFinishedGoals = 0.0;

$signalRows = [];
$validation = [
    'predictions' => 0,
    'hits' => 0,
    'signal_predictions' => 0,
    'signal_hits' => 0,
    'brier_sum' => 0.0,
];

$groupedMatches = [];
foreach ($matches as $match) {
    $groupedMatches[$match['match_time']][] = $match;
}

foreach ($groupedMatches as $matchTime => $groupMatches) {
    $globalAvg = $globalFinishedCount > 0 ? ($globalFinishedGoals / $globalFinishedCount) : 2.75;
    $slotAverages = [];
    foreach ($groupMatches as $groupMatch) {
        $leagueName = $groupMatch['league'] ?? '';
        if (!array_key_exists($leagueName, $slotAverages)) {
            $slotAverages[$leagueName] = $lastSlotAvgByLeague[$leagueName] ?? null;
        }
    }

    foreach ($groupMatches as $match) {
        if ($globalFinishedCount < $minHistory) {
            continue;
        }

        $metrics = o15SignalExpectedTotal($match, $leagueStats, $homeStats, $awayStats, $globalAvg);
        $adjustedMetrics = o15SignalAdjustedMetrics(
            $match,
            $metrics,
            $recentHomeTotals,
            $recentAwayTotals,
            $recentPairTotals,
            $recentTeamTotals,
            $slotAverages[$match['league'] ?? ''] ?? null
        );
        $matchDate = substr((string) $matchTime, 0, 10);
        $teamBalance = min($metrics['home_avg'], $metrics['away_avg']);
        $isSignal = $adjustedMetrics['score'] >= $threshold && $teamBalance >= $teamFloor;

        if (o15SignalHasFinished($match)) {
            $outcome = o15SignalTotalGoals($match) >= 2 ? 1 : 0;
            $validation['predictions']++;
            $validation['hits'] += $outcome;
            $validation['brier_sum'] += ($adjustedMetrics['probability'] - $outcome) * ($adjustedMetrics['probability'] - $outcome);

            if ($isSignal) {
                $validation['signal_predictions']++;
                $validation['signal_hits'] += $outcome;
            }

            continue;
        }

        if ($matchDate < $dateFrom || $matchDate > $dateTo) {
            continue;
        }
        if ($leagueFilter !== '' && ($match['league'] ?? '') !== $leagueFilter) {
            continue;
        }
        if (!$showAll && !$isSignal) {
            continue;
        }

        $signalRows[] = [
            'match_time' => $matchTime,
            'league' => $match['league'] ?? '',
            'home_team' => $match['home_team'] ?? '',
            'away_team' => $match['away_team'] ?? '',
            'base_score' => $metrics['score'],
            'score' => $adjustedMetrics['score'],
            'probability' => $adjustedMetrics['probability'],
            'rng_penalty' => $adjustedMetrics['rng_penalty'],
            'league_avg' => $metrics['league_avg'],
            'home_avg' => $metrics['home_avg'],
            'away_avg' => $metrics['away_avg'],
            'team_balance' => $teamBalance,
            'league_sample' => $metrics['league_sample'],
            'home_sample' => $metrics['home_sample'],
            'away_sample' => $metrics['away_sample'],
            'grade' => $isSignal && $adjustedMetrics['score'] >= max(2.60, $threshold + 0.20) ? 'Strong' : ($isSignal ? 'Valid' : 'Watchlist'),
        ];
    }

    $slotTotalsByLeague = [];
    foreach ($groupMatches as $match) {
        if (!o15SignalHasFinished($match)) {
            continue;
        }

        $totalGoals = o15SignalTotalGoals($match);
        $league = $match['league'] ?? '';
        $home = $match['home_team'] ?? '';
        $away = $match['away_team'] ?? '';

        if (!isset($leagueStats[$league])) {
            $leagueStats[$league] = ['count' => 0, 'goals' => 0.0];
        }
        if (!isset($homeStats[$home])) {
            $homeStats[$home] = ['count' => 0, 'goals' => 0.0];
        }
        if (!isset($awayStats[$away])) {
            $awayStats[$away] = ['count' => 0, 'goals' => 0.0];
        }

        $leagueStats[$league]['count']++;
        $leagueStats[$league]['goals'] += $totalGoals;
        $homeStats[$home]['count']++;
        $homeStats[$home]['goals'] += $totalGoals;
        $awayStats[$away]['count']++;
        $awayStats[$away]['goals'] += $totalGoals;
        $recentHomeTotals[$league][$home][] = $totalGoals;
        $recentAwayTotals[$league][$away][] = $totalGoals;
        $recentTeamTotals[$league][$home][] = $totalGoals;
        $recentTeamTotals[$league][$away][] = $totalGoals;
        $recentPairTotals[$league][o15SignalPairKey($home, $away)][] = $totalGoals;
        $slotTotalsByLeague[$league][] = $totalGoals;
        $globalFinishedCount++;
        $globalFinishedGoals += $totalGoals;
    }

    foreach ($slotTotalsByLeague as $league => $slotTotals) {
        $lastSlotAvgByLeague[$league] = array_sum($slotTotals) / count($slotTotals);
    }
}

usort($signalRows, static function (array $a, array $b): int {
    if ($a['score'] === $b['score']) {
        return strcmp($a['match_time'], $b['match_time']);
    }

    return $a['score'] < $b['score'] ? 1 : -1;
});

$strongSignals = count(array_filter($signalRows, static fn(array $row): bool => $row['score'] >= max(2.60, $threshold + 0.20)));
$signalWinrate = $validation['signal_predictions'] > 0
    ? ($validation['signal_hits'] / $validation['signal_predictions'])
    : 0.0;
$baseWinrate = $validation['predictions'] > 0
    ? ($validation['hits'] / $validation['predictions'])
    : 0.0;
$coverage = $validation['predictions'] > 0
    ? ($validation['signal_predictions'] / $validation['predictions'])
    : 0.0;
$brierScore = $validation['predictions'] > 0
    ? ($validation['brier_sum'] / $validation['predictions'])
    : 0.0;
?>

<div class="relative isolate overflow-hidden rounded-3xl border border-slate-200 bg-gradient-to-br from-white via-slate-50 to-emerald-50/40 p-4 md:p-8 space-y-6 page-fade-in">
    <div class="pointer-events-none absolute -top-20 right-0 h-72 w-72 rounded-full bg-emerald-200/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-16 left-0 h-64 w-64 rounded-full bg-sky-200/30 blur-3xl"></div>
    <div class="relative overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-800 p-5 text-white shadow-xl shadow-slate-950/15 md:p-6">
        <div class="pointer-events-none absolute -left-20 top-0 h-48 w-48 rounded-full bg-sky-400/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-20 -right-16 h-56 w-56 rounded-full bg-emerald-400/20 blur-3xl"></div>
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.2em] text-emerald-300 font-bold">Model Over 1.5</p>
                <h1 class="text-2xl md:text-3xl font-black tracking-tight">Signal Generator <span class="text-emerald-300">O1.5</span></h1>
                <p class="text-slate-300 text-sm md:text-base">Signal live sekarang pakai adjusted score: base model diturunkan oleh recent form, RNG penalty, dan previous-slot context.</p>
            </div>
            <div class="rounded-xl bg-white/10 border border-white/10 px-4 py-3 text-sm text-slate-200">
                <div>Adjusted threshold: <span class="font-black text-white"><?= htmlspecialchars(o15SignalFormatDec($threshold)) ?></span></div>
                <div>Team floor: <span class="font-black text-white"><?= htmlspecialchars(o15SignalFormatDec($teamFloor)) ?></span></div>
                <div>Warmup histori: <span class="font-black text-white"><?= number_format($minHistory) ?></span> match selesai</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-4 shadow-lg shadow-slate-900/5">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-slate-900 via-emerald-500 to-sky-400"></div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Signal Aktif</p>
            <p class="mt-2 text-2xl font-black text-slate-900"><?= number_format(count($signalRows)) ?></p>
            <p class="mt-1 text-xs text-slate-500">Sesuai filter tanggal dan league.</p>
        </div>
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-4 shadow-lg shadow-slate-900/5">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-slate-900 via-emerald-500 to-sky-400"></div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Strong Signal</p>
            <p class="mt-2 text-2xl font-black text-emerald-600"><?= number_format($strongSignals) ?></p>
            <p class="mt-1 text-xs text-slate-500">Adjusted score >= <?= htmlspecialchars(o15SignalFormatDec(max(2.60, $threshold + 0.20))) ?>.</p>
        </div>
            <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-4 shadow-lg shadow-slate-900/5">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-slate-900 via-emerald-500 to-sky-400"></div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Winrate Validasi</p>
            <p class="mt-2 text-2xl font-black text-blue-600"><?= htmlspecialchars(o15SignalFormatPct($signalWinrate)) ?></p>
            <p class="mt-1 text-xs text-slate-500">Hit rate historis untuk signal dengan filter team floor aktif.</p>
        </div>
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-4 shadow-lg shadow-slate-900/5">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-slate-900 via-emerald-500 to-sky-400"></div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Coverage</p>
            <p class="mt-2 text-2xl font-black text-amber-600"><?= htmlspecialchars(o15SignalFormatPct($coverage)) ?></p>
            <p class="mt-1 text-xs text-slate-500">Porsi match historis yang lolos threshold.</p>
        </div>
    </div>

    <form method="GET" class="rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-5 shadow-lg shadow-slate-900/5 transition-all md:p-6">
        <input type="hidden" name="page" value="o15-signals">

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-3">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Dari Tanggal</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="h-[46px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-medium transition duration-200 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Sampai Tanggal</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="h-[46px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-medium transition duration-200 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">League</label>
                <select name="league" class="h-[46px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-medium transition duration-200 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
                    <option value="">Semua League</option>
                    <?php foreach ($leagueList as $leagueName): ?>
                        <option value="<?= htmlspecialchars($leagueName) ?>" <?= $leagueFilter === $leagueName ? 'selected' : '' ?>><?= htmlspecialchars($leagueName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Threshold</label>
                <input type="number" step="0.01" min="1.50" max="4.50" name="threshold" value="<?= htmlspecialchars(o15SignalFormatDec($threshold)) ?>" class="h-[46px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-medium transition duration-200 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Team Floor</label>
                <input type="number" step="0.01" min="0.00" max="4.50" name="team_floor" value="<?= htmlspecialchars(o15SignalFormatDec($teamFloor)) ?>" class="h-[46px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-medium transition duration-200 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Min History</label>
                <input type="number" step="50" min="50" name="min_history" value="<?= (int) $minHistory ?>" class="h-[46px] rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-medium transition duration-200 focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-emerald-500/10">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-slate-900 via-slate-800 to-emerald-700 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-slate-900/10 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl">Update Signal</button>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
            <label class="inline-flex items-center gap-2 text-slate-600">
                <input type="checkbox" name="show_all" value="1" <?= $showAll ? 'checked' : '' ?> class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                Tampilkan semua upcoming match
            </label>
            <a href="<?= htmlspecialchars(o15SignalBuildUrl(['date_from' => $today, 'date_to' => $today, 'league' => '', 'threshold' => '2.40', 'team_floor' => '2.70', 'min_history' => 300, 'show_all' => ''])) ?>" class="text-xs font-bold text-slate-500 hover:text-slate-800">Reset ke setting default</a>
        </div>
    </form>

    <div class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
        <div class="rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-5 shadow-lg shadow-slate-900/5">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-black text-slate-900">Adjusted Model Aktif</h2>
                    <p class="text-sm text-slate-500">Base score tetap dihitung, lalu dipotong RNG penalty sebelum dijadikan signal.</p>
                </div>
                <span class="rounded-full border border-emerald-200 bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-700 shadow-sm shadow-emerald-900/5">Pre-match only</span>
            </div>
            <div class="relative overflow-hidden rounded-xl border border-slate-700 bg-gradient-to-b from-slate-950 to-slate-900 p-4 text-slate-100 shadow-inner">
                <div class="pointer-events-none absolute -left-10 -top-10 h-32 w-32 rounded-full bg-emerald-400/10 blur-2xl"></div>
<pre class="text-xs leading-6 whitespace-pre-wrap">league_avg = (sum_league_goals + global_avg * 10) / (league_matches + 10)
home_avg   = (sum_home_match_goals + league_avg * 6) / (home_matches + 6)
away_avg   = (sum_away_match_goals + league_avg * 6) / (away_matches + 6)
team_floor = min(home_avg, away_avg)

base_score_o15 = 0.45 * league_avg
               + 0.275 * home_avg
               + 0.275 * away_avg

recent_anchor = 0.45 * home_last5_avg
              + 0.35 * away_last5_avg
              + 0.20 * h2h_last5_avg

rng_penalty = recent_gap_penalty
            + under_penalty
            + volatility_penalty
            + previous_match_penalty

adjusted_score = base_score_o15 - rng_penalty

signal = adjusted_score >= <?= htmlspecialchars(o15SignalFormatDec($threshold)) ?>
       && team_floor >= <?= htmlspecialchars(o15SignalFormatDec($teamFloor)) ?></pre>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-5 shadow-lg shadow-slate-900/5 space-y-4">
            <div>
                <h2 class="text-lg font-black text-slate-900">Validasi Historis</h2>
                <p class="text-sm text-slate-500">Dievaluasi walk-forward per timestamp, tanpa bocor hasil match di jam yang sama.</p>
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl border border-slate-200 bg-white/80 p-3 shadow-sm shadow-slate-900/5">
                    <div class="text-[11px] uppercase tracking-wider font-bold text-slate-400">League O1.5</div>
                    <div class="mt-2 text-xl font-black text-slate-900"><?= htmlspecialchars(o15SignalFormatPct($baseWinrate)) ?></div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white/80 p-3 shadow-sm shadow-slate-900/5">
                    <div class="text-[11px] uppercase tracking-wider font-bold text-slate-400">Brier Score</div>
                    <div class="mt-2 text-xl font-black text-slate-900"><?= htmlspecialchars(number_format($brierScore, 4)) ?></div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white/80 p-3 shadow-sm shadow-slate-900/5">
                    <div class="text-[11px] uppercase tracking-wider font-bold text-slate-400">Prediksi Histori</div>
                    <div class="mt-2 text-xl font-black text-slate-900"><?= number_format($validation['predictions']) ?></div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white/80 p-3 shadow-sm shadow-slate-900/5">
                    <div class="text-[11px] uppercase tracking-wider font-bold text-slate-400">Adjusted Signal</div>
                    <div class="mt-2 text-xl font-black text-slate-900"><?= number_format($validation['signal_predictions']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg shadow-slate-900/5">
        <div class="px-5 py-4 bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm font-bold uppercase tracking-wide">Daftar Signal Over 1.5</div>
                <div class="text-xs text-slate-300">Diurutkan dari adjusted score tertinggi, sambil tetap menampilkan base score dan RNG penalty.</div>
            </div>
            <div class="text-xs text-slate-300">
                <?= number_format(count($signalRows)) ?> match
            </div>
        </div>

        <div class="overflow-x-auto bg-gradient-to-b from-white to-slate-50/60">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-left font-bold">Kickoff</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-left font-bold">Match</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-left font-bold">League</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Grade</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Base Score</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Adj. Score</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">RNG Penalty</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Adj. Prob.</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">League Avg</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Home Avg</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Away Avg</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Team Floor</th>
                        <th class="sticky top-0 z-10 bg-gradient-to-b from-slate-50 to-indigo-50 px-4 py-3 text-center font-bold">Samples</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (!$signalRows): ?>
                        <tr>
                            <td colspan="13" class="px-4 py-12 text-center text-slate-400 font-medium">Belum ada signal untuk filter ini. Turunkan threshold atau team floor, atau aktifkan opsi tampilkan semua upcoming match.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($signalRows as $row): ?>
                            <?php
                            $gradeClass = 'border border-amber-200 bg-amber-100 text-amber-700 shadow-sm shadow-amber-900/5';
                            $rowClass = 'bg-amber-50/30 transition-colors hover:bg-amber-50/60';
                            if ($row['grade'] === 'Strong') {
                                $gradeClass = 'border border-emerald-200 bg-emerald-100 text-emerald-700 shadow-sm shadow-emerald-900/5';
                                $rowClass = 'bg-emerald-50/40 transition-colors hover:bg-emerald-50/70';
                            } elseif ($row['grade'] === 'Watchlist') {
                                $gradeClass = 'border border-slate-200 bg-slate-100 text-slate-600 shadow-sm shadow-slate-900/5';
                                $rowClass = 'bg-slate-50/70 transition-colors hover:bg-slate-100/80';
                            }
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td class="px-4 py-3 text-slate-600 font-medium"><?= htmlspecialchars(date('d-m-Y H:i', strtotime($row['match_time']))) ?></td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($row['home_team']) ?></div>
                                    <div class="text-[10px] uppercase tracking-wide text-slate-500">vs <?= htmlspecialchars($row['away_team']) ?></div>
                                </td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($row['league']) ?></td>
                                <td class="px-4 py-3 text-center"><span class="rounded-full px-3 py-1 text-[11px] font-black <?= $gradeClass ?>"><?= htmlspecialchars($row['grade']) ?></span></td>
                                 <td class="px-4 py-3 text-center font-semibold text-slate-500"><?= htmlspecialchars(o15SignalFormatDec($row['base_score'])) ?></td>
                                 <td class="px-4 py-3 text-center font-black text-slate-900"><?= htmlspecialchars(o15SignalFormatDec($row['score'])) ?></td>
                                 <td class="px-4 py-3 text-center font-bold text-rose-600">-<?= htmlspecialchars(o15SignalFormatDec($row['rng_penalty'])) ?></td>
                                 <td class="px-4 py-3 text-center font-bold text-blue-700"><?= htmlspecialchars(o15SignalFormatPct($row['probability'])) ?></td>
                                 <td class="px-4 py-3 text-center text-slate-700"><?= htmlspecialchars(o15SignalFormatDec($row['league_avg'])) ?></td>
                                <td class="px-4 py-3 text-center text-slate-700"><?= htmlspecialchars(o15SignalFormatDec($row['home_avg'])) ?></td>
                                <td class="px-4 py-3 text-center text-slate-700"><?= htmlspecialchars(o15SignalFormatDec($row['away_avg'])) ?></td>
                                <td class="px-4 py-3 text-center font-semibold text-emerald-700"><?= htmlspecialchars(o15SignalFormatDec($row['team_balance'])) ?></td>
                                <td class="px-4 py-3 text-center text-slate-500">
                                    L<?= number_format($row['league_sample']) ?> / H<?= number_format($row['home_sample']) ?> / A<?= number_format($row['away_sample']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
