<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for "send me a new verification link" (#128)
 * ==============================================================================
 *
 * Path: tests/test_resend_verification.php
 *
 * Covers, WITHOUT a database:
 *   - verificationResendSettings(): defaults, text numbers, junk, clamping, and
 *     a settings reader that throws.
 *   - decideVerificationResend(): every outcome and the ORDER of the checks.
 *   - The one plain message every request gets.
 *   - requestNewVerificationLink() when the database is not available (503, and
 *     nothing that could reveal an account).
 *   - Source-text guards: one token path shared with sign-up, prepared
 *     statements with backticked names, the pending-only rule, the old links
 *     being retired, no address/link/token in log calls, the page's CSRF and
 *     no-JavaScript form, and the links from the verify and sign-in pages.
 *
 * The real SQL (retiring old tokens, the gap and daily cap, the per-IP cap) is
 * checked by the optional tests/integration/resend_verification_real_mysql.php.
 *
 * ⚠️ Variables are prefixed $rv so they cannot clash with other test files
 *    (everything runs in one global scope). It never defines dbQuery(),
 *    getConfig(), logError() or logActivity() - other tests assert they do not exist.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (#128)
 * @see         web/CueRCode.net/_functions/fn_auth.php
 */

requireFn('fn_password.php');
requireFn('fn_auth.php');

// ==============================================================================
// ⚙️ Section 1: settings
// ==============================================================================

$rvDefaults = verificationResendSettings(static function (string $key, mixed $default): mixed {
    return $default;
});
assertEquals(
    ['minGapSeconds' => 60, 'dailyCap' => 5, 'ipPerHour' => 10],
    $rvDefaults,
    'Settings: with nothing set, the defaults are 60 seconds, 5 a day, 10 an hour per IP address'
);

$rvText = verificationResendSettings(static function (string $key, mixed $default): mixed {
    return ['verification_resend_min_gap_seconds' => '120', 'verification_resend_daily_cap' => '3', 'verification_resend_ip_per_hour' => '20'][$key];
});
assertEquals(
    ['minGapSeconds' => 120, 'dailyCap' => 3, 'ipPerHour' => 20],
    $rvText,
    'Settings: numbers stored as text are accepted'
);

$rvJunk = verificationResendSettings(static function (string $key, mixed $default): mixed {
    return 'lots';
});
assertEquals($rvDefaults, $rvJunk, 'Settings: non-numeric values fall back to the defaults');

$rvHuge = verificationResendSettings(static function (string $key, mixed $default): mixed {
    return 999999999;
});
assertEquals(
    ['minGapSeconds' => 86400, 'dailyCap' => 50, 'ipPerHour' => 1000],
    $rvHuge,
    'Settings: silly-large values are pulled down to the safe maximum'
);

$rvTiny = verificationResendSettings(static function (string $key, mixed $default): mixed {
    return 0;
});
assertEquals(
    ['minGapSeconds' => 10, 'dailyCap' => 1, 'ipPerHour' => 1],
    $rvTiny,
    'Settings: zero cannot switch the limits off - it is raised to the safe minimum'
);

$rvThrows = verificationResendSettings(static function (string $key, mixed $default): mixed {
    throw new \RuntimeException('settings unavailable');
});
assertEquals($rvDefaults, $rvThrows, 'Settings: a settings reader that throws gives the defaults');

// ==============================================================================
// 🧠 Section 2: the decision
// ==============================================================================

$rvLimits = ['minGapSeconds' => 60, 'dailyCap' => 5, 'ipPerHour' => 10];
$rvOk     = ['ipRequestsInLastHour' => 1, 'accountIsPending' => true, 'secondsSinceLastLink' => 3600, 'linksInLast24Hours' => 1];

assertEquals(['send' => true, 'reason' => 'send'], decideVerificationResend($rvOk, $rvLimits), 'Decision: a waiting account with room to spare gets a link');

assertEquals(
    'no_pending_account',
    decideVerificationResend(array_merge($rvOk, ['accountIsPending' => false]), $rvLimits)['reason'],
    'Decision: no waiting account (unknown, already verified, suspended) sends nothing'
);

assertEquals(
    'too_soon',
    decideVerificationResend(array_merge($rvOk, ['secondsSinceLastLink' => 59]), $rvLimits)['reason'],
    'Decision: 59 seconds after the last link is too soon'
);
assertTrue(
    decideVerificationResend(array_merge($rvOk, ['secondsSinceLastLink' => 60]), $rvLimits)['send'],
    'Decision: exactly the minimum gap is allowed'
);
assertTrue(
    decideVerificationResend(array_merge($rvOk, ['secondsSinceLastLink' => null, 'linksInLast24Hours' => 0]), $rvLimits)['send'],
    'Decision: an account that has never had a link (no history) is allowed'
);

assertTrue(
    decideVerificationResend(array_merge($rvOk, ['linksInLast24Hours' => 4]), $rvLimits)['send'],
    'Decision: 4 links in a day is still under the cap of 5'
);
assertEquals(
    'daily_cap',
    decideVerificationResend(array_merge($rvOk, ['linksInLast24Hours' => 5]), $rvLimits)['reason'],
    'Decision: 5 links in a day reaches the cap'
);

assertTrue(
    decideVerificationResend(array_merge($rvOk, ['ipRequestsInLastHour' => 10]), $rvLimits)['send'],
    'Decision: the 10th request from one address in an hour is allowed'
);
assertEquals(
    'ip_limited',
    decideVerificationResend(array_merge($rvOk, ['ipRequestsInLastHour' => 11]), $rvLimits)['reason'],
    'Decision: the 11th request from one address in an hour is held back'
);

assertEquals(
    'ip_limited',
    decideVerificationResend(array_merge($rvOk, ['ipRequestsInLastHour' => 11, 'accountIsPending' => false]), $rvLimits)['reason'],
    'Decision order: the address limit is checked before whether an account exists'
);
assertEquals(
    'too_soon',
    decideVerificationResend(array_merge($rvOk, ['secondsSinceLastLink' => 5, 'linksInLast24Hours' => 9]), $rvLimits)['reason'],
    'Decision order: the minimum gap is checked before the daily cap'
);

// ==============================================================================
// 🙈 Section 3: the one plain message, and the no-database answer
// ==============================================================================

assertEquals(
    'If that address is waiting for verification, a new link is on its way. Please check your inbox and your spam folder.',
    CUERCODE_RESEND_VERIFICATION_MESSAGE,
    'The one answer every request gets is the agreed plain sentence'
);

$rvNoDb = requestNewVerificationLink('someone@example.invalid', '203.0.113.9');
assertFalse($rvNoDb['success'], 'With no database the request reports it is temporarily unavailable');
assertEquals(503, $rvNoDb['httpCode'], 'With no database the code is 503');
assertFalse(str_contains(json_encode($rvNoDb), 'someone@example.invalid'), 'The no-database answer never repeats the address');

// ==============================================================================
// 🔎 Section 4: source-text guards
// ==============================================================================

$rvSite    = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$rvAuth    = (string) file_get_contents($rvSite . '_functions' . DIRECTORY_SEPARATOR . 'fn_auth.php');
$rvPage    = (string) file_get_contents($rvSite . 'public_html' . DIRECTORY_SEPARATOR . 'resend-verification' . DIRECTORY_SEPARATOR . 'index.php');
$rvVerify  = (string) file_get_contents($rvSite . 'public_html' . DIRECTORY_SEPARATOR . 'verify-email' . DIRECTORY_SEPARATOR . 'index.php');
$rvLogin   = (string) file_get_contents($rvSite . 'public_html' . DIRECTORY_SEPARATOR . 'login' . DIRECTORY_SEPARATOR . 'index.php');

// 📌 Cut out just the resend section and registerUser() for narrow checks.
$rvStart   = strpos($rvAuth, 'Ask for a NEW verification link (#128)');
$rvEnd     = strpos($rvAuth, '✅ verifyEmailToken()');
$rvSection = ($rvStart !== false && $rvEnd !== false) ? substr($rvAuth, $rvStart, $rvEnd - $rvStart) : '';
assertTrue($rvSection !== '', 'Guard: the resend section is present in fn_auth.php');

$rvRegStart = strpos($rvAuth, 'function registerUser(');
$rvRegEnd   = strpos($rvAuth, 'function verifyEmailToken(');
$rvRegister = substr($rvAuth, $rvRegStart, $rvRegEnd - $rvRegStart);

assertContains('issueEmailVerificationLink(', $rvRegister, 'Guard: sign-up makes its link with the shared helper');
assertFalse(str_contains($rvRegister, 'INSERT INTO `tblEmailVerifications`'), 'Guard: sign-up no longer has its own copy of the token insert');
assertContains('issueEmailVerificationLink((int) $account[\'userID\'], $normalizedEmail, $displayName, true)', $rvSection, 'Guard: the resend uses the same helper and retires earlier links');
assertContains("`usedAt` IS NULL AND `verificationID` <> ?", $rvAuth, 'Guard: earlier unused links are retired, never the new one');
assertContains("(string) \$account['status'] === 'pending'", $rvSection, 'Guard: only a pending account is eligible');
assertContains("\$account['emailVerifiedAt'] === null", $rvSection, 'Guard: only an account with no verified time is eligible');
assertFalse(str_contains($rvSection, 'logError(\'error\', \'Resend-verification lookup failed\', $email'), 'Guard: errors never log the address');

// 🧪 Every dbQuery( in the section is a prepared statement (SQL, then types, then values) with backticked names.
assertTrue(preg_match_all('/dbQuery\(/', $rvSection) >= 3, 'Guard: the resend section talks to the database through dbQuery()');
assertFalse(str_contains($rvSection, '->query('), 'Guard: no raw query() call in the resend section');
assertFalse(preg_match('/FROM tbl|UPDATE tbl|INTO tbl/', $rvSection . $rvAuth) === 1, 'Guard: every table name in fn_auth.php SQL is backticked');

// 🙈 Log calls never carry the address, link or token.
preg_match_all('/logActivity\((.*?)\);/s', $rvSection, $rvLogCalls);
assertTrue(count($rvLogCalls[0]) === 2, 'Guard: the resend section has exactly two activity-log calls');
foreach ($rvLogCalls[1] as $rvCall) {
    assertFalse(
        preg_match('/\$(email|normalizedEmail|rawToken|verificationUrl|ipAddress)\b/', $rvCall) === 1,
        'Guard: an activity-log call carries no address, token, link or IP'
    );
}

// 🖥️ The page.
assertContains('csrfField()', $rvPage, 'Page: the form carries the CSRF field');
assertContains('validateCSRFToken(', $rvPage, 'Page: the POST checks the CSRF token');
assertContains('method="POST"', $rvPage, 'Page: a normal POST form (works without JavaScript)');
assertContains('$pageJS          = [];', $rvPage, 'Page: needs no JavaScript');
assertContains('for="resend-email"', $rvPage, 'Page: the email box has a label');
assertContains('role="status"', $rvPage, 'Page: the answer is announced to screen readers');
assertContains('$pageNoIndex     = true;', $rvPage, 'Page: kept out of search results');
assertFalse(str_contains($rvPage, '<table'), 'Page: no layout table');
assertContains('/resend-verification/', $rvVerify, 'The verify page links to the new-link form');
assertContains('/resend-verification/', $rvLogin, 'The sign-in page links to the new-link form');
