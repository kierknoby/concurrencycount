(function (root) {
	'use strict';

	function pad(value) { return value < 10 ? '0' + value : String(value); }
	function date(value) { return value instanceof Date ? value : new Date(value); }
	function localDate(value) {
		var current = date(value);
		return current.getFullYear() + '-' + pad(current.getMonth() + 1) + '-' + pad(current.getDate());
	}
	function localYearMonth(value) {
		var current = date(value);
		return current.getFullYear() + '-' + pad(current.getMonth() + 1);
	}
	function localTime(value) {
		var current = date(value);
		return pad(current.getHours()) + ':' + pad(current.getMinutes());
	}
	function localDateTime(value) { return localDate(value) + ' ' + localTime(value); }
	var api = {localDate: localDate, localTime: localTime, localDateTime: localDateTime, localYearMonth: localYearMonth};
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.CCDateFormat = api;
}(typeof window !== 'undefined' ? window : this));
