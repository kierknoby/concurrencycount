<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalRuntimeEstimator {
	const MIN_ENGINE_SECONDS = 0.5;
	const MIN_SAMPLES = 10;
	const MAX_WARMUP_SAMPLES = 100;
	const MIN_SAMPLE_PROPORTION = 0.01;

	private $maxRuntime;
	private $overallStartedAt;
	private $engineStartedAt;
	private $warningConfirmed;
	private $lastProcessed = null;
	private $lastTotal = null;

	public function __construct(float $maxRuntime, float $overallStartedAt, float $engineStartedAt, bool $warningConfirmed = false) {
		$this->maxRuntime = $maxRuntime;
		$this->overallStartedAt = $overallStartedAt;
		$this->engineStartedAt = $engineStartedAt;
		$this->warningConfirmed = $warningConfirmed;
	}

	public static function now(): float {
		return hrtime(true) / 1000000000;
	}

	public function beginEngine(float $now): void {
		$this->engineStartedAt = $now;
		$this->lastProcessed = null;
		$this->lastTotal = null;
	}

	public function evaluate(int $processed, int $total, float $now): array {
		$overallElapsed = max(0.0, $now - $this->overallStartedAt);
		$engineElapsed = max(0.0, $now - $this->engineStartedAt);
		$result = [
			'abort' => $overallElapsed > $this->maxRuntime,
			'warn' => false,
			'reliable' => false,
			'overall_elapsed' => $overallElapsed,
			'engine_elapsed' => $engineElapsed,
			'estimated_remaining' => null,
			'runtime_remaining' => max(0.0, $this->maxRuntime - $overallElapsed),
		];
		if ($total <= 0 || $processed < 0 || $processed > $total) return $result;
		if ($this->lastProcessed !== null && ($total !== $this->lastTotal || $processed < $this->lastProcessed)) return $result;
		$this->lastProcessed = $processed;
		$this->lastTotal = $total;
		if ($result['abort'] || $this->warningConfirmed || $processed <= 0 || $processed >= $total) return $result;
		$requiredSamples = min(self::MAX_WARMUP_SAMPLES, max(self::MIN_SAMPLES, (int)ceil($total * self::MIN_SAMPLE_PROPORTION)));
		if ($engineElapsed < self::MIN_ENGINE_SECONDS || $processed < $requiredSamples) return $result;
		$rate = $processed / $engineElapsed;
		if ($rate <= 0.0) return $result;
		$remaining = ($total - $processed) / $rate;
		if (!is_finite($remaining) || $remaining <= 0.0) return $result;
		$result['reliable'] = true;
		$result['estimated_remaining'] = $remaining;
		$result['warn'] = ($overallElapsed + $remaining) > $this->maxRuntime;
		return $result;
	}
}
