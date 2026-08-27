<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalGraphService {
	const MAX_DISPLAY_POINTS = 1200;

	public function trunkSeries(array $rows, array $trunks, string $start, string $end): array {
		$events = [];
		foreach ($rows as $row) {
			$trunk = isset($row['identity']) ? (string)$row['identity'] : '';
			if (!in_array($trunk, $trunks, true)) continue;
			$this->addInterval($events[$trunk], isset($row['calldate']) ? $row['calldate'] : '', isset($row['duration']) ? (int)$row['duration'] : 0);
		}
		$series = [];
		foreach ($trunks as $trunk) {
			$exact = $this->eventsToPoints(isset($events[$trunk]) ? $events[$trunk] : [], $start, $end);
			$series[$trunk] = $this->seriesResult($exact, $start, $end);
		}
		return ['mode' => 'trunk', 'source' => 'cdr_reconstructed', 'series' => $series];
	}

	public function overallSeries(array $rows, string $start, string $end): array {
		$events = [];
		foreach ($rows as $row) {
			$duration = isset($row['duration']) ? min(86400, (int)$row['duration']) : 0;
			$legs = isset($row['extension_legs']) ? max(0, (int)$row['extension_legs']) : 0;
			for ($i = 0; $i < $legs; $i++) $this->addInterval($events, isset($row['calldate']) ? $row['calldate'] : '', $duration);
		}
		$exact = $this->eventsToPoints($events, $start, $end);
		return [
			'mode' => 'group', 'source' => 'cdr_reconstructed',
			'series' => ['overall' => $this->seriesResult($exact, $start, $end)],
		];
	}

	private function addInterval(&$events, $calldate, int $duration): void {
		if (!is_array($events)) $events = [];
		if ($duration <= 0) return;
		$start = strtotime((string)$calldate);
		if ($start === false) return;
		$events[$start] = isset($events[$start]) ? $events[$start] + 1 : 1;
		$endEvent = $start + $duration + 1;
		$events[$endEvent] = isset($events[$endEvent]) ? $events[$endEvent] - 1 : -1;
	}

	private function eventsToPoints(array $events, string $rangeStart, string $rangeEnd): array {
		$start = strtotime($rangeStart);
		$end = strtotime($rangeEnd);
		ksort($events, SORT_NUMERIC);
		$current = 0;
		foreach ($events as $timestamp => $delta) {
			if ((int)$timestamp > $start) break;
			$current += (int)$delta;
		}
		$points = [['ts' => $start, 'value' => max(0, $current)]];
		foreach ($events as $timestamp => $delta) {
			$timestamp = (int)$timestamp;
			if ($timestamp <= $start) continue;
			if ($timestamp > $end) break;
			$current += (int)$delta;
			$points[] = ['ts' => $timestamp, 'value' => max(0, $current)];
		}
		$points[] = ['ts' => $end, 'value' => max(0, $current)];
		return $this->deduplicate($points);
	}

	private function seriesResult(array $exact, string $start, string $end): array {
		$exactPeak = 0;
		foreach ($exact as $point) $exactPeak = max($exactPeak, (int)$point['value']);
		$display = $exact;
		$resolution = 'exact_events';
		if (count($exact) > self::MAX_DISPLAY_POINTS) {
			$display = $this->aggregate($exact, strtotime($start), strtotime($end), self::MAX_DISPLAY_POINTS);
			$resolution = 'bucket_maxima';
		}
		return [
			'exact_peak' => $exactPeak,
			'exact_event_count' => count($exact),
			'display_resolution' => $resolution,
			'points' => $display,
		];
	}

	private function aggregate(array $points, int $start, int $end, int $maxPoints): array {
		$span = max(1, $end - $start + 1);
		$bucketSize = max(1, (int)ceil($span / $maxPoints));
		$buckets = [];
		foreach ($points as $point) {
			$bucket = (int)floor(((int)$point['ts'] - $start) / $bucketSize);
			if (!isset($buckets[$bucket]) || (int)$point['value'] > $buckets[$bucket]['value']) {
				$buckets[$bucket] = ['ts' => (int)$point['ts'], 'value' => (int)$point['value']];
			}
		}
		ksort($buckets, SORT_NUMERIC);
		return array_values($buckets);
	}

	private function deduplicate(array $points): array {
		$result = [];
		foreach ($points as $point) {
			$last = count($result) - 1;
			if ($last >= 0 && $result[$last]['ts'] === $point['ts']) {
				$result[$last] = $point;
				continue;
			}
			if ($last >= 0 && $result[$last]['value'] === $point['value']) continue;
			$result[] = $point;
		}
		return $result;
	}
}
