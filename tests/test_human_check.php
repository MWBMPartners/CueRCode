<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Human check (Turnstile, reCAPTCHA, hCaptcha) tests (#163)
 * ==============================================================================
 *
 * Path: tests/test_human_check.php
 *
 * WHAT THIS PROVES (plain English):
 *   The forms that can be abused (create an account, sign in, forgot
 *   password, send a new verification link) and the JSON routes that do the
 *   same jobs ask the visitor to pass a "human check" from Cloudflare
 *   Turnstile, Google reCAPTCHA or hCaptcha - whichever has its keys entered
 *   first, in that order - and the server checks the answer with the
 *   provider before doing anything.
 *
 *   NO REAL PROVIDER IS EVER CALLED HERE. Every reply comes from a stand-in
 *   ("transport") that this file hands to humanCheckVerifyRequest(), so the
 *   tests send nothing to Cloudflare, Google or hCaptcha. The reply shapes
 *   copy the providers' published documentation (read 2026-11-27): Turnstile
 *   "timeout-or-duplicate" for a spent token and hostname "example.com" from
 *   its test secret; hCaptcha "already-seen-response",
 *   "expired-input-response" and hostname "not-provided" under heavy load.
 *
 *   For each provider: success, failure, provider unreachable (no reply, an
 *   error page, a reply that is not JSON), wrong or missing token, keys
 *   absent, wrong host name, and a replayed (already used) token.
 *
 *   Then static checks that every page and every API route really makes the
 *   call (so there is no way round it), the content security policy names
 *   exactly the providers' addresses, the privacy page names the provider in
 *   use, migration 0005 passes the upgrade runner's rules, and the
 *   command-line switch reads its options safely.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0024)
 * @see         web/CueRCode.net/_functions/fn_human_check.php
 */

requireFn('fn_human_check.php');
requireFn('fn_migrations.php');

$hcSiteRoot = CUERCODE_SITE_ROOT;
$hcDs       = DIRECTORY_SEPARATOR;

// ==============================================================================
// 🧰 Helpers: a settings getter and a stand-in transport
// ==============================================================================

/**
 * ⚙️ Build a settings getter (key, default) from a plain array.
 *
 * @param array<string, mixed> $values Setting key => value
 * @return callable
 */
function hcGetter(array $values): callable
{
    return static function (string $key, mixed $default) use ($values): mixed {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $default;
    };
}

/**
 * 🔑 Settings with ONE provider's two keys entered.
 *
 * @param string               $provider turnstile | recaptcha | hcaptcha
 * @param array<string, mixed> $extra    More settings
 * @return array<string, mixed>
 */
function hcKeysFor(string $provider, array $extra = []): array
{
    $values = [
        'bot_protection_provider'      => 'auto',
        $provider . '_site_key'        => 'site-' . $provider . '-PUBLIC',
        $provider . '_secret_key'      => 'secret-' . $provider . '-PRIVATE-9f8e7d',
    ];

    return array_merge($values, $extra);
}

/**
 * 📡 A stand-in transport that records every call and returns a fixed reply.
 *
 * @param array<string, mixed> $reply ['ok' => bool, 'status' => int, 'body' => string, 'error' => string]
 * @param array<int, array>    $calls Filled with [url, fields, timeout] per call
 * @return callable
 */
function hcTransport(array $reply, array &$calls): callable
{
    return static function (string $url, array $fields, int $timeout) use ($reply, &$calls): array {
        $calls[] = ['url' => $url, 'fields' => $fields, 'timeout' => $timeout];
        return array_merge(['ok' => true, 'status' => 200, 'body' => '', 'error' => ''], $reply);
    };
}

/**
 * 📨 A provider-style JSON reply.
 *
 * @param array<string, mixed> $data
 * @return array{ok: bool, status: int, body: string, error: string}
 */
function hcJsonReply(array $data, int $status = 200): array
{
    return ['ok' => true, 'status' => $status, 'body' => (string) json_encode($data), 'error' => ''];
}

/**
 * ✅ Run one check with the given settings, input and reply.
 *
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $input
 * @param array<string, mixed> $reply
 * @param array<int, array>    $calls
 * @param string               $host    The site's own host name
 * @param string               $health  What the server's own test call says (up | down | misconfigured)
 * @param string               $purpose Which form (login | register | forgot | resend | '')
 * @return array
 */
function hcRun(array $settings, array $input, array $reply, array &$calls, string $host = 'cuercode.net', string $health = 'up', string $purpose = ''): array
{
    // ♻️ A fresh "accepted tokens" record for each run (review M1 is tested in
    //    tests/test_human_check_review_fixes.php).
    $claims = [];
    return humanCheckVerifyRequest($input, [
        'configGetter' => hcGetter($settings),
        'storedState'  => static function (string $key): string {
            return 'missing';
        },
        'transport'    => hcTransport($reply, $calls),
        'allowedHosts' => [$host],
        'purpose'      => $purpose,
        'tokenClaimer' => static function (string $hash, string $provider) use (&$claims): string {
            if (isset($claims[$hash])) {
                return 'reused';
            }
            $claims[$hash] = true;
            return 'claimed';
        },
        // 🩺 The server's own test call (decision 0037), as a stand-in.
        'health'       => static function (string $provider, int $maxAgeSeconds) use ($health): string {
            return $health;
        },
        'outageLogger' => static function (string $event, array $details): void {
        },
    ]);
}

