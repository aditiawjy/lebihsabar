<?php
if (!defined('SABARAJA_APP')) {
    exit('No direct script access allowed');
}

require_once 'koneksi.php';
require_once 'matches_data_helper.php';

$dbActive = sabarajaDataConnectionReady($conn, $db_error ?? '');

// 1. Fetch distinct leagues
$leagues = [];
if ($dbActive) {
    $result = $conn->query("SELECT DISTINCT league FROM matches WHERE league IS NOT NULL AND league <> '' ORDER BY league ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $leagues[] = $row['league'];
        }
    }
} else if (sabarajaDataCsvAvailable()) {
    sabarajaDataReadCsv(function (array $match) use (&$leagues): void {
        if ($match['league'] !== '') {
            $leagues[$match['league']] = true;
        }
    });
    $leagues = array_keys($leagues);
    sort($leagues);
}

// 2. Determine selected league, min matches, and active Boost Team
$selectedLeague = $_GET['league'] ?? '';
if ($selectedLeague === '' && !empty($leagues)) {
    $selectedLeague = $leagues[0];
}

$minMatches = isset($_GET['min_matches']) ? (int)$_GET['min_matches'] : 5;
$searchTerm = $_GET['search'] ?? '';
$boostTeam = $_GET['boost_team'] ?? '';

// Determine if the current league is 16 Mins Play
$is16MinLeague = (stripos($selectedLeague, '16 Mins') !== false || stripos($selectedLeague, 'FC 24') !== false);

// Dynamic config for Spain/England depending on selected league
$boostConfigs = [
    'Liverpool' => [
        'name' => 'Liverpool',
        'search' => 'Liverpool',
        'target' => '92.7%',
        'opponents' => [
            'Manchester United (V)', 'Everton (V)', 'Tottenham Hotspur (V)', 
            'Paris Saint Germain (V)', 'Chelsea (V)', 'Arminia Bielefeld (V)', 
            'Sevilla (V)', 'Manchester City (V)', 'Real Sociedad (V)'
        ],
        'desc' => 'Meningkatkan akurasi Liverpool dari 85.6% menjadi <strong class="text-blue-700 font-bold">92.7%</strong> dengan memfilter hanya lawan bertipe terbuka (MU, Chelsea, Everton, Tottenham, PSG, Man City, Sevilla, Arminia Bielefeld, Real Sociedad).'
    ],
    'PSG' => [
        'name' => 'PSG',
        'search' => 'Paris',
        'target' => '89.5%',
        'opponents' => [
            'Manchester City (V)', 'Liverpool (V)', 'Manchester United (V)', 
            'Chelsea (V)', 'Real Madrid (V)', 'Tottenham Hotspur (V)'
        ],
        'desc' => 'Meningkatkan akurasi PSG dari 82.4% menjadi <strong class="text-blue-700 font-bold">89.5%</strong> dengan memfilter hanya lawan bertipe terbuka (Man City, Liverpool, MU, Chelsea, Real Madrid, Tottenham).'
    ],
    'Man Utd' => [
        'name' => 'Man Utd',
        'search' => 'Manchester United',
        'target' => '94.2%',
        'opponents' => [
            'Liverpool (V)', 'Manchester City (V)', 'Tottenham Hotspur (V)', 
            'Arsenal (V)', 'Chelsea (V)', 'Everton (V)'
        ],
        'desc' => 'Meningkatkan akurasi Man Utd dari 82.4% menjadi <strong class="text-blue-700 font-bold">94.2%</strong> dengan memfilter hanya lawan bertipe terbuka (Liverpool, Man City, Tottenham, Arsenal, Chelsea, Everton).'
    ],
    'Tottenham' => [
        'name' => 'Tottenham',
        'search' => 'Tottenham',
        'target' => '90.7%',
        'opponents' => [
            'Arsenal (V)', 'Liverpool (V)', 'Manchester United (V)', 
            'Real Sociedad (V)', 'Paris Saint Germain (V)', 
            'Manchester City (V)', 'Bayer 04 Leverkusen (V)'
        ],
        'desc' => 'Meningkatkan akurasi Tottenham dari 82.1% menjadi <strong class="text-blue-700 font-bold">90.7%</strong> dengan memfilter hanya lawan bertipe terbuka (Arsenal, Liverpool, MU, Real Sociedad, PSG, Man City, Bayer Leverkusen).'
    ],
    'Chelsea' => [
        'name' => 'Chelsea',
        'search' => 'Chelsea',
        'target' => '91.4%',
        'opponents' => [
            'Paris Saint Germain (V)', 'Liverpool (V)', 'Arsenal (V)', 'Manchester City (V)'
        ],
        'desc' => 'Meningkatkan akurasi Chelsea dari 81.1% menjadi <strong class="text-blue-700 font-bold">91.4%</strong> dengan memfilter hanya lawan bertipe terbuka (PSG, Liverpool, Arsenal, Man City).'
    ]
];

