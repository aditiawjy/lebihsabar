const fs = require('fs'), vm = require('vm'), assert = require('assert');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.cells = []; this.textContent = ''; }
  append(...children) { this.children.push(...children); }
  prepend(child) { this.children.unshift(child); }
  replaceChildren(...children) { this.children = children; }
  createTHead() { const head = new Element('thead'); this.append(head); return head; }
  createTBody() { const body = new Element('tbody'); this.append(body); return body; }
  insertRow() { const row = new Element('tr'); this.append(row); return row; }
  insertCell() { const cell = new Element('td'); this.cells.push(cell); this.append(cell); return cell; }
}
const host = new Element('section');
let evaluated, dispatched;
const context = {
  document: {getElementById: () => host, querySelectorAll: () => [],
    createElement: tag => new Element(tag), createDocumentFragment: () => new Element('fragment'),
    dispatchEvent: event => {dispatched = event.detail;}, addEventListener() {}},
  window: {SabarajaPatternEngine: {evaluateLive(data) {
    evaluated = data;
    return {ready:true, activeRuleIds:[], matches:data.matches.map((match,index) => ({index,signals:[],htAvailable:true,phase:'HT'}))};
  }}, addEventListener() {}},
  CustomEvent: class {constructor(type, options) {this.detail = options.detail;}},
  setTimeout() {}, clearTimeout() {}
};
vm.createContext(context);
const source = fs.readFileSync(require('path').join(__dirname,'../pattern-live.js'), 'utf8');
vm.runInContext(source.replace('refreshView(); poll();', 'globalThis.renderTest = data => {payload=stabilizeHt(data); refreshView();};'), context);
const text = el => [el.textContent,...el.children.map(text)].join(' ');
const fixture = (league,ht) => ({league,home:'France',away:'Germany',status:'H.Time',score:ht,ht});
context.renderTest({online:true,fresh:true,age:0,matches:[fixture('20','2-1'),fixture('15','0-0'),fixture('16','1-1')]});
assert.equal(evaluated.matches.length,1);
assert.equal(evaluated.matches[0].league,'16');
assert.equal(dispatched.payload.matches.length,3,'Results receives all three leagues');
assert.equal(dispatched.analysis.matches.length,1,'Evaluator receives only League 16');
assert.equal(dispatched.payload.matches.find(match=>match.league==='16').ht,'1-1','HT must stay separate across leagues');
assert.equal(dispatched.payload.matches.find(match=>match.league==='15').ht,'0-0');
context.renderTest({online:true,fresh:true,age:0,matches:[fixture('15','0-0')]});
assert.equal(evaluated.matches.length,0,'League 15 must not create League 16 signals');
context.renderTest({online:true,fresh:true,age:0,matches:[]});
assert.equal(dispatched.payload.matches.length,0);
context.renderTest({online:false,fresh:false,matches:[],message:'Scraper offline'});
assert.equal(dispatched.payload.online,false);
console.log('Live bridge: all leagues published to results, L16 evaluator isolated, HT separated, empty/offline states passed.');
