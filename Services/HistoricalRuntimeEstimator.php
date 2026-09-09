<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Bounded, stage-aware work sampling. All clocks are server monotonic seconds. */
class HistoricalRuntimeEstimator {
	const MIN_ENGINE_SECONDS = 300;
	const MIN_SAMPLES = 10;
	private $maxRuntime;
	private $overallStartedAt;
	private $engineStartedAt;
	private $warningConfirmed;
	private $last = null;
	private $samples = [];
	private $progress = 0.0;
	private $stage = '';
	public function __construct(float $maxRuntime, float $overallStartedAt, float $engineStartedAt, bool $warningConfirmed = false) {
		$this->maxRuntime = $maxRuntime; $this->overallStartedAt = $overallStartedAt;
		$this->engineStartedAt = $engineStartedAt; $this->warningConfirmed = $warningConfirmed;
	}
	public static function now(): float { return hrtime(true) / 1000000000; }
	public function allowance(float $seconds): void { $this->maxRuntime = $seconds; }
	public function beginEngine(float $now): void { $this->engineStartedAt = $now; $this->invalidate(); }
	public function invalidate(): void { $this->last = null; $this->samples = []; }
	public function evaluate(int $processed, int $total, float $now, string $stage = 'progress'): array {
		$samplingStage = $stage === 'original-window' ? 'original-occupied-seconds' : $stage;
		$elapsed = max(0.0, $now - $this->overallStartedAt);
		$assessmentElapsed = max(0.0, $now - $this->engineStartedAt);
		$valid = $total > 0 && $processed >= 0 && $processed <= $total;
		if ($valid) {
			$percent = $processed >= $total ? 100.0 : min(99.0, 100.0 * $processed / $total);
			$this->progress = max($this->progress, $percent);
		}
		$result = ['abort' => $elapsed >= $this->maxRuntime, 'warn' => false, 'reliable' => false,
			'overall_elapsed' => $elapsed, 'engine_elapsed' => $assessmentElapsed,
			'estimated_remaining' => null, 'runtime_remaining' => max(0.0, $this->maxRuntime - $elapsed),
			'progress_percent' => $this->progress, 'eta_confidence' => $assessmentElapsed < 300 ? 'Calculating...' : 'Insufficient',
			'assessment_remaining_seconds' => max(0.0, 300 - $assessmentElapsed), 'calculation_phase' => $stage];
		if ($samplingStage !== $this->stage) { $this->invalidate(); $this->stage = $samplingStage; }
		if (!$valid) { $this->invalidate(); return $result; }
		if ($this->last !== null) {
			list($previous, $previousTotal, $at) = $this->last;
			$dt = $now - $at;
			if ($total !== $previousTotal || $processed < $previous || $dt > 10) $this->invalidate();
			elseif ($dt >= 2) {
				if ($processed <= $previous) $this->invalidate();
				else { $this->samples[] = ($processed - $previous) / $dt; $this->samples = array_slice($this->samples, -30); }
				$this->last = [$processed, $total, $now];
			}
		}
		if ($this->last === null) $this->last = [$processed, $total, $now];
		if ($result['abort'] || $assessmentElapsed < 300 || count($this->samples) < self::MIN_SAMPLES || $processed < max(10, ceil($total * .01)) || $processed >= $total) return $result;
		// Sweep construction/sorting throughput cannot predict the remaining traversal stages.
		if (strpos($stage, 'sweep') === 0 && !in_array($stage, ['sweep-event-traversal', 'sweep-group-peak-traversal'], true)) return $result;
		$mean = array_sum($this->samples) / count($this->samples);
		$variance = 0.0;
		foreach ($this->samples as $rate) $variance += ($rate - $mean) ** 2;
		if ($mean <= 0 || sqrt($variance / count($this->samples)) / $mean > .15 || min($this->samples) < $mean * .7 || max($this->samples) > $mean * 1.3) return $result;
		$remaining = ($total - $processed) / $mean;
		if (!is_finite($remaining) || $remaining <= 0) return $result;
		$result['reliable'] = true; $result['eta_confidence'] = 'High'; $result['estimated_remaining'] = $remaining;
		$result['warn'] = !$this->warningConfirmed && $remaining > $result['runtime_remaining'];
		return $result;
	}
}
