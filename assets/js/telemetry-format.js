(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.CCTelemetryFormat = api;
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function pad(value) { return value < 10 ? '0' + value : String(value); }

	function duration(seconds) {
		seconds = Math.max(0, Math.floor(Number(seconds) || 0));
		return pad(Math.floor(seconds / 3600)) + ':' + pad(Math.floor((seconds % 3600) / 60)) + ':' + pad(seconds % 60);
	}

	function eta(reliable, seconds) {
		seconds = Number(seconds);
		if (!reliable || !isFinite(seconds) || seconds <= 0) return 'Estimating...';
		if (seconds < 1) return '< 1 second';
		return duration(seconds);
	}

	return {duration: duration, eta: eta};
}));
