<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Database Upgrade Runner ("migrations")
 * ==============================================================================
 *
 * Path: _functions/fn_migrations.php
 *
 * WHAT THIS IS (plain English): from task 7n Phase 1A onwards, install.sql
 * and seed_data.sql are a FROZEN starting point ("baseline"). Every later
 * change to the database - a new table, a new column, a new index, a new
 * starting setting - is a small numbered file in
 * _includes/sql/migrations/NNNN_short_name.php. This file is the engine that
 * finds those files, works out which ones a database has not had yet, and
 * applies them in number order.
 *
 * WHY IT IS BUILT THIS WAY:
 *   - Every step checks the live database first ("does this column already
 *     exist?") and is skipped if the work is already done. So every upgrade
 *     is safe to run twice, and a run that stopped half-way can simply be run
 *     again. (MySQL cannot undo table changes, so "safe to repeat" is the
 *     only real protection against a half-finished run.)
 *   - Migration files only RETURN AN ARRAY of steps. Each step's SQL is a
 *     fixed piece of text written by a developer, checked against a short
 *     list of allowed step types, and refused if it contains a destructive
 *     word (DROP, TRUNCATE, RENAME, MODIFY, CHANGE, DELETE FROM). Nothing a
 *     visitor types ever reaches this SQL.
 *   - Starting rows are inserted with "insert if missing" only, so a value
 *     the owner has already edited in the admin screens is NEVER overwritten
 *     (owner rule: every commercial setting is admin-editable data).
 *   - A named database lock stops two upgrades running at the same time.
 *   - tblSchemaMigrations records each applied migration, when, by whom and a
 *     fingerprint (SHA-256) of the file. If a file changes after it was
 *     applied, the runner warns but never runs it again.
 *
 * TWO HALVES (house pattern - the same split as evaluateCreditSpend() and
 * parseEmailQueueRetentionDays()):
 *   1. PURE functions (no database): reading and checking migration files,
 *      the "what is waiting" plan, the install.sql column-name reader, the
 *      command-line option reader, exit codes and report text. These are
 *      unit-tested in tests/test_migrations.php.
 *   2. DATABASE functions (names start migrationDb...): they take a \mysqli
 *      connection as an argument and use prepare()/bind_param()/execute()
 *      for every query. The only exception is re-running the whole of
 *      install.sql, which uses multi_query() exactly as the web installer
 *      does (it is a file of many statements).
 *
 * WORKS WITHOUT THE SITE START-UP CODE: the web installer runs before the
 * site is set up, so nothing here calls dbQuery(), getDB(), getConfig(),
 * logError() or logActivity(), and this file loads nothing else.
 *
 * Entry points:
 *   - migrationApplyPending(mysqli $db, string $appliedBy, bool $dryRun): array
 *   - migrationStatus(mysqli $db): array
 *   - migrationExitCodeForResult(string $mode, array $result): int
 *   - migrationFormatReport(string $mode, array $result): array (lines)
 *   - the command line: _includes/cli/migrate.php (--status/--dry-run/--apply)
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-23 (task 7n Phase 1A, #113 item 5, #70)
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1 (the specification)
 * @see         _includes/cli/migrate.php - the command-line wrapper
 * @see         _includes/sql/migrations/ - the numbered migration files
 * @see         https://dev.mysql.com/doc/refman/8.0/en/information-schema.html
 * @see         https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html (GET_LOCK)
 * @see         https://www.php.net/manual/en/mysqli.prepare.php
 */

// ==============================================================================
// 🚫 Direct access protection (same pattern as every other _functions file)
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $migrationScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $migrationScriptFilename = '';
}
if (basename($migrationScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($migrationScriptFilename);

// ==============================================================================
// 📌 Constants
// ==============================================================================

// 🔒 Fixed PREFIX of the MySQL named lock that stops two upgrades running at
//    once. Named locks are server-wide, not per-database, so
//    migrationLockNameSqlExpression() below always builds the FULL lock name
//    by adding the current database's name (and a short hash of it) onto this
//    prefix - otherwise an upgrade running against one database on a shared
//    MySQL server would needlessly block (or, worse, be mistaken for) an
//    upgrade running against a completely different database on that same
//    server (review finding F6). A second run against the SAME database is
//    still correctly told "busy" and can simply try again.
if (!defined('CUERCODE_MIGRATION_LOCK_NAME')) {
    define('CUERCODE_MIGRATION_LOCK_NAME', 'cuercode_schema_migrate');
}

// 🔒 The web installer's OWN named lock (independent review, Codex C1 finding
//    2, 2026-11-25): held from just after connecting until the installer's
//    permanent lock file is written, so two people pressing "Install" at the
//    same moment cannot both create tables and load starting data. Built with
//    the same per-database expression as the upgrade lock above (so it never
//    blocks an installer on a different database), and at most 23 characters
//    so the full name stays within MySQL's 64-character limit.
if (!defined('CUERCODE_INSTALLER_LOCK_NAME')) {
    define('CUERCODE_INSTALLER_LOCK_NAME', 'cuercode_web_installer');
}

// 🚦 Command-line exit codes (plan §5.1). "Refused" means no upgrades were applied.
if (!defined('CUERCODE_MIGRATE_EXIT_OK')) {
    define('CUERCODE_MIGRATE_EXIT_OK', 0);        // up to date, or applied, or dry run done
}
if (!defined('CUERCODE_MIGRATE_EXIT_FAILED')) {
    define('CUERCODE_MIGRATE_EXIT_FAILED', 1);    // a step (or the database) failed
}
if (!defined('CUERCODE_MIGRATE_EXIT_REFUSED')) {
    define('CUERCODE_MIGRATE_EXIT_REFUSED', 2);   // not command line, busy, unsafe baseline, invalid file, bad option
}
if (!defined('CUERCODE_MIGRATE_EXIT_PENDING')) {
    define('CUERCODE_MIGRATE_EXIT_PENDING', 3);   // --status only: there is work waiting
}

// 📏 Longest migration key the ledger column (VARCHAR(100)) can hold.
if (!defined('CUERCODE_MIGRATION_KEY_MAX_LENGTH')) {
    define('CUERCODE_MIGRATION_KEY_MAX_LENGTH', 100);
}

// ==============================================================================
// 📂 Paths
// ==============================================================================

/**
 * 📂 Folder that holds the numbered migration files.
 *
 * @return string Absolute path ending in a directory separator
 */
function migrationDefaultDirectory(): string
{
    // This file is _functions/fn_migrations.php; the folder is
    // _includes/sql/migrations/ beside it.
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR
        . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;
}

/**
 * 📂 Path of the frozen baseline install.sql.
 *
 * @return string Absolute path
 */
function migrationDefaultInstallSqlPath(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR
        . 'sql' . DIRECTORY_SEPARATOR . 'install.sql';
}

// ==============================================================================
// 🗂️ The runner's own table ("step zero")
// ==============================================================================

/**
 * 🗂️ The step that creates tblSchemaMigrations (plan §5.2).
 *
 * Written as an ordinary create_table step so it goes through exactly the
 * same checks as every migration step (tests/test_migrations.php validates
 * it). It is NOT in install.sql: install.sql is the frozen baseline, and the
 * runner creates its own ledger before it does anything else.
 *
 * @return array{type: string, table: string, sql: string}
 */
function migrationLedgerStep(): array
{
    return [
        'type'  => 'create_table',
        'table' => 'tblSchemaMigrations',
        'sql'   => 'CREATE TABLE IF NOT EXISTS `tblSchemaMigrations` ('
            . ' `migrationKey` VARCHAR(100) NOT NULL COMMENT \'Migration file key, for example 0001_subscription_price_snapshot_columns\','
            . ' `checksum` CHAR(64) NOT NULL COMMENT \'SHA-256 of the migration file when it was applied\','
            . ' `appliedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'When it was applied (UTC)\','
            . ' `appliedBy` VARCHAR(100) NOT NULL COMMENT \'installer, cli:<os user> or admin:<userID>\','
            . ' `durationMs` INT UNSIGNED NULL COMMENT \'How long the run took in milliseconds\','
            . ' `stepsRun` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Steps that changed the database\','
            . ' `stepsSkipped` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Steps skipped because the work was already done\','
            . ' PRIMARY KEY (`migrationKey`)'
            . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            . ' COMMENT=\'Database upgrades (migrations) applied to this database - task 7n Phase 1A\'',
    ];
}

// ==============================================================================
// 🧮 PURE: checksums, keys and file names
// ==============================================================================

/**
 * 🧮 Fingerprint (SHA-256) of a migration file's text.
 *
 * Windows line endings are turned into Unix ones first, so the same file
 * checked out on Windows (git autocrlf) does not look "changed".
 *
 * @param string $fileContents Raw file text
 * @return string 64 lower-case hex characters
 */
function migrationChecksumForContents(string $fileContents): string
{
    $normalised = str_replace("\r\n", "\n", $fileContents);

    return hash('sha256', $normalised);
}

/**
 * 🏷️ Turn a file name such as "0001_subscription_price_snapshot_columns.php"
 *    into its migration key ("0001_subscription_price_snapshot_columns").
 *
 * Rules: four digits (0001 or more), an underscore, then lower-case letters
 * and digits in words joined by single underscores, then ".php"; the whole
 * key must fit the ledger column.
 *
 * @param string $fileName File name only (no folder)
 * @return string|null The key, or null when the name does not follow the rule
 */
function migrationKeyFromFileName(string $fileName): ?string
{
    $matches = [];

    if (preg_match('/^([0-9]{4})_([a-z0-9]+(?:_[a-z0-9]+)*)\.php$/D', $fileName, $matches) !== 1) {
        return null;
    }

    // 0000 is reserved (it would sort before the baseline).
    if ($matches[1] === '0000') {
        return null;
    }

    $key = $matches[1] . '_' . $matches[2];

    if (strlen($key) > CUERCODE_MIGRATION_KEY_MAX_LENGTH) {
        return null;
    }

    return $key;
}

/**
 * 🧹 Make the "applied by" label safe and short for the ledger.
 *
 * Keeps letters, digits and : . _ @ - only, and at most 100 characters.
 * An empty result becomes "unknown".
 *
 * @param string $appliedBy For example "installer", "cli:www-data", "admin:7"
 * @return string Safe label
 */
function migrationSafeAppliedBy(string $appliedBy): string
{
    $cleaned = preg_replace('/[^A-Za-z0-9:._@-]/', '', $appliedBy);

    if (!is_string($cleaned)) {
        $cleaned = '';
    }

    $cleaned = substr($cleaned, 0, CUERCODE_MIGRATION_KEY_MAX_LENGTH);

    if ($cleaned === '') {
        return 'unknown';
    }

    return $cleaned;
}

// ==============================================================================
// 🔍 PURE: SQL text checks
// ==============================================================================

/**
 * 🔍 Blank out the inside of every single-quoted text in a piece of SQL.
 *
 * Words inside COMMENT '...' or a seeded value must not trip the
 * "destructive word" or "one statement only" checks, so their characters are
 * replaced by spaces. The quote marks themselves stay, and back-quoted names
 * (`tblUsers`) are kept exactly as they are, because the checks need them.
 *
 * Two single quotes in a row inside quoted text ('it''s') are an escaped
 * quote. Backslashes and double quotes are refused by
 * migrationValidateStep() before this is ever called, so there is only one
 * way to read the text.
 *
 * @param string $sql Step SQL
 * @return array{ok: bool, text: string} ok=false when a quote is never closed
 */
function migrationBlankQuotedText(string $sql): array
{
    $length    = strlen($sql);
    $output    = '';
    $inQuote   = false;   // inside '...'
    $inBacktick = false;  // inside `...`
    $index     = 0;

    while ($index < $length) {
        $character = $sql[$index];

        if ($inQuote) {
            if ($character === "'") {
                // '' inside quoted text = an escaped quote, still inside.
                if ($index + 1 < $length && $sql[$index + 1] === "'") {
                    $output .= '  ';
                    $index  += 2;
                    continue;
                }

                // The closing quote.
                $inQuote = false;
                $output .= "'";
                $index++;
                continue;
            }

            // Any other byte inside quoted text becomes a space.
            $output .= ' ';
            $index++;
            continue;
        }

        if ($inBacktick) {
            // Names are copied as they are; `` would be an escaped back-quote.
            if ($character === '`') {
                $inBacktick = false;
            }
            $output .= $character;
            $index++;
            continue;
        }

        if ($character === "'") {
            $inQuote = true;
            $output .= "'";
            $index++;
            continue;
        }

        if ($character === '`') {
            $inBacktick = true;
        }

        $output .= $character;
        $index++;
    }

    if ($inQuote || $inBacktick) {
        return ['ok' => false, 'text' => $output];
    }

    return ['ok' => true, 'text' => $output];
}

/**
 * 🔍 Does the (already blanked) SQL have a comma outside every bracket?
 *
 * An ALTER step must do exactly ONE thing (one column, one index or one
 * foreign key), because the "already done?" check only looks at one name.
 * "ADD COLUMN a ..., ADD COLUMN b ..." has a comma outside brackets, while
 * DECIMAL(10,2), ENUM('a','b') and an index's column list keep their commas
 * inside brackets.
 *
 * @param string $blankedSql SQL with quoted text already blanked
 * @return bool True when a top-level comma exists
 */
function migrationHasTopLevelComma(string $blankedSql): bool
{
    $depth  = 0;
    $length = strlen($blankedSql);

    for ($index = 0; $index < $length; $index++) {
        $character = $blankedSql[$index];

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        } elseif ($character === ',' && $depth <= 0) {
            return true;
        }
    }

    return false;
}

/**
 * 🚫 The whole words a migration step may never contain (outside quoted text).
 *
 * "DELETE FROM" is refused as a phrase only, so the foreign-key wording
 * "ON DELETE SET NULL" / "ON DELETE CASCADE" stays allowed.
 *
 * @return array<string, string> Human label => regular expression
 */
function migrationRefusedWordPatterns(): array
{
    return [
        'DROP'        => '/\bDROP\b/i',
        'TRUNCATE'    => '/\bTRUNCATE\b/i',
        'RENAME'      => '/\bRENAME\b/i',
        'MODIFY'      => '/\bMODIFY\b/i',
        'CHANGE'      => '/\bCHANGE\b/i',
        'DELETE FROM' => '/\bDELETE\s+FROM\b/i',
    ];
}

/**
 * 📋 The allowed step types and the fields each one needs.
 *
 * @return array<string, array{needsName: bool}>
 */
function migrationStepTypes(): array
{
    return [
        'create_table'        => ['needsName' => false],
        'add_column'          => ['needsName' => true],
        'add_index'           => ['needsName' => true],
        'add_foreign_key'     => ['needsName' => true],
        'insert_if_missing'   => ['needsName' => false],
        'update_if_unchanged' => ['needsName' => false],
    ];
}

/**
 * ✅ Check ONE step of a migration. Pure - looks only at the text.
 *
 * @param mixed $step  One entry of a migration's 'steps' list
 * @param int   $index Its position (1-based), used in the messages
 * @return array<int, string> Problems found (empty = the step is valid)
 */
function migrationValidateStep(mixed $step, int $index): array
{
    $label  = 'step ' . $index;
    $errors = [];

    if (!is_array($step)) {
        return [$label . ': must be an array'];
    }

    // 🧾 Only known fields - a typo such as 'tabel' must not slip through.
    $allowedFields = ['type', 'table', 'name', 'sql', 'label'];
    foreach (array_keys($step) as $fieldName) {
        if (!is_string($fieldName) || !in_array($fieldName, $allowedFields, true)) {
            $errors[] = $label . ': unknown field "' . (string) $fieldName . '"';
        }
    }

    // 🏷️ Type must be on the whitelist.
    $types = migrationStepTypes();
    if (!isset($step['type']) || !is_string($step['type']) || !array_key_exists($step['type'], $types)) {
        $errors[] = $label . ': type must be one of ' . implode(', ', array_keys($types));
        return $errors;
    }
    $type = $step['type'];

    // 🧱 Table name: a plain database name.
    if (!isset($step['table']) || !is_string($step['table']) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $step['table']) !== 1) {
        $errors[] = $label . ': table must be a plain name (letters, digits, underscore; up to 64)';
        return $errors;
    }
    $table = $step['table'];

    // 🏷️ name: required for column / index / foreign key steps, not allowed otherwise.
    $name = '';
    if ($types[$type]['needsName']) {
        if (!isset($step['name']) || !is_string($step['name']) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $step['name']) !== 1) {
            $errors[] = $label . ': name must be a plain name (letters, digits, underscore; up to 64)';
            return $errors;
        }
        $name = $step['name'];
    } else {
        if (array_key_exists('name', $step)) {
            $errors[] = $label . ': a ' . $type . ' step has no name field (use label for a description)';
        }
    }

    // 🏷️ label: optional, short, plain (it is printed by the command line).
    if (array_key_exists('label', $step)) {
        if (!is_string($step['label']) || preg_match('/^[A-Za-z0-9_ .:,()\/-]{1,100}$/D', $step['label']) !== 1) {
            $errors[] = $label . ': label must be plain text of up to 100 characters';
        }
    }

    // 📝 SQL text.
    if (!isset($step['sql']) || !is_string($step['sql']) || trim($step['sql']) === '') {
        $errors[] = $label . ': sql must be a non-empty string';
        return $errors;
    }
    $sql = $step['sql'];

    // 🚫 Characters that would make the text ambiguous to read.
    if (strpos($sql, '\\') !== false) {
        $errors[] = $label . ': backslashes are not allowed in step SQL';
    }

    // 🔗 The table (and name) must appear back-quoted in the SQL.
    if (strpos($sql, '`' . $table . '`') === false) {
        $errors[] = $label . ': the table `' . $table . '` must appear back-quoted in the SQL';
    }
    if ($name !== '' && strpos($sql, '`' . $name . '`') === false) {
        $errors[] = $label . ': the name `' . $name . '` must appear back-quoted in the SQL';
    }

    // 🔍 Everything below looks at the SQL with quoted text blanked out.
    $blankResult = migrationBlankQuotedText($sql);
    if ($blankResult['ok'] !== true) {
        $errors[] = $label . ': a quote or back-quote is never closed';
        return $errors;
    }
    $blanked = $blankResult['text'];

    if (strpos($blanked, '"') !== false) {
        $errors[] = $label . ': use single quotes for text, not double quotes';
    }

    if (strpos($blanked, '--') !== false || strpos($blanked, '/*') !== false || strpos($blanked, '#') !== false) {
        $errors[] = $label . ': SQL comments are not allowed in step SQL (put notes in PHP comments instead)';
    }

    // ✂️ One statement only: a single trailing ";" is fine, any other is not.
    $trimmed = rtrim($blanked);
    if (substr($trimmed, -1) === ';') {
        $trimmed = rtrim(substr($trimmed, 0, -1));
    }
    if (strpos($trimmed, ';') !== false) {
        $errors[] = $label . ': only one SQL statement is allowed per step';
    }

    // 🚫 Additive only.
    foreach (migrationRefusedWordPatterns() as $wordLabel => $pattern) {
        if (preg_match($pattern, $trimmed) === 1) {
            $errors[] = $label . ': the word ' . $wordLabel . ' is not allowed (migrations only ever add)';
        }
    }

    // 🧭 Shape per type. Whitespace is squashed so line breaks do not matter.
    $squashed = preg_replace('/\s+/', ' ', trim($trimmed));
    if (!is_string($squashed)) {
        $squashed = '';
    }
    $quotedTable = preg_quote('`' . $table . '`', '/');
    $quotedName  = preg_quote('`' . $name . '`', '/');

    if ($type === 'create_table') {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS ' . $quotedTable . ' ?\(/i', $squashed) !== 1) {
            $errors[] = $label . ': a create_table step must start with CREATE TABLE IF NOT EXISTS `' . $table . '` (';
        }
    } elseif ($type === 'add_column') {
        if (preg_match('/^ALTER TABLE ' . $quotedTable . ' ADD COLUMN ' . $quotedName . ' /i', $squashed) !== 1) {
            $errors[] = $label . ': an add_column step must be ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ...';
        }
    } elseif ($type === 'add_index') {
        if (preg_match('/^ALTER TABLE ' . $quotedTable . ' ADD (INDEX|UNIQUE KEY) ' . $quotedName . ' ?\(/i', $squashed) !== 1) {
            $errors[] = $label . ': an add_index step must be ALTER TABLE `' . $table . '` ADD INDEX (or ADD UNIQUE KEY) `' . $name . '` (...)';
        }
    } elseif ($type === 'add_foreign_key') {
        if (preg_match('/^ALTER TABLE ' . $quotedTable . ' ADD CONSTRAINT ' . $quotedName . ' FOREIGN KEY ?\(/i', $squashed) !== 1) {
            $errors[] = $label . ': an add_foreign_key step must be ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $name . '` FOREIGN KEY (...)';
        }
    } elseif ($type === 'insert_if_missing') {
        if (preg_match('/^INSERT INTO ' . $quotedTable . ' ?\(/i', $squashed) !== 1) {
            $errors[] = $label . ': an insert_if_missing step must start with INSERT INTO `' . $table . '` (';
        }
        // The ending must be the no-op "ON DUPLICATE KEY UPDATE `x` = `x`",
        // so a row that already exists (perhaps edited by the owner) is
        // left exactly as it is.
        if (preg_match('/ ON DUPLICATE KEY UPDATE (`[A-Za-z0-9_]{1,64}`) ?= ?\1$/i', $squashed) !== 1) {
            $errors[] = $label . ': an insert_if_missing step must end with ON DUPLICATE KEY UPDATE `x` = `x` (a no-op)';
        }
        if (preg_match_all('/\bON DUPLICATE KEY UPDATE\b/i', $squashed) !== 1) {
            $errors[] = $label . ': ON DUPLICATE KEY UPDATE may appear only once';
        }
    } elseif ($type === 'update_if_unchanged') {
        if (preg_match('/^UPDATE ' . $quotedTable . ' SET /i', $squashed) !== 1) {
            $errors[] = $label . ': an update_if_unchanged step must start with UPDATE `' . $table . '` SET';
        }
        // The WHERE must only match rows still in their old seeded state.
        // The runner cannot judge that, but it can insist there IS a WHERE.
        if (preg_match('/\bWHERE\b/i', $squashed) !== 1) {
            $errors[] = $label . ': an update_if_unchanged step needs a WHERE that only matches rows still at their old seeded value';
        }
    }

    // ☝️ ALTER steps do exactly one thing.
    if ($type === 'add_column' || $type === 'add_index' || $type === 'add_foreign_key') {
        if (migrationHasTopLevelComma($squashed)) {
            $errors[] = $label . ': an ALTER step may add only one thing (one column, index or foreign key)';
        }
    }

    return $errors;
}

/**
 * ✅ Check a whole migration definition (what a migration file returns).
 *
 * @param mixed  $definition What the file returned
 * @param string $expectedKey The key taken from the file name
 * @return array<int, string> Problems found (empty = valid)
 */
function migrationValidateDefinition(mixed $definition, string $expectedKey): array
{
    if (!is_array($definition)) {
        return ['the file must return an array'];
    }

    $errors = [];

    foreach (array_keys($definition) as $fieldName) {
        if (!in_array($fieldName, ['key', 'description', 'steps'], true)) {
            $errors[] = 'unknown top-level field "' . (string) $fieldName . '"';
        }
    }

    if (!isset($definition['key']) || !is_string($definition['key']) || $definition['key'] !== $expectedKey) {
        $errors[] = 'key must be exactly "' . $expectedKey . '" (the file name without .php)';
    }

    if (!isset($definition['description']) || !is_string($definition['description']) || trim($definition['description']) === '') {
        $errors[] = 'description must be a non-empty string';
    }

    if (!isset($definition['steps']) || !is_array($definition['steps']) || $definition['steps'] === [] || !array_is_list($definition['steps'])) {
        $errors[] = 'steps must be a non-empty list';
        return $errors;
    }

    $position = 0;
    foreach ($definition['steps'] as $step) {
        $position++;
        foreach (migrationValidateStep($step, $position) as $stepError) {
            $errors[] = $stepError;
        }
    }

    return $errors;
}

// ==============================================================================
// 🧭 PURE: what is waiting, and does a step need to run
// ==============================================================================

/**
 * 🧭 Work out which migrations are waiting, given what exists and what ran.
 *
 * @param array<string, string> $available Key => checksum of every file (any order)
 * @param array<string, string> $applied   Key => checksum recorded in tblSchemaMigrations
 * @return array{pending: array<int, string>, applied: array<int, string>, changed: array<int, string>, missingFiles: array<int, string>, outOfOrder: array<int, string>}
 */
function migrationBuildPlan(array $available, array $applied): array
{
    // Four-digit, zero-padded numbers mean plain text order IS number order.
    $availableKeys = array_keys($available);
    sort($availableKeys, SORT_STRING);
    $appliedKeys = array_keys($applied);
    sort($appliedKeys, SORT_STRING);

    $pending      = [];
    $appliedList  = [];
    $changed      = [];
    $missingFiles = [];
    $outOfOrder   = [];

    // The newest migration this database has already had.
    $highestApplied = '';
    foreach ($appliedKeys as $appliedKey) {
        if (strcmp((string) $appliedKey, $highestApplied) > 0) {
            $highestApplied = (string) $appliedKey;
        }
    }

    foreach ($availableKeys as $key) {
        $key = (string) $key;

        if (array_key_exists($key, $applied)) {
            $appliedList[] = $key;

            // ⚠️ Applied, but the file has changed since - warn, never re-run.
            if (!hash_equals((string) $applied[$key], (string) $available[$key])) {
                $changed[] = $key;
            }
            continue;
        }

        $pending[] = $key;

        // ⚠️ An older-numbered file that arrived after newer ones ran (for
        //    example from a merged branch). It still runs - every step
        //    re-checks - but it is worth a mention.
        if ($highestApplied !== '' && strcmp($key, $highestApplied) < 0) {
            $outOfOrder[] = $key;
        }
    }

    // ⚠️ Recorded as applied, but the file is gone.
    foreach ($appliedKeys as $appliedKey) {
        if (!array_key_exists((string) $appliedKey, $available)) {
            $missingFiles[] = (string) $appliedKey;
        }
    }

    return [
        'pending'      => $pending,
        'applied'      => $appliedList,
        'changed'      => $changed,
        'missingFiles' => $missingFiles,
        'outOfOrder'   => $outOfOrder,
    ];
}

/**
 * 🔎 The read-only INFORMATION_SCHEMA question that tells whether a step's
 *    work is already done. Pure - it only builds the question.
 *
 * Every question is limited to the current database (DATABASE()) and uses
 * ? placeholders. insert_if_missing and update_if_unchanged have no question:
 * their SQL is written so that running it again changes nothing.
 *
 * @param array $step A valid step
 * @return array{sql: string, types: string, params: array<int, string>}|null
 */
function migrationStepExistsQuery(array $step): ?array
{
    $type = $step['type'];

    if ($type === 'create_table') {
        return [
            'sql'    => 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            'types'  => 's',
            'params' => [$step['table']],
        ];
    }

    if ($type === 'add_column') {
        return [
            'sql'    => 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            'types'  => 'ss',
            'params' => [$step['table'], $step['name']],
        ];
    }

    if ($type === 'add_index') {
        return [
            'sql'    => 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            'types'  => 'ss',
            'params' => [$step['table'], $step['name']],
        ];
    }

    if ($type === 'add_foreign_key') {
        return [
            'sql'    => 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                . ' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            'types'  => 'ss',
            'params' => [$step['table'], $step['name']],
        ];
    }

    return null;
}

/**
 * 🤔 Decide whether a step runs or is skipped. Pure apart from the checker.
 *
 * @param array    $step          A valid step
 * @param callable $existsChecker function (array $query): bool - answers the
 *                                question from migrationStepExistsQuery().
 *                                The real one asks the database; tests pass a
 *                                stand-in.
 * @return string 'run' or 'skip'
 */
function migrationDecideStep(array $step, callable $existsChecker): string
{
    $query = migrationStepExistsQuery($step);

    // No question = always run (the SQL itself is a no-op when not needed).
    if ($query === null) {
        return 'run';
    }

    if ($existsChecker($query) === true) {
        return 'skip';
    }

    return 'run';
}

// ==============================================================================
// 📜 PURE: reading install.sql (the frozen baseline)
// ==============================================================================

/**
 * 📜 Read the table and column names out of install.sql's CREATE TABLE blocks.
 *
 * Each block starts with a line "CREATE TABLE IF NOT EXISTS `name` (" and
 * ends with the line starting ") ENGINE". Inside it, every COLUMN line
 * starts with a back-quoted name followed by its type; key, index and
 * constraint lines start with a word (PRIMARY, UNIQUE, INDEX, CONSTRAINT,
 * REFERENCES ...), and the extra lines of a multi-line ENUM start with a
 * quote or a bracket, so they are not counted.
 *
 * @param string $installSql The text of install.sql
 * @return array<string, array<int, string>> Table name => column names, in file order
 */
function migrationParseInstallSqlSchema(string $installSql): array
{
    $tables       = [];
    $currentTable = null;
    $lines        = preg_split('/\r\n|\n|\r/', $installSql);

    if (!is_array($lines)) {
        return [];
    }

    foreach ($lines as $line) {
        $matches = [];

        if ($currentTable === null) {
            if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)`\s*\(/i', $line, $matches) === 1) {
                $currentTable = $matches[1];
                $tables[$currentTable] = [];
            }
            continue;
        }

        // The end of the block.
        if (preg_match('/^\s*\)\s*ENGINE\b/i', $line) === 1) {
            $currentTable = null;
            continue;
        }

        // A column line: back-quoted name, space, then a type word.
        if (preg_match('/^\s*`([A-Za-z0-9_]+)`\s+[A-Za-z]/', $line, $matches) === 1) {
            $tables[$currentTable][] = $matches[1];
        }
    }

    return $tables;
}

