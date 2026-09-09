<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Called only by the calculation worker; procfs reads are bounded and optional. */
class HistoricalProcessTelemetry {
	private $reader;
	private $previous = null;
	public function __construct(?callable $reader = null) { $this->reader = $reader ?: function ($path) { return @file_get_contents($path); }; }
	public function sample(float $now): array {
		$m = ['calculation_memory_current' => memory_get_usage(true), 'calculation_memory_peak' => memory_get_peak_usage(true)];
		$stat = (string)call_user_func($this->reader, '/proc/stat');
		$cpu = null;
		if (preg_match('/^cpu\s+(.+)$/m', $stat, $match)) {
			$cpu = array_map('floatval', preg_split('/\s+/', trim($match[1])));
			$cpu = array_slice(array_pad($cpu, 8, 0), 0, 8); // guest times already included in user/nice
			$m['system_cpu_count'] = preg_match_all('/^cpu[0-9]+\s/m', $stat);
			if ($this->previous && $this->previous['cpu']) {
				$old = $this->previous['cpu']; $delta = array_sum($cpu) - array_sum($old);
				if ($delta > 0) {
					$m['system_cpu_percent'] = max(0, min(100, 100 * (1 - (($cpu[3] - $old[3]) + ($cpu[4] - $old[4])) / $delta)));
					$m['io_wait_percent'] = max(0, min(100, 100 * ($cpu[4] - $old[4]) / $delta));
				}
			}
		}
		$mem = (string)call_user_func($this->reader, '/proc/meminfo');
		foreach (['MemTotal' => 'memory_total', 'MemAvailable' => 'memory_available', 'SwapTotal' => 'swap_total', 'SwapFree' => 'swap_free'] as $key => $field) {
			if (preg_match('/^' . $key . ':\s+(\d+) kB/m', $mem, $match)) $m[$field] = (float)$match[1] * 1024;
		}
		if (isset($m['memory_total'], $m['memory_available'])) $m['memory_used'] = max(0, $m['memory_total'] - $m['memory_available']);
		if (isset($m['swap_total'], $m['swap_free'])) $m['swap_used'] = max(0, $m['swap_total'] - $m['swap_free']);
		$load = (string)call_user_func($this->reader, '/proc/loadavg');
		if (preg_match('/^([0-9.]+)/', $load, $match)) $m['system_load'] = (float)$match[1];
		foreach (['io', 'memory'] as $kind) {
			$p = (string)call_user_func($this->reader, '/proc/pressure/' . $kind);
			if (preg_match('/^some avg10=([0-9.]+)/m', $p, $match)) $m[$kind . '_pressure_percent'] = (float)$match[1];
		}
		$vm = (string)call_user_func($this->reader, '/proc/vmstat'); $swap = null;
		if (preg_match('/^pswpin (\d+)/m', $vm, $a) && preg_match('/^pswpout (\d+)/m', $vm, $b)) $swap = (float)$a[1] + (float)$b[1];
		$usage = function_exists('getrusage') ? getrusage() : false; $seconds = null;
		if (is_array($usage) && isset($usage['ru_utime.tv_sec'], $usage['ru_stime.tv_sec'], $usage['ru_utime.tv_usec'], $usage['ru_stime.tv_usec'])) {
			$seconds = ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000000;
			$m['calculation_cpu_time'] = $seconds;
		}
		if ($this->previous && $now > $this->previous['at']) {
			$dt = $now - $this->previous['at'];
			if ($swap !== null && $this->previous['swap'] !== null && $swap >= $this->previous['swap']) $m['swap_pages_per_second'] = ($swap - $this->previous['swap']) / $dt;
			if ($seconds !== null && $this->previous['seconds'] !== null) $m['process_cpu_percent'] = max(0, 100 * ($seconds - $this->previous['seconds']) / $dt);
		}
		$this->previous = ['at' => $now, 'cpu' => $cpu, 'swap' => $swap, 'seconds' => $seconds];
		return $m;
	}
}
