<?php

require_once __DIR__ . '/../Services/HistoricalCdrAcquisition.php';
require_once __DIR__ . '/../Services/HistoricalDatabaseCapabilities.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalDatabaseCapabilities;
function capabilities_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }

$legacy = HistoricalDatabaseCapabilities::fromServerVersion('5.5.65-MariaDB');
capabilities_assert($legacy['vendor'] === 'mariadb' && $legacy['numeric_version'] === '5.5.65', 'MariaDB 5.5 version parsing failed');
capabilities_assert($legacy['historical_supported'] && !$legacy['select_statement_timeout_supported'] && $legacy['select_statement_timeout_type'] === 'none', 'MariaDB 5.5 Historical must remain available without claiming a SELECT timeout');
capabilities_assert(!$legacy['cleanup_statement_timeout_supported'] && $legacy['bounded_cleanup_fallback_supported'] && $legacy['adaptive_legacy_acquisition'] && $legacy['acquisition_window_seconds'] === 900, 'MariaDB 5.5 must use adaptive acquisition and bounded Demo cleanup');

$modernMaria = HistoricalDatabaseCapabilities::fromServerVersion('10.6.18-MariaDB-0ubuntu0.22.04.1');
capabilities_assert($modernMaria['numeric_version'] === '10.6.18' && $modernMaria['select_statement_timeout_type'] === 'max_statement_time' && $modernMaria['cleanup_statement_timeout_supported'] && !$modernMaria['bounded_cleanup_fallback_supported'], 'Modern MariaDB must protect Historical SELECT and Demo cleanup with max_statement_time');
$firstMariaTimeout = HistoricalDatabaseCapabilities::fromServerVersion('10.1.1-MariaDB');
capabilities_assert($firstMariaTimeout['select_statement_timeout_supported'] && $firstMariaTimeout['cleanup_statement_timeout_supported'], 'MariaDB 10.1.1 must enable max_statement_time');
$prefixedMaria = HistoricalDatabaseCapabilities::fromServerVersion('5.5.5-10.1.48-MariaDB');
capabilities_assert($prefixedMaria['numeric_version'] === '10.1.48' && $prefixedMaria['select_statement_timeout_supported'], 'MariaDB compatibility-prefix parsing failed');

$mysql57 = HistoricalDatabaseCapabilities::fromServerVersion('5.7.8-log');
capabilities_assert($mysql57['vendor'] === 'mysql' && $mysql57['historical_supported'] && $mysql57['select_statement_timeout_type'] === 'max_execution_time' && !$mysql57['cleanup_statement_timeout_supported'] && !$mysql57['bounded_cleanup_fallback_supported'], 'MySQL 5.7.8 must protect Historical SELECT but not claim cleanup DELETE protection');
$mysql8 = HistoricalDatabaseCapabilities::fromServerVersion('8.0.36');
capabilities_assert($mysql8['select_statement_timeout_supported'] && !$mysql8['cleanup_statement_timeout_supported'] && $mysql8['numeric_version'] === '8.0.36', 'MySQL 8 capability parsing failed');
$oldMysql = HistoricalDatabaseCapabilities::fromServerVersion('5.7.7');
capabilities_assert(!$oldMysql['historical_supported'] && !$oldMysql['select_statement_timeout_supported'], 'Older MySQL must remain unsupported rather than inheriting the MariaDB fallback');
$unknown = HistoricalDatabaseCapabilities::fromServerVersion('PostgreSQL 16.2');
capabilities_assert($unknown['vendor'] === 'unknown' && !$unknown['historical_supported'], 'Unknown database versions must fail capability recognition');
echo "Historical database capability tests passed\n";
