<?php
if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Services/PjsipIdentityService.php';
require_once __DIR__ . '/../Concurrencycount.class.php';
function expected_traffic_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
class DemoExpectedConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public $testTrunks = ['carrier'];
	public function __construct() {}
	public function getTrunks(): array { return $this->testTrunks; }
}
function demo_expected_row(string $direction, string $disposition = 'ANSWERED'): array {
	$base = ['calldate'=>'2026-01-01 10:00:00','duration'=>60,'disposition'=>$disposition,'src'=>'','dst'=>'','channel'=>'','dstchannel'=>''];
	if ($direction === 'inbound') return array_merge($base, ['src'=>'442079460000','dst'=>'202','channel'=>'PJSIP/carrier-aabbccdd','dstchannel'=>'PJSIP/202-aabbccdd']);
	if ($direction === 'outbound') return array_merge($base, ['src'=>'303','dst'=>'02079460123','channel'=>'PJSIP/303-aabbccdd','dstchannel'=>'PJSIP/carrier-aabbccdd']);
	return array_merge($base, ['src'=>'202','dst'=>'303','channel'=>'PJSIP/202-aabbccdd','dstchannel'=>'PJSIP/303-aabbccdd']);
}
$cc = new DemoExpectedConcurrencycount();
$answered = [demo_expected_row('inbound'), demo_expected_row('outbound'), demo_expected_row('internal')];
$ignored = [];
foreach (['NO ANSWER','BUSY','FAILED'] as $index => $disposition) $ignored[] = demo_expected_row(['inbound','outbound','internal'][$index], $disposition);
$perName = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'expectedDemoPerName'); $perName->setAccessible(true);
$extension = $perName->invoke($cc, array_merge($answered, $ignored), 'extension', ['carrier'], ['202','303']);
$trunk = $perName->invoke($cc, array_merge($answered, $ignored), 'trunk', ['carrier'], ['202','303']);
$groupMethod = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'expectedDemoGroup'); $groupMethod->setAccessible(true);
$group = $groupMethod->invoke($cc, array_merge($answered, $ignored), ['carrier'], ['202','303']);
expected_traffic_assert($trunk['global_max'] === 2 && $trunk['per_name']['carrier'] === 2, 'Trunk expected concurrency must follow actual inbound and outbound trunk legs');
expected_traffic_assert($extension['global_max'] === 2 && $extension['per_name']['202'] === 1 && $extension['per_name']['303'] === 2, 'Extension expected concurrency must prefer the destination extension and count at most one extension per CDR');
expected_traffic_assert($group['max_concurrency'] === 4, 'Group expected concurrency must count one inbound leg, one outbound leg, and two internal extension legs');
$onlyIgnoredTrunk = $perName->invoke($cc, $ignored, 'trunk', ['carrier'], ['202','303']);
$onlyIgnoredExtension = $perName->invoke($cc, $ignored, 'extension', ['carrier'], ['202','303']);
$onlyIgnoredGroup = $groupMethod->invoke($cc, $ignored, ['carrier'], ['202','303']);
expected_traffic_assert($onlyIgnoredTrunk['global_max'] === 0 && $onlyIgnoredExtension['global_max'] === 0 && $onlyIgnoredGroup['max_concurrency'] === 0, 'NO ANSWER, BUSY and FAILED rows must never contribute');
$touchA=demo_expected_row('inbound');$touchA['duration']=60;$touchB=demo_expected_row('outbound');$touchB['calldate']='2026-01-01 10:01:00';$touchB['duration']=1;
expected_traffic_assert($groupMethod->invoke($cc,[$touchA,$touchB],['carrier'],['202','303'])['max_concurrency']===2,'calldate through calldate plus duration must be inclusive at touching boundaries');
$long=demo_expected_row('inbound');$long['duration']=90000;$longResult=$groupMethod->invoke($cc,[$long],['carrier'],['202','303']);
expected_traffic_assert($longResult['peak_ranges'][0]['to']==='2026-01-02 10:00:00','Independent Group expectation must retain the 24-hour per-CDR cap');

