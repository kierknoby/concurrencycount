<?php

require_once __DIR__ . '/../Services/LiveSnapshotService.php';
require_once __DIR__ . '/../Services/ThresholdService.php';
require_once __DIR__ . '/../Services/HistoricalGraphService.php';
require_once __DIR__ . '/../Services/PjsipIdentityService.php';

use FreePBX\modules\Concurrencycount\Services\LiveSnapshotService;
use FreePBX\modules\Concurrencycount\Services\ThresholdService;
use FreePBX\modules\Concurrencycount\Services\HistoricalGraphService;
use FreePBX\modules\Concurrencycount\Services\PjsipIdentityService;

function live_identity(array $trunks, array $devices = [], array $overrides = []): PjsipIdentityService {
	$trunkMap = [];
	foreach ($trunks as $id) $trunkMap[$id] = ['channelid' => $id];
	$deviceMap = [];
	foreach ($devices as $id) $deviceMap[$id] = ['id' => $id];
	return new PjsipIdentityService($trunkMap, $deviceMap, $overrides);
}

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
], live_identity(['gamma', 'gamma-backup'], ['203', '204']), [
	'overall' => ['enabled' => true, 'threshold' => 2],
	'trunks' => [
		'gamma' => ['enabled' => true, 'threshold' => 3],
		'gamma-backup' => ['enabled' => true, 'threshold' => 1],
	],
], 1787730000);
live_assert_same(5, $snapshot['overall']['current'], 'Overall Live Concurrency counts configured trunk legs plus numeric extension legs');
live_assert_same('exceeded', $snapshot['overall']['status'], 'Overall threshold uses greater-than-or-equal');
live_assert_same(2, $snapshot['trunks']['gamma']['current'], 'Exact trunk count');
live_assert_same(1, $snapshot['trunks']['gamma']['direction_counts']['inbound'], 'Inbound trunk direction');
live_assert_same(1, $snapshot['trunks']['gamma']['direction_counts']['outbound'], 'Outbound trunk direction');
live_assert_same(1, $snapshot['trunks']['gamma-backup']['current'], 'Similarly named trunk isolated');
live_assert_same(1, $snapshot['trunks']['gamma-backup']['direction_counts']['unknown'], 'Unknown trunk direction');
live_assert_same('exceeded', $snapshot['trunks']['gamma-backup']['status'], 'Per-trunk threshold');
live_assert_same(10, $snapshot['overall']['calls'][0]['duration_seconds'], 'AMI duration parsed');
live_assert_same('unavailable', $live->unavailable('AMI unavailable', 1787730000)['overall']['status'], 'Unavailable snapshot status');

// Example A: an attributable configured PJSIP trunk leg alone must contribute to Overall Live Concurrency.
$trunkOnly = $live->analyse([
	live_channel('PJSIP/MAGRATHEA-IN-2-00000123', 'from-trunk'),
], live_identity(['MAGRATHEA-IN-2']), [], 1787730100);
live_assert_same(1, $trunkOnly['overall']['current'], 'A lone monitored trunk leg must count toward Overall Live Concurrency');
live_assert_same(1, $trunkOnly['trunks']['MAGRATHEA-IN-2']['current'], 'The same leg is still reported on its trunk card');

// Example B: inbound call with a trunk leg and an answered extension leg is two active PJSIP legs.
$inbound = $live->analyse([
	live_channel('PJSIP/gamma-10000001', 'from-trunk-gamma'),
	live_channel('PJSIP/203-10000002', 'from-internal'),
], live_identity(['gamma'], ['203']), [], 1787730200);
live_assert_same(2, $inbound['overall']['current'], 'Inbound trunk leg + extension leg counts as two active PJSIP legs');

// Example D: outbound call with an extension leg and a trunk leg is also two active PJSIP legs.
$outbound = $live->analyse([
	live_channel('PJSIP/204-10000003', 'from-internal'),
	live_channel('PJSIP/gamma-10000004', 'macro-dialout-trunk'),
], live_identity(['gamma'], ['204']), [], 1787730300);
live_assert_same(2, $outbound['overall']['current'], 'Outbound extension leg + trunk leg counts as two active PJSIP legs');

// Example C: an internal call between two extensions is two extension legs (unchanged historical-style leg counting).
$internal = $live->analyse([
	live_channel('PJSIP/201-10000005', 'from-internal'),
	live_channel('PJSIP/202-10000006', 'from-internal'),
], live_identity([], ['201', '202']), [], 1787730400);
live_assert_same(2, $internal['overall']['current'], 'Internal extension-to-extension call counts as two legs');

