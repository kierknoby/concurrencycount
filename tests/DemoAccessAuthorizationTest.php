<?php

if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';

function demo_authorization_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

class DemoAuthorizationRepository extends \FreePBX\modules\Concurrencycount\Services\SettingsRepository {
	public $enabled = false;
	public function __construct() {}
	public function get(string $key, $default = null) { return $key === 'demo_access' ? $this->enabled : $default; }
	public function set(string $key, $value): void { if ($key === 'demo_access') $this->enabled = $value; }
}

class DemoAuthorizationConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public function __construct() {}
}

function demo_authorization_private($object, string $method, array $arguments = []) {
	$reflection = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, $method);
	$reflection->setAccessible(true);
	return $reflection->invokeArgs($object, $arguments);
}

function demo_authorization_rejects(callable $operation, string $message): void {
	$rejected = false;
	try { $operation(); } catch (Throwable $exception) { $rejected = strpos($exception->getMessage(), 'Demo access is DISABLED') !== false; }
	demo_authorization_assert($rejected, $message);
}

$cc = new DemoAuthorizationConcurrencycount();
$repository = new DemoAuthorizationRepository();
$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, 'settingsRepository');
$property->setAccessible(true);
$property->setValue($cc, $repository);

demo_authorization_assert($cc->isDemoAccessEnabled() === false, 'Demo access must be disabled by default.');
$rejected = false;
try { $cc->requireDemoAccess(); } catch (RuntimeException $exception) { $rejected = strpos($exception->getMessage(), 'Demo access is DISABLED') !== false; }
demo_authorization_assert($rejected, 'Disabled Demo access must reject server-side execution.');

$cc->setDemoAccessEnabled(true);
demo_authorization_assert($cc->isDemoAccessEnabled() === true, 'The authorization API must enable Demo access.');
$cc->requireDemoAccess();

$cc->setDemoAccessEnabled(false);
$rejectedAgain = false;
try { $cc->calculate('demo', '2026-01-01 00:00:00', '2026-01-02 00:00:00'); } catch (RuntimeException $exception) { $rejectedAgain = true; }
demo_authorization_assert($rejectedAgain, 'The central calculation path must reject Demo when disabled.');

$_REQUEST = ['demo_size' => 'light', 'demo_rows' => 1000];
$preflight = demo_authorization_private($cc, 'handleDemoPreflight');
demo_authorization_assert($preflight['status'] === false && strpos($preflight['message'], 'Demo access is DISABLED') !== false, 'Disabled Demo access must reject preflight before database safety checks.');

$_REQUEST = ['audit_token' => str_repeat('a', 32), 'page' => 1];
$callPage = demo_authorization_private($cc, 'handleDemoCallPage');
demo_authorization_assert($callPage['status'] === false && strpos($callPage['message'], 'Demo access is DISABLED') !== false, 'Disabled Demo access must reject audit paging before spool access.');

$_REQUEST = ['mode' => 'demo', 'start_date' => '2026-01-01 00:00:00', 'end_date' => '2026-01-02 00:00:00'];
$run = demo_authorization_private($cc, 'handleRun');
demo_authorization_assert($run['status'] === false && strpos($run['message'], 'Demo access is DISABLED') !== false, 'Disabled Demo access must reject Demo run admission.');

$_REQUEST = ['mode' => 'demo', 'start_date' => '2026-01-01 00:00:00', 'end_date' => '2026-01-02 00:00:00'];
$previewOutput = '';
ob_start();
demo_authorization_private($cc, 'streamDemoFixturePreview');
$previewOutput = ob_get_clean();
demo_authorization_assert(strpos($previewOutput, 'Demo access is DISABLED') !== false, 'Disabled Demo access must reject synthetic fixture preview before generation.');

demo_authorization_rejects(function () use ($cc): void {
	$method = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'insertDemoCdrRow');
	$method->setAccessible(true);
	$method->invoke($cc, []);
}, 'Disabled Demo access must reject synthetic CDR insertion before INSERT INTO cdr.');

$_REQUEST = [];

echo "Demo access authorization tests passed\n";
