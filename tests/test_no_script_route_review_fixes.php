<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The no-JavaScript route: the merged review's fixes (#163, M1 L1-L6 I1-I5)
 * ==============================================================================
 *
 * Path: tests/test_no_script_route_review_fixes.php
 *
 * WHAT THIS PROVES (plain English), one block per finding of the independent
 * review .claude/plans/163-merged-review-report.md. Everything here runs
 * without a database or a real provider; the real-database and real-HTTP
 * proof (the same answer for a right and a wrong password, the follow-up
 * pages, the limits on the real table) is in
 * tests/integration/human_check_real_mysql.php.
 *
 *   M1  The email sign-in gives ONE answer whatever the password; the
 *       "limited" answer no longer mentions the email address (it is only
 *       about the visitor's connection now).
 *   L1  A form on the route that sends no email does not use up the limits
 *       on emails: the reserved email place is given back.
 *   L3  The per-mailbox count treats "+tag" spellings, and dots and
 *       googlemail.com for Gmail, as one mailbox (counting only).
 *   L4  With the saved keys unreadable ("broken"), the route button is
 *       drawn for everyone and the admin status no longer claims every form
 *       is refused.
 *   L5  The pages hand the work to ONE helper, and the helper never does the
 *       work when the limits say no (checked with stand-ins, not by looking
 *       for a function name).
 *   L6  An approval made in another browser runs out after 3 minutes unless
 *       Continue is pressed; the approve page can describe the asking browser.
 *   I2  Separate wording for "your connection" and "busy".
 *   I3  A request over the per-address limit is answered at once, without
 *       the lock wait or the minimum time.
 *   I4  While the server's own fresh test says a provider is down, a token
 *       is not sent to that provider at all (no long wait).
 *   I5  IPv6 addresses are counted per /64 network on the route.
 *   0005 compares the starting values byte for byte.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#163, merged review fixes)
 * @see         web/CueRCode.net/_functions/fn_no_script_route.php
 * @see         web/CueRCode.net/_functions/fn_human_check.php
 */

requireFn('fn_human_check.php');
requireFn('fn_no_script_route.php');

$nsSiteRoot = CUERCODE_SITE_ROOT;
$nsDs       = DIRECTORY_SEPARATOR;

// ==============================================================================
// 🧰 Helpers
// ==============================================================================

/**
 * ⚙️ A settings getter (key, default) from a plain array.
 *
 * @param array<string, mixed> $values
 * @return callable
 */
function nsGetter(array $values): callable
{
    return static function (string $key, mixed $default) use ($values): mixed {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $default;
    };
}

/**
 * ✂️ PHP source without comments.
 *
 * @param string $source
 * @return string
 */
function nsCodeOnly(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }
    return $code;
}

/**
 * 🧪 Run noScriptRouteRunForm() with stand-ins and report what happened.
 *
 * @param array $gateAnswer What the stand-in gate answers
 * @param int   $emailsQueuedByWork How many emails the stand-in work queues
 * @return array{workRan: bool, released: array<int, int>, padded: bool, result: array}
 */
function nsRunForm(array $gateAnswer, int $emailsQueuedByWork): array
{
    $seen = ['workRan' => false, 'released' => [], 'padded' => false, 'result' => []];
    $queued = 0;

    if (!function_exists('noScriptRouteRunForm')) {
        return $seen;
    }

    $seen['result'] = noScriptRouteRunForm(
        'forgot',
        'person@example.com',
        '198.51.100.9',
        static function () use (&$seen, &$queued, $emailsQueuedByWork): void {
            $seen['workRan'] = true;
            $queued += $emailsQueuedByWork;
        },
        [
            'gate'        => static function (string $purpose, string $email, string $ip, bool $reserve) use ($gateAnswer): array {
                return $gateAnswer;
            },
            'release'     => static function (int $requestID) use (&$seen): void {
                $seen['released'][] = $requestID;
            },
            'queuedCount' => static function () use (&$queued): int {
                return $queued;
            },
            'pad'         => static function (int $startedAtNanos) use (&$seen): void {
                $seen['padded'] = true;
            },
        ]
    );

    return $seen;
}

// ==============================================================================
// M1 + I2: one answer whatever the password; plain "limited" and "busy" words
// ==============================================================================

