<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Abuse reports: the parts with no database (WP4f, #169)
 * ==============================================================================
 *
 * Path: tests/test_abuse_reports.php
 *
 *   1. The categories, and the wider-network fingerprint (IPv4 /24, IPv6
 *      /48), keyed so the database alone cannot be checked against addresses.
 *   2. What a reporter may type as the code (the code, or the printed
 *      QRmy.Link address; nothing else).
 *   3. The form's checks: shapes only.
 *   4. The automatic-suspension rule: N different addresses AND N different
 *      networks (decision 0034, never more aggressive).
 *   5. The settings and their bounds.
 *   6. The human check's no-JavaScript wording for the report form.
 *   7. Source checks on the pages: the order on /report/, one answer for
 *      every case, the minimum time, the neutral page never looks a code up,
 *      the admin screen needs a fresh password, restoring needs a reason.
 *
 * The database parts are proved by tests/integration/dynamic_qr_abuse_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4f, #169)
 * @see         web/CueRCode.net/_functions/fn_abuse_reports.php
 */

requireFn('fn_abuse_reports.php');
requireFn('fn_go2mylink.php');

$arPublic = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR;

// ==============================================================================
// ✅ Section 1: categories and the fingerprints
// ==============================================================================
assertEquals(['phishing', 'malware', 'scam', 'illegal', 'other'], array_keys(abuseReportCategories()), 'the kinds of harm a reporter can choose');
assertEquals('198.51.100.0/24', abuseReporterNetworkKey('198.51.100.7'), 'an IPv4 address belongs to its /24');
assertEquals('198.51.100.0/24', abuseReporterNetworkKey('::ffff:198.51.100.200'), 'an IPv4 address written in IPv6 form is the same /24');
assertEquals('2001:db8:1::/48', abuseReporterNetworkKey('2001:db8:1:2::1'), 'an IPv6 address belongs to its /48');
assertEquals(abuseReporterNetworkKey('2001:db8:1:ffff:abcd::9'), abuseReporterNetworkKey('2001:db8:1:2::1'), 'two /64s in one /48 are one network');
assertTrue(abuseReporterNetworkKey('2001:db8:2::1') !== abuseReporterNetworkKey('2001:db8:1::1'), 'another /48 is another network');
$arKey = str_repeat('k', 64);
assertEquals(64, strlen(abuseReporterNetworkHash('198.51.100.7', $arKey)), 'the network fingerprint is 64 hex characters');
assertEquals(abuseReporterNetworkHash('198.51.100.7', $arKey), abuseReporterNetworkHash('198.51.100.99', $arKey), 'two addresses in one /24 give the same network fingerprint');
assertTrue(abuseReporterNetworkHash('198.51.100.7', $arKey) !== abuseReporterNetworkHash('198.51.100.7', str_repeat('j', 64)), 'the fingerprint depends on the site\'s key (the database alone cannot be checked against addresses)');
assertTrue(abuseReporterNetworkHash('198.51.100.7', $arKey) !== abuseReporterAddressHash('198.51.100.7', $arKey), 'the network fingerprint differs from the address fingerprint');
assertFalse(str_contains(abuseReporterNetworkHash('198.51.100.7', $arKey), '198'), 'no part of the address is in the fingerprint');

// ==============================================================================
// ✅ Section 2: what may be typed as the code
// ==============================================================================
foreach (['Ab3dE9x' => 'Ab3dE9x', ' QRmy.Link/Ab3dE9x ' => 'Ab3dE9x', 'https://qrmy.link/Ab3dE9x' => 'Ab3dE9x', 'https://alpha.QRmy.Link/Ab3dE9x/' => 'Ab3dE9x', 'qrmy.link/Ab3dE9x?src=x' => 'Ab3dE9x'] as $arTyped => $arCode) {
    assertEquals($arCode, abuseReportCodeFromText($arTyped), 'the code is read from ' . var_export($arTyped, true));
}
foreach (['', 'ab', 'https://evil.example/Ab3dE9x', 'https://qrmy.link.evil.example/Ab3dE9x', 'https://qrmy.link/a/b', 'https://qrmy.link/', 'admin', 'Ab3dE9x<script>', str_repeat('a', 51), 'javascript:alert(1)'] as $arBad) {
    assertEquals('', abuseReportCodeFromText($arBad), 'not a code: ' . var_export($arBad, true));
}

// ==============================================================================
// ✅ Section 3: the form's checks
// ==============================================================================
$arGood = abuseReportCheckInput(['code' => 'QRmy.Link/Ab3dE9x', 'category' => 'phishing', 'details' => "Line one\r\nLine two", 'email' => '']);
assertTrue($arGood['ok'] && $arGood['code'] === 'Ab3dE9x' && $arGood['details'] === "Line one\nLine two" && $arGood['email'] === '', 'a good report: the code read, line breaks kept, no email needed');
$arBad = abuseReportCheckInput(['code' => 'nope!', 'category' => 'spam', 'details' => str_repeat('x', 2001), 'email' => 'not-an-email']);
assertEquals(['code', 'category', 'details', 'email'], array_keys($arBad['errors']), 'each field is checked: the code, the category, the length, the email');
$arOther = abuseReportCheckInput(['code' => 'Ab3dE9x', 'category' => 'other', 'details' => 'hm']);
assertTrue(!$arOther['ok'] && isset($arOther['errors']['details']), '"Something else" needs a few words');
$arCtrl = abuseReportCheckInput(['code' => 'Ab3dE9x', 'category' => 'scam', 'details' => "bad\x07bell"]);
assertTrue(!$arCtrl['ok'] && isset($arCtrl['errors']['details']), 'control characters are refused');
$arArray = abuseReportCheckInput(['code' => ['x'], 'category' => ['phishing']]);
assertTrue(!$arArray['ok'] && isset($arArray['errors']['code'], $arArray['errors']['category']), 'a field sent as a list is refused, never an error');

// ==============================================================================
// ✅ Section 4: the automatic-suspension rule (decision 0034)
// ==============================================================================
assertTrue(abuseReportShouldSuspend(3, 3, 3), '3 different people from 3 different networks: suspended');
assertFalse(abuseReportShouldSuspend(2, 2, 3), '2 people: not yet');
assertFalse(abuseReportShouldSuspend(5, 1, 3), '5 addresses from ONE network (one person with many addresses, or one provider): not suspended');
assertFalse(abuseReportShouldSuspend(3, 2, 3), '3 addresses from only 2 networks: not suspended');
assertTrue(abuseReportShouldSuspend(1, 1, 1), 'an admin may set the number to 1');
assertFalse(abuseReportShouldSuspend(9, 9, 0), 'a number below 1 never suspends anything (never more aggressive than set)');

// ==============================================================================
// ✅ Section 5: the settings
// ==============================================================================
$arConfig = static function (array $arValues): callable {
    return static function (string $arKey, mixed $arDefault) use ($arValues): mixed {
        if (array_key_exists($arKey, $arValues)) {
            return $arValues[$arKey];
        }
        return $arDefault;
    };
};
assertEquals(['autoSuspendReports' => 3, 'windowHours' => 24, 'ipPerHour' => 5, 'emailsPerHour' => 30], abuseReportSettings($arConfig([])), 'defaults: 3 people in 24 hours (decision 0034), 5 reports an hour per address, 30 emails an hour');
assertEquals(['autoSuspendReports' => 3, 'windowHours' => 24, 'ipPerHour' => 5, 'emailsPerHour' => 30], abuseReportSettings($arConfig(['abuse_auto_suspend_reports' => '0', 'abuse_auto_suspend_window_hours' => '721', 'abuse_report_ip_per_hour' => 'x', 'abuse_report_emails_per_hour' => '-1'])), 'a number outside its bounds means the default (0 can never switch the rule off or make it fire on nothing)');
assertEquals(['autoSuspendReports' => 5, 'windowHours' => 48, 'ipPerHour' => 2, 'emailsPerHour' => 100], abuseReportSettings($arConfig(['abuse_auto_suspend_reports' => '5', 'abuse_auto_suspend_window_hours' => '48', 'abuse_report_ip_per_hour' => '2', 'abuse_report_emails_per_hour' => '100'])), 'an admin\'s numbers are used');

// ==============================================================================
// ✅ Section 6: the human check's wording for the report form
// ==============================================================================
$arWording = humanCheckEmailRouteWording('report');
assertTrue(is_array($arWording) && str_contains($arWording['explain'], 'does not count towards suspending a code automatically'), 'without JavaScript the report is still sent, and the visitor is told it is not counted automatically');
assertFalse(str_contains((string) $arWording['limit'], 'email'), '...and the limit sentence does not talk about emails (none are sent)');
assertContains('few emails an hour', humanCheckEmailRouteWording('forgot')['limit'], 'the account forms keep their own sentence about emails');

// ==============================================================================
// ✅ Section 7: source checks
// ==============================================================================
$arPageFile = (string) file_get_contents($arPublic . 'report' . DIRECTORY_SEPARATOR . 'index.php');
// 📌 The code only (the file header names the same functions).
$arPage = substr($arPageFile, (int) strpos($arPageFile, '$reportStarted  = hrtime(true);'));
$arCsrf  = strpos($arPage, 'validateCSRFToken(');
$arShape = strpos($arPage, 'abuseReportCheckInput($_POST)');
$arRate  = strpos($arPage, 'abuseReportRateAllowed(');
$arHuman = strpos($arPage, 'humanCheckVerifyRequest(');
$arStore = strpos($arPage, 'abuseReportSubmit(');
assertTrue($arCsrf !== false && $arShape !== false && $arRate !== false && $arHuman !== false && $arStore !== false && $arCsrf < $arRate && $arShape < $arRate && $arRate < $arHuman && $arHuman < $arStore, '/report/: the form token and the shapes, then the per-address limit, then the human check, and only then the report is stored');
assertEquals(1, substr_count($arPage, 'Thank you. Your report has been received'), '/report/: there is ONE thank-you, whatever happened to the report');
assertFalse(str_contains($arPage, "['known']") || str_contains($arPage, "['suspended']") || str_contains($arPage, "['stored']"), '/report/: the page never looks at whether the code was known, stored or suspended');
assertContains('padResponseToMinimumDuration($reportStarted, CUERCODE_ABUSE_REPORT_MIN_MICROSECONDS)', $arPage, '/report/: every answer takes at least the same time');
assertTrue(CUERCODE_ABUSE_REPORT_MIN_MICROSECONDS >= 250000, '...at least a quarter of a second');
assertContains("in_array(\$reportCheck['reason'], ['unavailable', 'misconfigured', 'store_unavailable'], true)", $arPage, '/report/: a human check that is not working keeps the report, uncounted');
assertContains("humanCheckWidgetHtml(null, 'report')", $arPageFile, '/report/: the human check with its no-JavaScript route');
$arAccepted = substr($arPage, (int) strpos($arPage, 'if (humanCheckEmailRouteChosen($_POST) && humanCheckIsOn())'), 1200);
$arRouteBranch = substr($arAccepted, 0, (int) strpos($arAccepted, '} else {'));
assertTrue(str_contains($arRouteBranch, '$reportAccepted = true;') && !str_contains($arRouteBranch, 'reportCounted'), '/report/: a report sent without JavaScript is accepted but never made to count');
$arCheckBranch = substr($arAccepted, (int) strpos($arAccepted, '} else {'));
assertTrue(substr_count($arCheckBranch, '$reportCounted  = true;') === 1 && strpos($arCheckBranch, "if (\$reportCheck['ok'] === true) {") < strpos($arCheckBranch, '$reportCounted  = true;') && strpos($arCheckBranch, '$reportCounted  = true;') < strpos($arCheckBranch, '} elseif ('), '/report/: only a passed (or switched-off) human check makes a report count');

$arNeutral = (string) file_get_contents($arPublic . 'unavailable' . DIRECTORY_SEPARATOR . 'index.php');
foreach (['dbQuery(', 'fetchRedirectRow', 'tblQRRedirects', 'destinationURL'] as $arNever) {
    assertFalse(str_contains($arNeutral, $arNever), 'the neutral page never looks a code up or shows a destination (' . $arNever . ')');
}
assertContains("'/report/?code=' . rawurlencode(\$unavailableCode)", $arNeutral, 'the neutral page links to the report page with the code filled in');
assertContains('dynamicQrTakedownPromise()', $arNeutral, '...and shows the takedown promise');

$arAdmin = (string) file_get_contents($arPublic . 'admin' . DIRECTORY_SEPARATOR . 'abuse' . DIRECTORY_SEPARATOR . 'index.php');
assertTrue(strpos($arAdmin, "\$adminUser = requireGlobalAdminPage('/admin/abuse/');") < strpos($arAdmin, "require_once CUERCODE_FUNCTIONS_DIR . 'fn_abuse_reports.php';"), '/admin/abuse/: the Global Admin gate comes first');
assertContains('adminCheckPost($adminUser, true)', $arAdmin, '/admin/abuse/: every change needs the form token, the same site and a fresh password');
assertFalse((bool) preg_match('/<a [^>]*href="<\?php echo adminEscape\(\(string\) \$abuseRow\[.destinationURL.\]/', $arAdmin), '/admin/abuse/: a destination is never a link');

$arTakedown = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_takedown.php');
$arRestore  = substr($arTakedown, (int) strpos($arTakedown, 'function dynamicQrRestore('), 3000);
assertContains("adminAccessDecision(['userID' => \$adminUserId], adminLoadAccessRow(\$adminUserId)) !== 'admin'", $arRestore, 'lifting a suspension checks the Global Admin again, fresh from the database');
assertContains("\$reason === ''", $arRestore, '...and refuses without a written reason');
$arLocked = substr($arTakedown, (int) strpos($arTakedown, 'function dynamicQrRestoreLocked('), 9000);
assertTrue(strpos($arLocked, "dynamicQrCheckDestinationSafety(\$target, 'restore'") < strpos($arLocked, 'go2mylinkUpdateDestination(') && strpos($arLocked, 'go2mylinkUpdateDestination(') < strpos($arLocked, "'dynamic_code.restore'"), 'lifting: the destination is checked again, Go2My.Link is changed first, and only then the record here');
$arApi = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_redirects.php');
assertFalse(str_contains($arApi, 'dynamicQrRestore('), 'no member route can lift a suspension (only the admin screen calls dynamicQrRestore())');
$arAbuse = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_abuse_reports.php');
assertFalse(str_contains($arAbuse, 'logActivity('), 'storing a report writes no activity-log row (which would keep the reporter\'s internet address)');
assertContains("'applyNow' => false", $arAbuse, 'a suspension from reports is sent to Go2My.Link by the cron job, so the answer takes the same time');

// 🧱 The widget with a provider configured: the report form's no-JavaScript
//    button and its own limit sentence are drawn inside <noscript>.
$arWidgetSettings = humanCheckReadSettings(static function (string $arKey, mixed $arDefault): mixed {
    $arValues = ['turnstile_site_key' => '0x4AAAAAAAtest', 'turnstile_secret_key' => '0x4AAAAAAAsecret', 'bot_protection_provider' => 'auto'];
    if (array_key_exists($arKey, $arValues)) {
        return $arValues[$arKey];
    }
    return $arDefault;
}, static function (string $arKey): string {
    return 'missing';
});
$arWidget = humanCheckWidgetHtml($arWidgetSettings, 'report', ['cachedHealth' => static function (string $arProvider): string {
    return 'up';
}]);
$arNoscript = substr($arWidget, (int) strpos($arWidget, '<noscript>'));
assertTrue(str_contains($arNoscript, 'Send my report without JavaScript') && str_contains($arNoscript, 'name="' . CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD . '"'), 'with a provider set up, the report form offers "Send my report without JavaScript" inside <noscript>');
assertTrue(str_contains($arNoscript, 'each connection can send only a few reports an hour') && !str_contains($arNoscript, 'emails an hour'), '...with the report form\'s own limit sentence (no talk of emails)');
