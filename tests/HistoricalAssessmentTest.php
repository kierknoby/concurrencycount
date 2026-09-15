<?php
require_once __DIR__ . '/../Services/HistoricalAssessment.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalAssessment;
function assessment_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
class AssessmentSampler {
	public $concern = true;
	public function sample($now) {
		return $this->concern
			? ['system_cpu_percent' => 94, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 5, 'process_cpu_percent' => 30]
			: ['system_cpu_percent' => 20, 'system_cpu_count' => 4, 'memory_total' => 100, 'memory_available' => 70, 'process_cpu_percent' => 1];
	}
}
$sampler = new AssessmentSampler(); $assessment = new HistoricalAssessment(0, 90, $sampler);
for ($second = 0; $second <= 300; $second += 2) $first = $assessment->checkpoint($second + 1, 1000, 'original-occupied-seconds', $second);
assessment_assert($first['impact_status'] === 'High', 'Initial five-minute assessment records sustained concern');
$assessment->reassess(400); $sampler->concern = false;
for ($second = 400; $second <= 700; $second += 2) $secondResult = $assessment->checkpoint($second - 399, 1000, 'original-occupied-seconds', $second);
assessment_assert($secondResult['impact_status'] === 'Low' && (float)$secondResult['assessment_started_at'] === 400.0, 'Reassessment uses a genuinely fresh five-minute impact dataset');
echo "Historical assessment reset tests passed\n";
