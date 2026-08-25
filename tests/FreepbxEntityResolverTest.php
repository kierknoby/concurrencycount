<?php

require_once __DIR__ . '/../Resolvers/FreepbxEntityResolver.php';

use FreePBX\modules\Concurrencycount\Resolvers\FreepbxEntityResolver;

function entity_assert($condition, string $message): void {
	if (!$condition) {
		throw new Exception($message);
	}
}

$fixtures = [
	'from-did-direct,203,1' => ['User Extension 203: Kieran', 'config.php?type=setup&display=extensions&extdisplay=203&skip=0', 'extension'],
	'ext-trunk,4,1' => ['Trunk: Gamma SIP (pjsip)', 'config.php?type=setup&display=trunks&extdisplay=OUT_4', 'trunk'],
	'from-trunk,02079461234,1' => ['Inbound Routes : Main', 'config.php?display=did&view=form&extdisplay=02079461234%2F', 'destination'],
	'ext-group,610,1' => ['Ring Group: Sales', 'config.php?display=ringgroups&view=form&extdisplay=610', 'ringgroup'],
	'ext-queues,600,1' => ['Queue: Sales', 'config.php?display=queues&view=form&extdisplay=600', 'queue'],
	'ivr-3,s,1' => ['IVR: Main Menu', 'config.php?display=ivr&action=edit&id=3', 'ivr'],
	'app-announcement-2,s,1' => ['Announcement: Welcome', 'config.php?display=announcement&action=edit&id=2', 'announcement'],
	'timeconditions,5,1' => ['Time Condition: Business Hours', 'config.php?display=timeconditions&itemid=5', 'timecondition'],
	'timegroups,7,1' => ['Time Group: Office Hours', 'config.php?display=timegroups&view=form&id=7', 'destination'],
	'ext-meetme,700,1' => ['Conference: Daily Standup', 'config.php?display=conferences&view=form&extdisplay=700', 'conference'],
	'followme-203,s,1' => ['Follow Me: 203', 'config.php?display=findmefollow&view=form&extdisplay=203', 'followme'],
	'app-daynight,0,1' => ['Call Flow Control: Main', 'config.php?display=daynight&view=form&itemid=0', 'callflow'],
	'miscapp-1,s,1' => ['Misc Application: Support', 'config.php?display=miscapps&view=form&extdisplay=1', 'destination'],
	'ext-miscdests,2,1' => ['Misc Destination: Mobile', 'config.php?display=miscdests&view=form&extdisplay=2', 'destination'],
	'ext-local,vmu203,1' => ['Voicemail: 203 unavailable', 'config.php?display=voicemail&extdisplay=203', 'voicemail'],
	'customdests,dest-1,1' => ['Custom Destination: CRM', 'config.php?display=customdests&view=form&extdisplay=1', 'destination'],
	'app-blackhole,hangup,1' => ['Terminate Call: Hangup', '', 'terminate'],
];

$provider = function (string $destination) use ($fixtures): array {
	if (!isset($fixtures[$destination])) {
		return [];
	}
	return [
		'description' => $fixtures[$destination][0],
		'edit_url' => $fixtures[$destination][1],
	];
};
$resolver = new FreepbxEntityResolver($provider);

foreach ($fixtures as $destination => $fixture) {
	$entity = $resolver->resolveDestination($destination);
	entity_assert($entity !== null, 'Fixture did not resolve: ' . $destination);
	entity_assert($entity['label'] === $fixture[0], 'Label mismatch: ' . $destination);
	entity_assert($entity['type'] === $fixture[2], 'Type mismatch: ' . $destination);
	$expectedUrl = $fixture[1] === '' ? null : $fixture[1];
	entity_assert($entity['native_url'] === $expectedUrl, 'URL mismatch: ' . $destination);
}

entity_assert($resolver->resolveDestination('unknown,600,1') === null, 'Unknown destination must remain plain text');
foreach ([
	'', '../config.php?display=queues', 'ext-queues,<script>,1', "ext-queues,600,1\r\nX-Test: bad",
] as $destination) {
	entity_assert($resolver->resolveDestination($destination) === null, 'Malformed destination must be rejected');
}
foreach ([
	'https://attacker.example/', '//attacker.example/', 'javascript:alert(1)',
	'config.php?display=queues&next=https://attacker.example/', "config.php?display=queues\nLocation:x",
] as $url) {
	entity_assert($resolver->safeLocalUrl($url) === null, 'Unsafe URL must be rejected: ' . $url);
}
entity_assert(
	$resolver->safeLocalUrl('config.php?display=did&view=form&extdisplay=02079461234%2F') === 'config.php?display=did&view=form&extdisplay=02079461234%2F',
	'Encoded local URL should survive unchanged'
);
entity_assert(
	$resolver->safeLocalUrl('config.php?display=did&view=form&extdisplay=+442079461234%2F') === 'config.php?display=did&view=form&extdisplay=%2B442079461234%2F',
	'E.164 plus sign should be URL encoded'
);

echo "FreePBX entity resolver tests passed\n";
