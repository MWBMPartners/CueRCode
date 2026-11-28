<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Human check: the independent review's fixes (#163, M1 M2 L1-L5)
 * ==============================================================================
 *
 * Path: tests/test_human_check_review_fixes.php
 *
 * WHAT THIS PROVES (plain English), one block per finding of the independent
 * review of the human check (.claude/plans/163-review-report.md):
 *
 *   M1  A token the provider accepted is remembered (only as a SHA-256 hash),
 *       so the same token sent again is refused, even if the provider would
 *       say yes twice. If the record cannot be written, the form is refused.
 *   M2  Saved keys that can no longer be read (a changed ENCRYPTION_KEY, a
 *       damaged row) do NOT switch the check off: the form is refused and
 *       the admin status says the keys cannot be read.
 *   L1  A reply in the providers' documented shape is followed whatever its
 *       HTTP status; faults on our own server (curl missing, the safe
 *       request settings not applied, a certificate problem) count as "our
 *       set-up is wrong" (refused), never as "the provider is down".
 *   L2  The host name in the provider's reply is compared with the site's
 *       own names (the website address setting and the product domains),
 *       never with the Host header the visitor sent.
 *   L3  The "every page makes the call" checks ignore comments, so
 *       commenting the call out fails them.
 *   L5  hCaptcha's "not-using-dummy-passcode" is the visitor's fault (failed)
 *       unless our OWN saved site key is one of hCaptcha's test site keys.
 *   +   The four web forms are rate limited per visitor address BEFORE the
 *       call to the provider.
 *
 *   NO REAL PROVIDER AND NO DATABASE ARE USED: every reply, record and
 *   counter is a stand-in handed to humanCheckVerifyRequest().
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, independent review fixes)
 * @see         web/CueRCode.net/_functions/fn_human_check.php
 */

requireFn('fn_human_check.php');

$rfSiteRoot = CUERCODE_SITE_ROOT;
$rfDs       = DIRECTORY_SEPARATOR;

// ==============================================================================
// 🧰 Helpers
// ==============================================================================

/**
 * ⚙️ A settings getter (key, default) from a plain array.
 *
 * @param array<string, mixed> $values
 * @return callable
 */
function rfGetter(array $values): callable
{
    return static function (string $key, mixed $default) use ($values): mixed {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $default;
    };
}

/**
 * 🔑 Settings with one provider's two keys entered.
 *
 * @param string               $provider
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function rfKeys(string $provider, array $extra = []): array
{
    return array_merge([
        'bot_protection_provider' => 'auto',
        $provider . '_site_key'   => 'site-' . $provider . '-PUBLIC',
        $provider . '_secret_key' => 'secret-' . $provider . '-PRIVATE',
        'site_url'                => 'https://cuercode.net',
    ], $extra);
}

/**
 * 📨 A stand-in transport that records each call and gives one reply.
 *
 * @param array<string, mixed> $reply
 * @param array<int, array>    $calls
 * @return callable
 */
function rfTransport(array $reply, array &$calls): callable
{
    return static function (string $url, array $fields, int $timeout) use ($reply, &$calls): array {
        $calls[] = ['url' => $url, 'fields' => $fields];
        return array_merge(['ok' => true, 'status' => 200, 'body' => '', 'error' => '', 'fault' => ''], $reply);
    };
}

/**
 * 📨 A provider-style JSON reply.
 *
 * @param array<string, mixed> $data
 * @param int                  $status
 * @return array<string, mixed>
 */
function rfJson(array $data, int $status = 200): array
{
    return ['ok' => true, 'status' => $status, 'body' => (string) json_encode($data), 'error' => '', 'fault' => ''];
}

/**
 * ♻️ An in-memory "accepted tokens" record standing in for tblHumanCheckTokens.
 *
 * @param array<string, string> $store Hash => provider (filled in)
 * @return callable (string $hash, string $provider): string claimed|reused|error
 */
function rfClaimer(array &$store): callable
{
    return static function (string $hash, string $provider) use (&$store): string {
        if (isset($store[$hash])) {
            return 'reused';
        }
        $store[$hash] = $provider;
        return 'claimed';
    };
}

/**
 * ✅ One check with stand-ins for everything outside this file.
 *
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $input
 * @param array<string, mixed> $reply
 * @param array<int, array>    $calls
 * @param array<string, mixed> $options Extra options (override the defaults)
 * @return array
 */