// ==============================================================================
// 🧭 Section 1: which provider is used (Turnstile, then reCAPTCHA, then hCaptcha)
// ==============================================================================

assertEquals(['turnstile', 'recaptcha', 'hcaptcha'], array_keys(humanCheckProviders()), 'the providers, in the order they are tried');

$hcAll = [
    'turnstile' => ['siteKey' => 'a', 'secret' => 'b'],
    'recaptcha' => ['siteKey' => 'c', 'secret' => 'd'],
    'hcaptcha'  => ['siteKey' => 'e', 'secret' => 'f'],
];
$hcNone = [
    'turnstile' => ['siteKey' => '', 'secret' => ''],
    'recaptcha' => ['siteKey' => '', 'secret' => ''],
    'hcaptcha'  => ['siteKey' => '', 'secret' => ''],
];

assertEquals(null, humanCheckChooseProvider('auto', $hcNone), 'automatic, no keys: off');
assertEquals('turnstile', humanCheckChooseProvider('auto', $hcAll), 'automatic, every key: Turnstile first');
$hcNoTurnstile = $hcAll;
$hcNoTurnstile['turnstile'] = ['siteKey' => '', 'secret' => ''];
assertEquals('recaptcha', humanCheckChooseProvider('auto', $hcNoTurnstile), 'automatic, no Turnstile keys: reCAPTCHA next');
$hcOnlyH = $hcNone;
$hcOnlyH['hcaptcha'] = ['siteKey' => 'e', 'secret' => 'f'];
assertEquals('hcaptcha', humanCheckChooseProvider('auto', $hcOnlyH), 'automatic, only hCaptcha keys: hCaptcha');
$hcHalf = $hcOnlyH;
$hcHalf['turnstile'] = ['siteKey' => 'a', 'secret' => ''];
$hcHalf['recaptcha'] = ['siteKey' => '', 'secret' => 'd'];
assertEquals('hcaptcha', humanCheckChooseProvider('auto', $hcHalf), 'automatic: a provider with only ONE of its two keys is skipped');
$hcSpaces = $hcNone;
$hcSpaces['turnstile'] = ['siteKey' => '   ', 'secret' => "\t"];
assertEquals(null, humanCheckChooseProvider('auto', $hcSpaces), 'automatic: keys that are only spaces count as missing');
assertEquals(null, humanCheckChooseProvider('none', $hcAll), 'switched off ("none"): off even with every key');
assertEquals('recaptcha', humanCheckChooseProvider('recaptcha', $hcAll), 'one provider chosen: that one, even if an earlier one has keys');
assertEquals(null, humanCheckChooseProvider('turnstile', $hcNoTurnstile), 'one provider chosen but its keys missing: off (never a different provider)');
assertEquals('turnstile', humanCheckChooseProvider('friendlycaptcha', $hcAll), 'an unknown stored value behaves as automatic (the check stays on)');

// ==============================================================================
// ⚙️ Section 2: reading the settings
// ==============================================================================

$hcDefaults = humanCheckReadSettings(hcGetter([]));
assertEquals('auto', $hcDefaults['choice'], 'settings: the choice defaults to automatic');
assertEquals('signin_only', $hcDefaults['whenUnreachable'], 'settings: every provider down means "let only sign-in through" by default (decision 0037)');
assertEquals(5, $hcDefaults['timeout'], 'settings: the waiting time defaults to 5 seconds');
assertEquals('', $hcDefaults['keys']['turnstile']['secret'], 'settings: no keys by default');
assertEquals('refuse', humanCheckReadSettings(hcGetter(['bot_protection_when_unreachable' => 'refuse']))['whenUnreachable'], 'settings: "refuse" is read');
assertEquals('signin_only', humanCheckReadSettings(hcGetter(['bot_protection_when_unreachable' => 'maybe']))['whenUnreachable'], 'settings: anything else means the default');
assertEquals(9, humanCheckReadSettings(hcGetter(['bot_protection_timeout_seconds' => '9']))['timeout'], 'settings: a waiting time stored as text is read');
assertEquals(5, humanCheckReadSettings(hcGetter(['bot_protection_timeout_seconds' => '99']))['timeout'], 'settings: a waiting time outside 2 to 15 seconds means the default');
assertEquals(5, humanCheckReadSettings(hcGetter(['bot_protection_timeout_seconds' => '1']))['timeout'], 'settings: a waiting time below 2 seconds means the default');
assertEquals('none', humanCheckReadSettings(hcGetter(['bot_protection_provider' => ' NONE ']))['choice'], 'settings: the choice is read without spaces or capitals');

// ==============================================================================
// 🤖 Section 3: every provider - success, failure, unreachable, bad tokens
// ==============================================================================

