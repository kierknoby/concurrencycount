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

assert(typeof Chart === 'function', 'Live View and Live Wall retain the existing Canvas renderer');

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
