<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Human check: outage fallback (0037) and no-JavaScript route (0038)
 * ==============================================================================
 *
 * Path: tests/test_human_check_fallbacks.php
 *
 * WHAT THIS PROVES (plain English):
 *
 *   Decision 0037 (a provider cannot be reached):
 *   - "Down" is decided ONLY by the server's own test call to the provider,
 *     which carries nothing from the visitor. A visitor's odd token, a
 *     strange reply to it, a missing token or any posted field never makes
 *     a provider count as down.
 *   - If one provider is down, another configured provider is used (the
 *     form is refused with "try again" and the next drawing uses the other
 *     provider); the visitor can never pick a provider that is not set up.
 *   - Only when EVERY configured provider is down is sign-in let through
 *     without the check (the other three forms are still refused), and
 *     that is written to the activity log, a bounded number of times.
 *   - The test calls themselves are bounded (remembered for a minute).
 *   - A Global Admin can choose "refuse" instead; 0037 is the default.
 *
 *   Decision 0038 (no JavaScript): the email-confirmed route's limits per
 *   visitor address, per email address and site-wide; the email address is
 *   only ever kept as a keyed hash; the widget offers the route only inside
 *   <noscript>; the email sign-in link is confirmed by a button press and
 *   never signs in a different browser without saying so (pure decisions
 *   here; the real database and HTTP run is in
 *   tests/integration/human_check_real_mysql.php).
 *
 *   NO REAL PROVIDER, NO DATABASE, NO EMAIL: everything is a stand-in.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decisions 0037 and 0038)
 * @see         web/CueRCode.net/_functions/fn_human_check.php
 * @see         web/CueRCode.net/_functions/fn_no_script_route.php
 */

requireFn('fn_human_check.php');
requireFn('fn_no_script_route.php');

$fbSiteRoot = CUERCODE_SITE_ROOT;
$fbDs       = DIRECTORY_SEPARATOR;

// ==============================================================================
// 🧰 Helpers
// ==============================================================================

/**
 * ⚙️ Settings getter from an array.
 *
 * @param array<string, mixed> $values
 * @return callable
 */
function fbGetter(array $values): callable
{
    return static function (string $key, mixed $default) use ($values): mixed {
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $default;
    };
}

/**
 * 🔑 Settings with the named providers' keys entered.
 *
 * @param array<int, string>   $providers
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function fbKeys(array $providers, array $extra = []): array
{
    $values = ['bot_protection_provider' => 'auto', 'site_url' => 'https://cuercode.net'];
    foreach ($providers as $provider) {
        $values[$provider . '_site_key']   = 'site-' . $provider;
        $values[$provider . '_secret_key'] = 'secret-' . $provider . '-PRIVATE';
    }
    return array_merge($values, $extra);
}

/**
 * 📨 A transport that answers per call through a callback, recording calls.
 *
 * @param callable          $answer (url, fields): array
 * @param array<int, array> $calls
 * @return callable
 */
function fbTransport(callable $answer, array &$calls): callable
{
    return static function (string $url, array $fields, int $timeout) use ($answer, &$calls): array {
        $calls[] = ['url' => $url, 'fields' => $fields];
        return array_merge(['ok' => true, 'status' => 200, 'body' => '', 'error' => '', 'fault' => ''], $answer($url, $fields));
    };
}

/**
 * 🩺 A health stand-in from a provider => state map, counting questions.
 *
 * @param array<string, string> $states
 * @param array<int, array>     $asked
 * @return callable
 */
function fbHealth(array $states, array &$asked): callable
{
    return static function (string $provider, int $maxAgeSeconds) use ($states, &$asked): string {
        $asked[] = [$provider, $maxAgeSeconds];
        if (isset($states[$provider])) {
            return $states[$provider];
        }
        return 'up';
    };
}

/**
 * ✅ One check with stand-ins.
 *
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $input
 * @param callable             $transport
 * @param array<string, mixed> $options
 * @return array
 */
