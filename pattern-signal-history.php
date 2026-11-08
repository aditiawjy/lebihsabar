<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_decode(file_get_contents('php://input'), true) : [];
$date = $_GET['date'] ?? ($input['date'] ?? '');
if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
    http_response_code(400); echo json_encode(['error'=>'Tanggal tidak valid.']); exit;
}
$base = getenv('LOCALAPPDATA') ?: ((getenv('USERPROFILE') ?: '').'/AppData/Local');
$folder = $base.'/Sabaraja/signal-history-'.substr(hash('sha256',__DIR__),0,12);
if (!is_dir($folder) && !mkdir($folder,0770,true) && !is_dir($folder)) { http_response_code(503); echo json_encode(['error'=>'Arsip sinyal tidak tersedia.']); exit; }
$path = $folder.'/'.$date.'.json';
$handle = fopen($path,'c+');
if (!$handle || !flock($handle, $_SERVER['REQUEST_METHOD']==='POST' ? LOCK_EX : LOCK_SH)) { if($handle)fclose($handle); http_response_code(503); echo json_encode(['error'=>'Arsip sinyal sedang tidak tersedia.']); exit; }
try {
    $raw = stream_get_contents($handle);
    $records = $raw === '' ? [] : json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($records)) throw new RuntimeException('Arsip sinyal tidak valid.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!is_array($input['records'] ?? null) || count($input['records'])>2000) throw new InvalidArgumentException('Batch sinyal tidak valid.');
        foreach ($input['records'] as $key=>$record) {
            if (!is_string($key) || strlen($key)>1000 || !is_array($record['fixture'] ?? null) || !is_array($record['signals'] ?? null)) continue;
            $fixture=$record['fixture'];
            if (substr($fixture['time'] ?? '',0,10)!==$date) continue;
            $safeFixture=[];
            foreach(['league','time','home','away'] as $field) $safeFixture[$field]=substr((string)($fixture[$field] ?? ''),0,200);
            if (!isset($records[$key])) $records[$key]=['fixture'=>$safeFixture,'signals'=>[]];
            foreach ($record['signals'] as $id=>$signal) {
                if (!is_string($id) || strlen($id)>4000 || !is_array($signal) || isset($records[$key]['signals'][$id])) continue;
                $label=substr((string)($signal['label'] ?? ''),0,4000); if($label==='')continue;
                $safe=['label'=>$label,'firstSeen'=>gmdate('c'),'afterFt'=>!empty($signal['afterFt'])];
                foreach(['title','detail','phase','ht','score'] as $field) $safe[$field]=substr((string)($signal[$field] ?? ''),0,$field==='detail'?24000:6000);
                $rule=$signal['rule'] ?? null;
                if(is_array($rule) && in_array($rule['period'] ?? '',['HT','2H','FT'],true) && in_array($rule['kind'] ?? '',['total','draw','notDraw','exact','notExact','teamGoal'],true)) {
                    $safe['rule']=['period'=>$rule['period'],'kind'=>$rule['kind']];
                    foreach(['side','history'] as $field)if(is_string($rule[$field] ?? null))$safe['rule'][$field]=substr($rule[$field],0,30);
                    foreach(['line','home','away','teamSide'] as $field)if(isset($rule[$field]) && is_numeric($rule[$field]))$safe['rule'][$field]=0+$rule[$field];
                }
                $analysis=$signal['analysis'] ?? null;
                if(is_array($analysis) && isset($analysis['hits'],$analysis['total']) && is_int($analysis['hits']) && is_int($analysis['total']) && $analysis['hits']>=0 && $analysis['total']>=$analysis['hits']) {
                    $safe['analysis']=['hits'=>$analysis['hits'],'total'=>$analysis['total'],'unknown'=>max(0,(int)($analysis['unknown']??0)),'triggerRun'=>max(0,(int)($analysis['triggerRun']??0))];
                    if(($analysis['mode']??'')==='counter-streak')$safe['analysis']['mode']='counter-streak';
                }
                $href=(string)($signal['href'] ?? '');
                $safe['href']=str_starts_with($href,'index.php?') ? substr($href,0,2000) : '';
                $records[$key]['signals'][$id]=$safe;
            }
        }
        $encoded=json_encode($records,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        rewind($handle); if(fwrite($handle,$encoded)!==strlen($encoded))throw new RuntimeException('Gagal menyimpan sinyal.');
        ftruncate($handle,strlen($encoded)); fflush($handle);
    }
    echo json_encode(['date'=>$date,'records'=>(object)$records],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) { http_response_code(503); echo json_encode(['error'=>'Arsip sinyal gagal dibaca atau disimpan.']); }
finally { flock($handle,LOCK_UN); fclose($handle); }
