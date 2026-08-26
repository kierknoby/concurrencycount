#!/usr/bin/php -q
<?php

$bootstrap_settings['freepbx_auth'] = false;
$bootstrap_settings['skip_astman'] = true;
$restrict_mods = [
	'concurrencycount' => true,
	'framework' => true,
	'pm2' => true,
];
require '/etc/freepbx.conf';

$freepbx = \FreePBX::Create();
$concurrencycount = $freepbx->Concurrencycount;
$running = true;
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, function () use (&$running): void { $running = false; });
	pcntl_signal(SIGINT, function () use (&$running): void { $running = false; });
}

while ($running) {
	try {
		$concurrencycount->processAlertOutbox();
	} catch (\Throwable $exception) {
		error_log('[concurrencycount] Alert mail worker error: ' . $exception->getMessage());
	}
	if ($running) sleep(1);
}
