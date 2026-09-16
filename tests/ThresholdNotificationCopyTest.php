<?php

require_once dirname(__DIR__) . '/Services/ThresholdService.php';

use FreePBX\modules\Concurrencycount\Services\ThresholdService;

function notification_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$reflection = new ReflectionClass(ThresholdService::class);
$service = $reflection->newInstanceWithoutConstructor();

$system = 'PBXSRV20-ONP';
$start = 1000;

$test = $service->buildNotification([
	'type'=>'alert',
	'scope'=>'test',
	'timestamp'=>$start,
	'since'=>$start,
	'threshold'=>1,
	'current'=>1,
	'peak'=>1,
	'direction_counts'=>[],
], $system);

notification_assert($test['subject'] === 'Concurrency Count test - PBXSRV20-ONP', 'Test subject is incorrect');
notification_assert(strpos($test['body'], 'Concurrency Count test email') === 0, 'Test heading is incorrect');
notification_assert(strpos($test['body'], 'PBX: PBXSRV20-ONP') !== false, 'Test PBX identity is missing');
notification_assert(strpos($test['body'], 'Trunk') === false, 'Test email must not fabricate a Trunk line');

$alert = $service->buildNotification([
	'type'=>'alert',
	'scope'=>'trunk:Gamma',
	'timestamp'=>$start,
	'since'=>$start,
	'threshold'=>4,
	'current'=>4,
	'peak'=>4,
	'direction_counts'=>[],
], $system);

notification_assert($alert['subject'] === 'Concurrency Count alert - PBXSRV20-ONP', 'Alert subject is incorrect');
notification_assert(strpos($alert['body'], 'Concurrency threshold reached') === 0, 'Alert heading is incorrect');
notification_assert(strpos($alert['body'], 'Trunk: Gamma') !== false, 'Alert trunk is missing');
notification_assert(strpos($alert['body'], 'Threshold: 4') !== false, 'Alert threshold is missing');
notification_assert(strpos($alert['body'], 'Current: 4') !== false, 'Alert current value is missing');
notification_assert(strpos($alert['body'], 'Peak during alert:') === false, 'Initial alert must not claim a completed episode peak');

$recovery = $service->buildNotification([
	'type'=>'recovery',
	'scope'=>'trunk:Gamma',
	'timestamp'=>$start + 333,
	'since'=>$start,
	'threshold'=>4,
	'current'=>3,
	'peak'=>7,
	'direction_counts'=>[],
], $system);

notification_assert($recovery['subject'] === 'Concurrency Count recovery - PBXSRV20-ONP', 'Recovery subject is incorrect');
notification_assert(strpos($recovery['body'], 'Concurrency returned below threshold') === 0, 'Recovery heading is incorrect');
notification_assert(strpos($recovery['body'], 'Peak during alert: 7') !== false, 'Recovery peak is missing');
notification_assert(strpos($recovery['body'], 'Duration above threshold: 333 seconds') !== false, 'Recovery duration is missing');

$overall = $service->buildNotification([
	'type'=>'alert',
	'scope'=>'overall',
	'timestamp'=>$start,
	'since'=>$start,
	'threshold'=>10,
	'current'=>12,
	'peak'=>12,
	'direction_counts'=>[],
], $system);

notification_assert(strpos($overall['body'], 'Scope: Overall Live Concurrency') !== false, 'Overall scope is incorrect');

foreach ([$test, $alert, $recovery, $overall] as $mail) {
	notification_assert(strpos($mail['body'], 'Please note: email deliveries can be delayed.') !== false, 'Standard delivery-delay footer is missing');
	notification_assert(strpos($mail['body'], 'Check current status in the FreePBX module.') !== false, 'Standard module-status footer is missing');
	notification_assert(strpos($mail['body'], 'config.php?display=concurrencycount') === false, 'Module URL must not appear');
	notification_assert(strpos($mail['body'], 'Accepted by the local mailer') === false, 'Old local-mailer wording must not appear');
	notification_assert(strpos($mail['body'], 'does not confirm external delivery') === false, 'Old delivery disclaimer must not appear');
}

echo "Threshold notification copy tests passed\n";
