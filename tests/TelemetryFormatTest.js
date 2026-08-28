'use strict';

const format = require('../assets/js/telemetry-format.js');

function assert(condition, message) {
	if (!condition) throw new Error(message);
}

assert(format.eta(false, null) === 'Estimating...', 'Unreliable ETA must remain in warm-up state');
assert(format.eta(true, 90) === '00:01:30', 'Reliable 90-second ETA must use clock formatting');
assert(format.eta(true, 0.25) === '< 1 second', 'Positive sub-second ETA must not render as zero');
assert(format.eta(true, 0) === 'Estimating...', 'Exact zero must not masquerade as an active reliable ETA');
assert(format.eta(true, -5) === 'Estimating...', 'Negative ETA must not be displayed');
assert(format.duration(-1) === '00:00:00', 'Negative elapsed/runtime values must clamp safely');

console.log('Telemetry formatter tests passed');
