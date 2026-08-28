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

echo "Engine runtime checkpoint tests passed\n";
