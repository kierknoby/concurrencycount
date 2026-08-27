<?php

$root = dirname(__DIR__);
$console = file_get_contents($root . '/Console/Concurrencycount.class.php');
if ($console === false) throw new Exception('Unable to read console command');

function console_contract_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

console_contract_assert(strpos($console, "->setName('concurrencycount')") !== false, 'Console command name changed');
$options = [
	['mode', 'm', 'VALUE_REQUIRED'],
	['start', 's', 'VALUE_REQUIRED'],
	['end', 'e', 'VALUE_REQUIRED'],
	['demo-report', 'null', 'VALUE_REQUIRED'],
	['demo-size', 'null', 'VALUE_REQUIRED'],
	['demo-seed', 'null', 'VALUE_REQUIRED'],
	['engine', 'null', 'VALUE_REQUIRED'],
	['compare', 'null', 'VALUE_REQUIRED'],
	['csv', 'null', 'VALUE_NONE'],
];
foreach ($options as $option) {
	$needle = "->addOption('" . $option[0] . "', " . ($option[1] === 'null' ? 'null' : "'" . $option[1] . "'") . ', InputOption::' . $option[2];
	console_contract_assert(strpos($console, $needle) !== false, 'Console option changed or missing: --' . $option[0]);
}
$newOptions = [
	['live', 'VALUE_NONE'], ['settings', 'VALUE_NONE'], ['set-refresh', 'VALUE_REQUIRED'],
	['set-overall-threshold', 'VALUE_REQUIRED'], ['overall-threshold', 'VALUE_REQUIRED'],
	['set-trunk-threshold', 'VALUE_REQUIRED'], ['trunk-threshold', 'VALUE_REQUIRED'],
	['alerts', 'VALUE_REQUIRED'], ['overall-alert', 'VALUE_REQUIRED'], ['trunk-alert', 'VALUE_REQUIRED'],
	['start-monitoring', 'VALUE_REQUIRED'], ['stop-monitoring', 'VALUE_REQUIRED'],
	['recovery', 'VALUE_REQUIRED'], ['alert-email', 'VALUE_REQUIRED'],
	['historical-graph', 'VALUE_REQUIRED'], ['graph-trunk', 'VALUE_REQUIRED'],
	['json', 'VALUE_NONE'], ['monitor', 'VALUE_NONE'],
	['monitor-status', 'VALUE_NONE'], ['restart-monitor', 'VALUE_NONE'],
	['list-historical-reports', 'VALUE_NONE'], ['show-historical-report', 'VALUE_REQUIRED'],
	['delete-historical-report', 'VALUE_REQUIRED'],
];
foreach ($newOptions as $option) {
	$needle = "->addOption('" . $option[0] . "', null, InputOption::" . $option[1];
	console_contract_assert(strpos($console, $needle) !== false, 'Management option missing: --' . $option[0]);
}
console_contract_assert(substr_count($console, '->addOption(') === count($options) + count($newOptions), 'Unexpected console option count');
console_contract_assert(strpos($console, '$cc->normaliseStartDate($start_raw)') !== false, 'Console start-date normalization changed');
console_contract_assert(strpos($console, '$cc->normaliseEndDate($end_raw)') !== false, 'Console end-date normalization changed');
console_contract_assert(strpos($console, '$cc->calculate($mode, $start, $end, true, [') !== false, 'Console calculation path changed');
console_contract_assert(strpos($console, 'peak_occurrences') === false && strpos($console, 'peakdetails') === false, 'GUI drill-down leaked into console output');
foreach (['getLiveStatus', 'getLiveSettings', 'saveLiveSettings', 'getHistoricalGraph', 'runThresholdMonitor', 'getAlertMonitorStatus', 'restartAlertMonitor'] as $sharedMethod) {
	console_contract_assert(strpos($console, '$cc->' . $sharedMethod . '(') !== false, 'CLI does not use shared backend method: ' . $sharedMethod);
}
console_contract_assert(strpos($console, 'if (!$requested) return null;') !== false, 'Legacy report path does not bypass management services');
console_contract_assert(strpos($console, 'JSON_PRETTY_PRINT') !== false, 'Machine-readable JSON output missing');
foreach (['Set browser refresh interval', 'Enable or disable threshold notifications globally', 'Show one current AMI live-status snapshot', 'Show the supervised threshold-alert monitor status', 'Restart the supervised threshold-alert monitor'] as $helpText) {
	console_contract_assert(strpos($console, $helpText) !== false, 'CLI help does not document: ' . $helpText);
}
console_contract_assert(strpos($console, "['start-monitoring' => true, 'stop-monitoring' => false]") !== false, 'Start/Stop Monitoring must share one settings mutation path');
console_contract_assert(strpos($console, "['monitored'] = \$monitored") !== false, 'CLI monitoring operations must update only the monitored field');
console_contract_assert(strpos($console, "!empty(\$scope['monitored']) ? 'active' : 'stopped'") !== false, 'Settings output must expose per-trunk monitoring state');

echo "Console contract passed\n";
