<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - PHP Runtime Guard Tests (#151, PHP 8.6 readiness)
 * ==============================================================================
 *
 * Path: tests/test_php_runtime_guards.php
 *
 * Two guards:
 *
 *   1. SESSION SETTINGS. PHP 8.6 turns on strict session mode, HttpOnly and
 *      SameSite=Lax by default. PHP 8.4 and 8.5 do NOT. The site must set all
 *      three itself. If someone deletes these lines and relies on the 8.6
 *      defaults, the site would lose them on 8.4/8.5. These tests read the two
 *      files as TEXT and fail if an explicit setting disappears. They prove the
 *      settings are written in the code; they do NOT start a web server and
 *      check the live cookie. The .htaccess `php_value` lines are skipped under
 *      FastCGI, so the PHP-side ini_set() calls are the ones that count there.
 *
 *   2. DEPRECATION CATCHER. The test bootstrap turns PHP deprecations into
 *      test failures. This test proves it in a child process: a raised
 *      E_USER_DEPRECATED must be recorded as a failure, and one silenced with
 *      the @ operator must not.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #151
 * @see         https://www.php.net/manual/en/session.security.ini.php
 */

$guardRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;

// ==============================================================================
// 🍪 Guard 1: explicit session settings
// ==============================================================================

$bootstrapPath = $guardRoot . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net'
    . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$htaccessPath  = $guardRoot . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net'
    . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . '.htaccess';

$bootstrapText = (string) file_get_contents($bootstrapPath);
$htaccessText  = (string) file_get_contents($htaccessPath);

assertTrue($bootstrapText !== '', 'production bootstrap.php is readable');
assertTrue($htaccessText !== '', 'public_html/.htaccess is readable');

// 🔒 HttpOnly set explicitly in session_set_cookie_params().
assertMatches(
    "/'httponly'\\s*=>\\s*true/",
    $bootstrapText,
    "bootstrap.php sets session cookie 'httponly' => true explicitly"
);

// 🔒 SameSite=Lax set explicitly in session_set_cookie_params().
assertMatches(
    "/'samesite'\\s*=>\\s*'Lax'/",
    $bootstrapText,
    "bootstrap.php sets session cookie 'samesite' => 'Lax' explicitly"
);

// 🔒 Strict session mode and cookies-only set in PHP itself, BEFORE session_start().
//    (.htaccess php_value is ignored under FastCGI, so it cannot be the only copy.)
assertMatches(
    "/ini_set\\(\\s*'session\\.use_strict_mode'\\s*,\\s*'1'\\s*\\)/",
    $bootstrapText,
    "bootstrap.php sets session.use_strict_mode to 1 with ini_set()"
);
assertMatches(
    "/ini_set\\(\\s*'session\\.use_only_cookies'\\s*,\\s*'1'\\s*\\)/",
    $bootstrapText,
    "bootstrap.php sets session.use_only_cookies to 1 with ini_set()"
);

$strictPos = strpos($bootstrapText, "ini_set('session.use_strict_mode'");
$startPos  = strpos($bootstrapText, 'session_start();');
assertTrue(
    $strictPos !== false && $startPos !== false && $strictPos < $startPos,
    'the ini_set() session settings come before session_start()'
);

// 🔒 Strict mode also stays in .htaccess (belt and braces, used under mod_php).
assertMatches(
    '/^\\s*php_value\\s+session\\.use_strict_mode\\s+1\\s*$/m',
    $htaccessText,
    '.htaccess also sets session.use_strict_mode 1'
);

// 🔒 The matching cookie-level .htaccess lines (belt and braces).
assertMatches(
    '/^\\s*php_value\\s+session\\.cookie_httponly\\s+1\\s*$/m',
    $htaccessText,
    '.htaccess sets session.cookie_httponly 1 explicitly'
);
assertMatches(
    '/^\\s*php_value\\s+session\\.cookie_samesite\\s+"Lax"\\s*$/m',
    $htaccessText,
    '.htaccess sets session.cookie_samesite "Lax" explicitly'
);

// ==============================================================================
// 🚨 Guard 2: the deprecation catcher turns a deprecation into a failure
// ==============================================================================

/**
 * 🛰️ Run a PHP program in a child process and return its standard output.
 *
 * @param string $program Full PHP source of the child program.
 * @param string $stderr  Filled with the child's STDERR text.
 * @param int    $exit    Filled with the child's exit code.
 *
 * @return string Captured standard output ('' on failure to start).
 */
function runtimeGuardChild(string $program, string &$stderr = '', int &$exit = -1): string
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'cuercode_guard_') . '.php';
    file_put_contents($tmpFile, $program);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stdout  = '';
    $process = proc_open([PHP_BINARY, $tmpFile], $descriptors, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }

    @unlink($tmpFile);

    return $stdout;
}

$testsDir = __DIR__ . DIRECTORY_SEPARATOR;

