/**
 * Concurrency Count wizard.
 *
 * Mirrors the bash CLI flow:
 *   1. Mode (trunks/extensions/group/demo, abbreviations accepted)
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
	var demoSeed = 0;
	var demoMoves = 0;
	var demoPlan = null;

	/**
	 * Use jQuery's DOM round-trip for HTML escaping. Cheaper than chained
	 * replace calls and impossible to get the entity table wrong. Every
	 * value that touches innerHTML goes through here first.
	 */
	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		return $('<div>').text(String(s)).html();
	}

	function ajax(params) {
		return $.ajax({
			url: 'ajax.php?module=concurrencycount',
			method: 'POST',
			data: params,
			dataType: 'json'
		});
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
			'<dt>Range</dt><dd>' + escapeHtml(demoPlan.start) + ' to ' + escapeHtml(demoPlan.end) + '</dd>'
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

	function renderResults(r) {
		finalMode = r.mode;
		finalStart = r.start;
		finalEnd = r.end;
		finalDemoReport = r.demo_report || null;
		finalDemoSize = r.demo_size || null;
		finalDemoSeed = r.demo_seed || null;
		finalEngine = r.engine || 'original';
		finalDemoEngines = r.engines ? Object.keys(r.engines) : [r.engine || 'original'];

		var modeLabel = r.mode.charAt(0).toUpperCase() + r.mode.slice(1);
		$('#cc-results-title').text('Results: ' + modeLabel + ' mode');

		$('#cc-results-meta').html(
			'<dt>From</dt><dd>' + escapeHtml(r.start) + '</dd>' +
			'<dt>To</dt><dd>' + escapeHtml(r.end) + '</dd>' +
			'<dt>Engine</dt><dd>' + escapeHtml(r.engine || (r.engines ? 'comparison' : 'original')) + '</dd>' +
			'<dt>Rows processed</dt><dd>' + escapeHtml(r.rows_processed) + '</dd>'
		);

		var body = $('#cc-results-body');
		body.data('demoRows', r.rows_inserted || '');
		if (r.empty_message) {
			body.html(renderExplanation(r) + '<p class="text-muted">' + escapeHtml(r.empty_message) + '</p>');
		} else if (r.mode === 'demo') {
			renderDemo(body, r);
		} else if (r.mode === 'group') {
			renderGroup(body, r);
		} else {
			renderPerName(body, r);
		}

		$('#cc-results-warning').text(r.warning || '');
		$('#cc-download-cdr').toggle(r.mode === 'demo');
		$('#cc-results').show();
	}

	function renderExplanation(r) {
		var html = '<div class="cc-result-explanation">';
		html += '<h4>What this means</h4>';
		html += '<p>' + escapeHtml(resultExplanationText(r)) + '</p>';
		html += '</div>';
		return html;
	}

	function resultExplanationText(r) {
		var engine = r.engine || (r.engines ? 'comparison' : 'original');
		var overview = r.overview || {};
		if (r.empty_message) {
			return 'No matching answered PJSIP calls were found for this report, so there is no concurrency peak to show.';
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

	function groupExplanation(r, overview, engine) {
		var max = parseInt(r.max_concurrency, 10) || 0;
		var average = parseFloat(overview.average_concurrency) || 0;
		var ratio = parseFloat(overview.peak_to_average_ratio) || 0;
		var peakPercent = parseFloat(overview.peak_period_percent) || 0;
		var text = 'The highest total number of simultaneous extension calls in this date range was ' + max + '.';
		if (average > 0) {
			text += ' Average concurrency across the selected period was ' + formatDecimal(average) + ', so the peak was ' + formatDecimal(ratio) + 'x the average.';
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
		var text = 'The highest simultaneous call count seen on any ' + label + ' in this date range was ' + max + '.';
		if (average > 0) {
			text += ' Across the selected period, average concurrent calls for this report were ' + formatDecimal(average) + ', so the peak was ' + formatDecimal(ratio) + 'x the average.';
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
		html += '<div class="cc-peak-summary">' +
			'Maximum concurrent calls overall: <strong>' + escapeHtml(r.max_concurrency) + '</strong>' +
			'</div>';
		if (r.peak_ranges && r.peak_ranges.length) {
			html += '<h4>Peak time ranges</h4><ul class="cc-peak-ranges">';
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
		if (!names.length) {
			el.html(renderExplanation(r) + '<p class="text-muted">No calls found in the selected date range.</p>');
			return;
		}
		var html = renderExplanation(r);
		html += '<table class="table table-striped"><thead><tr>' +
			'<th>' + escapeHtml(label) + '</th>' +
			'<th>Max concurrent</th>' +
			'</tr></thead><tbody>';
		names.forEach(function (n) {
			var count = r.per_name[n];
			var isPeak = (count === r.global_max && r.global_max > 0);
			html += '<tr' + (isPeak ? ' class="cc-peak-row"' : '') + '>' +
				'<td>' + escapeHtml(n) + '</td>' +
				'<td>' + escapeHtml(count) + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		html += '<div class="cc-peak-summary">Global maximum: <strong>' + escapeHtml(r.global_max) + '</strong></div>';
		el.html(html);
	}

	function renderDemo(el, r) {
		var html = renderExplanation(r);
		html += '<h4>Demo profile</h4>';
		html += '<dl class="dl-horizontal">' +
			'<dt>Run id</dt><dd>' + escapeHtml(r.demo_run_id || '') + '</dd>' +
			'<dt>Report</dt><dd>' + escapeHtml(r.demo_report || '') + '</dd>' +
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
			html += '<table class="table table-striped"><thead><tr><th>Metric</th><th>Expected</th><th>Actual</th></tr></thead><tbody>';
			html += '<tr><td>Maximum concurrent calls overall</td><td>' + escapeHtml(r.expected_max_concurrency) + '</td><td>' + escapeHtml(r.max_concurrency) + '</td></tr>';
			html += '<tr><td>Peak ranges</td><td>' + escapeHtml(formatRanges(r.expected_peak_ranges)) + '</td><td>' + escapeHtml(formatRanges(r.peak_ranges)) + '</td></tr>';
			html += '</tbody></table>';
		} else {
			var label = (r.demo_report === 'trunk') ? 'Trunk' : 'Extension';
			var expected = r.expected_per_name || {};
			html += '<h4>' + escapeHtml(label) + ' accuracy</h4>';
			html += '<table class="table table-striped"><thead><tr><th>' + escapeHtml(label) + '</th><th>Expected</th><th>Actual</th></tr></thead><tbody>';
			Object.keys(expected).forEach(function (n) {
				html += '<tr>' +
					'<td>' + escapeHtml(n) + '</td>' +
					'<td>' + escapeHtml(expected[n]) + '</td>' +
					'<td>' + escapeHtml((r.per_name || {})[n] || 0) + '</td>' +
					'</tr>';
			});
			html += '</tbody></table>';
			html += '<div class="cc-peak-summary">Expected global maximum: <strong>' + escapeHtml(r.expected_global_max) + '</strong> Actual: <strong>' + escapeHtml(r.global_max) + '</strong></div>';
		}
		el.html(html);
	}

	function renderEngineComparison(engines) {
		var html = '<h4>Engine performance</h4>';
		html += '<table class="table table-striped"><thead><tr>' +
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
		html += '</tbody></table>';
		return html;
	}

	function formatRanges(ranges) {
		if (!ranges || !ranges.length) return 'None';
		return ranges.map(function (range) {
			return (range.from === range.to) ? range.from : (range.from + ' to ' + range.to);
		}).join('; ');
	}

	/* ---------- Wizard state machine ---------- */

	function newWizard() {
		wizardState = {
			step: 'mode', attempts: 0,
			mode: null, month: null, year: null,
			start_date: null, end_date: null,
			engine: 'original'
		};
		$('#cc-engine').val('original');
		$('#cc-engine-group').hide();
		$('#cc-results').hide();
		setStatus('', null);
		showWizard();
		askMode();
	}

	function askMode() {
		wizardState.step = 'mode';
		wizardState.attempts = 0;
		setStep(
			'Summarise concurrency by: <code>trunks</code> / <code>extensions</code> / <code>group</code> / <code>demo</code>',
			'Abbreviations accepted (e.g. t, ext, g, d).',
			'trunks'
		);
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
		var value = $('#cc-wizard-value').val();
		var step = wizardState.step;

		// Month prompt shortcuts handled client-side. The server would also
		// catch these via the wizardstep endpoint, but resolving here avoids
		// a round-trip for the common case.
		if (step === 'month') {
			var trimmed = (value || '').trim().toLowerCase();
			if (trimmed === '') {
				askStartDate();
				return;
			}
			if (/^(t|to|tod|toda|today)$/.test(trimmed)) {
				hideWizard();
				resolveAndRun({kind: 'today'});
				return;
			}
			if (/^(y|ye|yes|yest|yeste|yester|yesterd|yesterda|yesterday)$/.test(trimmed)) {
				hideWizard();
				resolveAndRun({kind: 'yesterday'});
				return;
			}
		}

		ajax({command: 'wizardstep', step: step, value: value}).done(function (resp) {
			if (resp.status) {
				handleStepSuccess(resp);
				return;
			}
			wizardState.attempts++;
			if (wizardState.attempts >= MAX_ATTEMPTS) {
				tooManyAttempts();
				return;
			}
			showError(resp.message, wizardState.attempts);
		}).fail(function () {
			showError(randomOops());
		});
	}

	function handleStepSuccess(resp) {
		clearError();
		switch (wizardState.step) {
			case 'mode':
				wizardState.mode = resp.value;
				if (wizardState.mode === 'demo') {
					hideWizard();
					executeRun('demo', '2001-01-01 09:00:00', '2001-01-01 10:00:00');
					break;
				}
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

	function executeRun(mode, start, end, extraParams) {
		var selectedEngine = $('#cc-engine').val() || 'original';
		if (mode === 'demo') {
			setStatus('Creating temporary demo CDR rows and counting from ' + start + ' to ' + end + '...', 'running');
		} else {
			setStatus('Counting PJSIP ' + mode + ' call data from ' + start + ' to ' + end + '. This may take a while on busy systems...', 'running');
		}
		$('#cc-results').hide();

		var params = {command: 'run', mode: mode, start_date: start, end_date: end};
		if (mode !== 'demo') {
			params.engine = selectedEngine;
		}
		if (extraParams) {
			$.extend(params, extraParams);
		}
		ajax(params).done(function (resp) {
			if (resp.overrun_warning) {
				showOverrunModal(resp, mode, start, end, extraParams);
				return;
			}
			if (!resp.status) {
				setStatus(resp.message || 'Failed to run.', 'error');
				return;
			}
			setStatus('Count complete. ' + resp.results.rows_processed + ' rows processed.', 'success');
			renderResults(resp.results);
		}).fail(function () {
			setStatus(randomOops(), 'error');
		});
	}

	function showOverrunModal(resp, mode, start, end, extraParams) {
		var est = formatTime(resp.estimated_remaining);
		var left = formatTime(resp.runtime_remaining);
		$('#cc-overrun-message').text(
			'There is a lot to count. Estimated time remaining is ' + est +
			'. Maximum runtime remaining is ' + left + '.'
		);
		var modal = $('#cc-overrun');

		// Re-bind each open so we don't accumulate handlers across multiple
		// overrun prompts in one session.
		$('#cc-overrun-yes').off('click').on('click', function () {
			modal.modal('hide');
			setStatus('Continuing despite estimated overrun...', 'running');
			var params = {command: 'run', mode: mode, start_date: start, end_date: end, confirm_overrun: '1'};
			if (mode !== 'demo') {
				params.engine = $('#cc-engine').val() || 'original';
			}
			if (extraParams) {
				$.extend(params, extraParams);
			}
			ajax(params).done(function (resp2) {
				if (!resp2.status) {
					setStatus(resp2.message || 'Failed to run.', 'error');
					return;
				}
				setStatus('Count complete. ' + resp2.results.rows_processed + ' rows processed.', 'success');
				renderResults(resp2.results);
			}).fail(function () {
				setStatus(randomOops(), 'error');
			});
		});
		$('#cc-overrun-no').off('click').on('click', function () {
			modal.modal('hide');
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
			mode: finalMode, start_date: finalStart, end_date: finalEnd
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
		$('#cc-launch').off('click').on('click', newWizard);
		$('#cc-demo-launch').off('click').on('click', showDemoPrompt);
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
	});

})(window.jQuery);

} // end _ccLoaded guard
