'use strict';

// Lightweight production-controller behavioral test using a focused jQuery/DOM shim.
const fs = require('fs');
const vm = require('vm');

function assert(condition, message) {
	if (!condition) throw new Error(message);
}

const elements = {};
const collections = {};
const handlers = {};
const ajaxRequests = [];
const ids = [
	'cc-demo', 'cc-demo-launch', 'cc-demo-page-1', 'cc-demo-page-2',
	'cc-demo-acknowledge', 'cc-demo-proceed', 'cc-demo-back', 'cc-demo-error',
	'cc-demo-load', 'cc-demo-selection-status', 'cc-demo-plan',
	'cc-demo-preflight-rows', 'cc-demo-preflight-required', 'cc-demo-preflight-free',
	'cc-demo-preflight-reserve', 'cc-demo-preflight-available', 'cc-demo-preflight-safety',
	'cc-demo-minimum-concurrency', 'cc-results', 'cc-wizard'
];

ids.forEach(id => { elements[id] = {id: id, hidden: false, disabled: false, checked: false, value: ''}; });
['trunk', 'extension', 'group'].forEach(report => {
	elements['run-' + report] = {id: 'run-' + report, hidden: true, disabled: false, checked: false, value: report, report: report};
});

function elementFor(id) { return elements[id] || (elements[id] = {id: id, hidden: false, disabled: false, checked: false, value: ''}); }
function collectionFor(selector) {
	if (collections[selector]) return collections[selector];
	let matched;
	if (selector === '.cc-demo-run-mode') matched = ['run-trunk', 'run-extension', 'run-group'].map(id => elementFor(id));
	else if (selector === '.cc-demo-engine:checked') matched = [];
	else if (selector.indexOf('#') === 0) matched = [elementFor(selector.slice(1))];
	else matched = [];
	const collection = {
		length: matched.length,
		each(callback) { matched.forEach((element, index) => callback.call(element, index, element)); return collection; },
		off(event) { if (matched.length) matched.forEach(element => { delete handlers[element.id + ':' + event]; }); return collection; },
		on(event, callback) { matched.forEach(element => { handlers[element.id + ':' + event] = callback; }); return collection; },
		prop(name, value) { if (value === undefined) return matched[0] && matched[0][name]; matched.forEach(element => { element[name] = value; }); return collection; },
		attr(name, value) { if (value === undefined) return matched[0] && matched[0][name]; matched.forEach(element => { element[name] = value; }); return collection; },
		is(expression) { return expression === ':checked' ? !!(matched[0] && matched[0].checked) : false; },
		show() { return collection.prop('hidden', false); },
		hide() { return collection.prop('hidden', true); },
		text() { return collection; },
		html() { return ''; },
		empty() { return collection; },
		val(value) { if (value === undefined) return matched[0] && matched[0].value; matched.forEach(element => { element.value = value; }); return collection; },
		modal(action) { if (action === 'hide') trigger('cc-demo', 'hidden.bs.modal'); return collection; },
		data() { return undefined; },
		removeClass() { return collection; },
		addClass() { return collection; },
		remove() { return collection; },
		after() { return collection; },
		before() { return collection; },
		append() { return collection; },
		prepend() { return collection; },
		css() { return collection; },
		toggle() { return collection; },
		trigger() { return collection; },
		focus() { return collection; },
		closest() { return collection; },
		find() { return collection; }
	};
	collections[selector] = collection;
	return collection;
}

function $(selector) {
	if (typeof selector === 'function') { selector(); return collectionFor('__ready__'); }
	if (selector === document) return collectionFor('__document__');
	if (selector && selector.id) return collectionFor('#' + selector.id);
	if (selector && typeof selector === 'object') return collectionFor('__object__');
	return collectionFor(selector);
}

function trigger(id, event) {
	const handler = handlers[id + ':' + event];
	if (handler) handler.call(elementFor(id), {target: elementFor(id)});
}

function deferred() {
	return {
		done(callback) { this.success = callback; return this; },
		fail(callback) { this.failure = callback; return this; },
		success: null,
		failure: null
	};
}