/**
 * ⚖️ Compare what install.sql promises with what the database has.
 *
 * Names are compared without regard to upper/lower case, because MySQL on
 * Windows and macOS stores table names in lower case.
 *
 * @param array<string, array<int, string>> $expected From migrationParseInstallSqlSchema()
 * @param array<string, array<int, string>> $actual   Table => columns read from the database
 * @return array{missingTables: array<int, string>, missingColumns: array<int, string>}
 *         missingColumns entries look like "tblUsers.emailVerifiedAt"; columns of
 *         a missing table are not listed (the whole table is missing).
 */
function migrationCompareBaseline(array $expected, array $actual): array
{
    // 🔡 Lower-case look-up of what the database has.
    $actualLower = [];
    foreach ($actual as $tableName => $columns) {
        $columnSet = [];
        foreach ($columns as $columnName) {
            $columnSet[strtolower((string) $columnName)] = true;
        }
        $actualLower[strtolower((string) $tableName)] = $columnSet;
    }

    $missingTables  = [];
    $missingColumns = [];

    foreach ($expected as $tableName => $columns) {
        $tableKey = strtolower((string) $tableName);

        if (!array_key_exists($tableKey, $actualLower)) {
            $missingTables[] = (string) $tableName;
            continue;
        }

        foreach ($columns as $columnName) {
            if (!array_key_exists(strtolower((string) $columnName), $actualLower[$tableKey])) {
                $missingColumns[] = (string) $tableName . '.' . (string) $columnName;
            }
        }
    }

    return ['missingTables' => $missingTables, 'missingColumns' => $missingColumns];
}

