<?php
/**
 * Concurrency Count for FreePBX/PBXact 16 and 17
 *
 * Live and historical PJSIP concurrency module - NOT CURRENTLY SUITABLE FOR PRODUCTION.
 * GUI and CLI share the same validated reporting and identity services.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

require_once __DIR__ . '/Engines/EngineInterface.php';
require_once __DIR__ . '/Engines/Original.php';
require_once __DIR__ . '/Engines/Sweep.php';
require_once __DIR__ . '/Engines/Registry.php';
require_once __DIR__ . '/Analyzers/PeakDetailAnalyser.php';
require_once __DIR__ . '/Resolvers/FreepbxEntityResolver.php';
require_once __DIR__ . '/Services/LiveSnapshotService.php';
require_once __DIR__ . '/Services/ThresholdService.php';
require_once __DIR__ . '/Services/SettingsRepository.php';
require_once __DIR__ . '/Services/HistoricalGraphService.php';
require_once __DIR__ . '/Services/AlertMonitorCoordinator.php';
require_once __DIR__ . '/Services/AmiChannelSource.php';
require_once __DIR__ . '/Services/AlertOutboxService.php';
require_once __DIR__ . '/Services/HistoricalReportsService.php';
require_once __DIR__ . '/Services/PjsipIdentityService.php';
require_once __DIR__ . '/Services/HistoricalCallExclusionService.php';
require_once __DIR__ . '/Services/HistoricalCalculationControl.php';
require_once __DIR__ . '/Services/CliCancellationControl.php';
require_once __DIR__ . '/Services/SystemResourceTelemetry.php';

class Concurrencycount implements \BMO {

	const MAX_RUNTIME = 3600;
	/** Fallback only. Authoritative version lives in module.xml and is read by getVersion(). */
	const VERSION = '2.1.1';
	const MAX_ATTEMPTS = 3;
	const AJAX_COMMANDS = ['wizardstep', 'run', 'cancelcalculation', 'calculationtelemetry', 'peakdetails', 'livestatus', 'getsettings', 'savesettings', 'monitorstatus', 'restartmonitor', 'historicalgraph', 'download', 'previewfixture', 'email', 'gettrunks', 'listhistoricalreports', 'createhistoricalreport', 'updatehistoricalreport', 'closehistoricalreport', 'activatehistoricalreport', 'getidentityclassifications', 'saveidentityclassification', 'resetidentityclassification', 'resetallidentityclassifications', 'listexcludedcalls', 'excludecall', 'restoreexcludedcall', 'restoreallexcludedcalls'];
	const CSRF_SESSION_KEY = 'concurrencycount_csrf_token';
	const SETTINGS_KEY = 'live_settings';
	const ALERT_STATE_KEY = 'alert_state';
	const ALERT_OUTBOX_KEY = 'alert_outbox';
	const MONITOR_HEARTBEAT_KEY = 'monitor_heartbeat';
	const HISTORICAL_REPORTS_KEY = 'historical_reports';
	const PJSIP_IDENTITY_OVERRIDES_KEY = 'pjsip_identity_overrides';
	const HISTORICAL_CALL_EXCLUSIONS_KEY = 'historical_call_exclusions';
	const LEGACY_MONITOR_CRON_LINE = '* * * * * /usr/sbin/fwconsole concurrencycount --monitor --quiet >/dev/null 2>&1';
	const MONITOR_PROCESS_NAME = 'concurrencycount-alert-monitor';
	const MAIL_PROCESS_NAME = 'concurrencycount-alert-mailer';

	private $FreePBX;
	private $cdrdb;
	private $cdrColumnsCache = null;
	private $settingsRepository = null;
	private $pjsipIdentityService = null;
	private $historicalCallExclusions = null;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
		$this->cdrdb = $freepbx->Cdr->getCdrDbHandle();
		$this->ensureCsrfToken();
	}

	public function install(): void {
		$this->getSettingsRepository()->install();
		try {
			\FreePBX::Cron()->removeLine(self::LEGACY_MONITOR_CRON_LINE);
		} catch (\Throwable $exception) {
			$this->logWarning('Unable to remove legacy threshold monitor cron: ' . $exception->getMessage());
		}
		$this->startAlertMonitor();
	}
	public function uninstall(): void {
		$this->stopAlertMonitor(true);
		try {
			\FreePBX::Cron()->removeLine(self::LEGACY_MONITOR_CRON_LINE);
		} catch (\Throwable $exception) {
			$this->logWarning('Unable to remove legacy threshold monitor cron: ' . $exception->getMessage());
		}
		$this->getSettingsRepository()->uninstall();
	}
	public function backup(): void {}
	public function restore($backup): void {}
	public function doConfigPageInit($page): void {}

	public function startFreepbx($output = null): void {
		$this->startAlertMonitor();
	}

	public function stopFreepbx($output = null): void {
		$this->stopAlertMonitor(false);
	}

	public function startAlertMonitor(): array {
		try {
			if (!$this->FreePBX->Modules->checkStatus('pm2')) {
				return ['available' => false, 'status' => 'unavailable', 'message' => _('FreePBX Process Management (PM2) is unavailable.')];
			}
			$pm2 = $this->FreePBX->Pm2;
			foreach ([self::MONITOR_PROCESS_NAME => __DIR__ . '/alert-monitor.php', self::MAIL_PROCESS_NAME => __DIR__ . '/alert-mailer.php'] as $name => $script) {
				$current = $pm2->getStatus($name);
				if (!empty($current) && isset($current['pm2_env']['status']) && $current['pm2_env']['status'] === 'online') continue;
				$pm2->start($name, $script, [], true);
				$pm2->reset($name);
			}
			return $this->getAlertMonitorStatus();
		} catch (\Throwable $exception) {
			$this->logWarning('Unable to start threshold alert monitor: ' . $exception->getMessage());
			return ['available' => false, 'status' => 'failed', 'message' => $exception->getMessage()];
		}
	}

	public function stopAlertMonitor(bool $delete = false): array {
		try {
			if (!$this->FreePBX->Modules->checkStatus('pm2')) {
				return ['available' => false, 'status' => 'unavailable'];
			}
			$pm2 = $this->FreePBX->Pm2;
			foreach ([self::MONITOR_PROCESS_NAME, self::MAIL_PROCESS_NAME] as $name) {
				$current = $pm2->getStatus($name);
				if (empty($current)) continue;
				if ($delete) $pm2->delete($name);
				else $pm2->stop($name);
			}
			return ['available' => true, 'status' => $delete ? 'deleted' : 'stopped'];
		} catch (\Throwable $exception) {
			$this->logWarning('Unable to stop threshold alert monitor: ' . $exception->getMessage());
			return ['available' => false, 'status' => 'failed', 'message' => $exception->getMessage()];
		}
	}

	public function restartAlertMonitor(): array {
		$this->stopAlertMonitor(true);
		return $this->startAlertMonitor();
	}

	public function getAlertMonitorStatus(): array {
		try {
			if (!$this->FreePBX->Modules->checkStatus('pm2')) {
				return ['available' => false, 'status' => 'unavailable', 'message' => _('FreePBX Process Management (PM2) is unavailable.')];
			}
			$current = $this->FreePBX->Pm2->getStatus(self::MONITOR_PROCESS_NAME);
			if (empty($current)) return ['available' => true, 'status' => 'stopped', 'pid' => 0];
			$mailer = $this->FreePBX->Pm2->getStatus(self::MAIL_PROCESS_NAME);
			$heartbeat = $this->getSettingsRepository()->get(self::MONITOR_HEARTBEAT_KEY, []);
			$lastSuccess = is_array($heartbeat) && isset($heartbeat['last_successful_snapshot_at']) ? (int)$heartbeat['last_successful_snapshot_at'] : 0;
			$pm2Status = isset($current['pm2_env']['status']) ? (string)$current['pm2_env']['status'] : 'unknown';
			$mailerStatus = !empty($mailer) && isset($mailer['pm2_env']['status']) ? (string)$mailer['pm2_env']['status'] : 'stopped';
			$status = $pm2Status;
			if ($pm2Status === 'online' && (($lastSuccess === 0 || time() - $lastSuccess > 15) || $mailerStatus !== 'online')) $status = 'degraded';
			return [
				'available' => true,
				'status' => $status,
				'pm2_status' => $pm2Status,
				'mailer_status' => $mailerStatus,
				'pid' => isset($current['pid']) ? (int)$current['pid'] : 0,
				'restarts' => isset($current['pm2_env']['restart_time']) ? (int)$current['pm2_env']['restart_time'] : 0,
				'last_loop_at' => is_array($heartbeat) && isset($heartbeat['last_loop_at']) ? (int)$heartbeat['last_loop_at'] : 0,
				'last_successful_snapshot_at' => $lastSuccess,
				'ami_status' => is_array($heartbeat) && isset($heartbeat['ami_status']) ? (string)$heartbeat['ami_status'] : 'unknown',
				'last_error' => is_array($heartbeat) && isset($heartbeat['last_error']) ? (string)$heartbeat['last_error'] : '',
			];
		} catch (\Throwable $exception) {
			return ['available' => false, 'status' => 'failed', 'message' => $exception->getMessage()];
		}
	}

	public function recordMonitorHeartbeat(array $heartbeat): void {
		$this->getSettingsRepository()->set(self::MONITOR_HEARTBEAT_KEY, [
			'last_loop_at' => isset($heartbeat['last_loop_at']) ? (int)$heartbeat['last_loop_at'] : time(),
			'last_successful_snapshot_at' => isset($heartbeat['last_successful_snapshot_at']) ? (int)$heartbeat['last_successful_snapshot_at'] : 0,
			'ami_status' => isset($heartbeat['ami_status']) ? (string)$heartbeat['ami_status'] : 'unknown',
			'last_error' => isset($heartbeat['last_error']) ? substr((string)$heartbeat['last_error'], 0, 1000) : '',
		]);
	}

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
			'availableEngines' => $this->getAvailableEngines(),
			'csrfToken' => $this->getCsrfToken(),
		]);
	}

	/**
	 * AJAX request allowlist.
	 */
	public function ajaxRequest($req, &$setting): bool {
		$setting['authenticate'] = true;
		$setting['allowremote'] = false;
		return in_array((string)$req, self::AJAX_COMMANDS, true);
	}

	/**
	 * Custom handler for streaming binary output. Returning true tells the
	 * framework to skip the JSON wrapper and exit.
	 */
	public function ajaxCustomHandler(): bool {
		$this->requireValidCsrfToken();
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
		if ($command === 'download') {
			$this->streamDownload();
			return true;
		}
		if ($command === 'previewfixture') {
			$this->streamDemoFixturePreview();
			return true;
		}
		return false;
	}

	/**
	 * AJAX dispatcher for JSON responses.
	 */
	public function ajaxHandler(): array {
		$this->requireValidCsrfToken();
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';

		switch ($command) {
			case 'wizardstep':
				return $this->handleWizardStep();
			case 'run':
				return $this->handleRun();
			case 'cancelcalculation':
				return $this->handleCancelCalculation();
			case 'calculationtelemetry':
				return $this->handleCalculationTelemetry();
			case 'peakdetails':
				return $this->handlePeakDetails();
			case 'livestatus':
				return ['status' => true, 'snapshot' => $this->getLiveStatus()];
			case 'getsettings':
				return ['status' => true, 'settings' => $this->getLiveSettings()];
			case 'savesettings':
				return $this->handleSaveSettings();
			case 'monitorstatus':
				return ['status' => true, 'monitor' => $this->getAlertMonitorStatus()];
			case 'restartmonitor':
				return ['status' => true, 'monitor' => $this->restartAlertMonitor()];
			case 'historicalgraph':
				return $this->handleHistoricalGraph();
			case 'email':
				return $this->handleEmail();
			case 'gettrunks':
				return ['status' => true, 'trunks' => $this->getTrunks()];
			case 'listhistoricalreports':
				return ['status' => true] + $this->getHistoricalReports();
			case 'createhistoricalreport':
				return $this->handleCreateHistoricalReport();
			case 'updatehistoricalreport':
				return $this->handleUpdateHistoricalReport();
			case 'closehistoricalreport':
				return $this->handleCloseHistoricalReport();
			case 'activatehistoricalreport':
				return $this->handleActivateHistoricalReport();
			case 'getidentityclassifications':
				return ['status' => true, 'classifications' => $this->getIdentityClassifications()];
			case 'saveidentityclassification':
				return $this->handleSaveIdentityClassification();
			case 'resetidentityclassification':
				return $this->handleResetIdentityClassification();
			case 'resetallidentityclassifications':
				return $this->resetAllIdentityClassifications();
			case 'listexcludedcalls':
				return $this->handleListExcludedCalls();
			case 'excludecall':
				return $this->handleExcludeCall();
			case 'restoreexcludedcall':
				return $this->handleRestoreExcludedCall();
			case 'restoreallexcludedcalls':
				return $this->handleRestoreAllExcludedCalls();
		}
		return ['status' => false, 'message' => _('Unknown command')];
	}

	public function getLiveSettings(): array {
		$service = new \FreePBX\modules\Concurrencycount\Services\ThresholdService();
		$stored = $this->getSettingsRepository()->get(self::SETTINGS_KEY, $service->defaults());
		return $service->reconcileStored(is_array($stored) ? $stored : [], $this->getConfiguredLiveTrunks());
	}

	public function saveLiveSettings(array $settings): array {
		$service = new \FreePBX\modules\Concurrencycount\Services\ThresholdService();
		$normalised = $service->normalise($settings, $this->getConfiguredLiveTrunks());
		$repository = $this->getSettingsRepository();
		$states = $repository->get(self::ALERT_STATE_KEY, []);
		if (!is_array($states)) $states = [];
		// A stopped trunk resumes from the next real snapshot, never from an
		// old above-threshold episode that could manufacture a stale recovery.
		foreach ($normalised['trunks'] as $trunk => $scope) {
			if (empty($scope['monitored'])) unset($states['trunk:' . $trunk]);
		}
		$repository->transaction(function ($repository) use ($normalised, $states): void {
			$repository->set(self::SETTINGS_KEY, $normalised);
			$repository->set(self::ALERT_STATE_KEY, $states);
		});
		return $normalised;
	}

	public function getLiveStatus(): array {
		$service = new \FreePBX\modules\Concurrencycount\Services\LiveSnapshotService();
		try {
			global $astman;
			$source = new \FreePBX\modules\Concurrencycount\Services\AmiChannelSource();
			$sourceResult = $source->snapshot($astman, 3);
			if (empty($sourceResult['available'])) return $service->unavailable(isset($sourceResult['message']) ? (string)$sourceResult['message'] : _('Asterisk did not return a complete live channel snapshot.'));
			$settings = $this->getLiveSettings();
			$snapshot = $service->analyse($sourceResult['channels'], $this->getPjsipIdentityService(), $settings);
			return $this->enrichLiveSnapshot($snapshot);
		} catch (\Throwable $exception) {
			$this->logWarning('Live AMI snapshot failed: ' . $exception->getMessage());
			return $service->unavailable(_('Unable to read current Asterisk channel state.'));
		}
	}

	public function getConfiguredLiveTrunks(): array {
		$names = array_keys($this->getPjsipIdentityService()->configuredTrunks());
		sort($names);
		return $names;
	}

	private function getPjsipIdentityService(): \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService {
		if ($this->pjsipIdentityService !== null) return $this->pjsipIdentityService;
		$trunks = [];
		try {
			foreach ($this->FreePBX->Core->listTrunks() as $trunk) {
				if (strtolower(trim((string)(isset($trunk['tech']) ? $trunk['tech'] : ''))) !== 'pjsip') continue;
				$endpoint = trim((string)(isset($trunk['channelid']) ? $trunk['channelid'] : ''));
				if ($endpoint === '') continue;
				$trunks[$endpoint] = [
					'channelid' => $endpoint,
					'trunkid' => isset($trunk['trunkid']) ? (string)$trunk['trunkid'] : '',
					'name' => isset($trunk['name']) ? (string)$trunk['name'] : $endpoint,
					'disabled' => !empty($trunk['disabled']),
				];
			}
			$stmt = $this->FreePBX->Database->query("SELECT id FROM devices WHERE LOWER(tech) = 'pjsip' AND id <> ''");
			$devices = [];
			while (($id = $stmt->fetchColumn()) !== false) {
				$id = trim((string)$id);
				if ($id !== '') $devices[$id] = ['id' => $id];
			}
		} catch (\Throwable $exception) {
			$this->logWarning('Authoritative PJSIP identity inventory failed: ' . $exception->getMessage());
			throw new \RuntimeException(_('Unable to read authoritative FreePBX PJSIP identity configuration.'), 0, $exception);
		}
		$stored = $this->getSettingsRepository()->get(self::PJSIP_IDENTITY_OVERRIDES_KEY, []);
		$this->pjsipIdentityService = new \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService($trunks, $devices, is_array($stored) ? $stored : []);
		foreach (array_intersect(array_keys($trunks), array_keys($devices)) as $endpoint) {
			$this->logWarning('PJSIP identity conflict: endpoint "' . $endpoint . '" is configured as both a FreePBX trunk and device.');
		}
		return $this->pjsipIdentityService;
	}

	public function getIdentityClassifications(): array {
		$identity = $this->getPjsipIdentityService();
		$out = [];
		foreach ($identity->overrides() as $endpoint => $manual) {
			$current = $identity->classify($endpoint);
			$out[] = ['endpoint' => $endpoint, 'manual' => $manual, 'automatic_type' => $current['source'] === 'manual-override' ? 'unknown' : $current['type'], 'status' => $current['source'] === 'manual-override' ? 'active' : 'superseded'];
		}
		return $out;
	}

	private function handleSaveIdentityClassification(): array {
		try {
			$identity = $this->getPjsipIdentityService();
			$endpoint = $identity->validateEndpoint(isset($_REQUEST['endpoint']) ? $_REQUEST['endpoint'] : '');
			$type = isset($_REQUEST['classification']) ? (string)$_REQUEST['classification'] : '';
			if (!in_array($type, \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService::MANUAL_TYPES, true)) throw new \InvalidArgumentException(_('Invalid PJSIP endpoint classification.'));
			$current = $identity->classify($endpoint);
			if ($current['source'] === 'freepbx-conflict') throw new \InvalidArgumentException(_('A FreePBX trunk/device conflict cannot be overridden.'));
			if ($current['source'] === 'freepbx-trunk' || $current['source'] === 'freepbx-device') throw new \InvalidArgumentException(_('FreePBX already identifies this PJSIP endpoint automatically.'));
			$overrides = $identity->overrides();
			$overrides[$endpoint] = $type;
			$this->getSettingsRepository()->set(self::PJSIP_IDENTITY_OVERRIDES_KEY, $identity->validateOverrideMap($overrides));
			$this->pjsipIdentityService = null;
			return ['status' => true, 'classifications' => $this->getIdentityClassifications()];
		} catch (\Throwable $exception) { return ['status' => false, 'message' => $exception->getMessage()]; }
	}

	private function handleResetIdentityClassification(): array {
		try {
			$identity = $this->getPjsipIdentityService();
			$endpoint = $identity->validateEndpoint(isset($_REQUEST['endpoint']) ? $_REQUEST['endpoint'] : '');
			$overrides = $identity->overrides();
			unset($overrides[$endpoint]);
			$this->getSettingsRepository()->set(self::PJSIP_IDENTITY_OVERRIDES_KEY, $overrides);
			$this->pjsipIdentityService = null;
			return ['status' => true, 'classifications' => $this->getIdentityClassifications()];
		} catch (\Throwable $exception) { return ['status' => false, 'message' => $exception->getMessage()]; }
	}

	private function resetAllIdentityClassifications(): array {
		$this->getSettingsRepository()->set(self::PJSIP_IDENTITY_OVERRIDES_KEY, []);
		$this->pjsipIdentityService = null;
		return ['status' => true, 'classifications' => []];
	}

	private function getHistoricalCallExclusionService(): \FreePBX\modules\Concurrencycount\Services\HistoricalCallExclusionService {
		return new \FreePBX\modules\Concurrencycount\Services\HistoricalCallExclusionService();
	}

	private function getHistoricalCallExclusions(): array {
		if ($this->historicalCallExclusions !== null) return $this->historicalCallExclusions;
		$service = $this->getHistoricalCallExclusionService();
		$this->historicalCallExclusions = $service->repair($this->getSettingsRepository()->get(self::HISTORICAL_CALL_EXCLUSIONS_KEY, []));
		return $this->historicalCallExclusions;
	}

	private function handleExcludeCall(): array {
		try {
			$service = $this->getHistoricalCallExclusionService();
			$identity = $service->validateIdentity(isset($_REQUEST['call_identity']) ? $_REQUEST['call_identity'] : '');
			$rows = $this->fetchLogicalCallRows($identity);
			if (empty($rows)) throw new \InvalidArgumentException(_('The selected source CDR call could not be found.'));
			foreach ($rows as $row) if (strpos((string)(isset($row['accountcode']) ? $row['accountcode'] : ''), 'CCDEMO') === 0) throw new \InvalidArgumentException(_('Demo calls cannot be added to persistent Historical exclusions.'));
			$summary = $this->buildExcludedCallSummary($rows);
			$result = $this->withHistoricalCallExclusionsLock(function () use ($service, $identity, $summary): array {
				$repository = $this->getSettingsRepository();
				$stored = $service->repair($repository->get(self::HISTORICAL_CALL_EXCLUSIONS_KEY, []));
				$stored = $service->exclude($stored, $identity, $summary);
				$repository->set(self::HISTORICAL_CALL_EXCLUSIONS_KEY, $stored);
				return $stored;
			});
			$this->historicalCallExclusions = $result;
			return ['status' => true, 'excluded_count' => count($result)];
		} catch (\Throwable $exception) { return ['status' => false, 'message' => $exception->getMessage()]; }
	}

	private function handleRestoreExcludedCall(): array {
		try {
			$service = $this->getHistoricalCallExclusionService();
			$identity = $service->validateIdentity(isset($_REQUEST['call_identity']) ? $_REQUEST['call_identity'] : '');
			$result = $this->withHistoricalCallExclusionsLock(function () use ($service, $identity): array {
				$repository = $this->getSettingsRepository();
				$stored = $service->restore($repository->get(self::HISTORICAL_CALL_EXCLUSIONS_KEY, []), $identity);
				$repository->set(self::HISTORICAL_CALL_EXCLUSIONS_KEY, $stored);
				return $stored;
			});
			$this->historicalCallExclusions = $result;
			return ['status' => true, 'excluded_count' => count($result)];
		} catch (\Throwable $exception) { return ['status' => false, 'message' => $exception->getMessage()]; }
	}

	private function handleRestoreAllExcludedCalls(): array {
		try {
			$this->withHistoricalCallExclusionsLock(function (): array {
				$this->getSettingsRepository()->set(self::HISTORICAL_CALL_EXCLUSIONS_KEY, []);
				return [];
			});
			$this->historicalCallExclusions = [];
			return ['status' => true, 'excluded_count' => 0];
		} catch (\Throwable $exception) { return ['status' => false, 'message' => $exception->getMessage()]; }
	}

	private function handleListExcludedCalls(): array {
		try {
			$reportId = isset($_REQUEST['report_id']) ? trim((string)$_REQUEST['report_id']) : '';
			$report = $reportId === '' ? null : $this->getHistoricalReportDefinition($reportId);
			$stored = $this->getHistoricalCallExclusions();
			$sourceRows = $this->fetchExcludedLogicalCallRows(array_keys($stored));
			$calls = [];
			foreach ($stored as $identity => $entry) {
				$summary = isset($entry['summary']) ? $entry['summary'] : [];
				$calls[] = [
					'call_identity' => $identity,
					'excluded_at' => date('Y-m-d H:i:s', (int)$entry['excluded_at']),
					'summary' => $summary,
					'source_available' => !empty($sourceRows[$identity]),
					'matches_current_report' => $report === null || empty($sourceRows[$identity]) ? null : $this->excludedCallMatchesReport($sourceRows[$identity], $report),
				];
			}
			usort($calls, function ($a, $b) { return strcmp($b['excluded_at'], $a['excluded_at']); });
			return ['status' => true, 'calls' => $calls, 'excluded_count' => count($calls), 'has_report_context' => $report !== null];
		} catch (\Throwable $exception) { return ['status' => false, 'message' => $exception->getMessage()]; }
	}

	private function withHistoricalCallExclusionsLock(callable $callback): array {
		$lock = $this->FreePBX->Database->query("SELECT GET_LOCK('concurrencycount_historical_call_exclusions', 5)")->fetchColumn();
		if ((int)$lock !== 1) throw new \RuntimeException(_('Excluded Calls are busy, please try again.'));
		try { return call_user_func($callback); }
		finally { $this->FreePBX->Database->query("SELECT RELEASE_LOCK('concurrencycount_historical_call_exclusions')"); }
	}

	private function getHistoricalReportDefinition(string $id): array {
		if (!preg_match('/^[0-9a-f]{32}$/', $id)) throw new \InvalidArgumentException(_('Invalid historical report id.'));
		$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalReportsService();
		$stored = $service->reconcileStored($this->getSettingsRepository()->get(self::HISTORICAL_REPORTS_KEY, $service->defaults()));
		$report = $service->findById($stored, $id);
		if ($report === null) throw new \InvalidArgumentException(_('Historical report tab no longer exists.'));
		return $report;
	}

	private function fetchLogicalCallRows(string $identity): array {
		$service = $this->getHistoricalCallExclusionService();
		$parts = $service->splitIdentity($identity);
		$available = [];
		foreach ($this->getCdrColumns() as $column) if (isset($column['Field'])) $available[$column['Field']] = true;
		if (!isset($available[$parts['field']])) return [];
		$wanted = ['calldate', 'src', 'dst', 'disposition', 'duration', 'billsec', 'channel', 'dstchannel', 'uniqueid', 'linkedid', 'accountcode'];
		$select = [];
		foreach ($wanted as $field) if (isset($available[$field])) $select[] = '`' . $field . '`';
		$stmt = $this->cdrdb->prepare('SELECT ' . implode(', ', $select) . ' FROM cdr WHERE `' . $parts['field'] . '` = :identity ORDER BY calldate ASC');
		$stmt->execute([':identity' => $parts['value']]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function buildExcludedCallSummary(array $rows): array {
		$first = $rows[0];
		$channels = [];
		$trunks = [];
		$extensions = [];
		$identity = $this->getPjsipIdentityService();
		foreach ($rows as $row) foreach (['channel', 'dstchannel'] as $field) {
			$channel = trim((string)(isset($row[$field]) ? $row[$field] : ''));
			if ($channel === '') continue;
			$channels[$channel] = true;
			$endpoint = $identity->parseChannel($channel);
			if ($endpoint === null) continue;
			$type = $identity->classify($endpoint)['type'];
			if ($type === 'trunk') $trunks[$endpoint] = true;
			elseif ($type === 'extension') $extensions[$endpoint] = true;
		}
		return [
			'calldate' => isset($first['calldate']) ? (string)$first['calldate'] : '',
			'src' => isset($first['src']) ? (string)$first['src'] : '', 'dst' => isset($first['dst']) ? (string)$first['dst'] : '',
			'disposition' => isset($first['disposition']) ? (string)$first['disposition'] : '',
			'duration' => isset($first['duration']) ? (int)$first['duration'] : 0, 'billsec' => isset($first['billsec']) ? (int)$first['billsec'] : 0,
			'trunk' => implode(', ', array_keys($trunks)), 'extension' => implode(', ', array_keys($extensions)), 'channels' => array_keys($channels),
		];
	}

	private function excludedCallMatchesReport(array $rows, array $report): bool {
		$range = $this->historicalReportDefinitionRange($report);
		$start = $range['start'];
		$end = $range['end'];
		$eligibleRows = [];
		foreach ($rows as $row) {
			$calldate = isset($row['calldate']) ? (string)$row['calldate'] : '';
			if ($calldate < $start || $calldate > $end || (isset($row['disposition']) && $row['disposition'] !== 'ANSWERED')) continue;
			if (strpos((string)(isset($row['channel']) ? $row['channel'] : ''), 'PJSIP/') !== 0 && strpos((string)(isset($row['dstchannel']) ? $row['dstchannel'] : ''), 'PJSIP/') !== 0) continue;
			$eligibleRows[] = $row;
		}
		if (empty($eligibleRows)) return false;
		$identity = $this->getPjsipIdentityService();
		if ($report['mode'] === 'group') return !empty($this->classifyGroupRows($eligibleRows, $identity)['rows']);
		$classified = $this->classifyPerNameRows($eligibleRows, $report['mode'], $identity)['rows'];
		$filter = isset($report['filter']) ? (string)$report['filter'] : '';
		foreach ($classified as $row) if ($filter === '' || (isset($row['identity']) && hash_equals($filter, (string)$row['identity']))) return true;
		return false;
	}

	private function historicalReportDefinitionRange(array $report): array {
		$today = date('Y-m-d');
		$preset = isset($report['preset']) ? (string)$report['preset'] : 'custom';
		$from = isset($report['range_from']) ? (string)$report['range_from'] : $today;
		$to = isset($report['range_to']) ? (string)$report['range_to'] : $today;
		if ($preset === 'today') $from = $to = $today;
		elseif ($preset === 'yesterday') $from = $to = date('Y-m-d', strtotime($today . ' -1 day'));
		elseif ($preset === 'last7') { $from = date('Y-m-d', strtotime($today . ' -6 days')); $to = $today; }
		elseif ($preset === 'last30') { $from = date('Y-m-d', strtotime($today . ' -29 days')); $to = $today; }
		elseif ($preset === 'month') { $from = date('Y-m-01'); $to = $today; }
		elseif ($preset === 'year') { $from = date('Y-01-01'); $to = $today; }
		elseif ($preset === 'lastyear') { $year = (int)date('Y') - 1; $from = $year . '-01-01'; $to = $year . '-12-31'; }
		$start = $from . (!empty($report['include_time']) ? ' ' . $report['from_time'] . ':00' : ' 00:00:00');
		$end = $to . (!empty($report['include_time']) ? ' ' . $report['to_time'] . ':59' : ' 23:59:59');
		if ($to === $today && $end > date('Y-m-d H:i:s')) $end = date('Y-m-d H:i:s');
		return ['start' => $start, 'end' => $end];
	}

	private function fetchExcludedLogicalCallRows(array $identities): array {
		$service = $this->getHistoricalCallExclusionService();
		$columns = [];
		foreach ($this->getCdrColumns() as $column) if (isset($column['Field'])) $columns[$column['Field']] = true;
		$linked = []; $unique = [];
		foreach ($identities as $identity) { $part = $service->splitIdentity($identity); if ($part['field'] === 'linkedid') $linked[] = $part['value']; else $unique[] = $part['value']; }
		$clauses = []; $params = []; $index = 0;
		foreach (['linkedid' => $linked, 'uniqueid' => $unique] as $field => $values) {
			if (empty($values) || !isset($columns[$field])) continue;
			$holders = [];
			foreach ($values as $value) { $key = ':id' . $index++; $holders[] = $key; $params[$key] = $value; }
			$clauses[] = '`' . $field . '` IN (' . implode(',', $holders) . ')';
		}
		if (empty($clauses)) return [];
		$wanted = ['calldate', 'duration', 'channel', 'dstchannel', 'disposition', 'linkedid', 'uniqueid'];
		$selectParts = [];
		foreach ($wanted as $field) $selectParts[] = isset($columns[$field]) ? '`' . $field . '`' : "'' AS `" . $field . '`';
		$select = implode(', ', $selectParts);
		$stmt = $this->cdrdb->prepare('SELECT DISTINCT ' . $select . ' FROM cdr WHERE ' . implode(' OR ', $clauses));
		$stmt->execute($params);
		$rows = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$identity = $service->identityForRow($row);
			if ($identity !== null) $rows[$identity][] = $row;
		}
		return $rows;
	}

	private function getSettingsRepository(): \FreePBX\modules\Concurrencycount\Services\SettingsRepository {
		if ($this->settingsRepository === null) {
			$this->settingsRepository = new \FreePBX\modules\Concurrencycount\Services\SettingsRepository($this->FreePBX->Database);
		}
		return $this->settingsRepository;
	}

	/* ============================================================
	 * PERSISTED HISTORICAL REPORT TABS
	 * Definitions only (mode/engine/preset/range) - never CDR result
	 * payloads or graph points. GUI and CLI share these methods.
	 * ============================================================ */

	public function getHistoricalReports(): array {
		$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalReportsService();
		$stored = $service->reconcileStored($this->getSettingsRepository()->get(self::HISTORICAL_REPORTS_KEY, $service->defaults()));
		$trunks = $this->getTrunks();
		$reports = array_map(function ($report) use ($trunks) {
			if ($report['mode'] === 'trunk' && $report['filter'] !== '') {
				$report['missing_reference'] = !in_array($report['filter'], $trunks, true);
			}
			return $report;
		}, $service->listReports($stored));
		return ['reports' => $reports, 'active_id' => $stored['active_id']];
	}

	public function createHistoricalReport(array $definition): array {
		return $this->withHistoricalReportsLock(function () use ($definition) {
			$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalReportsService();
			$repository = $this->getSettingsRepository();
			$stored = $service->reconcileStored($repository->get(self::HISTORICAL_REPORTS_KEY, $service->defaults()));
			try {
				$definition = $this->markMissingReference($definition);
				list($stored, $report) = $service->createReport($stored, $definition);
			} catch (\RuntimeException $exception) {
				return ['status' => false, 'message' => $exception->getMessage(), 'reports' => $service->listReports($stored)];
			} catch (\InvalidArgumentException $exception) {
				return ['status' => false, 'message' => $exception->getMessage()];
			}
			$repository->set(self::HISTORICAL_REPORTS_KEY, $stored);
			return ['status' => true, 'report' => $report, 'reports' => $service->listReports($stored)];
		});
	}

	public function updateHistoricalReport(string $id, array $definition): array {
		return $this->withHistoricalReportsLock(function () use ($id, $definition) {
			$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalReportsService();
			$repository = $this->getSettingsRepository();
			$stored = $service->reconcileStored($repository->get(self::HISTORICAL_REPORTS_KEY, $service->defaults()));
			try {
				$definition = $this->markMissingReference($definition);
				list($stored, $report) = $service->updateReport($stored, $id, $definition);
			} catch (\InvalidArgumentException $exception) {
				return ['status' => false, 'message' => $exception->getMessage()];
			}
			$repository->set(self::HISTORICAL_REPORTS_KEY, $stored);
			return ['status' => true, 'report' => $report, 'reports' => $service->listReports($stored)];
		});
	}

	public function closeHistoricalReport(string $id): array {
		return $this->withHistoricalReportsLock(function () use ($id) {
			$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalReportsService();
			$repository = $this->getSettingsRepository();
			$stored = $service->reconcileStored($repository->get(self::HISTORICAL_REPORTS_KEY, $service->defaults()));
			$stored = $service->closeReport($stored, $id);
			$repository->set(self::HISTORICAL_REPORTS_KEY, $stored);
			return ['status' => true, 'reports' => $service->listReports($stored), 'active_id' => $stored['active_id']];
		});
	}

	public function setActiveHistoricalReport(string $id): array {
		return $this->withHistoricalReportsLock(function () use ($id) {
			$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalReportsService();
			$repository = $this->getSettingsRepository();
			$stored = $service->reconcileStored($repository->get(self::HISTORICAL_REPORTS_KEY, $service->defaults()));
			try {
				$stored = $service->setActive($stored, $id);
			} catch (\InvalidArgumentException $exception) {
				return ['status' => false, 'message' => $exception->getMessage()];
			}
			$repository->set(self::HISTORICAL_REPORTS_KEY, $stored);
			return ['status' => true, 'active_id' => $stored['active_id']];
		});
	}

	/**
	 * Mark (never silently retarget) a persisted trunk/extension filter that
	 * no longer matches current configuration, so the GUI can surface it.
	 */
	private function markMissingReference(array $definition): array {
		$filter = isset($definition['filter']) ? trim((string)$definition['filter']) : '';
		if ($filter === '') {
			$definition['missing_reference'] = false;
			return $definition;
		}
		$mode = isset($definition['mode']) ? (string)$definition['mode'] : '';
		if ($mode === 'group') {
			$definition['filter'] = '';
			$definition['missing_reference'] = false;
			return $definition;
		}
		if (in_array($mode, ['trunk', 'extension'], true)) {
			$classification = $this->getPjsipIdentityService()->classify($filter);
			$definition['missing_reference'] = $classification['type'] !== $mode;
		}
		return $definition;
	}

	/**
	 * Serialises historical-report-tab mutations across concurrent requests
	 * (e.g. a double-clicked "create" button) so the five-report limit and
	 * slot allocation can never race past the maximum.
	 */
	private function withHistoricalReportsLock(callable $callback): array {
		$lock = $this->FreePBX->Database->query("SELECT GET_LOCK('concurrencycount_historical_reports', 5)")->fetchColumn();
		if ((int)$lock !== 1) {
			return ['status' => false, 'message' => _('Historical report tabs are busy, please try again.')];
		}
		try {
			return $callback();
		} finally {
			$this->FreePBX->Database->query("SELECT RELEASE_LOCK('concurrencycount_historical_reports')");
		}
	}

	private function handleCreateHistoricalReport(): array {
		return $this->createHistoricalReport($this->readHistoricalReportRequest());
	}

	private function handleUpdateHistoricalReport(): array {
		$id = isset($_REQUEST['id']) ? (string)$_REQUEST['id'] : '';
		if (!preg_match('/^[0-9a-f]{32}$/', $id)) return ['status' => false, 'message' => _('Invalid historical report id.')];
		return $this->updateHistoricalReport($id, $this->readHistoricalReportRequest());
	}

	private function handleCloseHistoricalReport(): array {
		$id = isset($_REQUEST['id']) ? (string)$_REQUEST['id'] : '';
		if (!preg_match('/^[0-9a-f]{32}$/', $id)) return ['status' => false, 'message' => _('Invalid historical report id.')];
		return $this->closeHistoricalReport($id);
	}

	private function handleActivateHistoricalReport(): array {
		$id = isset($_REQUEST['id']) ? (string)$_REQUEST['id'] : '';
		if (!preg_match('/^[0-9a-f]{32}$/', $id)) return ['status' => false, 'message' => _('Invalid historical report id.')];
		return $this->setActiveHistoricalReport($id);
	}

	private function readHistoricalReportRequest(): array {
		return [
			'name' => isset($_REQUEST['name']) ? (string)$_REQUEST['name'] : '',
			'generated_default_name' => !empty($_REQUEST['generated_default_name']),
			'mode' => isset($_REQUEST['mode']) ? (string)$_REQUEST['mode'] : 'trunk',
			'engine' => isset($_REQUEST['engine']) ? (string)$_REQUEST['engine'] : 'original',
			'preset' => isset($_REQUEST['preset']) ? (string)$_REQUEST['preset'] : 'last7',
			'range_from' => isset($_REQUEST['range_from']) ? (string)$_REQUEST['range_from'] : '',
			'range_to' => isset($_REQUEST['range_to']) ? (string)$_REQUEST['range_to'] : '',
			'include_time' => !empty($_REQUEST['include_time']),
			'from_time' => isset($_REQUEST['from_time']) ? (string)$_REQUEST['from_time'] : '00:00',
			'to_time' => isset($_REQUEST['to_time']) ? (string)$_REQUEST['to_time'] : '23:59',
			'filter' => isset($_REQUEST['filter']) ? (string)$_REQUEST['filter'] : '',
		];
	}

	public function getHistoricalGraph(string $mode, string $start, string $end, string $trunk = ''): array {
		$mode = $this->normaliseMode($mode);
		if (!in_array($mode, ['trunk', 'group'], true)) {
			throw new \InvalidArgumentException(_('Historical graphs support Trunk Concurrency and Group Concurrency.'));
		}
		$range = $this->resolveDateRange(['kind' => 'custom', 'start' => $start, 'end' => $end]);
		$service = new \FreePBX\modules\Concurrencycount\Services\HistoricalGraphService();
		$identity = $this->getPjsipIdentityService();
		$cdrRows = $this->fetchPjsipCdrRows($range['start'], $range['end']);
		if ($mode === 'trunk') {
			$trunks = $this->getTrunks();
			if ($trunk !== '') {
				if (!in_array($trunk, $trunks, true)) throw new \InvalidArgumentException(_('Invalid trunk selected for historical graph.'));
				$trunks = [$trunk];
			}
			$rows = $this->classifyPerNameRows($cdrRows, 'trunk', $identity)['rows'];
			if ($trunk !== '') $rows = array_values(array_filter($rows, function ($row) use ($trunk) { return isset($row['identity']) && hash_equals($trunk, $row['identity']); }));
			$graph = $service->trunkSeries($rows, $trunks, $range['start'], $range['end']);
		} else {
			$graph = $service->overallSeries($this->classifyGroupRows($cdrRows, $identity)['rows'], $range['start'], $range['end']);
		}
		$settings = $this->getLiveSettings();
		$graph['start'] = $range['start'];
		$graph['end'] = $range['end'];
		$graph['thresholds'] = $mode === 'trunk' ? $settings['trunks'] : ['overall' => $settings['overall']];
		return $graph;
	}

	public function runThresholdMonitor(): array {
		$lock = $this->FreePBX->Database->query("SELECT GET_LOCK('concurrencycount_alert_monitor', 0)")->fetchColumn();
		if ((int)$lock !== 1) return ['available' => false, 'checked_at' => date('Y-m-d H:i:s'), 'events' => [], 'errors' => [], 'skipped' => 'monitor_busy'];
		try {
			$settings = $this->getLiveSettings();
			$snapshot = $this->getLiveStatus();
			$result = ['available' => $snapshot['available'], 'checked_at' => $snapshot['generated_at'], 'events' => [], 'errors' => []];
			if (!$snapshot['available']) {
				$result['errors'][] = isset($snapshot['message']) ? $snapshot['message'] : _('Live status unavailable.');
				return $result;
			}
			$repository = $this->getSettingsRepository();
			$states = $repository->get(self::ALERT_STATE_KEY, []);
			if (!is_array($states)) $states = [];
			$outbox = $repository->get(self::ALERT_OUTBOX_KEY, []);
			if (!is_array($outbox)) $outbox = [];
			$service = new \FreePBX\modules\Concurrencycount\Services\ThresholdService();
			$outboxService = new \FreePBX\modules\Concurrencycount\Services\AlertOutboxService();
			$scopes = ['overall' => ['value' => (int)$snapshot['overall']['current'], 'config' => $settings['overall'], 'split' => []]];
			foreach ($snapshot['trunks'] as $trunk => $trunkResult) {
				// Monitoring is an operational evaluation gate only. The snapshot
				// and Overall count were already built from actual AMI channel legs.
				if (isset($settings['trunks'][$trunk]) && empty($settings['trunks'][$trunk]['monitored'])) {
					unset($states['trunk:' . $trunk]);
					continue;
				}
				$scopes['trunk:' . $trunk] = [
					'value' => (int)$trunkResult['current'],
					'config' => isset($settings['trunks'][$trunk]) ? $settings['trunks'][$trunk] : [],
					'split' => isset($trunkResult['direction_counts']) ? $trunkResult['direction_counts'] : [],
				];
			}
			$now = time();
			foreach ($scopes as $scope => $scopeData) {
				$prior = isset($states[$scope]) && is_array($states[$scope]) ? $states[$scope] : [];
				$transition = $service->evaluate($scope, $scopeData['value'], $scopeData['config'], $prior, $settings['alerts_enabled'], $settings['recovery_enabled'], $now);
				if ($transition['event'] !== null) {
					$event = $transition['event'];
					$event['direction_counts'] = $scopeData['split'];
					$outbox = $outboxService->queue($outbox, $event, $now);
					$result['events'][] = $event;
				}
				$states[$scope] = $transition['state'];
			}
			$repository->transaction(function ($repository) use ($states, $outbox): void {
				$repository->set(self::ALERT_STATE_KEY, $states);
				$repository->set(self::ALERT_OUTBOX_KEY, $outbox);
			});
			return $result;
		} finally {
			$this->FreePBX->Database->query("SELECT RELEASE_LOCK('concurrencycount_alert_monitor')");
		}
	}

	public function processAlertOutbox(): array {
		$repository = $this->getSettingsRepository();
		$lock = $this->FreePBX->Database->query("SELECT GET_LOCK('concurrencycount_alert_monitor', 0)")->fetchColumn();
		if ((int)$lock !== 1) return ['processed' => false, 'status' => 'busy'];
		$ready = null;
		try {
			$outbox = $repository->get(self::ALERT_OUTBOX_KEY, []);
			if (!is_array($outbox) || empty($outbox)) return ['processed' => false, 'status' => 'empty'];
			$now = time();
			$outboxService = new \FreePBX\modules\Concurrencycount\Services\AlertOutboxService();
			$ready = $outboxService->nextReady($outbox, $now);
			if ($ready === null) return ['processed' => false, 'status' => 'waiting'];
			$outbox[$ready['event_id']]['delivery_status'] = 'delivering';
			$outbox[$ready['event_id']]['next_attempt_at'] = $now + 60;
			$repository->set(self::ALERT_OUTBOX_KEY, $outbox);
		} finally {
			$this->FreePBX->Database->query("SELECT RELEASE_LOCK('concurrencycount_alert_monitor')");
		}

		$settings = $this->getLiveSettings();
		$mail = $this->sendThresholdEvent($ready['event'], $settings['alert_email']);
		$lock = $this->FreePBX->Database->query("SELECT GET_LOCK('concurrencycount_alert_monitor', 5)")->fetchColumn();
		if ((int)$lock !== 1) return ['processed' => true, 'status' => 'retry', 'event_id' => $ready['event_id'], 'message' => _('Unable to record alert delivery result; the leased event will be retried.')];
		try {
			$outbox = $repository->get(self::ALERT_OUTBOX_KEY, []);
			if (!is_array($outbox)) $outbox = [];
			$outboxService = new \FreePBX\modules\Concurrencycount\Services\AlertOutboxService();
			$outbox = $outboxService->applyDelivery($outbox, $ready['event_id'], $mail['ok'], $mail['message'], time());
			$repository->set(self::ALERT_OUTBOX_KEY, $outbox);
			return ['processed' => true, 'status' => $mail['ok'] ? 'accepted' : 'retry', 'event_id' => $ready['event_id'], 'message' => $mail['message']];
		} finally {
			$this->FreePBX->Database->query("SELECT RELEASE_LOCK('concurrencycount_alert_monitor')");
		}
	}

	private function handleSaveSettings(): array {
		try {
			$json = isset($_REQUEST['settings']) ? (string)$_REQUEST['settings'] : '';
			$decoded = json_decode($json, true);
			if (!is_array($decoded)) throw new \InvalidArgumentException(_('Invalid settings payload.'));
			return ['status' => true, 'settings' => $this->saveLiveSettings($decoded)];
		} catch (\Exception $exception) {
			return ['status' => false, 'message' => $exception->getMessage()];
		}
	}

	private function handleHistoricalGraph(): array {
		try {
			return ['status' => true, 'graph' => $this->getHistoricalGraph(
				isset($_REQUEST['mode']) ? (string)$_REQUEST['mode'] : '',
				isset($_REQUEST['start_date']) ? (string)$_REQUEST['start_date'] : '',
				isset($_REQUEST['end_date']) ? (string)$_REQUEST['end_date'] : '',
				isset($_REQUEST['trunk']) ? trim((string)$_REQUEST['trunk']) : ''
			)];
		} catch (\Exception $exception) {
			return ['status' => false, 'message' => $exception->getMessage()];
		}
	}

	private function enrichLiveSnapshot(array $snapshot): array {
		$trunkEntities = [];
		foreach ($snapshot['trunks'] as $trunk => &$trunkResult) {
			$trunkResult['entity'] = $this->buildTrunkEntity($trunk);
			$trunkEntities[$trunk] = $trunkResult['entity'];
			foreach ($trunkResult['calls'] as &$call) $call['trunk_entity'] = $trunkResult['entity'];
			unset($call);
		}
		unset($trunkResult);
		foreach ($snapshot['overall']['calls'] as &$call) {
			if (!empty($call['extension'])) {
				$call['extension_entity'] = $this->resolveFreepbxDestination('from-did-direct,' . $call['extension'] . ',1');
			} elseif (!empty($call['trunk'])) {
				$call['trunk_entity'] = isset($trunkEntities[$call['trunk']]) ? $trunkEntities[$call['trunk']] : $this->buildTrunkEntity($call['trunk']);
			}
		}
		unset($call);
		return $snapshot;
	}

	private function sendThresholdEvent(array $event, string $to): array {
		if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
			return ['ok' => false, 'message' => _('Threshold alert email is not configured.')];
		}
		$service = new \FreePBX\modules\Concurrencycount\Services\ThresholdService();
		$message = $service->buildNotification($event, $this->getSystemIdentifier());
		return $this->sendMail($to, $message['subject'], $message['body'], '', '');
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

		if ($this->isTodayShorthand($s)) return date('Y-m-d 00:00:00');
		if ($this->isYesterdayShorthand($s)) return date('Y-m-d 00:00:00', strtotime('yesterday'));

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
			if (!$this->validatePartialDate($s)) return null;
			return $s . '-01 00:00:00';
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $s)) {
			if (!$this->validatePartialDate($s)) return null;
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

		if ($this->isTodayShorthand($s)) return date('Y-m-d H:i:s');
		if ($this->isYesterdayShorthand($s)) return date('Y-m-d 23:59:59', strtotime('yesterday'));

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
			if (!$this->validatePartialDate($s)) return null;
			if ($mo === $current_month && $y === $current_year) {
				return date('Y-m-d H:i:s');
			}
			return date('Y-m-d 23:59:59', strtotime("$y-$mo-01 +1 month -1 day"));
		}
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $s)) {
			if (!$this->validatePartialDate($s)) return null;
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
		$options['filter'] = isset($_REQUEST['filter']) ? trim((string)$_REQUEST['filter']) : '';
		$calculationId = isset($_REQUEST['calculation_id']) ? trim((string)$_REQUEST['calculation_id']) : '';
		$control = null;
		$previousIgnoreUserAbort = null;
		if ($calculationId !== '') {
			try {
				$control = new \FreePBX\modules\Concurrencycount\Services\HistoricalCalculationControl($this->getSettingsRepository());
				$calculationId = $control->validateId($calculationId);
				$control->begin($calculationId);
				$lastCancellationCheck = 0.0;
				$options['cancellation_check'] = function () use ($control, $calculationId, &$lastCancellationCheck): bool {
					$now = \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now();
					if (($now - $lastCancellationCheck) < 0.25) return false;
					$lastCancellationCheck = $now;
					return $control->isCancelled($calculationId);
				};
				$lastTelemetryUpdate = 0.0;
				$options['progress_update'] = function (array $assessment) use ($control, $calculationId, &$lastTelemetryUpdate): void {
					$now = \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now();
					if (($now - $lastTelemetryUpdate) < 1.0) return;
					$lastTelemetryUpdate = $now;
					$control->updateTelemetry($calculationId, (float)$assessment['overall_elapsed'], isset($assessment['estimated_remaining']) ? (float)$assessment['estimated_remaining'] : null, !empty($assessment['reliable']));
				};
				$previousIgnoreUserAbort = ignore_user_abort(true);
			} catch (\Exception $exception) {
				return ['status' => false, 'message' => $exception->getMessage()];
			}
		}

		if ($mode === null) {
			return ['status' => false, 'message' => _('Invalid mode entered. Please enter trunks, extensions, group, or demo.')];
		}
		// Authentication and CSRF validation have completed. Release PHP's
		// session lock so the same administrator's Stop request can run in
		// parallel with this long calculation.
		if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

		try {
			if ($mode !== 'demo') {
				$range = $this->resolveDateRange(['kind' => 'custom', 'start' => $start, 'end' => $end]);
				$start = $range['start'];
				$end = $range['end'];
			}
			$results = $this->calculate($mode, $start, $end, $confirm_overrun, $options);
			return ['status' => true, 'results' => $results];
		} catch (HistoricalCalculationCancelled $cancelled) {
			return ['status' => false, 'cancelled' => true, 'message' => _('Calculation stopped.')];
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
		} finally {
			if ($control !== null) $control->finish($calculationId);
			if ($previousIgnoreUserAbort !== null) ignore_user_abort($previousIgnoreUserAbort);
		}
	}

	private function handleCancelCalculation(): array {
		try {
			$id = isset($_REQUEST['calculation_id']) ? (string)$_REQUEST['calculation_id'] : '';
			$control = new \FreePBX\modules\Concurrencycount\Services\HistoricalCalculationControl($this->getSettingsRepository());
			$id = $control->validateId($id);
			return ['status' => true, 'cancelled' => $control->cancel($id)];
		} catch (\Exception $exception) {
			return ['status' => false, 'message' => $exception->getMessage()];
		}
	}

	private function handleCalculationTelemetry(): array {
		try {
			$id = isset($_REQUEST['calculation_id']) ? (string)$_REQUEST['calculation_id'] : '';
			$control = new \FreePBX\modules\Concurrencycount\Services\HistoricalCalculationControl($this->getSettingsRepository());
			$id = $control->validateId($id);
			$record = $control->status($id);
			if ($record === null) return ['status' => true, 'active' => false, 'resources' => ['available' => false]];
			$resources = ['available' => false];
			try {
				$dashboard = \FreePBX::Dashboard();
				if (is_object($dashboard) && method_exists($dashboard, 'getSysInfo')) {
					$resources = (new \FreePBX\modules\Concurrencycount\Services\SystemResourceTelemetry())->fromDashboard((array)$dashboard->getSysInfo());
				}
			} catch (\Throwable $exception) {
				// Dashboard telemetry is optional; calculation state remains available.
			}
			return ['status' => true] + $this->buildCalculationTelemetryPayload($record, $resources, microtime(true));
		} catch (\Exception $exception) {
			return ['status' => false, 'message' => $exception->getMessage()];
		}
	}

	private function buildCalculationTelemetryPayload(array $record, array $resources, float $now): array {
		$active = isset($record['status']) && $record['status'] === 'active';
		$elapsed = isset($record['elapsed']) && is_numeric($record['elapsed']) ? max(0.0, (float)$record['elapsed']) : 0.0;
		if (isset($record['started_at']) && is_numeric($record['started_at'])) $elapsed = max($elapsed, $now - (float)$record['started_at']);
		$estimate = isset($record['estimated_remaining']) && is_numeric($record['estimated_remaining']) ? (float)$record['estimated_remaining'] : null;
		$reliable = $active && !empty($record['eta_reliable']) && $estimate !== null && is_finite($estimate) && $estimate > 0.0;
		return [
			'active' => $active,
			'elapsed' => $elapsed,
			'max_runtime' => self::MAX_RUNTIME,
			'runtime_remaining' => max(0.0, self::MAX_RUNTIME - $elapsed),
			'estimated_remaining' => $reliable ? $estimate : null,
			'eta_reliable' => $reliable,
			'resources' => $resources,
		];
	}

	private function handlePeakDetails(): array {
		try {
			$trunk = trim((string)(isset($_REQUEST['trunk']) ? $_REQUEST['trunk'] : ''));
			$start = (string)(isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '');
			$end = (string)(isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '');
			$occurrence_from = (string)(isset($_REQUEST['occurrence_from']) ? $_REQUEST['occurrence_from'] : '');
			$occurrence_to = (string)(isset($_REQUEST['occurrence_to']) ? $_REQUEST['occurrence_to'] : '');

			if (!preg_match('/^[A-Za-z0-9_.:@+\-]{1,128}$/', $trunk) || $this->getPjsipIdentityService()->classify($trunk)['type'] !== 'trunk') {
				throw new \InvalidArgumentException(_('Invalid or unavailable trunk.'));
			}
			if (!$this->isCanonicalTimestamp($start) || !$this->isCanonicalTimestamp($end)
				|| !$this->isCanonicalTimestamp($occurrence_from) || !$this->isCanonicalTimestamp($occurrence_to)) {
				throw new \InvalidArgumentException(_('Invalid detail date range.'));
			}
			$range = $this->resolveDateRange(['kind' => 'custom', 'start' => $start, 'end' => $end]);
			$start = $range['start'];
			$end = $range['end'];
			if (strtotime($start) > strtotime($occurrence_from) || strtotime($occurrence_from) > strtotime($occurrence_to)
				|| strtotime($occurrence_to) > strtotime($end)) {
				throw new \InvalidArgumentException(_('Peak occurrence falls outside the report range.'));
			}

			$detail = $this->buildPeakDetails($trunk, $start, $end, $occurrence_from, $occurrence_to);
			return ['status' => true, 'detail' => $detail];
		} catch (\Exception $exception) {
			return ['status' => false, 'message' => $exception->getMessage()];
		}
	}

	private function isCanonicalTimestamp(string $value): bool {
		return (bool)preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $value)
			&& $this->validatePartialDate($value);
	}

	private function buildPeakDetails(string $trunk, string $start, string $end, string $occurrence_from, string $occurrence_to): array {
		$compact_rows = $this->classifyPerNameRows($this->fetchPjsipCdrRows($start, $end), 'trunk', $this->getPjsipIdentityService())['rows'];
		$compact_rows = array_values(array_filter($compact_rows, function ($row) use ($trunk) { return isset($row['identity']) && hash_equals($trunk, $row['identity']); }));
		$analyser = new \FreePBX\modules\Concurrencycount\Analyzers\PeakDetailAnalyser();
		$analysis = $analyser->analyseTrunk($compact_rows, $trunk);
		$selected = null;
		foreach ($analysis['occurrences'] as $occurrence) {
			if ($occurrence['from'] === $occurrence_from && $occurrence['to'] === $occurrence_to) {
				$selected = $occurrence;
				break;
			}
		}
		if ($selected === null) {
			throw new \InvalidArgumentException(_('Peak occurrence is no longer present in the selected report data.'));
		}

		$rows = $this->fetchTrunkDetailRows($trunk, $start, $end, $occurrence_from, $occurrence_to);
		$legs = [];
		foreach ($rows as $row) {
			$channel_match = $this->channelMatchesTrunk(isset($row['channel']) ? $row['channel'] : '', $trunk);
			$destination_match = $this->channelMatchesTrunk(isset($row['dstchannel']) ? $row['dstchannel'] : '', $trunk);
			$direction = $this->classifyTrunkLeg($channel_match, $destination_match);
			if ($channel_match) {
				$legs[] = ['calldate' => $row['calldate'], 'duration' => $row['duration'], 'chan' => $row['channel'], 'identity' => $trunk, 'direction' => $direction, 'cdr' => $row];
			}
			if ($destination_match) {
				$legs[] = ['calldate' => $row['calldate'], 'duration' => $row['duration'], 'chan' => $row['dstchannel'], 'identity' => $trunk, 'direction' => $direction, 'cdr' => $row];
			}
		}

		$calls = [];
		$directions = ['inbound' => 0, 'outbound' => 0, 'unknown' => 0];
		foreach ($legs as $leg) {
			$leg_start = strtotime($leg['calldate']);
			$leg_end = $leg_start + (int)$leg['duration'];
			if ($leg_start > strtotime($occurrence_to) || $leg_end < strtotime($occurrence_from)) continue;
			$call = $this->formatPeakCall($leg, $trunk);
			$calls[] = $call;
			$directions[$call['direction']]++;
		}
		unset($selected['row_indexes']);
		$selected['calls'] = $calls;
		$selected['direction_counts'] = $directions;
		return $selected;
	}

	private function channelMatchesTrunk($channel, string $trunk): bool {
		if (!preg_match('|^PJSIP/([^ ]+)-[0-9a-f]+$|', (string)$channel, $match)) {
			return false;
		}
		return hash_equals($trunk, $match[1]);
	}

	private function classifyTrunkLeg(bool $channel_match, bool $destination_match): string {
		if ($channel_match === $destination_match) return 'unknown';
		return $channel_match ? 'inbound' : 'outbound';
	}

	private function fetchTrunkDetailRows(string $trunk, string $start, string $end, string $occurrence_from, string $occurrence_to): array {
		$available = [];
		foreach ($this->getCdrColumns() as $column) {
			if (isset($column['Field'])) $available[$column['Field']] = true;
		}
		$wanted = ['calldate', 'clid', 'src', 'did', 'dst', 'dcontext', 'channel', 'dstchannel', 'lastapp', 'lastdata', 'duration', 'billsec', 'disposition', 'uniqueid', 'linkedid', 'recordingfile'];
		$select = [];
		foreach ($wanted as $field) {
			if (isset($available[$field])) $select[] = '`' . $field . '`';
		}
		foreach (['calldate', 'duration', 'channel', 'dstchannel'] as $required) {
			if (!isset($available[$required])) throw new \RuntimeException(sprintf(_('Required CDR field is unavailable: %s'), $required));
		}
		$sql = 'SELECT ' . implode(', ', $select) . " FROM cdr
			WHERE disposition = 'ANSWERED' AND calldate BETWEEN :start AND :end
			AND (channel LIKE :trunk_channel OR dstchannel LIKE :trunk_destination)
			AND calldate <= :occurrence_to
			AND TIMESTAMPADD(SECOND, duration, calldate) >= :occurrence_from
			ORDER BY calldate ASC";
		$stmt = $this->cdrdb->prepare($sql);
		$stmt->execute([
			':start' => $start, ':end' => $end,
			':occurrence_from' => $occurrence_from, ':occurrence_to' => $occurrence_to,
			':trunk_channel' => 'PJSIP/' . $trunk . '-%',
			':trunk_destination' => 'PJSIP/' . $trunk . '-%',
		]);
		return $this->getHistoricalCallExclusionService()->filterRows($stmt->fetchAll(\PDO::FETCH_ASSOC), $this->getHistoricalCallExclusions());
	}

	private function formatPeakCall(array $leg, string $trunk): array {
		$row = $leg['cdr'];
		$direction = $leg['direction'];
		$path = [];
		$trunk_entity = $this->buildTrunkEntity($trunk);
		if ($direction === 'inbound' && !empty($row['did'])) {
			$entity = $this->resolveFreepbxDestination('from-trunk,' . $row['did'] . ',1');
			if ($entity !== null) $path[] = $entity;
		}
		$extension_channel = $direction === 'outbound' ? (isset($row['channel']) ? $row['channel'] : '') : (isset($row['dstchannel']) ? $row['dstchannel'] : '');
		$extension_endpoint = $this->getPjsipIdentityService()->parseChannel($extension_channel);
		if ($extension_endpoint !== null && $this->getPjsipIdentityService()->classify($extension_endpoint)['source'] === 'freepbx-device') {
			$entity = $this->resolveFreepbxDestination('from-did-direct,' . $extension_endpoint . ',1');
			if ($entity !== null) $path[] = $entity;
		}

		return [
			'calldate' => isset($row['calldate']) ? (string)$row['calldate'] : '',
			'caller_id' => isset($row['clid']) ? (string)$row['clid'] : '',
			'source' => isset($row['src']) ? (string)$row['src'] : '',
			'did' => isset($row['did']) ? (string)$row['did'] : '',
			'destination' => isset($row['dst']) ? (string)$row['dst'] : '',
			'disposition' => isset($row['disposition']) ? (string)$row['disposition'] : '',
			'duration' => isset($row['duration']) ? (int)$row['duration'] : 0,
			'billsec' => isset($row['billsec']) ? (int)$row['billsec'] : 0,
			'direction' => $direction,
			'trunk' => $trunk,
			'trunk_entity' => $trunk_entity,
			'trunk_channel' => (string)$leg['chan'],
			'channel' => isset($row['channel']) ? (string)$row['channel'] : '',
			'destination_channel' => isset($row['dstchannel']) ? (string)$row['dstchannel'] : '',
			'uniqueid' => isset($row['uniqueid']) ? (string)$row['uniqueid'] : '',
			'linkedid' => isset($row['linkedid']) ? (string)$row['linkedid'] : '',
			'call_identity' => $this->getHistoricalCallExclusionService()->identityForRow($row),
			'recording' => isset($row['recordingfile']) ? (string)$row['recordingfile'] : '',
			'path' => $path,
			'cdr_search' => $this->buildCdrSearch($row),
		];
	}

	private function buildTrunkEntities(array $trunks): array {
		$entities = [];
		foreach ($trunks as $trunk) {
			$entities[$trunk] = $this->buildTrunkEntity((string)$trunk);
		}
		return $entities;
	}

	private function buildTrunkEntity(string $trunk): ?array {
		$classification = $this->getPjsipIdentityService()->classify($trunk);
		if ($classification['source'] !== 'freepbx-trunk') return null;
		$trunk_id = isset($classification['metadata']['trunkid']) ? (string)$classification['metadata']['trunkid'] : '';
		if (!preg_match('/^[0-9]+$/', $trunk_id)) return null;
		return $this->resolveFreepbxDestination('ext-trunk,' . $trunk_id . ',1');
	}

	private function resolveFreepbxDestination(string $destination): ?array {
		$resolver = new \FreePBX\modules\Concurrencycount\Resolvers\FreepbxEntityResolver();
		return $resolver->resolveDestination($destination, 'observed');
	}

	private function buildCdrSearch(array $row): array {
		$timestamp = strtotime(isset($row['calldate']) ? $row['calldate'] : '');
		$fields = [
			'need_html' => 'true',
			'startday' => date('d', $timestamp), 'startmonth' => date('m', $timestamp), 'startyear' => date('Y', $timestamp),
			'starthour' => date('H', $timestamp), 'startmin' => date('i', $timestamp),
			'endday' => date('d', $timestamp), 'endmonth' => date('m', $timestamp), 'endyear' => date('Y', $timestamp),
			'endhour' => date('H', $timestamp), 'endmin' => date('i', $timestamp),
			'disposition' => isset($row['disposition']) ? (string)$row['disposition'] : 'ANSWERED',
		];
		foreach (['dst', 'did'] as $field) {
			if (!empty($row[$field])) {
				$fields[$field] = (string)$row[$field];
				$fields[$field . '_mod'] = 'exact';
			}
		}
		if (!empty($row['src'])) {
			$fields['cnum'] = (string)$row['src'];
			$fields['cnum_mod'] = 'exact';
		}
		return ['url' => 'config.php?display=cdr', 'method' => 'POST', 'fields' => $fields];
	}

	/* ============================================================
	 * CALCULATION (mirrors bash)
	 * ============================================================ */

	/** Authoritative configured FreePBX PJSIP trunk channelids. */
	public function getTrunks(): array {
		$identity = $this->getPjsipIdentityService();
		$trunks = array_keys($identity->configuredTrunks());
		foreach ($identity->overrides() as $endpoint => $type) {
			if ($type === 'trunk' && $identity->classify($endpoint)['source'] === 'manual-override') $trunks[] = $endpoint;
		}
		$trunks = array_values(array_unique($trunks));
		sort($trunks);
		return $trunks;
	}

	/**
	 * Dispatch by mode.
	 */
	public function calculate(string $mode, string $start, string $end, bool $confirm_overrun = false, array $options = []): array {
		set_time_limit(self::MAX_RUNTIME + 60);
		$started_at = \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now();
		$engine_id = $this->normaliseEngineId(isset($options['engine']) ? $options['engine'] : 'original');
		$filter = isset($options['filter']) ? trim((string)$options['filter']) : '';
		$cancellationCheck = isset($options['cancellation_check']) && is_callable($options['cancellation_check']) ? $options['cancellation_check'] : null;
		$progressUpdate = isset($options['progress_update']) && is_callable($options['progress_update']) ? $options['progress_update'] : null;

		if ($mode === 'demo') {
			return $this->calculateDemo($start, $end, $started_at, $options);
		}
		if ($mode === 'group') {
			if ($filter !== '') throw new \InvalidArgumentException(_('Group reports do not support an endpoint filter.'));
			return $this->calculateGroup($start, $end, $started_at, $confirm_overrun, '', $engine_id, $cancellationCheck, $progressUpdate);
		}
		return $this->calculatePerName($mode, $start, $end, $started_at, $confirm_overrun, '', $engine_id, $filter, $cancellationCheck, $progressUpdate);
	}

	public function getAvailableEngines(): array {
		return \FreePBX\modules\Concurrencycount\Engines\Registry::getAvailableEngines();
	}

	private function normaliseEngineId($engine_id): string {
		$engine_id = strtolower(trim((string)$engine_id));
		$engines = $this->getAvailableEngines();
		if (isset($engines[$engine_id])) {
			return $engine_id;
		}
		if ($engine_id === '') return 'original';
		throw new \InvalidArgumentException(sprintf('Unknown calculation engine: %s', $engine_id));
	}

	private function buildEngine(string $engine_id, array $options = []): \FreePBX\modules\Concurrencycount\Engines\EngineInterface {
		$engine_id = $this->normaliseEngineId($engine_id);
		$engines = $this->getAvailableEngines();
		$class = $engines[$engine_id]['class'];
		return new $class($options);
	}

	private function engineOptions(array $all_names, float $started_at, bool $confirm_overrun, ?callable $cancellationCheck = null, ?callable $progressUpdate = null): array {
		$estimator = new \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator(
			self::MAX_RUNTIME,
			$started_at,
			\FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now(),
			$confirm_overrun
		);
		return [
			'all_names' => $all_names,
			'coalesce_ranges' => function (array $times): array {
				return $this->coalesceRanges($times);
			},
			'check_overrun' => function (int $processed, int $total, string $stage = 'progress') use ($estimator, $cancellationCheck, $progressUpdate): void {
				$now = \FreePBX\modules\Concurrencycount\Services\HistoricalRuntimeEstimator::now();
				if ($cancellationCheck !== null && call_user_func($cancellationCheck)) throw new HistoricalCalculationCancelled(_('Calculation stopped.'));
				if ($processed === 0) $estimator->beginEngine($now);
				$assessment = $estimator->evaluate($processed, $total, $now);
				if ($progressUpdate !== null) call_user_func($progressUpdate, $assessment);
				if ($assessment['abort']) {
					throw new \Exception(sprintf(_('Script exceeded the maximum runtime of %d seconds. Aborting to protect system stability.'), self::MAX_RUNTIME));
				}
				if ($assessment['warn']) {
					$ex = new RuntimeOverrunPending(_('Estimated time exceeds the maximum runtime.'));
					$ex->estimatedRemaining = (int)round($assessment['estimated_remaining']);
					$ex->runtimeRemaining = (int)floor($assessment['runtime_remaining']);
					throw $ex;
				}
			},
		];
	}

	/**
	 * Temporary demo fixture. Rows are inserted with a unique accountcode,
	 * counted via the normal CDR queries, then removed in a finally block.
	 */
	private function calculateDemo(string $start, string $end, float $started_at, array $options): array {
		$size = $this->normaliseDemoSize(isset($options['demo_size']) ? $options['demo_size'] : 'light');
		$report = $this->normaliseDemoReport(isset($options['demo_report']) ? $options['demo_report'] : 'extension');
		$demo_engines = $this->normaliseDemoEngines(isset($options['demo_engines']) ? $options['demo_engines'] : ['original']);
		$row_count = $this->normaliseDemoRows(isset($options['demo_rows']) ? $options['demo_rows'] : 0, $size);
		$seed = isset($options['demo_seed']) ? (int)$options['demo_seed'] : 0;
		$cancellationCheck = isset($options['cancellation_check']) && is_callable($options['cancellation_check']) ? $options['cancellation_check'] : null;
		$progressUpdate = isset($options['progress_update']) && is_callable($options['progress_update']) ? $options['progress_update'] : null;
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
				if (($inserted % 100) === 0 && $cancellationCheck !== null && call_user_func($cancellationCheck)) {
					throw new HistoricalCalculationCancelled(_('Calculation stopped.'));
				}
			}

			$engine_results = [];
			foreach ($demo_engines as $engine_id) {
				if (function_exists('memory_reset_peak_usage')) {
					memory_reset_peak_usage();
				}
				$before_memory = memory_get_usage(true);
				$wall_start = microtime(true);
				if ($report === 'group') {
					$actual = $this->calculateGroup($start, $end, $started_at, true, $accountcode, $engine_id, $cancellationCheck, $progressUpdate);
					$accuracy = $this->assessDemoGroupAccuracy($expected, $actual);
				} else {
					$actual = $this->calculatePerName($report, $start, $end, $started_at, true, $accountcode, $engine_id, '', $cancellationCheck, $progressUpdate);
					$accuracy = $this->assessDemoPerNameAccuracy($expected, $actual);
				}
				$wall_ms = (int)round((microtime(true) - $wall_start) * 1000);
				$peak_memory = max(0, memory_get_peak_usage(true) - $before_memory);
				$engine_results[$engine_id] = [
					'accuracy_status' => $accuracy ? 'pass' : 'fail',
					'wall_ms' => $wall_ms,
					'peak_memory_bytes' => $peak_memory,
					'rows_per_second' => $wall_ms > 0 ? (int)round($inserted / ($wall_ms / 1000)) : $inserted,
					'per_name' => isset($actual['per_name']) ? $actual['per_name'] : [],
					'global_max' => isset($actual['global_max']) ? $actual['global_max'] : 0,
					'max_concurrency' => isset($actual['max_concurrency']) ? $actual['max_concurrency'] : 0,
					'peak_ranges' => isset($actual['peak_ranges']) ? $actual['peak_ranges'] : [],
					'overview' => isset($actual['overview']) ? $actual['overview'] : [],
				];
			}
			$actual = $engine_results[$demo_engines[0]];
			$accuracy = $engine_results[$demo_engines[0]]['accuracy_status'] === 'pass';

			$result = [
				'mode' => 'demo', 'start' => $start, 'end' => $end,
				'per_name' => isset($actual['per_name']) ? $actual['per_name'] : [],
				'global_max' => isset($actual['global_max']) ? $actual['global_max'] : 0,
				'max_concurrency' => isset($actual['max_concurrency']) ? $actual['max_concurrency'] : 0,
				'peak_ranges' => isset($actual['peak_ranges']) ? $actual['peak_ranges'] : [],
				'overview' => isset($actual['overview']) ? $actual['overview'] : [],
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
			if ($demo_engines[0] !== 'original') {
				$result['engine'] = $demo_engines[0];
			}
			if (count($demo_engines) > 1) {
				$mixed = false;
				foreach ($engine_results as $engine_result) {
					if ($engine_result['accuracy_status'] !== 'pass') {
						$mixed = true;
						break;
					}
				}
				$result['engines'] = $engine_results;
				$result['accuracy_status'] = $mixed ? 'mixed' : 'pass';
				$result['engine'] = 'comparison';
			}
		} finally {
			$cleanup = $this->cleanupDemoCdrRows($accountcode);
			if ($cleanup['cleanup_remaining'] > 0) throw new \Exception(sprintf(_('Demo cleanup incomplete: %d synthetic CDR rows remain.'), $cleanup['cleanup_remaining']));
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
		$demo_engines = isset($_REQUEST['demo_engines']) ? $_REQUEST['demo_engines'] : null;
		if (is_string($demo_engines)) {
			$demo_engines = explode(',', $demo_engines);
		}
		return [
			'demo_report' => isset($_REQUEST['demo_report']) ? $_REQUEST['demo_report'] : 'extension',
			'demo_size' => isset($_REQUEST['demo_size']) ? $_REQUEST['demo_size'] : 'light',
			'demo_seed' => isset($_REQUEST['demo_seed']) ? $_REQUEST['demo_seed'] : 0,
			'demo_rows' => isset($_REQUEST['demo_rows']) ? $_REQUEST['demo_rows'] : 0,
			'demo_engines' => $demo_engines ?: ['original'],
			'engine' => isset($_REQUEST['engine']) ? $_REQUEST['engine'] : 'original',
		];
	}

	private function normaliseDemoEngines($engines): array {
		if (!is_array($engines)) {
			$engines = [(string)$engines];
		}
		$out = [];
		foreach ($engines as $engine_id) {
			$engine_id = $this->normaliseEngineId($engine_id);
			if (!in_array($engine_id, $out, true)) {
				$out[] = $engine_id;
			}
		}
		if (empty($out)) throw new \InvalidArgumentException('At least one valid Demo engine is required.');
		return $out;
	}

	private function normaliseDemoReport($report): string {
		$report = strtolower(trim((string)$report));
		if (in_array($report, ['trunk', 'extension', 'group'], true)) {
			return $report;
		}
		throw new \InvalidArgumentException('Demo report must be trunk, extension or group.');
	}

	private function normaliseDemoSize($size): string {
		$size = strtolower(trim((string)$size));
		if (in_array($size, ['light', 'medium', 'heavy'], true)) {
			return $size;
		}
		throw new \InvalidArgumentException('Demo size must be light, medium or heavy.');
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

	// Linear congruential generator. Deterministic and reproducible from a seed,
	// which is the only property we need here. Do not replace with random_int()
	// or anything cryptographic; reproducibility from a saved seed is the contract.
	private function demoRand(int $state): int {
		return (int)(($state * 1103515245 + 12345) & 0x7fffffff);
	}

	/**
	 * Independent re-implementation of the per-name algorithm for accuracy
	 * checking. MUST NOT share code with any engine. If an engine has a bug,
	 * this function is what catches it. If you find yourself wanting to DRY
	 * this out, stop and think about why this exists.
	 */
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

	/**
	 * Independent re-implementation of the group algorithm for accuracy
	 * checking. MUST NOT share code with any engine. If an engine has a bug,
	 * this function is what catches it. If you find yourself wanting to DRY
	 * this out, stop and think about why this exists.
	 */
	private function expectedDemoGroup(array $rows): array {
		$per_second_count = [];
		foreach ($rows as $row) {
			if (!preg_match('|^PJSIP/([0-9]+)-|', $row['channel'])) {
				continue;
			}
			$start_ts = strtotime($row['calldate']);
			$end_ts = $start_ts + (int)$row['duration'];
			if (($end_ts - $start_ts) > 86400) {
				$end_ts = $start_ts + 86400;
			}
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
		$exp_ranges = isset($expected['peak_ranges']) ? $expected['peak_ranges'] : [];
		$act_ranges = isset($actual['peak_ranges']) ? $actual['peak_ranges'] : [];
		if (count($exp_ranges) !== count($act_ranges)) {
			return false;
		}
		foreach ($exp_ranges as $i => $r) {
			if (!isset($act_ranges[$i])) return false;
			if ($r['from'] !== $act_ranges[$i]['from']) return false;
			if ($r['to'] !== $act_ranges[$i]['to']) return false;
		}
		return true;
	}

	private function getCdrColumns(): array {
		if ($this->cdrColumnsCache === null) {
			try {
				$this->cdrColumnsCache = $this->cdrdb->query('SHOW COLUMNS FROM cdr')->fetchAll(\PDO::FETCH_ASSOC);
			} catch (\Throwable $e) {
				$this->logError('Unable to inspect the CDR schema: ' . $e->getMessage());
				throw $e;
			}
		}
		return $this->cdrColumnsCache;
	}

	private function insertDemoCdrRow(array $row): void {
		$columns = $this->getCdrColumns();
		$insert_columns = [];
		$placeholders = [];
		$params = [];

		foreach ($columns as $col) {
			$field = $col['Field'];
			$extra = isset($col['Extra']) ? strtolower($col['Extra']) : '';
			if (strpos($extra, 'auto_increment') !== false || strpos($extra, 'generated') !== false) {
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
		if (isset($col['Null']) && strtoupper($col['Null']) !== 'YES') {
			throw new \Exception(sprintf(_('CDR column "%s" is required but is not supported by demo mode.'), $field));
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
	private function calculatePerName(string $mode, string $start, string $end, float $started_at, bool $confirm_overrun, string $accountcode = '', string $engine_id = 'original', string $filter = '', ?callable $cancellationCheck = null, ?callable $progressUpdate = null): array {
		$identity = $this->identityForOperation($accountcode);
		$filter = trim($filter);
		$trunks = $mode === 'trunk' ? ($accountcode === '' ? $this->getTrunks() : array_keys($identity->configuredTrunks())) : [];
		$classified = $this->classifyPerNameRows($this->fetchPjsipCdrRows($start, $end, $accountcode), $mode, $identity);
		$selection = (new \FreePBX\modules\Concurrencycount\Services\HistoricalEndpointFilterService())->apply($mode, $classified['rows'], $identity, $filter);
		if ($selection['missing_reference']) {
			$empty = $this->emptyResult($mode, $start, $end, _('The selected PJSIP endpoint is unavailable or no longer has the required classification.'), $engine_id);
			$empty['filter'] = $filter;
			$empty['missing_reference'] = true;
			return $empty;
		}
		$rows = $selection['rows'];
		if ($mode === 'trunk' && $filter !== '') $trunks = [$filter];

		if (empty($rows)) {
			$empty = $this->emptyResult($mode, $start, $end, _('No attributable calls found in the selected date range.'), $engine_id);
			$empty['identity_anomalies'] = array_values($classified['anomalies']);
			$empty['filter'] = $filter;
			return $empty;
		}

		$all_names = $this->buildAllNames($mode, $rows, $trunks);
		$engine = $this->buildEngine($engine_id, $this->engineOptions($all_names, $started_at, $confirm_overrun, $cancellationCheck, $progressUpdate));
		$calculated = $engine->calculatePerName($mode, $rows);

		if (empty($calculated['per_name'])) {
			return $this->emptyResult($mode, $start, $end, _('No calls found in the selected date range.'), $engine_id);
		}

		$result = [
			'mode' => $mode, 'start' => $start, 'end' => $end,
			'per_name' => $calculated['per_name'], 'global_max' => $calculated['global_max'],
			'overview' => $this->buildPerNameOverview($mode, $start, $end, $rows, $calculated),
			'rows_processed' => $calculated['rows_processed'],
			'warning' => '',
			'identity_anomalies' => array_values($classified['anomalies']),
			'filter' => $filter,
		];
		if ($mode === 'trunk') {
			$result['peak_occurrences'] = $this->buildTrunkPeakOccurrences($rows, $calculated['per_name']);
			$result['trunk_entities'] = $this->buildTrunkEntities($trunks);
		}
		if ($engine_id !== 'original') {
			$result['engine'] = $engine_id;
		}
		return $result;
	}

	private function buildTrunkPeakOccurrences(array $rows, array $per_name): array {
		$analyser = new \FreePBX\modules\Concurrencycount\Analyzers\PeakDetailAnalyser();
		$by_trunk = [];
		foreach ($per_name as $trunk => $peak) {
			$peak = (int)$peak;
			if ($peak <= 0) {
				$by_trunk[$trunk] = [];
				continue;
			}
			$analysis = $analyser->analyseTrunk($rows, (string)$trunk, $peak);
			$by_trunk[$trunk] = [];
			foreach ($analysis['occurrences'] as $occurrence) {
				unset($occurrence['row_indexes']);
				$by_trunk[$trunk][] = $occurrence;
			}
		}
		return $by_trunk;
	}

	/**
	 * Build the full set of names to display, matching the bash logic
	 * (extension list from CDR; trunk list from get_trunks()).
	 */
	private function buildAllNames(string $mode, array $rows, array $trunks = []): array {
		$names = [];
		if ($mode === 'extension') {
			foreach ($rows as $r) if (!empty($r['identity'])) $names[$r['identity']] = true;
		} else {
			foreach ($trunks as $t) {
				$names[$t] = true;
			}
		}
		return $names;
	}

	/**
	 * Group mode. Mirrors bash calculate_group_concurrency().
	 */
	private function calculateGroup(string $start, string $end, float $started_at, bool $confirm_overrun, string $accountcode = '', string $engine_id = 'original', ?callable $cancellationCheck = null, ?callable $progressUpdate = null): array {
		$identity = $this->identityForOperation($accountcode);
		$classified = $this->classifyGroupRows($this->fetchPjsipCdrRows($start, $end, $accountcode), $identity);
		$rows = $classified['rows'];

		if (empty($rows)) {
			$empty = $this->emptyResult('group', $start, $end, _('No attributable extension legs found in the selected date range.'), $engine_id);
			$empty['identity_anomalies'] = array_values($classified['anomalies']);
			return $empty;
		}

		$engine = $this->buildEngine($engine_id, $this->engineOptions([], $started_at, $confirm_overrun, $cancellationCheck, $progressUpdate));
		$calculated = $engine->calculateGroup($rows);

		if ((int)$calculated['max_concurrency'] === 0) {
			return $this->emptyResult('group', $start, $end, _('No calls found in the selected date range.'), $engine_id);
		}

		$result = [
			'mode' => 'group', 'start' => $start, 'end' => $end,
			'max_concurrency' => $calculated['max_concurrency'], 'peak_ranges' => $calculated['peak_ranges'],
			'overview' => $this->buildGroupOverview($start, $end, $rows, $calculated),
			'rows_processed' => $calculated['rows_processed'],
			'warning' => '',
			'identity_anomalies' => array_values($classified['anomalies']),
		];
		if ($engine_id !== 'original') {
			$result['engine'] = $engine_id;
		}
		return $result;
	}

	private function buildPerNameOverview(string $mode, string $start, string $end, array $rows, array $calculated): array {
		$start_ts = strtotime($start);
		$end_ts = strtotime($end);
		$period_seconds = max(1, ($end_ts - $start_ts) + 1);
		$total_seconds = 0;
		$names_seen = [];
		foreach ($rows as $row) {
			$calldate = isset($row['calldate']) ? $row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			$name = isset($row['identity']) ? (string)$row['identity'] : '';
			if ($calldate === '' || $duration <= 0 || $name === '') {
				continue;
			}
			$call_start = strtotime($calldate);
			$call_end = $call_start + $duration;
			$overlap_start = max($start_ts, $call_start);
			$overlap_end = min($end_ts, $call_end);
			if ($overlap_end < $overlap_start) {
				continue;
			}
			$total_seconds += ($overlap_end - $overlap_start) + 1;
			$names_seen[$name] = true;
		}
		$average = $total_seconds / $period_seconds;
		$global_max = isset($calculated['global_max']) ? (int)$calculated['global_max'] : 0;
		return [
			'average_concurrency' => round($average, 2),
			'peak_to_average_ratio' => $average > 0 ? round($global_max / $average, 2) : 0,
			'names_with_peak' => $this->countNamesAtPeak(isset($calculated['per_name']) ? $calculated['per_name'] : [], $global_max),
			'names_seen' => count($names_seen),
		];
	}

	private function buildGroupOverview(string $start, string $end, array $rows, array $calculated): array {
		$start_ts = strtotime($start);
		$end_ts = strtotime($end);
		$period_seconds = max(1, ($end_ts - $start_ts) + 1);
		$total_seconds = 0;
		foreach ($rows as $row) {
			$calldate = isset($row['calldate']) ? $row['calldate'] : '';
			$duration = isset($row['duration']) ? (int)$row['duration'] : 0;
			if ($calldate === '' || $duration <= 0) {
				continue;
			}
			$call_start = strtotime($calldate);
			$call_end = $call_start + min($duration, 86400);
			$overlap_start = max($start_ts, $call_start);
			$overlap_end = min($end_ts, $call_end);
			if ($overlap_end < $overlap_start) {
				continue;
			}
			$seconds = ($overlap_end - $overlap_start) + 1;
			$total_seconds += $seconds * (isset($row['extension_legs']) ? max(0, (int)$row['extension_legs']) : 0);
		}
		$average = $total_seconds / $period_seconds;
		$max = isset($calculated['max_concurrency']) ? (int)$calculated['max_concurrency'] : 0;
		$peak_seconds = $this->rangeSeconds(isset($calculated['peak_ranges']) ? $calculated['peak_ranges'] : []);
		return [
			'average_concurrency' => round($average, 2),
			'peak_to_average_ratio' => $average > 0 ? round($max / $average, 2) : 0,
			'peak_seconds' => $peak_seconds,
			'peak_period_percent' => round(($peak_seconds / $period_seconds) * 100, 2),
		];
	}

	private function countNamesAtPeak(array $per_name, int $peak): int {
		$count = 0;
		foreach ($per_name as $value) {
			if ((int)$value === $peak && $peak > 0) {
				$count++;
			}
		}
		return $count;
	}

	private function rangeSeconds(array $ranges): int {
		$total = 0;
		foreach ($ranges as $range) {
			if (!isset($range['from']) || !isset($range['to'])) {
				continue;
			}
			$total += max(0, strtotime($range['to']) - strtotime($range['from']) + 1);
		}
		return $total;
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

	private function fetchPjsipCdrRows(string $start, string $end, string $accountcode = ''): array {
		$params = [':start' => $start, ':end' => $end];
		$account_filter = '';
		if ($accountcode !== '') {
			$account_filter = ' AND accountcode = :accountcode';
			$params[':accountcode'] = $accountcode;
		}
		$available = [];
		foreach ($this->getCdrColumns() as $column) if (isset($column['Field'])) $available[$column['Field']] = true;
		$identitySelect = (isset($available['linkedid']) ? '`linkedid`' : "'' AS linkedid") . ', ' . (isset($available['uniqueid']) ? '`uniqueid`' : "'' AS uniqueid");
		$sql = "SELECT calldate, duration, channel, dstchannel, dst, $identitySelect
				FROM cdr
				WHERE disposition='ANSWERED'
				  AND calldate BETWEEN :start AND :end
				  $account_filter
				  AND (channel LIKE 'PJSIP/%' OR dstchannel LIKE 'PJSIP/%')";
		$stmt = $this->cdrdb->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		if ($accountcode !== '') return $rows;
		return $this->getHistoricalCallExclusionService()->filterRows($rows, $this->getHistoricalCallExclusions());
	}

	private function classifyPerNameRows(array $cdrRows, string $mode, \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService $identity): array {
		$rows = [];
		$anomalies = [];
		foreach ($cdrRows as $cdr) {
			$candidates = [];
			foreach (['channel', 'dstchannel'] as $field) {
				$endpoint = $identity->parseChannel(isset($cdr[$field]) ? $cdr[$field] : '');
				if ($endpoint === null) continue;
				$classification = $identity->classify($endpoint);
				if ($classification['type'] === 'unknown' || $classification['type'] === 'conflict') $anomalies[$endpoint] = $classification;
				if ($classification['type'] === $mode) $candidates[$field] = ['calldate' => $cdr['calldate'], 'duration' => $cdr['duration'], 'chan' => $cdr[$field], 'identity' => $endpoint];
			}
			if ($mode === 'extension') {
				// Preserve established per-extension semantics: at most one leg per
				// CDR, preferring the destination endpoint when it is an extension.
				if (isset($candidates['dstchannel'])) $rows[] = $candidates['dstchannel'];
				elseif (isset($candidates['channel'])) $rows[] = $candidates['channel'];
			} else {
				foreach ($candidates as $candidate) $rows[] = $candidate;
			}
		}
		return ['rows' => $rows, 'anomalies' => $anomalies];
	}

	private function classifyGroupRows(array $cdrRows, \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService $identity): array {
		$rows = [];
		$anomalies = [];
		foreach ($cdrRows as $cdr) {
			$legs = 0;
			foreach (['channel', 'dstchannel'] as $field) {
				$endpoint = $identity->parseChannel(isset($cdr[$field]) ? $cdr[$field] : '');
				if ($endpoint === null) continue;
				$classification = $identity->classify($endpoint);
				if ($classification['type'] === 'extension') $legs++;
				elseif ($classification['type'] === 'unknown' || $classification['type'] === 'conflict') $anomalies[$endpoint] = $classification;
			}
			if ($legs > 0) $rows[] = ['calldate' => $cdr['calldate'], 'duration' => $cdr['duration'], 'extension_legs' => $legs];
		}
		return ['rows' => $rows, 'anomalies' => $anomalies];
	}

	private function identityForOperation(string $accountcode = ''): \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService {
		if ($accountcode === '') return $this->getPjsipIdentityService();
		// Demo endpoints are tagged synthetic data and receive an isolated
		// inventory; this does not alter or depend on FreePBX configuration.
		$trunks = [];
		foreach ($this->getTrunks() as $trunk) $trunks[$trunk] = ['channelid' => $trunk];
		$devices = [];
		foreach (['101', '102', '103', '104', '105', '106', '107', '108'] as $id) $devices[$id] = ['id' => $id];
		return new \FreePBX\modules\Concurrencycount\Services\PjsipIdentityService($trunks, $devices, []);
	}

	private function emptyResult(string $mode, string $start, string $end, string $msg, string $engine_id = 'original'): array {
		$base = [
			'mode' => $mode, 'start' => $start, 'end' => $end,
			'rows_processed' => 0,
			'empty_message' => $msg,
			'warning' => '',
		];
		if ($engine_id !== 'original') {
			$base['engine'] = $engine_id;
		}
		if ($mode === 'group') {
			$base['max_concurrency'] = 0;
			$base['peak_ranges'] = [];
		} else {
			$base['per_name'] = [];
			$base['global_max'] = 0;
		}
		return $base;
	}

	/* ============================================================
	 * OUTPUT
	 * ============================================================ */

	public function resultsToCsv(array $r): string {
		$rows = [];
		$rows[] = ['Concurrency Count ' . $this->getVersion() . ' - NOT CURRENTLY SUITABLE FOR PRODUCTION'];
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
			if (!empty($r['engines'])) {
				$rows[] = ['Engine', 'Accuracy', 'Wall time', 'Peak memory', 'Rows/sec'];
				foreach ($r['engines'] as $id => $engine_result) {
					$rows[] = [
						$id,
						$engine_result['accuracy_status'],
						number_format(((int)$engine_result['wall_ms']) / 1000, 2) . 's',
						$this->formatBytes((int)$engine_result['peak_memory_bytes']),
						(int)$engine_result['rows_per_second'],
					];
				}
				$rows[] = [];
			}
			if (isset($r['demo_report']) && $r['demo_report'] === 'group') {
				$rows[] = ['Metric', 'Expected', 'Actual'];
				$rows[] = ['Exact highest simultaneous count overall', isset($r['expected_max_concurrency']) ? $r['expected_max_concurrency'] : 0, isset($r['max_concurrency']) ? $r['max_concurrency'] : 0];
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
			$groupMax = isset($r['max_concurrency']) ? (int)$r['max_concurrency'] : 0;
			$rows[] = ['Exact highest simultaneous count overall', $groupMax];
			$rows[] = [];
			$rows[] = [$groupMax === 1 ? 'Activity time ranges' : 'Peak time ranges'];
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
			$rows[] = [$label, 'Exact peak'];
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
		$options['filter'] = isset($_REQUEST['filter']) ? trim((string)$_REQUEST['filter']) : '';

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

	private function streamDemoFixturePreview(): void {
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

	private function formatBytes(int $bytes): string {
		if ($bytes >= 1048576) {
			return (string)round($bytes / 1048576) . 'MB';
		}
		if ($bytes >= 1024) {
			return (string)round($bytes / 1024) . 'KB';
		}
		return $bytes . 'B';
	}

	private function handleEmail(): array {
		$mode = $this->normaliseMode(isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '');
		$start = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '';
		$end = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '';
		$options = $this->requestDemoOptions();
		$options['filter'] = isset($_REQUEST['filter']) ? trim((string)$_REQUEST['filter']) : '';
		$to = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';

		// Defence in depth against header injection before validation.
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
			$mail_result = $this->sendMail($to, $subject, $body, $filename, $csv);

			if ($mail_result['ok']) {
				return ['status' => true, 'message' => sprintf(_('Report accepted by the local mailer for %s. Delivery is not confirmed.'), $to)];
			}
			return ['status' => false, 'message' => $mail_result['message']];
		} catch (\Throwable $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	private function buildEmailBody(array $r): string {
		$lines = [];
		$lines[] = 'Concurrency Count report from ' . $this->getSystemIdentifier();
		$lines[] = 'Concurrency Count ' . $this->getVersion() . ' - NOT CURRENTLY SUITABLE FOR PRODUCTION';
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
			if (!empty($r['engines'])) {
				$lines[] = 'Engine comparison:';
				$lines[] = sprintf('%-12s  %-8s  %-10s  %-12s  %s', 'Engine', 'Accuracy', 'Wall time', 'Peak memory', 'Rows/sec');
				foreach ($r['engines'] as $id => $engine_result) {
					$lines[] = sprintf(
						'%-12s  %-8s  %-10s  %-12s  %s',
						$id,
						$engine_result['accuracy_status'],
						number_format(((int)$engine_result['wall_ms']) / 1000, 2) . 's',
						$this->formatBytes((int)$engine_result['peak_memory_bytes']),
						number_format((int)$engine_result['rows_per_second'])
					);
				}
				$lines[] = '';
			}
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
			$groupMax = isset($r['max_concurrency']) ? (int)$r['max_concurrency'] : 0;
			$lines[] = 'Exact highest simultaneous count overall: ' . $groupMax;
			if ($groupMax === 1) $lines[] = 'Status: Activity detected, no concurrency';
			$lines[] = '';
			if (!empty($r['peak_ranges'])) {
				$lines[] = ($groupMax === 1 ? 'Activity' : 'Peak') . ' time ranges:';
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
			$lines[] = sprintf('%-24s  %s', $label, 'Exact peak');
			if (!empty($r['per_name'])) {
				foreach ($r['per_name'] as $name => $count) {
					$lines[] = sprintf('%-24s  %d', $name, $count);
				}
			} elseif (!empty($r['empty_message'])) {
				$lines[] = $r['empty_message'];
			}
			$lines[] = '';
			$lines[] = 'Global maximum: ' . (isset($r['global_max']) ? $r['global_max'] : 0);
			if ((int)(isset($r['global_max']) ? $r['global_max'] : 0) === 1) $lines[] = 'Status: Activity detected, no concurrency';
		}

		$lines[] = '';
		$lines[] = $r['warning'];
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = 'Concurrency Count for FreePBX/PBXact 16 and 17 - NOT CURRENTLY SUITABLE FOR PRODUCTION';
		return implode("\n", $lines);
	}

	private function ensureCsrfToken(): void {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION[self::CSRF_SESSION_KEY])) {
			$_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
		}
	}

	private function getCsrfToken(): string {
		$this->ensureCsrfToken();
		return session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::CSRF_SESSION_KEY])
			? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
	}

	private function requireValidCsrfToken(): void {
		$expected = $this->getCsrfToken();
		$provided = isset($_REQUEST['token']) ? (string)$_REQUEST['token'] : '';
		if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
			throw new \Exception(_('Invalid security token. Please reload the page and try again.'));
		}
	}

	private function getSystemIdentifier(): string {
		$identifier = '';
		try {
			$identifier = (string)\FreePBX::Config()->get('FREEPBX_SYSTEM_IDENT');
		} catch (\Throwable $e) {
			$this->logWarning('Unable to read the FreePBX system identifier: ' . $e->getMessage());
		}
		$identifier = preg_replace('/\s+/', ' ', trim($identifier));
		return $identifier !== '' ? $identifier : 'unknown system';
	}

	private function logWarning(string $message): void {
		try {
			if (class_exists('\\FreePBX') && method_exists('\\FreePBX', 'Logger')) {
				\FreePBX::Logger()->warning('[concurrencycount] ' . $message);
			}
		} catch (\Throwable $e) {
			// Logging must not change report behaviour.
		}
	}

	private function logError(string $message): void {
		try {
			if (class_exists('\\FreePBX') && method_exists('\\FreePBX', 'Logger')) {
				\FreePBX::Logger()->error('[concurrencycount] ' . $message);
			}
		} catch (\Throwable $e) {
			// Logging must not change report behaviour.
		}
	}

	private function sendMail(string $to, string $subject, string $body, string $attachFilename = '', string $attachContent = ''): array {
		$tempPath = '';
		try {
			if (!class_exists('\\CI_Email')) {
				return ['ok' => false, 'message' => _('CI_Email is not available.')];
			}
			$from = $this->getNotificationFromAddress();
			if ($from === '') {
				return ['ok' => false, 'message' => _('Email "From:" Address is not configured in Advanced Settings.')];
			}
			$email = new \CI_Email();
			$senderName = $this->getNotificationSenderName();
			if ($this->emailFromSupportsReturnPath($email)) {
				$email->from($from, $senderName, $from);
			} else {
				$email->from($from, $senderName);
				if (method_exists($email, 'set_header')) {
					$email->set_header('Return-Path', $from);
				}
			}
			if (method_exists($email, 'reply_to')) {
				$email->reply_to($from, $senderName);
			}
			$email->to($to);
			$email->subject($subject);
			$email->set_mailtype('text');
			$email->message($body);

			if ($attachFilename !== '') {
				$tempPath = tempnam(sys_get_temp_dir(), 'cc-');
				if ($tempPath === false) {
					throw new \Exception(_('Unable to create a temporary CSV attachment.'));
				}
				$attachmentPath = $tempPath . '-' . basename($attachFilename);
				if (!rename($tempPath, $attachmentPath) || file_put_contents($attachmentPath, $attachContent) === false) {
					throw new \Exception(_('Unable to prepare the CSV attachment.'));
				}
				$tempPath = $attachmentPath;
				$email->attach($attachmentPath, 'attachment');
			}

			if ($email->send()) {
				return ['ok' => true, 'message' => ''];
			}
			$error = _('CI_Email send failed.');
			$debug = method_exists($email, 'print_debugger') ? trim(strip_tags((string)$email->print_debugger(['headers']))) : '';
			$debug = preg_replace('/\s+/', ' ', $debug);
			if ($debug !== '') {
				$debug = substr($debug, 0, 1000);
				$this->logError('CI_Email send failed: ' . $debug);
				$error .= ' ' . $debug;
			} else {
				$this->logError('CI_Email send failed without diagnostics.');
			}
			return ['ok' => false, 'message' => $error];
		} catch (\Throwable $e) {
			$this->logError('Email send exception: ' . $e->getMessage());
			return ['ok' => false, 'message' => $e->getMessage()];
		} finally {
			if ($tempPath !== '' && is_file($tempPath)) {
				@unlink($tempPath);
			}
		}
	}

	private function getNotificationFromAddress(): string {
		try {
			return $this->normaliseEmailAddress((string)\FreePBX::Config()->get('AMPUSERMANEMAILFROM'));
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function normaliseEmailAddress(string $value): string {
		$value = trim($value);
		if (preg_match('/<([^>]+)>/', $value, $matches)) {
			$value = trim($matches[1]);
		}
		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
	}

	private function getNotificationSenderName(): string {
		try {
			$brand = trim((string)\FreePBX::Config()->get('DASHBOARD_FREEPBX_BRAND'));
			return $brand !== '' ? $brand : 'Concurrency Count';
		} catch (\Throwable $e) {
			return 'Concurrency Count';
		}
	}

	private function emailFromSupportsReturnPath($email): bool {
		try {
			$method = new \ReflectionMethod($email, 'from');
			return $method->getNumberOfParameters() >= 3;
		} catch (\ReflectionException $e) {
			return false;
		}
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

class HistoricalCalculationCancelled extends \Exception {}
