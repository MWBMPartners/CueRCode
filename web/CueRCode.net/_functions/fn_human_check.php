<?php
/**
 * ==============================================================================
 * 🤖 CueRCode - Human check: Turnstile, reCAPTCHA or hCaptcha (#163)
 * ==============================================================================
 *
 * Path: _functions/fn_human_check.php
 *
 * WHAT IT IS (plain English):
 *   A "human check" is the small box a visitor ticks (or that passes on its
 *   own) to show they are a person and not a script. It protects the forms
 *   a script could abuse: create an account, sign in, forgot password, send
 *   a new verification link - and, later, the abuse-report form (#169).
 *
 *   Decision 0024: three providers are supported - Cloudflare Turnstile,
 *   Google reCAPTCHA (version 2, the "I'm not a robot" box) and hCaptcha. A
 *   Global Admin enters the keys on /admin/settings/ (stored encrypted). With
 *   the provider setting on "automatic" (the starting value, migration
 *   0005), the FIRST provider that has BOTH its keys entered is used, in the
 *   order Turnstile, reCAPTCHA, hCaptcha. With no keys entered, the check is
 *   OFF and every form works as before.
 *
 * HOW A FORM USES IT (one call each way):
 *   - Draw it inside the <form>:   echo humanCheckWidgetHtml();
 *     and add '/assets/js/cuercode-human-check.js' to the page's $pageJS.
 *   - Check it on submit:          $check = humanCheckVerifyRequest($_POST);
 *                                  if (!$check['ok']) { show $check['message'] }
 *   - A JSON route:                requireHumanCheckForApi($requestBody)
 *                                  (fn_api_accounts.php), which uses the same call.
 *   The token is read from the provider's own form field
 *   (cf-turnstile-response, g-recaptcha-response, h-captcha-response) or from
 *   the JSON field "human_check_token" that the website's script sends.
 *
 * WHAT HAPPENS WHEN... (chosen behaviour, recorded in DEV_NOTES.md):
 *   - the provider cannot be reached (no reply, an error page, a reply that
 *     is not what the provider documents): REFUSED by default with "try
 *     again in a few minutes" (503). A Global Admin may choose "let people
 *     through" instead (bot_protection_when_unreachable = allow). Either way
 *     it is written to the error log.
 *   - our own keys are wrong (the provider says the secret is missing,
 *     invalid or does not match the site key): ALWAYS refused and logged as
 *     an error, because "let people through" would then silently switch the
 *     protection off for good.
 *   - the only Global Admin is locked out by either of the two above:
 *     `php _includes/cli/human_check.php off` on the server switches the
 *     check off (written to the admin change log); `... on` puts it back.
 *   - JavaScript is off: the widget cannot be drawn (all three providers
 *     need JavaScript), so the page shows a plain note saying so, and a form
 *     sent without a token is refused with the same note. The rate limits
 *     still apply.
 *
 * PRIVACY: the visitor's browser loads the provider's script, so the
 *   provider sees the visitor's address there. The server sends the provider
 *   only the secret key, the token (and, for hCaptcha, our site key) - never
 *   the visitor's address, email or anything they typed. The privacy page
 *   names the provider in use (humanCheckActive()).
 *
 * House rules kept here: full multi-line PHP (no ??, ?:, ternaries, arrow
 * functions or match), #[\SensitiveParameter] on everything that can hold a
 * secret or a token, nothing secret in any result, log line or page.
 *
 * 📦 LOADING: bootstrap.php loads it on every page. Loading defines
 *    constants and functions only (no database call, no output).
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0024)
 * @see         docs/decisions/0024-bot-check-supports-three-providers-at-first-launch.md
 * @see         _includes/cli/human_check.php - the command-line off switch
 * @see         public_html/assets/js/cuercode-human-check.js - draws the widget
 * @see         https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 * @see         https://developers.google.com/recaptcha/docs/verify
 * @see         https://docs.hcaptcha.com/#verify-the-user-response-server-side
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $humanCheckScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $humanCheckScriptFilename = '';
}
if (basename($humanCheckScriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// 🔒 The safe outbound-request settings (no redirects, https only, certificate
//    checked) live in ONE place, fn_oauth.php. Never copied here.
if (!function_exists('oauthApplyCurlSafety')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_oauth.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var int Longest token accepted, in characters (Turnstile documents 2048; the others are shorter). */
if (!defined('CUERCODE_HUMAN_CHECK_TOKEN_MAX_LENGTH')) {
    define('CUERCODE_HUMAN_CHECK_TOKEN_MAX_LENGTH', 4096);
}

