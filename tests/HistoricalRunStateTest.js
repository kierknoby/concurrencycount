'use strict';

const state = require('../assets/js/historical-run-state.js');
const id = '00112233445566778899aabbccddeeff';

function assert(condition, message) {
	if (!condition) throw new Error(message);
}

assert(!state.shouldReportFailure({id, sequence: 1, intentionalAbortReason: 'stop'}, 'abort'), 'Stop-triggered run abort must be suppressed');
assert(!state.shouldReportFailure({id, sequence: 2, intentionalAbortReason: 'superseded'}, 'abort'), 'Superseded run abort must be suppressed');
assert(state.shouldReportFailure({id, sequence: 3, intentionalAbortReason: null}, 'abort'), 'Unexpected abort must still be reported');
assert(state.shouldReportFailure({id, sequence: 4, intentionalAbortReason: 'stop'}, 'error'), 'A genuine network/server failure must still be reported even after Stop state');
assert(state.shouldReportFailure({id, sequence: 5, intentionalAbortReason: 'superseded'}, 'parsererror'), 'Malformed responses must still be reported');
assert(state.shouldReportFailure({id: 'wrong-id', sequence: 6, intentionalAbortReason: 'stop'}, 'abort'), 'Suppression requires a valid calculation ID');
assert(state.shouldReportFailure({id, sequence: null, intentionalAbortReason: 'stop'}, 'abort'), 'Suppression requires the exact run sequence');

console.log('Historical run-state tests passed');
