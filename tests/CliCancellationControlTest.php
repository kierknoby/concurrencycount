<?php

require_once __DIR__ . '/../Services/CliCancellationControl.php';
require_once __DIR__ . '/../Engines/EngineInterface.php';
require_once __DIR__ . '/../Engines/Original.php';
require_once __DIR__ . '/../Engines/Sweep.php';

use FreePBX\modules\Concurrencycount\Services\CliCancellationControl;
use FreePBX\modules\Concurrencycount\Engines\Original;
use FreePBX\modules\Concurrencycount\Engines\Sweep;

function cli_cancel_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$control = new CliCancellationControl();
cli_cancel_assert(!$control->isInterrupted(), 'CLI cancellation begins inactive');
$control->handleSignal(2);
cli_cancel_assert($control->isInterrupted() && $control->signal() === 2, 'SIGINT handler sets lightweight in-process cancellation state');
$control->handleSignal(2);
cli_cancel_assert($control->isInterrupted(), 'Repeated SIGINT remains idempotently cancelled');

$row = ['calldate' => '2026-08-28 00:00:00', 'duration' => 9000, 'identity' => 'gamma', 'extension_legs' => 1];
foreach ([Original::class, Sweep::class] as $engineClass) {
	$observed = false;
	$engine = new $engineClass([
		'all_names' => ['gamma' => true],
		'coalesce_ranges' => function (array $times): array { return []; },
		'check_overrun' => function () use ($control, &$observed): void {
			if ($control->isInterrupted()) {
				$observed = true;
				throw new RuntimeException('cancelled');
			}
		},
	]);
	try { $engine->calculatePerName('trunk', [$row]); } catch (RuntimeException $exception) {}
	cli_cancel_assert($observed, basename(str_replace('\\', '/', $engineClass)) . ' observes CLI cancellation through its runtime checkpoint');
}

$capabilityControl = new CliCancellationControl();
$availability = $capabilityControl->install();
cli_cancel_assert(is_bool($availability), 'PCNTL installation degrades to a safe boolean capability result');
$capabilityControl->uninstall();
$installedControl = new CliCancellationControl();
$installedControl->install();
$installedControl->uninstall();
cli_cancel_assert(!$installedControl->isInstalled(), 'CLI signal handlers are removed after active calculation work');

echo "CLI cancellation control tests passed\n";
