<?php

namespace FreePBX\modules\Concurrencycount\Services;

/** Exact naïve-datetime range subdivision for bounded CDR statements. */
class HistoricalCdrAcquisition {
	const INITIAL_CHUNK_SECONDS = 21600;
	const MINIMUM_CHUNK_SECONDS = 60;

	private $execute;
	private $isTimeout;
	private $checkpoint;

	public function __construct(callable $execute, callable $isTimeout, ?callable $checkpoint = null) {
		$this->execute = $execute;
		$this->isTimeout = $isTimeout;
		$this->checkpoint = $checkpoint;
	}

	public function fetch(string $start, string $end): array {
		$zone = new \DateTimeZone('UTC');
		$from = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $start, $zone);
		$through = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $end, $zone);
		if ($from === false || $through === false || $from > $through) throw new \InvalidArgumentException('Invalid historical date range.');
		$rows = [];
		$cursor = $from;
		while ($cursor <= $through) {
			$next = $cursor->modify('+' . self::INITIAL_CHUNK_SECONDS . ' seconds');
			$inclusive = $next >= $through;
			$limit = $inclusive ? $through : $next;
			$this->fetchRange($cursor, $limit, $inclusive, $rows);
			if ($inclusive) break;
			$cursor = $next;
		}
		return $rows;
	}

	private function fetchRange(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $inclusive, array &$rows): void {
		if ($this->checkpoint !== null) call_user_func($this->checkpoint, count($rows));
		$base = count($rows);
		try {
			$rangeRows = call_user_func($this->execute, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $inclusive);
			if (!is_array($rangeRows) && !$rangeRows instanceof \Traversable) throw new \UnexpectedValueException('Historical CDR acquisition callback must return iterable rows.');
			foreach ($rangeRows as $row) {
				$rows[] = $row;
				if ($this->checkpoint !== null && (count($rows) % 256) === 0) call_user_func($this->checkpoint, count($rows));
			}
		} catch (\Throwable $exception) {
			if (!call_user_func($this->isTimeout, $exception)) throw $exception;
			if (count($rows) > $base) array_splice($rows, $base);
			$span = $to->getTimestamp() - $from->getTimestamp();
			if ($span <= self::MINIMUM_CHUNK_SECONDS) throw $exception;
			$half = max(self::MINIMUM_CHUNK_SECONDS, (int)floor($span / 2));
			$middle = $from->modify('+' . $half . ' seconds');
			if ($middle >= $to) throw $exception;
			$this->fetchRange($from, $middle, false, $rows);
			$this->fetchRange($middle, $to, $inclusive, $rows);
		}
	}
}
