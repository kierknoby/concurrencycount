<?php
require_once __DIR__ . '/../Services/HistoricalRuntimeEstimator.php';
require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;
function runtime_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
$early = new HistoricalRuntimeEstimator(3600, 0, 0);
runtime_assert(!$early->evaluate(500, 1000, 299, 'original-occupied-seconds')['reliable'], 'ETA must be unavailable before 300 seconds');
$stable = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample <= 12; $sample++) $assessment = $stable->evaluate(100 + $sample * 20, 1000, 300 + $sample * 2, 'original-occupied-seconds');
runtime_assert($assessment['reliable'] && $assessment['eta_confidence'] === 'High', 'Stable evidence after five minutes must reach High confidence');
runtime_assert(abs($assessment['estimated_remaining'] - 66) < .001, 'ETA must use recent modelled-work throughput');
runtime_assert($assessment['progress_percent'] === 34.0, 'Progress must derive from modelled work');
$unstable = new HistoricalRuntimeEstimator(3600, 0, 0); $processed = 100;
for ($sample = 0; $sample <= 12; $sample++) { $processed += $sample % 2 ? 2 : 80; $assessment = $unstable->evaluate($processed, 2000, 300 + $sample * 2, 'original-occupied-seconds'); }
runtime_assert(!$assessment['reliable'] && $assessment['eta_confidence'] === 'Insufficient', 'Unstable throughput must remain insufficient');
$transition = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample <= 12; $sample++) $transition->evaluate(100 + $sample * 20, 2000, 300 + $sample * 2, 'sweep-event-traversal');
runtime_assert(!$transition->evaluate(1000, 2000, 326, 'sweep-group-peak-traversal')['reliable'], 'A stage transition must invalidate confidence samples');
$paused = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample < 8; $sample++) $paused->evaluate(100 + $sample * 20, 2000, 300 + $sample * 2, 'original-window');
$paused->invalidate();
for ($sample = 0; $sample < 5; $sample++) $assessment = $paused->evaluate(300 + $sample * 20, 2000, 400 + $sample * 2, 'original-window');
runtime_assert(!$assessment['reliable'], 'Pause/stall invalidation must require fresh stable evidence');
$progress = new HistoricalRuntimeEstimator(3600, 0, 0);
$first = $progress->evaluate(500, 1000, 1, 'progress')['progress_percent'];
$backwards = $progress->evaluate(400, 1000, 2, 'progress')['progress_percent'];
$complete = $progress->evaluate(1000, 1000, 3, 'progress')['progress_percent'];
runtime_assert($first === 50.0 && $backwards === 50.0 && $complete === 100.0, 'Progress must be monotonic and reach 100 only on completion');
$invalid = new HistoricalRuntimeEstimator(3600, 0, 0);
foreach ([[-1, 1000], [1001, 1000], [0, 0]] as $impossible) {
	$assessment = $invalid->evaluate($impossible[0], $impossible[1], 300, 'progress');
	runtime_assert(!$assessment['reliable'] && $assessment['estimated_remaining'] === null, 'Impossible or zero-total progress must not produce an ETA');
}
$backwardsEstimator = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample <= 10; $sample++) $backwardsEstimator->evaluate(100 + $sample * 10, 1000, 300 + $sample * 2, 'progress');
$backwardsAssessment = $backwardsEstimator->evaluate(150, 1000, 322, 'progress');
runtime_assert(!$backwardsAssessment['reliable'] && $backwardsAssessment['estimated_remaining'] === null && $backwardsAssessment['progress_percent'] === 20.0, 'Backwards work invalidates ETA samples without moving displayed progress backwards');
$nearComplete = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample <= 10; $sample++) $nearAssessment = $nearComplete->evaluate(890 + $sample * 10, 993, 300 + $sample * 2, 'progress');
runtime_assert($nearAssessment['reliable'] && $nearAssessment['estimated_remaining'] > 0 && $nearAssessment['estimated_remaining'] < 1, 'Near-complete stable work preserves a positive sub-second ETA after the confidence gate');
$completedAssessment = $nearComplete->evaluate(993, 993, 322, 'progress');
runtime_assert(!$completedAssessment['reliable'] && $completedAssessment['estimated_remaining'] === null && $completedAssessment['progress_percent'] === 100.0, 'Completed work publishes 100% without an active zero ETA');
$changedTotal = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample <= 10; $sample++) $changedTotal->evaluate(100 + $sample * 10, 1000, 300 + $sample * 2, 'progress');
runtime_assert(!$changedTotal->evaluate(220, 2000, 322, 'progress')['reliable'], 'A changed work total invalidates prior throughput evidence');
$runtime = new HistoricalRuntimeEstimator(3600, 0, 0);
runtime_assert(abs($runtime->evaluate(0, 1, 300, 'progress')['runtime_remaining'] - 3300) < .001, 'Runtime remaining uses the original runtime origin');
$runtime->allowance(7200);
runtime_assert(abs($runtime->evaluate(0, 1, 301, 'progress')['runtime_remaining'] - 6899) < .001, 'Increasing allowance must not reset elapsed runtime');
runtime_assert((new HistoricalRuntimeEstimator(3600, 0, 0))->evaluate(0, 1, 3600, 'progress')['abort'], 'Runtime expiry remains a hard stop');
$warning = new HistoricalRuntimeEstimator(3600, 0, 0);
for ($sample = 0; $sample <= 10; $sample++) $warningAssessment = $warning->evaluate(100 + $sample * 2, 10000, 300 + $sample * 2, 'progress');
runtime_assert($warningAssessment['reliable'] && $warningAssessment['warn'], 'Stable post-gate throughput warns when projected work exceeds remaining runtime');
$confirmed = new HistoricalRuntimeEstimator(3600, 0, 0, true);
for ($sample = 0; $sample <= 10; $sample++) $confirmedAssessment = $confirmed->evaluate(100 + $sample * 2, 10000, 300 + $sample * 2, 'progress');
runtime_assert($confirmedAssessment['reliable'] && !$confirmedAssessment['warn'], 'Confirmed warning suppresses repeat prediction warnings');
runtime_assert($confirmed->evaluate(121, 10000, 3601, 'progress')['abort'], 'Warning confirmation never suppresses hard runtime enforcement');
$acquiring = (new HistoricalRuntimeEstimator(3600, 0, 0))->evaluate(1024, 0, 300, 'cdr-acquisition');
runtime_assert($acquiring['progress_percent'] === 0.0 && $acquiring['calculation_phase'] === 'cdr-acquisition', 'Unknown acquisition totals remain explicit phase work rather than fake completion percentage');
$originalEstimator = new HistoricalRuntimeEstimator(3600, 0, 0); $originalReliable = false; $originalStages = [];
$original = new \FreePBX\modules\Concurrencycount\Engines\Original([
	'all_names' => ['100' => true],
	'check_overrun' => function (int $processed, int $total, string $stage = 'progress') use ($originalEstimator, &$originalReliable, &$originalStages) {
		$originalStages[$stage] = true;
		$r = $originalEstimator->evaluate($processed, $total, 300 + $processed / 2048, $stage);
		if ($r['reliable']) $originalReliable = true;
	},
]);
$original->calculatePerName('extension', [['calldate' => '2026-09-08 00:00:00', 'duration' => 60000, 'identity' => '100']]);
runtime_assert(isset($originalStages['original-window'], $originalStages['original-occupied-seconds']) && $originalReliable, 'Actual Original window markers preserve useful occupied-work ETA confidence');
echo "Historical runtime estimator tests passed\n";
