<?php

namespace FreePBX\modules\Concurrencycount\Services;

class LiveSnapshotService {
	public function analyse(array $channels, PjsipIdentityService $identity, array $thresholds = [], ?int $now = null): array {
		$now = $now === null ? time() : $now;
		$trunks = array_keys($identity->configuredTrunks());
		$overallCalls = [];
		$trunkResults = [];
		foreach ($trunks as $trunk) {
			$trunkResults[$trunk] = [
				'name' => $trunk,
				'current' => 0,
				'direction_counts' => ['inbound' => 0, 'outbound' => 0, 'unknown' => 0],
				'calls' => [],
			];
		}

		$anomalies = [];
		foreach ($channels as $rawChannel) {
			$channel = $this->normaliseChannel($rawChannel, $now);
			if ($channel === null) continue;
			$endpoint = $channel['endpoint'];
			$classification = $identity->classify($endpoint);
			if ($classification['type'] === 'extension') {
				continue;
			}
			if ($classification['type'] === 'unknown' || $classification['type'] === 'conflict') {
				$anomalies[$endpoint] = $classification;
				continue;
			}
			if ($classification['type'] !== 'trunk') continue;
			if (!isset($trunkResults[$endpoint])) {
				$trunkResults[$endpoint] = ['name' => $endpoint, 'current' => 0, 'direction_counts' => ['inbound' => 0, 'outbound' => 0, 'unknown' => 0], 'calls' => [], 'classification_source' => $classification['source']];
			}
			$channel['scope'] = 'trunk';
			$channel['trunk'] = $endpoint;
			$channel['direction'] = $this->classifyDirection($channel['context']);
			$trunkResults[$endpoint]['current']++;
			$trunkResults[$endpoint]['direction_counts'][$channel['direction']]++;
			$trunkResults[$endpoint]['calls'][] = $channel;
			// Overall Live Concurrency is the total current attributable trunk legs.
			$overallCalls[] = $channel;
		}

		$overallThreshold = isset($thresholds['overall']) ? $thresholds['overall'] : [];
		$overall = [
			'current' => count($overallCalls),
			'calls' => $overallCalls,
		];
		$overall = array_merge($overall, $this->statusFor($overall['current'], $overallThreshold));
		foreach ($trunkResults as $trunk => &$result) {
			$threshold = isset($thresholds['trunks'][$trunk]) ? $thresholds['trunks'][$trunk] : [];
			$result = array_merge($result, $this->statusFor($result['current'], $threshold));
		}
		unset($result);

		return [
			'available' => true,
			'source' => 'asterisk_ami',
			'generated_at' => date('Y-m-d H:i:s', $now),
			'generated_ts' => $now,
			'overall' => $overall,
			'trunks' => $trunkResults,
			'identity_anomalies' => array_values($anomalies),
		];
	}

	public function unavailable(string $message, ?int $now = null): array {
		$now = $now === null ? time() : $now;
		return [
			'available' => false,
			'source' => 'asterisk_ami',
			'generated_at' => date('Y-m-d H:i:s', $now),
			'generated_ts' => $now,
			'message' => $message,
			'overall' => ['current' => 0, 'calls' => [], 'threshold' => 0, 'threshold_enabled' => false, 'status' => 'unavailable'],
			'trunks' => [],
		];
	}

	private function normaliseChannel(array $raw, int $now): ?array {
		$data = [];
		foreach ($raw as $key => $value) $data[strtolower((string)$key)] = $value;
		$name = trim((string)(isset($data['channel']) ? $data['channel'] : ''));
		if (!preg_match('|^PJSIP/([^/ ]+)-([0-9a-f]+)$|i', $name, $match)) return null;
		$created = isset($data['creationtime']) ? strtotime((string)$data['creationtime']) : false;
		$duration = $this->durationSeconds(isset($data['duration']) ? $data['duration'] : null);
		if ($duration === 0 && $created !== false) $duration = max(0, $now - $created);
		return [
			'channel' => $name,
			'endpoint' => $match[1],
			'state' => (string)(isset($data['channelstatedesc']) ? $data['channelstatedesc'] : (isset($data['channelstate']) ? $data['channelstate'] : 'Unknown')),
			'caller_id_num' => (string)(isset($data['calleridnum']) ? $data['calleridnum'] : ''),
			'caller_id_name' => (string)(isset($data['calleridname']) ? $data['calleridname'] : ''),
			'connected_num' => (string)(isset($data['connectedlinenum']) ? $data['connectedlinenum'] : ''),
			'connected_name' => (string)(isset($data['connectedlinename']) ? $data['connectedlinename'] : ''),
			'context' => (string)(isset($data['context']) ? $data['context'] : ''),
			'extension_value' => (string)(isset($data['exten']) ? $data['exten'] : ''),
			'application' => (string)(isset($data['application']) ? $data['application'] : ''),
			'application_data' => (string)(isset($data['applicationdata']) ? $data['applicationdata'] : ''),
			'duration_seconds' => $duration,
			'bridge_id' => (string)(isset($data['bridgeid']) ? $data['bridgeid'] : ''),
			'uniqueid' => (string)(isset($data['uniqueid']) ? $data['uniqueid'] : ''),
			'linkedid' => (string)(isset($data['linkedid']) ? $data['linkedid'] : ''),
			'direction' => 'unknown',
		];
	}

	private function classifyDirection(string $context): string {
		$context = strtolower($context);
		if (preg_match('/^(from-trunk|from-pstn)/', $context)) return 'inbound';
		if (preg_match('/^(from-internal|macro-dialout-trunk|sub-dialout-trunk)/', $context)) return 'outbound';
		return 'unknown';
	}

	private function durationSeconds($duration): int {
		if (is_numeric($duration)) return max(0, (int)$duration);
		if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', (string)$duration, $match)) {
			return ((int)$match[1] * 3600) + ((int)$match[2] * 60) + (int)$match[3];
		}
		return 0;
	}

	private function statusFor(int $current, array $threshold): array {
		$enabled = !empty($threshold['enabled']) && isset($threshold['threshold']) && (int)$threshold['threshold'] > 0;
		$value = $enabled ? (int)$threshold['threshold'] : 0;
		$status = 'normal';
		if ($enabled && $current >= $value) $status = 'exceeded';
		elseif ($enabled && $current >= max(1, (int)ceil($value * 0.8))) $status = 'approaching';
		return ['threshold' => $value, 'threshold_enabled' => $enabled, 'status' => $status];
	}
}