$hcFacts = humanCheckProviders();

foreach ($hcFacts as $hcProvider => $hcFact) {
    $hcLabel    = $hcFact['name'] . ': ';
    $hcSettings = hcKeysFor($hcProvider);
    $hcToken    = 'token-' . $hcProvider . '-abc123';
    $hcInput    = [$hcFact['formField'] => $hcToken];

    // ✅ Success.
    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, $hcInput, hcJsonReply(['success' => true, 'hostname' => 'cuercode.net', 'error-codes' => []]), $hcCalls);
    assertTrue($hcResult['ok'], $hcLabel . 'a passed check is accepted');
    assertEquals('passed', $hcResult['reason'], $hcLabel . 'reason "passed"');
    assertEquals($hcProvider, $hcResult['provider'], $hcLabel . 'the result names the provider');
    assertEquals(1, count($hcCalls), $hcLabel . 'the provider is asked exactly once');
    assertEquals($hcFact['verifyUrl'], $hcCalls[0]['url'], $hcLabel . 'the provider\'s own checking address is used');
    assertTrue(str_starts_with($hcCalls[0]['url'], 'https://'), $hcLabel . 'the checking address is https');
    assertEquals('secret-' . $hcProvider . '-PRIVATE-9f8e7d', $hcCalls[0]['fields']['secret'], $hcLabel . 'the secret key is sent to the provider (only there)');
    assertEquals($hcToken, $hcCalls[0]['fields']['response'], $hcLabel . 'the visitor\'s token is sent as "response"');
    assertFalse(isset($hcCalls[0]['fields']['remoteip']), $hcLabel . 'the visitor\'s internet address is NOT sent');
    assertEquals(5, $hcCalls[0]['timeout'], $hcLabel . 'the waiting time from the settings is used');
    if ($hcProvider === 'hcaptcha') {
        assertEquals('site-hcaptcha-PUBLIC', $hcCalls[0]['fields']['sitekey'], $hcLabel . 'hCaptcha also gets the site key, so it can say the token was made for this site');
    }

    // 🔁 The token can also come from the JSON field the website's script sends.
    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, ['human_check_token' => $hcToken], hcJsonReply(['success' => true, 'hostname' => 'cuercode.net']), $hcCalls);
    assertTrue($hcResult['ok'], $hcLabel . 'the token in "human_check_token" (JSON routes) is accepted');
    assertEquals($hcToken, $hcCalls[0]['fields']['response'], $hcLabel . '... and that token is the one sent');

    // ❌ Failure.
    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, $hcInput, hcJsonReply(['success' => false, 'error-codes' => ['invalid-input-response']]), $hcCalls);
    assertFalse($hcResult['ok'], $hcLabel . 'a failed check is refused');
    assertEquals('failed', $hcResult['reason'], $hcLabel . 'reason "failed"');
    assertEquals(403, $hcResult['httpCode'], $hcLabel . 'refused with 403');
    assertEquals('HUMAN_CHECK_FAILED', $hcResult['errorCode'], $hcLabel . 'error code HUMAN_CHECK_FAILED');
    assertTrue($hcResult['message'] !== '', $hcLabel . 'with a plain message');

    // 🌐 Unreachable: no reply at all, and the server's own test agrees.
    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, $hcInput, ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Could not resolve host'], $hcCalls, 'cuercode.net', 'down');
    assertFalse($hcResult['ok'], $hcLabel . 'provider unreachable: refused');
    assertEquals('unavailable', $hcResult['reason'], $hcLabel . 'reason "unavailable"');
    assertEquals(503, $hcResult['httpCode'], $hcLabel . 'refused with 503 (try again later)');
    assertEquals('HUMAN_CHECK_UNAVAILABLE', $hcResult['errorCode'], $hcLabel . 'error code HUMAN_CHECK_UNAVAILABLE');

    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, $hcInput, ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'timed out'], $hcCalls, 'cuercode.net', 'down', 'login');
    assertTrue($hcResult['ok'], $hcLabel . 'every provider down, SIGN-IN: let through (decision 0037)');
    assertEquals('outage_signin', $hcResult['reason'], $hcLabel . 'reason "outage_signin"');

    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, $hcInput, ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'timed out'], $hcCalls, 'cuercode.net', 'up', 'login');
    assertEquals('failed', $hcResult['reason'], $hcLabel . 'no reply for this token but the server\'s own test says the provider is up: failed (never the outage rule)');

    // 🌐 Unreachable: an error page, a reply that is not JSON, a strange shape.
    foreach ([
        'an error page (HTTP 500)'        => ['ok' => true, 'status' => 500, 'body' => '<html>oops</html>', 'error' => ''],
        'a reply that is not JSON'        => ['ok' => true, 'status' => 200, 'body' => 'not json', 'error' => ''],
        'a JSON list instead of an object' => ['ok' => true, 'status' => 200, 'body' => '[true]', 'error' => ''],
        '"success" given as text'         => hcJsonReply(['success' => 'true']),
        'no "success" at all'             => hcJsonReply(['hostname' => 'cuercode.net']),
    ] as $hcWhat => $hcReply) {
        $hcCalls  = [];
        $hcResult = hcRun($hcSettings, $hcInput, $hcReply, $hcCalls, 'cuercode.net', 'down');
        assertEquals('unavailable', $hcResult['reason'], $hcLabel . $hcWhat . ' with the provider down counts as "could not check"');
        assertFalse($hcResult['ok'], $hcLabel . $hcWhat . ' is refused');
        $hcCalls  = [];
        assertEquals('failed', hcRun($hcSettings, $hcInput, $hcReply, $hcCalls)['reason'], $hcLabel . $hcWhat . ' with the provider up (by our own test) is a failed check');
    }

    // 🕳️ Missing token: never asks the provider.
    foreach ([
        'no token at all'    => [],
        'an empty token'     => [$hcFact['formField'] => ''],
        'a token of spaces'  => [$hcFact['formField'] => '   '],
        'a token that is a list' => [$hcFact['formField'] => ['x']],
    ] as $hcWhat => $hcBadInput) {
        $hcCalls  = [];
        $hcResult = hcRun($hcSettings, $hcBadInput, hcJsonReply(['success' => true]), $hcCalls);
        assertFalse($hcResult['ok'], $hcLabel . $hcWhat . ' is refused');
        assertEquals('missing_token', $hcResult['reason'], $hcLabel . $hcWhat . ': reason "missing_token"');
        assertEquals(400, $hcResult['httpCode'], $hcLabel . $hcWhat . ': 400');
        assertEquals('HUMAN_CHECK_REQUIRED', $hcResult['errorCode'], $hcLabel . $hcWhat . ': HUMAN_CHECK_REQUIRED');
        assertEquals(0, count($hcCalls), $hcLabel . $hcWhat . ': the provider is not asked');
    }
    assertContains('JavaScript', hcRun($hcSettings, [], hcJsonReply(['success' => true]), $hcCalls)['message'], $hcLabel . 'the "missing" message tells people the check needs JavaScript');

    // 📏 A far too long token: refused without asking the provider.
    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, [$hcFact['formField'] => str_repeat('x', CUERCODE_HUMAN_CHECK_TOKEN_MAX_LENGTH + 1)], hcJsonReply(['success' => true]), $hcCalls);
    assertEquals('failed', $hcResult['reason'], $hcLabel . 'a token longer than any real one fails');
    assertEquals(0, count($hcCalls), $hcLabel . '... without asking the provider');

    // 🔑 Keys absent: the check is off and nothing is asked.
    $hcCalls  = [];
    $hcResult = hcRun(['bot_protection_provider' => 'auto'], [], hcJsonReply(['success' => false]), $hcCalls);
    assertTrue($hcResult['ok'], $hcLabel . 'keys absent: the form works without a check');
    assertEquals('off', $hcResult['reason'], $hcLabel . 'keys absent: reason "off"');
    assertEquals(0, count($hcCalls), $hcLabel . 'keys absent: nothing is sent anywhere');
    $hcHalfKeys = hcKeysFor($hcProvider);
    $hcHalfKeys[$hcProvider . '_secret_key'] = '';
    $hcCalls = [];
    assertEquals('off', hcRun($hcHalfKeys, $hcInput, hcJsonReply(['success' => false]), $hcCalls)['reason'], $hcLabel . 'only the site key entered: still off');

    // 🏠 Wrong host name.
    $hcCalls  = [];
    $hcResult = hcRun($hcSettings, $hcInput, hcJsonReply(['success' => true, 'hostname' => 'evil.example']), $hcCalls);
    assertFalse($hcResult['ok'], $hcLabel . 'a token solved on another website is refused');
    assertEquals('wrong_host', $hcResult['reason'], $hcLabel . 'reason "wrong_host"');
    assertEquals(403, $hcResult['httpCode'], $hcLabel . 'wrong host: 403');
    $hcCalls = [];
    assertTrue(hcRun($hcSettings, $hcInput, hcJsonReply(['success' => true, 'hostname' => 'CueRCode.NET']), $hcCalls, 'cuercode.net:443')['ok'], $hcLabel . 'the host name is compared without capitals or a port number');
    $hcCalls = [];
    assertTrue(hcRun($hcSettings, $hcInput, hcJsonReply(['success' => true, 'hostname' => 'not-provided']), $hcCalls)['ok'], $hcLabel . '"not-provided" (hCaptcha under heavy load) is accepted');
    $hcCalls = [];
    assertTrue(hcRun($hcSettings, $hcInput, hcJsonReply(['success' => true]), $hcCalls)['ok'], $hcLabel . 'no host name in the reply is accepted');
    $hcCalls = [];
    assertFalse(hcRun($hcSettings, $hcInput, hcJsonReply(['success' => true, 'hostname' => 'cuercode.net.evil.example']), $hcCalls)['ok'], $hcLabel . 'a host name that only STARTS with ours is refused');

    // ♻️ Replayed or expired token, as each provider reports it.
    $hcReplayCodes = [
        'turnstile' => ['timeout-or-duplicate'],
        'recaptcha' => ['timeout-or-duplicate'],
        'hcaptcha'  => ['already-seen-response', 'invalid-or-already-seen-response', 'expired-input-response'],
    ];
    foreach ($hcReplayCodes[$hcProvider] as $hcCode) {
        $hcCalls  = [];
        $hcResult = hcRun($hcSettings, $hcInput, hcJsonReply(['success' => false, 'error-codes' => [$hcCode]]), $hcCalls);
        assertFalse($hcResult['ok'], $hcLabel . 'a used or expired token (' . $hcCode . ') is refused');
        assertEquals('expired_or_reused', $hcResult['reason'], $hcLabel . $hcCode . ': reason "expired_or_reused"');
        assertContains('expired', $hcResult['message'], $hcLabel . $hcCode . ': the message says it expired');
    }

    // 🔧 Our own keys are wrong: refused, even at sign-in (never the outage rule).
    foreach (['invalid-input-secret', 'missing-input-secret'] as $hcCode) {
        $hcCalls  = [];
        $hcResult = hcRun($hcSettings, $hcInput, hcJsonReply(['success' => false, 'error-codes' => [$hcCode]]), $hcCalls, 'cuercode.net', 'up', 'login');
        assertFalse($hcResult['ok'], $hcLabel . $hcCode . ' (our secret key is wrong) is refused, even at sign-in');
        assertEquals('misconfigured', $hcResult['reason'], $hcLabel . $hcCode . ': reason "misconfigured"');
        assertEquals(503, $hcResult['httpCode'], $hcLabel . $hcCode . ': 503');
    }

    // 🤫 Nothing secret in the result.
    $hcCalls   = [];
    $hcEncoded = (string) json_encode(hcRun($hcSettings, $hcInput, hcJsonReply(['success' => false, 'error-codes' => ['invalid-input-secret']]), $hcCalls));
    assertFalse(str_contains($hcEncoded, 'PRIVATE-9f8e7d'), $hcLabel . 'the result never contains the secret key');
    assertFalse(str_contains($hcEncoded, $hcToken), $hcLabel . 'the result never contains the visitor\'s token');

    // 🧪 The provider's published test secret: the host-name check is skipped
    //    (Turnstile's test secret always answers "example.com").
    $hcTestSettings = hcKeysFor($hcProvider);
    $hcTestSettings[$hcProvider . '_secret_key'] = $hcFact['testSecrets'][0];
    $hcCalls = [];
    assertTrue(hcRun($hcTestSettings, $hcInput, hcJsonReply(['success' => true, 'hostname' => 'example.com']), $hcCalls)['ok'], $hcLabel . 'a published TEST secret skips the host-name check');
    assertTrue(humanCheckActive(humanCheckReadSettings(hcGetter($hcTestSettings)))['isTestKey'], $hcLabel . 'a published test secret is reported as a test key');
    assertFalse(humanCheckActive(humanCheckReadSettings(hcGetter($hcSettings)))['isTestKey'], $hcLabel . 'a real secret is not a test key');
}

