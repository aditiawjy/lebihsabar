<?php
require_once __DIR__.'/../pattern-h2h.php';
require_once __DIR__.'/../pattern-signal-analysis.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function meeting($day,$hh,$ha,$fh,$fa,$home='A',$away='B'){
 return ['time'=>'2026-01-'.str_pad((string)$day,2,'0',STR_PAD_LEFT).'T12:00:00+08:00','home'=>$home,'away'=>$away,'htHome'=>$hh,'htAway'=>$ha,'ft'=>$fh.'–'.$fa,'totalGoals'=>$fh+$fa];
}
$pair=[meeting(1,0,0,0,0),meeting(2,0,0,0,0),meeting(3,1,0,2,0),meeting(4,0,0,0,0),meeting(5,0,0,0,0)];
$index=patternAnalysisTeamIndex(['L|A|B'=>['meetings'=>$pair]],'L');
$a=patternAdvancedAnalysis(array_reverse($pair),$index,'L','A','B','2026-01-06T12:00:00+08:00');
check($a['htExact']['previous']===2,'Exact HT leading run');
check($a['htExact']['record']===2,'Exact HT record');
check($a['triggerHistory']['htZero']['total']===1 && $a['triggerHistory']['htZero']['hits']===0,'Outcome follows previous run, excludes current fixture');
check($a['triggerHistory']['over05']['hits']===1,'O0.5 after two zero FT meetings');
check($a['teamNoGoal'][0]['period']==='HT' && $a['teamNoGoal'][0]['current']===2,'Own HT goals count');
$withFuture=array_merge($pair,[meeting(7,0,0,0,0),meeting(8,0,0,0,0)]);
$futureIndex=patternAnalysisTeamIndex(['L|A|B'=>['meetings'=>$withFuture]],'L');
$b=patternAdvancedAnalysis(array_reverse($withFuture),$futureIndex,'L','A','B','2026-01-06T12:00:00+08:00');
check($a===$b,'Future matches must not change any analysis');
$reverse=meeting(6,0,0,0,0,'B','A');
$c=patternAdvancedAnalysis(array_reverse(array_merge($pair,[$reverse])),$index,'L','A','B','2026-01-07T12:00:00+08:00');
check($c['htExact']['previous']===2,'Reversed fixture excluded from H2H');
$missing=meeting(4,null,null,0,0);$unknown=[$pair[0],$pair[1],$pair[2],$missing,$pair[4]];
$d=patternAdvancedAnalysis(array_reverse($unknown),$index,'L','A','B','2026-01-06T12:00:00+08:00');
check($d['htExact']['previous']===1,'Unknown HT breaks consecutive run');
$allOpponents=patternAnalysisTeamIndex(['L|A|B'=>['meetings'=>[$pair[4]]],'L|A|C'=>['meetings'=>[meeting(4,0,0,0,0,'A','C')]]],'L');
$e=patternAdvancedAnalysis([$pair[4]],$allOpponents,'L','A','B','2026-01-06T12:00:00+08:00');
check($e['teamNoGoal'][0]['current']===2,'Team run includes different opponents');
echo "PASS: prior-only trigger/outcome matching, exact HT, home-away scope, missing HT, all-opponent team streaks\n";
