<?php
function patternAnalysisTeamKey(string $name): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*(?:\(V\)|\[V\])/i', '', $name))));
}
function patternAnalysisTeamIndex(array $history, string $leagueFilter): array {
    $index=[];
    foreach ($history as $key=>$stats) {
        $separator=strpos($key,'|'); if($separator===false)continue;
        $league=substr($key,0,$separator); if($league!==$leagueFilter)continue;
        foreach($stats['meetings'] ?? [] as $m) foreach(['home','away'] as $side) {
            $team=patternAnalysisTeamKey($m[$side]);
            $id=$m['time'].'|'.patternAnalysisTeamKey($m['home']).'|'.patternAnalysisTeamKey($m['away']);
            $index[$league][$team][$id]=$m;
        }
    }
    foreach($index as &$teams)foreach($teams as &$meetings) {
        $meetings=array_values($meetings);
        usort($meetings,static fn($a,$b)=>strcmp($a['time'],$b['time']));
    }
    unset($teams,$meetings);
    return $index;
}
function patternAnalysisScores(array $m, string $period, ?string $team=null): ?array {
    if(($m['totalGoals'] ?? null)===null || !preg_match('/^(\d+)–(\d+)$/uD',$m['ft'] ?? '',$ft))return null;
    $scores=[(int)$ft[1],(int)$ft[2]];
    if($period!=='FT') {
        if(!isset($m['htHome'],$m['htAway']))return null;
        $ht=[$m['htHome'],$m['htAway']];
        if($ht[0]>$scores[0] || $ht[1]>$scores[1])return null;
        $scores=$period==='HT' ? $ht : [$scores[0]-$ht[0],$scores[1]-$ht[1]];
    }
    if($team!==null) {
        $side=patternAnalysisTeamKey($m['home'])===$team?0:1;
        return [$scores[$side],$scores[1-$side]];
    }
    return $scores;
}
// Every historical outcome is paired with its preceding run, never its own FT as trigger.
function patternAnalysisAfterRun(array $chronological, int $length, callable $qualifies, callable $outcome): array {
    $run=0;$total=0;$hits=0;$unknown=0;
    foreach($chronological as $m) {
        if($length>0 && $run===$length) {
            $result=$outcome($m);
            if($result===null)$unknown++; else { $total++; if($result)$hits++; }
        }
        $run=$qualifies($m)===true?$run+1:0;
    }
    return ['hits'=>$hits,'total'=>$total,'unknown'=>$unknown,'triggerRun'=>$length];
}
// Advance each team's chronological state once per response, rather than rescanning for every card row.
function patternAnalysisTeamSummary(array &$index, string $league, string $key, string $before): array {
    $state =& $index['__states'][$league][$key];
    if (!is_array($state) || ($state['before'] ?? '')>$before) $state=['cursor'=>0,'before'=>$before,'periods'=>[]];
    $history=$index[$league][$key] ?? [];
    while(isset($history[$state['cursor']]) && $history[$state['cursor']]['time']<$before) {
        $m=$history[$state['cursor']++];
        foreach(['HT','2H','FT'] as $period) {
            $e =& $state['periods'][$period];
            if(!is_array($e))$e=['current'=>0,'record'=>0,'after'=>[]];
            $scores=patternAnalysisScores($m,$period,$key);
            $length=$e['current'];
            if($length>0) {
                $entry =& $e['after'][$length];
                if(!is_array($entry))$entry=['hits'=>0,'total'=>0,'unknown'=>0,'triggerRun'=>$length];
                if($scores===null)$entry['unknown']++;else{$entry['total']++;if($scores[0]>0)$entry['hits']++;}
                unset($entry);
            }
            $e['current']=$scores!==null && $scores[0]===0?$e['current']+1:0;
            $e['record']=max($e['record'],$e['current']);unset($e);
        }
    }
    $state['before']=$before;$out=[];
    foreach(['HT','2H','FT'] as $period){$e=$state['periods'][$period] ?? ['current'=>0,'record'=>0,'after'=>[]];$out[$period]=['current'=>$e['current'],'record'=>$e['record'],'after'=>$e['after'][$e['current']] ?? ['hits'=>0,'total'=>0,'unknown'=>0,'triggerRun'=>$e['current']]];}
    return $out;
}
function patternAdvancedAnalysis(array $previous, array &$teamIndex, string $league, string $home, string $away, string $before): array {
    $pair=array_values(array_filter($previous,static fn($m)=>$m['time']<$before && patternSameVenue($m,$home,$away)));
    usort($pair,static fn($a,$b)=>strcmp($a['time'],$b['time']));
    $latest=array_reverse($pair);$htRun=patternH2hHtRun($latest,$home,$away);
    $targets=[
        'htZero'=>['HT',static fn($s)=>array_sum($s)===0,static fn($s)=>array_sum($s)===0],
        'secondZero'=>['2H',static fn($s)=>array_sum($s)===0,static fn($s)=>array_sum($s)===0],
        'under25'=>['FT',static fn($s)=>array_sum($s)<3,static fn($s)=>array_sum($s)<3],
        'draw'=>['FT',static fn($s)=>$s[0]===$s[1],static fn($s)=>$s[0]===$s[1]],
        'over05'=>['FT',static fn($s)=>array_sum($s)===0,static fn($s)=>array_sum($s)>0],
        'over15'=>['FT',static fn($s)=>array_sum($s)<2,static fn($s)=>array_sum($s)>1]
    ];
    $triggerHistory=[];
    foreach($targets as $name=>[$period,$qualify,$outcome]) {
        $count=0;
        foreach($latest as $m){$s=patternAnalysisScores($m,$period);if($s===null || !$qualify($s))break;$count++;}
        $triggerHistory[$name]=patternAnalysisAfterRun($pair,$count,
            static function($m)use($period,$qualify){$s=patternAnalysisScores($m,$period);return $s===null?null:$qualify($s);},
            static function($m)use($period,$outcome){$s=patternAnalysisScores($m,$period);return $s===null?null:$outcome($s);});
    }
    $exact=$htRun['score'];
    $exactHistory=$exact===null?['hits'=>0,'total'=>0,'unknown'=>0]:patternAnalysisAfterRun($pair,$htRun['previous'],
        static function($m)use($exact){$s=patternAnalysisScores($m,'HT');return $s===null?null:implode(':',$s)===$exact;},
        static function($m)use($exact){$s=patternAnalysisScores($m,'HT');return $s===null?null:implode(':',$s)===$exact;});
    $teamRuns=[];
    foreach([$home,$away] as $name) {
        $key=patternAnalysisTeamKey($name);
        $summary=patternAnalysisTeamSummary($teamIndex,$league,$key,$before);
        foreach($summary as $period=>$entry)$teamRuns[]=['team'=>$name,'period'=>$period]+$entry;
    }
    return ['scope'=>'same-home-away-before-kickoff','htExact'=>['score'=>$exact,'previous'=>$htRun['previous'],'record'=>$exact===null?0:($htRun['records'][$exact]??0),'after'=>$exactHistory],'teamNoGoal'=>$teamRuns,'triggerHistory'=>$triggerHistory];
}