const scenario = {
	loads: {medium: {rows: 5000, days: 1}},
	randomiser: () => ({next: () => ({token: '00112233445566778899aabbccddeeff', generation: 1, seed: 1, size: 'medium', rows: 5000, start: '2001-01-01 00:00:00', end: '2001-01-02 00:00:00'})}),
	preflightGuard: () => ({begin: () => 1, accepts: () => true}),
	equal: () => true,
	runParameters: () => ({})
};
const windowObject = {
	CCDemoScenario: scenario,
	CCHistoricalReportOrder: {createGenerationGuard: () => ({})},
	crypto: {getRandomValues(bytes) { bytes.fill(1); return bytes; }},
	confirm: () => true,
	setTimeout(callback) { callback(); return 1; },
	clearTimeout() {}
};
const document = {};
const context = {
	window: windowObject,
	document: document,
	self: windowObject,
	console: console,
	Uint8Array: Uint8Array,
	Math: Math,
	Date: Date,
	Number: Number,
	String: String,
	Object: Object,
	Array: Array,
	JSON: JSON,
	parseInt: parseInt,
	setTimeout: windowObject.setTimeout,
	clearTimeout: windowObject.clearTimeout
};
windowObject.jQuery = $;
context.$ = $;
context.window = windowObject;
windowObject.ajax = undefined;
context.window.jQuery = $;
function ajax(params) { const request = deferred(); ajaxRequests.push({params: params || {}, request: request}); return request; }
$.ajax = ajax;
$.extend = function (target, source) { return Object.assign(target, source); };
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/concurrencycount.js', 'utf8'), context);

function runState() {
	return {
		page1: !elementFor('cc-demo-page-1').hidden,
		page2: !!elementFor('cc-demo-page-2').hidden,
		acknowledged: !!elementFor('cc-demo-acknowledge').checked,
		proceedVisible: !elementFor('cc-demo-proceed').hidden,
		proceedDisabled: !!elementFor('cc-demo-proceed').disabled,
		backHidden: !!elementFor('cc-demo-back').hidden,
		runsHidden: ['trunk', 'extension', 'group'].every(report => elementFor('run-' + report).hidden),
		runsDisabled: ['trunk', 'extension', 'group'].every(report => elementFor('run-' + report).disabled)
	};
}
function assertFreshPage(state, label) {
	assert(state.page1 && state.page2 && !state.acknowledged && state.proceedVisible && state.proceedDisabled && state.backHidden && state.runsHidden, label);
}

trigger('cc-demo-launch', 'click');
assertFreshPage(runState(), 'fresh open must show only the unchecked Page 1 gate');
const preflightRequest = ajaxRequests.find(item => item.params.data && item.params.data.command === 'demopreflight');
assert(preflightRequest, 'fresh open must retain the existing preflight request');
preflightRequest.request.success({status: true, preflight: {required_bytes: 1, free_bytes: 2, reserve_bytes: 0, available_bytes: 2}});
assertFreshPage(runState(), 'successful Page 1 preflight must not expose Run buttons');

elementFor('cc-demo-acknowledge').checked = true;
trigger('cc-demo-acknowledge', 'change');
assert(!elementFor('cc-demo-proceed').disabled && runState().runsHidden, 'acknowledgement must only enable Proceed');
trigger('cc-demo-proceed', 'click');
let state = runState();
assert(!state.page1 && !state.page2 && !state.proceedVisible && !state.backHidden && !state.runsHidden, 'Proceed must show Page 2 and its controls');
	assert(!state.runsDisabled, 'Page transition must preserve the successful preflight enabled state');
trigger('cc-demo-back', 'click');
state = runState();
assert(state.page1 && state.page2 && state.acknowledged && state.proceedVisible && !state.proceedDisabled && state.backHidden && state.runsHidden, 'Back must return to Page 1 while preserving acknowledgement and enabled Proceed');

trigger('cc-demo-acknowledge', 'change');
trigger('cc-demo-proceed', 'click');
trigger('cc-demo', 'hidden.bs.modal');
trigger('cc-demo-launch', 'click');
assertFreshPage(runState(), 'close and reopen must restore the exact fresh Page 1 state');
assert(!Object.prototype.hasOwnProperty.call(windowObject, 'demoAcknowledged'), 'acknowledgement must not be persisted on window');
console.log('Demo modal gate tests passed');
