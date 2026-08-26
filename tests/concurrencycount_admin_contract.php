<?php
/**
 * Static administrative/security contract.
 * Run directly: php tests/concurrencycount_admin_contract.php
 */

$root = dirname(__DIR__);
$class = file_get_contents($root . '/Concurrencycount.class.php');
$view = file_get_contents($root . '/views/main.php');
$javascript = file_get_contents($root . '/assets/js/concurrencycount.js');
$css = file_get_contents($root . '/assets/css/concurrencycount.css');
$liveJavascript = file_get_contents($root . '/assets/js/live-command-centre.js');
$chartJavascript = file_get_contents($root . '/assets/js/concurrency-charts.js');
$monitor = file_get_contents($root . '/alert-monitor.php');
$console = file_get_contents($root . '/Console/Concurrencycount.class.php');
$mailer = file_get_contents($root . '/alert-mailer.php');
$amiSource = file_get_contents($root . '/Services/AmiChannelSource.php');
$thresholdService = file_get_contents($root . '/Services/ThresholdService.php');
$install = file_get_contents($root . '/install.php');
$uninstall = file_get_contents($root . '/uninstall.php');
$readme = file_get_contents($root . '/README.md');
$registry = file_get_contents($root . '/Engines/Registry.php');
$liveSnapshotService = file_get_contents($root . '/Services/LiveSnapshotService.php');
$historicalReportsService = file_get_contents($root . '/Services/HistoricalReportsService.php');
$module = simplexml_load_file($root . '/module.xml');

function admin_contract_assert($condition, $message) {
	if (!$condition) throw new Exception($message);
}

