<?php

require_once __DIR__ . '/../Services/HistoricalCdrAcquisition.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalCdrAcquisition;
function acquisition_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
function acquisition_clock(array $durations): callable {
	$time = 0.0; $index = 0; $starting = true;
	return function () use (&$time, &$index, &$starting, $durations): float {
		if ($starting) { $starting = false; return $time; }
		$time += $durations[min($index, count($durations) - 1)]; $index++; $starting = true; return $time;
	};
}

$calls = [];
$service = new HistoricalCdrAcquisition(function ($from, $to, $inclusive) use (&$calls) {
	$calls[] = [$from, $to, $inclusive];
	$span = strtotime($to . ' UTC') - strtotime($from . ' UTC');
	if ($span > 3600) throw new RuntimeException('statement timeout');
	return [[$from, $to, $inclusive]];
}, function ($e) { return $e->getMessage() === 'statement timeout'; });
$rows = $service->fetch('2026-09-08 00:00:00', '2026-09-08 06:00:00');
acquisition_assert(count($rows) === 8, 'Timed-out six-hour range must bisect to successful bounded ranges');
for ($index = 1; $index < count($rows); $index++) acquisition_assert($rows[$index - 1][1] === $rows[$index][0], 'Adaptive boundaries must be contiguous');
acquisition_assert($rows[0][0] === '2026-09-08 00:00:00' && $rows[count($rows) - 1][1] === '2026-09-08 06:00:00', 'Adaptive ranges must preserve exact outer boundaries');
acquisition_assert($rows[count($rows) - 1][2] === true && count(array_filter($rows, function ($row) { return $row[2]; })) === 1, 'Only the final range may include its upper boundary');
$unrelated = new HistoricalCdrAcquisition(function () { throw new RuntimeException('permission denied'); }, function () { return false; });
$propagated = false; try { $unrelated->fetch('2026-09-08 00:00:00', '2026-09-08 01:00:00'); } catch (RuntimeException $e) { $propagated = $e->getMessage() === 'permission denied'; }
acquisition_assert($propagated, 'Unrelated database errors must not be retried as timeouts');
$produced = 0; $incrementalCheckpoint = false;
$incremental = new HistoricalCdrAcquisition(function () use (&$produced) {
	return (function () use (&$produced) { for ($i = 0; $i < 1024; $i++) { $produced++; yield ['row' => $i]; } })();
}, function () { return false; }, function () use (&$produced, &$incrementalCheckpoint) { if ($produced > 0 && $produced < 1024) $incrementalCheckpoint = true; });
$incrementalRows = $incremental->fetch('2026-09-08 00:00:00', '2026-09-08 00:01:00');
acquisition_assert(count($incrementalRows) === 1024 && $incrementalCheckpoint, 'Iterable ranges are appended and checkpointed before the producer completes');
$legacyCalls = []; $legacyCheckpoints = 0;
$legacy = new HistoricalCdrAcquisition(function ($from, $to, $inclusive) use (&$legacyCalls) {
	$legacyCalls[] = [$from, $to, $inclusive]; return [[$from, $to]];
}, function () { return false; }, function () use (&$legacyCheckpoints) { $legacyCheckpoints++; }, 900, true, acquisition_clock([0.1]));
$legacyRows = $legacy->fetch('2026-09-08 00:00:00', '2026-09-08 01:00:05');
acquisition_assert(count($legacyCalls) === count($legacyRows), 'Legacy acquisition must return every successful adaptive range');
for ($index = 1; $index < count($legacyCalls); $index++) acquisition_assert($legacyCalls[$index - 1][1] === $legacyCalls[$index][0], 'Legacy acquisition windows must have no gaps or overlaps');
acquisition_assert($legacyCalls[0] === ['2026-09-08 00:00:00', '2026-09-08 00:15:00', false], 'Legacy acquisition must begin with a bounded fifteen-minute range');
acquisition_assert($legacyCalls[count($legacyCalls) - 1][1] === '2026-09-08 01:00:05', 'Legacy acquisition must preserve the exact outer boundary');
acquisition_assert(count(array_filter($legacyCalls, function ($row) { return $row[2]; })) === 1 && $legacyCalls[count($legacyCalls) - 1][2], 'Only the final legacy range may include its upper boundary');
acquisition_assert($legacyCheckpoints === count($legacyCalls), 'Legacy acquisition must checkpoint before every database window');

$slowCalls = [];
$slow = new HistoricalCdrAcquisition(function ($from, $to) use (&$slowCalls) { $slowCalls[] = strtotime($to . ' UTC') - strtotime($from . ' UTC'); return []; }, function () { return false; }, null, 900, true, acquisition_clock([1.1, .5, .5]));
$slow->fetch('2026-09-08 00:00:00', '2026-09-08 00:40:00');
acquisition_assert($slowCalls[0] === 900 && $slowCalls[1] === 450, 'A slow successful legacy query must halve the next window');

$minimumCalls = [];
$minimum = new HistoricalCdrAcquisition(function ($from, $to) use (&$minimumCalls) { $minimumCalls[] = strtotime($to . ' UTC') - strtotime($from . ' UTC'); return []; }, function () { return false; }, null, 900, true, acquisition_clock([2.0]));
$minimum->fetch('2026-09-08 00:00:00', '2026-09-08 00:40:00');
$nonFinalMinimumCalls = array_slice($minimumCalls, 0, -1);
acquisition_assert(min($nonFinalMinimumCalls) >= 60 && in_array(60, $nonFinalMinimumCalls, true), 'Adaptive legacy windows must reach but never cross the one-minute minimum before an exact final remainder');

$growthCalls = [];
$growth = new HistoricalCdrAcquisition(function ($from, $to) use (&$growthCalls) { $growthCalls[] = strtotime($to . ' UTC') - strtotime($from . ' UTC'); return []; }, function () { return false; }, null, 900, true, acquisition_clock([.1]));
$growth->fetch('2026-01-01 00:00:00', '2026-01-05 00:00:00');
acquisition_assert($growthCalls[0] === 900 && $growthCalls[1] === 900 && $growthCalls[2] === 1800, 'Two consistently fast legacy queries must grow the next window');
acquisition_assert(max($growthCalls) <= HistoricalCdrAcquisition::INITIAL_CHUNK_SECONDS && in_array(HistoricalCdrAcquisition::INITIAL_CHUNK_SECONDS, $growthCalls, true), 'Adaptive legacy growth must reach but never exceed six hours');

$longCalls = 0;
$long = new HistoricalCdrAcquisition(function () use (&$longCalls) { $longCalls++; return []; }, function () { return false; }, null, 900, true, acquisition_clock([.1]));
$long->fetch('2025-01-01 00:00:00', '2026-01-01 00:00:00');
acquisition_assert($longCalls < 2000, 'A fast representative year must not issue one query per minute');

$cancelCalls = 0; $cancelChecks = 0;
$legacyCancellation = new HistoricalCdrAcquisition(function () use (&$cancelCalls) { $cancelCalls++; return []; }, function () { return false; }, function () use (&$cancelChecks) {
	$cancelChecks++; if ($cancelChecks === 3) throw new RuntimeException('cancelled');
}, 900, true, acquisition_clock([.1]));
$cancelledBetweenWindows = false;
try { $legacyCancellation->fetch('2026-09-08 00:00:00', '2026-09-08 02:00:00'); } catch (RuntimeException $e) { $cancelledBetweenWindows = $e->getMessage() === 'cancelled'; }
acquisition_assert($cancelledBetweenWindows && $cancelCalls === 2, 'Legacy cancellation must stop before the next database window');
echo "Historical CDR acquisition tests passed\n";
