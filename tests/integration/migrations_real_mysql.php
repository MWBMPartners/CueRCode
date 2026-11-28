<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check for the database upgrade runner
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
 *   A. a fresh database: install.sql + seed_data.sql + migrations 0001,
 *      0002 and 0003 all succeed; install.sql runs a SECOND time with no error;
 *      tblSchemaMigrations lists every migration; running the migrations a
 *      second time changes nothing ("already up to date"); the real
 *      command-line tool's exit codes (0/2/3) on a real database.
 *   B. a database built by the OLD install.sql (before task 7n Phase 1A moved
 *      the #70 columns out into migration 0001) upgrades cleanly: 0001 skips
 *      every step (the columns are already there), 0002 and 0003 apply; the
 *      finished schema is byte-for-byte identical to the fresh path's, apart
 *      from the two brand-new tables and 0003's two new columns.
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
 * Three more scenarios, added for the Phase 1A part 2 code review fixes
 * (named here by their own code letters - Scenario I, J, K - to avoid
 * confusion with the lettering above, which has always run one letter ahead
 * in code: what this list calls E/F/G are "F busy lock"/"G failing step
 * stops/resumes"/"H seed protection" in the actual itCheck() scenario names):
 *
 *   Scenario I. review finding F2 - a database whose OWN default character
 *      set is latin1 does not end up with a MISMATCHED connection character
 *      set after install.sql's own "SET CHARACTER SET utf8mb4" statement
 *      runs; an emoji and a £ sign written by a migration step afterwards
 *      round-trip byte-for-byte.
 *   Scenario J. review finding F1 - running `migrate.php --apply` directly
 *      against a COMPLETELY EMPTY database (the exact bug: before the web
 *      installer has ever run) still leaves the installer's own
 *      migrationDbSeedDataAlreadyPresent() check correctly saying "NOT
 *      seeded" afterwards, even though migration 0002 has by then inserted
 *      rows into tblSettings.
 *   Scenario K. review finding F6 - the upgrade lock name includes the
 *      database, so two different databases on the same shared MySQL server
 *      never block each other's upgrades, while a database still correctly
 *      blocks a second run against ITSELF.
 *
 * Added 2026-11-25 (independent review, Codex C1 findings 1, 2 and 4):
 *
 *   Scenario J (extended). After `migrate.php --apply` on an empty database,
 *      the installer's seed load must SUCCEED, not just be allowed to run
 *      (migration 0002 adds some of the same settings as seed_data.sql).
 *   Scenario L. The starting data loads all-or-nothing (a file failing part-way
 *      leaves nothing behind, so a retry starts clean); a part-loaded database
 *      reads as "partial", not "already done"; a failed check is an error, not
 *      "no data"; and only one web installer can run per database at a time.
 *
 * Added 2026-11-25 (independent review, Codex round 2 findings R1-1 to R1-3):
 *
 *   Scenario M. "Complete" means every starting row (or seed_data.sql's
 *      completion marker), never "one row in each table"; with the marker,
 *      rows the owner later changed do not matter; the load is refused,
 *      writing nothing, when a table it writes to is not InnoDB, is missing,
 *      or is a view (and a reproduction shows why: MyISAM keeps its rows
 *      after a rollback).
 *   Scenarios A and J (extended). The bot-protection description ends up
 *      naming hCaptcha whichever runs first, the installer or the upgrade
 *      tool.
 *
 * Added 2026-11-26 (task 7n Phase 2, migration 0004 - plans, prices, currencies):
 *
 *   Scenario A and B (extended). 0004 applies on the fresh path and on the
 *      old-install.sql path; the fresh path has the Enterprise plan (a copy
 *      of Business), the five draft GBP price points, the two placeholder
 *      prices moved, the five new settings (pricing_show_prices = 1), and the
 *      three charging switches still 0.
 *   Scenario N. A database already at migration 0003 upgrades to 0004 and
 *      ends up identical (structure AND plan/price data) to a fresh install;
 *      on a second database at 0003 the owner's own changes - a changed
 *      placeholder price, their own price point, their own hand-made
 *      Enterprise plan, an existing setting - are all kept, and so are changes
 *      made after 0004 when 0004 is forced to run again.
 * Added 2026-11-27 (#163, migration 0005 - human check settings):
 *   Scenario A and J (extended). Both orders end with the provider setting
 *   "auto" (untouched "none" moved on), the new description and the two new
 *   settings. Scenario N (extended): 0004 and 0005 are both waiting at 0003.
 *   Scenario O. A database at 0004: an untouched "none" becomes "auto"; a
 *   "none" or "recaptcha" an admin or the command line chose (a row in the
 *   admin change log) is kept; an owner's own waiting time is kept; running
 *   0005 again changes nothing; a stored value or description that differs
 *   from the starting one only by capitals or a trailing space is kept byte
 *   for byte (added 2026-11-28, merged review of #163).
 *   Scenario P (#176, decision 0042). Upgrade 0006 on a database at 0005:
 *   every money column becomes DECIMAL(15,3), NOT NULL and defaults kept,
 *   every stored amount (the largest and smallest old values included) the
 *   same; KWD 1.234 and 999,999,999,999 stored exactly; run again it skips
 *   all six steps; stopped half-way it finishes the rest; a column it could
 *   only narrow, or whose "may be empty" was changed, is refused and left
 *   alone; the finished structure is the fresh install's.
 *
 *   Scenario J (extended). On the empty-database path 0004 leaves the plans
 *      and price-point tables EMPTY (review finding F1 still holds), and the
 *      installer's load (migrationDbRunSeedSql() with the migrations folder:
 *      the starting data plus the applied upgrades' data steps, in ONE
 *      transaction) then gives the same plans and prices as a fresh install.
 *      Added by finding F1 of the Phase 2 review (2026-11-27): while another
 *      upgrade holds the lock the load is refused with nothing written, and a
 *      repeated step that fails rolls the starting data back with it, so the
 *      retry the installer asks for loads everything again.
 *      Added by recheck finding 1 (2026-11-27, decision 0036): the
 *      installer's check migrationDbChangedAppliedUpgrades() reports an
 *      upgrade applied from an earlier version of its file (J, and the edited
 *      file in G), and nothing on a brand-new empty database; the upgrade
 *      tool's warning says to rebuild such a database (G, recheck finding 5).
 *
 * It is NOT run by tests/run.php (which only picks up tests/test_*.php at the
 * top level), because it needs a database. CI still syntax-checks it.
 *
 * How to run it (Docker example; see tests/README.md, "Real-database
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
 * ⚠️ Scenario B needs the pre-task-7n install.sql (commit 76b1af7, the last
 *    commit before task 7n Phase 1A moved the #70 columns out of it). Since
 *    the third-round review (Low 2) it is a committed copy,
 *    tests/integration/fixtures/install_76b1af7.sql, because after a squash
 *    or rebase merge that commit is no longer in the branch's history and
 *    `git show` failed every deploy. The script refuses to run (exit 2)
 *    unless the copy's SHA-256 is the pinned one and, wherever git history
 *    still holds commit 76b1af7, the copy is identical to that commit's file.
 *    Where history does not hold it, that second check is skipped and says
 *    so (the pinned hash still proves the file is unchanged).
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
 * 2 refused (unsafe database name, missing settings, the old install.sql
 * fixture missing, changed or different from git history).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-23 (task 7n Phase 1A part 2, #113 item 5, #70)
 * @see         tests/README.md - "Real-database check (database upgrades)"
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
 * 🔒 The EXACT lock name migrationDbAcquireLock()/migrationDbReleaseLock()
 *    would use for the database currently selected on $itDb (review finding
 *    F6) - built with the SAME SQL expression as production
 *    (migrationLockNameSqlExpression(), from fn_migrations.php), so this test
 *    can take/release that real lock directly without guessing at, or
 *    duplicating, the formula.
 */
function itLockNameFor(\mysqli $itDb): string
{
    $itLockNameStatement = $itDb->prepare('SELECT ' . migrationLockNameSqlExpression() . ' AS lockName');
    $itLockNamePrefix    = CUERCODE_MIGRATION_LOCK_NAME;
    $itLockNameStatement->bind_param('s', $itLockNamePrefix);
    $itLockNameStatement->execute();
    $itLockNameRow = $itLockNameStatement->get_result()->fetch_assoc();
    $itLockNameStatement->close();

    return (string) $itLockNameRow['lockName'];
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
 * 💷 The plan, price-point and setting data migration 0004 is about, without
 *    anything that legitimately differs between two databases (IDs, times),
 *    so two databases can be compared exactly (task 7n Phase 2).
 *
 * @return array{plans: array, pricePoints: array, settings: array}
 */
function itPhase2Data(\mysqli $itDb): array
{
    $itPlans = $itDb->query(
        'SELECT `planKey`, `planKind`, `ctaMode`, `contactUrl`, `planName`, `description`, `priceMonthly`, `priceYearly`, `currency`,'
        . ' `featuresJSON`, `limitsJSON`, `isActive`, `sortOrder`, `availableFrom`, `availableUntil`, `maxSales`, `basedOnPlanID`,'
        . ' `pricePromiseMode`, `pricePromiseMonths` FROM `tblSubscriptionPlans` ORDER BY `planKey`'
    )->fetch_all(MYSQLI_ASSOC);
    $itPricePoints = $itDb->query(
        'SELECT `p`.`planKey`, `pp`.`priceKey`, `pp`.`priceGroupKey`, `pp`.`billingInterval`, `pp`.`intervalCount`, `pp`.`amount`,'
        . ' `pp`.`currency`, `pp`.`isDefault`, `pp`.`isActive`, `pp`.`sortOrder`, `pp`.`grantsJSON`, `pp`.`validFrom`, `pp`.`validUntil`'
        . ' FROM `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID`'
        . ' ORDER BY `p`.`planKey`, `pp`.`priceKey`'
    )->fetch_all(MYSQLI_ASSOC);
    $itSettings = $itDb->query(
        "SELECT `settingKey`, `settingGroup`, `settingValue`, `settingType`, `isSensitive`, `isEditable` FROM `tblSettings`"
        . " WHERE `settingKey` IN ('billing_currencies', 'billing_default_currency', 'billing_price_promise_default_mode',"
        . " 'billing_price_promise_default_months', 'pricing_show_prices', 'billing_price_points_enabled', 'billing_discounts_enabled',"
        . " 'billing_payg_enabled') ORDER BY `settingKey`"
    )->fetch_all(MYSQLI_ASSOC);

    return ['plans' => $itPlans, 'pricePoints' => $itPricePoints, 'settings' => $itSettings];
}

/**
 * 🔢 One number from a query (COUNT(*) and the like).
 */
function itNumber(\mysqli $itDb, string $itSql): int
{
    return (int) $itDb->query($itSql)->fetch_row()[0];
}

/**
 * 🔤 One value from a query, as text ('' when there is no row).
 */
function itValue(\mysqli $itDb, string $itSql): string
{
    $itRow = $itDb->query($itSql)->fetch_row();

    if (!is_array($itRow) || $itRow[0] === null) {
        return '';
    }

    return (string) $itRow[0];
}

/**
 * ✂️ Take the six money columns upgrade 0006 widens (decision 0042) out of a
 *    schema snapshot, so a database before 0006 can be compared with one
 *    after it. Scenario P proves those six lines themselves.
 */
function itWithout0006Pieces(array $itSnapshot): array
{
    $itPieces = [
        'tblSubscriptionPlans' => ['priceMonthly', 'priceYearly'],
        'tblPlanPricePoints'   => ['amount'],
        'tblDiscounts'         => ['value'],
        'tblPayments'          => ['amount'],
        'tblOneOffPurchases'   => ['amount'],
    ];
    foreach ($itPieces as $itTable => $itNames) {
        if (!isset($itSnapshot[$itTable])) {
            continue;
        }
        foreach ($itNames as $itName) {
            $itSnapshot[$itTable] = (string) preg_replace('/^\s*`' . $itName . '` decimal\([^\n]*\n/m', '', $itSnapshot[$itTable]);
        }
        $itSnapshot[$itTable] = (string) preg_replace('/,\n/', "\n", $itSnapshot[$itTable]);
    }
    return $itSnapshot;
}

/**
 * ✂️ Take migration 0004's new columns, indexes and foreign key out of a
 *    schema snapshot, and drop line-end commas (removing the last line of a
 *    table would otherwise leave a comma behind), so it can be compared with
 *    a database that never had 0004.
 */
function itWithoutPhase2Pieces(array $itSnapshot): array
{
    $itPieces = [
        'tblSubscriptionPlans' => ['planKind', 'ctaMode', 'contactUrl', 'availableFrom', 'availableUntil', 'maxSales', 'basedOnPlanID',
            'pricePromiseMode', 'pricePromiseMonths', 'idx_plan_based_on', 'idx_plan_kind_active', 'fk_plan_based_on'],
        'tblPlanPricePoints'   => ['priceGroupKey', 'idx_pricepoint_plan_group'],
        'tblSubscriptions'     => ['pricePromiseMode', 'priceLockedUntil'],
    ];

    foreach ($itPieces as $itTable => $itNames) {
        if (!isset($itSnapshot[$itTable])) {
            continue;
        }
        foreach ($itNames as $itName) {
            $itSnapshot[$itTable] = (string) preg_replace('/^\s*(?:KEY |CONSTRAINT )?`' . $itName . '` [^\n]*\n/m', '', $itSnapshot[$itTable]);
        }
    }

    foreach ($itSnapshot as $itTable => $itCreate) {
        $itSnapshot[$itTable] = (string) preg_replace('/,\n/', "\n", $itCreate);
    }

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
    return itRunChild([PHP_BINARY, __FILE__, '--run-migrate-cli=' . $itMode, '--db=' . $itDbName]);
}

/**
 * 💷 Save one price group with the REAL admin editor on a database of this
 *    script (7n Phase 2 review, finding F5): runs
 *    tests/integration/admin_plans_real_mysql.php in its child mode, which
 *    starts the site against that database and calls adminPricesSaveGroup().
 *    The child works out the current price-list fingerprint itself.
 *
 * @param string               $itDbName A cuercode_it_ database
 * @param array<string, mixed> $itRequest userID, email, planID, group, post
 * @return array<string, mixed>|null The save's result, or null when the child failed
 */
function itSaveGroupWithEditor(string $itDbName, array $itRequest): ?array
{
    $itRequest['fp'] = '@current';
    $itChild = itRunChild([
        PHP_BINARY,
        __DIR__ . DIRECTORY_SEPARATOR . 'admin_plans_real_mysql.php',
        '--save-group',
        itSafeDbName($itDbName),
        base64_encode((string) json_encode($itRequest)),
    ]);
    $itMatch = [];
    if (preg_match('/@@RESULT@@(.+)$/m', $itChild['out'], $itMatch) !== 1) {
        echo '    (child said: ' . trim($itChild['out'] . ' ' . $itChild['err']) . ')' . PHP_EOL;
        return null;
    }
    $itDecoded = json_decode($itMatch[1], true);
    if (!is_array($itDecoded)) {
        return null;
    }
    return $itDecoded;
}

/**
 * 👶 Run a child PHP process and collect its output.
 *
 * @param array<int, string> $itCommand The command and its arguments
 * @return array{exit: int, out: string, err: string}
 */
function itRunChild(array $itCommand): array
{
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

// 🕰️ The install.sql of commit 76b1af7, the last commit before task 7n
//    Phase 1A moved the #70 columns out of it - a committed, byte-for-byte
//    copy (third-round review, Low 2; see the file header and
//    tests/integration/fixtures/README.md).
$itOldInstallPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'install_76b1af7.sql';
$itOldInstallSql  = '';
if (is_file($itOldInstallPath)) {
    $itOldInstallSql = (string) file_get_contents($itOldInstallPath);
}
if (hash('sha256', $itOldInstallSql) !== '531d98788dd0d4c8d0ddbab2c5dd30582fe060ac771dfd7edc990e6ab9990bfe') {
    fwrite(STDERR, 'Refusing to run: tests/integration/fixtures/install_76b1af7.sql is missing or has been changed (its SHA-256 is not the pinned one).' . PHP_EOL);
    exit(2);
}

// 🔎 Where git history still holds commit 76b1af7, the copy must be that
//    commit's file exactly. `git -C` is used so this works however this
//    script itself is invoked (relative path, absolute path, symlink).
$itOldInstallDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$itOldInstallProcess     = proc_open(
    ['git', '-C', $itRepoRoot, 'show', '76b1af7:web/CueRCode.net/_includes/sql/install.sql'],
    $itOldInstallDescriptors,
    $itOldInstallPipes
);
$itHistorySql  = '';
$itHistoryExit = 1;

if (is_resource($itOldInstallProcess)) {
    fclose($itOldInstallPipes[0]);
    $itHistorySql = (string) stream_get_contents($itOldInstallPipes[1]);
    stream_get_contents($itOldInstallPipes[2]);
    fclose($itOldInstallPipes[1]);
    fclose($itOldInstallPipes[2]);
    $itHistoryExit = proc_close($itOldInstallProcess);
}

if ($itHistoryExit === 0) {
    if ($itHistorySql !== $itOldInstallSql) {
        fwrite(STDERR, 'Refusing to run: tests/integration/fixtures/install_76b1af7.sql is not the same as install.sql at commit 76b1af7 in git history.' . PHP_EOL);
        exit(2);
    }
    echo 'Read the pre-task-7n install.sql from tests/integration/fixtures/install_76b1af7.sql (' . substr_count($itOldInstallSql, "\n") . ' lines; identical to commit 76b1af7 in git history).' . PHP_EOL;
} else {
    echo 'Read the pre-task-7n install.sql from tests/integration/fixtures/install_76b1af7.sql (' . substr_count($itOldInstallSql, "\n") . ' lines; pinned SHA-256 matches; commit 76b1af7 is not in this copy\'s git history, so the comparison with it was skipped).' . PHP_EOL;
}

// 🧮 Database names used below - all derived from the one base name, and all
//    re-checked by itSafeDbName() the moment they are used.
$itDbA  = $itBaseName . '_a';
$itDbB  = $itBaseName . '_b';
$itDbF  = $itBaseName . '_f';
$itDbG  = $itBaseName . '_g';
$itDbH  = $itBaseName . '_h';
$itDbI  = $itBaseName . '_i';
$itDbJ  = $itBaseName . '_j';
$itDbK1 = $itBaseName . '_k1';
$itDbK2 = $itBaseName . '_k2';
$itDbL  = $itBaseName . '_l';
$itDbL2 = $itBaseName . '_l2';
$itDbM  = $itBaseName . '_m';
$itDbN  = $itBaseName . '_n';
$itDbN2 = $itBaseName . '_n2';
$itDbO  = $itBaseName . '_o';
$itDbP  = $itBaseName . '_p';

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
itCheck('A fresh database', str_contains($itCliResult['out'], 'Applied 0003_sessions_valid_from'), 'real CLI: output says 0003 applied (review finding 1)');
itCheck('A fresh database', str_contains($itCliResult['out'], 'Applied 0004_plans_prices_currencies'), 'real CLI: output says 0004 applied (task 7n Phase 2)');
itCheck('A fresh database', str_contains($itCliResult['out'], 'Applied 0005_human_check_settings'), 'real CLI: output says 0005 applied (#163)');

$itCliResult = itCli($itDbA, 'apply');
itCheck('A fresh database', $itCliResult['exit'] === 0, 'real CLI: a SECOND --apply exit 0');
itCheck('A fresh database', str_contains($itCliResult['out'], 'Already up to date'), 'real CLI: a second apply reports "already up to date" (nothing changes)');

$itCliResult = itCli($itDbA, 'status');
itCheck('A fresh database', $itCliResult['exit'] === 0, 'real CLI: --status exit 0 once up to date');

$itLedgerRows = $itA->query('SELECT `migrationKey`, `appliedBy` FROM `tblSchemaMigrations` ORDER BY `migrationKey`')->fetch_all(MYSQLI_ASSOC);
itCheck('A fresh database', count($itLedgerRows) === 6, 'tblSchemaMigrations lists all six migrations (' . count($itLedgerRows) . ')');
itCheck('A fresh database', isset($itLedgerRows[0]['migrationKey']) && $itLedgerRows[0]['migrationKey'] === '0001_subscription_price_snapshot_columns', 'ledger: first row is 0001');
itCheck('A fresh database', isset($itLedgerRows[1]['migrationKey']) && $itLedgerRows[1]['migrationKey'] === '0002_admin_audit_log_and_settings', 'ledger: second row is 0002');
itCheck('A fresh database', isset($itLedgerRows[2]['migrationKey']) && $itLedgerRows[2]['migrationKey'] === '0003_sessions_valid_from', 'ledger: third row is 0003');
itCheck('A fresh database', isset($itLedgerRows[3]['migrationKey']) && $itLedgerRows[3]['migrationKey'] === '0004_plans_prices_currencies', 'ledger: fourth row is 0004');
itCheck('A fresh database', isset($itLedgerRows[4]['migrationKey']) && $itLedgerRows[4]['migrationKey'] === '0005_human_check_settings', 'ledger: fifth row is 0005 (#163)');
itCheck('A fresh database', isset($itLedgerRows[5]['migrationKey']) && $itLedgerRows[5]['migrationKey'] === '0006_three_decimal_money_columns', 'ledger: sixth row is 0006 (#176)');
// 📌 Only 0003's own two tables (0005's tblEmailSignInRequests has a column of the same name, #163).
$itNewColumns = $itA->query("SELECT `TABLE_NAME`, `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN ('tblUsers', 'tblUserSessions') AND `COLUMN_NAME` IN ('sessionsValidFrom', 'sessionsValidFromSeen') ORDER BY `COLUMN_NAME`")->fetch_all(MYSQLI_ASSOC);
itCheck('A fresh database', $itNewColumns === [
    ['TABLE_NAME' => 'tblUsers', 'COLUMN_NAME' => 'sessionsValidFrom', 'COLUMN_TYPE' => 'datetime(6)', 'IS_NULLABLE' => 'YES'],
    ['TABLE_NAME' => 'tblUserSessions', 'COLUMN_NAME' => 'sessionsValidFromSeen', 'COLUMN_TYPE' => 'datetime(6)', 'IS_NULLABLE' => 'YES'],
], '0003 added tblUsers.sessionsValidFrom and tblUserSessions.sessionsValidFromSeen as nullable datetime(6): ' . json_encode($itNewColumns));
// 🔤 No `??` (house rule) - an explicit isset() check instead.
$itLedgerAppliedByA = '';
if (isset($itLedgerRows[0]['appliedBy'])) {
    $itLedgerAppliedByA = (string) $itLedgerRows[0]['appliedBy'];
}
itCheck('A fresh database', str_starts_with($itLedgerAppliedByA, 'cli:'), 'ledger: appliedBy records the real CLI label (cli:<os user>)');
itCheck('A fresh database', $itA->query("SELECT 1 FROM `tblSettings` WHERE `settingKey` = 'billing_vat_mode' AND `settingValue` = 'inclusive'")->num_rows === 1, '0002 seeded billing_vat_mode = inclusive');
// 📝 Codex round 2 finding R1-3: installer order (seed first, then the upgrades).
//    #163: 0005 then moves that starting wording on to its own, and the
//    untouched "none" to "auto" (off until keys are entered).
itCheck('A fresh database', $itA->query("SELECT 1 FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider' AND `description` = 'Human check provider: auto (the first with both keys, in the order turnstile, recaptcha, hcaptcha), turnstile, recaptcha, hcaptcha, or none (off)'")->num_rows === 1, 'installer order (seed, then upgrades): the provider description is 0005\'s (it names hCaptcha, R1-3, and "auto")');
itCheck('A fresh database', itValue($itA, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'auto', '#163: installer order: the untouched provider "none" is now "auto"');
itCheck('A fresh database', itValue($itA, "SELECT GROUP_CONCAT(CONCAT(`settingKey`, '=', `settingValue`, '|', `settingType`, '|', `isSensitive`, '|', `isEditable`) ORDER BY `settingKey`) FROM `tblSettings` WHERE `settingKey` IN ('bot_protection_when_unreachable', 'bot_protection_timeout_seconds')") === 'bot_protection_timeout_seconds=5|integer|0|1,bot_protection_when_unreachable=signin_only|string|0|1', '#163: the two new human-check settings: only sign-in when every provider is down (decision 0037), wait 5 seconds');


// 💷 Task 7n Phase 2 (migration 0004) on the fresh path.
$itDataA = itPhase2Data($itA);
$itPlansA = [];
foreach ($itDataA['plans'] as $itPlanRow) {
    $itPlansA[$itPlanRow['planKey']] = $itPlanRow;
}
itCheck('A fresh database', array_keys($itPlansA) === ['anonymous', 'business', 'enterprise', 'free', 'pro'], '0004: five plans - the four starting ones plus Enterprise: ' . implode(', ', array_keys($itPlansA)));
itCheck(
    'A fresh database',
    isset($itPlansA['enterprise'])
        && [$itPlansA['enterprise']['planKind'], $itPlansA['enterprise']['ctaMode'], $itPlansA['enterprise']['contactUrl'], $itPlansA['enterprise']['planName'], $itPlansA['enterprise']['priceMonthly'], $itPlansA['enterprise']['priceYearly'], $itPlansA['enterprise']['currency'], (int) $itPlansA['enterprise']['isActive'], (int) $itPlansA['enterprise']['sortOrder']]
            === ['standard', 'contact', null, 'Enterprise', '99.000', '0.000', 'GBP', 1, 4],
    '0004: Enterprise is an ordinary plan with a Contact us button (address = site_email), from £99.00 a month, on sale, fifth on the grid'
);
itCheck('A fresh database', $itPlansA['enterprise']['featuresJSON'] === $itPlansA['business']['featuresJSON'] && $itPlansA['enterprise']['limitsJSON'] === $itPlansA['business']['limitsJSON'], '0004: Enterprise has exactly Business\'s features and limits');
itCheck('A fresh database', [$itPlansA['pro']['priceMonthly'], $itPlansA['pro']['priceYearly'], $itPlansA['business']['priceMonthly'], $itPlansA['business']['priceYearly']] === ['6.990', '49.990', '24.990', '199.990'], '0004: the placeholder monthly prices moved (Pro £6.99 / £49.99, Business £24.99 / £199.99)');
itCheck('A fresh database', [$itPlansA['free']['planKind'], $itPlansA['free']['ctaMode'], $itPlansA['free']['pricePromiseMode'], $itPlansA['free']['maxSales']] === ['standard', 'checkout', null, null], '0004: existing plans get the defaults (standard, checkout button, site-wide promise, no cap)');
$itPointsA = [];
foreach ($itDataA['pricePoints'] as $itPointRow) {
    $itPointsA[] = $itPointRow['planKey'] . ' ' . $itPointRow['priceKey'] . ' ' . $itPointRow['priceGroupKey'] . ' ' . $itPointRow['billingInterval'] . ' ' . $itPointRow['amount'] . ' ' . $itPointRow['currency'] . ' default=' . $itPointRow['isDefault'];
}
itCheck(
    'A fresh database',
    $itPointsA === [
        'business monthly_gbp monthly monthly 24.990 GBP default=0',
        'business yearly_gbp yearly yearly 199.990 GBP default=1',
        'enterprise monthly_gbp monthly monthly 99.000 GBP default=0',
        'pro monthly_gbp monthly monthly 6.990 GBP default=0',
        'pro yearly_gbp yearly yearly 49.990 GBP default=1',
    ],
    '0004: the five draft GBP price points, yearly ones recommended: ' . json_encode($itPointsA)
);
$itSettingsA = [];
foreach ($itDataA['settings'] as $itSettingRow) {
    $itSettingsA[$itSettingRow['settingKey']] = $itSettingRow['settingValue'];
}
itCheck(
    'A fresh database',
    $itSettingsA === [
        'billing_currencies' => 'GBP,USD,EUR',
        'billing_default_currency' => 'GBP',
        'billing_discounts_enabled' => '0',
        'billing_payg_enabled' => '0',
        'billing_price_points_enabled' => '0',
        'billing_price_promise_default_mode' => 'while_subscribed',
        'billing_price_promise_default_months' => '12',
        'pricing_show_prices' => '1',
    ],
    '0004: the five new settings (pricing_show_prices = 1, owner answer Q2 = A) and the three charging switches still 0: ' . json_encode($itSettingsA)
);
itCheck('A fresh database', migrationDbSeedDataState($itA)['state'] === 'complete', '0004 leaves the installer\'s starting-data check reading "complete"');

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
// 🔤 No `??` (house rule) - an explicit isset() check instead.
$itUpgradeFirstMigration = null;
if (isset($itUpgradeResult['migrations'][0])) {
    $itUpgradeFirstMigration = $itUpgradeResult['migrations'][0];
}
itCheck(
    'B old install.sql upgrades',
    isset($itUpgradeResult['migrations'][0])
        && $itUpgradeResult['migrations'][0]['key'] === '0001_subscription_price_snapshot_columns'
        && $itUpgradeResult['migrations'][0]['stepsRun'] === 0
        && $itUpgradeResult['migrations'][0]['stepsSkipped'] === 9,
    '0001 skips every one of its 9 steps (the OLD install.sql already has those columns): ' . json_encode($itUpgradeFirstMigration)
);
itCheck(
    'B old install.sql upgrades',
    isset($itUpgradeResult['migrations'][1]) && $itUpgradeResult['migrations'][1]['key'] === '0002_admin_audit_log_and_settings' && $itUpgradeResult['migrations'][1]['outcome'] === 'applied',
    '0002 applies normally'
);
itCheck(
    'B old install.sql upgrades',
    isset($itUpgradeResult['migrations'][2]) && $itUpgradeResult['migrations'][2]['key'] === '0003_sessions_valid_from' && $itUpgradeResult['migrations'][2]['outcome'] === 'applied',
    '0003 applies normally'
);
itCheck(
    'B old install.sql upgrades',
    isset($itUpgradeResult['migrations'][3]) && $itUpgradeResult['migrations'][3]['key'] === '0004_plans_prices_currencies' && $itUpgradeResult['migrations'][3]['outcome'] === 'applied',
    '0004 applies normally'
);
itCheck('B old install.sql upgrades', itPhase2Data($itB) === $itDataA, '0004 gives the old-install.sql database exactly the same plans, price points and settings as the fresh path');

$itSchemaB = itSchemaSnapshot($itB);
itCheck('B old install.sql upgrades', $itSchemaA === $itSchemaB, 'the fresh path (A) and the old-database upgrade path (B) give byte-for-byte identical finished schemas');

$itSchemaAWithoutNewTables = $itSchemaA;
unset($itSchemaAWithoutNewTables['tblSchemaMigrations'], $itSchemaAWithoutNewTables['tblAdminAuditLog']);
// 🤖 #163: 0005's four brand-new tables are taken out too.
unset($itSchemaAWithoutNewTables['tblHumanCheckTokens'], $itSchemaAWithoutNewTables['tblHumanCheckState'], $itSchemaAWithoutNewTables['tblNoScriptRequests'], $itSchemaAWithoutNewTables['tblEmailSignInRequests']);
// 🕐 0003's two new columns are taken out too (one line each in SHOW CREATE TABLE).
foreach (['tblUsers', 'tblUserSessions'] as $itTableWithNewColumn) {
    $itSchemaAWithoutNewTables[$itTableWithNewColumn] = (string) preg_replace('/^\s*`sessionsValidFrom(Seen)?` [^\n]*\n/m', '', $itSchemaAWithoutNewTables[$itTableWithNewColumn]);
}
// 💷 ...and 0004's columns, indexes and foreign key (task 7n Phase 2).
$itSchemaAWithoutNewTables = itWithoutPhase2Pieces($itSchemaAWithoutNewTables);
// 💷 ...and the six money columns 0006 widened (decision 0042).
itCheck('B old install.sql upgrades', itWithout0006Pieces($itSchemaAWithoutNewTables) === itWithout0006Pieces(itWithoutPhase2Pieces($itOldSchema)), 'apart from the brand-new tables (two from 0002, four from 0005), 0003\'s two new columns, 0004\'s new pieces and 0006\'s six widened money columns, the finished schema matches exactly what the OLD install.sql produced (nothing else moved or changed)');

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

$itHolder    = new \mysqli($itEnv['HOST'], $itEnv['USER'], $itEnv['PASS'], $itDbF, $itPort);
$itLockNameF = itLockNameFor($itHolder);
$itGotLockRow = $itHolder->query("SELECT GET_LOCK('" . $itHolder->real_escape_string($itLockNameF) . "', 0) AS gotLock")->fetch_assoc();
itCheck('F busy lock', (int) $itGotLockRow['gotLock'] === 1, 'a second connection takes the upgrade lock directly (using the exact per-database name production uses, F6)');

$itCliResult = itCli($itDbF, 'apply');
itCheck('F busy lock', $itCliResult['exit'] === 2, 'an upgrade run while the lock is held is refused (exit 2, "busy")');
itCheck('F busy lock', str_contains($itCliResult['out'], 'another upgrade is running') || str_contains($itCliResult['err'], 'another upgrade is running'), 'the refusal explains why, in plain English');
itCheck('F busy lock', $itF->query("SHOW TABLES LIKE 'tblSchemaMigrations'")->num_rows === 0, 'nothing was half-applied while busy (not even the ledger table)');

$itHolder->query("SELECT RELEASE_LOCK('" . $itHolder->real_escape_string($itLockNameF) . "')");
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
// 🔤 No `??` (house rule) - an explicit isset() check instead.
$itFailedMigrationDetail = $itResult['error'];
if (isset($itResult['migrations'][0]['problems'])) {
    $itFailedMigrationDetail = $itResult['migrations'][0]['problems'];
}
itCheck('G failing step stops/resumes', $itResult['status'] === 'failed', 'the run stops with status failed: ' . json_encode($itFailedMigrationDetail));
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
// 🔤 No `??` (house rule) - an explicit isset() check instead.
$itResumedFirstMigration = null;
if (isset($itResult['migrations'][0])) {
    $itResumedFirstMigration = $itResult['migrations'][0];
}
itCheck(
    'G failing step stops/resumes',
    $itResult['status'] === 'ok'
        && isset($itResult['migrations'][0])
        && $itResult['migrations'][0]['stepsSkipped'] === 1
        && $itResult['migrations'][0]['stepsRun'] === 1,
    'resumed correctly: step 1 skipped (already done), step 2 now run: ' . json_encode($itResumedFirstMigration)
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
itCheck(
    'G failing step stops/resumes',
    count($itResult['warnings']) === 1 && str_contains($itResult['warnings'][0], 'rebuild it from an empty database'),
    'recheck F5: ...and the warning says this database keeps the earlier version and how to bring it in line'
);
$itChangedG = migrationDbChangedAppliedUpgrades($itG, $itTempMigrationsDir);
itCheck('G failing step stops/resumes', $itChangedG['ok'] === true && count($itChangedG['changed']) === 1, 'recheck F1: the installer\'s check reports the same edited file: ' . json_encode($itChangedG));

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
// 🅸 Scenario I: a LATIN1-default database does not leave the connection in a
//    mismatched character-set state after install.sql's own "SET CHARACTER
//    SET utf8mb4" statement runs (review finding F2), and an emoji/£ written
//    by a migration step afterwards round-trips byte-for-byte
// ==============================================================================
echo PHP_EOL . 'Scenario I: a latin1-default database does not corrupt the connection charset (F2)' . PHP_EOL;
$itServerI = itServerConnect($itEnv, $itPort);
$itServerI->query('DROP DATABASE IF EXISTS `' . itSafeDbName($itDbI) . '`');
// 🔤 The database's OWN default is latin1 - this is exactly what makes
//    MySQL's "SET CHARACTER SET utf8mb4" (inside install.sql) behave
//    differently to set_charset('utf8mb4'): it sets character_set_connection
//    to THIS database's default (latin1), not to utf8mb4.
$itServerI->query('CREATE DATABASE `' . itSafeDbName($itDbI) . '` CHARACTER SET latin1 COLLATE latin1_swedish_ci');
$itServerI->select_db($itDbI);
$itServerI->query("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$itServerI->query("SET time_zone = '+00:00'");
// 🔤 Exactly what the real site's db_connect.php / installer does BEFORE
//    ever touching install.sql - utf8mb4, set consistently on all four
//    charset session variables via set_charset(), never a raw SET query.
$itServerI->set_charset('utf8mb4');
$itI = $itServerI;

$itOriginalCharsetI = $itI->character_set_name();
itCheck('I latin1 database charset', $itOriginalCharsetI === 'utf8mb4', 'the connection starts as utf8mb4 (matches the real site start-up), even though the database itself defaults to latin1');

$itInstallRunI = migrationDbRunInstallSql($itI, $itInstallSql);
itCheck('I latin1 database charset', $itInstallRunI['ok'] === true, 'install.sql runs on a latin1-default database: ' . $itInstallRunI['error']);
itCheck('I latin1 database charset', $itI->character_set_name() === $itOriginalCharsetI, 'the connection charset is restored to utf8mb4 after install.sql (not left mismatched by its own SET CHARACTER SET statement, F2)');

// 🌍 A migration step (its own throwaway, temporary migrations folder - never
//    the real one) writes an emoji and a £ sign; it must round-trip
//    byte-for-byte - proof the connection is genuinely consistent afterwards,
//    not just reporting the right charset NAME.
$itTempMigrationsDirI = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_it_mig_i_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
mkdir($itTempMigrationsDirI, 0700);
$itEmojiValue = 'Test GBP sign and emoji round-trip: £12.34 🎉';
file_put_contents(
    $itTempMigrationsDirI . '0001_test_charset_roundtrip.php',
    "<?php\nreturn ['key' => '0001_test_charset_roundtrip', 'description' => 'test', 'steps' => ["
        . "['type' => 'create_table', 'table' => 'tblItCharsetTest', 'sql' => 'CREATE TABLE IF NOT EXISTS `tblItCharsetTest` (`testID` INT UNSIGNED NOT NULL AUTO_INCREMENT, `testValue` VARCHAR(100) NOT NULL, PRIMARY KEY (`testID`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],"
        . "['type' => 'insert_if_missing', 'table' => 'tblItCharsetTest', 'sql' => \"INSERT INTO `tblItCharsetTest` (`testID`, `testValue`) VALUES (1, '" . $itEmojiValue . "') ON DUPLICATE KEY UPDATE `testID` = `testID`\"],"
        . "]];\n"
);

$itCharsetResult = migrationRun($itI, 'apply', 'test', $itTempMigrationsDirI, migrationDefaultInstallSqlPath());
itCheck('I latin1 database charset', $itCharsetResult['status'] === 'ok', 'the charset-round-trip migration applies: ' . json_encode($itCharsetResult['error']));

$itRoundTripRow = $itI->query('SELECT `testValue` FROM `tblItCharsetTest` WHERE `testID` = 1')->fetch_assoc();
itCheck(
    'I latin1 database charset',
    isset($itRoundTripRow['testValue']) && $itRoundTripRow['testValue'] === $itEmojiValue,
    'the £/emoji text written by a migration step round-trips byte-for-byte'
);

foreach ((array) glob($itTempMigrationsDirI . '*') as $itTempFile) {
    @unlink((string) $itTempFile);
}
@rmdir($itTempMigrationsDirI);

// ==============================================================================
// 🅹 Scenario J: `migrate.php --apply` run directly on a COMPLETELY EMPTY
//    database (before the web installer has ever touched it) must still
//    leave the installer's own seed-protection check correctly saying "NOT
//    seeded" afterwards (review finding F1 - this is the exact bug)
// ==============================================================================
echo PHP_EOL . 'Scenario J: CLI --apply on an EMPTY database, then the installer seed-check still says NOT seeded (F1)' . PHP_EOL;
$itJ = itFreshDb($itEnv, $itPort, $itDbJ);
// Nothing else is done to $itJ first - no install.sql, no seed_data.sql by
// hand - exactly "ran migrate.php --apply before the web installer ever ran".

$itCliResultJ = itCli($itDbJ, 'apply');
itCheck('J empty db then seed check', $itCliResultJ['exit'] === 0, 'CLI --apply on a completely empty database succeeds (it creates the starting tables from install.sql and applies migrations 0001/0002)');
itCheck(
    'J empty db then seed check',
    str_contains($itCliResultJ['out'], 'Starting data not loaded') || str_contains($itCliResultJ['err'], 'Starting data not loaded'),
    'the CLI prints a clear note that starting data (QR types, logos, plans) still needs the web installer'
);
itCheck('J empty db then seed check', (int) $itJ->query("SELECT COUNT(*) FROM `tblSettings`")->fetch_row()[0] > 0, 'migration 0002 DID insert rows into tblSettings (this is exactly why tblSettings cannot be a seed-protection table)');
// 💷 Task 7n Phase 2: 0004 writes to tblSubscriptionPlans, but only by copying
//    rows already there, so on an empty database it adds nothing (F1 holds).
itCheck('J empty db then seed check', itNumber($itJ, "SELECT COUNT(*) FROM `tblSchemaMigrations` WHERE `migrationKey` = '0004_plans_prices_currencies'") === 1, '0004 ran (and was recorded) on the empty database');
itCheck('J empty db then seed check', itNumber($itJ, 'SELECT COUNT(*) FROM `tblSubscriptionPlans`') === 0 && itNumber($itJ, 'SELECT COUNT(*) FROM `tblPlanPricePoints`') === 0, '0004 left the plans and price-point tables EMPTY on the empty database (review finding F1 still holds)');

$itSeedCheckJ = migrationDbSeedDataAlreadyPresent($itJ);
itCheck(
    'J empty db then seed check',
    $itSeedCheckJ['ok'] === true && $itSeedCheckJ['present'] === false,
    'the installer\'s seed-protection check correctly says "NOT seeded" (F1 fix) - so seed_data.sql would still be allowed to run if the web installer were used next'
);

// 🔁 Task 7n Phase 2, made all-or-nothing by review finding F1 of the Phase 2
//    review (2026-11-27). The installer loads the starting data AND repeats the
//    applied upgrades' data steps (0004 found no plans to copy or move) in ONE
//    transaction, under the upgrade lock. The bug this guards against: the
//    repeat failed ("busy") after the starting data had been committed, the
//    reload saw the data as complete, never repeated anything, and the
//    installer locked itself with no Enterprise plan and the old prices.
$itSettingsBeforeJ = itNumber($itJ, 'SELECT COUNT(*) FROM `tblSettings`');
$itModeBeforeJ     = itValue($itJ, 'SELECT @@SESSION.sql_mode');

// J-a. Another upgrade holds the lock: NOTHING is loaded, so a retry starts clean.
$itLockHolderJ = itServerConnect($itEnv, $itPort);
$itLockHolderJ->select_db(itSafeDbName($itDbJ));
$itLockNameJ = itLockNameFor($itLockHolderJ);
$itLockStatementJ = $itLockHolderJ->prepare('SELECT GET_LOCK(?, 0)');
$itLockStatementJ->bind_param('s', $itLockNameJ);
$itLockStatementJ->execute();
$itLockStatementJ->close();
$itSeedBusyJ = migrationDbRunSeedSql($itJ, $itSeedSql, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itSeedBusyJ['ok'] === false && $itSeedBusyJ['refusal'] !== '' && str_contains($itSeedBusyJ['refusal'], 'nothing was changed') && $itSeedBusyJ['stepsRun'] === 0, 'F1: while an upgrade holds the lock, the load is REFUSED before anything is written: ' . $itSeedBusyJ['refusal']);
itCheck('J empty db then seed check', migrationDbSeedDataState($itJ)['state'] === 'none' && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSubscriptionPlans`') === 0 && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSettings`') === $itSettingsBeforeJ, 'F1: ...so the starting data still reads "none" (no plans, no marker, settings unchanged) and a reload loads it');
$itLockHolderJ->close();

// J-b. A repeated step FAILS part-way: the starting data is rolled back with it.
//      (A trigger refuses any new price point - the 0004 price steps then fail.)
$itJ->query("CREATE TRIGGER `trg_it_j_refuse_price` BEFORE INSERT ON `tblPlanPricePoints` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration test: refused'");
$itSeedFailJ = migrationDbRunSeedSql($itJ, $itSeedSql, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itSeedFailJ['ok'] === false && $itSeedFailJ['refusal'] === '' && str_contains($itSeedFailJ['error'], 'rolled back') && str_contains($itSeedFailJ['error'], '0004_plans_prices_currencies'), 'F1: a repeated 0004 step that fails makes the whole load fail: ' . substr($itSeedFailJ['error'], 0, 160));
itCheck('J empty db then seed check', migrationDbSeedDataState($itJ)['state'] === 'none' && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSubscriptionPlans`') === 0 && itNumber($itJ, 'SELECT COUNT(*) FROM `tblQRCodeTypes`') === 0 && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSettings`') === $itSettingsBeforeJ, 'F1: ...and NOTHING of the starting data was kept (no plans, no QR types, no marker), so the reload the page asks for loads it all again');
$itJ->query('DROP TRIGGER `trg_it_j_refuse_price`');

// J-b2. Codex finding 2.P1: an upgrade recorded as applied whose FILE IS GONE
//       (simulated with a ledger row for a file that does not exist). Before
//       the fix the starting data loaded without that upgrade's data steps,
//       with only a warning, and the installer could lock itself. Now both
//       the installer's first check and the load itself refuse, and nothing
//       is written.
$itJ->query("INSERT INTO `tblSchemaMigrations` (`migrationKey`, `checksum`, `appliedBy`) VALUES ('0099_it_file_gone', REPEAT('a', 64), 'test')");
$itUnusableJ = migrationDbChangedAppliedUpgrades($itJ, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itUnusableJ['ok'] === true && $itUnusableJ['changed'] === [] && $itUnusableJ['unusable'] === ['0099_it_file_gone'], 'finding 2.P1: the installer\'s first check reports the applied upgrade whose file is missing: ' . json_encode($itUnusableJ));
$itSeedGoneJ = migrationDbRunSeedSql($itJ, $itSeedSql, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itSeedGoneJ['ok'] === false && str_contains($itSeedGoneJ['refusal'], 'nothing was changed') && str_contains($itSeedGoneJ['refusal'], '0099_it_file_gone') && $itSeedGoneJ['stepsRun'] === 0, 'finding 2.P1: the starting-data load is REFUSED: ' . $itSeedGoneJ['refusal']);
itCheck('J empty db then seed check', migrationDbSeedDataState($itJ)['state'] === 'none' && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSubscriptionPlans`') === 0 && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSettings`') === $itSettingsBeforeJ, 'finding 2.P1: ...and nothing was written (no plans, no marker, settings unchanged)');
$itJ->query("DELETE FROM `tblSchemaMigrations` WHERE `migrationKey` = '0099_it_file_gone'");

// J-c. The retry: everything lands together, identical to a fresh install.
//      ("Allowed to run" is not enough either - migration 0002 inserts some of
//      the same settings as seed_data.sql, and the load must still SUCCEED.)
$itSeedAfterCliJ = migrationDbRunSeedSql($itJ, $itSeedSql, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itSeedAfterCliJ['ok'] === true && $itSeedAfterCliJ['skipped'] === [] && in_array('0004_plans_prices_currencies', $itSeedAfterCliJ['migrations'], true) && in_array('0002_admin_audit_log_and_settings', $itSeedAfterCliJ['migrations'], true) && $itSeedAfterCliJ['stepsRun'] > 0, 'the retried load SUCCEEDS, with the applied upgrades\' data steps repeated (0002 and 0004 included, nothing skipped): ' . json_encode(['error' => $itSeedAfterCliJ['error'], 'stepsRun' => $itSeedAfterCliJ['stepsRun'], 'migrations' => $itSeedAfterCliJ['migrations']]));
itCheck('J empty db then seed check', migrationDbSeedDataState($itJ)['state'] === 'complete', 'and the starting data is then complete');
itCheck('J empty db then seed check', itPhase2Data($itJ) === $itDataA && itNumber($itJ, 'SELECT COUNT(*) FROM `tblSettings`') === itNumber($itA, 'SELECT COUNT(*) FROM `tblSettings`'), 'the plans, price points and settings are EXACTLY those of a fresh install (scenario A), with the same settings count');
itCheck('J empty db then seed check', itNumber($itJ, "SELECT COUNT(*) FROM `tblSubscriptionPlans` WHERE `planKey` = 'enterprise'") === 1, 'the Enterprise plan exists (the repeat ran)');
itCheck('J empty db then seed check', itValue($itJ, 'SELECT @@SESSION.sql_mode') === $itModeBeforeJ, 'the connection\'s own SQL mode is back after the load (nothing seed_data.sql sets for itself leaks into the repeated steps or what follows): ' . $itModeBeforeJ);
// 📝 Codex round 2 finding R1-3: upgrade-tool order (0002 ran before the row
//    existed, then the seed). Before the fix this kept the OLD wording for ever.
itCheck('J empty db then seed check', $itJ->query("SELECT 1 FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider' AND `description` = 'Human check provider: auto (the first with both keys, in the order turnstile, recaptcha, hcaptcha), turnstile, recaptcha, hcaptcha, or none (off)'")->num_rows === 1, 'upgrade-tool order (upgrades, then seed): the provider description is ALSO 0005\'s (R1-3, #163)');
itCheck('J empty db then seed check', itValue($itJ, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'auto', '#163: upgrade-tool order: the repeated 0005 step also moves the seeded "none" to "auto"');
$itCliResultJ2 = itCli($itDbJ, 'status');
itCheck('J empty db then seed check', $itCliResultJ2['exit'] === 0 && !str_contains($itCliResultJ2['out'] . $itCliResultJ2['err'], 'changed after it was applied'), 'and the upgrade tool reports up to date with no "changed after it was applied" warning (migration 0002 was not edited)');

// 🛑 Recheck finding 1 (decision 0036): an upgrade applied from an EARLIER
//    version of its file. The installer asks migrationDbChangedAppliedUpgrades()
//    before changing anything and stops, without locking itself, when the list
//    is not empty. Simulated by changing the recorded fingerprint of 0004, which
//    is exactly what a database that applied the 26 November 0004 looks like.
$itChangedNoneJ = migrationDbChangedAppliedUpgrades($itJ, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itChangedNoneJ['ok'] === true && $itChangedNoneJ['changed'] === [], 'recheck F1: with every upgrade file as applied, nothing is reported changed');
$itRealChecksumJ = itValue($itJ, "SELECT `checksum` FROM `tblSchemaMigrations` WHERE `migrationKey` = '0004_plans_prices_currencies'");
$itJ->query("UPDATE `tblSchemaMigrations` SET `checksum` = REPEAT('0', 64) WHERE `migrationKey` = '0004_plans_prices_currencies'");
$itChangedJ = migrationDbChangedAppliedUpgrades($itJ, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itChangedJ['ok'] === true && $itChangedJ['changed'] === ['0004_plans_prices_currencies'], 'recheck F1: an upgrade applied from an earlier version of its file is reported: ' . json_encode($itChangedJ));
$itRestoreChecksumJ = $itJ->prepare("UPDATE `tblSchemaMigrations` SET `checksum` = ? WHERE `migrationKey` = '0004_plans_prices_currencies'");
$itRestoreChecksumJ->bind_param('s', $itRealChecksumJ);
$itRestoreChecksumJ->execute();
$itRestoreChecksumJ->close();
$itChangedEmptyDb = itFreshDb($itEnv, $itPort, $itDbJ . '_c');
$itChangedFreshJ = migrationDbChangedAppliedUpgrades($itChangedEmptyDb, migrationDefaultDirectory());
itCheck('J empty db then seed check', $itChangedFreshJ['ok'] === true && $itChangedFreshJ['changed'] === [], 'recheck F1: a brand-new empty database (no upgrade record yet) has nothing changed, so a normal install goes ahead');
$itChangedEmptyDb->close();
itDropDb($itEnv, $itPort, $itDbJ . '_c');

// ==============================================================================
// 🅽 Scenario N (task 7n Phase 2): a database already at migration 0003
//    upgrades to 0004 - identical to a fresh install, and never overwriting
//    the owner's changes
// ==============================================================================
echo PHP_EOL . 'Scenario N: a database at migration 0003 upgrades to 0004; owner changes are kept' . PHP_EOL;

// 📁 A folder holding ONLY 0001-0003 (exact copies, so their fingerprints match
//    the real files and the real folder later sees them as already applied).
$itDir0003 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_it_mig0003_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
mkdir($itDir0003, 0700);
foreach (['0001_subscription_price_snapshot_columns', '0002_admin_audit_log_and_settings', '0003_sessions_valid_from'] as $itKey0003) {
    copy(migrationDefaultDirectory() . $itKey0003 . '.php', $itDir0003 . $itKey0003 . '.php');
}

// N1. No owner changes: the result must equal the fresh path exactly.
$itN = itFreshDb($itEnv, $itPort, $itDbN);
itRunSqlFile($itN, $itInstallSql);
itRunSqlFile($itN, $itSeedSql);
$itTo0003 = migrationRun($itN, 'apply', 'test', $itDir0003, migrationDefaultInstallSqlPath());
itCheck('N upgrade from 0003', $itTo0003['status'] === 'ok' && count($itTo0003['migrations']) === 3, 'setup: the database is brought to migration 0003 (three applied)');
itCheck('N upgrade from 0003', $itN->query("SHOW COLUMNS FROM `tblSubscriptionPlans` LIKE 'planKind'")->num_rows === 0 && itValue($itN, "SELECT `priceMonthly` FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'") === '4.99', 'setup: at 0003 there is no planKind column and Pro is at the placeholder £4.99');
$itStatusN = migrationRun($itN, 'status', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('N upgrade from 0003', $itStatusN['pending'] === ['0004_plans_prices_currencies', '0005_human_check_settings', '0006_three_decimal_money_columns'] && migrationExitCodeForResult('status', $itStatusN) === 3, 'the status check shows exactly 0004, 0005 and 0006 waiting (exit code 3)');
$itUpN = migrationRun($itN, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck(
    'N upgrade from 0003',
    $itUpN['status'] === 'ok' && count($itUpN['migrations']) === 3 && $itUpN['migrations'][0]['key'] === '0004_plans_prices_currencies'
        && $itUpN['migrations'][0]['outcome'] === 'applied' && $itUpN['migrations'][0]['stepsRun'] === 31 && $itUpN['migrations'][0]['stepsSkipped'] === 0
        && $itUpN['migrations'][1]['key'] === '0005_human_check_settings' && $itUpN['migrations'][1]['outcome'] === 'applied' && $itUpN['migrations'][1]['stepsRun'] === 12
        && $itUpN['migrations'][2]['key'] === '0006_three_decimal_money_columns' && $itUpN['migrations'][2]['outcome'] === 'applied' && $itUpN['migrations'][2]['stepsRun'] === 6,
    'the upgrade applies 0004 (all 31 steps), 0005 (all 12 steps: 2 updates, 6 settings, 4 tables) then 0006 (6 columns): ' . json_encode($itUpN['migrations'])
);
// 📝 Decision 0022 (#161): the starting descriptions mark unbuilt features as coming soon.
itCheck('N upgrade from 0003', itValue($itN, "SELECT `description` FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'") === 'For individuals who need dynamic QR codes, analytics and automation (all coming soon).', '#161: the starting Pro description now marks the unbuilt features as coming soon');
itCheck('N upgrade from 0003', str_contains(itValue($itN, "SELECT `description` FROM `tblSubscriptionPlans` WHERE `planKey` = 'business'"), 'team roles coming soon'), '#161: ...and so does the starting Business description');
itCheck('N upgrade from 0003', itSchemaSnapshot($itN) === $itSchemaA, 'the upgraded database\'s structure is byte-for-byte the fresh install\'s');
itCheck('N upgrade from 0003', itPhase2Data($itN) === $itDataA, 'and its plans, price points and settings are exactly the fresh install\'s');
itCheck('N upgrade from 0003', migrationDbSeedDataState($itN)['state'] === 'complete', 'the installer\'s starting-data check still reads "complete"');
$itAgainN = migrationRun($itN, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('N upgrade from 0003', $itAgainN['status'] === 'ok' && $itAgainN['migrations'] === [] && itPhase2Data($itN) === $itDataA, 'a second upgrade run changes nothing');

// N2. The owner had already changed things before 0004 arrived.
$itN2 = itFreshDb($itEnv, $itPort, $itDbN2);
itRunSqlFile($itN2, $itInstallSql);
itRunSqlFile($itN2, $itSeedSql);
migrationRun($itN2, 'apply', 'test', $itDir0003, migrationDefaultInstallSqlPath());
// 📝 Codex finding 2.P2: owner edits that differ from the starting wording
//    ONLY by a trailing space (Pro) or in capitals (Business, "sso") - the
//    table's collation treats both as the same text.
$itN2->query("UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 5.49, `description` = 'For individuals who need dynamic QR codes, analytics, and automation. ' WHERE `planKey` = 'pro'");
$itN2->query("UPDATE `tblSubscriptionPlans` SET `description` = 'For teams and organisations that need sso, roles, and enterprise-scale volume.' WHERE `planKey` = 'business'");
$itN2->query("INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `billingInterval`, `amount`, `currency`) SELECT `planID`, 'yearly_gbp', 'yearly', 45.00, 'GBP' FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'");
$itN2->query("INSERT INTO `tblSubscriptionPlans` (`planKey`, `planName`, `priceMonthly`, `priceYearly`, `currency`, `featuresJSON`, `limitsJSON`, `sortOrder`) VALUES ('enterprise', 'Our Enterprise', 150.00, 1500.00, 'GBP', '{}', '{}', 9)");
$itN2->query("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES ('billing', 'billing_currencies', 'GBP', 'string', 0, 1, 'Owner wording')");
$itPlanCountBeforeN2 = itNumber($itN2, 'SELECT COUNT(*) FROM `tblSubscriptionPlans`');
$itUpN2 = migrationRun($itN2, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('N owner changes kept', $itUpN2['status'] === 'ok', '0004 applies on a database the owner had already changed: ' . $itUpN2['error']);
itCheck('N owner changes kept', itValue($itN2, "SELECT `priceMonthly` FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'") === '5.490', 'the owner\'s own Pro monthly price (£5.49, no longer the placeholder) is NOT moved to £6.99');
itCheck('N owner changes kept', itValue($itN2, "SELECT `priceMonthly` FROM `tblSubscriptionPlans` WHERE `planKey` = 'business'") === '24.990', 'Business, still at the placeholder, IS moved to £24.99');
itCheck('N owner changes kept', itValue($itN2, "SELECT `description` FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'") === 'For individuals who need dynamic QR codes, analytics, and automation. ', '#161 and Codex 2.P2: a Pro description the owner changed ONLY by adding a trailing space is NOT overwritten');
itCheck('N owner changes kept', itValue($itN2, "SELECT `description` FROM `tblSubscriptionPlans` WHERE `planKey` = 'business'") === 'For teams and organisations that need sso, roles, and enterprise-scale volume.', 'Codex 2.P2: a Business description the owner changed ONLY in capitals ("sso") is NOT overwritten (byte-exact match; the untouched case is proved in N1)');
itCheck('N owner changes kept', itValue($itN2, "SELECT CONCAT(`pp`.`amount`, '|', COALESCE(`pp`.`priceGroupKey`, 'NULL'), '|', `pp`.`isDefault`) FROM `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID` WHERE `p`.`planKey` = 'pro' AND `pp`.`priceKey` = 'yearly_gbp'") === '45.000|NULL|0', 'the owner\'s own Pro yearly price point (£45.00) is kept exactly, not replaced by the draft £49.99');
// 💷 Review finding F5 (7n Phase 2 review): the price list must agree with the
//    owner's own price, never hold the draft. Before the fix 0004 added the
//    draft £6.99 here while the page kept £5.49, and the next save of ANY
//    price of the plan copied £6.99 over the owner's figure.
itCheck('N owner changes kept', itValue($itN2, "SELECT `pp`.`amount` FROM `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID` WHERE `p`.`planKey` = 'pro' AND `pp`.`priceKey` = 'monthly_gbp'") === '5.490', 'F5: the missing Pro monthly price point is added at the OWNER\'s price (£5.49), not the draft £6.99');
itCheck('N owner changes kept', itValue($itN2, "SELECT `pp`.`amount` FROM `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID` WHERE `p`.`planKey` = 'business' AND `pp`.`priceKey` = 'monthly_gbp'") === '24.990', 'F5: Business (still at the placeholder, so moved) gets the matching £24.99 price point');

// F5 follow-on: a save of a DIFFERENT price group of the same plan (yearly)
// through the real editor must leave the owner's monthly price alone.
$itN2->query("INSERT INTO `tblUsers` (`email`, `emailVerifiedAt`, `passwordHash`, `displayName`, `status`, `roleID`) VALUES ('admin@n2.cuercode.invalid', '2026-11-01 09:00:00', 'x', 'IT admin', 'active', 1)");
$itN2AdminID = (int) $itN2->insert_id;
$itN2ProID   = itNumber($itN2, "SELECT `planID` FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'");
$itN2Save    = itSaveGroupWithEditor($itDbN2, ['userID' => $itN2AdminID, 'email' => 'admin@n2.cuercode.invalid', 'planID' => $itN2ProID, 'group' => 'yearly', 'post' => ['amount' => ['GBP' => '49.99']]]);
itCheck('N owner changes kept', is_array($itN2Save) && $itN2Save['ok'] === true && $itN2Save['changed'] === true, 'F5: the real editor saves Pro\'s YEARLY price: ' . json_encode($itN2Save));
itCheck('N owner changes kept', itValue($itN2, "SELECT `priceMonthly` FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'") === '5.490', 'F5: ...and the owner\'s Pro MONTHLY price on the pricing page is still £5.49 (not replaced by a draft price)');
itCheck('N owner changes kept', itNumber($itN2, "SELECT COUNT(*) FROM `tblAdminAuditLog` WHERE `action` = 'plan.update' AND `entityKey` = 'pro' AND `changesJSON` LIKE '%priceMonthly%'") === 0, 'F5: no change of Pro\'s monthly price was recorded in the admin change log');
itCheck('N owner changes kept', itValue($itN2, "SELECT CONCAT(`planName`, '|', `priceMonthly`, '|', `ctaMode`, '|', `sortOrder`) FROM `tblSubscriptionPlans` WHERE `planKey` = 'enterprise'") === 'Our Enterprise|150.000|checkout|9', 'the owner\'s hand-made Enterprise plan is kept exactly (name, price, button, position)');
itCheck('N owner changes kept', itNumber($itN2, "SELECT COUNT(*) FROM `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID` WHERE `p`.`planKey` = 'enterprise'") === 0, '...and is NOT given the draft £99 price (only a Contact us plan 0004 made gets it)');
itCheck('N owner changes kept', itNumber($itN2, 'SELECT COUNT(*) FROM `tblSubscriptionPlans`') === $itPlanCountBeforeN2, 'no second Enterprise plan was made');
itCheck('N owner changes kept', itValue($itN2, "SELECT CONCAT(`settingValue`, '|', `description`) FROM `tblSettings` WHERE `settingKey` = 'billing_currencies'") === 'GBP|Owner wording', 'an existing billing_currencies setting is kept exactly');
itCheck('N owner changes kept', itValue($itN2, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'pricing_show_prices'") === '1', 'the missing pricing_show_prices setting is added (1)');

// N3. Changes made AFTER 0004, then 0004 forced to run again.
$itN2->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'pricing_show_prices'");
$itN2->query("UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 23.00 WHERE `planKey` = 'business'");
$itN2->query("UPDATE `tblPlanPricePoints` AS `pp` JOIN `tblSubscriptionPlans` AS `p` ON `p`.`planID` = `pp`.`planID` SET `pp`.`amount` = 26.00 WHERE `p`.`planKey` = 'business' AND `pp`.`priceKey` = 'monthly_gbp'");
$itDataBeforeRerunN2 = itPhase2Data($itN2);
$itSettingCountN2 = itNumber($itN2, 'SELECT COUNT(*) FROM `tblSettings`');
$itN2->query("DELETE FROM `tblSchemaMigrations` WHERE `migrationKey` = '0004_plans_prices_currencies'");
$itRerunN2 = migrationRun($itN2, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('N owner changes kept', $itRerunN2['status'] === 'ok' && isset($itRerunN2['migrations'][0]) && $itRerunN2['migrations'][0]['stepsSkipped'] === 16, '0004 forced to run again: every structure step is skipped (16), the data steps run: ' . json_encode($itRerunN2['migrations']));
itCheck('N owner changes kept', itPhase2Data($itN2) === $itDataBeforeRerunN2 && itNumber($itN2, 'SELECT COUNT(*) FROM `tblSettings`') === $itSettingCountN2, 'running 0004 again overwrites NOTHING (prices shown switched off, Business £23.00 and its £26.00 price point all kept) and adds no rows');
itCheck('N owner changes kept', itValue($itN2, "SELECT GROUP_CONCAT(`settingValue` ORDER BY `settingKey`) FROM `tblSettings` WHERE `settingKey` IN ('billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled')") === '0,0,0', 'charging stays off: the three charging switches are still 0');

foreach ((array) glob($itDir0003 . '*') as $itTempFile) {
    @unlink((string) $itTempFile);
}
@rmdir($itDir0003);

// ==============================================================================
// 🅾️ Scenario O (#163): a database at migration 0004 upgrades to 0005 - an
//    untouched "none" becomes "auto"; an admin's or the command line's own
//    choice is kept; running it again changes nothing
// ==============================================================================
echo PHP_EOL . 'Scenario O: migration 0005 (human check) on a database at 0004; owner choices are kept' . PHP_EOL;

$itDir0004 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_it_mig0004_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
mkdir($itDir0004, 0700);
foreach (['0001_subscription_price_snapshot_columns', '0002_admin_audit_log_and_settings', '0003_sessions_valid_from', '0004_plans_prices_currencies'] as $itKey0004) {
    copy(migrationDefaultDirectory() . $itKey0004 . '.php', $itDir0004 . $itKey0004 . '.php');
}

/**
 * 🧱 A fresh database brought to migration 0004 (O scenarios).
 */
function itAt0004(array $itEnv, int $itPort, string $itDbName, string $itInstallSql, string $itSeedSql, string $itDir0004): \mysqli
{
    $itDb = itFreshDb($itEnv, $itPort, $itDbName);
    itRunSqlFile($itDb, $itInstallSql);
    itRunSqlFile($itDb, $itSeedSql);
    migrationRun($itDb, 'apply', 'test', $itDir0004, migrationDefaultInstallSqlPath());
    return $itDb;
}

$itProviderSql = "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'";
$itAuditProviderSql = "INSERT INTO `tblAdminAuditLog` (`actorType`, `actorLabel`, `action`, `entityType`, `entityKey`, `outcome`) VALUES ('cli', 'cli:it', 'setting.update', 'setting', 'bot_protection_provider', 'success')";

// O1. Untouched: "none" -> "auto".
$itO = itAt0004($itEnv, $itPort, $itDbO, $itInstallSql, $itSeedSql, $itDir0004);
itCheck('O 0005 human check', itValue($itO, $itProviderSql) === 'none' && itNumber($itO, "SELECT COUNT(*) FROM `tblSchemaMigrations`") === 4, 'setup: at 0004 the provider is the starting "none"');
$itUpO = migrationRun($itO, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('O 0005 human check', $itUpO['status'] === 'ok' && count($itUpO['migrations']) === 2 && $itUpO['migrations'][0]['key'] === '0005_human_check_settings' && $itUpO['migrations'][0]['stepsRun'] === 12 && $itUpO['migrations'][1]['key'] === '0006_three_decimal_money_columns', '0005 applies, all 12 steps (then 0006): ' . json_encode($itUpO['migrations']));
itCheck('O 0005 human check', itValue($itO, $itProviderSql) === 'auto', 'untouched "none" becomes "auto" (off until keys are entered)');
itCheck('O 0005 human check', migrationDbSeedDataState($itO)['state'] === 'complete', 'the installer\'s starting-data check still reads "complete"');
$itSettingsAfterO = itValue($itO, "SELECT GROUP_CONCAT(CONCAT(`settingKey`, '=', `settingValue`, '|', `description`) ORDER BY `settingKey` SEPARATOR ';') FROM `tblSettings` WHERE `settingGroup` = 'security'");
$itCountAfterO = itNumber($itO, 'SELECT COUNT(*) FROM `tblSettings`');

// O2. Run 0005 again after the owner switched it off with the command line.
$itO->query("UPDATE `tblSettings` SET `settingValue` = 'none' WHERE `settingKey` = 'bot_protection_provider'");
$itO->query($itAuditProviderSql);
$itO->query("UPDATE `tblSettings` SET `settingValue` = '9' WHERE `settingKey` = 'bot_protection_timeout_seconds'");
$itO->query("DELETE FROM `tblSchemaMigrations` WHERE `migrationKey` = '0005_human_check_settings'");
$itRerunO = migrationRun($itO, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('O 0005 human check', $itRerunO['status'] === 'ok' && isset($itRerunO['migrations'][0]) && $itRerunO['migrations'][0]['key'] === '0005_human_check_settings', '0005 forced to run again: ' . json_encode($itRerunO['migrations']));
itCheck('O 0005 human check', itValue($itO, $itProviderSql) === 'none', 'a "none" chosen with the command line (a row in the admin change log) is KEPT - the check stays off');
itCheck('O 0005 human check', itValue($itO, "SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'bot_protection_timeout_seconds'") === '9', 'the owner\'s own waiting time (9) is kept');
itCheck('O 0005 human check', itNumber($itO, 'SELECT COUNT(*) FROM `tblSettings`') === $itCountAfterO, 'running 0005 again adds no rows');
itDropDb($itEnv, $itPort, $itDbO);

// O3. An admin had chosen a provider (or "none") on the screen BEFORE 0005 arrived.
$itO = itAt0004($itEnv, $itPort, $itDbO, $itInstallSql, $itSeedSql, $itDir0004);
$itO->query($itAuditProviderSql);
migrationRun($itO, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('O 0005 human check', itValue($itO, $itProviderSql) === 'none', 'a "none" an admin had saved before 0005 (change-log row present) is KEPT');
itDropDb($itEnv, $itPort, $itDbO);
$itO = itAt0004($itEnv, $itPort, $itDbO, $itInstallSql, $itSeedSql, $itDir0004);
$itO->query("UPDATE `tblSettings` SET `settingValue` = 'recaptcha', `description` = 'Owner wording' WHERE `settingKey` = 'bot_protection_provider'");
migrationRun($itO, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('O 0005 human check', itValue($itO, "SELECT CONCAT(`settingValue`, '|', `description`) FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === 'recaptcha|Owner wording', 'an owner\'s own provider ("recaptcha") and own description are KEPT');
itCheck('O 0005 human check', $itSettingsAfterO !== '' && str_contains($itSettingsAfterO, 'bot_protection_when_unreachable=signin_only'), 'the new settings are in the security group (the outage rule starts as decision 0037)');
itDropDb($itEnv, $itPort, $itDbO);

// O4. Byte for byte: a stored value or description that differs from the
//     starting one only by capitals or a trailing space is someone's own
//     (the column's comparison rules would call them equal).
foreach ([
    'capitals'       => ['NONE', 'ACTIVE bot protection provider (none, turnstile, recaptcha, hcaptcha)'],
    'trailing space' => ['none ', 'Active bot protection provider (none, turnstile, recaptcha, hcaptcha) '],
] as $itO4Case => $itO4Values) {
    $itO = itAt0004($itEnv, $itPort, $itDbO, $itInstallSql, $itSeedSql, $itDir0004);
    $itO4Stmt = $itO->prepare("UPDATE `tblSettings` SET `settingValue` = ?, `description` = ? WHERE `settingKey` = 'bot_protection_provider'");
    $itO4Stmt->bind_param('ss', $itO4Values[0], $itO4Values[1]);
    $itO4Stmt->execute();
    $itO4Stmt->close();
    migrationRun($itO, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
    itCheck('O 0005 human check', itValue($itO, "SELECT CONCAT('[', `settingValue`, ']|[', `description`, ']') FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'") === '[' . $itO4Values[0] . ']|[' . $itO4Values[1] . ']', 'a stored value and description that differ from the starting ones only by ' . $itO4Case . ' are KEPT byte for byte: ' . itValue($itO, "SELECT CONCAT('[', `settingValue`, ']|[', `description`, ']') FROM `tblSettings` WHERE `settingKey` = 'bot_protection_provider'"));
    itDropDb($itEnv, $itPort, $itDbO);
}

foreach ((array) glob($itDir0004 . '*') as $itTempFile) {
    @unlink((string) $itTempFile);
}
@rmdir($itDir0004);

// ==============================================================================
// 🅺 Scenario K: the upgrade lock name includes the database (review finding
//    F6) - two different databases never block each other's upgrades
// ==============================================================================
echo PHP_EOL . 'Scenario K: the upgrade lock name is per-database (F6)' . PHP_EOL;
$itK1 = itFreshDb($itEnv, $itPort, $itDbK1);
$itK2 = itFreshDb($itEnv, $itPort, $itDbK2);

$itLockNameK1 = itLockNameFor($itK1);
$itLockNameK2 = itLockNameFor($itK2);
itCheck('K per-database lock name', $itLockNameK1 !== $itLockNameK2, 'two different databases compute two different lock names');

$itLockRowK1 = $itK1->query("SELECT GET_LOCK('" . $itK1->real_escape_string($itLockNameK1) . "', 0) AS gotLock")->fetch_assoc();
itCheck('K per-database lock name', (int) $itLockRowK1['gotLock'] === 1, 'database K1\'s own lock is taken directly');

$itCliResultK2 = itCli($itDbK2, 'apply');
itCheck('K per-database lock name', $itCliResultK2['exit'] === 0, 'holding database K1\'s upgrade lock does not block an upgrade running on database K2 (a different database)');

$itK1->query("SELECT RELEASE_LOCK('" . $itK1->real_escape_string($itLockNameK1) . "')");

$itCliResultK1 = itCli($itDbK1, 'apply');
itCheck('K per-database lock name', $itCliResultK1['exit'] === 0, 'once K1\'s own lock is released, an upgrade on K1 itself still runs normally (the lock genuinely protects K1 against itself, it is not simply "never blocks anything")');

// ==============================================================================
// 🅻 Scenario L (independent review, Codex C1 findings 1, 2 and 4, 2026-11-25):
//    the installer's starting-data load is all-or-nothing, a part-loaded
//    database is recognised as PART-loaded (not "already done"), a failed
//    check is an error (not "no data"), and only one installer runs at once
// ==============================================================================
echo PHP_EOL . 'Scenario L: starting data loads all-or-nothing; part-loaded and unreadable states are caught; one installer at a time' . PHP_EOL;

// 💥 A seed file that fails part-way: the real seed_data.sql with one bad
//    statement slipped in just before the predefined-logos insert, so the
//    settings and QR-code-type inserts have already happened when it fails.
$itBrokenSeedSql = str_replace(
    'INSERT INTO `tblPredefinedLogos`',
    'INSERT INTO `tblItNoSuchTable` (`x`) VALUES (1);' . PHP_EOL . 'INSERT INTO `tblPredefinedLogos`',
    $itSeedSql
);
itCheck('L seed all-or-nothing', $itBrokenSeedSql !== $itSeedSql, 'the deliberately broken seed file was built');

// 🐞 Reproduce the reported fault the OLD way (plain multi_query, no
//    transaction): the earlier inserts stay behind, and the old yes/no check
//    then says "already present" - so a retry would have skipped the rest.
$itL = itFreshDb($itEnv, $itPort, $itDbL);
itRunSqlFile($itL, $itInstallSql);
itCheck('L seed all-or-nothing', str_starts_with(itRunSqlFile($itL, $itBrokenSeedSql), 'ERR'), 'reproduction: the broken seed file fails part-way when run the old way');
itCheck('L seed all-or-nothing', (int) $itL->query('SELECT COUNT(*) FROM `tblQRCodeTypes`')->fetch_row()[0] > 0, 'reproduction: the old way leaves QR code types behind');
$itOldCheckL = migrationDbSeedDataAlreadyPresent($itL);
itCheck('L seed all-or-nothing', $itOldCheckL['ok'] === true && $itOldCheckL['present'] === true, 'reproduction: the old yes/no check calls that "already present" (why a retry used to skip the rest)');

$itStateL = migrationDbSeedDataState($itL);
itCheck(
    'L seed all-or-nothing',
    $itStateL['ok'] === true && $itStateL['state'] === 'partial'
        && in_array('tblPredefinedLogos', $itStateL['empty'], true)
        && in_array('tblSubscriptionPlans', $itStateL['empty'], true)
        && in_array('tblQRCodeTypes', $itStateL['filled'], true),
    'the new three-way check calls it PART-loaded and names the empty tables'
);

// ✅ The NEW way: the same broken file, run through migrationDbRunSeedSql(),
//    leaves nothing behind at all, so a retry starts from a clean slate.
$itL = itFreshDb($itEnv, $itPort, $itDbL);
itRunSqlFile($itL, $itInstallSql);
$itSeedRunL = migrationDbRunSeedSql($itL, $itBrokenSeedSql);
itCheck('L seed all-or-nothing', $itSeedRunL['ok'] === false && $itSeedRunL['error'] !== '', 'the broken seed file is reported as failed');
$itLeftL = 0;
foreach (['tblSettings', 'tblQRCodeTypes', 'tblPredefinedLogos', 'tblSubscriptionPlans'] as $itSeedTableL) {
    $itLeftL = $itLeftL + (int) $itL->query('SELECT COUNT(*) FROM `' . $itSeedTableL . '`')->fetch_row()[0];
}
itCheck('L seed all-or-nothing', $itLeftL === 0, 'nothing from the failed load is left behind in any of the four starting-data tables (all-or-nothing)');
$itStateL = migrationDbSeedDataState($itL);
itCheck('L seed all-or-nothing', $itStateL['ok'] === true && $itStateL['state'] === 'none', 'so the database reads as "no starting data yet"');
itCheck('L seed all-or-nothing', (int) $itL->query('SELECT 1')->fetch_row()[0] === 1,'the connection is still usable after the failed load');

$itSeedRunL = migrationDbRunSeedSql($itL, $itSeedSql);
itCheck('L seed all-or-nothing', $itSeedRunL['ok'] === true, 'a retry with the good file then loads everything: ' . $itSeedRunL['error']);
$itStateL = migrationDbSeedDataState($itL);
itCheck('L seed all-or-nothing', $itStateL['ok'] === true && $itStateL['state'] === 'complete' && $itStateL['empty'] === [], 'and the database reads as "complete"');
$itWrapperL = migrationDbSeedDataAlreadyPresent($itL);
itCheck('L seed all-or-nothing', $itWrapperL['ok'] === true && $itWrapperL['present'] === true, 'the older yes/no check still answers "present" for a complete database');

// 🗂️ No tables at all: "none", not an error (install.sql has not run yet).
$itL = itFreshDb($itEnv, $itPort, $itDbL);
$itStateL = migrationDbSeedDataState($itL);
itCheck('L seed check errors', $itStateL['ok'] === true && $itStateL['state'] === 'none', 'a database with no tables at all reads as "no starting data yet", not an error');

// ⚠️ A check that FAILS (here: tblPredefinedLogos is a view over a table that
//    has since been dropped, so counting its rows is a database error that is
//    NOT "table missing") must be reported as an error, never as "no data".
itRunSqlFile($itL, $itInstallSql);
$itL->query('DROP TABLE `tblPredefinedLogos`');
$itL->query('CREATE TABLE `tblItGone` (`x` INT) ENGINE=InnoDB');
$itL->query('CREATE VIEW `tblPredefinedLogos` AS SELECT `x` FROM `tblItGone`');
$itL->query('DROP TABLE `tblItGone`');
$itStateL = migrationDbSeedDataState($itL);
itCheck('L seed check errors', $itStateL['ok'] === false && $itStateL['error'] !== '', 'a failed row count is an ERROR, not "no data" (Codex C1 finding 4): ' . $itStateL['error']);
$itWrapperL = migrationDbSeedDataAlreadyPresent($itL);
itCheck('L seed check errors', $itWrapperL['ok'] === false, 'the older yes/no check also reports the error instead of "no data"');

// 🔒 One installer at a time (Codex C1 finding 2): the installer's own named
//    lock, per database.
$itL   = itFreshDb($itEnv, $itPort, $itDbL);
$itL2  = itFreshDb($itEnv, $itPort, $itDbL2);
$itLb  = itServerConnect($itEnv, $itPort);
$itLb->select_db($itDbL);
itCheck('L installer lock', migrationDbAcquireInstallerLock($itL) === 'got', 'the first installer takes the lock');
itCheck('L installer lock', migrationDbAcquireInstallerLock($itLb) === 'busy', 'a second installer on the SAME database is told "busy" straight away');
itCheck('L installer lock', migrationDbAcquireInstallerLock($itL2) === 'got', 'an installer on a DIFFERENT database is not blocked');
itCheck('L installer lock', migrationDbAcquireLock($itL) === 'got', 'holding the installer lock does not block the upgrade tool\'s own lock on the same connection');
migrationDbReleaseLock($itL);
migrationDbReleaseInstallerLock($itL);
itCheck('L installer lock', migrationDbAcquireInstallerLock($itLb) === 'got', 'once released, the second installer can take it');
migrationDbReleaseInstallerLock($itLb);
migrationDbReleaseInstallerLock($itL2);
$itLb->close();

// ==============================================================================
// 🅼 Scenario M (independent review, Codex round 2 findings R1-1 and R1-2,
//    2026-11-25): "complete" means ALL the starting rows (or the completion
//    marker), never "one row in each table"; the load is refused unless every
//    table it writes to is InnoDB
// ==============================================================================
echo PHP_EOL . 'Scenario M: complete means every starting row (or the marker); non-InnoDB tables are refused' . PHP_EOL;

/**
 * 🔢 Count the rows of one table in a test database.
 */
function itCountRows(\mysqli $itDb, string $itTable): int
{
    return (int) $itDb->query('SELECT COUNT(*) FROM `' . $itTable . '`')->fetch_row()[0];
}

// M1. One row in each table (the case Codex named): PART-loaded, not complete.
$itM = itFreshDb($itEnv, $itPort, $itDbM);
itRunSqlFile($itM, $itInstallSql);
$itM->query("INSERT INTO `tblQRCodeTypes` (`typeName`, `typeLabel`, `typeIcon`, `typeDescription`, `typeCategory`, `formFieldsJSON`, `sortOrder`) VALUES ('url', 'URL', 'fa-solid fa-link', 'x', 'general', '[]', 1)");
$itM->query("INSERT INTO `tblPredefinedLogos` (`logoName`, `logoLabel`, `logoFileName`, `logoCategory`, `sortOrder`) VALUES ('facebook', 'Facebook', 'facebook.svg', 'social', 1)");
$itM->query("INSERT INTO `tblSubscriptionPlans` (`planKey`, `planName`, `featuresJSON`, `limitsJSON`) VALUES ('free', 'Free', '{}', '{}')");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'partial', 'one QR type, one logo and one plan is PART-loaded, not complete (R1-1; before the fix this read "complete"): ' . $itStateM['state']);
itCheck('M seed completeness', $itStateM['marker'] === false && $itStateM['missing'] === ['tblQRCodeTypes' => 37, 'tblPredefinedLogos' => 25, 'tblSubscriptionPlans' => 3, 'tblSettings' => 80], 'it counts what is missing per table: ' . json_encode($itStateM['missing']));
itCheck('M seed completeness', str_contains($itStateM['summary'], 'tblPredefinedLogos has 1 of its 26 starting rows'), 'the plain-English summary says what is there: ' . $itStateM['summary']);

// M2. A complete seed, then the marker removed (a database seeded before the
//     marker existed): every starting row is there, so it is complete.
$itM = itFreshDb($itEnv, $itPort, $itDbM);
itRunSqlFile($itM, $itInstallSql);
$itSeedRunM = migrationDbRunSeedSql($itM, $itSeedSql);
itCheck('M seed completeness', $itSeedRunM['ok'] === true && $itSeedRunM['refusal'] === '', 'the real seed_data.sql loads: ' . $itSeedRunM['error']);
itCheck('M seed completeness', (int) $itM->query("SELECT COUNT(*) FROM `tblSettings` WHERE `settingKey` = 'seed_data_loaded' AND `settingGroup` = 'system' AND `isEditable` = 0")->fetch_row()[0] === 1, 'the load wrote the completion marker (not editable)');
itCheck('M seed completeness', itCountRows($itM, 'tblQRCodeTypes') === 38 && itCountRows($itM, 'tblPredefinedLogos') === 26 && itCountRows($itM, 'tblSubscriptionPlans') === 4, 'it loaded 38 types, 26 logos and 4 plans (the counts migrationSeedExpectedRows() lists)');
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'complete' && $itStateM['marker'] === true, 'with the marker: complete');
$itM->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'seed_data_loaded'");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'complete' && $itStateM['marker'] === false && $itStateM['missing'] === [], 'without the marker but with every starting row: still complete (databases seeded before the marker existed)');

// M3. ...and one starting row deleted with no marker: PART-loaded again.
$itM->query("DELETE FROM `tblPredefinedLogos` WHERE `logoName` = 'qrcode'");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'partial' && $itStateM['missing'] === ['tblPredefinedLogos' => 1], 'no marker and one logo short: part-loaded, and it says which table: ' . $itStateM['summary']);

// M4. With the marker, owner changes do not matter: a deleted logo and a
//     renamed plan are the owner's business, and the database stays complete.
$itM->query("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES ('system', 'seed_data_loaded', '1', 'boolean', 0, 0, 'x')");
$itM->query("UPDATE `tblSubscriptionPlans` SET `planKey` = 'pro_2027' WHERE `planKey` = 'pro'");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'complete', 'with the marker, rows the owner later deleted or renamed do not make it "part-loaded"');

// M5. Marker left behind but the three tables emptied (decision 0018, Codex
//     round 3 finding 1): the marker means seeding already happened, so the
//     state is "complete" and the installer skips seeding - it must NOT put
//     back rows the owner deliberately removed. (Before this fix the answer was
//     "none" and the default rows were loaded again; reproduced first.)
$itM->query('SET FOREIGN_KEY_CHECKS = 0');
foreach (migrationSeedProtectionTables() as $itTableM) {
    $itM->query('DELETE FROM `' . $itTableM . '`');
}
$itM->query('SET FOREIGN_KEY_CHECKS = 1');
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'complete' && $itStateM['marker'] === true, 'marker present but every starting table empty: "complete" (seeding already happened), so it is NOT loaded again: ' . $itStateM['state']);
$itWrapperM = migrationDbSeedDataAlreadyPresent($itM);
itCheck('M seed completeness', $itWrapperM['ok'] === true && $itWrapperM['present'] === true, 'and the older yes/no check says "present"');
itCheck('M seed completeness', itCountRows($itM, 'tblQRCodeTypes') === 0 && itCountRows($itM, 'tblPredefinedLogos') === 0 && itCountRows($itM, 'tblSubscriptionPlans') === 0, 'nothing was put back in the three emptied tables');
// 🔁 The documented way to force a clean re-seed: remove the marker first.
$itM->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'seed_data_loaded'");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed completeness', $itStateM['ok'] === true && $itStateM['state'] === 'none', 'marker removed and the three tables empty: "none" (a deliberate clean re-seed is allowed)');
$itSeedRunM = migrationDbRunSeedSql($itM, $itSeedSql);
itCheck('M seed completeness', $itSeedRunM['ok'] === true && migrationDbSeedDataState($itM)['state'] === 'complete', 'and loading again then succeeds: ' . $itSeedRunM['error']);
itCheck('M seed completeness', itCountRows($itM, 'tblQRCodeTypes') === 38 && itCountRows($itM, 'tblPredefinedLogos') === 26 && itCountRows($itM, 'tblSubscriptionPlans') === 4, 'with every starting row back');

// M5b. No marker, all 68 rows present, but a starting SETTING missing (Codex
//      round 3 finding 2): part-loaded, not complete. Before this fix the three
//      tables alone decided it and the answer was "complete".
$itM->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'seed_data_loaded'");
itCheck('M seed settings', migrationDbSeedDataState($itM)['state'] === 'complete', 'baseline: no marker, 68 rows and every starting setting is complete');
$itM->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'site_name'");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed settings', $itStateM['ok'] === true && $itStateM['state'] === 'partial' && $itStateM['missing'] === ['tblSettings' => 1], 'no marker, all 68 rows, one key setting (site_name) missing: partial: ' . json_encode($itStateM['missing']));
itCheck('M seed settings', str_contains($itStateM['summary'], 'tblSettings is missing 1 of its 80 starting settings (site_name)'), 'and the summary names the settings: ' . $itStateM['summary']);
// Putting it back makes it complete again.
$itM->query("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES ('general', 'site_name', 'CueRCode', 'string', 0, 1, 'x')");
itCheck('M seed settings', migrationDbSeedDataState($itM)['state'] === 'complete', 'putting it back makes it complete again');
// Codex round 4 finding 1: a missing starting setting that is NOT one of the
// old nine key settings, with one unrelated extra settings row added so the
// total row count is still 80. Before this fix that read "complete".
$itM->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'copyright_year'");
$itM->query("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES ('general', 'unrelated_extra_setting', 'x', 'string', 0, 1, 'x')");
itCheck('M seed settings', itCountRows($itM, 'tblSettings') === 80, 'setup: the settings row count is still 80 (one missing, one unrelated extra)');
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed settings', $itStateM['ok'] === true && $itStateM['state'] === 'partial' && $itStateM['missing'] === ['tblSettings' => 1], 'no marker, 68 rows, copyright_year missing, one unrelated extra row: partial: ' . json_encode($itStateM['missing']));
itCheck('M seed settings', str_contains($itStateM['summary'], 'copyright_year') && str_contains($itStateM['summary'], '1 of its 80'), 'and the summary names the missing setting: ' . $itStateM['summary']);
// Many missing: only about five names are shown, with a count of the rest.
$itM->query("DELETE FROM `tblSettings` WHERE `settingGroup` = 'general'");
$itStateM = migrationDbSeedDataState($itM);
itCheck('M seed settings', $itStateM['state'] === 'partial' && str_contains($itStateM['summary'], ' more)') && substr_count($itStateM['summary'], ',') === 4, 'many missing: five names shown, then "and N more": ' . $itStateM['summary']);
$itM->query("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES ('general', 'copyright_year', '2026', 'string', 0, 1, 'x')");
// With the marker, a missing setting is the owner's business.
$itM->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'site_name'");
$itM->query("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) VALUES ('system', 'seed_data_loaded', '1', 'boolean', 0, 0, 'x')");
itCheck('M seed settings', migrationDbSeedDataState($itM)['state'] === 'complete', 'with the marker, a missing setting does not matter');

// M6. Not InnoDB (R1-2). First show WHY it matters: the same all-or-nothing
//     wrapping, run by hand on a MyISAM table, keeps that table's rows after
//     the rollback. Then show the real function refuses before writing anything.
$itBrokenAfterLogos = str_replace(
    'INSERT INTO `tblSubscriptionPlans`',
    'INSERT INTO `tblItNoSuchTable` (`x`) VALUES (1);' . PHP_EOL . 'INSERT INTO `tblSubscriptionPlans`',
    $itSeedSql
);
$itM = itFreshDb($itEnv, $itPort, $itDbM);
itRunSqlFile($itM, $itInstallSql);
$itM->query('ALTER TABLE `tblPredefinedLogos` ENGINE=MyISAM');
itRunSqlFile($itM, 'START TRANSACTION;' . PHP_EOL . $itBrokenAfterLogos . PHP_EOL . 'COMMIT;');
$itM->rollback();
itCheck('M non-InnoDB refused', itCountRows($itM, 'tblQRCodeTypes') === 0 && itCountRows($itM, 'tblPredefinedLogos') === 26, 'reproduction: with a MyISAM logos table the rollback undoes the QR types but keeps all 26 logos (why the check is needed)');

$itM = itFreshDb($itEnv, $itPort, $itDbM);
itRunSqlFile($itM, $itInstallSql);
$itM->query('ALTER TABLE `tblPredefinedLogos` ENGINE=MyISAM');
$itSeedRunM = migrationDbRunSeedSql($itM, $itSeedSql);
itCheck('M non-InnoDB refused', $itSeedRunM['ok'] === false && str_contains($itSeedRunM['refusal'], 'tblPredefinedLogos uses the MyISAM storage type') && str_contains($itSeedRunM['refusal'], 'ALTER TABLE `tblPredefinedLogos` ENGINE=InnoDB;'), 'a MyISAM table: the load is refused, and the plain-English reason says how to fix it: ' . $itSeedRunM['refusal']);
itCheck('M non-InnoDB refused', itCountRows($itM, 'tblSettings') === 0 && itCountRows($itM, 'tblQRCodeTypes') === 0 && itCountRows($itM, 'tblPredefinedLogos') === 0 && itCountRows($itM, 'tblSubscriptionPlans') === 0, 'nothing at all was written, in any of the four tables');
$itM->query('ALTER TABLE `tblPredefinedLogos` ENGINE=InnoDB');
$itSeedRunM = migrationDbRunSeedSql($itM, $itSeedSql);
itCheck('M non-InnoDB refused', $itSeedRunM['ok'] === true && migrationDbSeedDataState($itM)['state'] === 'complete', 'once the table is InnoDB again, the load works: ' . $itSeedRunM['error']);

// M7. A view where a table should be, and a missing table: refused too.
$itM = itFreshDb($itEnv, $itPort, $itDbM);
itRunSqlFile($itM, $itInstallSql);
$itM->query('SET FOREIGN_KEY_CHECKS = 0');
$itM->query('DROP TABLE `tblSubscriptionPlans`');
$itM->query('SET FOREIGN_KEY_CHECKS = 1');
$itSeedRunM = migrationDbRunSeedSql($itM, $itSeedSql);
itCheck('M non-InnoDB refused', $itSeedRunM['ok'] === false && str_contains($itSeedRunM['refusal'], 'tblSubscriptionPlans does not exist.') && itCountRows($itM, 'tblSettings') === 0, 'a missing table: refused, nothing written');
$itM->query('CREATE VIEW `tblSubscriptionPlans` AS SELECT 1 AS `planKey`');
$itSeedRunM = migrationDbRunSeedSql($itM, $itSeedSql);
itCheck('M non-InnoDB refused', $itSeedRunM['ok'] === false && str_contains($itSeedRunM['refusal'], 'tblSubscriptionPlans is not an ordinary table.') && itCountRows($itM, 'tblSettings') === 0, 'a view: refused, nothing written');

// ==============================================================================
// 🅿️ Scenario P (#176, decision 0042): upgrade 0006 widens every money column
//    to DECIMAL(15,3) - no stored amount changes; safe to run again; a run
//    that stopped half-way finishes; a column it could only narrow (or whose
//    "may be empty" differs) is refused and left alone
// ==============================================================================
echo PHP_EOL . 'Scenario P: upgrade 0006 (three decimal places) on a database at 0005' . PHP_EOL;

$itDir0005 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_it_mig0005_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
mkdir($itDir0005, 0700);
foreach (['0001_subscription_price_snapshot_columns', '0002_admin_audit_log_and_settings', '0003_sessions_valid_from', '0004_plans_prices_currencies', '0005_human_check_settings'] as $itKey0005) {
    copy(migrationDefaultDirectory() . $itKey0005 . '.php', $itDir0005 . $itKey0005 . '.php');
}
$itMoneyColumns = [['tblSubscriptionPlans', 'priceMonthly'], ['tblSubscriptionPlans', 'priceYearly'], ['tblPlanPricePoints', 'amount'], ['tblDiscounts', 'value'], ['tblPayments', 'amount'], ['tblOneOffPurchases', 'amount']];

/**
 * 🧱 A fresh database brought to migration 0005 (P scenarios).
 */
function itAt0005(array $itEnv, int $itPort, string $itDbName, string $itInstallSql, string $itSeedSql, string $itDir0005): \mysqli
{
    $itDb = itFreshDb($itEnv, $itPort, $itDbName);
    itRunSqlFile($itDb, $itInstallSql);
    itRunSqlFile($itDb, $itSeedSql);
    migrationRun($itDb, 'apply', 'test', $itDir0005, migrationDefaultInstallSqlPath());
    return $itDb;
}

/**
 * 💷 "type|nullable|default" of each money column, in order. "none" = no
 *    default (MariaDB shows a NULL default as the text NULL, MySQL as a real
 *    NULL; both read "none" here).
 */
function itMoneyColumnTypes(\mysqli $itDb, array $itColumns): array
{
    $itTypes = [];
    foreach ($itColumns as [$itTable, $itColumn]) {
        $itTypes[] = itValue($itDb, "SELECT CONCAT(`COLUMN_TYPE`, '|', `IS_NULLABLE`, '|', CASE WHEN `COLUMN_DEFAULT` IS NULL OR `COLUMN_DEFAULT` = 'NULL' THEN 'none' ELSE `COLUMN_DEFAULT` END) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '" . $itTable . "' AND `COLUMN_NAME` = '" . $itColumn . "'");
    }
    return $itTypes;
}

// P1. Every amount kept exactly, including the largest and smallest values.
$itP = itAt0005($itEnv, $itPort, $itDbP, $itInstallSql, $itSeedSql, $itDir0005);
itCheck('P 0006 money columns', itMoneyColumnTypes($itP, $itMoneyColumns) === ['decimal(10,2)|NO|0.00', 'decimal(10,2)|NO|0.00', 'decimal(10,2)|NO|none', 'decimal(10,2)|NO|none', 'decimal(10,2)|NO|none', 'decimal(10,2)|NO|none'], 'setup: at 0005 every money column is DECIMAL(10,2): ' . json_encode(itMoneyColumnTypes($itP, $itMoneyColumns)));
$itP->query("UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 99999999.99, `priceYearly` = 0.01 WHERE `planKey` = 'business'");
$itP->query("INSERT INTO `tblDiscounts` (`code`, `name`, `discountType`, `value`) VALUES ('IT-P', 'IT twelve and a half', 'percent', 12.50)");
// 🔢 Every amount as a whole number of thousandths (exact DECIMAL arithmetic).
$itMoneySql = "SELECT CONCAT((SELECT GROUP_CONCAT(CONCAT(`planKey`, '=', CAST(`priceMonthly` * 1000 AS SIGNED), '/', CAST(`priceYearly` * 1000 AS SIGNED)) ORDER BY `planKey`) FROM `tblSubscriptionPlans`),"
    . " ';', (SELECT GROUP_CONCAT(CONCAT(`pricePointID`, '=', CAST(`amount` * 1000 AS SIGNED)) ORDER BY `pricePointID`) FROM `tblPlanPricePoints`),"
    . " ';', (SELECT GROUP_CONCAT(CONCAT(`discountID`, '=', CAST(`value` * 1000 AS SIGNED)) ORDER BY `discountID`) FROM `tblDiscounts`))";
$itMoneyBefore = itValue($itP, $itMoneySql);
$itUpP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('P 0006 money columns', $itUpP['status'] === 'ok' && count($itUpP['migrations']) === 1 && $itUpP['migrations'][0]['key'] === '0006_three_decimal_money_columns' && $itUpP['migrations'][0]['stepsRun'] === 6, '0006 applies, all 6 steps: ' . json_encode($itUpP['migrations']));
itCheck('P 0006 money columns', itMoneyColumnTypes($itP, $itMoneyColumns) === ['decimal(15,3)|NO|0.000', 'decimal(15,3)|NO|0.000', 'decimal(15,3)|NO|none', 'decimal(15,3)|NO|none', 'decimal(15,3)|NO|none', 'decimal(15,3)|NO|none'], 'every money column is DECIMAL(15,3), still NOT NULL, the price defaults 0.000: ' . json_encode(itMoneyColumnTypes($itP, $itMoneyColumns)));
itCheck('P 0006 money columns', itValue($itP, "SELECT CONCAT(`priceMonthly`, '/', `priceYearly`) FROM `tblSubscriptionPlans` WHERE `planKey` = 'business'") === '99999999.990/0.010' && itValue($itP, "SELECT `value` FROM `tblDiscounts` WHERE `code` = 'IT-P'") === '12.500', 'the largest old value 99,999,999.99, the smallest 0.01 and a 12.50% discount are kept exactly');
itCheck('P 0006 money columns', $itMoneyBefore !== '' && itValue($itP, $itMoneySql) === $itMoneyBefore, 'every plan price, price point and discount is exactly the same amount as before: ' . $itMoneyBefore);
itCheck('P 0006 money columns', str_contains(itValue($itP, "SELECT `COLUMN_COMMENT` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblPlanPricePoints' AND `COLUMN_NAME` = 'amount'"), 'up to 3 decimal places'), 'the column descriptions say three decimal places');
itCheck('P 0006 money columns', itSchemaSnapshot($itP) === $itSchemaA, 'the upgraded database\'s structure is byte-for-byte the fresh install\'s');
$itP->query("INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `billingInterval`, `amount`, `currency`) SELECT `planID`, 'monthly_kwd', 'monthly', '1.234', 'KWD' FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'");
$itP->query("INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`, `billingInterval`, `amount`, `currency`) SELECT `planID`, 'monthly_jpy', 'monthly', '999999999999', 'JPY' FROM `tblSubscriptionPlans` WHERE `planKey` = 'pro'");
itCheck('P 0006 money columns', itValue($itP, "SELECT GROUP_CONCAT(`amount` ORDER BY `priceKey`) FROM `tblPlanPricePoints` WHERE `priceKey` IN ('monthly_kwd', 'monthly_jpy')") === '999999999999.000,1.234', 'KWD 1.234 and the largest typed amount (999,999,999,999 yen) are stored exactly');

// P2. Run again: every step is skipped and nothing changes.
$itP->query("DELETE FROM `tblSchemaMigrations` WHERE `migrationKey` = '0006_three_decimal_money_columns'");
$itSchemaBeforeAgainP = itSchemaSnapshot($itP);
$itAgainP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('P 0006 money columns', $itAgainP['status'] === 'ok' && $itAgainP['migrations'][0]['stepsRun'] === 0 && $itAgainP['migrations'][0]['stepsSkipped'] === 6 && itSchemaSnapshot($itP) === $itSchemaBeforeAgainP, 'forced to run again, 0006 skips all 6 steps and changes nothing: ' . json_encode($itAgainP['migrations']));
itDropDb($itEnv, $itPort, $itDbP);

// P3. A run that stopped half-way (three columns already widened, nothing
//     recorded) finishes the other three next time.
$itP = itAt0005($itEnv, $itPort, $itDbP, $itInstallSql, $itSeedSql, $itDir0005);
$itSteps0006 = (require migrationDefaultDirectory() . '0006_three_decimal_money_columns.php')['steps'];
foreach (array_slice($itSteps0006, 0, 3) as $itHalfStep) {
    $itP->query($itHalfStep['sql']);
}
$itHalfP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('P 0006 money columns', $itHalfP['status'] === 'ok' && $itHalfP['migrations'][0]['stepsRun'] === 3 && $itHalfP['migrations'][0]['stepsSkipped'] === 3 && itSchemaSnapshot($itP) === $itSchemaA, 'half-applied: the three done columns are skipped, the other three run, and the result is the fresh install\'s: ' . json_encode($itHalfP['migrations']));
itDropDb($itEnv, $itPort, $itDbP);

// P4. A column someone made WIDER in whole digits by hand (DECIMAL(20,2))
//     would be narrowed by DECIMAL(15,3): refused, left alone, not recorded.
$itP = itAt0005($itEnv, $itPort, $itDbP, $itInstallSql, $itSeedSql, $itDir0005);
$itP->query('ALTER TABLE `tblPayments` MODIFY COLUMN `amount` DECIMAL(20,2) NOT NULL');
$itNarrowP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
$itNarrowProblem = '';
if (isset($itNarrowP['migrations'][0]['problems'][0])) {
    $itNarrowProblem = (string) $itNarrowP['migrations'][0]['problems'][0];
}
itCheck('P 0006 money columns', $itNarrowP['status'] === 'failed' && str_contains($itNarrowProblem, 'step 5') && str_contains($itNarrowProblem, 'was not run') && migrationExitCodeForResult('apply', $itNarrowP) === 1, 'a hand-widened DECIMAL(20,2) column is refused at its step (5), exit code 1: ' . $itNarrowProblem);
itCheck('P 0006 money columns', itMoneyColumnTypes($itP, [['tblPayments', 'amount']]) === ['decimal(20,2)|NO|none'] && itNumber($itP, "SELECT COUNT(*) FROM `tblSchemaMigrations` WHERE `migrationKey` = '0006_three_decimal_money_columns'") === 0, '...the column is left as it was (never narrowed) and 0006 is not recorded');
itDropDb($itEnv, $itPort, $itDbP);

// P5. A column whose "may be empty" was changed by hand: refused too.
$itP = itAt0005($itEnv, $itPort, $itDbP, $itInstallSql, $itSeedSql, $itDir0005);
$itP->query('ALTER TABLE `tblDiscounts` MODIFY COLUMN `value` DECIMAL(10,2) NULL');
$itNullP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('P 0006 money columns', $itNullP['status'] === 'failed' && itMoneyColumnTypes($itP, [['tblDiscounts', 'value']]) === ['decimal(10,2)|YES|none'], 'a money column made NULL-able by hand is refused and left alone (0006 never changes whether a column may be empty): ' . json_encode([$itNullP['status'], itMoneyColumnTypes($itP, [['tblDiscounts', 'value']])]));
$itP->query('ALTER TABLE `tblDiscounts` MODIFY COLUMN `value` DECIMAL(10,2) NOT NULL');
$itFixedP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('P 0006 money columns', $itFixedP['status'] === 'ok' && itSchemaSnapshot($itP) === $itSchemaA, 'once the column is put right, the next run finishes and the result is the fresh install\'s');
itDropDb($itEnv, $itPort, $itDbP);

// P6. Third-round review, Low 3 A: a column already at three decimal places
//     but with only 10 whole digits (DECIMAL(13,3), set by hand) is NOT
//     "already done": its step runs and widens it to DECIMAL(15,3).
$itP = itAt0005($itEnv, $itPort, $itDbP, $itInstallSql, $itSeedSql, $itDir0005);
$itP->query('ALTER TABLE `tblPayments` MODIFY COLUMN `amount` DECIMAL(13,3) NOT NULL');
$itShortP = migrationRun($itP, 'apply', 'test', migrationDefaultDirectory(), migrationDefaultInstallSqlPath());
itCheck('P 0006 money columns', $itShortP['status'] === 'ok' && isset($itShortP['migrations'][0]['stepsRun']) && $itShortP['migrations'][0]['stepsRun'] === 6 && itMoneyColumnTypes($itP, [['tblPayments', 'amount']]) === ['decimal(15,3)|NO|none'] && itSchemaSnapshot($itP) === $itSchemaA, 'a column at DECIMAL(13,3) (three decimals, only 10 whole digits) is not skipped as done: its step runs and it becomes DECIMAL(15,3) (third-round review, Low 3 A): ' . json_encode([$itShortP['migrations'], itMoneyColumnTypes($itP, [['tblPayments', 'amount']])]));
itDropDb($itEnv, $itPort, $itDbP);

foreach ((array) glob($itDir0005 . '*') as $itTempFile) {
    @unlink((string) $itTempFile);
}
@rmdir($itDir0005);

// ==============================================================================
// 🧹 Clean up and report
// ==============================================================================
foreach ([$itDbA, $itDbB, $itDbF, $itDbG, $itDbH, $itDbI, $itDbJ, $itDbK1, $itDbK2, $itDbL, $itDbL2, $itDbM, $itDbN, $itDbN2, $itDbO, $itDbP] as $itDbToRemove) {
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