admin_contract_assert(strpos($class, 'const AJAX_COMMANDS') !== false, 'Central AJAX command list missing');
foreach (['wizardstep', 'run', 'peakdetails', 'livestatus', 'getsettings', 'savesettings', 'monitorstatus', 'restartmonitor', 'historicalgraph', 'download', 'previewfixture', 'email', 'gettrunks', 'listhistoricalreports', 'createhistoricalreport', 'updatehistoricalreport', 'closehistoricalreport', 'activatehistoricalreport'] as $command) {
	admin_contract_assert(strpos($class, "'" . $command . "'") !== false, 'AJAX command missing: ' . $command);
}
admin_contract_assert(strpos($class, "'authenticate'] = true") !== false, 'AJAX authentication setting missing');
admin_contract_assert(strpos($class, "'allowremote'] = false") !== false, 'AJAX remote-access setting missing');
admin_contract_assert(substr_count($class, 'requireValidCsrfToken();') >= 2, 'JSON and custom AJAX handlers must require CSRF');
admin_contract_assert(strpos($class, 'hash_equals(') !== false, 'hash_equals CSRF validation missing');
admin_contract_assert(strpos($class, 'FREEPBX_SYSTEM_IDENT') !== false, 'System identifier missing from email implementation');
admin_contract_assert(strpos($class, 'unknown system') !== false, 'System identifier fallback missing');
admin_contract_assert(strpos($class, 'new \\CI_Email()') !== false, 'CI_Email transport missing');
admin_contract_assert(strpos($class, '$this->FreePBX->Mail()') === false, 'Obsolete FreePBX Mail transport remains');
admin_contract_assert(strpos($class, '@mail(') === false && strpos($class, 'mail($') === false, 'Raw PHP mail transport remains');
foreach (['getNotificationFromAddress', 'normaliseEmailAddress', 'getNotificationSenderName', 'emailFromSupportsReturnPath'] as $helper) {
	admin_contract_assert(strpos($class, 'function ' . $helper) !== false, 'Email helper missing: ' . $helper);
}
foreach (['->to($to)', '->subject($subject)', "->set_mailtype('text')", '->message($body)', '->attach(', '->send()'] as $call) {
	admin_contract_assert(strpos($class, $call) !== false, 'Email call missing: ' . $call);
}
admin_contract_assert(strpos($class, "'Return-Path'") !== false, 'Return-Path handling missing');
admin_contract_assert(strpos($class, 'reply_to(') !== false, 'Reply-To handling missing');
admin_contract_assert(strpos($class, 'print_debugger') !== false, 'CI_Email diagnostics missing');
admin_contract_assert(strpos($class, 'accepted by the local mailer') !== false, 'Local-mailer acceptance wording missing');
admin_contract_assert(strpos($view, 'data-csrf-token=') !== false && strpos($view, 'name="token"') !== false, 'View must expose the CSRF token');
admin_contract_assert(substr_count($javascript, 'token:') >= 3, 'AJAX, download, and fixture preview must send CSRF tokens');
admin_contract_assert(strpos($javascript, 'Sweep is experimental') !== false, 'Sweep experimental wording missing');
admin_contract_assert(strpos($view, 'Demo writes to CDR.') !== false, 'Demo warning missing');
admin_contract_assert(strpos($view, 'cc-download') !== false && strpos($view, 'cc-email-send') !== false, 'Download/email controls missing');
foreach (['trunk', 'extension', 'group'] as $mode) {
	admin_contract_assert(preg_match('/<input[^>]+type="radio"[^>]+name="cc-wizard-mode"[^>]+id="cc-mode-' . $mode . '"[^>]+value="' . $mode . '"/', $view) === 1, 'GUI reporting radio missing or remapped: ' . $mode);
	admin_contract_assert(strpos($view, 'for="cc-mode-' . $mode . '"') !== false, 'GUI reporting label missing: ' . $mode);
	admin_contract_assert(strpos($javascript, $mode . ':') !== false, 'GUI mode description missing: ' . $mode);
}
foreach (['Trunk Concurrency', 'Extension Concurrency', 'Group Concurrency'] as $label) {
	admin_contract_assert(strpos($view, $label) !== false, 'User-facing mode label missing: ' . $label);
}
admin_contract_assert(strpos($view, 'Overall Extension Concurrency') === false, 'Historical Group mode must no longer be labelled Overall Extension Concurrency');
admin_contract_assert(strpos($liveJavascript, "scopeRow('overall', 'Overall Live Concurrency', settings.overall)") !== false, 'Live threshold settings must label the overall scope as Overall Live Concurrency, not the historical Group label');
admin_contract_assert(strpos($thresholdService, "'Overall Live Concurrency' : substr") !== false, 'Live alert notifications must label the overall scope as Overall Live Concurrency, not the historical Group label');
admin_contract_assert(strpos($liveJavascript, 'Overall Extension Concurrency') === false && strpos($thresholdService, 'Overall Extension Concurrency') === false, 'No Live Command Centre reference may use the stale Overall Extension Concurrency wording');
admin_contract_assert(substr_count($view, 'type="radio" name="cc-wizard-mode"') === 3, 'Reporting modes must use three native radio controls');
admin_contract_assert(preg_match('/id="cc-mode-trunk"[^>]+value="trunk"[^>]+checked/', $view) === 1, 'Trunk must remain the default GUI mode');
admin_contract_assert(strpos($view, '<fieldset') !== false && strpos($view, '<legend') !== false, 'Reporting mode controls need an accessible fieldset and legend');
admin_contract_assert(strpos($view, 'aria-describedby="cc-mode-description"') !== false, 'Mode controls are not associated with contextual help');
admin_contract_assert(strpos($view, 'not a Ring Group') === false, 'Ring Group warning belongs in concise help, not as an ambiguous mode label');
admin_contract_assert(strpos($view, 'cc-mode-description') !== false, 'Dynamic mode help is missing');
admin_contract_assert(strpos($javascript, 'not a Ring Group or selected member list') !== false, 'Group mode must explicitly reject Ring Group interpretation');
admin_contract_assert(strpos($view, 'Calculation engine') !== false, 'Engine control is not separated from reporting scope');
admin_contract_assert(strpos($view, 'id="cc-engine-group"') !== false && strpos($view, 'id="cc-engine"') !== false, 'Engine control hierarchy missing');
admin_contract_assert(strpos($css, '.cc-mode-options') !== false && strpos($css, 'grid-template-columns: repeat(3') !== false, 'Desktop mode selector layout missing');
admin_contract_assert(strpos($css, '.cc-mode-option.is-selected') !== false && strpos($css, ':focus-within') !== false, 'Selected and keyboard focus states missing');
admin_contract_assert(strpos($css, 'grid-template-columns: 1fr') !== false, 'Mobile mode selector stacking missing');
admin_contract_assert(strpos($registry, "'original'") !== false && strpos($registry, "'experimental' => false") !== false, 'Original engine status changed');
admin_contract_assert(strpos($registry, "'sweep'") !== false && strpos($registry, "'experimental' => true") !== false, 'Sweep engine status changed');
foreach (['### Trunk Concurrency', '### Extension Concurrency', '### Group Concurrency', '### Demo and engine comparison'] as $heading) {
	admin_contract_assert(strpos($readme, $heading) !== false, 'README reporting section missing: ' . $heading);
}
admin_contract_assert(strpos($readme, 'Overall Extension Concurrency') === false, 'README must no longer describe historical Group mode as Overall Extension Concurrency');
foreach (['including both boundary seconds', 'not mean a configured FreePBX Ring Group', 'Compare Engines', 'CLI keeps its existing option names'] as $concept) {
	admin_contract_assert(strpos($readme, $concept) !== false, 'README reporting contract missing: ' . $concept);
}
foreach (['today', 'yesterday', 'last7', 'last30', 'month', 'year', 'lastyear', 'custom'] as $preset) {
	admin_contract_assert(strpos($view, 'data-preset="' . $preset . '"') !== false, 'Date preset missing: ' . $preset);
}
admin_contract_assert(strpos($view, 'type="date"') !== false && strpos($view, 'cc-include-time') !== false, 'Native custom date/time controls missing');
admin_contract_assert(strpos($javascript, "command: 'peakdetails'") !== false, 'Peak detail AJAX wiring missing');
foreach (['cc-show-occurrences', 'cc-occurrence-toggle', 'loadOccurrence'] as $drilldown) {
	admin_contract_assert(strpos($javascript, $drilldown) !== false, 'Trunk drill-down wiring missing: ' . $drilldown);
}
foreach (['Peak trunk concurrency', 'Peak assigned CDR concurrency', 'Peak group concurrency'] as $resultTerm) {
	admin_contract_assert(strpos($javascript, $resultTerm) !== false, 'Mode-specific result terminology missing: ' . $resultTerm);
}
admin_contract_assert(strpos($javascript, 'config.php?display=') === false, 'Frontend must not construct FreePBX administrative URLs');
admin_contract_assert(strpos($class, "'need_html' => 'true'") !== false, 'Native CDR report action must submit an HTML search');
foreach (['ActionID', 'CoreShowChannelsComplete', 'ListItems', 'Asterisk channel snapshot did not complete'] as $snapshotContract) {
	admin_contract_assert(strpos($amiSource, $snapshotContract) !== false, 'Complete AMI snapshot contract missing: ' . $snapshotContract);
}
foreach (['CoreShowChannels', 'send_request', 'add_event_handler'] as $rawAmiPrimitive) {
	admin_contract_assert(strpos($liveJavascript, $rawAmiPrimitive) === false, 'Browser must not provide raw AMI primitive: ' . $rawAmiPrimitive);
}
admin_contract_assert(strpos($class, 'getConfiguredLiveTrunks') !== false && strpos($class, 'listTrunks()') !== false, 'Live trunk discovery must use Core configuration');
foreach (['getLiveStatus', 'getLiveSettings', 'saveLiveSettings', 'getHistoricalGraph', 'runThresholdMonitor'] as $sharedMethod) {
	admin_contract_assert(strpos($class, 'function ' . $sharedMethod) !== false, 'Shared backend capability missing: ' . $sharedMethod);
}
admin_contract_assert(strpos($class, 'LEGACY_MONITOR_CRON_LINE') !== false && strpos($class, 'removeLine(self::LEGACY_MONITOR_CRON_LINE)') !== false, 'Legacy minute monitor cron is not removed on upgrade');
admin_contract_assert(strpos($class, 'addLine(self::LEGACY_MONITOR_CRON_LINE)') === false, 'Minute monitor cron must not be registered');
foreach (['startAlertMonitor', 'stopAlertMonitor', 'restartAlertMonitor', 'getAlertMonitorStatus'] as $monitorMethod) {
	admin_contract_assert(strpos($class, 'function ' . $monitorMethod) !== false, 'PM2 monitor lifecycle method missing: ' . $monitorMethod);
}
foreach (['Newchannel', 'Newstate', 'Hangup', 'Rename', 'Masquerade', 'wait_response(true, true)', 'stream_set_timeout($astman->socket, 5)', 'min(30, $reconnectDelay * 2)'] as $workerContract) {
	admin_contract_assert(strpos($monitor, $workerContract) !== false, 'Event monitor contract missing: ' . $workerContract);
}
admin_contract_assert(strpos($class, "GET_LOCK('concurrencycount_alert_monitor', 0)") !== false, 'Monitor evaluation lock missing');
admin_contract_assert(strpos($class, 'ALERT_OUTBOX_KEY') !== false && strpos($class, '->transaction(') !== false, 'Atomic alert state/outbox persistence missing');
admin_contract_assert(strpos($class, 'MAIL_PROCESS_NAME') !== false && strpos($class, "__DIR__ . '/alert-mailer.php'") !== false, 'Separate supervised mail worker missing');
admin_contract_assert(strpos($mailer, 'processAlertOutbox()') !== false && strpos($mailer, "['skip_astman']") !== false, 'Mail worker must drain outbox without AMI');
foreach (['MONITOR_HEARTBEAT_KEY', 'last_successful_snapshot_at', 'ami_status', "status = 'degraded'"] as $heartbeatContract) {
	admin_contract_assert(strpos($class, $heartbeatContract) !== false, 'Monitor heartbeat contract missing: ' . $heartbeatContract);
}
admin_contract_assert(strpos($install, '->install()') !== false && strpos($uninstall, '->uninstall()') !== false, 'Procedural lifecycle does not delegate persistence/worker setup');
foreach (['LIVE', 'Current Asterisk state', 'HISTORICAL', 'Reconstructed from CDR records'] as $sourceLabel) {
	admin_contract_assert(strpos($view, $sourceLabel) !== false, 'Live/historical source label missing: ' . $sourceLabel);
}
foreach (['1, 5, 10, 15, 30, 60', 'Enable threshold alerts', 'Send recovery notifications', 'cc-threshold-rows'] as $settingControl) {
	admin_contract_assert(strpos($view, $settingControl) !== false, 'Live setting control missing: ' . $settingControl);
}
foreach (['Unattended alert monitor', 'Restart monitor', 'reconciles every 5 seconds'] as $monitorControl) {
	admin_contract_assert(strpos($view, $monitorControl) !== false, 'Monitor health control missing: ' . $monitorControl);
}
admin_contract_assert(preg_match('/class="cc-workspace-tab"[^>]*id="cc-tab-live"[^>]*role="tab"[^>]*aria-selected="true"/', $view) === 1, 'Live workspace tab must default to selected via aria-selected, not a button-state class');
admin_contract_assert(strpos($view, 'btn btn-primary cc-workspace-tab') === false && strpos($view, 'btn btn-default cc-workspace-tab') === false, 'Workspace tabs must not use enable/disable button styling');
admin_contract_assert(strpos($liveJavascript, "removeClass('active btn-primary')") === false && strpos($liveJavascript, "addClass('active btn-primary')") === false, 'Workspace switching must not toggle button-state classes');
admin_contract_assert(strpos($liveJavascript, "attr('aria-selected', 'true')") !== false && strpos($liveJavascript, "attr('aria-selected', 'false')") !== false, 'Workspace switching must use aria-selected semantics');
admin_contract_assert(strpos($view, 'do not enable or disable live monitoring') !== false, 'Workspace tabs must be explicitly labelled as navigation only');
admin_contract_assert(strpos($view, 'cc-settings-button') !== false, 'Thresholds & alerts control must use a neutral settings affordance, not an enable/disable button style');
foreach (['OVERALL LIVE CONCURRENCY', 'active monitored PJSIP call legs now'] as $overallLabel) {
	admin_contract_assert(strpos($view, $overallLabel) !== false, 'Overall Live Concurrency wording missing: ' . $overallLabel);
}
admin_contract_assert(strpos($view, 'OVERALL EXTENSION CONCURRENCY') === false, 'Live Overall metric must not be labelled as extension-only now that trunk legs are included');
admin_contract_assert(substr_count($liveSnapshotService, '$overallCalls[] = $channel;') === 2, 'Monitored trunk legs must contribute to Overall Live Concurrency alongside numeric extension legs');
admin_contract_assert(strpos($console, "'Overall Live Concurrency (active monitored PJSIP legs): ' . \$snapshot['overall']['current']") !== false, 'CLI must print the same Overall field the GUI reads, not a separately computed value');
admin_contract_assert(strpos($liveJavascript, "appendPoint(history.overall, data.generated_ts, data.overall.current)") !== false && strpos($liveJavascript, 'function renderSnapshot(data)') !== false, 'Overall and trunk rolling series must be derived from the same live snapshot object');
admin_contract_assert(strpos($liveJavascript, "command: 'monitorstatus'") !== false && strpos($liveJavascript, "command: 'restartmonitor'") !== false, 'GUI monitor status/restart parity missing');
foreach (['if (request ||', 'document.hidden', 'requestSequence', 'series.length > 900', "command: 'livestatus'", "command: 'savesettings'", "command: 'historicalgraph'"] as $pollingContract) {
	admin_contract_assert(strpos($liveJavascript, $pollingContract) !== false, 'Safe live polling contract missing: ' . $pollingContract);
}
admin_contract_assert(strpos($liveJavascript, "trigger('click')") !== false && strpos($liveJavascript, 'cc-occurrence-section') !== false, 'Historical graph does not reuse occurrence drill-down');

