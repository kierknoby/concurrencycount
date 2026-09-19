const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(__dirname + '/../assets/js/date-format.js', 'utf8');
const context = {window: {}, Date: Date};
vm.runInNewContext(source, context);
const format = context.window.CCDateFormat;
function assert(condition, message) { if (!condition) throw new Error(message); }

const sample = new Date(2026, 8, 19, 7, 5, 9);
assert(format.localDate(sample) === '2026-09-19', 'Local date must use ISO year-month-day order');
assert(format.localTime(sample) === '07:05', 'Local time must use 24-hour hour-minute output');
assert(format.localDateTime(sample) === '2026-09-19 07:05', 'Local date-time must use ISO date and 24-hour time');
assert(format.localYearMonth(sample) === '2026-09', 'Local year-month must use ISO year-month order');
console.log('Date formatting tests passed');
