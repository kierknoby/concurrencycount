<?php

namespace FreePBX\modules\Concurrencycount\Services;

/** Pure validation/state/filtering for global Historical call exclusions. */
class HistoricalCallExclusionService {
	const MAX_ID_LENGTH = 255;
	const MAX_EXCLUSIONS = 5000;

	public function repair($stored): array {
		if (!is_array($stored)) return [];
		$out = [];
		foreach ($stored as $identity => $entry) {
			if (!is_string($identity) || !$this->isValidIdentity($identity) || !is_array($entry)) continue;
			if (count($out) >= self::MAX_EXCLUSIONS) break;
			$summary = isset($entry['summary']) && is_array($entry['summary']) ? $this->normaliseSummary($entry['summary']) : [];
			$out[$identity] = ['excluded_at' => isset($entry['excluded_at']) ? max(0, (int)$entry['excluded_at']) : 0, 'summary' => $summary];
		}
		return $out;
	}

	public function identityForRow(array $row): ?string {
		$linkedid = trim((string)(isset($row['linkedid']) ? $row['linkedid'] : ''));
		if ($linkedid !== '' && $this->isValidValue($linkedid)) return 'linkedid:' . $linkedid;
		$uniqueid = trim((string)(isset($row['uniqueid']) ? $row['uniqueid'] : ''));
		if ($uniqueid !== '' && $this->isValidValue($uniqueid)) return 'uniqueid:' . $uniqueid;
		return null;
	}

	public function validateIdentity($identity): string {
		$identity = trim((string)$identity);
		if (!$this->isValidIdentity($identity)) throw new \InvalidArgumentException('Invalid historical call identity.');
		return $identity;
	}

	public function splitIdentity(string $identity): array {
		$identity = $this->validateIdentity($identity);
		$position = strpos($identity, ':');
		return ['field' => substr($identity, 0, $position), 'value' => substr($identity, $position + 1)];
	}

	public function exclude(array $stored, string $identity, array $summary, ?int $now = null): array {
		$stored = $this->repair($stored);
		$identity = $this->validateIdentity($identity);
		if (isset($stored[$identity])) return $stored;
		if (count($stored) >= self::MAX_EXCLUSIONS) throw new \RuntimeException('Historical call exclusion limit reached. Restore unused exclusions before adding more.');
		$stored[$identity] = ['excluded_at' => $now === null ? time() : $now, 'summary' => $this->normaliseSummary($summary)];
		return $stored;
	}

	public function restore(array $stored, string $identity): array {
		$stored = $this->repair($stored);
		unset($stored[$this->validateIdentity($identity)]);
		return $stored;
	}

	public function filterRows(array $rows, array $stored): array {
		$stored = $this->repair($stored);
		if (empty($stored)) return $rows;
		return array_values(array_filter($rows, function (array $row) use ($stored): bool {
			$identity = $this->identityForRow($row);
			return $identity === null || !isset($stored[$identity]);
		}));
	}

	private function normaliseSummary(array $summary): array {
		$out = [];
		foreach (['calldate', 'src', 'dst', 'disposition', 'trunk', 'extension'] as $field) {
			$value = isset($summary[$field]) ? trim((string)$summary[$field]) : '';
			if ($value !== '' && !preg_match('/[\x00-\x1F\x7F]/', $value)) $out[$field] = substr($value, 0, 255);
		}
		foreach (['duration', 'billsec'] as $field) if (isset($summary[$field])) $out[$field] = max(0, (int)$summary[$field]);
		$channels = isset($summary['channels']) && is_array($summary['channels']) ? $summary['channels'] : [];
		$out['channels'] = [];
		foreach ($channels as $channel) {
			if (count($out['channels']) >= 64) break;
			$channel = trim((string)$channel);
			if ($channel !== '' && strlen($channel) <= 255 && !preg_match('/[\x00-\x1F\x7F]/', $channel) && !in_array($channel, $out['channels'], true)) $out['channels'][] = $channel;
		}
		return $out;
	}

	private function isValidIdentity(string $identity): bool {
		if (!preg_match('/^(linkedid|uniqueid):(.+)$/', $identity, $match)) return false;
		return $this->isValidValue($match[2]);
	}

	private function isValidValue(string $value): bool {
		return $value !== '' && strlen($value) <= self::MAX_ID_LENGTH && !preg_match('/[\x00-\x1F\x7F]/', $value);
	}
}
