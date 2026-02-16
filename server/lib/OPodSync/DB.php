<?php

namespace OPodSync;

class DB extends \KD2\DB\DB
{
	const VERSION = 20260205;

	static protected $instance;

	protected $last_statement;

	static public function getInstance(): self
	{
		if (!isset(self::$instance)) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function __construct()
	{
		if (DB_DRIVER === 'mysql') {
			parent::__construct('mysql', [
				'host'     => DB_HOST,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'database' => DB_NAME,
				'port'     => DB_PORT,
			]);
		}
		else {
			$setup = !file_exists(DB_FILE);

			parent::__construct('sqlite', ['file' => DB_FILE]);

			$mode = strtoupper(SQLITE_JOURNAL_MODE);
			$set_mode = strtoupper($this->firstColumn('PRAGMA journal_mode;'));

			if ($set_mode !== $mode) {
				$this->exec(sprintf(
					'PRAGMA journal_mode = %s; PRAGMA synchronous = NORMAL; PRAGMA journal_size_limit = %d;',
					$mode,
					32 * 1024 * 1024
				));
			}

			if ($setup) {
				$this->install();
				return;
			}
		}

		if (DB_DRIVER === 'mysql') {
			$this->createSchemaVersionTable();

			if (!$this->firstColumn('SELECT version FROM schema_version LIMIT 1;')) {
				$this->install();
				return;
			}
		}

		$this->migrate();
	}

	protected function createSchemaVersionTable(): void
	{
		$this->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL);');
	}

	protected function getSchemaVersion(): int
	{
		if ($this->driver->type === 'sqlite') {
			// Check for schema_version table first (new format)
			$has_table = $this->firstColumn("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_version';");

			if ($has_table) {
				return (int) ($this->firstColumn('SELECT version FROM schema_version LIMIT 1;') ?: 0);
			}

			// Fall back to PRAGMA user_version (legacy)
			return (int) $this->firstColumn('PRAGMA user_version;');
		}

		$this->createSchemaVersionTable();
		return (int) ($this->firstColumn('SELECT version FROM schema_version LIMIT 1;') ?: 0);
	}

	protected function setSchemaVersion(int $version): void
	{
		if ($this->driver->type === 'sqlite') {
			$this->exec(sprintf('PRAGMA user_version = %d;', $version));

			// Also maintain schema_version table for consistency
			$this->createSchemaVersionTable();
		}

		// Upsert into schema_version table
		$current = $this->firstColumn('SELECT version FROM schema_version LIMIT 1;');

		if ($current === null) {
			$this->preparedQuery('INSERT INTO schema_version (version) VALUES (?);', $version);
		}
		else {
			$this->preparedQuery('UPDATE schema_version SET version = ?;', $version);
		}
	}

	/**
	 * Run multi-statement SQL (DDL) using the appropriate method per driver.
	 * SQLite PDO's exec() handles multiple statements natively.
	 * MySQL DDL causes implicit commits, so we cannot wrap in a transaction;
	 * instead we use PDO with emulated prepares to run multi-statement SQL directly.
	 */
	protected function runSQL(string $sql): void
	{
		if ($this->driver->type === 'mysql') {
			$this->connect();
			$prev = $this->pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
			$this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
			try {
				$st = $this->pdo->prepare($sql);
				$st->execute();
				while ($st->nextRowset()) {}
			} finally {
				$this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $prev);
			}
		}
		else {
			$this->exec($sql);
		}
	}

	public function install(): void
	{
		$schema_file = ROOT . '/sql/' . $this->driver->type . '/schema.sql';
		$sql = file_get_contents($schema_file);
		$this->runSQL($sql);

		if ($this->driver->type === 'sqlite') {
			// Create app_passwords table (from migration_20260205)
			$this->runSQL(file_get_contents(ROOT . '/sql/sqlite/migration_20260205.sql'));
		}

		$this->setSchemaVersion(self::VERSION);
	}

	public function migrate(): void
	{
		$v = $this->getSchemaVersion();

		if ($v >= self::VERSION) {
			return;
		}

		$dir = ROOT . '/sql/' . $this->driver->type;
		$list = glob($dir . '/migration_*.sql');
		sort($list);

		foreach ($list as $file) {
			if (!preg_match('!/migration_(.*?)\.sql$!', $file, $match)) {
				continue;
			}

			$file_version = $match[1];

			if ($file_version && $file_version <= self::VERSION && $file_version > $v) {
				$this->runSQL(file_get_contents($file));
			}
		}

		// Destroy session to avoid bugs between user data in session and DB
		$gpodder = new GPodder;
		$gpodder->logout();

		$this->setSchemaVersion(self::VERSION);
	}

	public function upsert(string $table, array $params, array $conflict_columns)
	{
		if ($this->driver->type === 'mysql') {
			$sql = sprintf(
				'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s;',
				$table,
				implode(', ', array_keys($params)),
				':' . implode(', :', array_keys($params)),
				implode(', ', array_map(fn($a) => $a . ' = VALUES(' . $a . ')', array_keys($params)))
			);
		}
		else {
			// Use excluded.col to reference the would-be inserted values
			// This avoids reusing named params, which PDO doesn't allow
			// with ATTR_EMULATE_PREPARES = false
			$sql = sprintf(
				'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s;',
				$table,
				implode(', ', array_keys($params)),
				':' . implode(', :', array_keys($params)),
				implode(', ', $conflict_columns),
				implode(', ', array_map(fn($a) => $a . ' = excluded.' . $a, array_keys($params)))
			);
		}

		return $this->simple($sql, $params);
	}

	/**
	 * Close all cached statement cursors.
	 * Required for SQLite PDO which cannot commit transactions
	 * while statements are still active.
	 */
	protected function closeCursors(): void
	{
		foreach ($this->statements as $st) {
			$st->closeCursor();
		}
	}

	public function commit()
	{
		$this->closeCursors();
		return parent::commit();
	}

	public function simple(string $sql, ...$params)
	{
		if (isset($params[0]) && is_array($params[0])) {
			$params = $params[0];
		}

		$this->last_statement = $this->preparedQuery($sql, $params);
		return $this->last_statement;
	}

	public function firstRow(string $sql, ...$params): ?\stdClass
	{
		$row = $this->simple($sql, ...$params)->fetch();
		return $row ?: null;
	}

	public function firstColumn(string $sql, ...$params)
	{
		$v = $this->simple($sql, ...$params)->fetchColumn();
		return $v === false ? null : $v;
	}

	public function rowsFirstColumn(string $sql, ...$params): array
	{
		$res = $this->simple($sql, ...$params);
		$out = [];

		while (($v = $res->fetchColumn()) !== false) {
			$out[] = $v;
		}

		return $out;
	}

	public function iterate(string $sql, ...$params): iterable
	{
		$st = $this->simple($sql, ...$params);

		while ($row = $st->fetch()) {
			yield $row;
		}
	}

	public function all(string $sql, ...$params): array
	{
		return $this->simple($sql, ...$params)->fetchAll();
	}

	public function changes(): int
	{
		if (!$this->last_statement) {
			return 0;
		}

		return $this->last_statement->rowCount();
	}
}
