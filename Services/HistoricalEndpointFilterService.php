<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalEndpointFilterService {
	public function apply(string $mode, array $rows, PjsipIdentityService $identity, string $filter): array {
		$filter = trim($filter);
		if ($mode === 'group') {
			if ($filter !== '') throw new \InvalidArgumentException('Group reports do not support an endpoint filter.');
			return ['rows' => $rows, 'filter' => '', 'missing_reference' => false];
		}
		if (!in_array($mode, ['trunk', 'extension'], true)) throw new \InvalidArgumentException('Invalid historical endpoint filter mode.');
		if ($filter === '') return ['rows' => $rows, 'filter' => '', 'missing_reference' => false];
		if ($identity->classify($filter)['type'] !== $mode) return ['rows' => [], 'filter' => $filter, 'missing_reference' => true];
		$filtered = array_values(array_filter($rows, function (array $row) use ($filter): bool {
			return isset($row['identity']) && hash_equals($filter, (string)$row['identity']);
		}));
		return ['rows' => $filtered, 'filter' => $filter, 'missing_reference' => false];
	}
}
