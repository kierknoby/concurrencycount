<?php

require_once dirname(__DIR__) . '/Services/HistoricalResultFloor.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalResultFloor;

function floor_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$floor = new HistoricalResultFloor();
floor_assert($floor->normalise(null) === null, 'Null must disable the floor');
floor_assert($floor->normalise('  ') === null, 'Blank must disable the floor');
floor_assert($floor->normalise('4') === 4, 'Integer string must be accepted');
floor_assert($floor->normalise(2147483647) === 2147483647, 'Upper bound must be accepted');
foreach ([0, -1, '1.5', 'four', true, 2147483648] as $invalid) {
	$rejected = false;
	try { $floor->normalise($invalid); } catch (InvalidArgumentException $e) { $rejected = true; }
	floor_assert($rejected, 'Invalid floor was accepted: ' . var_export($invalid, true));
}

$perName = [
	'mode' => 'trunk', 'global_max' => 5,
	'per_name' => ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'boundary' => 4, 'peak' => 5],
	'peak_occurrences' => ['zero' => [], 'one' => [], 'two' => [], 'three' => [], 'boundary' => [['from' => 'b']], 'peak' => [['from' => 'c']]],
	'trunk_entities' => ['zero' => [], 'one' => [], 'two' => [], 'three' => [], 'boundary' => ['id' => 2], 'peak' => ['id' => 3]],
];
$unchanged = $floor->apply($perName, null);
floor_assert($unchanged['per_name'] === $perName['per_name'] && $unchanged['minimum_concurrency'] === null, 'Blank floor must preserve detail');
$filtered = $floor->apply($perName, 4);
floor_assert($filtered['per_name'] === ['boundary' => 4, 'peak' => 5], 'Per-name floor must be inclusive');
floor_assert(array_keys($filtered['peak_occurrences']) === ['boundary', 'peak'], 'Trunk occurrences must follow visible trunks');
floor_assert(array_keys($filtered['trunk_entities']) === ['boundary', 'peak'], 'Trunk entities must follow visible trunks');
floor_assert($filtered['global_max'] === 5 && $filtered['calculated_global_max'] === 5, 'Per-name calculated peak truth must remain visible');
floor_assert(!isset($filtered['floor_notice']), 'Matching detail must not produce a no-match notice');

$noDetails = $floor->apply($perName, 6);
floor_assert($noDetails['per_name'] === [], 'Details below the floor must be hidden');
floor_assert($noDetails['global_max'] === 5, 'A floor above the peak must retain the actual peak');
floor_assert(!isset($noDetails['empty_message']), 'A completed floor-filtered result must not look like no Historical data');
floor_assert($noDetails['floor_notice'] === 'No periods reached the minimum concurrency of 6 during the selected date range.', 'Floor no-match notice changed');

$extension = $perName;
$extension['mode'] = 'extension';
unset($extension['peak_occurrences'], $extension['trunk_entities']);
floor_assert($floor->apply($extension, 4)['per_name'] === ['boundary' => 4, 'peak' => 5], 'Extension must use the same inclusive transformation');
$original = $extension;
$original['engine'] = 'original';
$sweep = $extension;
$sweep['engine'] = 'sweep';
$originalFiltered = $floor->apply($original, 4);
$sweepFiltered = $floor->apply($sweep, 4);
unset($originalFiltered['engine'], $sweepFiltered['engine']);
floor_assert($originalFiltered === $sweepFiltered, 'Equivalent Original and Sweep results must remain equivalent after the floor');

$group = ['mode' => 'group', 'max_concurrency' => 3, 'peak_ranges' => [['from' => 'a', 'to' => 'b']]];
$groupFiltered = $floor->apply($group, 4);
floor_assert($groupFiltered['max_concurrency'] === 3 && $groupFiltered['calculated_max_concurrency'] === 3, 'Group peak truth must remain visible');
floor_assert($groupFiltered['peak_ranges'] === [] && isset($groupFiltered['floor_notice']), 'Group periods below the floor must be hidden with a notice');
$groupBoundary = $floor->apply(['mode' => 'group', 'max_concurrency' => 4, 'peak_ranges' => [['from' => 'a', 'to' => 'b']]], 4);
floor_assert(count($groupBoundary['peak_ranges']) === 1 && !isset($groupBoundary['floor_notice']), 'Group floor must be inclusive');

$demo = [
	'mode' => 'demo', 'demo_report' => 'extension', 'global_max' => 5, 'accuracy_status' => 'pass',
	'per_name' => ['low' => 2, 'high' => 5], 'expected_per_name' => ['low' => 2, 'high' => 5],
	'engines' => [
		'original' => ['global_max' => 5, 'per_name' => ['low' => 2, 'high' => 5], 'accuracy_status' => 'pass'],
		'sweep' => ['global_max' => 5, 'per_name' => ['low' => 2, 'high' => 5], 'accuracy_status' => 'pass'],
	],
];
$demoFiltered = $floor->apply($demo, 5);
floor_assert($demoFiltered['per_name'] === ['high' => 5] && $demoFiltered['expected_per_name'] === ['high' => 5], 'Demo detail must use the floor');
floor_assert($demoFiltered['global_max'] === 5 && $demoFiltered['accuracy_status'] === 'pass', 'Demo result truth and accuracy must remain unchanged');
foreach ($demoFiltered['engines'] as $engine) {
	floor_assert($engine['per_name'] === ['high' => 5] && $engine['global_max'] === 5 && $engine['accuracy_status'] === 'pass', 'Demo engine detail must be filtered without changing engine truth');
}

$empty = ['mode' => 'extension', 'empty_message' => 'No eligible Historical data.', 'per_name' => [], 'global_max' => 0];
$emptyFiltered = $floor->apply($empty, 4);
floor_assert($emptyFiltered['empty_message'] === $empty['empty_message'] && !isset($emptyFiltered['floor_notice']), 'No-data and floor-no-match states must remain distinct');

$graph = ['mode' => 'group', 'series' => ['overall' => ['exact_peak' => 5, 'points' => [
	['ts' => 1, 'value' => 1], ['ts' => 2, 'value' => 3], ['ts' => 3, 'value' => 4], ['ts' => 4, 'value' => 5],
]]]];
$blankGraph = $floor->applyGraph($graph, null);
floor_assert($blankGraph['series']['overall']['points'] === $graph['series']['overall']['points'], 'Blank graph floor must preserve every display point');
$filteredGraph = $floor->applyGraph($graph, 4);
floor_assert($filteredGraph['series']['overall']['points'] === [['ts' => 1, 'value' => null], ['ts' => 2, 'value' => null], ['ts' => 3, 'value' => 4], ['ts' => 4, 'value' => 5]], 'Graph floor must hide lower values as gaps and include its exact boundary and higher points');
floor_assert($filteredGraph['series']['overall']['exact_peak'] === 5, 'Graph floor must preserve the exact underlying peak');

echo "Historical result floor tests passed\n";
