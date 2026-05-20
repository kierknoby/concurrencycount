<?php
/**
 * Concurrency Count input validation tests.
 *
 * Exercises the pure-function input normalisers without requiring a FreePBX
 * install. Skips anything that needs the FreePBX BMO container or a CDR
 * database. Those need integration tests on a real droplet.
 *
 * Run from the module root:
 *   ./vendor/bin/phpunit tests/
 *
 * If you don't have PHPUnit installed locally, see the README for a
 * containerised test command.
 */

use PHPUnit\Framework\TestCase;

/**
 * Light shim so we can instantiate the BMO class without a FreePBX object.
 * The constructor requires one, so we extend and override.
 */
class TestableConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public function __construct() {
		// Skip the parent constructor; we only test pure functions.
	}
}

require_once __DIR__ . '/../Concurrencycount.class.php';

class InputValidationTest extends TestCase {

	private TestableConcurrencycount $cc;

	protected function setUp(): void {
		$this->cc = new TestableConcurrencycount();
	}

	/* ---------- normaliseMode ---------- */

	public function testModeAcceptsFullNames(): void {
		$this->assertSame('trunk', $this->cc->normaliseMode('trunks'));
		$this->assertSame('extension', $this->cc->normaliseMode('extensions'));
		$this->assertSame('group', $this->cc->normaliseMode('group'));
	}

	public function testModeAcceptsAbbreviations(): void {
		$this->assertSame('trunk', $this->cc->normaliseMode('t'));
		$this->assertSame('trunk', $this->cc->normaliseMode('trk'));
		$this->assertSame('extension', $this->cc->normaliseMode('ext'));
		$this->assertSame('extension', $this->cc->normaliseMode('e'));
		$this->assertSame('group', $this->cc->normaliseMode('g'));
		$this->assertSame('group', $this->cc->normaliseMode('grp'));
	}

	public function testModeIsCaseInsensitive(): void {
		$this->assertSame('trunk', $this->cc->normaliseMode('TRUNKS'));
		$this->assertSame('extension', $this->cc->normaliseMode('Ext'));
	}

	public function testModeTrimsWhitespace(): void {
		$this->assertSame('trunk', $this->cc->normaliseMode('  trunk  '));
	}

	public function testModeRejectsGarbage(): void {
		$this->assertNull($this->cc->normaliseMode('foo'));
		$this->assertNull($this->cc->normaliseMode(''));
		$this->assertNull($this->cc->normaliseMode('123'));
	}

	/* ---------- normaliseYear ---------- */

	public function testYearSingleDigitExpandsTo200X(): void {
		$this->assertSame(2005, $this->cc->normaliseYear('5'));
		$this->assertSame(2000, $this->cc->normaliseYear('0'));
	}

	public function testYearTwoDigitsExpandTo20XX(): void {
		$this->assertSame(2025, $this->cc->normaliseYear('25'));
		$this->assertSame(2010, $this->cc->normaliseYear('10'));
	}

	public function testYearFourDigitsAccepted(): void {
		$this->assertSame(2025, $this->cc->normaliseYear('2025'));
		$this->assertSame(2000, $this->cc->normaliseYear('2000'));
	}

	public function testYearRejectsFuture(): void {
		$nextYear = (int)date('Y') + 1;
		$this->assertNull($this->cc->normaliseYear((string)$nextYear));
		$this->assertNull($this->cc->normaliseYear(substr((string)$nextYear, 2)));
	}

	public function testYearRejectsBefore2000(): void {
		$this->assertNull($this->cc->normaliseYear('1999'));
	}

	public function testYearRejectsGarbage(): void {
		$this->assertNull($this->cc->normaliseYear('abc'));
		$this->assertNull($this->cc->normaliseYear(''));
		$this->assertNull($this->cc->normaliseYear('12345'));
	}

	/* ---------- parseMonth ---------- */

	public function testMonthAcceptsNumeric(): void {
		$m = $this->cc->parseMonth('4');
		$this->assertNotNull($m);
		$this->assertSame('04', $m['num']);
		$this->assertSame('April', $m['name']);
	}

