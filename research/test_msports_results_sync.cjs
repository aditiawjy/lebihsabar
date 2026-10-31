const fs=require('fs'),path=require('path'),vm=require('vm');
const source=fs.readFileSync(path.join(__dirname,'../msports-parser/league16-results.js'),'utf8');
const table=(status,date,ht='0 - 0',ft='1 - 0',teams='Norway (V) v France (V)')=>({
 querySelectorAll:()=>[teams,ht,ft].map(textContent=>({textContent})),
 querySelector:selector=>({textContent:selector.includes('style_status')?status:date})
});
const valid=table('COMPLETED','2026-10-17 12:15 PM');
const heading={querySelector:()=>({textContent:'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play'}),nextElementSibling:{querySelectorAll:()=>[
 valid,valid,table('RUNNING','2026-10-17 12:30 PM'),table('COMPLETED','12:45 PM'),table('COMPLETED','2026-10-17 12:45 PM','2-0','1-0'),table('FT','2026-10-18 12:00 AM')
]}};
const context={document:{querySelectorAll:()=>[heading]},console,setInterval(){},chrome:{runtime:{sendMessage:async()=>({success:true,result:{message:'test only'}})}}};
vm.createContext(context);vm.runInContext(source.replace('    sync();\n})();','    globalThis.collectTest=collect;\n})();'),context);
const rows=context.collectTest();
if(rows.length!==2||rows[0].match_time!=='2026-10-17 12:15:00'||rows[1].match_time!=='2026-10-18 00:00:00')throw Error('Date/status/score/dedup handling failed');
if(rows.some(r=>r.league!=='SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play'))throw Error('League filter failed');
console.log('Results collector passed: completed only, exact source date, AM/PM, valid HT/FT, deduplication. No CSV modified.');