// 🔁 Turnstile's "internal-error" is the provider's own fault: "could not check".
$hcCalls = [];
assertEquals('unavailable', hcRun(hcKeysFor('turnstile'), ['cf-turnstile-response' => 'x'], hcJsonReply(['success' => false, 'error-codes' => ['internal-error']]), $hcCalls, 'cuercode.net', 'down')['reason'], 'Turnstile internal-error (and our own test says down) counts as "could not check"');

// 🧭 A provider's own field counts only for a provider that is set up.
$hcCalls = [];
$hcResult = hcRun(hcKeysFor('turnstile'), ['h-captcha-response' => 'from-hcaptcha'], hcJsonReply(['success' => true]), $hcCalls);
assertEquals('missing_token', $hcResult['reason'], 'with only Turnstile set up, an hCaptcha field does not count');
$hcCalls = [];
$hcResult = hcRun(array_merge(hcKeysFor('turnstile'), hcKeysFor('hcaptcha')), ['h-captcha-response' => 'from-hcaptcha'], hcJsonReply(['success' => true]), $hcCalls);
assertEquals('hcaptcha', $hcResult['provider'], 'with Turnstile AND hCaptcha set up, an hCaptcha token (the fallback, decision 0037) is checked with hCaptcha');
assertEquals(humanCheckProviders()['hcaptcha']['verifyUrl'], $hcCalls[0]['url'], '... at hCaptcha\'s own checking address');