	public function testMonthAcceptsFullName(): void {
		$m = $this->cc->parseMonth('April');
		$this->assertNotNull($m);
		$this->assertSame('04', $m['num']);
	}

	public function testMonthAcceptsShortName(): void {
		$m = $this->cc->parseMonth('Apr');
		$this->assertNotNull($m);
		$this->assertSame('04', $m['num']);
	}

	public function testMonthRejectsReservedWords(): void {
		$this->assertNull($this->cc->parseMonth('now'));
		$this->assertNull($this->cc->parseMonth('today'));
		$this->assertNull($this->cc->parseMonth('tomorrow'));
		$this->assertNull($this->cc->parseMonth('week'));
		$this->assertNull($this->cc->parseMonth('hour'));
	}

	public function testMonthRejectsOutOfRange(): void {
		$this->assertNull($this->cc->parseMonth('13'));
		$this->assertNull($this->cc->parseMonth('0'));
		$this->assertNull($this->cc->parseMonth('999'));
	}

	public function testMonthRejectsGarbage(): void {
		$this->assertNull($this->cc->parseMonth(''));
		$this->assertNull($this->cc->parseMonth('xyz'));
	}

	/* ---------- isTodayShorthand / isYesterdayShorthand ---------- */

	public function testTodayShorthandAcceptsAllForms(): void {
		foreach (['t', 'to', 'tod', 'toda', 'today', 'TODAY', 'Today'] as $s) {
			$this->assertTrue($this->cc->isTodayShorthand($s), "Should accept: $s");
		}
	}

	public function testTodayShorthandRejectsOthers(): void {
		$this->assertFalse($this->cc->isTodayShorthand('tomorrow'));
		$this->assertFalse($this->cc->isTodayShorthand(''));
		$this->assertFalse($this->cc->isTodayShorthand('yesterday'));
	}

	public function testYesterdayShorthandAcceptsAllForms(): void {
		foreach (['y', 'ye', 'yes', 'yest', 'yeste', 'yester', 'yesterd', 'yesterda', 'yesterday'] as $s) {
			$this->assertTrue($this->cc->isYesterdayShorthand($s), "Should accept: $s");
		}
	}

	/* ---------- validatePartialDate ---------- */

	public function testPartialDateAcceptsFullForm(): void {
		$this->assertTrue($this->cc->validatePartialDate('2025-04-15 10:30:45'));
	}

	public function testPartialDateAcceptsDateOnly(): void {
		$this->assertTrue($this->cc->validatePartialDate('2025-04-15'));
	}

	public function testPartialDateAcceptsYearMonth(): void {
		$this->assertTrue($this->cc->validatePartialDate('2025-04'));
	}

	public function testPartialDateAcceptsYearOnly(): void {
		$this->assertTrue($this->cc->validatePartialDate('2025'));
	}

	public function testPartialDateRejectsInvalidYearRange(): void {
		$this->assertFalse($this->cc->validatePartialDate('1999'));
		$this->assertFalse($this->cc->validatePartialDate('2100'));
	}

	public function testPartialDateRejectsInvalidMonth(): void {
		$this->assertFalse($this->cc->validatePartialDate('2025-13'));
		$this->assertFalse($this->cc->validatePartialDate('2025-00'));
	}

	public function testPartialDateRejectsInvalidDay(): void {
		$this->assertFalse($this->cc->validatePartialDate('2025-02-30'));
		$this->assertFalse($this->cc->validatePartialDate('2025-04-31'));
		$this->assertFalse($this->cc->validatePartialDate('2025-04-00'));
	}

	public function testPartialDateRejectsInvalidTime(): void {
		$this->assertFalse($this->cc->validatePartialDate('2025-04-15 25:00:00'));
		$this->assertFalse($this->cc->validatePartialDate('2025-04-15 10:60:00'));
		$this->assertFalse($this->cc->validatePartialDate('2025-04-15 10:30:60'));
	}

	public function testPartialDateRejectsGarbage(): void {
		$this->assertFalse($this->cc->validatePartialDate(''));
		$this->assertFalse($this->cc->validatePartialDate('abc'));
		$this->assertFalse($this->cc->validatePartialDate('2025/04/15'));
	}

	/* ---------- normaliseStartDate ---------- */

