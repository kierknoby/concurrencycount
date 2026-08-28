'use strict';

const format = require('../assets/js/telemetry-format.js');

function assert(condition, message) {
	if (!condition) throw new Error(message);
}

assert(format.eta(false, null) === 'Estimating...', 'Unreliable ETA must remain in warm-up state');
assert(format.eta(true, 90) === '00:01:30', 'Reliable 90-second ETA must use clock formatting');
assert(format.eta(true, 0.25) === '< 1 second', 'Positive sub-second ETA must not render as zero');
assert(format.eta(true, 0) === '< 1 second', 'A reliable local ETA reaching zero must remain an active sub-second estimate');
assert(format.eta(true, -5) === 'Estimating...', 'Negative ETA must not be displayed');
assert(format.duration(-1) === '00:00:00', 'Negative elapsed/runtime values must clamp safely');

let state = format.synchronize(null, {elapsed: 4, runtime_remaining: 3595, eta_reliable: true, estimated_remaining: 72}, 1000);
let timers = format.snapshot(state, 2000);
assert(timers.elapsed === 5, 'Elapsed 4 must interpolate to 5 after one monotonic second');
assert(timers.runtimeRemaining === 3594, 'Runtime 3595 must interpolate to 3594 after one monotonic second');
assert(timers.etaRemaining === 71, 'Reliable ETA 72 must interpolate to 71 after one monotonic second');
timers = format.snapshot(state, 3000);
assert(timers.elapsed === 6 && timers.runtimeRemaining === 3593 && timers.etaRemaining === 70, 'All timers must use actual monotonic time after two seconds');

let estimating = format.synchronize(null, {elapsed: 0, runtime_remaining: 3600, eta_reliable: false, estimated_remaining: 99}, 0);
assert(format.eta(format.snapshot(estimating, 9000).etaReliable, format.snapshot(estimating, 9000).etaRemaining) === 'Estimating...', 'Unreliable ETA must not start a local countdown');
let subsecond = format.synchronize(null, {elapsed: 1, runtime_remaining: 20, eta_reliable: true, estimated_remaining: 0.4}, 0);
assert(format.eta(format.snapshot(subsecond, 0).etaReliable, format.snapshot(subsecond, 0).etaRemaining) === '< 1 second', 'Authoritative positive sub-second ETA remains explicit');
assert(format.eta(format.snapshot(subsecond, 5000).etaReliable, format.snapshot(subsecond, 5000).etaRemaining) === '< 1 second', 'Local ETA below one second does not infer completion');

state = format.synchronize(state, {elapsed: 10, runtime_remaining: 3500, eta_reliable: true, estimated_remaining: 90}, 3000);
timers = format.snapshot(state, 3000);
assert(timers.elapsed === 10 && timers.runtimeRemaining === 3500 && timers.etaRemaining === 90, 'New telemetry resynchronizes elapsed/runtime and accepts an increased ETA');
state = format.synchronize(state, {elapsed: 9, runtime_remaining: 3498, eta_reliable: true, estimated_remaining: 20}, 4000);
timers = format.snapshot(state, 4000);
assert(timers.elapsed === 11, 'Display jitter must not move elapsed backwards at resynchronization');
assert(timers.runtimeRemaining === 3498 && timers.etaRemaining === 20, 'Backend runtime and decreased ETA remain authoritative');
timers = format.snapshot(state, 10400);
assert(timers.elapsed === 17.4 && timers.runtimeRemaining === 3491.6 && timers.etaRemaining === 13.6, 'A throttled callback uses actual monotonic elapsed time rather than one nominal tick');
timers = format.snapshot(state, 10000000);
assert(timers.runtimeRemaining === 0 && timers.etaRemaining === 0, 'Remaining timers never become negative');

console.log('Telemetry formatter tests passed');
