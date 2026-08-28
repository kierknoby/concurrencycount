<?php

namespace FreePBX\modules\Concurrencycount\Engines;

class Original implements EngineInterface {
	const RUNTIME_CHECK_INTERVAL = 4096;
	private $allNames;
	private $coalesceRanges;
	private $checkOverrun;

	public function __construct(array $options = []) {
		$this->allNames = isset($options['all_names']) ? $options['all_names'] : [];
		$this->coalesceRanges = isset($options['coalesce_ranges']) ? $options['coalesce_ranges'] : null;
		$this->checkOverrun = isset($options['check_overrun']) ? $options['check_overrun'] : null;
	}

	public function name(): string {
		return 'original';
	}

	public function calculatePerName(string $mode, array $rows): array {
		$max_concurrent = [];
		$ongoing_calls = [];
		$total_work = $this->totalWork($rows, false);
		$processed_work = 0;
		$processed = 0;
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, 0, $total_work);

		foreach ($rows as $row) {
			$processed++;
			$calldate = $row['calldate'];
			$duration = (int)$row['duration'];
			$name = isset($row['identity']) ? (string)$row['identity'] : '';
			$row_work = ($calldate !== '' && $duration > 0 && $name !== '') ? $duration + 1 : 1;

			if ($calldate === '' || $duration <= 0 || $name === '') {
				$processed_work += $row_work;
				if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed_work, $total_work);
				continue;
			}

			$start_ts = strtotime($calldate);
			$end_ts = $start_ts + $duration;

			for ($ts = $start_ts; $ts <= $end_ts; $ts++) {
				$key = $name . ',' . $ts;
				$ongoing_calls[$key] = isset($ongoing_calls[$key]) ? $ongoing_calls[$key] + 1 : 1;
				if (!isset($max_concurrent[$name]) || $ongoing_calls[$key] > $max_concurrent[$name]) {
					$max_concurrent[$name] = $ongoing_calls[$key];
				}
				$row_progress = $ts - $start_ts + 1;
				if (($row_progress % self::RUNTIME_CHECK_INTERVAL) === 0 && $this->checkOverrun !== null) {
					call_user_func($this->checkOverrun, $processed_work + $row_progress, $total_work, 'original-occupied-seconds');
				}
			}
			$processed_work += $row_work;
			if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed_work, $total_work);
		}

		if (empty($max_concurrent)) {
			return [
				'per_name' => [],
				'global_max' => 0,
				'rows_processed' => $processed,
			];
		}

		$global_max = 0;
		foreach ($max_concurrent as $v) {
			if ($v > $global_max) $global_max = $v;
		}

		$all_names = $this->allNames;
		ksort($all_names);
		$ordered = [];
		foreach ($all_names as $name => $_unused) {
			$ordered[$name] = isset($max_concurrent[$name]) ? $max_concurrent[$name] : 0;
		}

		return [
			'per_name' => $ordered, 'global_max' => $global_max,
			'rows_processed' => $processed,
		];
	}

	public function calculateGroup(array $rows): array {
		$per_second_count = [];
		$total_work = $this->totalWork($rows, true);
		$processed_work = 0;
		$processed = 0;
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, 0, $total_work);

		foreach ($rows as $row) {
			$processed++;
			$calldate = $row['calldate'];
			$duration = (int)$row['duration'];
			$extension_legs = isset($row['extension_legs']) ? max(0, (int)$row['extension_legs']) : 0;
			$row_work = ($calldate !== '' && $duration > 0) ? min($duration, 86400) + 1 : 1;

			if ($calldate === '' || $duration <= 0) {
				$processed_work += $row_work;
				if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed_work, $total_work);
				continue;
			}

			$start_ts = strtotime($calldate);
			$end_ts = $start_ts + $duration;
			if (($end_ts - $start_ts) > 86400) {
				$end_ts = $start_ts + 86400;
			}

			for ($ts = $start_ts; $ts <= $end_ts; $ts++) {
				if ($extension_legs > 0) $per_second_count[$ts] = isset($per_second_count[$ts]) ? $per_second_count[$ts] + $extension_legs : $extension_legs;
				$row_progress = $ts - $start_ts + 1;
				if (($row_progress % self::RUNTIME_CHECK_INTERVAL) === 0 && $this->checkOverrun !== null) {
					call_user_func($this->checkOverrun, $processed_work + $row_progress, $total_work, 'original-occupied-seconds');
				}
			}
			$processed_work += $row_work;
			if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed_work, $total_work);
		}

		$max = 0;
		$peak_times = [];
		$scanned = 0;
		foreach ($per_second_count as $ts => $count) {
			$scanned++;
			if (($scanned % self::RUNTIME_CHECK_INTERVAL) === 0) $this->hardCheckpoint($total_work, 'original-peak-scan');
			if ($count > $max) {
				$max = $count;
				$peak_times = [$ts];
			} elseif ($count === $max) {
				$peak_times[] = $ts;
			}
		}
		sort($peak_times);

		$ranges = call_user_func($this->coalesceRanges, $peak_times);

		return [
			'max_concurrency' => $max, 'peak_ranges' => $ranges,
			'rows_processed' => $processed,
		];
	}

	private function totalWork(array $rows, bool $capDuration): int {
		$total = 0;
		foreach ($rows as $row) {
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			if ($duration <= 0 || empty($row['calldate'])) $total++;
			else $total += ($capDuration ? min($duration, 86400) : $duration) + 1;
		}
		return max(1, $total);
	}

	private function hardCheckpoint(int $total, string $stage): void {
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $total, $total, $stage);
	}
}
