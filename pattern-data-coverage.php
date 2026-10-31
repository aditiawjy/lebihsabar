<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$zone = new DateTimeZone('Asia/Singapore');
$today = new DateTimeImmutable('today', $zone);
$endText = $_GET['end'] ?? $today->format('Y-m-d');
$startText = $_GET['start'] ?? $today->modify('-6 days')->format('Y-m-d');
$parse = static function($text) use ($zone) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text) || !checkdate((int)substr($text,5,2),(int)substr($text,8,2),(int)substr($text,0,4))) return null;
    return new DateTimeImmutable($text.' 00:00:00', $zone);
};
$start=$parse($startText); $end=$parse($endText);
if (!$start || !$end || $start > $end || $start->diff($end)->days > 30) { http_response_code(400); echo json_encode(['error'=>'Pilih rentang tanggal maksimal 31 hari.']); exit; }
$csv = getenv('MATCHES_CSV_FILE') ?: __DIR__.'/matches.csv';
$fh = is_readable($csv) ? fopen($csv, 'r') : false;
if (!$fh || !flock($fh, LOCK_SH)) { if ($fh) fclose($fh); http_response_code(503); echo json_encode(['error'=>'matches.csv tidak dapat dibaca.']); exit; }
$header = fgetcsv($fh);
if (!$header) { flock($fh,LOCK_UN); fclose($fh); http_response_code(422); echo json_encode(['error'=>'Format CSV tidak valid.']); exit; }
$header[0] = ltrim($header[0], "\xEF\xBB\xBF");
$days=[]; for ($d=$start; $d<=$end; $d=$d->modify('+1 day')) $days[$d->format('Y-m-d')] = [];
$leagues=[]; $seen=[];
while (($values=fgetcsv($fh)) !== false) {
    if (count($values)!==count($header)) continue;
    $row=array_combine($header,$values); $league=trim($row['league'] ?? '');
    if (!preg_match('/^(?:SABA |E-FOOTBALL |V-Soccer )/i',$league)) continue;
    $leagues[$league]=true;
    if (!ctype_digit($row['ft_home'] ?? '') || !ctype_digit($row['ft_away'] ?? '')) continue;
    $raw=$row['match_time'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$raw)) continue;
    $offset=$row['source_utc_offset'] ?? ''; if (preg_match('/^[+-]\d{2}:\d{2}$/D',$offset)) $raw.=$offset;
    try { $when=(new DateTimeImmutable($raw,$zone))->setTimezone($zone); } catch (Throwable $e) { continue; }
    $day=$when->format('Y-m-d'); if (!isset($days[$day])) continue;
    $home=trim(preg_replace('/\s*(?:\(V\)|\[V\])/i','',$row['home_team'] ?? '')); $away=trim(preg_replace('/\s*(?:\(V\)|\[V\])/i','',$row['away_team'] ?? ''));
    if (!$home || !$away) continue;
    $key=strtolower(json_encode([$day,$when->format('H:i:s'),$league,$home,$away])); if (isset($seen[$key])) continue; $seen[$key]=true;
    $days[$day][$league]=($days[$day][$league] ?? 0)+1;
}
flock($fh, LOCK_UN); fclose($fh);
$names=array_keys($leagues); natcasesort($names); $names=array_values($names);
$directory=getenv('SABARAJA_RESULTS_DIR') ?: '';
$result=[];
foreach ($days as $date=>$counts) {
    $previous=(new DateTimeImmutable($date,$zone))->modify('-1 day')->format('Y-m-d');
    $snapshots=[]; foreach ([$previous,$date] as $session) { $path=$directory.'/msports-'.$session.'.json'; if ($directory && is_file($path)) $snapshots[]=['session'=>$session,'updatedAt'=>gmdate('c',filemtime($path))]; }
    $cache=sys_get_temp_dir().'/sabaraja-vsoccer-results-'.md5(__DIR__.'|'.$date).'.json';
    $cached=is_file($cache) ? json_decode(file_get_contents($cache),true) : null;
    $result[]=['date'=>$date,'leagues'=>$counts,'msportsSnapshots'=>$snapshots,'vsoccerUpdatedAt'=>$cached['updatedAt'] ?? null];
}
echo json_encode(['start'=>$startText,'end'=>$endText,'timezone'=>'UTC+8','csvUpdatedAt'=>gmdate('c',filemtime($csv)),'leagueNames'=>$names,'days'=>$result],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
