<?php
date_default_timezone_set('Asia/Jakarta');

// Win Streak Analysis
$csvPath = __DIR__ . '/matches.csv';

// Market options
$marketOptions = [
    '2.5' => ['label' => 'Over 2.5', 'threshold' => 3, 'short' => 'O2.5'],
    '1.5' => ['label' => 'Over 1.5', 'threshold' => 2, 'short' => 'O1.5'],
];

$selectedMarket = $_GET['market'] ?? '2.5';
if (!array_key_exists($selectedMarket, $marketOptions)) {
    $selectedMarket = '2.5';
}
$marketConfig = $marketOptions[$selectedMarket];

function hasOverGoals(array $match, int $threshold): bool {
    if (!isset($match['ft_home']) || !isset($match['ft_away'])) return false;
    if ($match['ft_home'] === '' || $match['ft_away'] === '') return false;
    if (!is_numeric($match['ft_home']) || !is_numeric($match['ft_away'])) return false;
    
    $total = (int)$match['ft_home'] + (int)$match['ft_away'];
    return $total >= $threshold;
}

function hasFinishedScore(array $match): bool {
    return isset($match['ft_home'], $match['ft_away'])
        && $match['ft_home'] !== ''
        && $match['ft_away'] !== ''
        && is_numeric($match['ft_home'])
        && is_numeric($match['ft_away']);
}

function getTeamMatches(string $team, array $allMatches): array {
    $teamMatches = [];
    foreach ($allMatches as $match) {
        $home = trim($match['home_team'] ?? '');
        $away = trim($match['away_team'] ?? '');
        
        if ($home === $team || $away === $team) {
            $teamMatches[] = $match;
        }
    }
    
    // Sort by real datetime descending (not string compare)
    usort($teamMatches, function($a, $b) {
        $dateA = strtotime($a['match_time'] ?? '') ?: 0;
        $dateB = strtotime($b['match_time'] ?? '') ?: 0;
        return $dateB <=> $dateA;
    });
    
    return $teamMatches;
}

function calculateWinStreak(array $matches, string $team, int $threshold): array {
    // Only completed matches count for streak calculation
    $finishedMatches = array_values(array_filter($matches, 'hasFinishedScore'));

    $currentStreak = 0;
    $maxStreak = 0;
    $lastMatches = [];
    $consecutiveCount = 0;
    $foundFirstNonOver = false;
    
    foreach ($finishedMatches as $match) {
        $isOver = hasOverGoals($match, $threshold);
        $home = trim($match['home_team'] ?? '');
        $away = trim($match['away_team'] ?? '');
        
        // Store last 5 matches for display
        if (count($lastMatches) < 5) {
            $lastMatches[] = [
                'date' => substr($match['match_time'] ?? '', 0, 10),
                'vs' => $team === $home ? $away : $home,
                'is_home' => $team === $home,
                'score' => ($match['ft_home'] ?? '-') . '-' . ($match['ft_away'] ?? '-'),
                'is_over' => $isOver
            ];
        }
        
        // Calculate current streak (consecutive over goals from most recent)
        if (!$foundFirstNonOver) {
            if ($isOver) {
                $currentStreak++;
            } else {
                $foundFirstNonOver = true;
            }
        }
        
        // Calculate max streak
        if ($isOver) {
            $consecutiveCount++;
            $maxStreak = max($maxStreak, $consecutiveCount);
        } else {
            $consecutiveCount = 0;
        }
    }
    
    return [
        'current' => $currentStreak,
        'max' => $maxStreak,
        'last_matches' => $lastMatches,
        'finished_count' => count($finishedMatches)
    ];
}

function findNextMatch(string $team, array $allMatches): ?array {
    $today = date('Y-m-d');
    
    foreach ($allMatches as $match) {
        $matchDate = substr($match['match_time'] ?? '', 0, 10);
        $home = trim($match['home_team'] ?? '');
        $away = trim($match['away_team'] ?? '');
        
        if (($home === $team || $away === $team) && $matchDate >= $today) {
            return [
                'date' => $matchDate,
                'time' => substr($match['match_time'] ?? '', 11, 5),
                'vs' => $team === $home ? $away : $home,
                'is_home' => $team === $home
            ];
        }
    }
    
    return null;
}

// Read all matches
$allMatches = [];
$allTeams = [];

if (is_readable($csvPath) && ($fh = fopen($csvPath, 'r')) !== false) {
    $headers = fgetcsv($fh);
    if ($headers) {
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($headers)) continue;
            $match = array_combine($headers, $row);
            if (!$match) continue;
            
            $home = trim($match['home_team'] ?? '');
            $away = trim($match['away_team'] ?? '');
            
            if ($home && $away) {
                $allMatches[] = $match;
                $allTeams[$home] = ($allTeams[$home] ?? 0) + 1;
                $allTeams[$away] = ($allTeams[$away] ?? 0) + 1;
            }
        }
    }
    fclose($fh);
}

