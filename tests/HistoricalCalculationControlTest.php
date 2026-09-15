<?php

require_once __DIR__ . '/../Services/SettingsRepository.php';
require_once __DIR__ . '/../Services/HistoricalCalculationControl.php';
require_once __DIR__ . '/../Services/HistoricalRuntimeEstimator.php';
require_once __DIR__ . '/../Services/HistoricalReportsService.php';

use FreePBX\modules\Concurrencycount\Services\SettingsRepository;
use FreePBX\modules\Concurrencycount\Services\HistoricalCalculationControl;
use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;
use FreePBX\modules\Concurrencycount\Services\HistoricalReportsService;

function control_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

class ControlDatabase {
	public $values = [];
	public function prepare($sql) { return new ControlStatement($this, $sql); }
}

class ControlStatement {
	private $db;
	private $sql;
	private $result = false;
	public function __construct(ControlDatabase $db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute(array $params) {
		if (strpos($this->sql, 'SELECT setting_key') === 0) {
			$prefix = rtrim((string)$params[':prefix'], '%');
			$this->result = array_values(array_filter(array_keys($this->db->values), function ($key) use ($prefix) { return strpos($key, $prefix) === 0; }));
		} elseif (strpos($this->sql, 'SELECT') === 0) {
			$key = $params[':key'];
			$this->result = isset($this->db->values[$key]) ? $this->db->values[$key] : false;
		} elseif (strpos($this->sql, 'INSERT') === 0) $this->db->values[$params[':key']] = $params[':value'];
		elseif (strpos($this->sql, 'DELETE') === 0) unset($this->db->values[$params[':key']]);
		return true;
	}
	public function fetchColumn() { return $this->result; }
	public function fetchAll($mode = null) { return is_array($this->result) ? $this->result : []; }
}

$database = new ControlDatabase();
$control = new HistoricalCalculationControl(new SettingsRepository($database));
$first = '00112233445566778899aabbccddeeff';
$second = 'ffeeddccbbaa99887766554433221100';
$control->begin($first, 1000);
$control->begin($second, 1000);
control_assert(!$control->isCancelled($first), 'Created calculation starts active');
$firstStatus = $control->status($first);
control_assert(is_array($firstStatus) && $firstStatus['status'] === 'active' && isset($firstStatus['started_at']), 'Active calculation exposes isolated telemetry state');
$control->updateTelemetry($first, 12.5, 40.25, true, 1001);
$firstStatus = $control->status($first);
control_assert($firstStatus['elapsed'] === 12.5 && $firstStatus['eta_reliable'] === true && $firstStatus['estimated_remaining'] === 40.25, 'Reliable estimator progress updates the matching telemetry record');
control_assert((float)$control->status($second)['elapsed'] === 0.0, 'Telemetry is isolated by calculation ID');
$control->updateTelemetry($second, 1.0, 0.25, true, 1001);
control_assert($control->status($second)['eta_reliable'] === true && $control->status($second)['estimated_remaining'] === 0.25, 'Positive sub-second ETA remains distinct from zero');
$control->updateTelemetry($second, 2.0, null, false, 1002);
control_assert($control->status($second)['eta_reliable'] === false && $control->status($second)['estimated_remaining'] === null, 'Unreliable estimator state is explicitly unavailable');
$flow = new HistoricalRuntimeEstimator(3600.0, 0.0, 0.0);
$flowAssessment = $flow->evaluate(999, 1000, 1.0);
$control->updateTelemetry($second, $flowAssessment['overall_elapsed'], $flowAssessment['estimated_remaining'], $flowAssessment['reliable'], 1002);
$flowStatus = $control->status($second);
control_assert($flowStatus['eta_reliable'] === false && $flowStatus['estimated_remaining'] === null, 'Five-minute confidence gate survives calculation-control telemetry');
control_assert($control->cancel($first, 1001), 'Cancellation can be recorded');
control_assert($control->isCancelled($first), 'Running calculation observes its cancellation record');
control_assert(!$control->isCancelled($second), 'Cancellation is isolated by calculation ID');
control_assert($control->cancel($first, 1002), 'Repeated cancellation is idempotent');
$control->finish($first);
control_assert(!$control->isCancelled($first), 'Stopped calculation cleans up control state');
control_assert($control->status($first) === null, 'Stopped calculation cleans up telemetry state');
$control->finish($second);
control_assert(!$control->isCancelled($second), 'Completed calculation cleans up control state');
$control->begin($first, 1003);
control_assert(!$control->isCancelled($first), 'New calculation attempt after cancellation is unaffected');
$control->finish($first);

$early = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
control_assert($control->cancel($early, 1004), 'Stop arriving before registration creates an idempotent cancellation tombstone');
$control->begin($early, 1004);
control_assert($control->isCancelled($early), 'Late calculation registration must not overwrite an earlier Stop signal');
$control->finish($early);

$guiEarly = 'cccccccccccccccccccccccccccccccc';
control_assert($control->cancelOwned($guiEarly, $owner ?? hash('sha256', 'owner-one'), 1005), 'Authenticated GUI Stop before registration creates an owned tombstone');
control_assert(!$control->admitGui($guiEarly, hash('sha256', 'owner-one'), 3600, 1005), 'Owned GUI tombstone prevents late registration');
$control->finish($guiEarly);

$owner = hash('sha256', 'owner-one');
$otherOwner = hash('sha256', 'owner-two');
$guiFirst = '11111111111111111111111111111111';
$guiSecond = '22222222222222222222222222222222';
control_assert($control->admitGui($guiFirst, $owner, 3600, 2000), 'First GUI calculation is admitted for its ownership scope');
control_assert(!$control->admitGui($guiFirst, $owner, 3600, 2000), 'Duplicate Run request with the same calculation ID is not admitted twice');
control_assert(!$control->admitGui($guiSecond, $owner, 3600, 2001), 'Second GUI calculation for the same owner is rejected before engine admission');
control_assert($control->admitGui($guiSecond, $otherOwner, 3600, 2001), 'A different authenticated GUI ownership scope is independent');
control_assert($control->heartbeat($guiFirst, $owner, 2005), 'Healthy owner heartbeat renews the exact calculation lease');
control_assert(!$control->heartbeat($guiFirst, $otherOwner, 2006), 'Another ownership scope cannot renew a GUI lease');
control_assert(!$control->heartbeat($guiSecond, $owner, 2006), 'A stale owner/calculation pairing cannot renew a newer run');
control_assert(!$control->shouldStop($guiFirst, 2024), 'Renewed GUI lease remains healthy before expiry');
control_assert($control->shouldStop($guiFirst, 2025), 'Missing heartbeat expires at the documented lease boundary');
control_assert(!$control->admitGui('33333333333333333333333333333333', $owner, 3600, 2025), 'Expired but not-yet-unwound GUI work still blocks replacement admission');
$control->finish($guiFirst);
control_assert($control->admitGui('33333333333333333333333333333333', $owner, 3600, 2026), 'Replacement is admitted only after abandoned work performs terminal cleanup');
$control->finish('33333333333333333333333333333333');
$control->cancel($guiSecond, 2002);
control_assert($control->shouldStop($guiSecond, 2002), 'GUI cancellation remains a cooperative checkpoint condition');
control_assert(!$control->admitGui('44444444444444444444444444444444', $otherOwner, 3600, 2002), 'Cancellation request alone does not permit backend overlap');
$control->finish($guiSecond);
control_assert($control->admitGui('44444444444444444444444444444444', $otherOwner, 3600, 2003), 'Cancelled calculation permits replacement after it unwinds');
$control->finish('44444444444444444444444444444444');

$continued = '55555555555555555555555555555555';
control_assert($control->admitGui($continued, $owner, 3600, 3000, 500.0), 'Initial GUI attempt receives one server-owned runtime origin');
control_assert($control->runtimeStartedAt($continued, $owner) === 500.0, 'Initial runtime origin is retained exactly');
$control->updateTelemetry($continued, 35.0, 7200.0, true, 3005);
control_assert($control->pauseForWarning($continued, $owner, 3010), 'Predictive warning preserves the registered calculation-control record');
control_assert($control->status($continued)['eta_reliable'] === false && $control->status($continued)['estimated_remaining'] === null && (float)$control->status($continued)['elapsed'] === 35.0, 'Restarted work may reset ETA independently without resetting elapsed/runtime state');
control_assert(!$control->resumeGui($continued, $otherOwner, 3015), 'Another authenticated ownership scope cannot inherit a warning deadline');
control_assert($control->heartbeat($continued, $owner, 3015), 'Warning interaction keeps the same short-lived ownership lease healthy');
control_assert($control->resumeGui($continued, $owner, 3025), 'Confirmed continuation resumes the same calculation ID');
control_assert($control->runtimeStartedAt($continued, $owner) === 500.0, 'Continue cannot replace or extend the original runtime origin');
$continuedEstimator = new HistoricalRuntimeEstimator(3600.0, $control->runtimeStartedAt($continued, $owner), 3025.0, true);
$continuedAssessment = $continuedEstimator->evaluate(100, 100000, 4100.1);
control_assert($continuedAssessment['abort'], 'Continued work hard-aborts at the original absolute deadline');
control_assert($control->cancel($continued, 3026), 'Stop remains tied to the continued calculation ID');
control_assert($control->shouldStop($continued, 3026), 'Continued attempt observes cooperative cancellation');
$control->finish($continued);
control_assert($control->status($continued) === null, 'Stop/terminal cleanup removes runtime-deadline and telemetry state');

$adjusted = '66666666666666666666666666666666';
control_assert($control->admitGui($adjusted, $owner, 3600, time(), 1000.0), 'Runtime-adjustment run admitted');
$before = $control->owned($adjusted, $owner);
$partialMinute = false; try { $control->decide($adjusted, $owner, 'allowance', 3630, 1300.0); } catch (InvalidArgumentException $e) { $partialMinute = true; }
control_assert($partialMinute && $control->owned($adjusted, $owner)['runtime_allowance_seconds'] === 3600, 'Non-minute runtime allowance is rejected atomically');
$wholeMinute = $control->decide($adjusted, $owner, 'allowance', 3660, 1300.0);
control_assert($wholeMinute['runtime_allowance_seconds'] === 3660 && $wholeMinute['runtime_started_at'] === $before['runtime_started_at'], 'A valid whole-minute increase is accepted without resetting runtime origin');
$after = $control->decide($adjusted, $owner, 'allowance', 7200, 1300.0);
control_assert($after['runtime_allowance_seconds'] === 7200 && $after['runtime_started_at'] === $before['runtime_started_at'], 'Temporary allowance increase preserves runtime origin');
$invalidAllowance = false; try { $control->decide($adjusted, $owner, 'allowance', 86401, 1300.0); } catch (InvalidArgumentException $e) { $invalidAllowance = true; }
control_assert($invalidAllowance && $control->owned($adjusted, $owner)['runtime_allowance_seconds'] === 7200, 'Invalid allowance is rejected atomically');
$control->workerDecision($adjusted, $owner, 'paused_impact');
$progressBefore = ['progress_percent' => 47.0]; $control->publish($adjusted, $progressBefore);
$reassessed = $control->decide($adjusted, $owner, 'reassess', null, 1400.0);
control_assert($reassessed['assessment_generation'] === 1 && (float)$reassessed['runtime_started_at'] === 1000.0 && (float)$control->status($adjusted)['progress_percent'] === 47.0, 'Reassess preserves identity, origin and progress while starting a fresh assessment generation');
$control->finish($adjusted);

$criticalRun = '77777777777777777777777777777777';
control_assert($control->admitGui($criticalRun, $owner, 3600, time(), 2000.0), 'Critical-protection run admitted');
$control->workerDecision($criticalRun, $owner, 'paused_impact');
$control->decide($criticalRun, $owner, 'continue', null, 2100.0);
$criticalState = $control->workerDecision($criticalRun, $owner, 'paused_critical');
control_assert($criticalState['decision'] === 'paused_critical', 'Continue Anyway must never suppress a later Critical pause');
$control->finish($criticalRun);

$reportService = new HistoricalReportsService();
$reportDefinition = [
	'name' => 'Runtime admission', 'mode' => 'trunk', 'engine' => 'original', 'preset' => 'last7',
	'range_from' => '2026-09-01', 'range_to' => '2026-09-07', 'include_time' => false,
	'from_time' => '00:00', 'to_time' => '23:59', 'filter' => '', 'minimum_concurrency' => 2,
];
foreach ([5 => 300, 60 => 3600, 120 => 7200, 1440 => 86400] as $minutes => $seconds) {
	$report = $reportService->createReport($reportService->defaults(), array_merge($reportDefinition, ['maximum_runtime_minutes' => $minutes]))[1];
	$runId = str_repeat(dechex(($minutes % 15) + 1), 32);
	control_assert($control->admitGui($runId, $owner, $reportService->runtimeAllowanceSeconds($report), time(), 3000.0), 'A saved report admits its configured GUI runtime allowance');
	control_assert($control->owned($runId, $owner)['runtime_allowance_seconds'] === $seconds, 'Saved report minutes convert exactly to the server-owned GUI seconds allowance');
	$control->finish($runId);
}
$legacyReport = $reportService->normaliseDefinition($reportDefinition);
$legacyRun = '88888888888888888888888888888888';
control_assert($control->admitGui($legacyRun, $owner, $reportService->runtimeAllowanceSeconds($legacyReport), time(), 3000.0), 'A legacy saved report without a runtime field receives the authoritative default');
control_assert($control->owned($legacyRun, $owner)['runtime_allowance_seconds'] === 3600, 'Legacy saved report default converts to 3600 seconds');
$legacyBeforeExtension = $legacyReport['maximum_runtime_minutes'];
$control->decide($legacyRun, $owner, 'allowance', 7200, 3001.0);
control_assert($control->owned($legacyRun, $owner)['runtime_allowance_seconds'] === 7200 && $legacyReport['maximum_runtime_minutes'] === $legacyBeforeExtension, 'Temporary allowance increase changes only the calculation-control record');
$control->publish($legacyRun, ['runtime_remaining' => 7199.0]);
control_assert((float)$control->status($legacyRun)['runtime_remaining'] === 7199.0, 'Telemetry retains runtime remaining against the authoritative configured allowance');
$control->finish($legacyRun);

foreach ([240, 86460, 330] as $invalidInitialAllowance) {
	$rejected = false;
	try { $control->admitGui('99999999999999999999999999999999', $owner, $invalidInitialAllowance); }
	catch (InvalidArgumentException $exception) { $rejected = true; }
	control_assert($rejected, 'GUI admission rejects an invalid initial runtime allowance');
}

$expired = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$control->begin($expired, 1);
$control->cleanupExpired(1 + HistoricalCalculationControl::RECORD_TTL + 1);
control_assert(!$control->isCancelled($expired), 'Expired abandoned control records are removed');
control_assert($control->status($expired) === null, 'Expired abandoned telemetry records are removed');

$invalidRejected = false;
try { $control->begin('../../process-id'); } catch (InvalidArgumentException $exception) { $invalidRejected = true; }
control_assert($invalidRejected, 'Calculation IDs are strictly validated opaque values');

echo "Historical calculation control tests passed\n";