assertTrue(defined('CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE'), 'M1: there is ONE "we have emailed you a link" answer for the email sign-in');
if (defined('CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE')) {
    assertTrue(str_starts_with(CUERCODE_NO_SCRIPT_SIGN_IN_SENT_MESSAGE, 'If the email address and password are right'), 'M1: the answer says "if the email address and password are right" (it is given for a wrong password too)');
}
assertFalse(str_contains(CUERCODE_NO_SCRIPT_LIMIT_MESSAGE, 'for this address'), 'I2: the "limited" answer is only about the visitor\'s connection (the per-mailbox limit is no longer shown before the password)');
assertTrue(defined('CUERCODE_NO_SCRIPT_BUSY_MESSAGE'), 'I2: there is a separate "busy just now" answer for the lock');
$nsRouteCode = nsCodeOnly((string) file_get_contents($nsSiteRoot . '_functions' . $nsDs . 'fn_no_script_route.php'));
$nsStartBody = substr($nsRouteCode, (int) strpos($nsRouteCode, 'function noScriptSignInStartWork('), 6000);
$nsStartBody = substr($nsStartBody, 0, (int) strpos($nsStartBody, "\nfunction "));
assertFalse(str_contains($nsStartBody, 'loginUserGenericFailure('), 'M1: the email sign-in never gives the "invalid email or password" answer (that answer is what told a script the password was wrong)');

// ==============================================================================
// L1 + L5 + I3: one helper for sign-up, forgot password and new link
// ==============================================================================

assertTrue(function_exists('noScriptRouteRunForm'), 'L5: one helper runs sign-up, forgot password and new link on the route');

$nsSeen = nsRunForm(['allowed' => false, 'reason' => 'ip', 'requestID' => 0, 'emailReserved' => false], 1);
assertFalse($nsSeen['workRan'], 'L5: over the per-address limit: the form\'s work is NOT done (no account, no email)');
assertFalse($nsSeen['padded'], 'I3: over the per-address limit: answered at once, without the minimum time (nothing about any account is looked at)');

$nsSeen = nsRunForm(['allowed' => true, 'reason' => 'email', 'requestID' => 7, 'emailReserved' => false], 1);
assertFalse($nsSeen['workRan'], 'L5: the mailbox (or this form\'s site-wide) email limit is used up: the work is NOT done');
assertTrue($nsSeen['padded'], 'L5: ... and the answer still takes the minimum time (the same answer whatever happened)');

$nsSeen = nsRunForm(['allowed' => false, 'reason' => 'busy', 'requestID' => 0, 'emailReserved' => false], 1);
assertFalse($nsSeen['workRan'], 'L5: the lock could not be had: the work is NOT done (fails closed)');

$nsSeen = nsRunForm(['allowed' => true, 'reason' => 'ok', 'requestID' => 11, 'emailReserved' => true], 0);
assertTrue($nsSeen['workRan'], 'L1: within the limits: the work is done');
assertEquals([11], $nsSeen['released'], 'L1: no email was queued (for example forgot password for an address with no account): the reserved email place is given back, so made-up addresses cannot use up the site-wide limit');

$nsSeen = nsRunForm(['allowed' => true, 'reason' => 'ok', 'requestID' => 12, 'emailReserved' => true], 1);
assertTrue($nsSeen['workRan'] && $nsSeen['released'] === [], 'L1: an email was queued: the place stays used (it counts against the mailbox and the site-wide limits)');

foreach ([
    'register'            => ['registerUser(', "noScriptRouteRunForm('register'"],
    'forgot-password'     => ['requestPasswordReset(', "noScriptRouteRunForm('forgot'"],
    'resend-verification' => ['requestNewVerificationLink(', "noScriptRouteRunForm('resend'"],
] as $nsPage => $nsNeed) {
    $nsCode = nsCodeOnly((string) file_get_contents($nsSiteRoot . 'public_html' . $nsDs . $nsPage . $nsDs . 'index.php'));
    assertContains($nsNeed[1], $nsCode, 'L5: /' . $nsPage . '/ hands the route to the one helper, which decides from the limits');
    assertFalse(str_contains($nsCode, 'noScriptRouteGate('), 'L5: /' . $nsPage . '/ no longer reads the limits itself (nothing on the page can ignore their answer)');
    assertEquals(2, substr_count($nsCode, $nsNeed[0]), 'L5: /' . $nsPage . '/ does its work in exactly two places: inside the helper\'s work (route) and after the human check (normal form)');
}

