<?php
if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';
function no_controller_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
class NoControllerConcurrencycount extends \FreePBX\modules\Concurrencycount { public function __construct() {} }
class NoControllerGuard { public $checks = 0; public function checkpoint() { $this->checks++; } }
class NoControllerDeadlineDb { public $version; public $exec = []; public $queries = []; public $indexes; public function __construct($version, $indexes = null) { $this->version = $version; $this->indexes = $indexes === null ? [['INDEX_NAME' => 'calldate', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'calldate', 'SUB_PART' => null]] : $indexes; } public function query($sql) { $this->queries[] = $sql; return new NoControllerDeadlineStatement($this->version, $this->indexes); } public function exec($sql) { $this->exec[] = $sql; } }
class NoControllerDeadlineStatement { private $version; private $indexes; public function __construct($version, $indexes) { $this->version = $version; $this->indexes = $indexes; } public function fetchColumn() { return $this->version; } public function fetchAll($mode = null) { return $this->indexes; } }
$cc = new NoControllerConcurrencycount(); $guard = new NoControllerGuard();
$set = function ($name, $value) use ($cc) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($cc, $value); };
$set('workerGuard', $guard); $set('workerCancellation', function () { return false; }); $set('assessment', null); $set('workerControl', null);
$checkpoint = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'workerCheckpoint'); $checkpoint->setAccessible(true); $checkpoint->invoke($cc);
no_controller_assert($guard->checks === 1, 'No-controller checkpoints retain hard memory protection without PBX advisory state');
$set('workerRuntime', new \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator(3600, \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now() - 3601, \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now() - 3601, true));
$set('workerLast', -INF); $runtimeExpired = false;
try { $checkpoint->invoke($cc); } catch (ReflectionException $e) { throw $e; } catch (RuntimeException $e) { $runtimeExpired = strpos($e->getMessage(), 'maximum runtime') !== false; }
no_controller_assert($runtimeExpired, 'No-controller pre-engine checkpoints enforce the 3,600-second hard runtime');
$assessment = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, 'assessment'); $assessment->setAccessible(true);
no_controller_assert($assessment->getValue($cc) === null, 'No-controller runtime enforcement must not create PBX impact assessment or pause state');
$set('workerRuntime', null);
$set('workerCancellation', function () { return true; }); $cancelled = false;
try { $checkpoint->invoke($cc); } catch (ReflectionException $e) { throw $e; } catch (\FreePBX\modules\HistoricalCalculationCancelled $e) { $cancelled = true; }
no_controller_assert($cancelled, 'No-controller checkpoints retain cooperative CLI cancellation');
$source = file_get_contents(__DIR__ . '/../Concurrencycount.class.php');
no_controller_assert(strpos($source, "if (\$this->assessment !== null) \$this->configureHistoricalQueryDeadline()") === false, 'Native CDR query deadlines must not depend on GUI assessment');
no_controller_assert(strpos($source, "\$capabilities = \$this->configureHistoricalQueryDeadline();") !== false && strpos($source, "if (\$capabilities['adaptive_legacy_acquisition'])") !== false, 'Every shared CDR acquisition configures its database capability policy first');
$deadline = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'configureHistoricalQueryDeadline'); $deadline->setAccessible(true);
$maria = new NoControllerConcurrencycount(); $mariaDb = new NoControllerDeadlineDb('10.6.12-MariaDB');
$mariaSet = function ($name, $value) use ($maria) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($maria, $value); };
$mariaSet('cdrdb', $mariaDb); $deadline->invoke($maria);
no_controller_assert(in_array('SET SESSION max_statement_time=2', $mariaDb->exec, true) && in_array('SET SESSION innodb_lock_wait_timeout=2', $mariaDb->exec, true), 'MariaDB acquisition receives statement and lock-wait deadlines');
$legacyMaria = new NoControllerConcurrencycount(); $legacyMariaDb = new NoControllerDeadlineDb('5.5.65-MariaDB');
$legacyMariaSet = function ($name, $value) use ($legacyMaria) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($legacyMaria, $value); };
$legacyMariaSet('cdrdb', $legacyMariaDb); $legacyCapabilities = $deadline->invoke($legacyMaria);
no_controller_assert(!in_array('SET SESSION max_statement_time=2', $legacyMariaDb->exec, true), 'MariaDB 5.5 must not receive unsupported max_statement_time');
no_controller_assert(in_array('SET SESSION innodb_lock_wait_timeout=2', $legacyMariaDb->exec, true), 'MariaDB 5.5 must retain the InnoDB lock-wait deadline');
no_controller_assert($legacyCapabilities['adaptive_legacy_acquisition'] && $legacyCapabilities['acquisition_window_seconds'] === 900, 'MariaDB 5.5 ordinary Historical must receive the adaptive fifteen-minute starting policy');
$mysql = new NoControllerConcurrencycount(); $mysqlDb = new NoControllerDeadlineDb('8.0.36');
$mysqlSet = function ($name, $value) use ($mysql) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($mysql, $value); };
$mysqlSet('cdrdb', $mysqlDb); $deadline->invoke($mysql);
no_controller_assert(in_array('SET SESSION max_execution_time=2000', $mysqlDb->exec, true) && in_array('SET SESSION innodb_lock_wait_timeout=2', $mysqlDb->exec, true), 'MySQL acquisition receives SELECT and lock-wait deadlines');
$demoRequirement = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'requireDemoStatementTimeoutSupport'); $demoRequirement->setAccessible(true);
$legacyDemoClosed = false;
try { $demoRequirement->invoke($legacyMaria, $legacyCapabilities); } catch (ReflectionException $e) { throw $e; } catch (RuntimeException $e) { $legacyDemoClosed = strpos($e->getMessage(), 'Historical reporting remains available') !== false; }
no_controller_assert($legacyDemoClosed && strpos($e->getMessage(), 'MariaDB') !== false, 'Demo must fail closed on MariaDB 5.5 while identifying ordinary Historical as available');
$demoRequirement->invoke($maria, $deadline->invoke($maria));
$mysqlDemoClosed = false;
try { $demoRequirement->invoke($mysql, $deadline->invoke($mysql)); } catch (ReflectionException $e) { throw $e; } catch (RuntimeException $e) { $mysqlDemoClosed = strpos($e->getMessage(), 'cleanup DELETE') !== false && strpos($e->getMessage(), 'MySQL') !== false; }
no_controller_assert($mysqlDemoClosed, 'MySQL Demo must fail closed because max_execution_time does not protect cleanup DELETE statements');
$legacyPreflight = new NoControllerConcurrencycount(); $legacyPreflightDb = new NoControllerDeadlineDb('5.5.65-MariaDB');
$legacyPreflightSet = function ($name, $value) use ($legacyPreflight) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($legacyPreflight, $value); };
$legacyPreflightSet('cdrdb', $legacyPreflightDb); $_REQUEST['demo_size'] = 'light'; $_REQUEST['demo_rows'] = 1;
$preflight = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'handleDemoPreflight'); $preflight->setAccessible(true); $preflightResult = $preflight->invoke($legacyPreflight);
no_controller_assert($preflightResult['status'] === false && count($legacyPreflightDb->queries) === 1 && $legacyPreflightDb->queries[0] === 'SELECT VERSION()', 'Legacy Demo preflight must fail before cleanup-index, recovery, disk, or CDR work');
$mysqlPreflight = new NoControllerConcurrencycount(); $mysqlPreflightDb = new NoControllerDeadlineDb('8.0.36');
$mysqlPreflightSet = function ($name, $value) use ($mysqlPreflight) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($mysqlPreflight, $value); };
$mysqlPreflightSet('cdrdb', $mysqlPreflightDb); $mysqlPreflightResult = $preflight->invoke($mysqlPreflight);
no_controller_assert($mysqlPreflightResult['status'] === false && count($mysqlPreflightDb->queries) === 1, 'MySQL Demo rejection must precede cleanup-index, recovery, disk, or CDR work');
$accessPath = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'verifyLegacyHistoricalAccessPath'); $accessPath->setAccessible(true); $verifiedIndex = $accessPath->invoke($legacyMaria);
no_controller_assert($verifiedIndex === 'calldate', 'Legacy Historical must return the verified leading calldate index name');
$missingIndex = new NoControllerConcurrencycount(); $missingIndexDb = new NoControllerDeadlineDb('5.5.65-MariaDB', [['INDEX_NAME' => 'other', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'uniqueid', 'SUB_PART' => null]]);
$missingIndexSet = function ($name, $value) use ($missingIndex) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($missingIndex, $value); }; $missingIndexSet('cdrdb', $missingIndexDb);
$indexRejected = false; try { $accessPath->invoke($missingIndex); } catch (ReflectionException $e) { throw $e; } catch (RuntimeException $e) { $indexRejected = strpos($e->getMessage(), 'cdr.calldate') !== false; }
no_controller_assert($indexRejected, 'Legacy Historical must reject a CDR schema without a full leading calldate index');
$oldMysql = new NoControllerConcurrencycount(); $oldMysqlDb = new NoControllerDeadlineDb('5.7.7'); $oldMysqlSet = function ($name, $value) use ($oldMysql) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($oldMysql, $value); }; $oldMysqlSet('cdrdb', $oldMysqlDb);
$oldMysqlRejected = false; try { $deadline->invoke($oldMysql); } catch (ReflectionException $e) { throw $e; } catch (RuntimeException $e) { $oldMysqlRejected = true; }
no_controller_assert($oldMysqlRejected, 'MySQL older than 5.7.8 must remain unsupported');
echo "Historical no-controller policy tests passed\n";