// Example E: Local/helper channels alongside a trunk leg must not inflate Overall.
$withHelpers = $live->analyse([
	live_channel('PJSIP/gamma-10000007', 'from-trunk-gamma'),
	live_channel('Local/203@from-internal-0002;1', 'from-internal'),
	live_channel('Local/203@from-internal-0002;2', 'from-internal'),
], live_identity(['gamma']), [], 1787730500);
live_assert_same(1, $withHelpers['overall']['current'], 'Local/helper channels do not inflate Overall Live Concurrency');

// A custom PJSIP endpoint which is neither a configured trunk nor a numeric extension is excluded entirely.
$customEndpoint = $live->analyse([
	live_channel('PJSIP/unrelated-endpoint-10000008', 'from-trunk'),
], live_identity(['gamma']), [], 1787730600);
live_assert_same(0, $customEndpoint['overall']['current'], 'Unrelated custom PJSIP endpoint is excluded from Overall Live Concurrency');
live_assert_same(0, $customEndpoint['trunks']['gamma']['current'], 'Unrelated custom PJSIP endpoint is not attributed to an unrelated trunk');
live_assert_same('unknown', $customEndpoint['identity_anomalies'][0]['type'], 'Unknown live endpoint is surfaced non-blockingly');

$authoritativeShapes = $live->analyse([
	live_channel('PJSIP/123456-10000009', 'from-trunk'),
	live_channel('PJSIP/warehouse-phone-1000000a', 'from-internal'),
	live_channel('PJSIP/custom-gateway-1000000b', 'from-trunk'),
	live_channel('PJSIP/ignored-peer-1000000c', 'from-trunk'),
], live_identity(['123456'], ['warehouse-phone'], ['custom-gateway' => 'trunk', 'ignored-peer' => 'ignore']), [], 1787730650);
live_assert_same(3, $authoritativeShapes['overall']['current'], 'Numeric trunk, alphanumeric device and manual trunk are attributable');
live_assert_same(1, $authoritativeShapes['trunks']['123456']['current'], 'Numeric authoritative trunk has a trunk result');
live_assert_same(1, $authoritativeShapes['trunks']['custom-gateway']['current'], 'Manual custom trunk has a trunk result without fabricating FreePBX metadata');
live_assert_same([], $authoritativeShapes['identity_anomalies'], 'Ignored endpoint is excluded without repeated anomaly');

