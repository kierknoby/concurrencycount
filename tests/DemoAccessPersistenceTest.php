<?php

require_once __DIR__ . '/../Services/SettingsRepository.php';

use FreePBX\modules\Concurrencycount\Services\SettingsRepository;

function demo_access_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

class DemoAccessDatabase {
	public $values = [];
	public function exec($sql) { return true; }
	public function prepare($sql) { return new DemoAccessStatement($this, $sql); }
}

class DemoAccessStatement {
	private $db;
	private $sql;
	private $result = false;
	public function __construct(DemoAccessDatabase $db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute(array $params) {
		$key = (string)($params[':key'] ?? '');
		if (strpos($this->sql, 'SELECT 1') === 0) $this->result = array_key_exists($key, $this->db->values) ? 1 : false;
		elseif (strpos($this->sql, 'SELECT setting_value') === 0) $this->result = array_key_exists($key, $this->db->values) ? $this->db->values[$key] : false;
		elseif (strpos($this->sql, 'INSERT') === 0) $this->db->values[$key] = $params[':value'];
		return true;
	}
	public function fetchColumn() { return $this->result; }
}

$db = new DemoAccessDatabase();
$repository = new SettingsRepository($db);
$repository->install();
$repository->initialize('demo_access', false);
demo_access_assert($repository->get('demo_access', null) === false, 'Fresh install must initialize Demo access to DISABLED.');

$repository->initialize('demo_access', true);
demo_access_assert($repository->get('demo_access', null) === false, 'An update must preserve an existing DISABLED value.');

$db->values['demo_access'] = 'true';
$repository->initialize('demo_access', false);
demo_access_assert($repository->get('demo_access', null) === true, 'An update must preserve an existing ENABLED value.');

echo "Demo access persistence tests passed\n";
