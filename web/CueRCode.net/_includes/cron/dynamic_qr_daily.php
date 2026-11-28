<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - The daily dynamic-code job (scheduled job, command line only)
 * ==============================================================================
 *
 * Path: _includes/cron/dynamic_qr_daily.php
 *
 * Run ONCE A DAY by a DreamHost panel cron job (WP4e, #168), for example at
 * 06:00, with the copy of the site it belongs to in front of it:
 *
 *     CUERCODE_SITE_COPY=live /usr/local/php84/bin/php /home/<user>/<path>/CueRCode.net/_includes/cron/dynamic_qr_daily.php --quiet
 *
 * What it does, in order:
 *   1. The link-safety re-check (dynamicQrRecheckSafety(),
 *      fn_dynamic_qr_takedown.php): every dynamic code in use is checked
 *      again with Google Web Risk, the oldest check first, at most 200 a run;
 *      a destination now listed as unsafe is suspended automatically and its
 *      owner and the abuse mailbox are emailed. (Running it more than once a
 *      day is harmless: a code checked in the last 20 hours is skipped.)
 *   2. Old link-safety records (tblLinkSafetyHits) are deleted after the
 *      keeping period (link_safety_hits_retention_days, 90 by default).
 *
 * Google and Go2My.Link are only called when the cron line declares which
 * copy of the site this is (CUERCODE_SITE_COPY=live, alpha or beta, #181):
 * alpha, beta and live share the same files and settings.
 *
 * Usage:
 *     php dynamic_qr_daily.php [--quiet]   run
 *     php dynamic_qr_daily.php --help      show the help
 *
 * Exit codes: 0 finished (the lines say what was skipped) | 1 could not start
 * or crashed | 2 bad option | 3 database problem | 4 another run is working.
 * Its printed output never contains a destination, an email address or a key
 * - only counts and plain-English reasons.
 *
 * 🔒 COMMAND LINE ONLY: it lives in _includes/ (no web address; denied by
 *    _includes/.htaccess), and the first statement refuses anything else.
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4e, #168)
 * @see         ../../_functions/fn_dynamic_qr_takedown.php
 * @see         ../../_functions/fn_url_reputation.php
 * @see         docs/INSTALL.md - the cron jobs
 */

// ==============================================================================
// 🚫 Step 0: command line only - the FIRST statement
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// ==============================================================================
// 🧾 Step 1: the options
// ==============================================================================
$dailyArguments = [];
if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $dailyArguments = array_slice($_SERVER['argv'], 1);
}
$dailyQuiet = false;
foreach ($dailyArguments as $dailyArgument) {
    if ($dailyArgument === '--quiet') {
        $dailyQuiet = true;
    } elseif ($dailyArgument === '--help') {
        fwrite(STDOUT, 'Usage: php dynamic_qr_daily.php [--quiet]' . PHP_EOL
            . 'The daily dynamic-code job: the link-safety re-check and the clean-up of old link-safety records.' . PHP_EOL
            . 'Start the cron line with CUERCODE_SITE_COPY=live (or alpha, or beta), or nothing is sent to Google or Go2My.Link.' . PHP_EOL);
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown option. Use --help.' . PHP_EOL);
        exit(2);
    }
}

// ==============================================================================
// ⏱️ Step 2: database timeouts before connecting, then the site start-up
// ==============================================================================
ini_set('mysqlnd.net_read_timeout', '60');
ini_set('default_socket_timeout', '10');

register_shutdown_function(static function (): void {
    if (!defined('CUERCODE_DYNAMIC_DAILY_FINISHED')) {
        fwrite(STDERR, 'CueRCode daily dynamic-code job: stopped before it finished (start-up problem or a PHP fatal error) - check the PHP error log.' . PHP_EOL);
    }
});

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_takedown.php';

set_exception_handler(static function (\Throwable $exception): void {
    fwrite(STDERR, 'CueRCode daily dynamic-code job: stopped by an unexpected error (' . get_class($exception) . ') - see the error log.' . PHP_EOL);
    if (function_exists('logError')) {
        logError('critical', 'Daily dynamic-code job crashed', get_class($exception));
    }
    if (!defined('CUERCODE_DYNAMIC_DAILY_FINISHED')) {
        define('CUERCODE_DYNAMIC_DAILY_FINISHED', true);
    }
    exit(1);
});

// ==============================================================================
// 🔒 Step 3: one run at a time (a MySQL session lock, released at the end)
// ==============================================================================
$dailyLocked = false;
try {
    $dailyLockStmt = dbQuery('SELECT GET_LOCK(?, 0) AS `lockResult`', 's', ['cuercode_dynamic_daily']);
    $dailyLockRow  = $dailyLockStmt->get_result()->fetch_assoc();
    $dailyLockStmt->close();
    $dailyLocked = (is_array($dailyLockRow) && (int) $dailyLockRow['lockResult'] === 1);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'CueRCode daily dynamic-code job: the database could not be reached.' . PHP_EOL);
    define('CUERCODE_DYNAMIC_DAILY_FINISHED', true);
    exit(3);
}
if (!$dailyLocked) {
    fwrite(STDERR, 'CueRCode daily dynamic-code job: another run is still working; nothing was done.' . PHP_EOL);
    define('CUERCODE_DYNAMIC_DAILY_FINISHED', true);
    exit(4);
}

$dailyExit     = 0;
$dailyProblems = [];
$dailyLines    = [];

// ==============================================================================
// 🛡️ Step 4: the link-safety re-check
// ==============================================================================
$dailyRecheck = dynamicQrRecheckSafety();
foreach (dynamicQrRecheckLines($dailyRecheck) as $dailyLine) {
    if ($dailyRecheck['ok'] !== true || $dailyRecheck['skipped'] === 'copy_unknown' || $dailyRecheck['skipped'] === 'not_configured' || $dailyRecheck['stoppedEarly'] === true) {
        $dailyProblems[] = $dailyLine;
    } else {
        $dailyLines[] = $dailyLine;
    }
}
if ($dailyRecheck['ok'] !== true) {
    $dailyExit = 3;
}

// ==============================================================================
// 🧹 Step 5: old link-safety records
// ==============================================================================
$dailyPurged = urlReputationPurgeOldHits(urlReputationHitsRetentionDays(), 1000);
if ($dailyPurged === null) {
    $dailyProblems[] = 'Link-safety records: the old ones could not be deleted (database problem, see the error log).';
    $dailyExit = 3;
} elseif ($dailyPurged > 0) {
    $dailyLines[] = 'Link-safety records: deleted ' . $dailyPurged . ' older than ' . urlReputationHitsRetentionDays() . ' days.';
}

// ==============================================================================
// 🏁 Step 6: let the lock go, say what happened, exit
// ==============================================================================
try {
    $dailyReleaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', ['cuercode_dynamic_daily']);
    $dailyReleaseStmt->close();
} catch (\Throwable $exception) {
    // The lock belongs to this connection and ends with it anyway.
}

foreach ($dailyProblems as $dailyLine) {
    fwrite(STDERR, $dailyLine . PHP_EOL);
}
if (!$dailyQuiet) {
    foreach ($dailyLines as $dailyLine) {
        fwrite(STDOUT, $dailyLine . PHP_EOL);
    }
}

define('CUERCODE_DYNAMIC_DAILY_FINISHED', true);
exit($dailyExit);
