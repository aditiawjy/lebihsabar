<?php
/**
 * Check Matches Health Endpoint
 * Returns health metrics and duplicate detection for matches data
 * 
 * Duplicate key: match_time|home_team|away_team|league (4 components)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

date_default_timezone_set('Asia/Jakarta');

$CSV_FILE = __DIR__ . '/matches.csv';

try {
    // Get input data (for duplicate checking against existing)
    $json = file_get_contents('php://input');
    $inputMatches = [];
    
    if ($json) {
        $data = json_decode($json, true);
        if ($data && !empty($data['matches']) && is_array($data['matches'])) {
            $inputMatches = $data['matches'];
        }
    }

    // Read existing CSV data
    $existingKeys = [];
    $existingRows = [];
    $dailyMetrics = [
        'total_today' => 0,
        'leagues_today' => [],
        'pending_score' => 0,
        'total_all_time' => 0
    ];
    
    $today = date('Y-m-d');

    if (file_exists($CSV_FILE)) {
        $fh = fopen($CSV_FILE, 'r');
        $header = fgetcsv($fh); // skip header
        
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 9) continue;
            
            [$id, $match_time, $home_team, $away_team, $league, $fh_home, $fh_away, $ft_home, $ft_away] = $row;
            
            // Create 4-component duplicate key
            $ts = strtotime($match_time);
            if ($ts) {
                $timeKey = date('Y-m-d H:i:s', $ts);
                $dupKey = strtolower(trim($timeKey) . '|' . trim($home_team) . '|' . trim($away_team) . '|' . trim($league));
                $existingKeys[$dupKey] = true;
            }
            
            $existingRows[] = [
                'id' => $id,
                'match_time' => $match_time,
                'home_team' => $home_team,
                'away_team' => $away_team,
                'league' => $league,
                'ft_home' => $ft_home,
                'ft_away' => $ft_away
            ];
            
            // Daily metrics
            $dailyMetrics['total_all_time']++;
            $matchDate = substr($match_time, 0, 10);
            
            if ($matchDate === $today) {
                $dailyMetrics['total_today']++;
                if ($league) {
                    $dailyMetrics['leagues_today'][$league] = true;
                }
                
                // Check pending score (FT null/empty)
                if ($ft_home === '' || $ft_home === null || $ft_away === '' || $ft_away === null) {
                    $dailyMetrics['pending_score']++;
                }
            }
        }
        
        fclose($fh);
    }

    // Check for duplicates in input matches
    $duplicateIndexes = [];
    $duplicateCount = 0;
    
    foreach ($inputMatches as $idx => $match) {
        $ts = strtotime($match['match_time'] ?? '');
        if (!$ts) continue;
        
        $timeKey = date('Y-m-d H:i:s', $ts);
        $dupKey = strtolower(
            trim($timeKey) . '|' . 
            trim($match['home_team'] ?? '') . '|' . 
            trim($match['away_team'] ?? '') . '|' . 
            trim($match['league'] ?? '')
        );
        
        if (isset($existingKeys[$dupKey])) {
            $duplicateIndexes[] = $idx;
            $duplicateCount++;
        }
    }

    $dailyMetrics['leagues_today'] = array_keys($dailyMetrics['leagues_today']);
    $dailyMetrics['league_count_today'] = count($dailyMetrics['leagues_today']);

    ob_clean();
    echo json_encode([
        'success' => true,
        'duplicate_count' => $duplicateCount,
        'duplicate_indexes' => $duplicateIndexes,
        'daily_metrics' => $dailyMetrics,
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => 'Asia/Jakarta'
    ]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'daily_metrics' => [
            'total_today' => 0,
            'leagues_today' => [],
            'league_count_today' => 0,
            'pending_score' => 0,
            'total_all_time' => 0
        ]
    ]);
}
?>