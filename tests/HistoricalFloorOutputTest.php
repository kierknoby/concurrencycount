<?php

if (!interface_exists('BMO')) {
	interface BMO {}
}
require_once dirname(__DIR__) . '/Concurrencycount.class.php';

class HistoricalFloorOutputConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public function __construct() {}
	public function getVersion(): string { return '2.2.0'; }
}

function floor_output_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$cc = new HistoricalFloorOutputConcurrencycount();
$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalResultFloor();
$base = [
	'mode' => 'extension', 'start' => '2026-01-01 00:00:00', 'end' => '2026-01-01 23:59:59',
	'rows_processed' => 10, 'warning' => 'Exact completed result.', 'global_max' => 3,
	'per_name' => ['201' => 1, '202' => 3],
];
$result = $service->apply($base, 4);
$csv = $cc->resultsToCsv($result);
floor_output_assert(strpos($csv, 'Global maximum') !== false && strpos($csv, ',3') !== false, 'CSV must retain the actual calculated peak');
floor_output_assert(strpos($csv, 'No periods reached the minimum concurrency of 4 during the selected date range.') !== false, 'CSV must explain a completed zero-detail floor result');
floor_output_assert(strpos($csv, "201,1") === false && strpos($csv, "202,3") === false, 'CSV must exclude detail below the floor');

$emailMethod = new ReflectionMethod($cc, 'buildEmailBody');
$emailMethod->setAccessible(true);
$email = $emailMethod->invoke($cc, $result);
floor_output_assert(strpos($email, 'Global maximum: 3') !== false, 'Email must retain the actual calculated peak');
floor_output_assert(strpos($email, 'No periods reached the minimum concurrency of 4 during the selected date range.') !== false, 'Email must explain a completed zero-detail floor result');
floor_output_assert(strpos($email, "\n201") === false && strpos($email, "\n202") === false, 'Email must exclude detail below the floor');

$realEmpty = $service->apply([
	'mode' => 'extension', 'start' => $base['start'], 'end' => $base['end'], 'rows_processed' => 0,
	'warning' => 'No data.', 'global_max' => 0, 'per_name' => [], 'empty_message' => 'No eligible Historical data existed.',
], 4);
$emptyCsv = $cc->resultsToCsv($realEmpty);
floor_output_assert(strpos($emptyCsv, 'No eligible Historical data existed.') !== false, 'CSV must retain the genuine no-data message');
floor_output_assert(strpos($emptyCsv, 'No periods reached the minimum concurrency') === false, 'No-data CSV must not be represented as a floor no-match');

echo "Historical floor output tests passed\n";
