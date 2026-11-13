<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
    http_response_code(400); echo json_encode(['error'=>'Tanggal tidak valid.']); exit;
}
$path = getenv('MATCHES_CSV_FILE') ?: __DIR__.'/matches.csv';
$fh = is_readable($path) ? fopen($path,'r') : false;
if (!$fh || !flock($fh,LOCK_SH)) { if ($fh) fclose($fh); http_response_code(503); echo json_encode(['error'=>'Data CSV V-Soccer tidak dapat dibaca.']); exit; }
$header = fgetcsv($fh);
if (!$header) { flock($fh,LOCK_UN); fclose($fh); http_response_code(422); echo json_encode(['error'=>'Format CSV tidak valid.']); exit; }
$header[0] = ltrim($header[0], "\xEF\xBB\xBF");
$zone = new DateTimeZone('Asia/Singapore');
$clean = static fn($text) => trim(preg_replace('/\s+/', ' ', preg_replace('/\s*(?:\(V\)|\[V\])/i', '', $text)));
$snapshots = []; $leagueNames = []; $seen = [];
while (($values = fgetcsv($fh)) !== false) {
    if (count($values) !== count($header)) continue;
    $row = array_combine($header,$values);
    $name = trim($row['league'] ?? '');
    if (stripos($name,'V-Soccer ') !== 0) continue;
    $id = 'vsoccer:'.$name;
    $leagueNames[$id] = $clean($name);
    if (!ctype_digit($row['ft_home'] ?? '') || !ctype_digit($row['ft_away'] ?? '')) continue;
    $rawTime = $row['match_time'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$rawTime)) continue;
    $offset = $row['source_utc_offset'] ?? '';
    if (preg_match('/^[+-]\d{2}:\d{2}$/D',$offset)) $rawTime .= $offset;
    try { $time = (new DateTimeImmutable($rawTime,$zone))->setTimezone($zone); } catch (Exception $e) { continue; }
    if ($time->format('Y-m-d') !== $date) continue;
    $home=$clean($row['home_team'] ?? ''); $away=$clean($row['away_team'] ?? '');
    if ($home === '' || $away === '') continue;
    $identity = json_encode([$id,$time->format('c'),strtolower($home),strtolower($away)]);
    if (isset($seen[$identity])) continue;
    $seen[$identity]=true;
    $ht = null;
    if (ctype_digit($row['fh_home'] ?? '') && ctype_digit($row['fh_away'] ?? '') && (int)$row['fh_home'] <= (int)$row['ft_home'] && (int)$row['fh_away'] <= (int)$row['ft_away']) $ht = (int)$row['fh_home'].'–'.(int)$row['fh_away'];
    if (!isset($snapshots[$id])) $snapshots[$id] = ['league'=>$id,'displayName'=>$leagueNames[$id],'matches'=>[]];
    $snapshots[$id]['matches'][] = ['time'=>$time->format('c'),'home'=>$home,'away'=>$away,'status'=>'Selesai','ht'=>$ht,'ft'=>(int)$row['ft_home'].'–'.(int)$row['ft_away']];
}
flock($fh,LOCK_UN); fclose($fh);
asort($leagueNames,SORT_NATURAL | SORT_FLAG_CASE);
$choices=[]; foreach ($leagueNames as $id=>$name) $choices[]=['id'=>$id,'name'=>$name];
echo json_encode(['date'=>$date,'timezone'=>'UTC+8','source'=>'matches.csv','updatedAt'=>is_file($path)?gmdate('c',filemtime($path)):null,'leagues'=>$choices,'snapshots'=>array_values($snapshots)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