// Workspace ownership: exactly one Live workspace and one Historical workspace, each a real sibling container.
admin_contract_assert(substr_count($view, 'id="cc-live-section"') === 1, 'Exactly one Live workspace container must exist');
admin_contract_assert(substr_count($view, 'id="cc-historical-section"') === 1, 'Exactly one Historical workspace container must exist');
admin_contract_assert(preg_match('/<section id="cc-historical-section"[^>]*class="cc-workspace"[^>]*role="tabpanel"[^>]*aria-labelledby="cc-tab-historical"[^>]*style="display:none;"/', $view) === 1, 'Historical workspace must be a proper tabpanel section, hidden only via its own inline style');
foreach (['id="cc-launch"', 'id="cc-demo-launch"', 'id="cc-historical-graph"', 'id="cc-results"', 'id="cc-results-body"', 'id="cc-download"'] as $historicalControlId) {
	admin_contract_assert(strpos($view, $historicalControlId) !== false, 'Historical workspace must retain existing control: ' . $historicalControlId);
}
$liveSectionStart = strpos($view, '<section id="cc-live-section"');
$historicalSectionStart = strpos($view, '<section id="cc-historical-section"');
admin_contract_assert($liveSectionStart !== false && $historicalSectionStart !== false && $historicalSectionStart > $liveSectionStart, 'Historical workspace must follow Live workspace as a sibling, not be nested inside it');
$betweenSections = substr($view, $liveSectionStart, $historicalSectionStart - $liveSectionStart);
admin_contract_assert(substr_count($betweenSections, '<section') === substr_count($betweenSections, '</section>'), 'Live workspace section must close before the Historical workspace section opens (no accidental nesting)');
// Reject the previously-shipped defect where the outer row/col-sm-12 wrapper never closed, silently swallowing
// every modal that follows the workspaces into the still-open .concurrencycount container.
$openDivs = substr_count($view, '<div') - substr_count($view, '</div>');
admin_contract_assert($openDivs === 0, 'views/main.php has unbalanced <div> tags (' . $openDivs . ' unclosed) - modals after the workspaces would be nested in the wrong container');
admin_contract_assert(preg_match('/<\/section>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<div class="modal fade concurrencycount" id="cc-live-settings-modal"/', $view) === 1, 'The outer row/col-sm-12/concurrencycount wrapper must close before the first modal, keeping modals as top-level siblings');
admin_contract_assert(strpos($chartJavascript, 'this.threshold') !== false && strpos($chartJavascript, 'onSelect') !== false, 'Chart threshold/interaction support missing');
admin_contract_assert(strpos($css, '.cc-live-trunk-grid') !== false && strpos($css, '[data-status="exceeded"]') !== false, 'Command-centre responsive/status styling missing');
admin_contract_assert(strpos($javascript, "command: 'download'") !== false && strpos($javascript, "command: 'email'") !== false, 'Download/email command wiring missing');
admin_contract_assert(strpos($css, '#page_body') !== false && strpos($css, 'cc-table-scroll') !== false, 'Responsive containment/table scrolling missing');
admin_contract_assert((string)$module->version === '2.1.0', 'Admin contract version mismatch');

