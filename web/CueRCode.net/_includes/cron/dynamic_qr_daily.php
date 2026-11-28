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
 *   3. The comparison with Go2My.Link (WP4g, #170, lead choice L5): every
 *      working or suspended code is looked up (at most 500), differences are
 *      noted, scan totals collected, and a suspended code Go2My.Link points
 *      elsewhere has its takedown sent again by the every-minute job.
 *   4. The daily monitoring email to every Global Admin and the abuse
 *      mailbox (dynamicQrMonitorReport(), fn_dynamic_qr_monitor.php): new and
 *      changed destinations, the busiest codes, link-safety hits, reports,
 *      suspensions and the differences. Sent even when nothing happened, at
 *      most once per 20 hours (--force sends again).
 *
 * Google and Go2My.Link are only called when the cron line declares which
 * copy of the site this is (CUERCODE_SITE_COPY=live, alpha or beta, #181):
 * alpha, beta and live share the same files and settings.
 *
 * Usage:
 *     php dynamic_qr_daily.php [--quiet] [--force]   run (--force: send the email even if one went in the last 20 hours)
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
$dailyForce = false;
foreach ($dailyArguments as $dailyArgument) {
    if ($dailyArgument === '--quiet') {
        $dailyQuiet = true;
    } elseif ($dailyArgument === '--force') {
        $dailyForce = true;
    } elseif ($dailyArgument === '--help') {
        fwrite(STDOUT, 'Usage: php dynamic_qr_daily.php [--quiet] [--force]' . PHP_EOL
            . 'The daily dynamic-code job: the link-safety re-check, the clean-up of old link-safety records,' . PHP_EOL
            . 'the comparison with Go2My.Link and the daily monitoring email (--force sends it even if one went in the last 20 hours).' . PHP_EOL
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
require_once CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_monitor.php';

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
// 🔍 Step 6: the comparison with Go2My.Link, then the daily email (WP4g, #170)
// ==============================================================================
$dailyCompare = dynamicQrCompareWithGo2MyLink();
if ($dailyCompare['ok'] !== true) {
    $dailyProblems[] = 'Go2My.Link comparison: the codes could not be read (database problem, see the error log).';
    $dailyExit = 3;
} elseif ($dailyCompare['skipped'] === 'copy_unknown') {
    $dailyProblems[] = 'Go2My.Link comparison: NOT run, because this run did not say which copy of the site it is. Add CUERCODE_SITE_COPY=live (or alpha, or beta) in front of the cron command.';
} elseif ($dailyCompare['skipped'] !== '' && $dailyCompare['skipped'] !== 'upgrade_waiting') {
    $dailyLines[] = 'Go2My.Link comparison: not run (' . $dailyCompare['skipped'] . ').';
} else {
    $dailyLines[] = 'Go2My.Link comparison: compared ' . (int) $dailyCompare['compared'] . ' code(s), ' . count($dailyCompare['differences']) . ' difference(s).';
    if ($dailyCompare['stoppedEarly'] === true) {
        $dailyProblems[] = 'Go2My.Link comparison: stopped early - Go2My.Link could not answer ' . CUERCODE_MONITOR_COMPARE_STOP_AFTER . ' times in a row.';
    }
}

$dailyReport = dynamicQrMonitorReport(['recheck' => $dailyRecheck, 'compare' => $dailyCompare, 'copy' => dynamicQrCronCopyProblem([])]);
if ($dailyReport['ok'] !== true) {
    $dailyExit = 3;
}
$dailySend = dynamicQrMonitorSend($dailyReport, $dailyForce);
if ($dailySend['skipped'] === 'no_recipients') {
    $dailyProblems[] = 'Daily email: nobody to send it to (no active Global Admin and no valid abuse mailbox).';
} elseif ($dailySend['skipped'] === 'sent_recently') {
    $dailyLines[] = 'Daily email: one was already sent in the last 20 hours (use --force to send another).';
} elseif ($dailySend['sent'] === 0) {
    $dailyProblems[] = 'Daily email: it could not be queued (see the error log).';
    $dailyExit = 3;
} else {
    $dailyLines[] = 'Daily email: queued for ' . (int) $dailySend['sent'] . ' recipient(s) (the email job sends it).';
}

// ==============================================================================
// 🏁 Step 7: let the lock go, say what happened, exit
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
