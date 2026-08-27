<?php

require_once __DIR__ . '/../Services/HistoricalReportsService.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalReportsService;

function hr_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

function hr_definition(array $overrides = []): array {
	return array_merge([
		'mode' => 'trunk', 'engine' => 'original', 'preset' => 'last7',
		'range_from' => '2026-08-20', 'range_to' => '2026-08-26',
		'include_time' => false, 'from_time' => '00:00', 'to_time' => '23:59',
		'filter' => '', 'name' => 'Historic Report 1',
	], $overrides);
}

$service = new HistoricalReportsService();

/* Creation, numbering, stable IDs */
$stored = $service->defaults();
list($stored, $first) = $service->createReport($stored, hr_definition(), 'aaaa0000aaaa0000aaaa0000aaaa0000');
hr_assert(1 === $first['number'], 'First report allocates number 1');
hr_assert('Historic Report 1' === $first['name'], 'First report named Historic Report 1');
list($stored, $second) = $service->createReport($stored, hr_definition(['mode' => 'extension', 'name' => 'Historic Report 2']), 'bbbb0000bbbb0000bbbb0000bbbb0000');
hr_assert(2 === $second['number'], 'Second report allocates number 2');
hr_assert('Historic Report 2' === $second['name'], 'Second report named Historic Report 2');
hr_assert($first['id'] !== $second['id'], 'Stable internal IDs do not collide');
hr_assert($stored['active_id'] === $second['id'], 'Newly created report becomes active');

/* Independent definitions: report 1 mode/range must not be altered by report 2's creation */
hr_assert('trunk' === $stored['reports'][$first['id']]['mode'], 'Report 1 mode unaffected by report 2 creation');
hr_assert('2026-08-20' === $stored['reports'][$first['id']]['range_from'], 'Report 1 range unaffected by report 2 creation');

/* Fill to the hard limit of five, sixth must be rejected cleanly */
list($stored) = $service->createReport($stored, hr_definition(), 'cccc0000cccc0000cccc0000cccc0000');
list($stored) = $service->createReport($stored, hr_definition(), 'dddd0000dddd0000dddd0000dddd0000');
list($stored, $fifth) = $service->createReport($stored, hr_definition(), 'eeee0000eeee0000eeee0000eeee0000');
hr_assert(5 === $fifth['number'], 'Fifth report allocates number 5');
hr_assert(5 === count($stored['reports']), 'Exactly five reports open at the limit');
$sixthRejected = false;
try {
	$service->createReport($stored, hr_definition(), 'ffff0000ffff0000ffff0000ffff0000');
} catch (RuntimeException $exception) {
	$sixthRejected = true;
	hr_assert(false !== strpos($exception->getMessage(), 'Maximum of 5'), 'Sixth rejection message is the clear non-destructive limit message');
}
hr_assert(true === $sixthRejected, 'A sixth historical report is rejected cleanly');
hr_assert(5 === count($stored['reports']), 'Rejected sixth attempt does not mutate stored state');

/* Closing removes only the selected report and safely frees its number */
$stored = $service->closeReport($stored, $second['id']);
hr_assert(4 === count($stored['reports']), 'Close removes only the selected report');
hr_assert(!isset($stored['reports'][$second['id']]), 'Closed report id is gone');
hr_assert(isset($stored['reports'][$first['id']]), 'Closing report 2 does not affect report 1');
hr_assert($fifth['id'] === $stored['active_id'], 'Closing a non-active report leaves active_id untouched');
$stored = $service->closeReport($stored, $fifth['id']);
hr_assert(null === $stored['active_id'], 'Closing the active report clears active_id');
$nextNumber = $service->nextNumber($stored);
hr_assert(2 === $nextNumber, 'Freed number 2 is safely reused by the next new report');
list($stored, $reused) = $service->createReport($stored, hr_definition(['preset' => 'custom', 'range_from' => '2026-01-01', 'range_to' => '2026-01-31', 'generated_default_name' => true]), 'aaaa1111aaaa1111aaaa1111aaaa1111');
hr_assert(2 === $reused['number'], 'The reused slot is number 2');
hr_assert('Historic Report 2' === $reused['name'], 'Server-authoritative reused slot updates an untouched generated default name');

/* Custom names persist; legacy title-only definitions reconcile into the name field. */
list($stored, $custom) = $service->updateReport($stored, $first['id'], hr_definition(['name' => 'August trunk peak']));
hr_assert('August trunk peak' === $custom['name'], 'Custom report name persists through update');
$legacyDefinition = array_merge(hr_definition(), ['number' => 4, 'title' => 'Historic Report 4']);
unset($legacyDefinition['name']);
$legacy = $service->reconcileStored(['reports' => [
	'legacy00000000000000000000000000' => $legacyDefinition,
]]);
hr_assert('Historic Report 4' === $legacy['reports']['legacy00000000000000000000000000']['name'], 'Legacy title-only report reconciles to a display name');
$legacyWithoutTitle = $legacyDefinition;
unset($legacyWithoutTitle['title']);
$legacyWithoutTitle['number'] = 3;
$legacy = $service->reconcileStored(['reports' => ['legacy2' => $legacyWithoutTitle]]);
hr_assert('Historic Report 3' === $legacy['reports']['legacy2']['name'], 'Legacy report without any name derives Historic Report plus its slot');

