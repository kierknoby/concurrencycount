<?php

namespace FreePBX\modules\Concurrencycount\Services {
	// Keep unrelated host disk utilisation from deciding this terminal cleanup test.
	function disk_total_space(string $path) { return 100 * 1024 * 1024 * 1024; }
	function disk_free_space(string $path) { return 80 * 1024 * 1024 * 1024; }
}

namespace {

if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalResourceLimitException;
use FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator;
use FreePBX\modules\Concurrencycount\Services\PjsipIdentityService;
use FreePBX\modules\Concurrencycount\Services\SettingsRepository;

function demo_lifecycle_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

function demo_lifecycle_set($target, string $name, $value): void {
	$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name);
	$property->setAccessible(true);
	$property->setValue($target, $value);
}

class DemoLifecycleConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public function __construct() {}
	public function getTrunks(): array { return ['carrier']; }
}

class DemoLifecycleDb {
	public $rows = [];
	public $settings = [];
	public $deleteAttempts = 0;
	public $failCleanup = false;
	public $afterCommit;
	private $transaction = false;

	public function query($sql) { return new DemoLifecycleStatement($this, $sql); }
	public function prepare($sql) { return new DemoLifecycleStatement($this, $sql); }
	public function exec($sql) { return 0; }
	public function beginTransaction() { $this->transaction = true; return true; }
	public function inTransaction() { return $this->transaction; }
	public function commit() {
		$this->transaction = false;
		if ($this->afterCommit !== null) call_user_func($this->afterCommit);
		return true;
	}
	public function rollBack() { $this->transaction = false; return true; }
	public function totalRows(): int { return array_sum($this->rows); }
	public function registryKeys(): array {
		return array_values(array_filter(array_keys($this->settings), function ($key) {
			return strpos($key, 'demo_run:') === 0;
		}));
	}
}

class DemoLifecycleStatement {
	private $db;
	private $sql;
	private $result = [];
	private $affected = 0;
	private $cursor = 0;

	public function __construct(DemoLifecycleDb $db, string $sql) { $this->db = $db; $this->sql = $sql; }

	public function execute($params = []) {
		if (strpos($this->sql, 'INSERT INTO `concurrencycount_settings`') === 0) {
			$this->db->settings[$params[':key']] = $params[':value'];
		} elseif (strpos($this->sql, 'DELETE FROM `concurrencycount_settings`') === 0) {
			unset($this->db->settings[$params[':key']]);
		} elseif (strpos($this->sql, 'SELECT setting_value') === 0) {
			$this->result = array_key_exists($params[':key'], $this->db->settings) ? [[$this->db->settings[$params[':key']]]] : [];
		} elseif (strpos($this->sql, 'SELECT setting_key') === 0) {
			$prefix = substr($params[':prefix'], 0, -1);
			$this->result = [];
			foreach (array_keys($this->db->settings) as $key) if (strpos($key, $prefix) === 0) $this->result[] = [$key];
		} elseif (strpos($this->sql, 'INSERT INTO cdr ') === 0) {
			$accountcode = '';
			foreach ($params as $value) if (is_string($value) && preg_match('/^CCDEMO[0-9a-f]{8}$/', $value)) $accountcode = $value;
			if ($accountcode === '') throw new RuntimeException('Synthetic row did not retain its reserved accountcode.');
			$this->db->rows[$accountcode] = ($this->db->rows[$accountcode] ?? 0) + 1;
		} elseif (strpos($this->sql, 'DELETE FROM cdr ') === 0) {
			$this->db->deleteAttempts++;
			if ($this->db->failCleanup) throw new RuntimeException('simulated cleanup failure');
			$accountcode = $params[':accountcode_exact'];
			preg_match('/LIMIT (\d+)$/', $this->sql, $match);
			$limit = (int)$match[1];
			$this->affected = min($limit, $this->db->rows[$accountcode] ?? 0);
			$this->db->rows[$accountcode] = ($this->db->rows[$accountcode] ?? 0) - $this->affected;
		}
		return true;
	}

