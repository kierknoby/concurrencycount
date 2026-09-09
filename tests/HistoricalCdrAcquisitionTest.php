<?php

require_once __DIR__ . '/../Services/HistoricalCdrAcquisition.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalCdrAcquisition;
function acquisition_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

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
echo "Historical CDR acquisition tests passed\n";
