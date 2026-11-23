<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OPTIONAL real-database check for the database upgrade runner
 * ==============================================================================
 *
 * Path: tests/integration/migrations_real_mysql.php
 *
 * tests/test_migrations.php proves the upgrade runner's LOGIC (file parsing,
 * SQL-text validation, the "what is waiting" plan, exit codes, report text)
 * WITHOUT a database, using a stand-in "does this already exist?" checker.
 * This script proves the parts only a real MySQL/MariaDB server can: the real
 * INFORMATION_SCHEMA questions, the real named lock (GET_LOCK), a real
 * install.sql run twice, a real upgrade of a database built by the OLD
 * install.sql, and the real command-line tool (_includes/cli/migrate.php)
 * end to end - every acceptance bullet of task 7n Phase 1A, plan §9:
 *
 *   A. a fresh database: install.sql + seed_data.sql + migrations 0001 and
 *      0002 all succeed; install.sql runs a SECOND time with no error;
 *      tblSchemaMigrations lists both migrations; running the migrations a
 *      second time changes nothing ("already up to date"); the real
 *      command-line tool's exit codes (0/2/3) on a real database.
 *   B. a database built by the OLD install.sql (before task 7n Phase 1A moved
 *      the #70 columns out into migration 0001) upgrades cleanly: 0001 skips
 *      every step (the columns are already there), 0002 applies; the
 *      finished schema is byte-for-byte identical to the fresh path's, apart
 *      from the two brand-new tables.
 *   C. owner-edited setting values are never overwritten by a re-run.
 *   D. a database missing a baseline table (one of install.sql's own) gets it
 *      re-created from install.sql; a database missing a baseline COLUMN is
 *      refused (exit code 2) with the exact column named, and nothing runs.
 *   E. two upgrade runs at the same time: the second is refused ("busy"),
 *      nothing is half-applied; it succeeds once the lock is free.
 *   F. a failing step stops the whole migration, is NOT recorded (so the next
 *      run resumes and finishes it); a migration file edited after it was
 *      applied is reported as a warning and never re-run; a migration step
 *      that tries a destructive change (DROP) is refused before anything
 *      runs.
 *   G. migrationDbSeedDataAlreadyPresent() - the function the web installer
 *      (public_html/install/index.php) now calls before ever touching
 *      seed_data.sql - correctly reports "no data yet" on an empty or
 *      table-less database and "already seeded" once seed_data.sql has run.
 *
 * It is NOT run by tests/run.php (which only picks up tests/test_*.php at the
 * top level), because it needs a database. CI still syntax-checks it.
 *
 * How to run it (Docker example; see tests/README.md, "Optional real-database
 * check (database upgrades)" for the full write-up):
 *
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33113 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p113 CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/migrations_real_mysql.php
 *
 * ⚠️ IMPORTANT: unlike the email-queue check, CUERCODE_IT_DB_NAME here is a
 *    BASE name only - this script creates and drops several throwaway
 *    databases of its own (the base name with _a, _b, _f, _g, _h appended),
 *    because several acceptance bullets need independent databases (an "old"
 *    database, a "two runs at once" database, a "custom migration folder"
 *    database...). Every one of those derived names is re-checked against the
 *    same "cuercode_it_" safety rule before it is ever used.
 *
 * ⚠️ Scenario B needs the pre-task-7n install.sql, read straight out of git
 *    history (commit 76b1af7, the last commit before task 7n Phase 1A moved
 *    the #70 columns out of it) with `git show`. That commit must be reachable
 *    from wherever this script is run - an ordinary clone has it; a SHALLOW
 *    clone (`git clone --depth 1`) or a shallow CI checkout might not. If it
 *    is not reachable, this script refuses to run (exit 2) rather than skip
 *    silently.
 *
 * ⚠️ SAFETY - read before changing anything here:
 *   - It creates and DROPS whole databases. It refuses to run (exit 2) unless
 *     the base database name starts with "cuercode_it_", so it can never be
 *     pointed at a real site database by accident. Use a throwaway container
 *     and remove it afterwards.
 *   - It never reads _auth_keys/auth_creds.php: the connection details come
 *     only from the CUERCODE_IT_DB_* environment variables (DB_HOST/DB_PORT/
 *     DB_USER/DB_PASS/DB_NAME are defined directly, exactly as the real
 *     site does, before _includes/db_connect.php or _includes/cli/migrate.php
 *     is ever loaded).
 *   - It runs the REAL _includes/cli/migrate.php as a genuine child process
 *     (via this same file, re-invoked with an internal --run-migrate-cli=
 *     option) for the end-to-end exit-code checks, and the real runner
 *     functions (_functions/fn_migrations.php) in-process for the scenarios
 *     that need to inspect the detailed result array (stepsRun, warnings...).
 *
 * Exit codes: 0 every scenario passed | 1 a scenario failed or crashed |
 * 2 refused (unsafe database name, missing settings, git history too shallow).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-23 (task 7n Phase 1A part 2, #113 item 5, #70)
 * @see         tests/README.md - "Optional real-database check (database upgrades)"
 * @see         tests/test_migrations.php - the DB-independent unit tests
 * @see         web/CueRCode.net/_functions/fn_migrations.php - the runner
 * @see         web/CueRCode.net/_includes/cli/migrate.php - the command-line tool
 * @see         web/CueRCode.net/public_html/install/index.php - the installer caller
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1, §9 Phase 1A
 */

// ==============================================================================
// 🚫 Command line only - the first statement, as in the real runner
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

$itSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$itRepoRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

// ==============================================================================
// 👶 Self-invoked CHILD mode: runs the REAL _includes/cli/migrate.php against
//    the test server. The parent process (below) execs this same file with
//    --run-migrate-cli=<status|dry-run|apply> --db=<name>; this branch never
//    runs on its own, and always ends by handing control to migrate.php,
//    which calls exit() itself with the real exit code.
// ==============================================================================
$itChildArguments = array_slice($argv, 1);

if (isset($itChildArguments[0]) && str_starts_with($itChildArguments[0], '--run-migrate-cli=')) {
    $itChildMode   = substr($itChildArguments[0], strlen('--run-migrate-cli='));
    $itChildDbName = '';

    if (isset($itChildArguments[1]) && str_starts_with($itChildArguments[1], '--db=')) {
        $itChildDbName = substr($itChildArguments[1], strlen('--db='));
    }

    // 🛡️ The same safety rule as everywhere else in this file - repeated here
    //    because this branch is a separate process and must stand on its own.
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $itChildDbName) !== 1) {
        fwrite(STDERR, 'Refusing: --db must be a cuercode_it_ test database.' . PHP_EOL);
        exit(9);
    }

    $itChildPortValue = getenv('CUERCODE_IT_DB_PORT');
    if ($itChildPortValue === false || $itChildPortValue === '') {
        $itChildPortValue = '3306';
    }
    $itChildPassValue = getenv('CUERCODE_IT_DB_PASS');
    if ($itChildPassValue === false) {
        $itChildPassValue = '';
    }

    // 🗄️ Defined BEFORE migrate.php loads db_connect.php, exactly the way
    //    tests/integration/email_queue_real_mysql.php primes the real
    //    start-up code - db_connect.php only reads auth_creds.php when these
    //    are not already defined.
    define('DB_HOST', (string) getenv('CUERCODE_IT_DB_HOST'));
    define('DB_PORT', (int) $itChildPortValue);
    define('DB_USER', (string) getenv('CUERCODE_IT_DB_USER'));
    define('DB_PASS', $itChildPassValue);
    define('DB_NAME', $itChildDbName);

    // 📣 migrate.php reads $_SERVER['argv'] itself (not the global $argv), so
    //    it is rewritten here to look exactly like a real command line.
    $_SERVER['argv'] = ['migrate.php', '--' . $itChildMode];

    require $itSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'migrate.php';

    // migrate.php always exit()s itself - this line is unreachable, and is
    // only here so a future change to migrate.php that forgot to exit() is
    // still caught as a clear failure rather than falling through silently.
    exit(99);
}

// ==============================================================================
// 🛡️ Step 1: safety - only ever throwaway databases named cuercode_it_*
// ==============================================================================
$itEnv = [];

foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $itKey) {
    $itValue = getenv('CUERCODE_IT_DB_' . $itKey);

    if ($itValue === false) {
        $itValue = '';
    }

    $itEnv[$itKey] = $itValue;
}

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $itEnv['NAME']) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_" (this script creates and drops whole databases, so it only ever touches throwaway ones). It is used as a BASE name - see the file header.' . PHP_EOL);
    exit(2);
}

