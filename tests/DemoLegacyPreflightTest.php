<?php

if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';

function legacy_preflight_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

class LegacyPreflightConcurrencycount extends \FreePBX\modules\Concurrencycount { public function __construct() {} }
class LegacyPreflightStatement {
	private $db; private $sql; private $affected = 0;
	public function __construct($db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute($params = []) {
		if (strpos($this->sql, 'DELETE FROM cdr') === 0) {
			$this->db->deleteSql[] = $this->sql;
			preg_match('/LIMIT (\d+)$/', $this->sql, $match);
			$tag = $params[':accountcode_exact']; $this->affected = min((int)$match[1], $this->db->rows[$tag] ?? 0);
			$this->db->rows[$tag] = ($this->db->rows[$tag] ?? 0) - $this->affected;
		}
	}
	public function fetchColumn() {
		if ($this->sql === 'SELECT VERSION()') return $this->db->version;
		if (strpos($this->sql, 'SELECT ENGINE') === 0) return $this->db->engine;
		return false;
	}
	public function fetchAll($mode = null) { return $this->db->indexes; }
	public function fetch($mode = null) {
		if (strpos($this->sql, 'SELECT @@datadir') === 0) return ['datadir' => '/tmp', 'hostname' => gethostname(), 'log_bin' => 0, 'log_bin_basename' => ''];
		if (strpos($this->sql, 'SELECT DATA_LENGTH') === 0) return ['DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0, 'TABLE_ROWS' => 0];
		return false;
	}
	public function rowCount() { return $this->affected; }
}
class LegacyPreflightCdrDb {
	public $version = '5.5.65-MariaDB'; public $engine = 'InnoDB'; public $exec = []; public $queries = []; public $deleteSql = []; public $rows = ['CCDEMOaaaaaaaa' => 3]; public $transactions = 0;
	public $indexes = [['INDEX_NAME' => 'accountcode', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'accountcode', 'SUB_PART' => null]];
	public function query($sql) { $this->queries[] = $sql; return new LegacyPreflightStatement($this, $sql); }
	public function prepare($sql) { if (strpos($sql, 'INSERT INTO cdr') === 0) throw new RuntimeException('Preflight attempted synthetic insertion.'); return new LegacyPreflightStatement($this, $sql); }
	public function exec($sql) { $this->exec[] = $sql; return 0; }
	public function beginTransaction() { $this->transactions++; return true; }
}
class LegacySettingsStatement {
	private $db; private $sql; private $params = [];
	public function __construct($db, string $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute($params = []) { $this->params = $params; if (strpos($this->sql, 'DELETE FROM') === 0) unset($this->db->records[$params[':key']]); }
	public function fetchAll($mode = null) { return array_keys($this->db->records); }
	public function fetchColumn() { $key = $this->params[':key'] ?? ''; return isset($this->db->records[$key]) ? json_encode($this->db->records[$key]) : false; }
}
class LegacySettingsDb {
	public $records = ['demo_run:CCDEMOaaaaaaaa' => ['accountcode' => 'CCDEMOaaaaaaaa', 'updated_at' => 1]];
	public function prepare($sql) { return new LegacySettingsStatement($this, $sql); }
}

function legacy_preflight_set($cc, string $name, $value): void {
	$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $property->setAccessible(true); $property->setValue($cc, $value);
}

$cc = new LegacyPreflightConcurrencycount(); $cdrdb = new LegacyPreflightCdrDb(); $settingsDb = new LegacySettingsDb();
legacy_preflight_set($cc, 'cdrdb', $cdrdb);
legacy_preflight_set($cc, 'settingsRepository', new \FreePBX\modules\Concurrencycount\Services\SettingsRepository($settingsDb));
$_REQUEST = ['demo_size' => 'light', 'demo_rows' => 25];
$preflight = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'handleDemoPreflight'); $preflight->setAccessible(true);
$result = $preflight->invoke($cc);
legacy_preflight_assert($result['status'] === true, 'MariaDB 5.5 InnoDB must proceed through the actual Demo preflight path');
legacy_preflight_assert(in_array('SET SESSION lock_wait_timeout=2', $cdrdb->exec, true) && !in_array('SET SESSION max_statement_time=2', $cdrdb->exec, true), 'Legacy preflight must use lock deadlines without unsupported max_statement_time');
legacy_preflight_assert(count($cdrdb->deleteSql) > 0 && strpos($cdrdb->deleteSql[0], 'BINARY accountcode = :accountcode_exact LIMIT 100') !== false, 'Legacy preflight stale recovery must use the exact-tag 100-row fallback');
legacy_preflight_assert($cdrdb->rows['CCDEMOaaaaaaaa'] === 0 && empty($settingsDb->records), 'Legacy preflight must complete stale exact-tag recovery');
legacy_preflight_assert($cdrdb->transactions === 0, 'Demo preflight must not start a synthetic insertion transaction');

foreach ([false, 'MyISAM'] as $engine) {
	$unsafe = new LegacyPreflightConcurrencycount(); $unsafeDb = new LegacyPreflightCdrDb(); $unsafeDb->engine = $engine; $unsafeSettings = new LegacySettingsDb();
	legacy_preflight_set($unsafe, 'cdrdb', $unsafeDb); legacy_preflight_set($unsafe, 'settingsRepository', new \FreePBX\modules\Concurrencycount\Services\SettingsRepository($unsafeSettings));
	$unsafeResult = $preflight->invoke($unsafe);
	legacy_preflight_assert($unsafeResult['status'] === false && count($unsafeDb->deleteSql) === 0 && $unsafeDb->transactions === 0 && !empty($unsafeSettings->records), 'Unknown/non-InnoDB legacy storage must fail before stale cleanup or insertion');
}

echo "Demo legacy preflight tests passed\n";
