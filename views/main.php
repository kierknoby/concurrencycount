<?php
/**
 * Concurrency Count main view.
 *
 * Patterns borrowed from Frogman (mwtcmi/frogman):
 *  - filemtime() cache-busting on CSS/JS so module upgrades don't serve stale
 *    assets through the browser cache
 *  - Defensive type-cast on PHP-injected data when consumed by JS
 *  - Module version surfaced inline so we can see at a glance which build is
 *    running without diffing files
 *
 * Structure: page.concurrencycount.php wraps in container-fluid + display
 * no-border (Frogman's pattern), this view renders the inner content only.
 * Bootstrap 3 modals for the wizard and overrun prompt. No custom palette,
 * inherits FreePBX's bootstrap.
 *
 * @var string $moduleVersion
 * @var array $availableEngines
 * @var string $csrfToken
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}
$availableEngines = isset($availableEngines) && is_array($availableEngines) ? $availableEngines : [];
$csrfToken = isset($csrfToken) ? (string)$csrfToken : '';

// Cache-bust based on the newest asset file. If either file changes,
// browsers see a new URL and refetch. Falls back to time() if filemtime
// fails (shouldn't, but better than a blank query string).
$_ccAssetVer = max(
	@filemtime(__DIR__ . '/../assets/js/concurrencycount.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/js/date-range.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/js/concurrency-charts.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/js/live-command-centre.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/css/concurrencycount.css') ?: 0
) ?: time();
?>
<link rel="stylesheet" href="modules/concurrencycount/assets/css/concurrencycount.css?v=<?php echo $_ccAssetVer; ?>">

<div class="concurrencycount" data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
	<input type="hidden" name="token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
	<div class="row">
		<div class="col-sm-12">
			<h1>
				<?php echo _('Concurrency Count'); ?>
				<small class="text-muted" style="font-size:0.5em;">v<?php echo htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8'); ?> &mdash; <?php echo _('- NOT CURRENTLY SUITABLE FOR PRODUCTION'); ?></small>
			</h1>

			<div class="row">
				<div class="col-sm-12">
					<p>
						<?php echo _('Report peak simultaneous PJSIP trunk legs, individual extension records, or all numeric extension legs across the PBX.'); ?>
					</p>
				</div>
			</div>

			<div class="row">
				<div class="col-sm-12">
					<div class="cc-workspace-tabs" role="tablist" aria-label="<?php echo _('Concurrency views'); ?>">
						<button type="button" class="btn btn-primary cc-workspace-tab active" id="cc-tab-live" data-target="live" role="tab" aria-selected="true"><i class="fa fa-circle"></i> <?php echo _('Live Command Centre'); ?></button>
						<button type="button" class="btn btn-default cc-workspace-tab" id="cc-tab-historical" data-target="historical" role="tab" aria-selected="false"><i class="fa fa-history"></i> <?php echo _('Historical Reports'); ?></button>
					</div>

					<section id="cc-live-section" class="cc-workspace" role="tabpanel" aria-labelledby="cc-tab-live">
						<div class="cc-section-heading">
							<div><span class="cc-section-kicker"><?php echo _('LIVE'); ?></span><h2><?php echo _('Current Asterisk state'); ?></h2></div>
							<div class="cc-live-actions">
								<label for="cc-live-refresh"><?php echo _('Refresh'); ?></label>
								<select id="cc-live-refresh" class="form-control input-sm" aria-label="<?php echo _('Live browser refresh interval'); ?>">
									<?php foreach ([1, 5, 10, 15, 30, 60] as $seconds): ?><option value="<?php echo $seconds; ?>"<?php echo $seconds === 5 ? ' selected' : ''; ?>><?php echo $seconds; ?>s<?php echo $seconds === 1 ? ' ' . _('(aggressive)') : ''; ?></option><?php endforeach; ?>
								</select>
								<button type="button" id="cc-live-settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Thresholds & alerts'); ?></button>
							</div>
						</div>
						<p class="text-muted"><?php echo _('Live values come directly from current AMI channel state. Browser refresh does not control background alert monitoring.'); ?></p>
						<div id="cc-live-message" class="alert alert-info"><?php echo _('Connecting to Asterisk live state...'); ?></div>
						<div id="cc-live-content" style="display:none;">
							<div class="cc-live-overall cc-status-panel" data-status="normal">
								<div class="cc-live-metric-copy">
									<span class="cc-section-kicker"><?php echo _('OVERALL EXTENSION CONCURRENCY'); ?></span>
									<button type="button" id="cc-live-overall-value" class="cc-live-value" aria-controls="cc-live-call-detail">0</button>
									<span><?php echo _('numeric PJSIP extension channels active now'); ?></span>
									<div class="cc-live-meta"><span id="cc-live-overall-threshold"><?php echo _('Threshold off'); ?></span><span id="cc-live-overall-peak"><?php echo _('Recent peak 0'); ?></span><span id="cc-live-overall-status"><?php echo _('Normal'); ?></span></div>
								</div>
								<canvas id="cc-live-overall-chart" class="cc-live-chart" height="150"></canvas>
							</div>
							<div class="cc-live-updated"><strong><?php echo _('Last successful update:'); ?></strong> <time id="cc-live-updated-time">--</time></div>
							<h3><?php echo _('Live trunk activity'); ?></h3>
							<div id="cc-live-trunks" class="cc-live-trunk-grid"></div>
							<section id="cc-live-call-detail" class="cc-live-call-detail" style="display:none;" aria-live="polite"></section>
						</div>
					</section>

					<section id="cc-historical-section" class="cc-workspace" role="tabpanel" aria-labelledby="cc-tab-historical" style="display:none;">
						<div class="cc-section-heading"><div><span class="cc-section-kicker"><?php echo _('HISTORICAL'); ?></span><h2><?php echo _('Reconstructed from CDR records'); ?></h2></div></div>
						<div class="row"><div class="col-sm-12">
							<button type="button" id="cc-launch" class="btn btn-primary"><i class="fa fa-play"></i> <?php echo _('Start Historical Report'); ?></button>
							<button type="button" id="cc-demo-launch" class="btn btn-default" style="margin-left:8px;"><i class="fa fa-flask"></i> <?php echo _('Run Demo'); ?></button>
						</div></div>
						<div id="cc-status" class="alert" style="display:none; margin-top:20px;"></div>
						<div id="cc-results" style="display:none; margin-top:20px;">
							<h3 id="cc-results-title"></h3>
							<div class="row"><div class="col-sm-12"><dl class="dl-horizontal" id="cc-results-meta"></dl></div></div>
							<div class="row"><div class="col-sm-12"><div id="cc-historical-graph" class="cc-historical-graph" style="display:none;"><div class="cc-section-heading"><h3><?php echo _('Historical concurrency'); ?></h3><span id="cc-historical-resolution" class="text-muted"></span></div><canvas id="cc-historical-chart" height="220"></canvas><div id="cc-historical-series" class="cc-historical-series"></div></div></div></div>
							<div class="row"><div class="col-sm-12"><div id="cc-results-body"></div></div></div>
							<div class="row"><div class="col-sm-12"><div id="cc-results-warning" class="alert alert-warning"></div></div></div>
							<div class="row"><div class="col-sm-12">
								<button type="button" id="cc-download" class="btn btn-default"><i class="fa fa-download"></i> <?php echo _('Download CSV'); ?></button>
								<button type="button" id="cc-download-cdr" class="btn btn-default" style="display:none;"><i class="fa fa-table"></i> <?php echo _('Preview fixture CSV'); ?></button>
								<button type="button" id="cc-email-toggle" class="btn btn-default"><i class="fa fa-envelope"></i> <?php echo _('Email report'); ?></button>
							</div></div>
							<div id="cc-email-row" class="row" style="display:none; margin-top:12px;"><div class="col-sm-6"><div class="input-group"><input type="email" id="cc-email" class="form-control" placeholder="<?php echo _('recipient@example.com'); ?>"><span class="input-group-btn"><button type="button" id="cc-email-send" class="btn btn-primary"><?php echo _('Send'); ?></button></span></div></div></div>
						</div>
					</section>

		</div>
	</div>
</div>

<div class="modal fade concurrencycount" id="cc-live-settings-modal" tabindex="-1" role="dialog" aria-labelledby="cc-live-settings-title">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 id="cc-live-settings-title" class="modal-title"><?php echo _('Live thresholds and alerts'); ?></h4>
			</div>
			<div class="modal-body">
				<div class="row">
					<div class="col-sm-4 form-group"><label for="cc-setting-refresh"><?php echo _('Browser refresh interval'); ?></label><select id="cc-setting-refresh" class="form-control"><?php foreach ([1, 5, 10, 15, 30, 60] as $seconds): ?><option value="<?php echo $seconds; ?>"><?php echo $seconds; ?> <?php echo _('seconds'); ?><?php echo $seconds === 1 ? ' ' . _('(aggressive)') : ''; ?></option><?php endforeach; ?></select></div>
					<div class="col-sm-4 form-group"><label for="cc-setting-email"><?php echo _('Alert email'); ?></label><input type="email" id="cc-setting-email" class="form-control"></div>
					<div class="col-sm-4"><div class="checkbox"><label><input type="checkbox" id="cc-setting-alerts"> <?php echo _('Enable threshold alerts'); ?></label></div><div class="checkbox"><label><input type="checkbox" id="cc-setting-recovery"> <?php echo _('Send recovery notifications'); ?></label></div></div>
				</div>
				<div class="cc-monitor-health">
					<strong><?php echo _('Unattended alert monitor'); ?>:</strong>
					<span id="cc-monitor-status"><?php echo _('Checking...'); ?></span>
					<button type="button" id="cc-monitor-restart" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> <?php echo _('Restart monitor'); ?></button>
				</div>
				<p class="help-block"><?php echo _('Thresholds remain visible when alerts are disabled. A value of 0 disables that threshold. The supervised monitor reacts to AMI channel events and reconciles every 5 seconds, independently of browser refresh.'); ?></p>
				<div class="cc-table-scroll"><table class="table table-striped"><thead><tr><th><?php echo _('Scope'); ?></th><th><?php echo _('Threshold enabled'); ?></th><th><?php echo _('Threshold'); ?></th><th><?php echo _('Alert enabled'); ?></th></tr></thead><tbody id="cc-threshold-rows"></tbody></table></div>
				<div id="cc-settings-error" class="alert alert-danger" style="display:none;"></div>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button><button type="button" id="cc-settings-save" class="btn btn-primary"><i class="fa fa-save"></i> <?php echo _('Save settings'); ?></button></div>
		</div>
	</div>
</div>

<!-- Demo prompt modal -->
<div class="modal fade" id="cc-demo" tabindex="-1" role="dialog" aria-labelledby="cc-demo-title">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="cc-demo-title"><?php echo _('Concurrency Count Demo'); ?></h4>
			</div>
			<div class="modal-body">
				<p><?php echo _('The demo temporarily writes tagged synthetic PJSIP CDR rows, runs the normal report queries against those rows, then verifies that the rows were removed.'); ?></p>
				<div class="alert alert-warning">
					<strong><?php echo _('Demo writes to CDR.'); ?></strong>
					<?php echo _('The rows are synthetic, tagged with a CCDEMO accountcode, and normally use historical dates around 2001 so they are isolated from live reporting periods. Cleanup is verified after the run, but it is still best-effort if the server or database dies mid-run.'); ?>
				</div>
				<div class="form-group">
					<label class="control-label"><?php echo _('Randomise'); ?></label>
					<input type="text" id="cc-demo-seed" class="form-control" readonly style="margin-bottom:8px;">
					<div id="cc-demo-entropy" class="cc-demo-entropy">
						<span><?php echo _('Move inside this box to stir the seed. A new seed is created every time this window opens.'); ?></span>
					</div>
					<span class="help-block" id="cc-demo-entropy-status"><?php echo _('New random seed ready.'); ?></span>
				</div>
				<dl class="dl-horizontal" id="cc-demo-plan"></dl>
				<p class="text-muted"><?php echo _('The randomiser selects the date range and load size automatically. Demo rows are isolated with a temporary run id, so real CDRs in the same period are ignored.'); ?></p>
				<div class="form-group">
					<label class="control-label"><?php echo _('Compare engines'); ?></label>
					<?php foreach ($availableEngines as $id => $engine): ?>
						<div class="checkbox">
							<label>
								<input type="checkbox" class="cc-demo-engine" value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $id === 'original' ? 'checked' : ''; ?>>
								<?php echo htmlspecialchars($engine['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($engine['experimental']) ? ' ' . _('(experimental)') : ' ' . _('(recommended)'); ?>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal" id="cc-demo-cancel"><?php echo _('Cancel'); ?></button>
				<button type="button" class="btn btn-default cc-demo-run-mode" data-report="trunk">
					<i class="fa fa-play"></i> <?php echo _('Run Trunks'); ?>
				</button>
				<button type="button" class="btn btn-default cc-demo-run-mode" data-report="extension">
					<i class="fa fa-play"></i> <?php echo _('Run Extensions'); ?>
				</button>
				<button type="button" class="btn btn-primary cc-demo-run-mode" data-report="group">
					<i class="fa fa-play"></i> <?php echo _('Run Overall Extensions'); ?>
				</button>
			</div>
		</div>
	</div>
</div>

<!-- GUI report controls. CLI parsing remains independent. -->
<div class="modal fade concurrencycount" id="cc-wizard" tabindex="-1" role="dialog" aria-labelledby="cc-wizard-title">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="cc-wizard-title"><?php echo _('Concurrency Count'); ?></h4>
			</div>
			<div class="modal-body">
				<fieldset class="form-group" id="cc-wizard-mode-group" aria-describedby="cc-mode-description">
					<legend class="control-label"><?php echo _('What do you want to measure?'); ?></legend>
					<div class="cc-mode-options">
						<label class="cc-mode-option" for="cc-mode-trunk">
							<input type="radio" name="cc-wizard-mode" id="cc-mode-trunk" value="trunk" checked>
							<span class="cc-mode-copy">
								<span class="cc-mode-scope"><?php echo _('External capacity'); ?></span>
								<strong><?php echo _('Trunk Concurrency'); ?></strong>
								<span><?php echo _('Choose this for SIP channel sizing, carrier capacity, trunk licensing and peak external usage.'); ?></span>
								<small><?php echo _('Example: Peak 4 means four trunk legs were using that trunk simultaneously.'); ?></small>
							</span>
						</label>
						<label class="cc-mode-option" for="cc-mode-extension">
							<input type="radio" name="cc-wizard-mode" id="cc-mode-extension" value="extension">
							<span class="cc-mode-copy">
								<span class="cc-mode-scope"><?php echo _('Individual endpoints'); ?></span>
								<strong><?php echo _('Extension Concurrency'); ?></strong>
								<span><?php echo _('Choose this to find individual extensions with overlapping qualifying CDRs.'); ?></span>
								<small><?php echo _('Example: Peak 2 for extension 203 means two assigned CDRs overlapped.'); ?></small>
							</span>
						</label>
						<label class="cc-mode-option" for="cc-mode-group">
							<input type="radio" name="cc-wizard-mode" id="cc-mode-group" value="group">
							<span class="cc-mode-copy">
								<span class="cc-mode-scope"><?php echo _('PBX extension-side load'); ?></span>
								<strong><?php echo _('Overall Extension Concurrency'); ?></strong>
								<span><?php echo _('Choose this for one PBX-wide view of simultaneous numeric PJSIP extension legs.'); ?></span>
								<small><?php echo _('Example: Peak 12 means 12 extension legs were active across the PBX at once.'); ?></small>
							</span>
						</label>
					</div>
					<span id="cc-mode-description" class="help-block fpbx-help-block" aria-live="polite"><?php echo _('Trunks measure external capacity. Peak details can show when and which CDRs reached it.'); ?></span>
				</fieldset>
				<div class="form-group" id="cc-engine-group">
					<label for="cc-engine" class="control-label"><?php echo _('Calculation engine'); ?></label>
					<select id="cc-engine" class="form-control">
						<?php foreach ($availableEngines as $id => $engine): ?>
							<option value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $id === 'original' ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($engine['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($engine['experimental']) ? ' ' . _('(experimental)') : ' ' . _('(recommended)'); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="help-block fpbx-help-block"><?php echo _('The engine changes how concurrency is calculated, not what the selected report measures. Original is recommended; Sweep is experimental.'); ?></span>
				</div>
				<div class="form-group cc-date-range">
					<label class="control-label"><?php echo _('Date range'); ?></label>
					<div class="btn-group cc-date-presets" role="group" aria-label="<?php echo _('Date range presets'); ?>">
						<button type="button" class="btn btn-default cc-date-preset" data-preset="today"><?php echo _('Today'); ?></button>
						<button type="button" class="btn btn-default cc-date-preset" data-preset="yesterday"><?php echo _('Yesterday'); ?></button>
						<button type="button" class="btn btn-default cc-date-preset" data-preset="last7"><?php echo _('Last 7 days'); ?></button>
						<button type="button" class="btn btn-default cc-date-preset" data-preset="last30"><?php echo _('Last 30 days'); ?></button>
						<button type="button" class="btn btn-default cc-date-preset" data-preset="month"><?php echo _('This month'); ?></button>
						<button type="button" class="btn btn-default cc-date-preset" data-preset="custom"><?php echo _('Custom'); ?></button>
					</div>
					<div class="cc-range-nav">
						<button type="button" class="btn btn-default cc-range-shift" data-direction="-1" aria-label="<?php echo _('Previous range'); ?>" title="<?php echo _('Previous range'); ?>"><i class="fa fa-chevron-left"></i></button>
						<strong id="cc-range-label"></strong>
						<button type="button" class="btn btn-default cc-range-shift" data-direction="1" aria-label="<?php echo _('Next range'); ?>" title="<?php echo _('Next range'); ?>"><i class="fa fa-chevron-right"></i></button>
					</div>
					<div id="cc-custom-dates" class="row" style="display:none;">
						<div class="col-sm-6 form-group">
							<label for="cc-date-from"><?php echo _('From'); ?></label>
							<input type="date" id="cc-date-from" class="form-control">
						</div>
						<div class="col-sm-6 form-group">
							<label for="cc-date-to"><?php echo _('To'); ?></label>
							<input type="date" id="cc-date-to" class="form-control">
						</div>
					</div>
					<div class="checkbox cc-time-toggle">
						<label><input type="checkbox" id="cc-include-time"> <?php echo _('Include time'); ?></label>
					</div>
					<div id="cc-time-controls" class="row" style="display:none;">
						<div class="col-sm-6 form-group"><label for="cc-time-from"><?php echo _('From time'); ?></label><input type="time" id="cc-time-from" class="form-control" value="00:00"></div>
						<div class="col-sm-6 form-group"><label for="cc-time-to"><?php echo _('To time'); ?></label><input type="time" id="cc-time-to" class="form-control" value="23:59"></div>
					</div>
				</div>
				<div id="cc-wizard-error" class="alert alert-danger" style="display:none;"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal" id="cc-wizard-cancel"><?php echo _('Cancel'); ?></button>
				<button type="button" class="btn btn-primary" id="cc-wizard-next"><i class="fa fa-play"></i> <?php echo _('Run report'); ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Runtime overrun warning modal -->
<div class="modal fade" id="cc-overrun" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title"><?php echo _('Long-running count'); ?></h4>
			</div>
			<div class="modal-body">
				<div class="alert alert-warning">
					<strong><?php echo _('Warning:'); ?></strong>
					<span id="cc-overrun-message"></span>
				</div>
				<p><?php echo _('The count is likely to abort before completion. Continue anyway?'); ?></p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" id="cc-overrun-no"><?php echo _('No, abort'); ?></button>
				<button type="button" class="btn btn-warning" id="cc-overrun-yes"><?php echo _('Yes, continue'); ?></button>
			</div>
		</div>
	</div>
</div>

<script src="modules/concurrencycount/assets/js/date-range.js?v=<?php echo $_ccAssetVer; ?>"></script>
<script src="modules/concurrencycount/assets/js/concurrency-charts.js?v=<?php echo $_ccAssetVer; ?>"></script>
<script src="modules/concurrencycount/assets/js/concurrencycount.js?v=<?php echo $_ccAssetVer; ?>"></script>
<script src="modules/concurrencycount/assets/js/live-command-centre.js?v=<?php echo $_ccAssetVer; ?>"></script>