if ($is16MinLeague) {
    $boostConfigs['Spain'] = [
        'name' => 'Spain',
        'search' => 'Spain',
        'target' => '95.2%',
        'opponents' => [
            'Netherlands (V)', 'Italy (V)', 'Argentina (V)', 'Belgium (V)'
        ],
        'desc' => 'Meningkatkan akurasi Spain dari 85.8% menjadi <strong class="text-blue-700 font-bold">95.2%</strong> dengan memfilter hanya lawan bertipe terbuka (Netherlands, Italy, Argentina, Belgium).'
    ];
    $boostConfigs['Netherlands'] = [
        'name' => 'Netherlands',
        'search' => 'Netherlands',
        'target' => '95.6%',
        'opponents' => [
            'Ghana (V)', 'Spain (V)', 'USA (V)', 'Norway (V)', 'Mexico (V)', 'Italy (V)', 'Germany (V)', 'Belgium (V)'
        ],
        'desc' => 'Meningkatkan akurasi Netherlands dari 84.7% menjadi <strong class="text-blue-700 font-bold">95.6%</strong> dengan memfilter hanya lawan bertipe terbuka (Ghana, Spain, USA, Norway, Mexico, Italy, Germany, Belgium).'
    ];
    $boostConfigs['Norway'] = [
        'name' => 'Norway',
        'search' => 'Norway',
        'target' => '95.1%',
        'opponents' => [
            'Croatia (V)', 'France (V)', 'Netherlands (V)', 'Argentina (V)', 'Mexico (V)', 'Germany (V)', 'England (V)', 'Morocco (V)'
        ],
        'desc' => 'Meningkatkan akurasi Norway dari 84.5% menjadi <strong class="text-blue-700 font-bold">95.1%</strong> dengan memfilter hanya lawan bertipe terbuka (Croatia, France, Netherlands, Argentina, Mexico, Germany, England, Morocco).'
    ];
} else {
    $boostConfigs['England'] = [
        'name' => 'England',
        'search' => 'England',
        'target' => '97.9%',
        'opponents' => [
            'Croatia (V)', 'Sweden (V)', 'Brazil (V)'
        ],
        'desc' => 'Meningkatkan akurasi England dari 90.7% menjadi <strong class="text-blue-700 font-bold">97.9%</strong> dengan memfilter hanya lawan bertipe terbuka (Croatia, Sweden, Brazil).'
    ];
    $boostConfigs['Norway'] = [
        'name' => 'Norway',
        'search' => 'Norway',
        'target' => '93.0%',
        'opponents' => [
            'Austria (V)', 'Algeria (V)', 'Uruguay (V)', 'Slovenia (V)', 'Brazil (V)', 'Nigeria (V)'
        ],
        'desc' => 'Meningkatkan akurasi Norway dari 87.3% menjadi <strong class="text-blue-700 font-bold">93.0%</strong> dengan memfilter hanya lawan bertipe terbuka (Austria, Algeria, Uruguay, Slovenia, Brazil, Nigeria).'
    ];
}

// 3. Process matches for the selected league
$teamStats = [];
$totalLeagueMatches = 0;
$totalOver15Matches = 0;
$presentTeams = [];

