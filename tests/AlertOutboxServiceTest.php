<?php

require_once __DIR__ . '/../Services/AlertOutboxService.php';

use FreePBX\modules\Concurrencycount\Services\AlertOutboxService;

function outbox_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$service = new AlertOutboxService();
$event = ['type' => 'alert', 'scope' => 'overall', 'timestamp' => 100, 'threshold' => 8, 'current' => 9, 'peak' => 9];
$outbox = $service->queue([], $event, 100);
outbox_assert_same(1, count($outbox), 'Alert event queued');
$eventId = array_keys($outbox)[0];
outbox_assert_same(64, strlen($eventId), 'Stable event ID generated');
$outbox = $service->queue($outbox, $event, 101);
outbox_assert_same(1, count($outbox), 'Duplicate episode event not queued twice');
$ready = $service->nextReady($outbox, 100);
outbox_assert_same($eventId, $ready['event_id'], 'Ready event selected');
$outbox = $service->applyDelivery($outbox, $eventId, false, 'mailer unavailable', 100);
outbox_assert_same(1, $outbox[$eventId]['attempts'], 'Failed delivery increments attempts');
outbox_assert_same(null, $service->nextReady($outbox, 101), 'Backoff prevents immediate retry');
outbox_assert_same($eventId, $service->nextReady($outbox, 102)['event_id'], 'Failed delivery becomes retryable');
$outbox = $service->applyDelivery($outbox, $eventId, true, '', 102);
outbox_assert_same([], $outbox, 'Accepted delivery removes outbox event');

$recovery = ['type' => 'recovery', 'scope' => 'overall', 'timestamp' => 120, 'since' => 100, 'threshold' => 8, 'current' => 7, 'peak' => 10];
$outbox = $service->queue([], $recovery, 120);
outbox_assert_same(1, count($outbox), 'Recovery event has independent durable identity');

echo "Alert outbox service tests passed\n";
