<?php
/**
 * Test Script untuk Datetime Optimization
 * Memverifikasi bahwa query menggunakan index idx_match_time
 */

require_once 'koneksi.php';

echo "==============================================\n";
echo "Test DateTime Optimization\n";
echo "==============================================\n\n";

// Test 1: Same-day range query
echo "Test 1: Same-Day Range Query\n";
echo "------------------------------\n";
$testDate = '2024-01-15';
$timeFrom = '10:00:00';
$timeTo = '22:00:59';
$rangeStart = "$testDate $timeFrom";
$rangeEnd = "$testDate $timeTo";

$sql = "EXPLAIN SELECT * FROM matches
        WHERE match_time >= '$rangeStart'
          AND match_time <= '$rangeEnd'
        LIMIT 10";

echo "Query:\n$sql\n\n";
$result = $conn->query($sql);

if ($result) {
    echo "EXPLAIN Output:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - type: " . ($row['type'] ?? 'N/A') . "\n";
        echo "  - key: " . ($row['key'] ?? 'NULL') . "\n";
        echo "  - rows: " . ($row['rows'] ?? 'N/A') . "\n";

        if (isset($row['key']) && strpos($row['key'], 'match_time') !== false) {
            echo "  ✅ SUCCESS: Using index on match_time!\n";
        } elseif ($row['type'] === 'ALL') {
            echo "  ⚠️  WARNING: Full table scan (type=ALL)\n";
        }
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n\n";

// Test 2: Overnight range query
echo "Test 2: Overnight Range Query\n";
echo "------------------------------\n";
$testDate1 = '2024-01-15';
$testDate2 = '2024-01-16';
$timeFrom = '21:00:00';
$timeTo = '09:00:59';
$overnightStart = "$testDate1 $timeFrom";
$overnightEnd = "$testDate2 $timeTo";

$sql = "EXPLAIN SELECT * FROM matches
        WHERE match_time >= '$overnightStart'
          AND match_time <= '$overnightEnd'
        LIMIT 10";

echo "Query:\n$sql\n\n";
$result = $conn->query($sql);

if ($result) {
    echo "EXPLAIN Output:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - type: " . ($row['type'] ?? 'N/A') . "\n";
        echo "  - key: " . ($row['key'] ?? 'NULL') . "\n";
        echo "  - rows: " . ($row['rows'] ?? 'N/A') . "\n";

        if (isset($row['key']) && strpos($row['key'], 'match_time') !== false) {
            echo "  ✅ SUCCESS: Using index on match_time!\n";
        } elseif ($row['type'] === 'ALL') {
            echo "  ⚠️  WARNING: Full table scan (type=ALL)\n";
        }
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n\n";

// Test 3: OLD style query (for comparison)
echo "Test 3: OLD Style Query (for comparison)\n";
echo "-----------------------------------------\n";
$sql = "EXPLAIN SELECT * FROM matches
        WHERE DATE(match_time) = '$testDate'
          AND TIME(match_time) >= '$timeFrom'
          AND TIME(match_time) <= '$timeTo'
        LIMIT 10";

echo "Query:\n$sql\n\n";
$result = $conn->query($sql);

if ($result) {
    echo "EXPLAIN Output:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - type: " . ($row['type'] ?? 'N/A') . "\n";
        echo "  - key: " . ($row['key'] ?? 'NULL') . "\n";
        echo "  - rows: " . ($row['rows'] ?? 'N/A') . "\n";

        if ($row['type'] === 'ALL') {
            echo "  ❌ EXPECTED: Full table scan because of DATE()/TIME() functions\n";
        }
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n\n";

// Test 4: Performance comparison
echo "Test 4: Performance Comparison\n";
echo "------------------------------\n";

// Get a real date from the database
$dateResult = $conn->query("SELECT DATE(match_time) as match_date FROM matches WHERE match_time IS NOT NULL LIMIT 1");
if ($dateResult && $row = $dateResult->fetch_assoc()) {
    $realDate = $row['match_date'];
    $timeFrom = '00:00:00';
    $timeTo = '23:59:59';

    echo "Testing with real data from: $realDate\n\n";

    // Test OLD style
    $start = microtime(true);
    $sql = "SELECT COUNT(*) as cnt FROM matches
            WHERE DATE(match_time) = '$realDate'
              AND TIME(match_time) >= '$timeFrom'
              AND TIME(match_time) <= '$timeTo'";
    $result = $conn->query($sql);
    $oldTime = (microtime(true) - $start) * 1000;
    $oldCount = $result ? $result->fetch_assoc()['cnt'] : 0;

    // Test NEW style
    $rangeStart = "$realDate $timeFrom";
    $rangeEnd = "$realDate $timeTo";
    $start = microtime(true);
    $sql = "SELECT COUNT(*) as cnt FROM matches
            WHERE match_time >= '$rangeStart'
              AND match_time <= '$rangeEnd'";
    $result = $conn->query($sql);
    $newTime = (microtime(true) - $start) * 1000;
    $newCount = $result ? $result->fetch_assoc()['cnt'] : 0;

    echo "OLD Style (DATE/TIME functions):\n";
    echo "  - Count: $oldCount matches\n";
    echo "  - Time: " . number_format($oldTime, 2) . " ms\n\n";

    echo "NEW Style (datetime range):\n";
    echo "  - Count: $newCount matches\n";
    echo "  - Time: " . number_format($newTime, 2) . " ms\n\n";

    if ($oldCount === $newCount) {
        echo "✅ Results match! Both queries return same count.\n";
    } else {
        echo "⚠️  WARNING: Results don't match!\n";
    }

    if ($newTime < $oldTime) {
        $improvement = round(($oldTime / $newTime), 2);
        echo "✅ NEW style is {$improvement}x FASTER!\n";
    } else {
        echo "⚠️  No performance improvement detected.\n";
    }
}

echo "\n\n==============================================\n";
echo "Test Complete!\n";
echo "==============================================\n";

$conn->close();
?>
