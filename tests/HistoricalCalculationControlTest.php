<?php

require_once __DIR__ . '/../Services/SettingsRepository.php';
require_once __DIR__ . '/../Services/HistoricalCalculationControl.php';

use FreePBX\modules\Concurrencycount\Services\SettingsRepository;
use FreePBX\modules\Concurrencycount\Services\HistoricalCalculationControl;

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
$control->updateTelemetry($first, 12.5, 40.25, 1001);
$firstStatus = $control->status($first);
control_assert($firstStatus['elapsed'] === 12.5 && $firstStatus['estimated_remaining'] === 40.25, 'Estimator progress updates the matching telemetry record');
control_assert((float)$control->status($second)['elapsed'] === 0.0, 'Telemetry is isolated by calculation ID');
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

$expired = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$control->begin($expired, 1);
$control->cleanupExpired(1 + HistoricalCalculationControl::RECORD_TTL + 1);
control_assert(!$control->isCancelled($expired), 'Expired abandoned control records are removed');
control_assert($control->status($expired) === null, 'Expired abandoned telemetry records are removed');

$invalidRejected = false;
try { $control->begin('../../process-id'); } catch (InvalidArgumentException $exception) { $invalidRejected = true; }
control_assert($invalidRejected, 'Calculation IDs are strictly validated opaque values');

echo "Historical calculation control tests passed\n";
