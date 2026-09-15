<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalCalculationControl {
	const KEY_PREFIX = 'historical_calculation:';
	const TELEMETRY_KEY_PREFIX = 'historical_calculation_telemetry:';
	const ID_PATTERN = '/\A[a-f0-9]{32}\z/D';
	const RECORD_TTL = 93600;
	const GUI_LEASE_SECONDS = 20;

	private $repository;

	public function __construct(SettingsRepository $repository) {
		$this->repository = $repository;
	}

	public function validateId($id): string {
		$id = strtolower(trim((string)$id));
		if (!preg_match(self::ID_PATTERN, $id)) throw new \InvalidArgumentException('Invalid calculation identifier.');
		return $id;
	}

	public function begin(string $id, ?int $now = null): void {
		$id = $this->validateId($id);
		$now = $now === null ? time() : $now;
		$this->cleanupExpired($now);
		$existing = $this->repository->get(self::KEY_PREFIX . $id, null);
		if (is_array($existing) && isset($existing['status']) && $existing['status'] === 'cancelled') return;
		$this->repository->set(self::KEY_PREFIX . $id, ['status' => 'active', 'expires_at' => $now + self::RECORD_TTL]);
		$this->repository->set(self::TELEMETRY_KEY_PREFIX . $id, ['started_at' => microtime(true), 'elapsed' => 0.0, 'eta_reliable' => false, 'estimated_remaining' => null, 'expires_at' => $now + self::RECORD_TTL]);
	}

	/** Admit one GUI calculation for an authenticated PHP-session ownership scope. */
	public function admitGui(string $id, string $owner, int $runtimeAllowanceSeconds, ?int $now = null, ?float $runtimeNow = null): bool {
		$id = $this->validateId($id);
		$owner = $this->validateOwner($owner);
		if ($runtimeAllowanceSeconds < 300 || $runtimeAllowanceSeconds > 86400 || $runtimeAllowanceSeconds % 60 !== 0) throw new \InvalidArgumentException('Runtime allowance must be between 5 and 1440 whole minutes.');
		$now = $now === null ? time() : $now;
		$runtimeNow = $runtimeNow === null ? hrtime(true) / 1000000000 : $runtimeNow;
		foreach ($this->repository->findKeys(self::KEY_PREFIX) as $key) {
			$otherId = substr($key, strlen(self::KEY_PREFIX));
			$record = $this->repository->get($key, null);
			if (!is_array($record) || ($record['kind'] ?? '') !== 'gui' || ($record['owner'] ?? '') !== $owner || $otherId === $id) continue;
			if (!empty($record['registered'])) {
				if (($record['status'] ?? '') === 'awaiting_confirmation' && (int)($record['lease_expires_at'] ?? 0) <= $now) {
					$this->finish($otherId);
					continue;
				}
				if (($record['status'] ?? '') === 'active' && (int)($record['lease_expires_at'] ?? 0) <= $now) {
					$record['status'] = 'abandoned';
					$record['expires_at'] = $now + self::RECORD_TTL;
					$this->repository->set($key, $record);
				}
				return false;
			}
		}
		$existing = $this->repository->get(self::KEY_PREFIX . $id, null);
		if (is_array($existing) && !empty($existing['registered'])) return false;
		if (is_array($existing) && in_array($existing['status'] ?? '', ['cancelled', 'abandoned'], true)) return false;
		$this->repository->set(self::KEY_PREFIX . $id, [
			'status' => 'active', 'kind' => 'gui', 'owner' => $owner, 'registered' => true,
			'runtime_started_at' => $runtimeNow, 'runtime_allowance_seconds' => $runtimeAllowanceSeconds,
			'assessment_started_at' => $runtimeNow, 'assessment_generation' => 0, 'decision' => 'assessing',
			'lease_expires_at' => $now + self::GUI_LEASE_SECONDS,
			'expires_at' => $now + self::RECORD_TTL,
		]);
		$this->repository->set(self::TELEMETRY_KEY_PREFIX . $id, ['started_at' => microtime(true), 'elapsed' => 0.0, 'eta_reliable' => false, 'estimated_remaining' => null, 'expires_at' => $now + self::RECORD_TTL]);
		return true;
	}

	/** Caller serialises all mutations with the GUI advisory lock. */
	public function owned(string $id, string $owner): array {
		$id = $this->validateId($id); $this->validateOwner($owner);
		$r = $this->repository->get(self::KEY_PREFIX . $id, null);
		if (!is_array($r) || ($r['owner'] ?? '') !== $owner || empty($r['registered'])) throw new \RuntimeException('Calculation ownership is unavailable.');
		return $r;
	}
	public function decide(string $id, string $owner, string $action, $allowance = null, ?float $now = null): array {
		$r = $this->owned($id, $owner); $now = $now ?? hrtime(true) / 1000000000;
		if (($r['status'] ?? '') !== 'active' || (int)$r['lease_expires_at'] <= time()) throw new \RuntimeException('Calculation is no longer active.');
		if ($action === 'allowance') {
			if (filter_var($allowance, FILTER_VALIDATE_INT) === false || (int)$allowance % 60 !== 0 || $allowance <= $r['runtime_allowance_seconds'] || $allowance > 86400) throw new \InvalidArgumentException('Runtime allowance must increase in whole minutes and cannot exceed 1440 minutes.');
			$r['runtime_allowance_seconds'] = (int)$allowance;
		} elseif ($action === 'continue' || $action === 'reassess') {
			if (!in_array($r['decision'], ['paused_impact', 'paused_runtime', 'paused_critical'], true)) throw new \RuntimeException('Calculation is not awaiting a decision.');
			$remaining = $r['runtime_allowance_seconds'] - ($now - $r['runtime_started_at']);
			if ($remaining <= 0 || ($action === 'reassess' && $remaining < 300)) throw new \RuntimeException('Increase the runtime allowance before reassessing.');
			$r['decision'] = $action === 'reassess' ? 'reassessing' : 'running';
			$r['advisory_accepted'] = $action === 'continue';
			if ($action === 'reassess') { $r['assessment_started_at'] = $now; $r['assessment_generation']++; }
		} else throw new \InvalidArgumentException('Invalid calculation decision.');
		$this->repository->set(self::KEY_PREFIX . $id, $r);
		return $r;
	}
	public function workerDecision(string $id, string $owner, string $decision): array {
		$r = $this->owned($id, $owner);
		if ($r['status'] === 'active' && !in_array($r['decision'], ['paused_impact', 'paused_runtime'], true)) {
			if (in_array($decision, ['paused_impact', 'paused_runtime'], true) && !empty($r['advisory_accepted'])) return $r;
			$r['decision'] = $decision;
			$this->repository->set(self::KEY_PREFIX . $id, $r);
		}
		return $r;
	}
	public function publish(string $id, array $measurements): void {
		$key = self::TELEMETRY_KEY_PREFIX . $this->validateId($id);
		$r = $this->repository->get($key, null);
		if (!is_array($r)) throw new \RuntimeException('Calculation telemetry state disappeared.');
		$this->repository->set($key, array_merge($r, $measurements, ['expires_at' => time() + self::RECORD_TTL]));
	}

	public function pauseForWarning(string $id, string $owner, ?int $now = null): bool {
		$id = $this->validateId($id);
		$owner = $this->validateOwner($owner);
		$now = $now === null ? time() : $now;
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (!is_array($record) || ($record['owner'] ?? '') !== $owner || ($record['status'] ?? '') !== 'active' || !isset($record['runtime_started_at'])) return false;
		$record['status'] = 'awaiting_confirmation';
		$record['expires_at'] = $now + self::RECORD_TTL;
		$this->repository->set($key, $record);
		$telemetryKey = self::TELEMETRY_KEY_PREFIX . $id;
		$telemetry = $this->repository->get($telemetryKey, null);
		if (is_array($telemetry)) {
			$telemetry['eta_reliable'] = false;
			$telemetry['estimated_remaining'] = null;
			$this->repository->set($telemetryKey, $telemetry);
		}
		return true;
	}

	public function resumeGui(string $id, string $owner, ?int $now = null): bool {
		$id = $this->validateId($id);
		$owner = $this->validateOwner($owner);
		$now = $now === null ? time() : $now;
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (!is_array($record) || ($record['kind'] ?? '') !== 'gui' || ($record['owner'] ?? '') !== $owner || ($record['status'] ?? '') !== 'awaiting_confirmation' || !is_numeric($record['runtime_started_at'] ?? null)) return false;
		if ((int)($record['lease_expires_at'] ?? 0) <= $now) {
			if (($record['status'] ?? '') === 'awaiting_confirmation') {
				$this->finish($id);
				return false;
			}
			$record['status'] = 'abandoned';
			$this->repository->set($key, $record);
			return false;
		}
		$record['status'] = 'active';
		$record['lease_expires_at'] = $now + self::GUI_LEASE_SECONDS;
		$record['expires_at'] = $now + self::RECORD_TTL;
		$this->repository->set($key, $record);
		return true;
	}

	public function runtimeStartedAt(string $id, string $owner): float {
		$id = $this->validateId($id);
		$owner = $this->validateOwner($owner);
		$record = $this->repository->get(self::KEY_PREFIX . $id, null);
		if (!is_array($record) || ($record['owner'] ?? '') !== $owner || !is_numeric($record['runtime_started_at'] ?? null)) throw new \RuntimeException('Historical calculation runtime state is unavailable.');
		return (float)$record['runtime_started_at'];
	}

	public function heartbeat(string $id, string $owner, ?int $now = null): bool {
		$id = $this->validateId($id);
		$owner = $this->validateOwner($owner);
		$now = $now === null ? time() : $now;
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (!is_array($record) || ($record['kind'] ?? '') !== 'gui' || ($record['owner'] ?? '') !== $owner || !in_array($record['status'] ?? '', ['active', 'awaiting_confirmation'], true)) return false;
		if ((int)($record['lease_expires_at'] ?? 0) <= $now) {
			if (($record['status'] ?? '') === 'awaiting_confirmation') {
				$this->finish($id);
				return false;
			}
			$record['status'] = 'abandoned';
			$this->repository->set($key, $record);
			return false;
		}
		$record['lease_expires_at'] = $now + self::GUI_LEASE_SECONDS;
		$record['expires_at'] = $now + self::RECORD_TTL;
		$this->repository->set($key, $record);
		return true;
	}

	public function shouldStop(string $id, ?int $now = null): bool {
		$id = $this->validateId($id);
		$now = $now === null ? time() : $now;
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (!is_array($record)) return false;
		if (in_array($record['status'] ?? '', ['cancelled', 'abandoned'], true)) return true;
		if (($record['kind'] ?? '') === 'gui' && (int)($record['lease_expires_at'] ?? 0) <= $now) {
			$record['status'] = 'abandoned';
			$record['expires_at'] = $now + self::RECORD_TTL;
			$this->repository->set($key, $record);
			return true;
		}
		return false;
	}

	public function cancel(string $id, ?int $now = null): bool {
		$id = $this->validateId($id);
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (isset($record['status']) && $record['status'] === 'cancelled') return true;
		if (isset($record['status']) && $record['status'] === 'awaiting_confirmation') {
			$this->finish($id);
			return true;
		}
		$now = $now === null ? time() : $now;
		$replacement = is_array($record) ? $record : [];
		$replacement['status'] = 'cancelled';
		$replacement['expires_at'] = $now + self::RECORD_TTL;
		$this->repository->set($key, $replacement);
		return true;
	}

	public function cancelOwned(string $id, string $owner, ?int $now = null): bool {
		$id = $this->validateId($id); $owner = $this->validateOwner($owner);
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (is_array($record) && isset($record['owner']) && !hash_equals((string)$record['owner'], $owner)) throw new \RuntimeException('Calculation ownership is unavailable.');
		if (!is_array($record)) {
			$now = $now ?? time();
			$this->repository->set($key, ['status' => 'cancelled', 'kind' => 'gui', 'owner' => $owner, 'expires_at' => $now + self::RECORD_TTL]);
			return true;
		}
		return $this->cancel($id, $now);
	}

	public function isCancelled(string $id): bool {
		$id = $this->validateId($id);
		$record = $this->repository->get(self::KEY_PREFIX . $id, null);
		return is_array($record) && isset($record['status']) && $record['status'] === 'cancelled';
	}

	private function validateOwner(string $owner): string {
		if (!preg_match('/\A[a-f0-9]{64}\z/D', $owner)) throw new \InvalidArgumentException('Invalid GUI calculation owner.');
		return $owner;
	}

	public function finish(string $id): void {
		$id = $this->validateId($id);
		$this->repository->delete(self::KEY_PREFIX . $id);
		$this->repository->delete(self::TELEMETRY_KEY_PREFIX . $id);
	}

	public function updateTelemetry(string $id, float $elapsed, ?float $estimatedRemaining, bool $etaReliable = false, ?int $now = null): void {
		$id = $this->validateId($id);
		$key = self::TELEMETRY_KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (!is_array($record)) return;
		$now = $now === null ? time() : $now;
		$record['elapsed'] = max(0.0, $elapsed);
		$record['eta_reliable'] = $etaReliable && $estimatedRemaining !== null && is_finite($estimatedRemaining) && $estimatedRemaining > 0.0;
		$record['estimated_remaining'] = $record['eta_reliable'] ? $estimatedRemaining : null;
		$record['expires_at'] = $now + self::RECORD_TTL;
		$this->repository->set($key, $record);
	}

	public function status(string $id): ?array {
		$id = $this->validateId($id);
		$control = $this->repository->get(self::KEY_PREFIX . $id, null);
		if (!is_array($control)) return null;
		$telemetry = $this->repository->get(self::TELEMETRY_KEY_PREFIX . $id, []);
		return $control + (is_array($telemetry) ? $telemetry : []);
	}

	public function cleanupExpired(?int $now = null): void {
		$now = $now === null ? time() : $now;
		foreach ($this->repository->findKeys(self::KEY_PREFIX) as $key) {
			$record = $this->repository->get($key, null);
			if (!is_array($record) || !isset($record['expires_at']) || (int)$record['expires_at'] <= $now) $this->repository->delete($key);
		}
		foreach ($this->repository->findKeys(self::TELEMETRY_KEY_PREFIX) as $key) {
			$record = $this->repository->get($key, null);
			if (!is_array($record) || !isset($record['expires_at']) || (int)$record['expires_at'] <= $now) $this->repository->delete($key);
		}
	}
}
