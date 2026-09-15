<?php

namespace FreePBX\modules\Concurrencycount\Services;

/** Keeps one active Demo cleanup registry entry fresh without calculation state. */
class DemoCleanupHeartbeat {
	const CADENCE_SECONDS = 10;
	private $repository;
	private $key;
	private $accountcode;
	private $clock;
	private $last = -INF;

	public function __construct($repository, string $key, string $accountcode, ?callable $clock = null) {
		$this->repository = $repository;
		$this->key = $key;
		$this->accountcode = $accountcode;
		$this->clock = $clock ?: function (): int { return time(); };
	}

	public function checkpoint(): void {
		$now = (int)call_user_func($this->clock);
		if ($now - $this->last < self::CADENCE_SECONDS) return;
		$record = $this->repository->get($this->key, null);
		if (!is_array($record) || ($record['accountcode'] ?? '') !== $this->accountcode) {
			throw new \RuntimeException('Demo cleanup registry state is unavailable.');
		}
		$record['updated_at'] = $now;
		$record['cleanup_active'] = true;
		$this->repository->set($this->key, $record);
		$this->last = $now;
	}
}
