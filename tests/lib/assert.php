<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tiny Assertion Library (Characterization Test Harness)
 * ==============================================================================
 *
 * Path: tests/lib/assert.php
 *
 * A dependency-free assertion library for the CueRCode characterization test
 * suite. There is NO Composer / PHPUnit available on the production host, so
 * this is a deliberately minimal, hand-rolled set of assertions that record
 * each pass/fail into a shared global results collector.
 *
 * Every assertion takes an optional human-readable $msg used in the report.
 * Assertions NEVER throw on failure (except assertThrows, which inspects a
 * caught throwable) — a failed assertion simply records a FAIL and the test
 * continues, so one broken expectation does not abort the whole file.
 *
 * 🎯 Purpose of this cycle: capture CURRENT behaviour only (a safety net).
 *     If a function behaves "wrong", we still assert its CURRENT output so a
 *     future change that alters behaviour is detected.
 *
 * The results collector lives in $GLOBALS['__CUERCODE_TEST_RESULTS'] and is
 * an array of records, each: [
 *     'pass'    => bool,
 *     'message' => string,   // the $msg passed to the assertion
 *     'detail'  => string,   // expected/actual detail for failures
 *     'file'    => string,   // test file currently executing (set by run.php)
 *     'assert'  => string,   // which assertion fired (e.g. 'assertEquals')
 * ]
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1 (test safety-net cycle)
 * @see         https://www.php.net/manual/en/language.types.boolean.php
 * @see         https://www.php.net/manual/en/function.var-export.php
 */

// ==============================================================================
// 📊 Results Collector Bootstrap
// ==============================================================================
// Ensure the global results array exists before any assertion records into it.
// run.php also initialises this, but we guard here so assert.php can be used
// stand-alone if ever needed.
// ==============================================================================

if (!isset($GLOBALS['__CUERCODE_TEST_RESULTS']) || !is_array($GLOBALS['__CUERCODE_TEST_RESULTS'])) {
    $GLOBALS['__CUERCODE_TEST_RESULTS'] = [];
}

// 📌 Tracks the test file currently being executed, so each recorded result
//    can be attributed to its source file in the final report. run.php sets
//    this via test_set_current_file() before including each test_*.php.
if (!isset($GLOBALS['__CUERCODE_TEST_CURRENT_FILE'])) {
    $GLOBALS['__CUERCODE_TEST_CURRENT_FILE'] = '(unknown)';
}

// ==============================================================================
// 🔧 Internal Helpers
// ==============================================================================

/**
 * 🔧 Set the name of the test file currently being executed.
 *
 * Called by run.php immediately before it includes a test_*.php file so that
 * every assertion recorded while that file runs is attributed correctly.
 *
 * @param string $file The test file path (or basename) currently running.
 *
 * @return void
 */
function test_set_current_file(string $file): void
{
    $GLOBALS['__CUERCODE_TEST_CURRENT_FILE'] = $file;
}

/**
 * 🔧 Render any PHP value as a short, human-readable string for reports.
 *
 * Uses var_export() for fidelity, then collapses whitespace and truncates so
 * a failing assertion's detail line stays readable in a terminal.
 *
 * @param mixed $value Any value to stringify for the failure detail.
 *
 * @return string A compact single-line representation.
 *
 * @see https://www.php.net/manual/en/function.var-export.php
 */
function test_describe_value(mixed $value): string
{
    // 📦 var_export gives a faithful, re-parseable rendering of the value.
    $rendered = var_export($value, true);

    // 🧹 Collapse all runs of whitespace (incl. newlines) into single spaces.
    $rendered = preg_replace('/\s+/', ' ', $rendered);

    if ($rendered === null) {
        // preg_replace can return null on error - fall back to a safe cast.
        $rendered = (string) $value;
    }

    // ✂️ Truncate very long values so the report stays legible.
    if (strlen($rendered) > 300) {
        $rendered = substr($rendered, 0, 297) . '...';
    }

    return $rendered;
}

/**
 * 🔧 Record a single assertion result into the global collector.
 *
 * This is the single choke-point every public assertion funnels through, so
 * the recording shape stays consistent.
 *
 * @param bool   $pass   Whether the assertion passed.
 * @param string $assert The name of the assertion that fired.
 * @param string $msg    The caller-supplied human-readable message.
 * @param string $detail Expected/actual detail (shown only for failures).
 *
 * @return bool The same $pass value, so callers can chain if desired.
 */
function test_record(bool $pass, string $assert, string $msg, string $detail = ''): bool
{
    $GLOBALS['__CUERCODE_TEST_RESULTS'][] = [
        'pass'    => $pass,
        'message' => $msg,
        'detail'  => $detail,
        'file'    => $GLOBALS['__CUERCODE_TEST_CURRENT_FILE'],
        'assert'  => $assert,
    ];

    return $pass;
}

// ==============================================================================
// ✅ Public Assertions
// ==============================================================================

