'use strict';
const scenario = require('../assets/js/demo-scenario.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
const identity = {token: '00112233445566778899aabbccddeeff', generation: 1};
['light', 'medium', 'heavy'].forEach(load => { const first=scenario.build(identity,load), second=scenario.build(identity,load); assert(first.size===load && scenario.equal(first,second), 'The same token/profile must reproduce '+load); assert((new Date(first.end)-new Date(first.start))===86400000, load+' must use one exact end-exclusive day'); });
assert(scenario.build(identity).size === 'medium', 'Default Demo load must be Medium');
const saved=scenario.build(identity,'medium'), restored=scenario.restore({token:saved.token,generation:saved.generation,size:saved.size,rows:saved.rows,start:saved.start,end:saved.end});
assert(scenario.equal(saved,restored),'A saved six-field scenario must restore the same complete generation request');
let rejectedSaved=false;try{scenario.restore({token:saved.token,generation:saved.generation,size:saved.size,rows:1,start:saved.start,end:saved.end});}catch(error){rejectedSaved=true;}assert(rejectedSaved,'A saved scenario with an invalid strict row count must be rejected');
assert(scenario.loads.light.rows===1000 && scenario.loads.medium.rows===5000 && scenario.loads.heavy.rows===20000 && scenario.loads.light.days===1 && scenario.loads.medium.days===1 && scenario.loads.heavy.days===1, 'Profiles must differ in volume and time range');
const randomiser=scenario.randomiser(length => { const bytes=new Uint8Array(length); bytes.fill(7); return bytes; });
const randomOne=randomiser.next('heavy'), randomTwo=randomiser.next('heavy');
assert(randomOne.size==='heavy' && randomTwo.size==='heavy' && randomOne.fingerprint!==randomTwo.fingerprint, 'Randomise must preserve load and never repeat a session fingerprint even with repeated entropy bytes');
assert(!scenario.equal(scenario.build({token:'00112233445566778899aabbccddeeff',generation:1},'medium'), scenario.build({token:'10112233445566778899aabbccddeeff',generation:1},'medium')), 'Different scenario identities must differ');
['trunk','extension','group'].forEach(mode => { const plan=scenario.build(identity,'medium'), parameters=scenario.runParameters(plan,mode,['original','sweep'],2); assert(parameters.demo_report===mode && parameters.demo_token===plan.token && parameters.demo_generation==='1' && parameters.demo_rows==='5000', mode+' run must retain its complete scenario identity'); });
const preflight=scenario.preflightGuard(), oldToken=preflight.begin('old'), currentToken=preflight.begin('current');
assert(!preflight.accepts(oldToken,'old') && preflight.accepts(currentToken,'current'), 'Stale preflight responses must be rejected');

/* Year selection must genuinely constrain scenario generation, not just be cosmetic. */
assert(scenario.YEAR_MIN === 2001 && scenario.YEAR_MAX === 2015 && scenario.YEAR_DEFAULT === 2001, 'Supported Demo year range must be 2001-2015 defaulting to 2001');
assert(scenario.build(identity,'medium').year === 2001, 'Omitting year must default the scenario to 2001');
function tokenFor(index) { return (index.toString(16).padStart(8,'0') + '00112233445566778899aabb').slice(0,32); }
for (let year = 2001; year <= 2015; year++) {
	for (let sample = 0; sample < 40; sample++) {
		const plan = scenario.build({token: tokenFor(year * 1000 + sample), generation: sample + 1}, 'medium', year);
		const start = new Date(plan.start.replace(' ', 'T'));
		const end = new Date(plan.end.replace(' ', 'T'));
		assert(plan.year === year, 'Scenario must record the constrained year: ' + year);
		assert(start.getFullYear() === year, 'Generated scenario start must fall within the selected year ' + year);
		assert(end.getFullYear() === year, 'Generated scenario end must also fall within the selected year ' + year + ', not spill into the next year');
		assert(start >= new Date(year, 0, 1) && start < new Date(year, 11, 31, 23, 59, 60), 'Every supported year may use its full calendar range');
	}
}
assert(scenario.build(identity, 'medium', 1999).year === 2001, 'A year before the supported range must clamp to 2001');
assert(scenario.build(identity, 'medium', 2099).year === 2015, 'A year after the supported range must clamp to 2015');
const yearRandomiser = scenario.randomiser(length => { const bytes = new Uint8Array(length); bytes.fill(9); return bytes; });
const randomYearPlan = yearRandomiser.next('medium', 2010);
assert(randomYearPlan.year === 2010, 'Randomise must honour the selected year, not merely relabel a fixed-year scenario');
const sameYearDifferentLoad = scenario.build({token: randomYearPlan.token, generation: randomYearPlan.generation}, 'heavy', randomYearPlan.year);
assert(sameYearDifferentLoad.year === 2010 && new Date(sameYearDifferentLoad.start.replace(' ', 'T')).getFullYear() === 2010, 'Changing Load for an existing scenario must keep the selected year');

console.log('Demo scenario tests passed');
