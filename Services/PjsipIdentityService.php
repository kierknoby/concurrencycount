<?php

namespace FreePBX\modules\Concurrencycount\Services;

/**
 * Request-scoped, O(1) PJSIP identity classification.
 *
 * Endpoint identifiers are matched exactly and retain their configured case.
 * Only the technology name is case-insensitive, mirroring FreePBX's devices
 * query. Manual values never override authoritative FreePBX configuration.
 */
class PjsipIdentityService {
	const MAX_ENDPOINT_LENGTH = 128;
	const MANUAL_TYPES = ['trunk', 'extension', 'ignore'];

	private $trunks = [];
	private $devices = [];
	private $overrides = [];

	public function __construct(array $trunks, array $devices, array $overrides = []) {
		foreach ($trunks as $key => $metadata) {
			if (!is_array($metadata)) $metadata = ['channelid' => is_string($key) ? $key : $metadata];
			$endpoint = trim((string)(isset($metadata['channelid']) ? $metadata['channelid'] : $key));
			if (!$this->isValidEndpoint($endpoint)) continue;
			$metadata['type'] = 'trunk';
			$metadata['channelid'] = $endpoint;
			$this->trunks[$endpoint] = $metadata;
		}
		foreach ($devices as $key => $metadata) {
			if (!is_array($metadata)) $metadata = ['id' => is_string($key) ? $key : $metadata];
			$endpoint = trim((string)(isset($metadata['id']) ? $metadata['id'] : $key));
			if (!$this->isValidEndpoint($endpoint)) continue;
			$metadata['type'] = 'extension';
			$metadata['id'] = $endpoint;
			$this->devices[$endpoint] = $metadata;
		}
		$this->overrides = $this->repairOverrides($overrides);
	}

	public function classify($endpoint): array {
		$endpoint = trim((string)$endpoint);
		$trunk = isset($this->trunks[$endpoint]);
		$device = isset($this->devices[$endpoint]);
		$manual = isset($this->overrides[$endpoint]) ? $this->overrides[$endpoint] : null;
		if ($trunk && $device) return ['endpoint' => $endpoint, 'type' => 'conflict', 'source' => 'freepbx-conflict', 'manual' => $manual, 'metadata' => ['trunk' => $this->trunks[$endpoint], 'device' => $this->devices[$endpoint]]];
		if ($trunk) return ['endpoint' => $endpoint, 'type' => 'trunk', 'source' => 'freepbx-trunk', 'manual' => $manual, 'metadata' => $this->trunks[$endpoint]];
		if ($device) return ['endpoint' => $endpoint, 'type' => 'extension', 'source' => 'freepbx-device', 'manual' => $manual, 'metadata' => $this->devices[$endpoint]];
		if ($manual !== null) return ['endpoint' => $endpoint, 'type' => $manual, 'source' => 'manual-override', 'manual' => $manual, 'metadata' => []];
		return ['endpoint' => $endpoint, 'type' => 'unknown', 'source' => 'unresolved', 'manual' => null, 'metadata' => []];
	}

	public function parseChannel($channel): ?string {
		return preg_match('|^PJSIP/([^/ ]+)-[0-9a-f]+$|i', trim((string)$channel), $match) ? $match[1] : null;
	}

	public function configuredTrunks(): array { return $this->trunks; }
	public function configuredDevices(): array { return $this->devices; }
	public function overrides(): array { return $this->overrides; }

	public function validateOverrideMap($value): array {
		if (!is_array($value)) throw new \InvalidArgumentException('PJSIP endpoint classifications must be an object/map.');
		$out = [];
		foreach ($value as $endpoint => $type) {
			if (!is_string($endpoint) || !$this->isValidEndpoint($endpoint)) throw new \InvalidArgumentException('Invalid PJSIP endpoint identifier.');
			if (!is_string($type) || !in_array($type, self::MANUAL_TYPES, true)) throw new \InvalidArgumentException('Invalid PJSIP endpoint classification.');
			$out[trim($endpoint)] = $type;
		}
		return $out;
	}

	public function repairOverrides($value): array {
		if (!is_array($value)) return [];
		$out = [];
		foreach ($value as $endpoint => $type) {
			if (!is_string($endpoint) || !$this->isValidEndpoint($endpoint) || !is_string($type) || !in_array($type, self::MANUAL_TYPES, true)) continue;
			$out[trim($endpoint)] = $type;
		}
		return $out;
	}

	public function validateEndpoint($endpoint): string {
		$endpoint = trim((string)$endpoint);
		if (!$this->isValidEndpoint($endpoint)) throw new \InvalidArgumentException('Invalid PJSIP endpoint identifier.');
		return $endpoint;
	}

	private function isValidEndpoint(string $endpoint): bool {
		return $endpoint !== '' && strlen($endpoint) <= self::MAX_ENDPOINT_LENGTH
			&& !preg_match('/[\x00-\x1F\x7F]/', $endpoint)
			&& (bool)preg_match('/^[A-Za-z0-9_.:@+\-]+$/', $endpoint);
	}
}
