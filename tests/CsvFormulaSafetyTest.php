<?php
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/Concurrencycount.class.php';

class CsvFormulaSafetyConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public function __construct() {}
	public function getVersion(): string { return '2.2.2'; }
}
function csv_safety_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

$module = new CsvFormulaSafetyConcurrencycount();
$calls = [];
foreach (['=1+1', '+SUM(1,1)', '-1+1', '@SUM(1,1)', " \t=1+1", "\t@SUM(1,1)", "\x01+SUM(1,1)", '+441234567890'] as $index => $source) {
	$calls[] = [
		'calldate' => '2026-09-17 10:00:0' . $index, 'caller_id' => '', 'source' => $source,
		'destination' => '5551000', 'trunk_channel' => 'PJSIP/carrier-a1', 'direction' => 'outbound',
		'duration' => 30, 'linkedid' => 'linked-' . $index, 'uniqueid' => 'unique-' . $index,
		'call_identity' => 'linkedid:linked-' . $index, 'path' => [],
	];
}
$csv = $module->resultsToCsv([
	'mode' => 'trunk', 'start' => '2026-09-17 00:00:00', 'end' => '2026-09-17 23:59:59',
	'rows_processed' => 8, 'per_name' => ['carrier' => 2], 'global_max' => 2,
	'peak_evidence' => ['carrier' => [['from' => '2026-09-17 10:00:00', 'to' => '2026-09-17 10:01:00', 'peak' => 2, 'calls' => $calls]]],
]);
$stream = fopen('php://temp', 'r+');
fwrite($stream, substr($csv, 3));
rewind($stream);
$evidenceRows = [];
while (($row = fgetcsv($stream)) !== false) {
	if (isset($row[10]) && strpos($row[10], 'linked-') === 0) $evidenceRows[] = $row;
}
fclose($stream);
csv_safety_assert(count($evidenceRows) === 8, 'All formula-safety evidence rows remain valid CSV records');
$expected = ["'=1+1", "'+SUM(1,1)", "'-1+1", "'@SUM(1,1)", "' \t=1+1", "'\t@SUM(1,1)", "'\x01+SUM(1,1)", '+441234567890'];
foreach ($expected as $index => $source) csv_safety_assert($evidenceRows[$index][5] === $source, 'CSV source cell safety mismatch at fixture ' . $index);
foreach ($evidenceRows as $row) csv_safety_assert($row[3] === '2' && $row[9] === '30', 'Concurrency and duration remain unescaped numeric CSV values');
echo "CSV formula safety tests passed\n";