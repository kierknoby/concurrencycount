<?php

require_once __DIR__ . '/../Services/HistoricalCallExclusionService.php';
require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
require_once __DIR__ . '/../Engines/Sweep.php';
require_once __DIR__ . '/../Services/PjsipIdentityService.php';

interface BMO {}
require_once __DIR__ . '/../Concurrencycount.class.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalCallExclusionService;
use FreePBX\modules\Concurrencycount\Engines\Original;
use FreePBX\modules\Concurrencycount\Engines\Sweep;
use FreePBX\modules\Concurrencycount\Services\PjsipIdentityService;

function exclusion_assert($expected, $actual, string $message): void {
	if ($expected !== $actual) throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$service = new HistoricalCallExclusionService();
$callA1 = ['linkedid' => 'call-a', 'uniqueid' => 'row-a1', 'src' => '201', 'dst' => '999', 'calldate' => '2026-08-27 10:00:00'];
$callA2 = ['linkedid' => 'call-a', 'uniqueid' => 'row-a2', 'src' => '201', 'dst' => '999', 'calldate' => '2026-08-27 10:00:01'];
$callB = ['linkedid' => 'call-b', 'uniqueid' => 'row-b', 'src' => '201', 'dst' => '999', 'calldate' => '2026-08-27 10:00:02'];
$fallback = ['linkedid' => '', 'uniqueid' => 'only-unique'];

exclusion_assert('linkedid:call-a', $service->identityForRow($callA1), 'linkedid is preferred logical identity');
exclusion_assert('uniqueid:only-unique', $service->identityForRow($fallback), 'uniqueid is safe fallback');
exclusion_assert(null, $service->identityForRow([]), 'Weak synthetic identity is not invented');

$stored = $service->exclude([], 'linkedid:call-a', ['calldate' => $callA1['calldate'], 'src' => '201', 'dst' => '999'], 1000);
$stored = $service->exclude($stored, 'linkedid:call-a', ['src' => 'changed'], 2000);
exclusion_assert(1, count($stored), 'Duplicate exclusion is idempotent');
exclusion_assert(1000, $stored['linkedid:call-a']['excluded_at'], 'Duplicate does not rewrite audit timestamp');
$filtered = $service->filterRows([$callA1, $callA2, $callB], $stored);
exclusion_assert([$callB], $filtered, 'Every row in one linked logical call is excluded and similar independent call remains');
$restored = $service->restore($stored, 'linkedid:call-a');
exclusion_assert([$callA1, $callA2, $callB], $service->filterRows([$callA1, $callA2, $callB], $restored), 'Restore makes all logical-call rows eligible');
$three = $service->exclude([], 'linkedid:a', [], 1);
$three = $service->exclude($three, 'linkedid:b', [], 2);
$three = $service->exclude($three, 'linkedid:c', [], 3);
exclusion_assert([], $service->repair([]), 'Restore All target is an empty exclusion set');

$overLimit = [];
for ($index = 0; $index < HistoricalCallExclusionService::MAX_EXCLUSIONS + 1; $index++) {
	$overLimit['linkedid:repair-' . $index] = ['excluded_at' => $index, 'summary' => []];
}
$repairedLimit = $service->repair($overLimit);
exclusion_assert(HistoricalCallExclusionService::MAX_EXCLUSIONS, count($repairedLimit), 'Stored-state repair enforces the exclusion cap');
exclusion_assert(true, isset($repairedLimit['linkedid:repair-0']), 'Stored-state repair retains the first valid entry');
exclusion_assert(true, isset($repairedLimit['linkedid:repair-4999']), 'Stored-state repair retains valid entries in existing order');
exclusion_assert(false, isset($repairedLimit['linkedid:repair-5000']), 'Stored-state repair drops entries beyond the cap');

$relevanceModule = (new ReflectionClass(FreePBX\modules\Concurrencycount::class))->newInstanceWithoutConstructor();
$identityProperty = new ReflectionProperty(FreePBX\modules\Concurrencycount::class, 'pjsipIdentityService');
$identityProperty->setAccessible(true);
$identityProperty->setValue($relevanceModule, new PjsipIdentityService([], ['201' => ['id' => '201'], '202' => ['id' => '202']]));
$matchesReport = new ReflectionMethod(FreePBX\modules\Concurrencycount::class, 'excludedCallMatchesReport');
$matchesReport->setAccessible(true);
$extensionReport = [
	'mode' => 'extension', 'filter' => '201', 'preset' => 'custom',
	'range_from' => '2026-08-26', 'range_to' => '2026-08-26', 'include_time' => false,
	'from_time' => '00:00', 'to_time' => '23:59',
];
$twoExtensionSides = [[
	'calldate' => '2026-08-26 10:00:00', 'duration' => 60, 'disposition' => 'ANSWERED',
	'channel' => 'PJSIP/201-aaaaaa', 'dstchannel' => 'PJSIP/202-bbbbbb',
]];
exclusion_assert(false, $matchesReport->invoke($relevanceModule, $twoExtensionSides, $extensionReport), 'Extension relevance does not match a classified source when a classified destination is assigned');
$extensionReport['filter'] = '202';
exclusion_assert(true, $matchesReport->invoke($relevanceModule, $twoExtensionSides, $extensionReport), 'Extension relevance matches the destination-preferred assignment');
$extensionReport['filter'] = '201';
$sourceOnly = [[
	'calldate' => '2026-08-26 10:00:00', 'duration' => 60, 'disposition' => 'ANSWERED',
	'channel' => 'PJSIP/201-aaaaaa', 'dstchannel' => '',
]];
exclusion_assert(true, $matchesReport->invoke($relevanceModule, $sourceOnly, $extensionReport), 'Extension relevance uses the classified source when no classified destination exists');

$engineRows = [
	['calldate' => '2026-08-27 10:00:00', 'duration' => 60, 'identity' => 'TRUNK', 'linkedid' => 'call-a'],
	['calldate' => '2026-08-27 10:00:30', 'duration' => 60, 'identity' => 'TRUNK', 'linkedid' => 'call-b'],
];
$eligible = $service->filterRows($engineRows, $stored);
$options = ['all_names' => ['TRUNK' => true], 'coalesce_ranges' => function (array $times): array { return []; }, 'check_overrun' => function (): void {}];
foreach ([new Original($options), new Sweep($options)] as $engine) {
	$result = $engine->calculatePerName('trunk', $eligible);
	exclusion_assert(1, $result['global_max'], $engine->name() . ' receives the same exclusion-filtered input');
	$extensionResult = $engine->calculatePerName('extension', $eligible);
	exclusion_assert(1, $extensionResult['global_max'], $engine->name() . ' extension mode receives the same exclusion-filtered input');
}
$groupRows = [
	['calldate' => '2026-08-27 10:00:00', 'duration' => 60, 'extension_legs' => 2, 'linkedid' => 'call-a'],
	['calldate' => '2026-08-27 10:00:30', 'duration' => 60, 'extension_legs' => 1, 'linkedid' => 'call-b'],
];
foreach ([new Original($options), new Sweep($options)] as $engine) {
	$group = $engine->calculateGroup($service->filterRows($groupRows, $stored));
	exclusion_assert(1, $group['max_concurrency'], $engine->name() . ' group mode receives the same exclusion-filtered input');
}

$bad = false;
try { $service->validateIdentity('timestamp:2026-08-27'); } catch (InvalidArgumentException $exception) { $bad = true; }
exclusion_assert(true, $bad, 'Browser cannot submit an unsupported identity type');

echo "Historical call exclusion service tests passed\n";
