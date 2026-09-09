<?php
require_once __DIR__ . '/../Services/DemoCleanupService.php';
require_once __DIR__ . '/../Services/DemoCleanupCoordinator.php';
require_once __DIR__ . '/../Services/DemoCleanupHeartbeat.php';
use FreePBX\modules\Concurrencycount\Services\DemoCleanupService;
use FreePBX\modules\Concurrencycount\Services\DemoCleanupCoordinator;
use FreePBX\modules\Concurrencycount\Services\DemoCleanupHeartbeat;
function cleanup_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
class CleanupDb {
	public $rows = [];
	public $limits = [];
	public $sql = [];
	public $indexes = [['INDEX_NAME' => 'accountcode', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'accountcode', 'SUB_PART' => null]];
	public $indexError = false;
	public $deleteAttempts = 0;
	public function query($sql) { if ($this->indexError) throw new RuntimeException('metadata unavailable'); return new CleanupIndexStmt($this); }
	public function prepare($sql) { $this->sql[] = $sql; if (strpos($sql, 'DELETE') === 0) $this->deleteAttempts++; return new CleanupStmt($this, $sql); }
}
class CleanupIndexStmt { private $db; public function __construct($db) { $this->db = $db; } public function fetchAll($mode = null) { return $this->db->indexes; } }
class CleanupStmt {
	private $db; private $sql; private $affected = 0; private $account = '';
	public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
	public function execute($params) {
		$this->account = isset($params[':accountcode_exact']) ? $params[':accountcode_exact'] : $params[':accountcode'];
		if (strpos($this->sql, 'DELETE') === 0) {
			preg_match('/LIMIT (\d+)$/', $this->sql, $m); $limit = (int)$m[1]; $this->db->limits[] = $limit;
			if ($limit > 125) throw new RuntimeException('statement timeout');
			$this->affected = min($limit, $this->db->rows[$this->account] ?? 0);
			$this->db->rows[$this->account] = ($this->db->rows[$this->account] ?? 0) - $this->affected;
		}
	}
	public function rowCount() { return $this->affected; }
	public function fetchColumn() { return $this->db->rows[$this->account] ?? 0; }
}
class CleanupRepository {
	public $records;
	public function __construct($records) { $this->records = $records; }
	public function findKeys($prefix) { return array_values(array_filter(array_keys($this->records), function ($key) use ($prefix) { return strpos($key, $prefix) === 0; })); }
	public function get($key, $default = null) { return $this->records[$key] ?? $default; }
	public function set($key, $value) { $this->records[$key] = $value; }
	public function delete($key) { unset($this->records[$key]); }
}
$db = new CleanupDb(); $db->rows['CCDEMO1234abcd'] = 301;
$cleanupCheckpoints = 0;
$service = new DemoCleanupService($db, function ($e) { return $e->getMessage() === 'statement timeout'; }, function () use (&$cleanupCheckpoints) { $cleanupCheckpoints++; });
$result = $service->cleanup('CCDEMO1234abcd');
cleanup_assert($result === ['rows_removed' => 301, 'cleanup_remaining' => 0], 'Cleanup removes and verifies every exact-tag row');
cleanup_assert($db->limits === [1000, 500, 250, 125, 125, 125], 'Timed-out cleanup deterministically halves to a successful bounded batch');
cleanup_assert($cleanupCheckpoints >= 8, 'Adaptive cleanup retains runtime, memory and cancellation checkpoints between bounded attempts');
cleanup_assert(strpos($db->sql[0], 'accountcode = :accountcode_index AND BINARY accountcode = :accountcode_exact') !== false, 'Cleanup uses the leading accountcode index candidate while retaining case-exact tag protection');
$db->rows = ['CCDEMOaaaaaaaa' => 7, 'CCDEMObbbbbbbb' => 9]; $db->limits = [];
$repo = new CleanupRepository([
	'demo_run:CCDEMOaaaaaaaa' => ['accountcode' => 'CCDEMOaaaaaaaa', 'updated_at' => 100],
	'demo_run:CCDEMObbbbbbbb' => ['accountcode' => 'CCDEMObbbbbbbb', 'updated_at' => 950],
	'demo_run:wrong-key' => ['accountcode' => 'CCDEMOcccccccc', 'updated_at' => 100],
]);
cleanup_assert($service->recover($repo, 1000, 100) === 1, 'Only a stale exact registry entry is recovered');
cleanup_assert(($db->rows['CCDEMOaaaaaaaa'] ?? -1) === 0 && $db->rows['CCDEMObbbbbbbb'] === 9, 'Recovery leaves fresh tags untouched');
cleanup_assert(isset($repo->records['demo_run:CCDEMObbbbbbbb']) && !isset($repo->records['demo_run:wrong-key']), 'Recovery retains fresh state and removes malformed metadata without deleting its claimed rows');
$invalid = false; try { $service->cleanup('CCDEMO%'); } catch (InvalidArgumentException $e) { $invalid = true; }
cleanup_assert($invalid, 'Cleanup refuses non-exact identifiers');
cleanup_assert(DemoCleanupService::isReservedAccountcode('CCDEMO1234abcd') && !DemoCleanupService::isReservedAccountcode('customer-CCDEMO1234abcd') && !DemoCleanupService::isReservedAccountcode('CCDEMO1234ABCD'), 'Only the exact reserved synthetic accountcode pattern is excluded');
$ordinary = DemoCleanupService::excludeReservedRows([['accountcode' => 'customer'], ['accountcode' => 'CCDEMO1234abcd'], ['accountcode' => 'CCDEMO1234ABCD']]);
cleanup_assert(array_column($ordinary, 'accountcode') === ['customer', 'CCDEMO1234ABCD'] && strpos(DemoCleanupService::ordinarySqlPredicate(), 'NOT REGEXP') !== false, 'Ordinary acquisition excludes exact reserved Demo rows both in SQL and after fetch');

