<?php

use FreePBX\modules\Concurrencycount\Services\SettingsRepository;

if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';
if (!class_exists('FreePBX')) {
	class FreePBX {
		public static $cron;
		public static function Cron() { return self::$cron; }
	}
}

function demo_lifecycle_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

class DemoAccessLifecycleDatabase {
	public $values = [];
	public function exec($sql) { return true; }
	public function prepare($sql) { return new DemoAccessLifecycleStatement($this, $sql); }
}

class DemoAccessLifecycleStatement {
	private $db;
	private $sql;
	private $result = false;
	public function __construct(DemoAccessLifecycleDatabase $db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute(array $params) {
		$key = (string)($params[':key'] ?? '');
		if (strpos($this->sql, 'SELECT setting_value') === 0) $this->result = array_key_exists($key, $this->db->values) ? $this->db->values[$key] : false;
		elseif (strpos($this->sql, 'INSERT') === 0) $this->db->values[$key] = $params[':value'];
		return true;
	}
	public function fetchColumn() { return $this->result; }
}

class DemoAccessLifecycleCron {
	public function removeLine($line): void {}
}

class DemoAccessLifecycleConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public function __construct() {}
	public function startAlertMonitor(): array { return ['available' => true, 'status' => 'online']; }
}

function demo_lifecycle_set_repository($module, SettingsRepository $repository): void {
	$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, 'settingsRepository');
	$property->setAccessible(true);
	$property->setValue($module, $repository);
}

$db = new DemoAccessLifecycleDatabase();
$repository = new SettingsRepository($db);
FreePBX::$cron = new DemoAccessLifecycleCron();
$module = new DemoAccessLifecycleConcurrencycount();
demo_lifecycle_set_repository($module, $repository);

$module->install();
demo_lifecycle_assert($repository->get('demo_access', null) === false, 'Fresh install must initialise Demo access to DISABLED.');

$repository->set('demo_access', true);
$reloadedRepository = new SettingsRepository($db);
demo_lifecycle_assert($reloadedRepository->get('demo_access', null) === true, 'Explicit authorisation must survive repository reinitialisation when the install lifecycle does not run.');

demo_lifecycle_set_repository($module, $reloadedRepository);
$module->install();
demo_lifecycle_assert($reloadedRepository->get('demo_access', null) === false, 'Module update must reset existing ENABLED Demo access to DISABLED.');

$reloadedRepository->set('demo_access', false);
$module->install();
demo_lifecycle_assert($reloadedRepository->get('demo_access', null) === false, 'Module update must leave existing DISABLED Demo access DISABLED.');

$source = file_get_contents(__DIR__ . '/../Concurrencycount.class.php');
demo_lifecycle_assert(strpos($source, 'function startFreepbx') !== false && strpos($source, 'function stopFreepbx') !== false, 'Service lifecycle hooks must remain present.');
$start = strpos($source, 'public function startFreepbx');
$stop = strpos($source, 'public function stopFreepbx');
$afterStop = strpos($source, 'public function startAlertMonitor', $stop);
demo_lifecycle_assert(strpos(substr($source, $start, $stop - $start), 'DEMO_ACCESS_KEY') === false && strpos(substr($source, $stop, $afterStop - $stop), 'DEMO_ACCESS_KEY') === false, 'Service lifecycle hooks must not independently reset Demo access.');


echo "Demo access lifecycle tests passed\n";
