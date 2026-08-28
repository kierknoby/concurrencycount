<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalCalculationControl {
	const KEY_PREFIX = 'historical_calculation:';
	const TELEMETRY_KEY_PREFIX = 'historical_calculation_telemetry:';
	const ID_PATTERN = '/\A[a-f0-9]{32}\z/D';
	const RECORD_TTL = 7200;

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

	public function cancel(string $id, ?int $now = null): bool {
		$id = $this->validateId($id);
		$key = self::KEY_PREFIX . $id;
		$record = $this->repository->get($key, null);
		if (isset($record['status']) && $record['status'] === 'cancelled') return true;
		$now = $now === null ? time() : $now;
		$this->repository->set($key, ['status' => 'cancelled', 'expires_at' => $now + self::RECORD_TTL]);
		return true;
	}

	public function isCancelled(string $id): bool {
		$id = $this->validateId($id);
		$record = $this->repository->get(self::KEY_PREFIX . $id, null);
		return is_array($record) && isset($record['status']) && $record['status'] === 'cancelled';
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
		return ['status' => isset($control['status']) ? (string)$control['status'] : 'unavailable'] + (is_array($telemetry) ? $telemetry : []);
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