function rfRun(array $settings, array $input, array $reply, array &$calls, array $options = []): array
{
    $store = [];
    return humanCheckVerifyRequest($input, array_merge([
        'configGetter' => rfGetter($settings),
        'storedState'  => static function (string $key): string {
            return 'missing';
        },
        'transport'    => rfTransport($reply, $calls),
        'tokenClaimer' => rfClaimer($store),
        'health'       => static function (string $provider, int $maxAgeSeconds): string {
            return 'up';
        },
    ], $options));
}

/**
 * ✂️ PHP source with every comment removed (token_get_all), so a call that is
 *    only commented out is not found (review finding L3).
 *
 * @param string $source
 * @return string
 */
function rfCodeOnly(string $source): string
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

// ==============================================================================
// ♻️ M1: the same token can never be used twice
// ==============================================================================

$rfStore = [];
$rfCalls = [];
$rfOptions = [
    'tokenClaimer' => rfClaimer($rfStore),
];
$rfReply = rfJson(['success' => true, 'hostname' => 'cuercode.net']);
$rfFirst  = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'same-token-123'], $rfReply, $rfCalls, $rfOptions);
$rfSecond = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'same-token-123'], $rfReply, $rfCalls, $rfOptions);
assertTrue($rfFirst['ok'], 'M1: the first use of a token the provider accepts passes');
assertFalse($rfSecond['ok'], 'M1: the SAME token sent again is refused, although the stand-in provider says yes again');
assertEquals('expired_or_reused', $rfSecond['reason'], 'M1: ... as "expired or already used"');
assertEquals([hash('sha256', 'same-token-123')], array_keys($rfStore), 'M1: only the SHA-256 hash of the token is recorded');
assertFalse(str_contains((string) json_encode($rfStore), 'same-token-123'), 'M1: the token itself is never recorded');
assertEquals(['turnstile'], array_values($rfStore), 'M1: the record names the provider');

$rfCalls = [];
$rfOther = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'another-token'], $rfReply, $rfCalls, $rfOptions);
assertTrue($rfOther['ok'], 'M1: a different token still passes');

$rfCalls = [];
$rfNoStore = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'token-x'], $rfReply, $rfCalls, [
    'tokenClaimer' => static function (string $hash, string $provider): string {
        return 'error';
    },
]);
assertFalse($rfNoStore['ok'], 'M1: when the record cannot be written, the form is refused (never let through unrecorded)');
assertEquals(503, $rfNoStore['httpCode'], 'M1: ... with "try again in a few minutes" (503)');

$rfCalls = [];
$rfFailedClaims = [];
$rfFailedOnce = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'bad-token'], rfJson(['success' => false, 'error-codes' => ['invalid-input-response']]), $rfCalls, [
    'tokenClaimer' => rfClaimer($rfFailedClaims),
]);
assertFalse($rfFailedOnce['ok'], 'M1: a token the provider refuses is refused');
assertEquals([], $rfFailedClaims, 'M1: ... and is not recorded as accepted');

// 🗄️ With no database at all (a tool that loaded this file alone), the
//    default record cannot be written, so the check refuses.
$rfCalls = [];
$rfDefaultStore = humanCheckVerifyRequest(['cf-turnstile-response' => 'token-y'], [
    'configGetter' => rfGetter(rfKeys('turnstile')),
    'storedState'  => static function (string $key): string {
        return 'missing';
    },
    'transport'    => rfTransport($rfReply, $rfCalls),
    'health'       => static function (string $provider, int $maxAgeSeconds): string {
        return 'up';
    },
]);
assertFalse($rfDefaultStore['ok'], 'M1: without a database the default record fails closed (refused)');

// ==============================================================================
// 🔐 M2: keys that cannot be read do not switch the check off
// ==============================================================================

/**
 * ⚙️ A getter that answers like getConfig() does when a key cannot be
 *    decrypted: it hands back the caller's default.
 *
 * @param array<string, mixed> $values
 * @param array<int, string>   $broken Keys that "fail to decrypt"
 * @return callable
 */
function rfBrokenGetter(array $values, array $broken): callable
{
    return static function (string $key, mixed $default) use ($values, $broken): mixed {
        if (in_array($key, $broken, true)) {
            return $default;
        }
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $default;
    };
}

$rfStoredPresent = static function (string $key): string {
    if (str_ends_with($key, '_site_key') || str_ends_with($key, '_secret_key')) {
        if (str_starts_with($key, 'turnstile_')) {
            return 'present';
        }
    }
    return 'missing';
};

