<?php

namespace FreePBX\modules\Concurrencycount\Services;

/** Shared occupancy eligibility applied before classification and engines. */
class HistoricalCdrEligibility {
	public static function isEligible(array $row): bool {
		return strtoupper(trim((string)($row['disposition'] ?? 'ANSWERED'))) === 'ANSWERED'
			&& (int)($row['duration'] ?? 0) > 0;
	}

	public static function filter(array $rows): array {
		return array_values(array_filter($rows, [self::class, 'isEligible']));
	}
}
