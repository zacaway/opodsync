# oPodSync — Developer Guide

See README.md for project overview, features, APIs, and client compatibility.

## Architecture

PHP app with **zero Composer dependencies**. Uses the KD2 framework from a sibling repo at `../../kd2fw/` (relative to project root). In CI, this is symlinked from the checked-out `kd2fw` repo.

Key framework classes used: `KD2\DB\DB` (PDO database abstraction), `KD2\ErrorManager`, `KD2\Smartyer` (template engine), `KD2\Test`, `KD2\HTTP`.

### Source layout

```
server/
  _inc.php              # Bootstrap: autoloader, config loading, defaults, CSRF
  index.php             # Router: API dispatch + cron entry point
  login.php             # Web login (CSRF-protected)
  register.php          # Web registration (CSRF + CAPTCHA)
  lib/OPodSync/
    API.php             # gPodder + NextCloud API endpoints
    DB.php              # Database singleton (extends KD2\DB\DB)
    Feed.php            # RSS feed parsing (regex-based, not DOM)
    GPodder.php         # Session/auth, subscriptions, feed management
    Utils.php           # HTML description formatting
  sql/
    sqlite/schema.sql   # SQLite schema
    sqlite/migration_*  # SQLite migrations (numbered by date)
    mysql/schema.sql    # MySQL/MariaDB schema
  templates/            # Smartyer templates
```

### Database

Supports **SQLite** (default) and **MySQL/MariaDB**. Driver selected by `DB_DRIVER` constant (`'sqlite'` or `'mysql'`). See `config.dist.php` for all DB options.

`DB.php` is the core abstraction — a singleton extending `KD2\DB\DB` with:
- Dialect-aware `upsert()` (SQLite `ON CONFLICT` vs MySQL `ON DUPLICATE KEY`)
- `runSQL()` for multi-statement DDL (plain `exec()` for SQLite, emulated-prepares for MySQL — MySQL DDL causes implicit commits so it cannot be wrapped in transactions)
- Schema versioning via `schema_version` table (replaces SQLite-only `PRAGMA user_version`)
- `closeCursors()` override on `commit()` — SQLite PDO cannot commit while prepared statement cursors are active

When adding migrations: create files in **both** `sql/sqlite/` and `sql/mysql/` directories. Bump `DB::VERSION` constant. The `schema_version` table is managed by `createSchemaVersionTable()` in the constructor — do NOT include it in schema SQL files.

Config defaults are in `server/_inc.php` `$defaults` array. Constants from `config.local.php` take precedence, then env vars, then defaults.

## Development Environment

Requires [Nix](https://nixos.org). Run `nix develop` to get a shell with PHP 8.3, nginx, MariaDB, and all tools.

### Commands (inside `nix develop`)

| Command | Description |
|---------|-------------|
| `dev-server` | Start nginx + php-fpm on http://localhost:8080 |
| `dev-server-stop` | Stop the dev server |
| `dev-mysql` | Start local MariaDB on port 3307 (root/root) |
| `dev-mysql-stop` | Stop local MariaDB |
| `dev-mysql-test` | Reset DB and run integration tests against MariaDB |
| `make test` | Run all tests (unit + integration, SQLite) |

### Without Nix

`make test` or `php test/unit.php && php test/start.php` with PHP 8.0+ and sqlite3/pcntl/curl extensions.

## Testing

- **Unit tests:** `test/unit.php` — test files in `test/unit/`, numbered for order (e.g., `00_feed_parsing.php`)
- **Integration tests:** `test/start.php` — spins up PHP built-in server on :8099, test files in `test/tests/`
- **MySQL integration:** `dev-mysql-test` or set env vars: `DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_USER=root DB_PASSWORD=root DB_NAME=opodsync_test php test/start.php`
- Framework: `KD2\Test` (not PHPUnit). Use `Test::assert()`, `Test::equals()`, `Test::invoke($obj, 'method', $args)` for protected/private methods.
- `ENABLE_SUBSCRIPTIONS = true` must be set in test config for registration tests after first user.
- CI runs on PHP 8.0–8.3 via GitHub Actions (`.github/workflows/test.yml`).

## Database Pitfalls

- **MySQL DDL is non-transactional.** `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE` cause implicit commits. Never wrap DDL in `begin()`/`commit()`. Use `runSQL()` which handles this per-driver.
- **SQLite PDO cursor locking.** KD2's statement caching keeps `PDOStatement` objects alive. Must call `closeCursor()` on all cached statements before `commit()`. The `DB::commit()` override handles this.
- **PDO named parameter reuse.** With `ATTR_EMULATE_PREPARES = false`, PDO rejects duplicate named params. The `upsert()` method uses `excluded.col` (SQLite) and `VALUES(col)` (MySQL) to avoid this.
- **KD2 `execMultiple()` wraps in a transaction** — unsuitable for DDL. Use `runSQL()` instead for schema/migration SQL.

## Known Bugs

- `Feed::getDuration()` (line 153): array indices reversed for colon-format durations. `$parts[2]` is used as hours, `$parts[0]` as seconds — should be opposite. Also has operator precedence bug: `$parts[0] ?? 0` binds tighter than `+`. Input "1:30:00" gives 1801 instead of 5400.

## Style

- No Composer, no external dependencies — keep it that way.
- Tabs for indentation.
- All PHP classes under `OPodSync` namespace.
- SQL files use tabs, uppercase keywords.
