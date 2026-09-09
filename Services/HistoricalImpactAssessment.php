<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Correlation evidence, never a claim of exclusive process/database attribution. */
class HistoricalImpactAssessment {
	private $threshold;
	private $started;
	private $baseline;
	private $samples = [];
	private $recent = [];
	public function __construct(int $threshold, float $started, array $baseline = []) {
		if ($threshold < 50 || $threshold > 95) throw new \InvalidArgumentException('PBX Protection must be between 50% and 95%.');
		$this->threshold = $threshold; $this->started = $started; $this->baseline = $baseline;
	}
	public function sample(float $now, array $m): array {
		if (!isset($this->baseline['query_seconds']) && isset($m['query_seconds']) && $m['query_seconds'] > 0) $this->baseline['query_seconds'] = $m['query_seconds'];
		$memory = isset($m['memory_total'], $m['memory_available']) && $m['memory_total'] > 0 ? 100 * (1 - $m['memory_available'] / $m['memory_total']) : null;
		$cpu = $m['system_cpu_percent'] ?? null;
		$io = $m['io_wait_percent'] ?? null;
		$active = ($m['process_cpu_percent'] ?? 0) >= 10 || ($m['query_seconds'] ?? 0) >= .5;
		$rise = $cpu !== null && isset($this->baseline['system_cpu_percent']) && $cpu - $this->baseline['system_cpu_percent'] >= 15;
		$loadRise = isset($m['system_load'], $m['system_cpu_count'], $this->baseline['system_load']) && $m['system_load'] - $this->baseline['system_load'] >= $m['system_cpu_count'] * .5;
		$pressures = 0;
		if ($cpu !== null && $cpu >= $this->threshold) $pressures++;
		if ($memory !== null && $memory >= $this->threshold) $pressures++;
		if ($io !== null && $io >= 20) $pressures++;
		if (($m['io_pressure_percent'] ?? 0) >= 20) $pressures++;
		if (($m['swap_pages_per_second'] ?? 0) >= 256) $pressures++;
		$degraded = isset($m['query_seconds'], $this->baseline['query_seconds']) && $this->baseline['query_seconds'] > 0 && $m['query_seconds'] > max(1, 3 * $this->baseline['query_seconds']);
		$concerning = $pressures >= 2 || ($pressures >= 1 && $active && ($rise || $loadRise || $degraded));
		$acuteMemory = $memory !== null && $memory >= 98 && (($m['swap_pages_per_second'] ?? 0) >= 256 || ($m['memory_pressure_percent'] ?? 0) >= 20);
		$severeCpu = $cpu !== null && $cpu >= 99 && $active && ($rise || $loadRise);
		$severeIo = (($io !== null && $io >= 40) || ($m['io_pressure_percent'] ?? 0) >= 40) && $active;
		$severeSwap = ($m['swap_pages_per_second'] ?? 0) >= 2048 && $memory !== null && $memory >= 95 && $active;
		$sample = ['at' => $now, 'concern' => $concerning, 'pressure' => $pressures > 0, 'severe' => $severeCpu || $severeIo || $severeSwap, 'available' => $cpu !== null || $memory !== null || $io !== null];
		if ($now <= $this->started + 300 || empty($this->samples)) $this->samples[] = $sample;
		$this->recent[] = $sample;
		$this->recent = array_values(array_filter($this->recent, function ($s) use ($now) { return $s['at'] >= $now - 60; }));
		$this->recent = array_slice($this->recent, -31);
		$complete = $now - $this->started >= 300;
		$count = count($this->samples);
		$recentCount = count($this->recent);
		$recentSustained = $recentCount >= 10 && $now - $this->recent[0]['at'] >= 18;
		$windowSustained = $count >= 10 && $now - $this->samples[0]['at'] >= 18;
		$critical = $acuteMemory || ($recentSustained && count(array_filter($this->recent, function ($s) { return $s['severe']; })) / $recentCount >= .8);
		// Three quarters of the complete observation window must be concerning;
		// this retains four early minutes while rejecting isolated transients.
		$high = $windowSustained && count(array_filter($this->samples, function ($s) { return $s['concern']; })) / $count >= .75;
		$moderate = count(array_filter($this->samples, function ($s) { return $s['pressure']; })) > 0;
		$available = count(array_filter($this->samples, function ($s) { return $s['available']; })) >= max(10, (int)ceil($count * .75));
		$status = $critical ? 'Critical' : (!$complete ? 'Assessing...' : (!$available || !$windowSustained ? 'Unavailable' : ($high ? 'High' : ($moderate ? 'Moderate' : 'Low'))));
		return ['impact_status' => $status, 'impact_assessment_complete' => $complete,
			'impact_reason' => !$available ? 'Resource measurements unavailable; impact cannot be established.' : ($high || $critical ? 'Potentially concerning PBX impact was observed while this calculation was running.' : 'No sustained concerning degradation observed in available measurements.'),
			'impact_metrics_available' => $available];
	}
}