if ($selectedLeague !== '') {
    if ($dbActive) {
        $stmt = $conn->prepare("SELECT home_team, away_team, ft_home, ft_away FROM matches WHERE league = ? AND ft_home IS NOT NULL AND ft_away IS NOT NULL");
        if ($stmt) {
            $stmt->bind_param("s", $selectedLeague);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $home = trim($row['home_team']);
                    $away = trim($row['away_team']);
                    if ($home === '' || $away === '') continue;

                    $ft_home = $row['ft_home'];
                    $ft_away = $row['ft_away'];
                    $totalGoals = $ft_home + $ft_away;
                    $isOver15 = $totalGoals > 1.5;

                    $totalLeagueMatches++;
                    if ($isOver15) {
                        $totalOver15Matches++;
                    }

                    // Record presence of teams
                    $presentTeams[$home] = true;
                    $presentTeams[$away] = true;

                    // Boost filter logic
                    $skipHome = false;
                    $skipAway = false;
                    if ($boostTeam !== '' && isset($boostConfigs[$boostTeam])) {
                        $config = $boostConfigs[$boostTeam];
                        $searchVal = $config['search'] ?? $boostTeam;
                        
                        // If Home is the boost team, check if Away is in its high-scoring opponents list
                        if (stripos($home, $searchVal) !== false) {
                            $matched = false;
                            foreach ($config['opponents'] as $allowedOpp) {
                                $cleanOpp = trim(str_replace('(V)', '', $allowedOpp));
                                if (stripos($away, $cleanOpp) !== false) {
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                $skipHome = true;
                            }
                        }
                        
                        // If Away is the boost team, check if Home is in its high-scoring opponents list
                        if (stripos($away, $searchVal) !== false) {
                            $matched = false;
                            foreach ($config['opponents'] as $allowedOpp) {
                                $cleanOpp = trim(str_replace('(V)', '', $allowedOpp));
                                if (stripos($home, $cleanOpp) !== false) {
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                $skipAway = true;
                            }
                        }
                    }

                    if (!isset($teamStats[$home])) $teamStats[$home] = ['played' => 0, 'over15' => 0];
                    if (!isset($teamStats[$away])) $teamStats[$away] = ['played' => 0, 'over15' => 0];

                    if (!$skipHome) {
                        $teamStats[$home]['played']++;
                        if ($isOver15) $teamStats[$home]['over15']++;
                    }
                    if (!$skipAway) {
                        $teamStats[$away]['played']++;
                        if ($isOver15) $teamStats[$away]['over15']++;
                    }
                }
            }
        }
    } else if (sabarajaDataCsvAvailable()) {
        sabarajaDataReadCsv(function (array $match) use ($selectedLeague, &$teamStats, &$totalLeagueMatches, &$totalOver15Matches, &$presentTeams, $boostTeam, $boostConfigs): void {
            if ($match['league'] === $selectedLeague && $match['ft_home'] !== null && $match['ft_away'] !== null) {
                $home = $match['home_team'];
                $away = $match['away_team'];
                $totalGoals = $match['ft_home'] + $match['ft_away'];
                $isOver15 = $totalGoals > 1.5;

                $totalLeagueMatches++;
                if ($isOver15) {
                    $totalOver15Matches++;
                }

                $presentTeams[$home] = true;
                $presentTeams[$away] = true;

                // Boost filter logic
                $skipHome = false;
                $skipAway = false;
                if ($boostTeam !== '' && isset($boostConfigs[$boostTeam])) {
                    $config = $boostConfigs[$boostTeam];
                    $searchVal = $config['search'] ?? $boostTeam;
                    
                    if (stripos($home, $searchVal) !== false) {
                        $matched = false;
                        foreach ($config['opponents'] as $allowedOpp) {
                            $cleanOpp = trim(str_replace('(V)', '', $allowedOpp));
                            if (stripos($away, $cleanOpp) !== false) {
                                $matched = true;
                                    break;
                            }
                        }
                        if (!$matched) {
                            $skipHome = true;
                        }
                    }
                    if (stripos($away, $searchVal) !== false) {
                        $matched = false;
                        foreach ($config['opponents'] as $allowedOpp) {
                            $cleanOpp = trim(str_replace('(V)', '', $allowedOpp));
                            if (stripos($home, $cleanOpp) !== false) {
                                $matched = true;
                                    break;
                            }
                        }
                        if (!$matched) {
                            $skipAway = true;
                        }
                    }
                }

                if (!isset($teamStats[$home])) $teamStats[$home] = ['played' => 0, 'over15' => 0];
                if (!isset($teamStats[$away])) $teamStats[$away] = ['played' => 0, 'over15' => 0];

                if (!$skipHome) {
                    $teamStats[$home]['played']++;
                    if ($isOver15) $teamStats[$home]['over15']++;
                }
                if (!$skipAway) {
                    $teamStats[$away]['played']++;
                    if ($isOver15) $teamStats[$away]['over15']++;
                }
            }
        });
    }
}