$rfBroken = humanCheckReadSettings(rfBrokenGetter(rfKeys('turnstile'), ['turnstile_secret_key']), $rfStoredPresent);
$rfCalls  = [];
$rfBrokenResult = humanCheckVerifyRequest([], [
    'configGetter' => rfBrokenGetter(rfKeys('turnstile'), ['turnstile_secret_key']),
    'storedState'  => $rfStoredPresent,
    'transport'    => rfTransport($rfReply, $rfCalls),
    'health'       => static function (string $provider, int $maxAgeSeconds): string {
        return 'up';
    },
]);
assertFalse($rfBrokenResult['ok'], 'M2: a saved secret that cannot be read: the form is REFUSED (before the fix it was "off" and let through)');
assertEquals('misconfigured', $rfBrokenResult['reason'], 'M2: ... reason "misconfigured"');
assertEquals(0, count($rfCalls), 'M2: ... and nothing is sent to the provider');
assertContains('cannot be read', strtolower(implode("\n", humanCheckStatusLines($rfBroken))), 'M2: the admin status says the keys cannot be read');
assertFalse(str_contains(strtolower(implode("\n", humanCheckStatusLines($rfBroken))), 'not both entered'), 'M2: ... and does not claim they were never entered');

// 🔁 Another provider's readable keys keep the check ON with that provider.
$rfMixed = array_merge(rfKeys('turnstile'), rfKeys('recaptcha'));
$rfMixedSettings = humanCheckReadSettings(rfBrokenGetter($rfMixed, ['turnstile_secret_key']), $rfStoredPresent);
assertEquals('recaptcha', humanCheckActive($rfMixedSettings)['provider'], 'M2: automatic, Turnstile unreadable, reCAPTCHA readable: reCAPTCHA is used (the check stays on)');
assertContains('cannot be read', strtolower(implode("\n", humanCheckStatusLines($rfMixedSettings))), 'M2: ... and the status still warns about Turnstile');

// 🎯 A chosen provider whose keys cannot be read: refused, never another provider.
$rfChosen = humanCheckVerifyRequest([], [
    'configGetter' => rfBrokenGetter(array_merge($rfMixed, ['bot_protection_provider' => 'turnstile']), ['turnstile_secret_key']),
    'storedState'  => $rfStoredPresent,
    'transport'    => rfTransport($rfReply, $rfCalls),
]);
assertEquals('misconfigured', $rfChosen['reason'], 'M2: Turnstile chosen and unreadable: refused (not off, not reCAPTCHA)');

// 🗄️ The stored-row question itself failing counts as unreadable (fail closed).
$rfErrorState = humanCheckReadSettings(rfBrokenGetter(rfKeys('turnstile'), ['turnstile_secret_key']), static function (string $key): string {
    return 'error';
});
assertEquals(null, humanCheckActive($rfErrorState), 'M2: when the stored row cannot even be checked, no provider is "active"...');
assertEquals('broken', humanCheckPlan($rfErrorState)['state'], 'M2: ... and the check counts as broken (refuse), not off');

// 🆕 A fresh site (rows stored empty) is still simply off.
$rfFresh = humanCheckReadSettings(rfGetter(['bot_protection_provider' => 'auto', 'turnstile_site_key' => '', 'turnstile_secret_key' => '']), static function (string $key): string {
    return 'empty';
});
assertEquals('off', humanCheckPlan($rfFresh)['state'], 'M2: keys stored empty: the check is off, as before');

// ==============================================================================
// 🌐 L1: the documented reply shape is followed whatever the HTTP status
// ==============================================================================

$rfCalls = [];
$rfBadRequest = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'odd'], rfJson(['success' => false, 'error-codes' => ['bad-request']], 400), $rfCalls);
assertFalse($rfBadRequest['ok'], 'L1: HTTP 400 with the documented reply {"success":false} is refused');
assertEquals('failed', $rfBadRequest['reason'], 'L1: ... as "failed" (before the fix: "could not be reached")');
$rfCalls = [];
assertEquals('failed', rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'odd'], rfJson(['success' => false, 'error-codes' => ['bad-request']], 200), $rfCalls)['reason'], 'L1: the same body with HTTP 200 is also "failed"');
$rfCalls = [];
assertEquals('misconfigured', rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'odd'], rfJson(['success' => false, 'error-codes' => ['invalid-input-secret']], 401), $rfCalls)['reason'], 'L1: HTTP 401 with "invalid-input-secret" is our keys being wrong');