// ==============================================================================
// 🏠 Section 4: host names
// ==============================================================================

assertEquals('www.example.com', humanCheckNormaliseHost('WWW.Example.com:8443'), 'host: capitals and port removed');
assertEquals('example.com', humanCheckNormaliseHost(' example.com. '), 'host: spaces and a final dot removed');
assertEquals('[::1]', humanCheckNormaliseHost('[::1]:8080'), 'host: a bracketed address keeps its brackets, loses its port');
assertEquals('', humanCheckNormaliseHost("bad\nhost"), 'host: a line break makes it unusable (empty)');
assertTrue(in_array('alpha.example.org', humanCheckAllowedHosts('https://Alpha.Example.org/'), true), 'host: the website address setting\'s own host is allowed (review L2: never the request\'s Host header)');
assertTrue(in_array('cuercode.net', humanCheckAllowedHosts(''), true), 'host: with no website address setting, the product domains are still allowed');

// ==============================================================================
// 🧱 Section 5: the widget
// ==============================================================================

assertEquals('', humanCheckWidgetHtml(humanCheckReadSettings(hcGetter([]))), 'widget: nothing at all while the check is off');

foreach ($hcFacts as $hcProvider => $hcFact) {
    $hcWidgetSettings = hcKeysFor($hcProvider);
    $hcWidgetSettings[$hcProvider . '_site_key'] = 'site"<key>&' . $hcProvider;
    $hcWidget = humanCheckWidgetHtml(humanCheckReadSettings(hcGetter($hcWidgetSettings)));
    assertContains('class="cuercode-human-check', $hcWidget, $hcFact['name'] . ' widget: the container is drawn');
    assertContains('data-provider="' . $hcProvider . '"', $hcWidget, $hcFact['name'] . ' widget: names its provider');
    assertContains('data-sitekey="site&quot;&lt;key&gt;&amp;' . $hcProvider . '"', $hcWidget, $hcFact['name'] . ' widget: the site key is escaped');
    assertContains('data-script="' . htmlspecialchars($hcFact['scriptUrl'], ENT_QUOTES, 'UTF-8') . '"', $hcWidget, $hcFact['name'] . ' widget: the provider script address is given to our own script');
    assertContains('<noscript>', $hcWidget, $hcFact['name'] . ' widget: says what happens with JavaScript off');
    assertFalse(str_contains($hcWidget, 'PRIVATE-9f8e7d'), $hcFact['name'] . ' widget: the secret key is never on the page');
    assertFalse(str_contains($hcWidget, '<script'), $hcFact['name'] . ' widget: no script tag in the page (our script file loads the provider)');
    assertTrue(str_starts_with($hcFact['scriptUrl'], 'https://'), $hcFact['name'] . ': the script address is https');
    assertContains('render=explicit', $hcFact['scriptUrl'], $hcFact['name'] . ': the widget is drawn by our script (explicit), not automatically');
}

