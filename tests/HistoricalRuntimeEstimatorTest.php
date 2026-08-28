<?php

require_once __DIR__ . '/../Services/HistoricalRuntimeEstimator.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;

function runtime_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$tiny = new HistoricalRuntimeEstimator(3600.0, 0.0, 10.0);
$assessment = $tiny->evaluate(1, 10000, 10.9);
runtime_assert(!$assessment['reliable'] && !$assessment['warn'] && $assessment['estimated_remaining'] === null, 'First tiny sample must not produce an estimate');

$initial = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
$assessment = $initial->evaluate(0, 1000, 0.0);
runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Zero work and zero elapsed must remain safely unavailable');
$assessment = $initial->evaluate(250, 1000, 0.2);
runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Enough work without 0.5 seconds warm-up must remain unreliable');

$insufficientWork = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
$assessment = $insufficientWork->evaluate(9, 1000, 1.0);
runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Elapsed warm-up without the minimum sample must remain unreliable');

$exact = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
$assessment = $exact->evaluate(250, 1000, 2.0);
runtime_assert($assessment['reliable'] && abs($assessment['estimated_remaining'] - 6.0) < 0.000001, '250 of 1000 units in two seconds must yield an exact six-second ETA');
$assessment = $exact->evaluate(500, 1000, 3.0);
runtime_assert($assessment['reliable'] && abs($assessment['estimated_remaining'] - 3.0) < 0.000001, 'Advancing progress must update the ETA from six to three seconds');

$nearComplete = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
$assessment = $nearComplete->evaluate(999, 1000, 1.0);
runtime_assert($assessment['reliable'] && $assessment['estimated_remaining'] > 0.0 && $assessment['estimated_remaining'] < 1.0, 'Near-complete work must preserve a positive sub-second ETA');
$assessment = $nearComplete->evaluate(1000, 1000, 1.1);
runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Completed work must not publish an active zero ETA');

$invalid = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
foreach ([[-1, 1000], [1001, 1000], [0, 0]] as $progress) {
	$assessment = $invalid->evaluate($progress[0], $progress[1], 1.0);
	runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Impossible progress must not produce an ETA');
}
$backwards = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
runtime_assert($backwards->evaluate(200, 1000, 2.0)['reliable'], 'Controlled forward progress should become reliable');
$assessment = $backwards->evaluate(100, 1000, 3.0);
runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Backwards progress must not produce a nonsense ETA');

$fast = new HistoricalRuntimeEstimator(3600.0, 0.0, 10.0);
$assessment = $fast->evaluate(100, 10000, 10.1);
runtime_assert(!$assessment['warn'] && $assessment['estimated_remaining'] === null, 'Sub-second fast work must wait for elapsed-time warm-up');
$assessment = $fast->evaluate(1000, 10000, 10.5);
runtime_assert(!$assessment['warn'] && $assessment['estimated_remaining'] < 5.0, 'Fast sustained work must not produce a false overrun');

$slow = new HistoricalRuntimeEstimator(3600.0, 0.0, 10.0);
$assessment = $slow->evaluate(100, 100000, 20.0);
runtime_assert($assessment['warn'] && $assessment['estimated_remaining'] > 3600.0, 'Sustained poor throughput must warn when projected completion exceeds the limit');

$expired = new HistoricalRuntimeEstimator(3600.0, 0.0, 3500.0);
$assessment = $expired->evaluate(1, 100000, 3600.1);
runtime_assert($assessment['abort'], 'Actual overall runtime must abort even without a usable estimate');

$prepared = new HistoricalRuntimeEstimator(3600.0, 0.0, 3590.0);
$assessment = $prepared->evaluate(100, 1000, 3590.5);
runtime_assert(!$assessment['warn'] && $assessment['estimated_remaining'] < 5.0, 'Preparation delay must not contaminate engine throughput estimation');

$confirmed = new HistoricalRuntimeEstimator(3600.0, 0.0, 10.0, true);
$assessment = $confirmed->evaluate(100, 100000, 20.0);
runtime_assert(!$assessment['warn'], 'Confirmed overrun must suppress a repeat prediction warning');
$assessment = $confirmed->evaluate(100, 100000, 3600.1);
runtime_assert($assessment['abort'], 'Confirmed overrun must not disable the hard runtime limit');

$now = HistoricalRuntimeEstimator::now();
runtime_assert(is_float($now) && $now > 0.0, 'Runtime clock must provide a high-resolution numeric value');

echo "Historical runtime estimator tests passed\n";
