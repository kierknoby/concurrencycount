<?php

namespace FreePBX\modules\Concurrencycount\Services;

/**
 * Pure state logic for persisted Historical Reports tabs. Stores only the
 * report *definition* (mode/engine/date preset/options) - never CDR result
 * payloads or graph point arrays - so restoring a report means re-running
 * the existing calculation, not replaying cached data.
 */
class HistoricalReportsService {
	const MAX_REPORTS = 5;
	const ALLOWED_MODES = ['trunk', 'extension', 'group'];
	const ALLOWED_ENGINES = ['original', 'sweep'];
	const ALLOWED_PRESETS = ['today', 'yesterday', 'last7', 'last30', 'month', 'year', 'lastyear', 'custom'];

	public function defaults(): array {
		return ['version' => 1, 'active_id' => null, 'reports' => []];
	}

	/**
	 * Recover from malformed/corrupt persisted state rather than fatal.
	 * Unknown shapes, non-array entries, and overflow beyond MAX_REPORTS are
	 * dropped silently; this is a cache of GUI convenience state, not a
	 * source of truth that must be preserved at all costs.
	 */
	public function reconcileStored($stored): array {
		if (!is_array($stored)) return $this->defaults();
		$reports = [];
		$source = isset($stored['reports']) && is_array($stored['reports']) ? $stored['reports'] : [];
		$usedNumbers = [];
		foreach ($source as $id => $report) {
			if (count($reports) >= self::MAX_REPORTS) break;
			if (!is_string($id) || $id === '' || !is_array($report)) continue;
			$number = isset($report['number']) ? (int)$report['number'] : 0;
			if ($number < 1 || $number > self::MAX_REPORTS || isset($usedNumbers[$number])) continue;
			$normalised = $this->normaliseDefinitionSafely($report);
			if ($normalised === null) continue;
			$usedNumbers[$number] = true;
			$reports[$id] = array_merge($normalised, [
				'id' => $id,
				'number' => $number,
				'title' => isset($report['title']) && is_string($report['title']) && $report['title'] !== '' ? $report['title'] : ('Historic Report ' . $number),
				'created_at' => isset($report['created_at']) ? (int)$report['created_at'] : time(),
				'updated_at' => isset($report['updated_at']) ? (int)$report['updated_at'] : time(),
			]);
		}
		$activeId = isset($stored['active_id']) && is_string($stored['active_id']) && isset($reports[$stored['active_id']]) ? $stored['active_id'] : null;
		return ['version' => 1, 'active_id' => $activeId, 'reports' => $reports];
	}

	/** List reports in visible slot order (1..5), not insertion order. */
	public function listReports(array $stored): array {
		$reports = array_values($stored['reports']);
		usort($reports, function ($a, $b) { return $a['number'] <=> $b['number']; });
		return $reports;
	}

	/** Lowest unused slot number 1..5, or null if all five are taken. */
	public function nextNumber(array $stored): ?int {
		$used = [];
		foreach ($stored['reports'] as $report) $used[(int)$report['number']] = true;
		for ($number = 1; $number <= self::MAX_REPORTS; $number++) {
			if (!isset($used[$number])) return $number;
		}
		return null;
	}

	public function findById(array $stored, string $id): ?array {
		return isset($stored['reports'][$id]) ? $stored['reports'][$id] : null;
	}

	/**
	 * Create a new report definition. Throws RuntimeException (caller maps
	 * to the user-facing "maximum of 5" message) when already full - the
	 * limit is enforced here, in the same synchronous call that allocates
	 * the slot number, so no race between check-and-create is possible.
	 */
	public function createReport(array $stored, array $definition, ?string $id = null): array {
		if (count($stored['reports']) >= self::MAX_REPORTS) {
			throw new \RuntimeException('Maximum of 5 historical reports can be open at once.');
		}
		$number = $this->nextNumber($stored);
		if ($number === null) {
			throw new \RuntimeException('Maximum of 5 historical reports can be open at once.');
		}
		$normalised = $this->normaliseDefinition($definition);
		$id = $id !== null ? $id : bin2hex(random_bytes(16));
		$now = time();
		$report = array_merge($normalised, [
			'id' => $id,
			'number' => $number,
			'title' => 'Historic Report ' . $number,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$stored['reports'][$id] = $report;
		$stored['active_id'] = $id;
		return [$stored, $report];
	}

	public function updateReport(array $stored, string $id, array $definition): array {
		if (!isset($stored['reports'][$id])) {
			throw new \InvalidArgumentException('Historical report tab no longer exists.');
		}
		$normalised = $this->normaliseDefinition(array_merge($stored['reports'][$id], $definition));
		$stored['reports'][$id] = array_merge($stored['reports'][$id], $normalised, ['updated_at' => time()]);
		return [$stored, $stored['reports'][$id]];
	}

	public function closeReport(array $stored, string $id): array {
		unset($stored['reports'][$id]);
		if ($stored['active_id'] === $id) $stored['active_id'] = null;
		return $stored;
	}

	public function setActive(array $stored, string $id): array {
		if (!isset($stored['reports'][$id])) {
			throw new \InvalidArgumentException(_('Historical report tab no longer exists.'));
		}
		$stored['active_id'] = $id;
		return $stored;
	}

	/** Throwing variant used for create/update; returns null (drop) when reconciling stored data instead of throwing. */
	private function normaliseDefinitionSafely(array $definition): ?array {
		try {
			return $this->normaliseDefinition($definition);
		} catch (\Throwable $exception) {
			return null;
		}
	}

	public function normaliseDefinition(array $definition): array {
		$mode = isset($definition['mode']) ? (string)$definition['mode'] : 'trunk';
		if (!in_array($mode, self::ALLOWED_MODES, true)) throw new \InvalidArgumentException('Invalid historical report mode.');

		$engine = isset($definition['engine']) ? (string)$definition['engine'] : 'original';
		if (!in_array($engine, self::ALLOWED_ENGINES, true)) throw new \InvalidArgumentException('Invalid historical report engine.');

		$preset = isset($definition['preset']) ? (string)$definition['preset'] : 'last7';
		if (!in_array($preset, self::ALLOWED_PRESETS, true)) throw new \InvalidArgumentException('Invalid historical report date preset.');

		$rangeFrom = $this->normaliseDateOnly(isset($definition['range_from']) ? $definition['range_from'] : '');
		$rangeTo = $this->normaliseDateOnly(isset($definition['range_to']) ? $definition['range_to'] : '');
		if ($rangeFrom === null || $rangeTo === null) throw new \InvalidArgumentException('Invalid historical report date range.');

		$includeTime = !empty($definition['include_time']);
		$fromTime = $this->normaliseClockTime(isset($definition['from_time']) ? $definition['from_time'] : '00:00');
		$toTime = $this->normaliseClockTime(isset($definition['to_time']) ? $definition['to_time'] : '23:59');

		$filter = isset($definition['filter']) ? substr((string)$definition['filter'], 0, 128) : '';
		$missingReference = !empty($definition['missing_reference']);

		return [
			'mode' => $mode, 'engine' => $engine, 'preset' => $preset,
			'range_from' => $rangeFrom, 'range_to' => $rangeTo,
			'include_time' => $includeTime, 'from_time' => $fromTime, 'to_time' => $toTime,
			'filter' => $filter, 'missing_reference' => $missingReference,
		];
	}

	private function normaliseDateOnly(string $value): ?string {
		return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value) ? $value : null;
	}

	private function normaliseClockTime(string $value): string {
		return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) ? $value : '00:00';
	}
}
