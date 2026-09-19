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
const accessState = {enabled: false};
const ids = [
	'cc-demo', 'cc-demo-launch', 'cc-demo-page-1', 'cc-demo-page-2',
	'cc-demo-acknowledge', 'cc-demo-proceed', 'cc-demo-year', 'cc-demo-error',
	'cc-demo-load', 'cc-demo-selection-status', 'cc-demo-plan',
	'cc-demo-preflight-rows', 'cc-demo-preflight-required', 'cc-demo-preflight-free',
	'cc-demo-preflight-reserve', 'cc-demo-preflight-available', 'cc-demo-preflight-safety',
	'cc-demo-minimum-concurrency', 'cc-results', 'cc-wizard'
];

ids.forEach(id => { elements[id] = {id: id, hidden: false, disabled: false, checked: false, value: ''}; });
['trunk', 'extension', 'group'].forEach(report => {
	elements['run-' + report] = {id: 'run-' + report, hidden: true, disabled: false, checked: false, value: report, report: report};
});

function elementFor(id) { return elements[id] || (elements[id] = {id: id, hidden: false, disabled: false, checked: false, value: '', html: ''}); }
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
		html(value) { if (value === undefined) return matched[0] ? matched[0].html : ''; matched.forEach(element => { element.html = value; }); return collection; },
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

function htmlEscape(value) {
	return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function $(selector) {
	if (typeof selector === 'function') { selector(); return collectionFor('__ready__'); }
	if (selector === document) return collectionFor('__document__');
	// escapeHtml() round-trips a value through a detached <div> for entity escaping; reproduce that here.
	if (selector === '<div>') { let raw = ''; const escaper = {text(value) { raw = value; return escaper; }, html() { return htmlEscape(raw); }}; return escaper; }
	if (selector && selector.id) return collectionFor('#' + selector.id);
	if (selector === '.concurrencycount') return {attr(name) { return name === 'data-demo-access-enabled' ? (accessState.enabled ? 'true' : 'false') : undefined; }};
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

// Uses the real Demo scenario/randomiser module so year-constraint behaviour is genuinely exercised.
const scenario = require('../assets/js/demo-scenario.js');
const historicalRunState = require('../assets/js/historical-run-state.js');
const windowObject = {
	CCDemoScenario: scenario,
	CCHistoricalReportOrder: {createGenerationGuard: () => ({})},
	CCHistoricalRunState: historicalRunState,
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
		year: elementFor('cc-demo-year').value,
		runsHidden: ['trunk', 'extension', 'group'].every(report => elementFor('run-' + report).hidden),
		runsDisabled: ['trunk', 'extension', 'group'].every(report => elementFor('run-' + report).disabled)
	};
}
function assertFreshPage(state, label) {
	assert(state.page1 && state.page2 && !state.acknowledged && state.proceedVisible && state.proceedDisabled && state.year === '2001' && state.runsHidden, label);
}
function latestRequest(matcher) {
	for (let index = ajaxRequests.length - 1; index >= 0; index--) if (matcher(ajaxRequests[index])) return ajaxRequests[index];
	return null;
}
function resolveLatestPreflight() {
	const request = latestRequest(item => item.params.data && item.params.data.command === 'demopreflight');
	assert(request, 'expected a pending demopreflight request');
	request.request.success({status: true, preflight: {required_bytes: 1, free_bytes: 2, reserve_bytes: 0, available_bytes: 2}});
}
function renderedScenarioYear() {
	const html = elementFor('cc-demo-plan').html;
	const match = /CDR write range<\/dt><dd>(\d{4})-/.exec(html);
	assert(match, 'the rendered Demo plan must show a CDR write range');
	return match[1];
}

const requestsBeforeDeniedLaunch = ajaxRequests.length;
trigger('cc-demo-launch', 'click');
assert(elementFor('cc-demo-year').value === '' && ajaxRequests.length === requestsBeforeDeniedLaunch, 'DOM-tampered disabled Demo button must not open while rendered access state is false');
accessState.enabled = true;
trigger('cc-demo-launch', 'click');
assertFreshPage(runState(), 'fresh open must show only the unchecked Page 1 gate with Year defaulted to 2001');
resolveLatestPreflight();
assertFreshPage(runState(), 'successful Page 1 preflight must not expose Run buttons');
assert(!handlers['cc-demo-back:click'], 'no Back control may be wired');
assert(renderedScenarioYear() === '2001', 'default opening must produce a 2001 scenario');

elementFor('cc-demo-year').value = '2010';
trigger('cc-demo-year', 'change');
resolveLatestPreflight();
assert(runState().year === '2010', 'selecting a Year on Page 1 must update the visible selection');
assert(renderedScenarioYear() === '2010', 'the selected Year must constrain the generated scenario');

elementFor('cc-demo-acknowledge').checked = true;
trigger('cc-demo-acknowledge', 'change');
assert(!elementFor('cc-demo-proceed').disabled && runState().runsHidden, 'acknowledgement must only enable Proceed');
trigger('cc-demo-proceed', 'click');
let state = runState();
assert(!state.page1 && !state.page2 && !state.proceedVisible && !state.runsHidden, 'Proceed must show Page 2 and its controls with no Back and no Proceed');
assert(!state.runsDisabled, 'Page transition must preserve the successful preflight enabled state');
assert(renderedScenarioYear() === '2010', 'the scenario shown on Page 2 must fall within the selected Year');

elementFor('cc-demo-load').value = 'heavy';
trigger('cc-demo-load', 'change');
resolveLatestPreflight();
assert(renderedScenarioYear() === '2010', 'changing Load on Page 2 must keep the selected Year');

trigger('cc-demo-randomise', 'click');
resolveLatestPreflight();
assert(renderedScenarioYear() === '2010', 'Randomise on Page 2 must keep the selected Year');

trigger('cc-demo', 'hidden.bs.modal');
trigger('cc-demo-launch', 'click');
assertFreshPage(runState(), 'close and reopen must restore the exact fresh Page 1 state with Year reset to 2001');
assert(!Object.prototype.hasOwnProperty.call(windowObject, 'demoAcknowledged'), 'acknowledgement must not be persisted on window');
console.log('Demo modal gate tests passed');
