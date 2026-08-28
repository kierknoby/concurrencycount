<?php

namespace FreePBX\modules\Concurrencycount\Services;

class SystemResourceTelemetry {
	public function fromDashboard(array $info): array {
		$load = isset($info['psi.Vitals.@attributes.LoadAvg.five']) && is_numeric($info['psi.Vitals.@attributes.LoadAvg.five'])
			? (float)$info['psi.Vitals.@attributes.LoadAvg.five'] : null;
		$cpuIds = [];
		foreach ($info as $key => $_value) {
			if (preg_match('/^psi\.Hardware\.CPU\.CpuCore\.([^.]+)\./', (string)$key, $match)) $cpuIds[(string)$match[1]] = true;
		}
		$totalMemory = $this->number($info, 'psi.Memory.@attributes.Total');
		$appPercent = $this->number($info, 'psi.Memory.Details.@attributes.AppPercent');
		$appMemory = $this->number($info, 'psi.Memory.Details.@attributes.App');
		if ($appMemory === null && $totalMemory !== null && $appPercent !== null) $appMemory = $totalMemory * $appPercent / 100;
		$disk = $this->rootDisk($info);
		$swapTotal = $this->number($info, 'psi.Memory.Swap.@attributes.Total');
		$swapUsed = $this->number($info, 'psi.Memory.Swap.@attributes.Used');
		$swapPercent = $this->number($info, 'psi.Memory.Swap.@attributes.Percent');
		if ($swapUsed === null && $swapTotal !== null) {
			$swapFree = $this->number($info, 'psi.Memory.Swap.@attributes.Free');
			if ($swapFree !== null) $swapUsed = max(0, $swapTotal - $swapFree);
		}
		return [
			'available' => $load !== null || $totalMemory !== null || $swapTotal !== null || $disk !== null,
			'cpu' => [
				'label' => 'System load (5 min)', 'value' => $load,
				'logical_cpus' => empty($cpuIds) ? null : count($cpuIds),
				'definition' => 'Average tasks running or waiting for CPU or resources over five minutes; not a percentage',
			],
			'memory' => [
				'label' => 'Memory (applications)', 'used_bytes' => $appMemory === null ? null : (int)round($appMemory),
				'total_bytes' => $totalMemory === null ? null : (int)round($totalMemory),
				'percent' => $appPercent === null ? null : (float)$appPercent,
				'definition' => 'FreePBX dashboard application memory, excluding cache and buffers',
			],
			'swap' => $swapTotal === null ? null : [
				'label' => 'Swap',
				'used_bytes' => $swapUsed === null ? null : (int)round($swapUsed),
				'total_bytes' => (int)round($swapTotal),
				'percent' => $swapPercent === null ? null : (float)$swapPercent,
			],
			'disk' => $disk,
		];
	}

	private function rootDisk(array $info): ?array {
		$mounts = [];
		foreach ($info as $key => $value) {
			if (!preg_match('/^psi\.FileSystem\.Mount\.([^.]+)\.@attributes\.MountPoint$/', (string)$key, $match)) continue;
			$mounts[(string)$match[1]] = (string)$value;
		}
		$id = array_search('/', $mounts, true);
		if ($id === false) return null;
		$prefix = 'psi.FileSystem.Mount.' . $id . '.@attributes.';
		$total = $this->number($info, $prefix . 'Total');
		$used = $this->number($info, $prefix . 'Used');
		$percent = $this->number($info, $prefix . 'Percent');
		if ($used === null && $total !== null) {
			$free = $this->number($info, $prefix . 'Free');
			if ($free !== null) $used = max(0, $total - $free);
		}
		return [
			'label' => 'Disk (/)', 'mount_point' => '/',
			'used_bytes' => $used === null ? null : (int)round($used),
			'total_bytes' => $total === null ? null : (int)round($total),
			'percent' => $percent === null ? null : (float)$percent,
		];
	}

	private function number(array $info, string $key): ?float {
		return isset($info[$key]) && is_numeric($info[$key]) ? (float)$info[$key] : null;
	}
}
