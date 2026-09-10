(function (root, factory) {
	'use strict';
	var api = factory(root);
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.HistoricalSvgChart = api.HistoricalSvgChart;
}(typeof self !== 'undefined' ? self : this, function (root) {
	'use strict';
	var WIDTH = 1600, HEIGHT = 300, MINIMUM_RUN_WIDTH = 24, RUN_GAP = 4;
	var PLOT = {left: 75, right: 1575, top: 52, bottom: 245};

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
		var firstX = xForTimestamp(run.points[0].ts);
		var path = 'M ' + firstX + ' ' + PLOT.bottom + ' V ' + y(run.points[0].value);
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
				for (item = index; item < end; item++) {
					shortRuns[item].displayStartX = left + ((item - index) * (MINIMUM_RUN_WIDTH + RUN_GAP));
					shortRuns[item].displayEndX = shortRuns[item].displayStartX + MINIMUM_RUN_WIDTH;
				}
			}
			index = end;
		}
	}
	function model(points, threshold, domain, exactPeak) {
		points = Array.isArray(points) ? points.slice() : [];
		var minTs = Number(domain && domain.minTs), maxTs = Number(domain && domain.maxTs);
		if (!isFinite(minTs) || !isFinite(maxTs) || maxTs <= minTs) throw new Error('Historical SVG requires an explicit valid report domain.');
		threshold = Math.max(0, parseInt(threshold, 10) || 0); exactPeak = Math.max(0, parseInt(exactPeak, 10) || 0);
		var maxValue = Math.max(1, threshold, exactPeak);
		var x = function (ts) { return PLOT.left + (((Number(ts) - minTs) / (maxTs - minTs)) * (PLOT.right - PLOT.left)); };
		var y = function (value) { return PLOT.bottom - ((Number(value) / maxValue) * (PLOT.bottom - PLOT.top)); };
		var runs = [], visible = [], current = null;
		function finish(endTs) {
			if (!current) return;
			current.realEndTs = Number(endTs); current.realMidTs = current.realStartTs + ((current.realEndTs - current.realStartTs) / 2); current.realDuration = current.realEndTs - current.realStartTs;
			current.realStartX = x(current.realStartTs); current.realEndX = x(current.realEndTs); current.realMidX = x(current.realMidTs);
			current.widened = current.realEndX - current.realStartX < MINIMUM_RUN_WIDTH;
			current.desiredStart = Math.max(PLOT.left, Math.min(PLOT.right - MINIMUM_RUN_WIDTH, current.realMidX - (MINIMUM_RUN_WIDTH / 2))); current.desiredEnd = current.desiredStart + MINIMUM_RUN_WIDTH;
			current.displayStartX = current.widened ? current.desiredStart : current.realStartX; current.displayEndX = current.widened ? current.desiredEnd : current.realEndX;
			runs.push(current); current = null;
		}
		points.forEach(function (point) {
			if (point.value === null || typeof point.value === 'undefined') { finish(point.ts); return; }
			visible.push({point: point, x: x(point.ts), y: y(point.value)});
			if (!current) current = {realStartTs: Number(point.ts), realEndTs: null, realMidTs: null, realDuration: null, points: []};
			current.points.push(point);
		});
		finish(maxTs); separateShortRuns(runs);
		runs.forEach(function (run) {
			var displayX = function (ts) { return !run.widened || run.realDuration <= 0 ? x(ts) : run.displayStartX + (((Number(ts) - run.realStartTs) / run.realDuration) * (run.displayEndX - run.displayStartX)); };
			run.realPath = pathForRun(run, x, y); run.displayPath = pathForRun(run, displayX, y);
		});
		var ticks = [];
		for (var tick = 0; tick < 5; tick++) { var timestamp = minTs + (((maxTs - minTs) * tick) / 4); ticks.push({ts: timestamp, x: x(timestamp), label: axisTimestamp(timestamp, maxTs - minTs)}); }
		return {width: WIDTH, height: HEIGHT, plot: PLOT, minTs: minTs, maxTs: maxTs, maxValue: maxValue, threshold: threshold, thresholdY: threshold ? y(threshold) : null, exactPeak: exactPeak, runs: runs, visible: visible, ticks: ticks, x: x, y: y};
	}
	function documentFor(chart, metadata) {
		metadata = metadata || {};
		var out = '<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" width="' + WIDTH + '" height="' + HEIGHT + '" viewBox="0 0 ' + WIDTH + ' ' + HEIGHT + '" preserveAspectRatio="xMidYMid meet">';
		out += '<style>.background{fill:#fff}.axis{fill:none;stroke:#d8dde3;stroke-width:1}.series-halo{fill:none;stroke:#fff;stroke-width:6;stroke-linejoin:miter}.series{fill:none;stroke:#2675a8;stroke-width:2;stroke-linejoin:miter}.threshold{fill:none;stroke:#b83232;stroke-width:1;stroke-dasharray:5 4}.label{fill:#52606b;font:13px sans-serif}.title{fill:#26343d;font:bold 17px sans-serif}.subtitle{fill:#52606b;font:12px sans-serif}</style>';
		out += '<rect class="background" width="' + WIDTH + '" height="' + HEIGHT + '"/><text class="title" x="' + PLOT.left + '" y="22">' + escapeXml(metadata.title || 'Historical active call legs') + '</text><text class="subtitle" x="' + PLOT.left + '" y="40">' + escapeXml(metadata.subtitle || '') + '</text>';
		out += '<path class="axis" d="M ' + PLOT.left + ' ' + PLOT.top + ' V ' + PLOT.bottom + ' H ' + PLOT.right + '"/>';
		if (chart.thresholdY !== null) { out += '<path class="threshold" d="M ' + PLOT.left + ' ' + chart.thresholdY + ' H ' + PLOT.right + '"/><text class="label" x="' + (PLOT.left + 8) + '" y="' + Math.max(PLOT.top + 12, chart.thresholdY - 5) + '">Threshold ' + chart.threshold + '</text>'; }
		chart.runs.forEach(function (run, index) {
			var attributes = ' data-run="' + index + '" data-real-start-ts="' + run.realStartTs + '" data-real-end-ts="' + run.realEndTs + '" data-real-duration="' + run.realDuration + '" data-display-widened="' + (run.widened ? 'true' : 'false') + '"';
			if (run.widened) out += '<path class="series-halo" d="' + run.displayPath + '"' + attributes + '/>';
			out += '<path class="series" d="' + run.displayPath + '"' + attributes + '/>';
		});
		out += '<text class="label" x="20" y="' + (PLOT.top + 5) + '">' + chart.maxValue + '</text><text class="label" x="48" y="' + (PLOT.bottom + 5) + '">0</text>';
		chart.ticks.forEach(function (tick, index) { var anchor = index === 0 ? 'start' : (index === chart.ticks.length - 1 ? 'end' : 'middle'); out += '<text class="label" text-anchor="' + anchor + '" x="' + tick.x + '" y="278">' + escapeXml(tick.label) + '</text>'; });
		return out + '</svg>';
	}
	function nearest(chart, timestamp) {
		if (!chart.visible.length) return null;
		var best = chart.visible[0]; chart.visible.forEach(function (candidate) { if (Math.abs(candidate.point.ts - timestamp) < Math.abs(best.point.ts - timestamp)) best = candidate; }); return best;
	}
	function timestampForLogicalX(chart, logicalX) {
		var run = null;
		chart.runs.forEach(function (candidate) {
			if (logicalX < candidate.displayStartX || logicalX > candidate.displayEndX) return;
			if (!run || Math.abs(logicalX - ((candidate.displayStartX + candidate.displayEndX) / 2)) < Math.abs(logicalX - ((run.displayStartX + run.displayEndX) / 2))) run = candidate;
		});
		if (run) return run.realStartTs + (((logicalX - run.displayStartX) / Math.max(0.000001, run.displayEndX - run.displayStartX)) * run.realDuration);
		return chart.minTs + (((logicalX - PLOT.left) / (PLOT.right - PLOT.left)) * (chart.maxTs - chart.minTs));
	}
	function isCurrentResult(current, requested) { return current === requested; }
	function describe(chart) { return !chart.visible.length ? 'Concurrency chart with no points at or above the selected minimum' : 'Concurrency chart with ' + chart.visible.length + ' visible points, peak ' + chart.exactPeak + (chart.threshold ? ', threshold ' + chart.threshold : ''); }
	function HistoricalSvgChart(image, overlay, tooltip, options) {
		this.image = image; this.overlay = overlay; this.tooltip = tooltip; this.options = options || {}; this.chart = null; this.metadata = {}; this.svgDocument = ''; this.objectUrl = null;
		this.createImageUrl = this.options.createImageUrl || function (documentText) { return root.URL.createObjectURL(new root.Blob([documentText], {type: 'image/svg+xml;charset=utf-8'})); };
		this.revokeImageUrl = this.options.revokeImageUrl || function (url) { root.URL.revokeObjectURL(url); };
		this.onPointer = this.onPointer.bind(this); this.onLeave = this.onLeave.bind(this); this.onClick = this.onClick.bind(this);
		overlay.addEventListener('mousemove', this.onPointer); overlay.addEventListener('mouseleave', this.onLeave); overlay.addEventListener('click', this.onClick);
	}
	HistoricalSvgChart.prototype.setData = function (points, threshold, domain, exactPeak, metadata) {
		this.chart = model(points, threshold, domain, exactPeak); this.metadata = metadata || {}; this.svgDocument = documentFor(this.chart, this.metadata);
		if (this.objectUrl) this.revokeImageUrl(this.objectUrl);
		this.objectUrl = this.createImageUrl(this.svgDocument); this.image.alt = describe(this.chart); this.image.src = this.objectUrl;
	};
	HistoricalSvgChart.prototype.pointerTimestamp = function (event) {
		var rect = this.overlay.getBoundingClientRect(); if (rect.width <= 0) return this.chart ? this.chart.minTs : 0;
		var logicalX = Math.max(PLOT.left, Math.min(PLOT.right, ((event.clientX - rect.left) / rect.width) * WIDTH));
		return timestampForLogicalX(this.chart, logicalX);
	};
	HistoricalSvgChart.prototype.onPointer = function (event) {
		if (!this.chart) return; var candidate = nearest(this.chart, this.pointerTimestamp(event)); if (!candidate) return;
		var rect = this.overlay.getBoundingClientRect(); this.tooltip.style.display = 'block'; this.tooltip.style.left = Math.max(0, Math.min(rect.width, event.clientX - rect.left)) + 'px'; this.tooltip.style.top = (((candidate.y / HEIGHT) * rect.height) - 28) + 'px'; this.tooltip.textContent = pointTimestamp(candidate.point.ts, this.chart.maxTs - this.chart.minTs) + '  ' + candidate.point.value;
	};
	HistoricalSvgChart.prototype.onLeave = function () { this.tooltip.style.display = 'none'; };
	HistoricalSvgChart.prototype.onClick = function (event) { var candidate = this.chart ? nearest(this.chart, this.pointerTimestamp(event)) : null; if (candidate && typeof this.options.onSelect === 'function') this.options.onSelect(candidate.point); };
	HistoricalSvgChart.prototype.destroy = function () {
		this.overlay.removeEventListener('mousemove', this.onPointer); this.overlay.removeEventListener('mouseleave', this.onLeave); this.overlay.removeEventListener('click', this.onClick);
		if (this.objectUrl) this.revokeImageUrl(this.objectUrl);
		this.image.removeAttribute('src'); this.tooltip.style.display = 'none'; this.chart = null; this.metadata = {}; this.svgDocument = ''; this.objectUrl = null;
	};
	HistoricalSvgChart.isCurrentResult = isCurrentResult;
	return {HistoricalSvgChart: HistoricalSvgChart, model: model, documentFor: documentFor, describe: describe, nearest: nearest, timestampForLogicalX: timestampForLogicalX, formatAxisTimestamp: axisTimestamp, isCurrentResult: isCurrentResult, width: WIDTH, height: HEIGHT, minimumRunWidth: MINIMUM_RUN_WIDTH};
}));
