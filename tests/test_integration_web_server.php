<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - A test web server is stopped however its script ends
 * ==============================================================================
 *
 * Path: tests/test_integration_web_server.php
 *
 * tests/lib/web_server.php starts PHP's built-in web server for the
 * real-HTTP scenarios (tests/integration/human_check_real_mysql.php). Three
 * servers left running by earlier runs were found, so this proves, with a
 * real server on a free local port, that it stops when the script:
 *   - calls itWebServerStop() (the normal way);
 *   - exits with a failure code (a check failed);
 *   - throws an uncaught exception, or stops on a fatal error;
 *   - is stopped with SIGTERM (kill) or SIGINT (Ctrl+C);
 *   - is killed outright with SIGKILL (kill -9), which no code can catch.
 * A control case starts the server the OLD way (proc_open of php -S straight
 * from the script) and kills the script: that server is still running
 * afterwards, which shows this test can see an orphaned server. It is then
 * stopped by its process number.
 *
 * Each case runs in a child PHP process. "Stopped" means nothing accepts a
 * connection on the port any more (checked for up to 8 seconds).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (orphaned test web servers)
 * @see         tests/lib/web_server.php
 * @see         tests/lib/web_server_supervisor.php
 */

$wsLib = __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'web_server.php';
assertTrue(is_file($wsLib), 'the shared test web server helper exists (tests/lib/web_server.php)');

// 📌 The real-HTTP script uses the helper and never starts php -S itself.
$wsHumanCheck = (string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . 'human_check_real_mysql.php');
assertContains('itWebServerStart(', $wsHumanCheck, 'human_check_real_mysql.php starts its web server through the helper');
assertFalse(str_contains($wsHumanCheck, "'-S', '127.0.0.1:' . \$hcHttpPort, '-t'") && str_contains($wsHumanCheck, '$hcWeb = proc_open('), 'human_check_real_mysql.php no longer starts php -S with its own proc_open');
foreach ((array) glob(__DIR__ . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . '*_real_mysql.php') as $wsScript) {
    $wsText = (string) file_get_contents((string) $wsScript);
    if (preg_match("/['\"]-S['\"]/", $wsText) === 1) {
        assertContains('itWebServerStart(', $wsText, basename((string) $wsScript) . ': a script that starts a web server uses the helper');
    }
}

/**
 * 🔢 A free port on 127.0.0.1.
 */
function wsFreePort(): int
{
    $wsProbe = stream_socket_server('tcp://127.0.0.1:0');
    if ($wsProbe === false) {
        return 0;
    }
    $wsName = (string) stream_socket_get_name($wsProbe, false);
    fclose($wsProbe);
    return (int) substr($wsName, (int) strrpos($wsName, ':') + 1);
}

/**
 * 🔌 Does something accept a connection on the port?
 */
function wsPortOpen(int $wsPort): bool
{
    $wsErrNo  = 0;
    $wsErrStr = '';
    $wsSocket = @fsockopen('127.0.0.1', $wsPort, $wsErrNo, $wsErrStr, 0.3);
    if ($wsSocket === false) {
        return false;
    }
    fclose($wsSocket);
    return true;
}

/**
 * ⏳ Wait until the port is closed (true) or the time is up (false).
 */
function wsWaitClosed(int $wsPort, float $wsSeconds): bool
{
    $wsDeadline = microtime(true) + $wsSeconds;
    while (microtime(true) < $wsDeadline) {
        if (!wsPortOpen($wsPort)) {
            return true;
        }
        usleep(100000);
    }
    return !wsPortOpen($wsPort);
}

if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open') || !is_file($wsLib)) {
    assertTrue(true, 'web server cases skipped (no proc_open, Windows, or the helper is missing)');
    return;
}

$wsRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_ws_' . bin2hex(random_bytes(4));
mkdir($wsRoot);
file_put_contents($wsRoot . DIRECTORY_SEPARATOR . 'index.php', '<?php echo "ok";');
$wsChildFile = $wsRoot . DIRECTORY_SEPARATOR . 'child.php';
file_put_contents($wsChildFile, <<<'PHP'
<?php
// 👶 One case: start a server, say "up <port> <pid>", then end the way asked.
[$wsSelf, $wsMode, $wsLibPath, $wsPort, $wsDocRoot] = $argv;
$wsServerPid = 0;
if ($wsMode === 'old') {
    // ⛔ The old way: php -S straight from this script, never stopped.
    $wsOldPipes = [];
    $wsOld = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $wsPort, '-t', $wsDocRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $wsOldPipes);
    $wsServerPid = (int) proc_get_status($wsOld)['pid'];
} else {
    require $wsLibPath;
    $wsServer = itWebServerStart(['-S', '127.0.0.1:' . $wsPort, '-t', $wsDocRoot], '/dev/null', getenv());
}
$wsDeadline = microtime(true) + 10;
while (microtime(true) < $wsDeadline) {
    $wsSocket = @fsockopen('127.0.0.1', (int) $wsPort, $wsErrNo, $wsErrStr, 0.2);
    if ($wsSocket !== false) {
        fclose($wsSocket);
        break;
    }
    usleep(100000);
}
echo 'up ' . $wsPort . ' ' . $wsServerPid . "\n";
if ($wsMode === 'stop') {
    itWebServerStop($wsServer);
    sleep(30);
} elseif ($wsMode === 'fail') {
    exit(1);
} elseif ($wsMode === 'throw') {
    throw new RuntimeException('a check blew up');
} elseif ($wsMode === 'fatal') {
    wsNoSuchFunction();
} else {
    sleep(60);
}
PHP);

$wsCases = [
    'itWebServerStop() is called'          => ['stop', null],
    'the script exits with a failure code' => ['fail', null],
    'an uncaught exception'                => ['throw', null],
    'a fatal error'                        => ['fatal', null],
    'SIGTERM (kill)'                       => ['sleep', 15],
    'SIGINT (Ctrl+C)'                      => ['sleep', 2],
    'SIGKILL (kill -9, cannot be caught)'  => ['sleep', 9],
    'CONTROL: the old way, then SIGKILL'   => ['old', 9],
];
foreach ($wsCases as $wsLabel => [$wsMode, $wsSignal]) {
    $wsPort  = wsFreePort();
    $wsPipes = [];
    $wsChild = proc_open([PHP_BINARY, $wsChildFile, $wsMode, $wsLib, (string) $wsPort, $wsRoot], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $wsPipes);
    $wsLine  = (string) fgets($wsPipes[1]);
    $wsUp    = str_starts_with($wsLine, 'up ') && wsPortOpen($wsPort);
    $wsParts = explode(' ', trim($wsLine));
    if ($wsSignal !== null) {
        proc_terminate($wsChild, $wsSignal);
    }
    $wsClosed = wsWaitClosed($wsPort, 8.0);
    fclose($wsPipes[0]);
    fclose($wsPipes[1]);
    proc_terminate($wsChild, 9);
    proc_close($wsChild);

    if ($wsMode === 'old') {
        assertTrue($wsUp && $wsClosed === false, 'control: a server started the OLD way is still running after its script is killed (the orphan this test looks for)');
        // 🧹 Stop that orphan by its process number.
        if (isset($wsParts[2]) && (int) $wsParts[2] > 0) {
            if (function_exists('posix_kill')) {
                posix_kill((int) $wsParts[2], 9);
            } else {
                $wsKillPipes = [];
                $wsKill = proc_open(['kill', '-9', (string) (int) $wsParts[2]], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wsKillPipes);
                if (is_resource($wsKill)) {
                    fclose($wsKillPipes[0]);
                    fclose($wsKillPipes[1]);
                    fclose($wsKillPipes[2]);
                    proc_close($wsKill);
                }
            }
        }
        assertTrue(wsWaitClosed($wsPort, 5.0), 'control: ...and the orphan has been stopped by this test');
        continue;
    }
    assertTrue($wsUp && $wsClosed, 'the test web server was up, and is stopped when ' . $wsLabel);
}

@unlink($wsChildFile);
@unlink($wsRoot . DIRECTORY_SEPARATOR . 'index.php');
@rmdir($wsRoot);