// ==============================================================================
// 🖥️ Section 6: the command-line switch (options only - the real database
//    run is tests/integration/human_check_real_mysql.php)
// ==============================================================================

assertEquals(['action' => 'status', 'error' => ''], humanCheckCliParseArgs([]), 'command line: no option means "status"');
assertEquals(['action' => 'status', 'error' => ''], humanCheckCliParseArgs(['status']), 'command line: status');
assertEquals(['action' => 'off', 'error' => ''], humanCheckCliParseArgs(['off']), 'command line: off');
assertEquals(['action' => 'on', 'error' => ''], humanCheckCliParseArgs(['on']), 'command line: on');
assertEquals(['action' => 'help', 'error' => ''], humanCheckCliParseArgs(['--help']), 'command line: --help');
assertTrue(humanCheckCliParseArgs(['delete'])['error'] !== '', 'command line: an unknown word is refused');
assertTrue(humanCheckCliParseArgs(['off', 'on'])['error'] !== '', 'command line: two actions at once are refused');
assertEquals('none', humanCheckCliChoiceFor('off'), 'command line: "off" stores "none"');
assertEquals('auto', humanCheckCliChoiceFor('on'), 'command line: "on" stores "auto"');

$hcStatus = implode("\n", humanCheckStatusLines(humanCheckReadSettings(hcGetter(hcKeysFor('recaptcha')))));
assertContains('Google reCAPTCHA', $hcStatus, 'status: names the provider in use');
assertFalse(str_contains($hcStatus, 'PRIVATE-9f8e7d'), 'status: never shows a secret');
assertContains('off', strtolower(implode("\n", humanCheckStatusLines(humanCheckReadSettings(hcGetter([])))))
    , 'status: says the check is off when no keys are entered');
$hcTestStatusSettings = hcKeysFor('turnstile');
$hcTestStatusSettings['turnstile_secret_key'] = '1x0000000000000000000000000000000AA';
assertContains('test key', strtolower(implode("\n", humanCheckStatusLines(humanCheckReadSettings(hcGetter($hcTestStatusSettings))))), 'status: warns when a published test key is in use');

// ==============================================================================
// 🔒 Section 7: no way round it - every page and route makes the call
// ==============================================================================

/**
 * ✂️ PHP source without comments (token_get_all), so a call that is only
 *    commented out is not counted (review finding L3).
 *
 * @param string $source
 * @return string
 */
function hcCodeOnly(string $source): string
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

