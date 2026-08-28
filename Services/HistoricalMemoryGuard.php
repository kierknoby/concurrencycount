<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalMemoryGuard {
	const MIN_RESERVED_BYTES = 16777216;
	const RESERVED_RATIO = 0.20;

	private $hardLimitBytes;
	private $safeCeilingBytes;
	private $reservedBytes;
	private $usageReader;

	public function __construct(?string $configuredLimit = null, ?callable $usageReader = null) {
		if ($configuredLimit === null) $configuredLimit = (string)ini_get('memory_limit');
		$this->hardLimitBytes = self::parseLimit($configuredLimit);
		$this->usageReader = $usageReader ?: function (): int { return memory_get_usage(true); };
		$this->safeCeilingBytes = null;
		$this->reservedBytes = null;
		if ($this->hardLimitBytes !== null) {
			$reserve = max(self::MIN_RESERVED_BYTES, (int)ceil($this->hardLimitBytes * self::RESERVED_RATIO));
			$reserve = min($reserve, (int)floor($this->hardLimitBytes / 2));
			$this->reservedBytes = $reserve;
			$this->safeCeilingBytes = max(1, $this->hardLimitBytes - $reserve);
		}
	}

	public static function parseLimit($value): ?int {
		$value = trim((string)$value);
		if ($value === '' || $value === '-1') return null;
		if (!preg_match('/\A([0-9]+(?:\.[0-9]+)?)\s*([KMG]?)\z/i', $value, $match)) return null;
		$number = (float)$match[1];
		$multiplier = 1;
		switch (strtoupper($match[2])) {
			case 'K': $multiplier = 1024; break;
			case 'M': $multiplier = 1024 * 1024; break;
			case 'G': $multiplier = 1024 * 1024 * 1024; break;
		}
		$bytes = $number * $multiplier;
		if (!is_finite($bytes) || $bytes <= 0 || $bytes > PHP_INT_MAX) return null;
		return (int)floor($bytes);
	}

	public function checkpoint(): void {
		if ($this->safeCeilingBytes === null || $this->hardLimitBytes === null) return;
		$usage = max(0, (int)call_user_func($this->usageReader));
		if ($usage >= $this->safeCeilingBytes) {
			throw new HistoricalResourceLimitException($usage, $this->safeCeilingBytes, $this->hardLimitBytes);
		}
	}

	public function policy(): array {
		return [
			'hard_limit_bytes' => $this->hardLimitBytes,
			'safe_ceiling_bytes' => $this->safeCeilingBytes,
			'reserved_bytes' => $this->reservedBytes,
		];
	}
}
