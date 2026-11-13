const fs=require('fs'),vm=require('vm'),path=require('path');
(async()=>{
 const root=path.resolve(__dirname,'..'),data=await(await fetch('http://localhost/lebihsabar/pattern-data.php')).json();
 let source=fs.readFileSync(path.join(root,'patternsaba.js'),'utf8');source=source.slice(0,source.indexOf('  // Konfigurasi Kotak Utama UI'))+'globalThis.evaluate=rows=>l16BuildEvents(rows);})();';
 const ctx={document:{createElement:()=>({}),head:{append(){}}}};vm.createContext(ctx);vm.runInContext(source,ctx);
 const pair=(a,b)=>/^\d+$/.test(a)&&/^\d+$/.test(b)?[+a,+b]:null;
 const rows=data.rows.filter(r=>r.league==='SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play').map((r,i)=>({id:String(i),league:r.league,t:Date.parse(r.match_time.replace(' ','T')+'+08:00'),teams:[r.home_team,r.away_team],fh:pair(r.fh_home,r.fh_away),ft:pair(r.ft_home,r.ft_away),complete:!!pair(r.ft_home,r.ft_away)}));
 const events=ctx.evaluate(rows).events.filter(e=>['L16-51','L16-01','L16-30'].includes(e.rule.id)&&e.check.result!==null).map(e=>({code:e.rule.id,id:e.r.id,win:e.check.result==='BERHASIL'}));
 fs.writeFileSync(path.join(__dirname,'fail_reduction_source.json'),JSON.stringify({generatedAt:new Date().toISOString(),rows,events}));
 console.log(JSON.stringify({rows:rows.length,events:events.length}));
})().catch(e=>{console.error(e);process.exit(1)});
