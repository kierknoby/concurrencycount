'use strict';

const svg = require('../assets/js/historical-svg-chart.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
function close(actual, expected, message) { if (Math.abs(actual - expected) > 0.000001) throw new Error(message + ': expected ' + expected + ', got ' + actual); }

const yearStart = 1735689600, yearEnd = 1767225599;
const shortPoints = [
	{ts: yearStart + 100, value: 5},
	{ts: yearStart + 130, value: 6},
	{ts: yearStart + 145, value: 5},
	{ts: yearStart + 161, value: null},
	{ts: yearStart + 500, value: 5},
	{ts: yearStart + 561, value: null}
];
const chart = svg.model(shortPoints, 5, {minTs: yearStart, maxTs: yearEnd}, 6);
assert(svg.width === 1600 && svg.height === 300 && svg.minimumRunWidth === 24, 'Finished image uses central fixed logical dimensions and minimum run width');
assert(chart.minTs === yearStart && chart.maxTs === yearEnd && chart.ticks[0].ts === yearStart && chart.ticks[4].ts === yearEnd, 'Jan-Dec axis uses the exact selected report-domain boundaries');
assert(chart.exactPeak === 6 && chart.threshold === 5 && chart.maxValue === 6, 'Exact peak and threshold retain their calculation values');
assert(chart.runs.length === 2 && chart.runs[0].realStartTs === yearStart + 100 && chart.runs[0].realEndTs === yearStart + 161 && chart.runs[0].realDuration === 61, 'A null boundary records the exact real start, end and duration of a short run');
assert(chart.runs[1].realStartTs === yearStart + 500 && chart.runs[1].realEndTs === yearStart + 561, 'Two separate null-bounded runs retain separate real metadata');
assert(chart.runs[0].widened && chart.runs[0].displayEndX - chart.runs[0].displayStartX === 24, 'A year-scale 60-second run receives a recognisable fixed-domain display plateau');
assert(chart.runs[1].displayStartX > chart.runs[0].displayEndX, 'Nearby short runs are deliberately laid out as separate display shapes');
assert(chart.runs[0].displayPath.indexOf('M ' + chart.runs[0].displayStartX + ' ' + chart.plot.bottom + ' V ') === 0 && chart.runs[0].displayPath.endsWith('H ' + chart.runs[0].displayEndX + ' V ' + chart.plot.bottom), 'Short display geometry paints complete entry, plateau and exit shape rather than a line or blob');
assert((chart.runs[0].displayPath.match(/ H /g) || []).length === 3 && (chart.runs[0].displayPath.match(/ V /g) || []).length === 4, 'Multiple real concurrency transitions retain horizontal and vertical step geometry');
assert(chart.runs[0].realPath.indexOf(String(chart.x(yearStart + 100))) !== -1 && chart.runs[0].realPath.indexOf(String(chart.x(yearStart + 161))) !== -1, 'Real geometry keeps exact event and null-boundary positions independently of display widening');

const wide = svg.model([{ts: 100, value: 5}, {ts: 300, value: 6}, {ts: 700, value: null}], 5, {minTs: 0, maxTs: 1000}, 6);
assert(!wide.runs[0].widened && wide.runs[0].displayStartX === wide.runs[0].realStartX && wide.runs[0].displayEndX === wide.runs[0].realEndX && wide.runs[0].displayPath === wide.runs[0].realPath, 'A naturally wide run uses its exact true span without artificial widening');

const documentText = svg.documentFor(chart, {title: 'Historic Report 1 — Trunk A', subtitle: 'Exact floor-relevant CDR event transitions'});
assert(documentText.indexOf('<svg xmlns=') !== -1 && documentText.indexOf('width="1600" height="300" viewBox="0 0 1600 300"') !== -1, 'Generator returns one complete self-contained fixed-size SVG document');
assert(documentText.indexOf('data-real-start-ts="' + (yearStart + 100) + '" data-real-end-ts="' + (yearStart + 161) + '" data-real-duration="61" data-display-widened="true"') !== -1, 'Generated display path carries unchanged real timestamp metadata');
assert(documentText.indexOf('cc-historical-svg-short-run') === -1 && documentText.indexOf('<rect class="marker"') === -1 && documentText.indexOf('class="series" d="' + chart.runs[0].displayPath + '"') !== -1, 'Minimum width is painted by the complete series path, without a pill/blob marker layer');
assert(documentText.indexOf(chart.runs[0].displayPath) !== -1 && documentText.indexOf(chart.runs[1].displayPath) !== -1, 'Finished SVG contains both distinct short-run graph shapes');

let createdDocument = '', revoked = '', selectedPoint = null;
const image = {src: '', alt: '', removeAttribute: function (name) { if (name === 'src') this.src = ''; }};
const tooltip = {style: {}, textContent: ''};
const listeners = {};
const overlay = {addEventListener: function (name, fn) { listeners[name] = fn; }, removeEventListener: function (name) { delete listeners[name]; }, getBoundingClientRect: function () { return {left: 100, width: 800, height: 150}; }};
const rendered = new svg.HistoricalSvgChart(image, overlay, tooltip, {
	onSelect: function (point) { selectedPoint = point; },
	createImageUrl: function (text) { createdDocument = text; return 'blob:finished-historical-image'; },
	revokeImageUrl: function (url) { revoked = url; }
});
rendered.setData(shortPoints, 5, {minTs: yearStart, maxTs: yearEnd}, 6, {title: 'Report — Trunk A', subtitle: 'Exact events'});
assert(image.src === 'blob:finished-historical-image' && createdDocument === rendered.svgDocument && createdDocument.indexOf('<svg') !== -1, 'Historical display installs the finished generated SVG document as an image Blob URL');
assert(typeof listeners.mousemove === 'function' && typeof listeners.click === 'function' && createdDocument.indexOf('getBoundingClientRect') === -1, 'Interaction is an external overlay and the generated document has no DOM measurement dependency');

const firstRunDisplayMid = (rendered.chart.runs[0].displayStartX + rendered.chart.runs[0].displayEndX) / 2;
const firstRunClientX = 100 + ((firstRunDisplayMid / svg.width) * 800);
const mappedTimestamp = rendered.pointerTimestamp({clientX: firstRunClientX});
close(mappedTimestamp, rendered.chart.runs[0].realMidTs, 'Pointer mapping inverts display widening to the real run midpoint');
rendered.onPointer({clientX: firstRunClientX}); rendered.onClick({clientX: firstRunClientX});
assert(tooltip.textContent && selectedPoint && selectedPoint.ts >= yearStart + 100 && selectedPoint.ts <= yearStart + 145, 'Tooltip and click resolve to a real underlying run event');
overlay.getBoundingClientRect = function () { return {left: 20, width: 1600, height: 300}; };
close(rendered.pointerTimestamp({clientX: 20 + firstRunDisplayMid}), rendered.chart.runs[0].realMidTs, 'Overlay maps to the same real timestamp at a different displayed width');
rendered.destroy();
assert(revoked === 'blob:finished-historical-image' && image.src === '', 'Destroying a graph revokes and removes the prior finished image');

const requestedResult = {id: 'old'};
assert(svg.isCurrentResult(requestedResult, requestedResult) && !svg.isCurrentResult(null, requestedResult) && !svg.isCurrentResult({id: 'new'}, requestedResult), 'A late Historical response cannot repaint a cleared or replaced image');
assert(require('fs').readFileSync(require('path').join(__dirname, '../assets/js/historical-svg-chart.js'), 'utf8').indexOf('getBoundingClientRect') > require('fs').readFileSync(require('path').join(__dirname, '../assets/js/historical-svg-chart.js'), 'utf8').indexOf('pointerTimestamp'), 'DOM measurement exists only in interaction-time pointer mapping');

console.log('Historical SVG image tests passed');
