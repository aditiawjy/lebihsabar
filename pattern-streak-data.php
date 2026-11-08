<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/pattern-under15-streak.php';
require_once __DIR__.'/pattern-results-period.php';
$input = json_decode(file_get_contents('php://input'), true);
$date = $input['date'] ?? '';
$leagues = $input['leagues'] ?? [];
if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)) || !is_array($leagues) || count($leagues)>8) {
    http_response_code(400); echo json_encode(['error'=>'Permintaan statistik tidak valid.']); exit;
}
$result = [];
foreach ($leagues as $entry) {
    $league = (string)($entry['league'] ?? ''); $name = $entry['name'] ?? '';
    if (!in_array($league,['15','16','20','vietnam15','f24-club','f24-international','f26-club','f26-international'],true) || !is_string($name) || strlen($name)>160) continue;
    $period = patternResultsPeriod($date, $league);
    $result[$league] = patternCsvStreakStats(__DIR__.'/matches.csv', $name, $period['periodEnd']);
}
echo json_encode(['date'=>$date, 'leagues'=>$result], JSON_UNESCAPED_UNICODE);