foreach (['', str_repeat('x', 81), "bad\nname"] as $invalidName) {
	$nameRejected = false;
	try { $service->createReport($service->defaults(), hr_definition(['name' => $invalidName])); }
	catch (InvalidArgumentException $exception) { $nameRejected = true; }
	hr_assert($nameRejected, 'Invalid report name is rejected server-side');
}

/* Custom preset retains exact persisted dates; relative preset only stores the preset identity + last-known dates */
hr_assert('custom' === $reused['preset'] && '2026-01-01' === $reused['range_from'] && '2026-01-31' === $reused['range_to'], 'Custom preset persists its exact chosen dates');
hr_assert('last7' === $stored['reports'][$first['id']]['preset'], 'Relative preset identity is persisted, not only resolved dates');

/* Updating one report cannot alter another */
list($stored, $updatedFirst) = $service->updateReport($stored, $first['id'], hr_definition(['mode' => 'group', 'range_from' => '2026-08-25', 'range_to' => '2026-08-26']));
hr_assert('group' === $updatedFirst['mode'], 'Report 1 mode updates correctly');
hr_assert('trunk' === $stored['reports'][$reused['id']]['mode'], 'Report 2 mode unaffected by report 1 update');

/* Malformed persisted state is rejected/recovered safely, never fatal */
$recovered = $service->reconcileStored('not-an-array');
hr_assert(0 === count($recovered['reports']), 'A completely malformed stored value recovers to an empty, valid state');
$recovered = $service->reconcileStored([
	'reports' => [
		'validid0000000000000000000000000' => ['number' => 1, 'mode' => 'trunk', 'engine' => 'original', 'preset' => 'today', 'range_from' => '2026-08-26', 'range_to' => '2026-08-26'],
		'' => ['number' => 2, 'mode' => 'trunk', 'engine' => 'original', 'preset' => 'today', 'range_from' => '2026-08-26', 'range_to' => '2026-08-26'],
		'badnumber000000000000000000000000' => ['number' => 99, 'mode' => 'trunk', 'engine' => 'original', 'preset' => 'today', 'range_from' => '2026-08-26', 'range_to' => '2026-08-26'],
		'badmode0000000000000000000000000' => ['number' => 3, 'mode' => 'not-a-real-mode', 'engine' => 'original', 'preset' => 'today', 'range_from' => '2026-08-26', 'range_to' => '2026-08-26'],
		3 => ['number' => 4, 'mode' => 'trunk', 'engine' => 'original', 'preset' => 'today', 'range_from' => '2026-08-26', 'range_to' => '2026-08-26'],
	],
	'active_id' => 'does-not-exist',
]);
hr_assert(1 === count($recovered['reports']), 'Only the one well-formed entry survives reconciliation of malformed state');
hr_assert(null === $recovered['active_id'], 'A dangling active_id referencing nothing is dropped during reconciliation');
$overflow = ['reports' => []];
for ($i = 1; $i <= 8; $i++) {
	$overflow['reports']['id' . $i . str_repeat('0', 30)] = ['number' => $i <= 5 ? $i : 1, 'mode' => 'trunk', 'engine' => 'original', 'preset' => 'today', 'range_from' => '2026-08-26', 'range_to' => '2026-08-26'];
}
$recoveredOverflow = $service->reconcileStored($overflow);
hr_assert(5 === count($recoveredOverflow['reports']), 'Reconciliation caps overflow at the five-report hard limit');

/* Invalid definitions are rejected without creating a report */
$invalidRejected = false;
try {
	$service->createReport($service->defaults(), hr_definition(['mode' => 'bogus']));
} catch (InvalidArgumentException $exception) {
	$invalidRejected = true;
}
hr_assert(true === $invalidRejected, 'Invalid mode is rejected before persisting a new report');

foreach (['2026-02-29', '2026-02-31', '2026-13-01'] as $invalidDate) {
	$dateRejected = false;
	try { $service->createReport($service->defaults(), hr_definition(['range_from' => $invalidDate])); }
	catch (InvalidArgumentException $exception) { $dateRejected = true; }
	hr_assert($dateRejected, 'Impossible persisted calendar date is rejected: ' . $invalidDate);
}
$validLeap = $service->createReport($service->defaults(), hr_definition(['range_from' => '2024-02-29', 'range_to' => '2024-02-29']));
hr_assert('2024-02-29' === $validLeap[1]['range_from'], 'Valid leap day is retained');
$reverseRejected = false;
try { $service->createReport($service->defaults(), hr_definition(['range_from' => '2026-08-27', 'range_to' => '2026-08-26'])); }
catch (InvalidArgumentException $exception) { $reverseRejected = true; }
hr_assert($reverseRejected, 'Persisted report rejects a reversed date range');
$groupDefinition = $service->createReport($service->defaults(), hr_definition(['mode' => 'group', 'filter' => 'gamma']));
hr_assert('' === $groupDefinition[1]['filter'], 'Group definitions cannot retain an irrelevant endpoint filter');

/* Missing report id operations fail cleanly rather than crashing */
$missingRejected = false;
try {
	$service->updateReport($service->defaults(), 'unknown0000000000000000000000000', hr_definition());
} catch (InvalidArgumentException $exception) {
	$missingRejected = true;
}
hr_assert(true === $missingRejected, 'Updating a non-existent report id fails cleanly');

echo "Historical reports service tests passed\n";
