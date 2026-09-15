<?php
namespace FreePBX\modules\Concurrencycount\Services;

/** Committed-row audit accumulator backed by an authenticated transient JSONL spool. */
class DemoSyntheticCallCollection {
	const PAGE_SIZE = 100;
	const MAX_AGE = 3600;
	const DIRECTORY_NAME = 'concurrencycount-demo-audit';
	private $count = 0;
	private $mix = ['directions'=>['inbound'=>0,'outbound'=>0,'internal'=>0], 'dispositions'=>['ANSWERED'=>0,'NO ANSWER'=>0,'BUSY'=>0,'FAILED'=>0], 'trunks'=>[], 'flows'=>[]];
	private $owner;
	private $token = '';
	private $path = '';
	private $handle = null;
	private $metaCreated = false;

	public function __construct(string $owner = '', ?string $directory = null) {
		$this->owner = $owner;
		if ($owner === '') return;
		$directory = self::verifiedDirectory($directory, true);
		self::cleanupExpired($directory);
		$this->token = bin2hex(random_bytes(16));
		$this->path = self::dataPath($directory, $this->token);
		$this->handle = self::openExclusive($this->path);
		if ($this->handle === false) throw new \RuntimeException('Unable to create the transient Demo audit store.');
	}

	public function record(array $row, string $report): void {
		$call = $this->project($row, $report);
		if ($this->handle !== null) {
			$encoded = json_encode($call, JSON_UNESCAPED_SLASHES);
			if ($encoded === false || !self::writeAll($this->handle, $encoded . "\n")) throw new \RuntimeException('Unable to write the transient Demo audit store.');
		}
		$this->count++;
		$this->increment($this->mix['directions'], $call['direction']);
		$this->increment($this->mix['dispositions'], $call['disposition']);
		if ($call['trunk'] !== '') $this->increment($this->mix['trunks'], $call['trunk']);
		if ($call['flow'] !== '') $this->increment($this->mix['flows'], $call['flow']);
	}

	public function finalize(): array {
		if ($this->handle !== null) {
			if (!fflush($this->handle)) { fclose($this->handle); $this->handle = null; $this->discard(); throw new \RuntimeException('Unable to finalize the transient Demo audit store.'); }
			fclose($this->handle);
			$this->handle = null;
			$metaPath = self::metaPath(dirname($this->path), $this->token);
			$metaHandle = self::openExclusive($metaPath);
			if ($metaHandle === false) {
				@unlink($this->path);
				$this->path = '';
				throw new \RuntimeException('Unable to finalize the transient Demo audit store.');
			}
			$this->metaCreated = true;
			$encoded = json_encode(['owner'=>$this->owner, 'total'=>$this->count, 'created_at'=>time()]);
			$written = $encoded !== false && self::writeAll($metaHandle, $encoded) && fflush($metaHandle);
			fclose($metaHandle);
			if (!$written) { $this->discard(); throw new \RuntimeException('Unable to finalize the transient Demo audit store.'); }
		}
		return $this->token === '' ? ['token'=>'', 'items'=>[], 'page'=>1, 'pages'=>1, 'total'=>$this->count] : self::fetchPage($this->token, $this->owner, 1, dirname($this->path));
	}

	public static function fetchPage(string $token, string $owner, int $page, ?string $directory = null): array {
		if (!preg_match('/^[a-f0-9]{32}$/', $token) || $owner === '') throw new \InvalidArgumentException('Invalid Demo audit page request.');
		$directory = self::verifiedDirectory($directory, false);
		self::cleanupExpired($directory);
		$metaPath = self::metaPath($directory, $token);
		$path = self::dataPath($directory, $token);
		if (!self::safeFile($metaPath) || !self::safeFile($path)) throw new \RuntimeException('This Demo audit dataset is no longer available.');
		$meta = json_decode((string)file_get_contents($metaPath), true);
		if (!is_array($meta) || !isset($meta['owner'], $meta['total']) || !hash_equals((string)$meta['owner'], $owner)) throw new \RuntimeException('This Demo audit dataset is unavailable for this session.');
		$total = max(0, (int)$meta['total']);
		$pages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$page = max(1, min($pages, $page));
		$from = ($page - 1) * self::PAGE_SIZE;
		$items = [];
		$handle = fopen($path, 'rb');
		if ($handle === false) throw new \RuntimeException('Unable to read the Demo audit dataset.');
		$index = 0;
		try {
			while (($line = fgets($handle)) !== false) {
				if ($index++ < $from) continue;
				if (count($items) >= self::PAGE_SIZE) break;
				$item = json_decode($line, true);
				if (is_array($item)) $items[] = $item;
			}
		} finally { fclose($handle); }
		return ['token'=>$token, 'items'=>$items, 'page'=>$page, 'pages'=>$pages, 'total'=>$total];
	}

	public function discard(): void {
		if ($this->handle !== null) { fclose($this->handle); $this->handle = null; }
		if ($this->path === '') return;
		self::verifiedDirectory(dirname($this->path), false);
		if (self::safeFile($this->path)) @unlink($this->path);
		$metaPath = self::metaPath(dirname($this->path), $this->token);
		if ($this->metaCreated && self::safeFile($metaPath)) @unlink($metaPath);
		$this->path = '';
	}