// ==============================================================================
// L3: one mailbox, one count (counting only)
// ==============================================================================

assertTrue(function_exists('noScriptEmailCountingForm'), 'L3: there is one rule for the form an address is COUNTED under');
if (function_exists('noScriptEmailCountingForm')) {
    assertEquals('target@example.com', noScriptEmailCountingForm(' Target+1@Example.com '), 'L3: "+tag" is ignored for counting (capitals and spaces too)');
    assertEquals('target@example.com', noScriptEmailCountingForm('target+a+b@example.com'), 'L3: everything after the first "+" is ignored');
    assertEquals('firstlast@gmail.com', noScriptEmailCountingForm('First.Last+news@googlemail.com'), 'L3: for Gmail, dots and googlemail.com do not matter');
    assertEquals('first.last@example.com', noScriptEmailCountingForm('first.last@example.com'), 'L3: dots matter for every other provider');
    assertEquals('no-at-sign', noScriptEmailCountingForm('No-At-Sign'), 'L3: a string with no "@" is only lower-cased');
}
assertEquals(noScriptEmailHash('target+1@example.com', 'k'), noScriptEmailHash('target+29@example.com', 'k'), 'L3: target+1 and target+29 count as the same mailbox');
assertTrue(noScriptEmailHash('target@example.com', 'k') !== noScriptEmailHash('other@example.com', 'k'), 'L3: different mailboxes are counted apart');

// ==============================================================================
// I5: IPv6 addresses counted per /64 on the route
// ==============================================================================

// 📌 #177: the rule moved to fn_client_ip.php (clientAddressCountingKey()) so
//    every per-address limit uses it; tests/test_client_ip.php proves it, and
//    that this route uses it.
assertTrue(function_exists('clientAddressCountingKey') && !function_exists('noScriptAddressKey'), 'I5 / #177: the route counts visitor addresses by the one shared rule (an IPv6 address as its /64)');
if (function_exists('clientAddressCountingKey')) {
    assertEquals('2001:db8:1:2::/64', clientAddressCountingKey('2001:DB8:1:2::99'), 'I5: the key is the /64 network');
}

// ==============================================================================
// L6: approvals run out; the asking browser can be described
// ==============================================================================

