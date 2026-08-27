<?php

namespace FreePBX\modules\Concurrencycount\Analyzers;

class PeakDetailAnalyser {
	public function analyseTrunk(array $rows, string $trunk, int $expectedPeak = 0): array {
		$events = [];
		foreach ($rows as $index => $row) {
			$calldate = isset($row['calldate']) ? (string)$row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			$identity = isset($row['identity']) ? (string)$row['identity'] : '';
			if ($calldate === '' || $duration <= 0 || !hash_equals($trunk, $identity)) {
				continue;
			}

			$start = strtotime($calldate);
			if ($start === false) {
				continue;
			}
			$events[$start]['start'][] = $index;
			$events[$start + $duration + 1]['end'][] = $index;
		}

		if (empty($events)) {
			return ['peak' => 0, 'occurrences' => []];
		}

		ksort($events, SORT_NUMERIC);
		$times = array_keys($events);
		$active = [];
		$segments = [];
		$peak = 0;
		$timeCount = count($times);
		for ($position = 0; $position < $timeCount; $position++) {
			$timestamp = (int)$times[$position];
			foreach (isset($events[$timestamp]['end']) ? $events[$timestamp]['end'] : [] as $index) {
				unset($active[$index]);
			}
			foreach (isset($events[$timestamp]['start']) ? $events[$timestamp]['start'] : [] as $index) {
				$active[$index] = true;
			}

			if ($position + 1 >= $timeCount || empty($active)) {
				continue;
			}
			$segmentEnd = ((int)$times[$position + 1]) - 1;
			if ($segmentEnd < $timestamp) {
				continue;
			}
			$count = count($active);
			if ($count > $peak) {
				$peak = $count;
				$segments = [];
			}
			if ($count === $peak) {
				$segments[] = [
					'from_ts' => $timestamp,
					'to_ts' => $segmentEnd,
					'row_indexes' => array_keys($active),
				];
			}
		}

		if ($expectedPeak > 0 && $peak !== $expectedPeak) {
			throw new \UnexpectedValueException(sprintf(
				'Peak detail mismatch for trunk %s: engine=%d analyser=%d',
				$trunk,
				$expectedPeak,
				$peak
			));
		}

		return ['peak' => $peak, 'occurrences' => $this->coalescePeakSegments($segments, $trunk, $peak)];
	}

	private function coalescePeakSegments(array $segments, string $trunk, int $peak): array {
		$occurrences = [];
		foreach ($segments as $segment) {
			$lastIndex = count($occurrences) - 1;
			if ($lastIndex >= 0 && $occurrences[$lastIndex]['to_ts'] + 1 === $segment['from_ts']) {
				$occurrences[$lastIndex]['to_ts'] = $segment['to_ts'];
				foreach ($segment['row_indexes'] as $rowIndex) {
					$occurrences[$lastIndex]['row_index_set'][$rowIndex] = true;
				}
				continue;
			}

			$occurrences[] = [
				'trunk' => $trunk,
				'peak' => $peak,
				'from_ts' => $segment['from_ts'],
				'to_ts' => $segment['to_ts'],
				'row_index_set' => array_fill_keys($segment['row_indexes'], true),
			];
		}

		foreach ($occurrences as &$occurrence) {
			$occurrence['from'] = date('Y-m-d H:i:s', $occurrence['from_ts']);
			$occurrence['to'] = date('Y-m-d H:i:s', $occurrence['to_ts']);
			$occurrence['duration_seconds'] = ($occurrence['to_ts'] - $occurrence['from_ts']) + 1;
			$occurrence['sample_seconds'] = $occurrence['duration_seconds'];
			$occurrence['row_indexes'] = array_map('intval', array_keys($occurrence['row_index_set']));
			unset($occurrence['from_ts'], $occurrence['to_ts'], $occurrence['row_index_set']);
		}
		unset($occurrence);

		return $occurrences;
	}
}