// Sort matches by real datetime
usort($allMatches, function($a, $b) {
    $ta = strtotime($a['match_time'] ?? '') ?: 0;
    $tb = strtotime($b['match_time'] ?? '') ?: 0;
    return $tb <=> $ta;
});

// Process each team
$streakData = [];
$searchTerm = trim($_GET['search'] ?? '');
$minStreak = max(0, (int)($_GET['min_streak'] ?? 2));
$sortBy = $_GET['sort'] ?? 'current';
$order = $_GET['order'] ?? 'desc';

foreach ($allTeams as $team => $matchCount) {
    // Filter by search
    if ($searchTerm && stripos($team, $searchTerm) === false) {
        continue;
    }
    
    $teamMatches = getTeamMatches($team, $allMatches);
    if (count($teamMatches) < 3) continue; // Minimum 3 total matches
    
    $streak = calculateWinStreak($teamMatches, $team, $marketConfig['threshold']);
    
    // Skip teams without enough completed matches
    if (($streak['finished_count'] ?? 0) < 3) {
        continue;
    }

    // Filter by min streak
    if ($streak['current'] < $minStreak && $streak['max'] < $minStreak) {
        continue;
    }
    
    $streakData[] = [
        'team' => $team,
        'match_count' => $matchCount,
        'current_streak' => $streak['current'],
        'max_streak' => $streak['max'],
        'last_matches' => $streak['last_matches'],
        'next_match' => findNextMatch($team, $allMatches)
    ];
}

// Sort results
usort($streakData, function($a, $b) use ($sortBy, $order) {
    $valA = $a[$sortBy . '_streak'] ?? 0;
    $valB = $b[$sortBy . '_streak'] ?? 0;
    
    if ($valA === $valB) {
        // Secondary sort by team name
        $cmp = strcmp($a['team'], $b['team']);
        return $order === 'asc' ? $cmp : -$cmp;
    }
    
    return $order === 'asc' ? ($valA - $valB) : ($valB - $valA);
});

// Pagination
$perPage = 50;
$total = count($streakData);
$totalPages = max(1, ceil($total / $perPage));
$page = max(1, min((int)($_GET['page_num'] ?? 1), $totalPages));
$offset = ($page - 1) * $perPage;
$displayData = array_slice($streakData, $offset, $perPage);

function buildUrl(array $params = []): string {
    $base = array_merge($_GET, $params);
    return 'index.php?' . http_build_query($base);
}
?>

