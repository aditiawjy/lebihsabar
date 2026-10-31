const fs=require('fs'),path=require('path'),vm=require('vm');
const root=path.resolve(__dirname,'..');
const snapshot=JSON.parse(fs.readFileSync(path.join(__dirname,'fail_reduction_37_60_source.json'),'utf8'));
const report=JSON.parse(fs.readFileSync(path.join(__dirname,'reduce_fail_37_60.json'),'utf8'));
let source=fs.readFileSync(path.join(root,'patternsaba.js'),'utf8');
source=source.slice(0,source.indexOf('  // Konfigurasi Kotak Utama UI'))+'globalThis.evaluate=rows=>l16BuildEvents(rows);})();';
const ctx={document:{createElement:()=>({}),head:{append(){}}}};
vm.createContext(ctx);vm.runInContext(source,ctx);
const events=ctx.evaluate(snapshot.rows).events;
for(const code of ['L16-37','L16-60']){
 const candidate=report.reports.find(r=>r.code===code).candidates[0];
 const selected=events.filter(e=>e.rule.id===code&&(()=>{
  const day=new Date(e.r.t-4*3600000).toISOString().slice(0,10);
  return day>=report.start&&day<=report.end;
 })());
 const actual=new Set(selected.map(e=>e.r.id)),expected=new Set(candidate.ids);
 if(actual.size!==expected.size||[...actual].some(id=>!expected.has(id)))throw Error(code+' triggers differ from backtest');
 const win=selected.filter(e=>e.check.result==='BERHASIL').length,fail=selected.filter(e=>e.check.result==='TIDAK TERPENUHI').length;
 if(win!==candidate.overall.win||fail!==candidate.overall.fail)throw Error(code+' results differ');
 console.log(JSON.stringify({code,win,fail,total:win+fail,accuracy:candidate.overall.accuracy}));
}
