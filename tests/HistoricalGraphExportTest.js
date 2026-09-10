'use strict';

const exporter = require('../assets/js/historical-graph-export.js');
const generator = require('../assets/js/historical-svg-chart.js');
function assert(condition, message) { if (!condition) throw new Error(message); }

const start = 1735689600, end = 1767225599;
const points = [{ts: start + 100, value: 5}, {ts: start + 161, value: null}, {ts: start + 500, value: 6}, {ts: start + 561, value: null}];
const chart = generator.model(points, 5, {minTs: start, maxTs: end}, 6);
const metadata = {report: 'Historic Report 1', series: 'MAGRATHEA/IN 1', title: 'Historic Report 1 — MAGRATHEA/IN 1', subtitle: 'Exact floor-relevant CDR event transitions'};
const generatedDocument = generator.documentFor(chart, metadata);
const originalPoints = JSON.stringify(points), originalRuns = JSON.stringify(chart.runs);

assert(exporter.filename(metadata.report, metadata.series, 'svg') === 'Historic-Report-1-MAGRATHEA-IN-1.svg', 'SVG filename is filesystem-safe and format appropriate');
assert(exporter.filename(metadata.report, metadata.series, 'pdf').endsWith('.pdf') && exporter.filename(metadata.report, metadata.series, 'png').endsWith('.png') && exporter.filename(metadata.report, metadata.series, 'jpeg').endsWith('.jpg'), 'Every export format receives the correct extension');
assert(exporter.svgDocument(generatedDocument) === generatedDocument, 'SVG export uses the exact authoritative document displayed by the image');
assert(generatedDocument.indexOf('class="series" d="' + chart.runs[0].displayPath + '"') !== -1 && generatedDocument.indexOf('data-display-widened="true"') !== -1, 'SVG export retains the complete minimum-width series geometry and real metadata');
assert(generatedDocument.indexOf(metadata.title) !== -1 && generatedDocument.indexOf(metadata.subtitle) !== -1, 'Generated SVG identifies the selected report series and resolution');

const pdf = exporter.pdfDocument(generatedDocument, chart, metadata);
assert(pdf.indexOf('%PDF-1.4') === 0 && pdf.indexOf(' m ') !== -1 && pdf.indexOf(' l S') !== -1, 'PDF is a proper vector document containing graph path operators');
chart.runs.forEach(function (run) { assert(pdf.indexOf(String(20 + (run.displayStartX * 0.625))) !== -1, 'PDF uses the same displayed run geometry as the generated SVG'); });
assert(pdf.indexOf('Historic Report 1 - MAGRATHEA/IN 1') !== -1 && pdf.indexOf('Historic Report 1 ? MAGRATHEA/IN 1') === -1, 'PDF transliterates the generated em dash to readable ASCII punctuation');
assert(pdf.indexOf('cc-historical-export') === -1 && pdf.indexOf('FreePBX') === -1, 'PDF contains the graph only, without surrounding page controls');
assert(JSON.stringify(points) === originalPoints && JSON.stringify(chart.runs) === originalRuns, 'Export generation does not mutate Historical timestamps, runs or display geometry');

const source = require('fs').readFileSync(require('path').join(__dirname, '../assets/js/historical-graph-export.js'), 'utf8');
assert(source.indexOf("document.createElement('canvas')") !== -1 && source.indexOf('canvas.width = EXPORT_WIDTH * 2') !== -1, 'PNG and JPEG rasterise the authoritative SVG only during export at two-times resolution');
assert(source.indexOf("context.fillStyle = '#ffffff'") !== -1 && source.indexOf("format === 'jpeg' ? 'image/jpeg' : 'image/png'") !== -1, 'JPEG uses an opaque white background and the shared SVG rasterisation path');

console.log('Historical graph export tests passed');
