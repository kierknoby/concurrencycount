<?php

namespace FreePBX\modules\Concurrencycount\Services;

class AlertMonitorCoordinator {
	private $evaluateSnapshot;
	private $reconcileInterval;
	private $lastReconciledAt = 0;

	public function __construct(callable $evaluateSnapshot, int $reconcileInterval = 5) {
		if ($reconcileInterval < 1 || $reconcileInterval > 60) {
			throw new \InvalidArgumentException('Alert monitor reconciliation interval must be between 1 and 60 seconds.');
		}
		$this->evaluateSnapshot = $evaluateSnapshot;
		$this->reconcileInterval = $reconcileInterval;
	}

	public function start(int $now): array {
		return $this->reconcile($now, 'startup');
	}

	public function onEvent(string $event, int $now): ?array {
		$event = strtolower(trim($event));
		if (!in_array($event, ['newchannel', 'newstate', 'hangup', 'rename', 'masquerade'], true)) {
			return null;
		}
		return $this->reconcile($now, 'ami_event:' . $event);
	}

	public function onTimer(int $now): ?array {
		if ($this->lastReconciledAt > 0 && ($now - $this->lastReconciledAt) < $this->reconcileInterval) {
			return null;
		}
		return $this->reconcile($now, 'periodic');
	}

	private function reconcile(int $now, string $trigger): array {
		$this->lastReconciledAt = $now;
		$result = call_user_func($this->evaluateSnapshot, $now);
		if (!is_array($result)) {
			$result = ['available' => false, 'errors' => ['Live snapshot evaluator returned no result.']];
		}
		$result['trigger'] = $trigger;
		$result['monitor_ts'] = $now;
		return $result;
	}
}