/**
 * ✅ Assert two values are equal using strict (===) comparison.
 *
 * Strict comparison is used deliberately: characterization tests want to lock
 * in the EXACT current type and value, so '1' and 1 are treated as different.
 *
 * @param mixed  $expected The value the test expects.
 * @param mixed  $actual   The value actually produced.
 * @param string $msg      Description of what is being checked.
 *
 * @return bool True if equal, false otherwise.
 *
 * @see https://www.php.net/manual/en/language.operators.comparison.php
 */
function assertEquals(mixed $expected, mixed $actual, string $msg = ''): bool
{
    $pass = ($expected === $actual);

    if ($pass) {
        $detail = '';
    } else {
        $detail = 'expected ' . test_describe_value($expected)
            . ' but got ' . test_describe_value($actual);
    }

    return test_record($pass, 'assertEquals', $msg, $detail);
}

/**
 * ✅ Assert a value is strictly boolean true.
 *
 * @param mixed  $actual The value expected to be exactly true.
 * @param string $msg    Description of what is being checked.
 *
 * @return bool True if $actual === true.
 */
function assertTrue(mixed $actual, string $msg = ''): bool
{
    $pass = ($actual === true);

    if ($pass) {
        $detail = '';
    } else {
        $detail = 'expected true but got ' . test_describe_value($actual);
    }

    return test_record($pass, 'assertTrue', $msg, $detail);
}

/**
 * ✅ Assert a value is strictly boolean false.
 *
 * @param mixed  $actual The value expected to be exactly false.
 * @param string $msg    Description of what is being checked.
 *
 * @return bool True if $actual === false.
 */
function assertFalse(mixed $actual, string $msg = ''): bool
{
    $pass = ($actual === false);

    if ($pass) {
        $detail = '';
    } else {
        $detail = 'expected false but got ' . test_describe_value($actual);
    }

    return test_record($pass, 'assertFalse', $msg, $detail);
}

/**
 * ✅ Assert that a haystack string contains a needle substring.
 *
 * Uses str_contains() for a plain (non-regex) substring search.
 *
 * @param string $needle   The substring that must be present.
 * @param string $haystack The string to search within.
 * @param string $msg      Description of what is being checked.
 *
 * @return bool True if $needle is found inside $haystack.
 *
 * @see https://www.php.net/manual/en/function.str-contains.php
 */
function assertContains(string $needle, string $haystack, string $msg = ''): bool
{
    $pass = str_contains($haystack, $needle);

    if ($pass) {
        $detail = '';
    } else {
        $detail = 'needle ' . test_describe_value($needle)
            . ' not found in ' . test_describe_value($haystack);
    }

    return test_record($pass, 'assertContains', $msg, $detail);
}

/**
 * ✅ Assert that a string matches a PCRE regular expression.
 *
 * @param string $regex The full PCRE pattern (including delimiters), e.g. '/^abc/'.
 * @param string $actual The string to test against the pattern.
 * @param string $msg    Description of what is being checked.
 *
 * @return bool True if the pattern matches at least once.
 *
 * @see https://www.php.net/manual/en/function.preg-match.php
 */
function assertMatches(string $regex, string $actual, string $msg = ''): bool
{
    // 📌 preg_match returns 1 on match, 0 on no match, false on error.
    //    We treat only an explicit 1 as a pass.
    $result = preg_match($regex, $actual);
    $pass   = ($result === 1);

    if ($pass) {
        $detail = '';
    } else {
        if ($result === false) {
            $detail = 'invalid regex ' . test_describe_value($regex)
                . ' (preg error)';
        } else {
            $detail = 'pattern ' . test_describe_value($regex)
                . ' did not match ' . test_describe_value($actual);
        }
    }

    return test_record($pass, 'assertMatches', $msg, $detail);
}

/**
 * ✅ Assert that invoking a callable throws a Throwable.
 *
 * The callable is invoked inside a try/catch. If ANY Throwable (Exception or
 * Error) is caught, the assertion passes. If the callable returns normally,
 * the assertion fails.
 *
 * Optionally, $expectedClass can pin the throwable to a specific class (or a
 * subclass of it). Pass null (default) to accept any Throwable.
 *
 * @param callable    $fn            The callable expected to throw.
 * @param string      $msg           Description of what is being checked.
 * @param string|null $expectedClass Optional throwable class name to require.
 *
 * @return bool True if a (matching) Throwable was thrown.
 *
 * @see https://www.php.net/manual/en/class.throwable.php
 */
function assertThrows(callable $fn, string $msg = '', ?string $expectedClass = null): bool
{
    $pass   = false;
    $detail = '';

    try {
        // ▶️ Invoke the callable; if it returns, no exception was thrown.
        $fn();

        // ❌ Reaching here means nothing was thrown - that is a failure.
        $detail = 'expected a Throwable but none was thrown';
    } catch (\Throwable $e) {
        // ✅ Something was thrown. If a specific class was requested, verify it.
        if ($expectedClass === null) {
            $pass = true;
        } else {
            if ($e instanceof $expectedClass) {
                $pass = true;
            } else {
                $detail = 'expected throwable of type ' . $expectedClass
                    . ' but got ' . get_class($e)
                    . ' (' . $e->getMessage() . ')';
            }
        }
    }

    return test_record($pass, 'assertThrows', $msg, $detail);
}
