'use strict';

const svg = require('../assets/js/historical-svg-chart.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
function close(actual, expected, message) { if (Math.abs(actual - expected) > 0.000001) throw new Error(message + ': expected ' + expected + ', got ' + actual); }

const yearStart = 1735689600;
const yearEnd = 1767225599;
const exact = svg.model([
	{ts: yearStart + 100, value: 2},
	{ts: yearStart + 160, value: 5},
	{ts: yearStart + 220, value: 3},
	{ts: yearStart + 280, value: null},
	{ts: yearStart + 500, value: 4},
	{ts: yearStart + 561, value: null}
], 4, {minTs: yearStart, maxTs: yearEnd}, 5);
assert(exact.minTs === yearStart && exact.maxTs === yearEnd, 'Selected start_ts/end_ts define the SVG X domain');
assert(exact.paths.length === 2, 'Null floor gaps create separate SVG path segments');
assert((exact.paths[0].match(/H /g) || []).length >= 3 && (exact.paths[0].match(/V /g) || []).length === 2, 'Exact events generate horizontal-then-vertical step geometry');
close(exact.x(yearStart + 561) - exact.x(yearStart + 500), (61 / (yearEnd - yearStart)) * (exact.plot.right - exact.plot.left), 'A 60-second inclusive interval keeps its real 61-second boundary relationship');
assert(exact.paths[1].endsWith('H ' + exact.x(yearStart + 561)), 'A null boundary terminates its visible run at the real timestamp');
assert(exact.ticks[0].ts === yearStart && exact.ticks[4].ts === yearEnd, 'First and last tick labels belong to the explicit report domain');
assert(exact.ticks[0].label === svg.formatAxisTimestamp(yearStart, yearEnd - yearStart) && exact.ticks[4].label === svg.formatAxisTimestamp(yearEnd, yearEnd - yearStart) && /2025/.test(exact.ticks[0].label) && /2025/.test(exact.ticks[4].label), 'Long-domain first and last labels use the exact range boundaries and calendar formatting');
assert(exact.x(yearStart + 561) < exact.plot.left + 1, 'Activity concentrated near the start of a long domain remains mathematically concentrated there');
close(exact.thresholdY, exact.plot.bottom - ((4 / 5) * (exact.plot.bottom - exact.plot.top)), 'Threshold Y position is deterministic from threshold and exact peak');

const openEnded = svg.model([{ts: yearStart + 1000, value: 3}], 0, {minTs: yearStart, maxTs: yearEnd}, 3);
assert(openEnded.paths[0].endsWith('H ' + openEnded.plot.right), 'A final visible state explicitly extends to the report-domain end without requiring a duplicate range-end point');

const alternate = svg.model([{ts: yearStart + 2000, value: 7}, {ts: yearStart + 2061, value: null}], 0, {minTs: yearStart, maxTs: yearEnd}, 7);
assert(alternate.paths.length === 1 && alternate.minTs === exact.minTs && alternate.maxTs === exact.maxTs, 'Changing series is an immediate deterministic geometry replacement with no resize lifecycle');
const attributes = {};
const svgElement = {setAttribute: function (name, value) { attributes[name] = value; }, removeAttribute: function (name) { delete attributes[name]; }, addEventListener: function () {}, removeEventListener: function () {}, innerHTML: '', getBoundingClientRect: function () { return {left: 20, width: 1200}; }};
const responsiveChart = new svg.HistoricalSvgChart(svgElement);
responsiveChart.chart = exact;
assert(attributes.width === '100%' && attributes.height === '220' && !attributes.viewBox && svg.markup(exact).indexOf('class="cc-historical-svg-plot"') !== -1, 'Responsive Historical outer SVG uses full width while only its text-free plot geometry stretches');
const midpointRatio = 0.05 + (0.935 / 2);
const wideLogicalX = responsiveChart.pointerX({clientX: 20 + (1200 * midpointRatio)});
svgElement.getBoundingClientRect = function () { return {left: 7, width: 600}; };
const narrowLogicalX = responsiveChart.pointerX({clientX: 7 + (600 * midpointRatio)});
close(wideLogicalX, (exact.plot.left + exact.plot.right) / 2, 'Wide responsive pointer maps to the plot midpoint');
close(narrowLogicalX, wideLogicalX, 'Pointer X maps to the same logical timestamp at a different rendered width');
assert(svg.markup(exact).indexOf('<svg class="cc-historical-svg-plot"') !== -1 && svg.markup(exact).indexOf('<text class="cc-historical-svg-label"') > svg.markup(exact).indexOf('</svg>'), 'Labels remain outside the horizontally stretchable plot coordinate system');
assert(svg.markup(exact).indexOf('x="5%" y="10" width="93.5%" height="172"') !== -1, 'Nested plot retains its authoritative 5% left, 93.5% width and 172px height');
const interactionModel = svg.model([{ts: 25, value: 2}, {ts: 75, value: 4}], 0, {minTs: 0, maxTs: 100}, 4);
const tooltipText = {textContent: ''};
const tooltip = {style: {}, transform: '', setAttribute: function (name, value) { if (name === 'transform') this.transform = value; }, querySelector: function () { return tooltipText; }};
let selectedPoint = null;
const interactionElement = {setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {}, removeEventListener: function () {}, innerHTML: '', getBoundingClientRect: function () { return {left: 100, width: 800}; }, querySelector: function () { return tooltip; }};
const interactionChart = new svg.HistoricalSvgChart(interactionElement, {onSelect: function (point) { selectedPoint = point; }});
interactionChart.chart = interactionModel;
const secondPointClientX = 100 + (800 * (0.05 + (0.935 * 0.75)));
interactionChart.onPointer({clientX: secondPointClientX});
interactionChart.onClick({clientX: secondPointClientX});
assert(tooltipText.textContent.indexOf('  4') !== -1 && selectedPoint && selectedPoint.ts === 75, 'Tooltip and click selection share the corrected responsive pointer mapping');
assert(svg.describe(exact).indexOf('4 visible points') !== -1 && svg.describe(exact).indexOf('peak 5') !== -1, 'Accessible description reports visible points and the exact peak');
const requestedResult = {id: 'old'};
assert(svg.isCurrentResult(requestedResult, requestedResult) && !svg.isCurrentResult(null, requestedResult) && !svg.isCurrentResult({id: 'new'}, requestedResult), 'A late Historical AJAX result cannot repaint a cleared or replaced result');

console.log('Historical SVG chart tests passed');
