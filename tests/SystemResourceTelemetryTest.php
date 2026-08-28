<?php

require_once __DIR__ . '/../Services/SystemResourceTelemetry.php';

use FreePBX\modules\Concurrencycount\Services\SystemResourceTelemetry;

function resource_assert($condition, string $message): void {
	if (!$condition) throw new Exception($message);
}

$parser = new SystemResourceTelemetry();
$telemetry = $parser->fromDashboard([
	'psi.Vitals.@attributes.LoadAvg.five' => '1.25',
	'psi.Hardware.CPU.CpuCore.0.@attributes.Model' => 'CPU',
	'psi.Hardware.CPU.CpuCore.1.@attributes.Model' => 'CPU',
	'psi.Memory.@attributes.Total' => '8589934592',
	'psi.Memory.Details.@attributes.App' => '3221225472',
	'psi.Memory.Details.@attributes.AppPercent' => '37.5',
	'psi.Memory.Swap.@attributes.Total' => '2147483648',
	'psi.Memory.Swap.@attributes.Used' => '536870912',
	'psi.Memory.Swap.@attributes.Percent' => '25',
	'psi.FileSystem.Mount.0.@attributes.MountPoint' => '/',
	'psi.FileSystem.Mount.0.@attributes.Total' => '107374182400',
	'psi.FileSystem.Mount.0.@attributes.Used' => '53687091200',
	'psi.FileSystem.Mount.0.@attributes.Percent' => '50',
	'psi.FileSystem.Mount.1.@attributes.MountPoint' => '/boot',
]);

resource_assert($telemetry['available'] === true, 'Native dashboard fixture is available');
resource_assert($telemetry['cpu']['label'] === 'System load (5 min)' && $telemetry['cpu']['value'] === 1.25, 'CPU is truthfully exposed as system load');
resource_assert($telemetry['cpu']['logical_cpus'] === 2 && strpos($telemetry['cpu']['definition'], 'not a percentage') !== false, 'Native CpuCore entries provide logical CPU context and an accurate definition');
resource_assert($telemetry['memory']['label'] === 'Memory (applications)' && $telemetry['memory']['used_bytes'] === 3221225472, 'Application memory preserves the dashboard definition');
resource_assert(strpos($telemetry['memory']['definition'], 'excluding cache and buffers') !== false, 'Memory definition is explicit');
resource_assert($telemetry['swap']['used_bytes'] === 536870912 && $telemetry['swap']['total_bytes'] === 2147483648 && $telemetry['swap']['percent'] === 25.0, 'Native swap usage is preserved');
resource_assert($telemetry['disk']['mount_point'] === '/' && $telemetry['disk']['used_bytes'] === 53687091200, 'Root filesystem is selected rather than another mount');

$fallback = $parser->fromDashboard([
	'psi.Memory.@attributes.Total' => '1000',
	'psi.Memory.Details.@attributes.AppPercent' => '25',
	'psi.FileSystem.Mount.0.@attributes.MountPoint' => '/',
	'psi.FileSystem.Mount.0.@attributes.Total' => '100',
	'psi.FileSystem.Mount.0.@attributes.Free' => '40',
]);
resource_assert($fallback['memory']['used_bytes'] === 250, 'Application bytes can be derived from the native percentage');
resource_assert($fallback['disk']['used_bytes'] === 60, 'Disk usage can be derived without changing its definition');
resource_assert($fallback['swap'] === null, 'Swap is omitted when native data is unavailable');
resource_assert($parser->fromDashboard([])['available'] === false, 'Missing Dashboard data degrades safely');

echo "System resource telemetry tests passed\n";
