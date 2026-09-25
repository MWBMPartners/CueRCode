<?php
/**
 * ==============================================================================
 * 📬 CueRCode - Email Queue Runner (scheduled job, command line only)
 * ==============================================================================
 *
 * Path: _includes/cron/process_email_queue.php
 *
 * Sends the emails waiting in tblEmailQueue (sign-up verification, password
 * reset and so on). queueEmail() only ever writes a row; before #112 nothing
 * sent those rows, so no sign-up could ever be verified. A DreamHost panel
 * cron job runs this file every minute:
 *
 *     /usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cron/process_email_queue.php --quiet
 *
 * (the PHP path and the menu names are not yet checked against the live
 * server - see DEV_NOTES.md, "Email queue runner"). It must be run with the
 * command-line `php`, not `./file`: the deploy does not keep execute
 * permissions.
 *
 * This file is only a thin wrapper. All of the logic - and its tests - live
 * in _functions/fn_email.php (emailQueueRunnerMain() and runEmailQueueBatch()),
 * which make sure two runs at the same time can never send the same email
 * twice.
 *
 * Usage:
 *     php process_email_queue.php [--quiet] [--limit=N]   send up to N emails (default 20, max 50)
 *     php process_email_queue.php --check                 test the set-up, send nothing
 *     php process_email_queue.php --help                  show the help
 *
 * Exit codes: 0 fine (including nothing to send, email switched off, another
 * run already working) | 1 could not start or crashed | 2 bad option |
 * 3 database problem (including a failed clean-up, #122) | 4 sending blocked by settings/Microsoft Graph, or an
 * earlier run looks stuck | 5 gave up on at least one email | 255 PHP itself
 * stopped the run part-way (a fatal error such as running out of memory; the
 * runner prints a one-line note and any email it was sending is recovered
 * after 10 minutes).
 *
 * 🔒 COMMAND LINE ONLY. Three layers keep it off the web:
 *   1. it lives in _includes/, next to (not inside) public_html/, so it has no
 *      web address at all;
 *   2. _includes/.htaccess denies all web access, and that applies to this
 *      cron/ subfolder too - which is why there is deliberately NO separate
 *      .htaccess here (two copies of the same rule would drift apart);
 *   3. the very first statement below refuses any run that is not the
 *      command-line PHP, before anything else is loaded.
 *
 * Its own output never includes an email address, a subject, an email body, a
 * one-time link, a secret or a setting value - only counts and plain-English
 * problems. If writing the activity log to the database fails mid-run, the
 * site's shared activity logger falls back to PHP's error log, which on the
 * command line is the terminal (so the cron email). Since #126 that fallback
 * line hides every email address ("[address]"); it still includes the
 * subject, which is fixed text for sign-up and password-reset emails.
 *
 * It also runs the queue clean-up (#122/#123) - see fn_email.php
 * runEmailQueueHousekeeping(): one-time links are removed once an email is
 * sent, emails whose link has expired are skipped, and old records are
 * deleted. That happens even while email sending is switched off.
 *
 * @package     CueRCode
 * @subpackage  Email
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-09-25 (#112)
 * @see         ../../_functions/fn_email.php - emailQueueRunnerMain(), runEmailQueueBatch()
 * @see         DEV_NOTES.md - "Email queue runner" (set-up steps, exit codes, re-send runbook)
 */

// ==============================================================================
// 🚫 Step 0: command line only - the FIRST statement, before loading anything
// ==============================================================================
// php-cgi (which a web server would use) also counts as "not the command
// line", on purpose: the cron job must use the command-line `php`.
// @see https://www.php.net/manual/en/reserved.constants.php (PHP_SAPI)
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// ==============================================================================
// 📦 Step 1: load ONLY the email functions for now
// ==============================================================================
// That lets --help and a bad option be answered on a machine with no database,
// before anything tries to connect. fn_email.php needs nothing else at load
// time, and the start-up code loads the same file again later, which is a
// harmless no-op (the same real path is only ever included once).
// ==============================================================================
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_email.php';

// ==============================================================================
// 🧾 Step 2: read the options
// ==============================================================================
// $_SERVER['argv'] rather than the global $argv, so a wrapper script (such as
// the verification check in the plan) can supply its own options.
// ==============================================================================
$arguments = [];

if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $arguments = $_SERVER['argv'];
}

$parsedArguments = parseEmailQueueRunnerArgs($arguments);

// ==============================================================================
// 📖 Step 3: a bad option, or --help, is answered here and nothing else runs
// ==============================================================================
// emailQueueRunnerMain() answers these two cases using only its output
// writers, so it is safe to call before the start-up code has loaded (it does
// not touch the database or the settings for them).
// ==============================================================================
if ($parsedArguments['ok'] !== true || $parsedArguments['help'] === true) {
    exit(emailQueueRunnerMain($parsedArguments, emailQueueProductionEnvironment()));
}

