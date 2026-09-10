'use strict';

const exporter = require('../assets/js/historical-graph-export.js');
const svgRenderer = require('../assets/js/historical-svg-chart.js');
function assert(condition, message) { if (!condition) throw new Error(message); }

const start = 1735689600, end = 1767225599;
const points = [{ts: start + 100, value: 5}, {ts: start + 161, value: null}, {ts: start + 500, value: 6}, {ts: start + 561, value: null}];
const chart = svgRenderer.model(points, 5, {minTs: start, maxTs: end}, 6);
chart.markers = svgRenderer.markersForWidth(chart, 1000, 7);
const originalPoints = JSON.stringify(points), originalChart = JSON.stringify(chart.paths);
const graphMarkup = '<svg id="cc-historical-chart">' + svgRenderer.markup(chart) + '</svg>';
const metadata = {report: 'Historic Report 1', series: 'MAGRATHEA/IN 1', title: 'Historic Report 1 — MAGRATHEA/IN 1', subtitle: 'Exact floor-relevant CDR event transitions'};

assert(exporter.filename(metadata.report, metadata.series, 'svg') === 'Historic-Report-1-MAGRATHEA-IN-1.svg', 'SVG filename is filesystem-safe and format appropriate');
assert(exporter.filename(metadata.report, metadata.series, 'pdf').endsWith('.pdf') && exporter.filename(metadata.report, metadata.series, 'png').endsWith('.png') && exporter.filename(metadata.report, metadata.series, 'jpeg').endsWith('.jpg'), 'Every export format receives the correct extension');
const exportedSvg = exporter.svgDocument(graphMarkup, metadata);
assert(exportedSvg.indexOf('<path class="cc-historical-svg-series"') !== -1 && exportedSvg.indexOf('<style>') !== -1, 'SVG export remains self-contained vector text and paths');
assert((exportedSvg.match(/class="cc-historical-svg-short-run"/g) || []).length === 2 && exportedSvg.indexOf('data-start-ts="' + (start + 100) + '" data-end-ts="' + (start + 161) + '"') !== -1, 'SVG export retains separate visual markers with exact real run metadata');
assert(exportedSvg.indexOf('preserveAspectRatio="xMidYMid meet"') !== -1, 'Standalone SVG export preserves normally proportioned text when resized');
assert(exportedSvg.indexOf(metadata.title.replace('—', '—')) !== -1 && exportedSvg.indexOf(metadata.subtitle) !== -1, 'SVG export identifies the selected report series and resolution');
assert(exportedSvg.indexOf('<g class="cc-historical-svg-tooltip"') === -1 && exportedSvg.indexOf('>tooltip<') === -1, 'Transient pointer UI is excluded from the exported graph');
const pdf = exporter.pdfDocument(chart, metadata);
assert(pdf.indexOf('%PDF-1.4') === 0 && pdf.indexOf(' m ') !== -1 && pdf.indexOf(' l S') !== -1, 'PDF is a proper vector document containing graph path operators');
assert((pdf.match(/ 6 re f/g) || []).length === 2, 'PDF vector output includes each short-run visual marker');
assert(pdf.indexOf('Historic Report 1 - MAGRATHEA/IN 1') !== -1 && pdf.indexOf('Historic Report 1 ? MAGRATHEA/IN 1') === -1, 'PDF transliterates the generated em dash to a readable ASCII hyphen');
assert(pdf.indexOf('cc-historical-export') === -1 && pdf.indexOf('FreePBX') === -1, 'PDF contains the graph only, without surrounding page controls');
assert(JSON.stringify(points) === originalPoints && JSON.stringify(chart.paths) === originalChart, 'Export generation does not mutate Historical timestamps or graph geometry');

console.log('Historical graph export tests passed');
