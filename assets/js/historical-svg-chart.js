(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.HistoricalSvgChart = api.HistoricalSvgChart;
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';
	var WIDTH = 1000, HEIGHT = 220;
	var PLOT = {left: 50, right: 985, top: 10, bottom: 182};

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

	function escapeXml(value) {
		return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function model(points, threshold, domain, exactPeak) {
		points = Array.isArray(points) ? points.slice() : [];
		var minTs = Number(domain && domain.minTs), maxTs = Number(domain && domain.maxTs);
		if (!isFinite(minTs) || !isFinite(maxTs) || maxTs <= minTs) throw new Error('Historical SVG requires an explicit valid report domain.');
		threshold = Math.max(0, parseInt(threshold, 10) || 0);
		exactPeak = Math.max(0, parseInt(exactPeak, 10) || 0);
		var maxValue = Math.max(1, threshold, exactPeak);
		var x = function (ts) { return PLOT.left + (((Number(ts) - minTs) / (maxTs - minTs)) * (PLOT.right - PLOT.left)); };
		var y = function (value) { return PLOT.bottom - ((Number(value) / maxValue) * (PLOT.bottom - PLOT.top)); };
		var paths = [], commands = [], previous = null, visible = [], runs = [], currentRun = null;
		function finish(endTs) {
			if (commands.length) paths.push(commands.join(' '));
			if (currentRun) {
				currentRun.endTs = endTs;
				currentRun.anchorTs = currentRun.startTs + ((endTs - currentRun.startTs) / 2);
				currentRun.anchorX = x(currentRun.anchorTs);
				currentRun.y = y(currentRun.peakValue);
				runs.push(currentRun);
			}
			commands = []; previous = null; currentRun = null;
		}
		points.forEach(function (point) {
			if (point.value === null || typeof point.value === 'undefined') {
				if (previous) commands.push('H ' + x(point.ts));
				finish(Number(point.ts));
				return;
			}
			var px = x(point.ts), py = y(point.value);
			visible.push({point: point, x: px, y: py});
			if (!previous) {
				commands.push('M ' + px + ' ' + py);
				currentRun = {startTs: Number(point.ts), endTs: null, anchorTs: null, anchorX: null, y: py, peakValue: Number(point.value), firstPoint: point};
			}
			else commands.push('H ' + px + ' V ' + py);
			if (currentRun && Number(point.value) > currentRun.peakValue) currentRun.peakValue = Number(point.value);
			previous = point;
		});
		if (previous) commands.push('H ' + PLOT.right);
		finish(maxTs);
		var ticks = [];
		for (var tick = 0; tick < 5; tick++) {
			var timestamp = minTs + (((maxTs - minTs) * tick) / 4);
			ticks.push({ts: timestamp, x: x(timestamp), label: axisTimestamp(timestamp, maxTs - minTs)});
		}
		return {width: WIDTH, height: HEIGHT, plot: PLOT, minTs: minTs, maxTs: maxTs, maxValue: maxValue, threshold: threshold, thresholdY: threshold ? y(threshold) : null, paths: paths, visible: visible, runs: runs, markers: [], ticks: ticks, x: x, y: y, exactPeak: exactPeak};
	}

	function markersForWidth(chart, renderedWidth, minimumPixels) {
		minimumPixels = Math.max(2, Number(minimumPixels) || 7);
		var plotPixels = Math.max(1, Number(renderedWidth) * 0.935);
		return chart.runs.filter(function (run) {
			return ((run.endTs - run.startTs) / (chart.maxTs - chart.minTs)) * plotPixels < minimumPixels;
		}).map(function (run) {
			var logicalWidth = (minimumPixels / plotPixels) * (chart.plot.right - chart.plot.left);
			var displayX = Math.max(chart.plot.left, Math.min(chart.plot.right - logicalWidth, run.anchorX - (logicalWidth / 2)));
			return {startTs: run.startTs, endTs: run.endTs, anchorTs: run.anchorTs, x: run.anchorX, displayX: displayX, y: run.y, width: logicalWidth, value: run.peakValue, point: run.firstPoint};
		});
	}

	function markup(chart) {
		var out = '<rect class="cc-historical-svg-background" x="0" y="0" width="100%" height="220"/>';
		out += '<svg class="cc-historical-svg-plot" x="5%" y="10" width="93.5%" height="172" viewBox="50 10 935 172" preserveAspectRatio="none">';
		out += '<path class="cc-historical-svg-axis" d="M ' + chart.plot.left + ' ' + chart.plot.top + ' V ' + chart.plot.bottom + ' H ' + chart.plot.right + '"/>';
		if (chart.thresholdY !== null) out += '<path class="cc-historical-svg-threshold" d="M ' + chart.plot.left + ' ' + chart.thresholdY + ' H ' + chart.plot.right + '"/>';
		chart.paths.forEach(function (path) { out += '<path class="cc-historical-svg-series" d="' + path + '"/>'; });
		(chart.markers || []).forEach(function (marker) { out += '<rect class="cc-historical-svg-short-run" x="' + marker.displayX + '" y="' + (marker.y - 3) + '" width="' + marker.width + '" height="6" rx="3" data-start-ts="' + marker.startTs + '" data-end-ts="' + marker.endTs + '"/>'; });
		out += '</svg>';
		if (chart.thresholdY !== null) out += '<text class="cc-historical-svg-threshold-label" x="5.5%" y="' + Math.max(10, chart.thresholdY - 4) + '">Threshold ' + chart.threshold + '</text>';
		out += '<text class="cc-historical-svg-label" x="8" y="' + (chart.plot.top + 5) + '">' + chart.maxValue + '</text><text class="cc-historical-svg-label" x="3%" y="' + (chart.plot.bottom + 4) + '">0</text>';
		chart.ticks.forEach(function (tick, index) {
			var anchor = index === 0 ? 'start' : (index === chart.ticks.length - 1 ? 'end' : 'middle');
			var percentage = 5 + ((93.5 * index) / (chart.ticks.length - 1));
			out += '<text class="cc-historical-svg-label" text-anchor="' + anchor + '" x="' + percentage + '%" y="212">' + escapeXml(tick.label) + '</text>';
		});
		out += '<g class="cc-historical-svg-tooltip" style="display:none"><rect x="-90" y="-24" width="180" height="20" rx="3"/><text text-anchor="middle" y="-10"></text></g>';
		return out;
	}

	function describe(chart) {
		if (!chart.visible.length) return 'Concurrency chart with no points at or above the selected minimum';
		var current = chart.visible[chart.visible.length - 1].point.value;
		return 'Concurrency chart with ' + chart.visible.length + ' visible points, current ' + current + ', peak ' + chart.exactPeak + (chart.threshold ? ', threshold ' + chart.threshold : '');
	}

	function nearest(chart, logicalX) {
		if (!chart.visible.length) return null;
		var best = chart.visible[0];
		chart.visible.forEach(function (candidate) { if (Math.abs(candidate.x - logicalX) < Math.abs(best.x - logicalX)) best = candidate; });
		return best;
	}

	function isCurrentResult(current, requested) { return current === requested; }

	function HistoricalSvgChart(svg, options) {
		this.svg = svg; this.options = options || {}; this.chart = null;
		this.onPointer = this.onPointer.bind(this); this.onLeave = this.onLeave.bind(this); this.onClick = this.onClick.bind(this);
		svg.removeAttribute('viewBox'); svg.removeAttribute('preserveAspectRatio');
		svg.setAttribute('width', '100%'); svg.setAttribute('height', String(HEIGHT));
		svg.setAttribute('role', 'img'); svg.setAttribute('tabindex', '0');
		svg.addEventListener('mousemove', this.onPointer); svg.addEventListener('mouseleave', this.onLeave); svg.addEventListener('click', this.onClick);
	}
	HistoricalSvgChart.prototype.setData = function (points, threshold, domain, exactPeak) {
		this.chart = model(points, threshold, domain, exactPeak);
		var rect = this.svg.getBoundingClientRect();
		this.chart.markers = markersForWidth(this.chart, rect.width > 0 ? rect.width : WIDTH, 7);
		this.svg.innerHTML = markup(this.chart);
		this.svg.setAttribute('aria-label', describe(this.chart));
	};
	HistoricalSvgChart.prototype.pointerX = function (event) {
		var rect = this.svg.getBoundingClientRect();
		if (rect.width <= 0) return PLOT.left;
		var plotLeft = rect.left + (rect.width * 0.05), plotWidth = rect.width * 0.935;
		var ratio = Math.max(0, Math.min(1, (event.clientX - plotLeft) / plotWidth));
		return PLOT.left + (ratio * (PLOT.right - PLOT.left));
	};
	HistoricalSvgChart.prototype.onPointer = function (event) {
		if (!this.chart) return;
		var candidate = nearest(this.chart, this.pointerX(event));
		var tooltip = this.svg.querySelector('.cc-historical-svg-tooltip');
		if (!candidate || !tooltip) return;
		var rect = this.svg.getBoundingClientRect();
		var tooltipX = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
		tooltip.style.display = ''; tooltip.setAttribute('transform', 'translate(' + tooltipX + ' ' + candidate.y + ')');
		tooltip.querySelector('text').textContent = pointTimestamp(candidate.point.ts, this.chart.maxTs - this.chart.minTs) + '  ' + candidate.point.value;
	};
	HistoricalSvgChart.prototype.onLeave = function () { var tooltip = this.svg.querySelector('.cc-historical-svg-tooltip'); if (tooltip) tooltip.style.display = 'none'; };
	HistoricalSvgChart.prototype.onClick = function (event) { var candidate = this.chart ? nearest(this.chart, this.pointerX(event)) : null; if (candidate && typeof this.options.onSelect === 'function') this.options.onSelect(candidate.point); };
	HistoricalSvgChart.prototype.destroy = function () {
		this.svg.removeEventListener('mousemove', this.onPointer); this.svg.removeEventListener('mouseleave', this.onLeave); this.svg.removeEventListener('click', this.onClick); this.svg.innerHTML = '';
	};
	HistoricalSvgChart.isCurrentResult = isCurrentResult;

	return {HistoricalSvgChart: HistoricalSvgChart, model: model, markersForWidth: markersForWidth, markup: markup, describe: describe, nearest: nearest, formatAxisTimestamp: axisTimestamp, isCurrentResult: isCurrentResult};
}));
