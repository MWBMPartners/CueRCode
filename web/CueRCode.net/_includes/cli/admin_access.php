<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - Global Admin access tool (command line only)
 * ==============================================================================
 *
 * Path: _includes/cli/admin_access.php
 *
 * The safe way to make the FIRST Global Admin (plan §6.2). No web page can do
 * that - a "first visitor becomes admin" page would let a stranger claim a
 * fresh site before the owner does.
 *
 *     php /home/<user>/<path>/CueRCode.net/_includes/cli/admin_access.php grant owner@example.com --activate
 *     php /home/<user>/<path>/CueRCode.net/_includes/cli/admin_access.php list
 *     php /home/<user>/<path>/CueRCode.net/_includes/cli/admin_access.php revoke someone@example.com
 *
 * (On DreamHost the command-line PHP is typically /usr/local/php84/bin/php -
 * the same pattern as the email queue runner; not yet checked against the
 * live server.) Use the command-line `php`, not `./admin_access.php`: the
 * deploy does not keep execute permissions.
 *
 * What `grant` checks before changing anything:
 *   - exactly ONE account has that email, and it is 'pending' or 'active'
 *     (never 'suspended' or 'deleted');
 *   - the account has a password (a Global Admin re-types it before every
 *     change, so a Microsoft/Google-only account cannot be one yet);
 *   - you type the email address again (skipped with --yes).
 * --activate also marks the account active with a checked email address.
 * That is allowed here because whoever can run commands on the server
 * already controls the site - and it solves the chicken-and-egg problem of
 * the first admin, who cannot receive a verification email before email
 * sending has been set up in the admin area.
 *
 * The role change and its audit row (tblAdminAuditLog, action admin.grant /
 * admin.revoke, actor "cli:<operating-system user>") are written in ONE
 * transaction. `revoke` refuses to remove the last working Global Admin.
 * `list` shows email addresses partly hidden.
 *
 * Exit codes: 0 done (or nothing needed doing) | 1 failed (database problem;
 * nothing was half-done - run it again) | 2 refused (see the message; nothing
 * was changed).
 *
 * The logic and its tests live in _functions/fn_admin.php (adminCliParseArgs(),
 * adminGrantDecision(), adminRevokeDecision(), adminCliGrant(), adminCliRevoke())
 * - this file is only the wrapper.
 *
 * 🔒 COMMAND LINE ONLY, three layers (same as migrate.php and the email
 *    queue runner): it lives in _includes/, outside public_html/, so it has
 *    no web address; _includes/.htaccess denies all web access (that covers
 *    this cli/ folder too); and the FIRST statement below refuses anything
 *    that is not the command-line PHP.
 *
 * 🗄️ It loads the normal site start-up code (bootstrap.php), like the email
 *    queue runner, because it needs the settings, the error log and the
 *    same database connection rules as the site.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         ../../_functions/fn_admin.php - every decision this tool makes
 * @see         ../cron/process_email_queue.php - the command-line guard and start-up pattern copied here
 * @see         DEV_NOTES.md - "First Global Admin"
 * @see         .claude/plans/7n-admin-commerce-plan.md §6.2
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
// 📦 Step 1: load ONLY the admin functions for now
// ==============================================================================
// So --help and a bad option are answered on a machine with no database.
// Loading fn_admin.php defines functions and constants only.
// ==============================================================================
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';

// ==============================================================================
// 🧾 Step 2: read the options ($_SERVER['argv'], so a wrapper can supply them)
// ==============================================================================
$adminCliArguments = [];

if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $adminCliArguments = $_SERVER['argv'];
}

$adminCliParsed = adminCliParseArgs($adminCliArguments);

// ==============================================================================
// 📖 Step 3: a bad option, or --help, is answered here and nothing else runs
// ==============================================================================
if ($adminCliParsed['ok'] !== true) {
    fwrite(STDERR, $adminCliParsed['error'] . PHP_EOL . PHP_EOL . implode(PHP_EOL, adminCliUsage()) . PHP_EOL);
    exit(CUERCODE_ADMIN_CLI_EXIT_REFUSED);
}

