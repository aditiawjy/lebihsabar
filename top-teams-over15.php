<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Over 1.5 Goals Statistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .virtual-badge {
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen p-6">
    <?php
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ?>
    <div class="max-w-6xl mx-auto" x-data="{ activeTab: 'teams' }">
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="p-6 bg-indigo-600 text-white flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold">Top Over 1.5 Goals Statistics</h1>
                    <p class="text-indigo-100 text-sm mt-1">Comprehensive Analysis (Real & Virtual)</p>
                </div>
                <a href="index.php" class="bg-indigo-700 hover:bg-indigo-800 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                    <i class="fas fa-home mr-2"></i>Dashboard
                </a>
            </div>

            <!-- Tab Navigation -->
            <div class="flex border-b border-gray-200">
                <button @click="activeTab = 'teams'" 
                        :class="{ 'border-indigo-500 text-indigo-600': activeTab === 'teams', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'teams' }"
                        class="w-1/2 py-4 px-1 text-center border-b-2 font-medium text-sm transition-colors cursor-pointer">
                    <i class="fas fa-shield-alt mr-2"></i>Top Teams
                </button>
                <button @click="activeTab = 'matches'" 
                        :class="{ 'border-indigo-500 text-indigo-600': activeTab === 'matches', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'matches' }"
                        class="w-1/2 py-4 px-1 text-center border-b-2 font-medium text-sm transition-colors cursor-pointer">
                    <i class="fas fa-handshake mr-2"></i>Top H2H Matches
                </button>
            </div>

            <div class="p-6">
                <?php
                require_once 'koneksi.php';
                
                // Increase limits for processing
                ini_set('memory_limit', '512M');

                // --- LOGIC 1: TOP TEAMS ---
                $sqlTeams = "SELECT home_team, away_team, ft_home, ft_away 
                        FROM matches 
                        WHERE ft_home IS NOT NULL AND ft_away IS NOT NULL";
                
                $resultTeams = $conn->query($sqlTeams);
                $teamStats = [];

                if ($resultTeams && $resultTeams->num_rows > 0) {
                    while ($row = $resultTeams->fetch_assoc()) {
                        $home = $row['home_team'];
                        $away = $row['away_team'];
                        $totalGoals = $row['ft_home'] + $row['ft_away'];
                        $isOver15 = $totalGoals > 1.5;

                        // Process Home
                        if (!isset($teamStats[$home])) $teamStats[$home] = ['played' => 0, 'over15' => 0];
                        $teamStats[$home]['played']++;
                        if ($isOver15) $teamStats[$home]['over15']++;

                        // Process Away
                        if (!isset($teamStats[$away])) $teamStats[$away] = ['played' => 0, 'over15' => 0];
                        $teamStats[$away]['played']++;
                        if ($isOver15) $teamStats[$away]['over15']++;
                    }
                }

                $rankedTeams = [];
                foreach ($teamStats as $team => $stats) {
                    if ($stats['played'] < 10) continue;
                    $percentage = $stats['played'] > 0 ? ($stats['over15'] / $stats['played']) * 100 : 0;
                    $rankedTeams[] = [
                        'team' => $team,
                        'played' => $stats['played'],
                        'over15' => $stats['over15'],
                        'percentage' => $percentage
                    ];
                }

                usort($rankedTeams, function($a, $b) {
                    if ($b['percentage'] == $a['percentage']) return $b['played'] <=> $a['played'];
                    return $b['percentage'] <=> $a['percentage'];
                });

                // --- LOGIC 2: TOP H2H MATCHES (SQL Optimized) ---
                $sqlH2H = "SELECT 
                            CASE WHEN home_team < away_team THEN home_team ELSE away_team END as team1,
                            CASE WHEN home_team < away_team THEN away_team ELSE home_team END as team2,
                            COUNT(*) as played,
                            SUM(CASE WHEN (ft_home + ft_away) > 1.5 THEN 1 ELSE 0 END) as over15
                        FROM matches
                        WHERE ft_home IS NOT NULL AND ft_away IS NOT NULL
                        GROUP BY team1, team2
                        HAVING played >= 5
                        ORDER BY (SUM(CASE WHEN (ft_home + ft_away) > 1.5 THEN 1 ELSE 0 END) / COUNT(*)) DESC, played DESC
                        LIMIT 100";
                
                $resultH2H = $conn->query($sqlH2H);
                if (!$resultH2H) {
                    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>
                            <strong class='font-bold'>SQL Error:</strong>
                            <span class='block sm:inline'>" . $conn->error . "</span>
                          </div>";
                }
                
                $h2hMatches = [];
                if ($resultH2H && $resultH2H->num_rows > 0) {
                    while($row = $resultH2H->fetch_assoc()) {
                        $row['percentage'] = ($row['played'] > 0) ? ($row['over15'] / $row['played']) * 100 : 0;
                        $h2hMatches[] = $row;
                    }
                }
                ?>

                <!-- TAB 1: TEAMS -->
                <div x-show="activeTab === 'teams'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform scale-95" x-transition:enter-end="opacity-100 transform scale-100">
                    <div class="mb-4 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-700">Top Teams (Overall)</h2>
                        <span class="text-sm text-gray-500">Min. 10 matches</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                                <tr>
                                    <th scope="col" class="px-6 py-3 w-16 text-center">Rank</th>
                                    <th scope="col" class="px-6 py-3">Team</th>
                                    <th scope="col" class="px-6 py-3 text-center">Matches</th>
                                    <th scope="col" class="px-6 py-3 text-center">Over 1.5</th>
                                    <th scope="col" class="px-6 py-3 text-center">Percentage</th>
                                    <th scope="col" class="px-6 py-3 text-center">Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $rank = 1;
                                foreach (array_slice($rankedTeams, 0, 50) as $team): 
                                    $isVirtual = strpos($team['team'], '(V)') !== false;
                                    $displayName = $isVirtual ? str_replace('(V)', '', $team['team']) : $team['team'];
                                    
                                    $pctClass = 'text-green-600';
                                    if ($team['percentage'] < 85) $pctClass = 'text-blue-600';
                                    if ($team['percentage'] < 75) $pctClass = 'text-gray-600';
                                ?>
                                <tr class="bg-white border-b hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-center font-medium text-gray-900">
                                        <?php if ($rank <= 3) echo '<i class="fas fa-trophy text-yellow-500 mr-1"></i>'; echo $rank; ?>
                                    </td>
                                    <td class="px-6 py-4 font-medium text-gray-900">
                                        <?php echo $displayName; ?>
                                        <?php if ($isVirtual): ?>
                                            <span class="virtual-badge ml-2">V</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center"><?php echo number_format($team['played']); ?></td>
                                    <td class="px-6 py-4 text-center"><?php echo number_format($team['over15']); ?></td>
                                    <td class="px-6 py-4 text-center font-bold <?php echo $pctClass; ?>"><?php echo number_format($team['percentage'], 2); ?>%</td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="w-full bg-gray-200 rounded-full h-2.5 bg-gray-200">
                                            <div class="bg-indigo-600 h-2.5 rounded-full" style="width: <?php echo $team['percentage']; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 2: MATCHES (H2H) -->
                <div x-show="activeTab === 'matches'" x-cloak x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform scale-95" x-transition:enter-end="opacity-100 transform scale-100">
                    <div class="mb-4 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-700">Top Head-to-Head (H2H) Matchups</h2>
                        <span class="text-sm text-gray-500">Min. 5 meetings</span>
                    </div>
                    
                    <?php if (empty($h2hMatches)): ?>
                        <div class="p-8 text-center text-gray-500 bg-gray-50 rounded-lg">
                            <i class="fas fa-search mb-3 text-4xl text-gray-300"></i>
                            <p>No H2H records found with minimum 5 matches.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left text-gray-500">
                                <thead class="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 w-16 text-center">Rank</th>
                                        <th scope="col" class="px-6 py-3">Match</th>
                                        <th scope="col" class="px-6 py-3 text-center">Main (Played)</th>
                                        <th scope="col" class="px-6 py-3 text-center">Over 1.5</th>
                                        <th scope="col" class="px-6 py-3 text-center">Percentage</th>
                                        <th scope="col" class="px-6 py-3 text-center">Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    foreach ($h2hMatches as $match): 
                                        $pctClass = 'text-green-600';
                                        if ($match['percentage'] < 85) $pctClass = 'text-blue-600';
                                        if ($match['percentage'] < 75) $pctClass = 'text-gray-600';
                                        
                                        $t1Virtual = strpos($match['team1'], '(V)') !== false;
                                        $t2Virtual = strpos($match['team2'], '(V)') !== false;
                                        $t1Name = $t1Virtual ? str_replace('(V)', '', $match['team1']) : $match['team1'];
                                        $t2Name = $t2Virtual ? str_replace('(V)', '', $match['team2']) : $match['team2'];
                                    ?>
                                    <tr class="bg-white border-b hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 text-center font-medium text-gray-900">
                                            <?php if ($rank <= 3) echo '<i class="fas fa-trophy text-yellow-500 mr-1"></i>'; echo $rank; ?>
                                        </td>
                                        <td class="px-6 py-4 font-medium text-gray-900">
                                            <div class="flex items-center space-x-2">
                                                <span><?php echo $t1Name; ?></span>
                                                <span class="text-gray-400 text-xs">vs</span>
                                                <span><?php echo $t2Name; ?></span>
                                                <?php if ($t1Virtual || $t2Virtual): ?><span class="virtual-badge ml-2">V</span><?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-center"><?php echo number_format($match['played']); ?></td>
                                        <td class="px-6 py-4 text-center"><?php echo number_format($match['over15']); ?></td>
                                        <td class="px-6 py-4 text-center font-bold <?php echo $pctClass; ?>">
                                            <?php echo number_format($match['percentage'], 2); ?>%
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                                <div class="bg-indigo-600 h-2.5 rounded-full" style="width: <?php echo $match['percentage']; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php $rank++; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-4 text-xs text-gray-500 text-right">
                    Showing top 50 teams & top 100 H2H matches.
                </div>
            </div>
        </div>
    </div>
</body>
</html>