const fs=require('fs'),vm=require('vm'),path=require('path');
const root=path.resolve(__dirname,'..');
const stats=es=>{const win=es.filter(e=>e.check.result==='BERHASIL').length;return {win,fail:es.length-win,total:es.length,pct:es.length?+(100*win/es.length).toFixed(2):null}};
const day=t=>new Date(t-4*3600000).toISOString().slice(0,10);
(async()=>{
 const data=await (await fetch('http://localhost/lebihsabar/pattern-data.php')).json();
 const pair=(a,b)=>/^\d+$/.test(a)&&/^\d+$/.test(b)?[+a,+b]:null;
 const seen=new Set();
 const rows=data.rows.filter(r=>r.league==='SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play').filter(r=>{const k=[r.match_time,r.home_team,r.away_team].join('|');if(seen.has(k))return false;seen.add(k);return true}).map((r,i)=>({id:String(i),league:r.league,t:Date.parse(r.match_time.replace(' ','T')+'+08:00'),teams:[r.home_team,r.away_team],fh:pair(r.fh_home,r.fh_away),ft:pair(r.ft_home,r.ft_away),complete:!!pair(r.ft_home,r.ft_away)}));
 const source=fs.readFileSync(path.join(root,'patternsaba.js'),'utf8');const ctx={document:{createElement:()=>({}),head:{append(){}}}};vm.createContext(ctx);
 vm.runInContext(source.slice(0,source.indexOf('  // Konfigurasi Kotak Utama UI'))+'globalThis.run=l16BuildEvents;})();',ctx);
 const report=ctx.run(rows);const end=day(Math.max(...rows.map(r=>r.t)));const split=new Date(Date.parse(end+'T00:00:00Z')-6*86400000).toISOString().slice(0,10);
 const key=s=>s.toLowerCase().replace(/\s*\(v\)/g,'').trim();const prior=new Map();const features=new Map();
 for(const r of [...rows].sort((a,b)=>a.t-b.t)){
  const f={};for(let side=0;side<2;side++){
   const p=prior.get(key(r.teams[side]));
   if(p&&r.t-p.t>=30*60000&&r.t-p.t<=3*3600000&&p.fh&&p.ft){for(const [metric,val] of [['HT',p.fh[0]+p.fh[1]],['FT',p.ft[0]+p.ft[1]],['2H',p.ft[0]+p.ft[1]-p.fh[0]-p.fh[1]]])for(const limit of [1,2,3,4]){f[`${side?'Away':'Home'} previous ${metric} ≤${limit}`]=val<=limit;f[`${side?'Away':'Home'} previous ${metric} ≥${limit}`]=val>=limit;}}
  }
  features.set(r.id,f);if(r.complete&&r.ft&&r.fh)for(const t of r.teams)prior.set(key(t),r);
 }
 const out=[];
 for(const rule of report.rules){
  const es=report.events.filter(e=>e.rule.id===rule.id&&['BERHASIL','TIDAK TERPENUHI'].includes(e.check.result));if(stats(es).fail<8)continue;
  const train=es.filter(e=>day(e.r.t)<split),test=es.filter(e=>day(e.r.t)>=split);
  const candidates=new Map();for(const e of train){const f={...features.get(e.r.id)};if(rule.market.startsWith('2H')&&e.r.fh){const [a,b]=e.r.fh;Object.assign(f,{'HT imbang':a===b,'HT tidak imbang':a!==b,'Total HT ≤2':a+b<=2,'Total HT ≥2':a+b>=2,'Total HT ≤3':a+b<=3,'Home unggul HT':a>b,'Away unggul HT':b>a});}for(const name of Object.keys(f))candidates.set(name,true);}
  const passes=(e,name)=>{if(name.startsWith('HT ')||name.startsWith('Total HT')||name.endsWith('unggul HT')){const [a,b]=e.r.fh||[];return {'HT imbang':a===b,'HT tidak imbang':a!==b,'Total HT ≤2':a+b<=2,'Total HT ≥2':a+b>=2,'Total HT ≤3':a+b<=3,'Home unggul HT':a>b,'Away unggul HT':b>a}[name]===true;}return features.get(e.r.id)?.[name]===true;};
  const ranked=[...candidates.keys()].map(name=>({name,train:stats(train.filter(e=>passes(e,name)))})).filter(c=>c.train.total>=20&&c.train.total>=train.length*.5&&c.train.pct>stats(train).pct).sort((a,b)=>b.train.pct-a.train.pct||b.train.total-a.train.total);
  const best=ranked[0];out.push({code:rule.id,baseline:stats(es),train:stats(train),test:stats(test),candidate:best?{...best,test:stats(test.filter(e=>passes(e,best.name))),overall:stats(es.filter(e=>passes(e,best.name)))}:null});
 }
 const result={end,split,rows:rows.length,note:'Single-filter candidates selected on older data only. Recent data has been used in earlier research; not untouched prospective validation. Prior fixtures require 30-minute gap. No production rules changed.',results:out};
 fs.writeFileSync(path.join(__dirname,'refine_l16_current.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
})().catch(e=>{console.error(e);process.exit(1)});