// ==============================================================================
// 📁 Reading migration files (file system only, no database)
// ==============================================================================

/**
 * 📁 List the migration files in a folder, in number order.
 *
 * Files that are not ".php" (README, .htaccess ...) are ignored. A ".php"
 * file whose name does not follow NNNN_short_name.php, or two files with the
 * same number, is an error: nothing runs until it is fixed.
 *
 * @param string $directory Folder to read
 * @return array{files: array<string, string>, errors: array<int, string>} key => full path
 */
function migrationDiscoverFiles(string $directory): array
{
    $files  = [];
    $errors = [];

    if (!is_dir($directory)) {
        return ['files' => [], 'errors' => ['the migrations folder was not found']];
    }

    $entries = scandir($directory);
    if (!is_array($entries)) {
        return ['files' => [], 'errors' => ['the migrations folder could not be read']];
    }

    $numbersSeen = [];

    foreach ($entries as $entry) {
        if (strtolower(substr($entry, -4)) !== '.php') {
            continue;
        }

        $key = migrationKeyFromFileName($entry);
        if ($key === null) {
            $errors[] = 'the file name "' . $entry . '" does not follow NNNN_short_name.php (four digits, lower-case words)';
            continue;
        }

        $number = substr($key, 0, 4);
        if (array_key_exists($number, $numbersSeen)) {
            $errors[] = 'two migration files share the number ' . $number . ' (' . $numbersSeen[$number] . ' and ' . $entry . ')';
            continue;
        }
        $numbersSeen[$number] = $entry;

        $files[$key] = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $entry;
    }

    ksort($files, SORT_STRING);

    return ['files' => $files, 'errors' => $errors];
}

/**
 * 📥 Load one migration file: its fingerprint and the array it returns.
 *
 * The file is included inside a closure so it cannot see or change this
 * function's variables. Migration files only return an array; any error
 * while loading is reported, not thrown.
 *
 * @param string $path Full path of the file
 * @return array{ok: bool, definition: mixed, checksum: string, error: string}
 */
function migrationLoadFile(string $path): array
{
    $contents = @file_get_contents($path);

    if (!is_string($contents)) {
        return ['ok' => false, 'definition' => null, 'checksum' => '', 'error' => 'could not be read'];
    }

    $checksum = migrationChecksumForContents($contents);

    $loader = static function (string $migrationFilePath): mixed {
        return include $migrationFilePath;
    };

    try {
        $definition = $loader($path);
    } catch (\Throwable $loadError) {
        return [
            'ok'         => false,
            'definition' => null,
            'checksum'   => $checksum,
            'error'      => 'could not be loaded (' . get_class($loadError) . ')',
        ];
    }

    return ['ok' => true, 'definition' => $definition, 'checksum' => $checksum, 'error' => ''];
}

/**
 * 📚 Discover, load and fingerprint every migration file.
 *
 * @param string $directory Migrations folder
 * @return array{migrations: array<string, array{path: string, checksum: string, definition: mixed, loadError: string}>, errors: array<int, string>}
 */
function migrationLoadAll(string $directory): array
{
    $discovered = migrationDiscoverFiles($directory);
    $migrations = [];

    foreach ($discovered['files'] as $key => $path) {
        $loaded = migrationLoadFile($path);
        $migrations[$key] = [
            'path'       => $path,
            'checksum'   => $loaded['checksum'],
            'definition' => $loaded['definition'],
            'loadError'  => $loaded['error'],
        ];
    }

    return ['migrations' => $migrations, 'errors' => $discovered['errors']];
}

// ==============================================================================
// 💻 PURE: command line options, exit codes and the report
// ==============================================================================

/**
 * 💻 Read the command-line options.
 *
 * Exactly one of --status, --dry-run, --apply or --help is needed.
 *
 * @param array<int, string> $argv Full argv (argv[0] is the script name)
 * @return array{ok: bool, mode: string, error: string}
 */
function migrationParseCliArgs(array $argv): array
{
    $modes = [
        '--status'  => 'status',
        '--dry-run' => 'dry-run',
        '--apply'   => 'apply',
        '--help'    => 'help',
        '-h'        => 'help',
    ];

    $chosen    = [];
    $arguments = array_slice(array_values($argv), 1);

    foreach ($arguments as $argument) {
        $argument = (string) $argument;

        if (!array_key_exists($argument, $modes)) {
            return ['ok' => false, 'mode' => '', 'error' => 'Unknown option: ' . substr(preg_replace('/[^\x20-\x7E]/', '?', $argument), 0, 60)];
        }

        $chosen[$modes[$argument]] = true;
    }

    if (count($chosen) === 0) {
        return ['ok' => false, 'mode' => '', 'error' => 'Choose one of --status, --dry-run or --apply.'];
    }

    if (count($chosen) > 1) {
        return ['ok' => false, 'mode' => '', 'error' => 'Choose only one of --status, --dry-run, --apply or --help.'];
    }

    return ['ok' => true, 'mode' => (string) array_key_first($chosen), 'error' => ''];
}

/**
 * 📖 The help text for the command line.
 *
 * @return array<int, string> Lines
 */
function migrationCliUsage(): array
{
    return [
        'CueRCode database upgrades (migrations)',
        '',
        'Usage (run with the command-line php, from any folder):',
        '  php _includes/cli/migrate.php --status    list applied and waiting upgrades',
        '  php _includes/cli/migrate.php --dry-run   show what --apply would do; change nothing',
        '  php _includes/cli/migrate.php --apply     apply every waiting upgrade, in number order',
        '  php _includes/cli/migrate.php --help      show this help',
        '',
        'Exit codes:',
        '  0  up to date, applied, or dry run finished',
        '  1  a step or the database failed (run again after fixing - every step is safe to repeat)',
        '  2  refused, no upgrades applied (another upgrade running, database older than the',
        '     supported starting point, a broken migration file, or a bad option)',
        '  3  --status only: upgrades are waiting',
    ];
}

/**
 * 🚦 Turn a result into the command-line exit code (plan §5.1).
 *
 * @param string $mode   'status', 'dry-run' or 'apply'
 * @param array  $result From migrationStatus() / migrationApplyPending()
 * @return int 0, 1, 2 or 3
 */
function migrationExitCodeForResult(string $mode, array $result): int
{
    $status = '';
    if (isset($result['status']) && is_string($result['status'])) {
        $status = $result['status'];
    }

    if ($status === 'failed') {
        return CUERCODE_MIGRATE_EXIT_FAILED;
    }

    if ($status === 'busy' || $status === 'baseline_refused' || $status === 'invalid') {
        return CUERCODE_MIGRATE_EXIT_REFUSED;
    }

    if ($status !== 'ok') {
        // Anything unexpected is treated as a failure, never as success.
        return CUERCODE_MIGRATE_EXIT_FAILED;
    }

    if ($mode === 'status') {
        $pendingCount = 0;
        if (isset($result['pending']) && is_array($result['pending'])) {
            $pendingCount = count($result['pending']);
        }

        $missingTableCount = 0;
        if (isset($result['baseline']['missingTables']) && is_array($result['baseline']['missingTables'])) {
            $missingTableCount = count($result['baseline']['missingTables']);
        }

        if ($pendingCount > 0 || $missingTableCount > 0) {
            return CUERCODE_MIGRATE_EXIT_PENDING;
        }
    }

    return CUERCODE_MIGRATE_EXIT_OK;
}

/**
 * 📝 Plain-English report lines for a result. Never includes setting values
 *    or SQL - only names, counts and problems.
 *
 * @param string $mode               'status', 'dry-run' or 'apply'
 * @param array  $result             From migrationStatus() / migrationApplyPending()
 * @param bool   $includeErrorDetail True (the command-line tool's default): include the raw
 *                                   MySQL/PHP error text behind a failure. False (review finding
 *                                   F3 - used by the web installer, a page members of the public
 *                                   can reach): replace that raw technical text with one generic,
 *                                   plain-English line, because a database error message can
 *                                   contain internal details (table layouts, file paths) that
 *                                   should never reach a browser. Either way the FULL detail
 *                                   still belongs in the PHP error log - see
 *                                   public_html/install/index.php, which writes it there itself
 *                                   before ever calling this function with $includeErrorDetail
 *                                   false.
 * @return array<int, string> Lines
 */
