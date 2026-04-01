<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$payload = json_decode($body, true);

if (!isset($payload['goals']) || !is_array($payload['goals']) || !count($payload['goals'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No goals provided']);
    exit;
}

$logFile = __DIR__ . '/goal_log.json';

// Load existing
$existing = [];
if (is_file($logFile) && is_readable($logFile)) {
    $raw = file_get_contents($logFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $existing = $decoded;
    }
}

// Append new
foreach ($payload['goals'] as $goal) {
    $existing[] = [
        'timestamp'    => $goal['timestamp']   ?? date('c'),
        'time'         => $goal['time']         ?? '',
        'league'       => $goal['league']       ?? '',
        'home_team'    => $goal['home_team']    ?? '',
        'away_team'    => $goal['away_team']    ?? '',
        'minute'       => $goal['minute']       ?? '',
        'score_before' => $goal['score_before'] ?? '',
        'score_after'  => $goal['score_after']  ?? '',
        'home_score'   => $goal['home_score']   ?? '',
        'away_score'   => $goal['away_score']   ?? '',
    ];
}

// Keep last 2000 entries
if (count($existing) > 2000) {
    $existing = array_slice($existing, -2000);
}

file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode(['ok' => true, 'saved' => count($payload['goals'])]);
