<?php

require_once __DIR__ . '/../Services/LiveSnapshotService.php';
require_once __DIR__ . '/../Services/ThresholdService.php';
require_once __DIR__ . '/../Services/HistoricalGraphService.php';

use FreePBX\modules\Concurrencycount\Services\LiveSnapshotService;
use FreePBX\modules\Concurrencycount\Services\ThresholdService;
use FreePBX\modules\Concurrencycount\Services\HistoricalGraphService;

function live_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function live_channel(string $name, string $context, string $duration = '00:00:10'): array {
	return [
		'Channel' => $name,
		'ChannelStateDesc' => 'Up',
		'CallerIDNum' => '203',
		'CallerIDName' => 'Kieran',
		'ConnectedLineNum' => '02071234567',
		'ConnectedLineName' => '',
		'Context' => $context,
		'Exten' => '02071234567',
		'Application' => 'Dial',
		'ApplicationData' => '',
		'Duration' => $duration,
		'BridgeId' => 'bridge-1',
		'Uniqueid' => 'u-' . $name,
		'Linkedid' => 'l-' . $name,
	];
}

$live = new LiveSnapshotService();
$snapshot = $live->analyse([
	live_channel('PJSIP/203-a1b2c3', 'from-internal'),
	live_channel('PJSIP/204-b1c2d3', 'from-internal'),
	live_channel('PJSIP/gamma-c1d2e3', 'from-trunk-gamma'),
	live_channel('PJSIP/gamma-d1e2f3', 'from-internal'),
	live_channel('PJSIP/gamma-backup-e1f2a3', 'custom-context'),
	live_channel('Local/203@from-internal-0001;1', 'from-internal'),
	live_channel('PJSIP/unconfigured-f1a2b3', 'from-trunk'),
], ['gamma', 'gamma-backup'], [
	'overall' => ['enabled' => true, 'threshold' => 2],
	'trunks' => [
		'gamma' => ['enabled' => true, 'threshold' => 3],
		'gamma-backup' => ['enabled' => true, 'threshold' => 1],
	],
], 1787730000);
live_assert_same(2, $snapshot['overall']['current'], 'Overall counts numeric PJSIP channels only');
live_assert_same('exceeded', $snapshot['overall']['status'], 'Overall threshold uses greater-than-or-equal');
live_assert_same(2, $snapshot['trunks']['gamma']['current'], 'Exact trunk count');
live_assert_same(1, $snapshot['trunks']['gamma']['direction_counts']['inbound'], 'Inbound trunk direction');
live_assert_same(1, $snapshot['trunks']['gamma']['direction_counts']['outbound'], 'Outbound trunk direction');
live_assert_same(1, $snapshot['trunks']['gamma-backup']['current'], 'Similarly named trunk isolated');
live_assert_same(1, $snapshot['trunks']['gamma-backup']['direction_counts']['unknown'], 'Unknown trunk direction');
live_assert_same('exceeded', $snapshot['trunks']['gamma-backup']['status'], 'Per-trunk threshold');
live_assert_same(10, $snapshot['overall']['calls'][0]['duration_seconds'], 'AMI duration parsed');
live_assert_same('unavailable', $live->unavailable('AMI unavailable', 1787730000)['overall']['status'], 'Unavailable snapshot status');

$thresholds = new ThresholdService();
$defaults = $thresholds->defaults();
live_assert_same(5, $defaults['refresh_interval'], 'Default browser refresh interval');
live_assert_same(false, $defaults['alerts_enabled'], 'Alerts disabled by default');
live_assert_same(true, $defaults['recovery_enabled'], 'Recovery enabled by default');
$config = $thresholds->normalise([
	'refresh_interval' => 1,
	'alerts_enabled' => 'on',
	'recovery_enabled' => 'yes',
	'alert_email' => 'admin@example.com',
	'overall' => ['enabled' => true, 'threshold' => 2, 'alert_enabled' => true],
	'trunks' => [
		'gamma' => ['enabled' => true, 'threshold' => 3, 'alert_enabled' => true],
		'gamma-backup' => ['enabled' => true, 'threshold' => 0, 'alert_enabled' => true],
	],
], ['gamma', 'gamma-backup']);
live_assert_same(1, $config['refresh_interval'], 'One-second browser refresh accepted');
live_assert_same(false, $config['trunks']['gamma-backup']['enabled'], 'Threshold zero explicitly disables visual threshold');
$reconciled = $thresholds->reconcileStored([
	'alerts_enabled' => true,
	'overall' => ['enabled' => true, 'threshold' => 8, 'alert_enabled' => true],
	'trunks' => ['removed-trunk' => ['enabled' => true, 'threshold' => 5, 'alert_enabled' => true]],
], ['gamma']);
live_assert_same(false, isset($reconciled['trunks']['removed-trunk']), 'Removed persisted trunk is pruned without disabling settings');
live_assert_same(8, $reconciled['overall']['threshold'], 'Overall settings survive stale trunk reconciliation');
$unknownRejected = false;
try { $thresholds->normalise(['trunks' => ['removed-trunk' => ['threshold' => 5]]], ['gamma']); } catch (InvalidArgumentException $exception) { $unknownRejected = true; }
live_assert_same(true, $unknownRejected, 'New GUI/CLI writes still reject unknown trunks');

