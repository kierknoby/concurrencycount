(function (root, factory) {
	'use strict';
	var api = factory(root);
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.HistoricalSvgChart = api.HistoricalSvgChart;
}(typeof self !== 'undefined' ? self : this, function (root) {
	'use strict';
	var WIDTH = 1600, BASE_HEIGHT = 320, MINIMUM_RUN_WIDTH = 24, RUN_GAP = 4, LEGEND_COLUMNS = 4;
	var PLOT = {left: 75, right: 1575, top: 52, bottom: 245};

	function hslHex(hue, saturation, lightness) {
		var chroma = (1 - Math.abs((2 * lightness / 100) - 1)) * (saturation / 100), segment = hue / 60, intermediate = chroma * (1 - Math.abs((segment % 2) - 1));
		var rgb = segment < 1 ? [chroma, intermediate, 0] : (segment < 2 ? [intermediate, chroma, 0] : (segment < 3 ? [0, chroma, intermediate] : (segment < 4 ? [0, intermediate, chroma] : (segment < 5 ? [intermediate, 0, chroma] : [chroma, 0, intermediate]))));
		var offset = (lightness / 100) - (chroma / 2);
		return '#' + rgb.map(function (part) { return ('0' + Math.round((part + offset) * 255).toString(16)).slice(-2); }).join('').toUpperCase();
	}
	function labForHex(hex) {
		var channels = [1, 3, 5].map(function (offset) { var value = parseInt(hex.slice(offset, offset + 2), 16) / 255; return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4); });
		var x = (channels[0] * 0.4124 + channels[1] * 0.3576 + channels[2] * 0.1805) / 0.95047;
		var y = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
		var z = (channels[0] * 0.0193 + channels[1] * 0.1192 + channels[2] * 0.9505) / 1.08883;
		var convert = function (value) { return value > 0.008856 ? Math.pow(value, 1 / 3) : (7.787 * value) + (16 / 116); };
		x = convert(x); y = convert(y); z = convert(z);
		return {l: (116 * y) - 16, a: 500 * (x - y), b: 200 * (y - z)};
	}
	function perceptualDistance(left, right) { var dl = left.l - right.l, da = left.a - right.a, db = left.b - right.b; return Math.sqrt((dl * dl) + (da * da) + (db * db)); }
	function colourCandidates() {
		var candidates = [], seen = {};
		for (var hue = 0; hue < 360; hue += 5) [58, 72, 84].forEach(function (saturation) { [32, 38, 44].forEach(function (lightness) { var hex = hslHex(hue, saturation, lightness); if (!seen[hex]) { seen[hex] = true; candidates.push({hex: hex, lab: labForHex(hex)}); } }); });
		return candidates;
	}
	function fallbackColour(name, index) {
		var hash = 2166136261, text = String(name) + ':' + index;
		for (var character = 0; character < text.length; character++) { hash ^= text.charCodeAt(character); hash = Math.imul(hash, 16777619); }
		hash >>>= 0;
		return hslHex((hash % 36000) / 100, 58 + ((hash >>> 9) % 23), 34 + ((hash >>> 17) % 13));
	}
	function coloursForInventory(names) {
		var sorted = names.map(String).filter(function (name, index, all) { return all.indexOf(name) === index; }).sort(function (left, right) { return left < right ? -1 : (left > right ? 1 : 0); }), colours = {}, candidates = colourCandidates(), allocated = [];
		var seed = hslHex(210, 72, 38), seedIndex = candidates.map(function (candidate) { return candidate.hex; }).indexOf(seed);
		if (seedIndex < 0) seedIndex = 0;
		for (var index = 0; index < sorted.length && candidates.length; index++) {
			var chosenIndex = seedIndex;
			if (allocated.length) {
				var bestDistance = -1;
				candidates.forEach(function (candidate, candidateIndex) {
					var minimumDistance = Infinity;
					allocated.forEach(function (selected) { minimumDistance = Math.min(minimumDistance, perceptualDistance(candidate.lab, selected.lab)); });
					if (minimumDistance > bestDistance) { bestDistance = minimumDistance; chosenIndex = candidateIndex; }
				});
			}
			var chosen = candidates.splice(chosenIndex, 1)[0]; allocated.push(chosen); colours[sorted[index]] = chosen.hex; seedIndex = 0;
		}
		for (; index < sorted.length; index++) colours[sorted[index]] = fallbackColour(sorted[index], index);
		return colours;
	}

	function axisTimestamp(timestamp, span) {
		var date = new Date(timestamp * 1000);
		if (span <= 86400) return date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
		if (span <= 7 * 86400) return date.toLocaleString([], {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'});
		if (span <= 120 * 86400) return date.toLocaleDateString([], {month: 'short', day: 'numeric'});
		return date.toLocaleDateString([], {month: 'short', year: 'numeric'});
	}
	function pointTimestamp(timestamp, span) {
		if (span <= 86400) return new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
		return new Date(timestamp * 1000).toLocaleString([], {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'});
	}
	function escapeXml(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
	function pathForRun(run, xForTimestamp, y) {
		var firstX = xForTimestamp(run.points[0].ts), path = 'M ' + firstX + ' ' + PLOT.bottom + ' V ' + y(run.points[0].value);
		for (var index = 1; index < run.points.length; index++) path += ' H ' + xForTimestamp(run.points[index].ts) + ' V ' + y(run.points[index].value);
		return path + ' H ' + xForTimestamp(run.realEndTs) + ' V ' + PLOT.bottom;
	}
	function separateShortRuns(runs) {
		var shortRuns = runs.filter(function (run) { return run.widened; }), index = 0;
		while (index < shortRuns.length) {
			var end = index + 1;
			while (end < shortRuns.length && shortRuns[end].desiredStart < shortRuns[end - 1].desiredEnd + RUN_GAP) end++;
			if (end - index > 1) {
				var centre = 0, item;
				for (item = index; item < end; item++) centre += shortRuns[item].realMidX;
				centre /= end - index;
				var totalWidth = ((end - index) * MINIMUM_RUN_WIDTH) + ((end - index - 1) * RUN_GAP);
				var left = Math.max(PLOT.left, Math.min(PLOT.right - totalWidth, centre - (totalWidth / 2)));
				for (item = index; item < end; item++) { shortRuns[item].displayStartX = left + ((item - index) * (MINIMUM_RUN_WIDTH + RUN_GAP)); shortRuns[item].displayEndX = shortRuns[item].displayStartX + MINIMUM_RUN_WIDTH; }
			}
			index = end;
		}
	}
	function buildSeries(spec, minTs, maxTs, maxValue) {
		var points = Array.isArray(spec.points) ? spec.points.slice() : [];
		var x = function (ts) { return PLOT.left + (((Number(ts) - minTs) / (maxTs - minTs)) * (PLOT.right - PLOT.left)); };
		var y = function (value) { return PLOT.bottom - ((Number(value) / maxValue) * (PLOT.bottom - PLOT.top)); };
		var series = {name: String(spec.name), label: String(spec.label || spec.name), color: spec.color, threshold: Math.max(0, parseInt(spec.threshold, 10) || 0), exactPeak: Math.max(0, parseInt(spec.exactPeak, 10) || 0), resolution: spec.resolution || '', runs: [], visible: []}, current = null;
		function finish(endTs) {
			if (!current) return;
			current.realEndTs = Number(endTs); current.realMidTs = current.realStartTs + ((current.realEndTs - current.realStartTs) / 2); current.realDuration = current.realEndTs - current.realStartTs;
			current.realStartX = x(current.realStartTs); current.realEndX = x(current.realEndTs); current.realMidX = x(current.realMidTs); current.widened = current.realEndX - current.realStartX < MINIMUM_RUN_WIDTH;
			current.desiredStart = Math.max(PLOT.left, Math.min(PLOT.right - MINIMUM_RUN_WIDTH, current.realMidX - (MINIMUM_RUN_WIDTH / 2))); current.desiredEnd = current.desiredStart + MINIMUM_RUN_WIDTH;
			current.displayStartX = current.widened ? current.desiredStart : current.realStartX; current.displayEndX = current.widened ? current.desiredEnd : current.realEndX;
			series.runs.push(current); current = null;
		}
		points.forEach(function (point) {
			if (point.value === null || typeof point.value === 'undefined') { finish(point.ts); return; }
			if (!current) current = {realStartTs: Number(point.ts), realEndTs: null, realMidTs: null, realDuration: null, points: []};
			current.points.push(point);
		});
		finish(maxTs); separateShortRuns(series.runs);
		series.runs.forEach(function (run) {
			var displayX = function (ts) { return !run.widened || run.realDuration <= 0 ? x(ts) : run.displayStartX + (((Number(ts) - run.realStartTs) / run.realDuration) * (run.displayEndX - run.displayStartX)); };
			run.realPath = pathForRun(run, x, y); run.displayPath = pathForRun(run, displayX, y);
			run.displayPoints = run.points.map(function (point) { return {point: point, x: displayX(point.ts), y: y(point.value)}; });
			series.visible = series.visible.concat(run.displayPoints);
		});
		series.thresholdY = series.threshold ? y(series.threshold) : null;
		return series;
	}
	function multiModel(specs, domain) {
		var minTs = Number(domain && domain.minTs), maxTs = Number(domain && domain.maxTs);
		if (!isFinite(minTs) || !isFinite(maxTs) || maxTs <= minTs) throw new Error('Historical SVG requires an explicit valid report domain.');
		specs = Array.isArray(specs) ? specs.slice() : [];
		var maxValue = 1;
		specs.forEach(function (spec) { maxValue = Math.max(maxValue, parseInt(spec.exactPeak, 10) || 0, parseInt(spec.threshold, 10) || 0); });
		var defaultColours = coloursForInventory(specs.map(function (spec) { return spec.name; }));
		var series = specs.map(function (spec) { var copy = {}; Object.keys(spec).forEach(function (key) { copy[key] = spec[key]; }); copy.color = copy.color || defaultColours[String(copy.name)]; return buildSeries(copy, minTs, maxTs, maxValue); });
		var ticks = [];
		for (var tick = 0; tick < 5; tick++) { var timestamp = minTs + (((maxTs - minTs) * tick) / 4); ticks.push({ts: timestamp, x: PLOT.left + (((timestamp - minTs) / (maxTs - minTs)) * (PLOT.right - PLOT.left)), label: axisTimestamp(timestamp, maxTs - minTs)}); }
		var legendRows = Math.max(1, Math.ceil(series.length / LEGEND_COLUMNS));
		return {width: WIDTH, height: BASE_HEIGHT + (legendRows * 22), plot: PLOT, minTs: minTs, maxTs: maxTs, maxValue: maxValue, exactPeak: series.reduce(function (peak, item) { return Math.max(peak, item.exactPeak); }, 0), series: series, ticks: ticks};
	}
	function model(points, threshold, domain, exactPeak) { var chart = multiModel([{name: 'series', label: 'Series', points: points, threshold: threshold, exactPeak: exactPeak}], domain); var single = chart.series[0]; single.width = chart.width; single.height = chart.height; single.plot = chart.plot; single.minTs = chart.minTs; single.maxTs = chart.maxTs; single.maxValue = chart.maxValue; single.ticks = chart.ticks; single.x = function (ts) { return PLOT.left + (((Number(ts) - chart.minTs) / (chart.maxTs - chart.minTs)) * (PLOT.right - PLOT.left)); }; single.y = function (value) { return PLOT.bottom - ((Number(value) / chart.maxValue) * (PLOT.bottom - PLOT.top)); }; return single; }
	function documentFor(chart, metadata) {
		metadata = metadata || {};
		var out = '<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" width="' + chart.width + '" height="' + chart.height + '" viewBox="0 0 ' + chart.width + ' ' + chart.height + '" preserveAspectRatio="xMidYMid meet">';
		out += '<style>.background{fill:#fff}.axis{fill:none;stroke:#d8dde3;stroke-width:1}.series-halo{fill:none;stroke:#fff;stroke-width:6;stroke-linejoin:miter}.series{fill:none;stroke-width:2;stroke-linejoin:miter}.threshold{fill:none;stroke-width:1;stroke-dasharray:5 4}.label{fill:#52606b;font:13px sans-serif}.title{fill:#26343d;font:bold 17px sans-serif}.subtitle{fill:#52606b;font:12px sans-serif}.legend{fill:#26343d;font:12px sans-serif}</style>';
		out += '<rect class="background" width="' + chart.width + '" height="' + chart.height + '"/><text class="title" x="' + PLOT.left + '" y="22">' + escapeXml(metadata.title || 'Historical active call legs') + '</text><text class="subtitle" x="' + PLOT.left + '" y="40">' + escapeXml(metadata.subtitle || '') + '</text><path class="axis" d="M ' + PLOT.left + ' ' + PLOT.top + ' V ' + PLOT.bottom + ' H ' + PLOT.right + '"/>';
		chart.series.forEach(function (series) {
			if (series.thresholdY !== null) out += '<path class="threshold" data-series="' + escapeXml(series.name) + '" stroke="' + series.color + '" d="M ' + PLOT.left + ' ' + series.thresholdY + ' H ' + PLOT.right + '"/>';
			series.runs.forEach(function (run, index) {
				var attributes = ' data-series="' + escapeXml(series.name) + '" data-run="' + index + '" data-real-start-ts="' + run.realStartTs + '" data-real-end-ts="' + run.realEndTs + '" data-real-duration="' + run.realDuration + '" data-display-widened="' + (run.widened ? 'true' : 'false') + '"';
				if (run.widened) out += '<path class="series-halo" d="' + run.displayPath + '"' + attributes + '/>';
				out += '<path class="series" d="' + run.displayPath + '" stroke="' + series.color + '"' + attributes + '/>';
			});
		});
		out += '<text class="label" x="20" y="' + (PLOT.top + 5) + '">' + chart.maxValue + '</text><text class="label" x="48" y="' + (PLOT.bottom + 5) + '">0</text>';
		chart.ticks.forEach(function (tick, index) { var anchor = index === 0 ? 'start' : (index === chart.ticks.length - 1 ? 'end' : 'middle'); out += '<text class="label" text-anchor="' + anchor + '" x="' + tick.x + '" y="278">' + escapeXml(tick.label) + '</text>'; });
		chart.series.forEach(function (series, index) { var x = PLOT.left + ((index % LEGEND_COLUMNS) * 375), y = 310 + (Math.floor(index / LEGEND_COLUMNS) * 22); out += '<g class="legend-item" data-series="' + escapeXml(series.name) + '"><line x1="' + x + '" y1="' + (y - 4) + '" x2="' + (x + 24) + '" y2="' + (y - 4) + '" stroke="' + series.color + '" stroke-width="3"/><text class="legend" x="' + (x + 32) + '" y="' + y + '">' + escapeXml(series.label) + (series.threshold ? ' (threshold ' + series.threshold + ')' : '') + '</text></g>'; });
		return out + '</svg>';
	}
	function timestampForRun(run, logicalX) { return run.realStartTs + (((logicalX - run.displayStartX) / Math.max(0.000001, run.displayEndX - run.displayStartX)) * run.realDuration); }
	function nearestSeriesPoint(chart, logicalX, logicalY) {
		var best = null;
		chart.series.forEach(function (series, seriesIndex) {
			series.runs.forEach(function (run) {
				if (logicalX < run.displayStartX || logicalX > run.displayEndX) return;
				var timestamp = timestampForRun(run, logicalX), point = run.points[0];
				run.points.forEach(function (candidate) { if (candidate.ts <= timestamp) point = candidate; });
				var y = PLOT.bottom - ((Number(point.value) / chart.maxValue) * (PLOT.bottom - PLOT.top)), distance = Math.abs(y - logicalY);
				if (!best || distance < best.distance) best = {seriesName: series.name, seriesLabel: series.label, point: point, timestamp: timestamp, x: logicalX, y: y, distance: distance, seriesIndex: seriesIndex};
			});
			series.visible.forEach(function (candidate) { var distance = Math.sqrt(Math.pow(candidate.x - logicalX, 2) + Math.pow(candidate.y - logicalY, 2)); if (!best || distance < best.distance) best = {seriesName: series.name, seriesLabel: series.label, point: candidate.point, timestamp: candidate.point.ts, x: candidate.x, y: candidate.y, distance: distance, seriesIndex: seriesIndex}; });
		});
		return best;
	}
	function initialSelection(names) { return names.slice(); }
	function toggleSelection(selected, name) { var next = selected.slice(), index = next.indexOf(name); if (index >= 0) next.splice(index, 1); else next.push(name); return next; }
	function selectionPresentation(names, selected) { var series = {}; names.forEach(function (name) { var active = selected.indexOf(name) >= 0; series[name] = {selected: active, buttonClass: active ? 'btn-primary' : 'btn-default', ariaPressed: active ? 'true' : 'false'}; }); return {bulkClass: 'btn-default', bulkAriaPressed: null, series: series}; }
	function isCurrentResult(current, requested) { return current === requested; }
	function describe(chart) { return !chart.series.length ? 'Concurrency chart with no selected series' : 'Concurrency chart with ' + chart.series.length + ' selected series and peak ' + chart.exactPeak; }
	function HistoricalSvgChart(image, overlay, tooltip, options) {
		this.image = image; this.overlay = overlay; this.tooltip = tooltip; this.options = options || {}; this.chart = null; this.metadata = {}; this.svgDocument = ''; this.objectUrl = null;
		this.createImageUrl = this.options.createImageUrl || function (text) { return root.URL.createObjectURL(new root.Blob([text], {type: 'image/svg+xml;charset=utf-8'})); }; this.revokeImageUrl = this.options.revokeImageUrl || function (url) { root.URL.revokeObjectURL(url); };
		this.onPointer = this.onPointer.bind(this); this.onLeave = this.onLeave.bind(this); this.onClick = this.onClick.bind(this); overlay.addEventListener('mousemove', this.onPointer); overlay.addEventListener('mouseleave', this.onLeave); overlay.addEventListener('click', this.onClick);
	}
	HistoricalSvgChart.prototype.setSeries = function (specs, domain, metadata) { this.chart = multiModel(specs, domain); this.metadata = metadata || {}; this.svgDocument = documentFor(this.chart, this.metadata); if (this.objectUrl) this.revokeImageUrl(this.objectUrl); this.objectUrl = this.createImageUrl(this.svgDocument); this.image.alt = describe(this.chart); this.image.src = this.objectUrl; };
	HistoricalSvgChart.prototype.setData = function (points, threshold, domain, exactPeak, metadata) { this.setSeries([{name: 'series', label: 'Series', points: points, threshold: threshold, exactPeak: exactPeak}], domain, metadata); };
	HistoricalSvgChart.prototype.pointerCoordinates = function (event) { var rect = this.overlay.getBoundingClientRect(); if (rect.width <= 0 || rect.height <= 0) return {x: PLOT.left, y: PLOT.bottom}; return {x: Math.max(PLOT.left, Math.min(PLOT.right, ((event.clientX - rect.left) / rect.width) * WIDTH)), y: Math.max(PLOT.top, Math.min(PLOT.bottom, ((event.clientY - rect.top) / rect.height) * this.chart.height))}; };
	HistoricalSvgChart.prototype.candidate = function (event) { if (!this.chart) return null; var coordinates = this.pointerCoordinates(event); return nearestSeriesPoint(this.chart, coordinates.x, coordinates.y); };
	HistoricalSvgChart.prototype.onPointer = function (event) { var candidate = this.candidate(event); if (!candidate) return; var rect = this.overlay.getBoundingClientRect(); this.tooltip.style.display = 'block'; this.tooltip.style.left = Math.max(0, Math.min(rect.width, event.clientX - rect.left)) + 'px'; this.tooltip.style.top = Math.max(0, Math.min(rect.height, event.clientY - rect.top) - 12) + 'px'; this.tooltip.textContent = candidate.seriesLabel + ' · ' + pointTimestamp(candidate.point.ts, this.chart.maxTs - this.chart.minTs) + '  ' + candidate.point.value; };
	HistoricalSvgChart.prototype.onLeave = function () { this.tooltip.style.display = 'none'; };
	HistoricalSvgChart.prototype.onClick = function (event) { var candidate = this.candidate(event); if (candidate && typeof this.options.onSelect === 'function') this.options.onSelect(candidate.seriesName, candidate.point); };
	HistoricalSvgChart.prototype.destroy = function () { this.overlay.removeEventListener('mousemove', this.onPointer); this.overlay.removeEventListener('mouseleave', this.onLeave); this.overlay.removeEventListener('click', this.onClick); if (this.objectUrl) this.revokeImageUrl(this.objectUrl); this.image.removeAttribute('src'); this.tooltip.style.display = 'none'; this.chart = null; this.metadata = {}; this.svgDocument = ''; this.objectUrl = null; };
	HistoricalSvgChart.isCurrentResult = isCurrentResult;
	HistoricalSvgChart.selection = {initial: initialSelection, toggle: toggleSelection, all: function (names) { return names.slice(); }, presentation: selectionPresentation};
	HistoricalSvgChart.coloursForInventory = coloursForInventory;
	return {HistoricalSvgChart: HistoricalSvgChart, model: model, multiModel: multiModel, documentFor: documentFor, nearestSeriesPoint: nearestSeriesPoint, selection: HistoricalSvgChart.selection, coloursForInventory: coloursForInventory, labForHex: labForHex, perceptualDistance: perceptualDistance, formatAxisTimestamp: axisTimestamp, isCurrentResult: isCurrentResult, width: WIDTH, baseHeight: BASE_HEIGHT, minimumRunWidth: MINIMUM_RUN_WIDTH};
}));