if ($adminCliParsed['command'] === 'help') {
    fwrite(STDOUT, implode(PHP_EOL, adminCliUsage()) . PHP_EOL);
    exit(CUERCODE_ADMIN_CLI_EXIT_OK);
}

// ==============================================================================
// 🆘 Step 4: a helpful message if the start-up code stops before we are ready
// ==============================================================================
// db_connect.php calls exit(1) when the credentials file is missing; nothing
// can catch that, so a shutdown function says something useful instead.
// ==============================================================================
register_shutdown_function(static function (): void {
    if (!defined('CUERCODE_ADMIN_CLI_READY')) {
        fwrite(
            STDERR,
            'CueRCode admin access: could not start - the site start-up code stopped first'
            . ' (usually a missing or unreadable _auth_keys/auth_creds.php). Check the PHP error log.' . PHP_EOL
        );
    } elseif (!defined('CUERCODE_ADMIN_CLI_FINISHED')) {
        fwrite(
            STDERR,
            'CueRCode admin access: stopped part-way by a PHP fatal error - check the PHP error log.'
            . ' Changes are made in one transaction, so nothing was half-done.' . PHP_EOL
        );
    }
});

// ==============================================================================
// ⏱️ Step 5: sensible database timeouts, set BEFORE connecting
// ==============================================================================
ini_set('mysqlnd.net_read_timeout', '60');
ini_set('default_socket_timeout', '15');

// ==============================================================================
// 🚀 Step 6: the normal site start-up code (database, settings, logging)
// ==============================================================================
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

// 🧯 Plain-text crash handler (the web one prints an HTML error page).
set_exception_handler(static function (\Throwable $exception): void {
    fwrite(STDERR, 'CueRCode admin access: stopped by an unexpected error (' . get_class($exception) . ') - see the error log.' . PHP_EOL);

    if (function_exists('logError')) {
        logError('critical', 'Admin access command crashed', get_class($exception));
    }

    if (!defined('CUERCODE_ADMIN_CLI_FINISHED')) {
        define('CUERCODE_ADMIN_CLI_FINISHED', true);
    }

    exit(CUERCODE_ADMIN_CLI_EXIT_FAILED);
});

define('CUERCODE_ADMIN_CLI_READY', true);

/**
 * 🏁 Print the outcome and exit with its code.
 *
 * @param array{exitCode: int, message: string, warnings: array<int, string>} $adminCliOutcome
 * @return never
 */
function adminCliFinish(array $adminCliOutcome): never
{
    foreach ($adminCliOutcome['warnings'] as $adminCliWarning) {
        fwrite(STDERR, 'Note: ' . $adminCliWarning . PHP_EOL);
    }

    if ($adminCliOutcome['exitCode'] === CUERCODE_ADMIN_CLI_EXIT_OK) {
        fwrite(STDOUT, $adminCliOutcome['message'] . PHP_EOL);
    } else {
        fwrite(STDERR, $adminCliOutcome['message'] . PHP_EOL);
    }

    define('CUERCODE_ADMIN_CLI_FINISHED', true);
    exit($adminCliOutcome['exitCode']);
}

// ==============================================================================
// 🗄️ Step 7: the database must have its admin change log (migration 0002)
// ==============================================================================
// Every grant and revoke writes an audit row in the same transaction. Without
// the table that is impossible, so the tool refuses rather than change a role
// with no record of it.
// ==============================================================================
if (!function_exists('dbQuery')) {
    adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'The database could not be reached.', 'warnings' => []]);
}

if (!adminTableExists('tblAdminAuditLog')) {
    adminCliFinish([
        'exitCode' => CUERCODE_ADMIN_CLI_EXIT_REFUSED,
        'message'  => 'The database is missing its admin change log (tblAdminAuditLog). Run "php _includes/cli/migrate.php --apply" first, then run this again.',
        'warnings' => [],
    ]);
}

