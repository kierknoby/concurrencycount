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
			->addOption('csv', null, InputOption::VALUE_NONE, 'Output CSV instead of formatted text');
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
				'demo_engines' => ($mode === 'demo' && $compare) ? explode(',', $compare) : ['original'],
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
