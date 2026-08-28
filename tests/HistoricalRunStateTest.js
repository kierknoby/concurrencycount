'use strict';

const state = require('../assets/js/historical-run-state.js');
const id = '00112233445566778899aabbccddeeff';

function assert(condition, message) {
	if (!condition) throw new Error(message);
}

assert(!state.shouldReportFailure({id, sequence: 1, intentionalAbortReason: 'stop'}, 'abort'), 'Stop-triggered run abort must be suppressed');
assert(!state.shouldReportFailure({id, sequence: 2, intentionalAbortReason: 'superseded'}, 'abort'), 'Superseded run abort must be suppressed');
assert(!state.shouldReportFailure({id, sequence: 8, intentionalAbortReason: 'abandoned'}, 'abort'), 'Page/module abandonment abort must be suppressed');
assert(state.shouldReportFailure({id, sequence: 8, intentionalAbortReason: 'abandoned'}, 'error'), 'A real transport failure remains reportable even for abandonment state');
assert(state.shouldReportFailure({id, sequence: 3, intentionalAbortReason: null}, 'abort'), 'Unexpected abort must still be reported');
assert(state.shouldReportFailure({id, sequence: 4, intentionalAbortReason: 'stop'}, 'error'), 'A genuine network/server failure must still be reported even after Stop state');
assert(state.shouldReportFailure({id, sequence: 5, intentionalAbortReason: 'superseded'}, 'parsererror'), 'Malformed responses must still be reported');
assert(state.shouldReportFailure({id: 'wrong-id', sequence: 6, intentionalAbortReason: 'stop'}, 'abort'), 'Suppression requires a valid calculation ID');
assert(state.shouldReportFailure({id, sequence: null, intentionalAbortReason: 'stop'}, 'abort'), 'Suppression requires the exact run sequence');

const stoppingRun = {id, sequence: 7, intentionalAbortReason: 'stop'};
assert(state.cancellationAcknowledged({status: true, cancelled: true}, stoppingRun), 'Exact successful backend acknowledgement must permit Stop-and-close');
assert(!state.cancellationAcknowledged({status: false, cancelled: false}, stoppingRun), 'Rejected cancellation must retain the report');
assert(state.cancellationAcknowledged({status: true, cancelled: true}, stoppingRun), 'Acknowledged Stop must still close its old report after the user starts a newer run');
assert(!state.cancellationAcknowledged({status: true, cancelled: true}, {id, sequence: 7, intentionalAbortReason: 'superseded'}), 'Supersession must never use explicit Stop-and-close semantics');

console.log('Historical run-state tests passed');
