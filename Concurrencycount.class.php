<?php
/**
 * Concurrency Count for FreePBX 17
 *
 * Web module port of the Concurrency Count CLI tool- NOT CURRENTLY SUITABLE FOR PRODUCTION.
 * Behaviour mirrors the bash script: same modes, same date handling,
 * same validation, same algorithm, same warnings.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

class Concurrencycount implements \BMO {

	const MAX_RUNTIME = 3600;
	/** Fallback only. Authoritative version lives in module.xml and is read by getVersion(). */
	const VERSION = '2.0.0';
	const MAX_ATTEMPTS = 3;

	private $FreePBX;
	private $cdrdb;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
		$this->cdrdb = $freepbx->Cdr->getCdrDbHandle();
	}

	public function install(): void {}
	public function uninstall(): void {}
	public function backup(): void {}
	public function restore($backup): void {}
	public function doConfigPageInit($page): void {}

	/**
	 * Get the running version of this module. Authoritative source is the
	 * module.xml manifest, read via FreePBX's module info API. Falls back to
	 * the class constant if the API is unavailable (e.g. during install
	 * before modules table is populated).
	 */
	public function getVersion(): string {
		try {
			$info = \FreePBX::Modules()->getInfo('concurrencycount');
			if (isset($info['concurrencycount']['version'])) {
				return (string)$info['concurrencycount']['version'];
			}
		} catch (\Exception $e) {
			// Fall through
		}
		return self::VERSION;
	}

	/**
	 * Render the module page. Following Frogman's pattern of returning HTML
	 * via load_view() from a single entry point, with module metadata passed
	 * through to the template.
	 */
	public function showPage(): string {
		return load_view(__DIR__ . '/views/main.php', [
			'moduleVersion' => $this->getVersion(),
		]);
	}

	/**
	 * AJAX request allowlist.
	 */
	public function ajaxRequest($req, &$setting): bool {
		switch ($req) {
			case 'wizardstep':
			case 'run':
			case 'download':
			case 'downloadcdr':
			case 'email':
			case 'gettrunks':
				return true;
		}
		return false;
	}

	/**
	 * Custom handler for streaming binary output. Returning true tells the
	 * framework to skip the JSON wrapper and exit.
	 */
	public function ajaxCustomHandler(): bool {
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
		if ($command === 'download') {
			$this->streamDownload();
			return true;
		}
		if ($command === 'downloadcdr') {
			$this->streamDemoCdrDownload();
			return true;
		}
		return false;
	}

	/**
	 * AJAX dispatcher for JSON responses.
	 */
	public function ajaxHandler(): array {
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';

		switch ($command) {
			case 'wizardstep':
				return $this->handleWizardStep();
			case 'run':
				return $this->handleRun();
			case 'email':
				return $this->handleEmail();
			case 'gettrunks':
				return ['status' => true, 'trunks' => $this->getTrunks()];
		}
		return ['status' => false, 'message' => _('Unknown command')];
	}

	/* ============================================================
	 * INPUT NORMALISATION (mirrors bash)
	 * ============================================================ */

	/**
	 * Mode abbreviation matcher. Mirrors the bash case statement.
	 *
	 * trunks|trunk|trun|tru|tr|t|trks|trk|trnks|trnk
	 * extensions|extension|extensio|extensi|extens|exten|exte|ext|exts|ex|e
	 * groups|group|grou|gro|gr|g|grps|grp
	 * demo|dem|de|d
	 *
	 * @return string|null  'trunk', 'extension', 'group', 'demo', or null
	 */
	public function normaliseMode($input): ?string {
		$s = strtolower(trim((string)$input));
		$trunk_set = ['trunks','trunk','trun','tru','tr','t','trks','trk','trnks','trnk'];
		$ext_set   = ['extensions','extension','extensio','extensi','extens','exten','exte','ext','exts','ex','e'];
		$group_set = ['groups','group','grou','gro','gr','g','grps','grp'];
		$demo_set  = ['demo','dem','de','d'];

		if (in_array($s, $trunk_set, true)) return 'trunk';
		if (in_array($s, $ext_set, true))   return 'extension';
		if (in_array($s, $group_set, true)) return 'group';
		if (in_array($s, $demo_set, true))  return 'demo';
		return null;
	}

	/**
	 * Reserved words that must NOT be accepted as month input
	 * (mirrors bash "now|sec|secs|second|seconds|min|mins|..." rejection).
	 */
	private $reservedTimeWords = [
		'now','sec','secs','second','seconds','min','mins','minute','minutes',
		'hour','hours','day','days','week','weeks','fortnight','fortnights',
		'month','months','year','years','tomorrow',
	];

	/**
	 * Match the "today" shorthand prefix.
	 */
	public function isTodayShorthand($s): bool {
		$s = strtolower(trim((string)$s));
		return in_array($s, ['t','to','tod','toda','today'], true);
	}

	/**
	 * Match the "yesterday" shorthand prefix.
	 */
	public function isYesterdayShorthand($s): bool {
		$s = strtolower(trim((string)$s));
		return in_array($s, ['y','ye','yes','yest','yeste','yester','yesterd','yesterda','yesterday'], true);
	}

	/**
	 * Parse a month input into a numeric month and human name.
	 * Returns ['num' => '04', 'name' => 'April'] or null.
	 * Mirrors bash logic: accept 1-12 numeric, or month name (full or short).
	 */
	public function parseMonth($input): ?array {
		$s = strtolower(trim((string)$input));
		if ($s === '') return null;

		if (in_array($s, $this->reservedTimeWords, true)) return null;
		if (preg_match('/^[0-9]{3,}$/', $s)) return null;
		if (preg_match('/^[0-9]{4}-$/', $s)) return null;

		if (preg_match('/^[0-9]{1,2}$/', $s)) {
			$n = (int)$s;
			if ($n >= 1 && $n <= 12) {
				$num = sprintf('%02d', $n);
				$name = date('F', strtotime("$num/01"));
				return ['num' => $num, 'name' => $name];
			}
			return null;
		}

		$ts = strtotime($s . ' 1');
		if ($ts !== false) {
			$num = date('m', $ts);
			if ((int)$num >= 1 && (int)$num <= 12) {
				$name = date('F', $ts);
				return ['num' => $num, 'name' => $name];
			}
		}
		return null;
	}

	/**
	 * Normalise a year input (Y, YY, YYYY) to a full year integer, or null.
	 * Mirrors bash:
	 *   1 digit  -> 200X
	 *   2 digits -> 20XX (must not exceed current year)
	 *   4 digits -> as-is (must be 2000..current)
	 */
	public function normaliseYear($input): ?int {
		$s = trim((string)$input);
		$current = (int)date('Y');

		if (preg_match('/^[0-9]$/', $s)) {
			return 2000 + (int)$s;
		}
		if (preg_match('/^[0-9]{2}$/', $s)) {
			$y = 2000 + (int)$s;
			if ($y > $current) return null;
			return $y;
		}
		if (preg_match('/^[0-9]{4}$/', $s)) {
			$y = (int)$s;
			if ($y < 2000 || $y > $current) return null;
			return $y;
		}
		return null;
	}

	/**
	 * Partial date validator. Mirrors bash validate_partial_date().
	 * Accepts: YYYY, YYYY-MM, YYYY-MM-DD, YYYY-MM-DD HH:MM:SS.
	 */
	public function validatePartialDate($s): bool {
		$s = (string)$s;
		if (!preg_match('/^([0-9]{4})(-([0-9]{1,2})(-([0-9]{1,2}))?)?( ([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2}))?$/', $s, $m)) {
			return false;
		}
		$y = (int)$m[1];
		$mo = isset($m[3]) ? (int)$m[3] : 0;
		$d  = isset($m[5]) ? (int)$m[5] : 0;
		$H  = isset($m[7]) ? $m[7] : '';
		$M  = isset($m[8]) ? $m[8] : '';
		$S  = isset($m[9]) ? $m[9] : '';

		if ($y < 2000 || $y > 2099) return false;
		if ($mo === 0 && !isset($m[3])) return true;
		if ($mo < 1 || $mo > 12) return false;

		if ($d === 0 && !isset($m[5])) return true;
		$max_day = (int)date('d', strtotime("$y-$mo-01 +1 month -1 day"));
		if ($d < 1 || $d > $max_day) return false;

		if ($H === '' && $M === '' && $S === '') return true;
		if ($H === '' || $M === '' || $S === '') return false;
		if ((int)$H > 23 || (int)$M > 59 || (int)$S > 59) return false;
		return true;
	}

	/**
	 * Expand a partial start-date string into full 'YYYY-MM-DD HH:MM:SS'.
	 * Mirrors the bash branches in get_date_range() for start_date input.
	 *
	 * Returns string or null on failure.
	 */
	public function normaliseStartDate($input): ?string {
		$s = trim((string)$input);
		$current_year = (int)date('Y');

		if ($s === '') {
			return '2000-01-01 00:00:00';
		}

		if ($this->isTodayShorthand($s) || $this->isYesterdayShorthand($s)) {
			return null;
		}

		if (preg_match('/^[0-9]{3}$/', $s)) return null;

		if (preg_match('/^[0-9]$/', $s)) {
			return '200' . $s . '-01-01 00:00:00';
		}
		if (preg_match('/^[0-9]{2}$/', $s)) {
			$y = 2000 + (int)$s;
			if ($y > $current_year) return null;
			return sprintf('%04d-01-01 00:00:00', $y);
		}
		if (preg_match('/^[0-9]{4}$/', $s)) {
			$y = (int)$s;
			if ($y < 2000 || $y > $current_year) return null;
			return sprintf('%04d-01-01 00:00:00', $y);
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $s)) {
			return $s . '-01 00:00:00';
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $s)) {
			return $s . ' 00:00:00';
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $s)) {
			if (!$this->validatePartialDate($s)) return null;
			return $s;
		}

		$ts = strtotime($s);
		if ($ts !== false) {
			$out = date('Y-m-d H:i:s', $ts);
			if ($ts > time()) return null;
			return $out;
		}
		return null;
	}

	/**
	 * Expand a partial end-date string into full 'YYYY-MM-DD HH:MM:SS'.
	 * Mirrors the bash branches for end_date input:
	 * - blank   -> now
	 * - current year -> now
	 * - other year   -> 31 Dec 23:59:59
	 * - YYYY-MM -> last day of that month, or now if current month
	 * - shorthand year cases as above
	 */
	public function normaliseEndDate($input): ?string {
		$s = trim((string)$input);
		$current_year = (int)date('Y');
		$current_month = (int)date('m');

		if ($s === '') {
			return date('Y-m-d H:i:s');
		}

		if ($this->isTodayShorthand($s) || $this->isYesterdayShorthand($s)) {
			return null;
		}

		if (preg_match('/^[0-9]{3}$/', $s)) return null;

		if (preg_match('/^[0-9]$/', $s)) {
			$y = 2000 + (int)$s;
			if ($y === $current_year) return date('Y-m-d H:i:s');
			return sprintf('%04d-12-31 23:59:59', $y);
		}
		if (preg_match('/^[0-9]{2}$/', $s)) {
			$y = 2000 + (int)$s;
			if ($y > $current_year) return null;
			if ($y === $current_year) return date('Y-m-d H:i:s');
			return sprintf('%04d-12-31 23:59:59', $y);
		}
		if (preg_match('/^[0-9]{4}$/', $s)) {
			$y = (int)$s;
			if ($y < 2000 || $y > $current_year) return null;
			if ($y === $current_year) return date('Y-m-d H:i:s');
			return sprintf('%04d-12-31 23:59:59', $y);
		}
		if (preg_match('/^([0-9]{4})-([0-9]{2})$/', $s, $m)) {
			$y = (int)$m[1]; $mo = (int)$m[2];
			if ($mo === $current_month && $y === $current_year) {
				return date('Y-m-d H:i:s');
			}
			return date('Y-m-d 23:59:59', strtotime("$y-$mo-01 +1 month -1 day"));
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $s)) {
			$today = date('Y-m-d');
			if ($s === $today) return date('Y-m-d H:i:s');
			return $s . ' 23:59:59';
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $s)) {
			if (!$this->validatePartialDate($s)) return null;
			return $s;
		}

		$ts = strtotime($s);
		if ($ts !== false) {
			$parsed = date('Y-m-d', $ts);
			$today = date('Y-m-d');
			if ($parsed === $today) return date('Y-m-d H:i:s');
			return date('Y-m-d H:i:s', $ts);
		}
		return null;
	}

	/**
	 * Resolve a (mode, date-type, payload) wizard request into a final
	 * start/end pair. Used by the wizard endpoint to validate each step
	 * the same way the bash does when prompting interactively.
	 *
	 * Payload examples:
	 *   ['kind' => 'today']
	 *   ['kind' => 'yesterday']
	 *   ['kind' => 'month', 'month' => 'April', 'year' => '26']
	 *   ['kind' => 'custom', 'start' => '...', 'end' => '...']
	 */
	public function resolveDateRange(array $payload): array {
		$kind = isset($payload['kind']) ? $payload['kind'] : '';

		if ($kind === 'today') {
			return [
				'start' => date('Y-m-d 00:00:00'),
				'end'   => date('Y-m-d H:i:s'),
			];
		}
		if ($kind === 'yesterday') {
			return [
				'start' => date('Y-m-d 00:00:00', strtotime('yesterday')),
				'end'   => date('Y-m-d 23:59:59', strtotime('yesterday')),
			];
		}
		if ($kind === 'month') {
			$month = $this->parseMonth(isset($payload['month']) ? $payload['month'] : '');
			if ($month === null) {
				throw new \Exception(_('Invalid month entered.'));
			}
			$year = $this->normaliseYear(isset($payload['year']) ? $payload['year'] : '');
			if ($year === null) {
				throw new \Exception(_('Year out of allowed range.'));
			}
			$current_year = (int)date('Y');
			$current_month = date('m');
			if ($month['num'] === $current_month && $year === $current_year) {
				return [
					'start' => sprintf('%04d-%s-01 00:00:00', $year, $month['num']),
					'end'   => date('Y-m-d H:i:s'),
				];
			}
			return [
				'start' => sprintf('%04d-%s-01 00:00:00', $year, $month['num']),
				'end'   => date('Y-m-d 23:59:59', strtotime(sprintf('%04d-%s-01 +1 month -1 day', $year, $month['num']))),
			];
		}
		if ($kind === 'custom') {
			$start = $this->normaliseStartDate(isset($payload['start']) ? $payload['start'] : '');
			$end   = $this->normaliseEndDate(isset($payload['end']) ? $payload['end'] : '');
			if ($start === null) throw new \Exception(_('Invalid start date entered.'));
			if ($end === null) throw new \Exception(_('Invalid end date entered.'));

			$st = strtotime($start);
			$et = strtotime($end);
			$now = time();
			if ($st > $now) throw new \Exception(_('Start date is in the future.'));
			if ($et > $now + 1) throw new \Exception(_('End date is in the future.'));
			if ($et <= $st) throw new \Exception(_('End date entered is before start date.'));
			return ['start' => $start, 'end' => $end];
		}
		throw new \Exception(_('Unknown date kind.'));
	}

	/**
	 * Wizard validation endpoint. The client calls this per step and the
	 * server validates the same way the bash does, returning either the
	 * normalised value or an error so the modal can show "attempt X of 3".
	 */
	private function handleWizardStep(): array {
		$step = isset($_REQUEST['step']) ? $_REQUEST['step'] : '';

		try {
			if ($step === 'mode') {
				$mode = $this->normaliseMode(isset($_REQUEST['value']) ? $_REQUEST['value'] : '');
				if ($mode === null) {
					throw new \Exception(_('Invalid mode entered. Please enter trunks, extensions, group, or demo.'));
				}
				return ['status' => true, 'value' => $mode];
			}
			if ($step === 'month') {
				$m = $this->parseMonth(isset($_REQUEST['value']) ? $_REQUEST['value'] : '');
				if ($m === null) {
					throw new \Exception(_('Invalid month entered.'));
				}
				return ['status' => true, 'month' => $m];
			}
			if ($step === 'year') {
				$y = $this->normaliseYear(isset($_REQUEST['value']) ? $_REQUEST['value'] : '');
				if ($y === null) {
					throw new \Exception(_('Year out of allowed range.'));
				}
				return ['status' => true, 'year' => $y];
			}
			if ($step === 'startdate') {
				$d = $this->normaliseStartDate(isset($_REQUEST['value']) ? $_REQUEST['value'] : '');
				if ($d === null) {
					throw new \Exception(_('Invalid start date entered.'));
				}
				return ['status' => true, 'value' => $d];
			}
			if ($step === 'enddate') {
				$d = $this->normaliseEndDate(isset($_REQUEST['value']) ? $_REQUEST['value'] : '');
				if ($d === null) {
					throw new \Exception(_('Invalid end date entered.'));
				}
				return ['status' => true, 'value' => $d];
			}
			return ['status' => false, 'message' => _('Unknown wizard step.')];
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	/* ============================================================
	 * RUN ENDPOINT
	 * ============================================================ */

	private function handleRun(): array {
		$mode = $this->normaliseMode(isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '');
		$start = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '';
		$end = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '';
		$confirm_overrun = !empty($_REQUEST['confirm_overrun']);
		$options = $this->requestDemoOptions();

		if ($mode === null) {
			return ['status' => false, 'message' => _('Invalid mode entered. Please enter trunks, extensions, group, or demo.')];
		}

		try {
			$results = $this->calculate($mode, $start, $end, $confirm_overrun, $options);
			return ['status' => true, 'results' => $results];
		} catch (RuntimeOverrunPending $rop) {
			return [
				'status' => false,
				'overrun_warning' => true,
				'message' => $rop->getMessage(),
				'estimated_remaining' => $rop->estimatedRemaining,
				'runtime_remaining' => $rop->runtimeRemaining,
			];
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	/* ============================================================
	 * CALCULATION (mirrors bash)
	 * ============================================================ */

	/**
	 * Trunk discovery. Mirrors bash get_trunks():
	 *   asterisk -rx "pjsip show endpoints" | awk Endpoint | cut / | grep -vE '^[0-9]+$' | sort -u
	 */
	public function getTrunks(): array {
		$trunks = [];
		$out = [];
		$rc = 0;
		exec('asterisk -rx "pjsip show endpoints" 2>/dev/null', $out, $rc);
		if ($rc !== 0) {
			return $trunks;
		}
		foreach ($out as $line) {
			if (preg_match('/^\s*Endpoint:\s+(\S+)/', $line, $m)) {
				$name = $m[1];
				$parts = explode('/', $name);
				$name = trim($parts[0]);
				if ($name === '<Endpoint' || strpos($name, '<Endpoint') === 0) continue;
				if ($name === '') continue;
				if (preg_match('/^[0-9]+$/', $name)) continue;
				$trunks[$name] = true;
			}
		}
		$trunks = array_keys($trunks);
		sort($trunks);
		return $trunks;
	}

	/**
	 * Dispatch by mode.
	 */
	public function calculate(string $mode, string $start, string $end, bool $confirm_overrun = false, array $options = []): array {
		set_time_limit(self::MAX_RUNTIME + 60);
		$started_at = time();

		if ($mode === 'demo') {
			return $this->calculateDemo($start, $end, $started_at, $options);
		}
		if ($mode === 'group') {
			return $this->calculateGroup($start, $end, $started_at, $confirm_overrun);
		}
		return $this->calculatePerName($mode, $start, $end, $started_at, $confirm_overrun);
	}

	/**
	 * Temporary demo fixture. Rows are inserted with a unique accountcode,
	 * counted via the normal CDR queries, then removed in a finally block.
	 */
	private function calculateDemo(string $start, string $end, int $started_at, array $options): array {
		$size = $this->normaliseDemoSize(isset($options['demo_size']) ? $options['demo_size'] : 'light');
		$report = $this->normaliseDemoReport(isset($options['demo_report']) ? $options['demo_report'] : 'extension');
		$row_count = $this->normaliseDemoRows(isset($options['demo_rows']) ? $options['demo_rows'] : 0, $size);
		$seed = isset($options['demo_seed']) ? (int)$options['demo_seed'] : 0;
		if ($seed === 0) {
			$seed = random_int(1, 0x7fffffff);
		}
		$range = $this->normaliseDemoRange($start, $end);
		$start = $range['start'];
		$end = $range['end'];
		$accountcode = 'CCDEMO' . substr(hash('sha1', microtime(true) . random_int(0, PHP_INT_MAX)), 0, 8);
		$demo_trunk = '';
		if ($report === 'trunk') {
			$trunks = $this->getTrunks();
			if (empty($trunks)) {
				throw new \Exception(_('Demo trunk mode requires at least one non-numeric PJSIP trunk on the PBX.'));
			}
			$demo_trunk = $trunks[0];
		}
		$rows = $this->buildDemoRows($start, $end, $size, $seed, $accountcode, $report, $demo_trunk, $row_count);
		$expected = ($report === 'group')
			? $this->expectedDemoGroup($rows)
			: $this->expectedDemoPerName($rows, $report);
		$inserted = 0;
		$result = null;
		$cleanup = ['rows_removed' => 0, 'cleanup_remaining' => 0];

		try {
			foreach ($rows as $row) {
				$this->insertDemoCdrRow($row);
				$inserted++;
			}

			if ($report === 'group') {
				$actual = $this->calculateGroup($start, $end, $started_at, true, $accountcode);
				$accuracy = $this->assessDemoGroupAccuracy($expected, $actual);
			} else {
				$actual = $this->calculatePerName($report, $start, $end, $started_at, true, $accountcode);
				$accuracy = $this->assessDemoPerNameAccuracy($expected, $actual);
			}

			$result = [
				'mode' => 'demo', 'start' => $start, 'end' => $end,
				'per_name' => isset($actual['per_name']) ? $actual['per_name'] : [],
				'global_max' => isset($actual['global_max']) ? $actual['global_max'] : 0,
				'max_concurrency' => isset($actual['max_concurrency']) ? $actual['max_concurrency'] : 0,
				'peak_ranges' => isset($actual['peak_ranges']) ? $actual['peak_ranges'] : [],
				'expected_per_name' => isset($expected['per_name']) ? $expected['per_name'] : [],
				'expected_global_max' => isset($expected['global_max']) ? $expected['global_max'] : 0,
				'expected_max_concurrency' => isset($expected['max_concurrency']) ? $expected['max_concurrency'] : 0,
				'expected_peak_ranges' => isset($expected['peak_ranges']) ? $expected['peak_ranges'] : [],
				'accuracy_status' => $accuracy ? 'pass' : 'fail',
				'rows_processed' => $inserted,
				'rows_inserted' => $inserted,
				'demo_report' => $report,
				'demo_size' => $size,
				'demo_seed' => (string)$seed,
				'demo_run_id' => $accountcode,
				'warning' => _('Demo mode temporarily inserted synthetic CDR rows and removed them automatically after the run.'),
			];
		} finally {
			$cleanup = $this->cleanupDemoCdrRows($accountcode);
		}
		if ($result === null) {
			throw new \Exception(_('Demo run failed before results were produced.'));
		}
		$result['rows_removed'] = $cleanup['rows_removed'];
		$result['cleanup_remaining'] = $cleanup['cleanup_remaining'];
		$result['cleanup_status'] = ($cleanup['cleanup_remaining'] === 0) ? 'clean' : 'check';
		return $result;
	}

	private function requestDemoOptions(): array {
		return [
			'demo_report' => isset($_REQUEST['demo_report']) ? $_REQUEST['demo_report'] : 'extension',
			'demo_size' => isset($_REQUEST['demo_size']) ? $_REQUEST['demo_size'] : 'light',
			'demo_seed' => isset($_REQUEST['demo_seed']) ? $_REQUEST['demo_seed'] : 0,
			'demo_rows' => isset($_REQUEST['demo_rows']) ? $_REQUEST['demo_rows'] : 0,
		];
	}

	private function normaliseDemoReport($report): string {
		$report = strtolower(trim((string)$report));
		if (in_array($report, ['trunk', 'extension', 'group'], true)) {
			return $report;
		}
		return 'extension';
	}

	private function normaliseDemoSize($size): string {
		$size = strtolower(trim((string)$size));
		if (in_array($size, ['light', 'medium', 'heavy'], true)) {
			return $size;
		}
		return 'light';
	}

	private function normaliseDemoRows($rows, string $size): int {
		$defaults = ['light' => 50, 'medium' => 1000, 'heavy' => 10000];
		$max = ['light' => 250, 'medium' => 3000, 'heavy' => 15000];
		$rows = (int)$rows;
		if ($rows <= 0) {
			return $defaults[$size];
		}
		return max(1, min($rows, $max[$size]));
	}

	private function normaliseDemoRange(string $start, string $end): array {
		$start = $this->normaliseStartDate($start);
		$end = $this->normaliseEndDate($end);
		if ($start === null || $end === null) {
			throw new \Exception(_('Invalid demo date range.'));
		}
		$st = strtotime($start);
		$et = strtotime($end);
		if ($et <= $st) {
			throw new \Exception(_('Demo end date must be after start date.'));
		}
		if (($et - $st) < 900) {
			throw new \Exception(_('Demo range must be at least 15 minutes.'));
		}
		if (($et - $st) > 604800) {
			throw new \Exception(_('Demo range must be no more than 7 days.'));
		}
		return ['start' => $start, 'end' => $end];
	}

	private function buildDemoRows(string $start, string $end, string $size, int $seed, string $accountcode, string $report, string $demo_trunk = '', int $count = 0): array {
		$counts = ['light' => 50, 'medium' => 1000, 'heavy' => 10000];
		if ($count <= 0) {
			$count = $counts[$size];
		}
		$start_ts = strtotime($start);
		$end_ts = strtotime($end);
		$span = max(900, $end_ts - $start_ts);
		$min_duration = ($size === 'heavy') ? 300 : 180;
		$max_duration = ($size === 'heavy') ? 1800 : (($size === 'medium') ? 1200 : 600);
		$state = $seed;
		$extensions = ['101', '102', '103', '104', '105', '106', '107', '108'];
		$rows = [];

		for ($i = 0; $i < $count; $i++) {
			$state = $this->demoRand($state);
			$offset_limit = max(1, $span - $min_duration);
			$offset = $state % $offset_limit;
			$state = $this->demoRand($state);
			$duration = $min_duration + ($state % max(1, ($max_duration - $min_duration)));
			if (($start_ts + $offset + $duration) > $end_ts) {
				$duration = max(60, $end_ts - ($start_ts + $offset));
			}
			$state = $this->demoRand($state);
			$ext = $extensions[$state % count($extensions)];
			$calldate = date('Y-m-d H:i:s', $start_ts + $offset);
			$token = substr(hash('sha1', $accountcode . ':' . $i . ':' . $state), 0, 10);
			$is_trunk = ($report === 'trunk');
			$channel = $is_trunk ? ('PJSIP/' . $demo_trunk . '-' . $token) : ('PJSIP/' . $ext . '-' . $token);

			$rows[] = [
				'calldate' => $calldate,
				'duration' => $duration,
				'channel' => $channel,
				'dstchannel' => '',
				'src' => $is_trunk ? ('555' . sprintf('%04d', $i)) : $ext,
				'dst' => '555' . sprintf('%04d', $i),
				'accountcode' => $accountcode,
				'uniqueid' => $accountcode . '-' . $i,
				'linkedid' => $accountcode . '-' . $i,
			];
		}

		return $rows;
	}

	private function demoRand(int $state): int {
		return (int)(($state * 1103515245 + 12345) & 0x7fffffff);
	}

	private function expectedDemoPerName(array $rows, string $report): array {
		$max_concurrent = [];
		$ongoing_calls = [];
		foreach ($rows as $row) {
			$name = '';
			if ($report === 'extension') {
				if (preg_match('|PJSIP/([0-9]+)-|', $row['channel'], $m)) {
					$name = $m[1];
				}
			} else {
				if (preg_match('|PJSIP/([^ ]+)-[0-9a-f]+$|', $row['channel'], $m)) {
					$name = $m[1];
				}
			}
			if ($name === '') continue;
			$start_ts = strtotime($row['calldate']);
			$end_ts = $start_ts + (int)$row['duration'];
			for ($ts = $start_ts; $ts <= $end_ts; $ts++) {
				$key = $name . ',' . $ts;
				$ongoing_calls[$key] = isset($ongoing_calls[$key]) ? $ongoing_calls[$key] + 1 : 1;
				if (!isset($max_concurrent[$name]) || $ongoing_calls[$key] > $max_concurrent[$name]) {
					$max_concurrent[$name] = $ongoing_calls[$key];
				}
			}
		}
		ksort($max_concurrent);
		$global_max = 0;
		foreach ($max_concurrent as $v) {
			if ($v > $global_max) $global_max = $v;
		}
		return ['per_name' => $max_concurrent, 'global_max' => $global_max];
	}

	private function expectedDemoGroup(array $rows): array {
		$per_second_count = [];
		foreach ($rows as $row) {
			if (!preg_match('|^PJSIP/([0-9]+)-|', $row['channel'])) {
				continue;
			}
			$start_ts = strtotime($row['calldate']);
			$end_ts = $start_ts + (int)$row['duration'];
			for ($ts = $start_ts; $ts <= $end_ts; $ts++) {
				$per_second_count[$ts] = isset($per_second_count[$ts]) ? $per_second_count[$ts] + 1 : 1;
			}
		}
		$max = 0;
		$peak_times = [];
		foreach ($per_second_count as $ts => $count) {
			if ($count > $max) {
				$max = $count;
				$peak_times = [$ts];
			} elseif ($count === $max) {
				$peak_times[] = $ts;
			}
		}
		sort($peak_times);
		return ['max_concurrency' => $max, 'peak_ranges' => $this->coalesceRanges($peak_times)];
	}

	private function assessDemoPerNameAccuracy(array $expected, array $actual): bool {
		$actual_per_name = isset($actual['per_name']) ? $actual['per_name'] : [];
		foreach ($expected['per_name'] as $name => $count) {
			if (!isset($actual_per_name[$name]) || (int)$actual_per_name[$name] !== (int)$count) {
				return false;
			}
		}
		return (int)$expected['global_max'] === (int)(isset($actual['global_max']) ? $actual['global_max'] : 0);
	}

	private function assessDemoGroupAccuracy(array $expected, array $actual): bool {
		if ((int)$expected['max_concurrency'] !== (int)(isset($actual['max_concurrency']) ? $actual['max_concurrency'] : 0)) {
			return false;
		}
		return json_encode($expected['peak_ranges']) === json_encode(isset($actual['peak_ranges']) ? $actual['peak_ranges'] : []);
	}

	private function insertDemoCdrRow(array $row): void {
		$columns = $this->cdrdb->query('SHOW COLUMNS FROM cdr')->fetchAll(\PDO::FETCH_ASSOC);
		$insert_columns = [];
		$placeholders = [];
		$params = [];

		foreach ($columns as $col) {
			$field = $col['Field'];
			$extra = isset($col['Extra']) ? strtolower($col['Extra']) : '';
			if (strpos($extra, 'auto_increment') !== false) {
				continue;
			}
			$value = $this->demoColumnValue($field, $col, $row);
			if ($value === '__CC_SKIP__') {
				continue;
			}
			$key = ':p' . count($params);
			$insert_columns[] = '`' . str_replace('`', '``', $field) . '`';
			$placeholders[] = $key;
			$params[$key] = $value;
		}

		$sql = 'INSERT INTO cdr (' . implode(',', $insert_columns) . ') VALUES (' . implode(',', $placeholders) . ')';
		$stmt = $this->cdrdb->prepare($sql);
		$stmt->execute($params);
	}

	private function demoColumnValue(string $field, array $col, array $row) {
		$values = [
			'calldate' => $row['calldate'],
			'clid' => '"Demo" <' . $row['src'] . '>',
			'src' => $row['src'],
			'dst' => $row['dst'],
			'dcontext' => 'from-internal',
			'channel' => $row['channel'],
			'dstchannel' => $row['dstchannel'],
			'lastapp' => 'Dial',
			'lastdata' => $row['dstchannel'],
			'duration' => (int)$row['duration'],
			'billsec' => (int)$row['duration'],
			'disposition' => 'ANSWERED',
			'amaflags' => 3,
			'accountcode' => $row['accountcode'],
			'uniqueid' => $row['uniqueid'],
			'linkedid' => $row['linkedid'],
			'userfield' => 'Concurrency Count demo',
			'cnum' => $row['src'],
			'cnam' => 'Demo',
			'outbound_cnum' => $row['src'],
			'outbound_cnam' => 'Demo',
			'sequence' => 0,
		];
		if (array_key_exists($field, $values)) {
			return $values[$field];
		}
		if (isset($col['Null']) && strtoupper($col['Null']) === 'YES') {
			return null;
		}
		if (isset($col['Default']) && $col['Default'] !== null) {
			return $col['Default'];
		}
		$type = isset($col['Type']) ? strtolower($col['Type']) : '';
		if (preg_match('/int|decimal|float|double|bit|bool/', $type)) {
			return 0;
		}
		if (preg_match('/date|time|year/', $type)) {
			return $row['calldate'];
		}
		return '';
	}

	private function cleanupDemoCdrRows(string $accountcode): array {
		$stmt = $this->cdrdb->prepare('DELETE FROM cdr WHERE accountcode = :accountcode');
		$stmt->execute([':accountcode' => $accountcode]);
		$removed = $stmt->rowCount();
		$stmt = $this->cdrdb->prepare('SELECT COUNT(*) FROM cdr WHERE accountcode = :accountcode');
		$stmt->execute([':accountcode' => $accountcode]);
		return [
			'rows_removed' => $removed,
			'cleanup_remaining' => (int)$stmt->fetchColumn(),
		];
	}

	/**
	 * Per-name (trunk or extension) concurrency.
	 * Mirrors bash calculate_concurrency().
	 */
	private function calculatePerName(string $mode, string $start, string $end, int $started_at, bool $confirm_overrun, string $accountcode = ''): array {
		if ($mode === 'trunk') {
			$trunks = $this->getTrunks();
			if (empty($trunks)) {
				return $this->emptyResult($mode, $start, $end, _('No PJSIP trunks detected.'));
			}
			$rows = $this->fetchTrunkRows($trunks, $start, $end, $accountcode);
		} else {
			$rows = $this->fetchExtensionRows($start, $end, $accountcode);
		}

		if (empty($rows)) {
			return $this->emptyResult($mode, $start, $end, _('No calls found in the selected date range.'));
		}

		$max_concurrent = [];
		$ongoing_calls = [];
		$total_rows = count($rows);
		$processed = 0;
		$did_overrun_prompt = $confirm_overrun;

		foreach ($rows as $row) {
			$processed++;
			$elapsed = time() - $started_at;

			$this->checkOverrun($elapsed, $processed, $total_rows, $started_at, $did_overrun_prompt);

			$calldate = $row['calldate'];
			$duration = (int)$row['duration'];
			$chan = $row['chan'];

			if ($calldate === '' || $duration <= 0 || $chan === '') {
				continue;
			}

			$start_ts = strtotime($calldate);
			$end_ts = $start_ts + $duration;

			if ($mode === 'extension') {
				if (!preg_match('|PJSIP/([0-9]+)-|', $chan, $m)) continue;
				$name = $m[1];
			} else {
				if (!preg_match('|PJSIP/([^ ]+)-[0-9a-f]+$|', $chan, $m)) continue;
				$name = $m[1];
				if (preg_match('/^[0-9]+$/', $name)) continue;
			}

			for ($ts = $start_ts; $ts <= $end_ts; $ts++) {
				$key = $name . ',' . $ts;
				$ongoing_calls[$key] = isset($ongoing_calls[$key]) ? $ongoing_calls[$key] + 1 : 1;
				if (!isset($max_concurrent[$name]) || $ongoing_calls[$key] > $max_concurrent[$name]) {
					$max_concurrent[$name] = $ongoing_calls[$key];
				}
			}
		}

		if (empty($max_concurrent)) {
			return $this->emptyResult($mode, $start, $end, _('No calls found in the selected date range.'));
		}

		$global_max = 0;
		foreach ($max_concurrent as $v) {
			if ($v > $global_max) $global_max = $v;
		}

		$all_names = $this->buildAllNames($mode, $rows);
		ksort($all_names);
		$ordered = [];
		foreach ($all_names as $name => $_unused) {
			$ordered[$name] = isset($max_concurrent[$name]) ? $max_concurrent[$name] : 0;
		}

		return [
			'mode' => $mode, 'start' => $start, 'end' => $end,
			'per_name' => $ordered, 'global_max' => $global_max,
			'rows_processed' => $processed,
			'warning' => $this->trunkNamingWarning(),
		];
	}

	/**
	 * Build the full set of names to display, matching the bash logic
	 * (extension list from CDR; trunk list from get_trunks()).
	 */
	private function buildAllNames(string $mode, array $rows): array {
		$names = [];
		if ($mode === 'extension') {
			foreach ($rows as $r) {
				if (preg_match('|PJSIP/([0-9]+)-|', $r['chan'], $m)) {
					$names[$m[1]] = true;
				}
			}
		} else {
			foreach ($this->getTrunks() as $t) {
				$names[$t] = true;
			}
		}
		return $names;
	}

	/**
	 * Group mode. Mirrors bash calculate_group_concurrency().
	 */
	private function calculateGroup(string $start, string $end, int $started_at, bool $confirm_overrun, string $accountcode = ''): array {
		$sql = "SELECT calldate, duration, channel, dstchannel
				FROM cdr
				WHERE disposition = 'ANSWERED'
				  AND calldate BETWEEN :start AND :end
				  AND (channel LIKE 'PJSIP/%' OR dstchannel LIKE 'PJSIP/%')";
		$stmt = $this->cdrdb->prepare($sql);
		$params = [':start' => $start, ':end' => $end];
		if ($accountcode !== '') {
			$sql .= " AND accountcode = :accountcode";
			$params[':accountcode'] = $accountcode;
			$stmt = $this->cdrdb->prepare($sql);
		}
		$stmt->execute($params);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if (empty($rows)) {
			return $this->emptyResult('group', $start, $end, _('No calls found in the selected date range.'));
		}

		$per_second_count = [];
		$total_rows = count($rows);
		$processed = 0;
		$did_overrun_prompt = $confirm_overrun;

		foreach ($rows as $row) {
			$processed++;
			$elapsed = time() - $started_at;

			$this->checkOverrun($elapsed, $processed, $total_rows, $started_at, $did_overrun_prompt);

			$calldate = $row['calldate'];
			$duration = (int)$row['duration'];
			$chan = $row['channel'];
			$dstchan = $row['dstchannel'];

			if ($calldate === '' || $duration <= 0) continue;

			$start_ts = strtotime($calldate);
			$end_ts = $start_ts + $duration;
			if (($end_ts - $start_ts) > 86400) {
				$end_ts = $start_ts + 86400;
			}

			$ext1 = '';
			$ext2 = '';
			if (preg_match('|^PJSIP/([0-9]+)-|', $chan, $m)) {
				$ext1 = $m[1];
			}
			if (preg_match('|^PJSIP/([0-9]+)-|', $dstchan, $m)) {
				$ext2 = $m[1];
			}

			for ($ts = $start_ts; $ts <= $end_ts; $ts++) {
				if ($ext1 !== '') {
					$per_second_count[$ts] = isset($per_second_count[$ts]) ? $per_second_count[$ts] + 1 : 1;
				}
				if ($ext2 !== '') {
					$per_second_count[$ts] = isset($per_second_count[$ts]) ? $per_second_count[$ts] + 1 : 1;
				}
			}
		}

		$max = 0;
		$peak_times = [];
		foreach ($per_second_count as $ts => $count) {
			if ($count > $max) {
				$max = $count;
				$peak_times = [$ts];
			} elseif ($count === $max) {
				$peak_times[] = $ts;
			}
		}
		sort($peak_times);

		$ranges = $this->coalesceRanges($peak_times);

		if ($max === 0) {
			return $this->emptyResult('group', $start, $end, _('No calls found in the selected date range.'));
		}

		return [
			'mode' => 'group', 'start' => $start, 'end' => $end,
			'max_concurrency' => $max, 'peak_ranges' => $ranges,
			'rows_processed' => $processed,
			'warning' => $this->trunkNamingWarning(),
		];
	}

	/**
	 * Runtime overrun guard. Mirrors bash:
	 *   est_remain = elapsed/processed * (total - processed)
	 *   if elapsed + est_remain > MAX_RUNTIME and prompt not shown -> warn
	 *   if elapsed > MAX_RUNTIME -> abort
	 */
	private function checkOverrun(int $elapsed, int $processed, int $total, int $started_at, bool &$did_prompt): void {
		if ($elapsed > self::MAX_RUNTIME) {
			throw new \Exception(sprintf(_('Script exceeded the maximum runtime of %d seconds. Aborting to protect system stability.'), self::MAX_RUNTIME));
		}
		if ($did_prompt || $processed === 0) return;

		$est_remain = (int)round(($elapsed / max($processed, 1)) * ($total - $processed));
		if (($elapsed + $est_remain) > self::MAX_RUNTIME) {
			$max_left = self::MAX_RUNTIME - $elapsed;
			if ($max_left < 0) $max_left = 0;
			$ex = new RuntimeOverrunPending(_('Estimated time exceeds the maximum runtime.'));
			$ex->estimatedRemaining = $est_remain;
			$ex->runtimeRemaining = $max_left;
			throw $ex;
		}
	}

	private function coalesceRanges(array $sorted): array {
		$ranges = [];
		if (empty($sorted)) return $ranges;
		$range_start = $sorted[0];
		$prev = $range_start;
		$n = count($sorted);
		for ($i = 1; $i < $n; $i++) {
			$cur = $sorted[$i];
			if ($cur !== $prev + 1) {
				$ranges[] = [
					'from' => date('Y-m-d H:i:s', $range_start),
					'to'   => date('Y-m-d H:i:s', $prev),
				];
				$range_start = $cur;
			}
			$prev = $cur;
		}
		$ranges[] = [
			'from' => date('Y-m-d H:i:s', $range_start),
			'to'   => date('Y-m-d H:i:s', $prev),
		];
		return $ranges;
	}

	private function fetchTrunkRows(array $trunks, string $start, string $end, string $accountcode = ''): array {
		$placeholders = [];
		$params = [':start' => $start, ':end' => $end];
		$i = 0;
		foreach ($trunks as $t) {
			$key = ':t' . $i;
			$placeholders[] = "channel LIKE CONCAT('PJSIP/', $key, '%') OR dstchannel LIKE CONCAT('PJSIP/', $key, '%')";
			$params[$key] = $t;
			$i++;
		}
		$trunk_condition = '(' . implode(' OR ', $placeholders) . ')';

		$account_filter = '';
		if ($accountcode !== '') {
			$account_filter = ' AND accountcode = :accountcode';
			$params[':accountcode'] = $accountcode;
		}

		$sql = "SELECT calldate, duration, channel AS chan FROM cdr
				WHERE disposition='ANSWERED'
				  AND calldate BETWEEN :start AND :end
				  $account_filter
				  AND ($trunk_condition OR (CHAR_LENGTH(dst)>6 AND dst NOT REGEXP '^[19]'))
				UNION ALL
				SELECT calldate, duration, dstchannel AS chan FROM cdr
				WHERE disposition='ANSWERED'
				  AND calldate BETWEEN :start AND :end
				  $account_filter
				  AND ($trunk_condition OR (CHAR_LENGTH(dst)>6 AND dst NOT REGEXP '^[19]'))";
		$stmt = $this->cdrdb->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function fetchExtensionRows(string $start, string $end, string $accountcode = ''): array {
		$params = [':start' => $start, ':end' => $end];
		$account_filter = '';
		if ($accountcode !== '') {
			$account_filter = ' AND accountcode = :accountcode';
			$params[':accountcode'] = $accountcode;
		}
		$sql = "SELECT calldate, duration,
					CASE
						WHEN dstchannel REGEXP '^PJSIP/[0-9]+-' THEN dstchannel
						WHEN channel    REGEXP '^PJSIP/[0-9]+-' THEN channel
						ELSE ''
					END AS chan
				FROM cdr
				WHERE disposition='ANSWERED'
				  AND calldate BETWEEN :start AND :end
				  $account_filter
				  AND (channel LIKE 'PJSIP/%' OR dstchannel LIKE 'PJSIP/%')
				  AND dst NOT REGEXP '^[19]'";
		$stmt = $this->cdrdb->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function emptyResult(string $mode, string $start, string $end, string $msg): array {
		$base = [
			'mode' => $mode, 'start' => $start, 'end' => $end,
			'rows_processed' => 0,
			'empty_message' => $msg,
			'warning' => $this->trunkNamingWarning(),
		];
		if ($mode === 'group') {
			$base['max_concurrency'] = 0;
			$base['peak_ranges'] = [];
		} else {
			$base['per_name'] = [];
			$base['global_max'] = 0;
		}
		return $base;
	}

	private function trunkNamingWarning(): string {
		return _("WARNING: If your SIP trunks are named using numeric values e.g. 24700020, the Concurrency Count results may be inaccurate because the script counts concurrent calls by extension number. Trunks named numerically will be counted as extensions, leading to unexpected concurrency figures. For accurate results, trunk names should include alphabetic characters.");
	}

	/* ============================================================
	 * OUTPUT
	 * ============================================================ */

	public function resultsToCsv(array $r): string {
		$rows = [];
		$rows[] = ['Concurrency Count ' . $this->getVersion() . '- NOT CURRENTLY SUITABLE FOR PRODUCTION'];
		$rows[] = ['Mode', ucfirst($r['mode'])];
		$rows[] = ['From', $r['start']];
		$rows[] = ['To', $r['end']];
		$rows[] = ['Rows processed', $r['rows_processed']];
		$rows[] = [];

		if ($r['mode'] === 'demo') {
			$rows[] = ['Demo run id', isset($r['demo_run_id']) ? $r['demo_run_id'] : ''];
			$rows[] = ['Demo report', isset($r['demo_report']) ? $r['demo_report'] : ''];
			$rows[] = ['Demo size', isset($r['demo_size']) ? $r['demo_size'] : ''];
			$rows[] = ['Demo seed', isset($r['demo_seed']) ? $r['demo_seed'] : ''];
			$rows[] = ['Accuracy', isset($r['accuracy_status']) ? $r['accuracy_status'] : ''];
			$rows[] = ['Rows inserted', isset($r['rows_inserted']) ? $r['rows_inserted'] : 0];
			$rows[] = ['Rows removed', isset($r['rows_removed']) ? $r['rows_removed'] : 0];
			$rows[] = ['Cleanup remaining', isset($r['cleanup_remaining']) ? $r['cleanup_remaining'] : 0];
			$rows[] = [];
			if (isset($r['demo_report']) && $r['demo_report'] === 'group') {
				$rows[] = ['Metric', 'Expected', 'Actual'];
				$rows[] = ['Maximum concurrent calls overall', isset($r['expected_max_concurrency']) ? $r['expected_max_concurrency'] : 0, isset($r['max_concurrency']) ? $r['max_concurrency'] : 0];
			} else {
				$label = (isset($r['demo_report']) && $r['demo_report'] === 'trunk') ? 'Trunk' : 'Extension';
				$rows[] = [$label, 'Expected', 'Actual'];
				$expected = isset($r['expected_per_name']) ? $r['expected_per_name'] : [];
				foreach ($expected as $name => $count) {
					$actual = isset($r['per_name'][$name]) ? $r['per_name'][$name] : 0;
					$rows[] = [$name, $count, $actual];
				}
			}
		} elseif ($r['mode'] === 'group') {
			$rows[] = ['Maximum concurrent calls overall', isset($r['max_concurrency']) ? $r['max_concurrency'] : 0];
			$rows[] = [];
			$rows[] = ['Peak time ranges'];
			if (!empty($r['peak_ranges'])) {
				foreach ($r['peak_ranges'] as $range) {
					if ($range['from'] === $range['to']) {
						$rows[] = [$range['from']];
					} else {
						$rows[] = [$range['from'], $range['to']];
					}
				}
			}
		} else {
			$label = ($r['mode'] === 'trunk') ? 'Trunk' : 'Extension';
			$rows[] = [$label, 'Max concurrent'];
			if (!empty($r['per_name'])) {
				foreach ($r['per_name'] as $name => $count) {
					$rows[] = [$name, $count];
				}
			}
			$rows[] = [];
			$rows[] = ['Global maximum', isset($r['global_max']) ? $r['global_max'] : 0];
		}

		$fh = fopen('php://temp', 'r+');
		foreach ($rows as $row) {
			fputcsv($fh, $row);
		}
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);

		// UTF-8 BOM. Without this, Excel on Windows opens UTF-8 CSVs as
		// ANSI/locale codepage and accented characters (trunk names with
		// non-ASCII, currency symbols in descriptions) come out garbled.
		// The BOM also tells Excel to use comma as the separator across
		// most locales, even ones where the default separator is semicolon.
		return "\xEF\xBB\xBF" . $csv;
	}

	private function streamDownload(): void {
		$mode = $this->normaliseMode(isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '');
		$start = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '';
		$end = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '';
		$options = $this->requestDemoOptions();

		if ($mode === null) {
			http_response_code(400);
			echo _('Invalid mode.');
			return;
		}
		try {
			$results = $this->calculate($mode, $start, $end, true, $options);
			$csv = $this->resultsToCsv($results);
			$filename = 'concurrency-count-' . $mode . '-' . date('Ymd-His') . '.csv';

			while (ob_get_level()) ob_end_clean();
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			header('Content-Length: ' . strlen($csv));
			echo $csv;
		} catch (\Exception $e) {
			http_response_code(500);
			echo $e->getMessage();
		}
	}

	private function streamDemoCdrDownload(): void {
		$mode = $this->normaliseMode(isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '');
		$start = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '';
		$end = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '';
		$options = $this->requestDemoOptions();

		if ($mode !== 'demo') {
			http_response_code(400);
			echo _('CDR download is available for demo mode only.');
			return;
		}

		try {
			$report = $this->normaliseDemoReport(isset($options['demo_report']) ? $options['demo_report'] : 'extension');
			$size = $this->normaliseDemoSize(isset($options['demo_size']) ? $options['demo_size'] : 'light');
			$row_count = $this->normaliseDemoRows(isset($options['demo_rows']) ? $options['demo_rows'] : 0, $size);
			$seed = isset($options['demo_seed']) ? (int)$options['demo_seed'] : 0;
			if ($seed === 0) {
				$seed = random_int(1, 0x7fffffff);
			}
			$range = $this->normaliseDemoRange($start, $end);
			$demo_trunk = '';
			if ($report === 'trunk') {
				$trunks = $this->getTrunks();
				if (empty($trunks)) {
					throw new \Exception(_('Demo trunk mode requires at least one non-numeric PJSIP trunk on the PBX.'));
				}
				$demo_trunk = $trunks[0];
			}
			$rows = $this->buildDemoRows($range['start'], $range['end'], $size, $seed, 'CCDEMOCSV', $report, $demo_trunk, $row_count);
			$csv = $this->demoCdrRowsToCsv($rows, $report, $size, $seed, $range['start'], $range['end']);
			$filename = 'concurrency-count-demo-cdr-' . $report . '-' . date('Ymd-His') . '.csv';

			while (ob_get_level()) ob_end_clean();
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			header('Content-Length: ' . strlen($csv));
			echo $csv;
		} catch (\Exception $e) {
			http_response_code(500);
			echo $e->getMessage();
		}
	}

	private function demoCdrRowsToCsv(array $rows, string $report, string $size, int $seed, string $start, string $end): string {
		$out = [];
		$out[] = ['Concurrency Count demo CDR data'];
		$out[] = ['Report', $report];
		$out[] = ['Size', $size];
		$out[] = ['Seed', $seed];
		$out[] = ['From', $start];
		$out[] = ['To', $end];
		$out[] = [];
		$out[] = ['calldate', 'duration', 'channel', 'dstchannel', 'src', 'dst', 'disposition', 'accountcode', 'uniqueid', 'linkedid'];
		foreach ($rows as $row) {
			$out[] = [
				$row['calldate'],
				$row['duration'],
				$row['channel'],
				$row['dstchannel'],
				$row['src'],
				$row['dst'],
				'ANSWERED',
				$row['accountcode'],
				$row['uniqueid'],
				$row['linkedid'],
			];
		}

		$fh = fopen('php://temp', 'r+');
		foreach ($out as $row) {
			fputcsv($fh, $row);
		}
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);
		return "\xEF\xBB\xBF" . $csv;
	}

	private function handleEmail(): array {
		$mode = $this->normaliseMode(isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '');
		$start = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '';
		$end = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '';
		$options = $this->requestDemoOptions();
		$to = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';

		// Defence in depth against header injection. filter_var with
		// FILTER_VALIDATE_EMAIL already rejects addresses containing CR/LF
		// per RFC 5321, but a future PHP change or a fallback path that
		// skips validation shouldn't allow CR/LF to reach mail() headers.
		// Strip first, validate after.
		$to = str_replace(["\r", "\n", "\0"], '', $to);

		if ($mode === null) {
			return ['status' => false, 'message' => _('Invalid mode.')];
		}
		if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return ['status' => false, 'message' => _('Invalid email address.')];
		}

		try {
			$results = $this->calculate($mode, $start, $end, true, $options);
			$csv = $this->resultsToCsv($results);
			$filename = 'concurrency-count-' . $mode . '-' . date('Ymd-His') . '.csv';

			$subject = sprintf(_('Concurrency Count: %s from %s to %s'), ucfirst($mode), $start, $end);
			$body = $this->buildEmailBody($results);
			$ok = $this->sendMail($to, $subject, $body, $filename, $csv);

			if ($ok) {
				return ['status' => true, 'message' => sprintf(_('Report emailed to %s'), $to)];
			}
			return ['status' => false, 'message' => _('Failed to send email. Check the FreePBX system mail configuration.')];
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	private function buildEmailBody(array $r): string {
		$lines = [];
		$lines[] = 'Concurrency Count ' . $this->getVersion() . '- NOT CURRENTLY SUITABLE FOR PRODUCTION';
		$lines[] = '';
		$lines[] = 'Mode:           ' . ucfirst($r['mode']);
		$lines[] = 'From:           ' . $r['start'];
		$lines[] = 'To:             ' . $r['end'];
		$lines[] = 'Rows processed: ' . $r['rows_processed'];
		$lines[] = '';

		if ($r['mode'] === 'demo') {
			$lines[] = 'Demo run id:      ' . (isset($r['demo_run_id']) ? $r['demo_run_id'] : '');
			$lines[] = 'Demo report:      ' . (isset($r['demo_report']) ? ucfirst($r['demo_report']) : '');
			$lines[] = 'Demo size:        ' . (isset($r['demo_size']) ? $r['demo_size'] : '');
			$lines[] = 'Demo seed:        ' . (isset($r['demo_seed']) ? $r['demo_seed'] : '');
			$lines[] = 'Accuracy:         ' . (isset($r['accuracy_status']) ? strtoupper($r['accuracy_status']) : '');
			$lines[] = 'Rows inserted:    ' . (isset($r['rows_inserted']) ? $r['rows_inserted'] : 0);
			$lines[] = 'Rows removed:     ' . (isset($r['rows_removed']) ? $r['rows_removed'] : 0);
			$lines[] = 'Cleanup remaining:' . (isset($r['cleanup_remaining']) ? ' ' . $r['cleanup_remaining'] : ' 0');
			$lines[] = '';
			if (isset($r['demo_report']) && $r['demo_report'] === 'group') {
				$lines[] = 'Group accuracy:';
				$lines[] = 'Expected max: ' . (isset($r['expected_max_concurrency']) ? $r['expected_max_concurrency'] : 0);
				$lines[] = 'Actual max:   ' . (isset($r['max_concurrency']) ? $r['max_concurrency'] : 0);
			} else {
				$label = (isset($r['demo_report']) && $r['demo_report'] === 'trunk') ? 'Trunk' : 'Extension';
				$lines[] = $label . ' accuracy:';
				$lines[] = sprintf('%-24s  %-8s  %s', $label, 'Expected', 'Actual');
				$expected = isset($r['expected_per_name']) ? $r['expected_per_name'] : [];
				foreach ($expected as $name => $count) {
					$actual = isset($r['per_name'][$name]) ? $r['per_name'][$name] : 0;
					$lines[] = sprintf('%-24s  %-8d  %d', $name, $count, $actual);
				}
			}
		} elseif ($r['mode'] === 'group') {
			$lines[] = 'Maximum concurrent calls overall: ' . (isset($r['max_concurrency']) ? $r['max_concurrency'] : 0);
			$lines[] = '';
			if (!empty($r['peak_ranges'])) {
				$lines[] = 'Peak time ranges:';
				foreach ($r['peak_ranges'] as $range) {
					if ($range['from'] === $range['to']) {
						$lines[] = '  ' . $range['from'];
					} else {
						$lines[] = '  ' . $range['from'] . ' to ' . $range['to'];
					}
				}
			} elseif (!empty($r['empty_message'])) {
				$lines[] = $r['empty_message'];
			}
		} else {
			$label = ($r['mode'] === 'trunk') ? 'Trunk' : 'Extension';
			$lines[] = sprintf('%-24s  %s', $label, 'Max concurrent');
			if (!empty($r['per_name'])) {
				foreach ($r['per_name'] as $name => $count) {
					$lines[] = sprintf('%-24s  %d', $name, $count);
				}
			} elseif (!empty($r['empty_message'])) {
				$lines[] = $r['empty_message'];
			}
			$lines[] = '';
			$lines[] = 'Global maximum: ' . (isset($r['global_max']) ? $r['global_max'] : 0);
		}

		$lines[] = '';
		$lines[] = $r['warning'];
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = 'Concurrency Count for FreePBX 17- NOT CURRENTLY SUITABLE FOR PRODUCTION';
		return implode("\n", $lines);
	}

	private function sendMail(string $to, string $subject, string $body, string $attachFilename, string $attachContent): bool {
		try {
			$mailer = $this->FreePBX->Mail();
			if (is_object($mailer)) {
				if (method_exists($mailer, 'clearAddresses')) {
					$mailer->clearAddresses();
				}
				if (method_exists($mailer, 'addAddress')) {
					$mailer->addAddress($to);
				} elseif (method_exists($mailer, 'setTo')) {
					$mailer->setTo($to);
				} else {
					$mailer->to = $to;
				}

				if (method_exists($mailer, 'setSubject')) {
					$mailer->setSubject($subject);
				} else {
					$mailer->Subject = $subject;
				}

				if (method_exists($mailer, 'setBody')) {
					$mailer->setBody($body);
				} else {
					$mailer->Body = $body;
					$mailer->AltBody = $body;
				}

				if (method_exists($mailer, 'addStringAttachment')) {
					$mailer->addStringAttachment($attachContent, $attachFilename, 'base64', 'text/csv');
				} elseif (method_exists($mailer, 'AddStringAttachment')) {
					$mailer->AddStringAttachment($attachContent, $attachFilename, 'base64', 'text/csv');
				}

				if (method_exists($mailer, 'send')) {
					return (bool)$mailer->send();
				}
				if (method_exists($mailer, 'Send')) {
					return (bool)$mailer->Send();
				}
			}
		} catch (\Exception $e) {
			// Fall through
		}

		$boundary = md5(uniqid('cc', true));
		$headers = "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

		$msg = "--$boundary\r\n";
		$msg .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
		$msg .= $body . "\r\n\r\n";
		$msg .= "--$boundary\r\n";
		$msg .= "Content-Type: text/csv; name=\"$attachFilename\"\r\n";
		$msg .= "Content-Transfer-Encoding: base64\r\n";
		$msg .= "Content-Disposition: attachment; filename=\"$attachFilename\"\r\n\r\n";
		$msg .= chunk_split(base64_encode($attachContent)) . "\r\n";
		$msg .= "--$boundary--";

		return @mail($to, $subject, $msg, $headers);
	}
}

/**
 * Thrown when the in-flight runtime estimate exceeds MAX_RUNTIME and the
 * user has not yet confirmed to continue. The wizard catches it and shows
 * the warning modal, mirroring the bash 'Continue anyway?' prompt.
 */
class RuntimeOverrunPending extends \Exception {
	public $estimatedRemaining = 0;
	public $runtimeRemaining = 0;
}