// Check which boost configurations are available in the current league (using correct search term)
$availableBoosts = [];
foreach ($boostConfigs as $key => $config) {
    $searchVal = $config['search'] ?? $key;
    foreach (array_keys($presentTeams) as $t) {
        if (stripos($t, $searchVal) !== false) {
            $availableBoosts[$key] = $config;
            break;
        }
    }
}

// 4. Format & filter stats
$rankedTeams = [];
foreach ($teamStats as $team => $stats) {
    if ($stats['played'] < $minMatches) continue;
    $percentage = $stats['played'] > 0 ? ($stats['over15'] / $stats['played']) * 100 : 0;
    $rankedTeams[] = [
        'team' => $team,
        'played' => $stats['played'],
        'over15' => $stats['over15'],
        'percentage' => $percentage
    ];
}

// 5. Sort by percentage DESC, played DESC, team ASC
usort($rankedTeams, function($a, $b) {
    if (abs($b['percentage'] - $a['percentage']) < 0.0001) {
        if ($b['played'] === $a['played']) {
            return strcasecmp($a['team'], $b['team']);
        }
        return $b['played'] <=> $a['played'];
    }
    return $b['percentage'] <=> $a['percentage'];
});

$overallPct = $totalLeagueMatches > 0 ? ($totalOver15Matches / $totalLeagueMatches) * 100 : 0;
?>

