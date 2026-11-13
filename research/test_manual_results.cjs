const fs = require('fs'), vm = require('vm'), assert = require('assert');
const read = name => fs.readFileSync(require('path').join(__dirname, '..', name), 'utf8');
const source = read('pattern-results.js');
const calls = [], handlers = {};
const element = {value:'2026-11-08', disabled:false, addEventListener(type, fn) { handlers[type] = fn; }};
const context = {
  date:element, resultSnapshots:[], rendered:'', saveMatches:[], vsoccerSaveMatches:[], vsoccerSaveDate:null,
  stopped:false, renderResults(){}, setSaveState(){}, updateSaveButton(){},
  load(update){ calls.push(update); },
  document:{addEventListener(type, fn){handlers[type] = fn;}},
  window:{addEventListener(type, fn){handlers[type] = fn;}}
};
vm.createContext(context);
const tail = source.slice(source.indexOf("  date.addEventListener('change'"), source.lastIndexOf('})();'));
vm.runInContext(tail, context);
assert.deepEqual(calls, [false], 'startup must only read cache');
handlers.change(); handlers.pageshow({persisted:true}); handlers['pattern-results-cache-refresh']();
assert(calls.every(update => update === false), 'navigation must only read cache');
assert.equal(handlers.visibilitychange, undefined, 'returning to tab must not fetch Results');
assert(!/setTimeout|setInterval|schedule\(\)/.test(source), 'Results must have no refresh timer');
assert(source.includes("load(true); });"), 'Results button must retain manual update');
assert(source.includes('if (update && !updateError) await saveResults(true)'), 'successful manual update must save CSV');
const soccer = read('vsoccer-results.js');
assert(!soccer.includes("'pattern-results-cycle'"), 'Results refresh must not trigger V-Soccer provider');
assert(soccer.includes("method:update?'POST':'GET'"));
assert(soccer.includes('(update && active)'), 'duplicate manual requests must be ignored');
assert(read('pattern-all-results.js').includes("new CustomEvent('pattern-results-cache-refresh')"));
assert(read('pattern-live.js').includes('setTimeout(poll, 5000)'), 'live polling must remain active');
console.log('PASS: cache-only startup/date/navigation, no Results timer, manual buttons, CSV save and live polling preserved');
