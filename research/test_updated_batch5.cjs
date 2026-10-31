const fs=require('fs'),path=require('path'),vm=require('vm');
const snapshot=JSON.parse(fs.readFileSync(path.join(__dirname,'fail_reduction_batch5_source.json'),'utf8'));
const report=JSON.parse(fs.readFileSync(path.join(__dirname,'reduce_fail_batch5.json'),'utf8'));
let source=fs.readFileSync(path.join(__dirname,'../patternsaba.js'),'utf8');
source=source.slice(0,source.indexOf('  // Konfigurasi Kotak Utama UI'))+'globalThis.evaluate=rows=>l16BuildEvents(rows);})();';
const ctx={document:{createElement:()=>({}),head:{append(){}}}};
vm.createContext(ctx);vm.runInContext(source,ctx);
const events=ctx.evaluate(snapshot.rows).events;
const day=r=>new Date(r.t-4*3600000).toISOString().slice(0,10);
for(const code of ['L16-06','L16-16','L16-38','L16-44','L16-57']){
 const baseline=report.reports.find(r=>r.code===code);
 const updated=code==='L16-38'||code==='L16-57';
 const expectedStats=updated?baseline.candidates[code==='L16-57'?1:0].overall:baseline.base;
 const selected=events.filter(e=>e.rule.id===code&&day(e.r)>=report.start&&day(e.r)<=report.end);
 const win=selected.filter(e=>e.check.result==='BERHASIL').length,fail=selected.filter(e=>e.check.result==='TIDAK TERPENUHI').length;
 if(win!==expectedStats.win||fail!==expectedStats.fail)throw Error(code+' counts mismatch');
 if(updated){
  const ids=new Set(baseline.candidates[code==='L16-57'?1:0].ids);
  if(selected.length!==ids.size||selected.some(e=>!ids.has(e.r.id)))throw Error(code+' fixture mismatch');
 }
 console.log(JSON.stringify({code,updated,win,fail,accuracy:expectedStats.accuracy}));
}
