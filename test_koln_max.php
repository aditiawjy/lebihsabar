<?php
/**
 * Test script to verify FC Koln FHG Under 0.5 historical MAX
 */
require_once 'koneksi.php';

echo "<h2>FC Koln FHG Under 0.5 - Data Verification</h2>";

$team = "FC Koln (V)";
$league = "SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play";

// FHG Under 0.5 condition: (fh_home + fh_away) < 1
$matchCondition = "(fh_home + fh_away) < 1";

// Get daily counts for FC Koln
$query = "
    SELECT
        DATE(match_time) as match_date,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05
    FROM (
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY DATE(match_time)
    HAVING fhg_under_05 > 0
    ORDER BY fhg_under_05 DESC, match_date DESC
    LIMIT 30
";

$result = $conn->query($query);

echo "<h3>Daily FHG Under 0.5 counts (Top 30 days by count):</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Date</th><th>FHG U0.5 Count</th><th>Total Matches</th></tr>";

$dailyCounts = [];
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['match_date'] . "</td>";
    echo "<td><b>" . $row['fhg_under_05'] . "</b></td>";
    echo "<td>" . $row['total_matches'] . "</td>";
    echo "</tr>";
    $dailyCounts[$row['match_date']] = (int)$row['fhg_under_05'];
}
echo "</table>";

// Now calculate 2-day rolling windows
echo "<h3>Top 2-Day Consecutive Windows:</h3>";