function migrationFormatReport(string $mode, array $result, bool $includeErrorDetail = true): array
{
    $lines = [];

    if ($mode === 'status') {
        $lines[] = 'CueRCode database upgrades - status';
    } elseif ($mode === 'dry-run') {
        $lines[] = 'CueRCode database upgrades - dry run (nothing is changed)';
    } else {
        $lines[] = 'CueRCode database upgrades - apply';
    }

    $status = '';
    if (isset($result['status']) && is_string($result['status'])) {
        $status = $result['status'];
    }

    // 🧱 Baseline (install.sql) findings.
    if (isset($result['baseline']) && is_array($result['baseline'])) {
        $baseline = $result['baseline'];

        if (isset($baseline['createdTables']) && is_array($baseline['createdTables']) && $baseline['createdTables'] !== []) {
            $lines[] = 'Created missing starting tables from install.sql: ' . implode(', ', $baseline['createdTables']);

            // 🌱 F1: re-creating a baseline table from install.sql never loads
            //    its STARTING DATA - that only ever comes from seed_data.sql,
            //    which only the web installer runs. This note only appears
            //    for --apply (the only mode that can ever create a table), so
            //    it is seen exactly when it matters: typically "migrate.php
            //    --apply" was run directly against a brand-new, empty
            //    database, before the web installer ever touched it.
            if ($mode === 'apply') {
                $lines[] = 'Starting data not loaded: the tables above are now empty. Run the web installer '
                    . '(public_html/install/) to load the starting QR code types, predefined logos and '
                    . 'subscription plans.';
            }
        } elseif (isset($baseline['missingTables']) && is_array($baseline['missingTables']) && $baseline['missingTables'] !== []) {
            if ($mode === 'apply') {
                $lines[] = 'Starting tables still missing: ' . implode(', ', $baseline['missingTables']);
            } else {
                $lines[] = 'Starting tables missing (--apply creates them from install.sql): ' . implode(', ', $baseline['missingTables']);
            }
        }

        if (isset($baseline['missingColumns']) && is_array($baseline['missingColumns']) && $baseline['missingColumns'] !== []) {
            $lines[] = 'This database is older than the supported starting point. Missing columns:';
            foreach ($baseline['missingColumns'] as $missingColumn) {
                $lines[] = '  - ' . $missingColumn;
            }
            $lines[] = 'It needs a one-off catch-up upgrade before these upgrades can run. No upgrades were applied.';
        }
    }

    // 📋 Per-migration lines.
    if (isset($result['migrations']) && is_array($result['migrations'])) {
        foreach ($result['migrations'] as $migration) {
            $key = (string) $migration['key'];
            $outcome = (string) $migration['outcome'];

            if ($outcome === 'applied') {
                $lines[] = 'Applied ' . $key . ': ' . (int) $migration['stepsRun'] . ' step(s) run, '
                    . (int) $migration['stepsSkipped'] . ' already done.';
            } elseif ($outcome === 'would_apply') {
                $lines[] = 'Would apply ' . $key . ': ' . (int) $migration['stepsRun'] . ' step(s) to run, '
                    . (int) $migration['stepsSkipped'] . ' already done.';
            } elseif ($outcome === 'failed') {
                $lines[] = 'FAILED ' . $key . ' - not recorded; fix the problem and run --apply again (every step is safe to repeat).';
            } elseif ($outcome === 'pending') {
                $lines[] = 'Waiting: ' . $key;
            } elseif ($outcome === 'invalid') {
                $lines[] = 'Broken migration file ' . $key . ':';
            }

            if (isset($migration['problems']) && is_array($migration['problems'])) {
                // 🔒 F3: a 'failed' migration's problems are built from the
                //    raw MySQL/PHP error text (migrationRunLocked() above),
                //    which can mention internal table layouts or file paths -
                //    fine for the command line (a trusted operator, run with
                //    $includeErrorDetail left at its true default), never fine
                //    on a page a member of the public could reach (the web
                //    installer, which asks for $includeErrorDetail = false).
                //    An 'invalid' migration's problems are only ever plain
                //    descriptions of the migration FILE's own shape (step
                //    numbers, table/column names) - never a database error -
                //    so those are always shown.
                if ($includeErrorDetail || $outcome !== 'failed') {
                    foreach ($migration['problems'] as $problem) {
                        $lines[] = '  - ' . $problem;
                    }
                } else {
                    $lines[] = '  - technical detail was written to the server error log';
                }
            }
        }
    }

    // ✅ Already-applied list (status only).
    if ($mode === 'status' && isset($result['applied']) && is_array($result['applied'])) {
        foreach ($result['applied'] as $appliedRow) {
            $lines[] = 'Applied earlier: ' . (string) $appliedRow['key'] . ' (' . (string) $appliedRow['appliedAt']
                . ' UTC, by ' . (string) $appliedRow['appliedBy'] . ')';
        }
    }

    // ⚠️ Warnings.
    if (isset($result['warnings']) && is_array($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
            $lines[] = 'Warning: ' . $warning;
        }
    }

    // 🏁 Headline.
    if ($status === 'busy') {
        $lines[] = 'Refused: another upgrade is running right now. Try again in a minute.';
    } elseif ($status === 'invalid') {
        $lines[] = 'Refused: a migration file is broken (see above). No upgrades were applied.';
    } elseif ($status === 'baseline_refused') {
        $lines[] = 'Refused: the database does not match the supported starting point. No upgrades were applied.';
    } elseif ($status === 'failed') {
        // 🔒 F3: $result['error'] can carry raw MySQL/PHP error text (for
        //    example a baseline install.sql failure, or an unexpected
        //    exception's message) - never shown on a public page.
        if ($includeErrorDetail) {
            $error = '';
            if (isset($result['error']) && is_string($result['error'])) {
                $error = $result['error'];
            }
            $lines[] = 'Failed: ' . $error;
        } else {
            $lines[] = 'Failed: a technical problem stopped the upgrade. Full detail was written to the server error log.';
        }
    } elseif ($status === 'ok') {
        $pendingCount = 0;
        if (isset($result['pending']) && is_array($result['pending'])) {
            $pendingCount = count($result['pending']);
        }

        if ($mode === 'status') {
            if ($pendingCount === 0) {
                $lines[] = 'Already up to date.';
            } else {
                $lines[] = $pendingCount . ' upgrade(s) waiting. Run --apply to apply them.';
            }
        } elseif ($mode === 'dry-run') {
            if ($pendingCount === 0) {
                $lines[] = 'Already up to date - nothing would change.';
            } else {
                $lines[] = 'Dry run finished - nothing was changed.';
            }
        } else {
            if ($pendingCount === 0) {
                $lines[] = 'Already up to date.';
            } else {
                $lines[] = 'Done - ' . $pendingCount . ' upgrade(s) applied.';
            }
        }
    }

    return $lines;
}

/**
 * 🚦 Classify ONE line from migrationFormatReport() as 'warning', 'error' or
 *    'info', so a screen that already has its own warning/error/info styling
 *    (the web installer, and later the admin "Upgrades" page) can reuse the
 *    exact same plain-English lines without re-deciding what they mean. Pure
 *    string matching - never looks at a result array itself.
 *
 * @param string $line One line from migrationFormatReport()
 * @return string 'warning', 'error' or 'info'
 */
function migrationReportLineSeverity(string $line): string
{
    if (strpos($line, 'Warning:') === 0) {
        return 'warning';
    }

    // 🚨 Every headline/explanation that means "something stopped the run" or
    //    "this needs a human to look at it" - checked as a fixed, known list
    //    rather than guessing from punctuation, so a new report line defaults
    //    to 'info' (never silently hidden as success) until this list is
    //    taught about it.
    $errorStarts = [
        'FAILED',
        'Refused:',
        'Failed:',
        'This database is older than',
        'Broken migration file',
    ];

    foreach ($errorStarts as $errorStart) {
        if (strpos($line, $errorStart) === 0) {
            return 'error';
        }
    }

    return 'info';
}

// ==============================================================================
// 🌱 PURE + DATABASE: protecting seed_data.sql from running twice
// ==============================================================================
// seed_data.sql (unlike install.sql) is a set of plain INSERT statements with
// no "insert if missing" protection, so running it a second time against a
// database that already has its starting rows throws duplicate-key errors (or
// stops part-way through a multi_query() run). The web installer calls
// migrationDbSeedDataAlreadyPresent() before it ever touches seed_data.sql.
// ==============================================================================

/**
 * 🌱 The tables that, if any already has rows, mean "this database has
 *    already been seeded" (plan §9, installer known-limitation fix; narrowed
 *    2026-11-23, review finding F1).
 *
 * ⚠️ WHY NOT tblSettings: migration 0002 (and every later settings migration)
 *    also inserts rows into tblSettings with "insert if missing" steps, so a
 *    database that has only ever had `migrate.php --apply` run against it
 *    (no web installer, no seed_data.sql) already has tblSettings rows. If
 *    tblSettings were on this list, the web installer would then wrongly
 *    believe seed_data.sql had already run and would skip it forever - the
 *    QR code types, predefined logos and subscription plans would never be
 *    loaded. The three tables below are ONLY ever filled by seed_data.sql: no
 *    migration file writes to any of them (tests/test_migrations.php checks
 *    this stays true for every migration, and the real-database check in
 *    tests/integration/migrations_real_mysql.php proves the empty-database
 *    scenario this function exists to protect against).
 *
 * Kept as its own function (rather than inline) so a caller can see the exact
 * list without reading the database function below, and so a test can check
 * it directly.
 *
 * @return array<int, string> Plain table names (no back-quotes)
 */
function migrationSeedProtectionTables(): array
{
    return ['tblQRCodeTypes', 'tblPredefinedLogos', 'tblSubscriptionPlans'];
}

/**
 * 🧱 EVERY table seed_data.sql writes to (independent review, Codex round 2
 *    finding R1-2, 2026-11-25). migrationDbRunSeedSql() checks that each one
 *    is an ordinary InnoDB table before loading anything, because only InnoDB
 *    can undo a half-finished load. tests/test_migrations.php checks that this
 *    list names every "INSERT INTO" table in seed_data.sql.
 *
 * @return array<int, string> Plain table names (no back-quotes)
 */
function migrationSeedTables(): array
{
    return ['tblSettings', 'tblQRCodeTypes', 'tblPredefinedLogos', 'tblSubscriptionPlans'];
}

/**
 * 🏁 The settings row seed_data.sql writes as its VERY LAST statement
 *    (independent review, Codex round 2 finding R1-1, 2026-11-25).
 *
 * Because it is last, it can only exist once every earlier insert in the file
 * has succeeded: the installer loads the file as one transaction, and the
 * mysql command-line client stops at the first error. So "the marker is
 * there" means "the whole of seed_data.sql loaded", whatever the owner has
 * changed or deleted since. It lives in tblSettings, is not editable, and is
 * not on the admin settings screen (that screen only touches the settings in
 * its own list).
 *
 * @return string The settingKey of the marker row
 */
function migrationSeedMarkerKey(): string
{
    return 'seed_data_loaded';
}

/**
 * 📋 The starting rows seed_data.sql puts in each protection table, by their
 *    unique name (independent review, Codex round 2 finding R1-1,
 *    2026-11-25).
 *
 * Used ONLY for a database that has no completion marker (one seeded before
 * the marker existed, or one where someone has typed rows in by hand): it is
 * then "complete" only if EVERY one of these rows is there. Before this, one
 * row in each table was enough, so a database holding one QR code type, one
 * logo and one plan was treated as fully loaded and the installer locked
 * itself with the rest missing.
 *
 * seed_data.sql is a frozen starting point, so this list does not change;
 * tests/test_migrations.php reads seed_data.sql and fails if the two ever
 * disagree.
 *
 * @return array<string, array{column: string, keys: array<int, string>}> Table => its unique name column and the seeded names
 */
function migrationSeedExpectedRows(): array
{
    return [
        'tblQRCodeTypes' => [
            'column' => 'typeName',
            'keys'   => [
                'url', 'text', 'email', 'phone', 'sms', 'whatsapp', 'zoom', 'geo', 'vcard', 'calendar',
                'paypal', 'bitcoin', 'mp3', 'video', 'pdf', 'image', 'appstore', 'facebook', 'youtube', 'wifi',
                'linkedin', 'facebook_messenger', 'instagram', 'rating', 'deeplink', 'gs1_product',
                'barcode_code128', 'barcode_code39', 'barcode_ean13', 'barcode_ean8', 'barcode_upca',
                'barcode_upce', 'barcode_itf', 'barcode_codabar', 'barcode_datamatrix', 'barcode_pdf417',
                'barcode_aztec', 'dynamic_url',
            ],
        ],
        'tblPredefinedLogos' => [
            'column' => 'logoName',
            'keys'   => [
                'facebook', 'instagram', 'twitter', 'youtube', 'linkedin', 'tiktok', 'pinterest', 'snapchat',
                'whatsapp', 'telegram', 'skype', 'paypal', 'bitcoin', 'apple', 'android', 'appstore',
                'playstore', 'globe', 'email', 'phone', 'location', 'calendar', 'wifi', 'document', 'camera',
                'qrcode',
            ],
        ],
        'tblSubscriptionPlans' => [
            'column' => 'planKey',
            'keys'   => ['anonymous', 'free', 'pro', 'business'],
        ],
    ];
}

