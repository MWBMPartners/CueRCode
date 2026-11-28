<?php
/**
 * ==============================================================================
 * 🛑 CueRCode - Supervisor that keeps a test web server tied to its test script
 * ==============================================================================
 *
 * Path: tests/lib/web_server_supervisor.php
 *
 * WHY (plain English):
 *   A real-database script that starts PHP's built-in web server used to stop
 *   it only when it reached its last scenarios. If a check failed early, the
 *   script crashed, or someone pressed Ctrl+C or killed it, the server kept
 *   running on its own (three such servers were found). This small process
 *   sits between the test script and the server:
 *     - it starts the server (php -S ...) as its own child;
 *     - it watches its standard input, a pipe from the test script. The pipe
 *       closes when the test script closes it, ends, crashes or is killed -
 *       even with kill -9, because the operating system closes it then;
 *     - as soon as the pipe closes (or it is asked to stop by a signal), it
 *       stops the server (politely, then by force after 5 seconds), waits for
 *       it, and ends.
 *   tests/lib/web_server.php starts it and is what the scripts call.
 *
 * Usage: php tests/lib/web_server_supervisor.php -- <arguments for php>
 *        (everything after "--" is passed to PHP_BINARY, for example
 *        -S 127.0.0.1:8123 -t public_html router.php)
 *
 * Exit codes: 0 the server was stopped | 1 the server could not be started |
 *             2 bad usage.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (orphaned test web servers)
 * @see         tests/lib/web_server.php
 * @see         tests/test_integration_web_server.php
 */

// 🚫 Command line only.
if (PHP_SAPI !== 'cli') {
    exit('Access denied.');
}

// ==============================================================================
// 📋 The server's arguments (everything after "--")
// ==============================================================================
$supervisorArgs = array_slice($argv, 1);
if (!isset($supervisorArgs[0]) || $supervisorArgs[0] !== '--' || count($supervisorArgs) < 2) {
    fwrite(STDERR, 'Usage: php web_server_supervisor.php -- <arguments for php>' . PHP_EOL);
    exit(2);
}
$supervisorServerArgs = array_slice($supervisorArgs, 1);

// ==============================================================================
// 🚀 Start the server (its own input is empty; its output is ours)
// ==============================================================================
$supervisorPipes  = [];
$supervisorServer = proc_open(
    array_merge([PHP_BINARY], $supervisorServerArgs),
    [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
    $supervisorPipes
);
if (!is_resource($supervisorServer)) {
    fwrite(STDERR, 'The test web server could not be started.' . PHP_EOL);
    exit(1);
}

// 🔔 A stop signal (Ctrl+C, kill) ends the watch below, so the server is
//    still stopped. Without pcntl the pipe closing does the same job.
$supervisorStop = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $supervisorSignal) {
        pcntl_signal($supervisorSignal, static function () use (&$supervisorStop): void {
            $supervisorStop = true;
        });
    }
}

// ==============================================================================
// 👀 Watch the pipe from the test script, and the server itself
// ==============================================================================
stream_set_blocking(STDIN, false);
while ($supervisorStop === false) {
    $supervisorStatus = proc_get_status($supervisorServer);
    if ($supervisorStatus['running'] !== true) {
        // 💥 The server ended on its own; nothing left to stop.
        break;
    }

    $supervisorRead   = [STDIN];
    $supervisorWrite  = null;
    $supervisorExcept = null;
    $supervisorReady  = @stream_select($supervisorRead, $supervisorWrite, $supervisorExcept, 0, 200000);
    if ($supervisorReady === false) {
        // 🔔 Interrupted by a signal: the loop condition decides.
        continue;
    }
    if ($supervisorReady > 0) {
        $supervisorChunk = fread(STDIN, 8192);
        if (($supervisorChunk === '' || $supervisorChunk === false) && feof(STDIN)) {
            // 🚪 The test script closed the pipe, ended or was killed.
            break;
        }
    }
}

// ==============================================================================
// 🛑 Stop the server: politely, then by force, and wait for it
// ==============================================================================
$supervisorStatus = proc_get_status($supervisorServer);
if ($supervisorStatus['running'] === true) {
    proc_terminate($supervisorServer, 15);
    $supervisorDeadline = microtime(true) + 5;
    while (proc_get_status($supervisorServer)['running'] === true && microtime(true) < $supervisorDeadline) {
        usleep(50000);
    }
    if (proc_get_status($supervisorServer)['running'] === true) {
        proc_terminate($supervisorServer, 9);
    }
}
proc_close($supervisorServer);
exit(0);
