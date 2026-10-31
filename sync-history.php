<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/sync-history-lib.php';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $data=json_decode(file_get_contents('php://input'),true);
    $source=$data['source'] ?? ''; $date=$data['date'] ?? '';
    if (!in_array($source,['MSports','V-Soccer','CSV'],true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) { http_response_code(400); echo json_encode(['error'=>'Data riwayat tidak valid.']); exit; }
    $stage=in_array($data['stage'] ?? '',['fetch','save'],true) ? $data['stage'] : 'fetch';
    $reason=in_array($data['reason'] ?? '',['session','network','invalid','unknown'],true) ? $data['reason'] : 'unknown';
    if (!syncHistoryAppend(['status'=>'failed','source'=>$source,'stage'=>$stage,'reason'=>$reason,'requestedDates'=>[$date]])) { http_response_code(503); echo json_encode(['error'=>'Riwayat tidak dapat disimpan.']); exit; }
    echo json_encode(['success'=>true]); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); echo json_encode(['error'=>'Metode tidak didukung.']); exit; }
$path=syncHistoryPath(); $entries=[];
if (is_file($path)) {
    $fh=@fopen($path,'r');
    if (!$fh || !flock($fh,LOCK_SH)) { if($fh) fclose($fh); http_response_code(503); echo json_encode(['error'=>'Riwayat tidak dapat dibaca.']); exit; }
    while (($line=fgets($fh)) !== false) { $entry=json_decode($line,true); if (is_array($entry)) { $entries[]=$entry; if (count($entries)>100) array_shift($entries); } }
    flock($fh,LOCK_UN); fclose($fh);
}
echo json_encode(['entries'=>array_reverse($entries)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