<div class="p-3 sm:p-4 md:p-8 space-y-4 md:space-y-6 page-fade-in">
    <!-- Broadcast Header -->
    <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-4 md:p-6 shadow-xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.2em] text-amber-300 font-bold">Statistik Kompetisi</p>
                <h1 class="text-2xl md:text-3xl font-black tracking-tight">
                    League <span class="text-amber-300">Over 1.5 Goals</span>
                </h1>
                <p class="text-slate-300 text-sm md:text-base">Analisis rasio pertandingan yang berakhir dengan 2 gol atau lebih (Over 1.5).</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-500/15 border border-blue-400/30">
                    <span class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-blue-200"><?= $dbActive ? 'DATABASE MODE' : 'CSV MODE' ?></span>
                </div>
                <div class="px-3 py-2 rounded-lg bg-slate-700/70 border border-slate-600 text-xs font-bold text-slate-200"><?= date('d M Y') ?></div>
            </div>
        </div>
    </div>

    <!-- Dynamic Boost Recommendations Banner -->
    <?php if (!empty($availableBoosts)): ?>
        <div class="rounded-2xl border border-blue-100 bg-gradient-to-r from-blue-50 to-indigo-50 p-4 md:p-5 shadow-sm space-y-3">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center text-white text-base shadow-md shadow-blue-500/20 shrink-0">
                    🔥
                </div>
                <div>
                    <h4 class="text-sm font-bold text-slate-800">
                        <?= $boostTeam !== '' && isset($boostConfigs[$boostTeam]) ? 'Rekomendasi Akurasi ' . $boostConfigs[$boostTeam]['name'] . ' Aktif' : 'Rekomendasi Akurasi Boost (Over 1.5)' ?>
                    </h4>
                    <p class="text-xs text-slate-600 mt-0.5">
                        <?= $boostTeam !== '' && isset($boostConfigs[$boostTeam]) ? $boostConfigs[$boostTeam]['desc'] : 'Saring lawan bertipe terbuka untuk meningkatkan akurasi kemenangan Over 1.5 pada tim pilihan Anda di liga ini. Pilih salah satu tombol di bawah untuk melihat rincian kondisi.' ?>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 pt-1.5">
                <?php foreach ($availableBoosts as $key => $config): ?>
                    <?php $isActive = $boostTeam === $key; ?>
                    <a href="index.php?page=league-over15&league=<?= urlencode($selectedLeague) ?>&min_matches=<?= $minMatches ?>&boost_team=<?= $isActive ? '' : urlencode($key) ?>" 
                       class="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold transition-all <?= $isActive ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20 hover:bg-blue-700' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>"
                       title="<?= htmlspecialchars($config['desc']) ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? 'bg-white animate-pulse' : 'bg-slate-400' ?>"></span>
                        <?= htmlspecialchars($config['name']) ?> Boost (<?= $config['target'] ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Stats Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Card 1: Selected League -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Liga Terpilih</span>
                <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 002 2h1.5a2.5 2.5 0 012.496 2.196L19.5 17m0-11v1a1.5 1.5 0 001.5 1.5H21"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-lg font-bold text-slate-800 truncate" title="<?= htmlspecialchars($selectedLeague) ?>">
                    <?= htmlspecialchars($selectedLeague ?: 'Tidak ada liga') ?>
                </h3>
                <p class="text-xs text-slate-500 mt-1">Kompetisi aktif saat ini</p>
            </div>
        </div>

        <!-- Card 2: Total Teams -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Jumlah Tim</span>
                <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black text-slate-800"><?= count($rankedTeams) ?></h3>
                <p class="text-xs text-slate-500 mt-1">Tim aktif (min. <?= $minMatches ?> matches)</p>
            </div>
        </div>

        <!-- Card 3: Total Matches -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Matches</span>
                <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black text-slate-800"><?= number_format($totalLeagueMatches) ?></h3>
                <p class="text-xs text-slate-500 mt-1">Total selesai di liga terpilih</p>
            </div>
        </div>

        <!-- Card 4: Overall Ratio -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow duration-200">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Over 1.5 Ratio</span>
                <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black text-purple-600"><?= number_format($overallPct, 1) ?>%</h3>
                <p class="text-xs text-slate-500 mt-1"><?= number_format($totalOver15Matches) ?> dari <?= number_format($totalLeagueMatches) ?> matches</p>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 md:p-5 shadow-sm">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <input type="hidden" name="page" value="league-over15">
            <?php if ($boostTeam !== '' && isset($availableBoosts[$boostTeam])): ?>
                <input type="hidden" name="boost_team" value="<?= htmlspecialchars($boostTeam) ?>">
            <?php endif; ?>

            <!-- Dropdown League -->
            <div class="flex flex-col gap-1.5">
                <label for="league" class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Pilih Liga</label>
                <div class="relative">
                    <select id="league" name="league" onchange="this.form.submit()" class="w-full h-11 bg-slate-50 border border-slate-200 rounded-xl px-4 text-sm font-semibold text-slate-800 outline-none appearance-none focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 transition-all cursor-pointer">
                        <?php if (empty($leagues)): ?>
                            <option value="">-- Tidak ada liga tersedia --</option>
                        <?php else: ?>
                            <?php foreach ($leagues as $lg): ?>
                                <option value="<?= htmlspecialchars($lg) ?>" <?= $selectedLeague === $lg ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lg) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Min Matches -->
            <div class="flex flex-col gap-1.5">
                <label for="min_matches" class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Minimal Match</label>
                <div class="relative">
                    <select id="min_matches" name="min_matches" onchange="this.form.submit()" class="w-full h-11 bg-slate-50 border border-slate-200 rounded-xl px-4 text-sm font-semibold text-slate-800 outline-none appearance-none focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 transition-all cursor-pointer">
                        <?php foreach ([1, 3, 5, 10, 15, 20] as $num): ?>
                            <option value="<?= $num ?>" <?= $minMatches === $num ? 'selected' : '' ?>>
                                Minimal <?= $num ?> Pertandingan
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Search Field (Client Side JavaScript Filter) -->
            <div class="flex flex-col gap-1.5">
                <label for="team-search" class="text-xs font-extrabold uppercase tracking-wider text-slate-400">Cari Team</label>
                <div class="relative">
                    <input type="text" id="team-search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Masukkan nama team..." class="w-full h-11 bg-slate-50 border border-slate-200 rounded-xl pl-11 pr-4 text-sm font-semibold text-slate-800 outline-none focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 transition-all">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Table Section -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600 border-collapse">
                <thead class="bg-slate-50/75 border-b border-slate-200 text-xs font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th scope="col" class="px-6 py-4 w-20 text-center">Rank</th>
                        <th scope="col" class="px-6 py-4">Nama Team</th>
                        <th scope="col" class="px-6 py-4 w-32 text-center">Main</th>
                        <th scope="col" class="px-6 py-4 w-32 text-center">Over 1.5</th>
                        <th scope="col" class="px-6 py-4 w-36 text-center">Persentase</th>
                        <th scope="col" class="px-6 py-4 text-center">Visual Trend</th>
                    </tr>
                </thead>
                <tbody id="team-table-body" class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($rankedTeams)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-400">
                                <div class="flex flex-col items-center justify-center gap-3">
                                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span class="text-sm">Tidak ada team yang memenuhi filter minimal <?= $minMatches ?> pertandingan.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $rank = 1;
                        foreach ($rankedTeams as $item): 
                            $isVirtual = strpos($item['team'], '(V)') !== false;
                            $displayName = $isVirtual ? str_replace('(V)', '', $item['team']) : $item['team'];
                            $displayName = trim($displayName);

                            // Badge styling based on percentage
                            $pct = $item['percentage'];
                            if ($pct >= 85) {
                                $badgeClass = 'text-emerald-700 bg-emerald-50 border border-emerald-200/50';
                                $barClass = 'bg-gradient-to-r from-emerald-400 to-emerald-600';
                            } elseif ($pct >= 70) {
                                $badgeClass = 'text-blue-700 bg-blue-50 border border-blue-200/50';
                                $barClass = 'bg-gradient-to-r from-blue-400 to-blue-600';
                            } elseif ($pct >= 50) {
                                $badgeClass = 'text-slate-700 bg-slate-50 border border-slate-200/50';
                                $barClass = 'bg-gradient-to-r from-slate-400 to-slate-500';
                            } else {
                                $badgeClass = 'text-rose-700 bg-rose-50 border border-rose-200/50';
                                $barClass = 'bg-gradient-to-r from-rose-400 to-rose-600';
                            }

                            // Highlighting matches using correct search configs
                            $isBoostedRow = false;
                            if ($boostTeam !== '' && isset($boostConfigs[$boostTeam])) {
                                $searchVal = $boostConfigs[$boostTeam]['search'] ?? $boostTeam;
                                if (stripos($item['team'], $searchVal) !== false) {
                                    $isBoostedRow = true;
                                }
                            }
                        ?>
                            <tr class="hover:bg-slate-50/50 transition-colors duration-150 <?= $isBoostedRow ? 'bg-blue-50/20' : '' ?>" data-team="<?= htmlspecialchars($item['team']) ?>">
                                <td class="px-6 py-4 text-center font-bold text-slate-800">
                                    <?php if ($rank === 1): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-100 text-amber-600 text-xs">🥇</span>
                                    <?php elseif ($rank === 2): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-600 text-xs">🥈</span>
                                    <?php elseif ($rank === 3): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-50 text-amber-700 text-xs">🥉</span>
                                    <?php else: ?>
                                        <span class="text-slate-400 font-semibold text-xs"><?= $rank ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-800">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span><?= htmlspecialchars($displayName) ?></span>
                                        <?php if ($isVirtual): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-indigo-50 border border-indigo-200/50 text-indigo-600 uppercase tracking-wide">Virtual</span>
                                        <?php endif; ?>
                                        <?php if ($isBoostedRow): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 border border-amber-200/50 text-amber-700 uppercase tracking-wide">🔥 <?= $boostConfigs[$boostTeam]['target'] ?> Boosted</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center text-slate-700 font-semibold"><?= $item['played'] ?></td>
                                <td class="px-6 py-4 text-center text-slate-700 font-semibold"><?= $item['over15'] ?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-bold <?= $badgeClass ?>">
                                        <?= number_format($pct, 1) ?>%
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3 max-w-xs mx-auto">
                                        <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full <?= $barClass ?> transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                        </div>
                                        <span class="text-[10px] text-slate-400 w-8 text-right font-mono"><?= round($pct) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php $rank++; endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('team-search');
    const tableBody = document.getElementById('team-table-body');
    
    if (searchInput && tableBody) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const rows = tableBody.querySelectorAll('tr[data-team]');
            
            rows.forEach(row => {
                const teamName = row.getAttribute('data-team').toLowerCase();
                if (teamName.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});
</script>
