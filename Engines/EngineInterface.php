<?php

namespace FreePBX\modules\Concurrencycount\Engines;

interface EngineInterface {
	public function name(): string;
	public function calculatePerName(string $mode, array $rows): array;
	public function calculateGroup(array $rows): array;
}
