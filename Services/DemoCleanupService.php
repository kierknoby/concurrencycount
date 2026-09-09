<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Bounded exact-tag cleanup and durable recovery for synthetic Demo CDRs. */
class DemoCleanupService {
	const KEY_PREFIX = 'demo_run:';
	const ACCOUNT_PATTERN = '/\ACCDEMO[a-f0-9]{8}\z/D';
	const STALE_SECONDS = 300;
	private $db;
	private $isTimeout;
	private $checkpoint;
	public function __construct($db, callable $isTimeout, ?callable $checkpoint = null) { $this->db = $db; $this->isTimeout = $isTimeout; $this->checkpoint = $checkpoint; }
	public static function isReservedAccountcode($accountcode): bool { return is_string($accountcode) && preg_match(self::ACCOUNT_PATTERN, $accountcode) === 1; }
	public static function ordinarySqlPredicate(): string { return " AND BINARY accountcode NOT REGEXP '^CCDEMO[0-9a-f]{8}$'"; }
	public static function excludeReservedRows(array $rows): array {
		return array_values(array_filter($rows, function ($row) { return !self::isReservedAccountcode($row['accountcode'] ?? ''); }));
	}
	public function cleanup(string $accountcode): array {
		if (!preg_match(self::ACCOUNT_PATTERN, $accountcode)) throw new \InvalidArgumentException('Invalid Demo run identifier.');
		$removed = 0; $limit = 1000;
		while (true) {
			if ($this->checkpoint !== null) call_user_func($this->checkpoint);
			try {
				$stmt = $this->db->prepare('DELETE FROM cdr WHERE accountcode = :accountcode_index AND BINARY accountcode = :accountcode_exact LIMIT ' . $limit);
				$stmt->execute([':accountcode_index' => $accountcode, ':accountcode_exact' => $accountcode]);
			} catch (\Throwable $exception) {
				if (!call_user_func($this->isTimeout, $exception) || $limit <= 1) throw $exception;
				$limit = max(1, (int)floor($limit / 2)); continue;
			}
			$batch = $stmt->rowCount(); $removed += $batch;
			if ($this->checkpoint !== null) call_user_func($this->checkpoint);
			if ($batch < $limit) break;
		}
		// A successful short DELETE proves no matching row remained at statement
		// completion; a separate full COUNT scan would add another timeout path.
		return ['rows_removed' => $removed, 'cleanup_remaining' => 0];
	}
	public function recover($repository, int $now, int $ttl): int {
		$recovered = 0;
		foreach ($repository->findKeys(self::KEY_PREFIX) as $key) {
			$record = $repository->get($key, null);
			$accountcode = is_array($record) ? (string)($record['accountcode'] ?? '') : '';
			$updated = is_array($record) ? (int)($record['updated_at'] ?? 0) : 0;
			if (!preg_match(self::ACCOUNT_PATTERN, $accountcode) || $key !== self::KEY_PREFIX . $accountcode || $updated <= 0) { $repository->delete($key); continue; }
			if ($updated + $ttl > $now) continue;
			$result = $this->cleanup($accountcode);
			if ($result['cleanup_remaining'] === 0) { $repository->delete($key); $recovered++; }
		}
		return $recovered;
	}
}
