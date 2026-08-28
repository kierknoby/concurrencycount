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
	var wallActive = false;
	var preferenceSaveTimer = null;
	var settingsSaveQueue = [];
	var settingsSaveInFlight = false;
	var settingsSaveSequence = 0;
	var latestSettingsSaveSequence = 0;
	var draggedTrunk = null;
	var featuredDraft = [];
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
		$('#cc-live-refresh').off('change.ccLive').on('change.ccLive', function () {
			if (!settings) return;
			settings.refresh_interval = parseInt($(this).val(), 10);
			saveSettings(settings, false);
			startPolling(true);
		});
		$('#cc-live-settings').off('click.ccLive').on('click.ccLive', openSettings);
		$('#cc-live-wall-launch').off('click.ccLive').on('click.ccLive', enterLiveWall);
		$('#cc-live-wall-configure').off('click.ccLive').on('click.ccLive', openLiveWallConfiguration);
		$('#cc-live-wall-exit').off('click.ccLive').on('click.ccLive', exitLiveWall);
		$('#cc-wall-featured-save').off('click.ccLive').on('click.ccLive', saveLiveWallConfiguration);
		$('#cc-settings-save').off('click.ccLive').on('click.ccLive', saveSettingsFromModal);
		$('#cc-monitor-restart').off('click.ccLive').on('click.ccLive', restartMonitor);
		$('#cc-live-overall-value').off('click.ccLive').on('click.ccLive', function () { showCalls('Overall live PJSIP trunk activity', snapshot ? snapshot.overall.calls : []); });
		$(document).off('visibilitychange.ccLive').on('visibilitychange.ccLive', onVisibilityChange);
		$(window).off('beforeunload.ccLive').on('beforeunload.ccLive', stopPolling);
		$(document).off('fullscreenchange.ccLive').on('fullscreenchange.ccLive', onFullscreenChange);
		$(document).off('cc:historical-results.ccLive').on('cc:historical-results.ccLive', function (event, result, cachedSeries) { loadHistoricalGraph(result, cachedSeries); });
	}

	/**
	 * Section-visibility/polling side effect only. Which top-level tab is
	 * visually selected (Live, Historical Reports, or a Historic Report tab)
	 * is owned centrally by concurrencycount.js so all three kinds of tab
	 * share one tab strip; exposed here for it to call.
	 */
	function switchWorkspace(target) {
		activeWorkspace = target === 'live' ? 'live' : 'historical';
		$('#cc-live-section').toggle(activeWorkspace === 'live');
		$('#cc-historical-section').toggle(activeWorkspace === 'historical');
		if (activeWorkspace === 'live' || wallActive) startPolling(true);
		else stopTimer();
	}

	function loadSettings() {
		var saveSequenceWhenRequested = latestSettingsSaveSequence;
		return ajax({command: 'getsettings'}).done(function (response) {
			if (!response.status || saveSequenceWhenRequested !== latestSettingsSaveSequence) return;
			settings = response.settings;
			$('#cc-live-refresh, #cc-setting-refresh').val(String(settings.refresh_interval));
		}).fail(function () {
			showLiveMessage('Unable to load Live settings. Defaults are being used.', 'warning');
		});
	}

	function startPolling(immediate) {
		stopTimer();
		if (!isLivePresentationActive() || document.hidden) return;
		if (immediate) pollLive();
		else scheduleNext();
	}

	function scheduleNext() {
		stopTimer();
		if (!isLivePresentationActive() || document.hidden) return;
		var interval = settings ? parseInt(settings.refresh_interval, 10) : 5;
		timer = window.setTimeout(pollLive, Math.max(1, interval) * 1000);
	}

	function pollLive() {
		if (request || !isLivePresentationActive() || document.hidden) return;
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
		renderHiddenTrunks(data.trunks);
		if (wallActive) renderLiveWall(data);
	}

	function isLivePresentationActive() {
		return activeWorkspace === 'live' || wallActive;
	}

	function orderedTrunks(trunks) {
		var available = Object.keys(trunks || {});
		var ordered = [];
		(settings && settings.trunk_order ? settings.trunk_order : []).forEach(function (trunk) {
			if (available.indexOf(trunk) >= 0 && ordered.indexOf(trunk) < 0) ordered.push(trunk);
		});
		available.forEach(function (trunk) { if (ordered.indexOf(trunk) < 0) ordered.push(trunk); });
		return ordered;
	}

	function isHidden(trunk) {
		return !!settings && (settings.hidden_trunks || []).indexOf(trunk) >= 0;
	}

	function isMonitored(trunk) {
		return !settings || !settings.trunks || !settings.trunks[trunk] || settings.trunks[trunk].monitored !== false;
	}

	function updateOverall(overall) {
		$('#cc-live-overall-value').text(overall.current);
		$('#cc-live-overall-threshold').text(overall.threshold_enabled ? 'Threshold ' + overall.threshold : 'Threshold off');
		$('#cc-live-overall-peak').text('Recent peak ' + recentPeak(history.overall) + ' (this session)');
		$('#cc-live-overall-status').text(statusLabel(overall.status));
		$('.cc-live-overall').attr('data-status', overall.status);
		if (!charts.overall) charts.overall = new window.ConcurrencyChart(document.getElementById('cc-live-overall-chart'), {onSelect: function () { showCalls('Current overall active PJSIP trunk legs', snapshot.overall.calls); }});
		charts.overall.setData(history.overall, overall.threshold_enabled ? overall.threshold : 0);
	}

	function ensureTrunkCards(trunks) {
		var container = $('#cc-live-trunks');
		var names = orderedTrunks(trunks).filter(function (trunk) { return !isHidden(trunk); });
		var existing = container.data('trunks') || [];
		if (JSON.stringify(existing) === JSON.stringify(names)) return;
		Object.keys(charts.trunks).forEach(function (name) { charts.trunks[name].destroy(); });
		charts.trunks = {};
		var html = '';
		names.forEach(function (trunk, index) {
			html += '<article class="cc-live-trunk" draggable="false" data-trunk="' + escapeHtml(trunk) + '" data-trunk-index="' + index + '" data-status="normal">' +
				'<div class="cc-trunk-toolbar"><button type="button" class="cc-drag-handle" draggable="true" aria-label="Reorder ' + escapeHtml(trunk) + '; use Left and Right Arrow keys" title="Drag to reorder; keyboard: Left or Right Arrow"><i class="fa fa-bars"></i></button>' +
				'<button type="button" class="cc-toggle-monitoring"></button><button type="button" class="cc-hide-trunk">Hide Trunk</button></div>' +
				'<div class="cc-live-trunk-header"><div><h4 class="cc-trunk-name">' + escapeHtml(trunk) + '</h4><span class="cc-trunk-split">0 inbound · 0 outbound · 0 unknown</span></div>' +
				'<button type="button" class="cc-trunk-value" data-trunk-index="' + index + '">0</button></div>' +
				'<div class="cc-live-meta"><span class="cc-trunk-monitoring">Monitoring active</span><span class="cc-trunk-threshold">Threshold off</span><span class="cc-trunk-peak">Recent peak 0 (this session)</span><span class="cc-trunk-status">Normal</span></div>' +
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
		bindTrunkControls();
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
		panel.find('.cc-trunk-peak').text('Recent peak ' + recentPeak(history.trunks[trunk] || []) + ' (this session)');
		panel.find('.cc-trunk-status').text(statusLabel(result.status));
		panel.find('.cc-trunk-monitoring').text(isMonitored(trunk) ? 'Monitoring active' : 'Monitoring stopped');
		panel.find('.cc-toggle-monitoring').text(isMonitored(trunk) ? 'Stop Monitoring' : 'Start Monitoring').attr('aria-label', (isMonitored(trunk) ? 'Stop monitoring ' : 'Start monitoring ') + trunk);
		if (charts.trunks[trunk]) charts.trunks[trunk].setData(history.trunks[trunk] || [], result.threshold_enabled ? result.threshold : 0);
	}

	function bindTrunkControls() {
		var grid = $('#cc-live-trunks');
		grid.find('.cc-hide-trunk').off('click.ccLive').on('click.ccLive', function () { setHidden($(this).closest('[data-trunk]').attr('data-trunk'), true); });
		grid.find('.cc-toggle-monitoring').off('click.ccLive').on('click.ccLive', function () { toggleMonitoring($(this).closest('[data-trunk]').attr('data-trunk')); });
		grid.find('.cc-drag-handle').off('keydown.ccLive dragstart.ccLive dragend.ccLive').on('keydown.ccLive', function (event) {
			if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
			event.preventDefault();
			moveTrunk($(this).closest('[data-trunk]').attr('data-trunk'), event.key === 'ArrowLeft' ? -1 : 1);
		}).on('dragstart.ccLive', function (event) {
			draggedTrunk = $(this).closest('[data-trunk]').attr('data-trunk');
			$(this).closest('.cc-live-trunk').addClass('cc-is-dragging');
			event.originalEvent.dataTransfer.effectAllowed = 'move';
			event.originalEvent.dataTransfer.setData('text/plain', draggedTrunk);
		}).on('dragend.ccLive', function () {
			draggedTrunk = null;
			grid.find('.cc-live-trunk').removeClass('cc-is-dragging cc-drop-target');
		});
		grid.find('.cc-live-trunk').off('dragover.ccLive dragleave.ccLive drop.ccLive').on('dragover.ccLive', function (event) {
			if (!draggedTrunk || $(this).attr('data-trunk') === draggedTrunk) return;
			event.preventDefault();
			grid.find('.cc-live-trunk').removeClass('cc-drop-target');
			$(this).addClass('cc-drop-target');
		}).on('dragleave.ccLive', function () { $(this).removeClass('cc-drop-target'); }).on('drop.ccLive', function (event) {
			event.preventDefault();
			var target = $(this).attr('data-trunk');
			if (draggedTrunk && target && draggedTrunk !== target) reorderBefore(draggedTrunk, target);
		});
	}

	function setHidden(trunk, hidden) {
		if (!settings) return;
		var list = settings.hidden_trunks || [];
		settings.hidden_trunks = list.filter(function (name) { return name !== trunk; });
		if (hidden) settings.hidden_trunks.push(trunk);
		if (preferenceSaveTimer) window.clearTimeout(preferenceSaveTimer);
		preferenceSaveTimer = null;
		saveSettings(settings, false);
		renderSnapshot(snapshot);
	}

	function toggleMonitoring(trunk) {
		if (!settings || !settings.trunks || !settings.trunks[trunk]) return;
		settings.trunks[trunk].monitored = !isMonitored(trunk);
		saveSettings(settings, false, null, null, true);
		renderSnapshot(snapshot);
	}

	function moveTrunk(trunk, direction) {
		var visible = orderedTrunks(snapshot ? snapshot.trunks : {}).filter(function (name) { return !isHidden(name); });
		var index = visible.indexOf(trunk);
		var other = index + direction;
		if (index < 0 || other < 0 || other >= visible.length) return;
		var order = (settings.trunk_order || []).slice();
		var trunkIndex = order.indexOf(trunk);
		var otherIndex = order.indexOf(visible[other]);
		if (trunkIndex < 0 || otherIndex < 0) return;
		order.splice(trunkIndex, 1);
		otherIndex = order.indexOf(visible[other]);
		order.splice(direction < 0 ? otherIndex : otherIndex + 1, 0, trunk);
		settings.trunk_order = order;
		persistPreferences();
		renderSnapshot(snapshot);
	}

	function reorderBefore(trunk, target) {
		var order = (settings.trunk_order || []).slice();
		var from = order.indexOf(trunk);
		var to = order.indexOf(target);
		if (from < 0 || to < 0) return;
		order.splice(from, 1);
		to = order.indexOf(target);
		order.splice(to, 0, trunk);
		settings.trunk_order = order;
		persistPreferences();
		renderSnapshot(snapshot);
	}

	function persistPreferences() {
		if (preferenceSaveTimer) window.clearTimeout(preferenceSaveTimer);
		preferenceSaveTimer = window.setTimeout(function () { preferenceSaveTimer = null; saveSettings(settings, false); }, 250);
	}

	function renderHiddenTrunks(trunks) {
		var hidden = orderedTrunks(trunks).filter(isHidden);
		$('#cc-hidden-trunks').toggle(hidden.length > 0);
		var html = hidden.map(function (trunk) {
			var active = isMonitored(trunk);
			return '<div class="cc-hidden-trunk-row" data-trunk="' + escapeHtml(trunk) + '"><strong>' + escapeHtml(trunk) + '</strong><span>' + (active ? 'Monitoring active' : 'Monitoring stopped') + '</span>' +
				'<div><button type="button" class="btn btn-default btn-sm cc-unhide-trunk" aria-label="Unhide ' + escapeHtml(trunk) + '">Unhide</button> ' +
				'<button type="button" class="btn btn-default btn-sm cc-toggle-monitoring" aria-label="' + (active ? 'Stop monitoring ' : 'Start monitoring ') + escapeHtml(trunk) + '">' + (active ? 'Stop Monitoring' : 'Start Monitoring') + '</button></div></div>';
		}).join('');
		$('#cc-hidden-trunk-list').html(html).find('.cc-unhide-trunk').on('click', function () { setHidden($(this).closest('[data-trunk]').attr('data-trunk'), false); });
		$('#cc-hidden-trunk-list .cc-toggle-monitoring').on('click', function () { toggleMonitoring($(this).closest('[data-trunk]').attr('data-trunk')); });
	}

	function openLiveWallConfiguration() {
		if (!settings) {
			loadSettings().done(openLiveWallConfiguration);
			return;
		}
		featuredDraft = (settings.live_wall_featured_trunks || []).slice(0, 3);
		$('#cc-wall-featured-error').hide();
		renderLiveWallConfiguration();
		$('#cc-live-wall-config-modal').modal('show');
	}

	function configuredTrunksForWallConfiguration() {
		if (snapshot && snapshot.trunks) return orderedTrunks(snapshot.trunks);
		return Object.keys(settings && settings.trunks ? settings.trunks : {}).sort();
	}

	function featuredTrunkLabel(trunk) {
		var result = snapshot && snapshot.trunks ? snapshot.trunks[trunk] : null;
		return result && result.entity && result.entity.label ? result.entity.label : trunk;
	}

	function renderLiveWallConfiguration() {
		var configured = configuredTrunksForWallConfiguration();
		var rows = [];
		featuredDraft.forEach(function (trunk, index) {
			var available = configured.indexOf(trunk) >= 0;
			var states = [];
			if (isHidden(trunk)) states.push('Hidden from Live Wall');
			if (!available) states.push('Currently unavailable');
			else states.push(isMonitored(trunk) ? 'Monitoring active' : 'Monitoring stopped');
			rows.push(featuredConfigurationRow(trunk, index, true, states.join(' · ')));
		});
		configured.forEach(function (trunk) {
			if (featuredDraft.indexOf(trunk) >= 0) return;
			var states = [isHidden(trunk) ? 'Hidden from Live Wall' : (isMonitored(trunk) ? 'Monitoring active' : 'Monitoring stopped')];
			rows.push(featuredConfigurationRow(trunk, -1, false, states.join(' · ')));
		});
		if (!rows.length) rows.push('<p class="text-muted">No configured Live trunks are currently available.</p>');
		$('#cc-wall-featured-list').html(rows.join(''));
		$('#cc-wall-featured-count').text(featuredDraft.length + ' of 3 featured trunks selected.' + (featuredDraft.length === 3 ? ' Deselect one to choose another.' : ''));
		bindLiveWallConfigurationControls();
	}

	function featuredConfigurationRow(trunk, index, selected, stateText) {
		var disabled = !selected && featuredDraft.length >= 3;
		return '<div class="cc-wall-featured-row" data-featured-trunk="' + escapeHtml(trunk) + '">' +
			'<label><input type="checkbox" class="cc-wall-featured-choice"' + (selected ? ' checked' : '') + (disabled ? ' disabled' : '') + '> <strong>' + escapeHtml(featuredTrunkLabel(trunk)) + '</strong> <small>' + escapeHtml(trunk) + '</small></label>' +
			'<span class="cc-wall-featured-state">' + escapeHtml(stateText) + '</span>' +
			(selected ? '<span class="cc-wall-featured-order"><button type="button" class="btn btn-default btn-sm cc-featured-earlier" aria-label="Move ' + escapeHtml(trunk) + ' earlier"' + (index === 0 ? ' disabled' : '') + '>Move earlier</button> <button type="button" class="btn btn-default btn-sm cc-featured-later" aria-label="Move ' + escapeHtml(trunk) + ' later"' + (index === featuredDraft.length - 1 ? ' disabled' : '') + '>Move later</button></span>' : '') + '</div>';
	}

	function bindLiveWallConfigurationControls() {
		$('#cc-wall-featured-list .cc-wall-featured-choice').on('change', function () {
			var trunk = $(this).closest('[data-featured-trunk]').attr('data-featured-trunk');
			if ($(this).is(':checked')) {
				if (featuredDraft.length >= 3) {
					$(this).prop('checked', false);
					$('#cc-wall-featured-error').text('Choose no more than 3 featured trunks.').show();
					return;
				}
				featuredDraft.push(trunk);
			} else featuredDraft = featuredDraft.filter(function (name) { return name !== trunk; });
			$('#cc-wall-featured-error').hide();
			renderLiveWallConfiguration();
		});
		$('#cc-wall-featured-list .cc-featured-earlier, #cc-wall-featured-list .cc-featured-later').on('click', function () {
			var trunk = $(this).closest('[data-featured-trunk]').attr('data-featured-trunk');
			var from = featuredDraft.indexOf(trunk);
			var to = from + ($(this).hasClass('cc-featured-earlier') ? -1 : 1);
			if (from < 0 || to < 0 || to >= featuredDraft.length) return;
			featuredDraft.splice(from, 1);
			featuredDraft.splice(to, 0, trunk);
			renderLiveWallConfiguration();
		});
	}

	function saveLiveWallConfiguration() {
		var candidate = $.extend(true, {}, settings);
		candidate.live_wall_featured_trunks = featuredDraft.slice();
		var button = $('#cc-wall-featured-save').prop('disabled', true);
		$('#cc-wall-featured-error').hide();
		saveSettings(candidate, false, function () {
			$('#cc-live-wall-config-modal').modal('hide');
		}, function (message) {
			$('#cc-wall-featured-error').text(message || 'Unable to save featured trunks.').show();
		}).always(function () { button.prop('disabled', false); });
	}

	function enterLiveWall() {
		wallActive = true;
		$('#cc-live-wall').show().attr('aria-hidden', 'false');
		$('body').addClass('cc-wall-active');
		if (snapshot) renderLiveWall(snapshot);
		scheduleChartResize(resizeWallCharts);
		startPolling(!snapshot);
		var wall = document.getElementById('cc-live-wall');
		if (wall && typeof wall.requestFullscreen === 'function') {
			var requestResult;
			try { requestResult = wall.requestFullscreen(); } catch (error) { requestResult = null; }
			if (requestResult && typeof requestResult.catch === 'function') requestResult.catch(function () { /* Full-page fallback remains active. */ });
		}
	}

	function exitLiveWall() {
		wallActive = false;
		$('#cc-live-wall').hide().attr('aria-hidden', 'true');
		$('body').removeClass('cc-wall-active');
		if (document.fullscreenElement && typeof document.exitFullscreen === 'function') {
			var exitResult = document.exitFullscreen();
			if (exitResult && typeof exitResult.catch === 'function') exitResult.catch(function () {});
		}
		if (activeWorkspace !== 'live') stopTimer();
		scheduleChartResize(resizeLiveCharts);
	}

	function onFullscreenChange() {
		$('#cc-live-wall').toggleClass('cc-browser-fullscreen', document.fullscreenElement === document.getElementById('cc-live-wall'));
		if (wallActive) scheduleChartResize(resizeWallCharts);
	}

	function scheduleChartResize(callback) {
		if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(callback);
		else window.setTimeout(callback, 0);
	}

	function resizeWallCharts() {
		if (charts.wallOverall) charts.wallOverall.resize();
		Object.keys(charts.wallTrunks || {}).forEach(function (trunk) { charts.wallTrunks[trunk].resize(); });
	}

	function resizeLiveCharts() {
		if (charts.overall) charts.overall.resize();
		Object.keys(charts.trunks || {}).forEach(function (trunk) { charts.trunks[trunk].resize(); });
	}

	function renderLiveWall(data) {
		$('#cc-wall-message').hide();
		$('#cc-wall-content').css('display', 'grid');
		$('#cc-wall-updated').text('Last successful update: ' + new Date(data.generated_ts * 1000).toLocaleString());
		$('#cc-wall-overall-value').text(data.overall.current);
		$('#cc-wall-overall-status').text(statusLabel(data.overall.status));
		$('#cc-wall-overall-threshold').text(data.overall.threshold_enabled ? 'Threshold ' + data.overall.threshold : 'Threshold off');
		$('#cc-wall-overall-peak').text('Recent peak ' + recentPeak(history.overall));
		$('.cc-wall-overall').attr('data-status', data.overall.status);
		if (!charts.wallOverall) charts.wallOverall = new window.ConcurrencyChart(document.getElementById('cc-wall-overall-chart'), {theme: 'dark'});
		charts.wallOverall.setData(history.overall, data.overall.threshold_enabled ? data.overall.threshold : 0);
		var configuredFeatured = settings && settings.live_wall_featured_trunks ? settings.live_wall_featured_trunks : [];
		var names = configuredFeatured.filter(function (trunk) { return Object.prototype.hasOwnProperty.call(data.trunks, trunk) && !isHidden(trunk); });
		var unavailableCount = configuredFeatured.filter(function (trunk) { return !Object.prototype.hasOwnProperty.call(data.trunks, trunk); }).length;
		var hiddenCount = configuredFeatured.filter(function (trunk) { return Object.prototype.hasOwnProperty.call(data.trunks, trunk) && isHidden(trunk); }).length;
		var note = '';
		if (!configuredFeatured.length) note = 'No featured trunks configured.';
		else if (!names.length) note = 'Featured trunks are currently hidden or unavailable.';
		else if (unavailableCount || hiddenCount) note = (unavailableCount ? unavailableCount + ' featured unavailable. ' : '') + (hiddenCount ? hiddenCount + ' featured hidden from Live Wall.' : '');
		$('#cc-wall-featured-note').text(note).toggle(note !== '');
		$('#cc-wall-trunks').attr('data-count', names.length);
		var previous = $('#cc-wall-trunks').data('trunks') || [];
		if (JSON.stringify(previous) !== JSON.stringify(names)) {
			Object.keys(charts.wallTrunks || {}).forEach(function (name) { charts.wallTrunks[name].destroy(); });
			charts.wallTrunks = {};
			$('#cc-wall-trunks').html(names.map(function (trunk, index) {
				return '<article class="cc-wall-trunk" data-wall-trunk="' + escapeHtml(trunk) + '" data-status="normal"><h2>' + escapeHtml(featuredTrunkLabel(trunk)) + '</h2><strong class="cc-wall-trunk-value">0</strong><span class="cc-wall-trunk-split"></span><span class="cc-wall-monitoring"></span><span class="cc-wall-threshold"></span><span class="cc-wall-status"></span><span class="cc-wall-peak"></span><canvas id="cc-wall-trunk-chart-' + index + '" height="110"></canvas></article>';
			}).join('')).data('trunks', names);
			names.forEach(function (trunk, index) { charts.wallTrunks[trunk] = new window.ConcurrencyChart(document.getElementById('cc-wall-trunk-chart-' + index), {theme: 'dark'}); });
		}
		names.forEach(function (trunk) {
			var result = data.trunks[trunk];
			var card = $('#cc-wall-trunks [data-wall-trunk]').filter(function () { return $(this).attr('data-wall-trunk') === trunk; });
			card.attr('data-status', result.status).find('.cc-wall-trunk-value').text(result.current);
			card.find('.cc-wall-trunk-split').text(result.direction_counts.inbound + ' inbound · ' + result.direction_counts.outbound + ' outbound · ' + result.direction_counts.unknown + ' unknown');
			card.toggleClass('cc-monitoring-stopped', !isMonitored(trunk));
			card.find('.cc-wall-monitoring').text(isMonitored(trunk) ? 'Monitoring active' : 'Monitoring stopped');
			card.find('.cc-wall-threshold').text(result.threshold_enabled ? 'Threshold ' + result.threshold : 'Threshold off');
			card.find('.cc-wall-status').text(statusLabel(result.status));
			card.find('.cc-wall-peak').text('Recent peak ' + recentPeak(history.trunks[trunk] || []));
			charts.wallTrunks[trunk].setData(history.trunks[trunk] || [], result.threshold_enabled ? result.threshold : 0);
		});
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
		$('#cc-wall-message').removeClass('alert-info alert-danger').addClass('alert-warning').text(message).show();
		$('.cc-status-panel, .cc-live-trunk').attr('data-status', 'stale');
		$('.cc-wall-overall, .cc-wall-trunk').attr('data-status', 'stale');
	}

	function showLiveMessage(message, level) {
		$('#cc-live-message').removeClass('alert-info alert-warning alert-danger').addClass(level === 'danger' ? 'alert-danger' : (level === 'warning' ? 'alert-warning' : 'alert-info')).text(message).show();
	}

	function onVisibilityChange() {
		if (document.hidden) stopTimer();
		else if (isLivePresentationActive()) startPolling(true);
	}

	function stopTimer() {
		if (timer) window.clearTimeout(timer);
		timer = null;
	}

	function stopPolling() {
		stopTimer();
		if (preferenceSaveTimer) {
			window.clearTimeout(preferenceSaveTimer);
			preferenceSaveTimer = null;
			saveSettings(settings, false);
		}
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
		var rows = [scopeRow('overall', 'Overall Live Concurrency', settings.overall)];
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
			alert_email: $('#cc-setting-email').val().trim(),
			hidden_trunks: (settings.hidden_trunks || []).slice(), trunk_order: (settings.trunk_order || []).slice(),
			live_wall_featured_trunks: (settings.live_wall_featured_trunks || []).slice(),
			overall: {}, trunks: {}
		};
		$('#cc-threshold-rows tr').each(function () {
			var row = $(this);
			var scope = row.data('scope');
			var value = {enabled: row.find('.cc-threshold-enabled').is(':checked'), threshold: parseInt(row.find('.cc-threshold-value').val(), 10) || 0, alert_enabled: row.find('.cc-alert-enabled').is(':checked')};
			if (scope === 'overall') candidate.overall = value;
			else {
				var trunk = String(scope).substring(6);
				value.monitored = isMonitored(trunk);
				candidate.trunks[trunk] = value;
			}
		});
		saveSettings(candidate, true, null, null, true);
	}

	function saveSettings(candidate, closeModal, onSuccess, onFailure, pollAfterSave) {
		var deferred = $.Deferred();
		var sequence = ++settingsSaveSequence;
		latestSettingsSaveSequence = sequence;
		settingsSaveQueue.push({
			candidate: $.extend(true, {}, candidate),
			closeModal: closeModal,
			onSuccess: onSuccess,
			onFailure: onFailure,
			pollAfterSave: !!pollAfterSave,
			sequence: sequence,
			deferred: deferred
		});
		drainSettingsSaveQueue();
		return deferred.promise();
	}

	function drainSettingsSaveQueue() {
		if (settingsSaveInFlight || !settingsSaveQueue.length) return;
		settingsSaveInFlight = true;
		var pending = settingsSaveQueue.shift();
		ajax({command: 'savesettings', settings: JSON.stringify(pending.candidate)}).done(function (response) {
			if (!response.status) {
				$('#cc-settings-error').text(response.message || 'Unable to save settings.').show();
				showLiveMessage(response.message || 'Unable to save Live View settings.', 'warning');
				if (pending.sequence === latestSettingsSaveSequence && !settingsSaveQueue.length) loadSettings().always(function () { if (snapshot) renderSnapshot(snapshot); });
				if (pending.onFailure) pending.onFailure(response.message || 'Unable to save settings.');
				pending.deferred.reject(response.message || 'Unable to save settings.');
				return;
			}
			if (pending.sequence === latestSettingsSaveSequence) {
				settings = response.settings;
				$('#cc-live-refresh').val(String(settings.refresh_interval));
				if (snapshot) renderSnapshot(snapshot);
			}
			if (pending.closeModal) $('#cc-live-settings-modal').modal('hide');
			if (pending.pollAfterSave) startPolling(true);
			if (pending.onSuccess) pending.onSuccess(response.settings);
			pending.deferred.resolve(response.settings);
		}).fail(function () {
			$('#cc-settings-error').text('Unable to save settings.').show();
			showLiveMessage('Unable to save Live View settings.', 'warning');
			if (pending.sequence === latestSettingsSaveSequence && !settingsSaveQueue.length) loadSettings().always(function () { if (snapshot) renderSnapshot(snapshot); });
			if (pending.onFailure) pending.onFailure('Unable to save settings.');
			pending.deferred.reject('Unable to save settings.');
		}).always(function () {
			settingsSaveInFlight = false;
			drainSettingsSaveQueue();
		});
	}

	function loadHistoricalGraph(result, cachedSeries) {
		historicalResult = result;
		if (!result || (result.mode !== 'trunk' && result.mode !== 'group') || result.empty_message) {
			$('#cc-historical-graph').hide();
			return;
		}
		if (cachedSeries) {
			historicalSeries = cachedSeries;
			renderHistoricalSeries();
			return;
		}
		ajax({command: 'historicalgraph', mode: result.mode, start_date: result.start, end_date: result.end, trunk: result.mode === 'trunk' ? (result.filter || '') : ''}).done(function (response) {
			if (!response.status) return;
			historicalSeries = response.graph;
			renderHistoricalSeries();
			$(document).trigger('cc:historical-graph-loaded', [response.graph]);
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
				var section = $('.cc-trunk-result[data-name-index="' + nameIndex + '"] .cc-occurrence-section');
				var toggle = section.find('.cc-occurrence-toggle').eq(index);
				if (toggle.attr('aria-expanded') !== 'true') toggle.trigger('click');
				toggle.closest('.cc-occurrence').addClass('cc-occurrence-focused');
				setTimeout(function () { toggle.closest('.cc-occurrence').removeClass('cc-occurrence-focused'); }, 1800);
				$('html, body').animate({scrollTop: toggle.offset().top - 20}, 250);
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

	window.CCLiveWorkspace = {switchSection: switchWorkspace};

	$(initialise);
}(window.jQuery));
}
