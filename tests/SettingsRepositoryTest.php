<?php

require_once __DIR__ . '/../Services/SettingsRepository.php';

use FreePBX\modules\Concurrencycount\Services\SettingsRepository;

function repository_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

class FakeSettingsDatabase {
	public $values = [];
	public $installed = false;
	private $backup = null;
	public function exec($sql) {
		if (strpos($sql, 'CREATE TABLE') !== false) $this->installed = true;
		if (strpos($sql, 'DROP TABLE') !== false) { $this->installed = false; $this->values = []; }
		return true;
	}
	public function prepare($sql) { return new FakeSettingsStatement($this, $sql); }
	public function beginTransaction() { $this->backup = $this->values; return true; }
	public function commit() { $this->backup = null; return true; }
	public function inTransaction() { return $this->backup !== null; }
	public function rollBack() { if ($this->backup !== null) $this->values = $this->backup; $this->backup = null; return true; }
}

class FakeSettingsStatement {
	private $db;
	private $sql;
	private $result = false;
	public function __construct(FakeSettingsDatabase $db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute(array $params) {
		$key = $params[':key'];
		if (strpos($this->sql, 'SELECT') === 0) $this->result = isset($this->db->values[$key]) ? $this->db->values[$key] : false;
		elseif (strpos($this->sql, 'INSERT') === 0) $this->db->values[$key] = $params[':value'];
		elseif (strpos($this->sql, 'DELETE') === 0) unset($this->db->values[$key]);
		return true;
	}
	public function fetchColumn() { return $this->result; }
}

$db = new FakeSettingsDatabase();
$repository = new SettingsRepository($db);
$repository->install();
repository_assert($db->installed, 'Repository install creates its table');
$settings = ['refresh_interval' => 5, 'alerts_enabled' => false, 'hidden_trunks' => ['gamma'], 'trunks' => ['gamma' => ['threshold' => 8]]];
$repository->set('live_settings', $settings);
repository_assert($repository->get('live_settings', []) === $settings, 'Repository round-trips settings');
repository_assert($repository->get('live_settings', [])['hidden_trunks'] === ['gamma'], 'Hidden trunks survive save and reload without removing configured trunk settings');
$settings['hidden_trunks'] = [];
$repository->set('live_settings', $settings);
repository_assert($repository->get('live_settings', [])['hidden_trunks'] === [] && isset($repository->get('live_settings', [])['trunks']['gamma']), 'Unhide survives save and reload while retaining configured trunk settings');
$state = ['overall' => ['status' => 'above', 'peak' => 9]];
$repository->set('alert_state', $state);
repository_assert($repository->get('alert_state', []) === $state, 'Repository round-trips alert state');
$outbox = ['event-1' => ['type' => 'alert', 'scope' => 'overall']];
$repository->transaction(function (SettingsRepository $transaction) use ($state, $outbox): void {
	$transaction->set('alert_state', $state);
	$transaction->set('alert_outbox', $outbox);
});
repository_assert($repository->get('alert_state', []) === $state && $repository->get('alert_outbox', []) === $outbox, 'State and outbox commit atomically');
$rolledBack = false;
try {
	$repository->transaction(function (SettingsRepository $transaction): void {
		$transaction->set('alert_state', ['partial' => true]);
		throw new RuntimeException('force rollback');
	});
} catch (RuntimeException $exception) { $rolledBack = true; }
repository_assert($rolledBack && $repository->get('alert_state', []) === $state, 'Failed transition rolls back partial state');
$repository->delete('alert_state');
repository_assert($repository->get('alert_state', ['missing' => true]) === ['missing' => true], 'Repository delete restores default');
$repository->uninstall();
repository_assert(!$db->installed && $db->values === [], 'Repository uninstall removes owned persistence');

echo "Settings repository tests passed\n";
