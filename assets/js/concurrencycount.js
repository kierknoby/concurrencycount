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
		extension: 'Extensions measure overlapping CDRs assigned to each individual numeric PJSIP extension.',
		group: 'Overall measures numeric PJSIP extension-leg activity across the PBX, not a Ring Group or selected member list.'
	};
	var modeLabels = {
		trunk: 'Trunk Concurrency',
		extension: 'Extension Concurrency',
		group: 'Overall Extension Concurrency'
	};

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

	function ajax(params) {
		params = $.extend({}, params, {token: $('.concurrencycount').attr('data-csrf-token') || ''});
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
		html += '<p class="cc-engine-note">' + escapeHtml(engineExplanationText(r)) + '</p>';
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
		var text = 'The highest total number of simultaneous numeric PJSIP extension legs in this date range was ' + max + '. Both numeric sides of one internal CDR can count.';
		if (average > 0) {
			text += ' For calls that started in the selected range, average concurrency within the displayed window was ' + formatDecimal(average) + ', so the observed peak was ' + formatDecimal(ratio) + 'x that average.';
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
		var text = r.mode === 'trunk'
			? 'The highest simultaneous matching trunk-leg count seen on any trunk in this date range was ' + max + '.'
			: 'The highest simultaneous answered-CDR count assigned to any one extension in this date range was ' + max + '.';
		if (average > 0) {
			text += ' For calls that started in the selected range, average concurrency within the displayed window was ' + formatDecimal(average) + ', so the observed peak was ' + formatDecimal(ratio) + 'x that average.';
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
			'Peak overall extension concurrency: <strong>' + escapeHtml(r.max_concurrency) + '</strong>' +
			'<br><span>' + escapeHtml(r.max_concurrency) + ' numeric PJSIP extension legs active simultaneously across the PBX.</span>' +
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
		var peakColumn = r.mode === 'trunk' ? 'Peak trunk concurrency' : 'Peak assigned CDR concurrency';
		html += '<div class="cc-table-scroll"><table class="table table-striped"><thead><tr>' +
			'<th>' + escapeHtml(label) + '</th>' +
			'<th>' + escapeHtml(peakColumn) + '</th>' +
			'</tr></thead><tbody>';
		names.forEach(function (n) {
			var count = r.per_name[n];
			var isPeak = (count === r.global_max && r.global_max > 0);
			var nameIndex = names.indexOf(n);
			var entity = r.mode === 'trunk' && r.trunk_entities ? r.trunk_entities[n] : null;
			var occurrences = r.mode === 'trunk' && r.peak_occurrences && r.peak_occurrences[n] ? r.peak_occurrences[n] : [];
			html += '<tr' + (isPeak ? ' class="cc-peak-row"' : '') + '>' +
				'<td>' + renderEntity(entity, n) + '</td>' +
				'<td><strong>' + escapeHtml(count) + '</strong>' +
				(r.mode === 'trunk' && occurrences.length ? '<br><button type="button" class="btn btn-link cc-show-occurrences" data-name-index="' + nameIndex + '">Peak occurred ' + escapeHtml(occurrences.length) + ' ' + (occurrences.length === 1 ? 'time' : 'times') + '</button>' : '') +
				'</td>' +
				'</tr>';
		});
		html += '</tbody></table></div>';
		if (r.mode === 'trunk') {
			html += renderOccurrenceSections(names, r);
		}
		var peakDetail = r.mode === 'trunk'
			? r.global_max + ' trunk legs active simultaneously at the busiest point.'
			: r.global_max + ' assigned CDRs overlapping at the busiest point for one extension.';
		html += '<div class="cc-peak-summary">Peak ' + escapeHtml(r.mode === 'trunk' ? 'trunk concurrency' : 'assigned extension concurrency') + ': <strong>' + escapeHtml(r.global_max) + '</strong>' +
			'<br><span>' + escapeHtml(peakDetail) + '</span></div>';
		el.html(html);
	}

	function renderOccurrenceSections(names, r) {
		var html = '';
		names.forEach(function (trunk, nameIndex) {
			var occurrences = r.peak_occurrences && r.peak_occurrences[trunk] ? r.peak_occurrences[trunk] : [];
			if (!occurrences.length) return;
			html += '<section class="cc-occurrence-section" data-name-index="' + nameIndex + '" style="display:none">';
			html += '<h4>Simultaneous Call Details: ' + renderEntity(r.trunk_entities ? r.trunk_entities[trunk] : null, trunk) + '</h4>';
			occurrences.forEach(function (occurrence, occurrenceIndex) {
				html += '<div class="panel panel-default cc-occurrence">' +
					'<div class="panel-heading"><button type="button" class="cc-occurrence-toggle" data-name-index="' + nameIndex + '" data-occurrence-index="' + occurrenceIndex + '" aria-expanded="false">' +
					'<span><strong>Peak ' + escapeHtml(occurrence.peak) + ' trunk legs</strong> &middot; ' + escapeHtml(formatClockRange(occurrence.from, occurrence.to)) + ' &middot; lasted ' + escapeHtml(formatDuration(occurrence.duration_seconds)) + '</span>' +
					'<i class="fa fa-chevron-down" aria-hidden="true"></i></button></div>' +
					'<div class="panel-body cc-occurrence-detail" style="display:none"></div>' +
					'</div>';
			});
			html += '</section>';
		});
		return html;
	}

	function renderEntity(entity, fallback) {
		if (!entity || !entity.label) return escapeHtml(fallback || '');
		var label = entity.label;
		if (entity.number && label.indexOf(entity.number) === -1) label += ' (' + entity.number + ')';
		return entity.native_url
			? '<a class="cc-entity-link" href="' + escapeHtml(entity.native_url) + '">' + escapeHtml(label) + '</a>'
			: escapeHtml(label);
	}

	function formatClockRange(from, to) {
		var fromClock = String(from || '').split(' ')[1] || from;
		var toClock = String(to || '').split(' ')[1] || to;
		return fromClock === toClock ? fromClock : fromClock + ' to ' + toClock;
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
		if (detail.data('loaded')) {
			detail.toggle();
			button.attr('aria-expanded', detail.is(':visible') ? 'true' : 'false');
			return;
		}
		if (detail.data('loading')) return;
		detail.data('loading', true);
		button.prop('disabled', true);
		detail.html('<p class="text-muted"><span class="cc-spinner"></span>Loading contributing calls...</p>').show();
		button.attr('aria-expanded', 'true');
		ajax({
			command: 'peakdetails', trunk: trunk,
			start_date: currentResults.start, end_date: currentResults.end,
			occurrence_from: occurrence.from, occurrence_to: occurrence.to
		}).done(function (response) {
			if (!response.status) {
				detail.html('<div class="alert alert-danger">' + escapeHtml(response.message || 'Unable to load call details.') + '</div>');
				return;
			}
			detail.data('loaded', true).data('detail', response.detail).html(renderPeakCalls(response.detail));
		}).fail(function () {
			detail.html('<div class="alert alert-danger">' + escapeHtml(randomOops()) + '</div>');
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
				'<td><button type="button" class="btn btn-default btn-sm cc-view-cdr" data-call-index="' + callIndex + '">View in CDR Reports</button></td></tr>';
		});
		html += '</tbody></table></div>';
		return html;
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
			html += '<h4>Overall extension-leg accuracy</h4>';
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

	/* ---------- Wizard state machine ---------- */

	function newWizard() {
		wizardState = {mode: 'trunk', engine: 'original'};
		$('#cc-engine').val('original');
		selectMode('trunk');
		$('#cc-engine-group, #cc-wizard-mode-group').show();
		$('#cc-results').hide();
		setStatus('', null);
		updateModeDescription();
		applyDatePreset('last7');
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
		guiRange.includeTime = $('#cc-include-time').is(':checked');
		guiRange.fromTime = $('#cc-time-from').val() || '00:00';
		guiRange.toTime = $('#cc-time-to').val() || '23:59';
		try {
			var canonical = window.CCDateRange.resolve(guiRange, new Date());
			wizardState.mode = selectedMode();
			hideWizard();
			executeRun(wizardState.mode, canonical.start, canonical.end);
		} catch (error) {
			showError(error.message || 'Choose a valid date range.');
		}
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
			mode: finalMode, start_date: finalStart, end_date: finalEnd,
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
		$('input[name="cc-wizard-mode"]').off('change').on('change', updateModeDescription);
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
		$('#cc-results-body').off('click', '.cc-show-occurrences').on('click', '.cc-show-occurrences', function () {
			var nameIndex = parseInt($(this).data('name-index'), 10);
			$('.cc-occurrence-section[data-name-index="' + nameIndex + '"]').toggle();
		}).off('click', '.cc-occurrence-toggle').on('click', '.cc-occurrence-toggle', function () {
			loadOccurrence($(this));
		}).off('click', '.cc-view-cdr').on('click', '.cc-view-cdr', function () {
			openCdrSearch($(this));
		});
	});

})(window.jQuery);

} // end _ccLoaded guard
