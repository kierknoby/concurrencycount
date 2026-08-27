<?php

require_once __DIR__ . '/../Services/PjsipIdentityService.php';

use FreePBX\modules\Concurrencycount\Services\PjsipIdentityService;

function identity_assert($expected, $actual, string $message): void {
	if ($expected !== $actual) throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$service = new PjsipIdentityService([
	'MYTRUNK' => ['channelid' => 'MYTRUNK', 'trunkid' => '1', 'disabled' => false],
	'123456' => ['channelid' => '123456', 'trunkid' => '2', 'disabled' => true],
	'collision' => ['channelid' => 'collision', 'trunkid' => '3'],
], [
	'201' => ['id' => '201'], '999' => ['id' => '999'],
	'warehouse-phone' => ['id' => 'warehouse-phone'], 'collision' => ['id' => 'collision'],
], [
	'custom-gateway' => 'trunk', 'legacy-phone' => 'extension', 'test-peer' => 'ignore',
	'201' => 'trunk', 'MYTRUNK' => 'extension', 'collision' => 'ignore',
]);

identity_assert('trunk', $service->classify('MYTRUNK')['type'], 'Configured trunk');
identity_assert('freepbx-trunk', $service->classify('123456')['source'], 'Numeric configured trunk is authoritative even when disabled');
identity_assert('extension', $service->classify('201')['type'], 'Numeric configured device supersedes stale manual trunk');
identity_assert('extension', $service->classify('warehouse-phone')['type'], 'Alphanumeric configured device');
identity_assert('extension', $service->classify('999')['type'], 'Configured endpoint 999 is an extension');
identity_assert('trunk', $service->classify('custom-gateway')['type'], 'Manual trunk');
identity_assert('extension', $service->classify('legacy-phone')['type'], 'Manual extension');
identity_assert('ignore', $service->classify('test-peer')['type'], 'Manual ignore');
identity_assert('unknown', $service->classify('deleted-peer')['type'], 'Deleted/unknown endpoint is unresolved');
identity_assert('conflict', $service->classify('collision')['type'], 'Authoritative collision is not concealed by override');
identity_assert('201', $service->parseChannel('PJSIP/201-00001234'), 'Actual channel yields endpoint identity');
identity_assert(null, $service->parseChannel('999'), 'Dialled destination is not a channel identity');

$reset = new PjsipIdentityService([], [], []);
identity_assert('unknown', $reset->classify('custom-gateway')['type'], 'Reset returns manual identity to automatic unknown');

$malformed = new PjsipIdentityService([], [], ['ok' => 'trunk', "bad\nkey" => 'extension', 'nested' => ['trunk'], 'bad-type' => 'device']);
identity_assert(['ok' => 'trunk'], $malformed->overrides(), 'Malformed stored overrides are repaired');
$rejected = false;
try { $service->validateOverrideMap(['nested' => ['trunk']]); } catch (InvalidArgumentException $exception) { $rejected = true; }
identity_assert(true, $rejected, 'Malformed explicit save is rejected');

if (!interface_exists('BMO')) {
	interface BMO {}
}
require_once __DIR__ . '/../Concurrencycount.class.php';
class IdentityPipelineConcurrencycount extends \FreePBX\modules\Concurrencycount { public function __construct() {} }
$pipeline = new IdentityPipelineConcurrencycount();
$method = new ReflectionMethod($pipeline, 'classifyPerNameRows');
$method->setAccessible(true);
foreach (['999', '911', '111'] as $dialled) {
	$cdr = [[
		'calldate' => '2026-08-27 10:00:00', 'duration' => 60,
		'channel' => 'PJSIP/201-00001234', 'dstchannel' => 'PJSIP/MYTRUNK-00001235', 'dst' => $dialled,
	]];
	$result = $method->invokeArgs($pipeline, [$cdr, 'extension', $service]);
	identity_assert('201', $result['rows'][0]['identity'], 'Configured source extension remains countable when dialling ' . $dialled);
	identity_assert([], $result['anomalies'], 'Dialled destination ' . $dialled . ' is metadata, not an endpoint anomaly');
}
$endpoint999 = $method->invokeArgs($pipeline, [[[
	'calldate' => '2026-08-27 10:00:00', 'duration' => 60,
	'channel' => 'PJSIP/201-00001234', 'dstchannel' => 'PJSIP/999-00001236', 'dst' => '999',
]], 'extension', $service]);
identity_assert('999', $endpoint999['rows'][0]['identity'], 'An actual configured PJSIP/999 endpoint is an extension and destination leg is preferred');

echo "PJSIP identity service tests passed\n";
