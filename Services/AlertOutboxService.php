<?php

namespace FreePBX\modules\Concurrencycount\Services;

class AlertOutboxService {
	public function queue(array $outbox, array $event, int $now): array {
		$eventId = isset($event['event_id']) ? (string)$event['event_id'] : hash('sha256', (string)$event['type'] . '|' . (string)$event['scope'] . '|' . (int)$event['timestamp'] . '|' . (int)(isset($event['since']) ? $event['since'] : $event['timestamp']));
		if (isset($outbox[$eventId])) return $outbox;
		$event['event_id'] = $eventId;
		$event['attempts'] = 0;
		$event['next_attempt_at'] = $now;
		$event['queued_at'] = $now;
		$outbox[$eventId] = $event;
		return $outbox;
	}

	public function nextReady(array $outbox, int $now): ?array {
		foreach ($outbox as $eventId => $event) {
			if ((int)(isset($event['next_attempt_at']) ? $event['next_attempt_at'] : 0) <= $now) return ['event_id' => (string)$eventId, 'event' => $event];
		}
		return null;
	}

	public function applyDelivery(array $outbox, string $eventId, bool $accepted, string $message, int $now): array {
		if (!isset($outbox[$eventId])) return $outbox;
		if ($accepted) {
			unset($outbox[$eventId]);
			return $outbox;
		}
		$event = $outbox[$eventId];
		$event['attempts'] = (int)(isset($event['attempts']) ? $event['attempts'] : 0) + 1;
		$event['last_error'] = $message;
		$event['next_attempt_at'] = $now + min(300, (int)pow(2, min(8, $event['attempts'])));
		$outbox[$eventId] = $event;
		return $outbox;
	}
}
