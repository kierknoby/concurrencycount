<?php

namespace FreePBX\modules\Concurrencycount\Services;

class CliCancellationControl {
	private $interrupted = false;
	private $signal = 0;
	private $installed = false;
	private $previousAsync = false;
	private $previousHandlers = [];

	public function install(): bool {
		if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) return false;
		$this->previousAsync = pcntl_async_signals();
		pcntl_async_signals(true);
		$this->previousHandlers[SIGINT] = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGINT) : SIG_DFL;
		pcntl_signal(SIGINT, [$this, 'handleSignal']);
		if (defined('SIGTERM')) {
			$this->previousHandlers[SIGTERM] = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGTERM) : SIG_DFL;
			pcntl_signal(SIGTERM, [$this, 'handleSignal']);
		}
		$this->installed = true;
		return true;
	}

	public function uninstall(): void {
		if (!$this->installed) return;
		foreach ($this->previousHandlers as $signal => $handler) pcntl_signal($signal, $handler);
		pcntl_async_signals($this->previousAsync);
		$this->installed = false;
	}

	public function handleSignal(int $signal): void {
		$this->interrupted = true;
		$this->signal = $signal;
	}

	public function isInterrupted(): bool {
		return $this->interrupted;
	}

	public function signal(): int {
		return $this->signal;
	}

	public function isInstalled(): bool {
		return $this->installed;
	}
}
