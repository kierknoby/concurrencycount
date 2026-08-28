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
$liveJavascript = file_get_contents($root . '/assets/js/live-view.js');
$chartJavascript = file_get_contents($root . '/assets/js/concurrency-charts.js');
$telemetryJavascript = file_get_contents($root . '/assets/js/telemetry-format.js');
$historicalRunStateJavascript = file_get_contents($root . '/assets/js/historical-run-state.js');
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
$identityService = file_get_contents($root . '/Services/PjsipIdentityService.php');
$exclusionService = file_get_contents($root . '/Services/HistoricalCallExclusionService.php');
$module = simplexml_load_file($root . '/module.xml');

function admin_contract_assert($condition, $message) {
	if (!$condition) throw new Exception($message);
}

admin_contract_assert(strpos($class, 'const AJAX_COMMANDS') !== false, 'Central AJAX command list missing');
foreach (['wizardstep', 'run', 'cancelcalculation', 'calculationtelemetry', 'peakdetails', 'livestatus', 'getsettings', 'savesettings', 'monitorstatus', 'restartmonitor', 'historicalgraph', 'download', 'previewfixture', 'email', 'gettrunks', 'listhistoricalreports', 'createhistoricalreport', 'updatehistoricalreport', 'closehistoricalreport', 'activatehistoricalreport', 'getidentityclassifications', 'saveidentityclassification', 'resetidentityclassification', 'resetallidentityclassifications', 'listexcludedcalls', 'excludecall', 'restoreexcludedcall', 'restoreallexcludedcalls'] as $command) {
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
admin_contract_assert(strpos($liveJavascript, 'Overall Extension Concurrency') === false && strpos($thresholdService, 'Overall Extension Concurrency') === false, 'No Live View reference may use the stale Overall Extension Concurrency wording');
admin_contract_assert(substr_count($view, 'type="radio" name="cc-wizard-mode"') === 3, 'Reporting modes must use three native radio controls');
admin_contract_assert(preg_match('/id="cc-mode-trunk"[^>]+value="trunk"[^>]+checked/', $view) === 1, 'Trunk must remain the default GUI mode');
admin_contract_assert(strpos($view, '<fieldset') !== false && strpos($view, '<legend') !== false, 'Reporting mode controls need an accessible fieldset and legend');
admin_contract_assert(strpos($view, 'aria-describedby="cc-mode-description"') !== false, 'Mode controls are not associated with contextual help');
admin_contract_assert(strpos($view, 'not a Ring Group') === false, 'Ring Group warning belongs in concise help, not as an ambiguous mode label');
admin_contract_assert(strpos($view, 'cc-mode-description') !== false, 'Dynamic mode help is missing');
admin_contract_assert(strpos($javascript, 'not a Ring Group or selected member list') !== false, 'Group mode must explicitly reject Ring Group interpretation');
admin_contract_assert(strpos($view, 'Calculation engine') !== false, 'Engine control is not separated from reporting scope');
admin_contract_assert(strpos($view, 'id="cc-engine-group"') !== false && strpos($view, 'id="cc-engine"') !== false, 'Engine control hierarchy missing');
admin_contract_assert(strpos($view, 'id="cc-engine-explanation"') !== false && strpos($view, 'Calculation engines') !== false, 'Visible Calculation engines explanation missing');
admin_contract_assert(strpos($view, 'Recommended, default and reference implementation') !== false && strpos($view, 'most established calculation path') !== false, 'Original reference/default explanation missing');
admin_contract_assert(strpos($view, 'Experimental, event-based calculation') !== false && strpos($view, 'designed to return the same concurrency result') !== false, 'Sweep experimental same-result explanation missing');
admin_contract_assert(strpos($view, 'three calls overlap between 10:05:00 and 10:05:30') !== false && strpos($view, 'peak concurrency of 3') !== false, 'Engine overlap example missing');
admin_contract_assert(strpos($view, 'aria-describedby="cc-engine-explanation"') !== false, 'Engine selector is not associated with its visible explanation');
admin_contract_assert(strpos($css, '.cc-engine-explanation') !== false && strpos($css, 'overflow-wrap: anywhere') !== false, 'Engine explanation responsive styling missing');
admin_contract_assert(strpos($css, '.cc-mode-options') !== false && strpos($css, 'grid-template-columns: repeat(3') !== false, 'Desktop mode selector layout missing');
admin_contract_assert(strpos($css, '.cc-mode-option.is-selected') !== false && strpos($css, ':focus-within') !== false, 'Selected and keyboard focus states missing');
admin_contract_assert(strpos($css, 'grid-template-columns: 1fr') !== false, 'Mobile mode selector stacking missing');
admin_contract_assert(strpos($registry, "'original'") !== false && strpos($registry, "'experimental' => false") !== false, 'Original engine status changed');
admin_contract_assert(strpos($registry, "'sweep'") !== false && strpos($registry, "'experimental' => true") !== false, 'Sweep engine status changed');
foreach (['### Trunk Concurrency', '### Extension Concurrency', '### Group Concurrency', '### Demo and engine comparison'] as $heading) {
	admin_contract_assert(strpos($readme, $heading) !== false, 'README reporting section missing: ' . $heading);
}
admin_contract_assert(strpos($readme, 'Overall Extension Concurrency') === false, 'README must no longer describe historical Group mode as Overall Extension Concurrency');
foreach (['including both boundary seconds', 'not mean a configured FreePBX Ring Group', 'Compare Engines', 'CLI option names remain stable', 'explicit date aliases', 'stricter argument validation', 'safer operation/health exit behaviour'] as $concept) {
	admin_contract_assert(strpos($readme, $concept) !== false, 'README reporting contract missing: ' . $concept);
}
foreach (['today', 'yesterday', 'last7', 'last30', 'month', 'year', 'lastyear', 'custom'] as $preset) {
	admin_contract_assert(strpos($view, 'data-preset="' . $preset . '"') !== false, 'Date preset missing: ' . $preset);
}
admin_contract_assert(strpos($view, 'type="date"') !== false && strpos($view, 'cc-include-time') !== false, 'Native custom date/time controls missing');
admin_contract_assert(substr_count($view, 'id="cc-include-time"') === 1 && preg_match('/<input[^>]+type="checkbox"[^>]+id="cc-include-time"/', $view) === 1, 'Include time must remain one native checkbox');
admin_contract_assert(strpos($view, '<label class="cc-time-toggle" for="cc-include-time">') !== false, 'Include time text must be explicitly associated with its checkbox');
admin_contract_assert(preg_match('/<div class="cc-range-centre">.*id="cc-range-label".*id="cc-include-time".*<\/div>/s', $view) === 1, 'Include time must sit in the centred date-range column');
admin_contract_assert(strpos($css, '.cc-range-centre') !== false && strpos($css, '.cc-time-toggle input[type="checkbox"]') !== false && strpos($css, 'min-height: 0') !== false, 'Compact centred Include time styling missing');
$cancelCount = preg_match_all('/<button[^>]*class="([^"]*)"[^>]*>[^<]*(?:<[^>]+>[^<]*<\/[^>]+>[^<]*)*<\?php echo _\(\'Cancel\'\); \?>/s', $view, $cancelMatches);
admin_contract_assert($cancelCount === substr_count($view, "_('Cancel')"), 'Every user-facing Cancel button must be audited');
foreach ($cancelMatches[1] as $cancelClasses) {
	admin_contract_assert(strpos($cancelClasses, 'cc-btn-cancel') !== false, 'Cancel button missing shared soft-danger styling');
	admin_contract_assert(strpos($cancelClasses, 'btn-primary') === false && strpos($cancelClasses, 'btn-success') === false, 'Cancel button must not use affirmative styling');
}
admin_contract_assert(strpos($css, '.cc-btn-cancel') !== false && strpos($css, '.cc-btn-cancel:focus-visible') !== false, 'Shared Cancel hover/focus styling missing');
admin_contract_assert(strpos($javascript, "command: 'peakdetails'") !== false, 'Peak detail AJAX wiring missing');
foreach (['cc-trunk-result', 'cc-occurrence-section', 'cc-occurrence-toggle', 'loadOccurrence'] as $drilldown) {
	admin_contract_assert(strpos($javascript, $drilldown) !== false, 'Trunk drill-down wiring missing: ' . $drilldown);
}
admin_contract_assert(strpos($javascript, 'cc-show-occurrences') === false, 'Obsolete separate occurrence reveal control remains');
admin_contract_assert(strpos($javascript, 'style="display:none"><h5>Peak occurrences') === false, 'Compact peak occurrences must render immediately');
admin_contract_assert(strpos($javascript, 'renderOccurrenceSection(trunk, nameIndex, r, activityOnly)') !== false, 'Each occurrence list must be contained by its full trunk result model');
admin_contract_assert(strpos($javascript, "if (detail.data('loading')) return;") !== false, 'Rapid expansion must not duplicate an in-flight detail request');
admin_contract_assert(strpos($javascript, 'Select this occurrence to retry.') !== false, 'Failed occurrence detail requests must remain retryable');
admin_contract_assert(strpos($javascript, 'setOccurrenceExpanded') !== false && strpos($javascript, "aria-expanded=\"false\"") !== false && strpos($javascript, 'aria-controls=') !== false, 'Occurrence disclosure accessibility wiring missing');
admin_contract_assert(strpos($javascript, "detail.toggle()") !== false, 'Loaded occurrences must support independent expand and collapse');
admin_contract_assert(strpos($javascript, 'report.occurrenceCache[cacheKey]') !== false && strpos($javascript, 'restoreOccurrenceState(report)') !== false, 'Occurrence detail and expansion state must remain cached per Historic Report instance');
foreach (['Peak trunk concurrency', 'Peak assigned CDR concurrency', 'Peak group concurrency'] as $resultTerm) {
	admin_contract_assert(strpos($javascript, $resultTerm) !== false, 'Mode-specific result terminology missing: ' . $resultTerm);
}
foreach (['concurrencyNames', 'activityNames', 'Show activity-only results', 'Activity detected, no concurrency', 'No concurrent calls detected.', 'No activity found for this report.'] as $activityContract) {
	admin_contract_assert(strpos($javascript, $activityContract) !== false, 'Historical activity/concurrency presentation contract missing: ' . $activityContract);
}
admin_contract_assert(strpos($javascript, "parseInt(r.per_name[name], 10) >= 2") !== false && strpos($javascript, "parseInt(r.per_name[name], 10) === 1") !== false, 'Historical entity partition must preserve exact peak 1 as activity and begin concurrency at 2');
admin_contract_assert(strpos($javascript, 'cc-activity-result') !== false && strpos($javascript, 'renderOccurrenceSection(trunk, nameIndex, r, activityOnly)') !== false, 'Peak-1 Trunks must remain discoverable with their occurrence path');
admin_contract_assert(strpos($javascript, "activityOnly ? 'Activity occurrence'") !== false, 'Peak-1 Trunk occurrences must use activity wording');
admin_contract_assert(strpos($javascript, "command: 'peakdetails'") !== false && strpos($javascript, 'cc-exclude-call') !== false, 'Activity-only Trunks must retain lazy detail and Exclude Call');
admin_contract_assert(strpos($javascript, 'aria-controls="cc-activity-only-results"') !== false && strpos($javascript, "button.attr('aria-expanded', expanded ? 'true' : 'false')") !== false && strpos($javascript, ".prop('hidden', !expanded)") !== false, 'Activity-only disclosure must be keyboard-accessible and presentation-only');
admin_contract_assert(strpos($css, '.cc-trunk-result > .cc-occurrence-section .cc-occurrence.panel.panel-default') !== false && strpos($css, '.cc-occurrence.panel.panel-default > .panel-heading') !== false && strpos($css, '.cc-occurrence.panel.panel-default > .panel-body') !== false && strpos($css, 'background-color: #fff') !== false && strpos($css, 'background-image: none') !== false, 'Nested occurrence panels, headings and detail bodies must explicitly override contextual theme surfaces');
admin_contract_assert(strpos($javascript, "cc-trunk-result' + (isPeak ? ' cc-peak-row' : '')") !== false && strpos($css, 'tr.cc-peak-row > td') !== false, 'Neutral occurrence styling must not remove parent/result peak highlighting');
admin_contract_assert(strpos($javascript, 'if (occurrenceIndex === 5)') !== false && strpos($javascript, 'class="cc-additional-occurrences" hidden') !== false, 'Each Trunk must initially show only its first five occurrences');
admin_contract_assert(strpos($javascript, 'if (occurrences.length > 5)') !== false && strpos($javascript, "escapeHtml(occurrences.length - 5) + ' more</button>'") !== false && strpos($javascript, "? 'Show less'") !== false, 'Per-Trunk Show more/less control must exist only for more than five occurrences');
admin_contract_assert(strpos($javascript, 'data-occurrence-index="\' + occurrenceIndex') !== false, 'Additional occurrences must retain their original occurrence indices');
admin_contract_assert(strpos($javascript, 'class="btn btn-default btn-sm cc-occurrence-list-toggle" aria-expanded="false" aria-controls="cc-additional-occurrences-') !== false, 'Occurrence-list disclosure must expose accessible expanded and controlled state');
$occurrenceListHandlerStart = strpos($javascript, ".off('click', '.cc-occurrence-list-toggle')");
$occurrenceListHandlerEnd = strpos($javascript, ".off('click', '.cc-activity-toggle')", $occurrenceListHandlerStart);
$occurrenceListHandler = substr($javascript, $occurrenceListHandlerStart, $occurrenceListHandlerEnd - $occurrenceListHandlerStart);
admin_contract_assert(strpos($occurrenceListHandler, ".prop('hidden', !expanded)") !== false && strpos($occurrenceListHandler, 'occurrenceCache') === false && strpos($occurrenceListHandler, 'ajax(') === false, 'Show less must only hide additional occurrences without clearing cache or refetching detail');
admin_contract_assert(strpos($javascript, 'function formatOccurrenceRange') !== false && strpos($javascript, "months = ['Jan', 'Feb', 'Mar'") !== false, 'Occurrence headings must include a British-readable calendar date');
admin_contract_assert(strpos($javascript, 'if (fromParts.date === toParts.date)') !== false && strpos($javascript, "' to ' + toParts.label + ', ' + toParts.time") !== false, 'Occurrence formatting must avoid repeating same-day dates and include both cross-day dates');
admin_contract_assert(strpos($readme, 'an exact peak of 1 means activity occurred') !== false && strpos($readme, 'concurrency begins at 2') !== false, 'README activity-only semantic is missing');
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
admin_contract_assert(strpos($javascript, "attr('aria-selected', 'true')") !== false && strpos($javascript, "attr('aria-selected', 'false')") !== false, 'Top-level tab selection must use aria-selected semantics');
admin_contract_assert(strpos($view, 'do not enable or disable live monitoring') !== false, 'Workspace tabs must be explicitly labelled as navigation only');
admin_contract_assert(strpos($view, 'cc-settings-button') !== false, 'Thresholds & alerts control must use a neutral settings affordance, not an enable/disable button style');
foreach (['OVERALL LIVE CONCURRENCY', 'active attributable PJSIP trunk legs now'] as $overallLabel) {
	admin_contract_assert(strpos($view, $overallLabel) !== false, 'Overall Live Concurrency wording missing: ' . $overallLabel);
}
admin_contract_assert(strpos($view, 'OVERALL EXTENSION CONCURRENCY') === false, 'Live Overall metric must not be labelled as extension-only');
admin_contract_assert(strpos($liveSnapshotService, '$classification[\'type\'] === \'extension\'') !== false && strpos($liveSnapshotService, '$overallCalls[] = $channel;') !== false, 'Live snapshot must retain extension classification while aggregating trunk-only Overall');
admin_contract_assert(strpos($liveSnapshotService, "if (\$classification['type'] === 'extension') {\n\t\t\t\tcontinue;") !== false, 'Live Overall must exclude classifier-approved extension legs');
foreach (['freepbx-trunk', 'freepbx-device', 'manual-override', 'unresolved', 'freepbx-conflict'] as $source) admin_contract_assert(strpos($identityService, $source) !== false, 'Identity provenance missing: ' . $source);
admin_contract_assert(strpos($class, "SELECT id FROM devices WHERE LOWER(tech) = 'pjsip' AND id <> ''") !== false, 'Authoritative FreePBX PJSIP device query missing');
admin_contract_assert(strpos($class, 'pjsip show endpoints') === false && strpos($class, "NOT REGEXP '^[19]'") === false, 'Obsolete endpoint/destination heuristics remain in runtime code');
admin_contract_assert(strpos($view, 'PJSIP Endpoint Classifications') !== false && strpos($javascript, 'invalidateAndRerunReports') !== false, 'Classification management and report cache invalidation are required');
foreach (['Exclude Call', 'Excluded Calls', 'Restore all excluded calls', 'The original CDR will not be deleted or modified.'] as $wording) admin_contract_assert(strpos($view, $wording) !== false, 'Historical exclusion UI wording missing: ' . $wording);
admin_contract_assert(strpos($class, 'HISTORICAL_CALL_EXCLUSIONS_KEY') !== false && strpos($class, "GET_LOCK('concurrencycount_historical_call_exclusions'") !== false, 'Global exclusion persistence must be dedicated and race-locked');
admin_contract_assert(strpos($class, 'filterRows($rows, $this->getHistoricalCallExclusions())') !== false, 'Candidate CDR rows must be globally exclusion-filtered before classification');
admin_contract_assert(strpos($exclusionService, "'linkedid:'") !== false && strpos($exclusionService, "'uniqueid:'") !== false, 'Logical call identity must prefer linkedid with uniqueid fallback');
admin_contract_assert(strpos($javascript, 'invalidateAndRerunReports()') !== false && strpos($javascript, '.cc-exclude-call') !== false, 'Exclude Call must invalidate report caches and rerun the active report');
$excludedRendererStart = strpos($javascript, 'function renderExcludedCalls(calls, hasReportContext)');
$excludedRendererEnd = strpos($javascript, 'function restoreExcludedCall(identity)', $excludedRendererStart);
$excludedRenderer = substr($javascript, $excludedRendererStart, $excludedRendererEnd - $excludedRendererStart);
admin_contract_assert(strpos($excludedRenderer, 'calls.forEach(function (entry)') !== false && strpos($excludedRenderer, 'calls.filter(') === false, 'Excluded Calls must render the complete global list without filtering out calls irrelevant to the current report');
foreach (['cc-excluded-relevant', 'cc-excluded-not-in-scope', 'cc-excluded-unknown', 'cc-excluded-global'] as $rowClass) admin_contract_assert(strpos($excludedRenderer, $rowClass) !== false, 'Excluded Calls relevance row class missing: ' . $rowClass);
admin_contract_assert(strpos($excludedRenderer, "entry.matches_current_report === false ? 'cc-excluded-not-in-scope' : 'cc-excluded-unknown'") !== false, 'Unknown relevance must remain distinct from Not in scope');
admin_contract_assert(strpos($excludedRenderer, "!hasReportContext ? 'cc-excluded-global'") !== false && strpos($excludedRenderer, "hasReportContext ? '<td class=\"cc-excluded-relevance\">'") !== false, 'Global-list rendering without report context must omit relevance presentation and its column');
admin_contract_assert(strpos($excludedRenderer, 'cc-restore-excluded') !== false && strpos($excludedRenderer, "hasReportContext ? '<td class=\"cc-excluded-relevance\">'") < strpos($excludedRenderer, 'cc-restore-excluded'), 'Restore must remain rendered after the optional relevance cell for every exclusion state');
admin_contract_assert(strpos($css, '.cc-excluded-not-in-scope > td:not(:last-child)') !== false && strpos($css, '.cc-excluded-unknown .cc-excluded-relevance') !== false, 'Excluded Calls must mute only Not in scope content while presenting unknown relevance distinctly');
admin_contract_assert(strpos($class, 'UPDATE cdr') === false, 'Historical exclusions must never update CDR rows');
admin_contract_assert(strpos($view, 'Monitor live PJSIP concurrency, thresholds and trunk activity, or analyse historical trunk, extension and PBX-wide concurrency from CDR records.') !== false, 'GUI opening description must represent both Live and Historical functionality');
admin_contract_assert(strpos($console, "'Overall Live Concurrency (active attributable PJSIP trunk legs): ' . \$snapshot['overall']['current']") !== false, 'CLI must print the same trunk-only Overall field the GUI reads, not a separately computed value');
admin_contract_assert(strpos($liveJavascript, "appendPoint(history.overall, data.generated_ts, data.overall.current)") !== false && strpos($liveJavascript, 'function renderSnapshot(data)') !== false, 'Overall and trunk rolling series must be derived from the same live snapshot object');
admin_contract_assert(strpos($liveJavascript, "command: 'monitorstatus'") !== false && strpos($liveJavascript, "command: 'restartmonitor'") !== false, 'GUI monitor status/restart parity missing');
foreach (['if (request ||', 'document.hidden', 'requestSequence', 'series.length > 900', "command: 'livestatus'", "command: 'savesettings'", "command: 'historicalgraph'"] as $pollingContract) {
	admin_contract_assert(strpos($liveJavascript, $pollingContract) !== false, 'Safe live polling contract missing: ' . $pollingContract);
}
admin_contract_assert(strpos($liveJavascript, "trigger('click')") !== false && strpos($liveJavascript, '.cc-trunk-result[data-name-index=') !== false, 'Historical graph does not target the inline trunk occurrence');

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
admin_contract_assert(preg_match('/<\/section>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<section id="cc-live-wall"/', $view) === 1, 'The outer admin wrapper must close before the top-level Live Wall presentation');
admin_contract_assert(strpos($chartJavascript, 'this.threshold') !== false && strpos($chartJavascript, 'onSelect') !== false, 'Chart threshold/interaction support missing');
admin_contract_assert(strpos($css, '.cc-live-trunk-grid') !== false && strpos($css, '[data-status="exceeded"]') !== false, 'Command-centre responsive/status styling missing');
admin_contract_assert(strpos($javascript, "command: 'download'") !== false && strpos($javascript, "command: 'email'") !== false, 'Download/email command wiring missing');
admin_contract_assert(strpos($view, 'id="cc-calculation-stop"') !== false && strpos($view, "_('Stop')") !== false, 'Active Historical calculation Stop control missing');
admin_contract_assert(strpos($view, 'aria-live="polite"') !== false, 'Historical calculation status must be announced accessibly');
admin_contract_assert(strpos($javascript, "command: 'cancelcalculation', calculation_id: run.id") !== false, 'Stop must target the active opaque calculation ID');
admin_contract_assert(strpos($javascript, "params = {command: 'run', mode: mode, start_date: start, end_date: end, calculation_id: run.id}") !== false, 'Every GUI Historical run must send its calculation ID');
admin_contract_assert(strpos($javascript, 'run.stopping || !activeCalculation || activeCalculation.sequence !== run.sequence') !== false, 'Stopped or stale calculation responses must not replace newer report state');
admin_contract_assert(strpos($javascript, "intentionalAbortReason = 'stop'") !== false && strpos($javascript, "intentionalAbortReason = 'superseded'") !== false, 'Historical aborts must record an explicit Stop or supersession reason');
admin_contract_assert(substr_count($javascript, '{global: false}') >= 2 && strpos($javascript, "trigger('ajaxError'") !== false, 'Historical XHRs must suppress automatic global abort warnings while re-emitting unexpected failures');
admin_contract_assert(strpos($javascript, 'CCHistoricalRunState.shouldReportFailure(run, textStatus)') !== false, 'Historical failure reporting must use the calculation-scoped abort classifier');
admin_contract_assert(strpos($historicalRunStateJavascript, "textStatus !== 'abort'") !== false && strpos($historicalRunStateJavascript, "intentionalAbortReason === 'stop'") !== false && strpos($historicalRunStateJavascript, "intentionalAbortReason === 'superseded'") !== false, 'Only explicit Historical abort states may suppress the global warning');
admin_contract_assert(strpos($javascript, "setStatus('Stopping calculation...', 'running')") !== false && strpos($javascript, 'Calculation still running') !== false, 'Stop must expose coherent pending and failed-cancellation states');
admin_contract_assert(strpos($class, 'session_write_close()') !== false, 'Long calculations must release the PHP session lock for the authenticated Stop request');
admin_contract_assert(strpos($class, 'HistoricalCalculationControl') !== false && strpos($class, 'HistoricalCalculationCancelled') !== false, 'Backend cooperative cancellation control missing');
admin_contract_assert(strpos($view, 'id="cc-calculation-panel"') !== false && strpos($view, 'style="display:none;"') !== false, 'Telemetry panel must be hidden while idle');
foreach (['cc-telemetry-cpu', 'cc-telemetry-memory', 'cc-telemetry-swap', 'cc-telemetry-disk', 'cc-telemetry-elapsed', 'cc-telemetry-runtime', 'cc-telemetry-eta'] as $telemetryId) {
	admin_contract_assert(strpos($view, 'id="' . $telemetryId . '"') !== false, 'Historical telemetry field missing: ' . $telemetryId);
}
admin_contract_assert(strpos($javascript, "command: 'calculationtelemetry', calculation_id: run.id") !== false, 'Telemetry polling must target the active opaque calculation ID');
admin_contract_assert(strpos($javascript, 'activeCalculation.id !== run.id') !== false && strpos($javascript, 'activeCalculation.sequence !== run.sequence') !== false, 'Stale telemetry responses must not repaint a newer calculation');
admin_contract_assert(strpos($javascript, 'window.setTimeout(function () { pollCalculationTelemetry(run); }, 2000)') !== false, 'Telemetry polling interval must remain conservative and non-overlapping');
admin_contract_assert(strpos($javascript, 'stopCalculationTelemetry(run)') !== false && strpos($javascript, "$('#cc-calculation-panel').hide()") !== false, 'Every terminal calculation path must remove the temporary panel');
admin_contract_assert(substr_count($javascript, "$('#cc-calculation-panel').show()") === 1 && substr_count($view, 'id="cc-calculation-panel"') === 1, 'Telemetry panel may only be created/shown by the active calculation lifecycle');
admin_contract_assert(substr_count($view, 'id="cc-calculation-stop"') === 1 && strpos($view, '<section id="cc-calculation-panel"') < strpos($view, 'id="cc-calculation-stop"'), 'Stop must exist only inside the temporary panel');
admin_contract_assert(substr_count($javascript, 'stopCalculationTelemetry(run);') >= 4 && strpos($javascript, 'stopCalculationTelemetry(superseded);') !== false, 'Success, failure, runtime abort, cancellation and supersession must stop telemetry');
admin_contract_assert(strpos($javascript, "$('#cc-calculation-stop').prop('disabled', true)") !== false, 'Stop must disable immediately while cancellation is pending');
admin_contract_assert(strpos($javascript, 'renderCalculationTelemetry(response)') !== false && strpos($javascript, "command: 'cancelcalculation', calculation_id: run.id") !== false, 'Telemetry must remain observational and cancellation must remain an explicit Stop action');
admin_contract_assert(strpos($class, '\\FreePBX::Dashboard()') !== false && strpos($class, 'getSysInfo()') !== false, 'Resource telemetry must use the native FreePBX Dashboard source');
admin_contract_assert(strpos($class, 'shell_exec') === false, 'Resource telemetry must not add shell sampling');
admin_contract_assert(strpos($javascript, 'memory_get_usage') === false, 'Browser telemetry must not report the polling PHP process as calculation memory');
admin_contract_assert(strpos($class, 'catch (\\FreePBX\\modules\\Concurrencycount\\Services\\HistoricalResourceLimitException $resourceLimit)') !== false && strpos($class, "'resource_limit' => true") !== false, 'Predictable soft-memory stops must become a structured module response instead of escaping to FreePBX');
admin_contract_assert(strpos($javascript, 'if (resp.resource_limit)') !== false && strpos($javascript, 'restoreStoppedReport(targetReportId)') !== false, 'GUI must handle resource stops while retaining a prior completed result');
admin_contract_assert(strpos($javascript, "setStatus('Historical calculation stopped. '") !== false, 'GUI resource stop needs module-owned failure wording');
admin_contract_assert(strpos($view, 'cc-telemetry-resources-title') !== false && strpos($view, 'cc-telemetry-calculation-title') !== false, 'Resources and calculation timings must have distinct semantic groups');
$panelStart = strpos($view, '<section id="cc-calculation-panel"');
$panelEnd = strpos($view, '</section>', $panelStart);
$panel = substr($view, $panelStart, $panelEnd - $panelStart);
admin_contract_assert(strpos($panel, 'id="cc-calculation-stop"') < strpos($panel, 'id="cc-report-loading"'), 'Stop must precede the calculating status');
admin_contract_assert(strpos($view, 'System load (5 min)') !== false && strpos($view, 'this is not a percentage') !== false, 'System load needs accurate five-minute wording and concise explanation');
admin_contract_assert(strpos($javascript, "load += ' across '") !== false && strpos($javascript, "' CPUs'") !== false, 'System load must include native logical CPU context when available');
admin_contract_assert(strpos($javascript, "$('#cc-telemetry-swap-item').toggle(!!swap)") !== false, 'Swap must appear only when native data is valid');
admin_contract_assert(strpos($telemetryJavascript, "if (seconds < 1) return '< 1 second';") !== false && strpos($javascript, 'response.eta_reliable') !== false, 'Reliable sub-second ETA requires explicit state and must not render as zero');
admin_contract_assert(strpos($javascript, "$('#cc-excluded-calls').prop('disabled', true)") !== false, 'Excluded Calls must become genuinely disabled at calculation start');
admin_contract_assert(strpos($javascript, 'function restoreExcludedCallsAfterCalculation()') !== false && substr_count($javascript, 'restoreExcludedCallsAfterCalculation();') >= 4, 'Every terminal calculation path must restore Excluded Calls');
$supersedeStart = strpos($javascript, 'if (activeCalculation && !activeCalculation.stopping)');
$newRunStart = strpos($javascript, 'var targetReportId', $supersedeStart);
$supersedeBody = substr($javascript, $supersedeStart, $newRunStart - $supersedeStart);
admin_contract_assert(strpos($supersedeBody, 'restoreExcludedCallsAfterCalculation') === false, 'A superseded run must not re-enable Excluded Calls before its replacement starts');
admin_contract_assert(strpos($supersedeBody, 'closeReportTab') === false, 'A superseded run must not close its report tab');
admin_contract_assert(strpos($historicalRunStateJavascript, 'function cancellationAcknowledged(') !== false, 'Stop-and-close requires an exact backend acknowledgement classifier');
$stopHandlerStart = strpos($javascript, 'function stopActiveCalculation()');
$stopHandlerEnd = strpos($javascript, '/**', $stopHandlerStart);
$stopHandler = substr($javascript, $stopHandlerStart, $stopHandlerEnd - $stopHandlerStart);
admin_contract_assert(strpos($stopHandler, "ajax({command: 'cancelcalculation', calculation_id: run.id}).done") !== false, 'Stop must wait for successful cancellation response rather than closing from always');
admin_contract_assert(strpos($stopHandler, 'cancellationAcknowledged(response, run)') < strpos($stopHandler, 'closeReportTab(run.targetReportId)'), 'Normal report close must occur only after exact cancellation acknowledgement');
admin_contract_assert(strpos($stopHandler, 'ownsActiveCalculation') !== false, 'An old acknowledged Stop must not clean up a newer active calculation UI');
admin_contract_assert(strpos($stopHandler, "setStatus('Unable to confirm cancellation. The report remains open.', 'error')") !== false, 'Cancellation transport failure must retain the report with an error');
admin_contract_assert(strpos($stopHandler, 'run.stopping = false') !== false && strpos($stopHandler, "prop('disabled', false)") !== false, 'Failed cancellation must permit a safe retry');
admin_contract_assert(strpos($javascript, "if (nextTarget === 'historical') $('#cc-launch').trigger('focus')") !== false, 'Closing the last report must return focus to Start Historical Report');
admin_contract_assert(strpos($css, '#page_body') !== false && strpos($css, 'cc-table-scroll') !== false, 'Responsive containment/table scrolling missing');
admin_contract_assert((string)$module->version === '2.1.1', 'Admin contract version mismatch');

/* Persisted historical report tabs */
admin_contract_assert(strpos($class, 'HISTORICAL_REPORTS_KEY') !== false, 'Historical report tabs must use the module settings key persistence layer, not a new table');
admin_contract_assert(strpos($install, 'install()') !== false && strpos($class, 'CREATE TABLE') === false, 'No new database table should be introduced for historical report tabs');
foreach (['getHistoricalReports', 'createHistoricalReport', 'updateHistoricalReport', 'closeHistoricalReport', 'setActiveHistoricalReport'] as $method) {
	admin_contract_assert(strpos($class, 'function ' . $method) !== false, 'Historical report tab GUI/CLI-shared method missing: ' . $method);
}
admin_contract_assert(strpos($class, "GET_LOCK('concurrencycount_historical_reports'") !== false, 'Historical report tab mutations must be serialised so a double-click cannot exceed the five-report limit');
admin_contract_assert(strpos($historicalReportsService, 'MAX_REPORTS = 5') !== false, 'Five-report hard limit must be enforced in the backend service, not only the GUI');
admin_contract_assert(strpos($console, "'list-historical-reports'") !== false && strpos($console, "'show-historical-report'") !== false && strpos($console, "'delete-historical-report'") !== false, 'CLI visibility/management for persisted historical report tabs is missing');

/* Historic Report tabs are top-level peers of Live View / Historical Reports, not a nested second-level strip */
admin_contract_assert(substr_count($view, 'id="cc-workspace-tabs"') === 1, 'A single top-level tab strip container must exist');
foreach (['cc-report-tabs', 'cc-report-workspace', 'cc-report-new', 'cc-report-tab-bar'] as $legacyNestedNeedle) {
	admin_contract_assert(strpos($view, $legacyNestedNeedle) === false, 'No nested second-level historical report-tab container may remain: ' . $legacyNestedNeedle);
	admin_contract_assert(strpos($javascript, $legacyNestedNeedle) === false, 'No nested second-level historical report-tab container may remain in JS: ' . $legacyNestedNeedle);
}
admin_contract_assert(strpos($javascript, "\$('#cc-tab-historical').after(html)") !== false, 'Historic Report tabs must be inserted into the same top-level tab container as Live/Historical, immediately after the Historical Reports tab');
admin_contract_assert(strpos($javascript, 'class="cc-workspace-tab cc-report-tab-top"') !== false, 'Historic Report tabs must share the cc-workspace-tab top-level tab styling, not a separate nested tab style');
foreach (['id="cc-report-landing"', 'id="cc-report-active"'] as $needle) {
	admin_contract_assert(substr_count($view, $needle) === 1, 'Historical landing/active container must appear exactly once: ' . $needle);
}
admin_contract_assert(substr_count($view, 'id="cc-results"') === 1 && substr_count($view, 'id="cc-results-body"') === 1, 'Results DOM must remain a single shared surface, not duplicated per report tab (avoids duplicate IDs)');
admin_contract_assert(strpos($view, 'aria-label="Close ') === false, 'Close button accessible label is generated client-side per report, not hardcoded in markup');
admin_contract_assert(strpos($javascript, "escapeHtml('Close ' + report.name)") !== false, 'Each report tab close control must expose an accessible saved-name label');
admin_contract_assert(strpos($view, 'id="cc-report-name"') !== false && strpos($view, 'maxlength="80"') !== false, 'Historical report modal must provide the bounded report-name field');
admin_contract_assert(strpos($javascript, "$('#cc-launch').off('click').on('click', openNewReportWizard)") !== false, 'Start Historical Report must open only the unpersisted wizard');
admin_contract_assert(strpos($javascript, "function openNewReportWizard()") !== false && strpos($javascript, "command: 'createhistoricalreport'") > strpos($javascript, 'function createAndRunReport'), 'Report creation must occur only in the validated Run Report path');
admin_contract_assert(strpos($javascript, 'discardFailedFirstRun') !== false && strpos($javascript, 'firstRunPending') !== false, 'Failed first runs must clean up their newly allocated report slot');
$cleanupStart = strpos($javascript, 'function discardFailedFirstRun');
$cleanupEnd = strpos($javascript, 'function handleStepSuccess', $cleanupStart);
$cleanup = substr($javascript, $cleanupStart, $cleanupEnd - $cleanupStart);
admin_contract_assert(strpos($cleanup, "ajax({command: 'closehistoricalreport', id: targetReportId}).done") !== false, 'First-run cleanup must wait for the backend response');
admin_contract_assert(strpos($cleanup, 'if (!response.status)') !== false && strpos($cleanup, 'failedFirstRunCleanup(report, message') !== false, 'Backend cleanup failure must retain and warn about the saved report');
admin_contract_assert(strpos($cleanup, 'delete historicalReports[targetReportId]') > strpos($cleanup, 'if (!response.status)'), 'First-run report must be removed locally only after confirmed backend success');
admin_contract_assert(strpos($cleanup, 'could not be cleaned up') !== false && strpos($cleanup, 'The unused report was removed.') !== false, 'Cleanup success and failure messages must not make the same claim');
admin_contract_assert(strpos($cleanup, 'if (report.firstRunCleanupAttempted) return true;') !== false, 'Converging first-run failures must not duplicate cleanup requests');
admin_contract_assert(strpos($javascript, 'generated_default_name') !== false, 'Generated default names must defer final slot naming to the server');
admin_contract_assert(strpos($javascript, 'title="' . "' + escapeHtml(report.name)") !== false, 'Long report tabs must expose their complete saved name');
admin_contract_assert(strpos($css, '.cc-workspace-tab.cc-report-tab-top:hover') !== false && strpos($css, '[aria-selected="true"]:hover') !== false, 'Report tabs need hover styling distinct from selected styling');
admin_contract_assert(strpos($css, '.cc-report-tab-select:focus-visible') !== false && strpos($css, 'inset 0 -2px 0 #7d919f') !== false, 'Report tabs need a subtle keyboard-only focus treatment');
admin_contract_assert(strpos($css, '.cc-report-tab-select:hover') !== false && strpos($css, '.cc-report-tab-select:focus:not(:focus-visible)') !== false, 'Report-tab hover and mouse focus must suppress the bright focus box');
admin_contract_assert(strpos($css, 'text-overflow: ellipsis') !== false && strpos($css, 'max-width: 240px') !== false, 'Long report names must be constrained and ellipsized');
admin_contract_assert(strpos($console, "\$report['name']") !== false, 'CLI list/show/delete surfaces must expose the persisted report name');
admin_contract_assert(strpos($javascript, 'role="tab"') !== false, 'Report tabs must use tab semantics');
admin_contract_assert(strpos($javascript, "closest('.cc-report-tab-close')") !== false, 'Clicking the close (x) control must not also trigger tab selection');
admin_contract_assert(strpos($javascript, 'function selectTopTab') !== false, 'A single top-level tab selection function must own Live/Historical/report-tab switching');
admin_contract_assert(strpos($javascript, 'CCLiveWorkspace.switchSection') !== false, 'Top-level tab selection must delegate Live/Historical section visibility, not duplicate it');
admin_contract_assert(strpos($javascript, 'runTargetReportId') !== false && strpos($javascript, 'targetReportId') !== false, 'Report results must be attached to the report instance captured at request time, not read live at response time');
admin_contract_assert(strpos($javascript, 'occurrenceCache') !== false, 'Occurrence drill-down state must be scoped per report instance');
admin_contract_assert(strpos($javascript, 'runTargetReportId = null;') !== false, 'Demo must not silently attach its result to a persisted report tab');
$landingStart = strpos($view, 'id="cc-report-landing"');
$activeStart = strpos($view, 'id="cc-report-active"');
admin_contract_assert($landingStart !== false && $activeStart > $landingStart && strpos(substr($view, $landingStart, $activeStart - $landingStart), 'id="cc-demo-launch"') !== false, 'Run Demo must remain available from the Historical landing');
admin_contract_assert(strpos($javascript, ".cc-demo-run-mode').off('click').on('click'") !== false && strpos($javascript, "runDemo(\$(this).data('report'))") !== false, 'Demo mode buttons must invoke the transient runDemo path');
$runDemoStart = strpos($javascript, 'function runDemo(report)');
$runDemoEnd = strpos($javascript, 'function selectedDemoEngines', $runDemoStart);
$runDemoBody = substr($javascript, $runDemoStart, $runDemoEnd - $runDemoStart);
admin_contract_assert(strpos($runDemoBody, 'runTargetReportId = null;') !== false, 'Demo must retain a null persisted-report target');
admin_contract_assert(strpos($runDemoBody, 'showTransientDemoResult();') !== false && strpos($runDemoBody, 'showTransientDemoResult();') < strpos($runDemoBody, "executeRun('demo'"), 'Demo must reveal its Historical result/status surface before starting the request');
admin_contract_assert(strpos($runDemoBody, 'createhistoricalreport') === false && strpos($runDemoBody, 'historicalReports[') === false, 'Demo must not create a Historic Report, consume a slot or mutate a report cache');
$demoSurfaceStart = strpos($javascript, 'function showTransientDemoResult()');
$demoSurfaceEnd = strpos($javascript, '/**', $demoSurfaceStart);
$demoSurfaceBody = substr($javascript, $demoSurfaceStart, $demoSurfaceEnd - $demoSurfaceStart);
admin_contract_assert(strpos($demoSurfaceBody, "$('#cc-report-landing').hide()") !== false && strpos($demoSurfaceBody, "$('#cc-report-active').show()") !== false, 'Transient Demo must move from the landing to the visible shared result surface');
admin_contract_assert(strpos($demoSurfaceBody, ".cc-report-global-actions').hide()") !== false, 'Transient Demo must hide persisted-report controls such as Excluded Calls');
$landingFunctionStart = strpos($javascript, 'function showHistoricalLanding()');
$landingFunctionEnd = strpos($javascript, 'function showTransientDemoResult()', $landingFunctionStart);
$landingFunctionBody = substr($javascript, $landingFunctionStart, $landingFunctionEnd - $landingFunctionStart);
admin_contract_assert(strpos($landingFunctionBody, "$('#cc-report-landing').show()") !== false && strpos($landingFunctionBody, "$('#cc-report-active').hide()") !== false, 'Returning to Historical Reports must restore the normal landing state');
admin_contract_assert(strpos($javascript, "if (target === 'historical') {") !== false && strpos($javascript, 'showHistoricalLanding();') !== false, 'Historical tab navigation must provide the route back from transient Demo');
admin_contract_assert(substr_count($view, 'id="cc-results"') === 1 && strpos($runDemoBody, 'renderResults') === false, 'Demo must use the one shared result renderer rather than duplicate result markup');

/* Live View must retain its established polling and workspace contracts. */
foreach (['function updateOverall', 'function pollLive', 'function startPolling'] as $liveFn) {
	admin_contract_assert(strpos($liveJavascript, $liveFn) !== false, 'Live View function must remain present: ' . $liveFn);
}
admin_contract_assert(strpos($liveJavascript, 'window.CCLiveWorkspace') !== false, 'Live View must expose its section-switch hook for the shared top-level tab strip');
admin_contract_assert(strpos($liveJavascript, "\$('.cc-workspace-tab').off('click.ccLive')") === false, 'Live View must not bind top-level tab clicks itself now that report tabs share the strip');
admin_contract_assert(strpos($liveJavascript, 'historicalReports') === false, 'Live View file must not know about the historical report tab model');

/* 2.1.0 Live View presentation and operational monitoring */
admin_contract_assert(strpos($view, '>Live View<') === false || strpos($view, "_('Live View')") !== false, 'User-facing Live View label missing');
admin_contract_assert(strpos($view, "_('Live Command " . "Centre')") === false, 'Obsolete pre-2.1 live-dashboard label remains in the GUI');
foreach (['id="cc-live-wall"', 'id="cc-live-wall-exit"', 'id="cc-live-wall-configure"', 'id="cc-live-wall-config-modal"', 'id="cc-wall-featured-list"', 'id="cc-hidden-trunks"', 'id="cc-hidden-trunk-list"'] as $id) {
	admin_contract_assert(strpos($view, $id) !== false, 'Live View/Wall surface missing: ' . $id);
}
foreach (['hidden_trunks', 'trunk_order', 'live_wall_featured_trunks', 'monitored'] as $setting) {
	admin_contract_assert(strpos($thresholdService, "'" . $setting . "'") !== false, '2.1.0 Live setting missing: ' . $setting);
}
admin_contract_assert(strpos($class, "empty(\$settings['trunks'][\$trunk]['monitored'])") !== false, 'Background monitor must gate per-trunk evaluation on monitored state');
admin_contract_assert(strpos($class, "unset(\$states['trunk:' . \$trunk])") !== false, 'Stopping monitoring must clear stale per-trunk episode state');
foreach (['cc-hide-trunk', 'cc-unhide-trunk', 'cc-toggle-monitoring', 'cc-drag-handle'] as $control) {
	admin_contract_assert(strpos($liveJavascript, $control) !== false, 'Live View control missing: ' . $control);
}
admin_contract_assert(strpos($liveJavascript, 'cc-move-earlier') === false && strpos($liveJavascript, 'cc-move-later') === false, 'Normal Live View must not retain redundant reorder arrows');
admin_contract_assert(strpos($liveJavascript, "event.key !== 'ArrowLeft'") !== false && strpos($liveJavascript, "event.key !== 'ArrowRight'") !== false, 'The drag handle must retain keyboard-operable reordering');
admin_contract_assert(strpos($liveJavascript, ".attr('data-trunk')") !== false && strpos($liveJavascript, ".data('trunk')") === false, 'Trunk controls must preserve numeric-looking channelids as strings');
admin_contract_assert(strpos($liveJavascript, 'function orderedTrunks') !== false && strpos($liveJavascript, 'function renderHiddenTrunks') !== false, 'Shared visibility/order renderer missing');
admin_contract_assert(substr_count($liveJavascript, "command: 'livestatus'") === 1, 'Live Wall must not introduce a second live-status acquisition path');
admin_contract_assert(strpos($liveJavascript, 'renderLiveWall(snapshot)') !== false || strpos($liveJavascript, 'renderLiveWall(data)') !== false, 'Live Wall must render from the shared latest snapshot');
admin_contract_assert(strpos($view, 'id="cc-live-wall" class="cc-live-wall cc-theme-dark"') !== false, 'Live Wall must declare an explicit dark presentation theme');
$normalLiveMarkup = substr($view, strpos($view, '<section id="cc-live-section"'), strpos($view, '<section id="cc-live-wall"') - strpos($view, '<section id="cc-live-section"'));
admin_contract_assert(strpos($normalLiveMarkup, 'cc-theme-dark') === false, 'Normal Live View must remain independent of the Live Wall dark theme');
admin_contract_assert(strpos($chartJavascript, "this.options.theme === 'dark'") !== false && strpos($chartJavascript, 'ConcurrencyChart.prototype.palette') !== false && strpos($chartJavascript, 'palette.background') !== false, 'Shared charts must support an explicit dark presentation without changing the light default');
admin_contract_assert(substr_count($liveJavascript, "{theme: 'dark'}") === 2, 'Only the Overall and featured-trunk Live Wall chart constructors must request dark presentation');
admin_contract_assert(strpos($liveJavascript, 'charts.wallOverall.setData(history.overall') !== false && strpos($liveJavascript, 'charts.overall.setData(history.overall') !== false && strpos($liveJavascript, 'charts.wallTrunks[trunk].setData(history.trunks[trunk]') !== false && strpos($liveJavascript, 'charts.trunks[trunk].setData(history.trunks[trunk]') !== false, 'Live View and Live Wall must share the same rolling history arrays');
$wallTransitionStart = strpos($liveJavascript, 'function enterLiveWall()');
$wallTransitionEnd = strpos($liveJavascript, 'function renderLiveWall(data)', $wallTransitionStart);
$wallTransitionCode = substr($liveJavascript, $wallTransitionStart, $wallTransitionEnd - $wallTransitionStart);
admin_contract_assert(strpos($wallTransitionCode, 'scheduleChartResize(resizeWallCharts)') !== false && strpos($wallTransitionCode, 'scheduleChartResize(resizeLiveCharts)') !== false && strpos($wallTransitionCode, 'history.overall =') === false && strpos($wallTransitionCode, 'history.trunks =') === false, 'Live Wall transitions must resize both presentations without resetting rolling history');
admin_contract_assert(strpos($css, '.cc-live-wall.cc-theme-dark canvas') !== false && strpos($css, '.cc-live-wall.cc-theme-dark #cc-live-wall-exit:focus-visible') !== false, 'Live Wall dark surfaces and keyboard focus must remain wall-scoped');
admin_contract_assert(strpos($liveJavascript, 'settings.live_wall_featured_trunks') !== false && strpos($liveJavascript, 'configuredFeatured.filter') !== false, 'Live Wall must render only its configured featured-trunk list');
admin_contract_assert(strpos($liveJavascript, 'var names = orderedTrunks(data.trunks).filter') === false, 'Live Wall must not mirror every non-hidden Live View trunk');
admin_contract_assert(strpos($liveJavascript, 'Object.prototype.hasOwnProperty.call(data.trunks, trunk) && !isHidden(trunk)') !== false, 'Hidden or unavailable featured trunks must be suppressed without substitution');
admin_contract_assert(strpos($liveJavascript, "card.find('.cc-wall-monitoring').text(isMonitored(trunk) ? 'Monitoring active' : 'Monitoring stopped')") !== false, 'Monitoring-stopped featured trunks must remain visible with textual state');
admin_contract_assert(strpos($liveJavascript, 'function setHidden') !== false && strpos($liveJavascript, 'settings.live_wall_featured_trunks =') === false, 'Hide/Unhide must not rewrite featured-trunk preferences');
$setHiddenStart = strpos($liveJavascript, 'function setHidden');
$setHiddenEnd = strpos($liveJavascript, 'function toggleMonitoring', $setHiddenStart);
$setHiddenBody = substr($liveJavascript, $setHiddenStart, $setHiddenEnd - $setHiddenStart);
admin_contract_assert(strpos($setHiddenBody, 'saveSettings(settings, false)') !== false && strpos($setHiddenBody, 'persistPreferences()') === false, 'Hide/Unhide must save immediately instead of waiting for the reorder debounce');
admin_contract_assert(strpos($liveJavascript, 'settingsSaveQueue') !== false && strpos($liveJavascript, 'settingsSaveInFlight') !== false && strpos($liveJavascript, 'drainSettingsSaveQueue') !== false, 'Whole-settings saves must be serialized');
admin_contract_assert(strpos($liveJavascript, 'pending.sequence === latestSettingsSaveSequence') !== false, 'A stale save response must not replace newer in-memory settings');
admin_contract_assert(strpos($liveJavascript, 'saveSequenceWhenRequested !== latestSettingsSaveSequence') !== false, 'A stale settings reload must not replace newer in-memory preferences');
$saveSettingsStart = strpos($liveJavascript, 'function saveSettings(candidate');
$saveSettingsEnd = strpos($liveJavascript, 'function loadHistoricalGraph', $saveSettingsStart);
$saveSettingsBody = substr($liveJavascript, $saveSettingsStart, $saveSettingsEnd - $saveSettingsStart);
admin_contract_assert(strpos($saveSettingsBody, 'if (pending.pollAfterSave) startPolling(true)') !== false && strpos($saveSettingsBody, "\n\t\t\tstartPolling(true);") === false, 'Settings saves must poll only when the caller explicitly requires a refreshed snapshot');
admin_contract_assert(strpos($liveJavascript, 'featuredDraft.length >= 3') !== false && strpos($liveJavascript, 'Deselect one to choose another.') !== false, 'Configure Live Wall must enforce and explain the three-trunk limit');
admin_contract_assert(strpos($liveJavascript, 'cc-featured-earlier') !== false && strpos($liveJavascript, 'cc-featured-later') !== false, 'Featured trunks require accessible left-to-right ordering controls');
admin_contract_assert(strpos($liveJavascript, 'live_wall_featured_trunks: (settings.live_wall_featured_trunks || []).slice()') !== false, 'Threshold settings saves must preserve featured-trunk preferences');
admin_contract_assert(strpos($liveJavascript, "typeof wall.requestFullscreen === 'function'") !== false && strpos($liveJavascript, 'fullscreenchange.ccLive') !== false, 'Fullscreen API feature detection/state handling missing');
admin_contract_assert(strpos($view, 'cc-live-wall') < strpos($view, 'cc-live-settings-modal'), 'Live Wall must be a top-level presentation, not nested inside settings');
$wallMarkup = substr($view, strpos($view, '<section id="cc-live-wall"'), strpos($view, '<div class="modal fade concurrencycount" id="cc-live-settings-modal"') - strpos($view, '<section id="cc-live-wall"'));
foreach (['Hide Trunk', 'Unhide', 'Start Monitoring', 'Stop Monitoring', 'Thresholds & alerts', 'Move earlier', 'Move later'] as $mutation) {
	admin_contract_assert(strpos($wallMarkup, $mutation) === false, 'Live Wall must remain read-only; found: ' . $mutation);
}
admin_contract_assert(strpos($wallMarkup, 'Configure Live Wall') === false, 'Configure Live Wall must remain outside the read-only Wall');
$configModal = substr($view, strpos($view, '<div class="modal fade concurrencycount" id="cc-live-wall-config-modal"'), strpos($view, '<!-- Demo prompt modal -->') - strpos($view, '<div class="modal fade concurrencycount" id="cc-live-wall-config-modal"'));
admin_contract_assert(strpos($configModal, 'aria-labelledby="cc-live-wall-config-title"') !== false && strpos($configModal, 'Choose up to 3 trunks') !== false, 'Featured-trunk modal must be labelled and explain its limit');
admin_contract_assert(strpos($css, '.cc-wall-trunk-grid[data-count="3"]') !== false && strpos($css, 'repeat(3,minmax(0,1fr))') !== false, 'Live Wall must compose three equal featured cards for desktop');
admin_contract_assert(strpos($readme, 'Changing a trunk channelid') !== false, 'README must document channelid preference identity limitation');
admin_contract_assert(strpos($readme, 'only monitored PJSIP trunk and extension legs') === false && strpos($readme, 'includes monitored trunk legs') === false, 'README must not use operational monitored wording for Overall Live Concurrency attribution');

echo "Administrative contract passed\n";
