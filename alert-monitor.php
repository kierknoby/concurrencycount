#!/usr/bin/php -q
<?php

$bootstrap_settings['freepbx_auth'] = false;
$bootstrap_settings['astman_options']['cachemode'] = false;
$restrict_mods = [
	'concurrencycount' => true,
	'core' => true,
	'framework' => true,
	'pm2' => true,
];
require '/etc/freepbx.conf';

$freepbx = \FreePBX::Create();
$concurrencycount = $freepbx->Concurrencycount;
$running = true;
$dirtyEvent = '';
$lastSuccessfulSnapshotAt = 0;

if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, function () use (&$running): void { $running = false; });
	pcntl_signal(SIGINT, function () use (&$running): void { $running = false; });
}

$evaluate = function (int $now) use ($concurrencycount, &$lastSuccessfulSnapshotAt): array {
	$result = $concurrencycount->runThresholdMonitor();
	if (!empty($result['available'])) $lastSuccessfulSnapshotAt = $now;
	$concurrencycount->recordMonitorHeartbeat([
		'last_loop_at' => $now,
		'last_successful_snapshot_at' => $lastSuccessfulSnapshotAt,
		'ami_status' => !empty($result['available']) ? 'connected' : 'unavailable',
		'last_error' => empty($result['errors']) ? '' : implode('; ', $result['errors']),
	]);
	return $result;
};
$coordinator = new \FreePBX\modules\Concurrencycount\Services\AlertMonitorCoordinator($evaluate, 5);

$markDirty = function ($event) use (&$dirtyEvent): void {
	$dirtyEvent = strtolower((string)$event);
};
foreach (['Newchannel', 'Newstate', 'Hangup', 'Rename', 'Masquerade'] as $eventName) {
	$astman->add_event_handler($eventName, $markDirty);
}
$reconnectDelay = 1;
if ($astman->connected()) {
	$astman->Events('on');
	$coordinator->start(time());
}

while ($running) {
	if (!$astman->connected()) {
		$concurrencycount->recordMonitorHeartbeat(['last_loop_at' => time(), 'last_successful_snapshot_at' => $lastSuccessfulSnapshotAt, 'ami_status' => 'disconnected', 'last_error' => 'AMI disconnected; reconnecting.']);
		if ($astman->connect($astman->server . ':' . $astman->port, $astman->username, $astman->secret, 'on') === false) {
			sleep($reconnectDelay);
			$reconnectDelay = min(30, $reconnectDelay * 2);
			continue;
		}
		$reconnectDelay = 1;
		$astman->Events('on');
		$coordinator->start(time());
	}

	stream_set_timeout($astman->socket, 5);
	$response = $astman->wait_response(true, true);
	$now = time();
	if ($response === false) {
		$concurrencycount->recordMonitorHeartbeat(['last_loop_at' => $now, 'last_successful_snapshot_at' => $lastSuccessfulSnapshotAt, 'ami_status' => 'disconnected', 'last_error' => 'AMI event socket closed.']);
		$astman->disconnect();
		continue;
	}
	if ($dirtyEvent !== '') {
		$event = $dirtyEvent;
		$dirtyEvent = '';
		$coordinator->onEvent($event, $now);
	} else {
		$coordinator->onTimer($now);
	}
}

if ($astman->connected()) $astman->disconnect();