$state = [];
$first = $thresholds->evaluate('overall', 2, $config['overall'], $state, true, true, 1000);
live_assert_same('alert', $first['event']['type'], 'Threshold crossing sends alert');
live_assert_same('above', $first['state']['status'], 'Threshold crossing enters above state');
$repeat = $thresholds->evaluate('overall', 4, $config['overall'], $first['state'], true, true, 1010);
live_assert_same(null, $repeat['event'], 'Sustained threshold does not repeat alert');
live_assert_same(4, $repeat['state']['peak'], 'Sustained threshold tracks peak');
$recovery = $thresholds->evaluate('overall', 1, $config['overall'], $repeat['state'], true, true, 1060);
live_assert_same('recovery', $recovery['event']['type'], 'Below threshold sends optional recovery');
live_assert_same(4, $recovery['event']['peak'], 'Recovery includes alert peak');
live_assert_same(60, $recovery['event']['timestamp'] - $recovery['event']['since'], 'Recovery includes duration');
live_assert_same('normal', $recovery['state']['status'], 'Recovery resets state');
$alertMessage = $thresholds->buildNotification(array_merge($first['event'], ['direction_counts' => ['inbound' => 1, 'outbound' => 1, 'unknown' => 0]]), 'MY-PBX');
live_assert_same(true, strpos($alertMessage['subject'], 'threshold exceeded on MY-PBX') !== false, 'Alert subject includes system identifier');
live_assert_same(true, strpos($alertMessage['body'], 'Threshold: 2') !== false && strpos($alertMessage['body'], 'Inbound: 1') !== false, 'Alert body includes threshold and direction split');
$recoveryMessage = $thresholds->buildNotification($recovery['event'], 'MY-PBX');
live_assert_same(true, strpos($recoveryMessage['body'], 'Duration above threshold: 60 seconds') !== false, 'Recovery body includes duration');
$disabled = $thresholds->evaluate('overall', 9, $config['overall'], [], false, true, 1100);
live_assert_same(null, $disabled['event'], 'Master alert disable suppresses notification');
$perScopeDisabled = $thresholds->evaluate('gamma', 9, ['enabled' => true, 'threshold' => 3, 'alert_enabled' => false], [], true, true, 1100);
live_assert_same(null, $perScopeDisabled['event'], 'Per-scope alert disable suppresses notification');

foreach ([2, 3, 4, 20, 59, 61] as $invalidRefresh) {
	$thrown = false;
	try { $thresholds->normalise(['refresh_interval' => $invalidRefresh], []); } catch (InvalidArgumentException $exception) { $thrown = true; }
	live_assert_same(true, $thrown, 'Invalid refresh interval rejected: ' . $invalidRefresh);
}
foreach ([-1, 10001] as $invalidThreshold) {
	$thrown = false;
	try { $thresholds->normalise(['overall' => ['threshold' => $invalidThreshold]], []); } catch (InvalidArgumentException $exception) { $thrown = true; }
	live_assert_same(true, $thrown, 'Invalid threshold rejected: ' . $invalidThreshold);
}

$graphs = new HistoricalGraphService();
$trunkGraph = $graphs->trunkSeries([
	['calldate' => '2026-08-26 10:00:00', 'duration' => 10, 'chan' => 'PJSIP/gamma-aaaaaa'],
	['calldate' => '2026-08-26 10:00:05', 'duration' => 5, 'chan' => 'PJSIP/gamma-bbbbbb'],
	['calldate' => '2026-08-26 10:00:00', 'duration' => 20, 'chan' => 'PJSIP/gamma-backup-cccccc'],
], ['gamma', 'gamma-backup'], '2026-08-26 10:00:00', '2026-08-26 10:01:00');
live_assert_same(2, $trunkGraph['series']['gamma']['exact_peak'], 'Historical trunk exact peak');
live_assert_same('exact_events', $trunkGraph['series']['gamma']['display_resolution'], 'Short historical graph uses exact events');
$overallGraph = $graphs->overallSeries([
	['calldate' => '2026-08-26 10:00:00', 'duration' => 10, 'channel' => 'PJSIP/201-aaaaaa', 'dstchannel' => 'PJSIP/202-bbbbbb'],
	['calldate' => '2026-08-26 10:00:05', 'duration' => 5, 'channel' => 'PJSIP/gamma-cccccc', 'dstchannel' => 'PJSIP/203-dddddd'],
], '2026-08-26 10:00:00', '2026-08-26 10:01:00');
live_assert_same(3, $overallGraph['series']['overall']['exact_peak'], 'Historical overall counts both numeric PJSIP legs');

$manyRows = [];
for ($index = 0; $index < 1300; $index++) {
	$manyRows[] = [
		'calldate' => date('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00') + ($index * 20)),
		'duration' => 10,
		'chan' => 'PJSIP/gamma-' . sprintf('%06x', $index + 1),
	];
}
$aggregated = $graphs->trunkSeries($manyRows, ['gamma'], '2026-01-01 00:00:00', '2026-01-02 00:00:00');
live_assert_same('bucket_maxima', $aggregated['series']['gamma']['display_resolution'], 'Large historical graph aggregates display points');
live_assert_same(1, $aggregated['series']['gamma']['exact_peak'], 'Display aggregation preserves exact peak');
live_assert_same(true, count($aggregated['series']['gamma']['points']) <= HistoricalGraphService::MAX_DISPLAY_POINTS, 'Display points are bounded');

echo "Live service tests passed\n";
