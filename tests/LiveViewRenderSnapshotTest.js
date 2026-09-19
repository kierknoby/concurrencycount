'use strict';

const fs = require('fs');
const vm = require('vm');
const dateFormat = require('../assets/js/date-format.js');
function assert(condition, message) { if (!condition) throw new Error(message); }

const elements = {};
const handlers = {};
const ajaxCommands = [];
function elementFor(id) { return elements[id] || (elements[id] = {id: id, value: '', textValue: '', htmlValue: '', attrs: {}, props: {}, dataStore: {}}); }

function collectionFor(selector) {
	const element = selector && selector.charAt && selector.charAt(0) === '#' ? elementFor(selector.slice(1)) : elementFor(selector || '__anon__');
	const collection = {
		length: 1,
		first() { return collection; },
		off() { return collection; },
		on(event, childOrHandler, handler) { handlers[element.id + ':' + event] = typeof childOrHandler === 'function' ? childOrHandler : handler; return collection; },
		always(callback) { callback(); return collection; },
		done(callback) { callback(); return collection; },
		fail() { return collection; },
		val(value) { if (value === undefined) return element.value; element.value = value; return collection; },
		prop(name, value) { if (value === undefined) return element.props[name]; element.props[name] = value; return collection; },
		attr(name, value) { if (value === undefined) return element.attrs[name]; element.attrs[name] = value; return collection; },
		text(value) { if (value === undefined) return element.textValue; element.textValue = String(value); return collection; },
		html(value) { if (value === undefined) return element.htmlValue; element.htmlValue = String(value); return collection; },
		data(name, value) { if (value === undefined) return element.dataStore[name]; element.dataStore[name] = value; return collection; },
		each(callback) { callback.call(element, 0, element); return collection; },
		find() { return collectionFor('__find__'); },
		closest() { return collection; },
		is() { return false; },
		hide() { element.hidden = true; return collection; },
		show() { element.hidden = false; return collection; },
		toggle() { return collection; },
		css() { return collection; },
		modal() { return collection; },
		addClass() { return collection; },
		removeClass() { return collection; },
		toggleClass() { return collection; },
		append() { return collection; },
		remove() { return collection; }
	};
	return collection;
}

function $(selector) {
	if (typeof selector === 'function') { selector(); return collectionFor('__ready__'); }
	if (selector === '<div>') { let raw = ''; const escaper = {text(value) { raw = value == null ? '' : String(value); return escaper; }, html() { return raw.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }}; return escaper; }
	if (selector && selector.id) return collectionFor('#' + selector.id);
	if (selector && selector.__window) return collectionFor('__window__');
	return collectionFor(selector);
}

$.extend = function () {
	let index = 0, deep = false;
	if (arguments[0] === true) { deep = true; index = 1; }
	const target = arguments[index++] || {};
	for (; index < arguments.length; index++) {
		const source = arguments[index] || {};
		Object.keys(source).forEach(function (key) {
			if (deep && source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) target[key] = $.extend(true, target[key] || {}, source[key]);
			else target[key] = Array.isArray(source[key]) ? source[key].slice() : source[key];
		});
	}
	return target;
};
$.Deferred = function () { return {resolve() {}, reject() {}, promise() { return this; }}; };

const settings = {refresh_interval: 5, hidden_trunks: [], trunk_order: ['alpha'], live_wall_featured_trunks: [], live_wall_theme: 'dark', alerts_enabled: true, recovery_enabled: false, alert_email: '', overall: {enabled: false, threshold: 0, alert_enabled: false}, trunks: {alpha: {enabled: true, threshold: 3, alert_enabled: true, monitored: true}}};
const snapshot = {available: true, generated_at: '2026-09-19 10:05:00', generated_ts: 1789812300, overall: {current: 1, threshold_enabled: false, threshold: 0, status: 'normal', calls: []}, trunks: {alpha: {current: 1, threshold_enabled: true, threshold: 3, status: 'normal', direction_counts: {inbound: 1, outbound: 0, unknown: 0}, calls: [], entity: {label: 'Alpha'}}}};
function responseFor(command) {
	if (command === 'getsettings') return {status: true, settings: settings};
	if (command === 'livestatus') return {status: true, snapshot: snapshot};
	if (command === 'historicalprotection') return {status: true, threshold: 90};
	if (command === 'monitorstatus') return {status: true, monitor: {status: 'online', pid: 123}};
	if (command === 'savesettings') return {status: true, settings: settings};
	return {status: true};
}
$.ajax = function (request) {
	const command = request && request.data ? request.data.command : '';
	ajaxCommands.push(command);
	const response = responseFor(command);
	return {done(callback) { callback(response); return this; }, fail() { return this; }, always(callback) { callback(response); return this; }};
};

const windowObject = {__window: true, CCDateFormat: dateFormat, _ccLiveLoaded: false, jQuery: $, devicePixelRatio: 1, innerWidth: 1024, visualViewport: null, addEventListener() {}, removeEventListener() {}, setTimeout() { return 1; }, clearTimeout() {}, ConcurrencyChart: function () { this.setData = function () {}; this.resize = function () {}; this.destroy = function () {}; }};
const documentObject = {hidden: false, getElementById() { return {getContext() { return {}; }, addEventListener() {}, removeEventListener() {}, setAttribute() {}}; }};
const context = {window: windowObject, document: documentObject, self: windowObject, console: console, setTimeout: windowObject.setTimeout, clearTimeout: windowObject.clearTimeout, Date: Date, JSON: JSON, Object: Object, Array: Array, Number: Number, String: String, parseInt: parseInt, Math: Math};
context.$ = $;
context.jQuery = $;
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/live-view.js', 'utf8'), context);

assert(elementFor('cc-live-updated-time').textValue === dateFormat.localDateTime(snapshot.generated_ts * 1000), 'Live renderSnapshot must use the shared date formatter without a root ReferenceError');
assert(elementFor('cc-live-overall-value').textValue === '1', 'Live renderSnapshot must update the overall live value');
handlers['cc-settings-save:click.ccLive'].call(elementFor('cc-settings-save'));
assert(ajaxCommands.indexOf('savesettings') >= 0, 'Save Settings must submit the settings payload');
assert(elementFor('cc-live-overall-value').textValue === '1', 'Save Settings success must be able to rerender the current snapshot');
console.log('Live View render snapshot tests passed');