$thresholds = new ThresholdService();
$defaults = $thresholds->defaults();
live_assert_same(5, $defaults['refresh_interval'], 'Default browser refresh interval');
live_assert_same(false, $defaults['alerts_enabled'], 'Alerts disabled by default');
live_assert_same(true, $defaults['recovery_enabled'], 'Recovery enabled by default');
live_assert_same([], $defaults['hidden_trunks'], 'Hidden trunks default empty');
live_assert_same([], $defaults['trunk_order'], 'Trunk order defaults empty before inventory reconciliation');
live_assert_same([], $defaults['live_wall_featured_trunks'], 'Live Wall featured trunks default empty');
$config = $thresholds->normalise([
	'refresh_interval' => 1,
	'alerts_enabled' => 'on',
	'recovery_enabled' => 'yes',
	'alert_email' => 'admin@example.com',
	'overall' => ['enabled' => true, 'threshold' => 2, 'alert_enabled' => true],
	'trunks' => [
		'gamma' => ['enabled' => true, 'threshold' => 3, 'alert_enabled' => true, 'monitored' => false],
		'gamma-backup' => ['enabled' => true, 'threshold' => 0, 'alert_enabled' => true],
	],
], ['gamma', 'gamma-backup']);
live_assert_same(1, $config['refresh_interval'], 'One-second browser refresh accepted');
live_assert_same(false, $config['trunks']['gamma-backup']['enabled'], 'Threshold zero explicitly disables visual threshold');
live_assert_same(false, $config['trunks']['gamma']['monitored'], 'Monitoring state is independent of threshold enablement');
live_assert_same(true, $config['trunks']['gamma-backup']['monitored'], 'Legacy/new trunk monitoring defaults active');
live_assert_same(['gamma', 'gamma-backup'], $config['trunk_order'], 'New trunks append predictably to an empty saved order');
live_assert_same([], $config['live_wall_featured_trunks'], 'New trunks do not become featured automatically');
$featuredConfig = $thresholds->normalise([
	'live_wall_featured_trunks' => ['gamma-backup', 'gamma', 'gamma-backup'],
	'trunks' => [
		'gamma' => ['enabled' => true, 'threshold' => 3, 'alert_enabled' => true, 'monitored' => false],
		'gamma-backup' => ['enabled' => true, 'threshold' => 7, 'alert_enabled' => true, 'monitored' => true],
	],
], ['gamma', 'gamma-backup']);
live_assert_same(['gamma-backup', 'gamma'], $featuredConfig['live_wall_featured_trunks'], 'Featured trunk order persists and duplicate identifiers are removed');
live_assert_same(false, $featuredConfig['trunks']['gamma']['monitored'], 'Featured selection does not alter monitoring state');
live_assert_same(true, $featuredConfig['trunks']['gamma']['enabled'], 'Featured selection does not alter threshold enabled state');
live_assert_same(true, $featuredConfig['trunks']['gamma']['alert_enabled'], 'Featured selection does not alter alert enabled state');
$oneFeatured = $thresholds->normalise(['live_wall_featured_trunks' => ['gamma']], ['gamma']);
live_assert_same(['gamma'], $oneFeatured['live_wall_featured_trunks'], 'One featured trunk is accepted');
$threeFeatured = $thresholds->normalise(['live_wall_featured_trunks' => ['one', 'two', 'three']], []);
live_assert_same(['one', 'two', 'three'], $threeFeatured['live_wall_featured_trunks'], 'Three featured trunks are accepted in left-to-right order');
$tooManyFeaturedRejected = false;
try { $thresholds->normalise(['live_wall_featured_trunks' => ['one', 'two', 'three', 'four']], []); } catch (InvalidArgumentException $exception) { $tooManyFeaturedRejected = true; }
live_assert_same(true, $tooManyFeaturedRejected, 'Explicit featured trunk saves reject more than three identifiers');
$malformedFeaturedRejected = false;
try { $thresholds->normalise(['live_wall_featured_trunks' => 'gamma'], ['gamma']); } catch (InvalidArgumentException $exception) { $malformedFeaturedRejected = true; }
live_assert_same(true, $malformedFeaturedRejected, 'Malformed explicit featured trunk value is rejected');
$reconciled = $thresholds->reconcileStored([
	'alerts_enabled' => true,
	'hidden_trunks' => ['temporarily-absent', 'gamma', 'gamma'],
	'trunk_order' => ['temporarily-absent', 'gamma', 'gamma'],
	'live_wall_featured_trunks' => ['temporarily-absent', 'gamma', 'temporarily-absent'],
	'overall' => ['enabled' => true, 'threshold' => 8, 'alert_enabled' => true],
	'trunks' => ['removed-trunk' => ['enabled' => true, 'threshold' => 5, 'alert_enabled' => true]],
], ['gamma']);
live_assert_same(false, isset($reconciled['trunks']['removed-trunk']), 'Removed persisted trunk is pruned without disabling settings');
live_assert_same(8, $reconciled['overall']['threshold'], 'Overall settings survive stale trunk reconciliation');
live_assert_same(true, $reconciled['trunks']['gamma']['monitored'], 'Legacy settings without monitored reconcile active');
live_assert_same(['temporarily-absent', 'gamma'], $reconciled['hidden_trunks'], 'Preference reconciliation preserves stale identifiers and removes duplicates safely');
live_assert_same(['temporarily-absent', 'gamma'], $reconciled['trunk_order'], 'Saved order preserves stale logical positions without duplicate entries');
live_assert_same(['temporarily-absent', 'gamma'], $reconciled['live_wall_featured_trunks'], 'Valid stale featured channelids are retained in saved order');
$unknownRejected = false;
try { $thresholds->normalise(['trunks' => ['removed-trunk' => ['threshold' => 5]]], ['gamma']); } catch (InvalidArgumentException $exception) { $unknownRejected = true; }
live_assert_same(true, $unknownRejected, 'New GUI/CLI writes still reject unknown trunks');
$malformedPreferencesRejected = false;
try { $thresholds->normalise(['hidden_trunks' => 'gamma'], ['gamma']); } catch (InvalidArgumentException $exception) { $malformedPreferencesRejected = true; }
live_assert_same(true, $malformedPreferencesRejected, 'Malformed presentation preference lists are rejected');
$malformedMonitoringRejected = false;
try { $thresholds->normalise(['trunks' => ['gamma' => ['monitored' => 'perhaps']]], ['gamma']); } catch (InvalidArgumentException $exception) { $malformedMonitoringRejected = true; }
live_assert_same(true, $malformedMonitoringRejected, 'Malformed monitored state is rejected');
$malformedStored = $thresholds->reconcileStored(['hidden_trunks' => 'not-a-list', 'trunk_order' => [false, 'gamma'], 'live_wall_featured_trunks' => [false, 'one', 'two', 'three', 'four'], 'trunks' => ['gamma' => ['monitored' => 'perhaps']]], ['gamma']);
live_assert_same([], $malformedStored['hidden_trunks'], 'Malformed stored hidden preferences reconcile safely');
live_assert_same(['gamma'], $malformedStored['trunk_order'], 'Malformed stored order entries are skipped and current trunks remain ordered');
live_assert_same(true, $malformedStored['trunks']['gamma']['monitored'], 'Malformed stored monitoring state reconciles to active');
live_assert_same(['one', 'two', 'three'], $malformedStored['live_wall_featured_trunks'], 'Malformed/overflow stored featured state reconciles safely to three valid identifiers');

