'use strict';
const fs = require('fs');
const vm = require('vm');
function assert(condition, message) { if (!condition) throw new Error(message); }
const root = {_ccLiveLoaded: true};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/live-view.js', 'utf8'), {window: root, self: root});
const normalise = root.CCThresholdInput.normalise;
['0', '7', '10000'].forEach(value => assert(normalise(value) === value, 'Digits must remain unchanged: ' + value));
[
	['1.5', '15'], ['-1', '1'], ['+2', '2'], ['1e2', '12'], ['1E2', '12'],
	[' 42 ', '42'], ['abc', ''], ['4a.2', '42'], ['4@2', '42'], ['', '']
].forEach(fixture => assert(normalise(fixture[0]) === fixture[1], 'Invalid characters must be removed from: ' + fixture[0]));
console.log('Threshold input tests passed');
