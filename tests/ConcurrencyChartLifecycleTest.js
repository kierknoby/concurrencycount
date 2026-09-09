'use strict';

const fs = require('fs');
const vm = require('vm');
function assert(condition, message) { if (!condition) throw new Error(message); }

function contextStub() {
	const noop = function () {};
	return {setTransform: noop, clearRect: noop, fillRect: noop, beginPath: noop, moveTo: noop, lineTo: noop, stroke: noop, fillText: noop, save: noop, restore: noop, setLineDash: noop, measureText: function () { return {width: 20}; }};
}
function canvasStub(width, height) {
	const listeners = {};
	return {
		clientWidth: width, clientHeight: height, width: 300, height: 220, attributes: {}, listeners: listeners,
		getContext: function () { return contextStub(); },
		setAttribute: function (name, value) { this.attributes[name] = value; },
		addEventListener: function (name, handler) { listeners[name] = handler; },
		removeEventListener: function (name, handler) { if (listeners[name] === handler) delete listeners[name]; }
	};
}
function ResizeObserverStub(callback) { this.callback = callback; this.target = null; this.disconnected = false; }
ResizeObserverStub.prototype.observe = function (target) { this.target = target; };
ResizeObserverStub.prototype.disconnect = function () { this.disconnected = true; this.target = null; };
const windowStub = {devicePixelRatio: 2, listeners: {}, ResizeObserver: ResizeObserverStub, addEventListener: function (name, handler) { this.listeners[name] = handler; }, removeEventListener: function (name, handler) { if (this.listeners[name] === handler) delete this.listeners[name]; }};
const sandbox = {window: windowStub};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/concurrency-charts.js', 'utf8'), sandbox);
const Chart = windowStub.ConcurrencyChart;

const hidden = canvasStub(0, 0);
const points = [{ts: 1, value: 1}, {ts: 2, value: null}, {ts: 3, value: 4}];
const hiddenChart = new Chart(hidden);
hiddenChart.setData(points, 0);
assert(hidden.width === 300 && hidden.height === 220, 'Hidden construction must not replace intrinsic canvas dimensions with fallback backing dimensions');
assert(hiddenChart.points[1].value === null, 'Hidden rendering must preserve null graph gaps');
hidden.clientWidth = 640; hidden.clientHeight = 220;
assert(hiddenChart.resizeObserver.target === hidden, 'Chart must observe layout changes affecting its canvas');
hiddenChart.resizeObserver.callback();
assert(hidden.width === 1280 && hidden.height === 440, 'Becoming visible must establish the DPR backing store from CSS dimensions');
assert(hiddenChart.points[0].value === 1 && hiddenChart.points[1].value === null && hiddenChart.points[2].value === 4, 'Resize must preserve supplied graph data exactly');
hidden.clientWidth = 500;
hiddenChart.resize();
assert(hidden.width === 1000 && hiddenChart.points[1].value === null, 'Repeated responsive resize must use current CSS width without mutating gaps');

const replacement = new Chart(hidden);
assert(hidden.__ccConcurrencyChart === replacement, 'The newest chart must own its canvas');
assert(!hiddenChart.resize || windowStub.listeners.resize === replacement.resize, 'Replacing a chart must dispose of the previous window resize listener');
assert(hiddenChart.resizeObserver.disconnected, 'Replacing a chart must disconnect its previous layout observer');
replacement.setData(points, 0);
const secondReplacement = new Chart(hidden);
assert(hidden.__ccConcurrencyChart === secondReplacement && windowStub.listeners.resize === secondReplacement.resize, 'Repeated replacement must leave exactly one live chart owner and resize listener');
secondReplacement.destroy();
assert(hidden.__ccConcurrencyChart === null && !windowStub.listeners.resize, 'Destroy must release canvas ownership and listeners');

function loadHistoricalScheduler(withAnimationFrame) {
	const frames = []; const timers = [];
	const schedulerWindow = {_ccLiveLoaded: true, setTimeout: function (callback, delay) { timers.push({callback: callback, delay: delay}); }};
	if (withAnimationFrame) schedulerWindow.requestAnimationFrame = function (callback) { frames.push(callback); };
	vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/live-view.js', 'utf8'), {window: schedulerWindow});
	return {scheduler: schedulerWindow.CCChartRenderScheduler(), frames: frames, timers: timers};
}

const animationScheduler = loadHistoricalScheduler(true);
const rendered = [];
animationScheduler.scheduler.schedule(function () { rendered.push('old'); });
assert(rendered.length === 0 && animationScheduler.frames.length === 1, 'Historical rendering must defer through requestAnimationFrame');
animationScheduler.scheduler.schedule(function () { rendered.push('new'); });
assert(animationScheduler.frames.length === 1, 'Repeated Historical scheduling must share one pending animation frame');
animationScheduler.frames.shift()();
assert(rendered.length === 1 && rendered[0] === 'new', 'A stale queued render must not overwrite the newest requested graph state');

const fallbackScheduler = loadHistoricalScheduler(false);
let fallbackRendered = false;
fallbackScheduler.scheduler.schedule(function () { fallbackRendered = true; });
assert(!fallbackRendered && fallbackScheduler.timers.length === 1 && fallbackScheduler.timers[0].delay === 0, 'Unavailable requestAnimationFrame must use one asynchronous fallback');
fallbackScheduler.timers.shift().callback();
assert(fallbackRendered, 'Historical scheduling fallback must execute the requested render');

console.log('Concurrency chart lifecycle tests passed');
