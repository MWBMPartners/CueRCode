<?php
/**
 * ==============================================================================
 * 🔀 CueRCode - PHP version compatibility helpers
 * ==============================================================================
 *
 * Path: _functions/fn_compat.php
 *
 * Small helpers with NO dependencies that use a newer PHP feature when the
 * running PHP has it, and the old code when it does not (decision 0004,
 * recorded in docs/decisions/0006-php-8-6-adoption-what-and-where.md, #151).
 *
 * 📌 House rules for this file:
 *   - Choose the branch by testing that the feature EXISTS (function_exists),
 *     never by version number, and never branch stored data or security
 *     decisions on PHP version.
 *   - Every version must give the SAME answer for the SAME input.
 *   - Helpers carry the `cuercode` prefix. Never declare a global function
 *     with a name PHP itself owns (clamp() is a built-in from PHP 8.6).
 *
 * 📌 WHY an integer-only helper and not a copy of clamp():
 *   PHP 8.6's clamp() is not a drop-in for max(min()): given 1.0 it returns
 *   1.0 where the old code returned 1; its NaN result depends on argument
 *   order; and with the bounds reversed it throws ValueError where the old
 *   code returned the lower bound. cuercodeClampInt() accepts integers only
 *   (PHP rejects NaN, INF, null and 'abc' with a TypeError before the body
 *   runs, identically on every version) and handles reversed bounds itself,
 *   so the result is identical everywhere.
 *   Rejected: a global clamp() polyfill (claims a name PHP now owns and would
 *   have to copy clamp()'s mixed-type and NaN rules).
 *
 * @package     CueRCode
 * @subpackage  Compatibility
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-18 (#151)
 * @see         docs/decisions/0004-adopt-php-8-6-features-where-safe.md
 */

// ==============================================================================
// 🚫 Direct access protection - the same pattern as every other _functions file
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

if (!function_exists('cuercodeClampInt')) {
    /**
     * 📏 Keep a whole number between a lower and an upper bound.
     *
     * Same result as max($min, min($max, $value)) on every PHP version; on
     * PHP 8.6 and later it uses the built-in clamp().
     *
     * @param int $value The number to limit.
     * @param int $min   Lowest allowed value.
     * @param int $max   Highest allowed value.
     *
     * @return int $min when $value is below it, $max when above it, else $value.
     *             If the bounds are reversed ($min > $max) it returns $min.
     */
    function cuercodeClampInt(int $value, int $min, int $max): int
    {
        // Bounds reversed: return $min on EVERY version (what max(min()) always
        // did). 8.6's clamp() would throw here, so it never sees this case.
        if ($min > $max) {
            return $min;
        }

        // PHP 8.6+: use the built-in. Tested by existence, not version number.
        if (function_exists('clamp')) {
            return clamp($value, $min, $max); // php86-scan-ok: guarded by function_exists
        }

        // PHP 8.4 / 8.5: the long-standing equivalent.
        return max($min, min($max, $value));
    }
}
