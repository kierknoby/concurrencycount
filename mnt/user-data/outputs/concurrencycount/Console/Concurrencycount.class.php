<?php
/**
 * fwconsole concurrencycount command.
 *
 * Usage:
 *   fwconsole concurrencycount --mode=trunk --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59"
 *   fwconsole concurrencycount --mode=extension --start=... --end=...
 *   fwconsole concurrencycount --mode=group --start=... --end=... --csv
 *
 * Mode accepts the same abbreviations as the original bash CLI
 * (trunks/trunk/.../t, extensions/ext/.../e, groups/group/.../g).
 */

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Concurrencycount extends Command {

	protected function configure() {
		$this->setName('concurrencycount')
			->setDescription('Calculate maximum concurrent PJSIP calls per trunk, extension, or group')
			->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Mode: trunk, extension, or group (abbreviations accepted)', 'trunk')
			->addOption('start', 's', InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD HH:MM:SS (or shorthand)')
			->addOption('end', 'e', InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD HH:MM:SS (or shorthand)')
			->addOption('csv', null, InputOption::VALUE_NONE, 'Output CSV instead of formatted text');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$mode_raw = $input->getOption('mode');
		$start_raw = $input->getOption('start');
		$end_raw = $input->getOption('end');
		$csv = $input->getOption('csv');

		$cc = \FreePBX::Concurrencycount();

		$mode = $cc->normaliseMode($mode_raw);
		if ($mode === null) {
			$output->writeln('<error>Invalid mode. Use trunks, extensions, or group (abbreviations accepted).</error>');
			return 1;
		}

		if (!$start_raw || !$end_raw) {
			$output->writeln('<error>Both --start and --end are required.</error>');
			return 1;
		}

		$start = $cc->normaliseStartDate($start_raw);
		$end = $cc->normaliseEndDate($end_raw);
		if ($start === null) { $output->writeln('<error>Invalid start date.</error>'); return 1; }
		if ($end === null) { $output->writeln('<error>Invalid end date.</error>'); return 1; }

		try {
			$results = $cc->calculate($mode, $start, $end, true);
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($csv) {
			$output->write($cc->resultsToCsv($results));
			return 0;
		}

		$output->writeln('');
		$output->writeln('<info>Concurrency Count- NOT CURRENTLY SUITABLE FOR PRODUCTION</info>');
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

		if ($results['mode'] === 'group') {
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