/* Persisted historical report tabs */
admin_contract_assert(strpos($class, 'HISTORICAL_REPORTS_KEY') !== false, 'Historical report tabs must use the module settings key persistence layer, not a new table');
admin_contract_assert(strpos($install, 'install()') !== false && strpos($class, 'CREATE TABLE') === false, 'No new database table should be introduced for historical report tabs');
foreach (['getHistoricalReports', 'createHistoricalReport', 'updateHistoricalReport', 'closeHistoricalReport', 'setActiveHistoricalReport'] as $method) {
	admin_contract_assert(strpos($class, 'function ' . $method) !== false, 'Historical report tab GUI/CLI-shared method missing: ' . $method);
}
admin_contract_assert(strpos($class, "GET_LOCK('concurrencycount_historical_reports'") !== false, 'Historical report tab mutations must be serialised so a double-click cannot exceed the five-report limit');
admin_contract_assert(strpos($historicalReportsService, 'MAX_REPORTS = 5') !== false, 'Five-report hard limit must be enforced in the backend service, not only the GUI');
admin_contract_assert(strpos($console, "'list-historical-reports'") !== false && strpos($console, "'show-historical-report'") !== false && strpos($console, "'delete-historical-report'") !== false, 'CLI visibility/management for persisted historical report tabs is missing');

