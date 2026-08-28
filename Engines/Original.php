<?php

namespace FreePBX\modules\Concurrencycount\Engines;

class Original implements EngineInterface {
	const RUNTIME_CHECK_INTERVAL = 4096;
	const WINDOW_SECONDS = 3600;
	private $allNames;
	private $coalesceRanges;
	private $checkOverrun;
	private $windowObserver;
	private $resourceCheck;

	public function __construct(array $options = []) {
		$this->allNames = isset($options['all_names']) ? $options['all_names'] : [];
		$this->coalesceRanges = isset($options['coalesce_ranges']) ? $options['coalesce_ranges'] : null;
		$this->checkOverrun = isset($options['check_overrun']) ? $options['check_overrun'] : null;
		$this->windowObserver = isset($options['window_observer']) && is_callable($options['window_observer']) ? $options['window_observer'] : null;
		$this->resourceCheck = isset($options['resource_check']) && is_callable($options['resource_check']) ? $options['resource_check'] : null;
	}

	public function name(): string { return 'original'; }

	public function calculatePerName(string $mode, array $rows): array {
		$intervals = [];
		$totalWork = 0;
		$invalidWork = 0;
		$bounds = [null, null];
		foreach ($rows as $rowIndex => $row) {
			if ((((int)$rowIndex) % self::RUNTIME_CHECK_INTERVAL) === 0 && $this->resourceCheck !== null) call_user_func($this->resourceCheck);
			$calldate = isset($row['calldate']) ? (string)$row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			$name = isset($row['identity']) ? (string)$row['identity'] : '';
			$start = $calldate === '' ? false : strtotime($calldate);
			if ($start === false || $duration <= 0 || $name === '') {
				$totalWork++;
				$invalidWork++;
				continue;
			}
			$end = $start + $duration;
			$intervals[] = ['start' => $start, 'end' => $end, 'name' => $name];
			$totalWork += $duration + 1;
			$this->extendBounds($bounds, $start, $end);
		}
		$totalWork = max(1, $totalWork);
		$processedWork = $invalidWork;
		$this->checkpoint(0, $totalWork);
		if ($invalidWork > 0) $this->checkpoint($processedWork, $totalWork);
		$maxConcurrent = [];

		if ($bounds[0] !== null) {
			$firstWindow = intdiv($bounds[0], self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
			for ($windowStart = $firstWindow; $windowStart <= $bounds[1]; $windowStart += self::WINDOW_SECONDS) {
				$windowEnd = min($windowStart + self::WINDOW_SECONDS - 1, $bounds[1]);
				$secondsByName = [];
				foreach ($intervals as $interval) {
					$from = max($interval['start'], $windowStart);
					$to = min($interval['end'], $windowEnd);
					if ($to < $from) continue;
					$name = $interval['name'];
					if (!isset($secondsByName[$name])) $secondsByName[$name] = [];
					for ($ts = $from; $ts <= $to; $ts++) {
						$secondsByName[$name][$ts] = isset($secondsByName[$name][$ts]) ? $secondsByName[$name][$ts] + 1 : 1;
						if (!isset($maxConcurrent[$name]) || $secondsByName[$name][$ts] > $maxConcurrent[$name]) $maxConcurrent[$name] = $secondsByName[$name][$ts];
						$processedWork++;
						if (($processedWork % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($processedWork, $totalWork, 'original-occupied-seconds');
					}
				}
				if ($this->windowObserver !== null) {
					$largestIdentitySeconds = 0;
					foreach ($secondsByName as $identitySeconds) $largestIdentitySeconds = max($largestIdentitySeconds, count($identitySeconds));
					call_user_func($this->windowObserver, ['mode' => 'per-name', 'window_start' => $windowStart, 'window_end' => $windowEnd, 'timestamp_keys' => $largestIdentitySeconds, 'identity_timestamp_keys' => array_sum(array_map('count', $secondsByName)), 'identities' => count($secondsByName), 'memory_bytes' => memory_get_usage(true)]);
				}
				$this->checkpoint($processedWork, $totalWork, 'original-window');
				unset($secondsByName);
			}
		}
		$this->checkpoint($totalWork, $totalWork);

		if (empty($maxConcurrent)) return ['per_name' => [], 'global_max' => 0, 'rows_processed' => count($rows)];
		$globalMax = max($maxConcurrent);
		$allNames = $this->allNames;
		ksort($allNames);
		$ordered = [];
		foreach ($allNames as $name => $_unused) $ordered[$name] = isset($maxConcurrent[$name]) ? $maxConcurrent[$name] : 0;
		return ['per_name' => $ordered, 'global_max' => $globalMax, 'rows_processed' => count($rows)];
	}

	public function calculateGroup(array $rows): array {
		$intervals = [];
		$totalWork = 0;
		$invalidWork = 0;
		$bounds = [null, null];
		foreach ($rows as $rowIndex => $row) {
			if ((((int)$rowIndex) % self::RUNTIME_CHECK_INTERVAL) === 0 && $this->resourceCheck !== null) call_user_func($this->resourceCheck);
			$calldate = isset($row['calldate']) ? (string)$row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			$start = $calldate === '' ? false : strtotime($calldate);
			if ($start === false || $duration <= 0) {
				$totalWork++;
				$invalidWork++;
				continue;
			}
			$duration = min($duration, 86400);
			$end = $start + $duration;
			$intervals[] = ['start' => $start, 'end' => $end, 'legs' => isset($row['extension_legs']) ? max(0, (int)$row['extension_legs']) : 0];
			$totalWork += $duration + 1;
			$this->extendBounds($bounds, $start, $end);
		}
		$totalWork = max(1, $totalWork);
		$processedWork = $invalidWork;
		$this->checkpoint(0, $totalWork);
		if ($invalidWork > 0) $this->checkpoint($processedWork, $totalWork);
		$max = 0;
		$peakRanges = [];

		if ($bounds[0] !== null) {
			$firstWindow = intdiv($bounds[0], self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
			for ($windowStart = $firstWindow; $windowStart <= $bounds[1]; $windowStart += self::WINDOW_SECONDS) {
				$windowEnd = min($windowStart + self::WINDOW_SECONDS - 1, $bounds[1]);
				$seconds = [];
				foreach ($intervals as $interval) {
					$from = max($interval['start'], $windowStart);
					$to = min($interval['end'], $windowEnd);
					if ($to < $from) continue;
					for ($ts = $from; $ts <= $to; $ts++) {
						if ($interval['legs'] > 0) $seconds[$ts] = isset($seconds[$ts]) ? $seconds[$ts] + $interval['legs'] : $interval['legs'];
						$processedWork++;
						if (($processedWork % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($processedWork, $totalWork, 'original-occupied-seconds');
					}
				}
				ksort($seconds);
				$scanned = 0;
				foreach ($seconds as $ts => $count) {
					$scanned++;
					if (($scanned % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($processedWork, $totalWork, 'original-peak-scan');
					if ($count > $max) {
						$max = $count;
						$peakRanges = [[$ts, $ts]];
					} elseif ($count === $max && $max > 0) {
						$last = count($peakRanges) - 1;
						if ($last >= 0 && $peakRanges[$last][1] + 1 === $ts) $peakRanges[$last][1] = $ts;
						else $peakRanges[] = [$ts, $ts];
					}
				}
				if ($this->windowObserver !== null) call_user_func($this->windowObserver, ['mode' => 'group', 'window_start' => $windowStart, 'window_end' => $windowEnd, 'timestamp_keys' => count($seconds), 'identity_timestamp_keys' => count($seconds), 'identities' => empty($seconds) ? 0 : 1, 'memory_bytes' => memory_get_usage(true)]);
				$this->checkpoint($processedWork, $totalWork, 'original-window');
				unset($seconds);
			}
		}
		$this->checkpoint($totalWork, $totalWork);
		$ranges = [];
		foreach ($peakRanges as $range) $ranges[] = ['from' => date('Y-m-d H:i:s', $range[0]), 'to' => date('Y-m-d H:i:s', $range[1])];
		return ['max_concurrency' => $max, 'peak_ranges' => $ranges, 'rows_processed' => count($rows)];
	}

	private function extendBounds(array &$bounds, int $start, int $end): void {
		$bounds[0] = $bounds[0] === null ? $start : min($bounds[0], $start);
		$bounds[1] = $bounds[1] === null ? $end : max($bounds[1], $end);
	}

	private function checkpoint(int $processed, int $total, string $stage = 'progress'): void {
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, min($processed, $total), $total, $stage);
	}
}
