<?php

namespace FreePBX\modules\Concurrencycount\Services;

require_once __DIR__ . '/DemoDiskGuard.php';
require_once __DIR__ . '/DemoCleanupService.php';

/** Verifies the indexed cleanup path before any reserved-accountcode DELETE. */
class DemoCleanupCoordinator {
	private $db;
	private $isTimeout;
	private $checkpoint;
	private $initialBatchLimit;

	public function __construct($db, callable $isTimeout, ?callable $checkpoint = null, int $initialBatchLimit = 1000) {
		$this->db = $db;
		$this->isTimeout = $isTimeout;
		$this->checkpoint = $checkpoint;
		$this->initialBatchLimit = $initialBatchLimit;
	}

	public function verify(): void {
		(new DemoDiskGuard($this->db))->verifyCleanupAccessPath();
	}

	public function cleanup(string $accountcode): array {
		$this->verify();
		return $this->service()->cleanup($accountcode);
	}

	public function recover($repository, int $now, int $ttl): int {
		$this->verify();
		return $this->service()->recover($repository, $now, $ttl);
	}

	private function service(): DemoCleanupService {
		return new DemoCleanupService($this->db, $this->isTimeout, $this->checkpoint, $this->initialBatchLimit);
	}
}
