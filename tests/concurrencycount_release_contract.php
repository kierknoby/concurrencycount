<?php
/**
 * Static release contract for the PHP 7.4 / FreePBX 16 and 17 baseline.
 * Run directly: php tests/concurrencycount_release_contract.php
 */

$root = dirname(__DIR__);
$module = simplexml_load_file($root . '/module.xml');
if ($module === false) throw new Exception('module.xml does not parse');

function contract_assert($condition, $message) {
	if (!$condition) throw new Exception($message);
}

contract_assert((string)$module->version === '2.1.1', 'Unexpected module version');
contract_assert(strpos((string)$module->changelog, '*2.1.1 (28 August 2026)*') !== false, '2.1.1 release date missing');
contract_assert(strpos((string)$module->changelog, '*2.1.0 (27 August 2026)*') !== false, '2.1.0 release date missing');
contract_assert(strpos((string)$module->changelog, '*2.0.1 (27 August 2026)*') !== false, '2.0.1 release history missing');
$mainClass = file_get_contents($root . '/Concurrencycount.class.php');
contract_assert(strpos($mainClass, "const VERSION = '2.1.1';") !== false, 'Fallback PHP version mismatch');
$supported = [];
foreach ($module->supported->version as $version) $supported[] = (string)$version;
contract_assert(in_array('16.0', $supported, true) && in_array('17.0', $supported, true), 'Both supported versions are required');
contract_assert((string)$module->depends->version === '16.0', 'FreePBX minimum version must be 16.0');
$description = (string)$module->description;
foreach (['Live View', 'Live Wall', 'threshold monitoring', 'historical trunk, extension and PBX-wide group reporting', 'GUI/CLI management', 'NOT CURRENTLY SUITABLE FOR PRODUCTION'] as $descriptionConcept) {
	contract_assert(strpos($description, $descriptionConcept) !== false, 'Install-facing description missing: ' . $descriptionConcept);
}
contract_assert(strpos($description, 'Read-only against asteriskcdrdb') === false, 'Install-facing description must not hide Demo CDR writes behind a blanket read-only claim');
foreach (['framework', 'core', 'cdr', 'pm2'] as $dependency) {
	$found = false;
	foreach ($module->depends->module as $item) {
		if (strpos((string)$item, $dependency . ' ge 16.0.0') === 0) $found = true;
	}
	contract_assert($found, 'Missing dependency: ' . $dependency);
}

$runtimeFiles = [
	'Concurrencycount.class.php', 'Console/Concurrencycount.class.php',
	'Engines/EngineInterface.php', 'Engines/Original.php', 'Engines/Sweep.php',
	'Engines/Registry.php', 'page.concurrencycount.php', 'views/main.php',
	'Analyzers/PeakDetailAnalyser.php', 'Resolvers/FreepbxEntityResolver.php',
	'Services/LiveSnapshotService.php', 'Services/ThresholdService.php',
	'Services/SettingsRepository.php', 'Services/HistoricalGraphService.php',
	'Services/HistoricalRuntimeEstimator.php',
	'Services/HistoricalCalculationControl.php',
	'Services/CliCancellationControl.php',
	'Services/SystemResourceTelemetry.php',
	'Services/HistoricalReportsService.php',
	'Services/PjsipIdentityService.php',
	'Services/HistoricalCallExclusionService.php',
	'Services/AlertMonitorCoordinator.php', 'Services/AmiChannelSource.php',
	'Services/AlertOutboxService.php', 'alert-monitor.php', 'alert-mailer.php', 'install.php', 'uninstall.php',
];
foreach ($runtimeFiles as $file) {
	$source = file_get_contents($root . '/' . $file);
	foreach (['str_contains(', 'str_starts_with(', 'str_ends_with(', 'match (', '?->'] as $php8Only) {
		contract_assert(strpos($source, $php8Only) === false, $file . ' contains PHP 8-only syntax: ' . $php8Only);
	}
	contract_assert(!preg_match('/function\s+[A-Za-z_][A-Za-z0-9_]*\s*\([^)]*\|/', $source), $file . ' contains a union type');
}

$readme = file_get_contents($root . '/README.md');
contract_assert(strpos($readme, '# Concurrency Count 2.1.1 ') === 0, 'README release heading mismatch');
contract_assert(strpos($readme, 'FreePBX/PBXact 16 and 17') !== false, 'README compatibility claim missing');
contract_assert(strpos($readme, 'FreePBX/PBXact ' . '17 only') === false, 'README still excludes FreePBX 16');
echo "Release compatibility contract passed\n";
