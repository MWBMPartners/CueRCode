<?php
/**
 * ==============================================================================
 * 🌐 CueRCode - Start a test web server that is ALWAYS stopped afterwards
 * ==============================================================================
 *
 * Path: tests/lib/web_server.php
 *
 * WHY (plain English):
 *   The real-database scripts that need real HTTP start PHP's built-in web
 *   server. It must be stopped however the script ends: normally, at exit(),
 *   when a check fails, on a fatal error or an uncaught exception, on Ctrl+C,
 *   and when the script is killed outright. Three servers left running by
 *   earlier runs were found.
 *
 *   itWebServerStart() runs the server under tests/lib/web_server_supervisor.php,
 *   which stops it the moment the pipe from this script closes. That pipe
 *   closes on itWebServerStop(), at the end of the script (a shutdown
 *   function is registered), and when this process dies in any way at all -
 *   the operating system closes it, even for kill -9. Ctrl+C and kill (SIGINT,
 *   SIGTERM, SIGHUP) are also turned into a normal exit when pcntl is there,
 *   so the shutdown function runs and waits for the server to stop.
 *
 *   Fourth-round review, Info 1: if the SUPERVISOR itself dies (kill -9) or
 *   has to be killed after the 10-second wait, nothing would stop the server.
 *   So the supervisor tells this script the server's own process number (on
 *   a third pipe), and itWebServerStop() stops the server by that number
 *   when it is still answering on its address. (A process number is only
 *   used while its server still answers, so it cannot belong to another
 *   program by then.)
 *
 * Loading this file defines functions only.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (orphaned test web servers)
 * @see         tests/lib/web_server_supervisor.php
 * @see         tests/test_integration_web_server.php - proves every way of ending
 */

/**
 * 🚀 Start PHP's built-in web server under the supervisor.
 *
 * @param array<int, string>    $phpArgs    Arguments for PHP_BINARY, for example
 *                                          ['-S', '127.0.0.1:8123', '-t', $root, $router]
 * @param string                $outputFile Where the server's own output goes (appended)
 * @param array<string, string> $env        The server's environment
 * @return array{process: resource, stdin: resource, serverPid: int, address: string}|null Null when it could not start
 */
function itWebServerStart(array $phpArgs, string $outputFile, array $env): ?array
{
    $pipes   = [];
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'web_server_supervisor.php', '--'], $phpArgs),
        [0 => ['pipe', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a'], 3 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($process)) {
        return null;
    }

    // 🔢 Info 1: the server's own process number, from the supervisor
    //    ("pid N" on pipe 3; waited for up to 5 seconds).
    $serverPid = 0;
    $read      = [$pipes[3]];
    $write     = null;
    $except    = null;
    if (@stream_select($read, $write, $except, 5) === 1) {
        $pidLine = (string) fgets($pipes[3]);
        if (preg_match('/^pid (\d+)$/', trim($pidLine), $pidMatch) === 1) {
            $serverPid = (int) $pidMatch[1];
        }
    }
    fclose($pipes[3]);

    // 🌐 The address the server listens on (the value after -S).
    $address  = '';
    $sPosition = array_search('-S', $phpArgs, true);
    if ($sPosition !== false && isset($phpArgs[$sPosition + 1])) {
        $address = (string) $phpArgs[$sPosition + 1];
    }

    $server = ['process' => $process, 'stdin' => $pipes[0], 'serverPid' => $serverPid, 'address' => $address];

    // 🧹 Always stopped at the end of the script (exit(), a failed check,
    //    a fatal error or an uncaught exception all run shutdown functions).
    register_shutdown_function('itWebServerStop', $server);

    // 🔔 Ctrl+C and kill become an ordinary exit, so the shutdown function
    //    runs. (kill -9 cannot be caught; then the closed pipe does the work.)
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, static function (int $caught): void {
                exit(128 + $caught);
            });
        }
    }

    return $server;
}

/**
 * 🛑 Stop the server: close the pipe (the supervisor then stops the server)
 *    and wait up to 10 seconds for the supervisor to end. Safe to call twice.
 *
 * @param array{process: resource, stdin: resource} $server From itWebServerStart()
 * @return void
 */
function itWebServerStop(array $server): void
{
    if (is_resource($server['stdin'])) {
        fclose($server['stdin']);
    }
    if (!is_resource($server['process'])) {
        return;
    }

    $deadline = microtime(true) + 10;
    while (proc_get_status($server['process'])['running'] === true && microtime(true) < $deadline) {
        usleep(50000);
    }
    if (proc_get_status($server['process'])['running'] === true) {
        // 🧯 Should never happen: the supervisor stops itself within 5 seconds.
        proc_terminate($server['process'], 9);
    }
    proc_close($server['process']);

    // 🔪 Info 1: the supervisor is gone; if the server still answers, it was
    //    left behind (the supervisor was killed), so stop it by its number.
    itWebServerStopOrphan((int) ($server['serverPid'] ?? 0), (string) ($server['address'] ?? ''));
}

/**
 * 🔪 Stop a server the supervisor left behind (fourth-round review, Info 1):
 *    SIGTERM, then SIGKILL after 3 seconds - but only while something still
 *    answers on its address.
 *
 * @param int    $pid     The server's process number (0 = unknown: nothing to do)
 * @param string $address host:port it listens on
 * @return void
 */
function itWebServerStopOrphan(int $pid, string $address): void
{
    if ($pid <= 0 || $address === '' || !itWebServerAnswers($address)) {
        return;
    }

    itWebServerSignal($pid, 15);
    $deadline = microtime(true) + 3;
    while (itWebServerAnswers($address) && microtime(true) < $deadline) {
        usleep(50000);
    }
    if (itWebServerAnswers($address)) {
        itWebServerSignal($pid, 9);
    }
}

/**
 * 🔌 Does something accept a connection on host:port?
 *
 * @param string $address
 * @return bool
 */
function itWebServerAnswers(string $address): bool
{
    $errorNumber = 0;
    $errorText   = '';
    $socket      = @stream_socket_client('tcp://' . $address, $errorNumber, $errorText, 0.3);
    if ($socket === false) {
        return false;
    }
    fclose($socket);
    return true;
}

/**
 * 📨 Send a signal to a process (posix_kill(), or the kill command without it).
 *
 * @param int $pid
 * @param int $signal
 * @return void
 */
function itWebServerSignal(int $pid, int $signal): void
{
    if (function_exists('posix_kill')) {
        posix_kill($pid, $signal);
        return;
    }

    $pipes = [];
    $kill  = proc_open(['kill', '-' . $signal, (string) $pid], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($kill)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($kill);
    }
}
