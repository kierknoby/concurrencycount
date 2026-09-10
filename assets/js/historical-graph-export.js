(function (root, factory) {
	'use strict';
	var api = factory(root);
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.HistoricalGraphExport = api;
}(typeof self !== 'undefined' ? self : this, function (root) {
	'use strict';
	var EXPORT_WIDTH = 1600, GRAPH_HEIGHT = 300;

	function sanitizeFilename(value) {
		var name = String(value || 'Historical-Graph').trim().replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '').replace(/-+/g, '-');
		return name || 'Historical-Graph';
	}
	function filename(report, series, format) { return sanitizeFilename(report) + '-' + sanitizeFilename(series === 'overall' ? 'Overall' : series) + '.' + (format === 'jpeg' ? 'jpg' : format); }
	function svgDocument(generatedDocument) { return String(generatedDocument || ''); }
	function pdfEscape(value) { return String(value || '').replace(/[\u2013\u2014]/g, '-').replace(/[\u2018\u2019]/g, "'").replace(/[\u201c\u201d]/g, '"').replace(/[^\x20-\x7E]/g, '?').replace(/([\\()])/g, '\\$1'); }
	function pdfDocument(generatedDocument, chart, metadata) {
		var scale = 0.625, pageWidth = 1040, pageHeight = 228, offsetX = 20, offsetTop = 20;
		function px(x) { return offsetX + (Number(x) * scale); }
		function py(y) { return pageHeight - offsetTop - (Number(y) * scale); }
		var commands = ['1 1 1 rg 0 0 ' + pageWidth + ' ' + pageHeight + ' re f', '0.15 0.20 0.24 rg', 'BT /F1 11 Tf ' + px(chart.plot.left) + ' ' + py(22) + ' Td (' + pdfEscape(metadata.title) + ') Tj ET', '0.32 0.38 0.42 rg', 'BT /F1 8 Tf ' + px(chart.plot.left) + ' ' + py(40) + ' Td (' + pdfEscape(metadata.subtitle) + ') Tj ET'];
		commands.push('0.85 0.87 0.89 RG 1 w ' + px(chart.plot.left) + ' ' + py(chart.plot.top) + ' m ' + px(chart.plot.left) + ' ' + py(chart.plot.bottom) + ' l ' + px(chart.plot.right) + ' ' + py(chart.plot.bottom) + ' l S');
		if (chart.thresholdY !== null) {
			commands.push('0.72 0.20 0.20 RG 1 w [' + '5 4] 0 d ' + px(chart.plot.left) + ' ' + py(chart.thresholdY) + ' m ' + px(chart.plot.right) + ' ' + py(chart.thresholdY) + ' l S [] 0 d');
			commands.push('0.56 0.15 0.15 rg BT /F1 9 Tf ' + px(chart.plot.left + 5) + ' ' + py(Math.max(10, chart.thresholdY - 4)) + ' Td (Threshold ' + chart.threshold + ') Tj ET');
		}
		var seriesPaths = [], match, pathPattern = /<path class="series" d="([^"]+)"/g;
		while ((match = pathPattern.exec(generatedDocument)) !== null) seriesPaths.push(match[1]);
		seriesPaths.forEach(function (path) {
			var tokens = path.trim().split(/\s+/), index = 0, x = 0, y = 0, out = [];
			while (index < tokens.length) {
				var command = tokens[index++];
				if (command === 'M') { x = Number(tokens[index++]); y = Number(tokens[index++]); out.push(px(x) + ' ' + py(y) + ' m'); }
				else if (command === 'H') { x = Number(tokens[index++]); out.push(px(x) + ' ' + py(y) + ' l'); }
				else if (command === 'V') { y = Number(tokens[index++]); out.push(px(x) + ' ' + py(y) + ' l'); }
			}
			commands.push('0.15 0.46 0.66 RG 2 w ' + out.join(' ') + ' S');
		});
		commands.push('0.32 0.38 0.42 rg BT /F1 10 Tf ' + (offsetX + 8) + ' ' + py(chart.plot.top + 5) + ' Td (' + chart.maxValue + ') Tj ET');
		commands.push('BT /F1 10 Tf ' + px(28) + ' ' + py(chart.plot.bottom + 4) + ' Td (0) Tj ET');
		chart.ticks.forEach(function (tick) { commands.push('BT /F1 7 Tf ' + px(tick.x - 20) + ' ' + py(278) + ' Td (' + pdfEscape(tick.label) + ') Tj ET'); });
		var stream = commands.join('\n');
		var objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pageWidth + ' ' + pageHeight + '] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			'<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream',
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'
		];
		var pdf = '%PDF-1.4\n', offsets = [0];
		objects.forEach(function (object, index) { offsets.push(pdf.length); pdf += (index + 1) + ' 0 obj\n' + object + '\nendobj\n'; });
		var xref = pdf.length;
		pdf += 'xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n';
		for (var index = 1; index < offsets.length; index++) pdf += ('0000000000' + offsets[index]).slice(-10) + ' 00000 n \n';
		return pdf + 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF';
	}
	function save(blob, name) {
		var url = root.URL.createObjectURL(blob), anchor = root.document.createElement('a');
		anchor.href = url; anchor.download = name; root.document.body.appendChild(anchor); anchor.click(); anchor.remove();
		root.setTimeout(function () { root.URL.revokeObjectURL(url); }, 0);
	}
	function raster(svgText, format, name) {
		var blob = new root.Blob([svgText], {type: 'image/svg+xml;charset=utf-8'}), url = root.URL.createObjectURL(blob), image = new root.Image();
		image.onload = function () {
			var canvas = root.document.createElement('canvas'), context = canvas.getContext('2d');
			canvas.width = EXPORT_WIDTH * 2; canvas.height = GRAPH_HEIGHT * 2;
			context.fillStyle = '#ffffff'; context.fillRect(0, 0, canvas.width, canvas.height);
			context.drawImage(image, 0, 0, canvas.width, canvas.height); root.URL.revokeObjectURL(url);
			canvas.toBlob(function (output) { if (output) save(output, name); }, format === 'jpeg' ? 'image/jpeg' : 'image/png', format === 'jpeg' ? 0.94 : undefined);
		};
		image.src = url;
	}
	function download(format, generatedDocument, chart, metadata) {
		if (!generatedDocument || !chart || ['svg', 'pdf', 'png', 'jpeg'].indexOf(format) < 0) return false;
		var name = filename(metadata.report, metadata.series, format);
		if (format === 'pdf') save(new root.Blob([pdfDocument(generatedDocument, chart, metadata)], {type: 'application/pdf'}), name);
		else {
			var documentText = svgDocument(generatedDocument);
			if (format === 'svg') save(new root.Blob([documentText], {type: 'image/svg+xml;charset=utf-8'}), name);
			else raster(documentText, format, name);
		}
		return true;
	}
	return {sanitizeFilename: sanitizeFilename, filename: filename, svgDocument: svgDocument, pdfDocument: pdfDocument, download: download};
}));
