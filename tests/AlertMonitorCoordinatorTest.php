<?php

require_once __DIR__ . '/../Services/ThresholdService.php';
require_once __DIR__ . '/../Services/AlertMonitorCoordinator.php';

use FreePBX\modules\Concurrencycount\Services\ThresholdService;
use FreePBX\modules\Concurrencycount\Services\AlertMonitorCoordinator;

function monitor_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

$thresholds = new ThresholdService();
$config = ['enabled' => true, 'threshold' => 8, 'alert_enabled' => true];
$state = [];
$values = [];
$events = [];
$available = true;
$evaluate = function (int $now) use (&$state, &$values, &$events, &$available, $thresholds, $config): array {
	if (!$available) return ['available' => false, 'errors' => ['AMI unavailable']];
	$current = isset($values[$now]) ? $values[$now] : 6;
	$transition = $thresholds->evaluate('overall', $current, $config, $state, true, true, $now);
	$state = $transition['state'];
	if ($transition['event'] !== null) $events[] = $transition['event'];
	return ['available' => true, 'current' => $current, 'events' => $transition['event'] === null ? [] : [$transition['event']]];
};

$monitor = new AlertMonitorCoordinator($evaluate, 5);
$values = [0 => 6, 5 => 9, 10 => 10, 15 => 9, 20 => 6];
$monitor->start(0);
$monitor->onEvent('Newchannel', 5);
$monitor->onTimer(10);
$monitor->onEvent('Newstate', 15);
$monitor->onEvent('Hangup', 20);
monitor_assert_same(2, count($events), 'Short spike produces one alert and one recovery');
monitor_assert_same('alert', $events[0]['type'], 'Short spike alert emitted');
monitor_assert_same(10, $events[1]['peak'], 'Sustained crossing tracks peak before recovery');
monitor_assert_same('recovery', $events[1]['type'], 'Short spike recovery emitted');
monitor_assert_same(15, $events[1]['timestamp'] - $events[1]['since'], 'Sub-minute episode duration retained');

// Persisted state survives a worker restart while the scope remains above threshold.
$events = [];
$state = ['status' => 'above', 'scope' => 'overall', 'threshold' => 8, 'since' => 100, 'peak' => 9, 'last_value' => 9];
$values = [105 => 9, 110 => 10, 115 => 7];
$restarted = new AlertMonitorCoordinator($evaluate, 5);
$restarted->start(105);
$restarted->onTimer(110);
$restarted->onTimer(115);
monitor_assert_same(1, count($events), 'Restart while above does not duplicate initial alert');
monitor_assert_same('recovery', $events[0]['type'], 'Restarted monitor still recovers persisted episode');
monitor_assert_same(10, $events[0]['peak'], 'Restarted monitor continues peak tracking');

// AMI failure is unavailable, not zero, and cannot cause recovery.
$events = [];
$state = ['status' => 'above', 'scope' => 'overall', 'threshold' => 8, 'since' => 200, 'peak' => 9, 'last_value' => 9];
$available = false;
$failed = new AlertMonitorCoordinator($evaluate, 5);
$result = $failed->start(205);
monitor_assert_same(false, $result['available'], 'AMI loss remains unavailable');
monitor_assert_same('above', $state['status'], 'AMI loss preserves above-threshold state');
monitor_assert_same(0, count($events), 'AMI loss does not emit false recovery');
$available = true;
$values = [210 => 7];
$failed->onTimer(210);
monitor_assert_same('recovery', $events[0]['type'], 'Recovery occurs only after a successful below-threshold snapshot');

// Oscillation produces one alert per distinct episode, never repeated checks while continuously above.
$events = [];
$state = [];
$values = [300 => 7, 305 => 8, 310 => 9, 315 => 7, 320 => 8, 325 => 8, 330 => 7];
$oscillation = new AlertMonitorCoordinator($evaluate, 5);
$oscillation->start(300);
foreach ([305, 310, 315, 320, 325, 330] as $timestamp) $oscillation->onTimer($timestamp);
monitor_assert_same(4, count($events), 'Oscillation emits one alert and recovery per distinct episode');
monitor_assert_same(['alert', 'recovery', 'alert', 'recovery'], array_column($events, 'type'), 'Oscillation does not flood sustained checks');

monitor_assert_same(null, $oscillation->onEvent('VarSet', 335), 'Irrelevant AMI event does not trigger reconciliation');

echo "Alert monitor coordinator tests passed\n";