if ($itEnv['HOST'] === '' || $itEnv['USER'] === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER (and CUERCODE_IT_DB_PORT / CUERCODE_IT_DB_PASS if needed).' . PHP_EOL);
    exit(2);
}

if ($itEnv['PORT'] === '') {
    $itEnv['PORT'] = '3306';
}

$itPort     = (int) $itEnv['PORT'];
$itBaseName = $itEnv['NAME'];

// 🧰 The pure + database functions under test, and migrationDefault*() for the
//    repository's own install.sql / migrations folder paths. Loaded exactly
//    the way the web installer and migrate.php load it: no site start-up code.
require_once $itSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';

// ==============================================================================
// 🧰 Connection and schema helpers
// ==============================================================================

/**
 * 🔌 Connect to the test server without selecting a database (for CREATE/DROP
 *    DATABASE, and for the "two runs at once" lock-holder connection).
 */
function itServerConnect(array $itEnv, int $itPort): \mysqli
{
    return @new \mysqli($itEnv['HOST'], $itEnv['USER'], $itEnv['PASS'], '', $itPort);
}

/**
 * 🧮 Every derived database name used below goes through this one check -
 *    belt AND braces on top of the base-name check above.
 */
function itSafeDbName(string $itDbName): string
{
    if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $itDbName) !== 1) {
        fwrite(STDERR, 'Refusing: "' . $itDbName . '" is not a safe cuercode_it_ test database name.' . PHP_EOL);
        exit(9);
    }

    return $itDbName;
}

