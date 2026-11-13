<?php
function syncHistoryPath(): string {
    $root = getenv('LOCALAPPDATA') ?: ((getenv('USERPROFILE') ?: '') . '/AppData/Local');
    return rtrim($root, '\\/').'/Sabaraja/sync-history.jsonl';
}
function syncHistoryAppend(array $entry): bool {
    $path=syncHistoryPath(); $dir=dirname($path);
    if (!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) return false;
    $entry['time']=gmdate('c');
    $line=json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return $line !== false && @file_put_contents($path,$line."\n",FILE_APPEND|LOCK_EX) !== false;
}
function syncHistoryScope(array $matches): array {
    $dates=[]; $leagues=[];
    foreach ($matches as $row) {
        if (!is_array($row)) continue;
        $date=substr((string)($row['match_time'] ?? ''),0,10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) $dates[$date]=true;
        $league=trim((string)($row['league'] ?? ''));
        if ($league !== '') $leagues[substr($league,0,160)]=true;
    }
    $dates=array_keys($dates); sort($dates); $leagues=array_keys($leagues); sort($leagues);
    return ['dates'=>$dates,'leagues'=>$leagues,'received'=>count($matches)];
}