$presentationAndMonitoring = $live->analyse([
	live_channel('PJSIP/gamma-20000001', 'from-trunk-gamma'),
	live_channel('PJSIP/205-20000002', 'from-internal'),
], live_identity(['gamma'], ['205']), ['hidden_trunks' => ['gamma'], 'trunk_order' => ['gamma'], 'live_wall_featured_trunks' => ['gamma'], 'trunks' => ['gamma' => ['monitored' => false]]], 1787730700);
live_assert_same(2, $presentationAndMonitoring['overall']['current'], 'Hidden and monitoring-stopped trunk legs still contribute to Overall alongside numeric extension legs');
live_assert_same(1, $presentationAndMonitoring['trunks']['gamma']['current'], 'Presentation and monitoring preferences do not alter underlying trunk counts');

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
	['calldate' => '2026-08-26 10:00:00', 'duration' => 10, 'identity' => 'gamma'],
	['calldate' => '2026-08-26 10:00:05', 'duration' => 5, 'identity' => 'gamma'],
	['calldate' => '2026-08-26 10:00:00', 'duration' => 20, 'identity' => 'gamma-backup'],
], ['gamma', 'gamma-backup'], '2026-08-26 10:00:00', '2026-08-26 10:01:00');
live_assert_same(2, $trunkGraph['series']['gamma']['exact_peak'], 'Historical trunk exact peak');
live_assert_same('exact_events', $trunkGraph['series']['gamma']['display_resolution'], 'Short historical graph uses exact events');
$overallGraph = $graphs->overallSeries([
	['calldate' => '2026-08-26 10:00:00', 'duration' => 10, 'extension_legs' => 2],
	['calldate' => '2026-08-26 10:00:05', 'duration' => 5, 'extension_legs' => 1],
], '2026-08-26 10:00:00', '2026-08-26 10:01:00');
live_assert_same(3, $overallGraph['series']['overall']['exact_peak'], 'Historical overall counts both numeric PJSIP legs');

$boundaryGraph = $graphs->trunkSeries([
	['calldate' => '2026-08-26 09:59:50', 'duration' => 30, 'identity' => 'gamma'],
], ['gamma'], '2026-08-26 10:00:00', '2026-08-26 10:00:10');
live_assert_same(1, $boundaryGraph['series']['gamma']['exact_peak'], 'Interval spanning the entire graph range contributes at its boundaries');
live_assert_same(1, $boundaryGraph['series']['gamma']['points'][0]['value'], 'Graph derives non-zero state explicitly at range start');
$endBoundaryGraph = $graphs->trunkSeries([
	['calldate' => '2026-08-26 10:00:10', 'duration' => 1, 'identity' => 'gamma'],
], ['gamma'], '2026-08-26 10:00:00', '2026-08-26 10:00:10');
live_assert_same(1, $endBoundaryGraph['series']['gamma']['exact_peak'], 'An interval starting exactly at range end contributes at that inclusive second');
$adjacentBoundaryGraph = $graphs->trunkSeries([
	['calldate' => '2026-08-26 10:00:00', 'duration' => 10, 'identity' => 'gamma'],
	['calldate' => '2026-08-26 10:00:10', 'duration' => 5, 'identity' => 'gamma'],
], ['gamma'], '2026-08-26 10:00:00', '2026-08-26 10:00:20');
live_assert_same(2, $adjacentBoundaryGraph['series']['gamma']['exact_peak'], 'Adjacent calls overlap at their shared inclusive boundary second');

$manyRows = [];
for ($index = 0; $index < 1300; $index++) {
	$manyRows[] = [
		'calldate' => date('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00') + ($index * 20)),
		'duration' => 10,
		'identity' => 'gamma',
	];
}
$aggregated = $graphs->trunkSeries($manyRows, ['gamma'], '2026-01-01 00:00:00', '2026-01-02 00:00:00');
live_assert_same('bucket_maxima', $aggregated['series']['gamma']['display_resolution'], 'Large historical graph aggregates display points');
live_assert_same(1, $aggregated['series']['gamma']['exact_peak'], 'Display aggregation preserves exact peak');
live_assert_same(true, count($aggregated['series']['gamma']['points']) <= HistoricalGraphService::MAX_DISPLAY_POINTS, 'Display points are bounded');

echo "Live service tests passed\n";
