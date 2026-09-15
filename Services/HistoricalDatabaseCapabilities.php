<?php

namespace FreePBX\modules\Concurrencycount\Services;

/** Database capabilities needed by bounded Historical and Demo CDR work. */
class HistoricalDatabaseCapabilities {
	const LEGACY_INITIAL_ACQUISITION_WINDOW_SECONDS = 900;

	public static function fromServerVersion(string $serverVersion): array {
		if (stripos($serverVersion, 'MariaDB') !== false) {
			if (!preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $serverVersion, $match)) {
				return self::unknown($serverVersion);
			}
			$version = $match[1];
			$supported = version_compare($version, '10.1.1', '>=');
			return [
				'vendor' => 'mariadb',
				'numeric_version' => $version,
				'historical_supported' => true,
				'select_statement_timeout_supported' => $supported,
				'select_statement_timeout_type' => $supported ? 'max_statement_time' : 'none',
				'cleanup_statement_timeout_supported' => $supported,
				'bounded_cleanup_fallback_supported' => !$supported,
				'adaptive_legacy_acquisition' => !$supported,
				'acquisition_window_seconds' => $supported ? HistoricalCdrAcquisition::INITIAL_CHUNK_SECONDS : self::LEGACY_INITIAL_ACQUISITION_WINDOW_SECONDS,
			];
		}

		if (preg_match('/^(\d+\.\d+\.\d+)/', trim($serverVersion), $match)) {
			$version = $match[1];
			$supported = version_compare($version, '5.7.8', '>=');
			return [
				'vendor' => 'mysql',
				'numeric_version' => $version,
				'historical_supported' => $supported,
				'select_statement_timeout_supported' => $supported,
				'select_statement_timeout_type' => $supported ? 'max_execution_time' : 'none',
				'cleanup_statement_timeout_supported' => false,
				'bounded_cleanup_fallback_supported' => false,
				'adaptive_legacy_acquisition' => false,
				'acquisition_window_seconds' => HistoricalCdrAcquisition::INITIAL_CHUNK_SECONDS,
			];
		}

		return self::unknown($serverVersion);
	}

	private static function unknown(string $serverVersion): array {
		return [
			'vendor' => 'unknown',
			'numeric_version' => '',
			'historical_supported' => false,
			'select_statement_timeout_supported' => false,
			'select_statement_timeout_type' => 'none',
			'cleanup_statement_timeout_supported' => false,
			'bounded_cleanup_fallback_supported' => false,
			'adaptive_legacy_acquisition' => false,
			'acquisition_window_seconds' => HistoricalCdrAcquisition::INITIAL_CHUNK_SECONDS,
			'server_version' => $serverVersion,
		];
	}
}
