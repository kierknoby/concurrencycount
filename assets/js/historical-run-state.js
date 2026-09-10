(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.CCHistoricalRunState = api;
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function isIntentionalAbort(run, textStatus) {
		if (textStatus !== 'abort' || !run) return false;
		var sequence = Number(run.sequence);
		if (!/^[a-f0-9]{32}$/.test(String(run.id || '')) || run.sequence === null || !isFinite(sequence) || sequence <= 0 || Math.floor(sequence) !== sequence) return false;
		return run.intentionalAbortReason === 'stop' || run.intentionalAbortReason === 'superseded' || run.intentionalAbortReason === 'abandoned' || run.intentionalAbortReason === 'terminal';
	}

	function shouldReportFailure(run, textStatus) {
		return !isIntentionalAbort(run, textStatus);
	}

	function cancellationAcknowledged(response, run) {
		if (!response || response.status !== true || response.cancelled !== true || !run) return false;
		return isIntentionalAbort(run, 'abort') && run.intentionalAbortReason === 'stop';
	}

	function isSameRun(active, candidate) {
		return !!active && !!candidate && active.id === candidate.id && Number(active.sequence) === Number(candidate.sequence);
	}

	function hasMeaningfulMessage(value) {
		if (value === null || value === undefined) return false;
		return String(value)
			.replace(/<[^>]*>/g, '')
			.replace(/&nbsp;|&#160;|&#xA0;/gi, ' ')
			.replace(/[\s\u00a0]+/g, '') !== '';
	}

	function snapshotCriteria(source) {
		source = source || {};
		var snapshot = {};
		['name', 'mode', 'engine', 'preset', 'range_from', 'range_to', 'include_time', 'from_time', 'to_time', 'filter', 'minimum_concurrency', 'start', 'end'].forEach(function (key) {
			if (Object.prototype.hasOwnProperty.call(source, key)) snapshot[key] = source[key];
		});
		if (source.excluded_call_configuration) {
			snapshot.excluded_call_configuration = {
				count: Number(source.excluded_call_configuration.count) || 0,
				fingerprint: String(source.excluded_call_configuration.fingerprint || '')
			};
		}
		return snapshot;
	}

	function clearReportResult(report) {
		if (!report) return report;
		report.result = null;
		report.graphSeries = null;
		report.occurrenceCache = {};
		report.calculationPending = true;
		return report;
	}

	function isDiscardableFirstRun(report) {
		return !!report && report.firstRunPending === true;
	}

	function countingMessage(mode, start, end) {
		return 'Counting PJSIP ' + mode + ' call data from ' + start + ' to ' + end + '. This may take a while on busy systems...';
	}

	function endpointChoices(inventory, mode, selected) {
		selected = mode === 'group' ? '' : String(selected || '');
		if (mode === 'group') return {disabled: true, selected: '', stale: false, options: []};
		var source = inventory && Array.isArray(inventory[mode]) ? inventory[mode] : [];
		var options = source.map(function (entry) { return {value: String(entry.value), label: String(entry.label || entry.value), stale: false}; });
		var found = selected === '' || options.some(function (entry) { return entry.value === selected; });
		if (!found) options.push({value: selected, label: selected + ' (no longer configured)', stale: true});
		return {disabled: false, selected: selected, stale: !found, options: options};
	}

	function endpointSelectionForMode(mode, selections) {
		if (mode === 'group') return '';
		return String(selections && selections[mode] || '');
	}

	function requiresEndpointInventory(mode) {
		return mode === 'trunk' || mode === 'extension';
	}

	function initialEndpointState(mode) {
		var loadsInventory = requiresEndpointInventory(mode);
		return {loadsInventory: loadsInventory, runDisabled: loadsInventory, filterDisabled: mode === 'group', filter: mode === 'group' ? '' : null};
	}

	function endpointModeAction(mode, inventoryLoaded, requestPending) {
		if (!requiresEndpointInventory(mode)) return 'group';
		if (inventoryLoaded) return 'render';
		return requestPending ? 'wait' : 'load';
	}

	return {isIntentionalAbort: isIntentionalAbort, shouldReportFailure: shouldReportFailure, cancellationAcknowledged: cancellationAcknowledged, isSameRun: isSameRun, hasMeaningfulMessage: hasMeaningfulMessage, snapshotCriteria: snapshotCriteria, clearReportResult: clearReportResult, isDiscardableFirstRun: isDiscardableFirstRun, countingMessage: countingMessage, endpointChoices: endpointChoices, endpointSelectionForMode: endpointSelectionForMode, requiresEndpointInventory: requiresEndpointInventory, initialEndpointState: initialEndpointState, endpointModeAction: endpointModeAction};
}));
