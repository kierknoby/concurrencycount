/**
 * Concurrency Count wizard.
 *
 * Mirrors the bash CLI flow:
 *   1. Mode (trunks/extensions/group, abbreviations accepted)
 *   2. Month/shortcut (today, yesterday, month name, or blank for custom range)
 *   3a. If month: year (Y, YY, YYYY)
 *   3b. If blank: start date, then end date
 *   4. Run
 *
 * Three-attempt retry per step. Server-side validation matches the bash.
 *
 * Patterns borrowed from mwtcmi/frogman:
 *  - Single-load guard so a stray duplicate script tag doesn't double-bind
 *    every click handler
 *  - $elem.off().on() everywhere, same reason
 *  - escapeHtml via jQuery's text/html round-trip (smaller than chained
 *    replace, and harder to get wrong)
 *  - $.ajax with explicit contentType, dataType
 *  - Whimsical error variants on network failure instead of "Error:"
 *  - Timestamp on every status update
 *
 * Security notes carried over from Frogman's hard-won experience:
 *  - Every value that ends up in innerHTML is escaped first. The escapeHtml
 *    function is the only way to put text into the DOM.
 *  - No raw user input or server response is concatenated into HTML
 *    without going through escapeHtml.
 */
if (!window._ccLoaded) {
window._ccLoaded = true;

(function ($) {
	'use strict';

	var MAX_ATTEMPTS = 3;
	var wizardState = null;
	var finalMode = null;
	var finalStart = null;
	var finalEnd = null;
	var finalDemoReport = null;
	var finalDemoSize = null;
	var finalDemoSeed = null;
	var finalEngine = 'original';
	var finalDemoEngines = ['original'];
	var currentResults = null;
	var demoSeed = 0;
	var demoMoves = 0;
	var demoPlan = null;
	var guiRange = null;
	var modeDescriptions = {
		trunk: 'Trunks measure external capacity. Peak details can show when and which CDRs reached it.',
		extension: 'Extensions measure overlapping CDR legs assigned to each configured or manually classified PJSIP device.',
		group: 'Group measures attributable PJSIP extension-leg activity across the PBX, not a Ring Group or selected member list.'
	};
	var modeLabels = {
		trunk: 'Trunk Concurrency',
		extension: 'Extension Concurrency',
		group: 'Group Concurrency'
	};

	/* ---------- Historical report tab state (client-side model) ---------- */
	var historicalReports = {}; // id -> report instance (definition + transient result/UI cache)
	var activeReportId = null;
	var wizardTargetReportId = null; // which report the open wizard will run into
	var runTargetReportId = null; // snapshot of the report a just-fired AJAX run belongs to
	var historicalGraphTargetReportId = null; // snapshot for the graph-cache bridge to live-view.js
	var generatedReportName = '';
	var pendingExcludedCallIdentity = null;
	var pendingPersistedRefresh = false;
	var activeCalculation = null;
	var calculationSequence = 0;
	var calculationHeartbeatInterval = 5000;
	var workspaceLockReportId = null;
	var calculationUnavailableTitle = 'Unavailable while a Historical calculation is running.';

	function newCalculationId() {
		var bytes = new Uint8Array(16);
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') window.crypto.getRandomValues(bytes);
		else for (var index = 0; index < bytes.length; index++) bytes[index] = Math.floor(Math.random() * 256);
		return Array.prototype.map.call(bytes, function (value) { return ('0' + value.toString(16)).slice(-2); }).join('');
	}

	function selectedMode() {
		return $('input[name="cc-wizard-mode"]:checked').val() || 'trunk';
	}

	function selectMode(mode) {
		var input = $('input[name="cc-wizard-mode"][value="' + mode + '"]');
		if (!input.length) input = $('#cc-mode-trunk');
		input.prop('checked', true);
		updateModeDescription();
	}

	/**
	 * Use jQuery's DOM round-trip for HTML escaping. Cheaper than chained
	 * replace calls and impossible to get the entity table wrong. Every
	 * value that touches innerHTML goes through here first.
	 */
	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		return $('<div>').text(String(s)).html();
	}

	function ajax(params, requestOptions) {
		params = $.extend({}, params, {token: $('.concurrencycount').attr('data-csrf-token') || ''});
		var settings = $.extend({
			url: 'ajax.php?module=concurrencycount',
			method: 'POST',
			data: params,
			dataType: 'json'
		}, requestOptions || {});
		var request = $.ajax(settings);
		request.ccAjaxSettings = settings;
		return request;
	}

	function reportUnexpectedHistoricalAjaxFailure(request, textStatus, errorThrown) {
		$(document).trigger('ajaxError', [request, request.ccAjaxSettings || {}, errorThrown || textStatus]);
	}

	/**
	 * Whimsical error variants for network failures. Lifted from Frogman's
	 * spirit. Stock "Error:" prefixes signal a generic failure path; varied
	 * prefixes signal that someone thought about it.
	 */
	var NETWORK_OOPS = [
		'Lost the connection there.',
		'PBX didn\'t answer.',
		'Network blip, that one.',
		'Something went sideways.',
		'No reply from the server.'
	];

	function randomOops() {
		return NETWORK_OOPS[Math.floor(Math.random() * NETWORK_OOPS.length)];
	}

	/* ---------- Status ---------- */

	function setStatus(msg, level) {
		var el = $('#cc-status');
		el.removeClass('alert-info alert-warning alert-danger alert-success');
		if (!msg) { el.hide(); return; }

		var time = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
		var timeHtml = ' <small class="text-muted">' + escapeHtml(time) + '</small>';

		if (level === 'running') {
			el.addClass('alert-info').html('<span class="cc-spinner"></span>' + escapeHtml(msg) + timeHtml);
		} else if (level === 'error') {
			el.addClass('alert-danger').html(escapeHtml(msg) + timeHtml);
		} else if (level === 'success') {
			el.addClass('alert-success').html(escapeHtml(msg) + timeHtml);
		} else if (level === 'warning') {
			el.addClass('alert-warning').html(escapeHtml(msg) + timeHtml);
		}
		el.show();
	}

	/* ---------- Wizard plumbing ---------- */

	function showWizard() {
		$('#cc-wizard').modal('show');
	}

	function hideWizard() {
		$('#cc-wizard').modal('hide');
	}

	function showDemoPrompt() {
		$('#cc-results').hide();
		setStatus('', null);
		randomiseDemoSeed('New random seed ready.');
		$('#cc-demo').modal('show');
	}

	function runDemo(report) {
		report = report || 'extension';
		var seedField = parseInt($('#cc-demo-seed').val(), 10);
		if (!isNaN(seedField)) {
			demoSeed = seedField >>> 0;
		}
		demoPlan = buildDemoPlan(demoSeed, report);
		renderDemoPlan();
		var engines = selectedDemoEngines();
		if (demoPlan.size === 'heavy' && !window.confirm('Heavy demo creates about 10,000 synthetic CDR rows and may take several minutes. Continue?')) {
			return;
		}
		$('#cc-demo').modal('hide');
		// Demo is a synthetic accuracy/perf check, not a persisted report tab
		// (Option B): it paints the shared results surface transiently and
		// never reads or writes any report instance's saved state.
		runTargetReportId = null;
		showTransientDemoResult();
		executeRun('demo', demoPlan.start, demoPlan.end, {
			demo_report: report,
			demo_size: demoPlan.size,
			demo_rows: String(demoPlan.rows),
			demo_seed: String(demoSeed >>> 0),
			demo_engines: engines.join(',')
		});
	}

	function selectedDemoEngines() {
		var engines = [];
		$('.cc-demo-engine:checked').each(function () {
			engines.push($(this).val());
		});
		if (!engines.length) {
			engines.push('original');
			$('.cc-demo-engine[value="original"]').prop('checked', true);
		}
		return engines;
	}

	function updateDemoSeedStatus(prefix) {
		$('#cc-demo-seed').val(String(demoSeed >>> 0));
		$('#cc-demo-entropy-status').text(prefix + ' Seed: ' + (demoSeed >>> 0) + '. Movement samples: ' + demoMoves + '.');
		demoPlan = buildDemoPlan(demoSeed, 'trunk');
		renderDemoPlan();
	}

	function randomiseDemoSeed(prefix) {
		demoSeed = ((Date.now() ^ Math.floor(Math.random() * 0x7fffffff)) & 0x7fffffff) >>> 0;
		demoMoves = 0;
		$('#cc-demo-entropy').removeClass('cc-demo-entropy-active');
		updateDemoSeedStatus(prefix || 'Randomised again.');
	}

	function stirDemoSeed(x, y) {
		demoMoves++;
		demoSeed = (((demoSeed * 33) >>> 0) ^ (x << 16) ^ y ^ Date.now()) >>> 0;
		$('#cc-demo-entropy').addClass('cc-demo-entropy-active');
		updateDemoSeedStatus('Movement captured.');
	}

	function buildDemoPlan(seed, report) {
		var rng = seededRng(seed);
		var roll = rng();
		var size = roll > 0.92 ? 'heavy' : (roll > 0.45 ? 'medium' : 'light');
		var rows = size === 'heavy'
			? randRange(rng, 7000, 14000)
			: (size === 'medium' ? randRange(rng, 650, 2200) : randRange(rng, 25, 140));
		var dayOffset = randRange(rng, 0, 6200);
		var hour = randRange(rng, 0, 23);
		var minute = randRange(rng, 0, 59);
		var durationMinutes = size === 'heavy'
			? randRange(rng, 360, 10080)
			: (size === 'medium' ? randRange(rng, 90, 2160) : randRange(rng, 20, 240));
		var startDate = new Date(2001, 0, 1 + dayOffset, hour, minute, 0);
		var endDate = new Date(startDate.getTime() + durationMinutes * 60 * 1000);
		return {
			report: report,
			size: size,
			rows: rows,
			start: formatDateTime(startDate),
			end: formatDateTime(endDate)
		};
	}

	function seededRng(seed) {
		var state = (seed || 1) >>> 0;
		return function () {
			state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
			return state / 4294967296;
		};
	}

	function randRange(rng, min, max) {
		return min + Math.floor(rng() * (max - min + 1));
	}

	function renderDemoPlan() {
		if (!demoPlan) return;
		$('#cc-demo-plan').html(
			'<dt>Load</dt><dd>' + escapeHtml(demoPlan.size) + ' (' + escapeHtml(demoPlan.rows) + ' calls)</dd>' +
			'<dt>CDR write range</dt><dd>' + escapeHtml(demoPlan.start) + ' to ' + escapeHtml(demoPlan.end) + '</dd>' +
			'<dt>Accountcode</dt><dd>CCDEMO*</dd>'
		);
	}

	function formatDateTime(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
			' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
	}

	/**
	 * promptHtml is the only place where we accept HTML rather than text.
	 * It's called only with constant strings from this file (the prompts
	 * include <code> tags), never with server data. If that ever changes,
	 * escape the dynamic part first.
	 */
	function setStep(promptHtml, hint, placeholder) {
		$('#cc-wizard-mode-group').hide();
		$('#cc-wizard-value').closest('.form-group').show();
		$('#cc-wizard-prompt').html(promptHtml);
		$('#cc-wizard-hint').text(hint || '');
		var input = $('#cc-wizard-value');
		input.val('').attr('placeholder', placeholder || '');
		clearError();
		setTimeout(function () { input.focus(); }, 100);
	}

	function clearError() {
		$('#cc-wizard-error').hide().empty();
	}

	function showError(msg, attempts) {
		var html = escapeHtml(msg);
		if (attempts !== null && attempts !== undefined) {
			html += ' <em>(Attempt ' + escapeHtml(attempts) + ' of ' + MAX_ATTEMPTS + ')</em>';
		}
		$('#cc-wizard-error').html(html).show();
	}

	function tooManyAttempts() {
		showError('Too many invalid attempts. Goodbye.');
		setTimeout(hideWizard, 1500);
	}

	/* ---------- Results rendering ---------- */
	function renderResultWarning(message) {
		var warning = $('#cc-results-warning');
		var meaningful = window.CCHistoricalRunState.hasMeaningfulMessage(message);
		warning.text(meaningful ? String(message) : '').prop('hidden', !meaningful).attr('aria-hidden', meaningful ? 'false' : 'true');
	}

	function renderResults(r) {
		currentResults = r;
		finalMode = r.mode;
		finalStart = r.start;
		finalEnd = r.end;
		finalDemoReport = r.demo_report || null;
		finalDemoSize = r.demo_size || null;
		finalDemoSeed = r.demo_seed || null;
		finalEngine = r.engine || 'original';
		finalDemoEngines = r.engines ? Object.keys(r.engines) : [r.engine || 'original'];

		var modeLabel = modeLabels[r.mode] || (r.mode.charAt(0).toUpperCase() + r.mode.slice(1));
		$('#cc-results-title').text(modeLabel + ' results');

		$('#cc-results-meta').html(
			'<dt>From</dt><dd>' + escapeHtml(r.start) + '</dd>' +
			'<dt>To</dt><dd>' + escapeHtml(r.end) + '</dd>' +
			'<dt>Engine</dt><dd>' + escapeHtml(r.engine || (r.engines ? 'comparison' : 'original')) + '</dd>' +
			'<dt>Rows processed</dt><dd>' + escapeHtml(r.rows_processed) + '</dd>'
		);

		var body = $('#cc-results-body');
		body.data('demoRows', r.rows_inserted || '');
		if (r.empty_message) {
			body.html(renderExplanation(r) + '<p class="text-muted">No activity found for this report.</p>');
		} else if (r.mode === 'demo') {
			renderDemo(body, r);
		} else if (r.mode === 'group') {
			renderGroup(body, r);
		} else {
			renderPerName(body, r);
		}
		body.append(renderIdentityAnomalies(r.identity_anomalies || []));

		renderResultWarning(r.warning);
		$('#cc-download-cdr').toggle(r.mode === 'demo');
		$('#cc-results').show();
		$(document).trigger('cc:historical-results', [r]);
	}

	function renderIdentityAnomalies(anomalies) {
		if (!anomalies.length) return '';
		var html = '<section class="alert alert-warning cc-identity-anomalies"><h4>Some PJSIP endpoints could not be identified</h4><p>Unresolved endpoints are excluded from classification-dependent totals. These choices affect Concurrency Count only and do not change FreePBX, Asterisk or CDR data.</p>';
		anomalies.forEach(function (item) {
			html += '<div class="cc-identity-anomaly"><code>' + escapeHtml(item.endpoint) + '</code> ';
			if (item.type === 'conflict') html += '<span>is configured as both a FreePBX trunk and device. Resolve the FreePBX configuration conflict.</span>';
			else html += '<span>is not a configured FreePBX PJSIP trunk or device.</span> <button class="btn btn-default btn-xs cc-classify-endpoint" data-endpoint="' + escapeHtml(item.endpoint) + '" data-classification="trunk">Treat as Trunk</button> <button class="btn btn-default btn-xs cc-classify-endpoint" data-endpoint="' + escapeHtml(item.endpoint) + '" data-classification="extension">Treat as Extension</button> <button class="btn btn-default btn-xs cc-classify-endpoint" data-endpoint="' + escapeHtml(item.endpoint) + '" data-classification="ignore">Ignore</button> <span class="text-muted">Leave unresolved</span>';
			html += '</div>';
		});
		return html + '</section>';
	}

	function invalidateAndRerunReports() {
		if (activeCalculation) return;
		Object.keys(historicalReports).forEach(function (id) {
			historicalReports[id].result = null;
			historicalReports[id].graphSeries = null;
			historicalReports[id].occurrenceCache = {};
		});
		if (activeReportId && historicalReports[activeReportId]) { pendingPersistedRefresh = true; regenerateReport(historicalReports[activeReportId]); }
	}

	function saveIdentityClassification(endpoint, classification) {
		ajax({command: 'saveidentityclassification', endpoint: endpoint, classification: classification}).done(function (response) {
			if (!response.status) { setStatus(response.message || 'Unable to save endpoint classification.', 'error'); return; }
			invalidateAndRerunReports();
			loadIdentityClassifications();
		});
	}

	function renderIdentityClassifications(entries) {
		var body = $('#cc-identity-rows').empty();
		if (!entries.length) { body.append('<tr><td colspan="4" class="text-muted">No endpoint classifications are remembered.</td></tr>'); return; }
		entries.forEach(function (entry) {
			body.append('<tr><td><code>' + escapeHtml(entry.endpoint) + '</code></td><td>' + escapeHtml(entry.manual) + '</td><td>' + escapeHtml(entry.status) + (entry.status === 'superseded' ? ' by FreePBX (' + escapeHtml(entry.automatic_type) + ')' : '') + '</td><td><button type="button" class="btn btn-default btn-xs cc-reset-identity" data-endpoint="' + escapeHtml(entry.endpoint) + '">Reset to automatic</button></td></tr>');
		});
	}

	function loadIdentityClassifications() {
		return ajax({command: 'getidentityclassifications'}).done(function (response) {
			if (response.status) renderIdentityClassifications(response.classifications || []);
		});
	}

	function openIdentityClassifications() {
		loadIdentityClassifications();
		$('#cc-identity-modal').modal('show');
	}

	function renderExplanation(r) {
		var html = '<div class="cc-result-explanation">';
		html += '<h4>What this means</h4>';
		html += '<p>' + escapeHtml(resultExplanationText(r)) + '</p>';
		html += '<p class="cc-engine-note">' + escapeHtml(engineExplanationText(r)) + '</p>';
		html += '</div>';
		return html;
	}

	function resultExplanationText(r) {
		var engine = r.engine || (r.engines ? 'comparison' : 'original');
		var overview = r.overview || {};
		if (r.empty_message) {
			return 'No eligible activity was found in the selected range.';
		}
		if (r.mode === 'demo') {
			if (r.accuracy_status === 'pass' && r.engines) {
				return 'The selected engines matched the independently calculated expected output for this demo fixture. Original remains the recommended engine; experimental engines are shown here so their speed and accuracy can be compared safely.';
			}
			if (r.accuracy_status === 'pass') {
				return 'The demo output matched the independently calculated expected result, and the temporary CDR rows were checked after the run.';
			}
			if (r.accuracy_status === 'mixed') {
				return 'At least one experimental engine did not match the expected output. Treat Original as the trusted result for this run and use the comparison details when reporting the mismatch.';
			}
			return 'The demo output did not match the independently calculated expected result. Treat this run as a failed accuracy check and do not use experimental results for decisions.';
		}
		if (r.mode === 'group') {
			return groupExplanation(r, overview, engine);
		}
		var label = (r.mode === 'trunk') ? 'trunk' : 'extension';
		return perNameExplanation(r, overview, engine, label);
	}

	function engineExplanationText(r) {
		if (r.engines) {
			return 'Original is the reference engine: it walks every second of each call and is the trusted/default result. Sweep is experimental: it uses call start and end events to reach the same answer faster, and should only be trusted when its accuracy status passes.';
		}
		return engineMethodText(r.engine || 'original');
	}

	function engineMethodText(id) {
		if (id === 'sweep') {
			return 'Sweep calculates concurrency from call start/end events rather than walking every second. It is faster and lower-memory, but experimental.';
		}
		return 'Original calculates concurrency by walking every second of each call. It is slower on large ranges, but it is the trusted default and reference result.';
	}

	function groupExplanation(r, overview, engine) {
		var max = parseInt(r.max_concurrency, 10) || 0;
		var average = parseFloat(overview.average_concurrency) || 0;
		var ratio = parseFloat(overview.peak_to_average_ratio) || 0;
		var peakPercent = parseFloat(overview.peak_period_percent) || 0;
		var text = max === 1
			? 'Activity was present, but no eligible extension-side legs overlapped. The exact highest simultaneous count was 1.'
			: 'The highest total number of simultaneous attributable PJSIP extension legs in this date range was ' + max + '. Both classified extension sides of one internal CDR can count.';
		if (average > 0) {
			text += ' For calls that started in the selected range, the average simultaneous count within the displayed window was ' + formatDecimal(average) + ', so the observed peak was ' + formatDecimal(ratio) + 'x that average.';
		}
		if (peakPercent > 0) {
			if (peakPercent < 1) {
				text += ' The peak was brief, covering less than 1% of the selected period.';
			} else if (peakPercent >= 20) {
				text += ' The peak was sustained, covering about ' + formatDecimal(peakPercent) + '% of the selected period.';
			} else {
				text += ' The peak covered about ' + formatDecimal(peakPercent) + '% of the selected period.';
			}
		}
		text += ' Engine used: ' + engine + '.';
		return text;
	}

	function perNameExplanation(r, overview, engine, label) {
		var max = parseInt(r.global_max, 10) || 0;
		var average = parseFloat(overview.average_concurrency) || 0;
		var ratio = parseFloat(overview.peak_to_average_ratio) || 0;
		var namesWithPeak = parseInt(overview.names_with_peak, 10) || 0;
		var namesSeen = parseInt(overview.names_seen, 10) || 0;
		var text = max === 1
			? 'Activity was present, but no calls overlapped for any one ' + label + '. The exact highest simultaneous count was 1.'
			: (r.mode === 'trunk'
				? 'The highest simultaneous matching trunk-leg count seen on any trunk in this date range was ' + max + '.'
				: 'The highest simultaneous answered-CDR count assigned to any one extension in this date range was ' + max + '.');
		if (average > 0) {
			text += ' For calls that started in the selected range, the average simultaneous count within the displayed window was ' + formatDecimal(average) + ', so the observed peak was ' + formatDecimal(ratio) + 'x that average.';
		}
		if (namesWithPeak === 1 && namesSeen > 1) {
			text += ' The peak was concentrated on one ' + label + '.';
		} else if (namesWithPeak > 1) {
			text += ' The same peak was reached by ' + namesWithPeak + ' ' + label + 's.';
		}
		text += ' Engine used: ' + engine + '.';
		return text;
	}

	function renderGroup(el, r) {
		var html = renderExplanation(r);
		var activityOnly = parseInt(r.max_concurrency, 10) === 1;
		html += '<div class="cc-peak-summary' + (activityOnly ? ' cc-activity-summary' : '') + '">' +
			(activityOnly ? 'Activity detected, no concurrency' : 'Peak group concurrency: <strong>' + escapeHtml(r.max_concurrency) + '</strong>') +
			'<br><span>' + (activityOnly ? 'Highest simultaneous count: 1 attributable PJSIP extension leg.' : escapeHtml(r.max_concurrency) + ' attributable PJSIP extension legs active simultaneously across the PBX.') + '</span></div>';
		if (r.peak_ranges && r.peak_ranges.length) {
			html += '<h4>' + (activityOnly ? 'Activity time ranges' : 'Peak time ranges') + '</h4><ul class="cc-peak-ranges">';
			r.peak_ranges.forEach(function (range) {
				if (range.from === range.to) {
					html += '<li>' + escapeHtml(range.from) + '</li>';
				} else {
					html += '<li>' + escapeHtml(range.from) + ' to ' + escapeHtml(range.to) + '</li>';
				}
			});
			html += '</ul>';
		}
		el.html(html);
	}

	function renderPerName(el, r) {
		var label = (r.mode === 'trunk') ? 'Trunk' : 'Extension';
		var names = Object.keys(r.per_name);
		var concurrencyNames = names.filter(function (name) { return parseInt(r.per_name[name], 10) >= 2; });
		var activityNames = names.filter(function (name) { return parseInt(r.per_name[name], 10) === 1; });
		if (!concurrencyNames.length && !activityNames.length) {
			el.html(renderExplanation(r) + '<p class="text-muted">No activity found for this report.</p>');
			return;
		}
		var html = renderExplanation(r);
		if (r.mode === 'trunk') {
			html += renderTrunkResults(concurrencyNames, names, r, false);
			if (activityNames.length) html += renderActivityDisclosure(renderTrunkResults(activityNames, names, r, true), activityNames.length);
		} else {
			html += renderExtensionTable(concurrencyNames, r, 'Peak assigned CDR concurrency');
			if (activityNames.length) html += renderActivityDisclosure(renderExtensionTable(activityNames, r, 'Activity status'), activityNames.length);
		}
		if (parseInt(r.global_max, 10) >= 2) {
			var peakDetail = r.mode === 'trunk' ? r.global_max + ' trunk legs active simultaneously at the busiest point.' : r.global_max + ' assigned CDRs overlapping at the busiest point for one extension.';
			html += '<div class="cc-peak-summary">Peak ' + escapeHtml(r.mode === 'trunk' ? 'trunk concurrency' : 'assigned extension concurrency') + ': <strong>' + escapeHtml(r.global_max) + '</strong><br><span>' + escapeHtml(peakDetail) + '</span></div>';
		} else {
			html += '<div class="cc-peak-summary cc-activity-summary">No concurrent calls detected.<br><span>Activity was present, but no calls overlapped. Highest simultaneous count: 1.</span></div>';
		}
		el.html(html);
	}

	function renderTrunkResults(selectedNames, allNames, r, activityOnly) {
		if (!selectedNames.length) return '';
		var html = '<div class="cc-trunk-results">';
		selectedNames.forEach(function (trunk) {
			var nameIndex = allNames.indexOf(trunk);
			var count = r.per_name[trunk];
			var isPeak = count === r.global_max && r.global_max >= 2;
			html += '<section class="panel panel-default cc-trunk-result' + (isPeak ? ' cc-peak-row' : '') + (activityOnly ? ' cc-activity-result' : '') + '" data-name-index="' + nameIndex + '">' +
				'<div class="panel-heading cc-trunk-summary"><h4>' + renderEntity(r.trunk_entities ? r.trunk_entities[trunk] : null, trunk) + '</h4>' +
				'<p>' + (activityOnly ? 'Activity detected, no concurrency' : 'Peak trunk concurrency: <strong>' + escapeHtml(count) + '</strong>') + '</p></div>' +
				renderOccurrenceSection(trunk, nameIndex, r, activityOnly) + '</section>';
		});
		return html + '</div>';
	}

	function renderExtensionTable(selectedNames, r, heading) {
		if (!selectedNames.length) return '';
		var html = '<div class="cc-table-scroll"><table class="table table-striped"><thead><tr><th>Extension</th><th>' + escapeHtml(heading) + '</th></tr></thead><tbody>';
		selectedNames.forEach(function (name) {
			var count = parseInt(r.per_name[name], 10) || 0;
			var isPeak = count === r.global_max && r.global_max >= 2;
			html += '<tr' + (isPeak ? ' class="cc-peak-row"' : '') + '><td>' + escapeHtml(name) + '</td><td>' + (count === 1 ? 'Activity detected, no concurrency' : '<strong>' + escapeHtml(count) + '</strong>') + '</td></tr>';
		});
		return html + '</tbody></table></div>';
	}

	function renderActivityDisclosure(content, count) {
		return '<section class="cc-activity-only"><button type="button" class="btn btn-default cc-activity-toggle" aria-expanded="false" aria-controls="cc-activity-only-results"><span>Show activity-only results</span> <span class="badge">' + escapeHtml(count) + '</span></button><div id="cc-activity-only-results" class="cc-activity-only-results" hidden>' + content + '</div></section>';
	}

	function renderOccurrenceSection(trunk, nameIndex, r, activityOnly) {
		var occurrences = r.peak_occurrences && r.peak_occurrences[trunk] ? r.peak_occurrences[trunk] : [];
		if (!occurrences.length) return '<p class="panel-body text-muted cc-no-occurrences">No ' + (activityOnly ? 'activity' : 'peak') + ' occurrences in this range.</p>';
		var html = '<div class="cc-occurrence-section" data-name-index="' + nameIndex + '"><h5>' + (activityOnly ? 'Activity occurrences' : 'Peak occurrences') + '</h5>';
		occurrences.forEach(function (occurrence, occurrenceIndex) {
			if (occurrenceIndex === 5) html += '<div id="cc-additional-occurrences-' + nameIndex + '" class="cc-additional-occurrences" hidden>';
			var detailId = 'cc-occurrence-detail-' + nameIndex + '-' + occurrenceIndex;
			html += '<div class="panel panel-default cc-occurrence">' +
				'<div class="panel-heading"><button type="button" class="cc-occurrence-toggle" data-name-index="' + nameIndex + '" data-occurrence-index="' + occurrenceIndex + '" aria-expanded="false" aria-controls="' + detailId + '">' +
				'<i class="fa fa-chevron-right" aria-hidden="true"></i><span><strong>' + escapeHtml(formatOccurrenceRange(occurrence.from, occurrence.to)) + '</strong><small>' + (activityOnly ? 'Activity occurrence' : escapeHtml(occurrence.peak) + ' simultaneous trunk legs') + ' &middot; ' + escapeHtml(formatDuration(occurrence.duration_seconds)) + '</small></span></button></div>' +
				'<div id="' + detailId + '" class="panel-body cc-occurrence-detail" style="display:none"></div>' +
				'</div>';
		});
		if (occurrences.length > 5) {
			html += '</div><button type="button" class="btn btn-default btn-sm cc-occurrence-list-toggle" aria-expanded="false" aria-controls="cc-additional-occurrences-' + nameIndex + '">Show ' + escapeHtml(occurrences.length - 5) + ' more</button>';
		}
		html += '</div>';
		return html;
	}

	function setOccurrenceExpanded(button, expanded) {
		button.attr('aria-expanded', expanded ? 'true' : 'false');
		button.find('.fa').toggleClass('fa-chevron-right', !expanded).toggleClass('fa-chevron-down', expanded);
	}

	function renderEntity(entity, fallback) {
		if (!entity || !entity.label) return escapeHtml(fallback || '');
		var label = entity.label;
		if (entity.number && label.indexOf(entity.number) === -1) label += ' (' + entity.number + ')';
		return entity.native_url
			? '<a class="cc-entity-link" href="' + escapeHtml(entity.native_url) + '">' + escapeHtml(label) + '</a>'
			: escapeHtml(label);
	}

	function parseOccurrenceTimestamp(value) {
		var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}:\d{2})$/);
		if (!match) return null;
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		var monthIndex = parseInt(match[2], 10) - 1;
		if (monthIndex < 0 || monthIndex > 11) return null;
		return {date: match[1] + '-' + match[2] + '-' + match[3], label: parseInt(match[3], 10) + ' ' + months[monthIndex] + ' ' + match[1], time: match[4]};
	}

	function formatOccurrenceRange(from, to) {
		var fromParts = parseOccurrenceTimestamp(from);
		var toParts = parseOccurrenceTimestamp(to);
		if (!fromParts || !toParts) return String(from || '') === String(to || '') ? String(from || '') : String(from || '') + ' to ' + String(to || '');
		if (fromParts.date === toParts.date) return fromParts.label + ', ' + fromParts.time + (fromParts.time === toParts.time ? '' : ' to ' + toParts.time);
		return fromParts.label + ', ' + fromParts.time + ' to ' + toParts.label + ', ' + toParts.time;
	}

	function formatDuration(seconds) {
		seconds = Math.max(0, parseInt(seconds, 10) || 0);
		var hours = Math.floor(seconds / 3600);
		var minutes = Math.floor((seconds % 3600) / 60);
		var remainder = seconds % 60;
		var parts = [];
		if (hours) parts.push(hours + 'h');
		if (minutes) parts.push(minutes + 'm');
		if (remainder || !parts.length) parts.push(remainder + 's');
		return parts.join(' ');
	}

	function loadOccurrence(button) {
		var nameIndex = parseInt(button.data('name-index'), 10);
		var occurrenceIndex = parseInt(button.data('occurrence-index'), 10);
		var names = Object.keys(currentResults.per_name || {});
		var trunk = names[nameIndex];
		var occurrence = currentResults.peak_occurrences[trunk][occurrenceIndex];
		var detail = button.closest('.cc-occurrence').find('.cc-occurrence-detail');
		var cacheKey = occurrenceKey(nameIndex, occurrenceIndex);
		var report = activeReportId ? historicalReports[activeReportId] : null;
		if (detail.data('loaded')) {
			detail.toggle();
			var nowExpanded = detail.is(':visible');
			setOccurrenceExpanded(button, nowExpanded);
			if (report && report.occurrenceCache[cacheKey]) report.occurrenceCache[cacheKey].expanded = nowExpanded;
			return;
		}
		if (detail.data('loading')) return;
		detail.data('loading', true);
		button.prop('disabled', true);
		detail.html('<p class="text-muted"><span class="cc-spinner"></span>Loading contributing calls...</p>').show();
		setOccurrenceExpanded(button, true);
		ajax({
			command: 'peakdetails', trunk: trunk,
			start_date: currentResults.start, end_date: currentResults.end,
			occurrence_from: occurrence.from, occurrence_to: occurrence.to
		}).done(function (response) {
			if (!response.status) {
				detail.html('<div class="alert alert-danger">' + escapeHtml(response.message || 'Unable to load call details.') + ' Select this occurrence to retry.</div>');
				return;
			}
			detail.data('loaded', true).data('detail', response.detail).html(renderPeakCalls(response.detail));
			if (report) report.occurrenceCache[cacheKey] = {expanded: true, detail: response.detail};
		}).fail(function () {
			detail.html('<div class="alert alert-danger">' + escapeHtml(randomOops()) + ' Select this occurrence to retry.</div>').show();
			setOccurrenceExpanded(button, true);
		}).always(function () {
			detail.data('loading', false);
			button.prop('disabled', false);
		});
	}

	function renderPeakCalls(detail) {
		var counts = detail.direction_counts || {};
		var summary = [];
		if (counts.inbound) summary.push(counts.inbound + ' inbound');
		if (counts.outbound) summary.push(counts.outbound + ' outbound');
		if (counts.unknown) summary.push(counts.unknown + ' unknown');
		var html = '<p class="cc-direction-summary">' + escapeHtml(summary.join(' \u00b7 ')) + '</p>';
		html += '<div class="cc-table-scroll"><table class="table table-condensed cc-call-table"><thead><tr>' +
			'<th>Started</th><th>Caller / source</th><th>DID / destination</th><th>Direction</th><th>Duration</th><th>Observed path</th><th></th>' +
			'</tr></thead><tbody>';
		(detail.calls || []).forEach(function (call, callIndex) {
			var caller = call.caller_id || call.source;
			var destination = [call.did, call.destination].filter(Boolean).join(' / ');
			var path = [];
			if (call.direction === 'inbound') path.push(renderEntity(call.trunk_entity, call.trunk));
			(call.path || []).forEach(function (entity) { path.push(renderEntity(entity, entity.label)); });
			if (call.direction === 'outbound') path.push(renderEntity(call.trunk_entity, call.trunk));
			html += '<tr><td>' + escapeHtml(call.calldate) + '</td>' +
				'<td>' + escapeHtml(caller) + '</td><td>' + escapeHtml(destination) + '</td>' +
				'<td>' + escapeHtml(call.direction) + '</td><td>' + escapeHtml(formatDuration(call.duration)) + '</td>' +
				'<td class="cc-call-path">' + path.join(' <span aria-hidden="true">&rarr;</span> ') + '</td>' +
				'<td><button type="button" class="btn btn-default btn-sm cc-view-cdr" data-call-index="' + callIndex + '">View in CDR Reports</button>' +
				(call.call_identity ? ' <button type="button" class="btn btn-warning btn-sm cc-exclude-call" data-call-index="' + callIndex + '">Exclude Call</button>' : '') + '</td></tr>';
		});
		html += '</tbody></table></div>';
		return html;
	}

	function callFromDetailButton(button) {
		var occurrence = button.closest('.cc-occurrence');
		var callIndex = parseInt(button.data('call-index'), 10);
		var detail = occurrence.find('.cc-occurrence-detail').data('detail');
		return detail && detail.calls ? detail.calls[callIndex] : null;
	}

	function confirmExcludeCall(button) {
		var call = callFromDetailButton(button);
		if (!call || !call.call_identity) return;
		pendingExcludedCallIdentity = call.call_identity;
		$('#cc-exclude-call-error').hide().empty();
		$('#cc-exclude-call-modal').modal('show');
	}

	function excludePendingCall() {
		if (!pendingExcludedCallIdentity) return;
		$('#cc-exclude-call-confirm').prop('disabled', true);
		ajax({command: 'excludecall', call_identity: pendingExcludedCallIdentity}).done(function (response) {
			if (!response.status) { $('#cc-exclude-call-error').text(response.message || 'Unable to exclude this call.').show(); return; }
			pendingExcludedCallIdentity = null;
			$('#cc-exclude-call-modal').modal('hide');
			updateExcludedCount(response.excluded_count);
			setStatus('Call excluded globally. The source CDR was not changed. Regenerating this report...', 'success');
			invalidateAndRerunReports();
		}).fail(function () {
			$('#cc-exclude-call-error').text('Unable to save the call exclusion. The current report has not been changed.').show();
		}).always(function () { $('#cc-exclude-call-confirm').prop('disabled', false); });
	}

	function updateExcludedCount(count) {
		count = parseInt(count, 10) || 0;
		$('#cc-excluded-count').text(count ? '(' + count + ')' : '');
	}

	function openExcludedCalls() {
		$('#cc-excluded-calls-message').hide().empty();
		ajax({command: 'listexcludedcalls', report_id: activeReportId || ''}).done(function (response) {
			if (!response.status) { $('#cc-excluded-calls-message').addClass('alert-danger').text(response.message || 'Unable to load excluded calls.').show(); return; }
			renderExcludedCalls(response.calls || [], !!response.has_report_context);
			updateExcludedCount(response.excluded_count);
			$('#cc-excluded-calls-modal').modal('show');
		});
	}

	function renderExcludedCalls(calls, hasReportContext) {
		$('#cc-excluded-relevance-heading').toggle(hasReportContext);
		var body = $('#cc-excluded-calls-rows').empty();
		if (!calls.length) {
			body.append('<tr><td colspan="9" class="text-muted">No calls are currently excluded.</td></tr>');
			$('#cc-restore-all-excluded').prop('disabled', true);
			return;
		}
		$('#cc-restore-all-excluded').prop('disabled', false);
		calls.forEach(function (entry) {
			var summary = entry.summary || {};
			var context = [summary.trunk, summary.extension].filter(Boolean).join(' / ') || '-';
			var relevance = entry.matches_current_report === true ? 'Would be eligible' : (entry.matches_current_report === false ? 'Not in scope' : 'Relevance unavailable');
			var relevanceClass = !hasReportContext ? 'cc-excluded-global' : (entry.matches_current_report === true ? 'cc-excluded-relevant' : (entry.matches_current_report === false ? 'cc-excluded-not-in-scope' : 'cc-excluded-unknown'));
			var sourceState = entry.source_available ? '' : '<br><span class="text-muted">Source CDR unavailable</span>';
			body.append('<tr class="' + relevanceClass + '"><td>' + escapeHtml(summary.calldate || '-') + '</td><td>' + escapeHtml(summary.src || '-') + '</td><td>' + escapeHtml(summary.dst || '-') + '</td><td>' + escapeHtml(context) + '</td><td>' + escapeHtml(formatDuration(summary.duration || 0)) + '</td><td><code>' + escapeHtml(entry.call_identity) + '</code>' + sourceState + '</td><td>' + escapeHtml(entry.excluded_at) + '</td>' + (hasReportContext ? '<td class="cc-excluded-relevance">' + escapeHtml(relevance) + '</td>' : '') + '<td><button type="button" class="btn btn-default btn-sm cc-restore-excluded" data-call-identity="' + escapeHtml(entry.call_identity) + '">Restore</button></td></tr>');
		});
	}

	function restoreExcludedCall(identity) {
		if (!window.confirm('Restore this call? It will become eligible for Historical Reports again.')) return;
		ajax({command: 'restoreexcludedcall', call_identity: identity}).done(function (response) {
			if (!response.status) { $('#cc-excluded-calls-message').addClass('alert-danger').text(response.message || 'Unable to restore this call.').show(); return; }
			updateExcludedCount(response.excluded_count);
			openExcludedCalls();
			invalidateAndRerunReports();
		});
	}

	function restoreAllExcludedCalls() {
		if (!window.confirm('Restore all excluded calls? All calls will become eligible for Historical Reports again. No source CDR data will be changed.')) return;
		ajax({command: 'restoreallexcludedcalls'}).done(function (response) {
			if (!response.status) { $('#cc-excluded-calls-message').addClass('alert-danger').text(response.message || 'Unable to restore excluded calls.').show(); return; }
			updateExcludedCount(0);
			renderExcludedCalls([], !!activeReportId);
			invalidateAndRerunReports();
		});
	}

	function openCdrSearch(button) {
		var occurrence = button.closest('.cc-occurrence');
		var nameIndex = parseInt(occurrence.find('.cc-occurrence-toggle').data('name-index'), 10);
		var occurrenceIndex = parseInt(occurrence.find('.cc-occurrence-toggle').data('occurrence-index'), 10);
		var callIndex = parseInt(button.data('call-index'), 10);
		var detail = occurrence.find('.cc-occurrence-detail').data('detail');
		if (!detail || !detail.calls || !detail.calls[callIndex]) return;
		var search = detail.calls[callIndex].cdr_search;
		var form = $('<form>', {method: 'POST', action: search.url}).hide();
		Object.keys(search.fields || {}).forEach(function (name) {
			form.append($('<input>', {type: 'hidden', name: name, value: search.fields[name]}));
		});
		$('body').append(form);
		form.trigger('submit');
	}

	function renderDemo(el, r) {
		var html = renderExplanation(r);
		html += '<h4>Demo profile</h4>';
		var demoReportLabel = modeLabels[r.demo_report] || r.demo_report || '';
		html += '<dl class="dl-horizontal">' +
			'<dt>Run id</dt><dd>' + escapeHtml(r.demo_run_id || '') + '</dd>' +
			'<dt>Report</dt><dd>' + escapeHtml(demoReportLabel) + '</dd>' +
			'<dt>Size</dt><dd>' + escapeHtml(r.demo_size || 'light') + '</dd>' +
			'<dt>Seed</dt><dd>' + escapeHtml(r.demo_seed || '') + '</dd>' +
			'<dt>Rows inserted</dt><dd>' + escapeHtml(r.rows_inserted || r.rows_processed) + '</dd>' +
			'<dt>Rows removed</dt><dd>' + escapeHtml(r.rows_removed || 0) + '</dd>' +
			'<dt>Cleanup remaining</dt><dd>' + escapeHtml(r.cleanup_remaining || 0) + '</dd>' +
			'</dl>';
		if (r.cleanup_status === 'clean') {
			html += '<div class="alert alert-success">Demo CDR cleanup verified. No rows remain for this run.</div>';
		} else {
			html += '<div class="alert alert-danger">Demo cleanup needs checking. Rows remain for this run.</div>';
		}
		if (r.accuracy_status === 'pass') {
			html += '<div class="alert alert-success">Accuracy check passed. Actual output matches the expected output calculated from the demo CDR rows.</div>';
		} else if (r.accuracy_status === 'mixed') {
			html += '<div class="alert alert-warning">Accuracy check mixed. One or more engines did not match the expected output.</div>';
		} else {
			html += '<div class="alert alert-danger">Accuracy check failed. Actual output did not match the expected output.</div>';
		}
		if (r.engines) {
			html += renderEngineComparison(r.engines);
		}
		if (r.demo_report === 'group') {
			html += '<h4>Group accuracy</h4>';
			html += '<div class="cc-table-scroll"><table class="table table-striped"><thead><tr><th>Metric</th><th>Expected</th><th>Actual</th></tr></thead><tbody>';
			html += '<tr><td>Peak simultaneous extension legs overall</td><td>' + escapeHtml(r.expected_max_concurrency) + '</td><td>' + escapeHtml(r.max_concurrency) + '</td></tr>';
			html += '<tr><td>Peak ranges</td><td>' + escapeHtml(formatRanges(r.expected_peak_ranges)) + '</td><td>' + escapeHtml(formatRanges(r.peak_ranges)) + '</td></tr>';
			html += '</tbody></table></div>';
		} else {
			var label = (r.demo_report === 'trunk') ? 'Trunk' : 'Extension';
			var demoUnit = r.demo_report === 'trunk' ? 'trunk-leg peak' : 'assigned-CDR peak';
			var expected = r.expected_per_name || {};
			html += '<h4>' + escapeHtml(label) + ' accuracy</h4>';
			html += '<div class="cc-table-scroll"><table class="table table-striped"><thead><tr><th>' + escapeHtml(label) + '</th><th>Expected ' + escapeHtml(demoUnit) + '</th><th>Actual ' + escapeHtml(demoUnit) + '</th></tr></thead><tbody>';
			Object.keys(expected).forEach(function (n) {
				html += '<tr>' +
					'<td>' + escapeHtml(n) + '</td>' +
					'<td>' + escapeHtml(expected[n]) + '</td>' +
					'<td>' + escapeHtml((r.per_name || {})[n] || 0) + '</td>' +
					'</tr>';
			});
			html += '</tbody></table></div>';
			html += '<div class="cc-peak-summary">Expected highest ' + escapeHtml(demoUnit) + ': <strong>' + escapeHtml(r.expected_global_max) + '</strong> Actual: <strong>' + escapeHtml(r.global_max) + '</strong></div>';
		}
		el.html(html);
	}

	function renderEngineComparison(engines) {
		var html = '<h4>Engine performance</h4>';
		html += '<div class="cc-table-scroll"><table class="table table-striped"><thead><tr>' +
			'<th>Engine</th><th>Accuracy</th><th>Wall time</th><th>Peak memory</th><th>Rows/sec</th>' +
			'</tr></thead><tbody>';
		Object.keys(engines).forEach(function (id) {
			var e = engines[id];
			var fail = e.accuracy_status !== 'pass';
			html += '<tr' + (fail ? ' class="danger"' : '') + '>' +
				'<td>' + escapeHtml(id) + '</td>' +
				'<td>' + escapeHtml(e.accuracy_status) + '</td>' +
				'<td>' + escapeHtml(formatMs(e.wall_ms)) + '</td>' +
				'<td>' + escapeHtml(formatBytes(e.peak_memory_bytes)) + '</td>' +
				'<td>' + escapeHtml(formatNumber(e.rows_per_second)) + '</td>' +
				'</tr>';
		});
		html += '</tbody></table></div>';
		html += renderEngineComparisonNotes(engines);
		return html;
	}

	function renderEngineComparisonNotes(engines) {
		var html = '<div class="cc-engine-notes">';
		Object.keys(engines).forEach(function (id) {
			var e = engines[id];
			var suffix = '';
			if (e.accuracy_status === 'pass') {
				suffix = ' It matched the expected output in this run.';
			} else {
				suffix = ' It did not match the expected output in this run; do not use this engine for decisions here.';
			}
			html += '<p><strong>' + escapeHtml(id) + ':</strong> ' + escapeHtml(engineMethodText(id) + suffix) + '</p>';
		});
		html += '</div>';
		return html;
	}

	function formatRanges(ranges) {
		if (!ranges || !ranges.length) return 'None';
		return ranges.map(function (range) {
			return (range.from === range.to) ? range.from : (range.from + ' to ' + range.to);
		}).join('; ');
	}

	/* ---------- Historical report tabs ----------
	 * Historic Report tabs are peers of Live View / Historical
	 * Reports on the one top-level tab strip (#cc-workspace-tabs), not a
	 * second-level control nested inside the Historical section. One shared
	 * wizard/results DOM subtree (unchanged) is repainted from whichever
	 * report instance is active. Report definitions (mode/engine/date preset/
	 * options) persist server-side; CDR results/graphs/occurrence state are
	 * cached only in this in-memory model for the current page load and are
	 * regenerated (not replayed) after a reload.
	 */

	function occurrenceKey(nameIndex, occurrenceIndex) {
		return nameIndex + ':' + occurrenceIndex;
	}

	function reportCount() {
		return Object.keys(historicalReports).length;
	}

	function sortedReports() {
		return Object.keys(historicalReports).map(function (id) { return historicalReports[id]; }).sort(function (a, b) { return a.number - b.number; });
	}

	function initHistoricalReports() {
		ajax({command: 'listhistoricalreports'}).done(function (response) {
			if (!response.status) return;
			historicalReports = {};
			(response.reports || []).forEach(function (report) {
				historicalReports[report.id] = $.extend({result: null, hasRun: false, occurrenceCache: {}, graphSeries: null}, report);
			});
			renderTopReportTabs();
			// Deliberately does not change which top-level tab is active on
			// load: Live View remains the default landing tab, and
			// each report tab regenerates lazily only when actually selected.
		});
	}

	function renderTopReportTabs() {
		$('#cc-workspace-tabs .cc-report-tab-top').remove();
		var html = sortedReports().map(function (report) {
			var selected = report.id === activeReportId;
			return '<div class="cc-workspace-tab cc-report-tab-top" role="tab" aria-selected="' + (selected ? 'true' : 'false') + '" data-target="' + escapeHtml(report.id) + '" title="' + escapeHtml(report.name) + '">' +
				'<button type="button" class="cc-report-tab-select" data-target="' + escapeHtml(report.id) + '"><span>' + escapeHtml(report.name) + '</span>' + (report.missing_reference ? ' <i class="fa fa-exclamation-triangle text-warning" title="Referenced trunk/extension no longer exists" aria-hidden="true"></i>' : '') + '</button>' +
				'<button type="button" class="cc-report-tab-close" data-report-id="' + escapeHtml(report.id) + '" aria-label="' + escapeHtml('Close ' + report.name) + '"><i class="fa fa-times" aria-hidden="true"></i></button>' +
				'</div>';
		}).join('');
		$('#cc-tab-historical').after(html);
		if (workspaceLockReportId) setHistoricalWorkspaceLocked(true, workspaceLockReportId);
	}

	function setHistoricalWorkspaceLocked(locked, reportId) {
		workspaceLockReportId = locked ? reportId : null;
		var fixedControls = $('#cc-tab-live, #cc-tab-historical, #cc-launch, #cc-demo-launch, #cc-identity-manage, #cc-live-wall-launch, #cc-live-wall-configure');
		fixedControls.prop('disabled', locked).attr('aria-disabled', locked ? 'true' : 'false');
		fixedControls.each(function () {
			var control = $(this);
			if (locked) {
				if (control.data('cc-lock-title') === undefined) control.data('cc-lock-title', control.attr('title') || '');
				control.attr('title', calculationUnavailableTitle);
			} else {
				var originalTitle = control.data('cc-lock-title');
				if (originalTitle) control.attr('title', originalTitle); else control.removeAttr('title');
				control.removeData('cc-lock-title');
			}
		});
		$('#cc-workspace-tabs .cc-report-tab-top').each(function () {
			var tab = $(this);
			var isOwner = String(tab.data('target')) === String(reportId);
			var disabled = locked && !isOwner;
			tab.toggleClass('cc-calculation-locked', disabled).attr('aria-disabled', disabled ? 'true' : 'false');
			tab.find('.cc-report-tab-select').prop('disabled', disabled).attr('aria-disabled', disabled ? 'true' : 'false').attr('title', disabled ? calculationUnavailableTitle : '');
			tab.find('.cc-report-tab-close').prop('disabled', locked).attr('aria-disabled', locked ? 'true' : 'false').attr('title', locked ? calculationUnavailableTitle : '');
		});
		$('.concurrencycount').toggleClass('cc-historical-calculation-locked', locked);
	}

	function showReportLimitMessage() {
		$('#cc-report-limit-message').text('Maximum of 5 historical reports can be open at once.').show();
		setTimeout(function () { $('#cc-report-limit-message').fadeOut(); }, 4000);
	}

	function showHistoricalLanding() {
		activeReportId = null;
		$('#cc-report-landing').show();
		$('#cc-report-active').hide();
		$('#cc-report-active .cc-report-global-actions').show();
	}

	function showTransientDemoResult() {
		// Demo owns the shared Historical result surface only for this page
		// state. It has no report id, tab, persisted definition or cache.
		activeReportId = null;
		$('#cc-report-landing').hide();
		$('#cc-report-active').show();
		$('#cc-report-active .cc-report-global-actions').hide();
		$('#cc-report-loading, #cc-report-empty, #cc-historical-graph, #cc-email-row').hide();
	}

	/**
	 * Single point of top-level tab selection: Live View,
	 * Historical Reports (landing), or a Historic Report tab. Owns
	 * aria-selected for the whole shared strip; delegates section
	 * show/hide + Live polling to live-view.js.
	 */
	function selectTopTab(target) {
		if (workspaceLockReportId && String(target) !== String(workspaceLockReportId)) return;
		$('#cc-workspace-tabs [data-target]').attr('aria-selected', 'false');
		$('#cc-workspace-tabs [data-target="' + target + '"]').attr('aria-selected', 'true');
		if (window.CCLiveWorkspace) window.CCLiveWorkspace.switchSection(target === 'live' ? 'live' : 'historical');
		if (target === 'live') {
			activeReportId = null;
			return;
		}
		if (target === 'historical') {
			showHistoricalLanding();
			return;
		}
		activateReportTab(target);
	}

	function openNewReportWizard() {
		if (reportCount() >= 5) {
			showReportLimitMessage();
			return;
		}
		var used = {};
		sortedReports().forEach(function (report) { used[report.number] = true; });
		var slot = 1;
		while (used[slot] && slot <= 5) slot++;
		generatedReportName = 'Historic Report ' + slot;
		newWizard(null);
	}

	function closeReportTab(id) {
		if (workspaceLockReportId) return;
		if (!historicalReports[id]) return;
		var closingActive = id === activeReportId;
		ajax({command: 'closehistoricalreport', id: id}).always(function () {
			// Client-side removal proceeds regardless of network result; this
			// is convenience GUI state, not a destructive record the user
			// needs a guaranteed round trip to discard.
		});
		delete historicalReports[id];
		renderTopReportTabs();
		if (!closingActive) return;
		var remaining = sortedReports();
		var nextTarget = remaining.length ? remaining[0].id : 'historical';
		selectTopTab(nextTarget);
		window.setTimeout(function () {
			if (nextTarget === 'historical') $('#cc-launch').trigger('focus');
			else $('#cc-workspace-tabs .cc-report-tab-select[data-target="' + nextTarget + '"]').trigger('focus');
		}, 0);
	}

	function activateReportTab(id) {
		var report = historicalReports[id];
		if (!report) return;
		activeReportId = id;
		ajax({command: 'activatehistoricalreport', id: id});
		$('#cc-report-landing').hide();
		$('#cc-report-active').show();
		$('#cc-report-active .cc-report-global-actions').show();
		if (report.firstRunPending) {
			$('#cc-report-empty, #cc-results').hide();
			$('#cc-report-loading-text').text('Running ' + report.name + '...');
			$('#cc-report-loading').show();
			return;
		}
		if (report.result) {
			$('#cc-report-empty').hide();
			renderResults(report.result);
			restoreOccurrenceState(report);
			$(document).trigger('cc:historical-results', [report.result, report.graphSeries]);
			return;
		}
		regenerateReport(report);
	}

	function regenerateReport(report) {
		$('#cc-status').hide();
		$('#cc-results').hide();
		$('#cc-report-empty').hide();
		$('#cc-report-loading-text').text('Regenerating ' + report.name + '...');
		$('#cc-report-loading').show();
		var rangeSource = report.preset === 'custom'
			? {kind: 'custom', from: report.range_from, to: report.range_to}
			: window.CCDateRange.preset(report.preset, new Date());
		var candidate = {
			kind: rangeSource.kind || report.preset, from: rangeSource.from, to: rangeSource.to,
			includeTime: !!report.include_time, fromTime: report.from_time || '00:00', toTime: report.to_time || '23:59'
		};
		try {
			var canonical = window.CCDateRange.resolve(candidate, new Date());
			wizardTargetReportId = report.id;
			runTargetReportId = report.id;
			executeRun(report.mode, canonical.start, canonical.end, null, report.engine);
		} catch (error) {
			$('#cc-report-loading').hide();
			$('#cc-report-empty').show();
			setStatus((pendingPersistedRefresh ? 'The saved change remains active, but ' : '') + 'Unable to regenerate ' + report.name + ': ' + (error.message || 'invalid saved date range.'), 'error');
			pendingPersistedRefresh = false;
		}
	}

	function persistReportDefinition(id, definition) {
		if (!historicalReports[id]) return;
		ajax($.extend({command: 'updatehistoricalreport', id: id}, definition)).done(function (response) {
			if (response.status && response.report) {
				historicalReports[id] = $.extend(historicalReports[id], response.report);
				renderTopReportTabs();
			}
		});
	}

	function restoreOccurrenceState(report) {
		Object.keys(report.occurrenceCache || {}).forEach(function (key) {
			var cached = report.occurrenceCache[key];
			var parts = key.split(':');
			var button = $('.cc-occurrence-toggle[data-name-index="' + parts[0] + '"][data-occurrence-index="' + parts[1] + '"]');
			if (!button.length) return;
			var detail = button.closest('.cc-occurrence').find('.cc-occurrence-detail');
			if (cached.detail) {
				detail.data('loaded', true).data('detail', cached.detail).html(renderPeakCalls(cached.detail));
			}
			if (cached.expanded) {
				detail.show();
				setOccurrenceExpanded(button, true);
			}
		});
	}

	/* ---------- Wizard state machine ---------- */

	function newWizard(reportId) {
		wizardTargetReportId = reportId || null;
		var report = reportId ? historicalReports[reportId] : null;
		wizardState = {mode: report ? report.mode : 'trunk', engine: report ? report.engine : 'original'};
		$('#cc-report-name').val(report ? report.name : generatedReportName);
		$('#cc-engine').val(report ? report.engine : 'original');
		selectMode(report ? report.mode : 'trunk');
		$('#cc-engine-group, #cc-wizard-mode-group').show();
		$('#cc-results').hide();
		setStatus('', null);
		updateModeDescription();
		applyDatePreset(report ? report.preset : 'last7');
		showWizard();
	}

	function updateModeDescription() {
		var mode = selectedMode();
		$('.cc-mode-option').removeClass('is-selected');
		$('input[name="cc-wizard-mode"]:checked').closest('.cc-mode-option').addClass('is-selected');
		$('#cc-mode-description').text(modeDescriptions[mode] || 'Choose what the report should measure.');
	}

	function applyDatePreset(kind) {
		if (kind === 'custom') {
			guiRange.kind = 'custom';
			$('#cc-custom-dates').show();
		} else {
			guiRange = window.CCDateRange.preset(kind, new Date());
			guiRange.includeTime = $('#cc-include-time').is(':checked');
			guiRange.fromTime = $('#cc-time-from').val() || '00:00';
			guiRange.toTime = $('#cc-time-to').val() || '23:59';
			$('#cc-custom-dates').hide();
		}
		$('.cc-date-preset').removeClass('active').filter('[data-preset="' + kind + '"]').addClass('active');
		updateDateRangeControls();
	}

	function updateDateRangeControls() {
		if (!guiRange) return;
		$('#cc-date-from').val(guiRange.from);
		$('#cc-date-to').val(guiRange.to);
		$('#cc-range-label').text(formatDisplayDate(guiRange.from) + ' - ' + formatDisplayDate(guiRange.to));
		var today = window.CCDateRange.dateOnly(new Date());
		$('.cc-range-shift[data-direction="1"]').prop('disabled', guiRange.to >= today);
		$('#cc-time-controls').toggle($('#cc-include-time').is(':checked'));
	}

	function readCustomRange() {
		var from = $('#cc-date-from').val();
		var to = $('#cc-date-to').val();
		if (!window.CCDateRange.parseDate(from) || !window.CCDateRange.parseDate(to) || to < from) {
			showError('Choose a valid From and To date.');
			return false;
		}
		guiRange.from = from;
		guiRange.to = to;
		updateDateRangeControls();
		clearError();
		return true;
	}

	function formatDisplayDate(value) {
		var date = window.CCDateRange.parseDate(value);
		return date ? date.toLocaleDateString([], {day: 'numeric', month: 'short', year: 'numeric'}) : value;
	}

	function askMode() {
		wizardState.step = 'mode';
		wizardState.attempts = 0;
		$('#cc-wizard-value').closest('.form-group').hide();
		$('#cc-wizard-mode-group').show();
		selectMode('trunk');
		clearError();
		setTimeout(function () { $('#cc-mode-trunk').focus(); }, 100);
	}

	function askMonth() {
		wizardState.step = 'month';
		wizardState.attempts = 0;
		setStep(
			'Type a month, <code>today</code>, <code>yesterday</code>, or leave blank for a custom date range:',
			'Examples: April, 4, today, yesterday, or leave blank.',
			'(month name or blank)'
		);
	}

	function askYear() {
		wizardState.step = 'year';
		wizardState.attempts = 0;
		setStep(
			'Type year for ' + escapeHtml(wizardState.month.name) + ' (YYYY, YY or Y):',
			'Examples: 2025, 25, 5.',
			'25'
		);
	}

	function askStartDate() {
		wizardState.step = 'startdate';
		wizardState.attempts = 0;
		setStep(
			'Enter start date/time:',
			'Format: YYYY-MM-DD HH:MM:SS, YYYY-MM-DD, YYYY-MM, YYYY, YY or Y. Blank = year 2000.',
			'2025-04-01 00:00:00'
		);
	}

	function askEndDate() {
		wizardState.step = 'enddate';
		wizardState.attempts = 0;
		setStep(
			'Enter end date/time:',
			'Format: YYYY-MM-DD HH:MM:SS, YYYY-MM-DD, YYYY-MM, YYYY, YY or Y. Blank = now.',
			'2025-04-30 23:59:59'
		);
	}

	function submitStep() {
		if (!guiRange || (guiRange.kind === 'custom' && !readCustomRange())) return;
		var reportName = $.trim($('#cc-report-name').val());
		if (!reportName || reportName.length > 80) {
			showError(reportName ? 'Report name must be 80 characters or fewer.' : 'Enter a report name.');
			return;
		}
		guiRange.includeTime = $('#cc-include-time').is(':checked');
		guiRange.fromTime = $('#cc-time-from').val() || '00:00';
		guiRange.toTime = $('#cc-time-to').val() || '23:59';
		try {
			var canonical = window.CCDateRange.resolve(guiRange, new Date());
			wizardState.mode = selectedMode();
			if (wizardTargetReportId) {
				hideWizard();
				runTargetReportId = wizardTargetReportId;
				executeRun(wizardState.mode, canonical.start, canonical.end);
				return;
			}
			createAndRunReport(reportName, canonical);
		} catch (error) {
			showError(error.message || 'Choose a valid date range.');
		}
	}

	function createAndRunReport(reportName, canonical) {
		var engine = $('#cc-engine').val() || 'original';
		var definition = {
			command: 'createhistoricalreport', name: reportName,
			generated_default_name: reportName === generatedReportName ? '1' : '',
			mode: wizardState.mode, engine: engine, preset: guiRange.kind,
			range_from: guiRange.from, range_to: guiRange.to,
			include_time: guiRange.includeTime ? '1' : '', from_time: guiRange.fromTime, to_time: guiRange.toTime,
			filter: ''
		};
		$('#cc-wizard-next').prop('disabled', true);
		ajax(definition).done(function (response) {
			if (!response.status) {
				showError(response.message || 'Unable to create historical report.');
				return;
			}
			var report = $.extend({result: null, hasRun: false, occurrenceCache: {}, graphSeries: null, firstRunPending: true}, response.report);
			historicalReports[report.id] = report;
			hideWizard();
			renderTopReportTabs();
			selectTopTab(report.id);
			runTargetReportId = report.id;
			executeRun(report.mode, canonical.start, canonical.end, null, report.engine);
		}).fail(function () {
			showError(randomOops());
		}).always(function () {
			$('#cc-wizard-next').prop('disabled', false);
		});
	}

	function discardFailedFirstRun(targetReportId, message) {
		var report = targetReportId ? historicalReports[targetReportId] : null;
		if (!report || !report.firstRunPending) return false;
		if (report.firstRunCleanupAttempted) return true;
		report.firstRunCleanupAttempted = true;
		$('#cc-report-loading').hide();
		setStatus(message + ' Removing the unused saved report...', 'warning');
		ajax({command: 'closehistoricalreport', id: targetReportId}).done(function (response) {
			if (!response.status) {
				failedFirstRunCleanup(report, message, response.message);
				return;
			}
			delete historicalReports[targetReportId];
			renderTopReportTabs();
			selectTopTab('historical');
			$('#cc-report-limit-message').text(message + ' The unused report was removed.').show();
		}).fail(function () {
			failedFirstRunCleanup(report, message, 'The cleanup request failed.');
		});
		return true;
	}

	function failedFirstRunCleanup(report, runMessage, cleanupMessage) {
		// Keep the saved report visible and addressable by its stable id. It can
		// be closed manually, and selecting it later uses the established retry path.
		report.firstRunPending = false;
		renderTopReportTabs();
		setStatus(runMessage + ' Its saved report definition could not be cleaned up. ' + (cleanupMessage || 'Close the report tab manually.'), 'warning');
	}

	function handleStepSuccess(resp) {
		clearError();
		switch (wizardState.step) {
			case 'mode':
				wizardState.mode = resp.value;
				$('#cc-engine-group').show();
				askMonth();
				break;
			case 'month':
				wizardState.month = resp.month;
				askYear();
				break;
			case 'year':
				wizardState.year = resp.year;
				hideWizard();
				resolveAndRun({
					kind: 'month',
					month: wizardState.month.name,
					// Defensive cast: PHP json_encode of a numeric year becomes a
					// JS number, which would still work in this case but breaks
					// if anyone later passes it to a string-only API. Force string.
					year: String(wizardState.year)
				});
				break;
			case 'startdate':
				wizardState.start_date = resp.value;
				askEndDate();
				break;
			case 'enddate':
				wizardState.end_date = resp.value;
				hideWizard();
				resolveAndRun({
					kind: 'custom',
					start: wizardState.start_date,
					end: wizardState.end_date
				});
				break;
		}
	}

	/* ---------- Run ---------- */

	function resolveAndRun(payload) {
		var mode = wizardState.mode;
		var start, end;

		if (payload.kind === 'today') {
			var t = isoNow();
			start = t.split(' ')[0] + ' 00:00:00';
			end = t;
			executeRun(mode, start, end);
		} else if (payload.kind === 'yesterday') {
			var y = yesterdayIso();
			start = y + ' 00:00:00';
			end = y + ' 23:59:59';
			executeRun(mode, start, end);
		} else if (payload.kind === 'month') {
			// Re-query the server for normalised month/year, then build
			// canonical dates. This mirrors the bash logic exactly: current
			// month + current year stops at "now", otherwise end-of-month.
			$.when(
				ajax({command: 'wizardstep', step: 'month', value: payload.month}),
				ajax({command: 'wizardstep', step: 'year', value: payload.year})
			).done(function (mResp, yResp) {
				var m = mResp[0], y = yResp[0];
				if (!m.status || !y.status) {
					setStatus((m && m.message) || (y && y.message) || 'Validation failed.', 'error');
					return;
				}
				var today = new Date();
				var s, e;
				if (parseInt(m.month.num, 10) === (today.getMonth() + 1) && y.year === today.getFullYear()) {
					s = y.year + '-' + m.month.num + '-01 00:00:00';
					e = isoNow();
				} else {
					s = y.year + '-' + m.month.num + '-01 00:00:00';
					e = lastDayOfMonth(y.year, m.month.num) + ' 23:59:59';
				}
				executeRun(mode, s, e);
			}).fail(function () {
				setStatus(randomOops(), 'error');
			});
		} else if (payload.kind === 'custom') {
			executeRun(mode, payload.start, payload.end);
		}
	}

	function isoNow() {
		var d = new Date();
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
			' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
	}
	function yesterdayIso() {
		var d = new Date();
		d.setDate(d.getDate() - 1);
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}
	function pad(n) { return n < 10 ? '0' + n : '' + n; }
	function lastDayOfMonth(year, monthNum) {
		var m = parseInt(monthNum, 10);
		var d = new Date(year, m, 0);
		return year + '-' + pad(m) + '-' + pad(d.getDate());
	}

	function formatTelemetryBytes(bytes) {
		if (bytes === null || bytes === undefined || bytes === '') return '--';
		bytes = Number(bytes);
		if (!isFinite(bytes) || bytes < 0) return '--';
		var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		var unit = 0;
		while (bytes >= 1024 && unit < units.length - 1) { bytes /= 1024; unit++; }
		return (unit === 0 ? Math.round(bytes) : bytes.toFixed(1)) + ' ' + units[unit];
	}

	function telemetryUsage(metric) {
		if (!metric) return '--';
		var hasUsed = metric.used_bytes !== null && metric.used_bytes !== undefined && isFinite(Number(metric.used_bytes));
		var hasTotal = metric.total_bytes !== null && metric.total_bytes !== undefined && isFinite(Number(metric.total_bytes));
		var hasPercent = metric.percent !== null && metric.percent !== undefined && isFinite(Number(metric.percent));
		if (!hasUsed && !hasTotal && !hasPercent) return '--';
		var usage = formatTelemetryBytes(metric.used_bytes) + ' / ' + formatTelemetryBytes(metric.total_bytes);
		return usage + (hasPercent ? ' (' + Number(metric.percent).toFixed(1) + '%)' : '');
	}

	function renderCalculationTelemetry(response) {
		var resources = response.resources || {};
		var cpu = resources.cpu || {};
		var memory = resources.memory || {};
		var swap = resources.swap || null;
		var disk = resources.disk || {};
		$('#cc-telemetry-cpu-label').text(cpu.label || 'System load (5 min)');
		$('#cc-telemetry-memory-label').text(memory.label || 'Memory (applications)');
		$('#cc-telemetry-disk-label').text(disk.label || 'Disk (/)');
		var load = cpu.value !== null && cpu.value !== undefined && isFinite(Number(cpu.value)) ? Number(cpu.value).toFixed(2) : '--';
		if (load !== '--' && cpu.logical_cpus !== null && cpu.logical_cpus !== undefined && Number(cpu.logical_cpus) > 0) load += ' across ' + Number(cpu.logical_cpus) + (Number(cpu.logical_cpus) === 1 ? ' CPU' : ' CPUs');
		$('#cc-telemetry-cpu').text(load);
		$('#cc-telemetry-memory').text(telemetryUsage(memory));
		$('#cc-telemetry-swap-item').toggle(!!swap);
		if (swap) {
			$('#cc-telemetry-swap-label').text(swap.label || 'Swap');
			$('#cc-telemetry-swap').text(telemetryUsage(swap));
		}
		$('#cc-telemetry-disk').text(telemetryUsage(disk));
	}

	function monotonicNow() {
		return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
	}

	function renderCalculationTimers(run) {
		if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id || !run.timerState) return;
		var timers = window.CCTelemetryFormat.snapshot(run.timerState, monotonicNow());
		$('#cc-telemetry-elapsed').text(window.CCTelemetryFormat.duration(timers.elapsed));
		$('#cc-telemetry-runtime').text(window.CCTelemetryFormat.duration(timers.runtimeRemaining));
		$('#cc-telemetry-eta').text(window.CCTelemetryFormat.eta(timers.etaReliable, timers.etaRemaining));
	}

	function scheduleCalculationTimerRender(run) {
		if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id) return;
		renderCalculationTimers(run);
		run.timerRenderTimer = window.setTimeout(function () { scheduleCalculationTimerRender(run); }, 1000);
	}

	function synchronizeCalculationTimers(run, response) {
		run.timerState = window.CCTelemetryFormat.synchronize(run.timerState, response, monotonicNow());
		renderCalculationTimers(run);
	}

	function stopCalculationTimerRenderer(run) {
		if (run && run.timerRenderTimer) window.clearTimeout(run.timerRenderTimer);
		if (run) { run.timerRenderTimer = null; run.timerState = null; }
	}

	function pollCalculationTelemetry(run) {
		if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id) return;
		run.telemetryRequest = ajax({command: 'calculationtelemetry', calculation_id: run.id}, {global: false}).done(function (response) {
			if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id) return;
			if (response.status && response.active) {
				synchronizeCalculationTimers(run, response);
				renderCalculationTelemetry(response);
			}
		}).fail(function (request, textStatus, errorThrown) {
			if (window.CCHistoricalRunState.shouldReportFailure(run, textStatus)) reportUnexpectedHistoricalAjaxFailure(request, textStatus, errorThrown);
		}).always(function () {
			run.telemetryRequest = null;
			if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id) return;
			run.telemetryTimer = window.setTimeout(function () { pollCalculationTelemetry(run); }, 2000);
		});
	}

	function startCalculationTelemetry(run) {
		$('#cc-telemetry-cpu, #cc-telemetry-memory, #cc-telemetry-swap, #cc-telemetry-disk').text('--');
		$('#cc-telemetry-swap-item').hide();
		$('#cc-telemetry-elapsed').text('00:00:00');
		$('#cc-telemetry-runtime').text('01:00:00');
		$('#cc-telemetry-eta').text('Estimating...');
		$('#cc-calculation-panel-title').text('Calculating...');
		$('#cc-calculation-stop').prop('disabled', false);
		$('#cc-excluded-calls').prop('disabled', true).attr('aria-disabled', 'true');
		$('#cc-report-loading').show();
		$('#cc-calculation-panel').show();
		run.timerState = window.CCTelemetryFormat.synchronize(null, {elapsed: 0, runtime_remaining: 3600, eta_reliable: false, estimated_remaining: null}, monotonicNow());
		scheduleCalculationTimerRender(run);
		pollCalculationTelemetry(run);
	}

	function pollCalculationHeartbeat(run) {
		if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id) return;
		run.heartbeatRequest = ajax({command: 'calculationheartbeat', calculation_id: run.id}, {global: false}).always(function () {
			run.heartbeatRequest = null;
			if (!activeCalculation || activeCalculation.sequence !== run.sequence || activeCalculation.id !== run.id) return;
			run.heartbeatTimer = window.setTimeout(function () { pollCalculationHeartbeat(run); }, calculationHeartbeatInterval);
		});
	}

	function stopCalculationHeartbeat(run) {
		if (run && run.heartbeatTimer) window.clearTimeout(run.heartbeatTimer);
		if (run && run.heartbeatRequest && typeof run.heartbeatRequest.abort === 'function') run.heartbeatRequest.abort();
		if (run) { run.heartbeatTimer = null; run.heartbeatRequest = null; }
	}

	function sendAbandonmentCancellation(run) {
		if (!run || run.abandonmentSent) return;
		run.abandonmentSent = true;
		run.stopping = true;
		run.intentionalAbortReason = 'abandoned';
		var data = new window.FormData();
		data.append('command', 'cancelcalculation');
		data.append('calculation_id', run.id);
		data.append('token', $('.concurrencycount').attr('data-csrf-token') || '');
		if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
			window.navigator.sendBeacon('ajax.php?module=concurrencycount', data);
		} else if (window.fetch) {
			window.fetch('ajax.php?module=concurrencycount', {method: 'POST', body: data, credentials: 'same-origin', keepalive: true});
		}
	}

	function stopCalculationTelemetry(run) {
		if (run && run.telemetryTimer) window.clearTimeout(run.telemetryTimer);
		if (run && !run.intentionalAbortReason) run.intentionalAbortReason = 'terminal';
		if (run && run.telemetryRequest && typeof run.telemetryRequest.abort === 'function') run.telemetryRequest.abort();
		if (run) { run.telemetryTimer = null; run.telemetryRequest = null; }
		stopCalculationTimerRenderer(run);
		if (!activeCalculation || !run || activeCalculation.sequence === run.sequence) $('#cc-calculation-panel').hide();
	}

	function finishCalculationUi(run) {
		stopCalculationHeartbeat(run);
		setHistoricalWorkspaceLocked(false);
		restoreExcludedCallsAfterCalculation();
	}

	function restoreExcludedCallsAfterCalculation() {
		$('#cc-excluded-calls').prop('disabled', false).removeAttr('aria-disabled');
	}

	function executeRun(mode, start, end, extraParams, engineOverride) {
		if (activeCalculation) return;
		var targetReportId = runTargetReportId;
		var selectedEngine = engineOverride || $('#cc-engine').val() || 'original';
		var run = {id: newCalculationId(), sequence: ++calculationSequence, targetReportId: targetReportId, request: null, stopping: false, intentionalAbortReason: null, telemetryTimer: null, telemetryRequest: null, timerState: null, timerRenderTimer: null, heartbeatTimer: null, heartbeatRequest: null, abandonmentSent: false};
		activeCalculation = run;
		if (targetReportId) selectTopTab(targetReportId);
		setHistoricalWorkspaceLocked(true, targetReportId);
		if (mode === 'demo') {
			setStatus('Creating temporary demo CDR rows and counting from ' + start + ' to ' + end + '...', 'running');
		} else {
			setStatus('Counting PJSIP ' + mode + ' call data from ' + start + ' to ' + end + '. This may take a while on busy systems...', 'running');
		}
		startCalculationTelemetry(run);
		pollCalculationHeartbeat(run);
		$('#cc-results').hide();

		var params = {command: 'run', mode: mode, start_date: start, end_date: end, calculation_id: run.id};
		if (targetReportId && historicalReports[targetReportId]) params.filter = historicalReports[targetReportId].filter || '';
		if (mode !== 'demo') {
			params.engine = selectedEngine;
		}
		if (extraParams) {
			$.extend(params, extraParams);
		}
		run.request = ajax(params, {global: false}).done(function (resp) {
			if (run.stopping || !activeCalculation || activeCalculation.sequence !== run.sequence) return;
			if (resp.overrun_warning) {
				stopCalculationTelemetry(run);
				activeCalculation = null;
				finishCalculationUi(run);
				showOverrunModal(resp, mode, start, end, extraParams, targetReportId, selectedEngine);
				return;
			}
			stopCalculationTelemetry(run);
			activeCalculation = null;
			finishCalculationUi(run);
			if (resp.admission_busy) {
				var waitingReport = targetReportId && historicalReports[targetReportId] ? historicalReports[targetReportId] : null;
				if (waitingReport) waitingReport.firstRunPending = false;
				restoreStoppedReport(targetReportId);
				setStatus(resp.message || 'A previous Historical calculation is still stopping. Please try again shortly.', 'warning');
				return;
			}
			if (resp.cancelled) {
				restoreStoppedReport(targetReportId);
				setStatus('Calculation stopped.', 'warning');
				return;
			}
			if (resp.resource_limit) {
				if (discardFailedFirstRun(targetReportId, resp.message || 'Historical calculation stopped.')) return;
				restoreStoppedReport(targetReportId);
				setStatus('Historical calculation stopped. ' + (resp.message || '') + ' ' + (resp.advice || ''), 'error');
				pendingPersistedRefresh = false;
				return;
			}
			if (!resp.status) {
				if (discardFailedFirstRun(targetReportId, resp.message || 'Failed to run.')) return;
				setStatus((pendingPersistedRefresh ? 'The saved change remains active, but the report could not be refreshed. ' : '') + (resp.message || 'Failed to run.'), 'error');
				pendingPersistedRefresh = false;
				return;
			}
			setStatus('Count complete. ' + resp.results.rows_processed + ' rows processed.', 'success');
			applyReportResult(targetReportId, resp.results, selectedEngine);
		}).fail(function (request, textStatus, errorThrown) {
			if (!window.CCHistoricalRunState.shouldReportFailure(run, textStatus)) return;
			reportUnexpectedHistoricalAjaxFailure(request, textStatus, errorThrown);
			if (!activeCalculation || activeCalculation.sequence !== run.sequence) return;
			stopCalculationTelemetry(run);
			activeCalculation = null;
			finishCalculationUi(run);
			if (discardFailedFirstRun(targetReportId, randomOops())) return;
			setStatus((pendingPersistedRefresh ? 'The saved change remains active, but the report could not be refreshed. ' : '') + randomOops(), 'error');
			pendingPersistedRefresh = false;
		});
	}

	function restoreStoppedReport(targetReportId) {
		var report = targetReportId && historicalReports[targetReportId] ? historicalReports[targetReportId] : null;
		if (report) report.firstRunPending = false;
		if (targetReportId !== null && targetReportId !== activeReportId) return;
		$('#cc-report-loading').hide();
		if (report && report.result) {
			$('#cc-report-empty').hide();
			renderResults(report.result);
			restoreOccurrenceState(report);
		} else $('#cc-report-empty').show();
	}

	function stopActiveCalculation() {
		var run = activeCalculation;
		if (!run || run.stopping) return;
		run.stopping = true;
		run.intentionalAbortReason = 'stop';
		$('#cc-calculation-stop').prop('disabled', true);
		$('#cc-calculation-panel-title').text('Stopping calculation...');
		$('#cc-report-loading-text').text('');
		setStatus('Stopping calculation...', 'running');
		ajax({command: 'cancelcalculation', calculation_id: run.id}).done(function (response) {
			if (!window.CCHistoricalRunState.cancellationAcknowledged(response, run)) {
				if (!activeCalculation || activeCalculation.sequence !== run.sequence) return;
				run.stopping = false;
				run.intentionalAbortReason = null;
				$('#cc-calculation-stop').prop('disabled', false);
				$('#cc-calculation-panel-title').text('Calculation still running');
				setStatus(response && response.message ? response.message : 'Unable to confirm cancellation. The report remains open.', 'error');
				return;
			}
			var ownsActiveCalculation = activeCalculation && activeCalculation.id === run.id && activeCalculation.sequence === run.sequence;
			stopCalculationTelemetry(run);
			if (ownsActiveCalculation) {
				activeCalculation = null;
				finishCalculationUi(run);
			}
			if (!ownsActiveCalculation && activeCalculation && activeCalculation.targetReportId === run.targetReportId) {
				var replacementForClosingReport = activeCalculation;
				replacementForClosingReport.stopping = true;
				replacementForClosingReport.intentionalAbortReason = 'superseded';
				ajax({command: 'cancelcalculation', calculation_id: replacementForClosingReport.id});
				if (replacementForClosingReport.request && typeof replacementForClosingReport.request.abort === 'function') replacementForClosingReport.request.abort();
				stopCalculationTelemetry(replacementForClosingReport);
				stopCalculationHeartbeat(replacementForClosingReport);
				activeCalculation = null;
				finishCalculationUi(replacementForClosingReport);
			}
			if (run.targetReportId && historicalReports[run.targetReportId]) closeReportTab(run.targetReportId);
			else selectTopTab('historical');
		}).fail(function () {
			if (!activeCalculation || activeCalculation.sequence !== run.sequence) return;
			run.stopping = false;
			run.intentionalAbortReason = null;
			$('#cc-calculation-stop').prop('disabled', false);
			$('#cc-calculation-panel-title').text('Calculation still running');
			setStatus('Unable to confirm cancellation. The report remains open.', 'error');
		});
		if (run.request && typeof run.request.abort === 'function') run.request.abort();
	}

	/**
	 * Attaches a freshly calculated result to the report instance that
	 * requested it (captured before the AJAX round trip), persists the
	 * definition that produced it, and only repaints the shared DOM surface
	 * if that report is still the one visibly active.
	 */
	function applyReportResult(targetReportId, results, engineUsed) {
		pendingPersistedRefresh = false;
		if (targetReportId && historicalReports[targetReportId]) {
			var report = historicalReports[targetReportId];
			report.result = results;
			report.hasRun = true;
			report.firstRunPending = false;
			report.occurrenceCache = {};
			report.graphSeries = null;
			report.mode = results.mode === 'demo' ? report.mode : results.mode;
			report.engine = engineUsed;
			persistReportDefinition(targetReportId, {
				mode: report.mode, engine: report.engine, preset: report.preset,
				range_from: report.range_from, range_to: report.range_to,
				include_time: report.include_time ? '1' : '', from_time: report.from_time, to_time: report.to_time,
				filter: report.filter || '', name: report.name
			});
		}
		if (targetReportId === null || targetReportId === activeReportId) {
			historicalGraphTargetReportId = targetReportId;
			renderResults(results);
			$(document).trigger('cc:historical-results', [results, null]);
		}
	}

	function showOverrunModal(resp, mode, start, end, extraParams, targetReportId, selectedEngine) {
		var est = formatTime(resp.estimated_remaining);
		var left = formatTime(resp.runtime_remaining);
		$('#cc-overrun-message').text(
			'There is a lot to count. Based on progress so far, this report may exceed the maximum calculation runtime. ' +
			'Estimated time remaining: ' + est + '. Maximum runtime remaining: ' + left + '.'
		);
		var modal = $('#cc-overrun');

		// Re-bind each open so we don't accumulate handlers across multiple
		// overrun prompts in one session.
		$('#cc-overrun-yes').off('click').on('click', function () {
			modal.modal('hide');
			setStatus('Continuing despite estimated overrun...', 'running');
			runTargetReportId = targetReportId;
			executeRun(mode, start, end, $.extend({}, extraParams || {}, {confirm_overrun: '1'}), selectedEngine);
		});
		$('#cc-overrun-no').off('click').on('click', function () {
			modal.modal('hide');
			if (discardFailedFirstRun(targetReportId, 'Report run was cancelled.')) return;
			setStatus('Aborting as per user request.', 'warning');
		});

		modal.modal('show');
	}

	function formatTime(seconds) {
		seconds = parseInt(seconds, 10) || 0;
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return m + ' minutes ' + s + ' seconds';
	}

	function formatMs(ms) {
		ms = parseInt(ms, 10) || 0;
		return (ms / 1000).toFixed(2) + 's';
	}

	function formatBytes(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		if (bytes >= 1048576) return Math.round(bytes / 1048576) + 'MB';
		if (bytes >= 1024) return Math.round(bytes / 1024) + 'KB';
		return bytes + 'B';
	}

	function formatNumber(n) {
		n = parseInt(n, 10) || 0;
		return n.toLocaleString();
	}

	function formatDecimal(n) {
		n = parseFloat(n) || 0;
		return n.toFixed(2).replace(/\.?0+$/, '');
	}

	/* ---------- Download / email ---------- */

	function onDownload() {
		if (!finalMode) return;
		var params = {
			module: 'concurrencycount', command: 'download',
			mode: finalMode, start_date: finalStart, end_date: finalEnd,
			filter: activeReportId && historicalReports[activeReportId] ? (historicalReports[activeReportId].filter || '') : '',
			token: $('.concurrencycount').attr('data-csrf-token') || ''
		};
		if (finalMode === 'demo') {
			params.demo_report = finalDemoReport || 'extension';
			params.demo_size = finalDemoSize || 'light';
			params.demo_rows = $('#cc-results-body').data('demoRows') || '';
			params.demo_seed = finalDemoSeed || '0';
			params.demo_engines = finalDemoEngines.join(',');
		} else {
			params.engine = finalEngine || 'original';
		}
		var qs = $.param(params);
		window.location.href = 'ajax.php?' + qs;
	}

	function onDownloadCdr() {
		if (finalMode !== 'demo') return;
		var qs = $.param({
			module: 'concurrencycount', command: 'previewfixture',
			mode: 'demo', start_date: finalStart, end_date: finalEnd,
			token: $('.concurrencycount').attr('data-csrf-token') || '',
			demo_report: finalDemoReport || 'extension',
			demo_size: finalDemoSize || 'light',
			demo_rows: $('#cc-results-body').data('demoRows') || '',
			demo_seed: finalDemoSeed || '0'
		});
		window.location.href = 'ajax.php?' + qs;
	}

	function onEmailToggle() {
		$('#cc-email-row').toggle();
	}

	function onEmailSend() {
		var to = $('#cc-email').val().trim();
		if (!to) {
			setStatus('Enter an email address.', 'error');
			return;
		}
		setStatus('Generating report and sending email...', 'running');
		var params = {
			command: 'email', mode: finalMode,
			start_date: finalStart, end_date: finalEnd, email: to
		};
		params.filter = activeReportId && historicalReports[activeReportId] ? (historicalReports[activeReportId].filter || '') : '';
		if (finalMode === 'demo') {
			params.demo_report = finalDemoReport || 'extension';
			params.demo_size = finalDemoSize || 'light';
			params.demo_rows = $('#cc-results-body').data('demoRows') || '';
			params.demo_seed = finalDemoSeed || '0';
			params.demo_engines = finalDemoEngines.join(',');
		} else {
			params.engine = finalEngine || 'original';
		}
		ajax(params).done(function (resp) {
			if (resp.status) {
				setStatus(resp.message, 'success');
				$('#cc-email-row').hide();
			} else {
				setStatus(resp.message || 'Failed to send.', 'error');
			}
		}).fail(function () {
			setStatus(randomOops(), 'error');
		});
	}

	/* ---------- Init ---------- */

	$(function () {
		// .off().on() everywhere so re-running this script (or anything that
		// re-runs DOM-ready handlers) doesn't double-bind clicks. Lifted from
		// Frogman's defensive style.
		$('#cc-launch').off('click').on('click', openNewReportWizard);
		$('input[name="cc-wizard-mode"]').off('change').on('change', updateModeDescription);
		$('#cc-demo-launch').off('click').on('click', showDemoPrompt);
		$('#cc-identity-manage').off('click').on('click', openIdentityClassifications);
		$('#cc-excluded-calls').off('click').on('click', openExcludedCalls);
		$('#cc-exclude-call-confirm').off('click').on('click', excludePendingCall);
		$('#cc-restore-all-excluded').off('click').on('click', restoreAllExcludedCalls);
		$('#cc-excluded-calls-rows').off('click.ccExcluded', '.cc-restore-excluded').on('click.ccExcluded', '.cc-restore-excluded', function () { restoreExcludedCall($(this).data('call-identity')); });
		$('#cc-results-body').off('click.ccIdentity', '.cc-classify-endpoint').on('click.ccIdentity', '.cc-classify-endpoint', function () { saveIdentityClassification($(this).data('endpoint'), $(this).data('classification')); });
		$('#cc-identity-rows').off('click.ccIdentity', '.cc-reset-identity').on('click.ccIdentity', '.cc-reset-identity', function () {
			ajax({command: 'resetidentityclassification', endpoint: $(this).data('endpoint')}).done(function (response) { if (response.status) { renderIdentityClassifications(response.classifications || []); invalidateAndRerunReports(); } });
		});
		$('#cc-identity-reset-all').off('click').on('click', function () {
			if (!window.confirm('Reset all remembered PJSIP endpoint classifications?')) return;
			ajax({command: 'resetallidentityclassifications'}).done(function (response) { if (response.status) { renderIdentityClassifications([]); invalidateAndRerunReports(); } });
		});
		$('#cc-workspace-tabs').off('click.ccTabs', '.cc-workspace-tab[data-target]').on('click.ccTabs', '.cc-workspace-tab[data-target]', function (e) {
			if ($(e.target).closest('.cc-report-tab-close').length) return;
			if ($(this).attr('aria-disabled') === 'true' || workspaceLockReportId && String($(this).data('target')) !== String(workspaceLockReportId)) { e.preventDefault(); return; }
			selectTopTab($(this).data('target'));
		}).off('click.ccTabsClose', '.cc-report-tab-close').on('click.ccTabsClose', '.cc-report-tab-close', function (e) {
			e.stopPropagation();
			if (workspaceLockReportId) return;
			closeReportTab($(this).data('report-id'));
		});
		$(document).off('cc:historical-graph-loaded').on('cc:historical-graph-loaded', function (event, series) {
			if (historicalGraphTargetReportId && historicalReports[historicalGraphTargetReportId]) {
				historicalReports[historicalGraphTargetReportId].graphSeries = series;
			}
		});
		$('.cc-demo-run-mode').off('click').on('click', function () {
			runDemo($(this).data('report'));
		});
		$('#cc-demo-entropy').off('mousemove touchmove').on('mousemove', function (e) {
			var off = $(this).offset();
			stirDemoSeed(Math.floor(e.pageX - off.left), Math.floor(e.pageY - off.top));
		}).on('touchmove', function (e) {
			var touch = e.originalEvent.touches && e.originalEvent.touches[0];
			if (!touch) return;
			var off = $(this).offset();
			stirDemoSeed(Math.floor(touch.pageX - off.left), Math.floor(touch.pageY - off.top));
		});
		$('#cc-wizard-next').off('click').on('click', submitStep);
		$('.cc-date-preset').off('click').on('click', function () {
			applyDatePreset($(this).data('preset'));
		});
		$('.cc-range-shift').off('click').on('click', function () {
			if (guiRange && (guiRange.kind !== 'custom' || readCustomRange())) {
				guiRange = window.CCDateRange.shift(guiRange, parseInt($(this).data('direction'), 10), new Date());
				updateDateRangeControls();
			}
		});
		$('#cc-date-from, #cc-date-to').off('change').on('change', readCustomRange);
		$('#cc-include-time').off('change').on('change', function () {
			if (guiRange) guiRange.includeTime = $(this).is(':checked');
			updateDateRangeControls();
		});
		$('#cc-wizard-cancel').off('click').on('click', function () {
			setStatus('Session aborted.', 'warning');
		});
		$('#cc-wizard-value').off('keydown').on('keydown', function (e) {
			if (e.which === 13) {
				e.preventDefault();
				submitStep();
			}
		});

		$('#cc-download').off('click').on('click', onDownload);
		$('#cc-download-cdr').off('click').on('click', onDownloadCdr);
		$('#cc-email-toggle').off('click').on('click', onEmailToggle);
		$('#cc-email-send').off('click').on('click', onEmailSend);
		$('#cc-calculation-stop').off('click').on('click', stopActiveCalculation);
		$(window).off('pagehide.ccHistoricalLease').on('pagehide.ccHistoricalLease', function () {
			if (activeCalculation) {
				sendAbandonmentCancellation(activeCalculation);
				stopCalculationTelemetry(activeCalculation);
				stopCalculationHeartbeat(activeCalculation);
			}
		});
		$(document).off('click.ccHistoricalLeave', 'a[href]').on('click.ccHistoricalLeave', 'a[href]', function (event) {
			if (!activeCalculation) return;
			if (event.isDefaultPrevented() || event.which !== 1 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || $(this).is('[download]') || String($(this).attr('target') || '').toLowerCase() === '_blank') return;
			var href = String($(this).attr('href') || '');
			if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href) || /[?&]display=concurrencycount(?:&|$)/.test(href)) return;
			sendAbandonmentCancellation(activeCalculation);
		});
		$('#cc-results-body').off('click', '.cc-occurrence-toggle').on('click', '.cc-occurrence-toggle', function () {
			loadOccurrence($(this));
		}).off('click', '.cc-occurrence-list-toggle').on('click', '.cc-occurrence-list-toggle', function () {
			var button = $(this);
			var expanded = button.attr('aria-expanded') !== 'true';
			button.attr('aria-expanded', expanded ? 'true' : 'false').text(expanded ? 'Show less' : 'Show ' + button.closest('.cc-occurrence-section').find('.cc-additional-occurrences .cc-occurrence').length + ' more');
			button.closest('.cc-occurrence-section').find('.cc-additional-occurrences').prop('hidden', !expanded);
		}).off('click', '.cc-activity-toggle').on('click', '.cc-activity-toggle', function () {
			var button = $(this);
			var expanded = button.attr('aria-expanded') !== 'true';
			button.attr('aria-expanded', expanded ? 'true' : 'false').find('span:first').text(expanded ? 'Hide activity-only results' : 'Show activity-only results');
			button.closest('.cc-activity-only').find('.cc-activity-only-results').prop('hidden', !expanded);
		}).off('click', '.cc-exclude-call').on('click', '.cc-exclude-call', function () {
			confirmExcludeCall($(this));
		}).off('click', '.cc-view-cdr').on('click', '.cc-view-cdr', function () {
			openCdrSearch($(this));
		});

		initHistoricalReports();
	});

})(window.jQuery);

} // end _ccLoaded guard
