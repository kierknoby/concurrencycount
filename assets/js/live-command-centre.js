if (!window._ccLiveLoaded) {
window._ccLiveLoaded = true;
(function ($) {
	'use strict';

	var settings = null;
	var snapshot = null;
	var timer = null;
	var request = null;
	var requestSequence = 0;
	var appliedSequence = 0;
	var activeWorkspace = 'live';
	var history = {overall: [], trunks: {}};
	var charts = {overall: null, trunks: {}, historical: null};
	var historicalResult = null;
	var historicalSeries = null;

	function ajax(params) {
		params = $.extend({}, params, {token: $('.concurrencycount').first().attr('data-csrf-token') || $('input[name="token"]').first().val() || ''});
		return $.ajax({url: 'ajax.php?module=concurrencycount', method: 'POST', data: params, dataType: 'json', timeout: 30000});
	}

	function escapeHtml(value) {
		return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
	}

	function initialise() {
		bindEvents();
		loadSettings().always(function () { startPolling(true); });
	}

	function bindEvents() {
		$('.cc-workspace-tab').off('click.ccLive').on('click.ccLive', function () { switchWorkspace($(this).data('target')); });
		$('#cc-live-refresh').off('change.ccLive').on('change.ccLive', function () {
			if (!settings) return;
			settings.refresh_interval = parseInt($(this).val(), 10);
			saveSettings(settings, false);
			startPolling(true);
		});
		$('#cc-live-settings').off('click.ccLive').on('click.ccLive', openSettings);
		$('#cc-settings-save').off('click.ccLive').on('click.ccLive', saveSettingsFromModal);
		$('#cc-monitor-restart').off('click.ccLive').on('click.ccLive', restartMonitor);
		$('#cc-live-overall-value').off('click.ccLive').on('click.ccLive', function () { showCalls('Overall live extension-side activity', snapshot ? snapshot.overall.calls : []); });
		$(document).off('visibilitychange.ccLive').on('visibilitychange.ccLive', onVisibilityChange);
		$(window).off('beforeunload.ccLive').on('beforeunload.ccLive', stopPolling);
		$(document).off('cc:historical-results.ccLive').on('cc:historical-results.ccLive', function (event, result) { loadHistoricalGraph(result); });
	}

	function switchWorkspace(target) {
		activeWorkspace = target === 'historical' ? 'historical' : 'live';
		$('.cc-workspace-tab').removeClass('active btn-primary').addClass('btn-default').attr('aria-selected', 'false');
		$('.cc-workspace-tab[data-target="' + activeWorkspace + '"]').addClass('active btn-primary').removeClass('btn-default').attr('aria-selected', 'true');
		$('#cc-live-section').toggle(activeWorkspace === 'live');
		$('#cc-historical-section').toggle(activeWorkspace === 'historical');
		if (activeWorkspace === 'live') startPolling(true);
		else stopTimer();
	}

	function loadSettings() {
		return ajax({command: 'getsettings'}).done(function (response) {
			if (!response.status) return;
			settings = response.settings;
			$('#cc-live-refresh, #cc-setting-refresh').val(String(settings.refresh_interval));
		}).fail(function () {
			showLiveMessage('Unable to load Live settings. Defaults are being used.', 'warning');
		});
	}

	function startPolling(immediate) {
		stopTimer();
		if (activeWorkspace !== 'live' || document.hidden) return;
		if (immediate) pollLive();
		else scheduleNext();
	}

	function scheduleNext() {
		stopTimer();
		if (activeWorkspace !== 'live' || document.hidden) return;
		var interval = settings ? parseInt(settings.refresh_interval, 10) : 5;
		timer = window.setTimeout(pollLive, Math.max(1, interval) * 1000);
	}

	function pollLive() {
		if (request || activeWorkspace !== 'live' || document.hidden) return;
		var sequence = ++requestSequence;
		request = ajax({command: 'livestatus'}).done(function (response) {
			if (sequence < appliedSequence) return;
			appliedSequence = sequence;
			if (!response.status || !response.snapshot || !response.snapshot.available) {
				markStale(response.snapshot && response.snapshot.message ? response.snapshot.message : (response.message || 'Asterisk live state is unavailable.'));
				return;
			}
			snapshot = response.snapshot;
			appendHistory(snapshot);
			renderSnapshot(snapshot);
		}).fail(function () {
			markStale('Live refresh failed. The last successful values may be stale.');
		}).always(function () {
			request = null;
			scheduleNext();
		});
	}

	function appendHistory(data) {
		appendPoint(history.overall, data.generated_ts, data.overall.current);
		Object.keys(data.trunks).forEach(function (trunk) {
			if (!history.trunks[trunk]) history.trunks[trunk] = [];
			appendPoint(history.trunks[trunk], data.generated_ts, data.trunks[trunk].current);
		});
	}

	function appendPoint(series, timestamp, value) {
		series.push({ts: parseInt(timestamp, 10), value: parseInt(value, 10) || 0});
		var cutoff = parseInt(timestamp, 10) - 900;
		while (series.length && (series[0].ts < cutoff || series.length > 900)) series.shift();
	}

	function renderSnapshot(data) {
		$('#cc-live-message').hide();
		$('#cc-live-content').show();
		$('#cc-live-updated-time').attr('datetime', data.generated_at).text(new Date(data.generated_ts * 1000).toLocaleString());
		updateOverall(data.overall);
		ensureTrunkCards(data.trunks);
		Object.keys(data.trunks).forEach(function (trunk) { updateTrunk(trunk, data.trunks[trunk]); });
	}

	function updateOverall(overall) {
		$('#cc-live-overall-value').text(overall.current);
		$('#cc-live-overall-threshold').text(overall.threshold_enabled ? 'Threshold ' + overall.threshold : 'Threshold off');
		$('#cc-live-overall-peak').text('Recent peak ' + recentPeak(history.overall));
		$('#cc-live-overall-status').text(statusLabel(overall.status));
		$('.cc-live-overall').attr('data-status', overall.status);
		if (!charts.overall) charts.overall = new window.ConcurrencyChart(document.getElementById('cc-live-overall-chart'), {onSelect: function () { showCalls('Current overall extension-side channels', snapshot.overall.calls); }});
		charts.overall.setData(history.overall, overall.threshold_enabled ? overall.threshold : 0);
	}

	function ensureTrunkCards(trunks) {
		var container = $('#cc-live-trunks');
		var names = Object.keys(trunks);
		var existing = container.data('trunks') || [];
		if (JSON.stringify(existing) === JSON.stringify(names)) return;
		Object.keys(charts.trunks).forEach(function (name) { charts.trunks[name].destroy(); });
		charts.trunks = {};
		var html = '';
		names.forEach(function (trunk, index) {
			html += '<article class="cc-live-trunk" data-trunk-index="' + index + '" data-status="normal">' +
				'<div class="cc-live-trunk-header"><div><h4 class="cc-trunk-name">' + escapeHtml(trunk) + '</h4><span class="cc-trunk-split">0 inbound · 0 outbound · 0 unknown</span></div>' +
				'<button type="button" class="cc-trunk-value" data-trunk-index="' + index + '">0</button></div>' +
				'<div class="cc-live-meta"><span class="cc-trunk-threshold">Threshold off</span><span class="cc-trunk-peak">Recent peak 0</span><span class="cc-trunk-status">Normal</span></div>' +
				'<canvas class="cc-trunk-chart" id="cc-trunk-chart-' + index + '" height="110"></canvas></article>';
		});
		container.html(html).data('trunks', names);
		container.find('.cc-trunk-value').off('click.ccLive').on('click.ccLive', function () {
			var name = names[parseInt($(this).data('trunk-index'), 10)];
			showCalls(name + ' current trunk channels', snapshot.trunks[name].calls);
		});
		names.forEach(function (trunk, index) {
			charts.trunks[trunk] = new window.ConcurrencyChart(document.getElementById('cc-trunk-chart-' + index), {onSelect: function () { showCalls(trunk + ' current trunk channels', snapshot.trunks[trunk].calls); }});
		});
	}

	function updateTrunk(trunk, result) {
		var names = $('#cc-live-trunks').data('trunks') || [];
		var index = names.indexOf(trunk);
		if (index < 0) return;
		var panel = $('.cc-live-trunk[data-trunk-index="' + index + '"]');
		panel.attr('data-status', result.status);
		panel.find('.cc-trunk-value').text(result.current);
		panel.find('.cc-trunk-name').html(renderEntity(result.entity, trunk));
		panel.find('.cc-trunk-split').text(result.direction_counts.inbound + ' inbound · ' + result.direction_counts.outbound + ' outbound · ' + result.direction_counts.unknown + ' unknown');
		panel.find('.cc-trunk-threshold').text(result.threshold_enabled ? 'Threshold ' + result.threshold : 'Threshold off');
		panel.find('.cc-trunk-peak').text('Recent peak ' + recentPeak(history.trunks[trunk] || []));
		panel.find('.cc-trunk-status').text(statusLabel(result.status));
		charts.trunks[trunk].setData(history.trunks[trunk] || [], result.threshold_enabled ? result.threshold : 0);
	}

	function showCalls(title, calls) {
		var html = '<div class="cc-section-heading"><h3>' + escapeHtml(title) + '</h3><button type="button" class="btn btn-default btn-sm cc-close-live-detail"><i class="fa fa-times"></i> Close</button></div>';
		if (!calls || !calls.length) html += '<p class="text-muted">No active contributing channels in the latest snapshot.</p>';
		else {
			html += '<div class="cc-table-scroll"><table class="table table-condensed cc-live-call-table"><thead><tr><th>Channel</th><th>FreePBX object</th><th>State</th><th>Caller</th><th>Connected line</th><th>Context / destination</th><th>Duration</th><th>Direction</th><th>Bridge / linked</th></tr></thead><tbody>';
			calls.forEach(function (call) {
				var entity = call.extension_entity || call.trunk_entity;
				html += '<tr><td>' + escapeHtml(call.channel) + '</td><td>' + renderEntity(entity, '') + '</td><td>' + escapeHtml(call.state) + '</td><td>' + escapeHtml([call.caller_id_name, call.caller_id_num].filter(Boolean).join(' ')) + '</td>' +
					'<td>' + escapeHtml([call.connected_name, call.connected_num].filter(Boolean).join(' ')) + '</td><td>' + escapeHtml(call.context + (call.extension_value ? ' / ' + call.extension_value : '')) + '</td>' +
					'<td>' + escapeHtml(formatDuration(call.duration_seconds)) + '</td><td>' + escapeHtml(call.direction || 'unknown') + '</td><td>' + escapeHtml(call.bridge_id || call.linkedid) + '</td></tr>';
			});
			html += '</tbody></table></div>';
		}
		$('#cc-live-call-detail').html(html).show().find('.cc-close-live-detail').on('click', function () { $('#cc-live-call-detail').hide(); });
	}

	function renderEntity(entity, fallback) {
		if (!entity || !entity.label) return escapeHtml(fallback || '');
		return entity.native_url ? '<a href="' + escapeHtml(entity.native_url) + '">' + escapeHtml(entity.label) + '</a>' : escapeHtml(entity.label);
	}

	function markStale(message) {
		showLiveMessage(message, 'warning');
		$('.cc-status-panel, .cc-live-trunk').attr('data-status', 'stale');
	}

	function showLiveMessage(message, level) {
		$('#cc-live-message').removeClass('alert-info alert-warning alert-danger').addClass(level === 'danger' ? 'alert-danger' : (level === 'warning' ? 'alert-warning' : 'alert-info')).text(message).show();
	}

	function onVisibilityChange() {
		if (document.hidden) stopTimer();
		else if (activeWorkspace === 'live') startPolling(true);
	}

	function stopTimer() {
		if (timer) window.clearTimeout(timer);
		timer = null;
	}

	function stopPolling() {
		stopTimer();
		if (request && typeof request.abort === 'function') request.abort();
		request = null;
	}

	function openSettings() {
		if (!settings) {
			loadSettings().done(openSettings);
			return;
		}
		$('#cc-setting-refresh').val(String(settings.refresh_interval));
		$('#cc-setting-email').val(settings.alert_email || '');
		$('#cc-setting-alerts').prop('checked', !!settings.alerts_enabled);
		$('#cc-setting-recovery').prop('checked', !!settings.recovery_enabled);
		var rows = [scopeRow('overall', 'Overall Extension Concurrency', settings.overall)];
		Object.keys(settings.trunks || {}).forEach(function (trunk) { rows.push(scopeRow('trunk:' + trunk, trunk, settings.trunks[trunk])); });
		$('#cc-threshold-rows').html(rows.join(''));
		$('#cc-settings-error').hide();
		$('#cc-live-settings-modal').modal('show');
		loadMonitorStatus();
	}

	function loadMonitorStatus() {
		$('#cc-monitor-status').text('Checking...');
		ajax({command: 'monitorstatus'}).done(function (response) {
			if (!response.status || !response.monitor) {
				$('#cc-monitor-status').text('Unavailable');
				return;
			}
			var monitor = response.monitor;
			var detail = monitor.status === 'online' && monitor.pid ? 'Online (PID ' + monitor.pid + ')' : statusLabel(monitor.status);
			if (monitor.mailer_status && monitor.mailer_status !== 'online') detail += '; mail worker ' + monitor.mailer_status;
			$('#cc-monitor-status').text(detail);
		}).fail(function () { $('#cc-monitor-status').text('Unavailable'); });
	}

	function restartMonitor() {
		var button = $('#cc-monitor-restart').prop('disabled', true);
		$('#cc-monitor-status').text('Restarting...');
		ajax({command: 'restartmonitor'}).done(function (response) {
			if (!response.status || !response.monitor) {
				$('#cc-monitor-status').text(response.message || 'Restart failed');
				return;
			}
			var monitor = response.monitor;
			$('#cc-monitor-status').text(monitor.status === 'online' ? 'Online (PID ' + monitor.pid + ')' : statusLabel(monitor.status));
		}).fail(function () { $('#cc-monitor-status').text('Restart failed'); }).always(function () { button.prop('disabled', false); });
	}

	function scopeRow(scope, label, config) {
		return '<tr data-scope="' + escapeHtml(scope) + '"><td>' + escapeHtml(label) + '</td>' +
			'<td><input type="checkbox" class="cc-threshold-enabled"' + (config.enabled ? ' checked' : '') + ' aria-label="Enable threshold for ' + escapeHtml(label) + '"></td>' +
			'<td><input type="number" class="form-control cc-threshold-value" min="0" max="10000" value="' + escapeHtml(config.threshold) + '" aria-label="Threshold for ' + escapeHtml(label) + '"></td>' +
			'<td><input type="checkbox" class="cc-alert-enabled"' + (config.alert_enabled ? ' checked' : '') + ' aria-label="Enable alert for ' + escapeHtml(label) + '"></td></tr>';
	}

	function saveSettingsFromModal() {
		var candidate = {
			refresh_interval: parseInt($('#cc-setting-refresh').val(), 10),
			alerts_enabled: $('#cc-setting-alerts').is(':checked'),
			recovery_enabled: $('#cc-setting-recovery').is(':checked'),
			alert_email: $('#cc-setting-email').val().trim(), overall: {}, trunks: {}
		};
		$('#cc-threshold-rows tr').each(function () {
			var row = $(this);
			var scope = row.data('scope');
			var value = {enabled: row.find('.cc-threshold-enabled').is(':checked'), threshold: parseInt(row.find('.cc-threshold-value').val(), 10) || 0, alert_enabled: row.find('.cc-alert-enabled').is(':checked')};
			if (scope === 'overall') candidate.overall = value;
			else candidate.trunks[String(scope).substring(6)] = value;
		});
		saveSettings(candidate, true);
	}

	function saveSettings(candidate, closeModal) {
		ajax({command: 'savesettings', settings: JSON.stringify(candidate)}).done(function (response) {
			if (!response.status) {
				$('#cc-settings-error').text(response.message || 'Unable to save settings.').show();
				return;
			}
			settings = response.settings;
			$('#cc-live-refresh').val(String(settings.refresh_interval));
			if (closeModal) $('#cc-live-settings-modal').modal('hide');
			startPolling(true);
		}).fail(function () { $('#cc-settings-error').text('Unable to save settings.').show(); });
	}

	function loadHistoricalGraph(result) {
		historicalResult = result;
		if (!result || (result.mode !== 'trunk' && result.mode !== 'group') || result.empty_message) {
			$('#cc-historical-graph').hide();
			return;
		}
		ajax({command: 'historicalgraph', mode: result.mode, start_date: result.start, end_date: result.end}).done(function (response) {
			if (!response.status) return;
			historicalSeries = response.graph;
			renderHistoricalSeries();
		});
	}

	function renderHistoricalSeries() {
		var names = Object.keys(historicalSeries.series || {});
		if (!names.length) return;
		var selected = names[0];
		for (var index = 1; index < names.length; index++) if (historicalSeries.series[names[index]].exact_peak > historicalSeries.series[selected].exact_peak) selected = names[index];
		var buttons = names.map(function (name) { return '<button type="button" class="btn btn-default btn-sm cc-series-choice" data-series="' + escapeHtml(name) + '">' + escapeHtml(name === 'overall' ? 'Overall' : name) + '</button>'; });
		$('#cc-historical-series').html(buttons.join(''));
		$('#cc-historical-series .cc-series-choice').on('click', function () { showHistoricalSeries($(this).data('series')); });
		$('#cc-historical-graph').show();
		showHistoricalSeries(selected);
	}

	function showHistoricalSeries(name) {
		var series = historicalSeries.series[name];
		if (!series) return;
		$('#cc-historical-series .cc-series-choice').removeClass('btn-primary').addClass('btn-default').filter(function () { return $(this).data('series') === name; }).addClass('btn-primary').removeClass('btn-default');
		$('#cc-historical-resolution').text(series.display_resolution === 'exact_events' ? 'Exact CDR event transitions' : 'Display uses bucket maxima; exact peak remains ' + series.exact_peak);
		var thresholdConfig = historicalSeries.thresholds[name] || {};
		if (!charts.historical) charts.historical = new window.ConcurrencyChart(document.getElementById('cc-historical-chart'), {onSelect: function (point) { focusHistoricalPoint(name, point); }});
		else charts.historical.options.onSelect = function (point) { focusHistoricalPoint(name, point); };
		charts.historical.setData(series.points, thresholdConfig.enabled ? thresholdConfig.threshold : 0);
	}

	function focusHistoricalPoint(name, point) {
		if (!historicalResult || historicalResult.mode !== 'trunk' || name === 'overall') return;
		var occurrences = historicalResult.peak_occurrences && historicalResult.peak_occurrences[name] ? historicalResult.peak_occurrences[name] : [];
		for (var index = 0; index < occurrences.length; index++) {
			var from = Date.parse(occurrences[index].from.replace(' ', 'T')) / 1000;
			var to = Date.parse(occurrences[index].to.replace(' ', 'T')) / 1000;
			if (point.ts >= from && point.ts <= to) {
				var names = Object.keys(historicalResult.per_name || {});
				var nameIndex = names.indexOf(name);
				var section = $('.cc-occurrence-section[data-name-index="' + nameIndex + '"]').show();
				var toggle = section.find('.cc-occurrence-toggle').eq(index);
				if (toggle.attr('aria-expanded') !== 'true') toggle.trigger('click');
				$('html, body').animate({scrollTop: section.offset().top - 20}, 250);
				break;
			}
		}
	}

	function recentPeak(points) {
		var peak = 0;
		for (var index = 0; index < points.length; index++) peak = Math.max(peak, points[index].value);
		return peak;
	}

	function statusLabel(status) {
		return status === 'exceeded' ? 'Threshold exceeded' : (status === 'approaching' ? 'Approaching threshold' : (status === 'degraded' ? 'Degraded / no recent AMI snapshot' : (status === 'unavailable' || status === 'stale' || status === 'failed' ? 'Stale / disconnected' : 'Normal')));
	}

	function formatDuration(seconds) {
		seconds = parseInt(seconds, 10) || 0;
		var minutes = Math.floor(seconds / 60);
		return minutes ? minutes + 'm ' + (seconds % 60) + 's' : seconds + 's';
	}

	$(initialise);
}(window.jQuery));
}
