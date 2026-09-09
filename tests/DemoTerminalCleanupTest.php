<?php

if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';

function terminal_cleanup_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

class TerminalCleanupConcurrencycount extends \FreePBX\modules\Concurrencycount { public function __construct() {} }
class TerminalCleanupGuard { public function checkpoint() { throw new RuntimeException('simulated resource failure'); } }
class TerminalCleanupDb {
	public $rows = [];
	public $deleteAttempts = 0;
	public $exec = [];
	public function query($sql) { return new TerminalCleanupQuery($sql); }
	public function exec($sql) { $this->exec[] = $sql; return 0; }
	public function prepare($sql) { if (strpos($sql, 'DELETE') === 0) $this->deleteAttempts++; return new TerminalCleanupDelete($this, $sql); }
}
class TerminalCleanupQuery {
	private $sql;
	public function __construct($sql) { $this->sql = $sql; }
	public function fetchColumn() { return '8.0.36'; }
	public function fetchAll($mode = null) { return [['INDEX_NAME' => 'accountcode', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'accountcode', 'SUB_PART' => null]]; }
}
class TerminalCleanupDelete {
	private $db;
	private $sql;
	private $affected = 0;
	public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute($params) {
		$accountcode = $params[':accountcode_exact'];
		preg_match('/LIMIT (\d+)$/', $this->sql, $match);
		$limit = (int)$match[1];
		$this->affected = min($limit, $this->db->rows[$accountcode] ?? 0);
		$this->db->rows[$accountcode] = ($this->db->rows[$accountcode] ?? 0) - $this->affected;
	}
	public function rowCount() { return $this->affected; }
}

function terminal_cleanup_set($cc, string $name, $value): void {
	$property = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name);
	$property->setAccessible(true);
	$property->setValue($cc, $value);
}

function terminal_cleanup_run(string $accountcode, callable $terminalSetup): void {
	$cc = new TerminalCleanupConcurrencycount();
	$db = new TerminalCleanupDb();
	$db->rows[$accountcode] = 3;
	terminal_cleanup_set($cc, 'cdrdb', $db);
	$terminalSetup($cc);
	$cleanup = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'cleanupDemoCdrRows');
	$cleanup->setAccessible(true);
	$result = $cleanup->invoke($cc, $accountcode);
	terminal_cleanup_assert($db->deleteAttempts > 0, 'Terminal Demo cleanup must attempt an exact DELETE for ' . $accountcode);
	terminal_cleanup_assert($db->rows[$accountcode] === 0 && $result['cleanup_remaining'] === 0, 'Terminal Demo cleanup must remove inserted rows for ' . $accountcode);
}

terminal_cleanup_run('CCDEMOaaaaaaaa', function ($cc) {
	terminal_cleanup_set($cc, 'workerCancellation', function () { return true; });
});
terminal_cleanup_run('CCDEMObbbbbbbb', function ($cc) {
	$expired = \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now() - 3601;
	terminal_cleanup_set($cc, 'workerRuntime', new \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator(3600, $expired, $expired, true));
});
terminal_cleanup_run('CCDEMOcccccccc', function ($cc) {
	terminal_cleanup_set($cc, 'workerGuard', new TerminalCleanupGuard());
});

$source = file_get_contents(__DIR__ . '/../Concurrencycount.class.php');
terminal_cleanup_assert(strpos($source, 'const MAX_RUNTIME = 3600;') !== false, 'Normal Historical logical runtime must remain 3,600 seconds');
terminal_cleanup_assert(strpos($source, 'DEMO_CLEANUP_MAX_RUNTIME = 300') !== false && strpos($source, 'DEMO_CLEANUP_PHP_MARGIN = 30') !== false, 'Demo cleanup must have a bounded five-minute logical budget and explicit PHP margin');
terminal_cleanup_assert(strpos($source, 'set_time_limit(($this->workerControl !== null ? 86400 : self::MAX_RUNTIME) + self::DEMO_CLEANUP_MAX_RUNTIME + self::DEMO_CLEANUP_PHP_MARGIN)') !== false, 'Demo-capable requests need PHP headroom beyond their logical calculation allowance');
terminal_cleanup_assert(strpos($source, 'set_time_limit(self::DEMO_CLEANUP_MAX_RUNTIME + self::DEMO_CLEANUP_PHP_MARGIN)') !== false, 'Entering mandatory cleanup must restart a separate bounded PHP execution allowance');
terminal_cleanup_assert(strpos($source, 'set_time_limit(0)') === false, 'Unlimited PHP execution time must not be introduced');
$controlSource = file_get_contents(__DIR__ . '/../Services/HistoricalCalculationControl.php');
terminal_cleanup_assert(strpos($controlSource, '$allowance > 86400') !== false, 'GUI logical runtime maximum must remain 86,400 seconds');

echo "Demo terminal cleanup tests passed\n";