function fbRun(array $settings, array $input, callable $transport, array $options = []): array
{
    $claims = [];
    return humanCheckVerifyRequest($input, array_merge([
        'configGetter' => fbGetter($settings),
        'storedState'  => static function (string $key): string {
            return 'missing';
        },
        'transport'    => $transport,
        'tokenClaimer' => static function (string $hash, string $provider) use (&$claims): string {
            if (isset($claims[$hash])) {
                return 'reused';
            }
            $claims[$hash] = true;
            return 'claimed';
        },
        'outageLogger' => static function (string $event, array $details): void {
        },
    ], $options));
}

$fbTimeout = static function (string $url, array $fields): array {
    return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl error 28: timed out', 'fault' => ''];
};
$fbPass = static function (string $url, array $fields): array {
    return ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => true, 'hostname' => 'cuercode.net'])];
};

// ==============================================================================
// ⚙️ The outage setting: decision 0037 is the default, "refuse" the choice
// ==============================================================================

assertEquals('signin_only', humanCheckReadSettings(fbGetter([]))['whenUnreachable'], '0037: with nothing saved, the outage rule is "let only sign-in through"');
assertEquals('refuse', humanCheckReadSettings(fbGetter(['bot_protection_when_unreachable' => 'refuse']))['whenUnreachable'], '0037: a Global Admin may choose "refuse"');
assertEquals('signin_only', humanCheckReadSettings(fbGetter(['bot_protection_when_unreachable' => 'allow']))['whenUnreachable'], '0037: the old "let everyone through" value is not offered any more and means the default');
assertEquals('signin_only', humanCheckReadSettings(fbGetter(['bot_protection_when_unreachable' => 'anything']))['whenUnreachable'], '0037: an unknown value means the default');
assertEquals(['signin_only', 'refuse'], array_keys(humanCheckOutageModes()), '0037: exactly two outage rules can be chosen');

// ==============================================================================
// 🩺 Who decides "down": only the server's own test call
// ==============================================================================

// 🧪 A reply to a visitor's token that looks broken, while the provider's
//    answer to OUR test call is normal: the visitor fails, nobody is let through.
$fbCalls = [];
$fbOddForVisitor = fbTransport(static function (string $url, array $fields): array {
    if ($fields['response'] === CUERCODE_HUMAN_CHECK_PROBE_RESPONSE) {
        return ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => false, 'error-codes' => ['invalid-input-response']])];
    }
    return ['ok' => true, 'status' => 500, 'body' => '<html>odd</html>'];
}, $fbCalls);
$fbState = [];
$fbStateOptions = [
    'stateRead'  => static function (string $key) use (&$fbState): ?array {
        if (isset($fbState[$key])) {
            return $fbState[$key];
        }
        return null;
    },
    'stateWrite' => static function (string $key, string $value) use (&$fbState): bool {
        $fbState[$key] = ['value' => $value, 'ageSeconds' => 0];
        return true;
    },
    'probeLock'  => static function (string $provider): bool {
        return true;
    },
    'probeUnlock' => static function (string $provider): void {
    },
];
$fbOdd = fbRun(fbKeys(['turnstile']), ['cf-turnstile-response' => 'crafted-token'], $fbOddForVisitor, array_merge($fbStateOptions, ['purpose' => 'login']));
assertFalse($fbOdd['ok'], '0037: a token that makes the provider answer oddly is REFUSED at sign-in (it does not open the outage rule)');
assertEquals('failed', $fbOdd['reason'], '0037: ... as "did not pass" (the server\'s own test call said the provider is up)');
assertEquals(2, count($fbCalls), '0037: one call with the visitor\'s token, then ONE test call');
assertEquals(CUERCODE_HUMAN_CHECK_PROBE_RESPONSE, $fbCalls[1]['fields']['response'], '0037: the test call sends a fixed value, never the visitor\'s token');
assertEquals('up', $fbState['health:turnstile']['value'], '0037: the test result is remembered');

// ♻️ Within the remembering time no second test call is made.
$fbCalls = [];
fbRun(fbKeys(['turnstile']), ['cf-turnstile-response' => 'crafted-token-2'], $fbOddForVisitor, array_merge($fbStateOptions, ['purpose' => 'login']));
assertEquals(1, count($fbCalls), '0037: a second odd answer straight after: no new test call (bounded)');

