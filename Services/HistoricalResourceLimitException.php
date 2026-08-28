<?php

namespace FreePBX\modules\Concurrencycount\Services;

class HistoricalResourceLimitException extends \RuntimeException {
	public $usageBytes;
	public $safeCeilingBytes;
	public $hardLimitBytes;

	public function __construct(int $usageBytes, int $safeCeilingBytes, int $hardLimitBytes) {
		parent::__construct("This calculation reached Concurrency Count's safe memory allowance.");
		$this->usageBytes = $usageBytes;
		$this->safeCeilingBytes = $safeCeilingBytes;
		$this->hardLimitBytes = $hardLimitBytes;
	}
}