$hcPages = [
    'register'            => 'registerUser(',
    'login'               => 'loginUser(',
    'forgot-password'     => 'requestPasswordReset(',
    'resend-verification' => 'requestNewVerificationLink(',
];
foreach ($hcPages as $hcPage => $hcEngineCall) {
    $hcSource = hcCodeOnly((string) file_get_contents($hcSiteRoot . 'public_html' . $hcDs . $hcPage . $hcDs . 'index.php'));
    $hcCheckAt  = strpos($hcSource, 'humanCheckVerifyRequest($_POST, [');
    $hcCsrfAt   = strpos($hcSource, 'validateCSRFToken(');
    $hcEngineAt = strrpos($hcSource, $hcEngineCall);
    assertTrue($hcCheckAt !== false, '/' . $hcPage . '/: the page checks the human check');
    assertTrue($hcCheckAt !== false && $hcCsrfAt !== false && $hcCsrfAt < $hcCheckAt, '/' . $hcPage . '/: after the security token');
    assertTrue($hcCheckAt !== false && $hcEngineAt !== false && $hcCheckAt < $hcEngineAt, '/' . $hcPage . '/: before anything is done with the email address');
    assertContains('humanCheckWidgetHtml(null, \'', $hcSource, '/' . $hcPage . '/: the page draws the widget (with its form\'s email route)');
    assertContains('/assets/js/cuercode-human-check.js', $hcSource, '/' . $hcPage . '/: the page loads the widget script');
}

$hcApi = hcCodeOnly((string) file_get_contents($hcSiteRoot . '_functions' . $hcDs . 'fn_api_accounts.php'));

/**
 * ✂️ The body of one function in a source file (from its name to the next "function ").
 *
 * @param string $source
 * @param string $name
 * @return string
 */
function hcFunctionBody(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\nfunction ", $start + 10);
    if ($next === false) {
        return substr($source, $start);
    }
    return substr($source, $start, $next - $start);
}

foreach ([
    'handleAuthRegister'             => 'registerUser(',
    'handleAuthLogin'                => 'loginUser(',
    'handleAuthRequestPasswordReset' => 'requestPasswordReset(',
] as $hcHandler => $hcEngineCall) {
    $hcBody = hcFunctionBody($hcApi, $hcHandler);
    $hcCheckAt  = strpos($hcBody, 'requireHumanCheckForApi($requestBody, \'');
    $hcEngineAt = strpos($hcBody, $hcEngineCall);
    assertTrue($hcCheckAt !== false, 'API ' . $hcHandler . ': checks the human check');
    assertTrue($hcCheckAt !== false && $hcEngineAt !== false && $hcCheckAt < $hcEngineAt, 'API ' . $hcHandler . ': before the engine is called');
    assertTrue($hcCheckAt !== false && strpos($hcBody, 'requireCsrfOrApiKey()') < $hcCheckAt, 'API ' . $hcHandler . ': after the security token or API key check');
}
foreach (['handleAuthLogout', 'handleAuthVerifyEmail', 'handleAuthResetPassword'] as $hcHandler) {
    assertFalse(str_contains(hcFunctionBody($hcApi, $hcHandler), 'requireHumanCheckForApi('), 'API ' . $hcHandler . ': deliberately exempt (needs a signed-in session or an emailed secret link)');
}
$hcGuard = hcFunctionBody($hcApi, 'requireHumanCheckForApi');
assertContains('humanCheckVerifyRequest($requestBody, [\'purpose\' => $purpose])', $hcGuard, 'API guard: uses the same one call as the pages, saying which form it is');
assertFalse(str_contains($hcGuard, 'HTTP_X_API_KEY'), 'API guard: an API key does NOT skip the check');
assertContains('errorResponse(', $hcGuard, 'API guard: a refusal ends the request');

// 📨 Every route that sends an email or signs someone in: no other door.
$hcRouter = (string) file_get_contents($hcSiteRoot . 'public_html' . $hcDs . 'api' . $hcDs . 'v1' . $hcDs . 'index.php');
preg_match_all("/'pattern'\\s*=>\\s*'auth\\/([a-z-]+)'/", $hcRouter, $hcRouteMatches);
assertEquals(['register', 'login', 'logout', 'verify-email', 'request-password-reset', 'reset-password'], $hcRouteMatches[1], 'the API still has exactly the six account routes this file covers (a new one must be added here)');

// 🌐 The website's own script sends the token on the three JSON routes and resets the widget.
$hcAccountJs = (string) file_get_contents($hcSiteRoot . 'public_html' . $hcDs . 'assets' . $hcDs . 'js' . $hcDs . 'cuercode-account.js');
assertEquals(3, substr_count($hcAccountJs, 'human_check_token: humanCheckToken($form)'), 'account script: register, sign in and forgot password send the token');
assertTrue(substr_count($hcAccountJs, 'humanCheckReset($form, xhr)') >= 3, 'account script: the widget is reset after each answer (a token works once), or moved to the next provider');
assertEquals(3, substr_count($hcAccountJs, 'human_check_provider: humanCheckProvider($form)'), 'account script: the three JSON routes say which configured provider made the token');
$hcWidgetJs = (string) file_get_contents($hcSiteRoot . 'public_html' . $hcDs . 'assets' . $hcDs . 'js' . $hcDs . 'cuercode-human-check.js');
foreach ($hcFacts as $hcProvider => $hcFact) {
    assertContains("'" . $hcProvider . "'", $hcWidgetJs, 'widget script: knows ' . $hcFact['name']);
}
assertContains('cuercodeHumanCheckLoaded', $hcWidgetJs, 'widget script: defines the callback the provider script calls');