assertTrue(defined('CUERCODE_NO_SCRIPT_APPROVAL_SECONDS') && CUERCODE_NO_SCRIPT_APPROVAL_SECONDS === 180, 'L6: an approval from another browser lasts 3 minutes');
assertEquals('sign_in', noScriptSignInContinueDecision(['status' => 'approved', 'secondsLeft' => 600, 'approvedSecondsAgo' => 30], true), 'L6: Continue 30 seconds after the approval: signed in');
assertEquals('invalid', noScriptSignInContinueDecision(['status' => 'approved', 'secondsLeft' => 600, 'approvedSecondsAgo' => 181], true), 'L6: Continue more than 3 minutes after the approval: NOT signed in (someone at a shared computer later cannot finish it)');
assertEquals('invalid', noScriptSignInContinueDecision(['status' => 'approved', 'secondsLeft' => 600], true), 'L6: an approval with no known time counts as run out (the safe side)');
assertTrue(function_exists('noScriptDescribeBrowser'), 'L6: the approve page can name the asking browser in plain words');
if (function_exists('noScriptDescribeBrowser')) {
    assertEquals('Firefox on Windows', noScriptDescribeBrowser('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0'), 'L6: Firefox on Windows');
    assertEquals('Edge on Windows', noScriptDescribeBrowser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'), 'L6: Edge is not mistaken for Chrome');
    assertEquals('Chrome on Android', noScriptDescribeBrowser('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'), 'L6: Chrome on Android (not "Linux")');
    assertEquals('Safari on iPhone', noScriptDescribeBrowser('Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'), 'L6: Safari on iPhone (not "Mac")');
    assertEquals('Safari on a Mac', noScriptDescribeBrowser('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15'), 'L6: Safari on a Mac');
    assertEquals('an unknown browser', noScriptDescribeBrowser(''), 'L6: nothing sent: "an unknown browser"');
    assertEquals('an unknown browser', noScriptDescribeBrowser('curl/8.7.1'), 'L6: a program that names no known browser: "an unknown browser"');
}

// ==============================================================================
// L4: keys unreadable ("broken"): the route button is drawn for everyone
// ==============================================================================

$nsBrokenSettings = humanCheckReadSettings(
    nsGetter(['bot_protection_provider' => 'auto', 'site_url' => 'https://cuercode.net', 'turnstile_site_key' => 'site-key-visible']),
    static function (string $key): string {
        if ($key === 'turnstile_secret_key') {
            return 'present';
        }
        return 'missing';
    }
);
assertEquals('broken', humanCheckPlan($nsBrokenSettings)['state'], 'L4: (set-up) a saved secret that cannot be read makes the plan "broken"');
foreach (['login', 'register', 'forgot', 'resend'] as $nsPurpose) {
    $nsHtml = humanCheckWidgetHtml($nsBrokenSettings, $nsPurpose);
    $nsButtonAt = strpos($nsHtml, 'name="human_check_email_route"');
    assertTrue($nsButtonAt !== false, 'L4: form ' . $nsPurpose . ': with the keys unreadable, the email-confirmed route button is drawn');
    assertFalse(str_contains($nsHtml, '<noscript>'), 'L4: form ' . $nsPurpose . ': ... for everyone, not only inside <noscript> (people with JavaScript have no other way in while the keys are fixed)');
    assertFalse(str_contains($nsHtml, 'site-key-visible'), 'L4: form ' . $nsPurpose . ': ... and no provider widget or key is drawn');
}
$nsBrokenStatus = implode(' ', humanCheckStatusLines($nsBrokenSettings));
assertFalse(str_contains($nsBrokenStatus, 'Refusing every protected form'), 'L4: the admin status no longer claims every protected form is refused');
assertContains('email-confirmed route', $nsBrokenStatus, 'L4: the admin status says the email-confirmed route still works');
$nsHumanCode = (string) file_get_contents($nsSiteRoot . '_functions' . $nsDs . 'fn_human_check.php');
assertFalse(str_contains($nsHumanCode, 'every protected form is refused'), 'L4: no wording in fn_human_check.php says every protected form is refused');
$nsAccountJs = (string) file_get_contents($nsSiteRoot . 'public_html' . $nsDs . 'assets' . $nsDs . 'js' . $nsDs . 'cuercode-account.js');
assertContains('human_check_email_route', $nsAccountJs, 'L4: the page script lets the route button send the form normally (it would otherwise turn it into a JSON call that the broken check refuses)');

// ==============================================================================
// I4: a fresh "down" from the server's own test: the token is not sent at all
// ==============================================================================

$nsTransportCalls = 0;
$nsOptions = [
    'configGetter' => nsGetter(['bot_protection_provider' => 'auto', 'site_url' => 'https://cuercode.net', 'turnstile_site_key' => 'ts-site', 'turnstile_secret_key' => 'ts-secret']),
    'storedState'  => static function (string $key): string {
        return 'missing';
    },
    'transport'    => static function (string $url, array $fields, int $timeout) use (&$nsTransportCalls): array {
        $nsTransportCalls++;
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'timed out', 'fault' => 'network'];
    },
    'stateRead'    => static function (string $key): ?array {
        if ($key === 'health:turnstile') {
            return ['value' => 'down', 'ageSeconds' => 5];
        }
        return null;
    },
    'health'       => static function (string $provider, int $maxAgeSeconds): string {
        return 'down';
    },
    'rateLimiter'  => static function (int $limit): bool {
        return true;
    },
    'outageLogger' => static function (string $event, array $details): bool {
        return true;
    },
    'logDue'       => static function (string $key, int $seconds): bool {
        return false;
    },
    'allowedHosts' => ['cuercode.net'],
];
$nsLogin = humanCheckVerifyRequest(['cf-turnstile-response' => 'a-token'], array_merge($nsOptions, ['purpose' => 'login']));
assertEquals(0, $nsTransportCalls, 'I4: the server\'s own test said "down" 5 seconds ago: the visitor\'s token is NOT sent to the provider (no 5-second wait)');
assertEquals('outage_signin', $nsLogin['reason'], 'I4: ... and sign-in follows the outage rule as before (decision 0037)');
$nsRegister = humanCheckVerifyRequest(['cf-turnstile-response' => 'a-token'], array_merge($nsOptions, ['purpose' => 'register']));
assertEquals('unavailable', $nsRegister['reason'], 'I4: ... sign-up is refused with "try again" (only sign-in can use the outage rule)');
$nsUpOptions = $nsOptions;
$nsUpOptions['stateRead'] = static function (string $key): ?array {
    return ['value' => 'up', 'ageSeconds' => 5];
};
humanCheckVerifyRequest(['cf-turnstile-response' => 'a-token'], array_merge($nsUpOptions, ['purpose' => 'login']));
assertEquals(1, $nsTransportCalls, 'I4: when the test said "up", the token IS sent to the provider');
$nsOldOptions = $nsOptions;
$nsOldOptions['stateRead'] = static function (string $key): ?array {
    return ['value' => 'down', 'ageSeconds' => CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS + 1];
};
humanCheckVerifyRequest(['cf-turnstile-response' => 'a-token'], array_merge($nsOldOptions, ['purpose' => 'login']));
assertEquals(2, $nsTransportCalls, 'I4: an OLD "down" (over a minute) is not trusted: the token is sent again');

