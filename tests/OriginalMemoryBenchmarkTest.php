<?php

require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
require_once __DIR__ . '/../Engines/Sweep.php';

use FreePBX\modules\Concurrencycount\Engines\Original;
use FreePBX\modules\Concurrencycount\Engines\Sweep;

function benchmark_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$workloads = [
	'small' => ['rows' => 10, 'duration' => 60, 'span' => 3600, 'identities' => 2],
	'medium' => ['rows' => 80, 'duration' => 300, 'span' => 86400, 'identities' => 5],
	'large' => ['rows' => 240, 'duration' => 600, 'span' => 604800, 'identities' => 8],
	'production-shaped' => ['rows' => 400, 'duration' => 1200, 'span' => 2592000, 'identities' => 12],
];
$origin = strtotime('2026-01-01 00:00:00');

foreach ($workloads as $label => $spec) {
	$rows = [];
	$names = [];
	for ($index = 0; $index < $spec['rows']; $index++) {
		$name = 'endpoint-' . ($index % $spec['identities']);
		$names[$name] = true;
		$offset = $spec['rows'] === 1 ? 0 : (int)floor(($spec['span'] - 1) * $index / ($spec['rows'] - 1));
		$rows[] = ['calldate' => date('Y-m-d H:i:s', $origin + $offset), 'duration' => $spec['duration'], 'identity' => $name, 'extension_legs' => 1];
	}
	$observations = [];
	$coalesce = function (array $times): array { return []; };
	if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
	$memoryBefore = memory_get_usage(false);
	$started = microtime(true);
	$original = new Original(['all_names' => $names, 'coalesce_ranges' => $coalesce, 'window_observer' => function (array $item) use (&$observations): void { $observations[] = $item; }]);
	$result = $original->calculatePerName('trunk', $rows);
	$elapsed = microtime(true) - $started;
	$peakDelta = max(0, memory_get_peak_usage(false) - $memoryBefore);
	$sweep = new Sweep(['all_names' => $names, 'coalesce_ranges' => $coalesce]);
	benchmark_assert($result === $sweep->calculatePerName('trunk', $rows), $label . ' result must exactly match Sweep');
	$maxTimestampKeys = empty($observations) ? 0 : max(array_column($observations, 'timestamp_keys'));
	benchmark_assert($maxTimestampKeys <= Original::WINDOW_SECONDS, $label . ' per-identity seconds exceeded one window');
	echo sprintf("Original benchmark %-17s rows=%d work=%d windows=%d elapsed=%.4fs peak_delta=%d max_timestamp_keys=%d peak=%d\n",
		$label, $spec['rows'], $spec['rows'] * ($spec['duration'] + 1), count($observations), $elapsed, $peakDelta, $maxTimestampKeys, $result['global_max']);
}

echo "Original memory benchmark tests passed\n";