// 🧩 Loaded on every page.
$hcBootstrap = (string) file_get_contents($hcSiteRoot . '_includes' . $hcDs . 'bootstrap.php');
assertContains("'fn_human_check.php'", $hcBootstrap, 'bootstrap.php loads fn_human_check.php');

// ==============================================================================
// 🛡️ Section 8: the content security policy - exactly the addresses needed
// ==============================================================================

$hcHtaccess = (string) file_get_contents($hcSiteRoot . 'public_html' . $hcDs . '.htaccess');
$hcExpectedCsp = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://challenges.cloudflare.com https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://hcaptcha.com https://*.hcaptcha.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://hcaptcha.com https://*.hcaptcha.com; "
    . "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
    . "img-src 'self' data: https:; "
    . "connect-src 'self' https://www.google.com/recaptcha/ https://hcaptcha.com https://*.hcaptcha.com; "
    . "frame-src 'self' https://challenges.cloudflare.com https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/ https://hcaptcha.com https://*.hcaptcha.com; "
    . "frame-ancestors 'self'; base-uri 'self'; form-action 'self';";
assertContains('Header set Content-Security-Policy "' . $hcExpectedCsp . '"', $hcHtaccess, 'the site policy adds exactly the three providers\' script, frame, style and connect addresses (nothing wider)');

// ==============================================================================
// 🔏 Section 9: the privacy page names the provider in use
// ==============================================================================

$hcPrivacy = (string) file_get_contents($hcSiteRoot . 'public_html' . $hcDs . 'privacy.php');
assertContains('humanCheckProvidersInUse()', $hcPrivacy, 'privacy page: asks which providers are in use');
assertContains('_GRECAPTCHA', $hcPrivacy, 'privacy page: says the provider may set its own cookie (review: section 4)');
assertContains('based in the United States', $hcPrivacy, 'privacy page: says the providers are US companies (review: section 6)');
assertEquals([], humanCheckProvidersInUse(humanCheckReadSettings(hcGetter([]))), 'privacy helper: no providers while the check is off');
assertEquals(['Cloudflare Turnstile', 'hCaptcha'], array_column(humanCheckProvidersInUse(humanCheckReadSettings(hcGetter(array_merge(hcKeysFor('turnstile'), hcKeysFor('hcaptcha'))))), 'name'), 'privacy helper: every provider in use, in order, and never a key');
assertContains('id="human-check"', $hcPrivacy, 'privacy page: has a human-check section');
foreach ($hcFacts as $hcFact) {
    assertTrue(str_starts_with($hcFact['privacyUrl'], 'https://'), $hcFact['name'] . ': a privacy policy address to link to');
}

// ==============================================================================
// 🧱 Section 10: migration 0005 follows every upgrade-runner rule
// ==============================================================================

$hcMigrationPath = $hcSiteRoot . '_includes' . $hcDs . 'sql' . $hcDs . 'migrations' . $hcDs . '0005_human_check_settings.php';
assertTrue(is_file($hcMigrationPath), 'migration 0005 exists');
$hcMigration = migrationLoadFile($hcMigrationPath);
assertTrue($hcMigration['ok'], 'migration 0005 loads (' . $hcMigration['error'] . ')');
assertEquals([], migrationValidateDefinition($hcMigration['definition'], '0005_human_check_settings'), 'migration 0005 passes every runner check');
$hcMigrationText = (string) file_get_contents($hcMigrationPath);
assertContains("SET `settingValue` = \\'auto\\'", $hcMigrationText, 'migration 0005: switches the untouched "none" to automatic');
assertContains("AND `settingValue` = \\'none\\'", $hcMigrationText, '... only while it is still "none"');
assertContains('NOT EXISTS (SELECT 1 FROM `tblAdminAuditLog`', $hcMigrationText, '... and only if no admin (or the command line) ever changed it');
foreach (['bot_protection_when_unreachable', 'bot_protection_timeout_seconds', 'human_check_form_ip_per_hour', 'no_script_route_ip_per_hour', 'no_script_route_email_per_day', 'no_script_route_site_per_hour'] as $hcNewKey) {
    assertContains("\\'" . $hcNewKey . "\\'", $hcMigrationText, 'migration 0005 adds ' . $hcNewKey);
}
assertContains("\\'signin_only\\'", $hcMigrationText, 'migration 0005: the outage rule starts as decision 0037 (signin_only)');
foreach (['tblHumanCheckTokens', 'tblHumanCheckState', 'tblNoScriptRequests', 'tblEmailSignInRequests'] as $hcNewTable) {
    assertContains('CREATE TABLE IF NOT EXISTS `' . $hcNewTable . '`', $hcMigrationText, 'migration 0005 adds ' . $hcNewTable);
}