// ==============================================================================
// 0005: the starting values are compared byte for byte
// ==============================================================================

$nsMigrationText = (string) file_get_contents($nsSiteRoot . '_includes' . $nsDs . 'sql' . $nsDs . 'migrations' . $nsDs . '0005_human_check_settings.php');
assertContains("CAST(`settingValue` AS BINARY) = CAST(\\'none\\' AS BINARY)", $nsMigrationText, '0005: "none" is matched byte for byte (a stored "NONE" or "none " is someone\'s own value and is kept)');
assertContains('CAST(`description` AS BINARY) = CAST(', $nsMigrationText, '0005: the starting description is matched byte for byte');
assertFalse(str_contains($nsMigrationText, "AND `settingValue` = \\'none\\'"), '0005: no case-blind compare of "none" is left');
assertContains('`emailSent`', $nsMigrationText, 'L1: 0005: the route\'s record says which uses actually sent an email (only those count against the email limits)');

// ==============================================================================
// Third-round review, Medium 1: the route never locks the ACCOUNT (its own
// per-account number can still keep a person without JavaScript out of the
// route for a day: an accepted risk, decision 0044)
// ==============================================================================

$nsBounds = noScriptRouteBounds();
assertTrue(isset($nsBounds['password_per_day']) && $nsBounds['password_per_day']['default'] === 10 && $nsBounds['password_per_day']['min'] === 1 && $nsBounds['password_per_day']['max'] === 100, 'Medium 1: there is a per-account number of wrong passwords the route checks a day (default 10, 1-100), so guessing stays bounded per account without the lock-out');
assertEquals(10, noScriptRouteSettings(nsGetter([]))['passwordPerDay'] ?? null, 'Medium 1: nothing saved means the default of 10');
assertEquals(1, noScriptRouteSettings(nsGetter(['no_script_route_password_per_day' => '0']))['passwordPerDay'] ?? null, 'Medium 1: 0 is pulled up to 1 (never "no limit")');
assertEquals(100, noScriptRouteSettings(nsGetter(['no_script_route_password_per_day' => '5000']))['passwordPerDay'] ?? null, 'Medium 1: a huge number is pulled down to 100');
assertContains("loginUserCheckPassword(\$email, \$password, false)", $nsStartBody, 'Medium 1: the route checks the password WITHOUT counting a wrong one toward the lock-out');
assertContains("noScriptRouteGate('login', \$email, \$ip, false, true)", $nsStartBody, 'Medium 1: the route asks the gate for a password-check place before the password');
assertContains('cuercodeDummyPasswordHash()', $nsStartBody, 'Medium 1: past the per-account number the same time is spent on a stand-in check (the answer and timing stay the same)');
$nsAuthCode  = nsCodeOnly((string) file_get_contents($nsSiteRoot . '_functions' . $nsDs . 'fn_auth.php'));
$nsCheckBody = substr($nsAuthCode, (int) strpos($nsAuthCode, 'function loginUserCheckPassword('), 6000);
$nsCheckBody = substr($nsCheckBody, 0, (int) strpos($nsCheckBody, "\nfunction "));
assertContains('if ($countWrongPassword && !$isLocked', $nsCheckBody, 'Medium 1: a wrong password is counted toward the lock-out only when the caller asks (the normal sign-in does)');
$nsLoginBody = substr($nsAuthCode, (int) strpos($nsAuthCode, 'function loginUser('), 3000);
assertContains('loginUserCheckPassword($email, $plainPassword);', $nsLoginBody, 'Medium 1: the normal sign-in still counts wrong passwords (the default)');
