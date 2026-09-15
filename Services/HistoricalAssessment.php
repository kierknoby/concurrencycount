<?php
namespace FreePBX\modules\Concurrencycount\Services;
require_once __DIR__ . '/HistoricalRuntimeEstimator.php';
require_once __DIR__ . '/HistoricalImpactAssessment.php';
require_once __DIR__ . '/HistoricalProcessTelemetry.php';

/** One worker instance for the whole logical run, including acquisition and Demo. */
class HistoricalAssessment {
	private $estimator;
	private $impact;
	private $sampler;
	private $started;
	private $assessmentStarted;
	private $threshold;
	private $lastSample = -INF;
	private $metrics = [];
	private $baseline;
	private $result = [];
	private $querySeconds = 0.0;
	private $queryTotal = 0.0;
	private $peaks = [];
	public function __construct(float $started, int $threshold = 90, $sampler = null) {
		$this->started = $started; $this->assessmentStarted = $started; $this->threshold = $threshold;
		$this->sampler = $sampler ?: new HistoricalProcessTelemetry();
		$this->baseline = $this->sampler->sample(HistoricalRuntimeEstimator::now());
		$this->estimator = new HistoricalRuntimeEstimator(3600, $started, $started);
		$this->impact = new HistoricalImpactAssessment($threshold, $started, $this->baseline);
	}
	public function reassess(float $now): void {
		$this->assessmentStarted = $now;
		$this->estimator->beginEngine($now);
		$this->impact = new HistoricalImpactAssessment($this->threshold, $now, $this->metrics);
	}
	public function query(float $seconds): void { $this->querySeconds = max($this->querySeconds, $seconds); $this->queryTotal += $seconds; }
	public function checkpoint(int $processed, int $total, string $stage, float $now, int $allowance = 3600, bool $paused = false): array {
		$this->estimator->allowance($allowance);
		if ($paused) $this->estimator->invalidate();
		$r = $this->estimator->evaluate($processed, $total, $now, $stage);
		if ($now - $this->lastSample >= 2) {
			$this->metrics = $this->sampler->sample($now);
			$this->metrics['query_seconds'] = $this->querySeconds;
			$this->metrics['query_total_seconds'] = $this->queryTotal;
			foreach (['system_cpu_percent', 'memory_pressure_percent', 'io_wait_percent', 'calculation_memory_peak'] as $field) {
				if (isset($this->metrics[$field]) && is_numeric($this->metrics[$field])) $this->peaks[$field] = max($this->peaks[$field] ?? 0, $this->metrics[$field]);
			}
			$this->querySeconds = 0;
			$this->result = $this->impact->sample($now, $this->metrics);
			$this->lastSample = $now;
		}
		return $r + $this->result + $this->metrics + ['runtime_allowance_seconds' => $allowance,
			'runtime_started_at' => $this->started, 'assessment_started_at' => $this->assessmentStarted,
			'assessment_elapsed_seconds' => max(0, $now - $this->assessmentStarted),
			'phase_items_processed' => $processed, 'phase_items_total' => $total > 0 ? $total : null];
	}
	public function summary(float $now): array {
		return $this->result + $this->peaks + ['calculation_seconds' => max(0, $now - $this->started), 'query_total_seconds' => $this->queryTotal];
	}
}
