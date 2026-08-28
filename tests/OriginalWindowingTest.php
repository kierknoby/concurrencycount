<?php

require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
require_once __DIR__ . '/../Engines/Sweep.php';

use FreePBX\modules\Concurrencycount\Engines\Original;
use FreePBX\modules\Concurrencycount\Engines\Sweep;

function window_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

function window_engine(array $names = [], ?callable $observer = null): Original {
	return new Original(['all_names' => $names, 'coalesce_ranges' => function (array $times): array { return []; }, 'window_observer' => $observer]);
}

function cdrgen_reference_peak(array $intervals): int {
	$minimum = min(array_column($intervals, 0));
	$maximum = max(array_column($intervals, 1));
	$peak = 0;
	$firstChunk = $minimum - ($minimum % 3600);
	for ($chunkStart = $firstChunk; $chunkStart <= $maximum; $chunkStart += 3600) {
		$chunkEnd = min($chunkStart + 3599, $maximum);
		$seconds = [];
		foreach ($intervals as $interval) {
			$from = max($interval[0], $chunkStart);
			$to = min($interval[1], $chunkEnd);
			for ($ts = $from; $ts <= $to; $ts++) {
				$seconds[$ts] = isset($seconds[$ts]) ? $seconds[$ts] + 1 : 1;
				$peak = max($peak, $seconds[$ts]);
			}
		}
	}
	return $peak;
}

$boundaryRows = [
	['calldate' => '2026-08-28 12:59:58', 'duration' => 5, 'identity' => 'alpha', 'extension_legs' => 1],
	['calldate' => '2026-08-28 12:59:59', 'duration' => 3, 'identity' => 'alpha', 'extension_legs' => 1],
];
$perName = window_engine(['alpha' => true])->calculatePerName('trunk', $boundaryRows);
window_assert($perName['per_name']['alpha'] === 2 && $perName['global_max'] === 2, 'Trunk peak spanning a one-hour boundary must remain exact');
$referenceIntervals = [];
foreach ($boundaryRows as $row) {
	$start = strtotime($row['calldate']);
	$referenceIntervals[] = [$start, $start + $row['duration']];
}
window_assert($perName['global_max'] === cdrgen_reference_peak($referenceIntervals), 'Original boundary peak must match the independently adapted cdrgen reference algorithm');
$group = window_engine()->calculateGroup($boundaryRows);
window_assert($group['max_concurrency'] === 2 && $group['peak_ranges'] === [['from' => '2026-08-28 12:59:59', 'to' => '2026-08-28 13:00:02']], 'Peak range must merge continuously across an internal window boundary');

$touching = [
	['calldate' => '2026-08-28 12:59:59', 'duration' => 1, 'identity' => '100', 'extension_legs' => 1],
	['calldate' => '2026-08-28 13:00:00', 'duration' => 1, 'identity' => 'beta', 'extension_legs' => 1],
];
$touchingGroup = window_engine()->calculateGroup($touching);
window_assert($touchingGroup['max_concurrency'] === 2 && $touchingGroup['peak_ranges'] === [['from' => '2026-08-28 13:00:00', 'to' => '2026-08-28 13:00:00']], 'Inclusive end/start overlap at a chunk boundary must count both calls');
$touchingNames = window_engine(['100' => true, 'beta' => true])->calculatePerName('extension', $touching);
window_assert(isset($touchingNames['per_name']['100'], $touchingNames['per_name']['beta']) && $touchingNames['per_name']['100'] === 1 && $touchingNames['per_name']['beta'] === 1, 'Numeric and alphanumeric identities must survive chunking unchanged');

$many = [];
for ($index = 0; $index < 12; $index++) $many[] = ['calldate' => '2026-08-28 12:59:55', 'duration' => 20, 'identity' => 'gamma', 'extension_legs' => 1];
$manyGroup = window_engine()->calculateGroup($many);
window_assert($manyGroup['max_concurrency'] === 12 && $manyGroup['peak_ranges'][0] === ['from' => '2026-08-28 12:59:55', 'to' => '2026-08-28 13:00:15'], 'Many overlapping boundary-spanning calls must retain one exact range');

$idleRows = [
	['calldate' => '2026-01-01 00:00:00', 'duration' => 1, 'identity' => 'alpha', 'extension_legs' => 1],
	['calldate' => '2026-03-01 00:00:00', 'duration' => 1, 'identity' => 'alpha', 'extension_legs' => 1],
];
$idle = window_engine(['alpha' => true])->calculatePerName('trunk', $idleRows);
window_assert($idle['global_max'] === 1, 'Long idle gaps must not change the result');

$cap = window_engine()->calculateGroup([['calldate' => '2026-08-01 00:00:00', 'duration' => 90000, 'extension_legs' => 2]]);
window_assert($cap['max_concurrency'] === 2 && $cap['peak_ranges'] === [['from' => '2026-08-01 00:00:00', 'to' => '2026-08-02 00:00:00']], 'Group must preserve its inclusive 86,400-second contribution cap');

$observations = [];
$longRows = [
	['calldate' => '2026-08-01 00:00:00', 'duration' => 36000, 'identity' => 'alpha', 'extension_legs' => 1],
	['calldate' => '2026-08-01 00:00:00', 'duration' => 36000, 'identity' => 'beta', 'extension_legs' => 1],
];
if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
$before = memory_get_usage(false);
$large = window_engine(['alpha' => true, 'beta' => true], function (array $observation) use (&$observations): void { $observations[] = $observation; })->calculatePerName('trunk', $longRows);
$peakDelta = max(0, memory_get_peak_usage(false) - $before);
window_assert($large['global_max'] === 1 && count($observations) === 11, 'A ten-hour call must be evaluated over eleven aligned windows including its inclusive endpoint');
window_assert(max(array_column($observations, 'timestamp_keys')) <= Original::WINDOW_SECONDS, 'No per-identity timestamp map may exceed one window');
window_assert(max(array_column($observations, 'identity_timestamp_keys')) <= Original::WINDOW_SECONDS * 2, 'Per-window identity-second state must be bounded by window size times active identities');

$sweep = new Sweep(['all_names' => ['alpha' => true], 'coalesce_ranges' => function (array $times): array { return []; }]);
window_assert($sweep->calculatePerName('trunk', $boundaryRows) === window_engine(['alpha' => true])->calculatePerName('trunk', $boundaryRows), 'Chunk-boundary Trunk result must exactly match Sweep');

echo 'Original windowing tests passed; windows=' . count($observations) . ', max_identity_seconds=' . max(array_column($observations, 'timestamp_keys')) . ', peak_memory_delta=' . $peakDelta . " bytes\n";
