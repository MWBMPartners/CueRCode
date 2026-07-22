<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Test Runner
 * ==============================================================================
 *
 * Path: tests/run.php
 *
 * A zero-dependency test runner for the CueRCode characterization safety net.
 * There is no Composer / PHPUnit on the production host, so this hand-rolled
 * runner discovers and executes the suite using only the PHP CLI.
 *
 * What it does:
 *   1. Loads the test bootstrap (tests/bootstrap.php) - defines test-only
 *      constants + the requireFn() loader, and NEVER opens a DB connection.
 *   2. Loads the assertion library (tests/lib/assert.php) - the assertEquals,
 *      assertTrue, assertContains, ... functions used inside test files.
 *   3. Discovers every tests/test_*.php file (sorted), optionally filtered by
 *      a --filter=<substring> argument.
 *   4. Includes each test file (its assertions record into the global results
 *      collector). A fatal Throwable thrown OUTSIDE an assertion is caught and
 *      recorded as a synthetic failure so one broken file cannot abort the run.
 *   5. Prints a coloured, per-file PASS/FAIL breakdown plus a grand-total
 *      summary, then exits 0 if everything passed (or there were zero tests),
 *      and 1 if any assertion failed.
 *
 * Usage:
 *   php tests/run.php                  # run every test_*.php
 *   php tests/run.php --filter=crypto  # only files whose name contains "crypto"
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1 (test safety-net cycle)
 * @see         https://www.php.net/manual/en/function.glob.php
 * @see         https://www.php.net/manual/en/features.commandline.php
 */

// ==============================================================================
// 🚫 Step 0: CLI-only guard
// ==============================================================================
// This runner is for the command line. Refuse to run under a web SAPI so it
// can never be invoked over HTTP even if accidentally deployed.
// @see https://www.php.net/manual/en/function.php-sapi-name.php
// ==============================================================================

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Test runner is CLI-only.');
}

// ==============================================================================
// 🎨 Step 1: Terminal Colour Helpers
// ==============================================================================
// ANSI colour codes, auto-disabled when output is not an interactive TTY (e.g.
// piped to a file or a CI log) or when NO_COLOR is set.
// @see https://no-color.org/
// @see https://www.php.net/manual/en/function.stream-isatty.php
// ==============================================================================

$useColor = true;

// 🔌 Disable colour when NO_COLOR is present (any value) - de-facto standard.
if (getenv('NO_COLOR') !== false) {
    $useColor = false;
}

// 🔌 Disable colour when STDOUT is not a terminal (piped/redirected output).
if (defined('STDOUT') && function_exists('stream_isatty') && !stream_isatty(STDOUT)) {
    $useColor = false;
}

/**
 * 🎨 Wrap text in an ANSI colour code when colour output is enabled.
 *
 * @param string $text  The text to colourise.
 * @param string $code  The ANSI SGR code (e.g. '32' green, '31' red, '1' bold).
 *
 * @return string The (optionally) colourised text.
 */
function colorize(string $text, string $code): string
{
    // 📌 $useColor is set in the global scope above; pull it in here.
    global $useColor;

    if (!$useColor) {
        return $text;
    }

    return "\033[" . $code . 'm' . $text . "\033[0m";
}

// ==============================================================================
// 🧩 Step 2: Parse CLI arguments (--filter=<substring>)
// ==============================================================================

$filter = '';

// $argv is the array of command-line arguments; element 0 is the script path.
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    }
}

// ==============================================================================
// 🚀 Step 3: Load bootstrap + assertion library
// ==============================================================================
// The bootstrap defines test-only constants and the requireFn() loader without
// ever opening a database connection. The assert library defines the
// assertEquals/assertTrue/... functions and the global results collector.
// ==============================================================================

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'assert.php';

// 📊 Ensure the results collector is initialised (assert.php also guards this).
if (!isset($GLOBALS['__CUERCODE_TEST_RESULTS']) || !is_array($GLOBALS['__CUERCODE_TEST_RESULTS'])) {
    $GLOBALS['__CUERCODE_TEST_RESULTS'] = [];
}

// ==============================================================================
// 🔎 Step 4: Discover test files (tests/test_*.php), sorted
// ==============================================================================
// @see https://www.php.net/manual/en/function.glob.php
// ==============================================================================

$pattern   = __DIR__ . DIRECTORY_SEPARATOR . 'test_*.php';
$testFiles = glob($pattern);

if ($testFiles === false) {
    $testFiles = [];
}

