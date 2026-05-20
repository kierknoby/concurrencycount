<?php

namespace FreePBX\modules\Concurrencycount\Engines;

class Registry {
	public static function getAvailableEngines(): array {
		return [
			'original' => [
				'id' => 'original',
				'label' => 'Original',
				'class' => Original::class,
				'experimental' => false,
			],
			'sweep' => [
				'id' => 'sweep',
				'label' => 'Sweep',
				'class' => Sweep::class,
				'experimental' => true,
			],
		];
	}
}
