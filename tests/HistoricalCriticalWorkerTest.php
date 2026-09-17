<?php
if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalAssessment;
use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;
use FreePBX\modules\Concurrencycount\Services\HistoricalTelemetryCadence;

function critical_worker_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

class CriticalWorkerSampler {
	public $acute = false;
	public function sample($now): array {
		return $this->acute
			? ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'system_load' => .2, 'memory_total' => 100, 'memory_available' => 1, 'memory_pressure_percent' => 30]
			: ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'system_load' => .2, 'memory_total' => 100, 'memory_available' => 70];
	}
}
class CriticalWorkerGuard { public function checkpoint(): void {} }
class CriticalWorkerLockStatement { public function fetchColumn() { return 1; } }
class CriticalWorkerLockDatabase { public function query($sql) { return new CriticalWorkerLockStatement(); } }
class CriticalWorkerFreePBX { public $Database; public function __construct() { $this->Database = new CriticalWorkerLockDatabase(); } }
class CriticalWorkerControl {
	public $decisions = [];
	private $record;
	public function __construct(float $started) {
		$this->record = ['status' => 'active', 'lease_expires_at' => time() + 60, 'runtime_allowance_seconds' => 3600, 'decision' => 'running', 'assessment_generation' => 0, 'assessment_started_at' => $started];
	}
	public function owned($id, $owner): array { return $this->record; }
	public function workerDecision($id, $owner, $decision): array {
		$this->decisions[] = $decision;
		return $this->record;
	}
	public function publish($id, array $measurements): void {}
}
class CriticalWorkerConcurrencycount extends \FreePBX\modules\Concurrencycount { public function __construct() {} }

$now = HistoricalRuntimeEstimator::now();
$started = $now - 302;
$sampler = new CriticalWorkerSampler();
$assessment = new HistoricalAssessment($started, 90, $sampler);
for ($second = 0; $second <= 300; $second += 2) {
	$healthy = $assessment->checkpoint($second + 1, 1000, 'test', $started + $second);
}
critical_worker_assert($healthy['impact_status'] === 'Low', 'Healthy samples complete the initial assessment as Low');
$sampler->acute = true;
$control = new CriticalWorkerControl($started);
$cc = new CriticalWorkerConcurrencycount();
$set = function (string $name, $value) use ($cc): void {
	$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name);
	$property->setAccessible(true);
	$property->setValue($cc, $value);
};
$set('FreePBX', new CriticalWorkerFreePBX());
$set('workerGuard', new CriticalWorkerGuard());
$set('workerCancellation', function () { return false; });
$set('workerControl', $control);
$set('workerId', str_repeat('7', 32));
$set('workerOwner', hash('sha256', 'critical-worker-owner'));
$set('workerGeneration', 0);
$set('workerLast', -INF);
$set('workerWork', [302, 1000, 'test']);
$set('workerCadence', new HistoricalTelemetryCadence());
$set('assessment', $assessment);
$checkpoint = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'workerCheckpoint');
$checkpoint->setAccessible(true);
$checkpoint->invoke($cc);
critical_worker_assert($control->decisions === ['paused_critical'], 'Post-window Critical classification drives the worker protective pause decision');
echo "Historical Critical worker tests passed\n";
