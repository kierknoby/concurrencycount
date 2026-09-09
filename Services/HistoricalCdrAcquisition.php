<?php

namespace FreePBX\modules\Concurrencycount\Services;

/** Exact naïve-datetime range subdivision for bounded CDR statements. */
class HistoricalCdrAcquisition {
	const INITIAL_CHUNK_SECONDS = 21600;
	const MINIMUM_CHUNK_SECONDS = 60;
	const SLOW_QUERY_SECONDS = 1.0;
	const FAST_QUERY_SECONDS = 0.25;
	const FAST_QUERIES_TO_GROW = 2;

	private $execute;
	private $isTimeout;
	private $checkpoint;
	private $initialChunkSeconds;
	private $adaptive;
	private $clock;

	public function __construct(callable $execute, callable $isTimeout, ?callable $checkpoint = null, int $initialChunkSeconds = self::INITIAL_CHUNK_SECONDS, bool $adaptive = false, ?callable $clock = null) {
		if ($initialChunkSeconds < self::MINIMUM_CHUNK_SECONDS) throw new \InvalidArgumentException('Historical acquisition windows must be at least one minute.');
		if ($initialChunkSeconds > self::INITIAL_CHUNK_SECONDS) throw new \InvalidArgumentException('Historical acquisition windows cannot exceed six hours.');
		$this->execute = $execute;
		$this->isTimeout = $isTimeout;
		$this->checkpoint = $checkpoint;
		$this->initialChunkSeconds = $initialChunkSeconds;
		$this->adaptive = $adaptive;
		$this->clock = $clock ?: function (): float { return microtime(true); };
	}

	public function fetch(string $start, string $end): array {
		$zone = new \DateTimeZone('UTC');
		$from = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $start, $zone);
		$through = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $end, $zone);
		if ($from === false || $through === false || $from > $through) throw new \InvalidArgumentException('Invalid historical date range.');
		$rows = [];
		$cursor = $from;
		$chunkSeconds = $this->initialChunkSeconds;
		$fastQueries = 0;
		while ($cursor <= $through) {
			$next = $cursor->modify('+' . $chunkSeconds . ' seconds');
			$inclusive = $next >= $through;
			$limit = $inclusive ? $through : $next;
			$elapsed = $this->fetchRange($cursor, $limit, $inclusive, $rows);
			if ($this->adaptive) {
				if ($elapsed >= self::SLOW_QUERY_SECONDS) {
					$chunkSeconds = max(self::MINIMUM_CHUNK_SECONDS, (int)floor($chunkSeconds / 2));
					$fastQueries = 0;
				} elseif ($elapsed <= self::FAST_QUERY_SECONDS) {
					$fastQueries++;
					if ($fastQueries >= self::FAST_QUERIES_TO_GROW) {
						$chunkSeconds = min(self::INITIAL_CHUNK_SECONDS, $chunkSeconds * 2);
						$fastQueries = 0;
					}
				} else {
					$fastQueries = 0;
				}
			}
			if ($inclusive) break;
			$cursor = $next;
		}
		return $rows;
	}

	private function fetchRange(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $inclusive, array &$rows): float {
		if ($this->checkpoint !== null) call_user_func($this->checkpoint, count($rows));
		$base = count($rows);
		$started = call_user_func($this->clock);
		try {
			$rangeRows = call_user_func($this->execute, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $inclusive);
			if (!is_array($rangeRows) && !$rangeRows instanceof \Traversable) throw new \UnexpectedValueException('Historical CDR acquisition callback must return iterable rows.');
			foreach ($rangeRows as $row) {
				$rows[] = $row;
				if ($this->checkpoint !== null && (count($rows) % 256) === 0) call_user_func($this->checkpoint, count($rows));
			}
			return max(0.0, call_user_func($this->clock) - $started);
		} catch (\Throwable $exception) {
			if (!call_user_func($this->isTimeout, $exception)) throw $exception;
			if (count($rows) > $base) array_splice($rows, $base);
			$span = $to->getTimestamp() - $from->getTimestamp();
			if ($span <= self::MINIMUM_CHUNK_SECONDS) throw $exception;
			$half = max(self::MINIMUM_CHUNK_SECONDS, (int)floor($span / 2));
			$middle = $from->modify('+' . $half . ' seconds');
			if ($middle >= $to) throw $exception;
			$this->fetchRange($from, $middle, false, $rows);
			return $this->fetchRange($middle, $to, $inclusive, $rows);
		}
	}
}
