<?php
/**
 * Ultra Fast Bulk Import - No Safety, Just Speed!
 */

// Disable all limits
set_time_limit(0);
ini_set('memory_limit', '-1');
ini_set('display_errors', 0);
error_reporting(0);

require_once 'koneksi.php';

// Optimize MySQL for speed
$conn->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
$conn->query("SET SESSION autocommit = 1");
$conn->query("SET SESSION unique_checks = 0");
$conn->query("SET SESSION foreign_key_checks = 0");
$conn->query("SET SESSION bulk_insert_buffer_size = 256*1024*1024");

// Clear table fast
$conn->query("TRUNCATE TABLE matches");

echo "=== ULTRA FAST BULK IMPORT ===\n";
echo "All safety disabled - MAX SPEED!\n\n";

$csvFile = __DIR__ . '/matches.csv';
$handle = fopen($csvFile, 'r');
fgetcsv($handle); // Skip header

$total = 0;
$values = [];
$batchSize = 1000; // Large batch for speed

echo "Building INSERT batches...\n";

// Build all values first
while (($row = fgetcsv($handle)) !== false) {
    if (empty($row[1]) || $row[0] === 'id') continue;
    
    // Escape and format
    $match_time = $conn->real_escape_string($row[1]);
    $home_team = $conn->real_escape_string($row[2]);
    $away_team = $conn->real_escape_string($row[3]);
    $league = $conn->real_escape_string($row[4]);
    $fh_home = (int)$row[5];
    $fh_away = (int)$row[6];
    $ft_home = (int)$row[7];
    $ft_away = (int)$row[8];
    
    $values[] = "('$match_time','$home_team','$away_team','$league',$fh_home,$fh_away,$ft_home,$ft_away)";
    $total++;
    
    if ($total % 5000 == 0) {
        echo "\rCollected: " . number_format($total) . " rows";
    }
}

fclose($handle);
echo "\n\nTotal collected: " . number_format($total) . " rows\n";

// Now insert in large batches
echo "Inserting batches...\n";
$inserted = 0;
$startTime = microtime(true);

for ($i = 0; $i < count($values); $i += $batchSize) {
    $batch = array_slice($values, $i, $batchSize);
    $sql = "INSERT INTO matches (match_time, home_team, away_team, league, fh_home, fh_away, ft_home, ft_away) VALUES " . implode(',', $batch);
    
    if ($conn->query($sql)) {
        $inserted += count($batch);
        
        if ($inserted % 5000 == 0) {
            $speed = round($inserted / (microtime(true) - $startTime));
            echo "\rInserted: " . number_format($inserted) . " rows | Speed: " . number_format($speed) . " rows/sec";
        }
    }
}

// Restore settings
$conn->query("SET SESSION unique_checks = 1");
$conn->query("SET SESSION foreign_key_checks = 1");
$conn->query("SET SESSION autocommit = 1");

$duration = round(microtime(true) - $startTime, 2);
echo "\n\n✅ BLAST COMPLETE!\n";
echo "Inserted: " . number_format($inserted) . " rows\n";
echo "Time: {$duration} seconds\n";
echo "Speed: " . number_format($inserted / $duration) . " rows/sec\n";

// Quick check
$result = $conn->query("SELECT COUNT(*) as total FROM matches");
$count = $result->fetch_assoc()['total'];
echo "Database: " . number_format($count) . " rows\n";

$conn->close();
?>
