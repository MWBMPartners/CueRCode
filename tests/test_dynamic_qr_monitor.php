<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The daily monitoring email: the parts with no database (WP4g, #170)
 * ==============================================================================
 *
 * Path: tests/test_dynamic_qr_monitor.php
 *
 *   1. What Go2My.Link should show for a code (lead choice L5): the
 *      destination while it is on, the neutral page while it is off or
 *      suspended; and what counts as a difference.
 *   2. A long section is cut, and says how many more there are.
 *   3. Source checks: the daily job compares with Go2My.Link and sends the
 *      email, at most once per 20 hours; the comparison is bounded and
 *      needs a declared site copy; addresses in the email are defanged and
 *      owners are named by account number only.
 *
 * The database parts are proved by tests/integration/dynamic_qr_abuse_real_mysql.php (section L).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4g, #170)
 * @see         web/CueRCode.net/_functions/fn_dynamic_qr_monitor.php
 */

requireFn('fn_dynamic_qr_monitor.php');

// ==============================================================================
// ✅ Section 1: what Go2My.Link should show
// ==============================================================================
$moNeutral = 'https://cuercode.net/unavailable/?code=Ab3dE9x';
$moRow = ['isActive' => 1, 'disabledReason' => null, 'suspendedAt' => null, 'destinationURL' => 'https://example.com/menu'];
assertEquals('https://example.com/menu', dynamicQrExpectedRemote($moRow, $moNeutral), 'a working code: its destination');
assertEquals($moNeutral, dynamicQrExpectedRemote(array_merge($moRow, ['isActive' => 0, 'disabledReason' => 'owner']), $moNeutral), 'switched off by its owner: the neutral page');
assertEquals($moNeutral, dynamicQrExpectedRemote(array_merge($moRow, ['suspendedAt' => '2026-11-28 10:00:00']), $moNeutral), 'suspended: the neutral page (even though the owner had it on)');
assertEquals('', dynamicQrRemoteDifference('https://example.com/menu', ['destinationUrl' => 'https://example.com/menu', 'isActive' => true]), 'the same on both sides: no difference');
assertEquals('Go2My.Link sends scans somewhere else', dynamicQrRemoteDifference('https://example.com/menu', ['destinationUrl' => 'https://evil.example/', 'isActive' => true]), 'a different destination on Go2My.Link is a difference');
assertEquals('switched off on Go2My.Link', dynamicQrRemoteDifference('https://example.com/menu', ['destinationUrl' => 'https://example.com/menu', 'isActive' => false]), 'switched off on Go2My.Link is a difference');

// ==============================================================================
// ✅ Section 2: long sections
// ==============================================================================
$moLines = [];
for ($moI = 0; $moI < 60; $moI++) {
    $moLines[] = 'line ' . $moI;
}
$moTrimmed = dynamicQrMonitorTrim($moLines);
assertTrue(count($moTrimmed) === 51 && str_contains($moTrimmed[50], '10 more'), 'a section shows at most 50 lines, and counts the rest');
assertEquals(['a', 'b'], dynamicQrMonitorTrim(['a', 'b']), 'a short section is left alone');

// ==============================================================================
// ✅ Section 3: source checks
// ==============================================================================
$moDaily = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'cron' . DIRECTORY_SEPARATOR . 'dynamic_qr_daily.php');
foreach (['dynamicQrRecheckSafety()', 'dynamicQrCompareWithGo2MyLink()', 'dynamicQrMonitorReport(', 'dynamicQrMonitorSend($dailyReport, $dailyForce)'] as $moStep) {
    assertContains($moStep, $moDaily, 'the daily job runs ' . $moStep);
}
assertTrue(strpos($moDaily, '$dailyRecheck = dynamicQrRecheckSafety();') < strpos($moDaily, '$dailyCompare = dynamicQrCompareWithGo2MyLink();') && strpos($moDaily, '$dailyCompare = dynamicQrCompareWithGo2MyLink();') < strpos($moDaily, '$dailyReport = dynamicQrMonitorReport('), '...in order: re-check, compare, then the report (so the email tells today\'s results)');
assertEquals(72000, CUERCODE_MONITOR_EMAIL_GAP_SECONDS, 'at most one daily email per 20 hours');
assertEquals(500, CUERCODE_MONITOR_COMPARE_CAP, 'at most 500 codes compared with Go2My.Link a day');

$moSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_monitor.php');
$moCompare = substr($moSource, (int) strpos($moSource, 'function dynamicQrCompareWithGo2MyLink('), 5000);
assertTrue(strpos($moCompare, 'dynamicQrCronCopyProblem($options)') < strpos($moCompare, 'go2mylinkLookupLink('), 'the comparison never calls Go2My.Link before checking the declared site copy');
assertContains('dynamicQrMarkSuspensionUnapplied(', $moCompare, 'a suspended code Go2My.Link points elsewhere gets its takedown sent again');
$moReport = substr($moSource, (int) strpos($moSource, 'function dynamicQrMonitorReport('), 12000);
assertTrue(substr_count($moReport, 'dynamicQrDefangUrl(') === 5 && substr_count($moReport, "\$row['destinationURL']") === substr_count($moReport, "dynamicQrDefangUrl((string) \$row['destinationURL'])"), 'every destination in the email is defanged (new, changed from and to, hits, differences)');
assertFalse(str_contains($moReport, '`o`.`email`') || str_contains($moReport, 'ownerEmail'), 'owners are named by account number only, never by email address');
assertContains("\$due('log:dynamic_qr_daily_email', CUERCODE_MONITOR_EMAIL_GAP_SECONDS)", $moSource, 'the once-a-day rule uses the shared bounded record (one statement, so two runs cannot both send)');