<div class="p-4 md:p-8 space-y-6 page-fade-in">
    
    <!-- Broadcast Header -->
    <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-5 md:p-6 shadow-xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.2em] text-emerald-300 font-bold">Win Streak Analysis</p>
                <h1 class="text-2xl md:text-3xl font-black tracking-tight">
                    <?= htmlspecialchars($marketConfig['label']) ?> <span class="text-emerald-300">Goals</span>
                </h1>
                <p class="text-slate-300 text-sm md:text-base">Analisis win streak club berdasarkan total gol >= <?= $marketConfig['threshold'] ?>.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/15 border border-emerald-400/30">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-200">Live</span>
                </div>
                <div class="px-3 py-2 rounded-lg bg-slate-700/70 border border-slate-600 text-xs font-bold text-slate-200"><?= date('d M Y') ?></div>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Clubs</p>
            <p class="mt-2 text-2xl font-black text-slate-900"><?= count($streakData) ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Current Streak 3+</p>
            <p class="mt-2 text-2xl font-black text-emerald-600"><?= count(array_filter($streakData, fn($s) => $s['current_streak'] >= 3)) ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Max Streak 5+</p>
            <p class="mt-2 text-2xl font-black text-blue-600"><?= count(array_filter($streakData, fn($s) => $s['max_streak'] >= 5)) ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Hot Streak</p>
            <p class="mt-2 text-2xl font-black text-amber-600"><?= max(array_column($streakData, 'current_streak') ?: [0]) ?></p>
        </div>
    </div>

    <!-- Filter Form -->
    <form method="GET" class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6 transition-all">
        <input type="hidden" name="page" value="win-streak">
        
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <!-- Search -->
            <div class="md:col-span-2">
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 block">Cari Club</label>
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Nama club..." 
                        class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all">
                </div>
            </div>

            <!-- Market -->
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 block">Market</label>
                <select name="market" class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all h-[46px]" onchange="this.form.submit()">
                    <?php foreach ($marketOptions as $key => $opt): ?>
                        <option value="<?= $key ?>" <?= $selectedMarket === $key ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Min Streak -->
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 block">Min Streak</label>
                <select name="min_streak" class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all h-[46px]">
                    <option value="0" <?= $minStreak === 0 ? 'selected' : '' ?>>Semua</option>
                    <option value="2" <?= $minStreak === 2 ? 'selected' : '' ?>>2+</option>
                    <option value="3" <?= $minStreak === 3 ? 'selected' : '' ?>>3+</option>
                    <option value="5" <?= $minStreak === 5 ? 'selected' : '' ?>>5+</option>
                </select>
            </div>
            
            <!-- Sort -->
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 block">Urutkan</label>
                <select name="sort" class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all h-[46px]" onchange="this.form.submit()">
                    <option value="current" <?= $sortBy === 'current' ? 'selected' : '' ?>>Current Streak</option>
                    <option value="max" <?= $sortBy === 'max' ? 'selected' : '' ?>>Max Streak</option>
                </select>
            </div>
        </div>
        
        <div class="flex items-center gap-3 mt-4 pt-4 border-t border-slate-100">
            <button type="submit" class="bg-slate-900 text-white rounded-xl px-6 py-3 text-sm font-bold hover:bg-slate-800 transition-all shadow-lg active:scale-95 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Cari
            </button>
            <a href="<?= htmlspecialchars(buildUrl(['search' => '', 'min_streak' => 2, 'sort' => 'current', 'market' => '2.5', 'page_num' => 1])) ?>" class="px-5 py-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-200 hover:text-slate-800 transition-all font-bold text-sm">
                Reset
            </a>
        </div>
    </form>

    <!-- Results Table -->
    <div class="bg-white rounded-2xl shadow-md border-0 overflow-hidden">
        <div class="px-5 py-4 bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span class="text-sm font-bold uppercase tracking-wide">Win Streak Data</span>
            </div>
            <span class="text-xs text-slate-300"><?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> / <?= $total ?></span>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">#</th>
                        <th class="px-4 py-3 text-left font-bold">Club</th>
                        <th class="px-4 py-3 text-center font-bold">Current</th>
                        <th class="px-4 py-3 text-center font-bold">Max</th>
                        <th class="px-4 py-3 text-center font-bold">Last 5 Matches</th>
                        <th class="px-4 py-3 text-center font-bold">Next Match</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($displayData)): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-slate-400 font-medium">
                                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                Tidak ada data yang memenuhi kriteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($displayData as $i => $data): ?>
                            <tr class="hover:bg-blue-50/30 transition-all duration-200">
                                <td class="px-4 py-3 text-slate-500 font-medium"><?= $offset + $i + 1 ?></td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($data['team']) ?></div>
                                    <div class="text-[10px] text-slate-500"><?= $data['match_count'] ?> matches</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($data['current_streak'] >= 3): ?>
                                        <span class="px-3 py-1.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-700 animate-pulse">
                                            <?= $data['current_streak'] ?> 🔥
                                        </span>
                                    <?php elseif ($data['current_streak'] >= 1): ?>
                                        <span class="px-3 py-1.5 rounded-full text-xs font-bold bg-blue-100 text-blue-700">
                                            <?= $data['current_streak'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-500">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-3 py-1.5 rounded-full text-xs font-black bg-violet-100 text-violet-700">
                                        <?= $data['max_streak'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <?php foreach (array_slice($data['last_matches'], 0, 5) as $match): ?>
                                            <div class="relative group">
                                                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold <?= $match['is_over'] ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-500' ?>" title="<?= htmlspecialchars($match['vs']) ?> (<?= $match['score'] ?>)">
                                                    <?= $match['is_over'] ? '✓' : '✗' ?>
                                                </span>
                                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block bg-slate-800 text-white text-[10px] px-2 py-1 rounded whitespace-nowrap z-10">
                                                    <?= htmlspecialchars($match['date']) ?> vs <?= htmlspecialchars($match['vs']) ?>: <?= $match['score'] ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($data['next_match']): ?>
                                        <div class="font-bold text-slate-800 text-xs"><?= htmlspecialchars($data['next_match']['vs']) ?></div>
                                        <div class="text-[10px] text-slate-500"><?= date('d-m-y', strtotime($data['next_match']['date'])) ?> <?= $data['next_match']['time'] ?></div>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600"><?= $data['next_match']['is_home'] ? 'H' : 'A' ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-400">-</span>
                                    <?php endif; ?>
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
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(buildUrl(['page_num' => $page - 1])) ?>" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-slate-700 transition-all">&lt; Prev</a>
            <?php endif; ?>
            
            <?php 
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($p = $start; $p <= $end; $p++): 
            ?>
                <a href="<?= htmlspecialchars(buildUrl(['page_num' => $p])) ?>" 
                   class="px-4 py-2 rounded-xl font-bold transition-all <?= $p === $page ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(buildUrl(['page_num' => $page + 1])) ?>" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-slate-700 transition-all">Next &gt;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}
.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
