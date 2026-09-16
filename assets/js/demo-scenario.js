(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.CCDemoScenario = api;
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';
	var LOADS = {light: {rows: 1000, days: 1}, medium: {rows: 5000, days: 1}, heavy: {rows: 20000, days: 1}};
	function rng(seed) { var state = (Number(seed) || 1) >>> 0; return function () { state = (Math.imul(state, 1664525) + 1013904223) >>> 0; return state / 4294967296; }; }
	function range(random, minimum, maximum) { return minimum + Math.floor(random() * (maximum - minimum + 1)); }
	function format(date) { function pad(value) { return value < 10 ? '0' + value : String(value); } return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()); }
	function hash32(value) { var hash = 2166136261; String(value).split('').forEach(function (character) { hash ^= character.charCodeAt(0); hash = Math.imul(hash, 16777619); }); return hash >>> 0; }
	function fingerprint(plan) { return [plan.token, plan.generation, plan.size, plan.rows, plan.start, plan.end].join('|'); }
	function build(identity, load) {
		load = Object.prototype.hasOwnProperty.call(LOADS, load) ? load : 'medium'; identity = identity || {};
		var token = String(identity.token || '').toLowerCase(), generation = Math.max(1, Number(identity.generation) || 1);
		if (!/^[a-f0-9]{32}$/.test(token)) throw new Error('A cryptographically strong Demo scenario token is required.');
		var seed = hash32(token + ':' + generation) || 1, random = rng(seed), profile = LOADS[load];
		var start = new Date(2001, 0, 1 + range(random, 0, 5800), range(random, 0, 23), range(random, 0, 59), 0);
		var end = new Date(start.getTime() + profile.days * 86400000);
		var plan = {token: token, generation: generation, seed: seed, size: load, rows: profile.rows, start: format(start), end: format(end)};
		plan.fingerprint = fingerprint(plan); return plan;
	}
	function restore(definition) {
		definition = definition || {};
		var plan = build({token:definition.token,generation:definition.generation}, definition.size);
		if (Number(definition.rows) !== plan.rows || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(String(definition.start)) || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(String(definition.end))) throw new Error('Saved Demo scenario definition is invalid.');
		plan.start=String(definition.start); plan.end=String(definition.end); plan.fingerprint=fingerprint(plan);
		return plan;
	}
	function equal(left, right) { return !!left && !!right && ['token', 'generation', 'seed', 'size', 'rows', 'start', 'end'].every(function (key) { return String(left[key]) === String(right[key]); }); }
	function runParameters(plan, report, engines, minimumConcurrency) {
		return {demo_report: report, demo_size: plan.size, demo_rows: String(plan.rows), demo_token: plan.token, demo_generation: String(plan.generation), demo_engines: engines.join(','), minimum_concurrency: String(minimumConcurrency)};
	}
	function randomiser(randomBytes) {
		var generation = 0, seen = {};
		return {next: function (load) { for (;;) { generation++; var bytes = randomBytes(16), token = Array.prototype.map.call(bytes, function (byte) { return ('0' + Number(byte).toString(16)).slice(-2); }).join(''); var plan = build({token:token,generation:generation},load); if (!seen[plan.fingerprint]) { seen[plan.fingerprint]=true; return plan; } }} };
	}
	function preflightGuard() {
		var generation = 0, currentKey = '';
		return {
			begin: function (key) { currentKey = String(key); generation++; return generation; },
			accepts: function (token, key) { return token === generation && String(key) === currentKey; }
		};
	}
	function page(items, pageNumber, pageSize) {
		items = Array.isArray(items) ? items : []; pageSize = Math.max(1, Number(pageSize) || 100);
		var pages = Math.max(1, Math.ceil(items.length / pageSize));
		pageNumber = Math.max(1, Math.min(pages, Number(pageNumber) || 1));
		return {items: items.slice((pageNumber - 1) * pageSize, pageNumber * pageSize), page: pageNumber, pages: pages, total: items.length};
	}
	return {build: build, restore: restore, equal: equal, fingerprint: fingerprint, page: page, preflightGuard: preflightGuard, randomiser: randomiser, runParameters: runParameters, loads: LOADS};
}));
