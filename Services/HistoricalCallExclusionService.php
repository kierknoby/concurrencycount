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
			if (isset($entry['group_id']) && $this->isValidGroupId($entry['group_id'])) {
				$out[$identity]['group_id'] = strtolower((string)$entry['group_id']);
				$out[$identity]['group_context'] = $this->normaliseGroupContext(isset($entry['group_context']) && is_array($entry['group_context']) ? $entry['group_context'] : []);
			}
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

	public function excludeGroup(array $stored, array $calls, string $groupId, array $context, ?int $now = null): array {
		$stored = $this->repair($stored);
		if (!$this->isValidGroupId($groupId)) throw new \InvalidArgumentException('Invalid historical exclusion group id.');
		$unique = [];
		foreach ($calls as $call) {
			if (!is_array($call) || !isset($call['identity'])) continue;
			$identity = $this->validateIdentity($call['identity']);
			if (!isset($unique[$identity])) $unique[$identity] = isset($call['summary']) && is_array($call['summary']) ? $call['summary'] : [];
		}
		$newCount = count(array_diff_key($unique, $stored));
		if (count($stored) + $newCount > self::MAX_EXCLUSIONS) throw new \RuntimeException('Historical call exclusion limit reached. Restore unused exclusions before adding more.');
		foreach ($unique as $identity => $summary) {
			if (isset($stored[$identity])) continue;
			$stored[$identity] = [
				'excluded_at' => $now === null ? time() : $now,
				'summary' => $this->normaliseSummary($summary),
				'group_id' => strtolower($groupId),
				'group_context' => $this->normaliseGroupContext($context),
			];
		}
		return $stored;
	}

	public function restoreGroup(array $stored, string $groupId): array {
		$stored = $this->repair($stored);
		if (!$this->isValidGroupId($groupId)) throw new \InvalidArgumentException('Invalid historical exclusion group id.');
		$groupId = strtolower($groupId);
		foreach ($stored as $identity => $entry) if (isset($entry['group_id']) && hash_equals($groupId, $entry['group_id'])) unset($stored[$identity]);
		return $stored;
	}

	public function filterRows(array $rows, array $stored, ?callable $checkpoint = null): array {
		$stored = $this->repair($stored);
		if (empty($stored)) return $rows;
		$out = [];
		foreach ($rows as $index => $row) {
			if ($checkpoint !== null && ($index % 256) === 0) call_user_func($checkpoint, $index, count($rows));
			$identity = $this->identityForRow($row);
			if ($identity === null || !isset($stored[$identity])) $out[] = $row;
		}
		return $out;
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

	private function normaliseGroupContext(array $context): array {
		$out = [];
		foreach (['trunk', 'occurrence_from', 'occurrence_to'] as $field) {
			$value = trim((string)($context[$field] ?? ''));
			if ($value !== '' && strlen($value) <= 255 && !preg_match('/[\x00-\x1F\x7F]/', $value)) $out[$field] = $value;
		}
		return $out;
	}

	private function isValidGroupId($groupId): bool {
		return is_string($groupId) && (bool)preg_match('/^[0-9a-f]{32}$/i', $groupId);
	}

	private function isValidIdentity(string $identity): bool {
		if (!preg_match('/^(linkedid|uniqueid):(.+)$/', $identity, $match)) return false;
		return $this->isValidValue($match[2]);
	}

	private function isValidValue(string $value): bool {
		return $value !== '' && strlen($value) <= self::MAX_ID_LENGTH && !preg_match('/[\x00-\x1F\x7F]/', $value);
	}
}