/**
 * 🔑 The starting SETTINGS a database seeded before the completion marker
 *    existed must hold, by name (independent review, Codex round 3 finding
 *    2, 2026-11-25).
 *
 * Used ONLY when there is no completion marker. The 68 starting rows in the
 * three tables (migrationSeedExpectedRows()) are not enough on their own: a
 * database holding all 68 but missing a starting setting was called
 * "complete", the installer skipped the seed and wrote its lock, and the
 * missing setting was never put back. The list is kept short and explicit
 * (a handful of settings from different groups, each of which seed_data.sql
 * writes); migrationSeedMinimumSettingsCount() is the second guard, on the
 * number of settings. tests/test_migrations.php reads seed_data.sql and
 * fails if a name here is not seeded or the count drifts.
 *
 * @return array<int, string> settingKey values
 */
function migrationSeedRequiredSettingKeys(): array
{
    return [
        'site_name',
        'site_url',
        'app_version',
        'copyright_holder',
        'bot_protection_provider',
        'sso_enabled',
        'dynamic_qr_enabled',
        'dynamic_redirect_base_url',
        'redirect_allow_http',
    ];
}

/**
 * 🔢 The fewest rows tblSettings may hold for a marker-free database to be
 *    called complete: the number of starting settings seed_data.sql inserts
 *    (not counting the completion marker). Settings added later by upgrades
 *    only push the real count higher. tests/test_migrations.php counts the
 *    settings in seed_data.sql and fails if this number is not the same.
 *
 * @return int Minimum number of tblSettings rows
 */
function migrationSeedMinimumSettingsCount(): int
{
    return 80;
}

/**
 * 🌱 Does this database already hold seed data? A thin yes/no wrapper around
 *    migrationDbSeedDataState() below, kept for older callers and tests.
 *
 * "present" is true when ANY of the protection tables has rows - that is,
 * when the state is "partial" or "complete". The web installer itself now
 * uses the three-way state instead, because "partial" must never be treated
 * as "already done" (independent review, Codex C1 finding 1, 2026-11-25).
 *
 * 🔒 Codex C1 finding 4 (2026-11-25): a check that FAILS (lost connection,
 * missing permission, a broken view...) is now reported as ok = false with
 * the error, never quietly as "no data". Only a table that genuinely does not
 * exist yet still counts as "nothing to protect".
 *
 * @param \mysqli $db Connection with the site database selected
 * @return array{ok: bool, present: bool, error: string}
 */
function migrationDbSeedDataAlreadyPresent(\mysqli $db): array
{
    $state = migrationDbSeedDataState($db);

    if ($state['ok'] !== true) {
        return ['ok' => false, 'present' => false, 'error' => $state['error']];
    }

    if ($state['state'] === 'none') {
        return ['ok' => true, 'present' => false, 'error' => ''];
    }

    return ['ok' => true, 'present' => true, 'error' => ''];
}

/**
 * 🌱 How much of the starting data (seed_data.sql) is in this database?
 *    (independent review, Codex C1 findings 1 and 4, and Codex round 2
 *    finding R1-1, 2026-11-25)
 *
 * Looks at every table from migrationSeedProtectionTables() and answers:
 *   - "none"     - there is NO completion marker and every one of them is
 *                  empty or missing: seed_data.sql has not run (or a failed
 *                  run was rolled back), so it may run;
 *   - "complete" - seed_data.sql has run in full. Proven EITHER by its
 *                  completion marker (migrationSeedMarkerKey(), the file's
 *                  last statement - so rows the owner has since changed or
 *                  deleted do not matter), OR, for a database with no marker,
 *                  by EVERY starting row in migrationSeedExpectedRows() being
 *                  there AND the starting settings (migrationSeedRequiredSettingKeys()
 *                  and migrationSeedMinimumSettingsCount()). A PRESENT marker
 *                  always means complete, even when the owner has emptied the
 *                  three tables (decision 0018): seeding already happened;
 *   - "partial"  - anything else: some starting rows are there but not all of
 *                  them, and there is no marker. seed_data.sql cannot be run
 *                  again safely (plain INSERTs), and the database must not be
 *                  called "installed" either, so the installer stops and says
 *                  what is missing ("summary").
 *
 * ⚠️ Before Codex round 2, "complete" only meant "each table has at least one
 *    row", so a database holding one QR code type, one logo and one plan was
 *    called complete and the installer locked itself (reproduced on MySQL
 *    8.0.36 and MariaDB 10.6.28).
 *
 * A table that does not exist yet counts as empty. It is found by asking
 * INFORMATION_SCHEMA first (a prepared statement), so the row count below is
 * only ever run on a table that exists - and ANY failure of any question is
 * returned as ok = false with the error, never as "no data" or "complete".
 *
 * @param \mysqli $db Connection with the site database selected
 * @return array{ok: bool, state: string, filled: array<int, string>, empty: array<int, string>, missing: array<string, int>, marker: bool, summary: string, error: string}
 *         filled/empty: tables with / without rows; missing: table => how many
 *         of its starting rows are not there (only worked out when there is
 *         no marker); marker: whether the completion marker is there;
 *         summary: plain English, safe to show, saying what is missing
 */
function migrationDbSeedDataState(\mysqli $db): array
{
    $filledTables = [];
    $emptyTables  = [];
    $result       = [
        'ok'      => false,
        'state'   => '',
        'filled'  => [],
        'empty'   => [],
        'missing' => [],
        'marker'  => false,
        'summary' => '',
        'error'   => '',
    ];

    foreach (migrationSeedProtectionTables() as $tableName) {
        // 1️⃣ Does the table exist? (prepared; the name is a bound value)
        $exists = migrationDbTableExistsState($db, $tableName);

        if ($exists['ok'] !== true) {
            $result['error'] = $exists['error'];
            return $result;
        }

        if ($exists['exists'] !== true) {
            // Missing table: nothing loaded into it yet.
            $emptyTables[] = $tableName;
            continue;
        }

        // 2️⃣ Does it have rows? The table name comes only from the fixed list
        //    in migrationSeedProtectionTables() (never from user input), so it
        //    is safe to place in the SQL text - a prepared statement cannot
        //    take a table name as a bound value.
        $counted = migrationDbFetchAll($db, 'SELECT COUNT(*) AS `rowCount` FROM `' . $tableName . '`');

        if ($counted['ok'] !== true || $counted['rows'] === []) {
            $result['error'] = 'Could not count the rows in ' . $tableName . ' (' . $counted['error'] . ')';
            return $result;
        }

        if ((int) $counted['rows'][0]['rowCount'] > 0) {
            $filledTables[] = $tableName;
        } else {
            $emptyTables[] = $tableName;
        }
    }

    $result['filled'] = $filledTables;
    $result['empty']  = $emptyTables;

    // 3️⃣ The completion marker: the last thing seed_data.sql writes. It is
    //    checked BEFORE "are the tables empty?" (decision 0018, Codex round 3
    //    finding 1): a present marker means seeding already happened, so an
    //    owner who deliberately emptied the three tables does not get the
    //    default rows put back just because the installer lock file was lost.
    //    A deliberate clean re-seed means removing the marker setting first.
    $marker = migrationDbSeedMarkerPresent($db);

    if ($marker['ok'] !== true) {
        $result['error'] = $marker['error'];
        return $result;
    }

    if ($marker['present'] === true) {
        $result['ok']     = true;
        $result['state']  = 'complete';
        $result['marker'] = true;
        return $result;
    }

    // 🈳 No marker and nothing in any of the three tables: a fresh install,
    //    so seed_data.sql may run.
    if ($filledTables === []) {
        $result['ok']    = true;
        $result['state'] = 'none';
        return $result;
    }

    // 4️⃣ No marker: every starting row must be there, by its unique name.
    //    The names are bound values; the table and column names come only
    //    from the fixed list in migrationSeedExpectedRows().
    $missingCounts = [];
    $summaryParts  = [];

    foreach (migrationSeedExpectedRows() as $tableName => $expected) {
        $expectedCount = count($expected['keys']);
        $presentCount  = 0;

        if (in_array($tableName, $filledTables, true)) {
            $placeholders = implode(', ', array_fill(0, $expectedCount, '?'));
            $found        = migrationDbFetchAll(
                $db,
                'SELECT COUNT(*) AS `foundCount` FROM `' . $tableName . '` WHERE `' . $expected['column'] . '` IN (' . $placeholders . ')',
                str_repeat('s', $expectedCount),
                $expected['keys']
            );

            if ($found['ok'] !== true || $found['rows'] === []) {
                $result['error'] = 'Could not check the starting rows in ' . $tableName . ' (' . $found['error'] . ')';
                return $result;
            }

            $presentCount = (int) $found['rows'][0]['foundCount'];
        }

        if ($presentCount < $expectedCount) {
            $missingCounts[$tableName] = $expectedCount - $presentCount;
            $summaryParts[]            = $tableName . ' has ' . $presentCount . ' of its ' . $expectedCount . ' starting rows';
        }
    }

    // 5️⃣ ...and the starting settings too (Codex round 3 finding 2): the key
    //    settings by name, and a minimum number of settings rows. A missing
    //    tblSettings counts as having none of them.
    $settingsCheck = migrationDbSeedSettingsShortfall($db);

    if ($settingsCheck['ok'] !== true) {
        $result['error'] = $settingsCheck['error'];
        return $result;
    }

    if ($settingsCheck['short'] > 0) {
        $missingCounts['tblSettings'] = $settingsCheck['short'];
        $summaryParts[]               = $settingsCheck['summary'];
    }

    $result['ok']      = true;
    $result['missing'] = $missingCounts;

    if ($missingCounts === []) {
        $result['state'] = 'complete';
    } else {
        $result['state']   = 'partial';
        $result['summary'] = implode('; ', $summaryParts);
    }

    return $result;
}

/**
 * 🔑 How many of the starting settings is this marker-free database short of?
 *    (Codex round 3 finding 2.) Counts the key settings from
 *    migrationSeedRequiredSettingKeys() that are not there, and compares the
 *    number of tblSettings rows with migrationSeedMinimumSettingsCount();
 *    "short" is the larger of the two shortfalls. All values are bound; any
 *    failure of any question is ok = false, never "nothing missing".
 *
 * @param \mysqli $db Connection with the site database selected
 * @return array{ok: bool, short: int, summary: string, error: string}
 */
function migrationDbSeedSettingsShortfall(\mysqli $db): array
{
    $failed = ['ok' => false, 'short' => 0, 'summary' => '', 'error' => ''];
    $keys   = migrationSeedRequiredSettingKeys();
    $minimum = migrationSeedMinimumSettingsCount();

    $settingsTable = migrationDbTableExistsState($db, 'tblSettings');

    if ($settingsTable['ok'] !== true) {
        $failed['error'] = $settingsTable['error'];
        return $failed;
    }

    if ($settingsTable['exists'] !== true) {
        return [
            'ok'      => true,
            'short'   => $minimum,
            'summary' => 'tblSettings does not exist, so none of the ' . $minimum . ' starting settings are there',
            'error'   => '',
        ];
    }

    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $keyRows      = migrationDbFetchAll(
        $db,
        'SELECT COUNT(*) AS `foundCount` FROM `tblSettings` WHERE `settingKey` IN (' . $placeholders . ')',
        str_repeat('s', count($keys)),
        $keys
    );

    if ($keyRows['ok'] !== true || $keyRows['rows'] === []) {
        $failed['error'] = 'Could not check the starting settings in tblSettings (' . $keyRows['error'] . ')';
        return $failed;
    }

    $totalRows = migrationDbFetchAll($db, 'SELECT COUNT(*) AS `rowCount` FROM `tblSettings`');

    if ($totalRows['ok'] !== true || $totalRows['rows'] === []) {
        $failed['error'] = 'Could not count the rows in tblSettings (' . $totalRows['error'] . ')';
        return $failed;
    }

    $keysMissing  = count($keys) - (int) $keyRows['rows'][0]['foundCount'];
    $rowCount     = (int) $totalRows['rows'][0]['rowCount'];
    $countMissing = max(0, $minimum - $rowCount);
    $short        = max($keysMissing, $countMissing);

    if ($short <= 0) {
        return ['ok' => true, 'short' => 0, 'summary' => '', 'error' => ''];
    }

    return [
        'ok'      => true,
        'short'   => $short,
        'summary' => 'tblSettings is missing starting settings (' . $keysMissing . ' of ' . count($keys)
            . ' key settings absent; ' . $rowCount . ' settings rows where at least ' . $minimum . ' are expected)',
        'error'   => '',
    ];
}

/**
 * 🔎 Does a table exist in the selected database? Answers with ok = false
 *    when the question itself fails, so a failure is never read as "no".
 *
 * @param \mysqli $db        Connection with the site database selected
 * @param string  $tableName Plain table name (a bound value)
 * @return array{ok: bool, exists: bool, error: string}
 */
function migrationDbTableExistsState(\mysqli $db, string $tableName): array
{
    $existence = migrationDbFetchAll(
        $db,
        'SELECT COUNT(*) AS `tableCount` FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
        's',
        [$tableName]
    );

    if ($existence['ok'] !== true || $existence['rows'] === []) {
        return ['ok' => false, 'exists' => false, 'error' => 'Could not check whether ' . $tableName . ' exists (' . $existence['error'] . ')'];
    }

    return ['ok' => true, 'exists' => ((int) $existence['rows'][0]['tableCount'] > 0), 'error' => ''];
}

/**
 * 🏁 Is seed_data.sql's completion marker (migrationSeedMarkerKey()) in
 *    tblSettings? A missing tblSettings simply means "no".
 *
 * @param \mysqli $db Connection with the site database selected
 * @return array{ok: bool, present: bool, error: string}
 */
