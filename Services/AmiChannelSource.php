<?php

namespace FreePBX\modules\Concurrencycount\Services;

class AmiChannelSource {
	public function snapshot($astman, int $timeoutSeconds = 3): array {
		if (!is_object($astman) || !method_exists($astman, 'send_request') || !method_exists($astman, 'wait_response') || !method_exists($astman, 'add_event_handler') || !method_exists($astman, 'connected') || !$astman->connected()) {
			return $this->unavailable('Asterisk Manager Interface is unavailable.');
		}
		$actionId = 'cc-' . bin2hex(random_bytes(8));
		$channels = [];
		$complete = false;
		$expectedItems = null;
		$channelHandler = function ($event, $data) use ($actionId, &$channels): void {
			if (!isset($data['ActionID']) || !hash_equals($actionId, (string)$data['ActionID'])) return;
			$channels[] = $data;
		};
		$completeHandler = function ($event, $data) use ($actionId, &$complete, &$expectedItems): void {
			if (!isset($data['ActionID']) || !hash_equals($actionId, (string)$data['ActionID'])) return;
			$complete = true;
			if (isset($data['ListItems']) && is_numeric($data['ListItems'])) $expectedItems = (int)$data['ListItems'];
		};
		$astman->add_event_handler('CoreShowChannel', $channelHandler);
		$astman->add_event_handler('CoreShowChannelsComplete', $completeHandler);
		$deadline = microtime(true) + max(1, $timeoutSeconds);
		try {
			stream_set_timeout($astman->socket, max(1, $timeoutSeconds));
			$response = $astman->send_request('CoreShowChannels', ['ActionID' => $actionId]);
			if (!is_array($response) || !isset($response['Response']) || strcasecmp((string)$response['Response'], 'Success') !== 0) {
				return $this->unavailable('Asterisk rejected the channel snapshot request.');
			}
			while (!$complete && microtime(true) < $deadline && $astman->connected()) {
				$packet = $astman->wait_response(true, true);
				if ($packet === false) break;
			}
			if (!$complete || !$astman->connected()) return $this->unavailable('Asterisk channel snapshot did not complete.');
			if ($expectedItems !== null && $expectedItems !== count($channels)) return $this->unavailable('Asterisk channel snapshot was incomplete.');
			return ['available' => true, 'channels' => $channels, 'action_id' => $actionId];
		} catch (\Throwable $exception) {
			return $this->unavailable($exception->getMessage());
		} finally {
			$this->removeHandler($astman, 'coreshowchannel', $channelHandler);
			$this->removeHandler($astman, 'coreshowchannelscomplete', $completeHandler);
			if (isset($astman->socket) && is_resource($astman->socket)) stream_set_timeout($astman->socket, 30);
		}
	}

	private function removeHandler($astman, string $event, callable $handler): void {
		if (!isset($astman->event_handlers[$event]) || !is_array($astman->event_handlers[$event])) return;
		$astman->event_handlers[$event] = array_values(array_filter($astman->event_handlers[$event], function ($registered) use ($handler): bool {
			return $registered !== $handler;
		}));
		if (empty($astman->event_handlers[$event])) unset($astman->event_handlers[$event]);
	}

	private function unavailable(string $message): array {
		return ['available' => false, 'channels' => [], 'message' => $message];
	}
}