	public function fetchColumn() {
		if (strpos($this->sql, 'SELECT VERSION()') === 0) return '10.6.18-MariaDB';
		if (empty($this->result)) return false;
		$row = array_shift($this->result);
		return reset($row);
	}
	public function fetchAll($mode = null) {
		if (strpos($this->sql, 'information_schema.STATISTICS') !== false) return [['INDEX_NAME'=>'accountcode','SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'accountcode','SUB_PART'=>null]];
		if (strpos($this->sql, 'SHOW COLUMNS FROM cdr') === 0) {
			return array_map(function ($field) { return ['Field'=>$field,'Type'=>'varchar(255)','Null'=>'YES','Default'=>null,'Extra'=>'']; }, ['calldate','duration','disposition','src','dst','channel','dstchannel','accountcode','uniqueid','linkedid']);
		}
		if ($mode === \PDO::FETCH_COLUMN) return array_map(function ($row) { return reset($row); }, $this->result);
		return $this->result;
	}
	public function fetch($mode = null) {
		if (strpos($this->sql, 'SELECT @@datadir') === 0) return ['datadir'=>'/tmp','hostname'=>gethostname(),'log_bin'=>0];
		if (strpos($this->sql, 'SELECT DATA_LENGTH') === 0) return ['DATA_LENGTH'=>0,'INDEX_LENGTH'=>0,'TABLE_ROWS'=>0];
		if (!isset($this->result[$this->cursor])) return false;
		return $this->result[$this->cursor++];
	}
	public function rowCount() { return $this->affected; }
	public function closeCursor() { return true; }
}

class DemoLifecycleMemoryGuard {
	private $db;
	public function __construct(DemoLifecycleDb $db) { $this->db = $db; }
	public function checkpoint() {
		if ($this->db->totalRows() > 0) throw new HistoricalResourceLimitException(95, 90, 100);
	}
}

function run_demo_terminal_case(string $case): DemoLifecycleDb {
	$cc = new DemoLifecycleConcurrencycount();
	$db = new DemoLifecycleDb();
	demo_lifecycle_set($cc, 'cdrdb', $db);
	demo_lifecycle_set($cc, 'settingsRepository', new SettingsRepository($db));
	demo_lifecycle_set($cc, 'pjsipIdentityService', new PjsipIdentityService(['carrier'=>['channelid'=>'carrier']], ['2001'=>['id'=>'2001'],'2002'=>['id'=>'2002']], []));

	if ($case === 'cancellation') {
		demo_lifecycle_set($cc, 'workerCancellation', function () use ($db) { return $db->totalRows() > 0; });
	} elseif ($case === 'memory') {
		demo_lifecycle_set($cc, 'workerGuard', new DemoLifecycleMemoryGuard($db));
	} elseif ($case === 'runtime') {
		$db->afterCommit = function () use ($cc) {
			$expired = HistoricalRuntimeEstimator::now() - 3601;
			demo_lifecycle_set($cc, 'workerRuntime', new HistoricalRuntimeEstimator(3600, $expired, $expired, true));
			demo_lifecycle_set($cc, 'workerLast', -INF);
		};
	} elseif ($case === 'cleanup-failure') {
		demo_lifecycle_set($cc, 'workerCancellation', function () use ($db) { return $db->totalRows() > 0; });
		$db->failCleanup = true;
	}

	$calculate = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'calculateDemo');
	$calculate->setAccessible(true);
	$failed = false;
	try {
		$calculate->invoke($cc, '2026-01-01 00:00:00', '2026-01-02 00:00:00', HistoricalRuntimeEstimator::now(), [
			'demo_size'=>'light', 'demo_rows'=>1000, 'demo_report'=>'extension', 'demo_engines'=>['original'],
			'demo_token'=>'00112233445566778899aabbccddeeff', 'demo_generation'=>1,
		]);
	} catch (Throwable $exception) {
		$failed = true;
	}
	demo_lifecycle_assert($failed, ucfirst($case) . ' must terminate the Demo calculation.');
	return $db;
}

foreach (['cancellation', 'memory', 'runtime'] as $case) {
	$db = run_demo_terminal_case($case);
	demo_lifecycle_assert($db->deleteAttempts > 0, ucfirst($case) . ' must enter mandatory cleanup after rows are committed.');
	demo_lifecycle_assert($db->totalRows() === 0, ucfirst($case) . ' cleanup must leave zero reserved rows.');
	demo_lifecycle_assert($db->registryKeys() === [], ucfirst($case) . ' cleanup must remove the successful run registry entry.');
}

$failedCleanupDb = run_demo_terminal_case('cleanup-failure');
demo_lifecycle_assert($failedCleanupDb->deleteAttempts > 0, 'A genuine cleanup failure must still attempt DELETE.');
demo_lifecycle_assert($failedCleanupDb->totalRows() > 0, 'The cleanup-failure fixture must retain recoverable reserved rows.');
demo_lifecycle_assert(count($failedCleanupDb->registryKeys()) === 1, 'A genuine cleanup failure must preserve its stale-recovery registry entry.');

echo "Demo lifecycle cleanup tests passed\n";
}