foreach ([
    'the curl extension is missing'       => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'the curl extension is not installed', 'fault' => 'ours'],
    'the safe settings could not be set'  => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'the safe request settings could not be applied', 'fault' => 'ours'],
    'a certificate problem'               => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl error 60', 'fault' => 'ours'],
] as $rfWhat => $rfFault) {
    $rfCalls = [];
    $rfOurs  = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'tok'], $rfFault, $rfCalls);
    assertFalse($rfOurs['ok'], 'L1: ' . $rfWhat . ': refused');
    assertEquals('misconfigured', $rfOurs['reason'], 'L1: ' . $rfWhat . ': counts as OUR set-up being wrong, not the provider being down');
}

assertTrue(humanCheckCurlFaultIsOurs(60), 'L1: curl error 60 (certificate not trusted) is our own fault');
assertTrue(humanCheckCurlFaultIsOurs(77), 'L1: curl error 77 (certificate bundle unreadable) is our own fault');
assertTrue(humanCheckCurlFaultIsOurs(58), 'L1: curl error 58 (local certificate problem) is our own fault');
assertFalse(humanCheckCurlFaultIsOurs(28), 'L1: curl error 28 (timed out) is NOT our fault (the provider may be down)');
assertFalse(humanCheckCurlFaultIsOurs(6), 'L1: curl error 6 (name not found) is NOT counted as our fault');
assertFalse(humanCheckCurlFaultIsOurs(7), 'L1: curl error 7 (could not connect) is NOT counted as our fault');

// ==============================================================================
// 🏠 L2: the site's own names, never the visitor's Host header
// ==============================================================================

$rfHosts = humanCheckAllowedHosts('https://cuercode.net');
foreach (['cuercode.net', 'www.cuercode.net', 'cuercode.me', 'www.cuercode.me', 'qrmy.link', 'www.qrmy.link'] as $rfHost) {
    assertTrue(in_array($rfHost, $rfHosts, true), 'L2: ' . $rfHost . ' is one of the site\'s own names');
}
assertTrue(in_array('alpha.example.org', humanCheckAllowedHosts('https://alpha.example.org/'), true), 'L2: the website address setting\'s own host is allowed (the alpha and beta copies have their own)');
assertFalse(in_array('evil.example', $rfHosts, true), 'L2: a stranger\'s name is not');

$rfSavedHost = null;
if (isset($_SERVER['HTTP_HOST'])) {
    $rfSavedHost = $_SERVER['HTTP_HOST'];
}
$_SERVER['HTTP_HOST'] = 'evil.example';
$rfCalls = [];
$rfEvil = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'host-token'], rfJson(['success' => true, 'hostname' => 'evil.example']), $rfCalls);
assertEquals('wrong_host', $rfEvil['reason'], 'L2: a token made on evil.example is refused EVEN WHEN the request claims Host: evil.example (before the fix it passed)');
$rfCalls = [];
assertTrue(rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'host-token-2'], rfJson(['success' => true, 'hostname' => 'www.cuercode.net']), $rfCalls)['ok'], 'L2: a token made on www.cuercode.net passes whatever the Host header says');
if ($rfSavedHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $rfSavedHost;
}

// ==============================================================================
// 🧪 L5: hCaptcha "not-using-dummy-passcode"
// ==============================================================================

$rfCalls = [];
$rfDummy = rfRun(rfKeys('hcaptcha'), ['h-captcha-response' => 'from-test-key'], rfJson(['success' => false, 'error-codes' => ['not-using-dummy-passcode']]), $rfCalls);
assertEquals('failed', $rfDummy['reason'], 'L5: with our real site key, "not-using-dummy-passcode" is the visitor\'s fault (failed, before the fix: our keys "wrong")');
$rfCalls = [];
$rfOurTest = rfRun(rfKeys('hcaptcha', ['hcaptcha_site_key' => '10000000-ffff-ffff-ffff-000000000001']), ['h-captcha-response' => 'x'], rfJson(['success' => false, 'error-codes' => ['not-using-dummy-passcode']]), $rfCalls);
assertEquals('misconfigured', $rfOurTest['reason'], 'L5: with hCaptcha\'s TEST site key saved and a real secret, it is our set-up (misconfigured)');

// ==============================================================================
// ⏱️ Rate limit before the call to the provider (web forms)
// ==============================================================================