	public function testStartDateBlankReturnsYear2000(): void {
		$this->assertSame('2000-01-01 00:00:00', $this->cc->normaliseStartDate(''));
	}

	public function testStartDateShorthandYearExpands(): void {
		$this->assertSame('2005-01-01 00:00:00', $this->cc->normaliseStartDate('5'));
		$this->assertSame('2025-01-01 00:00:00', $this->cc->normaliseStartDate('25'));
		$this->assertSame('2020-01-01 00:00:00', $this->cc->normaliseStartDate('2020'));
	}

	public function testStartDateYearMonthExpands(): void {
		$this->assertSame('2025-04-01 00:00:00', $this->cc->normaliseStartDate('2025-04'));
	}

	public function testStartDateRejectsTodayKeyword(): void {
		$this->assertNull($this->cc->normaliseStartDate('today'));
		$this->assertNull($this->cc->normaliseStartDate('yesterday'));
	}

	public function testStartDateRejects3DigitYear(): void {
		$this->assertNull($this->cc->normaliseStartDate('123'));
	}

	/* ---------- normaliseEndDate ---------- */

	public function testEndDateBlankReturnsNow(): void {
		$now = $this->cc->normaliseEndDate('');
		$this->assertNotNull($now);
		// Should be within a second of date()
		$this->assertLessThanOrEqual(1, abs(strtotime($now) - time()));
	}

	public function testEndDateCurrentYearReturnsNow(): void {
		$year = (string)date('Y');
		$result = $this->cc->normaliseEndDate($year);
		$this->assertNotNull($result);
		// Should be roughly now, not Dec 31
		$this->assertLessThanOrEqual(1, abs(strtotime($result) - time()));
	}

	public function testEndDatePastYearReturnsYearEnd(): void {
		$result = $this->cc->normaliseEndDate('2020');
		$this->assertSame('2020-12-31 23:59:59', $result);
	}

	public function testEndDatePastYearMonthReturnsMonthEnd(): void {
		$result = $this->cc->normaliseEndDate('2020-04');
		$this->assertSame('2020-04-30 23:59:59', $result);
	}

	public function testEndDateRejectsFutureYear(): void {
		$nextYear = (int)date('Y') + 1;
		$this->assertNull($this->cc->normaliseEndDate((string)$nextYear));
	}

	/* ---------- coalesceRanges ---------- */
	/* Tested via reflection since it's private */

	public function testCoalesceRangesEmpty(): void {
		$result = $this->invokePrivate('coalesceRanges', [[]]);
		$this->assertSame([], $result);
	}

	public function testCoalesceRangesSingleTimestamp(): void {
		$ts = strtotime('2025-04-15 10:30:00');
		$result = $this->invokePrivate('coalesceRanges', [[$ts]]);
		$this->assertCount(1, $result);
		$this->assertSame($result[0]['from'], $result[0]['to']);
	}

	public function testCoalesceRangesContiguous(): void {
		$base = strtotime('2025-04-15 10:30:00');
		$sequence = [$base, $base + 1, $base + 2, $base + 3];
		$result = $this->invokePrivate('coalesceRanges', [$sequence]);
		$this->assertCount(1, $result);
		$this->assertNotSame($result[0]['from'], $result[0]['to']);
	}

	public function testCoalesceRangesDisjoint(): void {
		$a = strtotime('2025-04-15 10:30:00');
		$b = $a + 100;
		$result = $this->invokePrivate('coalesceRanges', [[$a, $b]]);
		$this->assertCount(2, $result);
	}

	public function testCoalesceRangesMultiSpan(): void {
		$a = strtotime('2025-04-15 10:30:00');
		$result = $this->invokePrivate('coalesceRanges', [[
			$a, $a + 1, $a + 2,           // first range: 3 seconds
			$a + 10, $a + 11,             // second range: 2 seconds
			$a + 100                       // third range: 1 second
		]]);
		$this->assertCount(3, $result);
	}

	/* ---------- helpers ---------- */

	private function invokePrivate(string $name, array $args) {
		$ref = new \ReflectionMethod($this->cc, $name);
		$ref->setAccessible(true);
		return $ref->invokeArgs($this->cc, $args);
	}
}