$cc->testTrunks=['7301'];
$identity = new \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService(['7301'=>['channelid'=>'7301','name'=>'Numeric trunk']], ['7301'=>['id'=>'7301'],'8402'=>['id'=>'8402'],'9503'=>['id'=>'9503']], []);
$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, 'pjsipIdentityService'); $property->setAccessible(true); $property->setValue($cc, $identity);
$inventoryMethod = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'demoEndpointInventory'); $inventoryMethod->setAccessible(true);
$inventory = $inventoryMethod->invoke($cc);
$operationMethod = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'identityForOperation'); $operationMethod->setAccessible(true);
$isolated = $operationMethod->invoke($cc, 'CCDEMO1234abcd');
expected_traffic_assert($inventory['extensions'] === ['8402','9503'], 'Configured trunk identity must take precedence over numeric extension heuristics');
expected_traffic_assert(array_map('strval', array_keys($isolated->configuredDevices())) === $inventory['extensions'], 'Generator inventory and isolated Demo classifier must share the same extensions');
expected_traffic_assert(array_map('strval',array_keys($isolated->configuredTrunks())) === $inventory['trunks'], 'Generator inventory and isolated Demo classifier must share the same trunks');
expected_traffic_assert($isolated->classify('7301')['type']==='trunk','The isolated Demo classifier must retain a configured numeric PJSIP trunk as a trunk');
$orderedIdentity = new \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService(['7301'=>['channelid'=>'7301','name'=>'Numeric trunk']], ['8402'=>['id'=>'8402','name'=>'Alice'],'8503'=>['id'=>'8503','name'=>'Bob'],'9504'=>['id'=>'9504','name'=>'Carol']], []);
$reversedIdentity = new \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService(['7301'=>['channelid'=>'7301','name'=>'Numeric trunk']], ['9504'=>['id'=>'9504','name'=>'Carol'],'8503'=>['id'=>'8503','name'=>'Bob'],'8402'=>['id'=>'8402','name'=>'Alice']], []);
$property->setValue($cc, $orderedIdentity); $orderedInventory = $inventoryMethod->invoke($cc);
$property->setValue($cc, $reversedIdentity); $reversedInventory = $inventoryMethod->invoke($cc);
expected_traffic_assert($orderedInventory['extensions'] === $reversedInventory['extensions'] && $orderedInventory['extension_names'] === $reversedInventory['extension_names'], 'Equivalent configured Extension inventories must produce byte-identical canonical adapter inputs regardless of insertion order');
$adapter = new \FreePBX\modules\Concurrencycount\Services\CdrgenAdapter();
$identityScenario = ['profile'=>'light','rows'=>1000,'start'=>'2001-01-01 00:00:00','end'=>'2001-01-02 00:00:00','identity'=>'00112233445566778899aabbccddeeff:1','accountcode'=>'CCDEMO1234abcd','extensions'=>$orderedInventory['extensions'],'extension_names'=>$orderedInventory['extension_names'],'trunks'=>$orderedInventory['generator_trunks']];
$orderedDataset = $adapter->generate($identityScenario); $identityScenario['extensions']=$reversedInventory['extensions']; $identityScenario['extension_names']=$reversedInventory['extension_names']; $identityScenario['trunks']=$reversedInventory['generator_trunks']; $reversedDataset=$adapter->generate($identityScenario);
expected_traffic_assert($orderedDataset['metadata']['dataset_identity'] === $reversedDataset['metadata']['dataset_identity'], 'Canonical equivalent PBX inventories must retain the same authoritative CDRgen dataset identity');
unset($orderedDataset, $reversedDataset);
$cc->testTrunks=['2001'];
$collisionIdentity = new \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService(['2001'=>['channelid'=>'2001','name'=>'Numeric trunk']], ['2001'=>['id'=>'2001'],'8402'=>['id'=>'8402']], []);
$property->setValue($cc, $collisionIdentity);
$collisionInventory = $inventoryMethod->invoke($cc);
expected_traffic_assert(in_array('2001', $collisionInventory['trunks'], true) && !in_array('2001', $collisionInventory['extensions'], true), 'A configured numeric trunk must never be reintroduced by the fallback Extension inventory');
$numericInbound = array_merge(demo_expected_row('inbound'), ['channel'=>'PJSIP/7301-aabbccdd','dstchannel'=>'PJSIP/8402-aabbccdd','dst'=>'8402']);
$numericOutbound = array_merge(demo_expected_row('outbound'), ['channel'=>'PJSIP/8402-aabbccdd','dstchannel'=>'PJSIP/7301-aabbccdd','src'=>'8402']);
$numericInternal = array_merge(demo_expected_row('internal'), ['channel'=>'PJSIP/8402-aabbccdd','dstchannel'=>'PJSIP/8503-aabbccdd','src'=>'8402','dst'=>'8503']);
$numericRows = [$numericInbound, $numericOutbound, $numericInternal];
$numericTrunk = $perName->invoke($cc, $numericRows, 'trunk', ['7301'], ['8402','8503']);
$numericExtension = $perName->invoke($cc, $numericRows, 'extension', ['7301'], ['8402','8503']);
$numericGroup = $groupMethod->invoke($cc, $numericRows, ['7301'], ['8402','8503']);
expected_traffic_assert($numericTrunk['per_name']['7301'] === 2 && $numericTrunk['global_max'] === 2, 'Configured numeric trunks must count identically to named configured trunks');
expected_traffic_assert(!isset($numericExtension['per_name']['7301']) && $numericExtension['per_name']['8402'] === 2 && $numericExtension['per_name']['8503'] === 1, 'A numeric configured trunk must never become an expected Extension identity');
expected_traffic_assert($numericGroup['max_concurrency'] === 4, 'Numeric-trunk inbound/outbound calls must count one extension leg and internal calls two Group legs');

