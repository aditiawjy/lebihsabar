const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const context={window:{}};vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../pattern-live-results.js'),'utf8'),context);
const merge=context.window.SabarajaLiveResults.merge;
const kickoff=Date.parse('2026-10-18T14:00:00+08:00'),now=kickoff+9*60000;
const fixture=(changes={})=>({time:new Date(kickoff).toISOString(),home:'France',away:'Germany',status:'Belum selesai',ht:'1–1',ft:null,...changes});
const live=(changes={})=>({league:'16',home:'France (V)',away:' Germany (V) ',status:"2H 1'",score:'2 - 1',ht:'1 - 1',...changes});
const snapshot=(matches=[fixture()],changes={})=>({league:'16',date:'2026-10-18',matches,...changes});
const payload=(matches=[live()],changes={})=>({online:true,fresh:true,age:0,fetchedAt:now,matches,...changes});
const original=snapshot();const before=JSON.stringify(original);
let rows=merge(original,payload(),{matches:[{index:0,signals:[{id:'L16-01'}]}]},now);
assert.equal(rows.length,1);assert(rows[0].live.kickoffMatched);assert.equal(rows[0].live.status,"2H 1'");
assert.equal(rows[0].live.signals[0].id,'L16-01');assert.equal(rows[0].ft,null,'Live score cannot become FT');
assert.equal(JSON.stringify(original),before,'API objects remain unchanged for CSV save');
rows=merge(snapshot(),payload([live({status:"2H 3'"})],{fetchedAt:now+11*60000}),null,now+11*60000);
assert.equal(rows.length,1);assert(rows[0].live.kickoffMatched,'A delayed feed clock must not duplicate a unique recent unfinished API fixture');
rows=merge(snapshot([fixture({status:'Selesai',ft:'3–2'})]),payload(),null,now);
assert.equal(rows.length,2);assert.equal(rows.find(r=>r.status==='Selesai').ft,'3–2');assert(!rows.find(r=>r.status==='Selesai').live);
rows=merge(snapshot([fixture({time:new Date(kickoff-60*60000).toISOString()}),fixture()]),payload(),null,now);
assert.equal(rows.filter(r=>r.live).length,1);assert.equal(rows.find(r=>r.live).time,new Date(kickoff).toISOString());
rows=merge(snapshot([fixture(),fixture()]),payload(),null,now);
assert.equal(rows.find(r=>r.live).time,null,'Ambiguous kickoff must remain unknown');
assert(!rows.find(r=>r.live).live.kickoffMatched);
rows=merge(snapshot(),payload([live({league:'20'})]),null,now);assert(!rows.some(r=>r.live),'Do not mix leagues');
rows=merge(snapshot(undefined,{apiOnly:true}),payload(),null,now);assert(!rows.some(r=>r.live),'API-only competitions never receive live data');
rows=merge(snapshot(),payload([live({home:'Germany',away:'France'})]),null,now);assert.equal(rows.find(r=>r.live).time,null,'Do not reverse home/away');
rows=merge(snapshot(),payload([]),null,now);assert(!rows.some(r=>r.live));
rows=merge(snapshot(),payload(undefined,{fresh:false,online:false,age:60}),null,now);assert.equal(rows[0].live.fresh,false);assert.equal(rows[0].live.signals.length,0);
rows=merge(snapshot(),payload(undefined,{age:301}),null,now);assert(!rows.some(r=>r.live),'Drop expired live fixtures');
rows=merge(snapshot(undefined,{date:'2026-10-17'}),payload(),null,now);assert(!rows.some(r=>r.live),'No live overlays on historical dates');
const overnight=Date.parse('2026-10-18T00:40:00+08:00');
rows=merge(snapshot([fixture({time:'2026-10-18T00:30:00+08:00'})],{date:'2026-10-17',sessionDate:'2026-10-17',periodStart:'2026-10-17T12:00:00+08:00',periodEnd:'2026-10-18T00:46:00+08:00'}),payload(undefined,{fetchedAt:overnight}),null,overnight);
assert(rows[0].live?.kickoffMatched,'After-midnight live belongs to the previous L16 session');
for(const league of ['15','16','20']) {
  const elapsed=Number(league)/2+1;
  const at=kickoff+elapsed*60000;
  rows=merge(snapshot(undefined,{league}),payload([live({league})],{fetchedAt:at}),null,at);
  assert(rows[0].live.kickoffMatched,'Match kickoff using each league duration');
}
rows=merge(snapshot([fixture({time:new Date(kickoff+15*60000).toISOString()}),fixture({status:'Selesai',ft:'1–1',time:new Date(kickoff-15*60000).toISOString()}),fixture()]),payload(),null,now);
assert(rows[0].live);assert.equal(rows[1].status,'Belum selesai');assert.equal(rows[2].status,'Selesai');
for (const league of ['15','16','20','f24-club']) {
  const times=['16:30','16:00','17:15','16:15','16:45','17:00'];
  rows=merge(snapshot(times.map(time=>fixture({time:`2026-10-18T${time}:00+08:00`})),{league}),payload([]),null,Date.parse('2026-10-18T16:08:00+08:00'));
  assert.deepEqual(Array.from(rows,row=>row.time.slice(11,16)),[...times].sort(),'Overdue unfinished schedules stay in ascending kickoff order for '+league);
}
const market={period:'FT',locked:false,selections:[{side:'O',line:'2.75',price:'1.81'},{side:'U',line:'2.75',price:'1.95'}]};
rows=merge(snapshot(),payload([live({markets:[market]})]),null,now);
assert.equal(rows[0].live.markets[0].selections[0].line,'2.75','Live merge must preserve actual market line');
class Node {
  constructor(text=''){this.textContent=text;this.childNodes=[];this.classList={add(){}};}
  append(...nodes){this.childNodes.push(...nodes);}
}
context.document={createElement:()=>new Node(),createTextNode:text=>new Node(text)};
const renderedText=node=>node.textContent+node.childNodes.map(renderedText).join('');
let container=new Node();context.window.SabarajaLiveResults.appendMarkets(container,{fresh:true,markets:[market]});
assert(renderedText(container).includes('FT O/U O 2.75 @1.81 · U 2.75 @1.95'));
container=new Node();context.window.SabarajaLiveResults.appendMarkets(container,{fresh:false,markets:[{period:'1H',locked:true,selections:[]}]});
assert(renderedText(container).includes('1H O/U Terkunci · data lama'));
container=new Node();context.window.SabarajaLiveResults.appendMarkets(container,{fresh:true,markets:[]});
assert.equal(renderedText(container),'O/U belum tersedia');
console.log('Results/live merge: league/team/kickoff matching, repeated fixtures, ambiguous times, stale data, historical dates, ordering and CSV isolation passed.');
