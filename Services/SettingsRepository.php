<?php

namespace FreePBX\modules\Concurrencycount\Services;

class SettingsRepository {
	const TABLE = 'concurrencycount_settings';
	private $db;

	public function __construct($db) {
		$this->db = $db;
	}

	public function install(): void {
		$this->db->exec("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
			`setting_key` varchar(128) NOT NULL,
			`setting_value` longtext NOT NULL,
			`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`setting_key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	public function uninstall(): void {
		$this->db->exec('DROP TABLE IF EXISTS `' . self::TABLE . '`');
	}

	public function get(string $key, $default = null) {
		$stmt = $this->db->prepare('SELECT setting_value FROM `' . self::TABLE . '` WHERE setting_key = :key');
		$stmt->execute([':key' => $key]);
		$value = $stmt->fetchColumn();
		if ($value === false) return $default;
		$decoded = json_decode((string)$value, true);
		return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
	}

	public function set(string $key, $value): void {
		$json = json_encode($value);
		if ($json === false) throw new \RuntimeException('Unable to encode Concurrency Count setting.');
		$stmt = $this->db->prepare('INSERT INTO `' . self::TABLE . '` (setting_key, setting_value) VALUES (:key, :value)
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
		$stmt->execute([':key' => $key, ':value' => $json]);
	}

	public function delete(string $key): void {
		$stmt = $this->db->prepare('DELETE FROM `' . self::TABLE . '` WHERE setting_key = :key');
		$stmt->execute([':key' => $key]);
	}

	public function transaction(callable $callback) {
		$this->db->beginTransaction();
		try {
			$result = call_user_func($callback, $this);
			$this->db->commit();
			return $result;
		} catch (\Throwable $exception) {
			if ($this->db->inTransaction()) $this->db->rollBack();
			throw $exception;
		}
	}
}
