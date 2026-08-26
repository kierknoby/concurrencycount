<?php

require_once __DIR__ . '/../Services/AmiChannelSource.php';

use FreePBX\modules\Concurrencycount\Services\AmiChannelSource;

function ami_assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) throw new Exception($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

class FakeAmiManager {
	public $event_handlers = [];
	public $socket;
	public $mode;
	private $connected = true;
	private $actionId = '';
	private $packets = 0;

	public function __construct(string $mode) {
		$this->mode = $mode;
		$this->socket = fopen('php://temp', 'r+');
		if ($mode === 'disconnected') $this->connected = false;
	}
	public function connected(): bool { return $this->connected; }
	public function add_event_handler($event, $handler): bool {
		$this->event_handlers[strtolower($event)][] = $handler;
		return true;
	}
	public function send_request($action, $parameters = []): array {
		$this->actionId = (string)$parameters['ActionID'];
		return ['Response' => 'Success'];
	}
	public function wait_response($allowTimeout = false, $returnOnEvent = false) {
		$this->packets++;
		if ($this->mode === 'timeout') return [];
		if ($this->packets === 1 && in_array($this->mode, ['complete', 'mismatch'], true)) {
			$this->emit('coreshowchannel', ['Event' => 'CoreShowChannel', 'ActionID' => 'unrelated', 'Channel' => 'PJSIP/999-aaaaaa']);
			$this->emit('coreshowchannel', ['Event' => 'CoreShowChannel', 'ActionID' => $this->actionId, 'Channel' => 'PJSIP/203-bbbbbb']);
			return ['Event' => 'CoreShowChannel'];
		}
		if ($this->packets === 1 && $this->mode === 'empty') {
			$this->emit('coreshowchannelscomplete', ['Event' => 'CoreShowChannelsComplete', 'ActionID' => $this->actionId, 'ListItems' => '0']);
			return ['Event' => 'CoreShowChannelsComplete'];
		}
		if ($this->packets === 2 && in_array($this->mode, ['complete', 'mismatch'], true)) {
			$this->emit('coreshowchannelscomplete', ['Event' => 'CoreShowChannelsComplete', 'ActionID' => $this->actionId, 'ListItems' => $this->mode === 'mismatch' ? '2' : '1']);
			return ['Event' => 'CoreShowChannelsComplete'];
		}
		return [];
	}
	private function emit(string $event, array $data): void {
		foreach (isset($this->event_handlers[$event]) ? $this->event_handlers[$event] : [] as $handler) $handler($event, $data, '127.0.0.1', 5038);
	}
}

$source = new AmiChannelSource();
$completeManager = new FakeAmiManager('complete');
$complete = $source->snapshot($completeManager, 1);
ami_assert_same(true, $complete['available'], 'Complete snapshot available');
ami_assert_same(1, count($complete['channels']), 'Only ActionID-matched channel retained');
ami_assert_same('PJSIP/203-bbbbbb', $complete['channels'][0]['Channel'], 'Matched channel returned');
ami_assert_same([], $completeManager->event_handlers, 'Temporary handlers removed');

$empty = $source->snapshot(new FakeAmiManager('empty'), 1);
ami_assert_same(true, $empty['available'], 'Explicit zero-item complete snapshot is valid');
ami_assert_same(0, count($empty['channels']), 'Explicit empty snapshot retained');

$mismatch = $source->snapshot(new FakeAmiManager('mismatch'), 1);
ami_assert_same(false, $mismatch['available'], 'ListItems mismatch rejected');
$timeout = $source->snapshot(new FakeAmiManager('timeout'), 1);
ami_assert_same(false, $timeout['available'], 'Missing completion rejected');
$disconnected = $source->snapshot(new FakeAmiManager('disconnected'), 1);
ami_assert_same(false, $disconnected['available'], 'Disconnected AMI rejected');

echo "AMI channel source tests passed\n";
