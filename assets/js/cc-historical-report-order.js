(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.CCHistoricalReportOrder = api;
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';
	function move(order, id, offset) {
		var next = order.slice(), from = next.indexOf(String(id)), to = from + offset;
		if (from < 0 || to < 0 || to >= next.length) return next;
		var value = next.splice(from, 1)[0]; next.splice(to, 0, value); return next;
	}
	function drop(order, moved, target, after) {
		if (String(moved) === String(target)) return order.slice();
		var next = order.slice(), from = next.indexOf(String(moved));
		if (from < 0 || next.indexOf(String(target)) < 0) return next;
		next.splice(from, 1); var to = next.indexOf(String(target)) + (after ? 1 : 0); next.splice(to, 0, String(moved)); return next;
	}
	function createSaver(send, saved, failed) {
		var busy = false, pending = null;
		function drain() {
			if (busy || !pending) return;
			var request = pending; pending = null; busy = true;
			send(request.order, function (error, response) {
				busy = false;
				if (error || !response || !response.status) failed(error || (response && response.message) || 'Unable to save report order.', request.order, request.context);
				else saved(request.order, response, request.context);
				drain();
			}, request.context);
		}
		return {request:function (order, context) { pending = {order:order.slice(), context:context}; drain(); }, isBusy:function () { return busy; }};
	}
	function reconcileInventory(localReports, authoritativeReports, activeId) {
		var reports = {}, order = [];
		(authoritativeReports || []).forEach(function (authoritative) {
			if (!authoritative || authoritative.id === undefined || authoritative.id === null) return;
			var id = String(authoritative.id), merged = {}, existing = localReports[id] || {};
			Object.keys(existing).forEach(function (key) { merged[key] = existing[key]; });
			merged.result = existing.result || null;
			merged.hasRun = !!existing.hasRun;
			merged.occurrenceCache = existing.occurrenceCache || {};
			merged.graphSeries = existing.graphSeries || null;
			Object.keys(authoritative).forEach(function (key) { merged[key] = authoritative[key]; });
			merged.id = id;
			reports[id] = merged; order.push(id);
		});
		var current = activeId === null || activeId === undefined ? null : String(activeId);
		return {reports:reports, order:order, activeRemoved:current !== null && !reports[current], fallbackId:order.length ? order[0] : null};
	}
	function restorePersistedOrder(persistedOrder, currentOrder, reports) {
		var restored = [], seen = {};
		(persistedOrder || []).concat(currentOrder || []).forEach(function (candidate) {
			var id = String(candidate);
			if (!seen[id] && reports[id]) { seen[id] = true; restored.push(id); }
		});
		return restored;
	}
	function createGenerationGuard() {
		var generation = 0;
		return {current:function () { return generation; }, advance:function () { generation++; return generation; }, isCurrent:function (candidate) { return candidate === generation; }};
	}
	function createRecovery(load, currentGeneration, apply) {
		var busy = false, pendingGeneration = null;
		function drain() {
			if (busy || pendingGeneration === null) return;
			var generation = pendingGeneration; pendingGeneration = null; busy = true;
			load(function (error, response) {
				busy = false;
				if (!error && response && response.status) {
					if (generation === currentGeneration()) apply(response);
					else pendingGeneration = currentGeneration();
				}
				drain();
			});
		}
		return {request:function (generation) { pendingGeneration = generation === undefined ? currentGeneration() : generation; drain(); }, isBusy:function () { return busy; }};
	}
	return {move:move, drop:drop, createSaver:createSaver, reconcileInventory:reconcileInventory, restorePersistedOrder:restorePersistedOrder, createGenerationGuard:createGenerationGuard, createRecovery:createRecovery};
}));