/**
 * 🧱 Drop (if present) and freshly create one throwaway database, connected
 *    and selected, with the same session settings the real site uses.
 */
function itFreshDb(array $itEnv, int $itPort, string $itDbName): \mysqli
{
    $itDbName = itSafeDbName($itDbName);
    $itServer = itServerConnect($itEnv, $itPort);
    $itServer->query('DROP DATABASE IF EXISTS `' . $itDbName . '`');
    $itServer->query('CREATE DATABASE `' . $itDbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $itServer->select_db($itDbName);
    $itServer->query("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $itServer->query("SET time_zone = '+00:00'");

    return $itServer;
}

/**
 * 🗑️ Drop one throwaway database (used during final clean-up).
 */
function itDropDb(array $itEnv, int $itPort, string $itDbName): void
{
    $itDbName = itSafeDbName($itDbName);
    $itServer = itServerConnect($itEnv, $itPort);
    $itServer->query('DROP DATABASE IF EXISTS `' . $itDbName . '`');
    $itServer->close();
}

/**
 * 📜 Run a trusted, multi-statement SQL file (install.sql / seed_data.sql / the
 *    pre-task-7n install.sql from git history) exactly the way the web
 *    installer does. Returns 'ok' or 'ERR <message>'.
 */
function itRunSqlFile(\mysqli $itDb, string $itSql): string
{
    try {
        $itDb->multi_query($itSql);

        do {
            $itStored = $itDb->store_result();

            if ($itStored instanceof \mysqli_result) {
                $itStored->free();
            }
        } while ($itDb->more_results() && $itDb->next_result());

        if ($itDb->errno !== 0) {
            return 'ERR ' . $itDb->error;
        }

        return 'ok';
    } catch (\Throwable $itError) {
        return 'ERR ' . $itError->getMessage();
    }
}

/**
 * 📸 A normalised snapshot of every table's definition (SHOW CREATE TABLE,
 *    with the AUTO_INCREMENT start value stripped out so two databases that
 *    received rows in a different order still compare equal). Used to prove
 *    "the fresh path and the old-database-upgrade path give byte-for-byte
 *    identical schemas" (plan §9).
 *
 * @return array<string, string> Table name => its normalised CREATE TABLE text
 */
function itSchemaSnapshot(\mysqli $itDb): array
{
    $itSnapshot = [];
    $itTables   = $itDb->query('SHOW TABLES');

    while ($itRow = $itTables->fetch_row()) {
        $itCreate = $itDb->query('SHOW CREATE TABLE `' . $itRow[0] . '`')->fetch_row()[1];
        $itSnapshot[$itRow[0]] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $itCreate);
    }

    ksort($itSnapshot);

    return $itSnapshot;
}

/**
 * 💻 Run the REAL _includes/cli/migrate.php, as a genuine separate process,
 *    against one test database, by re-invoking THIS file with its internal
 *    --run-migrate-cli= option (see the self-invoked CHILD mode above). The
 *    command is an array, so no shell is involved; the environment (including
 *    CUERCODE_IT_DB_*) is inherited by the child automatically.
 *
 * @return array{exit: int, out: string, err: string}
 */
function itCli(string $itDbName, string $itMode): array
{
    $itCommand = [PHP_BINARY, __FILE__, '--run-migrate-cli=' . $itMode, '--db=' . $itDbName];

    $itDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $itProcess     = proc_open($itCommand, $itDescriptors, $itPipes);

    if (!is_resource($itProcess)) {
        return ['exit' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }

    fclose($itPipes[0]);
    $itOut = (string) stream_get_contents($itPipes[1]);
    fclose($itPipes[1]);
    $itErr = (string) stream_get_contents($itPipes[2]);
    fclose($itPipes[2]);

    return ['exit' => proc_close($itProcess), 'out' => $itOut, 'err' => $itErr];
}

/**
 * 📝 Record one check for a scenario and print it.
 */
$itChecks = [];
function itCheck(string $itScenario, bool $itPassed, string $itWhat): void
{
    if (!isset($GLOBALS['itChecks'][$itScenario])) {
        $GLOBALS['itChecks'][$itScenario] = true;
    }

    if (!$itPassed) {
        $GLOBALS['itChecks'][$itScenario] = false;
    }

    if ($itPassed) {
        echo '    ok   ';
    } else {
        echo '    FAIL ';
    }

    echo $itWhat . PHP_EOL;
}

// ==============================================================================
// ⏳ Step 2: wait for the server (up to 120 seconds, as the email-queue check does)
// ==============================================================================
$itDeadline   = microtime(true) + 120;
$itConnection = null;
$itLastError  = '';

while ($itConnection === null && microtime(true) < $itDeadline) {
    try {
        $itConnection = itServerConnect($itEnv, $itPort);
    } catch (\Throwable $itError) {
        $itLastError = $itError->getMessage();
        usleep(500000);
    }
}

if ($itConnection === null) {
    fwrite(STDERR, 'Could not connect to the test database server within 120 seconds: ' . $itLastError . PHP_EOL);
    exit(1);
}

echo 'Connected to the test database server: ' . $itConnection->query('SELECT VERSION()')->fetch_row()[0] . PHP_EOL;
$itConnection->close();

// ==============================================================================
// 📜 Step 3: read the three SQL files - current install.sql, current
//    seed_data.sql, and the pre-task-7n install.sql from git history
// ==============================================================================
$itInstallSql = file_get_contents(migrationDefaultInstallSqlPath());
$itSeedSql    = file_get_contents(dirname(migrationDefaultInstallSqlPath()) . DIRECTORY_SEPARATOR . 'seed_data.sql');

if (!is_string($itInstallSql) || $itInstallSql === '' || !is_string($itSeedSql) || $itSeedSql === '') {
    fwrite(STDERR, 'Could not read install.sql / seed_data.sql.' . PHP_EOL);
    exit(2);
}

// 🕰️ The last commit before task 7n Phase 1A moved the #70 columns out of
//    install.sql (see the file header for why this must come from git
//    history, not a committed copy). `git -C` is used so this works however
//    this script itself is invoked (relative path, absolute path, symlink).
$itOldInstallDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$itOldInstallProcess     = proc_open(
    ['git', '-C', $itRepoRoot, 'show', '76b1af7:web/CueRCode.net/_includes/sql/install.sql'],
    $itOldInstallDescriptors,
    $itOldInstallPipes
);
$itOldInstallSql = '';
$itOldInstallErr = '';
$itOldInstallExit = 1;

if (is_resource($itOldInstallProcess)) {
    fclose($itOldInstallPipes[0]);
    $itOldInstallSql  = (string) stream_get_contents($itOldInstallPipes[1]);
    $itOldInstallErr  = (string) stream_get_contents($itOldInstallPipes[2]);
    fclose($itOldInstallPipes[1]);
    fclose($itOldInstallPipes[2]);
    $itOldInstallExit = proc_close($itOldInstallProcess);
}

if ($itOldInstallExit !== 0 || trim($itOldInstallSql) === '') {
    fwrite(STDERR, 'Refusing to run: could not read the pre-task-7n install.sql from git history (commit 76b1af7).' . PHP_EOL);
    fwrite(STDERR, 'This usually means the clone is shallow (git clone --depth 1) and does not reach that commit.' . PHP_EOL);
    fwrite(STDERR, 'git said: ' . trim($itOldInstallErr) . PHP_EOL);
    exit(2);
}

echo 'Read the pre-task-7n install.sql from git history (commit 76b1af7, ' . substr_count($itOldInstallSql, "\n") . ' lines).' . PHP_EOL;

// 🧮 Database names used below - all derived from the one base name, and all
//    re-checked by itSafeDbName() the moment they are used.
$itDbA = $itBaseName . '_a';
$itDbB = $itBaseName . '_b';
$itDbF = $itBaseName . '_f';
$itDbG = $itBaseName . '_g';
$itDbH = $itBaseName . '_h';

// ==============================================================================
// 🅰️ Scenario A: fresh database - install.sql + seed + migrations; install.sql
//    runs TWICE; a second apply changes nothing; the real CLI's exit codes
// ==============================================================================
echo PHP_EOL . 'Scenario A: fresh database (install.sql + seed_data.sql + migrations 0001/0002)' . PHP_EOL;
$itA = itFreshDb($itEnv, $itPort, $itDbA);
itCheck('A fresh database', itRunSqlFile($itA, $itInstallSql) === 'ok', 'install.sql runs');
itCheck('A fresh database', itRunSqlFile($itA, $itSeedSql) === 'ok', 'seed_data.sql runs');
itCheck('A fresh database', itRunSqlFile($itA, $itInstallSql) === 'ok', 'install.sql runs a SECOND time with no error (#113 item 5)');

$itCliResult = itCli($itDbA, 'status');
itCheck('A fresh database', $itCliResult['exit'] === 3, 'real CLI: --status exit 3 (migrations waiting)');

$itCliResult = itCli($itDbA, 'dry-run');
itCheck('A fresh database', $itCliResult['exit'] === 0, 'real CLI: --dry-run exit 0');
itCheck('A fresh database', $itA->query("SHOW TABLES LIKE 'tblSchemaMigrations'")->num_rows === 0, 'a dry run created nothing (no ledger table yet)');

$itCliResult = itCli($itDbA, 'apply');
itCheck('A fresh database', $itCliResult['exit'] === 0, 'real CLI: --apply exit 0');
itCheck('A fresh database', str_contains($itCliResult['out'], 'Applied 0001_subscription_price_snapshot_columns'), 'real CLI: output says 0001 applied');
itCheck('A fresh database', str_contains($itCliResult['out'], 'Applied 0002_admin_audit_log_and_settings'), 'real CLI: output says 0002 applied');

$itCliResult = itCli($itDbA, 'apply');
itCheck('A fresh database', $itCliResult['exit'] === 0, 'real CLI: a SECOND --apply exit 0');
itCheck('A fresh database', str_contains($itCliResult['out'], 'Already up to date'), 'real CLI: a second apply reports "already up to date" (nothing changes)');

$itCliResult = itCli($itDbA, 'status');
itCheck('A fresh database', $itCliResult['exit'] === 0, 'real CLI: --status exit 0 once up to date');

$itLedgerRows = $itA->query('SELECT `migrationKey`, `appliedBy` FROM `tblSchemaMigrations` ORDER BY `migrationKey`')->fetch_all(MYSQLI_ASSOC);
itCheck('A fresh database', count($itLedgerRows) === 2, 'tblSchemaMigrations lists both migrations (' . count($itLedgerRows) . ')');
itCheck('A fresh database', isset($itLedgerRows[0]['migrationKey']) && $itLedgerRows[0]['migrationKey'] === '0001_subscription_price_snapshot_columns', 'ledger: first row is 0001');
itCheck('A fresh database', isset($itLedgerRows[1]['migrationKey']) && $itLedgerRows[1]['migrationKey'] === '0002_admin_audit_log_and_settings', 'ledger: second row is 0002');
itCheck('A fresh database', str_starts_with((string) ($itLedgerRows[0]['appliedBy'] ?? ''), 'cli:'), 'ledger: appliedBy records the real CLI label (cli:<os user>)');
itCheck('A fresh database', $itA->query("SELECT 1 FROM `tblSettings` WHERE `settingKey` = 'billing_vat_mode' AND `settingValue` = 'inclusive'")->num_rows === 1, '0002 seeded billing_vat_mode = inclusive');

$itSchemaA = itSchemaSnapshot($itA);

// ==============================================================================
// 🅱️ Scenario B: a database built by the OLD install.sql upgrades cleanly -
//    0001 skips every step, 0002 applies; the finished schema matches A's
// ==============================================================================
echo PHP_EOL . 'Scenario B: a database built by the OLD (pre-task-7n) install.sql upgrades cleanly' . PHP_EOL;
$itB = itFreshDb($itEnv, $itPort, $itDbB);
itCheck('B old install.sql upgrades', itRunSqlFile($itB, $itOldInstallSql) === 'ok', 'the OLD install.sql runs');
itCheck('B old install.sql upgrades', itRunSqlFile($itB, $itSeedSql) === 'ok', 'seed_data.sql runs on top of it');

$itOldSchema = itSchemaSnapshot($itB);

$itUpgradeResult = migrationRun($itB, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('B old install.sql upgrades', $itUpgradeResult['status'] === 'ok', 'migrationRun(apply) succeeds on the old database');
itCheck(
    'B old install.sql upgrades',
    isset($itUpgradeResult['migrations'][0])
        && $itUpgradeResult['migrations'][0]['key'] === '0001_subscription_price_snapshot_columns'
        && $itUpgradeResult['migrations'][0]['stepsRun'] === 0
        && $itUpgradeResult['migrations'][0]['stepsSkipped'] === 9,
    '0001 skips every one of its 9 steps (the OLD install.sql already has those columns): ' . json_encode($itUpgradeResult['migrations'][0] ?? null)
);
itCheck(
    'B old install.sql upgrades',
    isset($itUpgradeResult['migrations'][1]) && $itUpgradeResult['migrations'][1]['key'] === '0002_admin_audit_log_and_settings' && $itUpgradeResult['migrations'][1]['outcome'] === 'applied',
    '0002 applies normally'
);

$itSchemaB = itSchemaSnapshot($itB);
itCheck('B old install.sql upgrades', $itSchemaA === $itSchemaB, 'the fresh path (A) and the old-database upgrade path (B) give byte-for-byte identical finished schemas');

$itSchemaAWithoutNewTables = $itSchemaA;
unset($itSchemaAWithoutNewTables['tblSchemaMigrations'], $itSchemaAWithoutNewTables['tblAdminAuditLog']);
itCheck('B old install.sql upgrades', $itSchemaAWithoutNewTables === $itOldSchema, 'apart from the two brand-new tables, the finished schema matches exactly what the OLD install.sql produced (nothing else moved or changed)');

// ==============================================================================
// 🅲 Scenario C: owner-edited setting values are never overwritten by a re-run
// ==============================================================================
echo PHP_EOL . 'Scenario C: owner-edited setting values survive a re-run' . PHP_EOL;
$itA->query("UPDATE `tblSettings` SET `settingValue` = 'exclusive' WHERE `settingKey` = 'billing_vat_mode'");
$itA->query("UPDATE `tblSettings` SET `description` = 'Owner wording' WHERE `settingKey` = 'bot_protection_provider'");
$itA->query("DELETE FROM `tblSchemaMigrations` WHERE `migrationKey` = '0002_admin_audit_log_and_settings'");

$itCliResult = itCli($itDbA, 'apply');
itCheck('C owner edits survive', $itCliResult['exit'] === 0, 're-applying 0002 (forced pending again) exits 0');
itCheck('C owner edits survive', $itA->query("SELECT 1 FROM `tblSettings` WHERE `settingKey` = 'billing_vat_mode' AND `settingValue` = 'exclusive'")->num_rows === 1, 'the owner-edited VAT mode value is kept, not reset to the seeded default');
itCheck('C owner edits survive', $itA->query("SELECT 1 FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider' AND `description` = 'Owner wording'")->num_rows === 1, 'the owner-edited description is kept');
itCheck('C owner edits survive', (int) $itA->query("SELECT COUNT(*) FROM `tblSettings` WHERE `settingKey` = 'paypal_mode'")->fetch_row()[0] === 1, 're-running 0002 did not duplicate any row');

// ==============================================================================
// 🅳 Scenario D: a missing baseline table is re-created; a missing baseline
//    column is refused (exit code 2), nothing changed
// ==============================================================================
echo PHP_EOL . 'Scenario D: missing baseline table recreated; missing baseline column refused' . PHP_EOL;
$itA->query('SET FOREIGN_KEY_CHECKS = 0');
$itA->query('DROP TABLE `tblQRScans`');
$itA->query('SET FOREIGN_KEY_CHECKS = 1');

$itCliResult = itCli($itDbA, 'status');
itCheck('D baseline table/column', $itCliResult['exit'] === 3, 'a missing baseline table shows as work waiting (exit 3)');

$itCliResult = itCli($itDbA, 'apply');
itCheck('D baseline table/column', $itCliResult['exit'] === 0, 'apply recreates the missing table (exit 0)');
itCheck('D baseline table/column', str_contains($itCliResult['out'], 'tblQRScans'), 'the output names the table it recreated');
itCheck('D baseline table/column', $itA->query("SHOW TABLES LIKE 'tblQRScans'")->num_rows === 1, 'tblQRScans exists again');

$itA->query('ALTER TABLE `tblUsers` DROP COLUMN `erasedAt`');

$itCliResult = itCli($itDbA, 'apply');
itCheck('D baseline table/column', $itCliResult['exit'] === 2, 'a missing baseline COLUMN is refused (exit 2)');
itCheck('D baseline table/column', str_contains($itCliResult['out'], 'tblUsers.erasedAt') || str_contains($itCliResult['err'], 'tblUsers.erasedAt'), 'the refusal names the exact missing column');

$itCliResult = itCli($itDbA, 'status');
itCheck('D baseline table/column', $itCliResult['exit'] === 2, '--status also refuses (exit 2) while a baseline column is missing');

// ==============================================================================
// 🅴 Scenario F: two upgrade runs at the same time - the second is refused
//    ("busy"), nothing is half-applied; it succeeds once the lock is free
// ==============================================================================
echo PHP_EOL . 'Scenario F: two upgrade runs at the same time (the named lock)' . PHP_EOL;
$itF = itFreshDb($itEnv, $itPort, $itDbF);
itRunSqlFile($itF, $itInstallSql);
itRunSqlFile($itF, $itSeedSql);

$itHolder     = new \mysqli($itEnv['HOST'], $itEnv['USER'], $itEnv['PASS'], $itDbF, $itPort);
$itGotLockRow = $itHolder->query("SELECT GET_LOCK('cuercode_schema_migrate', 0) AS gotLock")->fetch_assoc();
itCheck('F busy lock', (int) $itGotLockRow['gotLock'] === 1, 'a second connection takes the upgrade lock directly');

$itCliResult = itCli($itDbF, 'apply');
itCheck('F busy lock', $itCliResult['exit'] === 2, 'an upgrade run while the lock is held is refused (exit 2, "busy")');
itCheck('F busy lock', str_contains($itCliResult['out'], 'another upgrade is running') || str_contains($itCliResult['err'], 'another upgrade is running'), 'the refusal explains why, in plain English');
itCheck('F busy lock', $itF->query("SHOW TABLES LIKE 'tblSchemaMigrations'")->num_rows === 0, 'nothing was half-applied while busy (not even the ledger table)');

$itHolder->query("SELECT RELEASE_LOCK('cuercode_schema_migrate')");
$itHolder->close();

$itCliResult = itCli($itDbF, 'apply');
itCheck('F busy lock', $itCliResult['exit'] === 0, 'once the lock is free, the upgrade runs normally (exit 0)');

// ==============================================================================
// 🅶 Scenario G: a failing step stops the migration and is NOT recorded (so
//    the next run resumes and finishes it); an edited-after-applied file
//    warns and is never re-run; a destructive (DROP) step is refused
// ==============================================================================
echo PHP_EOL . 'Scenario G: failing step stops/resumes; edited-after-applied warns; DROP refused' . PHP_EOL;
$itG = itFreshDb($itEnv, $itPort, $itDbG);
itRunSqlFile($itG, $itInstallSql);
itRunSqlFile($itG, $itSeedSql);

$itTempMigrationsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_it_mig_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
mkdir($itTempMigrationsDir, 0700);

$itBadMigrationPath = $itTempMigrationsDir . '0001_test_failing_step.php';

// Step 1 is a harmless, valid column add; step 2 points at a table that does
// not exist, so it fails. Neither step touches anything from the real
// migrations folder - this custom folder is entirely self-contained.
file_put_contents(
    $itBadMigrationPath,
    "<?php\nreturn ['key' => '0001_test_failing_step', 'description' => 'test', 'steps' => ["
        . "['type' => 'add_column', 'table' => 'tblUsers', 'name' => 'itTestZzA', 'sql' => 'ALTER TABLE `tblUsers` ADD COLUMN `itTestZzA` INT NULL'],"
        . "['type' => 'add_column', 'table' => 'tblNoSuchTable', 'name' => 'itTestZzB', 'sql' => 'ALTER TABLE `tblNoSuchTable` ADD COLUMN `itTestZzB` INT NULL'],"
        . "]];\n"
);

$itResult = migrationRun($itG, 'apply', 'test', $itTempMigrationsDir, migrationDefaultInstallSqlPath());
itCheck('G failing step stops/resumes', $itResult['status'] === 'failed', 'the run stops with status failed: ' . json_encode($itResult['migrations'][0]['problems'] ?? $itResult['error']));
itCheck('G failing step stops/resumes', migrationExitCodeForResult('apply', $itResult) === 1, 'exit code 1 (a step failed)');
itCheck('G failing step stops/resumes', (int) $itG->query("SELECT COUNT(*) FROM `tblSchemaMigrations` WHERE `migrationKey` = '0001_test_failing_step'")->fetch_row()[0] === 0, 'the failed migration is NOT recorded');
itCheck('G failing step stops/resumes', $itG->query("SHOW COLUMNS FROM `tblUsers` LIKE 'itTestZzA'")->num_rows === 1, 'step 1 (before the failure) did take effect - the column exists');

// 🔁 Fix step 2 (point it at a real table) and run again: step 1 is skipped
//    (already done), step 2 now runs - this IS "resume and finish it".
file_put_contents(
    $itBadMigrationPath,
    "<?php\nreturn ['key' => '0001_test_failing_step', 'description' => 'test', 'steps' => ["
        . "['type' => 'add_column', 'table' => 'tblUsers', 'name' => 'itTestZzA', 'sql' => 'ALTER TABLE `tblUsers` ADD COLUMN `itTestZzA` INT NULL'],"
        . "['type' => 'add_column', 'table' => 'tblUsers', 'name' => 'itTestZzB', 'sql' => 'ALTER TABLE `tblUsers` ADD COLUMN `itTestZzB` INT NULL'],"
        . "]];\n"
);
$itResult = migrationRun($itG, 'apply', 'test', $itTempMigrationsDir, migrationDefaultInstallSqlPath());
itCheck(
    'G failing step stops/resumes',
    $itResult['status'] === 'ok'
        && isset($itResult['migrations'][0])
        && $itResult['migrations'][0]['stepsSkipped'] === 1
        && $itResult['migrations'][0]['stepsRun'] === 1,
    'resumed correctly: step 1 skipped (already done), step 2 now run: ' . json_encode($itResult['migrations'][0] ?? null)
);

// ⚠️ Edit the (now-applied) file's description only, so its checksum changes.
//    It must be reported as a warning and never run again.
file_put_contents(
    $itBadMigrationPath,
    "<?php\nreturn ['key' => '0001_test_failing_step', 'description' => 'test, now edited after being applied', 'steps' => ["
        . "['type' => 'add_column', 'table' => 'tblUsers', 'name' => 'itTestZzA', 'sql' => 'ALTER TABLE `tblUsers` ADD COLUMN `itTestZzA` INT NULL'],"
        . "['type' => 'add_column', 'table' => 'tblUsers', 'name' => 'itTestZzB', 'sql' => 'ALTER TABLE `tblUsers` ADD COLUMN `itTestZzB` INT NULL'],"
        . "]];\n"
);
$itResult = migrationRun($itG, 'status', 'test', $itTempMigrationsDir, migrationDefaultInstallSqlPath());
itCheck(
    'G failing step stops/resumes',
    count($itResult['warnings']) === 1 && str_contains($itResult['warnings'][0], 'changed after it was applied'),
    'editing an already-applied file is reported as a warning: ' . json_encode($itResult['warnings'])
);

// 🚫 A brand-new migration that tries a destructive change (DROP) is refused
//    before anything in it runs, even though its first clause (ADD COLUMN) is
//    harmless on its own.
file_put_contents(
    $itTempMigrationsDir . '0002_test_destructive.php',
    "<?php\nreturn ['key' => '0002_test_destructive', 'description' => 'test', 'steps' => ["
        . "['type' => 'add_column', 'table' => 'tblUsers', 'name' => 'itTestZzC', 'sql' => 'ALTER TABLE `tblUsers` ADD COLUMN `itTestZzC` INT NULL, DROP COLUMN `email`'],"
        . "]];\n"
);
$itResult = migrationRun($itG, 'apply', 'test', $itTempMigrationsDir, migrationDefaultInstallSqlPath());
itCheck('G failing step stops/resumes', $itResult['status'] === 'invalid', 'a migration containing DROP is refused outright (status invalid)');
itCheck('G failing step stops/resumes', $itG->query("SHOW COLUMNS FROM `tblUsers` LIKE 'itTestZzC'")->num_rows === 0, 'nothing in the refused migration ran - the column was never added');

// 🧹 Remove the temporary migrations folder.
foreach ((array) glob($itTempMigrationsDir . '*') as $itTempFile) {
    @unlink((string) $itTempFile);
}
@rmdir($itTempMigrationsDir);

// ==============================================================================
// 🅷 Scenario H: migrationDbSeedDataAlreadyPresent() - the function the web
//    installer calls before ever touching seed_data.sql
// ==============================================================================
echo PHP_EOL . 'Scenario H: migrationDbSeedDataAlreadyPresent() (the installer\'s seed-protection check)' . PHP_EOL;
$itH = itFreshDb($itEnv, $itPort, $itDbH);

$itSeedCheck = migrationDbSeedDataAlreadyPresent($itH);
itCheck('H seed protection', $itSeedCheck['ok'] === true && $itSeedCheck['present'] === false, 'an empty database (no tables yet) reports "not present", not an error');

itRunSqlFile($itH, $itInstallSql);
$itSeedCheck = migrationDbSeedDataAlreadyPresent($itH);
itCheck('H seed protection', $itSeedCheck['ok'] === true && $itSeedCheck['present'] === false, 'tables exist but are empty: still "not present"');

itRunSqlFile($itH, $itSeedSql);
$itSeedCheck = migrationDbSeedDataAlreadyPresent($itH);
itCheck('H seed protection', $itSeedCheck['ok'] === true && $itSeedCheck['present'] === true, 'once seed_data.sql has run, it correctly reports "already present"');

// ==============================================================================
// 🧹 Clean up and report
// ==============================================================================
foreach ([$itDbA, $itDbB, $itDbF, $itDbG, $itDbH] as $itDbToRemove) {
    itDropDb($itEnv, $itPort, $itDbToRemove);
}

echo PHP_EOL . 'Results:' . PHP_EOL;
$itAllPassed = true;

foreach ($itChecks as $itScenario => $itPassed) {
    if ($itPassed) {
        echo '  PASS  ' . $itScenario . PHP_EOL;
    } else {
        echo '  FAIL  ' . $itScenario . PHP_EOL;
        $itAllPassed = false;
    }
}

if ($itAllPassed) {
    exit(0);
}

exit(1);
