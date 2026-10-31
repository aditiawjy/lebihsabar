<?php
// Read-only candidate mining; never writes rules or matches.csv.
ini_set('display_errors', '0');
set_time_limit(90);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$source = getenv('MATCHES_CSV_FILE') ?: __DIR__.'/matches.csv';
$now = time();
$start = $now - 30*86400;
$split = $now - 7*86400;
$cacheDir = __DIR__.'/cache';
$cacheFile = $cacheDir.'/pattern-miner-l16.json';
$version = (is_file($source) ? filemtime($source).'-'.filesize($source) : 'missing').'-'.filemtime(__FILE__).'-'.gmdate('Y-m-d');
if (is_file($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (($cached['version'] ?? '') === $version) { echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
}
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ready'=>false,'message'=>'Jalankan pencarian dengan tombol Cari kandidat formula.']); exit;
}
function miner_score($a,$b) { return ctype_digit((string)$a) && ctype_digit((string)$b) ? [(int)$a,(int)$b] : null; }
function miner_stats() { return ['total'=>0,'win'=>0,'fail'=>0,'maxLoseStreak'=>0,'streak'=>0]; }
function miner_add(&$stats,$win) {
    $stats['total']++;
    if ($win) { $stats['win']++; $stats['streak']=0; }
    else { $stats['fail']++; $stats['streak']++; $stats['maxLoseStreak']=max($stats['maxLoseStreak'],$stats['streak']); }
}
function miner_finish($stats) {
    unset($stats['streak']);
    $stats['accuracy']=$stats['total'] ? round(100*$stats['win']/$stats['total'],2) : null;
    return $stats;
}
function miner_slot($slots,$t,$decision) {
    $slot=$slots[$t] ?? [];
    if (count($slot)!==2 || $t+16*60>$decision) return null;
    $ht=0;$ft=0;$zeros=0;
    foreach($slot as $r){$ht+=array_sum($r['ht']);$ft+=array_sum($r['ft']);if(array_sum($r['ft'])===0)$zeros++;}
    return ['HT'=>$ht,'FT'=>$ft,'2H'=>$ft-$ht,'zeros'=>$zeros];
}
try {
    if (!is_dir($cacheDir) && !mkdir($cacheDir,0775,true)) throw new RuntimeException('Folder cache tidak tersedia.');
    $lock=fopen($cacheDir.'/pattern-miner.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { http_response_code(409); throw new RuntimeException('Pencarian lain masih berjalan.'); }
    $fh=@fopen($source,'r');
    if (!$fh || !flock($fh,LOCK_SH)) throw new RuntimeException('matches.csv tidak dapat dibaca.');
    $header=fgetcsv($fh);$unique=[];$tz=new DateTimeZone('Asia/Singapore');
    while(($values=fgetcsv($fh))!==false) {
        if(count($values)!==count($header))continue;
        $r=array_combine($header,$values);
        if(($r['league']??'')!=='SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play')continue;
        $ht=miner_score($r['fh_home'],$r['fh_away']);$ft=miner_score($r['ft_home'],$r['ft_away']);
        if(!$ht||!$ft||$ft[0]<$ht[0]||$ft[1]<$ht[1])continue;
        $t=(new DateTimeImmutable($r['match_time'],$tz))->getTimestamp();
        if($t<$start-3*3600 || $t+16*60>$now)continue;
        $home=strtolower(trim(preg_replace('/\s*\(v\)/i','',$r['home_team'])));
        $away=strtolower(trim(preg_replace('/\s*\(v\)/i','',$r['away_team'])));
        $unique[$t.'|'.$home.'|'.$away]=['t'=>$t,'home'=>$home,'away'=>$away,'ht'=>$ht,'ft'=>$ft];
    }
    fclose($fh);
    $records=array_values($unique);
    usort($records,static function($a,$b){return $a['t']<=>$b['t'] ?: strcmp($a['home'].'|'.$a['away'],$b['home'].'|'.$b['away']);});
    $slots=[];foreach($records as $r)$slots[$r['t']][]=$r;
    $aNames=['Slot -15m HT ≥2','Slot -15m HT ≥3','Slot -15m HT ≥4','Slot -15m 2H ≥3','Slot -15m 2H ≥4','Slot -30m FT ≥5','Slot -30m FT ≥8','Slot -15m jumlah FT 0–0 ≥1','Slot -15m jumlah FT 0–0 =2'];
    $bNames=['Home prev 2H ≤1','Home prev 2H ≥2','Away prev HT ≤1','Away prev HT ≥2','Home same venue prev FT ≤3','Home same venue prev FT ≥4','Away same venue prev FT ≤3','Away same venue prev FT ≥4'];
    $cNames=['HT 0–0','HT 0–1','HT 1–0','HT 1–1','Total HT ganjil','Total HT genap','Total HT ≤1','Tanpa filter HT'];
    $stats=[];
    for($a=0;$a<count($aNames);$a++)for($b=0;$b<count($bNames);$b++)for($c=0;$c<count($cNames);$c++)$stats["$a:$b:$c"]=['train'=>miner_stats(),'validation'=>miner_stats()];
    $previous=[];$venues=[];$observations=0;
    foreach($records as $r) {
        $t=$r['t'];$decision=$t+8*60;
        $prev=function($pool)use($t,$decision){
            for($i=count($pool)-1;$i>=0;$i--){$p=$pool[$i];if($t-$p['t']>3*3600)break;if($p['t']<$t&&$p['t']+16*60<=$decision)return $p;}return null;
        };
        $homePrev=$prev($previous[$r['home']]??[]);$awayPrev=$prev($previous[$r['away']]??[]);
        $homeVenue=$prev($venues['home|'.$r['home']]??[]);$awayVenue=$prev($venues['away|'.$r['away']]??[]);
        $s15=miner_slot($slots,$t-15*60,$decision);$s30=miner_slot($slots,$t-30*60,$decision);
        if($t>=$start){
            $A=[$s15&&$s15['HT']>=2,$s15&&$s15['HT']>=3,$s15&&$s15['HT']>=4,$s15&&$s15['2H']>=3,$s15&&$s15['2H']>=4,$s30&&$s30['FT']>=5,$s30&&$s30['FT']>=8,$s15&&$s15['zeros']>=1,$s15&&$s15['zeros']===2];
            $home2H=$homePrev?array_sum($homePrev['ft'])-array_sum($homePrev['ht']):null;
            $awayHT=$awayPrev?array_sum($awayPrev['ht']):null;
            $B=[$home2H!==null&&$home2H<=1,$home2H!==null&&$home2H>=2,$awayHT!==null&&$awayHT<=1,$awayHT!==null&&$awayHT>=2,$homeVenue&&array_sum($homeVenue['ft'])<=3,$homeVenue&&array_sum($homeVenue['ft'])>=4,$awayVenue&&array_sum($awayVenue['ft'])<=3,$awayVenue&&array_sum($awayVenue['ft'])>=4];
            $htTotal=array_sum($r['ht']);
            $C=[$r['ht']===[0,0],$r['ht']===[0,1],$r['ht']===[1,0],$r['ht']===[1,1],$htTotal%2===1,$htTotal%2===0,$htTotal<=1,true];
            $period=$t<$split?'train':'validation';$win=array_sum($r['ft'])>$htTotal;
            foreach($A as $a=>$yesA)if($yesA)foreach($B as $b=>$yesB)if($yesB)foreach($C as $c=>$yesC)if($yesC)miner_add($stats["$a:$b:$c"][$period],$win);
            $observations++;
        }
        $previous[$r['home']][]=$r;$previous[$r['away']][]=$r;
        $venues['home|'.$r['home']][]=$r;$venues['away|'.$r['away']][]=$r;
    }
    $candidates=[];
    foreach($stats as $key=>$s){
        $train=$s['train'];
        if($train['total']<35||$train['win']/$train['total']<.93||$train['maxLoseStreak']>1)continue;
        [$a,$b,$c]=array_map('intval',explode(':',$key));
        $candidates[]=['formula'=>$aNames[$a].' + '.$bNames[$b].' + '.$cNames[$c], 'market'=>'2H Over 0.5',
            'train'=>miner_finish($train),'validation'=>miner_finish($s['validation'])];
    }
    usort($candidates,static function($a,$b){return $b['train']['accuracy']<=>$a['train']['accuracy'] ?: $b['train']['total']<=>$a['train']['total'];});
    $report=['version'=>$version,'createdAt'=>gmdate('c',$now),'league'=>'16','market'=>'2H Over 0.5','combinations'=>count($stats),'observations'=>$observations,
        'searchFrom'=>gmdate('c',$start),'validationFrom'=>gmdate('c',$split),'until'=>gmdate('c',$now),
        'warning'=>'Waktu keputusan HT memakai menit nominal +8 dan FT +16. CSV tidak mencatat waktu publikasi skor; validasi real-time masih diperlukan.',
        'candidates'=>$candidates];
    $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    file_put_contents($cacheFile.'.tmp',$json);rename($cacheFile.'.tmp',$cacheFile);
    echo $json;
} catch(Throwable $e) {
    if(http_response_code()<400)http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
