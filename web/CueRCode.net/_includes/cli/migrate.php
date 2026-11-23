<?php
/**
 * ==============================================================================
 * 🧱 CueRCode - Database Upgrade Tool (command line only)
 * ==============================================================================
 *
 * Path: _includes/cli/migrate.php
 *
 * Applies the numbered database upgrades ("migrations") in
 * _includes/sql/migrations/ to the site's database. Run it after every
 * deploy that adds a migration file (the web installer runs the same engine
 * on a brand-new install):
 *
 *     /usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cli/migrate.php --status
 *     /usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cli/migrate.php --apply
 *
 * (the PHP path is the DreamHost pattern used for the email queue runner; it
 * is not yet checked against the live server). Use the command-line `php`,
 * not `./migrate.php`: the deploy does not keep execute permissions.
 *
 * Usage:
 *     php migrate.php --status    list applied and waiting upgrades (exit 3 if any wait)
 *     php migrate.php --dry-run   show what --apply would do; change nothing
 *     php migrate.php --apply     apply every waiting upgrade, in number order
 *     php migrate.php --help      show the help
 *
 * Exit codes (plan §5.1): 0 up to date / applied / dry run done | 1 a step or
 * the database failed (fix it and run again - every step is safe to repeat) |
 * 2 refused, no upgrades applied (not the command line, another upgrade
 * running, a database older than the supported starting point, a broken
 * migration file, or a bad option) | 3 --status found upgrades waiting.
 *
 * This file is only a thin wrapper. All of the logic - and its tests - live
 * in _functions/fn_migrations.php.
 *
 * 🔒 COMMAND LINE ONLY. Three layers keep it off the web (same as
 *    _includes/cron/process_email_queue.php):
 *   1. it lives in _includes/, next to (not inside) public_html/, so it has no
 *      web address at all;
 *   2. _includes/.htaccess denies all web access, and that applies to this
 *      cli/ subfolder too - so there is deliberately NO separate .htaccess
 *      here (two copies of the same rule would drift apart);
 *   3. the very first statement below refuses any run that is not the
 *      command-line PHP, before anything else is loaded.
 *
 * 🗄️ DATABASE CONNECTION: it loads ONLY _includes/db_connect.php - the same
 *    credentials files (_auth_keys/auth_creds.php, site first, then
 *    server-wide) and the same connection settings (utf8mb4, strict SQL mode,
 *    UTC) as the rest of the site. It deliberately does NOT load the full
 *    start-up code (bootstrap.php) that the email runner uses, because that
 *    reads settings from the database - and this tool must work on a
 *    database that is still missing tables.
 *
 * Its output never includes a password, a secret or a setting value - only
 * migration names, counts and plain-English problems.
 *
 * @package     CueRCode
 * @subpackage  Database
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-23 (task 7n Phase 1A, #113 item 5)
 * @see         ../../_functions/fn_migrations.php - migrationApplyPending(), migrationStatus()
 * @see         ../cron/process_email_queue.php - the command-line guard pattern copied here
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1
 */

// ==============================================================================
// 🚫 Step 0: command line only - the FIRST statement, before loading anything
// ==============================================================================
// php-cgi (which a web server would use) also counts as "not the command
// line", on purpose.
// @see https://www.php.net/manual/en/reserved.constants.php (PHP_SAPI)
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// ==============================================================================
// 📦 Step 1: load ONLY the upgrade functions for now
// ==============================================================================
// So --help and a bad option are answered on a machine with no database,
// before anything tries to connect.
// ==============================================================================
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';

// ==============================================================================
// 🧾 Step 2: read the options
// ==============================================================================
// $_SERVER['argv'] rather than the global $argv, so a wrapper script can
// supply its own options (same as the email queue runner).
// ==============================================================================
$migrateArguments = [];

if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $migrateArguments = $_SERVER['argv'];
}

$migrateParsed = migrationParseCliArgs($migrateArguments);

// ==============================================================================
// 📖 Step 3: a bad option, or --help, is answered here and nothing else runs
// ==============================================================================
if ($migrateParsed['ok'] !== true) {
    fwrite(STDERR, $migrateParsed['error'] . PHP_EOL . PHP_EOL . implode(PHP_EOL, migrationCliUsage()) . PHP_EOL);
    exit(CUERCODE_MIGRATE_EXIT_REFUSED);
}

if ($migrateParsed['mode'] === 'help') {
    fwrite(STDOUT, implode(PHP_EOL, migrationCliUsage()) . PHP_EOL);
    exit(CUERCODE_MIGRATE_EXIT_OK);
}