// ==============================================================================
// 🆘 Step 4: a helpful message if the start-up code stops before we are ready
// ==============================================================================
// _includes/db_connect.php calls exit(1) inside the start-up code when the
// database credentials file is missing. Nothing can catch that, so a shutdown
// function is the only way to say something useful. The exit code stays 1.
// ==============================================================================
//
// The same shutdown function also covers the opposite case: the run STARTED
// but never reached its own exit. That only happens when PHP itself stops the
// script with a fatal error (for example running out of memory), which is not
// an exception, so neither the try/catch in Step 8 nor the crash handler in
// Step 7 ever sees it. PHP then exits with code 255 and, on a host whose PHP
// error log goes to a file, the cron email would otherwise be EMPTY - silence
// that looks like success. CUERCODE_EMAIL_RUNNER_FINISHED is defined on every
// normal way out (Step 7 and Step 8), so this message only appears when the
// run really was cut short. (Found by the #112 independent verification,
// 2026-09-25: a 16 MB memory limit and a 6 MB email reproduced exit 255 with
// no message.)
// ==============================================================================
register_shutdown_function(static function (): void {
    if (!defined('CUERCODE_EMAIL_RUNNER_READY')) {
        fwrite(
            STDERR,
            'CueRCode email queue: could not start - the site start-up code stopped before the runner could run'
            . ' (usually a missing or unreadable _auth_keys/auth_creds.php). Check the PHP error log.' . PHP_EOL
        );
    } else {
        if (!defined('CUERCODE_EMAIL_RUNNER_FINISHED')) {
            fwrite(
                STDERR,
                'CueRCode email queue: stopped part-way by a PHP fatal error (for example running out of memory)'
                . ' - check the PHP error log. Any email it was sending is put back automatically after 10 minutes.' . PHP_EOL
            );
        }
    }
});

// ==============================================================================
// ⏱️ Step 5: sensible database timeouts, set BEFORE connecting
// ==============================================================================
// PHP's default database read timeout is 86400 seconds (24 hours): one hung
// read would hold the queue lock, and stop all email, for a whole day. These
// values only apply to connections opened after these lines, which is why
// they come before the start-up code.
// ==============================================================================
ini_set('mysqlnd.net_read_timeout', (string) CUERCODE_EMAIL_RUNNER_DB_READ_TIMEOUT_SECONDS);
ini_set('default_socket_timeout', (string) CUERCODE_EMAIL_RUNNER_CONNECT_TIMEOUT_SECONDS);

// ==============================================================================
// 🚀 Step 6: the normal site start-up code (database, settings, logging)
// ==============================================================================
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

// ==============================================================================
// 🧯 Step 7: a plain-text crash handler
// ==============================================================================
// This replaces the web crash handler from fn_error_handler.php, which prints
// an HTML "Server Error" page - useless (and confusing) in a cron email.
// ==============================================================================
set_exception_handler(static function (\Throwable $exception): void {
    fwrite(
        STDERR,
        'CueRCode email queue: stopped by an unexpected error (' . get_class($exception) . ') - see the error log.' . PHP_EOL
    );

    if (function_exists('logError')) {
        logError('critical', 'Email queue runner crashed', $exception->getMessage());
    }

    // 🏁 A normal (handled) way out - see the Step 4 shutdown function.
    if (!defined('CUERCODE_EMAIL_RUNNER_FINISHED')) {
        define('CUERCODE_EMAIL_RUNNER_FINISHED', true);
    }

    exit(CUERCODE_EMAIL_RUNNER_EXIT_CRASH);
});

// ✅ From here on the "could not start" message is no longer appropriate.
define('CUERCODE_EMAIL_RUNNER_READY', true);

// ==============================================================================
// 📬 Step 8: run, and exit with the runner's code
// ==============================================================================
try {
    $exitCode = emailQueueRunnerMain($parsedArguments, emailQueueProductionEnvironment());
} catch (\Throwable $exception) {
    fwrite(
        STDERR,
        'CueRCode email queue: stopped by an unexpected error (' . get_class($exception) . ') - see the error log.' . PHP_EOL
    );

    if (function_exists('logError')) {
        logError('critical', 'Email queue runner crashed', $exception->getMessage());
    }

    $exitCode = CUERCODE_EMAIL_RUNNER_EXIT_CRASH;
}

// 🏁 The run reached its own end - the Step 4 shutdown function must NOT print
//    the "stopped part-way" message. Defined here, immediately before exit(),
//    so that anything which kills the script before this line still gets it.
define('CUERCODE_EMAIL_RUNNER_FINISHED', true);

exit($exitCode);