foreach ([
	'absent' => [],
	'non-leading' => [['INDEX_NAME' => 'calldate_accountcode', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'calldate', 'SUB_PART' => null], ['INDEX_NAME' => 'calldate_accountcode', 'SEQ_IN_INDEX' => 2, 'COLUMN_NAME' => 'accountcode', 'SUB_PART' => null]],
	'short-prefix' => [['INDEX_NAME' => 'accountcode_prefix', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'accountcode', 'SUB_PART' => 8]],
] as $case => $indexes) {
	$unsafeDb = new CleanupDb(); $unsafeDb->indexes = $indexes; $unsafeDb->rows['CCDEMOaaaaaaaa'] = 4;
	$unsafeRepo = new CleanupRepository(['demo_run:CCDEMOaaaaaaaa' => ['accountcode' => 'CCDEMOaaaaaaaa', 'updated_at' => 100]]);
	$failed = false;
	try { (new DemoCleanupCoordinator($unsafeDb, function () { return false; }))->recover($unsafeRepo, 1000, 100); } catch (RuntimeException $e) { $failed = true; }
	cleanup_assert($failed && $unsafeDb->deleteAttempts === 0 && $unsafeDb->rows['CCDEMOaaaaaaaa'] === 4 && isset($unsafeRepo->records['demo_run:CCDEMOaaaaaaaa']), 'Unsafe stale recovery performs zero DELETE statements and retains registry state: ' . $case);
}
$metadataDb = new CleanupDb(); $metadataDb->indexError = true; $metadataDb->rows['CCDEMOaaaaaaaa'] = 4;
$metadataRepo = new CleanupRepository(['demo_run:CCDEMOaaaaaaaa' => ['accountcode' => 'CCDEMOaaaaaaaa', 'updated_at' => 100]]);
$metadataFailed = false;
try { (new DemoCleanupCoordinator($metadataDb, function () { return false; }))->recover($metadataRepo, 1000, 100); } catch (RuntimeException $e) { $metadataFailed = true; }
cleanup_assert($metadataFailed && $metadataDb->deleteAttempts === 0 && isset($metadataRepo->records['demo_run:CCDEMOaaaaaaaa']), 'Unavailable index metadata performs zero DELETE statements and retains stale registry state');
$safeDb = new CleanupDb(); $safeDb->rows['CCDEMOaaaaaaaa'] = 4;
$safeRepo = new CleanupRepository(['demo_run:CCDEMOaaaaaaaa' => ['accountcode' => 'CCDEMOaaaaaaaa', 'updated_at' => 100]]);
$recovered = (new DemoCleanupCoordinator($safeDb, function ($e) { return $e->getMessage() === 'statement timeout'; }))->recover($safeRepo, 1000, 100);
cleanup_assert($recovered === 1 && $safeDb->deleteAttempts > 0 && $safeDb->rows['CCDEMOaaaaaaaa'] === 0 && !isset($safeRepo->records['demo_run:CCDEMOaaaaaaaa']), 'Valid leading accountcode index permits successful stale recovery and registry removal');

