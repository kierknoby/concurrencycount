<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Immediate state transitions with a bounded steady-state persistence cadence. */
class HistoricalTelemetryCadence {
	private $lastAt = -INF;
	private $lastState = null;
	public function shouldPublish(float $now, string $state): bool {
		if ($this->lastState !== $state || $now - $this->lastAt >= 2) {
			$this->lastState = $state; $this->lastAt = $now; return true;
		}
		return false;
	}
}
