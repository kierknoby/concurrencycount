<?php

namespace PHPUnit\Framework {
	if (!class_exists('PHPUnit\Framework\TestCase')) {
		abstract class TestCase {
			protected function setUp(): void {}

			protected function assertSame($expected, $actual, string $message = ''): void {
				if ($expected !== $actual) {
					throw new \Exception(($message !== '' ? $message . "\n" : '') . 'Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
				}
			}
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use FreePBX\modules\Concurrencycount\Engines\Registry;

	if (!interface_exists('BMO')) {
		interface BMO {}
	}

	require_once __DIR__ . '/../Concurrencycount.class.php';

	if (!class_exists('TestableConcurrencycount')) {
		class TestableConcurrencycount extends \FreePBX\modules\Concurrencycount {
			public function __construct() {
				// Skip the parent constructor; engine parity only needs pure helpers.
			}
		}
	}

	class EngineParityTest extends TestCase {
		private TestableConcurrencycount $cc;

		protected function setUp(): void {
			$this->cc = new TestableConcurrencycount();
		}

		/**
		 * @dataProvider provideFixtures
		 */
		public function testRegisteredEnginesMatchExpectedExtensionPerName(array $rows): void {
			$expected = $this->callPrivate('expectedDemoPerName', [$rows, 'extension']);
			$engineRows = $this->toEngineRows($rows);
			$allNames = $this->callPrivateBuildAllNames('extension', $engineRows);
			$expected['per_name'] = $this->shapeExpectedPerName($expected['per_name'], $allNames);

			foreach (Registry::getAvailableEngines() as $id => $meta) {
				$engine = new $meta['class']($this->engineOptions($allNames));
				$actual = $engine->calculatePerName('extension', $engineRows);
				$this->assertSame($expected['per_name'], $actual['per_name'], $id . ' extension per-name output mismatch');
				$this->assertSame($expected['global_max'], $actual['global_max'], $id . ' extension global max mismatch');
			}
		}

		/**
		 * @dataProvider provideTrunkFixtures
		 */
		public function testRegisteredEnginesMatchExpectedTrunkPerName(array $rows, array $trunks): void {
			$expected = $this->callPrivate('expectedDemoPerName', [$rows, 'trunk']);
			$engineRows = $this->toEngineRows($rows);
			$allNames = $this->callPrivateBuildAllNames('trunk', $engineRows, $trunks);
			$expected['per_name'] = $this->shapeExpectedPerName($expected['per_name'], $allNames);

			foreach (Registry::getAvailableEngines() as $id => $meta) {
				$engine = new $meta['class']($this->engineOptions($allNames));
				$actual = $engine->calculatePerName('trunk', $engineRows);
				$this->assertSame($expected['per_name'], $actual['per_name'], $id . ' trunk per-name output mismatch');
				$this->assertSame($expected['global_max'], $actual['global_max'], $id . ' trunk global max mismatch');
			}
		}

		/**
		 * @dataProvider provideGroupFixtures
		 */
		public function testRegisteredEnginesMatchExpectedGroup(array $rows): void {
			$expected = $this->callPrivate('expectedDemoGroup', [$rows]);

			foreach (Registry::getAvailableEngines() as $id => $meta) {
				$engine = new $meta['class']($this->engineOptions([]));
				$actual = $engine->calculateGroup($rows);
				$this->assertSame($expected['max_concurrency'], $actual['max_concurrency'], $id . ' group max mismatch');
				$this->assertSame($expected['peak_ranges'], $actual['peak_ranges'], $id . ' group peak ranges mismatch');
			}
		}

		public function provideFixtures(): array {
			return [
				'empty' => [
					[],
				],
				'single call' => [
					[
						$this->row('2001-01-01 09:00:00', 60, 'PJSIP/101-aaaaaa'),
					],
				],
				'overlapping same extension' => [
					[
						$this->row('2001-01-01 09:00:00', 120, 'PJSIP/101-aaaaaa'),
						$this->row('2001-01-01 09:00:30', 90, 'PJSIP/101-bbbbbb'),
						$this->row('2001-01-01 09:01:00', 60, 'PJSIP/102-cccccc'),
					],
				],
				'simultaneous start' => [
					[
						$this->row('2001-01-01 10:00:00', 120, 'PJSIP/101-aaaaaa'),
						$this->row('2001-01-01 10:00:00', 120, 'PJSIP/102-bbbbbb'),
					],
				],
				'touching inclusive second' => [
					[
						$this->row('2001-01-01 11:00:00', 60, 'PJSIP/101-aaaaaa'),
						$this->row('2001-01-01 11:01:00', 60, 'PJSIP/102-bbbbbb'),
						$this->row('2001-01-01 11:01:01', 30, 'PJSIP/103-cccccc'),
					],
				],
				'long duration' => [
					[
						$this->row('2001-01-01 12:00:00', 90000, 'PJSIP/104-eeeeee'),
					],
				],
				'peak at end of range' => [
					[
						$this->row('2001-01-01 23:00:00', 60, 'PJSIP/105-ffffff'),
						$this->row('2001-01-01 23:00:00', 60, 'PJSIP/106-a1b2c3'),
					],
				],
				'mixed valid and invalid channels' => [
					[
						$this->row('2001-01-01 13:00:00', 60, 'PJSIP/101-aaaaaa'),
						$this->row('2001-01-01 13:00:10', 60, 'SIP/102-bbbbbb'),
						$this->row('2001-01-01 13:00:20', 60, 'PJSIP/alice-cccccc'),
						$this->row('2001-01-01 13:00:30', 60, ''),
					],
				],
			];
		}

		public function provideTrunkFixtures(): array {
			return [
				'trunk overlap' => [
					[
						$this->row('2001-01-01 09:00:00', 120, 'PJSIP/voipfone-aaaaaa'),
						$this->row('2001-01-01 09:00:30', 90, 'PJSIP/voipfone-bbbbbb'),
						$this->row('2001-01-01 09:01:00', 60, 'PJSIP/sipgate-cccccc'),
					],
					['sipgate', 'unusedtrunk', 'voipfone'],
				],
				'trunk long duration' => [
					[
						$this->row('2001-01-01 12:00:00', 90000, 'PJSIP/voipfone-dddddd'),
					],
					['voipfone'],
				],
				'trunk invalid channels' => [
					[
						$this->row('2001-01-01 13:00:00', 60, 'PJSIP/voipfone-eeeeee'),
						$this->row('2001-01-01 13:00:10', 60, 'PJSIP/12345-ffffff'),
						$this->row('2001-01-01 13:00:20', 60, 'PJSIP/bad-zzzzzz'),
					],
					['bad', 'voipfone'],
				],
			];
		}

		public function provideGroupFixtures(): array {
			return $this->provideFixtures();
		}

		private function row(string $calldate, int $duration, string $channel): array {
			return [
				'calldate' => $calldate,
				'duration' => $duration,
				'channel' => $channel,
				'dstchannel' => '',
				'src' => '',
				'dst' => '',
				'accountcode' => 'CCDEMO',
				'uniqueid' => '',
				'linkedid' => '',
			];
		}

		private function toEngineRows(array $rows): array {
			$engineRows = [];
			foreach ($rows as $row) {
				$engineRows[] = [
					'calldate' => $row['calldate'],
					'duration' => $row['duration'],
					'chan' => $row['channel'],
				];
			}
			return $engineRows;
		}

		private function shapeExpectedPerName(array $expected, array $allNames): array {
			ksort($allNames);
			$shaped = [];
			foreach ($allNames as $name => $_unused) {
				$shaped[$name] = isset($expected[$name]) ? $expected[$name] : 0;
			}
			return $shaped;
		}

		private function engineOptions(array $allNames): array {
			return [
				'all_names' => $allNames,
				'coalesce_ranges' => function (array $times): array {
					return $this->callPrivate('coalesceRanges', [$times]);
				},
				'check_overrun' => function (int $processed, int $total): void {},
			];
		}

		private function callPrivateBuildAllNames(string $mode, array $rows, array $trunks = []): array {
			$ref = new ReflectionMethod($this->cc, 'buildAllNames');
			$ref->setAccessible(true);
			return $ref->invokeArgs($this->cc, [$mode, $rows, $trunks]);
		}

		private function callPrivate(string $method, array $args): array {
			$ref = new ReflectionMethod($this->cc, $method);
			$ref->setAccessible(true);
			return $ref->invokeArgs($this->cc, $args);
		}
	}

	if (PHP_SAPI === 'cli' && realpath(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') === __FILE__) {
		$test = new EngineParityTest();
		$ref = new ReflectionClass($test);
		$setUp = $ref->getMethod('setUp');
		$setUp->setAccessible(true);
		$ran = 0;
		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if (strpos($method->getName(), 'test') !== 0) {
				continue;
			}
			$doc = $method->getDocComment() ?: '';
			$provider = null;
			if (preg_match('/@dataProvider\s+([A-Za-z0-9_]+)/', $doc, $m)) {
				$provider = $m[1];
			}
			$cases = $provider ? $test->$provider() : ['default' => []];
			foreach ($cases as $name => $args) {
				$setUp->invoke($test);
				$method->invokeArgs($test, $args);
				$ran++;
				echo $method->getName() . ' [' . $name . "] ok\n";
			}
		}
		echo 'Engine parity tests passed: ' . $ran . "\n";
	}
}