// 🔤 Sort for stable, deterministic execution order.
sort($testFiles);

// 🧪 Apply the optional --filter substring (matched against the base name).
if ($filter !== '') {
    $filtered = [];
    foreach ($testFiles as $testFile) {
        if (str_contains(basename($testFile), $filter)) {
            $filtered[] = $testFile;
        }
    }
    $testFiles = $filtered;
}

// ==============================================================================
// 🏁 Step 5: Run each test file
// ==============================================================================
// Before including a file we mark it as "current" so the assertions it records
// are attributed to it. Any Throwable escaping the file (a fatal outside an
// assertion) is caught and recorded as a synthetic failure.
// ==============================================================================

echo PHP_EOL;
echo colorize('🧪 CueRCode Characterization Test Runner', '1') . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;

if ($filter !== '') {
    echo 'Filter: ' . colorize($filter, '33') . PHP_EOL;
}

echo 'Discovered ' . count($testFiles) . ' test file(s).' . PHP_EOL;
echo PHP_EOL;

foreach ($testFiles as $testFile) {
    // 📌 Record where we are so assertions attribute correctly.
    test_set_current_file(basename($testFile));

    // 📸 Snapshot the result count before this file runs, so we can compute
    //    this file's pass/fail tally afterwards.
    $countBefore = count($GLOBALS['__CUERCODE_TEST_RESULTS']);

    try {
        // ▶️ Execute the test file. Its assertions self-record.
        require $testFile;
    } catch (\Throwable $e) {
        // ❌ A fatal escaped the file (outside any assertion). Record it so the
        //    run continues and the failure is visible in the summary.
        test_record(
            false,
            'fatal',
            'Uncaught throwable in ' . basename($testFile),
            get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
        );
    }

    // 📊 Tally just this file's results.
    $fileResults = array_slice($GLOBALS['__CUERCODE_TEST_RESULTS'], $countBefore);
    $filePass    = 0;
    $fileFail    = 0;

    foreach ($fileResults as $r) {
        if ($r['pass']) {
            $filePass++;
        } else {
            $fileFail++;
        }
    }

    // 🖨️ Per-file one-line status.
    if ($fileFail === 0) {
        $status = colorize('PASS', '32');
    } else {
        $status = colorize('FAIL', '31');
    }

    echo sprintf(
        '  [%s] %-40s %d passed, %d failed',
        $status,
        basename($testFile),
        $filePass,
        $fileFail
    ) . PHP_EOL;
}

// ==============================================================================
// 📋 Step 6: Print failures in detail
// ==============================================================================

$allResults  = $GLOBALS['__CUERCODE_TEST_RESULTS'];
$totalPass   = 0;
$totalFail   = 0;
$failures    = [];

foreach ($allResults as $r) {
    if ($r['pass']) {
        $totalPass++;
    } else {
        $totalFail++;
        $failures[] = $r;
    }
}

if ($totalFail > 0) {
    echo PHP_EOL;
    echo colorize('Failures:', '1;31') . PHP_EOL;
    echo str_repeat('-', 60) . PHP_EOL;

    foreach ($failures as $i => $f) {
        $label = $f['message'];
        if ($label === '') {
            $label = '(no message)';
        }

        echo colorize('  ✗ ', '31')
            . '#' . ($i + 1) . ' '
            . '[' . $f['file'] . '] '
            . $f['assert'] . ': '
            . $label
            . PHP_EOL;

        if ($f['detail'] !== '') {
            echo '      ' . colorize($f['detail'], '90') . PHP_EOL;
        }
    }
}

// ==============================================================================
// 🧮 Step 7: Grand-total summary + exit code
// ==============================================================================

$totalAssertions = $totalPass + $totalFail;

echo PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;

if ($totalAssertions === 0) {
    // 🟡 No test_*.php files (or none with assertions) - this is a valid,
    //    successful "empty suite" state for the initial scaffolding.
    echo colorize('No assertions executed (0 tests).', '33') . PHP_EOL;
    echo str_repeat('=', 60) . PHP_EOL;
    echo PHP_EOL;
    exit(0);
}

$summary = sprintf(
    'Total: %d assertion(s)  |  %s  |  %s',
    $totalAssertions,
    colorize($totalPass . ' passed', '32'),
    colorize($totalFail . ' failed', $totalFail > 0 ? '31' : '32')
);

echo $summary . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo PHP_EOL;

// 🚦 Exit non-zero if anything failed, so CI can gate on it.
if ($totalFail > 0) {
    exit(1);
}

exit(0);
