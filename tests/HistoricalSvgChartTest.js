'use strict';

const svg = require('../assets/js/historical-svg-chart.js');
function assert(condition, message) { if (!condition) throw new Error(message); }
function close(actual, expected, message) { if (Math.abs(actual - expected) > 0.000001) throw new Error(message + ': expected ' + expected + ', got ' + actual); }

const yearStart = 1735689600, yearEnd = 1767225599;
assert(JSON.stringify(svg.selection.initial(['A', 'B', 'C'])) === JSON.stringify(['A', 'B', 'C']), 'Initial selection contains every available series');
let selected = svg.selection.toggle(['A'], 'B');
assert(JSON.stringify(selected) === JSON.stringify(['A', 'B']), 'Selecting a second series retains the first');
selected = svg.selection.toggle(selected, 'A');
assert(JSON.stringify(selected) === JSON.stringify(['B']), 'Deselecting one series retains the others');
assert(JSON.stringify(svg.selection.all(['A', 'B', 'C'])) === JSON.stringify(['A', 'B', 'C']), 'Select All selects every available series without a limit');
const initialButtons = svg.selection.presentation(['A', 'B', 'C'], svg.selection.initial(['A', 'B', 'C']));
assert(initialButtons.selectAllActive && !initialButtons.unselectAllActive && initialButtons.selectAllClass === 'cc-series-active' && initialButtons.unselectAllClass === 'cc-series-neutral' && Object.keys(initialButtons.series).every(function (name) { return initialButtons.series[name].buttonClass === 'cc-series-active' && initialButtons.series[name].ariaPressed === 'true'; }), 'All-selected state visibly activates Select All and every series while Unselect All is neutral');
const emptyButtons = svg.selection.presentation(['A', 'B', 'C'], []);
assert(!emptyButtons.selectAllActive && emptyButtons.unselectAllActive, 'No-series state activates Unselect All only');
assert(emptyButtons.selectAllClass === 'cc-series-neutral' && emptyButtons.unselectAllClass === 'cc-series-active' && Object.keys(emptyButtons.series).every(function (name) { return emptyButtons.series[name].buttonClass === 'cc-series-neutral' && emptyButtons.series[name].ariaPressed === 'false'; }), 'No-selected state visibly activates Unselect All and leaves Select All and every series neutral');
const individualButtons = svg.selection.presentation(['A', 'B', 'C'], ['B']);
assert(!individualButtons.selectAllActive && !individualButtons.unselectAllActive, 'Partial selection leaves both aggregate controls neutral');
assert(individualButtons.selectAllClass === 'cc-series-neutral' && individualButtons.unselectAllClass === 'cc-series-neutral' && individualButtons.series.A.buttonClass === 'cc-series-neutral' && individualButtons.series.B.buttonClass === 'cc-series-active' && individualButtons.series.C.buttonClass === 'cc-series-neutral', 'Partial selection leaves both aggregate controls neutral and visibly activates only selected series');
const largeNames = ['20260827', 'InterVoIP', 'MAGRATHEA-IN-1', 'MAGRATHEA-IN-2', 'MAGRATHEA-IN-3', 'MAGRATHEA-IN-4', 'MAGRATHEA-IN-5', 'MAGRATHEA-OUT', 'SBCSRV1-MAN', 'SBCSRV2-LON', 'SBCSRV3-MAN', 'SBCSRV4-LON', 'ST22017T002', 'fpbx-1-d92ylejHZE3q', 'fpbx-2-d92ylejHZE3q'];
const inventoryColours = svg.coloursForInventory(largeNames), largeColours = largeNames.map(function (name) { return inventoryColours[name]; });
assert(new Set(largeColours).size === 15 && largeColours.every(function (colour) { return /^#[0-9A-F]{6}$/.test(colour); }), 'The actual 15-series PBX inventory receives 15 distinct six-digit colours');
assert(inventoryColours['20260827'] === '#1B61A7' && inventoryColours['MAGRATHEA-IN-1'] === '#CE1212' && inventoryColours['MAGRATHEA-IN-5'] === '#8C1752', 'Normal 15-series inventory retains the established perceptual farthest-point allocation');
function colourDistance(left, right) { return svg.perceptualDistance(svg.labForHex(left), svg.labForHex(right)); }
const sortedLargeNames = largeNames.slice().sort();
for (let earlyIndex = 1; earlyIndex < 6; earlyIndex++) { let minimumDistance = Infinity; for (let priorIndex = 0; priorIndex < earlyIndex; priorIndex++) minimumDistance = Math.min(minimumDistance, colourDistance(inventoryColours[sortedLargeNames[earlyIndex]], inventoryColours[sortedLargeNames[priorIndex]])); assert(minimumDistance > 60, 'Each early farthest-point colour must retain meaningful perceptual separation'); }
['MAGRATHEA-IN-1', 'MAGRATHEA-IN-3', 'MAGRATHEA-IN-5'].forEach(function (name, index, group) { group.slice(index + 1).forEach(function (other) { assert(colourDistance(inventoryColours[name], inventoryColours[other]) > 45, name + ' and ' + other + ' must be perceptually separated'); }); });
assert(colourDistance(inventoryColours['MAGRATHEA-IN-2'], inventoryColours['MAGRATHEA-IN-4']) > 45, 'The previously similar IN-2 and IN-4 colours must be perceptually separated');
assert(JSON.stringify(svg.coloursForInventory(largeNames)) === JSON.stringify(svg.coloursForInventory(largeNames.slice().reverse())), 'Colour allocation is deterministic for the same complete inventory regardless of input order');
const stableColour = inventoryColours['MAGRATHEA-IN-3'];
const subsetSpecs = ['MAGRATHEA-IN-1', 'MAGRATHEA-IN-3'].map(function (name) { return {name: name, color: inventoryColours[name], points: [], exactPeak: 0}; });
assert(svg.coloursForInventory(largeNames)['MAGRATHEA-IN-3'] === stableColour && svg.multiModel(subsetSpecs, {minTs: 0, maxTs: 1}).series[1].color === stableColour, 'Changing the selected subset does not change a series colour while the full inventory is unchanged');
const oversizedNames = [];
for (let oversizedIndex = 0; oversizedIndex < 700; oversizedIndex++) oversizedNames.push('OVERSIZED-PBX-SERIES-' + String(oversizedIndex).padStart(4, '0'));
const oversizedColours = svg.coloursForInventory(oversizedNames);
assert(oversizedNames.every(function (name) { return typeof oversizedColours[name] === 'string' && /^#[0-9A-F]{6}$/.test(oversizedColours[name]); }), 'Inventories beyond the perceptual candidate pool still assign every series a valid colour');
assert(JSON.stringify(oversizedColours) === JSON.stringify(svg.coloursForInventory(oversizedNames.slice().reverse())), 'Oversized-inventory fallback allocation is deterministic for the same complete inventory');

const shortA = [{ts: yearStart + 100, value: 5}, {ts: yearStart + 130, value: 6}, {ts: yearStart + 161, value: null}, {ts: yearStart + 500, value: 5}, {ts: yearStart + 561, value: null}];
const shortB = [{ts: yearStart + 8640000, value: 4}, {ts: yearStart + 8640061, value: null}];
const multi = svg.multiModel([
	{name: 'A', label: 'Trunk A', points: shortA, threshold: 5, exactPeak: 6},
	{name: 'B', label: 'Trunk B', points: shortB, threshold: 4, exactPeak: 4}
], {minTs: yearStart, maxTs: yearEnd});
assert(multi.width === 1600 && multi.height > svg.baseHeight && svg.minimumRunWidth === 24, 'Multi-series image uses deterministic fixed logical geometry and legend space');
assert(multi.minTs === yearStart && multi.maxTs === yearEnd && multi.ticks[0].ts === yearStart && multi.ticks[4].ts === yearEnd, 'Every series shares the exact Jan-Dec report domain');
assert(multi.maxValue === 6 && multi.exactPeak === 6 && multi.series[0].thresholdY !== null && multi.series[1].thresholdY !== null, 'Shared Y scale preserves exact peaks and independent per-series thresholds');
assert(multi.series[0].runs.length === 2 && multi.series[0].runs[0].realStartTs === yearStart + 100 && multi.series[0].runs[0].realEndTs === yearStart + 161, 'Real timestamps and null-bounded gaps remain unchanged');
assert(multi.series[0].runs[0].widened && multi.series[0].runs[0].displayEndX - multi.series[0].runs[0].displayStartX === 24, 'Year-scale short run retains minimum-width full step geometry');
assert(multi.series[0].runs[0].displayPath.indexOf(' V ') !== -1 && (multi.series[0].runs[0].displayPath.match(/ H /g) || []).length >= 2, 'Short runs retain complete horizontal and vertical transitions');
assert(multi.series[0].runs[1].displayStartX > multi.series[0].runs[0].displayEndX, 'Two distinct short runs remain separate');

const wide = svg.multiModel([{name: 'Wide', points: [{ts: 100, value: 5}, {ts: 300, value: 6}, {ts: 700, value: null}], exactPeak: 6}], {minTs: 0, maxTs: 1000});
assert(!wide.series[0].runs[0].widened && wide.series[0].runs[0].displayPath === wide.series[0].runs[0].realPath, 'Naturally wide runs use their exact span without widening');

const documentText = svg.documentFor(multi, {title: 'Historic Report — selected series', subtitle: '2 selected series'});
assert(documentText.indexOf('data-series="A"') !== -1 && documentText.indexOf('data-series="B"') !== -1, 'One generated SVG contains every selected series');
const twoColours = svg.coloursForInventory(['A', 'B']);
assert(documentText.indexOf('stroke="' + twoColours.A + '"') !== -1 && documentText.indexOf('stroke="' + twoColours.B + '"') !== -1, 'Selected series receive distinguishable deterministic colours');
assert(documentText.indexOf('>Trunk A (threshold 5)</text>') !== -1 && documentText.indexOf('>Trunk B (threshold 4)</text>') !== -1, 'Legend identifies each series and associates its threshold');
const onlyA = svg.documentFor(svg.multiModel([{name: 'A', label: 'Trunk A', points: shortA, exactPeak: 6}], {minTs: yearStart, maxTs: yearEnd}), {title: 'Only A'});
assert(onlyA.indexOf('data-series="A"') !== -1 && onlyA.indexOf('data-series="B"') === -1, 'Generated SVG excludes unselected series');

let createdDocuments = [], revoked = [], clicked = null;
const image = {src: '', alt: '', removeAttribute: function () { this.src = ''; }};
const tooltip = {style: {}, textContent: ''}, listeners = {};
const overlay = {addEventListener: function (name, fn) { listeners[name] = fn; }, removeEventListener: function (name) { delete listeners[name]; }, getBoundingClientRect: function () { return {left: 100, top: 20, width: 800, height: multi.height / 2}; }};
const rendered = new svg.HistoricalSvgChart(image, overlay, tooltip, {onSelect: function (name, point) { clicked = {name: name, point: point}; }, createImageUrl: function (text) { createdDocuments.push(text); return 'blob:image-' + createdDocuments.length; }, revokeImageUrl: function (url) { revoked.push(url); }});
const specs = [{name: 'A', label: 'Trunk A', points: shortA, threshold: 5, exactPeak: 6}, {name: 'B', label: 'Trunk B', points: shortB, threshold: 4, exactPeak: 4}];
rendered.setSeries(specs, {minTs: yearStart, maxTs: yearEnd}, {title: 'Report', subtitle: 'Two series'});
assert(image.src === 'blob:image-1' && rendered.svgDocument === createdDocuments[0], 'One authoritative finished SVG document is installed as one image');
const run = rendered.chart.series[1].runs[0], logicalX = (run.displayStartX + run.displayEndX) / 2, logicalY = rendered.chart.plot.bottom - ((4 / rendered.chart.maxValue) * (rendered.chart.plot.bottom - rendered.chart.plot.top));
const event = {clientX: 100 + ((logicalX / rendered.chart.width) * 800), clientY: 20 + ((logicalY / rendered.chart.height) * (rendered.chart.height / 2))};
rendered.onPointer(event); rendered.onClick(event);
assert(tooltip.textContent.indexOf('Trunk B · ') === 0 && clicked && clicked.name === 'B' && clicked.point.ts === yearStart + 8640000, 'Nearest pointer and click identify the correct series and real source point');
assert(rendered.candidate(event) && rendered.candidate(event).point.value === 4, 'A non-zero rendered mark within the hit radius returns a candidate');
const blankEvent = {clientX: event.clientX, clientY: 20 + ((rendered.chart.plot.top / rendered.chart.height) * (rendered.chart.height / 2))};
const selectedBeforeBlankClick = clicked;
rendered.onPointer(blankEvent); rendered.onClick(blankEvent);
assert(rendered.candidate(blankEvent) === null && tooltip.style.display === 'none' && clicked === selectedBeforeBlankClick, 'Moving into blank graph space clears hover and clicking there does not select');
rendered.setSeries([{name:'Zero', label:'Zero', points:[{ts:yearStart + 100, value:0}, {ts:yearStart + 200, value:null}], exactPeak:0}], {minTs:yearStart, maxTs:yearEnd}, {title:'Zero'});
const zeroPoint = rendered.chart.series[0].visible[0];
const zeroEvent = {clientX:100 + ((zeroPoint.x / rendered.chart.width) * 800), clientY:20 + ((zeroPoint.y / rendered.chart.height) * (rendered.chart.height / 2))};
assert(rendered.candidate(zeroEvent) === null, 'A rendered zero-value point never returns a hit candidate');
rendered.setSeries([specs[0]], {minTs:yearStart,maxTs:yearEnd}, {title:'Gap'});
const gapX=(rendered.chart.series[0].runs[0].displayEndX+rendered.chart.series[0].runs[1].displayStartX)/2;
const gapY=rendered.chart.series[0].visible[0].y;
const gapEvent={clientX:100+((gapX/rendered.chart.width)*800),clientY:20+((gapY/rendered.chart.height)*(rendered.chart.height/2))};
assert(rendered.candidate(gapEvent)===null,'A null-bounded gap has no invisible timestamp hit region');
rendered.setSeries([{name:'Under',label:'Under',points:[{ts:yearStart+100,value:4},{ts:yearStart+200,value:null}],exactPeak:4},{name:'Top',label:'Top',points:[{ts:yearStart+100,value:4},{ts:yearStart+200,value:null}],exactPeak:4}],{minTs:yearStart,maxTs:yearEnd},{title:'Overlap'});
const topMark=rendered.chart.series[1].visible[0];
const overlapEvent={clientX:100+((topMark.x/rendered.chart.width)*800),clientY:20+((topMark.y/rendered.chart.height)*(rendered.chart.height/2))};
assert(rendered.candidate(overlapEvent).seriesName==='Top','Overlapping rendered marks resolve to the topmost rendered series');
rendered.setSeries([specs[0]], {minTs: yearStart, maxTs: yearEnd}, {title: 'Report', subtitle: 'One series'});
assert(image.src === 'blob:image-5' && revoked[0] === 'blob:image-1', 'Selection redraw revokes superseded image URLs');
rendered.destroy();
assert(revoked[4] === 'blob:image-5' && image.src === '', 'Destroy clears the finished image and revokes its URL');

const source = require('fs').readFileSync(require('path').join(__dirname, '../assets/js/historical-svg-chart.js'), 'utf8');
assert(source.indexOf('getBoundingClientRect') > source.indexOf('pointerCoordinates') && source.indexOf('ResizeObserver') === -1 && source.indexOf('requestAnimationFrame') === -1 && source.indexOf('devicePixelRatio') === -1, 'Generation has no DOM width, resize, RAF or DPR dependency');
const requested = {id: 'old'};
assert(svg.isCurrentResult(requested, requested) && !svg.isCurrentResult(null, requested), 'Stale response guard remains intact');

console.log('Historical multi-series SVG image tests passed');
