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
 * @return array{process: resource, stdin: resource}|null Null when it could not start
 */
function itWebServerStart(array $phpArgs, string $outputFile, array $env): ?array
{
    $pipes   = [];
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'web_server_supervisor.php', '--'], $phpArgs),
        [0 => ['pipe', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($process)) {
        return null;
    }

    $server = ['process' => $process, 'stdin' => $pipes[0]];

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
}
