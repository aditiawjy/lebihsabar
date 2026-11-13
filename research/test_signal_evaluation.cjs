const fs=require('fs'),vm=require('vm'),assert=require('assert');
const context={window:{}};vm.createContext(context);vm.runInContext(fs.readFileSync(require('path').join(__dirname,'../pattern-signal-evaluation.js'),'utf8'),context);
const {evaluate,inferRule}=context.window.SabarajaSignalEvaluation;
const finished={status:'Selesai',ht:'2–0',ft:'2–0'};
assert.equal(evaluate(inferRule('H2H UNDER 2.5 · 3×'),finished).text,'Gagal');
assert.equal(evaluate(inferRule('H2H UNDER 2.5 · 3×'),{...finished,ft:'3–0'}).text,'Kena');
assert.equal(evaluate(inferRule('H2H 2H 0–0 · 2×'),finished).text,'Gagal');
assert.equal(evaluate(inferRule('H2H 2H 0–0 · 2×'),{...finished,ht:null}).text,'Data belum lengkap');
assert.equal(evaluate(inferRule('H2H HT 0–0 · 2×'),{...finished,ht:'0–0'}).text,'Gagal');
assert.equal(evaluate(inferRule('H2H HT 0–0 · 2×'),{status:'Belum selesai',ht:null,live:{status:'1H 4',score:'0–0'}}).text,'Menunggu');
assert.equal(evaluate(inferRule('H2H FT O1.5'),{status:'Belum selesai',live:{status:'2H 5',score:'3–0'}}).text,'Menunggu');
assert.equal(evaluate(inferRule('H2H FT O1.5'),{...finished,ft:'1–0'}).text,'Gagal');
assert.equal(evaluate(inferRule('H2H FT O1.5'),finished,{afterFt:true}).text,'Pascahasil');
assert.equal(evaluate(inferRule('H2H HT 0–0'),finished,{ht:'0–0',phase:'HT'}).text,'Pascahasil');
assert.equal(evaluate({period:'HT',kind:'exact',home:0,away:1},{...finished,ht:'1–0'}).text,'Gagal');
assert.equal(evaluate({period:'FT',kind:'teamGoal',teamSide:1},finished).text,'Gagal');
assert.equal(evaluate({period:'2H',kind:'teamGoal',teamSide:0},{...finished,ft:'3–0'}).text,'Kena');
assert.equal(evaluate(null,finished).text,'Informasi');
assert.equal(evaluate(inferRule('H2H DRAW'),{...finished,status:'Ditunda'}).text,'Ditunda');
console.log('PASS: market outcomes, pending vs settled phases, missing scores, late observations, exact side, team goal, cancelled and informational signals');

function element(tag){return {tag,children:[],dataset:{},addEventListener(){},append(...children){this.children.push(...children);}};}
context.document={createElement:element};
const root=element('td');
context.window.SabarajaSignalEvaluation.append(root,{home:'A',away:'B',status:'Belum selesai',signalAnalysis:{teamNoGoal:[{team:'A (V)',period:'HT',current:2,record:5,after:{hits:1,total:2}},{team:'B [V]',period:'HT',current:1,record:4,after:{hits:1,total:2}}],triggerHistory:{}}});
const body=root.children[0].children[1];assert(body.children.some(node=>node.textContent?.includes('Kandang tanpa gol: HT 2×')));assert(body.children.some(node=>node.textContent?.includes('Tandang tanpa gol: HT 1×')));
console.log('PASS: displayed team names match CSV virtual suffixes');

const api=context.window.SabarajaSignalEvaluation;
assert.equal(evaluate(inferRule('H2H UNDER 2.5 · 5×'),{status:'Selesai',ht:'2–0',ft:'4–0'}).text,'Kena');
assert.equal(evaluate(inferRule('H2H DRAW'),{status:'Selesai',ht:'0–0',ft:'1–0'}).text,'Kena');
assert.equal(evaluate(inferRule('HT persis 0–1 · 3×'),{status:'Selesai',ht:'1–0',ft:'2–0'}).text,'Kena');
assert.equal(evaluate(inferRule('H2H FT O1.5'),{status:'Selesai',ht:'0–0',ft:'2–0'}).text,'Kena');
const converted=api.counterHistory({hits:3,total:10},inferRule('H2H UNDER 2.5'));
assert.equal(converted.hits,7);assert.equal(api.counterHistory(converted,inferRule('H2H UNDER 2.5')).hits,7);
assert.equal(api.counterHistory({hits:3,total:10},inferRule('H2H FT O1.5')).hits,3);
console.log('PASS: counter-streak direction, Norway-Wales 4–0 example, explicit Over preserved, historical rates inverted exactly once');
