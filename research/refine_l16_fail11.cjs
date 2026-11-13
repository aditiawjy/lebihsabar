// Reuse the verified CSV loader, event engine and chronological features.
const fs=require('fs'),vm=require('vm'),path=require('path');
let source=fs.readFileSync(path.join(__dirname,'refine_l16_current.cjs'),'utf8');
source=source.replace('if(stats(es).fail<8)continue;',"if(!['L16-08','L16-16','L16-27','L16-32','L16-34','L16-39','L16-57'].includes(rule.id))continue;");
const start=source.indexOf('  const ranked=');
const end=source.indexOf('\n }\n const result=',start);
if(start<0||end<0)throw Error('Research harness changed');
source=source.slice(0,start)+`
  const names=[...candidates.keys()];const combinations=names.map(n=>[n]);
  for(let a=0;a<names.length;a++)for(let b=a+1;b<names.length;b++)combinations.push([names[a],names[b]]);
  let trials=0;const unique=new Set();
  const ranked=[];
  for(const conditions of combinations){
    trials++;const selected=train.filter(e=>conditions.every(n=>passes(e,n)));
    const signature=selected.map(e=>e.r.id).sort().join('|');if(unique.has(signature))continue;unique.add(signature);
    const summary=stats(selected);
    if(summary.total<20 || summary.total<train.length*.4 || summary.pct<=stats(train).pct)continue;
    ranked.push({conditions,train:summary});
  }
  ranked.sort((a,b)=>b.train.pct-a.train.pct||b.train.total-a.train.total||a.conditions.length-b.conditions.length);
  const finalists=ranked.slice(0,5).map(c=>({...c,test:stats(test.filter(e=>c.conditions.every(n=>passes(e,n)))),overall:stats(es.filter(e=>c.conditions.every(n=>passes(e,n))))}));
  out.push({code:rule.id,baseline:stats(es),train:stats(train),test:stats(test),trials,candidate:finalists[0]||null,finalists});
`+source.slice(end);
source=source.replace('Single-filter candidates','One/two-filter candidates (top five by training only; test not used in ranking)').replaceAll('refine_l16_current.json','refine_l16_fail11.json');
vm.runInNewContext(source,{require,__dirname,console,fetch});
