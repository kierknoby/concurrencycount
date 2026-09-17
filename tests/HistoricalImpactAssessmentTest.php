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
$memoryCriticalAssessment = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20]);
for ($i = 0; $i < 11; $i++) $memoryCritical = $memoryCriticalAssessment->sample(280 + $i * 2, ['system_cpu_percent' => 99, 'memory_total' => 100, 'memory_available' => 1, 'memory_pressure_percent' => 30]);
impact_assert($memoryCritical['impact_status'] === 'Critical', 'Active severe memory pressure remains Critical when the assessment window elapses');
impact_assert($memoryCriticalAssessment->complete(301)['impact_status'] === 'High', 'Completed severe memory pressure resolves to High');
$transient = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20]);
for ($i = 0; $i < 10; $i++) $result = $transient->sample(282 + $i * 2, ['system_cpu_percent' => $i === 4 ? 99 : 20]);
impact_assert($result['impact_status'] !== 'High', 'One transient spike must not become High');
$unknown = impact_classify([]);
impact_assert($unknown['impact_status'] === 'Unable to assess' && !$unknown['impact_metrics_available'], 'Unavailable metrics must not masquerade as measured Low impact');
$active = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20]);
$activeResult = $active->sample(30, ['system_cpu_percent' => 30, 'memory_total' => 100, 'memory_available' => 70]);
impact_assert($activeResult['impact_status'] === 'Assessing...' && !$activeResult['impact_assessment_complete'], 'An active assessment remains Assessing');
$completedLow = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20]);
for ($i = 0; $i < 11; $i++) $completedLow->sample(10 + ($i * 2), ['system_cpu_percent' => 30, 'memory_total' => 100, 'memory_available' => 70]);
impact_assert($completedLow->complete(54)['impact_status'] === 'Low', 'A completed short Demo resolves modest telemetry to Low');
$cpuCriticalAssessment = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20, 'system_load' => .2, 'query_seconds' => .1]);
for ($i = 0; $i < 11; $i++) $cpuCritical = $cpuCriticalAssessment->sample(280 + $i * 2, ['system_cpu_percent' => 100, 'system_cpu_count' => 4, 'system_load' => 5, 'memory_total' => 100, 'memory_available' => 50, 'process_cpu_percent' => 25]);
impact_assert($cpuCritical['impact_status'] === 'Critical', 'Active extreme CPU saturation remains Critical');
impact_assert($cpuCriticalAssessment->complete(301)['impact_status'] === 'High', 'Completed extreme CPU saturation resolves to High');
$ioCriticalAssessment = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20, 'system_load' => .2, 'query_seconds' => .1]);
for ($i = 0; $i < 11; $i++) $ioCritical = $ioCriticalAssessment->sample(280 + $i * 2, ['system_cpu_percent' => 50, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 50, 'process_cpu_percent' => 25, 'io_wait_percent' => 45]);
impact_assert($ioCritical['impact_status'] === 'Critical', 'Active extreme I/O pressure remains Critical');
impact_assert($ioCriticalAssessment->complete(301)['impact_status'] === 'High', 'Completed extreme I/O pressure resolves to High');
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
$lateCritical = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20, 'system_load' => .2]);
for ($second = 0; $second <= 300; $second += 2) {
	$lateCritical->sample($second, ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'system_load' => .2, 'memory_total' => 100, 'memory_available' => 70]);
}
$lateCriticalResult = $lateCritical->sample(302, ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'system_load' => .2, 'memory_total' => 100, 'memory_available' => 1, 'memory_pressure_percent' => 30]);
impact_assert($lateCriticalResult['impact_status'] === 'Critical' && $lateCriticalResult['impact_assessment_complete'], 'Acute memory pressure remains Critical after the initial assessment window');
impact_assert($lateCritical->complete(303)['impact_status'] === 'Low', 'Final presentation does not expose a transient Critical classification');
$lateSustainedCritical = new HistoricalImpactAssessment(90, 0, ['system_cpu_percent' => 20, 'system_load' => .2]);
for ($second = 0; $second <= 300; $second += 2) $lateSustainedCritical->sample($second, ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'system_load' => .2, 'memory_total' => 100, 'memory_available' => 70]);
for ($second = 302; $second <= 362; $second += 2) $lateSustainedResult = $lateSustainedCritical->sample($second, ['system_cpu_percent' => 100, 'system_cpu_count' => 4, 'system_load' => 5, 'memory_total' => 100, 'memory_available' => 70, 'process_cpu_percent' => 25]);
impact_assert($lateSustainedResult['impact_status'] === 'Critical', 'Sustained post-window resource pressure remains actively Critical');
impact_assert($lateSustainedCritical->complete(363)['impact_status'] === 'High', 'Final presentation translates sustained Critical evidence to High');
$rejected = false; try { new HistoricalImpactAssessment(100, 0); } catch (InvalidArgumentException $e) { $rejected = true; }
impact_assert($rejected, 'An ineffective 100% setting is rejected');
echo "Historical impact assessment tests passed\n";
