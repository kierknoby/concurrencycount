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

function loadHistoricalScheduler(withAnimationFrame, maximumAttempts) {
	const frames = []; const timers = [];
	const schedulerWindow = {_ccLiveLoaded: true, setTimeout: function (callback, delay) { timers.push({callback: callback, delay: delay}); }};
	if (withAnimationFrame) schedulerWindow.requestAnimationFrame = function (callback) { frames.push(callback); };
	vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/live-view.js', 'utf8'), {window: schedulerWindow});
	return {scheduler: schedulerWindow.CCChartRenderScheduler(maximumAttempts), frames: frames, timers: timers, window: schedulerWindow};
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
assert(!fallbackRendered && fallbackScheduler.timers.length === 1 && fallbackScheduler.timers[0].delay === 16, 'Unavailable requestAnimationFrame must use one frame-paced asynchronous fallback');
fallbackScheduler.timers.shift().callback();
assert(fallbackRendered, 'Historical scheduling fallback must execute the requested render');

const settlingScheduler = loadHistoricalScheduler(true, 6);
let settlingAttempts = 0;
settlingScheduler.scheduler.schedule(function () { settlingAttempts++; return settlingAttempts < 3; });
settlingScheduler.frames.shift()();
settlingScheduler.frames.shift()();
settlingScheduler.frames.shift()();
assert(settlingAttempts === 3 && settlingScheduler.frames.length === 0, 'Unusable and changing dimensions must rerender automatically until stable');

const supersededScheduler = loadHistoricalScheduler(true, 6);
const supersededRenders = [];
supersededScheduler.scheduler.schedule(function () { supersededRenders.push('old'); return true; });
supersededScheduler.frames.shift()();
supersededScheduler.scheduler.schedule(function () { supersededRenders.push('new'); return false; });
supersededScheduler.frames.shift()();
assert(supersededRenders.join(',') === 'old,new' && supersededScheduler.frames.length === 0, 'A newer Historical series must supersede an older pending retry');

const boundedScheduler = loadHistoricalScheduler(true, 3);
let boundedAttempts = 0;
boundedScheduler.scheduler.schedule(function () { boundedAttempts++; return true; });
while (boundedScheduler.frames.length) boundedScheduler.frames.shift()();
assert(boundedAttempts === 3, 'An unusable hidden graph must stop its active retry loop at the configured bound');

let currentHistoricalToken = 'latest';
const actualLifecycle = boundedScheduler.window.CCHistoricalGraphLifecycle(function () { return currentHistoricalToken === 'latest'; });
let lifecycleRenders = 0;
let suppliedDomain = null;
let renderedSeriesId = null;
function lifecycleRender() {
	lifecycleRenders++;
	renderedSeriesId = currentHistoricalToken;
	suppliedDomain = {minTs: 1735689600, maxTs: 1767225599};
	return {owned: true, backingWidth: 1200, backingHeight: 440};
}
function lifecycleVerify() { return suppliedDomain.minTs === 1735689600 && suppliedDomain.maxTs === 1767225599; }
let lifecycleOutcome = actualLifecycle.step({containerWidth: 0, containerHeight: 0, cssWidth: 0, cssHeight: 0, ratio: 2}, lifecycleRender, lifecycleVerify);
assert(lifecycleOutcome.retry && !lifecycleOutcome.reveal && lifecycleRenders === 0, 'Historical graph must begin loading without exposing a render when layout is unusable');
lifecycleOutcome = actualLifecycle.step({containerWidth: 650, containerHeight: 310, cssWidth: 600, cssHeight: 220, ratio: 2}, lifecycleRender, lifecycleVerify);
assert(lifecycleOutcome.retry && !lifecycleOutcome.reveal && lifecycleRenders === 0, 'The first usable dimensions must be treated as unsettled');
lifecycleOutcome = actualLifecycle.step({containerWidth: 650, containerHeight: 310, cssWidth: 600, cssHeight: 220, ratio: 2}, lifecycleRender, lifecycleVerify);
assert(lifecycleOutcome.retry && !lifecycleOutcome.reveal && lifecycleRenders === 1 && renderedSeriesId === 'latest' && suppliedDomain.minTs === 1735689600, 'Stable dimensions must render the latest data with its explicit report-window domain while retaining Loading');
lifecycleOutcome = actualLifecycle.step({containerWidth: 650, containerHeight: 310, cssWidth: 600, cssHeight: 220, ratio: 2}, lifecycleRender, lifecycleVerify);
assert(!lifecycleOutcome.retry && lifecycleOutcome.reveal && lifecycleRenders === 1, 'Loading must clear only after another stable frame verifies backing dimensions, ownership, data, and domain');

const staleLifecycle = boundedScheduler.window.CCHistoricalGraphLifecycle(function () { return currentHistoricalToken === 'old'; });
let staleRendered = false;
const staleOutcome = staleLifecycle.step({containerWidth: 650, containerHeight: 310, cssWidth: 600, cssHeight: 220, ratio: 2}, function () { staleRendered = true; }, function () { return true; });
assert(!staleOutcome.retry && !staleOutcome.reveal && !staleRendered, 'A newer Historical series must supersede an older settling lifecycle before it can render');

let malformedVisible = false;
const exhaustedLifecycle = boundedScheduler.window.CCHistoricalGraphLifecycle(function () { return true; });
const exhausted = loadHistoricalScheduler(true, 2);
exhausted.scheduler.schedule(function () {
	const outcome = exhaustedLifecycle.step({containerWidth: 0, containerHeight: 0, cssWidth: 0, cssHeight: 0, ratio: 2}, function () { malformedVisible = true; }, function () { return false; });
	if (outcome.reveal) malformedVisible = true;
	return outcome.retry;
});
while (exhausted.frames.length) exhausted.frames.shift()();
assert(!malformedVisible, 'Exhausting bounded retries must leave the malformed graph hidden in Loading state');
const recoveredLifecycle = boundedScheduler.window.CCHistoricalGraphLifecycle(function () { return true; });
const recoveredMeasurement = {containerWidth: 650, containerHeight: 310, cssWidth: 600, cssHeight: 220, ratio: 2};
recoveredLifecycle.step(recoveredMeasurement, lifecycleRender, lifecycleVerify);
recoveredLifecycle.step(recoveredMeasurement, lifecycleRender, lifecycleVerify);
const recoveredOutcome = recoveredLifecycle.step(recoveredMeasurement, lifecycleRender, lifecycleVerify);
assert(recoveredOutcome.reveal, 'ResizeObserver or secondary recovery must be able to restart settling and reveal the latest series');

const yearStart = 1735689600;
const yearEnd = 1767225599;
const domainCanvas = canvasStub(640, 220);
const domainChart = new Chart(domainCanvas);
domainChart.setData([{ts: yearStart + 3600, value: 1}, {ts: yearStart + 50000, value: 3}], 0, {minTs: yearStart, maxTs: yearEnd});
const yearBounds = domainChart.bounds();
assert(yearBounds.minTs === yearStart && yearBounds.maxTs === yearEnd, 'Historical chart domain must use the selected report window rather than sparse qualifying points');
assert(/2025/.test(Chart.formatAxisTimestamp(yearStart, yearEnd - yearStart)) && !/:/.test(Chart.formatAxisTimestamp(yearStart, yearEnd - yearStart)), 'A year-scale Historical axis must use calendar labels rather than same-day clock labels');

const fullscreenWall = {requestFullscreen: function () { return {catch: function () {}}; }};
const fullscreenDocument = {fullscreenElement: null};
const helperWindow = {_ccLiveLoaded: true, setTimeout: function () {}};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/live-view.js', 'utf8'), {window: helperWindow});
const fullscreen = helperWindow.CCLiveWallFullscreen;
assert(fullscreen.shouldShow(true, fullscreenWall, fullscreenDocument), 'Full Screen must show for an active supported wall outside browser fullscreen');
fullscreenDocument.fullscreenElement = fullscreenWall;
assert(!fullscreen.shouldShow(true, fullscreenWall, fullscreenDocument), 'Full Screen must hide while the wall is the document fullscreen element');
fullscreenDocument.fullscreenElement = null;
assert(fullscreen.shouldShow(true, fullscreenWall, fullscreenDocument), 'Full Screen must reappear after fullscreenchange reports no fullscreen element');
assert(!fullscreen.shouldShow(false, fullscreenWall, fullscreenDocument), 'Full Screen must remain scoped to active Live Wall presentation');
assert(!fullscreen.shouldShow(true, {}, fullscreenDocument), 'Unsupported browsers must not show Full Screen');
let requested = 0; let rejectionHandler = null; let settled = 0;
const rejectedWall = {requestFullscreen: function () { requested++; return {catch: function (handler) { rejectionHandler = handler; }}; }};
assert(fullscreen.request(rejectedWall, function () { settled++; }) && requested === 1, 'Explicit Full Screen must invoke requestFullscreen');
rejectionHandler();
assert(settled === 1 && fullscreen.shouldShow(true, rejectedWall, fullscreenDocument), 'A rejected request must keep Full Screen available for another user gesture');
const throwingWall = {requestFullscreen: function () { throw new Error('denied'); }};
assert(!fullscreen.request(throwingWall, function () { settled++; }) && settled === 2, 'Synchronous fullscreen exceptions must be contained and resynchronised');

const selection = helperWindow.CCLiveWallSelection;
const launch = helperWindow.CCLiveWallLaunch;
function selectionState(saved, inventory) { return selection.state(saved, inventory); }
assert(selectionState([], []).complete && selectionState([], []).required === 0, 'N=0 must permit Overall-only Live Wall');
assert(!selectionState([], ['one']).complete && selectionState(['one'], ['one']).complete, 'N=1 must require exactly one configured trunk');
assert(!selectionState(['one'], ['one', 'two']).complete && selectionState(['one', 'two'], ['one', 'two']).complete, 'N=2 must require exactly two configured trunks');
assert(selectionState(['one', 'two', 'three'], ['one', 'two', 'three']).complete, 'N=3 must require three configured trunks');
assert(!selectionState(['one', 'two'], ['one', 'two', 'three', 'four', 'five', 'six', 'seven']).complete, 'Two of seven trunks must remain incomplete');
const threeOfSeven = selectionState(['three', 'one', 'two'], ['one', 'two', 'three', 'four', 'five', 'six', 'seven']);
assert(threeOfSeven.complete && threeOfSeven.required === 3 && threeOfSeven.valid.join(',') === 'three,one,two', 'Three of seven must be complete and preserve saved order');
const deleted = selectionState(['three', 'one', 'two'], ['one', 'two', 'four', 'five', 'six', 'seven']);
assert(!deleted.complete && deleted.valid.join(',') === 'one,two', 'Deleting a selected trunk must not invent a substitute');
const added = selectionState(['three', 'one', 'two'], ['one', 'two', 'three', 'four']);
assert(added.complete && added.valid.join(',') === 'three,one,two', 'Adding a trunk must not change an already valid ordered selection');

const directLaunchEvents = [];
let finishRevalidation = null;
launch.start(threeOfSeven, {
	configure: function () { directLaunchEvents.push('configure'); },
	enter: function () { directLaunchEvents.push('enter'); },
	revalidate: function (done) { directLaunchEvents.push('revalidate'); finishRevalidation = done; },
	invalidate: function () { directLaunchEvents.push('invalidate'); }
});
assert(directLaunchEvents.join(',') === 'enter,revalidate', 'A valid launch must enter and request fullscreen before asynchronous revalidation starts');
finishRevalidation(selectionState(['three', 'one', 'two'], ['one', 'two', 'four', 'five']));
assert(directLaunchEvents.join(',') === 'enter,revalidate,invalidate', 'Authoritative revalidation must close an invalidated Live Wall without substituting a trunk');

const incompleteLaunchEvents = [];
launch.start(selectionState(['one', 'two'], ['one', 'two', 'three', 'four']), {
	configure: function () { incompleteLaunchEvents.push('configure'); },
	enter: function () { incompleteLaunchEvents.push('enter'); },
	revalidate: function () { incompleteLaunchEvents.push('revalidate'); },
	invalidate: function () { incompleteLaunchEvents.push('invalidate'); }
});
assert(incompleteLaunchEvents.join(',') === 'configure', 'An incomplete launch must open configuration without entering Live Wall');

const beforeBackendRace = selectionState(['one', 'two', 'three'], ['one', 'two', 'three', 'four']);
const afterBackendRace = selectionState(beforeBackendRace.valid, ['one', 'two', 'four', 'five']);
assert(beforeBackendRace.complete && !afterBackendRace.complete && afterBackendRace.valid.join(',') === 'one,two', 'Inventory changes between pre-save refresh and backend save must reconcile without substitution');
const recoveryEvents = [];
let completeInventoryRefresh = null;
helperWindow.CCLiveWallConfigurationRecovery.run(beforeBackendRace.valid, function (ready) {
	recoveryEvents.push('refresh');
	completeInventoryRefresh = ready;
}, function (reconciled) {
	recoveryEvents.push('render:' + reconciled.valid.join(',') + ':' + reconciled.inventoryCount);
});
assert(recoveryEvents.join(',') === 'refresh', 'A backend inventory rejection must not rerender before authoritative refresh completes');
completeInventoryRefresh(['one', 'two', 'four', 'five']);
assert(recoveryEvents.join(',') === 'refresh,render:one,two:4', 'The modal must rerender against refreshed inventory without selecting a replacement');

const failedPreflightEvents = [];
const failedPreflightDraft = ['one', 'two', 'three'];
let failPreflightRefresh = null;
helperWindow.CCLiveWallSavePreflight.run(function (ready, failed) {
	failedPreflightEvents.push('refresh');
	failPreflightRefresh = failed;
}, function () {
	failedPreflightEvents.push('save');
}, function (message) {
	failedPreflightEvents.push('enable:' + message);
});
failPreflightRefresh('inventory unavailable');
assert(failedPreflightEvents.join(',') === 'refresh,enable:inventory unavailable' && failedPreflightDraft.join(',') === 'one,two,three', 'A failed pre-save refresh must preserve the draft and restore retry state without attempting a save');

const failedRecoveryEvents = [];
const failedRecoveryDraft = ['one', 'two', 'three'];
const backendRejection = 'Choose exactly 3 currently configured trunks.';
let failRecoveryRefresh = null;
helperWindow.CCLiveWallConfigurationRecovery.run(failedRecoveryDraft, function (ready, failed) {
	failedRecoveryEvents.push('refresh');
	failRecoveryRefresh = failed;
}, function () {
	failedRecoveryEvents.push('render');
}, function () {
	failedRecoveryEvents.push('enable:' + backendRejection);
});
failRecoveryRefresh('inventory unavailable');
assert(failedRecoveryEvents.join(',') === 'refresh,enable:' + backendRejection && failedRecoveryDraft.join(',') === 'one,two,three', 'A failed post-rejection refresh must preserve the backend message and draft, restore retry state, and avoid rendering stale inventory');

console.log('Concurrency chart lifecycle tests passed');
