<?php
/**
 * fwconsole concurrencycount command.
 *
 * Usage:
 *   fwconsole concurrencycount --mode=trunk --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59"
 *   fwconsole concurrencycount --mode=extension --start=... --end=...
 *   fwconsole concurrencycount --mode=group --start=... --end=... --csv
 *   fwconsole concurrencycount --mode=demo
 *
 * Mode accepts the same abbreviations as the original bash CLI
 * (trunks/trunk/.../t, extensions/ext/.../e, groups/group/.../g), plus demo.
 */

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Concurrencycount extends Command {

	protected function configure() {
		$this->setName('concurrencycount')
			->setDescription('Calculate maximum concurrent PJSIP calls per trunk, extension, group, or demo fixture')
			->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Mode: trunk, extension, group, or demo (abbreviations accepted)', 'trunk')
			->addOption('start', 's', InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD HH:MM:SS (or shorthand)')
			->addOption('end', 'e', InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD HH:MM:SS (or shorthand)')
			->addOption('demo-report', null, InputOption::VALUE_REQUIRED, 'Demo report: trunk, extension, or group', 'extension')
			->addOption('demo-size', null, InputOption::VALUE_REQUIRED, 'Demo size: light, medium, or heavy', 'light')
			->addOption('demo-seed', null, InputOption::VALUE_REQUIRED, 'Demo random seed', '0')
			->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Engine: original (default), sweep, ...', 'original')
			->addOption('compare', null, InputOption::VALUE_REQUIRED, 'Demo mode only: comma-separated engine list to compare')
			->addOption('csv', null, InputOption::VALUE_NONE, 'Output CSV instead of formatted text')
			->addOption('live', null, InputOption::VALUE_NONE, 'Show one current AMI live-status snapshot and exit')
			->addOption('settings', null, InputOption::VALUE_NONE, 'Show Live Command Centre and threshold settings')
			->addOption('set-refresh', null, InputOption::VALUE_REQUIRED, 'Set browser refresh interval: 1, 5, 10, 15, 30 or 60 seconds')
			->addOption('set-overall-threshold', null, InputOption::VALUE_REQUIRED, 'Set overall threshold; 0 disables it')
			->addOption('overall-threshold', null, InputOption::VALUE_REQUIRED, 'Enable or disable the configured overall threshold: on|off')
			->addOption('set-trunk-threshold', null, InputOption::VALUE_REQUIRED, 'Set trunk threshold as TRUNK=VALUE; 0 disables it')
			->addOption('trunk-threshold', null, InputOption::VALUE_REQUIRED, 'Enable or disable a trunk threshold as TRUNK=on|off')
			->addOption('alerts', null, InputOption::VALUE_REQUIRED, 'Enable or disable threshold notifications globally: on|off')
			->addOption('overall-alert', null, InputOption::VALUE_REQUIRED, 'Enable or disable overall threshold notifications: on|off')
			->addOption('trunk-alert', null, InputOption::VALUE_REQUIRED, 'Enable or disable trunk notifications as TRUNK=on|off')
			->addOption('recovery', null, InputOption::VALUE_REQUIRED, 'Enable or disable recovery notifications: on|off')
			->addOption('alert-email', null, InputOption::VALUE_REQUIRED, 'Set threshold notification email address')
			->addOption('historical-graph', null, InputOption::VALUE_REQUIRED, 'Return historical graph data for trunk or group mode')
			->addOption('graph-trunk', null, InputOption::VALUE_REQUIRED, 'Limit a trunk historical graph to one trunk')
			->addOption('json', null, InputOption::VALUE_NONE, 'Use machine-readable JSON for live, settings, monitor or graph output')
			->addOption('monitor', null, InputOption::VALUE_NONE, 'Run one threshold evaluation snapshot and exit (diagnostic)')
			->addOption('monitor-status', null, InputOption::VALUE_NONE, 'Show the supervised threshold-alert monitor status')
			->addOption('restart-monitor', null, InputOption::VALUE_NONE, 'Restart the supervised threshold-alert monitor')
			->addOption('list-historical-reports', null, InputOption::VALUE_NONE, 'List persisted Historical Reports GUI tabs')
			->addOption('show-historical-report', null, InputOption::VALUE_REQUIRED, 'Show one persisted historical report tab by number (1-5) or id')
			->addOption('delete-historical-report', null, InputOption::VALUE_REQUIRED, 'Close/delete one persisted historical report tab by number (1-5) or id');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$mode_raw = $input->getOption('mode');
		$start_raw = $input->getOption('start');
		$end_raw = $input->getOption('end');
		$demo_report = $input->getOption('demo-report');
		$demo_size = $input->getOption('demo-size');
		$demo_seed = $input->getOption('demo-seed');
		$engine = $input->getOption('engine');
		$compare = $input->getOption('compare');
		$csv = $input->getOption('csv');

		$cc = \FreePBX::Concurrencycount();
		$managementResult = $this->handleManagementOperation($input, $output, $cc);
		if ($managementResult !== null) return $managementResult;

		$mode = $cc->normaliseMode($mode_raw);
		if ($mode === null) {
			$output->writeln('<error>Invalid mode. Use trunks, extensions, group, or demo (abbreviations accepted).</error>');
			return 1;
		}

		if ($mode !== 'demo' && (!$start_raw || !$end_raw)) {
			$output->writeln('<error>Both --start and --end are required.</error>');
			return 1;
		}

		if ($mode === 'demo') {
			$plan = $this->demoPlan((int)$demo_seed, $demo_size);
			$start = $start_raw ?: $plan['start'];
			$end = $end_raw ?: $plan['end'];
		} else {
			$start = $cc->normaliseStartDate($start_raw);
			$end = $cc->normaliseEndDate($end_raw);
			if ($start === null) { $output->writeln('<error>Invalid start date.</error>'); return 1; }
			if ($end === null) { $output->writeln('<error>Invalid end date.</error>'); return 1; }
		}

		try {
			$results = $cc->calculate($mode, $start, $end, true, [
				'demo_report' => $demo_report,
				'demo_size' => $demo_size,
				'demo_seed' => $demo_seed,
				'engine' => $engine,
				'demo_engines' => ($mode === 'demo' && $compare) ? explode(',', $compare) : [$engine],
			]);
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($csv) {
			$output->write($cc->resultsToCsv($results));
			return 0;
		}

		$output->writeln('');
		$output->writeln('<info>Concurrency Count - NOT CURRENTLY SUITABLE FOR PRODUCTION</info>');
		$output->writeln('Mode:           ' . ucfirst($results['mode']));
		$output->writeln('From:           ' . $results['start']);
		$output->writeln('To:             ' . $results['end']);
		if (isset($results['engine'])) {
			$output->writeln('Engine:         ' . $results['engine']);
		}
		$output->writeln('Rows processed: ' . $results['rows_processed']);
		$output->writeln('');

		if (!empty($results['empty_message'])) {
			$output->writeln('<comment>' . $results['empty_message'] . '</comment>');
			$output->writeln('');
			return 0;
		}

		if ($results['mode'] === 'demo') {
			$output->writeln('Demo report:    ' . ucfirst($results['demo_report']));
			$output->writeln('Demo seed:      ' . $results['demo_seed']);
			$output->writeln('Accuracy:       ' . strtoupper($results['accuracy_status']));
			$output->writeln('Rows removed:   ' . $results['rows_removed']);
			$output->writeln('Rows remaining: ' . $results['cleanup_remaining']);
			$output->writeln('');
			if (!empty($results['engines'])) {
				$output->writeln('Engine comparison:');
				$output->writeln(sprintf('%-12s  %-8s  %-10s  %-12s  %s', 'Engine', 'Accuracy', 'Wall time', 'Peak memory', 'Rows/sec'));
				foreach ($results['engines'] as $id => $engine_result) {
					$output->writeln(sprintf(
						'%-12s  %-8s  %-10s  %-12s  %s',
						$id,
						$engine_result['accuracy_status'],
						number_format(((int)$engine_result['wall_ms']) / 1000, 2) . 's',
						$this->formatBytes((int)$engine_result['peak_memory_bytes']),
						number_format((int)$engine_result['rows_per_second'])
					));
				}
				$output->writeln('');
			}
			if ($results['demo_report'] === 'group') {
				$output->writeln('Group accuracy:');
				$output->writeln('Expected max: ' . $results['expected_max_concurrency']);
				$output->writeln('Actual max:   ' . $results['max_concurrency']);
			} else {
				$label = ($results['demo_report'] === 'trunk') ? 'Trunk' : 'Extension';
				$output->writeln($label . ' accuracy:');
				$output->writeln(sprintf('%-24s  %-8s  %s', $label, 'Expected', 'Actual'));
				foreach ($results['expected_per_name'] as $name => $count) {
					$actual = isset($results['per_name'][$name]) ? $results['per_name'][$name] : 0;
					$output->writeln(sprintf('%-24s  %-8d  %d', $name, $count, $actual));
				}
			}
		} elseif ($results['mode'] === 'group') {
			$output->writeln('<info>Maximum concurrent calls overall: ' . $results['max_concurrency'] . '</info>');
			$output->writeln('');
			if (!empty($results['peak_ranges'])) {
				$output->writeln('Peak time ranges:');
				foreach ($results['peak_ranges'] as $r) {
					if ($r['from'] === $r['to']) {
						$output->writeln('  ' . $r['from']);
					} else {
						$output->writeln('  ' . $r['from'] . ' to ' . $r['to']);
					}
				}
			}
		} else {
			$label = ($results['mode'] === 'trunk') ? 'Trunk' : 'Extension';
			$output->writeln(sprintf('%-24s  %s', $label, 'Max concurrent'));
			foreach ($results['per_name'] as $name => $count) {
				$marker = ($count === $results['global_max'] && $results['global_max'] > 0) ? '*' : ' ';
				$output->writeln(sprintf('%s%-23s  %d', $marker, $name, $count));
			}
			$output->writeln('');
			$output->writeln('<info>Global maximum: ' . $results['global_max'] . '</info>');
		}

		$output->writeln('');
		$output->writeln('<comment>' . $results['warning'] . '</comment>');
		$output->writeln('');
		return 0;
	}

	private function handleManagementOperation(InputInterface $input, OutputInterface $output, $cc): ?int {
		$json = (bool)$input->getOption('json');
		$managementOptions = [
			'live', 'settings', 'set-refresh', 'set-overall-threshold', 'overall-threshold',
			'set-trunk-threshold', 'trunk-threshold', 'alerts', 'overall-alert', 'trunk-alert',
			'recovery', 'alert-email', 'historical-graph', 'monitor',
			'monitor-status', 'restart-monitor',
			'list-historical-reports', 'show-historical-report', 'delete-historical-report',
		];
		$requested = false;
		foreach ($managementOptions as $option) {
			if ($input->getOption($option) !== null && $input->getOption($option) !== false) {
				$requested = true;
				break;
			}
		}
		if (!$requested) return null;
		if ($input->getOption('monitor')) {
			$result = $cc->runThresholdMonitor();
			$this->writeStructured($output, $result, $json, 'Threshold monitor');
			return empty($result['errors']) ? 0 : 1;
		}
		if ($input->getOption('monitor-status')) {
			$result = $cc->getAlertMonitorStatus();
			$this->writeMonitorStatus($output, $result, $json);
			return !empty($result['available']) && $result['status'] === 'online' ? 0 : 1;
		}
		if ($input->getOption('restart-monitor')) {
			$result = $cc->restartAlertMonitor();
			$this->writeMonitorStatus($output, $result, $json);
			return !empty($result['available']) && isset($result['pm2_status']) && $result['pm2_status'] === 'online' ? 0 : 1;
		}
		if ($input->getOption('list-historical-reports')) {
			$result = $cc->getHistoricalReports();
			$this->writeHistoricalReports($output, $result['reports'], $result['active_id'], $json);
			return 0;
		}
		if ($input->getOption('show-historical-report') !== null) {
			$report = $this->findHistoricalReport($cc, (string)$input->getOption('show-historical-report'));
			if ($report === null) {
				$output->writeln('<error>No persisted historical report matches that number or id.</error>');
				return 1;
			}
			$this->writeStructured($output, $report, $json, 'Historical report');
			return 0;
		}
		if ($input->getOption('delete-historical-report') !== null) {
			$report = $this->findHistoricalReport($cc, (string)$input->getOption('delete-historical-report'));
			if ($report === null) {
				$output->writeln('<error>No persisted historical report matches that number or id.</error>');
				return 1;
			}
			$result = $cc->closeHistoricalReport($report['id']);
			if (empty($result['status'])) {
				$output->writeln('<error>' . (isset($result['message']) ? $result['message'] : 'Unable to close historical report.') . '</error>');
				return 1;
			}
			$output->writeln('<info>Closed ' . $report['name'] . '.</info>');
			return 0;
		}

		$settings = $cc->getLiveSettings();
		$changed = false;
		try {
			if ($input->getOption('set-refresh') !== null) {
				$settings['refresh_interval'] = $input->getOption('set-refresh');
				$changed = true;
			}
			if ($input->getOption('set-overall-threshold') !== null) {
				$value = $input->getOption('set-overall-threshold');
				$settings['overall']['threshold'] = $value;
				$settings['overall']['enabled'] = ((int)$value > 0);
				$changed = true;
			}
			if ($input->getOption('overall-threshold') !== null) {
				$settings['overall']['enabled'] = $input->getOption('overall-threshold');
				$changed = true;
			}
			if ($input->getOption('set-trunk-threshold') !== null) {
				list($trunk, $value) = $this->parseAssignment($input->getOption('set-trunk-threshold'));
				if (!isset($settings['trunks'][$trunk])) throw new \InvalidArgumentException('Unknown PJSIP trunk: ' . $trunk);
				$settings['trunks'][$trunk]['threshold'] = $value;
				$settings['trunks'][$trunk]['enabled'] = ((int)$value > 0);
				$changed = true;
			}
			if ($input->getOption('trunk-threshold') !== null) {
				list($trunk, $value) = $this->parseAssignment($input->getOption('trunk-threshold'));
				if (!isset($settings['trunks'][$trunk])) throw new \InvalidArgumentException('Unknown PJSIP trunk: ' . $trunk);
				$settings['trunks'][$trunk]['enabled'] = $value;
				$changed = true;
			}
			if ($input->getOption('alerts') !== null) {
				$settings['alerts_enabled'] = $input->getOption('alerts');
				$changed = true;
			}
			if ($input->getOption('overall-alert') !== null) {
				$settings['overall']['alert_enabled'] = $input->getOption('overall-alert');
				$changed = true;
			}
			if ($input->getOption('trunk-alert') !== null) {
				list($trunk, $value) = $this->parseAssignment($input->getOption('trunk-alert'));
				if (!isset($settings['trunks'][$trunk])) throw new \InvalidArgumentException('Unknown PJSIP trunk: ' . $trunk);
				$settings['trunks'][$trunk]['alert_enabled'] = $value;
				$changed = true;
			}
			if ($input->getOption('recovery') !== null) {
				$settings['recovery_enabled'] = $input->getOption('recovery');
				$changed = true;
			}
			if ($input->getOption('alert-email') !== null) {
				$settings['alert_email'] = $input->getOption('alert-email');
				$changed = true;
			}
			if ($changed) $settings = $cc->saveLiveSettings($settings);
		} catch (\Exception $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');
			return 1;
		}

		if ($input->getOption('live')) {
			$result = $cc->getLiveStatus();
			$this->writeLive($output, $result, $json);
			return !empty($result['available']) ? 0 : 1;
		}
		if ($input->getOption('historical-graph') !== null) {
			$start = $cc->normaliseStartDate($input->getOption('start'));
			$end = $cc->normaliseEndDate($input->getOption('end'));
			if ($start === null || $end === null) {
				$output->writeln('<error>Historical graph requires valid --start and --end values.</error>');
				return 1;
			}
			try {
				$result = $cc->getHistoricalGraph($input->getOption('historical-graph'), $start, $end, (string)$input->getOption('graph-trunk'));
				$this->writeStructured($output, $result, $json, 'Historical graph');
				return 0;
			} catch (\Exception $exception) {
				$output->writeln('<error>' . $exception->getMessage() . '</error>');
				return 1;
			}
		}
		if ($changed || $input->getOption('settings')) {
			$this->writeSettings($output, $settings, $json);
			return 0;
		}
		return null;
	}

	private function parseAssignment($value): array {
		$parts = explode('=', (string)$value, 2);
		if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
			throw new \InvalidArgumentException('Expected TRUNK=VALUE.');
		}
		return [trim($parts[0]), trim($parts[1])];
	}

	private function writeLive(OutputInterface $output, array $snapshot, bool $json): void {
		if ($json) {
			$output->writeln(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		$output->writeln('Live status: ' . (!empty($snapshot['available']) ? 'AVAILABLE' : 'UNAVAILABLE'));
		$output->writeln('Updated:     ' . $snapshot['generated_at']);
		if (empty($snapshot['available'])) {
			$output->writeln('Message:     ' . $snapshot['message']);
			return;
		}
		$output->writeln('Overall Live Concurrency (active monitored PJSIP legs): ' . $snapshot['overall']['current']);
		foreach ($snapshot['overall']['calls'] as $call) $output->writeln('  ' . $call['channel'] . ' ' . $call['state'] . ' ' . $call['duration_seconds'] . 's');
		foreach ($snapshot['trunks'] as $trunk => $result) {
			$output->writeln(sprintf('%-24s %d (%d inbound, %d outbound, %d unknown)', $trunk, $result['current'], $result['direction_counts']['inbound'], $result['direction_counts']['outbound'], $result['direction_counts']['unknown']));
			foreach ($result['calls'] as $call) $output->writeln('  ' . $call['channel'] . ' ' . $call['state'] . ' ' . $call['duration_seconds'] . 's');
		}
	}

	private function writeSettings(OutputInterface $output, array $settings, bool $json): void {
		if ($json) {
			$output->writeln(json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		$output->writeln('Live refresh interval: ' . $settings['refresh_interval'] . ' seconds');
		$output->writeln('Threshold alerts:      ' . ($settings['alerts_enabled'] ? 'enabled' : 'disabled'));
		$output->writeln('Recovery notifications:' . ($settings['recovery_enabled'] ? ' enabled' : ' disabled'));
		$output->writeln('Alert email:           ' . ($settings['alert_email'] !== '' ? $settings['alert_email'] : '(not configured)'));
		$output->writeln(sprintf('Overall threshold:     %s %d; alert %s', $settings['overall']['enabled'] ? 'enabled' : 'disabled', $settings['overall']['threshold'], $settings['overall']['alert_enabled'] ? 'enabled' : 'disabled'));
		foreach ($settings['trunks'] as $trunk => $scope) {
			$output->writeln(sprintf('%-24s threshold %s %d; alert %s', $trunk, $scope['enabled'] ? 'enabled' : 'disabled', $scope['threshold'], $scope['alert_enabled'] ? 'enabled' : 'disabled'));
		}
	}

	private function writeMonitorStatus(OutputInterface $output, array $status, bool $json): void {
		if ($json) {
			$output->writeln(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		$output->writeln('Alert monitor: ' . strtoupper(isset($status['status']) ? $status['status'] : 'unknown'));
		if (!empty($status['pid'])) $output->writeln('PID:           ' . (int)$status['pid']);
		if (isset($status['restarts'])) $output->writeln('PM2 restarts:  ' . (int)$status['restarts']);
		if (isset($status['ami_status'])) $output->writeln('AMI:           ' . $status['ami_status']);
		if (isset($status['mailer_status'])) $output->writeln('Mail worker:   ' . $status['mailer_status']);
		if (!empty($status['last_successful_snapshot_at'])) $output->writeln('Last snapshot: ' . date('Y-m-d H:i:s', (int)$status['last_successful_snapshot_at']));
		if (!empty($status['message'])) $output->writeln('Message:       ' . $status['message']);
	}

	private function writeStructured(OutputInterface $output, array $result, bool $json, string $label): void {
		if ($json) {
			$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		$output->writeln($label . ':');
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	private function findHistoricalReport($cc, string $needle): ?array {
		$reports = $cc->getHistoricalReports()['reports'];
		foreach ($reports as $report) {
			if ($needle === $report['id'] || $needle === (string)$report['number']) return $report;
		}
		return null;
	}

	private function writeHistoricalReports(OutputInterface $output, array $reports, $activeId, bool $json): void {
		if ($json) {
			$output->writeln(json_encode(['reports' => $reports, 'active_id' => $activeId], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		if (empty($reports)) {
			$output->writeln('No historical report tabs are currently persisted.');
			return;
		}
		foreach ($reports as $report) {
			$active = ($report['id'] === $activeId) ? ' (active)' : '';
			$missing = !empty($report['missing_reference']) ? ' [missing reference]' : '';
			$output->writeln(sprintf(
				'%s%s: mode=%s engine=%s preset=%s range=%s..%s%s',
				$report['name'], $active, $report['mode'], $report['engine'], $report['preset'],
				$report['range_from'], $report['range_to'], $missing
			));
		}
	}

	private function demoPlan(int $seed, string $size): array {
		$seed = $seed ?: time();
		$size = in_array($size, ['light', 'medium', 'heavy'], true) ? $size : 'light';
		$hours = ['light' => 1, 'medium' => 3, 'heavy' => 6];
		$dayOffset = (int)(floor($seed / 7) % 365);
		$hour = 8 + (int)(floor($seed / 13) % 8);
		$minute = (int)(floor($seed / 17) % 4) * 15;
		$start = mktime($hour, $minute, 0, 1, 1 + $dayOffset, 2001);
		return [
			'start' => date('Y-m-d H:i:s', $start),
			'end' => date('Y-m-d H:i:s', $start + ($hours[$size] * 3600)),
		];
	}

	private function formatBytes(int $bytes): string {
		if ($bytes >= 1048576) {
			return (string)round($bytes / 1048576) . 'MB';
		}
		if ($bytes >= 1024) {
			return (string)round($bytes / 1024) . 'KB';
		}
		return $bytes . 'B';
	}
}
