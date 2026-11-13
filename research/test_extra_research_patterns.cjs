const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
let source=fs.readFileSync(path.join(__dirname,'../patternsaba.js'),'utf8');
source=source.slice(0,source.indexOf('  function renderPatternTable(lines)'))+'globalThis.extraTest={rules:EXTRA_RESEARCH_RULES,evaluate:extraResearchEvaluate};})();';
const ctx={document:{createElement:()=>({}),getElementById:()=>null,head:{append(){}},body:{append(){}}},setTimeout:()=>0,clearTimeout(){}};
vm.createContext(ctx);vm.runInContext(source,ctx);
(async()=>{
 const data=await(await fetch('http://localhost/lebihsabar/pattern-data.php')).json();
 const pair=(a,b)=>/^\d+$/.test(a)&&/^\d+$/.test(b)?[+a,+b]:null;
 const rows=data.rows.map((r,i)=>({id:String(i),league:r.league,t:Date.parse(r.match_time.replace(' ','T')+'+08:00'),teams:[r.home_team,r.away_team],fh:pair(r.fh_home,r.fh_away),ft:pair(r.ft_home,r.ft_away),complete:!!pair(r.ft_home,r.ft_away)}));
 const expected={'L15-UJI-MU-FT':[156,15],'L15-UJI-PSG-2H':[204,29],'L15-UJI-CHE-2H':[294,43],'L15-UJI-RMA-2H':[232,42],'L20-UJI-QAT-2H':[114,13]};
 for(const rule of ctx.extraTest.rules){
  const records=rows.filter(r=>r.league===(rule.league==='15'?'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play':'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play'));
  let win=0,fail=0;
  for(const r of records){const c=ctx.extraTest.evaluate(records,r,rule);if(c.result==='BERHASIL')win++;if(c.result==='TIDAK TERPENUHI')fail++;}
  assert.deepEqual([win,fail],expected[rule.id],rule.id+' must reproduce research');
  const t=records.find(r=>ctx.extraTest.evaluate(records,r,rule).active);
  assert(t,rule.id+' fixture');
  if(rule.conditionType!=='mu')assert(!ctx.extraTest.evaluate(records,{...t,fh:null},rule).active,'Missing HT cannot trigger');
  else assert(ctx.extraTest.evaluate(records,{...t,fh:null,ft:null,complete:false},rule).active,'MU pre-match trigger must not require target HT');
  assert(!ctx.extraTest.evaluate(records,{...t,league:'other'},rule).active,'No cross-league trigger');
  console.log(rule.id,win,'WIN',fail,'FAIL');
 }
})().catch(e=>{console.error(e);process.exit(1)});
