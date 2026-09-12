'use strict';
const scenario = require('../assets/js/demo-scenario.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
const seed = 123456789;
['light', 'medium', 'heavy'].forEach(load => {
	const first = scenario.build(seed, load), second = scenario.build(seed, load);
	assert(first.size === load, load + ' must remain explicitly selected');
	assert(scenario.equal(first, second), 'The same saved ' + load + ' scenario must reproduce exactly');
});
assert(scenario.build(seed).size === 'medium', 'Default Demo load must be deterministic Medium');
function assertLoadState(load) {
	const state = scenario.loadState(load);
	['light', 'medium', 'heavy'].forEach(name => {
		const selected = name === load;
		assert(state[name].checked === selected && state[name].active === selected && state[name].ariaPressed === String(selected), load + ' must be the only logical, visual and accessible selection');
	});
}
assertLoadState('medium');
assertLoadState('light');
assertLoadState('heavy');
['light', 'medium', 'heavy'].forEach(load => assert(scenario.build(seed, load).seed === seed && scenario.build(seed, load).size === load, 'Changing to ' + load + ' must rebuild with the same seed'));
assert(!scenario.equal(scenario.build(seed, 'medium'), scenario.build(seed + 1, 'medium')), 'Randomising the seed must change the generated scenario');
assert(scenario.build(seed, 'medium').size === scenario.build(seed + 1, 'medium').size, 'Randomising must preserve the selected load');
const saved = JSON.parse(JSON.stringify(scenario.build(seed, 'heavy')));
assert(scenario.equal(saved, scenario.build(seed, 'heavy')), 'Saved seed, load, rows and range must restore reproducibly');
assert(scenario.loadState(saved.size).heavy.active && scenario.loadState(saved.size).heavy.ariaPressed === 'true', 'Restoring a saved load must restore its visual and accessible state');
['trunk', 'extension', 'group'].forEach(mode => {
	const plan = scenario.build(seed, 'medium');
	const parameters = scenario.runParameters(plan, mode, ['original', 'sweep'], 2);
	assert(parameters.demo_report === mode, mode + ' button must preserve its Demo report mode');
	assert(parameters.demo_size === plan.size && parameters.demo_rows === String(plan.rows) && parameters.demo_seed === String(plan.seed), 'Displayed scenario must reach the ' + mode + ' run');
	assert(parameters.demo_engines === 'original,sweep' && parameters.minimum_concurrency === '2', 'Selected engines and Minimum concurrency must reach the ' + mode + ' run');
});
console.log('Demo scenario tests passed');
