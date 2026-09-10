'use strict';

const exporter = require('../assets/js/historical-graph-export.js');
const generator = require('../assets/js/historical-svg-chart.js');
function assert(condition, message) { if (!condition) throw new Error(message); }

const start = 1735689600, end = 1767225599;
const specs = [
	{name: 'A', label: 'Trunk A', points: [{ts: start + 100, value: 5}, {ts: start + 161, value: null}], threshold: 5, exactPeak: 5},
	{name: 'B', label: 'Trunk B', points: [{ts: start + 500, value: 6}, {ts: start + 561, value: null}], threshold: 4, exactPeak: 6}
];
const chart = generator.multiModel(specs, {minTs: start, maxTs: end});
const metadata = {report: 'Historic Report 1', series: 'A-B', title: 'Historic Report 1 — 2 selected series', subtitle: '2 selected series'};
const generatedDocument = generator.documentFor(chart, metadata), original = JSON.stringify(chart.series);

assert(exporter.filename(metadata.report, metadata.series, 'svg') === 'Historic-Report-1-A-B.svg', 'Multi-series SVG filename is filesystem-safe');
const largeSelection = [];
for (let index = 1; index <= 125; index++) largeSelection.push('VERY-LONG-PJSIP-TRUNK-NAME-' + index);
assert(exporter.seriesComponent(['MAGRATHEA-IN-1']) === 'MAGRATHEA-IN-1' && exporter.seriesComponent(largeSelection) === '125-series', 'Single-series naming retains its name while a large selection becomes a bounded count');
const boundedName = exporter.filename('Historic Report 1', exporter.seriesComponent(largeSelection), 'jpeg');
assert(boundedName === 'Historic-Report-1-125-series.jpg' && boundedName.length < 64, 'Large multi-series exports use a short bounded count component');
assert(exporter.filename(metadata.report, metadata.series, 'pdf').endsWith('.pdf') && exporter.filename(metadata.report, metadata.series, 'png').endsWith('.png') && exporter.filename(metadata.report, metadata.series, 'jpeg').endsWith('.jpg'), 'Every export format receives the correct extension');
assert(exporter.svgDocument(generatedDocument) === generatedDocument, 'SVG export uses the exact authoritative image document');
assert(generatedDocument.indexOf('data-series="A"') !== -1 && generatedDocument.indexOf('data-series="B"') !== -1 && generatedDocument.indexOf('>Trunk A (threshold 5)</text>') !== -1 && generatedDocument.indexOf('>Trunk B (threshold 4)</text>') !== -1, 'SVG export contains all selected series, thresholds and legend entries');

const pdf = exporter.pdfDocument(generatedDocument, chart, metadata);
assert(pdf.indexOf('%PDF-1.4') === 0 && pdf.indexOf(' m ') !== -1 && pdf.indexOf(' l S') !== -1, 'PDF is a proper vector graph document');
assert(pdf.indexOf('Trunk A \\(threshold 5\\)') !== -1 && pdf.indexOf('Trunk B \\(threshold 4\\)') !== -1, 'PDF includes the same selected-series legend and threshold associations');
function pdfColour(hex) { const value = hex.slice(1); return [0, 2, 4].map(function (offset) { return (parseInt(value.slice(offset, offset + 2), 16) / 255).toFixed(3); }).join(' ') + ' RG'; }
const exportColours = generator.coloursForInventory(['A', 'B']);
assert(pdf.indexOf(pdfColour(exportColours.A)) !== -1 && pdf.indexOf(pdfColour(exportColours.B)) !== -1, 'PDF retains both deterministic inventory-assigned series colours');
assert(pdf.indexOf('cc-historical-export') === -1 && pdf.indexOf('FreePBX') === -1, 'PDF excludes surrounding page controls');
assert(JSON.stringify(chart.series) === original, 'Exports do not mutate real timestamps or display geometry');

const source = require('fs').readFileSync(require('path').join(__dirname, '../assets/js/historical-graph-export.js'), 'utf8');
assert(source.indexOf("document.createElement('canvas')") !== -1 && source.indexOf('* 2') !== -1, 'PNG and JPEG rasterise the authoritative SVG only during export at two-times resolution');
assert(source.indexOf("context.fillStyle = '#ffffff'") !== -1 && source.indexOf("format === 'jpeg' ? 'image/jpeg' : 'image/png'") !== -1, 'JPEG uses an opaque white background and the shared SVG rasterisation path');

console.log('Historical multi-series graph export tests passed');
