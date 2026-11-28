<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Database times are compared by the database (#158, #130)
 * ==============================================================================
 *
 * Path: tests/test_expiry_clock.php
 *
 * The database stores times in UTC; PHP's clock follows the site's
 * `site_timezone` setting. Reading a stored time with strtotime() and
 * comparing it with time() is therefore wrong in any other zone. This test
 * needs no database: it reads the source and fails if
 *   1. a stored time column is read with strtotime() inside _functions/, or
 *   2. one of the expiry or lock-out questions stops being asked in SQL.
 * The behaviour itself is proved against a real server, in several zones, by
 * tests/integration/site_timezone_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#158, #130)
 */

$ecFunctions = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR;

// 🕵️ A stored column: an array element whose key ends in "At" or "_at", or
//    is "lockedUntil", "validFrom" or "validUntil", handed to strtotime() or
//    to a date object built without a zone.
$ecPattern = '/(?:strtotime|new\s+\\\\?DateTime(?:Immutable)?)\s*\(\s*(?:\(string\)\s*)?\$\w+\[\'(?:\w+At|\w+_at|lockedUntil|validFrom|validUntil)\'\]\s*\)/';

assertTrue(
    preg_match($ecPattern, "\$x = strtotime((string) \$row['expiresAt']);") === 1,
    'sanity: the scan recognises the faulty form'
);

$ecFound = [];
foreach ((array) glob($ecFunctions . 'fn_*.php') as $ecFile) {
    $ecSource = (string) file_get_contents((string) $ecFile);
    if (preg_match($ecPattern, $ecSource) === 1) {
        $ecFound[] = basename((string) $ecFile);
    }
    // 🪜 The two-step form: $raw = $row['lockedUntil']; ... strtotime($raw).
    if (preg_match('/strtotime\s*\(\s*(?:\(string\)\s*)?\$(?:lockedUntil|expiresAt)\w*/i', $ecSource) === 1) {
        $ecFound[] = basename((string) $ecFile) . ' (two-step)';
    }
}
assertEquals([], $ecFound, '#158/#130: no function reads a stored UTC time in the site time zone');

$ecAuth = (string) file_get_contents($ecFunctions . 'fn_auth.php');
$ecApi  = (string) file_get_contents($ecFunctions . 'fn_api_auth.php');

// 🔢 Early link check, verify, reset, current session and locked re-check
//    (two statements: with and without upgrade 0003).
assertEquals(6, preg_match_all('/`expiresAt` > NOW\(\)\) AS `isLive`/', $ecAuth), '#158/#130: every link and session lookup asks the database whether the row is still live');
// 🔒 Verification and sign-in.
assertEquals(2, substr_count($ecAuth, '(`lockedUntil` IS NOT NULL AND `lockedUntil` > NOW()) AS `isLocked`'), '#158/#130: both lock-out checks are asked in SQL');
assertContains('`lockedUntil` = DATE_ADD(NOW(), INTERVAL ? SECOND)', $ecAuth, '#158/#130: a new lock-out is written with the database clock');
assertContains('(`expiresAt` IS NOT NULL AND `expiresAt` <= NOW()) AS `isExpired`', $ecApi, '#158: API key expiry is asked in SQL');
