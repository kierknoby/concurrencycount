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
			var order = pending.slice(); pending = null; busy = true;
			send(order, function (error, response) {
				busy = false;
				if (error || !response || !response.status) { pending = null; failed(error || (response && response.message) || 'Unable to save report order.'); }
				else saved(order, response);
				drain();
			});
		}
		return {request:function (order) { pending = order.slice(); drain(); }, isBusy:function () { return busy; }};
	}
	return {move:move, drop:drop, createSaver:createSaver};
}));
