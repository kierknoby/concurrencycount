<?php

require_once __DIR__ . '/../Analyzers/PeakDetailAnalyser.php';

use FreePBX\modules\Concurrencycount\Analyzers\PeakDetailAnalyser;

function peak_detail_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function peak_detail_row(string $start, int $duration, string $trunk, string $suffix): array {
	return [
		'calldate' => $start,
		'duration' => $duration,
		'chan' => 'PJSIP/' . $trunk . '-' . $suffix,
		'identity' => $trunk,
	];
}

$analyser = new PeakDetailAnalyser();

$single = $analyser->analyseTrunk([
	peak_detail_row('2026-08-24 10:12:04', 347, 'alpha', 'aaaaaa'),
	peak_detail_row('2026-08-24 10:13:38', 249, 'alpha', 'bbbbbb'),
	peak_detail_row('2026-08-24 10:14:11', 238, 'alpha', 'cccccc'),
	peak_detail_row('2026-08-24 10:14:22', 391, 'alpha', 'dddddd'),
], 'alpha', 4);
peak_detail_assert_same(4, $single['peak'], 'Single occurrence peak');
peak_detail_assert_same(1, count($single['occurrences']), 'Single occurrence count');
peak_detail_assert_same('2026-08-24 10:14:22', $single['occurrences'][0]['from'], 'Single occurrence start');
peak_detail_assert_same('2026-08-24 10:17:47', $single['occurrences'][0]['to'], 'Single occurrence end');
peak_detail_assert_same([0, 1, 2, 3], $single['occurrences'][0]['row_indexes'], 'Single occurrence contributors');

$multiple = $analyser->analyseTrunk([
	peak_detail_row('2026-08-24 11:00:00', 10, 'alpha', 'aaaaaa'),
	peak_detail_row('2026-08-24 11:00:05', 2, 'alpha', 'bbbbbb'),
	peak_detail_row('2026-08-24 11:00:09', 1, 'alpha', 'cccccc'),
], 'alpha', 2);
peak_detail_assert_same(2, count($multiple['occurrences']), 'Distinct occurrence count');
peak_detail_assert_same('2026-08-24 11:00:05', $multiple['occurrences'][0]['from'], 'First distinct occurrence start');
peak_detail_assert_same('2026-08-24 11:00:07', $multiple['occurrences'][0]['to'], 'First distinct occurrence end');
peak_detail_assert_same('2026-08-24 11:00:09', $multiple['occurrences'][1]['from'], 'Second distinct occurrence start');
peak_detail_assert_same('2026-08-24 11:00:10', $multiple['occurrences'][1]['to'], 'Second distinct occurrence end');

$touching = $analyser->analyseTrunk([
	peak_detail_row('2026-08-24 12:00:00', 60, 'alpha', 'aaaaaa'),
	peak_detail_row('2026-08-24 12:01:00', 60, 'alpha', 'bbbbbb'),
], 'alpha', 2);
peak_detail_assert_same('2026-08-24 12:01:00', $touching['occurrences'][0]['from'], 'Inclusive touching start');
peak_detail_assert_same('2026-08-24 12:01:00', $touching['occurrences'][0]['to'], 'Inclusive touching end');
peak_detail_assert_same(1, $touching['occurrences'][0]['sample_seconds'], 'Inclusive touching sample count');
peak_detail_assert_same(1, $touching['occurrences'][0]['duration_seconds'], 'Inclusive touching displayed duration');

$isolated = $analyser->analyseTrunk([
	peak_detail_row('2026-08-24 13:00:00', 60, 'alpha', 'aaaaaa'),
	peak_detail_row('2026-08-24 13:00:00', 60, '123', 'bbbbbb'),
	peak_detail_row('2026-08-24 13:00:00', 60, 'beta', 'cccccc'),
], 'alpha', 1);
peak_detail_assert_same([0], $isolated['occurrences'][0]['row_indexes'], 'Only selected trunk contributes');

$mismatchThrown = false;
try {
	$analyser->analyseTrunk([peak_detail_row('2026-08-24 14:00:00', 60, 'alpha', 'aaaaaa')], 'alpha', 2);
} catch (UnexpectedValueException $exception) {
	$mismatchThrown = true;
}
peak_detail_assert_same(true, $mismatchThrown, 'Engine mismatch must fail closed');

echo "Peak detail analyser tests passed\n";
