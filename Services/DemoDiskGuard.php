<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Fail closed: local database storage must be identifiable before any write. */
class DemoDiskGuard {
	const MIN_BYTES_PER_ROW = 16384;
	const MIN_RESERVE = 1073741824;
	const RESERVE_RATIO = .20;
	private $db;
	private $space;
	private $plan;
	public function __construct($db, ?callable $space = null) {
		$this->db = $db;
		$this->space = $space ?: function (string $path): array {
			$real = realpath($path);
			if ($real === false || !is_dir($real)) return [];
			$stat = @stat($real);
			return ['path' => $real, 'device' => is_array($stat) ? ($stat['dev'] ?? null) : null, 'free' => @disk_free_space($real), 'total' => @disk_total_space($real)];
		};
	}
	public function preflight(int $rows): array {
		if ($rows <= 0) throw new \InvalidArgumentException('Invalid Demo row count.');
		$this->verifyCleanupAccessPath();
		$info = $this->db->query('SELECT @@datadir AS datadir, @@hostname AS hostname, @@log_bin AS log_bin')->fetch(\PDO::FETCH_ASSOC);
		$host = gethostname();
		$dbHost = is_array($info) ? (string)($info['hostname'] ?? '') : '';
		$shortHost = $host ? explode('.', $host)[0] : '';
		$shortDbHost = $dbHost !== '' ? explode('.', $dbHost)[0] : '';
		if (!is_array($info) || empty($info['datadir']) || !$host || strcasecmp($shortDbHost, $shortHost) !== 0) throw new \RuntimeException('Demo cannot start safely: the local database filesystem cannot be verified.');
		$binaryLog = !empty($info['log_bin']);
		$space = call_user_func($this->space, (string)$info['datadir']);
		$this->validateSpace($space, $binaryLog);
		$estimate = $this->estimateBytesPerRow();
		if ($rows > intdiv(PHP_INT_MAX, $estimate['bytes'] * ($binaryLog ? 2 : 1))) throw new \InvalidArgumentException('Invalid Demo row count.');
		$reserve = (int)max(self::MIN_RESERVE, ceil($space['total'] * self::RESERVE_RATIO));
		$dataRequired = $rows * $estimate['bytes'];
		$required = $dataRequired;
		$logPlan = null;
		if ($binaryLog) {
			$basename = '';
			try {
				$logVariable = $this->db->query("SHOW VARIABLES LIKE 'log_bin_basename'")->fetch(\PDO::FETCH_ASSOC);
				if (is_array($logVariable)) $basename = trim((string)($logVariable['Value'] ?? $logVariable['value'] ?? ''));
			} catch (\Throwable $exception) {
				$basename = '';
			}
			if ($basename === '' || $basename === '0' || $basename[0] !== DIRECTORY_SEPARATOR) throw new \RuntimeException('Demo cannot start safely: binary logging is enabled but its filesystem cannot be identified.');
			$logSpace = call_user_func($this->space, dirname($basename)); $this->validateSpace($logSpace, true);
			$sameFilesystem = $space['device'] === $logSpace['device'];
			if ($sameFilesystem) $required += $dataRequired;
			else {
				$logReserve = (int)max(self::MIN_RESERVE, ceil($logSpace['total'] * self::RESERVE_RATIO));
				if ($dataRequired > max(0, $logSpace['free'] - $logReserve)) throw new \RuntimeException('Demo cannot start safely: binary-log storage requirement exceeds safe free space.');
				$logPlan = ['path' => $logSpace['path'], 'device' => $logSpace['device'], 'free_bytes' => $logSpace['free'], 'total_bytes' => $logSpace['total'], 'reserve_bytes' => $logReserve, 'required_bytes' => $dataRequired];
			}
		}
		$this->plan = ['rows' => $rows, 'path' => $space['path'], 'free_bytes' => $space['free'], 'total_bytes' => $space['total'], 'reserve_bytes' => $reserve,
			'required_bytes' => $required + ($logPlan ? $logPlan['required_bytes'] : 0), 'data_required_bytes' => $required,
			'estimated_bytes_per_row' => $estimate['bytes'] * ($binaryLog ? 2 : 1), 'estimate_source' => $estimate['source'], 'binary_log' => $logPlan,
			'available_bytes' => max(0, $space['free'] - $reserve), 'peak_storage_growth_bytes' => 0];
		if ($required > $this->plan['available_bytes']) throw new \RuntimeException('Demo cannot start safely: conservative storage requirement exceeds free space after the 20% / 1 GiB reserve. Reduce the Demo size or free database disk space.');
		return $this->plan;
	}
	public function verifyCleanupAccessPath(): void {
		try {
			$stmt = $this->db->query("SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cdr' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
			$indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $exception) {
			throw new \RuntimeException('Demo cannot start safely: the CDR accountcode cleanup index cannot be verified.', 0, $exception);
		}
		if (!is_array($indexes)) throw new \RuntimeException('Demo cannot start safely: the CDR accountcode cleanup index cannot be verified.');
		foreach ($indexes as $index) {
			$prefixLength = $index['SUB_PART'] ?? null;
			if ((int)($index['SEQ_IN_INDEX'] ?? 0) === 1 && strcasecmp((string)($index['COLUMN_NAME'] ?? ''), 'accountcode') === 0
				&& ($prefixLength === null || (int)$prefixLength >= 14)) return;
		}
		throw new \RuntimeException('Demo cannot start safely: the CDR table requires an index whose leading column is accountcode for bounded cleanup.');
	}
	public function check(int $inserted): array {
		if (!$this->plan) throw new \LogicException('Demo preflight is required before insertion.');
		$s = call_user_func($this->space, $this->plan['path']); $this->validateSpace($s);
		$growth = max(0, $this->plan['free_bytes'] - $s['free']);
		$this->plan['peak_storage_growth_bytes'] = max($this->plan['peak_storage_growth_bytes'], $growth);
		$perFilesystemRow = $this->plan['binary_log'] ? intdiv($this->plan['estimated_bytes_per_row'], 2) : $this->plan['estimated_bytes_per_row'];
		$remaining = max(0, $this->plan['rows'] - $inserted) * ($this->plan['binary_log'] ? $perFilesystemRow : $this->plan['estimated_bytes_per_row']);
		if ($s['free'] - $this->plan['reserve_bytes'] < $remaining || $growth > max(16777216, $inserted * $this->plan['data_required_bytes'] / max(1, $this->plan['rows']))) throw new \RuntimeException('Demo stopped: database disk headroom fell or observed filesystem growth exceeded the conservative allowance.');
		if ($this->plan['binary_log']) {
			$log = call_user_func($this->space, $this->plan['binary_log']['path']); $this->validateSpace($log, true);
			if ($log['device'] !== $this->plan['binary_log']['device'] || $log['free'] - $this->plan['binary_log']['reserve_bytes'] < $remaining) throw new \RuntimeException('Demo stopped: binary-log disk headroom is no longer safe.');
		}
		return $this->plan;
	}
	private function estimateBytesPerRow(): array {
		try {
			$row = $this->db->query("SELECT DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cdr'")->fetch(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			// Restricted information_schema access uses the documented conservative floor.
			return ['bytes' => self::MIN_BYTES_PER_ROW, 'source' => 'conservative_floor'];
		}
		$allocated = is_array($row) ? (int)($row['DATA_LENGTH'] ?? 0) + (int)($row['INDEX_LENGTH'] ?? 0) : 0;
		$tableRows = is_array($row) ? (int)($row['TABLE_ROWS'] ?? 0) : 0;
		if ($allocated > 0 && $tableRows > 0) {
			// TABLE_ROWS can be approximate. Four times allocated bytes covers indexes,
			// page churn and estimation error.
			$measuredFloat = ceil(($allocated / $tableRows) * 4);
			if (!is_finite($measuredFloat) || $measuredFloat > intdiv(PHP_INT_MAX, 2)) throw new \RuntimeException('Demo cannot start safely: CDR storage statistics are outside supported bounds.');
			return ['bytes' => max(self::MIN_BYTES_PER_ROW, (int)$measuredFloat), 'source' => 'cdr_table_statistics'];
		}
		return ['bytes' => self::MIN_BYTES_PER_ROW, 'source' => 'conservative_floor'];
	}
	private function validateSpace(array $s, bool $requireDevice = false): void {
		if (empty($s['path']) || ($requireDevice && !isset($s['device'])) || !isset($s['free'], $s['total']) || !is_numeric($s['free']) || !is_numeric($s['total']) || !is_finite((float)$s['free']) || !is_finite((float)$s['total']) || $s['total'] <= 0 || $s['free'] < 0 || $s['free'] > $s['total']) throw new \RuntimeException('Demo cannot start safely: database disk information is unavailable.');
	}
}
