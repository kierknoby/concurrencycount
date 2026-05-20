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
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

// Cache-bust based on the newest asset file. If either file changes,
// browsers see a new URL and refetch. Falls back to time() if filemtime
// fails (shouldn't, but better than a blank query string).
$_ccAssetVer = max(
	@filemtime(__DIR__ . '/../assets/js/concurrencycount.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/css/concurrencycount.css') ?: 0
) ?: time();
?>
<link rel="stylesheet" href="modules/concurrencycount/assets/css/concurrencycount.css?v=<?php echo $_ccAssetVer; ?>">

<div class="concurrencycount">
	<div class="row">
		<div class="col-sm-12">
			<h1>
				<?php echo _('Concurrency Count'); ?>
				<small class="text-muted" style="font-size:0.5em;">v<?php echo htmlspecialchars($moduleVersion); ?> &mdash; <?php echo _('by 20tele.com'); ?></small>
			</h1>

			<div class="row">
				<div class="col-sm-12">
					<p>
						<?php echo _('Maximum concurrent calls per trunk, extension, or group, within a specified date range. PJSIP only (no chan_sip support).'); ?>
					</p>
				</div>
			</div>

			<div class="row">
				<div class="col-sm-12">
					<button type="button" id="cc-launch" class="btn btn-primary">
						<i class="fa fa-play"></i> <?php echo _('Start Concurrency Count'); ?>
					</button>
				</div>
			</div>

			<div id="cc-status" class="alert" style="display:none; margin-top:20px;"></div>

			<div id="cc-results" style="display:none; margin-top:20px;">
				<h3 id="cc-results-title"></h3>
				<div class="row">
					<div class="col-sm-12">
						<dl class="dl-horizontal" id="cc-results-meta"></dl>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-12">
						<div id="cc-results-body"></div>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-12">
						<div id="cc-results-warning" class="alert alert-warning"></div>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-12">
						<button type="button" id="cc-download" class="btn btn-default">
							<i class="fa fa-download"></i> <?php echo _('Download CSV'); ?>
						</button>
						<button type="button" id="cc-email-toggle" class="btn btn-default">
							<i class="fa fa-envelope"></i> <?php echo _('Email report'); ?>
						</button>
					</div>
				</div>
				<div id="cc-email-row" class="row" style="display:none; margin-top:12px;">
					<div class="col-sm-6">
						<div class="input-group">
							<input type="email" id="cc-email" class="form-control" placeholder="<?php echo _('recipient@example.com'); ?>">
							<span class="input-group-btn">
								<button type="button" id="cc-email-send" class="btn btn-primary"><?php echo _('Send'); ?></button>
							</span>
						</div>
					</div>
				</div>
			</div>

		</div>
	</div>
</div>

<!-- Wizard modal: mirrors the CLI flow -->
<div class="modal fade" id="cc-wizard" tabindex="-1" role="dialog" aria-labelledby="cc-wizard-title">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="cc-wizard-title"><?php echo _('Concurrency Count'); ?></h4>
			</div>
			<div class="modal-body">
				<div class="form-group">
					<label id="cc-wizard-prompt" for="cc-wizard-value" class="control-label"></label>
					<input type="text" id="cc-wizard-value" class="form-control" autocomplete="off">
					<span id="cc-wizard-hint" class="help-block fpbx-help-block"></span>
				</div>
				<div id="cc-wizard-error" class="alert alert-danger" style="display:none;"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal" id="cc-wizard-cancel"><?php echo _('Cancel'); ?></button>
				<button type="button" class="btn btn-primary" id="cc-wizard-next"><?php echo _('Next'); ?></button>
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

<script src="modules/concurrencycount/assets/js/concurrencycount.js?v=<?php echo $_ccAssetVer; ?>"></script>
