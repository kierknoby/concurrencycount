<?php

require_once __DIR__ . '/../Services/HistoricalCdrEligibility.php';
require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
require_once __DIR__ . '/../Engines/Sweep.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalCdrEligibility;
use FreePBX\modules\Concurrencycount\Engines\Original;
use FreePBX\modules\Concurrencycount\Engines\Sweep;

function eligibility_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

$zero = ['calldate'=>'2026-09-16 10:00:00','duration'=>0,'disposition'=>'ANSWERED','identity'=>'trunk-a','chan'=>'PJSIP/trunk-a-a1','extension_legs'=>2];
$one = ['calldate'=>'2026-09-16 10:00:00','duration'=>1,'disposition'=>'ANSWERED','identity'=>'trunk-a','chan'=>'PJSIP/trunk-a-a2','extension_legs'=>1];
$long = ['calldate'=>'2026-09-16 10:00:00','duration'=>60,'disposition'=>'ANSWERED','identity'=>'trunk-a','chan'=>'PJSIP/trunk-a-a3','extension_legs'=>1];
eligibility_assert(!HistoricalCdrEligibility::isEligible($zero), 'ANSWERED duration zero is ineligible');
eligibility_assert(HistoricalCdrEligibility::isEligible($one), 'ANSWERED duration one is eligible');
$eligible = HistoricalCdrEligibility::filter([$zero, $one, $long]);
eligibility_assert(count($eligible) === 2 && !in_array($zero, $eligible, true), 'The shared dataset removes zero duration before classification/evidence');
$options = ['all_names'=>['trunk-a'=>true], 'coalesce_ranges'=>function(array $times): array { return []; }, 'check_overrun'=>function(): void {}];
foreach ([new Original($options), new Sweep($options)] as $engine) {
	$trunk = $engine->calculatePerName('trunk', $eligible);
	$extension = $engine->calculatePerName('extension', $eligible);
	$group = $engine->calculateGroup($eligible);
	eligibility_assert($trunk['global_max'] === 2, get_class($engine) . ' excludes ANSWERED duration zero from same-timestamp Trunk peak');
	eligibility_assert($extension['global_max'] === 2, get_class($engine) . ' excludes ANSWERED duration zero from same-timestamp Extension peak');
	eligibility_assert($group['max_concurrency'] === 2, get_class($engine) . ' excludes zero duration from Group peak');
}
echo "Historical CDR eligibility tests passed\n";
