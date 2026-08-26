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

var thisYear = range.preset('year', now);
same('2026-01-01', thisYear.from, 'This year starts on 1 January of the current year');
same('2026-08-25', thisYear.to, 'This year ends on the current date');

var lastYear = range.preset('lastyear', now);
same('2025-01-01', lastYear.from, 'Last year starts on 1 January of the previous year');
same('2025-12-31', lastYear.to, 'Last year ends on 31 December of the previous year');

var twoYearsAgo = range.shift(lastYear, -1, now);
same('2024-01-01', twoYearsAgo.from, 'Shifting last year back one more year keeps full calendar-year boundaries');
same('2024-12-31', twoYearsAgo.to, 'Shifting last year back one more year includes the leap day');

var backToLastYear = range.shift(twoYearsAgo, 1, now);
var backToThisYearPartial = range.shift(backToLastYear, 1, now);
same('2026-01-01', backToThisYearPartial.from, 'Shifting forward two years from a full previous year returns to the current year start');
same('2026-08-25', backToThisYearPartial.to, 'Shifting into the current year caps the end at today, not 31 December');

var shiftedThisYearBack = range.shift(thisYear, -1, now);
same('2025-01-01', shiftedThisYearBack.from, 'Shifting This year back one year gives the full previous calendar year start');
same('2025-12-31', shiftedThisYearBack.to, 'Shifting This year back one year gives the full previous calendar year end');

var leapNow = new Date(2024, 1, 29, 12, 0, 0);
var leapThisYear = range.preset('year', leapNow);
same('2024-01-01', leapThisYear.from, 'This year on a leap-year date still starts 1 January');
same('2024-02-29', leapThisYear.to, 'This year on 29 Feb of a leap year ends on the leap day itself');
var leapLastYear = range.preset('lastyear', leapNow);
same('2023-01-01', leapLastYear.from, 'Last year computed during a leap year starts on the prior non-leap year');
same('2023-12-31', leapLastYear.to, 'Last year computed during a leap year ends 31 December of the prior non-leap year');
var leapLastYearShiftedBack = range.shift(leapThisYear, -1, leapNow);
same('2023-01-01', leapLastYearShiftedBack.from, 'Shifting a leap-year This year back one year lands on the prior non-leap year start');
same('2023-12-31', leapLastYearShiftedBack.to, 'Shifting a leap-year This year back one year lands on the prior non-leap year end, not truncated by the leap day');

var custom = range.resolve({from: '2024-02-29', to: '2024-03-01', includeTime: true, fromTime: '09:00', toTime: '17:30'}, now);
same('2024-02-29 09:00:00', custom.start, 'Custom time start');
same('2024-03-01 17:30:59', custom.end, 'Custom time inclusive minute end');

var thisYearCanonical = range.resolve(thisYear, now);
same('2026-01-01 00:00:00', thisYearCanonical.start, 'This year canonical start is date-only midnight');
same('2026-08-25 07:05:09', thisYearCanonical.end, 'This year canonical end uses now when the range reaches today');

var lastYearCanonical = range.resolve(lastYear, now);
same('2025-01-01 00:00:00', lastYearCanonical.start, 'Last year canonical start is date-only midnight');
same('2025-12-31 23:59:59', lastYearCanonical.end, 'Last year canonical end is the full last second of 31 December');
var cappedToday = range.resolve({from: '2026-08-25', to: '2026-08-25', includeTime: true, fromTime: '00:00', toTime: '23:59'}, now);
same('2026-08-25 07:05:09', cappedToday.end, 'Today custom end caps at now');

var invalid = false;
try { range.resolve({from: '2026-08-25', to: '2026-08-24'}, now); } catch (error) { invalid = true; }
same(true, invalid, 'Invalid reverse range rejected');
invalid = false;
try { range.resolve({from: '2026-08-26', to: '2026-08-26'}, now); } catch (error) { invalid = true; }
same(true, invalid, 'Future custom range rejected');

console.log('Date range tests passed');
