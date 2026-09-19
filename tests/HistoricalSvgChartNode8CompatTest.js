'use strict';

// Regression coverage for the Node 8.16.0 UMD root-selection fallback: Node 8 has no `self`
// and no `globalThis`, and a CommonJS module's top-level `this` is `module.exports`, not the
// Node global object. This simulates that exact combination inside a controlled vm context so
// the regression is genuinely exercised rather than masked by the modern test runner's globalThis.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

function assert(condition, message) { if (!condition) throw new Error(message); }

const dateFormat = require('../assets/js/date-format.js');
const sourcePath = path.join(__dirname, '..', 'assets', 'js', 'historical-svg-chart.js');
const source = fs.readFileSync(sourcePath, 'utf8');

const fixedRootExpression = "typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : (typeof global !== 'undefined' ? global : this))";
const preFixRootExpression = "typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : this)";
assert(source.indexOf(fixedRootExpression) !== -1, 'historical-svg-chart.js must fall back to the Node global object before this');
const brokenSource = source.split(fixedRootExpression).join(preFixRootExpression);
assert(brokenSource !== source && brokenSource.indexOf(preFixRootExpression) !== -1, 'The pre-fix expression substitution must actually change the source under test');

function runInSimulatedNode8(candidateSource) {
	const sandbox = {};
	sandbox.global = sandbox;
	sandbox.module = {exports: {}};
	sandbox.CCDateFormat = dateFormat;
	const context = vm.createContext(sandbox);
	// A fresh V8 realm always exposes globalThis as a language feature; Node 8.16.0 predates
	// that feature entirely, so it is removed here to faithfully simulate the old runtime.
	vm.runInContext('if (typeof globalThis !== "undefined") { delete globalThis.globalThis; }', context);
	assert(vm.runInContext('typeof self', context) === 'undefined', 'Simulated Node 8 must have no self');
	assert(vm.runInContext('typeof globalThis', context) === 'undefined', 'Simulated Node 8 must have no globalThis');
	// Node's module wrapper calls the compiled module function with `this` bound to module.exports.
	vm.runInContext('(function () {\n' + candidateSource + '\n}).call(module.exports);', context);
	return sandbox.module.exports;
}

const start = 1735689600, end = 1767225599;
const domain = {minTs: start, maxTs: end};
const specs = [{name: 'A', label: 'Trunk A', points: [{ts: start + 100, value: 5}, {ts: start + 161, value: null}], threshold: 5, exactPeak: 5}];

let brokenThrew = false, brokenMessage = '';
try {
	runInSimulatedNode8(brokenSource).multiModel(specs, domain);
} catch (error) {
	brokenThrew = true;
	brokenMessage = String(error && error.message);
}
assert(brokenThrew && /localYearMonth/.test(brokenMessage), 'The pre-fix root-selection fallback must fail under simulated Node 8.16.0 exactly as reported');

const chart = runInSimulatedNode8(source).multiModel(specs, domain);
const expectedLabel = dateFormat.localYearMonth(new Date(start * 1000));
assert(chart.ticks[0].label === expectedLabel, 'The corrected root-selection fallback must reach the shared CCDateFormat helper under simulated Node 8.16.0');

console.log('Historical SVG chart Node 8 compatibility tests passed');