// ==============================================================================
// 🆘 Step 4: a helpful message if the database code stops before we are ready
// ==============================================================================
// _includes/db_connect.php calls exit(1) when the credentials file is
// missing. Nothing can catch that, so a shutdown function is the only way to
// say something useful. It also covers a PHP fatal error part-way through
// (for example running out of memory): every step is safe to repeat, so the
// message says to run the tool again.
// ==============================================================================
register_shutdown_function(static function (): void {
    if (!defined('CUERCODE_MIGRATE_READY')) {
        fwrite(
            STDERR,
            'CueRCode upgrades: could not start - the database settings could not be loaded'
            . ' (usually a missing or unreadable _auth_keys/auth_creds.php). Check the PHP error log.' . PHP_EOL
        );
    } else {
        if (!defined('CUERCODE_MIGRATE_FINISHED')) {
            fwrite(
                STDERR,
                'CueRCode upgrades: stopped part-way by a PHP fatal error - check the PHP error log,'
                . ' then run --apply again (every step is safe to repeat).' . PHP_EOL
            );
        }
    }
});

// ==============================================================================
// ⏱️ Step 5: sensible database timeouts, set BEFORE connecting
// ==============================================================================
// PHP's default read timeout is 24 hours; a hung read would hold the upgrade
// lock all that time. Ten minutes is far more than any of today's small
// tables need. These only apply to connections opened after these lines.
// ==============================================================================
ini_set('mysqlnd.net_read_timeout', '600');
ini_set('default_socket_timeout', '15');

// ==============================================================================
// 🗄️ Step 6: connect, the same way the site does (see the file header)
// ==============================================================================
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db_connect.php';

// ✅ From here on the "could not start" message is no longer appropriate.
define('CUERCODE_MIGRATE_READY', true);

try {
    $migrateDb = getDB();
} catch (\Throwable $migrateConnectError) {
    // The real reason (host, user) is already in the PHP error log via
    // getDB(); it is not repeated here.
    fwrite(STDERR, 'CueRCode upgrades: could not connect to the database - check the PHP error log.' . PHP_EOL);
    define('CUERCODE_MIGRATE_FINISHED', true);
    exit(CUERCODE_MIGRATE_EXIT_FAILED);
}

// ==============================================================================
// 👤 Step 7: who is running it - "cli:<operating-system user>"
// ==============================================================================
$migrateOsUser = '';

if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $migrateUserInfo = posix_getpwuid(posix_geteuid());
    if (is_array($migrateUserInfo) && isset($migrateUserInfo['name'])) {
        $migrateOsUser = (string) $migrateUserInfo['name'];
    }
}

if ($migrateOsUser === '') {
    $migrateEnvUser = getenv('USER');
    if (is_string($migrateEnvUser)) {
        $migrateOsUser = $migrateEnvUser;
    }
}

if ($migrateOsUser === '') {
    $migrateOsUser = 'unknown';
}

// ==============================================================================
// 🚀 Step 8: run, print the report, exit with the right code
// ==============================================================================
try {
    if ($migrateParsed['mode'] === 'status') {
        $migrateResult = migrationStatus($migrateDb);
    } elseif ($migrateParsed['mode'] === 'dry-run') {
        $migrateResult = migrationApplyPending($migrateDb, 'cli:' . $migrateOsUser, true);
    } else {
        $migrateResult = migrationApplyPending($migrateDb, 'cli:' . $migrateOsUser, false);
    }

    $migrateExitCode = migrationExitCodeForResult($migrateParsed['mode'], $migrateResult);
    $migrateLines    = migrationFormatReport($migrateParsed['mode'], $migrateResult);

    if ($migrateExitCode === CUERCODE_MIGRATE_EXIT_OK || $migrateExitCode === CUERCODE_MIGRATE_EXIT_PENDING) {
        fwrite(STDOUT, implode(PHP_EOL, $migrateLines) . PHP_EOL);
    } else {
        fwrite(STDERR, implode(PHP_EOL, $migrateLines) . PHP_EOL);
    }
} catch (\Throwable $migrateUnexpected) {
    fwrite(
        STDERR,
        'CueRCode upgrades: stopped by an unexpected error (' . get_class($migrateUnexpected) . ').'
        . ' Run --apply again after fixing it (every step is safe to repeat).' . PHP_EOL
    );
    $migrateExitCode = CUERCODE_MIGRATE_EXIT_FAILED;
}

// 🏁 The run reached its own end - the Step 4 message must not appear.
define('CUERCODE_MIGRATE_FINISHED', true);

exit($migrateExitCode);
