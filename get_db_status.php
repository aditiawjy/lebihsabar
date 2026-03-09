<?php
require_once 'koneksi.php';

header('Content-Type: application/json');

// Get total matches
$totalResult = $conn->query("SELECT COUNT(*) as total FROM matches");
$totalMatches = $totalResult->fetch_assoc()['total'];

// Get unique leagues
$leagueResult = $conn->query("SELECT COUNT(DISTINCT league) as total FROM matches WHERE league IS NOT NULL");
$totalLeagues = $leagueResult->fetch_assoc()['total'];

// Get last match
$lastResult = $conn->query("SELECT match_time FROM matches ORDER BY match_time DESC LIMIT 1");
$lastMatch = null;
if ($row = $lastResult->fetch_assoc()) {
    $date = new DateTime($row['match_time']);
    $lastMatch = $date->format('d/m/Y H:i');
}

echo json_encode([
    'totalMatches' => $totalMatches,
    'totalLeagues' => $totalLeagues,
    'lastMatch' => $lastMatch
]);

$conn->close();
?>
