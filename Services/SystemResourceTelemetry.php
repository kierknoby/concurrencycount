<?php

namespace FreePBX\modules\Concurrencycount\Services;

class SystemResourceTelemetry {
	public function fromDashboard(array $info): array {
		$load = isset($info['psi.Vitals.@attributes.LoadAvg.five']) && is_numeric($info['psi.Vitals.@attributes.LoadAvg.five'])
			? (float)$info['psi.Vitals.@attributes.LoadAvg.five'] : null;
		$totalMemory = $this->number($info, 'psi.Memory.@attributes.Total');
		$appPercent = $this->number($info, 'psi.Memory.Details.@attributes.AppPercent');
		$appMemory = $this->number($info, 'psi.Memory.Details.@attributes.App');
		if ($appMemory === null && $totalMemory !== null && $appPercent !== null) $appMemory = $totalMemory * $appPercent / 100;
		$disk = $this->rootDisk($info);
		return [
			'available' => $load !== null || $totalMemory !== null || $disk !== null,
			'cpu' => ['label' => 'Load average (5 min)', 'value' => $load],
			'memory' => [
				'label' => 'Memory (applications)', 'used_bytes' => $appMemory === null ? null : (int)round($appMemory),
				'total_bytes' => $totalMemory === null ? null : (int)round($totalMemory),
				'percent' => $appPercent === null ? null : (float)$appPercent,
				'definition' => 'FreePBX dashboard application memory, excluding cache and buffers',
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