$rfCalls   = [];
$rfLimited = rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'junk'], $rfReply, $rfCalls, [
    'formRateLimit' => true,
    'rateLimiter'   => static function (): bool {
        return false;
    },
]);
assertFalse($rfLimited['ok'], 'rate limit: over the limit, refused');
assertEquals('rate_limited', $rfLimited['reason'], 'rate limit: reason "rate_limited"');
assertEquals(429, $rfLimited['httpCode'], 'rate limit: 429');
assertEquals(0, count($rfCalls), 'rate limit: the provider is NOT called');

$rfLimiterAsked = 0;
$rfCalls = [];
rfRun(rfKeys('turnstile'), [], $rfReply, $rfCalls, [
    'formRateLimit' => true,
    'rateLimiter'   => static function () use (&$rfLimiterAsked): bool {
        $rfLimiterAsked++;
        return true;
    },
]);
assertEquals(0, $rfLimiterAsked, 'rate limit: a form with no token is refused without counting (no call would be made)');

$rfCalls = [];
assertTrue(rfRun(rfKeys('turnstile'), ['cf-turnstile-response' => 'fine'], $rfReply, $rfCalls, [
    'formRateLimit' => true,
    'rateLimiter'   => static function (): bool {
        return true;
    },
])['ok'], 'rate limit: within the limit, the check goes ahead');
assertEquals(1, count($rfCalls), 'rate limit: ... and the provider is called once');

$rfBounds = humanCheckFormLimitBounds();
assertEquals(30, $rfBounds['default'], 'rate limit: 30 a hour per visitor address by default');
assertEquals(30, humanCheckReadSettings(rfGetter([]))['formIpPerHour'], 'rate limit: the default is used when nothing is saved');
assertEquals(1000, humanCheckReadSettings(rfGetter(['human_check_form_ip_per_hour' => '999999']))['formIpPerHour'], 'rate limit: a silly number is pulled into range');
assertEquals(30, humanCheckReadSettings(rfGetter(['human_check_form_ip_per_hour' => 'lots']))['formIpPerHour'], 'rate limit: text means the default');

// ==============================================================================
// 🔒 L3: commenting a call out fails the "every page makes the call" check
// ==============================================================================

$rfPages = [
    'register'            => ['registerUser(', "'purpose' => 'register'"],
    'login'               => ['loginUser(', "'purpose' => 'login'"],
    'forgot-password'     => ['requestPasswordReset(', "'purpose' => 'forgot'"],
    'resend-verification' => ['requestNewVerificationLink(', "'purpose' => 'resend'"],
];
foreach ($rfPages as $rfPage => $rfExpect) {
    $rfCode = rfCodeOnly((string) file_get_contents($rfSiteRoot . 'public_html' . $rfDs . $rfPage . $rfDs . 'index.php'));
    $rfCheckAt  = strpos($rfCode, 'humanCheckVerifyRequest($_POST');
    $rfEngineAt = strrpos($rfCode, $rfExpect[0]);
    assertTrue($rfCheckAt !== false, 'L3: /' . $rfPage . '/ makes the call in CODE (comments ignored)');
    assertTrue($rfCheckAt !== false && $rfEngineAt !== false && $rfCheckAt < $rfEngineAt, 'L3: /' . $rfPage . '/ makes it before the engine call');
    assertContains($rfExpect[1], $rfCode, 'L3: /' . $rfPage . '/ says which form it is (so only sign-in can use the outage rule)');
    assertContains("'formRateLimit' => true", $rfCode, 'L3: /' . $rfPage . '/ asks for the rate limit before the provider call');
}
$rfApiCode = rfCodeOnly((string) file_get_contents($rfSiteRoot . '_functions' . $rfDs . 'fn_api_accounts.php'));
foreach (['register', 'login', 'forgot'] as $rfPurpose) {
    assertContains("requireHumanCheckForApi(\$requestBody, '" . $rfPurpose . "')", $rfApiCode, 'L3: the JSON route for ' . $rfPurpose . ' makes the call in code');
}

// 🧪 The stripper itself: a commented-out call is not found.
assertFalse(str_contains(rfCodeOnly("<?php\n// \$x = humanCheckVerifyRequest(\$_POST);\n/* humanCheckVerifyRequest(\$_POST) */\n\$y = 1;\n"), 'humanCheckVerifyRequest'), 'L3: a call that is only in a comment is not counted');
