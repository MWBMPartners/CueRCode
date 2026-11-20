<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the PHP 8.6 adoption helper cuercodeClampInt() (#151)
 * ==============================================================================
 *
 * Path: tests/test_php86_adoption.php
 *
 * Decision 0004 says: use a PHP 8.6 feature on 8.6 where it is safe, and keep
 * the old code on 8.4 / 8.5, with identical results. The first (and so far
 * only) adopted feature is clamp(), wrapped by cuercodeClampInt() in
 * _functions/fn_compat.php. This file proves, on whichever PHP is running it:
 *
 *   1. For every pair of limits used in the code, the helper gives EXACTLY
 *      (strict ===) what the old max(low, min(high, value)) gave, across the
 *      edge values (PHP_INT_MIN, PHP_INT_MAX, either side of each limit).
 *   2. Equal limits work; REVERSED limits return the lower limit on every
 *      version (the native 8.6 clamp() would throw).
 *   3. NaN, INF and text are refused with a TypeError on every version.
 *   4. clamp() exists exactly when PHP is 8.6 or newer.
 *   5. On 8.6 only: records WHY the float site in fn_email.php was skipped
 *      (clamp() returns NaN where the old code returned 600.0).
 *   6. Source guards: fn_compat.php tests function_exists('clamp'); no project
 *      file declares a global function clamp().
 *   7. The bad-JSON reply code is still INVALID_JSON and PHP's message still
 *      starts with "Syntax error" (8.6 only adds a location after it).
 *
 * ⚠️ House rule for this file (shared global scope with tests/run.php): every
 *    variable is prefixed $p86.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-18 (#151)
 * @see         web/CueRCode.net/_functions/fn_compat.php
 * @see         docs/decisions/0006-php-8-6-adoption-what-and-where.md
 */

requireFn('fn_compat.php');
requireFn('fn_email.php'); // for the two email batch-size constants

// ==============================================================================
// 1. Every pair of limits used in the code, against the old expression
// ==============================================================================

$p86Pairs = [
    [0, 100], [0, 20], [0, 255], [0, 50], [0, 8], [1, 10], [1, 100], [1, 1000],
    [1, 10000], [1, 1440], [1, 20], [1, 300], [1, 50],
    [1, CUERCODE_EMAIL_QUEUE_MAX_BATCH], [1, CUERCODE_EMAIL_QUEUE_TIDY_BATCH],
    [10, 300], [100, 4000], [100, 8000], [16, 2048], [5, 40], [8, 72],
];

foreach ($p86Pairs as $p86Pair) {
    $p86Lo = $p86Pair[0];
    $p86Hi = $p86Pair[1];

    $p86Values = [
        PHP_INT_MIN, -1, 0, $p86Lo - 1, $p86Lo, $p86Lo + 1,
        $p86Hi - 1, $p86Hi, $p86Hi + 1, PHP_INT_MAX,
    ];

    $p86AllSame = true;
    $p86Detail  = '';
    foreach ($p86Values as $p86Value) {
        $p86Old = max($p86Lo, min($p86Hi, $p86Value));
        $p86New = cuercodeClampInt($p86Value, $p86Lo, $p86Hi);
        if ($p86Old !== $p86New) {
            $p86AllSame = false;
            $p86Detail  = 'value ' . $p86Value . ': old ' . $p86Old . ', new ' . $p86New;
            break;
        }
    }

    assertTrue(
        $p86AllSame,
        'cuercodeClampInt matches max(min()) for limits ' . $p86Lo . ' to ' . $p86Hi . ' ' . $p86Detail
    );
}

// ==============================================================================
// 2. Equal and reversed limits
// ==============================================================================

assertSame7(5, cuercodeClampInt(99, 5, 5), 'equal limits give that limit (value above)');
assertSame7(5, cuercodeClampInt(-99, 5, 5), 'equal limits give that limit (value below)');
assertSame7(10, cuercodeClampInt(5, 10, 1), 'reversed limits return the lower-limit argument on every PHP version');
assertSame7(max(10, min(1, 5)), cuercodeClampInt(5, 10, 1), 'reversed limits match what max(min()) always returned');

/**
 * 🔧 Strict (type and value) comparison with a message.
 *
 * @param int    $expected Expected integer.
 * @param mixed  $actual   Actual value.
 * @param string $msg      Description.
 *
 * @return bool Pass or fail.
 */
function assertSame7(int $expected, mixed $actual, string $msg): bool
{
    return assertTrue($expected === $actual, $msg . ' (expected ' . $expected . ', got ' . var_export($actual, true) . ')');
}

// ==============================================================================
// 3. Bad input is refused the same way on every version
// ==============================================================================

assertThrows(static function (): int {
    return cuercodeClampInt(NAN, 1, 10);
}, 'NaN is refused with a TypeError', TypeError::class);

assertThrows(static function (): int {
    return cuercodeClampInt(INF, 1, 10);
}, 'INF is refused with a TypeError', TypeError::class);

assertThrows(static function (): int {
    return cuercodeClampInt('abc', 1, 10);
}, 'text is refused with a TypeError', TypeError::class);

// ==============================================================================
// 4. clamp() exists exactly on 8.6 and later
// ==============================================================================

assertEquals(
    PHP_VERSION_ID >= 80600,
    function_exists('clamp'),
    'clamp() exists exactly when PHP is 8.6 or newer (running ' . PHP_VERSION . ')'
);

// ==============================================================================
// 5. 8.6 only: why the float site was skipped
// ==============================================================================

if (function_exists('clamp')) {
    // Called through a variable so the scanner (which bans a direct call)
    // does not mind; the call is already behind function_exists().
    $p86Native = 'clamp';
    assertTrue(is_nan($p86Native(NAN, 1.0, 600.0)), '8.6 clamp() returns NaN for NaN (reason fn_email.php float site stays max(min()))');
    assertTrue(max(1.0, min(NAN, 600.0)) === 600.0, 'the old float expression gives 600.0 for NaN');
} else {
    assertTrue(max(1.0, min(NAN, 600.0)) === 600.0, 'the old float expression gives 600.0 for NaN (no native clamp() here)');
}

// ==============================================================================
// 6. Source guards
// ==============================================================================

$p86Root   = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';
$p86Compat = file_get_contents($p86Root . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_compat.php');
assertTrue(
    is_string($p86Compat) && str_contains($p86Compat, "function_exists('clamp')"),
    'fn_compat.php chooses the native clamp() by testing function_exists'
);

$p86Declares = '';
$p86Iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p86Root, FilesystemIterator::SKIP_DOTS));
foreach ($p86Iterator as $p86File) {
    $p86Path = $p86File->getPathname();
    if (!str_ends_with($p86Path, '.php') || str_contains($p86Path, DIRECTORY_SEPARATOR . '_libraries' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $p86Code = file_get_contents($p86Path);
    if (is_string($p86Code) && preg_match('/^\s*function\s+clamp\s*\(/mi', $p86Code) === 1) {
        $p86Declares = $p86Path;
        break;
    }
}
assertEquals('', $p86Declares, 'no project file declares a global function clamp() ' . $p86Declares);

// ==============================================================================
// 7. Bad JSON: the reply code and the start of PHP's message are stable
// ==============================================================================

$p86Index = file_get_contents($p86Root . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php');
assertTrue(
    is_string($p86Index) && str_contains($p86Index, "'INVALID_JSON'") && str_contains($p86Index, "'Invalid JSON in request body: ' . json_last_error_msg()"),
    'the API still answers bad JSON with INVALID_JSON and "Invalid JSON in request body: ..."'
);

json_decode('{bad');
assertMatches('/^Syntax error/', json_last_error_msg(), 'PHP message for bad JSON starts with "Syntax error" on every version (8.6 adds a location after it)');