// ==============================================================================
// 📋 Step 8: list
// ==============================================================================
if ($adminCliParsed['command'] === 'list') {
    $adminCliRows = adminCliListAdmins();

    if ($adminCliRows === null) {
        adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'The list could not be read from the database.', 'warnings' => []]);
    }

    if ($adminCliRows === []) {
        adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_OK, 'message' => 'There are no Global Admins yet. Use "grant" to make one.', 'warnings' => []]);
    }

    $adminCliLines = ['Global Admins:'];
    $adminCliWorking = 0;
    foreach ($adminCliRows as $adminCliRow) {
        $adminCliVerified = ($adminCliRow['emailVerifiedAt'] !== null);
        $adminCliState = 'working';
        if ((string) $adminCliRow['status'] !== 'active' || !$adminCliVerified) {
            $adminCliState = 'cannot use the admin area (account ' . (string) $adminCliRow['status'];
            if (!$adminCliVerified) {
                $adminCliState .= ', email not checked';
            }
            $adminCliState .= ')';
        } else {
            $adminCliWorking++;
        }
        $adminCliLines[] = '  #' . (int) $adminCliRow['userID'] . '  ' . adminMaskEmail((string) $adminCliRow['email']) . '  ' . $adminCliState;
    }
    $adminCliLines[] = $adminCliWorking . ' working Global Admin(s).';

    adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_OK, 'message' => implode(PHP_EOL, $adminCliLines), 'warnings' => []]);
}

// ==============================================================================
// 🔎 Step 9: grant / revoke - look first, say what will happen, ask to confirm
// ==============================================================================
$adminCliEmail = $adminCliParsed['email'];
$adminCliRows  = adminCliFindAccounts($adminCliEmail, false);

if ($adminCliRows === null) {
    adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_FAILED, 'message' => 'The account could not be looked up.', 'warnings' => []]);
}

if ($adminCliParsed['command'] === 'grant') {
    $adminCliPreview = adminGrantDecision($adminCliRows, $adminCliParsed['activate']);
} else {
    $adminCliPreview = adminRevokeDecision($adminCliRows, adminCountGlobalAdmins());
}

if ($adminCliPreview['outcome'] === 'refuse') {
    adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_REFUSED, 'message' => $adminCliPreview['message'], 'warnings' => []]);
}

if ($adminCliPreview['outcome'] === 'nothing') {
    $adminCliPreviewWarnings = [];
    if (isset($adminCliPreview['warnings'])) {
        $adminCliPreviewWarnings = $adminCliPreview['warnings'];
    }
    adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_OK, 'message' => $adminCliPreview['message'], 'warnings' => $adminCliPreviewWarnings]);
}

fwrite(STDOUT, 'Account #' . (int) $adminCliRows[0]['userID'] . ' (' . $adminCliEmail . '): ' . $adminCliPreview['message'] . PHP_EOL);

if ($adminCliParsed['yes'] !== true) {
    fwrite(STDOUT, 'Type the email address again to confirm: ');
    $adminCliTyped = fgets(STDIN);

    if (!adminCliConfirmationMatches($adminCliTyped, $adminCliEmail)) {
        adminCliFinish(['exitCode' => CUERCODE_ADMIN_CLI_EXIT_REFUSED, 'message' => 'The email address did not match, so nothing was changed.', 'warnings' => []]);
    }
}

// ==============================================================================
// ✍️ Step 10: make the change (the decision is re-made inside the transaction)
// ==============================================================================
if ($adminCliParsed['command'] === 'grant') {
    adminCliFinish(adminCliGrant($adminCliEmail, $adminCliParsed['activate'], adminCliActorLabel()));
}

adminCliFinish(adminCliRevoke($adminCliEmail, adminCliActorLabel()));
