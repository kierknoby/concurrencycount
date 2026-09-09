<?php
if (!function_exists('_')) { function _($message) { return $message; } }
if (!interface_exists('BMO')) { interface BMO {} }
require_once __DIR__ . '/../Concurrencycount.class.php';
function no_controller_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
class NoControllerConcurrencycount extends \FreePBX\modules\Concurrencycount { public function __construct() {} }
class NoControllerGuard { public $checks = 0; public function checkpoint() { $this->checks++; } }
class NoControllerDeadlineDb { public $version; public $exec = []; public function __construct($version) { $this->version = $version; } public function query($sql) { return new NoControllerDeadlineStatement($this->version); } public function exec($sql) { $this->exec[] = $sql; } }
class NoControllerDeadlineStatement { private $version; public function __construct($version) { $this->version = $version; } public function fetchColumn() { return $this->version; } }
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
no_controller_assert(strpos($source, "\$this->configureHistoricalQueryDeadline();\n\t\t\$acquisition = new") !== false, 'Every shared CDR acquisition configures a native statement deadline first');
$deadline = new ReflectionMethod(\FreePBX\modules\Concurrencycount::class, 'configureHistoricalQueryDeadline'); $deadline->setAccessible(true);
$maria = new NoControllerConcurrencycount(); $mariaDb = new NoControllerDeadlineDb('10.6.12-MariaDB');
$mariaSet = function ($name, $value) use ($maria) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($maria, $value); };
$mariaSet('cdrdb', $mariaDb); $deadline->invoke($maria);
no_controller_assert(in_array('SET SESSION max_statement_time=2', $mariaDb->exec, true) && in_array('SET SESSION innodb_lock_wait_timeout=2', $mariaDb->exec, true), 'MariaDB acquisition receives statement and lock-wait deadlines');
$mysql = new NoControllerConcurrencycount(); $mysqlDb = new NoControllerDeadlineDb('8.0.36');
$mysqlSet = function ($name, $value) use ($mysql) { $p = new ReflectionProperty(\FreePBX\modules\Concurrencycount::class, $name); $p->setAccessible(true); $p->setValue($mysql, $value); };
$mysqlSet('cdrdb', $mysqlDb); $deadline->invoke($mysql);
no_controller_assert(in_array('SET SESSION max_execution_time=2000', $mysqlDb->exec, true) && in_array('SET SESSION innodb_lock_wait_timeout=2', $mysqlDb->exec, true), 'MySQL acquisition receives SELECT and lock-wait deadlines');
echo "Historical no-controller policy tests passed\n";
