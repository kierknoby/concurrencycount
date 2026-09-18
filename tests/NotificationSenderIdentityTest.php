<?php
if (!interface_exists('BMO')) { interface BMO {} }

class NotificationSenderConfig {
	public $values = [];
	public function get($key) { return isset($this->values[$key]) ? $this->values[$key] : ''; }
}

class FreePBX {
	public static $config;
	public static function Config() { return self::$config; }
}

class CI_Email {
	public static $last;
	public $fromArgs = [];
	public $replyToArgs = [];
	public $headers = [];
	public function __construct() { self::$last = $this; }
	public function from($address, $name = '', $returnPath = '') { $this->fromArgs = [$address, $name, $returnPath]; return $this; }
	public function reply_to($address, $name = '') { $this->replyToArgs = [$address, $name]; return $this; }
	public function set_header($name, $value) { $this->headers[$name] = $value; return $this; }
	public function to($to) { return $this; }
	public function subject($subject) { return $this; }
	public function set_mailtype($type) { return $this; }
	public function message($body) { return $this; }
	public function attach($path, $disposition) { return $this; }
	public function send() { return true; }
}

class LegacyNotificationEmail {
	public function from($address, $name = '') {}
}

require_once dirname(__DIR__) . '/Concurrencycount.class.php';

function sender_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

$module = (new ReflectionClass(\FreePBX\modules\Concurrencycount::class))->newInstanceWithoutConstructor();
$normalise = new ReflectionMethod($module, 'normaliseNotificationSenderIdentity');
$normalise->setAccessible(true);
$resolve = function (string $value, string $fallback = 'FreePBX') use ($normalise, $module): array {
	return $normalise->invoke($module, $value, $fallback);
};

foreach ([
	['asterisk@example.com', 'FreePBX', 'asterisk@example.com', 'FreePBX'],
	['  asterisk@example.com  ', 'FreePBX', 'asterisk@example.com', 'FreePBX'],
	['<asterisk@example.com>', 'FreePBX', 'asterisk@example.com', 'FreePBX'],
	['PBX-123 <asterisk@example.com>', 'FreePBX', 'asterisk@example.com', 'PBX-123'],
	['JaCoTec TK-System <pbx@example.com>', 'FreePBX', 'pbx@example.com', 'JaCoTec TK-System'],
	['  JaCoTec TK-System <pbx@example.com>  ', 'FreePBX', 'pbx@example.com', 'JaCoTec TK-System'],
] as $fixture) {
	$identity = $resolve($fixture[0], $fixture[1]);
	sender_assert($identity === ['address' => $fixture[2], 'name' => $fixture[3]], 'Sender identity mismatch for: ' . $fixture[0]);
}

foreach ([
	'', 'PBX <asterisk@example.com', 'PBX asterisk@example.com>',
	'PBX <<asterisk@example.com>>', 'PBX <asterisk@example.com> <other@example.com>',
	'PBX <asterisk@example.com> trailing',
	'not-an-email', "PBX <asterisk@example.com>\r\nBcc: attacker@example.com",
] as $invalid) {
	sender_assert($resolve($invalid) === ['address' => '', 'name' => ''], 'Malformed sender must be rejected: ' . str_replace(["\r", "\n"], '', $invalid));
}

sender_assert($resolve('asterisk@example.com', '')['name'] === 'Concurrency Count', 'Empty brand must use the Concurrency Count fallback name');
sender_assert($resolve('asterisk@example.com', "Brand\r\nInjected")['name'] === 'Concurrency Count', 'Unsafe brand must use the Concurrency Count fallback name');

FreePBX::$config = new NotificationSenderConfig();
FreePBX::$config->values = [
	'AMPUSERMANEMAILFROM' => 'JaCoTec TK-System <pbx@example.com>',
	'DASHBOARD_FREEPBX_BRAND' => 'Ignored fallback',
];
$send = new ReflectionMethod($module, 'sendMail');
$send->setAccessible(true);
$result = $send->invoke($module, 'recipient@example.com', 'Subject', 'Body');
sender_assert($result['ok'] === true, 'Resolved sender mail must be accepted by the fake transport');
sender_assert(CI_Email::$last->fromArgs === ['pbx@example.com', 'JaCoTec TK-System', 'pbx@example.com'], 'From and Return-Path must use the resolved sender identity');
sender_assert(CI_Email::$last->replyToArgs === ['pbx@example.com', 'JaCoTec TK-System'], 'Reply-To must use the resolved sender identity');

FreePBX::$config->values = [
	'AMPUSERMANEMAILFROM' => 'asterisk@example.com',
	'DASHBOARD_FREEPBX_BRAND' => 'Configured PBX Brand',
];
$send->invoke($module, 'recipient@example.com', 'Subject', 'Body');
sender_assert(CI_Email::$last->fromArgs === ['asterisk@example.com', 'Configured PBX Brand', 'asterisk@example.com'], 'Address-only configuration must use the configured FreePBX brand fallback');

$returnPathSupport = new ReflectionMethod($module, 'emailFromSupportsReturnPath');
$returnPathSupport->setAccessible(true);
sender_assert($returnPathSupport->invoke($module, new CI_Email()) === true, 'Modern CI_Email from signature must support an explicit Return-Path');
sender_assert($returnPathSupport->invoke($module, new LegacyNotificationEmail()) === false, 'Legacy CI_Email from signature must retain two-argument compatibility');

FreePBX::$config->values['AMPUSERMANEMAILFROM'] = 'invalid <sender';
CI_Email::$last = null;
$invalidResult = $send->invoke($module, 'recipient@example.com', 'Subject', 'Body');
sender_assert($invalidResult['ok'] === false && CI_Email::$last === null, 'Invalid configured sender must fail before constructing the mail transport');

echo "Notification sender identity tests passed\n";