function migrationDbSeedMarkerPresent(\mysqli $db): array
{
    $settingsTable = migrationDbTableExistsState($db, 'tblSettings');

    if ($settingsTable['ok'] !== true) {
        return ['ok' => false, 'present' => false, 'error' => $settingsTable['error']];
    }

    if ($settingsTable['exists'] !== true) {
        return ['ok' => true, 'present' => false, 'error' => ''];
    }

    $markerRows = migrationDbFetchAll(
        $db,
        'SELECT COUNT(*) AS `markerCount` FROM `tblSettings` WHERE `settingKey` = ?',
        's',
        [migrationSeedMarkerKey()]
    );

    if ($markerRows['ok'] !== true || $markerRows['rows'] === []) {
        return ['ok' => false, 'present' => false, 'error' => 'Could not look for the starting-data marker (' . $markerRows['error'] . ')'];
    }

    return ['ok' => true, 'present' => ((int) $markerRows['rows'][0]['markerCount'] > 0), 'error' => ''];
}

/**
 * 🧱 Is every table seed_data.sql writes to an ordinary InnoDB table?
 *    (independent review, Codex round 2 finding R1-2, 2026-11-25)
 *
 * The all-or-nothing load in migrationDbRunSeedSql() is a database
 * transaction, and only InnoDB tables take part in one. If, say,
 * tblPredefinedLogos had been changed to MyISAM, its 26 rows stayed behind
 * after a later failure and the "rollback" - while the installer said nothing
 * was kept (reproduced on MySQL 8.0.36 and MariaDB 10.6.28). So the engine is
 * checked first and the load is refused unless every table is InnoDB. A
 * missing table, or a view, is refused too (the load would fail anyway).
 *
 * @param \mysqli $db Connection with the site database selected
 * @return array{ok: bool, problems: array<int, string>, error: string}
 *         ok = false with error: the check itself failed; problems: one plain
 *         sentence per table that is not usable (safe to show)
 */
function migrationDbSeedTablesTransactional(\mysqli $db): array
{
    $tables       = migrationSeedTables();
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));
    $found        = migrationDbFetchAll(
        $db,
        'SELECT `TABLE_NAME` AS `tableName`, `TABLE_TYPE` AS `tableType`, `ENGINE` AS `engineName` FROM `INFORMATION_SCHEMA`.`TABLES`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (' . $placeholders . ')',
        str_repeat('s', count($tables)),
        $tables
    );

    if ($found['ok'] !== true) {
        return ['ok' => false, 'problems' => [], 'error' => 'Could not check the storage type of the starting-data tables (' . $found['error'] . ')'];
    }

    $byName = [];
    foreach ($found['rows'] as $row) {
        $byName[(string) $row['tableName']] = $row;
    }

    $problems = [];

    foreach ($tables as $tableName) {
        if (!isset($byName[$tableName])) {
            $problems[] = $tableName . ' does not exist.';
            continue;
        }

        $row = $byName[$tableName];

        if ((string) $row['tableType'] !== 'BASE TABLE') {
            $problems[] = $tableName . ' is not an ordinary table.';
            continue;
        }

        $engineName = '';
        if ($row['engineName'] !== null) {
            $engineName = (string) $row['engineName'];
        }

        if (strcasecmp($engineName, 'InnoDB') !== 0) {
            $shownEngine = $engineName;
            if ($shownEngine === '') {
                $shownEngine = 'an unknown type';
            }
            $problems[] = $tableName . ' uses the ' . $shownEngine . ' storage type, which cannot undo a half-finished load. '
                . 'Change it with: ALTER TABLE `' . $tableName . '` ENGINE=InnoDB;';
        }
    }

    return ['ok' => true, 'problems' => $problems, 'error' => ''];
}

/**
 * 🌱 Load seed_data.sql ALL-OR-NOTHING (independent review, Codex C1
 *    finding 1, 2026-11-25).
 *
 * seed_data.sql is a handful of plain INSERT statements. Run on its own with
 * multi_query(), a failure part-way left the earlier inserts behind, and the
 * next attempt then saw "data already there" and skipped the rest - the
 * installer could lock itself with no logos or plans. Here the whole file is
 * wrapped in one transaction: every insert lands, or none does, so a failed
 * attempt leaves the database exactly as it was and a retry simply runs the
 * whole file again.
 *
 * ⚠️ This only works because every starting-data table is InnoDB and
 *    seed_data.sql contains nothing that commits on its own (no CREATE,
 *    ALTER, DROP, TRUNCATE...). tests/test_migrations.php checks the file
 *    stays that way, and since Codex round 2 (finding R1-2) the storage type
 *    of every table is checked here BEFORE anything is loaded: if any is not
 *    InnoDB the load is refused and nothing is written.
 *
 * Both kinds of MySQLi failure are handled: a false return and the
 * mysqli_sql_exception PHP 8.1+ throws by default. On any failure the open
 * transaction is rolled back here (and MySQL rolls it back anyway if the
 * connection drops).
 *
 * @param \mysqli $db      Connection with the site database selected
 * @param string  $seedSql Text of seed_data.sql
 * @return array{ok: bool, error: string, refusal: string}
 *         error holds full MySQL detail - log it, never show it to a visitor;
 *         refusal is plain English, safe to show, and is set only when the
 *         load was refused before it started (nothing was written)
 */
function migrationDbRunSeedSql(\mysqli $db, string $seedSql): array
{
    // 🧱 Codex round 2 finding R1-2: only load into tables that can undo it.
    $engineCheck = migrationDbSeedTablesTransactional($db);

    if ($engineCheck['ok'] !== true) {
        return [
            'ok'      => false,
            'error'   => 'seed_data.sql was not run: ' . $engineCheck['error'],
            'refusal' => 'The installer could not check the starting-data tables, so it did not load anything.',
        ];
    }

    if ($engineCheck['problems'] !== []) {
        return [
            'ok'      => false,
            'error'   => 'seed_data.sql was not run: ' . implode(' ', $engineCheck['problems']),
            'refusal' => 'The starting data was not loaded, and nothing was written, because it can only be loaded '
                . 'all-or-nothing into ordinary InnoDB tables: ' . implode(' ', $engineCheck['problems']),
        ];
    }

    // 🧱 Wrapped in one transaction. PHP_EOL before COMMIT matters: the file
    //    ends in "--" comment lines, and a comment runs to the end of its line.
    $wrappedSql = 'START TRANSACTION;' . PHP_EOL . $seedSql . PHP_EOL . 'COMMIT;' . PHP_EOL;
    $error      = '';

    try {
        if ($db->multi_query($wrappedSql)) {
            do {
                $resultSet = $db->store_result();
                if ($resultSet instanceof \mysqli_result) {
                    $resultSet->free();
                }
                if (!$db->more_results()) {
                    break;
                }
            } while ($db->next_result());

            if ($db->errno !== 0) {
                $error = 'MySQL error ' . $db->errno . ': ' . $db->error;
            }
        } else {
            $error = 'MySQL error ' . $db->errno . ': ' . $db->error;
        }
    } catch (\mysqli_sql_exception $databaseError) {
        $error = 'MySQL error ' . $databaseError->getCode() . ': ' . $databaseError->getMessage();

        // Drain anything left so the connection can be used again.
        try {
            while ($db->more_results() && $db->next_result()) {
                $leftover = $db->store_result();
                if ($leftover instanceof \mysqli_result) {
                    $leftover->free();
                }
            }
        } catch (\mysqli_sql_exception $drainError) {
            unset($drainError);
        }
    }

    if ($error !== '') {
        // ↩️ Undo whatever part of the file did run. A failure here is
        //    reported too, but MySQL also discards an uncommitted transaction
        //    when the connection closes, so nothing half-loaded can survive.
        try {
            if (!$db->rollback()) {
                $error = $error . ' (and the rollback failed: ' . $db->error . ')';
            }
        } catch (\mysqli_sql_exception $rollbackError) {
            $error = $error . ' (and the rollback failed: ' . $rollbackError->getMessage() . ')';
        }

        return ['ok' => false, 'error' => 'seed_data.sql failed and was rolled back: ' . $error, 'refusal' => ''];
    }

    return ['ok' => true, 'error' => '', 'refusal' => ''];
}

// ==============================================================================
// 🗄️ DATABASE functions (each takes a \mysqli; prepared statements only)
// ==============================================================================

/**
 * 🗄️ Prepare and run one statement, turning both kinds of MySQLi failure
 *    (a false return, or a mysqli_sql_exception when error reporting is on -
 *    the PHP 8.1+ default) into a plain result.
 *
 * @param \mysqli             $db     Connection
 * @param string              $sql    Static SQL with ? placeholders
 * @param string              $types  bind_param() types ('' for none)
 * @param array<int, mixed>   $params Values to bind
 * @return array{ok: bool, stmt: \mysqli_stmt|null, error: string}
 */
function migrationDbExecute(\mysqli $db, string $sql, string $types = '', array $params = []): array
{
    try {
        $statement = $db->prepare($sql);

        if ($statement === false) {
            return ['ok' => false, 'stmt' => null, 'error' => 'MySQL error ' . $db->errno . ': ' . $db->error];
        }

        if ($types !== '') {
            $statement->bind_param($types, ...$params);
        }

        if (!$statement->execute()) {
            $error = 'MySQL error ' . $statement->errno . ': ' . $statement->error;
            $statement->close();
            return ['ok' => false, 'stmt' => null, 'error' => $error];
        }

        return ['ok' => true, 'stmt' => $statement, 'error' => ''];
    } catch (\mysqli_sql_exception $databaseError) {
        return ['ok' => false, 'stmt' => null, 'error' => 'MySQL error ' . $databaseError->getCode() . ': ' . $databaseError->getMessage()];
    }
}

/**
 * 🗄️ Run a statement and return every row (associative arrays).
 *
 * @param \mysqli           $db     Connection
 * @param string            $sql    Static SQL with ? placeholders
 * @param string            $types  bind_param() types
 * @param array<int, mixed> $params Values to bind
 * @return array{ok: bool, rows: array<int, array<string, mixed>>, error: string}
 */
