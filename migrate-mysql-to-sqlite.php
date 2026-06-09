<?php
/**
 * One-off migration: MariaDB/MySQL  ->  SQLite
 *
 * Reads every oPodSync table from the live MySQL database and writes the rows
 * into a fresh SQLite file, preserving primary-key IDs (so foreign keys stay
 * intact). The SQLite schema is built from the project's own schema.sql +
 * migrations, so it exactly matches what the app expects.
 *
 * Usage (run from the project root on the production box):
 *
 *   # Reads MySQL credentials from config.local.php by default:
 *   php migrate-mysql-to-sqlite.php /path/to/new-data.sqlite
 *
 *   # Or override connection via env vars:
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=opodsync DB_PASSWORD=secret \
 *   DB_NAME=opodsync php migrate-mysql-to-sqlite.php /path/to/new-data.sqlite
 *
 * After it finishes, point the app at SQLite (see notes printed at the end).
 */

error_reporting(E_ALL);

$ROOT = __DIR__ . '/server';
$SQL  = $ROOT . '/sql';

// Must match DB::VERSION in server/lib/OPodSync/DB.php
const SCHEMA_VERSION = 20251211;

// FK-safe insertion order. schema_version is handled separately.
$TABLES = [
	'feeds',
	'users',
	'episodes',
	'devices',
	'subscriptions',
	'episodes_actions',
];

// --- args -------------------------------------------------------------------

$out = $argv[1] ?? null;

if (!$out) {
	fwrite(STDERR, "Usage: php migrate-mysql-to-sqlite.php <output.sqlite>\n");
	exit(1);
}

if (file_exists($out)) {
	fwrite(STDERR, "Refusing to overwrite existing file: $out\n");
	exit(1);
}

// --- resolve MySQL connection details --------------------------------------
// Prefer env vars; otherwise pull the constants from config.local.php.

function cfg(string $name, $default = null) {
	$env = getenv($name);
	if ($env !== false && $env !== '') {
		return $env;
	}
	if (defined($name)) {
		return constant($name);
	}
	return $default;
}

$config_file = __DIR__ . '/config.local.php';

if (file_exists($config_file) && !getenv('DB_NAME')) {
	// Loading config.local.php defines the DB_* constants we need.
	// We only read DB_* constants; the rest is harmless.
	require $config_file;
}

$host = cfg('DB_HOST', 'localhost');
$port = (int) cfg('DB_PORT', 3306);
$user = cfg('DB_USER');
$pass = cfg('DB_PASSWORD', '');
$name = cfg('DB_NAME');

if (!$user || !$name) {
	fwrite(STDERR, "Could not determine MySQL DB_USER/DB_NAME. Set them in config.local.php or via env vars.\n");
	exit(1);
}

// --- connect to MySQL -------------------------------------------------------

$mysql_dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

try {
	$mysql = new PDO($mysql_dsn, $user, $pass, [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
}
catch (PDOException $e) {
	fwrite(STDERR, "MySQL connection failed: " . $e->getMessage() . "\n");
	exit(1);
}

echo "Connected to MySQL $name@$host:$port\n";

// --- build the fresh SQLite schema -----------------------------------------

$sqlite = new PDO('sqlite:' . $out, null, null, [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sqlite->exec('PRAGMA journal_mode = MEMORY;');
$sqlite->exec('PRAGMA foreign_keys = OFF;'); // load with FKs deferred

// schema.sql  = full current SQLite schema.
$schema = file_get_contents($SQL . '/sqlite/schema.sql');
$sqlite->exec($schema);

echo "Created fresh SQLite schema at $out\n";

// --- copy each table --------------------------------------------------------

/**
 * Per-column value sanitizer to satisfy SQLite CHECK constraints that MySQL
 * does not enforce.
 */
function sanitize(string $table, string $col, $val) {
	if ($val === null) {
		return null;
	}

	// pubdate: SQLite requires datetime(pubdate) == pubdate, so reject
	// MySQL zero-dates and anything not canonical.
	if ($col === 'pubdate') {
		if ($val === '' || strpos($val, '0000-00-00') === 0) {
			return null;
		}
	}

	// feeds.language: SQLite requires LENGTH == 2 or NULL.
	if ($table === 'feeds' && $col === 'language') {
		if (strlen($val) !== 2) {
			return null;
		}
	}

	return $val;
}

$sqlite->beginTransaction();

$totals = [];

foreach ($TABLES as $table) {
	// Skip tables that don't exist in the source (e.g. app_passwords on very old installs)
	try {
		$check = $mysql->query("SELECT 1 FROM `$table` LIMIT 1");
	}
	catch (PDOException $e) {
		echo "  - $table: not present in source, skipping\n";
		continue;
	}

	$rows = $mysql->query("SELECT * FROM `$table`");
	$count = 0;
	$insert = null;
	$cols = null;

	foreach ($rows as $row) {
		if ($insert === null) {
			$cols = array_keys($row);
			$placeholders = implode(', ', array_fill(0, count($cols), '?'));
			$collist = implode(', ', $cols);
			$insert = $sqlite->prepare("INSERT INTO $table ($collist) VALUES ($placeholders)");
		}

		$values = [];
		foreach ($cols as $col) {
			$values[] = sanitize($table, $col, $row[$col]);
		}

		$insert->execute($values);
		$count++;
	}

	$totals[$table] = $count;
	echo "  - $table: $count rows\n";
}

// schema version
$sqlite->exec('PRAGMA user_version = ' . SCHEMA_VERSION . ';');

$sqlite->commit();

// --- integrity check --------------------------------------------------------

$sqlite->exec('PRAGMA foreign_keys = ON;');
$violations = $sqlite->query('PRAGMA foreign_key_check;')->fetchAll();

echo "\nDone. Row counts:\n";
foreach ($totals as $t => $c) {
	echo sprintf("  %-20s %d\n", $t, $c);
}

if ($violations) {
	echo "\nWARNING: " . count($violations) . " foreign-key violation(s) detected:\n";
	foreach (array_slice($violations, 0, 20) as $v) {
		echo "  " . json_encode($v) . "\n";
	}
	echo "Inspect these before going live.\n";
}
else {
	echo "\nForeign-key check: OK (no violations).\n";
}

echo "\nNext steps:\n";
echo "  1. Stop the app / take it offline.\n";
echo "  2. Re-run this migration against the final DB if data changed since.\n";
echo "  3. Move $out into place (e.g. DATA_ROOT/data.sqlite) and chown to the web user.\n";
echo "  4. In config.local.php set:  const DB_DRIVER = 'sqlite';  const DB_FILE = '<path>';\n";
echo "     and remove/comment the DB_HOST/DB_USER/etc MySQL constants.\n";
echo "  5. Bring the app back up and verify login + a sync.\n";
