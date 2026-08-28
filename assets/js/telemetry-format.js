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
		if (!reliable || !isFinite(seconds) || seconds < 0) return 'Estimating...';
		if (seconds < 1) return '< 1 second';
		return duration(seconds);
	}

	function snapshot(state, now) {
		if (!state) return null;
		var delta = Math.max(0, (Number(now) - Number(state.syncedAt)) / 1000);
		if (!isFinite(delta)) delta = 0;
		var elapsed = Number(state.elapsed);
		var runtime = Number(state.runtimeRemaining);
		var estimated = Number(state.etaRemaining);
		if (!isFinite(elapsed) || elapsed < 0) elapsed = 0;
		if (!isFinite(runtime) || runtime < 0) runtime = 0;
		if (!isFinite(estimated) || estimated < 0) estimated = 0;
		return {
			elapsed: elapsed + delta,
			runtimeRemaining: Math.max(0, runtime - delta),
			etaReliable: !!state.etaReliable,
			etaRemaining: state.etaReliable ? Math.max(0, estimated - delta) : null
		};
	}

	function synchronize(previous, telemetry, now) {
		telemetry = telemetry || {};
		var current = snapshot(previous, now);
		var backendElapsed = Number(telemetry.elapsed);
		var backendRuntime = Number(telemetry.runtime_remaining);
		var backendEta = Number(telemetry.estimated_remaining);
		if (!isFinite(backendElapsed) || backendElapsed < 0) backendElapsed = current ? current.elapsed : 0;
		if (current) backendElapsed = Math.max(backendElapsed, current.elapsed);
		if (!isFinite(backendRuntime) || backendRuntime < 0) backendRuntime = current ? current.runtimeRemaining : 0;
		var reliable = telemetry.eta_reliable === true && isFinite(backendEta) && backendEta >= 0;
		return {
			elapsed: backendElapsed,
			runtimeRemaining: Math.max(0, backendRuntime),
			etaReliable: reliable,
			etaRemaining: reliable ? backendEta : null,
			syncedAt: Number(now)
		};
	}

	return {duration: duration, eta: eta, snapshot: snapshot, synchronize: synchronize};
}));
