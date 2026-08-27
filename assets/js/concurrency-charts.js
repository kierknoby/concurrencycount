(function (root) {
	'use strict';

	function ConcurrencyChart(canvas, options) {
		this.canvas = canvas;
		this.context = canvas.getContext('2d');
		this.options = options || {};
		this.theme = this.options.theme === 'dark' ? 'dark' : 'light';
		this.points = [];
		this.threshold = 0;
		this.tooltip = null;
		this.resize = this.resize.bind(this);
		this.onPointer = this.onPointer.bind(this);
		this.onClick = this.onClick.bind(this);
		canvas.setAttribute('tabindex', '0');
		canvas.setAttribute('role', 'img');
		canvas.addEventListener('mousemove', this.onPointer);
		canvas.addEventListener('mouseleave', this.onPointer);
		canvas.addEventListener('click', this.onClick);
		window.addEventListener('resize', this.resize);
		this.resize();
	}

	ConcurrencyChart.prototype.setData = function (points, threshold) {
		this.points = Array.isArray(points) ? points.slice() : [];
		this.threshold = Math.max(0, parseInt(threshold, 10) || 0);
		this.canvas.setAttribute('aria-label', this.describe());
		this.draw();
	};

	ConcurrencyChart.prototype.setTheme = function (theme) {
		this.theme = theme === 'dark' ? 'dark' : 'light';
		this.draw();
	};

	ConcurrencyChart.prototype.palette = function () {
		return this.theme === 'dark' ? {
			background: '#172431', axis: '#516678', grid: '#263a4b', label: '#c7d1da',
			line: '#62b0e8', exceeded: '#ff8585', threshold: '#ff8585', thresholdText: '#ffb0b0',
			tooltipBackground: '#edf3f7', tooltipText: '#172431'
		} : {
			background: '#ffffff', axis: '#d8dde3', grid: null, label: '#52606b',
			line: '#2675a8', exceeded: '#b83232', threshold: '#b83232', thresholdText: '#8f2525',
			tooltipBackground: '#26343d', tooltipText: '#ffffff'
		};
	};

	ConcurrencyChart.prototype.resize = function () {
		var ratio = window.devicePixelRatio || 1;
		var width = Math.max(240, this.canvas.clientWidth || 320);
		var height = Math.max(100, this.canvas.clientHeight || 140);
		if (this.canvas.width !== Math.round(width * ratio) || this.canvas.height !== Math.round(height * ratio)) {
			this.canvas.width = Math.round(width * ratio);
			this.canvas.height = Math.round(height * ratio);
			this.context.setTransform(ratio, 0, 0, ratio, 0, 0);
		}
		this.width = width;
		this.height = height;
		this.draw();
	};

	ConcurrencyChart.prototype.draw = function () {
		var context = this.context;
		var palette = this.palette();
		var width = this.width || 320;
		var height = this.height || 140;
		context.clearRect(0, 0, width, height);
		context.fillStyle = palette.background;
		context.fillRect(0, 0, width, height);
		var plot = {left: 34, right: width - 10, top: 10, bottom: height - 24};
		if (palette.grid) {
			context.strokeStyle = palette.grid;
			context.lineWidth = 1;
			for (var gridIndex = 1; gridIndex < 4; gridIndex++) {
				var gridY = plot.top + (((plot.bottom - plot.top) / 4) * gridIndex);
				context.beginPath();
				context.moveTo(plot.left, gridY);
				context.lineTo(plot.right, gridY);
				context.stroke();
			}
		}
		context.strokeStyle = palette.axis;
		context.lineWidth = 1;
		context.beginPath();
		context.moveTo(plot.left, plot.top);
		context.lineTo(plot.left, plot.bottom);
		context.lineTo(plot.right, plot.bottom);
		context.stroke();
		if (!this.points.length) {
			context.fillStyle = palette.label;
			context.font = '12px sans-serif';
			context.fillText('Waiting for data', plot.left + 8, plot.top + 22);
			return;
		}
		var bounds = this.bounds();
		this.drawThreshold(plot, bounds);
		context.strokeStyle = palette.line;
		context.lineWidth = 2;
		context.beginPath();
		for (var index = 0; index < this.points.length; index++) {
			var position = this.position(this.points[index], plot, bounds);
			if (index === 0) context.moveTo(position.x, position.y);
			else {
				var previous = this.position(this.points[index - 1], plot, bounds);
				context.lineTo(position.x, previous.y);
				context.lineTo(position.x, position.y);
			}
		}
		context.stroke();
		if (this.threshold > 0) {
			context.strokeStyle = palette.exceeded;
			context.lineWidth = 3;
			context.beginPath();
			var started = false;
			for (var pointIndex = 0; pointIndex < this.points.length; pointIndex++) {
				var point = this.points[pointIndex];
				var pointPosition = this.position(point, plot, bounds);
				if (point.value >= this.threshold) {
					if (!started) context.moveTo(pointPosition.x, pointPosition.y);
					else context.lineTo(pointPosition.x, pointPosition.y);
					started = true;
				} else {
					started = false;
				}
			}
			context.stroke();
		}
		this.drawLabels(plot, bounds);
		if (this.tooltip) this.drawTooltip(this.tooltip, plot, bounds);
	};

	ConcurrencyChart.prototype.bounds = function () {
		var minTs = parseInt(this.points[0].ts, 10) || 0;
		var maxTs = parseInt(this.points[this.points.length - 1].ts, 10) || minTs + 1;
		var maxValue = this.threshold;
		for (var index = 0; index < this.points.length; index++) maxValue = Math.max(maxValue, parseInt(this.points[index].value, 10) || 0);
		return {minTs: minTs, maxTs: Math.max(minTs + 1, maxTs), maxValue: Math.max(1, maxValue)};
	};

	ConcurrencyChart.prototype.position = function (point, plot, bounds) {
		return {
			x: plot.left + (((point.ts - bounds.minTs) / (bounds.maxTs - bounds.minTs)) * (plot.right - plot.left)),
			y: plot.bottom - ((point.value / bounds.maxValue) * (plot.bottom - plot.top))
		};
	};

	ConcurrencyChart.prototype.drawThreshold = function (plot, bounds) {
		if (!this.threshold) return;
		var y = plot.bottom - ((this.threshold / bounds.maxValue) * (plot.bottom - plot.top));
		this.context.save();
		this.context.setLineDash([5, 4]);
		var palette = this.palette();
		this.context.strokeStyle = palette.threshold;
		this.context.beginPath();
		this.context.moveTo(plot.left, y);
		this.context.lineTo(plot.right, y);
		this.context.stroke();
		this.context.restore();
		this.context.fillStyle = palette.thresholdText;
		this.context.font = '11px sans-serif';
		this.context.fillText('Threshold ' + this.threshold, plot.left + 4, Math.max(10, y - 4));
	};

	ConcurrencyChart.prototype.drawLabels = function (plot, bounds) {
		this.context.fillStyle = this.palette().label;
		this.context.font = '11px sans-serif';
		this.context.fillText(String(bounds.maxValue), 5, plot.top + 4);
		this.context.fillText('0', 20, plot.bottom + 4);
		this.context.fillText(formatTime(bounds.minTs), plot.left, this.height - 7);
		var endLabel = formatTime(bounds.maxTs);
		this.context.fillText(endLabel, plot.right - this.context.measureText(endLabel).width, this.height - 7);
	};

	ConcurrencyChart.prototype.drawTooltip = function (point, plot, bounds) {
		var palette = this.palette();
		var position = this.position(point, plot, bounds);
		var text = formatTime(point.ts) + '  ' + point.value;
		var width = this.context.measureText(text).width + 12;
		var x = Math.min(this.width - width - 4, Math.max(4, position.x - width / 2));
		var y = Math.max(4, position.y - 28);
		this.context.fillStyle = palette.tooltipBackground;
		this.context.fillRect(x, y, width, 20);
		this.context.fillStyle = palette.tooltipText;
		this.context.fillText(text, x + 6, y + 14);
	};

	ConcurrencyChart.prototype.onPointer = function (event) {
		if (event.type === 'mouseleave') this.tooltip = null;
		else this.tooltip = this.nearest(event.offsetX);
		this.draw();
	};

	ConcurrencyChart.prototype.onClick = function (event) {
		var point = this.nearest(event.offsetX);
		if (point && typeof this.options.onSelect === 'function') this.options.onSelect(point);
	};

	ConcurrencyChart.prototype.nearest = function (x) {
		if (!this.points.length) return null;
		var bounds = this.bounds();
		var plotWidth = Math.max(1, (this.width - 10) - 34);
		var timestamp = bounds.minTs + (((x - 34) / plotWidth) * (bounds.maxTs - bounds.minTs));
		var best = this.points[0];
		for (var index = 1; index < this.points.length; index++) {
			if (Math.abs(this.points[index].ts - timestamp) < Math.abs(best.ts - timestamp)) best = this.points[index];
		}
		return best;
	};

	ConcurrencyChart.prototype.describe = function () {
		if (!this.points.length) return 'Concurrency chart waiting for data';
		var peak = 0;
		for (var index = 0; index < this.points.length; index++) peak = Math.max(peak, this.points[index].value);
		return 'Concurrency chart with ' + this.points.length + ' points, current ' + this.points[this.points.length - 1].value + ', peak ' + peak + (this.threshold ? ', threshold ' + this.threshold : '');
	};

	ConcurrencyChart.prototype.destroy = function () {
		window.removeEventListener('resize', this.resize);
		this.canvas.removeEventListener('mousemove', this.onPointer);
		this.canvas.removeEventListener('mouseleave', this.onPointer);
		this.canvas.removeEventListener('click', this.onClick);
	};

	function formatTime(timestamp) {
		return new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
	}

	root.ConcurrencyChart = ConcurrencyChart;
}(typeof window !== 'undefined' ? window : this));