function migrationDbFetchAll(\mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $executed = migrationDbExecute($db, $sql, $types, $params);

    if ($executed['ok'] !== true) {
        return ['ok' => false, 'rows' => [], 'error' => $executed['error']];
    }

    $statement = $executed['stmt'];
    $rows      = [];

    try {
        $resultSet = $statement->get_result();
        if ($resultSet !== false) {
            while (true) {
                $row = $resultSet->fetch_assoc();
                if (!is_array($row)) {
                    break;
                }
                $rows[] = $row;
            }
            $resultSet->free();
        }
    } catch (\mysqli_sql_exception $databaseError) {
        $statement->close();
        return ['ok' => false, 'rows' => [], 'error' => 'MySQL error ' . $databaseError->getCode() . ': ' . $databaseError->getMessage()];
    }

    $statement->close();

    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/**
 * 🔒 The SQL EXPRESSION (a piece of SQL text, not a value) that builds the
 *    FULL GET_LOCK()/RELEASE_LOCK() name for whichever database is currently
 *    selected on the connection (review finding F6).
 *
 * MySQL named locks are limited to 64 characters, and are server-wide - the
 * same name used against two different databases on the same shared MySQL
 * server would be the SAME lock, which is not what is wanted here. So the
 * current database's name is folded in, in two parts:
 *   - up to the first 30 characters of DATABASE() itself, kept for a human
 *     reading a MySQL process list to see roughly which database it is;
 *   - an 8-character slice of MD5(DATABASE()) - the hash of the WHOLE
 *     database name, not just the truncated 30 characters - so two different
 *     database names that happen to share their first 30 characters still
 *     almost certainly end up with two different lock names rather than
 *     silently colliding into one.
 * 23 (the prefix) + 1 + 30 + 1 + 8 = 63 characters at most, safely under the
 * 64-character limit, and always the SAME length calculation regardless of
 * how long or short the real database name is.
 *
 * Used identically by migrationDbAcquireLock() and migrationDbReleaseLock()
 * (one shared function, so the two can never quietly drift apart and start
 * computing different names for the same database).
 *
 * @return string SQL text with exactly one `?` placeholder (the fixed prefix,
 *                CUERCODE_MIGRATION_LOCK_NAME - DATABASE() itself needs no
 *                placeholder, it is a MySQL built-in, never user input)
 */
function migrationLockNameSqlExpression(): string
{
    return 'CONCAT(?, \':\', SUBSTRING(DATABASE(), 1, 30), \':\', SUBSTRING(MD5(DATABASE()), 1, 8))';
}

/**
 * 🔒 Try to take the upgrade lock without waiting.
 *
 * @param \mysqli $db Connection
 * @return string 'got', 'busy' or 'error'
 */
function migrationDbAcquireLock(\mysqli $db): string
{
    $fetched = migrationDbFetchAll(
        $db,
        'SELECT GET_LOCK(' . migrationLockNameSqlExpression() . ', 0) AS gotLock',
        's',
        [CUERCODE_MIGRATION_LOCK_NAME]
    );

    if ($fetched['ok'] !== true || $fetched['rows'] === []) {
        return 'error';
    }

    $value = $fetched['rows'][0]['gotLock'];

    if ($value === null) {
        return 'error';
    }

    if ((int) $value === 1) {
        return 'got';
    }

    return 'busy';
}

/**
 * 🔓 Release the upgrade lock (a failure here is harmless: MySQL releases a
 *    named lock anyway when the connection closes).
 *
 * @param \mysqli $db Connection
 * @return void
 */
function migrationDbReleaseLock(\mysqli $db): void
{
    $fetched = migrationDbFetchAll(
        $db,
        'SELECT RELEASE_LOCK(' . migrationLockNameSqlExpression() . ') AS released',
        's',
        [CUERCODE_MIGRATION_LOCK_NAME]
    );
    unset($fetched);
}

/**
 * 🔒 Try to take the web installer's lock without waiting (Codex C1 finding 2).
 *
 * A different name from the upgrade lock, so the installer can hold this one
 * while migrationApplyPending() takes and releases the upgrade lock inside it
 * (MySQL 5.7+ and MariaDB 10.0.2+ allow one connection several named locks).
 *
 * @param \mysqli $db Connection with the site database selected
 * @return string 'got', 'busy' or 'error'
 */
function migrationDbAcquireInstallerLock(\mysqli $db): string
{
    $fetched = migrationDbFetchAll(
        $db,
        'SELECT GET_LOCK(' . migrationLockNameSqlExpression() . ', 0) AS `gotLock`',
        's',
        [CUERCODE_INSTALLER_LOCK_NAME]
    );

    if ($fetched['ok'] !== true || $fetched['rows'] === []) {
        return 'error';
    }

    $value = $fetched['rows'][0]['gotLock'];

    if ($value === null) {
        return 'error';
    }

    if ((int) $value === 1) {
        return 'got';
    }

    return 'busy';
}

/**
 * 🔓 Release the web installer's lock (harmless if it fails: MySQL releases
 *    a named lock when the connection closes).
 *
 * @param \mysqli $db Connection
 * @return void
 */
function migrationDbReleaseInstallerLock(\mysqli $db): void
{
    $fetched = migrationDbFetchAll(
        $db,
        'SELECT RELEASE_LOCK(' . migrationLockNameSqlExpression() . ') AS `released`',
        's',
        [CUERCODE_INSTALLER_LOCK_NAME]
    );
    unset($fetched);
}

/**
 * 🔎 The real "already done?" checker: asks INFORMATION_SCHEMA.
 *
 * @param \mysqli $db    Connection
 * @param array   $query From migrationStepExistsQuery()
 * @return bool True when the table/column/index/foreign key exists
 * @throws \RuntimeException When the question itself fails (the run then stops)
 */
function migrationDbExists(\mysqli $db, array $query): bool
{
    $fetched = migrationDbFetchAll($db, $query['sql'], $query['types'], $query['params']);

    if ($fetched['ok'] !== true || $fetched['rows'] === []) {
        throw new \RuntimeException('Could not check the database structure (' . $fetched['error'] . ')');
    }

    $firstRow = array_values($fetched['rows'][0]);

    return ((int) $firstRow[0]) > 0;
}

/**
 * 🗂️ Does tblSchemaMigrations exist yet?
 *
 * @param \mysqli $db Connection
 * @return bool
 */
function migrationDbLedgerExists(\mysqli $db): bool
{
    $ledgerStep = migrationLedgerStep();

    return migrationDbExists($db, migrationStepExistsQuery($ledgerStep));
}

/**
 * 📖 What tblSchemaMigrations says has been applied.
 *
 * @param \mysqli $db Connection
 * @return array{ok: bool, rows: array<string, array{key: string, checksum: string, appliedAt: string, appliedBy: string}>, error: string}
 */
function migrationDbReadApplied(\mysqli $db): array
{
    $fetched = migrationDbFetchAll(
        $db,
        'SELECT `migrationKey`, `checksum`, `appliedAt`, `appliedBy` FROM `tblSchemaMigrations` ORDER BY `migrationKey`'
    );

    if ($fetched['ok'] !== true) {
        return ['ok' => false, 'rows' => [], 'error' => $fetched['error']];
    }

    $rows = [];
    foreach ($fetched['rows'] as $row) {
        $key = (string) $row['migrationKey'];
        $rows[$key] = [
            'key'       => $key,
            'checksum'  => (string) $row['checksum'],
            'appliedAt' => (string) $row['appliedAt'],
            'appliedBy' => (string) $row['appliedBy'],
        ];
    }

    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/**
 * 📖 Every table and its columns in the current database.
 *
 * @param \mysqli $db Connection
 * @return array{ok: bool, tables: array<string, array<int, string>>, error: string}
 */
function migrationDbReadColumns(\mysqli $db): array
{
    $fetched = migrationDbFetchAll(
        $db,
        'SELECT TABLE_NAME AS tableName, COLUMN_NAME AS columnName FROM INFORMATION_SCHEMA.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );

    if ($fetched['ok'] !== true) {
        return ['ok' => false, 'tables' => [], 'error' => $fetched['error']];
    }

    $tables = [];
    foreach ($fetched['rows'] as $row) {
        $tables[(string) $row['tableName']][] = (string) $row['columnName'];
    }

    return ['ok' => true, 'tables' => $tables, 'error' => ''];
}

/**
 * 🧭 Is a database selected on this connection?
 *
 * @param \mysqli $db Connection
 * @return bool
 */
function migrationDbHasDatabase(\mysqli $db): bool
{
    $fetched = migrationDbFetchAll($db, 'SELECT DATABASE() AS currentDatabase');

    if ($fetched['ok'] !== true || $fetched['rows'] === []) {
        return false;
    }

    return $fetched['rows'][0]['currentDatabase'] !== null && (string) $fetched['rows'][0]['currentDatabase'] !== '';
}

/**
 * 📜 Re-run the whole of install.sql (only ever done when a starting table
 *    is missing). install.sql is only SET lines and CREATE TABLE IF NOT
 *    EXISTS since Phase 1A, so this never touches an existing table.
 *
 * install.sql switches the session's SQL mode to "NO_AUTO_VALUE_ON_ZERO",
 * which would quietly turn OFF strict mode for every migration step after
 * it. So the connection's SQL mode and time zone are saved first and put
 * back afterwards.
 *
 * install.sql (and seed_data.sql, run the same way by the web installer)
 * also contains a plain "SET CHARACTER SET utf8mb4" statement (review finding
 * F2). That MySQL statement does NOT behave like set_charset(): it sets
 * character_set_client and character_set_results to utf8mb4, but sets
 * character_set_connection (and its collation) to the CURRENT DATABASE's OWN
 * default character set - which, on a database whose default is something
 * older such as latin1 or utf8mb3, leaves the connection in a MISMATCHED
 * state (client says utf8mb4, connection says latin1) rather than simply "the
 * database default". Left like that, every later migration step on this
 * connection risks silently mangling multi-byte text (emoji, £, accented
 * names...) being written or read. mysqli's own set_charset() is the one safe
 * way to set all of those session variables back to the SAME, consistent
 * value in one go - so the character set is saved here exactly like the SQL
 * mode and time zone, and restored with set_charset(), in a finally block so
 * it happens on every path out of this function, including an exception.
 *
 * @param \mysqli $db         Connection
 * @param string  $installSql Text of install.sql
 * @return array{ok: bool, error: string}
 */
function migrationDbRunInstallSql(\mysqli $db, string $installSql): array
{
    $saved = migrationDbFetchAll($db, 'SELECT @@SESSION.sql_mode AS sqlMode, @@SESSION.time_zone AS timeZone');
    if ($saved['ok'] !== true || $saved['rows'] === []) {
        return ['ok' => false, 'error' => 'Could not read the connection settings (' . $saved['error'] . ')'];
    }
    $savedSqlMode  = (string) $saved['rows'][0]['sqlMode'];
    $savedTimeZone = (string) $saved['rows'][0]['timeZone'];

    // 🔤 mysqli's own idea of the connection's current character set, read
    //    BEFORE install.sql's "SET CHARACTER SET utf8mb4" statement runs.
    $savedCharset = $db->character_set_name();

    $error = '';

    try {
        try {
            // multi_query(): install.sql is many statements in one file - the
            // web installer runs it exactly the same way.
            if ($db->multi_query($installSql)) {
                do {
                    $resultSet = $db->store_result();
                    if ($resultSet instanceof \mysqli_result) {
                        $resultSet->free();
                    }
                    if (!$db->more_results()) {
                        break;
                    }
                } while ($db->next_result());

                if ($db->errno !== 0) {
                    $error = 'MySQL error ' . $db->errno . ': ' . $db->error;
                }
            } else {
                $error = 'MySQL error ' . $db->errno . ': ' . $db->error;
            }
        } catch (\mysqli_sql_exception $databaseError) {
            $error = 'MySQL error ' . $databaseError->getCode() . ': ' . $databaseError->getMessage();

            // Drain anything left so the connection can be used again.
            try {
                while ($db->more_results() && $db->next_result()) {
                    $leftover = $db->store_result();
                    if ($leftover instanceof \mysqli_result) {
                        $leftover->free();
                    }
                }
            } catch (\mysqli_sql_exception $drainError) {
                unset($drainError);
            }
        }
    } finally {
        // ↩️ Put the connection's settings back, whatever happened above -
        //    a finally block, so this always runs, even on an unexpected
        //    exception type this function does not otherwise catch.
        $restoredMode = migrationDbExecute($db, 'SET SESSION sql_mode = ?', 's', [$savedSqlMode]);
        if ($restoredMode['ok'] === true) {
            $restoredMode['stmt']->close();
        } elseif ($error === '') {
            $error = 'Could not restore the SQL mode (' . $restoredMode['error'] . ')';
        }

        $restoredZone = migrationDbExecute($db, 'SET SESSION time_zone = ?', 's', [$savedTimeZone]);
        if ($restoredZone['ok'] === true) {
            $restoredZone['stmt']->close();
        } elseif ($error === '') {
            $error = 'Could not restore the time zone (' . $restoredZone['error'] . ')';
        }

        // 🔤 Character set/collation restored LAST, and with set_charset() -
        //    not a SET query - because it is the only call that sets
        //    character_set_client, character_set_results, character_set_connection
        //    and collation_connection back to the SAME value consistently,
        //    undoing any mismatch install.sql's own SET CHARACTER SET left
        //    behind (F2). The @ is deliberate: a false return here is read
        //    from $db->error immediately after, never relied on silently.
        if (!@$db->set_charset($savedCharset)) {
            if ($error === '') {
                $error = 'Could not restore the character set (' . $db->error . ')';
            }
        }
    }

    if ($error !== '') {
        return ['ok' => false, 'error' => 'install.sql failed: ' . $error];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * ▶️ Run one step's SQL as a prepared statement with no parameters.
 *
 * MySQL accepts CREATE TABLE, ALTER TABLE, INSERT and UPDATE as prepared
 * statements, which keeps the house rule "prepared statements for all
 * queries". The SQL is a fixed text from a migration file, already checked.
 *
 * @param \mysqli $db  Connection
 * @param string  $sql Step SQL
 * @return array{ok: bool, error: string}
 */
function migrationDbRunStepSql(\mysqli $db, string $sql): array
{
    // A trailing ";" is allowed in files, but not inside a prepared statement.
    $sqlToRun = rtrim($sql);
    if (substr($sqlToRun, -1) === ';') {
        $sqlToRun = rtrim(substr($sqlToRun, 0, -1));
    }

    $executed = migrationDbExecute($db, $sqlToRun);

    if ($executed['ok'] !== true) {
        return ['ok' => false, 'error' => $executed['error']];
    }

    $executed['stmt']->close();

    return ['ok' => true, 'error' => ''];
}

/**
 * 🧾 Record a finished migration in tblSchemaMigrations.
 *
 * @param \mysqli $db           Connection
 * @param string  $key          Migration key
 * @param string  $checksum     SHA-256 of the file
 * @param string  $appliedBy    Safe label
 * @param int     $durationMs   Run time
 * @param int     $stepsRun     Steps that changed something
 * @param int     $stepsSkipped Steps already done
 * @return array{ok: bool, error: string}
 */
function migrationDbRecord(\mysqli $db, string $key, string $checksum, string $appliedBy, int $durationMs, int $stepsRun, int $stepsSkipped): array
{
    $executed = migrationDbExecute(
        $db,
        'INSERT INTO `tblSchemaMigrations` (`migrationKey`, `checksum`, `appliedBy`, `durationMs`, `stepsRun`, `stepsSkipped`)'
        . ' VALUES (?, ?, ?, ?, ?, ?)',
        'sssiii',
        [$key, $checksum, $appliedBy, $durationMs, $stepsRun, $stepsSkipped]
    );

    if ($executed['ok'] !== true) {
        return ['ok' => false, 'error' => $executed['error']];
    }

    $executed['stmt']->close();

    return ['ok' => true, 'error' => ''];
}

// ==============================================================================
// 🚀 The runner
// ==============================================================================

/**
 * 🧱 Baseline check (plan §5.1 step 3): compare the database with install.sql.
 *
 * @param \mysqli $db             Connection
 * @param string  $installSqlPath Path of install.sql
 * @param bool    $mayCreate      True on --apply: create missing tables by re-running install.sql
 * @return array{ok: bool, baseline: array{missingTables: array<int, string>, createdTables: array<int, string>, missingColumns: array<int, string>}, error: string}
 */
function migrationDbCheckBaseline(\mysqli $db, string $installSqlPath, bool $mayCreate): array
{
    $baseline = ['missingTables' => [], 'createdTables' => [], 'missingColumns' => []];

    $installSql = @file_get_contents($installSqlPath);
    if (!is_string($installSql) || $installSql === '') {
        return ['ok' => false, 'baseline' => $baseline, 'error' => 'install.sql could not be read'];
    }

    $expected = migrationParseInstallSqlSchema($installSql);
    if ($expected === []) {
        return ['ok' => false, 'baseline' => $baseline, 'error' => 'install.sql has no tables the runner can read'];
    }

    $columns = migrationDbReadColumns($db);
    if ($columns['ok'] !== true) {
        return ['ok' => false, 'baseline' => $baseline, 'error' => 'Could not read the database structure (' . $columns['error'] . ')'];
    }

    $comparison = migrationCompareBaseline($expected, $columns['tables']);

    if ($comparison['missingTables'] !== [] && $mayCreate) {
        $installRun = migrationDbRunInstallSql($db, $installSql);
        if ($installRun['ok'] !== true) {
            $baseline['missingTables'] = $comparison['missingTables'];
            return ['ok' => false, 'baseline' => $baseline, 'error' => $installRun['error']];
        }

        $createdFrom = $comparison['missingTables'];

        // Look again: the tables must now be there.
        $columns = migrationDbReadColumns($db);
        if ($columns['ok'] !== true) {
            return ['ok' => false, 'baseline' => $baseline, 'error' => 'Could not read the database structure (' . $columns['error'] . ')'];
        }
        $comparison = migrationCompareBaseline($expected, $columns['tables']);

        foreach ($createdFrom as $tableName) {
            if (!in_array($tableName, $comparison['missingTables'], true)) {
                $baseline['createdTables'][] = $tableName;
            }
        }

        if ($comparison['missingTables'] !== []) {
            $baseline['missingTables'] = $comparison['missingTables'];
            return ['ok' => false, 'baseline' => $baseline, 'error' => 'install.sql ran but some starting tables are still missing'];
        }
    }

    $baseline['missingTables']  = $comparison['missingTables'];
    $baseline['missingColumns'] = $comparison['missingColumns'];

    return ['ok' => true, 'baseline' => $baseline, 'error' => ''];
}

/**
 * 🚀 Shared engine behind --status, --dry-run and --apply.
 *
 * @param \mysqli $db             Connection (a database must be selected)
 * @param string  $mode           'status', 'dry-run' or 'apply'
 * @param string  $appliedBy      Who is running it ('installer', 'cli:<user>', 'admin:<id>')
 * @param string  $migrationsDir  Folder of migration files
 * @param string  $installSqlPath Path of install.sql
 * @return array{status: string, mode: string, pending: array<int, string>, applied: array<int, array>, migrations: array<int, array>, baseline: array, warnings: array<int, string>, error: string}
 */
function migrationRun(\mysqli $db, string $mode, string $appliedBy, string $migrationsDir, string $installSqlPath): array
{
    $result = [
        'status'     => 'failed',
        'mode'       => $mode,
        'pending'    => [],
        'applied'    => [],
        'migrations' => [],
        'baseline'   => ['missingTables' => [], 'createdTables' => [], 'missingColumns' => []],
        'warnings'   => [],
        'error'      => '',
    ];

    if ($mode !== 'status' && $mode !== 'dry-run' && $mode !== 'apply') {
        $result['error'] = 'unknown mode';
        return $result;
    }

    $isApply = false;
    if ($mode === 'apply') {
        $isApply = true;
    }

    // ⏱️ Long runs: lift the time limit where the host allows it. The tables
    //    are small today; this is a safety margin for the web installer.
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    try {
        if (!migrationDbHasDatabase($db)) {
            $result['error'] = 'No database is selected on this connection.';
            return $result;
        }
    } catch (\Throwable $connectionError) {
        $result['error'] = 'The database could not be reached (' . get_class($connectionError) . ').';
        return $result;
    }

    // 🔒 Step 1: the lock (--apply only; the other two change nothing).
    $haveLock = false;
    if ($isApply) {
        $lockResult = migrationDbAcquireLock($db);
        if ($lockResult === 'busy') {
            $result['status'] = 'busy';
            return $result;
        }
        if ($lockResult !== 'got') {
            $result['error'] = 'Could not take the upgrade lock.';
            return $result;
        }
        $haveLock = true;
    }

    try {
        $result = migrationRunLocked($db, $mode, $isApply, migrationSafeAppliedBy($appliedBy), $migrationsDir, $installSqlPath, $result);
    } catch (\Throwable $unexpected) {
        $result['status'] = 'failed';
        $result['error']  = 'Stopped by an unexpected error (' . get_class($unexpected) . ': ' . $unexpected->getMessage() . ')';
    } finally {
        // 🔓 Step 6: always release the lock.
        if ($haveLock) {
            migrationDbReleaseLock($db);
        }
    }

    return $result;
}

/**
 * 🚀 The part of migrationRun() that happens while the lock is held.
 *
 * @param \mysqli $db             Connection
 * @param string  $mode           'status', 'dry-run' or 'apply'
 * @param bool    $isApply        True for --apply
 * @param string  $appliedBy      Safe label
 * @param string  $migrationsDir  Folder of migration files
 * @param string  $installSqlPath Path of install.sql
 * @param array   $result         The result being built
 * @return array The finished result
 */
function migrationRunLocked(\mysqli $db, string $mode, bool $isApply, string $appliedBy, string $migrationsDir, string $installSqlPath, array $result): array
{
    // 📚 Read every migration file first (no database needed).
    $loaded = migrationLoadAll($migrationsDir);
    if ($loaded['errors'] !== []) {
        $result['status'] = 'invalid';
        $result['migrations'][] = ['key' => '(migrations folder)', 'outcome' => 'invalid', 'stepsRun' => 0, 'stepsSkipped' => 0, 'problems' => $loaded['errors']];
        return $result;
    }

    // 🗂️ Step 2: the runner's own table.
    $ledgerExists = migrationDbLedgerExists($db);
    if (!$ledgerExists && $isApply) {
        $ledgerStep = migrationLedgerStep();
        $created    = migrationDbRunStepSql($db, $ledgerStep['sql']);
        if ($created['ok'] !== true) {
            $result['error'] = 'Could not create tblSchemaMigrations (' . $created['error'] . ')';
            return $result;
        }
        $ledgerExists = true;
    }

    // 🧱 Step 3: baseline check.
    $baselineCheck = migrationDbCheckBaseline($db, $installSqlPath, $isApply);
    $result['baseline'] = $baselineCheck['baseline'];
    if ($baselineCheck['ok'] !== true) {
        $result['error'] = $baselineCheck['error'];
        return $result;
    }
    if ($baselineCheck['baseline']['missingColumns'] !== []) {
        // Older than the supported starting point: refuse, change nothing more.
        $result['status'] = 'baseline_refused';
        return $result;
    }

    // 📖 What has already run.
    $appliedRows = [];
    if ($ledgerExists) {
        $readApplied = migrationDbReadApplied($db);
        if ($readApplied['ok'] !== true) {
            $result['error'] = 'Could not read tblSchemaMigrations (' . $readApplied['error'] . ')';
            return $result;
        }
        $appliedRows = $readApplied['rows'];
    }

    $availableChecksums = [];
    foreach ($loaded['migrations'] as $key => $migration) {
        $availableChecksums[$key] = $migration['checksum'];
    }
    $appliedChecksums = [];
    foreach ($appliedRows as $key => $appliedRow) {
        $appliedChecksums[$key] = $appliedRow['checksum'];
    }

    $plan = migrationBuildPlan($availableChecksums, $appliedChecksums);
    $result['pending'] = $plan['pending'];
    $result['applied'] = array_values($appliedRows);

    // ⚠️ Step 5: warnings - never re-run a changed file.
    foreach ($plan['changed'] as $changedKey) {
        $result['warnings'][] = $changedKey . ' was changed after it was applied. It will not run again; put new work in a new numbered file.';
    }
    foreach ($plan['missingFiles'] as $missingKey) {
        $result['warnings'][] = $missingKey . ' is recorded as applied but its file is missing.';
    }
    foreach ($plan['outOfOrder'] as $outOfOrderKey) {
        $result['warnings'][] = $outOfOrderKey . ' is numbered lower than an upgrade that already ran; it will still be applied (every step re-checks).';
    }

    // ✅ Validate EVERY waiting migration before running ANY of them.
    $invalid = false;
    foreach ($plan['pending'] as $pendingKey) {
        $migration = $loaded['migrations'][$pendingKey];
        $problems  = [];

        if ($migration['loadError'] !== '') {
            $problems[] = 'the file ' . $migration['loadError'];
        } else {
            $problems = migrationValidateDefinition($migration['definition'], $pendingKey);
        }

        if ($problems !== []) {
            $invalid = true;
            $result['migrations'][] = ['key' => $pendingKey, 'outcome' => 'invalid', 'stepsRun' => 0, 'stepsSkipped' => 0, 'problems' => $problems];
        }
    }
    if ($invalid) {
        $result['status'] = 'invalid';
        return $result;
    }

    // 🔎 The real "already done?" checker for this connection.
    $existsChecker = static function (array $query) use ($db): bool {
        return migrationDbExists($db, $query);
    };

    // 📋 --status: list what is waiting and stop.
    if ($mode === 'status') {
        foreach ($plan['pending'] as $pendingKey) {
            $result['migrations'][] = ['key' => $pendingKey, 'outcome' => 'pending', 'stepsRun' => 0, 'stepsSkipped' => 0, 'problems' => []];
        }
        $result['status'] = 'ok';
        return $result;
    }

    // ▶️ Step 4: each waiting migration, in order.
    foreach ($plan['pending'] as $pendingKey) {
        $migration  = $loaded['migrations'][$pendingKey];
        $definition = $migration['definition'];
        $startedAt  = hrtime(true);
        $stepsRun   = 0;
        $stepsSkipped = 0;
        $position   = 0;

        foreach ($definition['steps'] as $step) {
            $position++;
            $decision = migrationDecideStep($step, $existsChecker);

            if ($decision === 'skip') {
                $stepsSkipped++;
                continue;
            }

            if (!$isApply) {
                // Dry run: count it, do not run it.
                $stepsRun++;
                continue;
            }

            $ran = migrationDbRunStepSql($db, $step['sql']);
            if ($ran['ok'] !== true) {
                // ❌ Stop. Do NOT record the migration: the next run resumes
                //    safely because every step re-checks.
                $result['migrations'][] = [
                    'key'          => $pendingKey,
                    'outcome'      => 'failed',
                    'stepsRun'     => $stepsRun,
                    'stepsSkipped' => $stepsSkipped,
                    'problems'     => ['step ' . $position . ' (' . migrationDescribeStep($step) . ') failed: ' . $ran['error']],
                ];
                $result['status'] = 'failed';
                $result['error']  = $pendingKey . ' step ' . $position . ' failed.';
                return $result;
            }
            $stepsRun++;
        }

        if (!$isApply) {
            $result['migrations'][] = ['key' => $pendingKey, 'outcome' => 'would_apply', 'stepsRun' => $stepsRun, 'stepsSkipped' => $stepsSkipped, 'problems' => []];
            continue;
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1000000);
        $recorded   = migrationDbRecord($db, $pendingKey, $migration['checksum'], $appliedBy, $durationMs, $stepsRun, $stepsSkipped);
        if ($recorded['ok'] !== true) {
            $result['migrations'][] = [
                'key'          => $pendingKey,
                'outcome'      => 'failed',
                'stepsRun'     => $stepsRun,
                'stepsSkipped' => $stepsSkipped,
                'problems'     => ['all steps finished but recording it failed: ' . $recorded['error']],
            ];
            $result['status'] = 'failed';
            $result['error']  = $pendingKey . ' could not be recorded.';
            return $result;
        }

        $result['migrations'][] = ['key' => $pendingKey, 'outcome' => 'applied', 'stepsRun' => $stepsRun, 'stepsSkipped' => $stepsSkipped, 'problems' => []];
    }

    $result['status'] = 'ok';

    return $result;
}

/**
 * 🏷️ Short description of a step for messages (no SQL, no values).
 *
 * @param array $step A valid step
 * @return string For example "add_column tblSubscriptions.pricePointID"
 */
function migrationDescribeStep(array $step): string
{
    $description = (string) $step['type'] . ' ' . (string) $step['table'];

    if (isset($step['name']) && is_string($step['name'])) {
        $description .= '.' . $step['name'];
    }

    if (isset($step['label']) && is_string($step['label'])) {
        $description .= ' (' . $step['label'] . ')';
    }

    return $description;
}

/**
 * 🚀 Apply (or, with $dryRun, preview) every waiting migration (plan §5.1).
 *
 * @param \mysqli $db        Connection with the site database selected
 * @param string  $appliedBy 'installer', 'cli:<os user>' or 'admin:<userID>'
 * @param bool    $dryRun    True = report only, change nothing
 * @return array See migrationRun()
 */
function migrationApplyPending(\mysqli $db, string $appliedBy, bool $dryRun): array
{
    $mode = 'apply';
    if ($dryRun) {
        $mode = 'dry-run';
    }

    return migrationRun($db, $mode, $appliedBy, migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
}

/**
 * 📋 Report applied and waiting migrations without changing anything.
 *
 * @param \mysqli $db Connection with the site database selected
 * @return array See migrationRun()
 */
function migrationStatus(\mysqli $db): array
{
    return migrationRun($db, 'status', 'status', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
}
