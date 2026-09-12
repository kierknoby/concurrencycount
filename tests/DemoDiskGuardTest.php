<?php
require_once __DIR__ . '/../Services/DemoDiskGuard.php';
use FreePBX\modules\Concurrencycount\Services\DemoDiskGuard;
function disk_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
class DiskDb { public $host; public $stats; public $logBin; public $logPath; public $indexes; public $indexError; public $queries = []; public function __construct($stats = null, $logBin = 0, $logPath = '', $indexes = null, $indexError = false) { $this->host = gethostname(); $this->stats = $stats; $this->logBin = $logBin; $this->logPath = $logPath; $this->indexes = $indexes === null ? [['INDEX_NAME' => 'accountcode', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'accountcode']] : $indexes; $this->indexError = $indexError; } public function query($sql) { $this->queries[] = $sql; if (strpos($sql, '@@log_bin_basename') !== false) throw new RuntimeException("Unknown system variable 'log_bin_basename'"); if ($this->indexError && strpos($sql, 'information_schema.STATISTICS') !== false) throw new RuntimeException('metadata unavailable'); return new DiskStmt($this, $sql); } }
class DiskStmt { private $db; private $sql; public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; } public function fetch($mode = null) { if (strpos($this->sql, 'information_schema.TABLES') !== false) return $this->db->stats; if (strpos($this->sql, 'SHOW VARIABLES') === 0) return $this->db->logPath === '' ? false : ['Variable_name' => 'log_bin_basename', 'Value' => $this->db->logPath]; return ['datadir' => '/db', 'hostname' => $this->db->host, 'log_bin' => $this->db->logBin]; } public function fetchAll($mode = null) { return $this->db->indexes; } }
$db = new DiskDb(); $free = 20 * 1024 * 1024 * 1024;
$space = function ($path) use (&$free) { return ['path' => $path, 'device' => 1, 'free' => $free, 'total' => 40 * 1024 * 1024 * 1024]; };
$guard = new DemoDiskGuard($db, $space); $plan = $guard->preflight(1000);
disk_assert(count(array_filter($db->queries, function ($sql) { return strpos($sql, '@@log_bin_basename') !== false || strpos($sql, 'SHOW VARIABLES') === 0; })) === 0, 'MariaDB 5.5 with binary logging off must not query log_bin_basename');
disk_assert($plan['rows'] === 1000, 'A leading accountcode index permits Demo preflight');
disk_assert($plan['reserve_bytes'] === 8 * 1024 * 1024 * 1024 && $plan['available_bytes'] === 12 * 1024 * 1024 * 1024, '20% reserve is applied to the database filesystem');
disk_assert($plan['estimated_bytes_per_row'] === 16384 && $plan['estimate_source'] === 'conservative_floor', 'Missing table statistics use the conservative floor');
$statsDb = new DiskDb(['DATA_LENGTH' => 1000000, 'INDEX_LENGTH' => 1000000, 'TABLE_ROWS' => 100], 1, '/db/mysql-bin');
$statsGuard = new DemoDiskGuard($statsDb, $space);
$statsPlan = $statsGuard->preflight(1000);
disk_assert($statsPlan['estimated_bytes_per_row'] === 160000 && $statsPlan['estimate_source'] === 'cdr_table_statistics', 'Table allocation, uncertainty and binary logging raise the row estimate');
disk_assert($statsPlan['binary_log'] === null && $statsPlan['data_required_bytes'] === $statsPlan['required_bytes'], 'Same-filesystem binary logging is protected by the database filesystem plan');
disk_assert(in_array("SHOW VARIABLES LIKE 'log_bin_basename'", $statsDb->queries, true) && count(array_filter($statsDb->queries, function ($sql) { return strpos($sql, '@@log_bin_basename') !== false; })) === 0, 'Enabled binary logging must use a capability-safe basename probe');
$separateSpace = function ($path) { return $path === '/logs' ? ['path' => '/logs', 'device' => 2, 'free' => 3 * 1024 * 1024 * 1024, 'total' => 10 * 1024 * 1024 * 1024] : ['path' => '/db', 'device' => 1, 'free' => 20 * 1024 * 1024 * 1024, 'total' => 40 * 1024 * 1024 * 1024]; };
$separatePlan = (new DemoDiskGuard(new DiskDb(null, 1, '/logs/mysql-bin'), $separateSpace))->preflight(1000);
disk_assert($separatePlan['binary_log']['device'] === 2 && $separatePlan['binary_log']['reserve_bytes'] === 2 * 1024 * 1024 * 1024, 'Separate binary-log filesystem receives its own reserve and requirement');
$unsafeLog = false; try { (new DemoDiskGuard(new DiskDb(null, 1, '/logs/mysql-bin'), function ($path) { return $path === '/logs' ? ['path' => '/logs', 'device' => 2, 'free' => 1024, 'total' => 10 * 1024 * 1024 * 1024] : ['path' => '/db', 'device' => 1, 'free' => 20 * 1024 * 1024 * 1024, 'total' => 40 * 1024 * 1024 * 1024]; }))->preflight(1); } catch (RuntimeException $e) { $unsafeLog = true; }
disk_assert($unsafeLog, 'Unsafe separate binary-log filesystem refuses Demo before insertion');
$unknownLog = false; try { (new DemoDiskGuard(new DiskDb(null, 1, ''), $space))->preflight(1); } catch (RuntimeException $e) { $unknownLog = true; }
disk_assert($unknownLog, 'Enabled binary logging with no resolvable location fails closed');
$guard->check(0); $free -= 1024 * 1024; $checked = $guard->check(100);
disk_assert($checked['peak_storage_growth_bytes'] === 1024 * 1024, 'Batch checks track actual growth');
$unsafe = false; try { (new DemoDiskGuard($db, function () { return ['path' => '/db', 'free' => 1024, 'total' => 1024]; }))->preflight(1); } catch (RuntimeException $e) { $unsafe = true; }
disk_assert($unsafe, 'Unsafe preflight refuses before insertion');
$missing = false; try { (new DemoDiskGuard($db, function () { return []; }))->preflight(1); } catch (RuntimeException $e) { $missing = true; }
disk_assert($missing, 'Unavailable disk data fails conservatively');
$smallPlan = (new DemoDiskGuard($db, function () { return ['path' => '/db', 'device' => 1, 'free' => 1500 * 1024 * 1024, 'total' => 2 * 1024 * 1024 * 1024]; }))->preflight(1);
disk_assert($smallPlan['reserve_bytes'] === 1024 * 1024 * 1024, 'One GiB absolute reserve protects small filesystems');
$overflow = false; try { $guard->preflight(PHP_INT_MAX); } catch (InvalidArgumentException $e) { $overflow = true; }
disk_assert($overflow, 'Overflowing row counts fail safely');
$missingIndex = false; try { (new DemoDiskGuard(new DiskDb(null, 0, '', []), $space))->preflight(1); } catch (RuntimeException $e) { $missingIndex = true; }
disk_assert($missingIndex, 'Demo preflight fails before insertion when accountcode is absent from indexes');
$nonLeadingIndex = [['INDEX_NAME' => 'calldate_accountcode', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'calldate'], ['INDEX_NAME' => 'calldate_accountcode', 'SEQ_IN_INDEX' => 2, 'COLUMN_NAME' => 'accountcode']];
$nonLeading = false; try { (new DemoDiskGuard(new DiskDb(null, 0, '', $nonLeadingIndex), $space))->preflight(1); } catch (RuntimeException $e) { $nonLeading = true; }
disk_assert($nonLeading, 'An accountcode in a non-leading composite position is not a safe cleanup index');
$shortPrefix = false; try { (new DemoDiskGuard(new DiskDb(null, 0, '', [['INDEX_NAME' => 'accountcode_prefix', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'accountcode', 'SUB_PART' => 8]]), $space))->preflight(1); } catch (RuntimeException $e) { $shortPrefix = true; }
disk_assert($shortPrefix, 'An index prefix shorter than the reserved Demo tag cannot prove the exact cleanup access path');
$metadataUnavailable = false; try { (new DemoDiskGuard(new DiskDb(null, 0, '', null, true), $space))->preflight(1); } catch (RuntimeException $e) { $metadataUnavailable = true; }
disk_assert($metadataUnavailable, 'Unavailable index metadata fails closed');
echo "Demo disk guard tests passed\n";
