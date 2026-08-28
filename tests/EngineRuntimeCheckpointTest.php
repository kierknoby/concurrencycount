<?php

require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
require_once __DIR__ . '/../Engines/Sweep.php';

use FreePBX\modules\Concurrencycount\Engines\Original;
use FreePBX\modules\Concurrencycount\Engines\Sweep;

function checkpoint_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$coalesce = function (array $times): array { return []; };
$originalInterrupted = false;
$original = new Original([
	'all_names' => ['gamma' => true],
	'coalesce_ranges' => $coalesce,
	'check_overrun' => function (int $processed, int $total, string $stage = '') use (&$originalInterrupted): void {
		if ($stage === 'original-occupied-seconds') {
			$originalInterrupted = true;
			throw new RuntimeException('simulated hard limit');
		}
	},
]);
try {
	$original->calculatePerName('trunk', [['calldate' => '2026-08-28 00:00:00', 'duration' => 9000, 'identity' => 'gamma']]);
} catch (RuntimeException $exception) {}
checkpoint_assert($originalInterrupted, 'Original must be interruptible inside one long occupied-second row');

$originalTotals = [];
$measureOriginal = function (array $fixture) use (&$originalTotals, $coalesce): void {
	$engine = new Original([
		'all_names' => ['gamma' => true], 'coalesce_ranges' => $coalesce,
		'check_overrun' => function (int $processed, int $total) use (&$originalTotals): void {
			if ($processed === 0) $originalTotals[] = $total;
		},
	]);
	$engine->calculatePerName('trunk', $fixture);
};
$shortRows = [];
for ($index = 0; $index < 20; $index++) $shortRows[] = ['calldate' => '2026-08-28 00:00:00', 'duration' => 1, 'identity' => 'gamma'];
$measureOriginal($shortRows);
$measureOriginal([['calldate' => '2026-08-28 00:00:00', 'duration' => 100, 'identity' => 'gamma']]);
checkpoint_assert($originalTotals === [40, 101], 'Original work units must count occupied seconds, so one long CDR outweighs many short CDRs');

$rows = [];
for ($index = 0; $index < 3000; $index++) {
	$rows[] = ['calldate' => date('Y-m-d H:i:s', 1787875200 + (3000 - $index)), 'duration' => 30, 'identity' => 'gamma', 'extension_legs' => 1];
}
$sweepSortInterrupted = false;
$sweep = new Sweep([
	'all_names' => ['gamma' => true],
	'coalesce_ranges' => $coalesce,
	'check_overrun' => function (int $processed, int $total, string $stage = '') use (&$sweepSortInterrupted): void {
		if ($stage === 'sweep-sort') {
			$sweepSortInterrupted = true;
			throw new RuntimeException('simulated hard limit');
		}
	},
]);
try { $sweep->calculatePerName('trunk', $rows); } catch (RuntimeException $exception) {}
checkpoint_assert($sweepSortInterrupted, 'Sweep must be interruptible during event sorting after row ingestion');

$sweepTraversalInterrupted = false;
$sweep = new Sweep([
	'all_names' => ['gamma' => true],
	'coalesce_ranges' => $coalesce,
	'check_overrun' => function (int $processed, int $total, string $stage = '') use (&$sweepTraversalInterrupted): void {
		if ($stage === 'sweep-event-traversal') {
			$sweepTraversalInterrupted = true;
			throw new RuntimeException('simulated hard limit');
		}
	},
]);
try { $sweep->calculateGroup($rows); } catch (RuntimeException $exception) {}
checkpoint_assert($sweepTraversalInterrupted, 'Sweep must be interruptible during event traversal after sorting');

$sweepGroupPeakInterrupted = false;
$sweep = new Sweep([
	'all_names' => ['gamma' => true],
	'coalesce_ranges' => $coalesce,
	'check_overrun' => function (int $processed, int $total, string $stage = '') use (&$sweepGroupPeakInterrupted): void {
		if ($stage === 'sweep-group-peak-traversal') {
			$sweepGroupPeakInterrupted = true;
			throw new RuntimeException('simulated hard limit');
		}
	},
]);
try { $sweep->calculateGroup($rows); } catch (RuntimeException $exception) {}
checkpoint_assert($sweepGroupPeakInterrupted, 'Sweep Group must be interruptible during its second event traversal');

$sweepTotals = [];
foreach ([1, 10000] as $duration) {
	$engine = new Sweep([
		'all_names' => ['gamma' => true], 'coalesce_ranges' => $coalesce,
		'check_overrun' => function (int $processed, int $total) use (&$sweepTotals): void {
			if ($processed === 0) $sweepTotals[] = $total;
		},
	]);
	$engine->calculatePerName('trunk', [['calldate' => '2026-08-28 00:00:00', 'duration' => $duration, 'identity' => 'gamma']]);
}
checkpoint_assert($sweepTotals[0] === $sweepTotals[1] && $sweepTotals[0] > 1, 'Sweep work units must model event construction, sorting and traversal rather than occupied seconds');

echo "Engine runtime checkpoint tests passed\n";