$heartbeatNow = 400;
$activeDb = new CleanupDb(); $activeDb->rows['CCDEMOdddddddd'] = 4;
$activeRepo = new CleanupRepository(['demo_run:CCDEMOdddddddd' => ['accountcode' => 'CCDEMOdddddddd', 'updated_at' => 100]]);
$heartbeat = new DemoCleanupHeartbeat($activeRepo, 'demo_run:CCDEMOdddddddd', 'CCDEMOdddddddd', function () use (&$heartbeatNow) { return $heartbeatNow; });
$heartbeat->checkpoint();
cleanup_assert($activeRepo->records['demo_run:CCDEMOdddddddd']['updated_at'] === 400 && $activeRepo->records['demo_run:CCDEMOdddddddd']['cleanup_active'] === true, 'Active cleanup immediately refreshes and marks its registry heartbeat');
$heartbeatNow = 405; $heartbeat->checkpoint();
cleanup_assert($activeRepo->records['demo_run:CCDEMOdddddddd']['updated_at'] === 400, 'Cleanup heartbeat writes are bounded by their cadence');
$heartbeatNow = 411; $heartbeat->checkpoint();
cleanup_assert($activeRepo->records['demo_run:CCDEMOdddddddd']['updated_at'] === 411, 'A long active cleanup refreshes its registry heartbeat');
$freshRecovery = (new DemoCleanupCoordinator($activeDb, function ($e) { return $e->getMessage() === 'statement timeout'; }))->recover($activeRepo, 710, 300);
cleanup_assert($freshRecovery === 0 && $activeDb->deleteAttempts === 0 && $activeDb->rows['CCDEMOdddddddd'] === 4, 'Recovery performs zero DELETEs while the active cleanup heartbeat is fresh');
$staleRecovery = (new DemoCleanupCoordinator($activeDb, function ($e) { return $e->getMessage() === 'statement timeout'; }))->recover($activeRepo, 712, 300);
cleanup_assert($staleRecovery === 1 && $activeDb->deleteAttempts > 0 && !isset($activeRepo->records['demo_run:CCDEMOdddddddd']), 'Recovery becomes eligible after five minutes without a cleanup heartbeat and removes the registry on success');
$failedDb = new CleanupDb(); $failedDb->rows['CCDEMOeeeeeeee'] = 4;
$failedRepo = new CleanupRepository(['demo_run:CCDEMOeeeeeeee' => ['accountcode' => 'CCDEMOeeeeeeee', 'updated_at' => 100]]);
$failedCleanup = false;
try { (new DemoCleanupCoordinator($failedDb, function () { return false; }))->recover($failedRepo, 1000, 300); } catch (RuntimeException $e) { $failedCleanup = true; }
cleanup_assert($failedCleanup && $failedDb->deleteAttempts > 0 && isset($failedRepo->records['demo_run:CCDEMOeeeeeeee']), 'A genuine cleanup DELETE failure retains its registry for later recovery');
echo "Demo cleanup service tests passed\n";