	public function count(): int { return $this->count; }
	public function trafficMix(): array { ksort($this->mix['trunks']); ksort($this->mix['flows']); return $this->mix; }
	public function integrity(int $generated, int $inserted, int $removed, int $remaining): string { return $generated === $inserted && $inserted === $this->count && $this->count === $removed && $remaining === 0 ? 'verified' : 'mismatch'; }

	private function project(array $row, string $report): array {
		$start = (string)($row['calldate'] ?? ''); $duration = (int)($row['duration'] ?? 0); $extensions = (array)($row['_extensions'] ?? []);
		if ($report === 'trunk') $identity = (string)($row['_trunk'] ?? 'internal'); elseif ($report === 'extension') $identity = (string)($row['_handled_extension'] ?? ''); else $identity = 'PBX-wide group (' . implode(', ', $extensions) . ')';
		return ['start'=>$start,'answer'=>(string)($row['answer']??''),'end'=>$start===''?'':date('Y-m-d H:i:s',strtotime($start)+$duration),'duration'=>$duration,'billsec'=>(int)($row['billsec']??0),'direction'=>(string)($row['_direction']??''),'source'=>(string)($row['src']??''),'destination'=>(string)($row['dst']??''),'did'=>(string)($row['did']??''),'disposition'=>(string)($row['disposition']??''),'channel'=>(string)($row['channel']??''),'destination_channel'=>(string)($row['dstchannel']??''),'report_identity'=>$identity,'accountcode'=>(string)($row['accountcode']??''),'last_application'=>(string)($row['lastapp']??''),'last_data'=>(string)($row['lastdata']??''),'context'=>(string)($row['dcontext']??''),'trunk'=>(string)($row['trunk']??$row['_trunk']??''),'carrier'=>(string)($row['carrier']??''),'queue'=>(string)($row['queue']??''),'hangup_source'=>(string)($row['hangupsource']??''),'hangup_cause'=>(string)($row['hangupcause']??''),'recording_file'=>(string)($row['recordingfile']??''),'flow'=>(string)($row['_flow']??'')];
	}
	private function increment(array &$values, string $name): void { if (isset($values[$name])) $values[$name]++; elseif ($name !== '') $values[$name] = 1; }
	private static function defaultDirectory(): string { return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME; }
	private static function verifiedDirectory(?string $directory, bool $create): string {
		$directory = $directory === null ? self::defaultDirectory() : rtrim($directory, DIRECTORY_SEPARATOR);
		if ($create && !file_exists($directory) && !is_link($directory)) {
			$oldUmask = umask(0077);
			try { $created = @mkdir($directory, 0700); } finally { umask($oldUmask); }
			if (!$created && !is_dir($directory)) throw new \RuntimeException('Unable to create the private Demo audit directory.');
		}
		clearstatcache(true, $directory);
		if (is_link($directory) || !is_dir($directory)) throw new \RuntimeException('The Demo audit directory is unavailable or unsafe.');
		$permissions = fileperms($directory);
		if ($permissions === false || ($permissions & 0077) !== 0) throw new \RuntimeException('The Demo audit directory permissions are unsafe.');
		if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) throw new \RuntimeException('The Demo audit directory owner is unsafe.');
		if (!is_readable($directory) || !is_writable($directory)) throw new \RuntimeException('The Demo audit directory is inaccessible.');
		return $directory;
	}
	private static function openExclusive(string $path) {
		$oldUmask = umask(0077);
		try { $handle = @fopen($path, 'x+b'); } finally { umask($oldUmask); }
		if ($handle === false) return false;
		// The path was created exclusively inside a verified 0700 directory, so it
		// cannot be an attacker-controlled object when its final mode is applied.
		if (!@chmod($path, 0600) || !self::safeFile($path)) { fclose($handle); @unlink($path); return false; }
		return $handle;
	}
	private static function writeAll($handle, string $data): bool {
		$offset = 0;
		$length = strlen($data);
		while ($offset < $length) {
			$written = fwrite($handle, substr($data, $offset));
			if ($written === false || $written === 0) return false;
			$offset += $written;
		}
		return true;
	}
	private static function safeFile(string $path): bool {
		clearstatcache(true, $path);
		if (is_link($path) || !is_file($path)) return false;
		$permissions = fileperms($path);
		return $permissions !== false && ($permissions & 0077) === 0;
	}
	private static function dataPath(string $directory, string $token): string { return $directory . DIRECTORY_SEPARATOR . 'audit-' . $token . '.jsonl'; }
	private static function metaPath(string $directory, string $token): string { return $directory . DIRECTORY_SEPARATOR . 'audit-' . $token . '.meta'; }
	private static function cleanupExpired(string $directory): void {
		$directory = self::verifiedDirectory($directory, false);
		foreach ((array)glob($directory . DIRECTORY_SEPARATOR . 'audit-*') as $file) {
			if (self::safeFile($file) && time() - (int)@filemtime($file) > self::MAX_AGE) @unlink($file);
		}
	}
}
