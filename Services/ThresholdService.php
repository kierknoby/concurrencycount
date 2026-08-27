<?php

namespace FreePBX\modules\Concurrencycount\Services;

class ThresholdService {
	const ALLOWED_REFRESH_INTERVALS = [1, 5, 10, 15, 30, 60];

	public function defaults(): array {
		return [
			'refresh_interval' => 5,
			'alerts_enabled' => false,
			'recovery_enabled' => true,
			'alert_email' => '',
			'hidden_trunks' => [],
			'trunk_order' => [],
			'overall' => $this->scopeDefaults(),
			'trunks' => [],
		];
	}

	public function normalise(array $input, array $trunks = []): array {
		return $this->normaliseInternal($input, $trunks, true);
	}

	public function reconcileStored(array $input, array $trunks = []): array {
		return $this->normaliseInternal($input, $trunks, false);
	}

	private function normaliseInternal(array $input, array $trunks, bool $rejectUnknownTrunks): array {
		$defaults = $this->defaults();
		$refresh = isset($input['refresh_interval']) ? (int)$input['refresh_interval'] : $defaults['refresh_interval'];
		if (!in_array($refresh, self::ALLOWED_REFRESH_INTERVALS, true)) {
			throw new \InvalidArgumentException('Refresh interval must be 1, 5, 10, 15, 30 or 60 seconds.');
		}
		$email = trim((string)(isset($input['alert_email']) ? $input['alert_email'] : ''));
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new \InvalidArgumentException('Alert email address is invalid.');
		}
		$result = [
			'refresh_interval' => $refresh,
			'alerts_enabled' => $this->toBool(isset($input['alerts_enabled']) ? $input['alerts_enabled'] : false),
			'recovery_enabled' => $this->toBool(isset($input['recovery_enabled']) ? $input['recovery_enabled'] : true),
			'alert_email' => $email,
			'hidden_trunks' => $this->normaliseIdentifierList(isset($input['hidden_trunks']) ? $input['hidden_trunks'] : [], 'Hidden trunks', $rejectUnknownTrunks),
			'trunk_order' => $this->normaliseIdentifierList(isset($input['trunk_order']) ? $input['trunk_order'] : [], 'Trunk order', $rejectUnknownTrunks),
			'overall' => $this->normaliseScope(isset($input['overall']) && is_array($input['overall']) ? $input['overall'] : []),
			'trunks' => [],
		];
		$allowed = array_fill_keys($trunks, true);
		$provided = isset($input['trunks']) && is_array($input['trunks']) ? $input['trunks'] : [];
		foreach ($provided as $trunk => $scope) {
			if (!is_string($trunk) || !is_array($scope)) {
				throw new \InvalidArgumentException('Threshold configuration contains an invalid trunk.');
			}
			if (!isset($allowed[$trunk])) {
				if ($rejectUnknownTrunks) throw new \InvalidArgumentException('Threshold configuration contains an invalid trunk.');
				continue;
			}
			$result['trunks'][$trunk] = $this->normaliseTrunkScope($scope, $rejectUnknownTrunks);
		}
		foreach ($trunks as $trunk) {
			if (!isset($result['trunks'][$trunk])) $result['trunks'][$trunk] = $this->trunkScopeDefaults();
			if (!in_array($trunk, $result['trunk_order'], true)) $result['trunk_order'][] = $trunk;
		}
		return $result;
	}

	private function normaliseIdentifierList($value, string $label, bool $strict): array {
		if (!is_array($value)) {
			if ($strict) throw new \InvalidArgumentException($label . ' must be a list.');
			return [];
		}
		$result = [];
		$seen = [];
		foreach ($value as $identifier) {
			if (!is_string($identifier)) {
				if ($strict) throw new \InvalidArgumentException($label . ' contains an invalid trunk identifier.');
				continue;
			}
			$identifier = trim($identifier);
			if ($identifier === '' || strlen($identifier) > 128 || preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
				if ($strict) throw new \InvalidArgumentException($label . ' contains an invalid trunk identifier.');
				continue;
			}
			if (isset($seen[$identifier])) continue;
			$seen[$identifier] = true;
			$result[] = $identifier;
		}
		return $result;
	}

	public function evaluate(string $scope, int $current, array $scopeConfig, array $state, bool $masterAlertsEnabled, bool $recoveryEnabled, int $now): array {
		$configured = !empty($scopeConfig['enabled']) && isset($scopeConfig['threshold']) && (int)$scopeConfig['threshold'] > 0;
		$threshold = $configured ? (int)$scopeConfig['threshold'] : 0;
		$notifications = $masterAlertsEnabled && !empty($scopeConfig['alert_enabled']) && $configured;
		$status = isset($state['status']) && $state['status'] === 'above' ? 'above' : 'normal';
		$event = null;
		if (!$notifications) {
			return ['state' => $this->normalState(), 'event' => null];
		}
		if ($current >= $threshold) {
			if ($status !== 'above') {
				$state = [
					'status' => 'above', 'scope' => $scope, 'threshold' => $threshold,
					'since' => $now, 'peak' => $current, 'last_value' => $current,
				];
				$event = ['type' => 'alert', 'scope' => $scope, 'threshold' => $threshold, 'current' => $current, 'peak' => $current, 'timestamp' => $now];
			} else {
				$state['peak'] = max((int)(isset($state['peak']) ? $state['peak'] : 0), $current);
				$state['last_value'] = $current;
			}
			return ['state' => $state, 'event' => $event];
		}
		if ($status === 'above') {
			if ($recoveryEnabled) {
				$event = [
					'type' => 'recovery', 'scope' => $scope, 'threshold' => $threshold,
					'current' => $current, 'peak' => (int)(isset($state['peak']) ? $state['peak'] : 0),
					'since' => (int)(isset($state['since']) ? $state['since'] : $now),
					'timestamp' => $now,
				];
			}
			return ['state' => $this->normalState(), 'event' => $event];
		}
		return ['state' => $this->normalState(), 'event' => null];
	}

	public function buildNotification(array $event, string $systemIdentifier): array {
		$scope = $event['scope'] === 'overall' ? 'Overall Live Concurrency' : substr((string)$event['scope'], 6) . ' Trunk';
		$isRecovery = $event['type'] === 'recovery';
		$subject = sprintf('Concurrency %s on %s', $isRecovery ? 'recovered' : 'threshold exceeded', $systemIdentifier);
		$lines = [
			'Concurrency Count alert from ' . $systemIdentifier, '', $scope,
			'Threshold: ' . (int)$event['threshold'], 'Current: ' . (int)$event['current'],
		];
		if (!empty($event['direction_counts'])) {
			$lines[] = 'Inbound: ' . (int)(isset($event['direction_counts']['inbound']) ? $event['direction_counts']['inbound'] : 0);
			$lines[] = 'Outbound: ' . (int)(isset($event['direction_counts']['outbound']) ? $event['direction_counts']['outbound'] : 0);
			$lines[] = 'Unknown: ' . (int)(isset($event['direction_counts']['unknown']) ? $event['direction_counts']['unknown'] : 0);
		}
		$lines[] = 'Peak during alert: ' . (int)$event['peak'];
		if ($isRecovery) $lines[] = 'Duration above threshold: ' . max(0, (int)$event['timestamp'] - (int)$event['since']) . ' seconds';
		$lines[] = 'Time: ' . date('Y-m-d H:i:s', (int)$event['timestamp']);
		$lines[] = 'Module: config.php?display=concurrencycount';
		$lines[] = '';
		$lines[] = 'Accepted by the local mailer does not confirm external delivery.';
		return ['subject' => $subject, 'body' => implode("\n", $lines)];
	}

	private function normaliseScope(array $scope): array {
		$enabled = $this->toBool(isset($scope['enabled']) ? $scope['enabled'] : false);
		$threshold = isset($scope['threshold']) ? (int)$scope['threshold'] : 0;
		if ($threshold < 0 || $threshold > 10000) {
			throw new \InvalidArgumentException('Threshold must be between 0 and 10000.');
		}
		if ($threshold === 0) $enabled = false;
		return [
			'enabled' => $enabled,
			'threshold' => $threshold,
			'alert_enabled' => $this->toBool(isset($scope['alert_enabled']) ? $scope['alert_enabled'] : false),
		];
	}

	private function normaliseTrunkScope(array $scope, bool $strict): array {
		try {
			$monitored = $this->toBool(isset($scope['monitored']) ? $scope['monitored'] : true);
		} catch (\InvalidArgumentException $exception) {
			if ($strict) throw $exception;
			$monitored = true;
		}
		return $this->normaliseScope($scope) + [
			'monitored' => $monitored,
		];
	}

	private function scopeDefaults(): array {
		return ['enabled' => false, 'threshold' => 0, 'alert_enabled' => false];
	}

	private function trunkScopeDefaults(): array {
		return $this->scopeDefaults() + ['monitored' => true];
	}

	private function normalState(): array {
		return ['status' => 'normal', 'since' => 0, 'peak' => 0, 'last_value' => 0];
	}

	private function toBool($value): bool {
		if (is_bool($value)) return $value;
		$normalised = strtolower(trim((string)$value));
		if (in_array($normalised, ['1', 'true', 'yes', 'on', 'enabled'], true)) return true;
		if (in_array($normalised, ['0', 'false', 'no', 'off', 'disabled'], true)) return false;
		throw new \InvalidArgumentException('Boolean settings must use on or off.');
	}
}
