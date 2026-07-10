<?php
$fh = fopen(__DIR__.'/matches.csv','r');
$hdr = fgetcsv($fh);
// normalisasi nama kolom id (ada BOM/quote aneh)
$idx = array_flip($hdr);
$col = function($row,$name) use($idx){ return $row[$idx[$name]] ?? ''; };
$rows = [];
while(($r=fgetcsv($fh))!==false){
  if(count($r)!==count($hdr)) continue;
  $home=trim($col($r,'home_team')); $away=trim($col($r,'away_team'));
  if($home!=='Real Sociedad (V)' && $away!=='Real Sociedad (V)') continue;
  $ftH=$col($r,'ft_home'); $ftA=$col($r,'ft_away');
  if($ftH===''||$ftA==='') continue;
  $rows[] = [
    'id'=>$r[0],
    'mt'=>$col($r,'match_time'),
    'league'=>trim($col($r,'league')),
    'home'=>$home,'away'=>$away,
    'fh'=>$col($r,'fh_home').'-'.$col($r,'fh_away'),
    'ft'=>(int)$ftH.'-'.(int)$ftA,
    'tot'=>(int)$ftH+(int)$ftA,
  ];
}
fclose($fh);
usort($rows, fn($a,$b)=>strcmp($b['mt'],$a['mt'])); // terbaru dulu
echo "Total match Real Sociedad (V) ber-skor: ".count($rows)."\n\n";
echo "10 terbaru (terbaru di atas):\n";
foreach(array_slice($rows,0,10) as $i=>$x){
  $u = $x['tot']<2 ? 'U1.5 ✓' : 'OVER ✗';
  printf("  %2d) id=%-8s %s | %s | %s vs %s | FT %s (HT %s) tot=%d -> %s\n",
    $i+1,$x['id'],$x['mt'],$x['league'],$x['home'],$x['away'],$x['ft'],$x['fh'],$x['tot'],$u);
}
// hitung current streak per league key
echo "\nCurrent streak per league:\n";
$byLg=[];
foreach($rows as $x){ $byLg[$x['league']][]=$x; }
foreach($byLg as $lg=>$arr){
  $s=0; foreach($arr as $x){ if($x['tot']<2)$s++; else break; }
  echo "  [$lg] streak U1.5 berjalan = $s\n";
}