// Get ALL daily counts sorted by date
$query2 = "
    SELECT
        DATE(match_time) as match_date,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05
    FROM (
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY DATE(match_time)
    HAVING fhg_under_05 > 0
    ORDER BY match_date ASC
";

$result2 = $conn->query($query2);
$allDailyCounts = [];
while ($row = $result2->fetch_assoc()) {
    $allDailyCounts[$row['match_date']] = (int)$row['fhg_under_05'];
}

// Calculate 2-day consecutive windows
$dates = array_keys($allDailyCounts);
$counts = array_values($allDailyCounts);
$n = count($dates);

$windows = [];
for ($i = 0; $i < $n - 1; $i++) {
    $date1 = new DateTime($dates[$i]);
    $date2 = new DateTime($dates[$i + 1]);
    $diff = $date1->diff($date2)->days;

    if ($diff == 1) {
        // Consecutive dates
        $windowSum = $counts[$i] + $counts[$i + 1];
        $windows[] = [
            'start_date' => $dates[$i],
            'end_date' => $dates[$i + 1],
            'day1_count' => $counts[$i],
            'day2_count' => $counts[$i + 1],
            'total' => $windowSum
        ];
    }
}

// Sort by total descending
usort($windows, function($a, $b) {
    return $b['total'] - $a['total'];
});

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Start Date</th><th>End Date</th><th>Day 1</th><th>Day 2</th><th>Total</th></tr>";
$count = 0;
foreach ($windows as $w) {
    if ($count >= 20) break;
    echo "<tr>";
    echo "<td>" . $w['start_date'] . "</td>";
    echo "<td>" . $w['end_date'] . "</td>";
    echo "<td>" . $w['day1_count'] . "</td>";
    echo "<td>" . $w['day2_count'] . "</td>";
    echo "<td><b>" . $w['total'] . "</b></td>";
    echo "</tr>";
    $count++;
}
echo "</table>";

// Check specific dates mentioned
echo "<h3>Specific Date Check:</h3>";

// Check 10-11 Sep 2025
echo "<h4>10-11 September 2025:</h4>";
$sep10 = $allDailyCounts['2025-09-10'] ?? 'NO DATA';
$sep11 = $allDailyCounts['2025-09-11'] ?? 'NO DATA';
echo "10 Sep 2025: $sep10<br>";
echo "11 Sep 2025: $sep11<br>";
if (is_numeric($sep10) && is_numeric($sep11)) {
    echo "Total: " . ($sep10 + $sep11) . "<br>";
}

// Check 29-30 Jun 2025
echo "<h4>29-30 June 2025:</h4>";
$jun29 = $allDailyCounts['2025-06-29'] ?? 'NO DATA';
$jun30 = $allDailyCounts['2025-06-30'] ?? 'NO DATA';
echo "29 Jun 2025: $jun29<br>";
echo "30 Jun 2025: $jun30<br>";
if (is_numeric($jun29) && is_numeric($jun30)) {
    echo "Total: " . ($jun29 + $jun30) . "<br>";
}

// Check 30 Jun - 1 Jul 2025
echo "<h4>30 June - 1 July 2025:</h4>";
$jul01 = $allDailyCounts['2025-07-01'] ?? 'NO DATA';
echo "30 Jun 2025: $jun30<br>";
echo "01 Jul 2025: $jul01<br>";
if (is_numeric($jun30) && is_numeric($jul01)) {
    echo "Total: " . ($jun30 + $jul01) . "<br>";
}

echo "<h3>Maximum 2-Day Window Found:</h3>";
if (!empty($windows)) {
    $max = $windows[0];
    echo "Start: " . $max['start_date'] . "<br>";
    echo "End: " . $max['end_date'] . "<br>";
    echo "Day 1: " . $max['day1_count'] . "<br>";
    echo "Day 2: " . $max['day2_count'] . "<br>";
    echo "<b>TOTAL: " . $max['total'] . "</b><br>";
} else {
    echo "No consecutive 2-day windows found!";
}

// ============ TIME FILTER TEST ============
echo "<hr><h2>TIME FILTER TEST: 00:00 - 15:59</h2>";

// Test with time filter 00:00-15:59
$timeFilter = " AND TIME(match_time) >= '00:00:00' AND TIME(match_time) <= '15:59:59'";

$queryTimeFiltered = "
    SELECT
        DATE(match_time) as match_date,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05
    FROM (
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL $timeFilter
        UNION ALL
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL $timeFilter
    ) all_matches
    GROUP BY DATE(match_time)
    HAVING fhg_under_05 > 0
    ORDER BY fhg_under_05 DESC, match_date DESC
    LIMIT 30
";

$resultTimeFiltered = $conn->query($queryTimeFiltered);

echo "<h3>Daily FHG Under 0.5 counts (00:00-15:59 ONLY):</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Date</th><th>FHG U0.5 Count (00:00-15:59)</th><th>Total Matches</th></tr>";

$timeFilteredCounts = [];
if ($resultTimeFiltered) {
    while ($row = $resultTimeFiltered->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['match_date'] . "</td>";
        echo "<td><b>" . $row['fhg_under_05'] . "</b></td>";
        echo "<td>" . $row['total_matches'] . "</td>";
        echo "</tr>";
        $timeFilteredCounts[$row['match_date']] = (int)$row['fhg_under_05'];
    }
}
echo "</table>";

// Check Sep 10-11 with time filter
echo "<h4>10-11 Sep 2025 (00:00-15:59 ONLY):</h4>";
$sep10_filtered = $timeFilteredCounts['2025-09-10'] ?? 'NO DATA';
$sep11_filtered = $timeFilteredCounts['2025-09-11'] ?? 'NO DATA';
echo "10 Sep 2025: $sep10_filtered<br>";
echo "11 Sep 2025: $sep11_filtered<br>";
if (is_numeric($sep10_filtered) && is_numeric($sep11_filtered)) {
    echo "Total: " . ($sep10_filtered + $sep11_filtered) . "<br>";
}

// Show actual match times for Sep 10-11 to understand distribution
echo "<h3>Match Times Distribution (10-11 Sep 2025):</h3>";
$matchTimesQuery = "
    SELECT
        match_time,
        TIME(match_time) as match_time_only,
        CASE WHEN TIME(match_time) >= '00:00:00' AND TIME(match_time) <= '15:59:59' THEN 'YES' ELSE 'NO' END as in_time_range,
        fh_home,
        fh_away,
        CASE WHEN $matchCondition THEN 'YES' ELSE 'NO' END as is_fhg_under_05
    FROM (
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        AND DATE(match_time) IN ('2025-09-10', '2025-09-11')
        UNION ALL
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        AND DATE(match_time) IN ('2025-09-10', '2025-09-11')
    ) all_matches
    ORDER BY match_time
";

$matchTimesResult = $conn->query($matchTimesQuery);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Match Time</th><th>Time Only</th><th>In 00:00-15:59?</th><th>FH Score</th><th>Is FHG U0.5?</th></tr>";

$inRangeCount = 0;
$outRangeCount = 0;
$inRangeFHGU05 = 0;
$outRangeFHGU05 = 0;

if ($matchTimesResult) {
    while ($row = $matchTimesResult->fetch_assoc()) {
        $inRange = $row['in_time_range'] === 'YES';
        $isFHGU05 = $row['is_fhg_under_05'] === 'YES';

        if ($inRange) {
            $inRangeCount++;
            if ($isFHGU05) $inRangeFHGU05++;
        } else {
            $outRangeCount++;
            if ($isFHGU05) $outRangeFHGU05++;
        }

        echo "<tr style='background:" . ($inRange ? '#d4edda' : '#f8d7da') . "'>";
        echo "<td>" . $row['match_time'] . "</td>";
        echo "<td>" . $row['match_time_only'] . "</td>";
        echo "<td>" . $row['in_time_range'] . "</td>";
        echo "<td>" . $row['fh_home'] . "-" . $row['fh_away'] . "</td>";
        echo "<td>" . $row['is_fhg_under_05'] . "</td>";
        echo "</tr>";
    }
}
echo "</table>";

echo "<h4>Summary for 10-11 Sep 2025:</h4>";
echo "Matches in 00:00-15:59: $inRangeCount (FHG U0.5: $inRangeFHGU05)<br>";
echo "Matches OUTSIDE 00:00-15:59: $outRangeCount (FHG U0.5: $outRangeFHGU05)<br>";
echo "<b>If time filter applied, MAX should be: $inRangeFHGU05</b><br>";
echo "<b>Without time filter, MAX is: " . ($inRangeFHGU05 + $outRangeFHGU05) . "</b><br>";

// ============ ADDITIONAL ANALYSIS ============
echo "<hr><h2>ADDITIONAL ANALYSIS: Correlation Factors</h2>";

// 1. HOME/AWAY SPLIT ANALYSIS
echo "<h3>1. Home vs Away Analysis</h3>";
$homeAwayQuery = "
    SELECT
        status,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05,
        ROUND(SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
    FROM (
        SELECT match_time, fh_home, fh_away, ft_home, ft_away, 'HOME' as status
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT match_time, fh_home, fh_away, ft_home, ft_away, 'AWAY' as status
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY status
    ORDER BY status
";

$homeAwayResult = $conn->query($homeAwayQuery);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Status</th><th>Total Matches</th><th>FHG U0.5 Count</th><th>Percentage</th></tr>";
if ($homeAwayResult) {
    while ($row = $homeAwayResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td><b>" . $row['status'] . "</b></td>";
        echo "<td>" . $row['total_matches'] . "</td>";
        echo "<td>" . $row['fhg_under_05'] . "</td>";
        echo "<td><b>" . $row['percentage'] . "%</b></td>";
        echo "</tr>";
    }
}
echo "</table>";

// 2. TOTAL FT GOALS CORRELATION
echo "<h3>2. Total Full-Time Goals Correlation</h3>";
$ftGoalsQuery = "
    SELECT
        CASE
            WHEN (ft_home + ft_away) <= 1 THEN '0-1 goals'
            WHEN (ft_home + ft_away) = 2 THEN '2 goals'
            WHEN (ft_home + ft_away) = 3 THEN '3 goals'
            ELSE '4+ goals'
        END as ft_goals_category,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05,
        ROUND(SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
    FROM (
        SELECT match_time, fh_home, fh_away, ft_home, ft_away
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT match_time, fh_home, fh_away, ft_home, ft_away
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY ft_goals_category
    ORDER BY FIELD(ft_goals_category, '0-1 goals', '2 goals', '3 goals', '4+ goals')
";

$ftGoalsResult = $conn->query($ftGoalsQuery);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Total FT Goals</th><th>Total Matches</th><th>FHG U0.5 Count</th><th>Percentage</th></tr>";
if ($ftGoalsResult) {
    while ($row = $ftGoalsResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['ft_goals_category'] . "</td>";
        echo "<td>" . $row['total_matches'] . "</td>";
        echo "<td>" . $row['fhg_under_05'] . "</td>";
        echo "<td><b>" . $row['percentage'] . "%</b></td>";
        echo "</tr>";
    }
}
echo "</table>";

// 3. DAY OF WEEK PATTERN
echo "<h3>3. Day of Week Pattern</h3>";
$dayOfWeekQuery = "
    SELECT
        DAYOFWEEK(match_time) as day_num,
        CASE DAYOFWEEK(match_time)
            WHEN 1 THEN 'Sunday'
            WHEN 2 THEN 'Monday'
            WHEN 3 THEN 'Tuesday'
            WHEN 4 THEN 'Wednesday'
            WHEN 5 THEN 'Thursday'
            WHEN 6 THEN 'Friday'
            WHEN 7 THEN 'Saturday'
        END as day_name,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05,
        ROUND(SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
    FROM (
        SELECT match_time, fh_home, fh_away, ft_home, ft_away
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT match_time, fh_home, fh_away, ft_home, ft_away
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY day_num, day_name
    ORDER BY day_num
";

$dayOfWeekResult = $conn->query($dayOfWeekQuery);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Day of Week</th><th>Total Matches</th><th>FHG U0.5 Count</th><th>Percentage</th></tr>";
if ($dayOfWeekResult) {
    while ($row = $dayOfWeekResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['day_name'] . "</td>";
        echo "<td>" . $row['total_matches'] . "</td>";
        echo "<td>" . $row['fhg_under_05'] . "</td>";
        echo "<td><b>" . $row['percentage'] . "%</b></td>";
        echo "</tr>";
    }
}
echo "</table>";

// 4. TIME RANGE BREAKDOWN (3 Segments)
echo "<h3>4. Time Range Breakdown (3 Segments)</h3>";
$timeRangeQuery = "
    SELECT
        CASE
            WHEN TIME(match_time) >= '00:00:00' AND TIME(match_time) <= '07:59:59' THEN '00:00-07:59 (Morning)'
            WHEN TIME(match_time) >= '08:00:00' AND TIME(match_time) <= '15:59:59' THEN '08:00-15:59 (Noon)'
            ELSE '16:00-23:59 (Evening)'
        END as time_range,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05,
        ROUND(SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
    FROM (
        SELECT match_time, fh_home, fh_away, ft_home, ft_away
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT match_time, fh_home, fh_away, ft_home, ft_away
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY time_range
    ORDER BY time_range
";

$timeRangeResult = $conn->query($timeRangeQuery);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Time Range</th><th>Total Matches</th><th>FHG U0.5 Count</th><th>Percentage</th></tr>";
if ($timeRangeResult) {
    while ($row = $timeRangeResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['time_range'] . "</td>";
        echo "<td>" . $row['total_matches'] . "</td>";
        echo "<td>" . $row['fhg_under_05'] . "</td>";
        echo "<td><b>" . $row['percentage'] . "%</b></td>";
        echo "</tr>";
    }
}
echo "</table>";

// 5. MATCH OUTCOME CORRELATION
echo "<h3>5. Match Outcome Correlation</h3>";
$matchOutcomeQuery = "
    SELECT
        outcome,
        COUNT(*) as total_matches,
        SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) as fhg_under_05,
        ROUND(SUM(CASE WHEN $matchCondition THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentage
    FROM (
        SELECT
            match_time, fh_home, fh_away, ft_home, ft_away,
            CASE
                WHEN ft_home > ft_away THEN 'WIN'
                WHEN ft_home < ft_away THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        UNION ALL
        SELECT
            match_time, fh_home, fh_away, ft_home, ft_away,
            CASE
                WHEN ft_away > ft_home THEN 'WIN'
                WHEN ft_away < ft_home THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) all_matches
    GROUP BY outcome
    ORDER BY FIELD(outcome, 'WIN', 'DRAW', 'LOSE')
";

$matchOutcomeResult = $conn->query($matchOutcomeQuery);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Match Outcome</th><th>Total Matches</th><th>FHG U0.5 Count</th><th>Percentage</th></tr>";
if ($matchOutcomeResult) {
    while ($row = $matchOutcomeResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td><b>" . $row['outcome'] . "</b></td>";
        echo "<td>" . $row['total_matches'] . "</td>";
        echo "<td>" . $row['fhg_under_05'] . "</td>";
        echo "<td><b>" . $row['percentage'] . "%</b></td>";
        echo "</tr>";
    }
}
echo "</table>";

// ============ COMBINED CONDITIONS ANALYSIS ============
echo "<hr><h2>COMBINED CONDITIONS: FHG U0.5 + Recent Form</h2>";
echo "<p><i>Analyzing if previous match result affects FHG Under 0.5 likelihood</i></p>";

// Get all matches with previous match info
$recentFormQuery = "
    WITH all_team_matches AS (
        SELECT
            match_time,
            fh_home,
            fh_away,
            ft_home,
            ft_away,
            'HOME' as status,
            CASE
                WHEN ft_home > ft_away THEN 'WIN'
                WHEN ft_home < ft_away THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome,
            (ft_home + ft_away) as total_goals
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL

        UNION ALL

        SELECT
            match_time,
            fh_home,
            fh_away,
            ft_home,
            ft_away,
            'AWAY' as status,
            CASE
                WHEN ft_away > ft_home THEN 'WIN'
                WHEN ft_away < ft_home THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome,
            (ft_home + ft_away) as total_goals
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ),
    matches_with_prev AS (
        SELECT
            m1.*,
            m2.outcome as prev_outcome,
            m2.total_goals as prev_total_goals,
            CASE WHEN (m1.fh_home + m1.fh_away) < 1 THEN 1 ELSE 0 END as is_fhg_u05
        FROM all_team_matches m1
        LEFT JOIN all_team_matches m2
            ON m2.match_time < m1.match_time
            AND m2.match_time = (
                SELECT MAX(match_time)
                FROM all_team_matches
                WHERE match_time < m1.match_time
            )
    )
    SELECT
        'FHG U0.5 + Last Match LOSS' as condition_name,
        COUNT(*) as total_occurrences,
        SUM(CASE WHEN DATE(match_time) IN (
            SELECT DATE(match_time)
            FROM matches_with_prev
            WHERE is_fhg_u05 = 1 AND prev_outcome = 'LOSE'
            GROUP BY DATE(match_time)
        ) THEN 1 ELSE 0 END) as days_with_condition,
        GROUP_CONCAT(DISTINCT DATE(match_time) ORDER BY match_time DESC SEPARATOR ', ') as sample_dates
    FROM matches_with_prev
    WHERE is_fhg_u05 = 1 AND prev_outcome = 'LOSE'

    UNION ALL

    SELECT
        'FHG U0.5 + Last Match Under 1.5' as condition_name,
        COUNT(*) as total_occurrences,
        SUM(CASE WHEN DATE(match_time) IN (
            SELECT DATE(match_time)
            FROM matches_with_prev
            WHERE is_fhg_u05 = 1 AND prev_total_goals < 1.5
            GROUP BY DATE(match_time)
        ) THEN 1 ELSE 0 END) as days_with_condition,
        GROUP_CONCAT(DISTINCT DATE(match_time) ORDER BY match_time DESC SEPARATOR ', ') as sample_dates
    FROM matches_with_prev
    WHERE is_fhg_u05 = 1 AND prev_total_goals < 1.5

    UNION ALL

    SELECT
        'FHG U0.5 + Last Match Under 2.5' as condition_name,
        COUNT(*) as total_occurrences,
        SUM(CASE WHEN DATE(match_time) IN (
            SELECT DATE(match_time)
            FROM matches_with_prev
            WHERE is_fhg_u05 = 1 AND prev_total_goals < 2.5
            GROUP BY DATE(match_time)
        ) THEN 1 ELSE 0 END) as days_with_condition,
        GROUP_CONCAT(DISTINCT DATE(match_time) ORDER BY match_time DESC SEPARATOR ', ') as sample_dates
    FROM matches_with_prev
    WHERE is_fhg_u05 = 1 AND prev_total_goals < 2.5

    UNION ALL

    SELECT
        'FHG U0.5 ONLY (baseline)' as condition_name,
        COUNT(*) as total_occurrences,
        COUNT(DISTINCT DATE(match_time)) as days_with_condition,
        NULL as sample_dates
    FROM matches_with_prev
    WHERE is_fhg_u05 = 1
";

// Note: CTE might not work in older MySQL, let's use simpler approach
$recentFormQuerySimple = "
    SELECT
        'Total FHG U0.5 Matches (Baseline)' as condition_name,
        COUNT(*) as match_count,
        COUNT(DISTINCT DATE(match_time)) as unique_dates
    FROM (
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        AND (fh_home + fh_away) < 1
        UNION ALL
        SELECT match_time, fh_home, fh_away FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
        AND (fh_home + fh_away) < 1
    ) fhg_matches
";

$baselineResult = $conn->query($recentFormQuerySimple);
echo "<h3>Baseline: FHG Under 0.5 Statistics</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Metric</th><th>Match Count</th><th>Unique Dates</th></tr>";
if ($baselineResult && $row = $baselineResult->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['condition_name'] . "</td>";
    echo "<td><b>" . $row['match_count'] . "</b></td>";
    echo "<td><b>" . $row['unique_dates'] . "</b></td>";
    echo "</tr>";
}
echo "</table>";

// Detailed analysis with previous match context
echo "<h3>6. Combined Conditions: FHG U0.5 + Previous Match Form</h3>";
echo "<p><i>Shows matches where BOTH FHG U0.5 occurred AND specific previous match condition was met</i></p>";

// Get matches with previous match info using self-join approach
$combinedAnalysis = "
    SELECT
        curr.match_time as current_time,
        DATE(curr.match_time) as match_date,
        curr.fh_home as curr_fh_home,
        curr.fh_away as curr_fh_away,
        curr.ft_home as curr_ft_home,
        curr.ft_away as curr_ft_away,
        curr.outcome as curr_outcome,
        prev.match_time as prev_time,
        prev.outcome as prev_outcome,
        (prev.ft_home + prev.ft_away) as prev_total_goals
    FROM (
        SELECT
            match_time, fh_home, fh_away, ft_home, ft_away,
            CASE
                WHEN ft_home > ft_away THEN 'WIN'
                WHEN ft_home < ft_away THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL

        UNION ALL

        SELECT
            match_time, fh_home, fh_away, ft_home, ft_away,
            CASE
                WHEN ft_away > ft_home THEN 'WIN'
                WHEN ft_away < ft_home THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) curr
    LEFT JOIN (
        SELECT
            match_time, ft_home, ft_away,
            CASE
                WHEN ft_home > ft_away THEN 'WIN'
                WHEN ft_home < ft_away THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome
        FROM matches
        WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL

        UNION ALL

        SELECT
            match_time, ft_home, ft_away,
            CASE
                WHEN ft_away > ft_home THEN 'WIN'
                WHEN ft_away < ft_home THEN 'LOSE'
                ELSE 'DRAW'
            END as outcome
        FROM matches
        WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
    ) prev ON prev.match_time < curr.match_time
        AND prev.match_time = (
            SELECT MAX(m.match_time)
            FROM (
                SELECT match_time FROM matches
                WHERE home_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
                UNION ALL
                SELECT match_time FROM matches
                WHERE away_team = '$team' AND league = '$league' AND ft_home IS NOT NULL
            ) m
            WHERE m.match_time < curr.match_time
        )
    WHERE (curr.fh_home + curr.fh_away) < 1
    ORDER BY curr.match_time DESC
    LIMIT 50
";

$combinedResult = $conn->query($combinedAnalysis);

// Count statistics
$total_fhg_u05 = 0;
$with_prev_loss = 0;
$with_prev_u15 = 0;
$with_prev_u25 = 0;
$dates_prev_loss = [];
$dates_prev_u15 = [];
$dates_prev_u25 = [];

if ($combinedResult) {
    $combinedResult->data_seek(0); // Reset pointer
    while ($row = $combinedResult->fetch_assoc()) {
        $total_fhg_u05++;
        $date = $row['match_date'];

        if ($row['prev_outcome'] === 'LOSE') {
            $with_prev_loss++;
            $dates_prev_loss[$date] = ($dates_prev_loss[$date] ?? 0) + 1;
        }

        if ($row['prev_total_goals'] !== null && $row['prev_total_goals'] < 1.5) {
            $with_prev_u15++;
            $dates_prev_u15[$date] = ($dates_prev_u15[$date] ?? 0) + 1;
        }

        if ($row['prev_total_goals'] !== null && $row['prev_total_goals'] < 2.5) {
            $with_prev_u25++;
            $dates_prev_u25[$date] = ($dates_prev_u25[$date] ?? 0) + 1;
        }
    }
}

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Combined Condition</th><th>Match Count (Last 50 FHG U0.5)</th><th>Unique Dates</th><th>Percentage</th></tr>";

$pct_loss = $total_fhg_u05 > 0 ? round(($with_prev_loss / $total_fhg_u05) * 100, 2) : 0;
$pct_u15 = $total_fhg_u05 > 0 ? round(($with_prev_u15 / $total_fhg_u05) * 100, 2) : 0;
$pct_u25 = $total_fhg_u05 > 0 ? round(($with_prev_u25 / $total_fhg_u05) * 100, 2) : 0;

echo "<tr>";
echo "<td><b>FHG U0.5 + Previous Match LOSS</b></td>";
echo "<td>" . $with_prev_loss . "</td>";
echo "<td>" . count($dates_prev_loss) . "</td>";
echo "<td><b>" . $pct_loss . "%</b></td>";
echo "</tr>";

echo "<tr>";
echo "<td><b>FHG U0.5 + Previous Match Under 1.5</b></td>";
echo "<td>" . $with_prev_u15 . "</td>";
echo "<td>" . count($dates_prev_u15) . "</td>";
echo "<td><b>" . $pct_u15 . "%</b></td>";
echo "</tr>";

echo "<tr>";
echo "<td><b>FHG U0.5 + Previous Match Under 2.5</b></td>";
echo "<td>" . $with_prev_u25 . "</td>";
echo "<td>" . count($dates_prev_u25) . "</td>";
echo "<td><b>" . $pct_u25 . "%</b></td>";
echo "</tr>";

echo "<tr style='background:#e9ecef'>";
echo "<td><i>Total FHG U0.5 (in sample)</i></td>";
echo "<td><i>" . $total_fhg_u05 . "</i></td>";
echo "<td colspan='2'><i>Last 50 matches with FHG U0.5</i></td>";
echo "</tr>";

echo "</table>";

// Show detailed sample
echo "<h4>Sample Matches (Last 20 FHG U0.5 with Previous Match Context):</h4>";
echo "<table border='1' cellpadding='5' style='font-size: 12px;'>";
echo "<tr><th>Current Match</th><th>FH</th><th>FT</th><th>Result</th><th>Prev Match</th><th>Prev Result</th><th>Prev Goals</th><th>Conditions Met</th></tr>";

if ($combinedResult) {
    $combinedResult->data_seek(0); // Reset pointer
    $count = 0;
    while ($row = $combinedResult->fetch_assoc() && $count < 20) {
        $conditions = [];
        if ($row['prev_outcome'] === 'LOSE') $conditions[] = 'Prev LOSS';
        if ($row['prev_total_goals'] !== null && $row['prev_total_goals'] < 1.5) $conditions[] = 'Prev U1.5';
        if ($row['prev_total_goals'] !== null && $row['prev_total_goals'] < 2.5) $conditions[] = 'Prev U2.5';

        $conditionsText = !empty($conditions) ? implode(', ', $conditions) : '-';

        echo "<tr>";
        echo "<td>" . $row['current_time'] . "</td>";
        echo "<td>" . $row['curr_fh_home'] . "-" . $row['curr_fh_away'] . "</td>";
        echo "<td>" . $row['curr_ft_home'] . "-" . $row['curr_ft_away'] . "</td>";
        echo "<td>" . $row['curr_outcome'] . "</td>";
        echo "<td>" . ($row['prev_time'] ?? 'N/A') . "</td>";
        echo "<td>" . ($row['prev_outcome'] ?? 'N/A') . "</td>";
        echo "<td>" . ($row['prev_total_goals'] ?? 'N/A') . "</td>";
        echo "<td><b>" . $conditionsText . "</b></td>";
        echo "</tr>";
        $count++;
    }
}
echo "</table>";

echo "<h3>Key Insights</h3>";
echo "<p><i>Review the percentages above to identify the strongest correlations with FHG Under 0.5 occurrences.</i></p>";
?>
