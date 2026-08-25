<?php

namespace FreePBX\modules\Concurrencycount\Resolvers;

class FreepbxEntityResolver {
	private $destinationProvider;

	public function __construct(callable $destinationProvider = null) {
		$this->destinationProvider = $destinationProvider;
	}

	public function resolveDestination($destination, string $source = 'observed'): ?array {
		$destination = trim((string)$destination);
		if (!$this->isSafeDestination($destination)) {
			return null;
		}

		$info = $this->lookupDestination($destination);
		if (empty($info) || empty($info['description'])) {
			return null;
		}

		$parts = explode(',', $destination);
		$context = isset($parts[0]) ? $parts[0] : '';
		$id = isset($parts[1]) ? $parts[1] : '';
		$url = isset($info['edit_url']) ? $this->safeLocalUrl($info['edit_url']) : null;
		$type = $this->destinationType($context, isset($info['data']) && is_array($info['data']) ? $info['data'] : []);

		return [
			'type' => $type,
			'id' => $id,
			'label' => (string)$info['description'],
			'number' => $this->destinationNumber($context, $id),
			'module' => $this->moduleFromUrl($url),
			'native_url' => $url,
			'source' => $source === 'configured' ? 'configured' : 'observed',
			'destination' => $destination,
		];
	}

	public function safeLocalUrl($url): ?string {
		$url = trim((string)$url);
		if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
			return null;
		}
		if (!preg_match('|^(?:\./)?config\.php\?[A-Za-z0-9_.~%=&+\-]*$|', $url)) {
			return null;
		}
		$url = preg_replace('|^\./|', '', $url);
		return str_replace('+', '%2B', $url);
	}

	private function lookupDestination(string $destination): array {
		if ($this->destinationProvider !== null) {
			$result = call_user_func($this->destinationProvider, $destination);
			return is_array($result) ? $result : [];
		}

		$functions = get_defined_functions();
		foreach ($functions['user'] as $function) {
			if (!preg_match('/^[a-z0-9_]+_getdestinfo$/i', $function)) {
				continue;
			}
			try {
				$result = call_user_func($function, $destination);
				if (is_array($result) && !empty($result['description'])) {
					return $result;
				}
			} catch (\Throwable $exception) {
				continue;
			}
		}
		return [];
	}

	private function isSafeDestination(string $destination): bool {
		return $destination !== ''
			&& strlen($destination) <= 255
			&& (bool)preg_match('/^[A-Za-z0-9_.+*#@:\-]+(?:,[A-Za-z0-9_.+*#@:\/\-]*){1,3}$/', $destination);
	}

	private function destinationType(string $context, array $data): string {
		if (!empty($data['gqltype']) && preg_match('/^[a-z0-9_-]+$/i', (string)$data['gqltype'])) {
			return strtolower((string)$data['gqltype']);
		}
		$patterns = [
			'from-did-direct' => 'extension', 'ext-local' => 'voicemail', 'ext-trunk' => 'trunk',
			'ext-queues' => 'queue', 'ext-group' => 'ringgroup', 'ivr-' => 'ivr',
			'app-announcement-' => 'announcement', 'timeconditions' => 'timecondition',
			'ext-meetme' => 'conference', 'app-daynight' => 'callflow',
			'app-blackhole' => 'terminate', 'followme-' => 'followme',
		];
		foreach ($patterns as $prefix => $type) {
			if (strpos($context, $prefix) === 0) {
				return $type;
			}
		}
		return 'destination';
	}

	private function destinationNumber(string $context, string $id): string {
		if (in_array($context, ['from-did-direct', 'ext-queues', 'ext-group', 'ext-meetme'], true)) {
			return $id;
		}
		if ($context === 'ext-local' && preg_match('/^vm[busia]([0-9*#]+)$/', $id, $match)) {
			return $match[1];
		}
		return '';
	}

	private function moduleFromUrl(?string $url): string {
		if ($url === null) {
			return '';
		}
		$query = parse_url($url, PHP_URL_QUERY);
		$params = [];
		parse_str((string)$query, $params);
		return isset($params['display']) && preg_match('/^[A-Za-z0-9_-]+$/', (string)$params['display'])
			? (string)$params['display']
			: '';
	}
}