$childProgram = '<?php' . PHP_EOL
    . 'require ' . var_export($testsDir . 'bootstrap.php', true) . ';' . PHP_EOL
    . 'require ' . var_export($testsDir . 'lib' . DIRECTORY_SEPARATOR . 'assert.php', true) . ';' . PHP_EOL
    . '// 1) A silenced deprecation must NOT be recorded.' . PHP_EOL
    . '@trigger_error("silenced one", E_USER_DEPRECATED);' . PHP_EOL
    . '$afterSilenced = count($GLOBALS["__CUERCODE_TEST_RESULTS"]);' . PHP_EOL
    . '// 2) An unsilenced deprecation MUST be recorded as a failure.' . PHP_EOL
    . 'trigger_error("loud one", E_USER_DEPRECATED);' . PHP_EOL
    . '$last = end($GLOBALS["__CUERCODE_TEST_RESULTS"]);' . PHP_EOL
    . 'echo json_encode([' . PHP_EOL
    . '    "silencedRecorded" => $afterSilenced,' . PHP_EOL
    . '    "pass"   => $last["pass"] ?? null,' . PHP_EOL
    . '    "assert" => $last["assert"] ?? null,' . PHP_EOL
    . '    "msg"    => $last["message"] ?? null,' . PHP_EOL
    . '    "flag"   => !empty($GLOBALS["__CUERCODE_DEPRECATIONS"]),' . PHP_EOL
    . ']);' . PHP_EOL;

$childOutput = runtimeGuardChild($childProgram);
$childResult = json_decode($childOutput, true);

assertTrue(is_array($childResult), 'catcher child process produced JSON output');

if (is_array($childResult)) {
    assertEquals(0, $childResult['silencedRecorded'], 'a deprecation silenced with @ is not recorded');
    assertEquals(false, $childResult['pass'], 'an unsilenced deprecation is recorded as a FAILED result');
    assertEquals('deprecation', $childResult['assert'], 'the failure is labelled "deprecation"');
    assertContains('loud one', (string) $childResult['msg'], 'the failure message carries the deprecation text');
    assertTrue($childResult['flag'], 'the run-level deprecation flag is set for run.php');
}

// 🚦 run.php must turn that flag into a non-zero exit.
$runnerText = (string) file_get_contents($testsDir . 'run.php');
assertContains(
    '__CUERCODE_DEPRECATIONS',
    $runnerText,
    'tests/run.php checks the deprecation flag when choosing its exit code'
);

// ==============================================================================
// 🛰️ Guard 3: a deprecation inside a CHILD process fails the parent
// ==============================================================================
// Five tests spawn `php`, load tests/bootstrap.php and read only STDOUT. Before
// this guard, a deprecation raised in such a child was lost. Now the child
// exits 255, writes a CUERCODE-DEPRECATION: marker to STDERR, and the parent
// helpers turn the marker into a failed assertion.

$bareChild = '<?php' . PHP_EOL
    . 'require ' . var_export($testsDir . 'bootstrap.php', true) . ';' . PHP_EOL
    . 'trigger_error("child deprecation", E_USER_DEPRECATED);' . PHP_EOL
    . 'echo "reached the end";' . PHP_EOL;

$childErr  = '';
$childExit = -1;
$childOut  = runtimeGuardChild($bareChild, $childErr, $childExit);

assertEquals(255, $childExit, 'a child that raises a deprecation exits with code 255');
assertContains('CUERCODE-DEPRECATION:', $childErr, 'the child writes the deprecation marker to STDERR');
assertContains('child deprecation', $childErr, 'the marker line carries the deprecation text');
assertContains('reached the end', $childOut, 'the child still ran to the end before exiting');

// 🔕 A clean child exits 0 with no marker.
$cleanChild = '<?php' . PHP_EOL
    . 'require ' . var_export($testsDir . 'bootstrap.php', true) . ';' . PHP_EOL
    . '@trigger_error("silenced", E_USER_DEPRECATED);' . PHP_EOL
    . 'echo "ok";' . PHP_EOL;
$cleanErr  = '';
$cleanExit = -1;
runtimeGuardChild($cleanChild, $cleanErr, $cleanExit);
assertEquals(0, $cleanExit, 'a child with only a silenced deprecation exits 0');
assertEquals('', $cleanErr, 'a clean child writes nothing to STDERR');

// 🧪 The real helper (tests/test_core.php) must record a FAILED assertion.
if (function_exists('runPhpSnippet')) {
    $before = count($GLOBALS['__CUERCODE_TEST_RESULTS']);
    runPhpSnippet('trigger_error("helper deprecation", E_USER_DEPRECATED); echo "x";');
    $added  = array_slice($GLOBALS['__CUERCODE_TEST_RESULTS'], $before);

    // 🗑️ Remove the EXPECTED failure from the collector so the suite stays green.
    $GLOBALS['__CUERCODE_TEST_RESULTS'] = array_slice($GLOBALS['__CUERCODE_TEST_RESULTS'], 0, $before);

    $helperFailed = false;
    foreach ($added as $record) {
        if ($record['pass'] === false && str_contains($record['message'], 'child process raised no PHP deprecation')) {
            $helperFailed = true;
        }
    }
    assertTrue($helperFailed, 'runPhpSnippet() records a failed assertion when the child raises a deprecation');
}