/** @var string The JSON field the website's script (and any API caller) puts the token in. */
if (!defined('CUERCODE_HUMAN_CHECK_JSON_FIELD')) {
    define('CUERCODE_HUMAN_CHECK_JSON_FIELD', 'human_check_token');
}

/** @var int Seconds to wait for the provider when no valid setting is saved. */
if (!defined('CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT')) {
    define('CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT', 5);
}

/** @var int Shortest waiting time a Global Admin may choose, in seconds. */
if (!defined('CUERCODE_HUMAN_CHECK_MIN_TIMEOUT')) {
    define('CUERCODE_HUMAN_CHECK_MIN_TIMEOUT', 2);
}

/** @var int Longest waiting time a Global Admin may choose, in seconds. */
if (!defined('CUERCODE_HUMAN_CHECK_MAX_TIMEOUT')) {
    define('CUERCODE_HUMAN_CHECK_MAX_TIMEOUT', 15);
}

// ==============================================================================
// 🧠 PURE: the providers
// ==============================================================================

/**
 * 📋 The three providers, IN THE ORDER THEY ARE TRIED (decision 0024).
 *
 * scriptUrl ends with render=explicit&onload=cuercodeHumanCheckLoaded: our own
 * script (cuercode-human-check.js) defines that callback first and then loads
 * the provider's script, so the widget is drawn in the site's light or dark
 * colours and can be reset after each try (a token works only once).
 *
 * testSecrets are the providers' PUBLISHED test secret keys (read in their
 * documentation, 2026-11-27). They pass or fail everything by design, and
 * Turnstile's always reports the host name "example.com", so with one of them
 * the host-name check is skipped and the admin screen warns about it.
 *
 * @return array<string, array{name: string, siteKeySetting: string, secretSetting: string, verifyUrl: string, scriptUrl: string, formField: string, privacyUrl: string, testSecrets: array<int, string>}>
 */
function humanCheckProviders(): array
{
    return [
        'turnstile' => [
            'name'           => 'Cloudflare Turnstile',
            'siteKeySetting' => 'turnstile_site_key',
            'secretSetting'  => 'turnstile_secret_key',
            'verifyUrl'      => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'scriptUrl'      => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=cuercodeHumanCheckLoaded',
            'formField'      => 'cf-turnstile-response',
            'privacyUrl'     => 'https://www.cloudflare.com/privacypolicy/',
            'testSecrets'    => [
                '1x0000000000000000000000000000000AA',
                '2x0000000000000000000000000000000AA',
                '3x0000000000000000000000000000000AA',
            ],
        ],
        'recaptcha' => [
            'name'           => 'Google reCAPTCHA',
            'siteKeySetting' => 'recaptcha_site_key',
            'secretSetting'  => 'recaptcha_secret_key',
            'verifyUrl'      => 'https://www.google.com/recaptcha/api/siteverify',
            'scriptUrl'      => 'https://www.google.com/recaptcha/api.js?render=explicit&onload=cuercodeHumanCheckLoaded&hl=en-GB',
            'formField'      => 'g-recaptcha-response',
            'privacyUrl'     => 'https://policies.google.com/privacy',
            'testSecrets'    => [
                '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe',
            ],
        ],
        'hcaptcha' => [
            'name'           => 'hCaptcha',
            'siteKeySetting' => 'hcaptcha_site_key',
            'secretSetting'  => 'hcaptcha_secret_key',
            'verifyUrl'      => 'https://api.hcaptcha.com/siteverify',
            'scriptUrl'      => 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=cuercodeHumanCheckLoaded&hl=en',
            'formField'      => 'h-captcha-response',
            'privacyUrl'     => 'https://www.hcaptcha.com/privacy',
            'testSecrets'    => [
                '0x0000000000000000000000000000000000000000',
            ],
        ],
    ];
}

/**
 * 🧭 Which provider is in use? PURE.
 *
 *   'none'                 -> off (the off switch; also what the command line sets)
 *   'auto'                 -> the first provider with BOTH keys, in order
 *   'turnstile' and so on  -> that provider only, and off if its keys are missing
 *                             (never quietly a different provider)
 *   anything else          -> treated as 'auto', so a damaged value never
 *                             switches the protection off
 *
 * @param string $choice The bot_protection_provider setting
 * @param array<string, array{siteKey: string, secret: string}> $keys Provider => its two keys
 * @return string|null The provider key, or null when the check is off
 */
