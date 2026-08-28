<?php

require_once __DIR__ . '/../Services/SettingsRepository.php';
require_once __DIR__ . '/../Services/HistoricalCalculationControl.php';
require_once __DIR__ . '/../Services/HistoricalRuntimeEstimator.php';

use FreePBX\modules\Concurrencycount\Services\SettingsRepository;
use FreePBX\modules\Concurrencycount\Services\HistoricalCalculationControl;
use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;

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
control_assert($flowStatus['eta_reliable'] === true && $flowStatus['estimated_remaining'] > 0.0 && $flowStatus['estimated_remaining'] < 1.0, 'Deterministic estimator output survives the active calculation-control telemetry path');
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

$owner = hash('sha256', 'owner-one');
$otherOwner = hash('sha256', 'owner-two');
$guiFirst = '11111111111111111111111111111111';
$guiSecond = '22222222222222222222222222222222';
control_assert($control->admitGui($guiFirst, $owner, 2000), 'First GUI calculation is admitted for its ownership scope');
control_assert(!$control->admitGui($guiFirst, $owner, 2000), 'Duplicate Run request with the same calculation ID is not admitted twice');
control_assert(!$control->admitGui($guiSecond, $owner, 2001), 'Second GUI calculation for the same owner is rejected before engine admission');
control_assert($control->admitGui($guiSecond, $otherOwner, 2001), 'A different authenticated GUI ownership scope is independent');
control_assert($control->heartbeat($guiFirst, $owner, 2005), 'Healthy owner heartbeat renews the exact calculation lease');
control_assert(!$control->heartbeat($guiFirst, $otherOwner, 2006), 'Another ownership scope cannot renew a GUI lease');
control_assert(!$control->heartbeat($guiSecond, $owner, 2006), 'A stale owner/calculation pairing cannot renew a newer run');
control_assert(!$control->shouldStop($guiFirst, 2024), 'Renewed GUI lease remains healthy before expiry');
control_assert($control->shouldStop($guiFirst, 2025), 'Missing heartbeat expires at the documented lease boundary');
control_assert(!$control->admitGui('33333333333333333333333333333333', $owner, 2025), 'Expired but not-yet-unwound GUI work still blocks replacement admission');
$control->finish($guiFirst);
control_assert($control->admitGui('33333333333333333333333333333333', $owner, 2026), 'Replacement is admitted only after abandoned work performs terminal cleanup');
$control->finish('33333333333333333333333333333333');
$control->cancel($guiSecond, 2002);
control_assert($control->shouldStop($guiSecond, 2002), 'GUI cancellation remains a cooperative checkpoint condition');
control_assert(!$control->admitGui('44444444444444444444444444444444', $otherOwner, 2002), 'Cancellation request alone does not permit backend overlap');
$control->finish($guiSecond);
control_assert($control->admitGui('44444444444444444444444444444444', $otherOwner, 2003), 'Cancelled calculation permits replacement after it unwinds');
$control->finish('44444444444444444444444444444444');

$expired = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$control->begin($expired, 1);
$control->cleanupExpired(1 + HistoricalCalculationControl::RECORD_TTL + 1);
control_assert(!$control->isCancelled($expired), 'Expired abandoned control records are removed');
control_assert($control->status($expired) === null, 'Expired abandoned telemetry records are removed');

$invalidRejected = false;
try { $control->begin('../../process-id'); } catch (InvalidArgumentException $exception) { $invalidRejected = true; }
control_assert($invalidRejected, 'Calculation IDs are strictly validated opaque values');

echo "Historical calculation control tests passed\n";
