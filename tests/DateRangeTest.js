'use strict';

var range = require('../assets/js/date-range.js');

function same(expected, actual, message) {
	if (expected !== actual) throw new Error(message + ': expected ' + expected + ', got ' + actual);
}

var now = new Date(2026, 7, 25, 7, 5, 9);
var today = range.preset('today', now);
same('2026-08-25', today.from, 'Today start');
same('2026-08-25', today.to, 'Today end');
var todayCanonical = range.resolve(today, now);
same('2026-08-25 00:00:00', todayCanonical.start, 'Today canonical start');
same('2026-08-25 07:05:09', todayCanonical.end, 'Today canonical end uses now');

var yesterday = range.preset('yesterday', now);
same('2026-08-24 00:00:00', range.resolve(yesterday, now).start, 'Yesterday start');
same('2026-08-24 23:59:59', range.resolve(yesterday, now).end, 'Yesterday end');

var lastSeven = range.preset('last7', now);
same('2026-08-19', lastSeven.from, 'Seven-day inclusive start');
same('2026-08-25', lastSeven.to, 'Seven-day inclusive end');
var previousSeven = range.shift(lastSeven, -1, now);
same('2026-08-12', previousSeven.from, 'Previous seven-day start');
same('2026-08-18', previousSeven.to, 'Previous seven-day end');
same('2026-08-25', range.shift(previousSeven, 1, now).to, 'Next seven-day range');

var july = range.shift({kind: 'month', from: '2026-08-01', to: '2026-08-25'}, -1, now);
same('2026-07-01', july.from, 'Previous calendar month start');
same('2026-07-31', july.to, 'Previous calendar month end');
var currentMonth = range.shift(july, 1, now);
same('2026-08-01', currentMonth.from, 'Current partial month keeps calendar start');
same('2026-08-25', currentMonth.to, 'Current partial month ends today');
var february = range.shift({kind: 'month', from: '2024-03-01', to: '2024-03-31'}, -1, now);
same('2024-02-29', february.to, 'Leap month end');

var custom = range.resolve({from: '2024-02-29', to: '2024-03-01', includeTime: true, fromTime: '09:00', toTime: '17:30'}, now);
same('2024-02-29 09:00:00', custom.start, 'Custom time start');
same('2024-03-01 17:30:59', custom.end, 'Custom time inclusive minute end');
var cappedToday = range.resolve({from: '2026-08-25', to: '2026-08-25', includeTime: true, fromTime: '00:00', toTime: '23:59'}, now);
same('2026-08-25 07:05:09', cappedToday.end, 'Today custom end caps at now');

var invalid = false;
try { range.resolve({from: '2026-08-25', to: '2026-08-24'}, now); } catch (error) { invalid = true; }
same(true, invalid, 'Invalid reverse range rejected');
invalid = false;
try { range.resolve({from: '2026-08-26', to: '2026-08-26'}, now); } catch (error) { invalid = true; }
same(true, invalid, 'Future custom range rejected');

console.log('Date range tests passed');
