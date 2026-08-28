<?php

namespace FreePBX\modules\Concurrencycount\Engines;

class Sweep implements EngineInterface {
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
		return 'sweep';
	}

	public function calculatePerName(string $mode, array $rows): array {
		$events = [];
		$total_rows = count($rows);
		$event_count = $this->countEvents($rows, false);
		$sort_work = $this->sortWork($event_count);
		$total_work = max(1, $total_rows + $sort_work + $event_count);
		$processed = 0;
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, 0, $total_work);

		foreach ($rows as $row) {
			$processed++;
			$calldate = isset($row['calldate']) ? $row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			$name = isset($row['identity']) ? (string)$row['identity'] : '';
			if ($calldate === '' || $duration <= 0 || $name === '') {
				if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed, $total_work);
				continue;
			}

			$start_ts = strtotime($calldate);
			$end_ts = $start_ts + $duration;
			$events[] = [$start_ts, 1, $name];
			$events[] = [$end_ts + 1, -1, $name];
			if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed, $total_work);
		}

		$comparisons = 0;
		usort($events, function ($a, $b) use (&$comparisons, $total_rows, $sort_work, $total_work) {
			$comparisons++;
			if (($comparisons % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($total_rows + min($comparisons, $sort_work), $total_work, 'sweep-sort');
			if ($a[0] === $b[0]) return $b[1] <=> $a[1];
			return $a[0] <=> $b[0];
		});
		$base_work = $total_rows + $sort_work;
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $base_work, $total_work);

		$current = [];
		$max_concurrent = [];
		$count = count($events);
		$traversed = 0;
		for ($i = 0; $i < $count; $i++) {
			$ts = $events[$i][0];
			$changed = [];
			while ($i < $count && $events[$i][0] === $ts) {
				$traversed++;
				if (($traversed % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($base_work + $traversed, $total_work, 'sweep-event-traversal');
				$name = $events[$i][2];
				$current[$name] = isset($current[$name]) ? $current[$name] + $events[$i][1] : $events[$i][1];
				if ($current[$name] < 0) {
					$current[$name] = 0;
				}
				$changed[$name] = true;
				$i++;
			}
			$i--;
			foreach ($changed as $name => $_unused) {
				if (!isset($max_concurrent[$name]) || $current[$name] > $max_concurrent[$name]) {
					$max_concurrent[$name] = $current[$name];
				}
			}
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
		$events = [];
		$total_rows = count($rows);
		$event_count = $this->countEvents($rows, true);
		$sort_work = $this->sortWork($event_count);
		$total_work = max(1, $total_rows + $sort_work + ($event_count * 2));
		$processed = 0;
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, 0, $total_work);

		foreach ($rows as $row) {
			$processed++;
			$calldate = isset($row['calldate']) ? $row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			$extension_legs = isset($row['extension_legs']) ? max(0, (int)$row['extension_legs']) : 0;
			if ($calldate === '' || $duration <= 0) {
				if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed, $total_work);
				continue;
			}

			$start_ts = strtotime($calldate);
			$end_ts = $start_ts + $duration;
			if (($end_ts - $start_ts) > 86400) {
				$end_ts = $start_ts + 86400;
			}

			if ($extension_legs > 0) {
				$events[] = [$start_ts, $extension_legs];
				$events[] = [$end_ts + 1, -$extension_legs];
			}
			if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $processed, $total_work);
		}

		$comparisons = 0;
		usort($events, function ($a, $b) use (&$comparisons, $total_rows, $sort_work, $total_work) {
			$comparisons++;
			if (($comparisons % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($total_rows + min($comparisons, $sort_work), $total_work, 'sweep-sort');
			if ($a[0] === $b[0]) return $b[1] <=> $a[1];
			return $a[0] <=> $b[0];
		});
		$base_work = $total_rows + $sort_work;
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, $base_work, $total_work);

		$current = 0;
		$max = 0;
		$count = count($events);
		$traversed = 0;
		for ($i = 0; $i < $count; $i++) {
			$ts = $events[$i][0];
			while ($i < $count && $events[$i][0] === $ts) {
				$traversed++;
				if (($traversed % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($base_work + $traversed, $total_work, 'sweep-event-traversal');
				$current += $events[$i][1];
				$i++;
			}
			$i--;

			$next_ts = ($i + 1 < $count) ? $events[$i + 1][0] : null;
			if ($next_ts !== null && $next_ts > $ts && $current > $max) {
				$max = $current;
			}
		}

		$current = 0;
		$peak_ranges = [];
		$traversed = 0;
		for ($i = 0; $i < $count; $i++) {
			$ts = $events[$i][0];
			while ($i < $count && $events[$i][0] === $ts) {
				$traversed++;
				if (($traversed % self::RUNTIME_CHECK_INTERVAL) === 0) $this->checkpoint($base_work + $event_count + $traversed, $total_work, 'sweep-group-peak-traversal');
				$current += $events[$i][1];
				$i++;
			}
			$i--;

			$next_ts = ($i + 1 < $count) ? $events[$i + 1][0] : null;
			if ($current === $max && $max > 0 && $next_ts !== null && $next_ts > $ts) {
				$range_start = $ts;
				$range_end = $next_ts - 1;
				$last = count($peak_ranges) - 1;
				if ($last >= 0 && $peak_ranges[$last]['to_ts'] + 1 === $range_start) {
					$peak_ranges[$last]['to_ts'] = $range_end;
				} else {
					$peak_ranges[] = ['from_ts' => $range_start, 'to_ts' => $range_end];
				}
			}
		}

		$ranges = [];
		foreach ($peak_ranges as $range) {
			$ranges[] = [
				'from' => date('Y-m-d H:i:s', $range['from_ts']),
				'to' => date('Y-m-d H:i:s', $range['to_ts']),
			];
		}

		return [
			'max_concurrency' => $max,
			'peak_ranges' => $ranges,
			'rows_processed' => $processed,
		];
	}

	private function countEvents(array $rows, bool $group): int {
		$count = 0;
		foreach ($rows as $row) {
			if (empty($row['calldate']) || !isset($row['duration']) || (int)$row['duration'] <= 0) continue;
			if ($group && (!isset($row['extension_legs']) || (int)$row['extension_legs'] <= 0)) continue;
			if (!$group && (!isset($row['identity']) || (string)$row['identity'] === '')) continue;
			$count += 2;
		}
		return $count;
	}

	private function sortWork(int $eventCount): int {
		if ($eventCount < 2) return 0;
		return $eventCount * (int)ceil(log($eventCount, 2));
	}

	private function checkpoint(int $processed, int $total, string $stage): void {
		if ($this->checkOverrun !== null) call_user_func($this->checkOverrun, min($processed, $total), $total, $stage);
	}
}
