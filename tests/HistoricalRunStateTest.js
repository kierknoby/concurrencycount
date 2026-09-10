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
assert(state.isSameRun({id, sequence: 9}, {id, sequence: 9}), 'Warning continuation may act on its exact calculation ID and browser sequence');
assert(!state.isSameRun({id, sequence: 10}, {id, sequence: 9}), 'Stale warning cannot act on a newer browser run');
assert(!state.isSameRun({id: 'ffeeddccbbaa99887766554433221100', sequence: 9}, {id, sequence: 9}), 'Another calculation ID cannot inherit warning continuation state');

assert(!state.hasMeaningfulMessage(''), 'Empty report notice must remain hidden');
assert(!state.hasMeaningfulMessage(null), 'Null report notice must remain hidden');
assert(!state.hasMeaningfulMessage(undefined), 'Undefined report notice must remain hidden');
assert(!state.hasMeaningfulMessage(' \t\n '), 'Whitespace-only report notice must remain hidden');
assert(!state.hasMeaningfulMessage('<span> \n&nbsp;</span>'), 'Empty generated markup must remain hidden');
assert(state.hasMeaningfulMessage('Some calls were omitted.'), 'Meaningful report warning must remain visible');
assert(state.hasMeaningfulMessage('<strong>Partial data:</strong> one source was unavailable.'), 'Meaningful notice text inside markup must remain visible');

const submittedCriteria = {
	name: 'Capacity year', mode: 'trunk', engine: 'sweep', preset: 'custom',
	range_from: '2025-01-01', range_to: '2025-12-31', include_time: true,
	from_time: '00:00', to_time: '23:59', filter: 'gamma', minimum_concurrency: 4,
	start: '2025-01-01 00:00:00', end: '2025-12-31 23:59:59',
	excluded_call_configuration: {count: 2, fingerprint: 'abc123'}
};
const savedCriteria = state.snapshotCriteria(submittedCriteria);
const editDraft = state.snapshotCriteria(savedCriteria);
assert(JSON.stringify(savedCriteria) === JSON.stringify(submittedCriteria), 'Edit Report must retain every submitted report criterion and exclusion snapshot');
editDraft.minimum_concurrency = 5;
editDraft.range_to = '2025-11-30';
editDraft.excluded_call_configuration.count = 9;
assert(savedCriteria.minimum_concurrency === 4 && savedCriteria.range_to === '2025-12-31' && savedCriteria.excluded_call_configuration.count === 2, 'Opening and editing a draft must not mutate the displayed result criteria');
assert(JSON.stringify(state.snapshotCriteria(savedCriteria)) === JSON.stringify(savedCriteria), 'Run Again must reproduce the exact saved submitted criteria');
assert(state.snapshotCriteria(editDraft).minimum_concurrency === 5 && state.snapshotCriteria(editDraft).range_to === '2025-11-30', 'Edited criteria must be captured for the revised submission');

const rerunReport = {result: {global_max: 9}, graphSeries: {series: {}}, occurrenceCache: {one: {}}, firstRunPending: false};
state.clearReportResult(rerunReport);
assert(rerunReport.result === null && rerunReport.graphSeries === null && Object.keys(rerunReport.occurrenceCache).length === 0 && rerunReport.calculationPending && !rerunReport.firstRunPending, 'Edited rerun must discard cached output and use a distinct transient pending state');
assert(!state.isDiscardableFirstRun(rerunReport), 'A failed edited rerun cannot qualify for unused first-run deletion');
assert(state.isDiscardableFirstRun({firstRunPending: true}), 'A genuinely new report retains established failed-first-run cleanup eligibility');
assert(state.countingMessage('trunk', '2025-01-01', '2025-12-31').indexOf('Counting PJSIP trunk call data from 2025-01-01 to 2025-12-31') === 0, 'First and restored active calculations share the visible Counting status');

const inventory = {
	trunk: [{value: 'gamma', label: 'Gamma Carrier (gamma)'}],
	extension: [{value: '203', label: 'Extension 203'}, {value: '204', label: 'Extension 204'}]
};
const trunks = state.endpointChoices(inventory, 'trunk', 'gamma');
assert(!trunks.disabled && trunks.selected === 'gamma' && trunks.options[0].label === 'Gamma Carrier (gamma)', 'Trunk mode restores an exact configured trunk with its human-readable label');
const extensions = state.endpointChoices(inventory, 'extension', '204');
assert(!extensions.disabled && extensions.selected === '204' && extensions.options.length === 2, 'Extension mode offers configured extensions and restores the selected identifier');
const group = state.endpointChoices(inventory, 'group', 'gamma');
assert(group.disabled && group.selected === '' && group.options.length === 0, 'Group mode disables endpoint filtering and forces an empty value');
const stale = state.endpointChoices(inventory, 'trunk', 'removed-trunk');
assert(stale.stale && stale.selected === 'removed-trunk' && stale.options[stale.options.length - 1].label.indexOf('no longer configured') !== -1, 'A removed saved endpoint remains explicit and is never substituted');
const pendingSelections = {trunk: 'gamma', extension: ''};
assert(state.endpointSelectionForMode('extension', pendingSelections) === '' && state.endpointChoices(inventory, 'extension', state.endpointSelectionForMode('extension', pendingSelections)).options.length === 2, 'A Trunk-to-Extension switch before inventory completion renders current Extension choices without carrying the trunk');
assert(state.endpointSelectionForMode('group', pendingSelections) === '', 'Group remains runnable with an empty filter while inventory is pending');
assert(!state.requiresEndpointInventory('group'), 'Initial Group mode is runnable without starting or awaiting endpoint inventory');
assert(state.requiresEndpointInventory('trunk') && state.requiresEndpointInventory('extension'), 'Trunk and Extension wait for authoritative endpoint choices');
const initialGroup = state.initialEndpointState('group');
assert(!initialGroup.loadsInventory && !initialGroup.runDisabled && initialGroup.filterDisabled && initialGroup.filter === '', 'Initial Group skips inventory, keeps Run enabled, and forces a disabled empty filter before any AJAX response');
const initialTrunk = state.initialEndpointState('trunk');
assert(initialTrunk.loadsInventory && initialTrunk.runDisabled, 'Initial Trunk still waits for authoritative inventory before Run is enabled');
assert(state.endpointModeAction('trunk', false, false) === 'load', 'Initial Group-to-Trunk starts authoritative inventory loading');
assert(state.endpointModeAction('extension', false, false) === 'load', 'Initial Group-to-Extension starts authoritative inventory loading');
assert(state.endpointModeAction('extension', false, true) === 'wait', 'A second mode switch waits for the single in-flight inventory request');
assert(state.endpointModeAction('group', false, true) === 'group', 'Returning to Group remains immediately runnable without another inventory request');
assert(state.endpointModeAction('trunk', true, false) === 'render' && state.endpointModeAction('extension', true, false) === 'render', 'Cached inventory renders subsequent endpoint modes without another request');
const separateSelections = {trunk: 'gamma', extension: '204'};
assert(state.endpointSelectionForMode('trunk', separateSelections) === 'gamma' && state.endpointSelectionForMode('extension', separateSelections) === '204', 'Trunk and Extension selections remain independent across mode switches');

console.log('Historical run-state tests passed');
