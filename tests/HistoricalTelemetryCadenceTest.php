<?php
require_once __DIR__ . '/../Services/HistoricalTelemetryCadence.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalTelemetryCadence;
function cadence_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
$c = new HistoricalTelemetryCadence();
cadence_assert($c->shouldPublish(0, 'running'), 'Initial state publishes immediately');
cadence_assert(!$c->shouldPublish(.5, 'running') && !$c->shouldPublish(1.5, 'running'), 'Steady state does not publish every half second');
cadence_assert($c->shouldPublish(2, 'running'), 'Steady state publishes at two seconds');
cadence_assert($c->shouldPublish(2.1, 'paused_impact'), 'Pause transition publishes immediately');
cadence_assert(!$c->shouldPublish(2.6, 'paused_impact') && $c->shouldPublish(4.2, 'paused_impact'), 'Paused persistence retains the bounded cadence');
cadence_assert($c->shouldPublish(4.3, 'running'), 'Resume transition publishes immediately');
echo "Historical telemetry cadence tests passed\n";