/* Tab strip markup: exactly one of each core container, no duplicated result DOM */
foreach (['id="cc-report-tabs"', 'id="cc-report-workspace"', 'id="cc-report-landing"', 'id="cc-report-new"'] as $needle) {
	admin_contract_assert(substr_count($view, $needle) === 1, 'Historical report tab container must appear exactly once: ' . $needle);
}
admin_contract_assert(substr_count($view, 'id="cc-results"') === 1 && substr_count($view, 'id="cc-results-body"') === 1, 'Results DOM must remain a single shared surface, not duplicated per report tab (avoids duplicate IDs)');
admin_contract_assert(strpos($view, 'aria-label="Close ') === false, 'Close button accessible label is generated client-side per report, not hardcoded in markup');
admin_contract_assert(strpos($javascript, "escapeHtml('Close ' + report.title)") !== false, 'Each report tab close control must expose an accessible "Close Historic Report N" label');
admin_contract_assert(strpos($javascript, "role=\"tab\"") !== false, 'Report tabs must use tab semantics');
admin_contract_assert(strpos($javascript, 'runTargetReportId') !== false && strpos($javascript, 'targetReportId') !== false, 'Report results must be attached to the report instance captured at request time, not read live at response time');
admin_contract_assert(strpos($javascript, 'occurrenceCache') !== false, 'Occurrence drill-down state must be scoped per report instance');
admin_contract_assert(strpos($javascript, 'runTargetReportId = null;') !== false, 'Demo must not silently attach its result to a persisted report tab');

/* Live Command Centre must remain untouched by this change */
foreach (['function switchWorkspace', 'function updateOverall', 'function pollLive', 'function startPolling'] as $liveFn) {
	admin_contract_assert(strpos($liveJavascript, $liveFn) !== false, 'Live Command Centre function must remain present and unchanged: ' . $liveFn);
}
admin_contract_assert(strpos($liveJavascript, 'historicalReports') === false, 'Live Command Centre file must not know about the historical report tab model');

echo "Administrative contract passed\n";
