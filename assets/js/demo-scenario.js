(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.CCDemoScenario = api;
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';
	var LOADS = {light: [25, 140, 20, 240], medium: [650, 2200, 90, 2160], heavy: [7000, 14000, 360, 10080]};
	function rng(seed) { var state = (Number(seed) || 1) >>> 0; return function () { state = (Math.imul(state, 1664525) + 1013904223) >>> 0; return state / 4294967296; }; }
	function range(random, minimum, maximum) { return minimum + Math.floor(random() * (maximum - minimum + 1)); }
	function format(date) { function pad(value) { return value < 10 ? '0' + value : String(value); } return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()); }
	function build(seed, load) {
		load = Object.prototype.hasOwnProperty.call(LOADS, load) ? load : 'medium'; seed = (Number(seed) >>> 0) || 1;
		var random = rng(seed), limits = LOADS[load], rows = range(random, limits[0], limits[1]);
		var start = new Date(2001, 0, 1 + range(random, 0, 6200), range(random, 0, 23), range(random, 0, 59), 0);
		var end = new Date(start.getTime() + range(random, limits[2], limits[3]) * 60000);
		return {seed: seed, size: load, rows: rows, start: format(start), end: format(end)};
	}
	function equal(left, right) { return !!left && !!right && ['seed', 'size', 'rows', 'start', 'end'].every(function (key) { return String(left[key]) === String(right[key]); }); }
	function runParameters(plan, report, engines, minimumConcurrency) {
		return {demo_report: report, demo_size: plan.size, demo_rows: String(plan.rows), demo_seed: String(plan.seed), demo_engines: engines.join(','), minimum_concurrency: String(minimumConcurrency)};
	}
	return {build: build, equal: equal, runParameters: runParameters, loads: LOADS};
}));