// 🧪 The test call's own answer: what counts as up, down and "our set-up".
assertEquals('up', humanCheckProbeOutcome(['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]), 'fault' => '']), 'probe: a normal "that token is invalid" answer means UP');
assertEquals('up', humanCheckProbeOutcome(['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => true]), 'fault' => '']), 'probe: a "yes" (a published test key) means up');
assertEquals('misconfigured', humanCheckProbeOutcome(['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => false, 'error-codes' => ['invalid-input-secret']]), 'fault' => '']), 'probe: "our secret is wrong" is OUR set-up, never "down"');
assertEquals('down', humanCheckProbeOutcome(['ok' => false, 'status' => 0, 'body' => '', 'fault' => '']), 'probe: no answer at all means down');
assertEquals('down', humanCheckProbeOutcome(['ok' => true, 'status' => 503, 'body' => '<html>busy</html>', 'fault' => '']), 'probe: an error page from the provider (5xx) means down');
assertEquals('up', humanCheckProbeOutcome(['ok' => true, 'status' => 429, 'body' => '<html>slow down</html>', 'fault' => '']), 'probe: "too many requests" (429) means the provider IS reachable - a flood of junk tokens sent through this server can never make it count as down');
assertEquals('up', humanCheckProbeOutcome(['ok' => true, 'status' => 403, 'body' => '<html>blocked</html>', 'fault' => '']), 'probe: any other 4xx page also counts as reachable (refusing is the safe direction)');
assertEquals('down', humanCheckProbeOutcome(['ok' => true, 'status' => 200, 'body' => (string) json_encode(['success' => false, 'error-codes' => ['internal-error']]), 'fault' => '']), 'probe: the provider\'s "internal-error" means down');
assertEquals('misconfigured', humanCheckProbeOutcome(['ok' => false, 'status' => 0, 'body' => '', 'fault' => 'ours']), 'probe: a fault on our own server (curl missing, certificates) is our set-up, never "down"');

// 🔒 Another request already testing the provider: count it as up (never as down).
$fbCalls   = [];
$fbBusy    = humanCheckProviderHealth('turnstile', 60, [
    'active'      => humanCheckActive(humanCheckReadSettings(fbGetter(fbKeys(['turnstile'])))),
    'timeout'     => 5,
    'transport'   => fbTransport($fbTimeout, $fbCalls),
    'stateRead'   => static function (string $key): ?array {
        return ['value' => 'down', 'ageSeconds' => 9999];
    },
    'stateWrite'  => static function (string $key, string $value): bool {
        return true;
    },
    'probeLock'   => static function (string $provider): bool {
        return false;
    },
    'probeUnlock' => static function (string $provider): void {
    },
]);
assertEquals('up', $fbBusy, 'probe: an OLD "down" while another request holds the test: counted as up (the safe direction)');
assertEquals(0, count($fbCalls), 'probe: ... and no extra test call');

// 🗄️ Nowhere to remember the result: no test call, counted as up.
$fbCalls = [];
assertEquals('up', humanCheckProviderHealth('turnstile', 60, [
    'active'      => humanCheckActive(humanCheckReadSettings(fbGetter(fbKeys(['turnstile'])))),
    'timeout'     => 5,
    'transport'   => fbTransport($fbTimeout, $fbCalls),
    'stateRead'   => static function (string $key): ?array {
        return null;
    },
    'stateWrite'  => static function (string $key, string $value): bool {
        return false;
    },
    'probeLock'   => static function (string $provider): bool {
        return false;
    },
    'probeUnlock' => static function (string $provider): void {
    },
]), 'probe: with no record table (upgrade not applied) a provider is never counted as down');

// ==============================================================================
// 🚪 Only sign-in, and only when EVERY configured provider is down
// ==============================================================================

$fbLogged = [];
$fbLogger = static function (string $event, array $details) use (&$fbLogged): void {
    $fbLogged[] = $event;
};

foreach (['login' => true, 'register' => false, 'forgot' => false, 'resend' => false, '' => false] as $fbPurpose => $fbLetThrough) {
    $fbAsked = [];
    $fbCalls = [];
    $fbLogged = [];
    $fbResult = fbRun(fbKeys(['turnstile']), ['cf-turnstile-response' => 'real-token'], fbTransport($fbTimeout, $fbCalls), [
        'purpose'      => $fbPurpose,
        'health'       => fbHealth(['turnstile' => 'down'], $fbAsked),
        'outageLogger' => $fbLogger,
    ]);
    $fbName = '"' . $fbPurpose . '"';
    if ($fbLetThrough) {
        assertTrue($fbResult['ok'], '0037: every provider down: SIGN-IN is let through');
        assertEquals('outage_signin', $fbResult['reason'], '0037: ... reason "outage_signin"');
        assertEquals(['attempt'], $fbLogged, '0037: ... and it is written to the activity log');
    } else {
        assertFalse($fbResult['ok'], '0037: every provider down: form ' . $fbName . ' is still REFUSED');
        assertEquals('unavailable', $fbResult['reason'], '0037: form ' . $fbName . ': reason "unavailable" (try again later)');
        assertEquals([], $fbLogged, '0037: form ' . $fbName . ': nothing let through, nothing logged as let through');
    }
}

// 🔒 "refuse" chosen: sign-in is refused too.
$fbAsked = [];
$fbCalls = [];
$fbRefuse = fbRun(fbKeys(['turnstile'], ['bot_protection_when_unreachable' => 'refuse']), ['cf-turnstile-response' => 't'], fbTransport($fbTimeout, $fbCalls), [
    'purpose' => 'login',
    'health'  => fbHealth(['turnstile' => 'down'], $fbAsked),
]);
assertEquals('unavailable', $fbRefuse['reason'], '0037: with "refuse" chosen, sign-in is refused during an outage');

// 🔁 One of two providers down: no let-through; the other provider is next.
$fbAsked = [];
$fbCalls = [];
$fbOneDown = fbRun(fbKeys(['turnstile', 'recaptcha']), ['cf-turnstile-response' => 't'], fbTransport($fbTimeout, $fbCalls), [
    'purpose' => 'login',
    'health'  => fbHealth(['turnstile' => 'down', 'recaptcha' => 'up'], $fbAsked),
]);
assertFalse($fbOneDown['ok'], '0037: Turnstile down but reCAPTCHA up: sign-in is NOT let through');
assertEquals('unavailable', $fbOneDown['reason'], '0037: ... "try again" (the page then draws reCAPTCHA)');
$fbWidget = humanCheckWidgetHtml(humanCheckReadSettings(fbGetter(fbKeys(['turnstile', 'recaptcha']))), 'login', [
    'cachedHealth' => static function (string $provider): string {
        if ($provider === 'turnstile') {
            return 'down';
        }
        return 'up';
    },
]);
assertContains('data-provider="recaptcha"', $fbWidget, '0037: the widget is drawn with the provider that is up (reCAPTCHA)');
assertContains('data-fallbacks=', $fbWidget, '0037: ... and lists the other configured provider to try if its script will not load');

// 🔁 Both down: sign-in let through.
$fbAsked = [];
$fbCalls = [];
assertEquals('outage_signin', fbRun(fbKeys(['turnstile', 'recaptcha']), ['cf-turnstile-response' => 't'], fbTransport($fbTimeout, $fbCalls), [
    'purpose' => 'login',
    'health'  => fbHealth(['turnstile' => 'down', 'recaptcha' => 'down'], $fbAsked),
])['reason'], '0037: both configured providers down: sign-in is let through');

// 🧑‍💻 The visitor uses the fallback provider's token: checked with THAT provider.
$fbCalls = [];
$fbFallback = fbRun(fbKeys(['turnstile', 'recaptcha']), ['human_check_token' => 'rc-token', 'human_check_provider' => 'recaptcha'], fbTransport($fbPass, $fbCalls));
assertTrue($fbFallback['ok'], '0037: a token from the configured fallback provider (reCAPTCHA) is accepted');
assertEquals(humanCheckProviders()['recaptcha']['verifyUrl'], $fbCalls[0]['url'], '0037: ... checked with reCAPTCHA itself');
$fbCalls = [];
$fbFormFallback = fbRun(fbKeys(['turnstile', 'recaptcha']), ['g-recaptcha-response' => 'rc-form-token'], fbTransport($fbPass, $fbCalls));
assertEquals('recaptcha', $fbFormFallback['provider'], '0037: a normal form post with reCAPTCHA\'s own field is checked with reCAPTCHA');

// 🚫 A provider that is NOT set up can never be chosen by the visitor.
$fbCalls = [];
$fbUnset = fbRun(fbKeys(['turnstile']), ['human_check_token' => 'h-token', 'human_check_provider' => 'hcaptcha'], fbTransport($fbPass, $fbCalls));
assertFalse($fbUnset['ok'], '0037: naming hCaptcha (not set up) is refused');
assertEquals(0, count($fbCalls), '0037: ... and hCaptcha is never asked');
$fbCalls = [];
$fbUnsetField = fbRun(fbKeys(['turnstile']), ['h-captcha-response' => 'h-token'], fbTransport($fbPass, $fbCalls));
assertEquals('missing_token', $fbUnsetField['reason'], '0037: hCaptcha\'s own field, hCaptcha not set up: no token (never asked)');

// 🕳️ No token at sign-in: let through ONLY when every provider is down.
$fbAsked = [];
$fbCalls = [];
$fbNoTokenUp = fbRun(fbKeys(['turnstile']), ['human_check_status' => 'script-failed', 'provider_down' => '1'], fbTransport($fbPass, $fbCalls), [
    'purpose' => 'login',
    'health'  => fbHealth(['turnstile' => 'up'], $fbAsked),
]);
assertEquals('missing_token', $fbNoTokenUp['reason'], '0037: no token, provider up: refused - a posted "the script failed" claim changes nothing');
assertEquals(0, count($fbCalls), '0037: ... and no call is made to the provider');
$fbAsked = [];
$fbCalls = [];
$fbNoTokenDown = fbRun(fbKeys(['turnstile']), [], fbTransport($fbPass, $fbCalls), [
    'purpose' => 'login',
    'health'  => fbHealth(['turnstile' => 'down'], $fbAsked),
]);
assertEquals('outage_signin', $fbNoTokenDown['reason'], '0037: no token at sign-in while every provider is down (the script could not load): let through');
assertEquals([['turnstile', CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS]], $fbAsked, '0037: ... decided by the remembered test result (at most a minute old)');
$fbAsked = [];
$fbCalls = [];
assertEquals('missing_token', fbRun(fbKeys(['turnstile']), [], fbTransport($fbPass, $fbCalls), [
    'purpose' => 'register',
    'health'  => fbHealth(['turnstile' => 'down'], $fbAsked),
])['reason'], '0037: no token at SIGN-UP while every provider is down: still refused');
assertEquals([], $fbAsked, '0037: ... without even asking about health (sign-up can never use the rule)');

// 🔧 Our set-up wrong (probe says misconfigured): refused, never let through.
$fbAsked = [];
$fbCalls = [];
assertEquals('misconfigured', fbRun(fbKeys(['turnstile']), ['cf-turnstile-response' => 't'], fbTransport($fbTimeout, $fbCalls), [
    'purpose' => 'login',
    'health'  => fbHealth(['turnstile' => 'misconfigured'], $fbAsked),
])['reason'], '0037: provider test says our keys are wrong: refused (not the outage rule)');

// 📝 The activity-log rows are bounded.
$fbLogState = [];
$fbLogRows  = 0;
for ($fbI = 0; $fbI < 50; $fbI++) {
    humanCheckRecordOutage('attempt', [], [
        'logDue'      => static function (string $key, int $seconds) use (&$fbLogState): bool {
            if (isset($fbLogState[$key])) {
                return false;
            }
            $fbLogState[$key] = true;
            return true;
        },
        'activityLog' => static function (string $type, string $detail, ?string $entityType, ?int $entityID, array $extra) use (&$fbLogRows): void {
            $fbLogRows++;
        },
    ]);
}
assertEquals(1, $fbLogRows, '0037: fifty let-through sign-ins inside a minute write ONE activity row (bounded)');

// ==============================================================================
// ✉️ 0038: the email-confirmed route for visitors without JavaScript
// ==============================================================================

$fbLimits = noScriptRouteSettings(fbGetter([]));
assertEquals(['ipPerHour' => 5, 'emailPerDay' => 3, 'sitePerHour' => 30, 'passwordPerDay' => 10], $fbLimits, '0038: safe default limits: 5 an hour per visitor address, 3 a day per email address, 30 an hour for the whole site, and 10 wrong passwords checked a day per account (third-round review, Medium 1)');
assertEquals(1, noScriptRouteSettings(fbGetter(['no_script_route_ip_per_hour' => '0']))['ipPerHour'], '0038: a limit of 0 is pulled up to 1 (never "no limit")');
assertEquals(20, noScriptRouteSettings(fbGetter(['no_script_route_email_per_day' => '500']))['emailPerDay'], '0038: a huge per-address limit is pulled down to the most allowed (20)');
assertEquals(30, noScriptRouteSettings(fbGetter(['no_script_route_site_per_hour' => 'many']))['sitePerHour'], '0038: text means the default');

assertTrue(noScriptRouteDecide(['ip' => 0, 'email' => 0, 'site' => 0], $fbLimits)['allowed'], '0038: nothing sent yet: allowed');
assertEquals('ip', noScriptRouteDecide(['ip' => 5, 'email' => 0, 'site' => 0], $fbLimits)['reason'], '0038: the 6th request in an hour from one address is refused');
assertEquals('email', noScriptRouteDecide(['ip' => 0, 'email' => 3, 'site' => 0], $fbLimits)['reason'], '0038: the 4th request in a day for one email address is refused (whoever sends it)');
assertEquals('site', noScriptRouteDecide(['ip' => 0, 'email' => 0, 'site' => 30], $fbLimits)['reason'], '0038: the 31st request in an hour for the whole site is refused');
assertTrue(noScriptRouteDecide(['ip' => 4, 'email' => 2, 'site' => 29], $fbLimits)['allowed'], '0038: just under every limit: allowed');

$fbHashA = noScriptEmailHash('Person@Example.com ', 'key-one');
assertEquals($fbHashA, noScriptEmailHash('person@example.com', 'key-one'), '0038: the email key ignores capitals and spaces (one person, one count)');
assertTrue(preg_match('/^[0-9a-f]{64}$/', $fbHashA) === 1, '0038: the email is kept only as a 64-character keyed hash');
assertFalse(str_contains($fbHashA, 'example'), '0038: ... never the address itself');
assertTrue($fbHashA !== hash('sha256', 'person@example.com'), '0038: ... and not a plain hash a list of addresses could be matched against');
assertTrue($fbHashA !== noScriptEmailHash('person@example.com', 'key-two'), '0038: a different site key gives a different hash');

assertTrue(humanCheckEmailRouteChosen(['human_check_email_route' => '1']), '0038: the route is chosen by its own button');
assertFalse(humanCheckEmailRouteChosen(['human_check_email_route' => ['1']]), '0038: a list is not the button');
assertFalse(humanCheckEmailRouteChosen([]), '0038: no button, no route');

// 🧱 The widget offers the route only inside <noscript>, and only while the check is on.
$fbOnSettings = humanCheckReadSettings(fbGetter(fbKeys(['turnstile'])));
foreach (['login', 'register', 'forgot', 'resend'] as $fbPurpose) {
    $fbHtml = humanCheckWidgetHtml($fbOnSettings, $fbPurpose, ['cachedHealth' => static function (string $provider): string {
        return 'up';
    }]);
    $fbNoscriptAt = strpos($fbHtml, '<noscript>');
    $fbButtonAt   = strpos($fbHtml, 'name="human_check_email_route"');
    assertTrue($fbNoscriptAt !== false && $fbButtonAt !== false && $fbNoscriptAt < $fbButtonAt && $fbButtonAt < strpos($fbHtml, '</noscript>'), '0038: form ' . $fbPurpose . ': the email-route button is inside <noscript>');
    assertContains('type="submit"', $fbHtml, '0038: form ' . $fbPurpose . ': it is a real button (works without JavaScript)');
}
assertEquals('', humanCheckWidgetHtml(humanCheckReadSettings(fbGetter([]))), '0038: check off: no widget and no route');

// 🎫 The email sign-in link: decisions (pure).
assertEquals('sign_in_here', noScriptSignInApproveDecision(['status' => 'waiting', 'secondsLeft' => 600], true, true), '0038: link opened in the SAME browser, button pressed: that browser is signed in');
assertEquals('approve_other', noScriptSignInApproveDecision(['status' => 'waiting', 'secondsLeft' => 600], false, true), '0038: link opened in ANOTHER browser: it only approves the first browser (and says so), never signs this one in');
assertEquals('invalid', noScriptSignInApproveDecision(['status' => 'approved', 'secondsLeft' => 600], false, true), '0038: a link already used to approve cannot be used again (single use)');
assertEquals('invalid', noScriptSignInApproveDecision(['status' => 'used', 'secondsLeft' => 600], true, true), '0038: a used link is refused');
assertEquals('invalid', noScriptSignInApproveDecision(['status' => 'waiting', 'secondsLeft' => 0], true, true), '0038: an expired link is refused');
assertEquals('invalid', noScriptSignInApproveDecision(['status' => 'waiting', 'secondsLeft' => 600], true, false), '0038: the account changed since (password reset, signed out everywhere, locked, suspended): refused');
assertEquals('invalid', noScriptSignInApproveDecision(null, true, true), '0038: an unknown link is refused');

assertEquals('sign_in', noScriptSignInContinueDecision(['status' => 'approved', 'secondsLeft' => 600, 'approvedSecondsAgo' => 20], true), '0038: the first browser presses Continue after approval: signed in');
assertEquals('waiting', noScriptSignInContinueDecision(['status' => 'waiting', 'secondsLeft' => 600], true), '0038: Continue before the link is approved: "not approved yet"');
assertEquals('invalid', noScriptSignInContinueDecision(['status' => 'approved', 'secondsLeft' => 0], true), '0038: Continue after it expired: start again');
assertEquals('invalid', noScriptSignInContinueDecision(['status' => 'approved', 'secondsLeft' => 600], false), '0038: Continue after the account changed: start again');
assertEquals('invalid', noScriptSignInContinueDecision(['status' => 'used', 'secondsLeft' => 600], true), '0038: Continue on a finished request: start again');
assertEquals(15, CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES, '0038: an email sign-in link lasts 15 minutes');

// ==============================================================================
// 🔒 Static checks: the pages use the route safely
// ==============================================================================

/**
 * ✂️ PHP source without comments.
 *
 * @param string $source
 * @return string
 */
function fbCodeOnly(string $source): string
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

foreach ([
    'register'            => ['registerUser(', "noScriptRouteRunForm('register'"],
    'forgot-password'     => ['requestPasswordReset(', "noScriptRouteRunForm('forgot'"],
    'resend-verification' => ['requestNewVerificationLink(', "noScriptRouteRunForm('resend'"],
] as $fbPage => $fbNeed) {
    $fbCode = fbCodeOnly((string) file_get_contents($fbSiteRoot . 'public_html' . $fbDs . $fbPage . $fbDs . 'index.php'));
    assertContains('humanCheckEmailRouteChosen($_POST)', $fbCode, '0038: /' . $fbPage . '/ knows the email route');
    // 📌 The helper itself (limits first, the same minimum time either way)
    //    is proved with stand-ins in test_no_script_route_review_fixes.php.
    assertContains($fbNeed[1], $fbCode, '0038: /' . $fbPage . '/ runs the route through the one helper (limits first, same minimum time)');
}
$fbLoginCode = fbCodeOnly((string) file_get_contents($fbSiteRoot . 'public_html' . $fbDs . 'login' . $fbDs . 'index.php'));
assertContains('noScriptSignInStart(', $fbLoginCode, '0038: /login/ starts an email sign-in for the route');
assertContains('noScriptSignInContinue(', $fbLoginCode, '0038: /login/ has the Continue step for the first browser');
$fbRouteCode = fbCodeOnly((string) file_get_contents($fbSiteRoot . '_functions' . $fbDs . 'fn_no_script_route.php'));
$fbStartBody = substr($fbRouteCode, (int) strpos($fbRouteCode, 'function noScriptSignInStart('));
$fbGateAt     = strpos($fbStartBody, 'noScriptRouteGate(');
$fbPasswordAt = strpos($fbStartBody, 'loginUserCheckPassword(');
$fbQueueAt    = strpos($fbStartBody, 'queueEmail(');
assertTrue($fbGateAt !== false && $fbPasswordAt !== false && $fbGateAt < $fbPasswordAt, '0038: email sign-in: the limits are checked BEFORE the password (so the route cannot be used to guess passwords without limit)');
assertTrue($fbPasswordAt !== false && $fbQueueAt !== false && $fbPasswordAt < $fbQueueAt, '0038: email sign-in: the correct password is required BEFORE any email is sent');
$fbConfirmCode = fbCodeOnly((string) file_get_contents($fbSiteRoot . 'public_html' . $fbDs . 'login' . $fbDs . 'confirm' . $fbDs . 'index.php'));
$fbPostAt    = strpos($fbConfirmCode, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
$fbApproveAt = strpos($fbConfirmCode, 'noScriptSignInApprove(');
assertTrue($fbPostAt !== false && $fbApproveAt !== false && $fbPostAt < $fbApproveAt, '0038: the confirm page changes nothing on opening the link (mail scanners): only a POSTed button press approves');
assertContains('validateCSRFToken(', $fbConfirmCode, '0038: the confirm button carries the security token');
assertContains('$pageNoIndex', $fbConfirmCode, '0038: the confirm page is kept out of search results');

// 📧 The email.
requireFn('fn_email.php');
assertTrue(isValidEmailTemplateKey('email_sign_in'), '0038: the email sign-in template is allowed');
assertEquals('confirmUrl', emailQueueRequiredLinkParam('email_sign_in'), '0038: an email sign-in email without its link is never sent');
$fbLifetimes = emailQueueLinkLifetimes();
assertEquals(CUERCODE_NO_SCRIPT_SIGN_IN_TTL_MINUTES * 60, $fbLifetimes['email_sign_in'], '0038: a queued sign-in email whose link has expired is not sent late');

// ==============================================================================
// 🗣️ The admin status wording during an outage (decisions 0037 and 0038)
// ==============================================================================
// The email-confirmed route for visitors without JavaScript does not ask a
// provider, so it stays open while every provider is down - in both outage
// modes. The admin screen used to say "every form is refused" (refuse) and
// "the other forms are refused" (sign-in only), which was not true of it.
foreach (['refuse', 'signin_only'] as $fbOutageMode) {
    $fbOutageLines = implode("\n", humanCheckStatusLines(humanCheckReadSettings(static function (string $fbKey, mixed $fbDefault) use ($fbOutageMode): mixed {
        if ($fbKey === 'bot_protection_when_unreachable') {
            return $fbOutageMode;
        }
        return $fbDefault;
    })));
    assertFalse(str_contains($fbOutageLines, 'every form is refused'), 'status wording (' . $fbOutageMode . '): does not say every form is refused');
    assertContains('without JavaScript', $fbOutageLines, 'status wording (' . $fbOutageMode . '): says the email-confirmed route for visitors without JavaScript stays open');
}
$fbOutageOptions = humanCheckOutageModes();
assertFalse(str_contains($fbOutageOptions['refuse'], 'Refuse every form'), 'status wording: the "refuse" choice no longer says it refuses every form');
assertContains('without JavaScript', $fbOutageOptions['refuse'], 'status wording: the "refuse" choice says the no-JavaScript route stays open');
