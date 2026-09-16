'use strict';
const scenario = require('../assets/js/demo-scenario.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
const identity = {token: '00112233445566778899aabbccddeeff', generation: 1};
['light', 'medium', 'heavy'].forEach(load => { const first=scenario.build(identity,load), second=scenario.build(identity,load); assert(first.size===load && scenario.equal(first,second), 'The same token/profile must reproduce '+load); assert((new Date(first.end)-new Date(first.start))===86400000, load+' must use one exact end-exclusive day'); });
assert(scenario.build(identity).size === 'medium', 'Default Demo load must be Medium');
const saved=scenario.build(identity,'medium'), restored=scenario.restore({token:saved.token,generation:saved.generation,size:saved.size,rows:saved.rows,start:saved.start,end:saved.end});
assert(scenario.equal(saved,restored),'A saved six-field scenario must restore the same complete generation request');
let rejectedSaved=false;try{scenario.restore({token:saved.token,generation:saved.generation,size:saved.size,rows:1,start:saved.start,end:saved.end});}catch(error){rejectedSaved=true;}assert(rejectedSaved,'A saved scenario with an invalid strict row count must be rejected');
function assertLoadState(load) { const state=scenario.loadState(load); ['light','medium','heavy'].forEach(name => assert(state[name].active===(name===load) && state[name].ariaPressed===String(name===load), load+' must be the only selected button')); }
['light','medium','heavy'].forEach(assertLoadState);
function button(load, classes) { const values=new Set(classes||[]); return {load:load,classList:{add:function(){Array.from(arguments).forEach(value=>values.add(value));},remove:function(){Array.from(arguments).forEach(value=>values.delete(value));},contains:function(value){return values.has(value);}},attributes:{'data-load':load},getAttribute:function(name){return this.attributes[name];},setAttribute:function(name,value){this.attributes[name]=String(value);}}; }
const loadButtons=[button('light',['btn-primary','selected']),button('medium',['active','btn-success','cc-demo-load-selected']),button('heavy',[])];
function assertRenderedLoad(load,message){scenario.renderLoadButtons(loadButtons,load);const selected=loadButtons.filter(item=>item.classList.contains('cc-demo-load-selected')),neutral=loadButtons.filter(item=>item.classList.contains('cc-demo-load-unselected')),pressed=loadButtons.filter(item=>item.getAttribute('aria-pressed')==='true'),released=loadButtons.filter(item=>item.getAttribute('aria-pressed')==='false');assert(selected.length===1&&selected[0].load===load&&neutral.length===2&&pressed.length===1&&pressed[0].load===load&&released.length===2,message);assert(loadButtons.every(item=>!item.classList.contains('btn-primary')&&!item.classList.contains('btn-success')&&!item.classList.contains('selected')),'Legacy visual state is removed before rendering '+load);}
assertRenderedLoad('medium','Initial Medium render selects only Medium');
assertRenderedLoad('heavy','Medium to Heavy selects only Heavy');
assertRenderedLoad('light','Heavy to Light selects only Light');
assertRenderedLoad('medium','Light to Medium selects only Medium');
assertRenderedLoad('heavy','Selecting Heavy before Randomise selects only Heavy');
assertRenderedLoad('heavy','Randomise rerender retains Heavy as the only selected load');
assert(scenario.loads.light.rows===1000 && scenario.loads.medium.rows===5000 && scenario.loads.heavy.rows===20000 && scenario.loads.light.days===1 && scenario.loads.medium.days===1 && scenario.loads.heavy.days===1, 'Profiles must differ in volume and time range');
const randomiser=scenario.randomiser(length => { const bytes=new Uint8Array(length); bytes.fill(7); return bytes; });
const randomOne=randomiser.next('heavy'), randomTwo=randomiser.next('heavy');
assert(randomOne.size==='heavy' && randomTwo.size==='heavy' && randomOne.fingerprint!==randomTwo.fingerprint, 'Randomise must preserve load and never repeat a session fingerprint even with repeated entropy bytes');
assert(!scenario.equal(scenario.build({token:'00112233445566778899aabbccddeeff',generation:1},'medium'), scenario.build({token:'10112233445566778899aabbccddeeff',generation:1},'medium')), 'Different scenario identities must differ');
['trunk','extension','group'].forEach(mode => { const plan=scenario.build(identity,'medium'), parameters=scenario.runParameters(plan,mode,['original','sweep'],2); assert(parameters.demo_report===mode && parameters.demo_token===plan.token && parameters.demo_generation==='1' && parameters.demo_rows==='5000', mode+' run must retain its complete scenario identity'); });
const preflight=scenario.preflightGuard(), oldToken=preflight.begin('old'), currentToken=preflight.begin('current');
assert(!preflight.accepts(oldToken,'old') && preflight.accepts(currentToken,'current'), 'Stale preflight responses must be rejected');
console.log('Demo scenario tests passed');
