<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Hanya permintaan lokal.']); exit;
}
$origin = parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST);
if ($origin && !in_array($origin,['localhost','127.0.0.1','::1','[::1]'],true)) {
    http_response_code(403); echo json_encode(['success'=>false]); exit;
}
$python = getenv('SABARAJA_PYTHON') ?: 'C:/Users/adit/AppData/Local/Programs/Python/Python310/python.exe';
$input = json_decode(file_get_contents('php://input'),true) ?: [];
$health = ($input['type'] ?? '') === 'health';
$process = proc_open([$python,__DIR__.'/msports-parser/telegram_notify.py','--mode',$health?'health':'notify'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);
if (!is_resource($process)) {http_response_code(503);echo json_encode(['success'=>false]);exit;}
if ($health) fwrite($pipes[0],json_encode(['source'=>$input['source'] ?? '', 'status'=>$input['status'] ?? '']));
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
$code=proc_close($process);$result=json_decode($out,true);
if ($code!==0 || !is_array($result)) {http_response_code(502);echo json_encode(['success'=>false,'error'=>'Notifikasi Telegram gagal.']);exit;}
echo json_encode($result);