function humanCheckChooseProvider(string $choice, #[\SensitiveParameter] array $keys): ?string
{
    if ($choice === 'none') {
        return null;
    }

    $providers = humanCheckProviders();

    if (isset($providers[$choice])) {
        if (humanCheckKeysPresent($keys, $choice)) {
            return $choice;
        }
        return null;
    }

    foreach (array_keys($providers) as $provider) {
        if (humanCheckKeysPresent($keys, $provider)) {
            return $provider;
        }
    }

    return null;
}

/**
 * 🔑 Does this provider have both keys? Keys made only of spaces do not count. PURE.
 *
 * @param array<string, array{siteKey: string, secret: string}> $keys
 * @param string $provider
 * @return bool
 */
function humanCheckKeysPresent(#[\SensitiveParameter] array $keys, string $provider): bool
{
    if (!isset($keys[$provider]['siteKey'], $keys[$provider]['secret'])) {
        return false;
    }

    return trim((string) $keys[$provider]['siteKey']) !== '' && trim((string) $keys[$provider]['secret']) !== '';
}

// ==============================================================================
// ⚙️ Settings
// ==============================================================================

/**
 * ⚙️ Read every human-check setting.
 *
 * @param callable|null $configGetter function (string $key, mixed $default): mixed.
 *                                    Null means the site's getConfig() (which
 *                                    decrypts the keys). Tests pass their own.
 * @return array{choice: string, keys: array<string, array{siteKey: string, secret: string}>, whenUnreachable: string, timeout: int}
 */
function humanCheckReadSettings(?callable $configGetter = null): array
{
    if ($configGetter === null) {
        $configGetter = 'humanCheckSiteConfig';
    }

    $choice = strtolower(trim((string) $configGetter('bot_protection_provider', 'auto')));

    $keys = [];
    foreach (humanCheckProviders() as $provider => $facts) {
        $keys[$provider] = [
            'siteKey' => trim((string) $configGetter($facts['siteKeySetting'], '')),
            'secret'  => trim((string) $configGetter($facts['secretSetting'], '')),
        ];
    }

    $whenUnreachable = 'refuse';
    if (strtolower(trim((string) $configGetter('bot_protection_when_unreachable', 'refuse'))) === 'allow') {
        $whenUnreachable = 'allow';
    }

    // ⏱️ A whole number of seconds within the bounds, otherwise the default.
    $timeout    = CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT;
    $timeoutRaw = trim((string) $configGetter('bot_protection_timeout_seconds', (string) CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT));
    if (preg_match('/^[0-9]{1,3}$/', $timeoutRaw) === 1) {
        $timeoutNumber = (int) $timeoutRaw;
        if ($timeoutNumber >= CUERCODE_HUMAN_CHECK_MIN_TIMEOUT && $timeoutNumber <= CUERCODE_HUMAN_CHECK_MAX_TIMEOUT) {
            $timeout = $timeoutNumber;
        }
    }

    return [
        'choice'          => $choice,
        'keys'            => $keys,
        'whenUnreachable' => $whenUnreachable,
        'timeout'         => $timeout,
    ];
}

/**
 * ⚙️ The site's own settings reader, with a safe answer when it is missing
 *    (a tool that loaded this file alone).
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function humanCheckSiteConfig(string $key, mixed $default): mixed
{
    if (!function_exists('getConfig')) {
        return $default;
    }

    return getConfig($key, $default);
}

/**
 * 🟢 The provider in use now, with what a page needs - or null when off.
 *
 * @param array|null $settings From humanCheckReadSettings(); null reads the site's settings
 * @return array{provider: string, name: string, siteKey: string, secret: string, isTestKey: bool, scriptUrl: string, formField: string, verifyUrl: string, privacyUrl: string}|null
 */
function humanCheckActive(#[\SensitiveParameter] ?array $settings = null): ?array
{
    if ($settings === null) {
        $settings = humanCheckReadSettings();
    }

    $provider = humanCheckChooseProvider($settings['choice'], $settings['keys']);
    if ($provider === null) {
        return null;
    }

    $facts  = humanCheckProviders()[$provider];
    $secret = $settings['keys'][$provider]['secret'];

    return [
        'provider'   => $provider,
        'name'       => $facts['name'],
        'siteKey'    => $settings['keys'][$provider]['siteKey'],
        'secret'     => $secret,
        'isTestKey'  => in_array($secret, $facts['testSecrets'], true),
        'scriptUrl'  => $facts['scriptUrl'],
        'formField'  => $facts['formField'],
        'verifyUrl'  => $facts['verifyUrl'],
        'privacyUrl' => $facts['privacyUrl'],
    ];
}

// ==============================================================================
// 🏠 PURE: host names
// ==============================================================================

/**
 * 🏠 A host name in a form that can be compared: lower case, no port, no
 *    final dot, no spaces. Anything with a control character gives ''. PURE.
 *
 * @param string $host For example "WWW.Example.com:8443"
 * @return string For example "www.example.com"
 */
function humanCheckNormaliseHost(string $host): string
{
    if (preg_match('/[\x00-\x1F\x7F]/', $host) === 1) {
        return '';
    }

    $host = strtolower(trim($host));

    if (str_starts_with($host, '[')) {
        // 🌐 A bracketed IPv6 address: keep the brackets, drop any port.
        $closing = strpos($host, ']');
        if ($closing === false) {
            return '';
        }
        return substr($host, 0, $closing + 1);
    }

    $colon = strpos($host, ':');
    if ($colon !== false) {
        $host = substr($host, 0, $colon);
    }

    return rtrim($host, '.');
}

/**
 * 🏠 The host name a token should have been made on: the host name the
 *    visitor's browser used for THIS request (on shared hosting and behind
 *    Cloudflare that is always one of the site's own names, and it covers the
 *    alpha and beta copies too), or - with no request, as on the command
 *    line - the host of the website address setting. PURE.
 *
 * @param array<string, mixed> $server  $_SERVER (or a stand-in)
 * @param string               $siteUrl The site_url setting
 * @return string '' when neither is usable
 */
function humanCheckExpectedHost(array $server, string $siteUrl): string
{
    if (isset($server['HTTP_HOST']) && is_string($server['HTTP_HOST'])) {
        $requestHost = humanCheckNormaliseHost($server['HTTP_HOST']);
        if ($requestHost !== '') {
            return $requestHost;
        }
    }

    $siteHost = parse_url($siteUrl, PHP_URL_HOST);
    if (!is_string($siteHost)) {
        return '';
    }

    return humanCheckNormaliseHost($siteHost);
}

// ==============================================================================
// 🧠 PURE: reading the provider's reply
// ==============================================================================

/**
 * 🧾 The visitor's token from a form or JSON body. "human_check_token" first
 *    (the JSON routes), then the provider's own form field. Anything that is
 *    not a non-empty string gives ''. PURE.
 *
 * @param array<string, mixed> $input     $_POST or the parsed JSON body
 * @param string               $formField The provider's own field name
 * @return string
 */
function humanCheckTokenFromInput(#[\SensitiveParameter] array $input, string $formField): string
{
    foreach ([CUERCODE_HUMAN_CHECK_JSON_FIELD, $formField] as $field) {
        if (isset($input[$field]) && is_string($input[$field])) {
            $token = trim($input[$field]);
            if ($token !== '') {
                return $token;
            }
        }
    }

    return '';
}

/**
 * 🧾 What the provider's reply means. PURE.
 *
 * Outcomes: passed | failed | expired_or_reused | wrong_host | misconfigured | unavailable
 *
 * The three providers share one reply shape: {"success": true|false,
 * "hostname": "...", "error-codes": [...]}. A reply of any other shape, an
 * error page or no reply at all is "unavailable" - the check could not be
 * done, which is different from the visitor failing it.
 *
 * @param int    $httpStatus     The reply's HTTP status (0 = no reply)
 * @param string $body           The reply text
 * @param string $expectedHost   From humanCheckExpectedHost() ('' = do not compare)
 * @param bool   $skipHostCheck  True for a published test secret
 * @return array{outcome: string, errorCodes: array<int, string>}
 */
function humanCheckInterpretReply(int $httpStatus, string $body, string $expectedHost, bool $skipHostCheck): array
{
    if ($httpStatus !== 200) {
        return ['outcome' => 'unavailable', 'errorCodes' => []];
    }

    $reply = json_decode($body, true);
    if (!is_array($reply) || array_is_list($reply) || !isset($reply['success']) || !is_bool($reply['success'])) {
        return ['outcome' => 'unavailable', 'errorCodes' => []];
    }

    // 🧾 The provider's own words for what went wrong (short, safe to log).
    $errorCodes = [];
    if (isset($reply['error-codes']) && is_array($reply['error-codes'])) {
        foreach ($reply['error-codes'] as $code) {
            if (is_string($code) && preg_match('/^[a-z0-9-]{1,64}$/', $code) === 1) {
                $errorCodes[] = $code;
            }
        }
    }

    if ($reply['success'] === true) {
        if ($skipHostCheck || $expectedHost === '') {
            return ['outcome' => 'passed', 'errorCodes' => []];
        }

        // 🏠 No host name, or hCaptcha's "not-provided" (its documentation says
        //    it may send that under heavy load), is accepted; any other host
        //    name must be exactly ours.
        $replyHost = '';
        if (isset($reply['hostname']) && is_string($reply['hostname'])) {
            $replyHost = humanCheckNormaliseHost($reply['hostname']);
        }
        if ($replyHost === '' || $replyHost === 'not-provided' || $replyHost === $expectedHost) {
            return ['outcome' => 'passed', 'errorCodes' => []];
        }

        return ['outcome' => 'wrong_host', 'errorCodes' => []];
    }

    // 🔧 Our own keys are wrong (secret missing or invalid, or the site key
    //    and secret do not belong together, or a test site key with a real secret).
    $ourFault = ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch', 'not-using-dummy-passcode'];
    // ♻️ A token used before, or too old (each provider's own words).
    $replayed = ['timeout-or-duplicate', 'already-seen-response', 'invalid-or-already-seen-response', 'expired-input-response'];
    // 🌩️ The provider's own fault.
    $theirFault = ['internal-error'];

    foreach ($errorCodes as $code) {
        if (in_array($code, $ourFault, true)) {
            return ['outcome' => 'misconfigured', 'errorCodes' => $errorCodes];
        }
    }
    foreach ($errorCodes as $code) {
        if (in_array($code, $theirFault, true)) {
            return ['outcome' => 'unavailable', 'errorCodes' => $errorCodes];
        }
    }
    foreach ($errorCodes as $code) {
        if (in_array($code, $replayed, true)) {
            return ['outcome' => 'expired_or_reused', 'errorCodes' => $errorCodes];
        }
    }

    return ['outcome' => 'failed', 'errorCodes' => $errorCodes];
}

/**
 * 📦 The result every caller gets, with its plain message. PURE.
 *
 * @param string $reason          off | passed | unavailable_allowed | missing_token | failed | wrong_host | expired_or_reused | unavailable | misconfigured
 * @param string $provider        The provider key ('' when off)
 * @return array{ok: bool, reason: string, provider: string, message: string, httpCode: int, errorCode: string}
 */
function humanCheckResult(string $reason, string $provider): array
{
    $result = [
        'ok'        => false,
        'reason'    => $reason,
        'provider'  => $provider,
        'message'   => '',
        'httpCode'  => 200,
        'errorCode' => '',
    ];

    if ($reason === 'off' || $reason === 'passed' || $reason === 'unavailable_allowed') {
        $result['ok'] = true;
        return $result;
    }

    // 📌 Each error code is written as 'errorCode' => '...' so the API error
    //    catalogue check (tests/test_error_catalogue.php) finds it.
    if ($reason === 'missing_token') {
        return array_merge($result, [
            'message'   => 'Please complete the human check (the box that shows you are a person, not a program) and try again. It needs JavaScript: if you cannot see it, switch JavaScript on in your browser.',
            'httpCode'  => 400,
            'errorCode' => 'HUMAN_CHECK_REQUIRED',
        ]);
    }

    if ($reason === 'expired_or_reused') {
        return array_merge($result, [
            'message'   => 'The human check has expired or was already used. Please complete it again and resend the form.',
            'httpCode'  => 403,
            'errorCode' => 'HUMAN_CHECK_FAILED',
        ]);
    }

    if ($reason === 'unavailable' || $reason === 'misconfigured') {
        return array_merge($result, [
            'message'   => 'The human check could not be completed just now. Please try again in a few minutes.',
            'httpCode'  => 503,
            'errorCode' => 'HUMAN_CHECK_UNAVAILABLE',
        ]);
    }

    // ❌ failed, wrong_host and anything unexpected.
    return array_merge($result, [
        'message'   => 'The human check did not pass. Please try it again.',
        'httpCode'  => 403,
        'errorCode' => 'HUMAN_CHECK_FAILED',
    ]);
}

// ==============================================================================
// 🌐 The request to the provider
// ==============================================================================

/**
 * 🌐 POST a form to the provider and return what came back. TLS checked, no
 *    redirects, https only (oauthApplyCurlSafety()), never unpacks compressed
 *    replies (#150), a short time limit. Nothing sent is ever logged.
 *
 * @param string               $url     The provider's checking address
 * @param array<string, string> $fields The form fields (secret, response, ...)
 * @param int                  $timeout Seconds
 * @return array{ok: bool, status: int, body: string, error: string}
 */
function humanCheckHttpPost(string $url, #[\SensitiveParameter] array $fields, int $timeout): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'the curl extension is not installed'];
    }

    $curlHandle = curl_init();
    if ($curlHandle === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init() failed'];
    }

    curl_setopt_array($curlHandle, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);

    // 🔒 No redirects, https only, certificates checked.
    if (!oauthApplyCurlSafety($curlHandle)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'the safe request settings could not be applied'];
    }

    $responseBody = curl_exec($curlHandle);
    $curlErrno    = curl_errno($curlHandle);
    $curlError    = curl_error($curlHandle);
    $httpCode     = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    // 🧹 No curl_close($curlHandle): a no-op since PHP 8.0, and PHP 8.5 warns (#117).

    if ($curlErrno !== 0 || $responseBody === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl error ' . $curlErrno . ': ' . $curlError];
    }

    return ['ok' => true, 'status' => $httpCode, 'body' => (string) $responseBody, 'error' => ''];
}

/**
 * ✅ THE ONE CALL: does this form or JSON body pass the human check?
 *
 * Off (no keys, or switched off) -> ok, nothing is sent anywhere.
 * Otherwise the token is read, checked for shape, sent to the provider with
 * the secret key, and the reply decides. Never throws.
 *
 * @param array<string, mixed> $input   $_POST or the parsed JSON body
 * @param array<string, mixed> $options Only for tests and tools:
 *                                      'configGetter' (callable(key, default)),
 *                                      'transport' (callable(url, fields, timeout): array like humanCheckHttpPost()),
 *                                      'expectedHost' (string)
 * @return array{ok: bool, reason: string, provider: string, message: string, httpCode: int, errorCode: string}
 */
function humanCheckVerifyRequest(#[\SensitiveParameter] array $input, array $options = []): array
{
    $configGetter = null;
    if (isset($options['configGetter']) && is_callable($options['configGetter'])) {
        $configGetter = $options['configGetter'];
    }

    $settings = humanCheckReadSettings($configGetter);
    $active   = humanCheckActive($settings);

    if ($active === null) {
        return humanCheckResult('off', '');
    }

    $provider = $active['provider'];
    $token    = humanCheckTokenFromInput($input, $active['formField']);

    if ($token === '') {
        return humanCheckResult('missing_token', $provider);
    }

    if (mb_strlen($token) > CUERCODE_HUMAN_CHECK_TOKEN_MAX_LENGTH) {
        return humanCheckResult('failed', $provider);
    }

    if (isset($options['expectedHost']) && is_string($options['expectedHost'])) {
        $expectedHost = humanCheckNormaliseHost($options['expectedHost']);
    } else {
        $expectedHost = humanCheckExpectedHost($_SERVER, (string) humanCheckSiteConfig('site_url', ''));
    }

    $transport = 'humanCheckHttpPost';
    if (isset($options['transport']) && is_callable($options['transport'])) {
        $transport = $options['transport'];
    }

    $fields = [
        'secret'   => $active['secret'],
        'response' => $token,
    ];
    if ($provider === 'hcaptcha') {
        // 🪪 hCaptcha can then say whether the token was made for OUR site key.
        $fields['sitekey'] = $active['siteKey'];
    }

    $reply = $transport($active['verifyUrl'], $fields, $settings['timeout']);
    $fields = [];
    $token  = '';

    $status = 0;
    $body   = '';
    if (is_array($reply) && isset($reply['ok']) && $reply['ok'] === true) {
        if (isset($reply['status'])) {
            $status = (int) $reply['status'];
        }
        if (isset($reply['body']) && is_string($reply['body'])) {
            $body = $reply['body'];
        }
    }

    $verdict = humanCheckInterpretReply($status, $body, $expectedHost, $active['isTestKey']);
    $outcome = $verdict['outcome'];

    if ($outcome === 'unavailable') {
        $transportError = '';
        if (is_array($reply) && isset($reply['error']) && is_string($reply['error'])) {
            $transportError = $reply['error'];
        }
        humanCheckLog('warning', 'Human check provider could not be reached or gave an unexpected reply', 'provider: ' . $provider . ' | HTTP ' . $status . ' | ' . substr($transportError, 0, 200) . ' | codes: ' . implode(',', $verdict['errorCodes']) . ' | when unreachable: ' . $settings['whenUnreachable']);
        if ($settings['whenUnreachable'] === 'allow') {
            return humanCheckResult('unavailable_allowed', $provider);
        }
        return humanCheckResult('unavailable', $provider);
    }

    if ($outcome === 'misconfigured') {
        humanCheckLog('error', 'Human check keys refused by the provider - check them on /admin/settings/ (or switch the check off: php _includes/cli/human_check.php off)', 'provider: ' . $provider . ' | codes: ' . implode(',', $verdict['errorCodes']));
        return humanCheckResult('misconfigured', $provider);
    }

    return humanCheckResult($outcome, $provider);
}

/**
 * 📝 Write to the site's error log when it is loaded. Never given a secret or token.
 *
 * @param string $severity warning | error
 * @param string $title
 * @param string $detail
 * @return void
 */
function humanCheckLog(string $severity, string $title, string $detail): void
{
    if (function_exists('logError')) {
        logError($severity, $title, $detail);
    }
}

// ==============================================================================
// 🧱 The widget
// ==============================================================================

/**
 * 🧱 The human-check box to put INSIDE a form, or '' when the check is off.
 *
 * Draws only an empty container with the provider, the public site key and
 * the provider's script address as data attributes; our own script
 * (/assets/js/cuercode-human-check.js, which the page adds to $pageJS) loads
 * the provider's script and draws the widget in it. With JavaScript off the
 * <noscript> note explains why the form will not go through.
 *
 * @param array|null $settings From humanCheckReadSettings(); null reads the site's settings
 * @return string HTML
 */
function humanCheckWidgetHtml(#[\SensitiveParameter] ?array $settings = null): string
{
    $active = humanCheckActive($settings);
    if ($active === null) {
        return '';
    }

    return '<div class="mb-3">'
        . '<p class="form-label mb-1" id="human-check-label">Human check</p>'
        . '<div class="cuercode-human-check" role="group" aria-labelledby="human-check-label"'
        . ' data-provider="' . humanCheckEscape($active['provider']) . '"'
        . ' data-sitekey="' . humanCheckEscape($active['siteKey']) . '"'
        . ' data-script="' . humanCheckEscape($active['scriptUrl']) . '"></div>'
        . '<noscript><div class="alert alert-warning mt-2 mb-0" role="alert">'
        . 'This form uses a human check from ' . humanCheckEscape($active['name']) . ', which needs JavaScript. '
        . 'Please switch JavaScript on in your browser to use this form.'
        . '</div></noscript>'
        . '</div>';
}

/**
 * 🧼 Escape text for an HTML attribute or text.
 *
 * @param string $text
 * @return string
 */
function humanCheckEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// ==============================================================================
// 📋 Status (admin screen and command line)
// ==============================================================================

/**
 * 📋 Plain lines saying what the check is doing now. Never shows a key. PURE.
 *
 * @param array $settings From humanCheckReadSettings()
 * @return array<int, string>
 */
function humanCheckStatusLines(#[\SensitiveParameter] array $settings): array
{
    $providers = humanCheckProviders();
    $active    = humanCheckActive($settings);
    $lines     = [];

    if ($active !== null) {
        $lines[] = 'In use now: ' . $active['name'] . '.';
        if ($active['isTestKey']) {
            $lines[] = 'Warning: the saved secret is the provider\'s published test key, which passes or fails every check by design. Never use it on the live site.';
        }
    } elseif ($settings['choice'] === 'none') {
        $lines[] = 'Off: the human check is switched off (provider setting "none").';
    } elseif (isset($providers[$settings['choice']])) {
        $lines[] = 'Off: ' . $providers[$settings['choice']]['name'] . ' is chosen but its site key and secret key are not both entered.';
    } else {
        $lines[] = 'Off: no provider has both its site key and secret key entered.';
    }

    if ($settings['whenUnreachable'] === 'allow') {
        $lines[] = 'If the provider cannot be reached: people are let through (and it is logged).';
    } else {
        $lines[] = 'If the provider cannot be reached: forms are refused with "try again in a few minutes" (and it is logged).';
    }

    return $lines;
}

// ==============================================================================
// 🖥️ The command-line switch (_includes/cli/human_check.php)
// ==============================================================================

/**
 * 🧾 Read the command-line options. PURE.
 *
 * @param array<int, mixed> $arguments The words after the script name
 * @return array{action: string, error: string} action: status | on | off | help
 */
function humanCheckCliParseArgs(array $arguments): array
{
    $actions = [];

    foreach ($arguments as $argument) {
        if (!is_string($argument)) {
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            return ['action' => 'help', 'error' => ''];
        }
        if (in_array($argument, ['status', 'on', 'off'], true)) {
            $actions[] = $argument;
            continue;
        }
        return ['action' => '', 'error' => 'Unknown option: ' . preg_replace('/[^\x20-\x7E]/', '?', mb_substr($argument, 0, 60))];
    }

    if (count($actions) > 1) {
        return ['action' => '', 'error' => 'Give only one of: status, on, off.'];
    }

    if ($actions === []) {
        return ['action' => 'status', 'error' => ''];
    }

    return ['action' => $actions[0], 'error' => ''];
}

/**
 * 🔁 The provider-setting value each command-line action stores. PURE.
 *
 * @param string $action on | off
 * @return string auto | none
 */
function humanCheckCliChoiceFor(string $action): string
{
    if ($action === 'off') {
        return 'none';
    }

    return 'auto';
}

/**
 * 💾 Store the provider setting from the command line, with its admin
 *    change-log row (actor "cli:<operating-system user>"), in ONE
 *    transaction. Needs the site start-up code and fn_admin.php loaded.
 *
 * @param string $choice     auto | none
 * @param string $actorLabel From adminCliActorLabel()
 * @return array{ok: bool, changed: bool, message: string}
 */
function humanCheckCliSaveChoice(string $choice, string $actorLabel): array
{
    if (!in_array($choice, ['auto', 'none'], true)) {
        return ['ok' => false, 'changed' => false, 'message' => 'Only "auto" or "none" can be stored from the command line.'];
    }

    if (!function_exists('getDB') || !function_exists('setConfig') || !function_exists('adminAuditWrite') || !function_exists('adminTableExists') || !function_exists('adminRollbackQuietly')) {
        return ['ok' => false, 'changed' => false, 'message' => 'The site start-up code is not loaded, so nothing was changed.'];
    }

    if (!adminTableExists('tblAdminAuditLog')) {
        return ['ok' => false, 'changed' => false, 'message' => 'Database upgrades are waiting (php _includes/cli/migrate.php --apply), so nothing was changed.'];
    }

    $database = getDB();
    $before   = '';

    try {
        $database->begin_transaction();

        // 🔐 Read the row with a lock, so the change-log row says what it really was.
        $stmt = dbQuery('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? FOR UPDATE', 's', ['bot_protection_provider']);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($row) && isset($row['settingValue'])) {
            $before = (string) $row['settingValue'];
        }

        if ($before === $choice) {
            $database->commit();
            return ['ok' => true, 'changed' => false, 'message' => 'Nothing to change: the provider setting is already "' . $choice . '".'];
        }

        $saved = setConfig('bot_protection_provider', $choice, [
            'group'       => 'security',
            'type'        => 'string',
            'isSensitive' => false,
            'description' => 'Human check provider: auto (the first with both keys, in the order turnstile, recaptcha, hcaptcha), turnstile, recaptcha, hcaptcha, or none (off)',
        ]);

        $written = false;
        if ($saved) {
            $written = adminAuditWrite([
                'actorType'   => 'cli',
                'actorUserID' => null,
                'actorLabel'  => $actorLabel,
                'action'      => 'setting.update',
                'entityType'  => 'setting',
                'entityKey'   => 'bot_protection_provider',
                'outcome'     => 'success',
                'changes'     => adminAuditChanges(['bot_protection_provider' => $before], ['bot_protection_provider' => $choice], []),
                'reason'      => 'Changed with _includes/cli/human_check.php',
                'requestIP'   => null,
                'userAgent'   => null,
            ]);
        }

        if (!$saved || !$written) {
            adminRollbackQuietly($database);
            if (function_exists('clearConfigCache')) {
                clearConfigCache('bot_protection_provider');
            }
            return ['ok' => false, 'changed' => false, 'message' => 'The change could not be saved, so nothing was changed. Please run it again.'];
        }

        $database->commit();
    } catch (\Throwable $e) {
        adminRollbackQuietly($database);
        if (function_exists('clearConfigCache')) {
            clearConfigCache('bot_protection_provider');
        }
        humanCheckLog('error', 'Human check switch could not be saved from the command line', get_class($e));
        return ['ok' => false, 'changed' => false, 'message' => 'The change could not be saved, so nothing was changed. Please run it again.'];
    }

    return ['ok' => true, 'changed' => true, 'message' => 'Saved: the provider setting changed from "' . $before . '" to "' . $choice . '".'];
}
