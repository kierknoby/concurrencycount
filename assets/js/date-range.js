(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.CCDateRange = api;
	}
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function pad(value) { return value < 10 ? '0' + value : String(value); }
	function dateOnly(date) { return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()); }
	function timeOnly(date) { return pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()); }
	function atMidnight(date) { return new Date(date.getFullYear(), date.getMonth(), date.getDate()); }
	function addDays(date, days) { var copy = atMidnight(date); copy.setDate(copy.getDate() + days); return copy; }
	function parseDate(value) {
		var match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value || '');
		if (!match) return null;
		var date = new Date(parseInt(match[1], 10), parseInt(match[2], 10) - 1, parseInt(match[3], 10));
		return dateOnly(date) === value ? date : null;
	}

	function preset(kind, now) {
		now = now || new Date();
		var today = atMidnight(now);
		if (kind === 'yesterday') {
			var yesterday = addDays(today, -1);
			return {kind: kind, from: dateOnly(yesterday), to: dateOnly(yesterday)};
		}
		if (kind === 'last7') return {kind: kind, from: dateOnly(addDays(today, -6)), to: dateOnly(today)};
		if (kind === 'last30') return {kind: kind, from: dateOnly(addDays(today, -29)), to: dateOnly(today)};
		if (kind === 'month') return {kind: kind, from: dateOnly(new Date(today.getFullYear(), today.getMonth(), 1)), to: dateOnly(today)};
		return {kind: 'today', from: dateOnly(today), to: dateOnly(today)};
	}

	function resolve(range, now) {
		now = now || new Date();
		var from = parseDate(range.from);
		var to = parseDate(range.to);
		if (!from || !to || to.getTime() < from.getTime()) throw new Error('Invalid date range');
		var today = atMidnight(now);
		if (from.getTime() > today.getTime() || to.getTime() > today.getTime()) throw new Error('Date range cannot be in the future');
		var includeTime = !!range.includeTime;
		var startTime = includeTime ? normaliseTime(range.fromTime, '00:00') + ':00' : '00:00:00';
		var endTime;
		if (includeTime) {
			endTime = normaliseTime(range.toTime, '23:59') + ':59';
		} else {
			endTime = dateOnly(to) === dateOnly(now) ? timeOnly(now) : '23:59:59';
		}
		if (dateOnly(to) === dateOnly(now) && endTime > timeOnly(now)) endTime = timeOnly(now);
		var start = dateOnly(from) + ' ' + startTime;
		var end = dateOnly(to) + ' ' + endTime;
		if (end <= start) throw new Error('End must be after start');
		return {start: start, end: end};
	}

	function shift(range, direction, now) {
		now = now || new Date();
		var from = parseDate(range.from);
		var to = parseDate(range.to);
		if (!from || !to || (direction !== -1 && direction !== 1)) throw new Error('Invalid date range shift');
		var shiftedFrom;
		var shiftedTo;
		if (range.kind === 'month') {
			shiftedFrom = new Date(from.getFullYear(), from.getMonth() + direction, 1);
			shiftedTo = new Date(shiftedFrom.getFullYear(), shiftedFrom.getMonth() + 1, 0);
		} else {
			var span = Math.round((to.getTime() - from.getTime()) / 86400000) + 1;
			shiftedFrom = addDays(from, span * direction);
			shiftedTo = addDays(to, span * direction);
		}
		var today = atMidnight(now);
		if (shiftedTo.getTime() > today.getTime()) {
			if (range.kind !== 'month') {
				var daysPast = Math.round((shiftedTo.getTime() - today.getTime()) / 86400000);
				shiftedFrom = addDays(shiftedFrom, -daysPast);
			}
			shiftedTo = today;
		}
		return {
			kind: range.kind,
			from: dateOnly(shiftedFrom),
			to: dateOnly(shiftedTo),
			includeTime: !!range.includeTime,
			fromTime: range.fromTime || '00:00',
			toTime: range.toTime || '23:59'
		};
	}

	function normaliseTime(value, fallback) {
		return /^([01][0-9]|2[0-3]):[0-5][0-9]$/.test(value || '') ? value : fallback;
	}

	return {dateOnly: dateOnly, parseDate: parseDate, preset: preset, resolve: resolve, shift: shift};
}));
