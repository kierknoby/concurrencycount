<?php

require_once __DIR__ . '/../Services/PjsipIdentityService.php';
require_once __DIR__ . '/../Services/HistoricalEndpointFilterService.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalEndpointFilterService;
use FreePBX\modules\Concurrencycount\Services\PjsipIdentityService;

function hef_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$identity = new PjsipIdentityService(
	['trunk-a' => ['channelid' => 'trunk-a'], '12345' => ['channelid' => '12345']],
	['ext-a' => ['id' => 'ext-a'], 'ext-b' => ['id' => 'ext-b']],
	[]
);
$service = new HistoricalEndpointFilterService();
$trunks = [['identity' => 'trunk-a'], ['identity' => '12345']];
hef_assert_same($trunks, $service->apply('trunk', $trunks, $identity, '')['rows'], 'Unfiltered trunk report retains both trunks');
hef_assert_same([['identity' => 'trunk-a']], $service->apply('trunk', $trunks, $identity, 'trunk-a')['rows'], 'Trunk A filter retains only trunk A');
hef_assert_same([['identity' => '12345']], $service->apply('trunk', $trunks, $identity, '12345')['rows'], 'Numeric trunk filter is authoritative');
$extensions = [['identity' => 'ext-a'], ['identity' => 'ext-b']];
hef_assert_same([['identity' => 'ext-a']], $service->apply('extension', $extensions, $identity, 'ext-a')['rows'], 'Extension A filter retains only extension A');
hef_assert_same([['identity' => 'ext-b']], $service->apply('extension', $extensions, $identity, 'ext-b')['rows'], 'Extension B filter retains only extension B');
$missing = $service->apply('trunk', $trunks, $identity, 'removed-trunk');
hef_assert_same([], $missing['rows'], 'Missing persisted endpoint never falls back to all endpoints');
hef_assert_same(true, $missing['missing_reference'], 'Missing persisted endpoint remains marked missing');
$groupRejected = false;
try { $service->apply('group', [], $identity, 'trunk-a'); } catch (InvalidArgumentException $exception) { $groupRejected = true; }
hef_assert_same(true, $groupRejected, 'Group endpoint filter is rejected');

echo "Historical endpoint filter service tests passed\n";
