<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Output-only transformation applied after a complete Historical calculation. */
class HistoricalResultFloor {
	// Portable signed integer ceiling across supported PHP/database boundaries;
	// it is an input-safety limit, not a meaningful PBX concurrency target.
	public const MAXIMUM = 2147483647;
	public const DEFAULT_HISTORICAL_MINIMUM = 2;

	public function normalise($value): ?int {
		if ($value === null || (is_string($value) && trim($value) === '')) return null;
		if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAXIMUM]]) === false) {
			throw new \InvalidArgumentException('Minimum concurrency must be a positive whole number.');
		}
		return (int)$value;
	}

	public function normaliseHistorical($value): int {
		$comparable = is_string($value) ? trim($value) : $value;
		if ($comparable === null || $comparable === '' || $comparable === 0 || $comparable === '0' || $comparable === 1 || $comparable === '1') return self::DEFAULT_HISTORICAL_MINIMUM;
		if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::DEFAULT_HISTORICAL_MINIMUM, 'max_range' => self::MAXIMUM]]) === false) {
			throw new \InvalidArgumentException('Minimum concurrency must be a whole number of 2 or greater.');
		}
		return (int)$value;
	}

	/** Filter display points only; exact_peak remains the complete graph truth. */
	public function applyGraph(array $graph, ?int $floor): array {
		$graph['minimum_concurrency'] = $floor;
		if ($floor === null) return $graph;
		foreach (($graph['series'] ?? []) as $name => $series) {
			$series['points'] = array_map(function ($point) use ($floor) {
				if (!isset($point['value']) || (int)$point['value'] < $floor) $point['value'] = null;
				return $point;
			}, $series['points'] ?? []);
			$graph['series'][$name] = $series;
		}
		return $graph;
	}

	public function apply(array $complete, ?int $floor): array {
		$complete['minimum_concurrency'] = $floor;
		if ($floor === null || isset($complete['empty_message'])) return $complete;
		if (($complete['mode'] ?? '') === 'demo') return $this->applyDemo($complete, $floor);
		if (($complete['mode'] ?? '') === 'group') return $this->applyGroup($complete, $floor);
		return $this->applyPerName($complete, $floor);
	}

	private function applyPerName(array $result, int $floor): array {
		$result['calculated_global_max'] = (int)($result['global_max'] ?? 0);
		$result['per_name'] = array_filter($result['per_name'] ?? [], function ($value) use ($floor) { return (int)$value >= $floor; });
		foreach (['peak_occurrences', 'trunk_entities'] as $field) if (isset($result[$field])) $result[$field] = array_intersect_key($result[$field], $result['per_name']);
		if (empty($result['per_name'])) $this->markNoDetails($result, $floor);
		return $result;
	}

	private function applyGroup(array $result, int $floor): array {
		$result['calculated_max_concurrency'] = (int)($result['max_concurrency'] ?? 0);
		if ($result['calculated_max_concurrency'] < $floor) { $result['peak_ranges'] = []; $this->markNoDetails($result, $floor); }
		return $result;
	}

	private function applyDemo(array $result, int $floor): array {
		if (($result['demo_report'] ?? '') === 'group') {
			$result = $this->applyGroup($result, $floor);
			if (isset($result['expected_max_concurrency'])) {
				$result['calculated_expected_max_concurrency'] = (int)$result['expected_max_concurrency'];
				if ($result['calculated_expected_max_concurrency'] < $floor) $result['expected_peak_ranges'] = [];
			}
		} else {
			$result = $this->applyPerName($result, $floor);
			if (isset($result['expected_per_name'])) $result['expected_per_name'] = array_filter($result['expected_per_name'], function ($value) use ($floor) { return (int)$value >= $floor; });
		}
		foreach (($result['engines'] ?? []) as $id => $engine) {
			if (($result['demo_report'] ?? '') === 'group') {
				$engine['calculated_max_concurrency'] = (int)($engine['max_concurrency'] ?? 0);
				if ($engine['calculated_max_concurrency'] < $floor) $engine['peak_ranges'] = [];
			} else {
				$engine['calculated_global_max'] = (int)($engine['global_max'] ?? 0);
				$engine['per_name'] = array_filter($engine['per_name'] ?? [], function ($value) use ($floor) { return (int)$value >= $floor; });
			}
			$result['engines'][$id] = $engine;
		}
		return $result;
	}

	private function markNoDetails(array &$result, int $floor): void {
		$result['floor_notice'] = sprintf('No periods reached the minimum concurrency of %d during the selected date range.', $floor);
	}
}
