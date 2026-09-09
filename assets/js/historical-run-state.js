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

	return {isIntentionalAbort: isIntentionalAbort, shouldReportFailure: shouldReportFailure, cancellationAcknowledged: cancellationAcknowledged, isSameRun: isSameRun, hasMeaningfulMessage: hasMeaningfulMessage, snapshotCriteria: snapshotCriteria};
}));
