<?php
require_once __DIR__ . '/../Services/HistoricalImpactAssessment.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalImpactAssessment;
function impact_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
function impact_classify(array $sample, array $baseline = ['system_cpu_percent' => 20, 'system_load' => .2, 'query_seconds' => .1]): array {
	$a = new HistoricalImpactAssessment(90, 0, $baseline);
	for ($i = 0; $i < 11; $i++) $r = $a->sample(280 + $i * 2, $sample);
	return $r;
}
impact_assert(impact_classify(['system_cpu_percent' => 30, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 70])['impact_status'] === 'Low', 'Low stream');
impact_assert(impact_classify(['system_cpu_percent' => 92, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 30])['impact_status'] === 'Moderate', 'One pressure without attribution remains Moderate');
impact_assert(impact_classify(['system_cpu_percent' => 94, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 5, 'process_cpu_percent' => 30])['impact_status'] === 'High', 'Sustained multi-metric pressure is High');
impact_assert(impact_classify(['system_cpu_percent' => 99, 'memory_total' => 100, 'memory_available' => 1, 'memory_pressure_percent' => 30])['impact_status'] === 'Critical', 'Severe memory pressure is Critical');
$transient = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20]);
for ($i = 0; $i < 10; $i++) $result = $transient->sample(282 + $i * 2, ['system_cpu_percent' => $i === 4 ? 99 : 20]);
impact_assert($result['impact_status'] !== 'High', 'One transient spike must not become High');
$unknown = impact_classify([]);
impact_assert($unknown['impact_status'] === 'Unavailable' && !$unknown['impact_metrics_available'], 'Unavailable metrics must not masquerade as measured Low impact');
$cpuCritical = impact_classify(['system_cpu_percent' => 100, 'system_cpu_count' => 4, 'system_load' => 5, 'memory_total' => 100, 'memory_available' => 50, 'process_cpu_percent' => 25]);
impact_assert($cpuCritical['impact_status'] === 'Critical', 'Extreme sustained CPU saturation with active calculation evidence is Critical');
$ioCritical = impact_classify(['system_cpu_percent' => 50, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 50, 'process_cpu_percent' => 25, 'io_wait_percent' => 45]);
impact_assert($ioCritical['impact_status'] === 'Critical', 'Extreme sustained I/O pressure with active calculation evidence is Critical');
$oneCpuSpike = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20, 'system_load' => .2]);
for ($i = 0; $i < 10; $i++) $oneCpuResult = $oneCpuSpike->sample(282 + $i * 2, ['system_cpu_percent' => $i === 9 ? 100 : 20, 'system_cpu_count' => 4, 'system_load' => $i === 9 ? 5 : .2, 'process_cpu_percent' => 25]);
impact_assert($oneCpuResult['impact_status'] !== 'Critical', 'One extreme CPU spike must not become Critical');
$wholeWindow = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20, 'system_load' => .2]);
for ($second = 0; $second <= 300; $second += 2) {
	$sample = $second < 240
		? ['system_cpu_percent' => 94, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 5, 'process_cpu_percent' => 30]
		: ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 70, 'process_cpu_percent' => 1];
	$wholeWindowResult = $wholeWindow->sample($second, $sample);
}
impact_assert($wholeWindowResult['impact_status'] === 'High', 'Four concerning minutes cannot age out during a quiet final minute');
$rejected = false; try { new HistoricalImpactAssessment(100, 0); } catch (InvalidArgumentException $e) { $rejected = true; }
impact_assert($rejected, 'An ineffective 100% setting is rejected');
echo "Historical impact assessment tests passed\n";
