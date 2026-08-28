<?php

require_once __DIR__ . '/../Services/HistoricalResourceLimitException.php';
require_once __DIR__ . '/../Services/HistoricalMemoryGuard.php';

use FreePBX\modules\Concurrencycount\Services\HistoricalMemoryGuard;
use FreePBX\modules\Concurrencycount\Services\HistoricalResourceLimitException;

function memory_guard_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

memory_guard_assert(HistoricalMemoryGuard::parseLimit('128M') === 134217728, '128M parsing failed');
memory_guard_assert(HistoricalMemoryGuard::parseLimit('512M') === 536870912, '512M parsing failed');
memory_guard_assert(HistoricalMemoryGuard::parseLimit('1G') === 1073741824, '1G parsing failed');
memory_guard_assert(HistoricalMemoryGuard::parseLimit('-1') === null, 'Unlimited memory must disable the ceiling');
memory_guard_assert(HistoricalMemoryGuard::parseLimit('invalid') === null, 'Invalid memory limit must disable the ceiling safely');

$guard = new HistoricalMemoryGuard('512M', function (): int { return 429496730; });
$policy = $guard->policy();
memory_guard_assert($policy['reserved_bytes'] === 107374183, '512M policy must reserve 20 percent headroom');
memory_guard_assert($policy['safe_ceiling_bytes'] === 429496729, '512M safe ceiling must remain below PHP hard limit');
$caught = null;
try { $guard->checkpoint(); } catch (HistoricalResourceLimitException $exception) { $caught = $exception; }
memory_guard_assert($caught instanceof HistoricalResourceLimitException, 'Crossing the safe ceiling must throw the dedicated resource condition');
memory_guard_assert($caught->safeCeilingBytes === $policy['safe_ceiling_bytes'] && $caught->hardLimitBytes === 536870912, 'Dedicated condition must preserve policy diagnostics');

$below = new HistoricalMemoryGuard('128M', function (): int { return 100000000; });
$below->checkpoint();
$low = new HistoricalMemoryGuard('8M', function (): int { return 4194304; });
$lowPolicy = $low->policy();
memory_guard_assert($lowPolicy['reserved_bytes'] === 4194304 && $lowPolicy['safe_ceiling_bytes'] === 4194304, 'Unusually low limits must retain half as headroom');
$unlimited = new HistoricalMemoryGuard('-1', function (): int { return PHP_INT_MAX; });
$unlimited->checkpoint();

memory_guard_assert(!is_subclass_of(HistoricalResourceLimitException::class, 'FreePBX\\modules\\Concurrencycount\\HistoricalCalculationCancelled'), 'Resource failure must remain distinct from cancellation');

echo "Historical memory guard tests passed\n";
