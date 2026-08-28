<?php

require_once __DIR__ . '/../Services/HistoricalRuntimeEstimator.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;

function runtime_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$tiny = new HistoricalRuntimeEstimator(3600.0, 0.0, 10.0);
$assessment = $tiny->evaluate(1, 10000, 10.9);
runtime_assert(!$assessment['warn'] && $assessment['estimated_remaining'] === null, 'First tiny sample must not produce an estimate');

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
