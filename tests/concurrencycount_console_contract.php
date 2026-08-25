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
console_contract_assert(substr_count($console, '->addOption(') === count($options), 'Console option count changed');
console_contract_assert(strpos($console, '$cc->normaliseStartDate($start_raw)') !== false, 'Console start-date normalization changed');
console_contract_assert(strpos($console, '$cc->normaliseEndDate($end_raw)') !== false, 'Console end-date normalization changed');
console_contract_assert(strpos($console, '$cc->calculate($mode, $start, $end, true, [') !== false, 'Console calculation path changed');
console_contract_assert(strpos($console, 'peak_occurrences') === false && strpos($console, 'peakdetails') === false, 'GUI drill-down leaked into console output');

echo "Console contract passed\n";