$prefixExtensions = ['101', '9503'];
$inboundTo101 = array_merge(demo_expected_row('inbound'), ['channel'=>'PJSIP/7301-aabbccdd','dstchannel'=>'PJSIP/101-aabbccdd','dst'=>'101']);
$internalPrefixes = array_merge(demo_expected_row('internal'), ['channel'=>'PJSIP/101-aabbccdd','dstchannel'=>'PJSIP/9503-aabbccdd','src'=>'101','dst'=>'9503']);
$outboundPrefix = array_merge(demo_expected_row('outbound'), ['channel'=>'PJSIP/101-aabbccdd','dstchannel'=>'PJSIP/7301-aabbccdd','src'=>'101','dst'=>'912345678']);
$prefixExtensionResult = $perName->invoke($cc, [$inboundTo101, $internalPrefixes, $outboundPrefix], 'extension', ['7301'], $prefixExtensions);
$prefixGroupResult = $groupMethod->invoke($cc, [$inboundTo101, $internalPrefixes, $outboundPrefix], ['7301'], $prefixExtensions);
expected_traffic_assert($prefixExtensionResult['per_name']['101'] === 2, 'Configured extension 101 must be counted inbound and outbound regardless of numeric prefix');
expected_traffic_assert($prefixExtensionResult['per_name']['9503'] === 1, 'Configured extension 9503 must be counted as the destination of an internal call');
expected_traffic_assert(!isset($prefixExtensionResult['per_name']['7301']), 'Configured numeric trunk 7301 must never become an expected Extension');
expected_traffic_assert($prefixGroupResult['max_concurrency'] === 4, 'Inbound and outbound external calls count one Group extension leg while internal 101 to 9503 counts two');
$outboundOnly = $perName->invoke($cc, [$outboundPrefix], 'extension', ['7301'], $prefixExtensions);
expected_traffic_assert($outboundOnly['per_name']['101'] === 1, 'Observable extension-to-trunk topology remains outbound even when the external dialled destination begins with 9');
$unknownNumeric = array_merge(demo_expected_row('internal'), ['channel'=>'PJSIP/1999-aabbccdd','dstchannel'=>'PJSIP/9999-aabbccdd','src'=>'1999','dst'=>'9999']);
expected_traffic_assert($perName->invoke($cc, [$unknownNumeric], 'extension', ['7301'], $prefixExtensions)['global_max'] === 0, 'Unknown numeric endpoints are not promoted to extensions by their shape');
expected_traffic_assert($groupMethod->invoke($cc, [$unknownNumeric], ['7301'], $prefixExtensions)['max_concurrency'] === 0, 'Unknown numeric endpoints do not contribute Group extension legs');
$csvMethod = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'demoCdrRowsToCsv'); $csvMethod->setAccessible(true);
$authoritativeIdentity = str_repeat('ab', 32);
$csvRow = array_merge($numericInbound, ['accountcode'=>'CCDEMOCSV','uniqueid'=>'demo-1','linkedid'=>'demo-1']);
$csv = $csvMethod->invoke($cc, [$csvRow], 'trunk', 'light', substr($authoritativeIdentity, 0, 16), $authoritativeIdentity, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
expected_traffic_assert(strpos($csv, "Scenario," . substr($authoritativeIdentity, 0, 16)) !== false && strpos($csv, '"Dataset identity",' . $authoritativeIdentity) !== false, 'Demo CDR CSV metadata must carry the short and complete authoritative CDRgen dataset identity');
echo "Demo expected traffic tests passed\n";
