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
			->addOption('demo-size', null, InputOption::VALUE_REQUIRED, 'Demo size: light, medium, or heavy', 'light')
			->addOption('demo-seed', null, InputOption::VALUE_REQUIRED, 'Demo random seed', '0')
			->addOption('csv', null, InputOption::VALUE_NONE, 'Output CSV instead of formatted text');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$mode_raw = $input->getOption('mode');
		$start_raw = $input->getOption('start');
		$end_raw = $input->getOption('end');
		$demo_size = $input->getOption('demo-size');
		$demo_seed = $input->getOption('demo-seed');
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
			$start = $start_raw ?: '2001-01-01 09:00:00';
			$end = $end_raw ?: '2001-01-01 10:00:00';
		} else {
			$start = $cc->normaliseStartDate($start_raw);
			$end = $cc->normaliseEndDate($end_raw);
			if ($start === null) { $output->writeln('<error>Invalid start date.</error>'); return 1; }
			if ($end === null) { $output->writeln('<error>Invalid end date.</error>'); return 1; }
		}

		try {
			$results = $cc->calculate($mode, $start, $end, true, [
				'demo_size' => $demo_size,
				'demo_seed' => $demo_seed,
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
		$output->writeln('<info>Concurrency Count by 20tele.com</info>');
		$output->writeln('Mode:           ' . ucfirst($results['mode']));
		$output->writeln('From:           ' . $results['start']);
		$output->writeln('To:             ' . $results['end']);
		$output->writeln('Rows processed: ' . $results['rows_processed']);
		$output->writeln('');

		if (!empty($results['empty_message'])) {
			$output->writeln('<comment>' . $results['empty_message'] . '</comment>');
			$output->writeln('');
			return 0;
		}

		if ($results['mode'] === 'demo') {
			$output->writeln('Extension demo:');
			$output->writeln(sprintf('%-24s  %s', 'Extension', 'Max concurrent'));
			foreach ($results['per_name'] as $name => $count) {
				$marker = ($count === $results['global_max'] && $results['global_max'] > 0) ? '*' : ' ';
				$output->writeln(sprintf('%s%-23s  %d', $marker, $name, $count));
			}
			$output->writeln('');
			$output->writeln('<info>Extension global maximum: ' . $results['global_max'] . '</info>');
			$output->writeln('');
			$output->writeln('<info>Group demo maximum concurrent calls overall: ' . $results['max_concurrency'] . '</info>');
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
}
