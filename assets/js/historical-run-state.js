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
		return run.intentionalAbortReason === 'stop' || run.intentionalAbortReason === 'superseded' || run.intentionalAbortReason === 'terminal';
	}

	function shouldReportFailure(run, textStatus) {
		return !isIntentionalAbort(run, textStatus);
	}

	return {isIntentionalAbort: isIntentionalAbort, shouldReportFailure: shouldReportFailure};
}));
