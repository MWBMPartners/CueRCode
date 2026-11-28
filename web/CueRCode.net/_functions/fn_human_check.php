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
 *   0005), EVERY provider that has both its keys entered is "configured",
 *   tried in the order Turnstile, reCAPTCHA, hCaptcha. With no keys entered,
 *   the check is OFF and every form works as before.
 *
 * HOW A FORM USES IT (one call each way):
 *   - Draw it inside the <form>:   echo humanCheckWidgetHtml(null, 'login');
 *     and add '/assets/js/cuercode-human-check.js' to the page's $pageJS.
 *   - Check it on submit:          $check = humanCheckVerifyRequest($_POST, ['purpose' => 'login', 'formRateLimit' => true]);
 *                                  if (!$check['ok']) { show $check['message'] }
 *   - A JSON route:                requireHumanCheckForApi($requestBody, 'login')
 *                                  (fn_api_accounts.php), which uses the same call.
 *   'purpose' says which form it is (login, register, forgot, resend). Only
 *   'login' can ever use the outage rule below. The token is read from the
 *   provider's own form field (cf-turnstile-response, g-recaptcha-response,
 *   h-captcha-response) or from the JSON field "human_check_token" (with
 *   "human_check_provider" naming which configured provider made it).
 *
 * WHAT HAPPENS WHEN... (recorded in DEV_NOTES.md):
 *   - a token is sent twice (review M1): the first time the provider accepts
 *     it, its SHA-256 hash is recorded (tblHumanCheckTokens, kept 10
 *     minutes); the same token again is refused. If the record cannot be
 *     written, the form is refused.
 *   - the saved keys cannot be read (review M2: a changed ENCRYPTION_KEY or
 *     a damaged row): the check does NOT switch off. The provider counts as
 *     "set up but broken"; if no other provider is usable, the protected
 *     forms sent the normal way are refused, the admin status says the keys
 *     cannot be read, and the email-confirmed route (decision 0038, with its
 *     limits) is offered to EVERYONE so people keep a way in while the keys
 *     are entered again (merged review L4).
 *   - our own set-up is wrong (the provider says the secret is wrong, or our
 *     server cannot make a safe request: curl missing, certificates): ALWAYS
 *     refused, logged at most once every 10 minutes per provider.
 *   - a provider cannot be reached (decision 0037): "down" is decided ONLY
 *     by the server's own test call to the provider, which carries nothing
 *     from the visitor (a fixed dummy token). A strange reply to a visitor's
 *     token while the test call says "up" is simply a failed check. The
 *     test result is remembered (tblHumanCheckState) for a minute, so test
 *     calls are bounded. While one provider is down the page draws the next
 *     configured one. Only when EVERY configured provider is down does the
 *     admin-editable rule apply: "signin_only" (the 0037 default) lets SIGN-IN
 *     through without the check - the password, the wrong-password lock-out
 *     and the rate limits all still apply - and writes it to the activity log
 *     (at most one row a minute, plus one per account an hour for a
 *     successful sign-in); the other three forms are refused. "refuse"
 *     refuses sign-in too.
 *   - the four web forms are rate limited per visitor address BEFORE the
 *     call to the provider (human_check_form_ip_per_hour, default 30).
 *   - the only Global Admin is locked out: `php _includes/cli/human_check.php
 *     off` on the server switches the check off (written to the admin change
 *     log); `... on` puts it back.
 *   - JavaScript is off (decision 0038): the widget cannot be drawn, so the
 *     <noscript> part offers an email-confirmed route with strict limits
 *     (fn_no_script_route.php); a form sent without a token any other way is
 *     refused.
 *   - a provider's fresh test result says "down" (merged review I4): a
 *     visitor's token is not sent to it at all (no long wait); the outage
 *     rule above decides as if the call had failed.
 *
 * PRIVACY: the visitor's browser loads the provider's script, so the
 *   provider sees the visitor's address there. The server sends the provider
 *   only the secret key, the token (and, for hCaptcha, our site key) - never
 *   the visitor's address, email or anything they typed. The privacy page
 *   names the provider(s) in use (humanCheckActive()).
 *
 * House rules kept here: full multi-line PHP (no ??, ?:, ternaries, arrow
 * functions or match), #[\SensitiveParameter] on everything that can hold a
 * secret or a token, nothing secret in any result, log line or page, every
 * SQL identifier back-quoted and every value a placeholder.
 *
 * 📦 LOADING: bootstrap.php loads it on every page. Loading defines
 *    constants and functions only (no database call, no output).
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       2026-11-27 (#163, decision 0024; review fixes and decisions 0037, 0038)
 * @see         docs/decisions/0024-bot-check-supports-three-providers-at-first-launch.md
 * @see         docs/decisions/0037-human-check-outage-falls-back-to-other-providers-then-sign-in-only.md
 * @see         docs/decisions/0038-no-javascript-visitors-get-an-email-confirmed-fallback.md
 * @see         _functions/fn_no_script_route.php - the email-confirmed route (0038)
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

// 📏 cuercodeClampInt() (never a global clamp(), decision 0006).
if (!function_exists('cuercodeClampInt')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_compat.php';
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

/** @var string The JSON field naming which configured provider made the token (0037). */
if (!defined('CUERCODE_HUMAN_CHECK_PROVIDER_FIELD')) {
    define('CUERCODE_HUMAN_CHECK_PROVIDER_FIELD', 'human_check_provider');
}

/** @var string The form button that asks for the email-confirmed route (0038). */
if (!defined('CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD')) {
    define('CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD', 'human_check_email_route');
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

/** @var string What a settings reader hands back for "no usable value" (never a real setting). */
if (!defined('CUERCODE_HUMAN_CHECK_UNSET')) {
    define('CUERCODE_HUMAN_CHECK_UNSET', "\x00cuercode-unset\x00");
}

/** @var int Seconds an accepted token's hash is kept (every provider's tokens last at most 5 minutes). Review M1. */
if (!defined('CUERCODE_HUMAN_CHECK_REPLAY_SECONDS')) {
    define('CUERCODE_HUMAN_CHECK_REPLAY_SECONDS', 600);
}

/** @var int How old a remembered provider test result may be when deciding "every provider is down" (0037). */
if (!defined('CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS')) {
    define('CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS', 60);
}

/** @var int After an odd reply to a visitor's token, a test result older than this is re-tested (bounded). */
if (!defined('CUERCODE_HUMAN_CHECK_HEALTH_RECHECK_SECONDS')) {
    define('CUERCODE_HUMAN_CHECK_HEALTH_RECHECK_SECONDS', 15);
}

/** @var string The fixed dummy token the server's own test call sends (nothing from any visitor). */
if (!defined('CUERCODE_HUMAN_CHECK_PROBE_RESPONSE')) {
    define('CUERCODE_HUMAN_CHECK_PROBE_RESPONSE', 'cuercode-health-check');
}

/** @var int The same "our set-up is wrong" error is logged at most once in this many seconds per provider. */
if (!defined('CUERCODE_HUMAN_CHECK_LOG_INTERVAL_SECONDS')) {
    define('CUERCODE_HUMAN_CHECK_LOG_INTERVAL_SECONDS', 600);
}

/** @var int At most one "sign-in let through during an outage" activity row in this many seconds (0037). */
if (!defined('CUERCODE_HUMAN_CHECK_OUTAGE_LOG_SECONDS')) {
    define('CUERCODE_HUMAN_CHECK_OUTAGE_LOG_SECONDS', 60);
}

/** @var string The name the four web forms write to the request log for the pre-check rate limit. */
if (!defined('CUERCODE_HUMAN_CHECK_FORM_ENDPOINT')) {
    define('CUERCODE_HUMAN_CHECK_FORM_ENDPOINT', 'page/human-check');
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
 * testSecrets and testSiteKeys are the providers' PUBLISHED test keys (read
 * in their documentation, 2026-11-27). They pass or fail everything by
 * design, and Turnstile's always reports the host name "example.com", so
 * with a test secret the host-name check is skipped and the admin screen
 * warns about it.
 *
 * company is the business that runs it (all three are in the United States),
 * for the privacy page.
 *
 * @return array<string, array{name: string, siteKeySetting: string, secretSetting: string, verifyUrl: string, scriptUrl: string, formField: string, privacyUrl: string, company: string, testSecrets: array<int, string>, testSiteKeys: array<int, string>}>
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
            'company'        => 'Cloudflare, Inc.',
            'testSecrets'    => [
                '1x0000000000000000000000000000000AA',
                '2x0000000000000000000000000000000AA',
                '3x0000000000000000000000000000000AA',
            ],
            'testSiteKeys'   => [
                '1x00000000000000000000AA',
                '2x00000000000000000000AB',
                '1x00000000000000000000BB',
                '2x00000000000000000000BB',
                '3x00000000000000000000FF',
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
            'company'        => 'Google LLC',
            'testSecrets'    => [
                '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe',
            ],
            'testSiteKeys'   => [
                '6LeIxAcTAAAAAJcZVRqyHh4bKbQjCqDAqTIBmZW0',
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
            'company'        => 'Intuition Machines, Inc.',
            'testSecrets'    => [
                '0x0000000000000000000000000000000000000000',
            ],
            'testSiteKeys'   => [
                '10000000-ffff-ffff-ffff-000000000001',
                '20000000-ffff-ffff-ffff-000000000002',
                '30000000-ffff-ffff-ffff-000000000003',
            ],
        ],
    ];
}

/**
 * 🧭 Which provider is used FIRST? PURE. (Kept for callers that need one
 *    name; humanCheckPlan() gives the whole ordered list.)
 *
 *   'none'                 -> off (the off switch; also what the command line sets)
 *   'auto'                 -> the first provider with BOTH keys, in order
 *   'turnstile' and so on  -> that provider only, and off if its keys are missing
 *                             (never quietly a different provider)
 *   anything else          -> treated as 'auto', so a damaged value never
 *                             switches the protection off
 *
 * @param string $choice The bot_protection_provider setting
 * @param array<string, array{siteKey: string, secret: string, unreadable?: bool}> $keys Provider => its two keys
 * @return string|null The provider key, or null when the check is off (or broken)
 */
function humanCheckChooseProvider(string $choice, #[\SensitiveParameter] array $keys): ?string
{
    $plan = humanCheckPlanFor($choice, $keys);
    if ($plan['state'] !== 'on') {
        return null;
    }

    return $plan['providers'][0];
}

/**
 * 🔑 Does this provider have both keys, readable? Keys made only of spaces do not count. PURE.
 *
 * @param array<string, array{siteKey: string, secret: string, unreadable?: bool}> $keys
 * @param string $provider
 * @return bool
 */
function humanCheckKeysPresent(#[\SensitiveParameter] array $keys, string $provider): bool
{
    if (!isset($keys[$provider]['siteKey'], $keys[$provider]['secret'])) {
        return false;
    }

    if (isset($keys[$provider]['unreadable']) && $keys[$provider]['unreadable'] === true) {
        return false;
    }

    return trim((string) $keys[$provider]['siteKey']) !== '' && trim((string) $keys[$provider]['secret']) !== '';
}

/**
 * 🗺️ The plan for this request: is the check off, on (with which providers,
 *    in order) or broken (keys saved but unreadable - review M2)? PURE.
 *
 * @param string $choice The bot_protection_provider setting
 * @param array<string, array{siteKey: string, secret: string, unreadable?: bool}> $keys
 * @return array{state: string, providers: array<int, string>, unreadable: array<int, string>}
 *         state: off | on | broken
 */
function humanCheckPlanFor(string $choice, #[\SensitiveParameter] array $keys): array
{
    $plan = ['state' => 'off', 'providers' => [], 'unreadable' => []];

    if ($choice === 'none') {
        return $plan;
    }

    $providers = humanCheckProviders();

    // 🔐 Which providers have a saved key that cannot be read back.
    foreach (array_keys($providers) as $provider) {
        if (isset($keys[$provider]['unreadable']) && $keys[$provider]['unreadable'] === true) {
            $plan['unreadable'][] = $provider;
        }
    }

    if (isset($providers[$choice])) {
        // 🎯 One provider chosen: only that one, never another.
        if (in_array($choice, $plan['unreadable'], true)) {
            $plan['state'] = 'broken';
            return $plan;
        }
        if (humanCheckKeysPresent($keys, $choice)) {
            $plan['state']     = 'on';
            $plan['providers'] = [$choice];
        }
        return $plan;
    }

    // 🔁 Automatic (or an unknown stored value): every usable provider, in order.
    foreach (array_keys($providers) as $provider) {
        if (humanCheckKeysPresent($keys, $provider)) {
            $plan['providers'][] = $provider;
        }
    }

    if ($plan['providers'] !== []) {
        $plan['state'] = 'on';
    } elseif ($plan['unreadable'] !== []) {
        $plan['state'] = 'broken';
    }

    return $plan;
}

/**
 * 🗺️ The plan from a full settings array (humanCheckReadSettings()). PURE.
 *
 * @param array $settings
 * @return array{state: string, providers: array<int, string>, unreadable: array<int, string>}
 */
function humanCheckPlan(#[\SensitiveParameter] array $settings): array
{
    return humanCheckPlanFor((string) $settings['choice'], $settings['keys']);
}

/**
 * 🚦 The two outage rules a Global Admin may choose (decision 0037). PURE.
 *
 * @return array<string, string> Value => plain description
 */
function humanCheckOutageModes(): array
{
    return [
        'signin_only' => 'Let only sign-in through, and record it in the activity log (decision 0037, recommended)',
        'refuse'      => 'Refuse every form, including sign-in, until a provider can be reached',
    ];
}

/**
 * 📏 The limits for the per-visitor rate limit on the four web forms. PURE.
 *
 * @return array{min: int, max: int, default: int}
 */
function humanCheckFormLimitBounds(): array
{
    return ['min' => 1, 'max' => 1000, 'default' => 30];
}

// ==============================================================================
// ⚙️ Settings
// ==============================================================================

/**
 * ⚙️ Read every human-check setting.
 *
 * Review M2: a key is read with a stand-in default (CUERCODE_HUMAN_CHECK_UNSET).
 * getConfig() hands the default back when a row is missing, when the database
 * read fails, AND when an encrypted value cannot be decrypted. So when the
 * stand-in comes back, the stored row is asked about directly (without
 * decrypting it): a row that holds something, or a question that fails,
 * means "saved but cannot be read" - never "not entered".
 *
 * @param callable|null $configGetter function (string $key, mixed $default): mixed.
 *                                    Null means the site's getConfig().
 * @param callable|null $storedState  function (string $key): string - missing | empty | present | error.
 *                                    Null means configStoredValueState() (fn_config.php).
 * @return array{choice: string, keys: array<string, array{siteKey: string, secret: string, unreadable: bool}>, whenUnreachable: string, timeout: int, formIpPerHour: int, siteUrl: string}
 */
function humanCheckReadSettings(?callable $configGetter = null, ?callable $storedState = null): array
{
    if ($configGetter === null) {
        $configGetter = 'humanCheckSiteConfig';
    }
    if ($storedState === null) {
        $storedState = 'humanCheckSiteStoredState';
    }

    $choice = strtolower(trim(humanCheckText($configGetter('bot_protection_provider', 'auto'))));

    $keys = [];
    foreach (humanCheckProviders() as $provider => $facts) {
        $siteKey = humanCheckReadKey($configGetter, $storedState, $facts['siteKeySetting']);
        $secret  = humanCheckReadKey($configGetter, $storedState, $facts['secretSetting']);
        $keys[$provider] = [
            'siteKey'    => $siteKey['value'],
            'secret'     => $secret['value'],
            'unreadable' => ($siteKey['unreadable'] || $secret['unreadable']),
        ];
    }

    // 🚦 Decision 0037 is the default; "refuse" is the only other choice. The
    //    old "allow" (let every form through) is no longer offered.
    $whenUnreachable = 'signin_only';
    if (strtolower(trim(humanCheckText($configGetter('bot_protection_when_unreachable', 'signin_only')))) === 'refuse') {
        $whenUnreachable = 'refuse';
    }

    // ⏱️ A whole number of seconds within the bounds, otherwise the default.
    $timeout    = CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT;
    $timeoutRaw = trim(humanCheckText($configGetter('bot_protection_timeout_seconds', (string) CUERCODE_HUMAN_CHECK_DEFAULT_TIMEOUT)));
    if (preg_match('/^[0-9]{1,3}$/', $timeoutRaw) === 1) {
        $timeoutNumber = (int) $timeoutRaw;
        if ($timeoutNumber >= CUERCODE_HUMAN_CHECK_MIN_TIMEOUT && $timeoutNumber <= CUERCODE_HUMAN_CHECK_MAX_TIMEOUT) {
            $timeout = $timeoutNumber;
        }
    }

    // ⏱️ The per-visitor limit on the four web forms (kept within range).
    $bounds       = humanCheckFormLimitBounds();
    $formLimit    = $bounds['default'];
    $formLimitRaw = trim(humanCheckText($configGetter('human_check_form_ip_per_hour', (string) $bounds['default'])));
    if (preg_match('/^[0-9]{1,9}$/', $formLimitRaw) === 1) {
        $formLimit = cuercodeClampInt((int) $formLimitRaw, $bounds['min'], $bounds['max']);
    }

    return [
        'choice'          => $choice,
        'keys'            => $keys,
        'whenUnreachable' => $whenUnreachable,
        'timeout'         => $timeout,
        'formIpPerHour'   => $formLimit,
        'siteUrl'         => trim(humanCheckText($configGetter('site_url', ''))),
    ];
}

/**
 * 🔑 Read ONE key setting, telling "not entered" apart from "cannot be read".
 *
 * @param callable $configGetter
 * @param callable $storedState
 * @param string   $settingKey
 * @return array{value: string, unreadable: bool}
 */
function humanCheckReadKey(callable $configGetter, callable $storedState, string $settingKey): array
{
    $raw = $configGetter($settingKey, CUERCODE_HUMAN_CHECK_UNSET);

    if ($raw !== CUERCODE_HUMAN_CHECK_UNSET) {
        return ['value' => trim(humanCheckText($raw)), 'unreadable' => false];
    }

    // 🔍 The reader gave its default back: ask about the stored row itself.
    try {
        $state = (string) $storedState($settingKey);
    } catch (\Throwable $e) {
        $state = 'error';
    }

    if ($state === 'present' || $state === 'error') {
        return ['value' => '', 'unreadable' => true];
    }

    return ['value' => '', 'unreadable' => false];
}

/**
 * 🧵 Any setting value as text ('' for anything that is not a plain value). PURE.
 *
 * @param mixed $value
 * @return string
 */
function humanCheckText(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_bool($value)) {
        if ($value) {
            return '1';
        }
        return '0';
    }

    return '';
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
 * 🔍 The site's own "is anything stored in this row?" question (review M2).
 *
 * @param string $key
 * @return string missing | empty | present | error
 */
function humanCheckSiteStoredState(string $key): string
{
    if (!function_exists('configStoredValueState')) {
        return 'missing';
    }

    return configStoredValueState($key);
}

/**
 * 🧾 Everything a page or the check needs about ONE configured provider.
 *
 * @param array  $settings From humanCheckReadSettings()
 * @param string $provider
 * @return array{provider: string, name: string, siteKey: string, secret: string, isTestKey: bool, siteKeyIsTest: bool, scriptUrl: string, formField: string, verifyUrl: string, privacyUrl: string}
 */
function humanCheckProviderFacts(#[\SensitiveParameter] array $settings, string $provider): array
{
    $facts   = humanCheckProviders()[$provider];
    $secret  = $settings['keys'][$provider]['secret'];
    $siteKey = $settings['keys'][$provider]['siteKey'];

    return [
        'provider'      => $provider,
        'name'          => $facts['name'],
        'siteKey'       => $siteKey,
        'secret'        => $secret,
        'isTestKey'     => in_array($secret, $facts['testSecrets'], true),
        'siteKeyIsTest' => in_array($siteKey, $facts['testSiteKeys'], true),
        'scriptUrl'     => $facts['scriptUrl'],
        'formField'     => $facts['formField'],
        'verifyUrl'     => $facts['verifyUrl'],
        'privacyUrl'    => $facts['privacyUrl'],
    ];
}

/**
 * 🟢 The FIRST provider in use now, with what a page needs - or null when off
 *    (or broken).
 *
 * @param array|null $settings From humanCheckReadSettings(); null reads the site's settings
 * @return array{provider: string, name: string, siteKey: string, secret: string, isTestKey: bool, siteKeyIsTest: bool, scriptUrl: string, formField: string, verifyUrl: string, privacyUrl: string}|null
 */
function humanCheckActive(#[\SensitiveParameter] ?array $settings = null): ?array
{
    if ($settings === null) {
        $settings = humanCheckReadSettings();
    }

    $plan = humanCheckPlan($settings);
    if ($plan['state'] !== 'on') {
        return null;
    }

    return humanCheckProviderFacts($settings, $plan['providers'][0]);
}

/**
 * 🔏 The providers in use, in order, with only what the privacy page needs
 *    (never a key). Empty while the check is off.
 *
 * @param array|null $settings
 * @return array<int, array{name: string, company: string, privacyUrl: string}>
 */
function humanCheckProvidersInUse(#[\SensitiveParameter] ?array $settings = null): array
{
    if ($settings === null) {
        $settings = humanCheckReadSettings();
    }

    $inUse = [];
    foreach (humanCheckPlan($settings)['providers'] as $provider) {
        $facts   = humanCheckProviders()[$provider];
        $inUse[] = ['name' => $facts['name'], 'company' => $facts['company'], 'privacyUrl' => $facts['privacyUrl']];
    }

    return $inUse;
}

/**
 * 🟢 Is the check on (or broken, which also refuses)? False only when off.
 *
 * @param array|null $settings
 * @return bool
 */
function humanCheckIsOn(#[\SensitiveParameter] ?array $settings = null): bool
{
    if ($settings === null) {
        $settings = humanCheckReadSettings();
    }

    return humanCheckPlan($settings)['state'] !== 'off';
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
 * 🌍 The product's own domains (primary, secondary, tertiary - see
 *    .claude/CLAUDE.md "Project Overview"). PURE.
 *
 * @return array<int, string>
 */
function humanCheckProductDomains(): array
{
    return ['cuercode.net', 'cuercode.me', 'qrmy.link'];
}

/**
 * 🏠 The host names a token may have been made on (review L2): the website
 *    address setting's own host (each copy of the site - live, alpha, beta -
 *    has its own), the product's domains, and each with and without "www.".
 *    NEVER the Host header the visitor's request sent. PURE.
 *
 * @param string $siteUrl The site_url setting
 * @return array<int, string>
 */
function humanCheckAllowedHosts(string $siteUrl): array
{
    $bases = humanCheckProductDomains();

    $siteHost = parse_url($siteUrl, PHP_URL_HOST);
    if (is_string($siteHost)) {
        $siteHost = humanCheckNormaliseHost($siteHost);
        if ($siteHost !== '') {
            array_unshift($bases, $siteHost);
        }
    }

    $hosts = [];
    foreach ($bases as $base) {
        if (str_starts_with($base, 'www.')) {
            $base = substr($base, 4);
        }
        foreach ([$base, 'www.' . $base] as $host) {
            if (!in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
    }

    return $hosts;
}

// ==============================================================================
// 🧠 PURE: the token and the provider's reply
// ==============================================================================

/**
 * 🧾 Which configured provider's token is in this form or JSON body? PURE.
 *
 * JSON routes: "human_check_token", with "human_check_provider" naming the
 * provider (the first configured one when it is left out). Normal forms: the
 * provider's own field, looked for only among the CONFIGURED providers, in
 * order. A visitor can never make the check use a provider that is not set
 * up (decision 0037): naming one gives 'refused'.
 *
 * @param array<string, mixed> $input     $_POST or the parsed JSON body
 * @param array<int, string>   $providers Configured providers, in order
 * @return array{provider: string, token: string, refused: bool}
 */
function humanCheckFindToken(#[\SensitiveParameter] array $input, array $providers): array
{
    $none = ['provider' => '', 'token' => '', 'refused' => false];

    if ($providers === []) {
        return $none;
    }

    // 🧾 The JSON field first.
    if (isset($input[CUERCODE_HUMAN_CHECK_JSON_FIELD]) && is_string($input[CUERCODE_HUMAN_CHECK_JSON_FIELD])) {
        $token = trim($input[CUERCODE_HUMAN_CHECK_JSON_FIELD]);
        if ($token !== '') {
            $named = '';
            if (array_key_exists(CUERCODE_HUMAN_CHECK_PROVIDER_FIELD, $input)) {
                $named = $input[CUERCODE_HUMAN_CHECK_PROVIDER_FIELD];
            }
            // 📌 Not named (or named as empty text): the first configured provider.
            if (is_string($named) && trim($named) === '') {
                return ['provider' => $providers[0], 'token' => $token, 'refused' => false];
            }
            if (is_string($named) && in_array(strtolower(trim($named)), $providers, true)) {
                return ['provider' => strtolower(trim($named)), 'token' => $token, 'refused' => false];
            }
            // 🚫 A provider that is not set up (or not text at all).
            return ['provider' => '', 'token' => '', 'refused' => true];
        }
    }

    // 🧾 Then each configured provider's own form field, in order.
    $all = humanCheckProviders();
    foreach ($providers as $provider) {
        $field = $all[$provider]['formField'];
        if (isset($input[$field]) && is_string($input[$field])) {
            $token = trim($input[$field]);
            if ($token !== '') {
                return ['provider' => $provider, 'token' => $token, 'refused' => false];
            }
        }
    }

    return $none;
}

/**
 * 🧾 What the provider's reply means. PURE.
 *
 * Outcomes: passed | failed | expired_or_reused | wrong_host | misconfigured | unavailable
 *
 * The three providers share one reply shape: {"success": true|false,
 * "hostname": "...", "error-codes": [...]}. Review L1: when the reply HAS
 * that shape it is followed whatever the HTTP status code (a "400" with
 * {"success": false} is a failed check, not an unreachable provider). Only a
 * reply of another shape (an error page, no reply) is "unavailable" - and
 * even then the caller asks the server's own test call before believing it.
 *
 * @param int                $httpStatus    The reply's HTTP status (0 = no reply)
 * @param string             $body          The reply text
 * @param array<int, string> $allowedHosts  From humanCheckAllowedHosts() ([] = do not compare)
 * @param bool               $skipHostCheck True for a published test secret
 * @param bool               $siteKeyIsTest True when OUR saved site key is a published test site key (review L5)
 * @return array{outcome: string, errorCodes: array<int, string>}
 */
function humanCheckInterpretReply(int $httpStatus, string $body, array $allowedHosts, bool $skipHostCheck, bool $siteKeyIsTest = false): array
{
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
        if ($skipHostCheck || $allowedHosts === []) {
            return ['outcome' => 'passed', 'errorCodes' => []];
        }

        // 🏠 No host name, or hCaptcha's "not-provided" (its documentation says
        //    it may send that under heavy load), is accepted; any other host
        //    name must be one of the site's own.
        $replyHost = '';
        if (isset($reply['hostname']) && is_string($reply['hostname'])) {
            $replyHost = humanCheckNormaliseHost($reply['hostname']);
        }
        if ($replyHost === '' || $replyHost === 'not-provided' || in_array($replyHost, $allowedHosts, true)) {
            return ['outcome' => 'passed', 'errorCodes' => []];
        }

        return ['outcome' => 'wrong_host', 'errorCodes' => []];
    }

    // 🔧 Our own keys are wrong (secret missing or invalid, or the site key
    //    and secret do not belong together).
    $ourFault = ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch'];
    // 🧪 Review L5: hCaptcha's "not-using-dummy-passcode" means a TEST site
    //    key was used without its test secret. That is our fault only when
    //    OUR saved site key is a test key; otherwise a visitor sent a token
    //    made with hCaptcha's public test key, which is simply a failed check.
    if ($siteKeyIsTest) {
        $ourFault[] = 'not-using-dummy-passcode';
    }
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
 * 🩺 What the server's OWN test call's reply says about the provider
 *    (decision 0037). PURE.
 *
 *   up            - it answered in its documented shape (any answer about our
 *                   dummy token, normally "invalid-input-response")
 *   misconfigured - it says our secret is wrong, or our own server could not
 *                   make a safe request (never counted as "down")
 *   down          - no answer, a server error page (5xx), any other shape
 *                   with a 2xx or 3xx status, or its own "internal-error"
 *
 * A 4xx page that is not the documented shape (for example 429 "too many
 * requests", or 403 from a firewall) counts as UP: the provider answered.
 * A flood of junk tokens sent THROUGH this server could make a provider slow
 * this server down; that must never be able to open the outage rule, so it
 * only ever leads to refusals (the safe direction).
 *
 * @param array{ok: bool, status: int, body: string, fault: string} $reply Normalised (humanCheckNormaliseReply())
 * @return string up | down | misconfigured
 */
function humanCheckProbeOutcome(array $reply): string
{
    if ($reply['ok'] !== true) {
        if ($reply['fault'] === 'ours') {
            return 'misconfigured';
        }
        return 'down';
    }

    $verdict = humanCheckInterpretReply((int) $reply['status'], (string) $reply['body'], [], true, false);

    if ($verdict['outcome'] === 'unavailable') {
        // 🚦 A 4xx page: the provider answered (perhaps slowing us down) - up.
        if (intdiv((int) $reply['status'], 100) === 4 && $verdict['errorCodes'] === []) {
            return 'up';
        }
        return 'down';
    }
    if ($verdict['outcome'] === 'misconfigured') {
        return 'misconfigured';
    }

    return 'up';
}

/**
 * 📦 The result every caller gets, with its plain message. PURE.
 *
 * @param string $reason   off | passed | outage_signin | missing_token | failed | wrong_host | expired_or_reused | unavailable | misconfigured | rate_limited | store_unavailable
 * @param string $provider The provider key ('' when off)
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

    if ($reason === 'off' || $reason === 'passed' || $reason === 'outage_signin') {
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

    if ($reason === 'rate_limited') {
        return array_merge($result, [
            'message'   => 'Too many tries from your connection in the last hour. Please wait a while and try again.',
            'httpCode'  => 429,
            'errorCode' => 'RATE_LIMIT_EXCEEDED',
        ]);
    }

    if ($reason === 'unavailable' || $reason === 'misconfigured' || $reason === 'store_unavailable') {
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
 * 🔧 Is this curl error a lasting fault on OUR server (review L1)? PURE.
 *
 * These mean our own set-up cannot make a safe request (unsupported or
 * malformed address, a missing feature, a certificate or TLS-library
 * problem). They are counted as "our set-up is wrong" (refused), never as
 * "the provider is down", so they can never open the outage rule. A
 * certificate the provider's side gets wrong (60) is treated the same: the
 * safe direction. Timeouts, name lookups and refused connections are not
 * in the list (the provider or the network may really be down).
 *
 * @param int $errno curl_errno()
 * @return bool
 * @see https://curl.se/libcurl/c/libcurl-errors.html
 */
function humanCheckCurlFaultIsOurs(int $errno): bool
{
    return in_array($errno, [1, 2, 3, 4, 43, 48, 53, 54, 58, 59, 60, 64, 66, 77, 82, 83], true);
}

/**
 * 🌐 POST a form to the provider and return what came back. TLS checked, no
 *    redirects, https only (oauthApplyCurlSafety()), never unpacks compressed
 *    replies (#150), a short time limit. Nothing sent is ever logged.
 *
 * @param string               $url     The provider's checking address
 * @param array<string, string> $fields The form fields (secret, response, ...)
 * @param int                  $timeout Seconds
 * @return array{ok: bool, status: int, body: string, error: string, fault: string} fault: 'ours' for a lasting fault on our server
 */
function humanCheckHttpPost(string $url, #[\SensitiveParameter] array $fields, int $timeout): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'the curl extension is not installed', 'fault' => 'ours'];
    }

    $curlHandle = curl_init();
    if ($curlHandle === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init() failed', 'fault' => 'ours'];
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
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'the safe request settings could not be applied', 'fault' => 'ours'];
    }

    $responseBody = curl_exec($curlHandle);
    $curlErrno    = curl_errno($curlHandle);
    $curlError    = curl_error($curlHandle);
    $httpCode     = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    // 🧹 No curl_close($curlHandle): a no-op since PHP 8.0, and PHP 8.5 warns (#117).

    if ($curlErrno !== 0 || $responseBody === false) {
        $fault = '';
        if (humanCheckCurlFaultIsOurs($curlErrno)) {
            $fault = 'ours';
        }
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl error ' . $curlErrno . ': ' . $curlError, 'fault' => $fault];
    }

    return ['ok' => true, 'status' => $httpCode, 'body' => (string) $responseBody, 'error' => '', 'fault' => ''];
}

/**
 * 📦 Any transport's answer in one shape (a stand-in may leave keys out). PURE.
 *
 * @param mixed $reply
 * @return array{ok: bool, status: int, body: string, error: string, fault: string}
 */
function humanCheckNormaliseReply(mixed $reply): array
{
    $normal = ['ok' => false, 'status' => 0, 'body' => '', 'error' => '', 'fault' => ''];

    if (!is_array($reply)) {
        return $normal;
    }

    if (isset($reply['ok']) && $reply['ok'] === true) {
        $normal['ok'] = true;
        if (isset($reply['status'])) {
            $normal['status'] = (int) $reply['status'];
        }
        if (isset($reply['body']) && is_string($reply['body'])) {
            $normal['body'] = $reply['body'];
        }
    }
    if (isset($reply['error']) && is_string($reply['error'])) {
        $normal['error'] = substr($reply['error'], 0, 200);
    }
    if (isset($reply['fault']) && $reply['fault'] === 'ours') {
        $normal['fault'] = 'ours';
    }

    return $normal;
}

/**
 * 📨 The form fields sent to a provider for one token. PURE.
 *
 * @param array  $facts From humanCheckProviderFacts()
 * @param string $token
 * @return array<string, string>
 */
function humanCheckVerifyFields(#[\SensitiveParameter] array $facts, #[\SensitiveParameter] string $token): array
{
    $fields = [
        'secret'   => $facts['secret'],
        'response' => $token,
    ];
    if ($facts['provider'] === 'hcaptcha') {
        // 🪪 hCaptcha can then say whether the token was made for OUR site key.
        $fields['sitekey'] = $facts['siteKey'];
    }

    return $fields;
}

// ==============================================================================
// ✅ THE ONE CALL
// ==============================================================================

/**
 * ✅ THE ONE CALL: does this form or JSON body pass the human check?
 *
 * In order (each step is explained in the file header):
 *   1. off -> ok, nothing sent anywhere; broken (keys unreadable) -> refused.
 *   2. the token and which configured provider made it; none -> refused,
 *      unless this is SIGN-IN and every configured provider is down by the
 *      server's own test (decision 0037, rule "signin_only").
 *   3. too long -> failed; web forms: over the per-visitor limit -> refused
 *      before any call is made.
 *   4. the provider is asked. passed -> the token's hash is recorded (refused
 *      if it was recorded before, or if it cannot be recorded); our set-up
 *      wrong -> refused; an odd reply -> the server's own test decides:
 *      provider up -> failed; down -> refused, or for sign-in with every
 *      provider down -> let through and logged.
 * Never throws.
 *
 * @param array<string, mixed> $input   $_POST or the parsed JSON body
 * @param array<string, mixed> $options 'purpose' (login | register | forgot | resend),
 *                                      'formRateLimit' (true on the four web pages),
 *                                      and for tests and tools only:
 *                                      'configGetter', 'storedState', 'transport',
 *                                      'tokenClaimer', 'health', 'rateLimiter',
 *                                      'outageLogger', 'stateRead', 'stateWrite',
 *                                      'probeLock', 'probeUnlock', 'allowedHosts'
 * @return array{ok: bool, reason: string, provider: string, message: string, httpCode: int, errorCode: string}
 */
function humanCheckVerifyRequest(#[\SensitiveParameter] array $input, array $options = []): array
{
    $configGetter = humanCheckOption($options, 'configGetter', null);
    $storedState  = humanCheckOption($options, 'storedState', null);

    $settings = humanCheckReadSettings($configGetter, $storedState);
    $plan     = humanCheckPlan($settings);

    if ($plan['state'] === 'off') {
        return humanCheckResult('off', '');
    }

    $deps = humanCheckDependencies($settings, $options);

    if ($plan['state'] === 'broken') {
        // 🔐 Review M2: keys saved but unreadable - refused, never "off".
        humanCheckLogBounded($deps, 'log:unreadable', 'error', 'Human check keys are saved but cannot be read (has ENCRYPTION_KEY changed?) - the protected forms sent the normal way are refused (the email-confirmed route still works, with its limits) until the keys are entered again on /admin/settings/ (or the check is switched off: php _includes/cli/human_check.php off)', 'providers: ' . implode(',', $plan['unreadable']));
        return humanCheckResult('misconfigured', $plan['unreadable'][0]);
    }

    $purpose = '';
    if (isset($options['purpose']) && in_array($options['purpose'], ['login', 'register', 'forgot', 'resend'], true)) {
        $purpose = $options['purpose'];
    }

    $found = humanCheckFindToken($input, $plan['providers']);

    if ($found['refused']) {
        return humanCheckResult('failed', '');
    }

    if ($found['token'] === '') {
        // 🚪 Decision 0037: no token at sign-in is accepted ONLY while every
        //    configured provider is down by the server's own test.
        if (humanCheckOutageAllows($purpose, $settings, $plan, $deps)) {
            return humanCheckOutageResult($plan, $deps);
        }
        return humanCheckResult('missing_token', $plan['providers'][0]);
    }

    $provider = $found['provider'];
    $facts    = humanCheckProviderFacts($settings, $provider);

    if (mb_strlen($found['token']) > CUERCODE_HUMAN_CHECK_TOKEN_MAX_LENGTH) {
        return humanCheckResult('failed', $provider);
    }

    // ⏱️ Web forms: the per-visitor limit BEFORE any outbound call.
    if (isset($options['formRateLimit']) && $options['formRateLimit'] === true) {
        $allowed = $deps['rateLimiter']($settings['formIpPerHour']);
        if ($allowed !== true) {
            return humanCheckResult('rate_limited', $provider);
        }
    }

    $tokenHash = hash('sha256', $found['token']);

    // 🩺 Review I4: the server's own test said "down" less than a minute
    //    ago - do not make the visitor wait the full time limit for a call
    //    that will not be answered. Only the server writes that record.
    if (humanCheckFreshlyDown($deps['stateRead'], $provider)) {
        $found = [];
        $reply = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'skipped: the last test said down', 'fault' => ''];
    } else {
        $reply = humanCheckNormaliseReply($deps['transport']($facts['verifyUrl'], humanCheckVerifyFields($facts, $found['token']), $settings['timeout']));
        $found = [];
    }

    if ($reply['ok'] !== true && $reply['fault'] === 'ours') {
        // 🔧 Review L1: a lasting fault on our own server - refused, not "down".
        humanCheckLogBounded($deps, 'log:misconfigured:' . $provider, 'error', 'Human check: this server cannot make a safe request to the provider (curl, certificates or request settings) - forms are refused', 'provider: ' . $provider . ' | ' . $reply['error']);
        return humanCheckResult('misconfigured', $provider);
    }

    $status = 0;
    if ($reply['ok'] === true) {
        $status = $reply['status'];
    }

    $verdict = humanCheckInterpretReply($status, $reply['body'], $deps['allowedHosts'], $facts['isTestKey'], $facts['siteKeyIsTest']);
    $outcome = $verdict['outcome'];

    if ($outcome === 'passed') {
        // ♻️ Review M1: the token's hash is recorded; a second use is refused.
        $claim = $deps['tokenClaimer']($tokenHash, $provider);
        if ($claim === 'claimed') {
            return humanCheckResult('passed', $provider);
        }
        if ($claim === 'reused') {
            return humanCheckResult('expired_or_reused', $provider);
        }
        humanCheckLogBounded($deps, 'log:token_store', 'error', 'Human check: an accepted token could not be recorded, so the form was refused (is database upgrade 0005 applied?)', 'provider: ' . $provider);
        return humanCheckResult('store_unavailable', $provider);
    }

    if ($outcome === 'misconfigured') {
        humanCheckLogBounded($deps, 'log:misconfigured:' . $provider, 'error', 'Human check keys refused by the provider - check them on /admin/settings/ (or switch the check off: php _includes/cli/human_check.php off)', 'provider: ' . $provider . ' | codes: ' . implode(',', $verdict['errorCodes']));
        return humanCheckResult('misconfigured', $provider);
    }

    if ($outcome === 'unavailable') {
        // 🩺 Decision 0037: an odd reply to a VISITOR's token is never believed
        //    on its own. The server's own test call decides.
        $health = $deps['health']($provider, CUERCODE_HUMAN_CHECK_HEALTH_RECHECK_SECONDS);
        if ($health === 'up') {
            return humanCheckResult('failed', $provider);
        }
        if ($health === 'misconfigured') {
            return humanCheckResult('misconfigured', $provider);
        }
        if (humanCheckOutageAllows($purpose, $settings, $plan, $deps)) {
            return humanCheckOutageResult($plan, $deps);
        }
        return humanCheckResult('unavailable', $provider);
    }

    return humanCheckResult($outcome, $provider);
}

/**
 * 🩺 Did the server's own test call say "down" for this provider less than
 *    CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS ago (review I4)? Reads the
 *    record only; never makes a call. Any problem reading it means "no".
 *
 * @param callable $stateRead (string $key): ?array{value: string, ageSeconds: int}
 * @param string   $provider
 * @return bool
 */
function humanCheckFreshlyDown(callable $stateRead, string $provider): bool
{
    try {
        $record = $stateRead('health:' . $provider);
    } catch (\Throwable $e) {
        return false;
    }

    return is_array($record)
        && isset($record['value'], $record['ageSeconds'])
        && $record['value'] === 'down'
        && (int) $record['ageSeconds'] <= CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS;
}

/**
 * 🔧 One option, or the given default when it is missing or not callable. PURE.
 *
 * @param array<string, mixed> $options
 * @param string               $name
 * @param callable|null        $default
 * @return callable|null
 */
function humanCheckOption(array $options, string $name, ?callable $default): ?callable
{
    if (isset($options[$name]) && is_callable($options[$name])) {
        return $options[$name];
    }

    return $default;
}

/**
 * 🔧 Everything the one call uses from outside: the site's own (database,
 *    curl, request log) unless a test or tool hands in a stand-in.
 *
 * @param array                $settings
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function humanCheckDependencies(#[\SensitiveParameter] array $settings, array $options): array
{
    $deps = [
        'transport'    => humanCheckOption($options, 'transport', 'humanCheckHttpPost'),
        'tokenClaimer' => humanCheckOption($options, 'tokenClaimer', 'humanCheckClaimToken'),
        'rateLimiter'  => humanCheckOption($options, 'rateLimiter', 'humanCheckFormRateAllowed'),
        'stateRead'    => humanCheckOption($options, 'stateRead', 'humanCheckStateRead'),
        'stateWrite'   => humanCheckOption($options, 'stateWrite', 'humanCheckStateWrite'),
        'probeLock'    => humanCheckOption($options, 'probeLock', 'humanCheckProbeLock'),
        'probeUnlock'  => humanCheckOption($options, 'probeUnlock', 'humanCheckProbeUnlock'),
        'logDue'       => humanCheckOption($options, 'logDue', 'humanCheckStateLogDue'),
    ];

    if (isset($options['allowedHosts']) && is_array($options['allowedHosts'])) {
        $deps['allowedHosts'] = array_values(array_filter(array_map('humanCheckNormaliseHost', array_filter($options['allowedHosts'], 'is_string'))));
    } else {
        $deps['allowedHosts'] = humanCheckAllowedHosts($settings['siteUrl']);
    }

    $outageLogger = humanCheckOption($options, 'outageLogger', null);
    if ($outageLogger === null) {
        $logDue       = $deps['logDue'];
        $outageLogger = static function (string $event, array $details) use ($logDue): void {
            humanCheckRecordOutage($event, $details, ['logDue' => $logDue]);
        };
    }
    $deps['outageLogger'] = $outageLogger;

    $health = humanCheckOption($options, 'health', null);
    if ($health === null) {
        $healthDeps = [
            'settings'    => $settings,
            'timeout'     => $settings['timeout'],
            'transport'   => $deps['transport'],
            'stateRead'   => $deps['stateRead'],
            'stateWrite'  => $deps['stateWrite'],
            'probeLock'   => $deps['probeLock'],
            'probeUnlock' => $deps['probeUnlock'],
        ];
        $health = static function (string $provider, int $maxAgeSeconds) use ($healthDeps): string {
            $healthDeps['active'] = humanCheckProviderFacts($healthDeps['settings'], $provider);
            return humanCheckProviderHealth($provider, $maxAgeSeconds, $healthDeps);
        };
    }
    $deps['health'] = $health;

    return $deps;
}

// ==============================================================================
// 🚪 Decision 0037: the outage rule
// ==============================================================================

/**
 * 🚪 May this request go through without a passed check because EVERY
 *    configured provider is down? Only for sign-in, only with the rule
 *    "signin_only", and only when the server's own test says every
 *    configured provider is down (nothing the visitor sent is used).
 *
 * @param string $purpose
 * @param array  $settings
 * @param array  $plan
 * @param array  $deps
 * @return bool
 */
function humanCheckOutageAllows(string $purpose, #[\SensitiveParameter] array $settings, array $plan, array $deps): bool
{
    if ($purpose !== 'login' || $settings['whenUnreachable'] !== 'signin_only') {
        return false;
    }

    return humanCheckAllProvidersDown($plan['providers'], $deps['health']);
}

/**
 * 🩺 Is every configured provider down (by the server's own test, at most a
 *    minute old)? Stops at the first one that is not. An empty list is
 *    never "all down".
 *
 * @param array<int, string> $providers
 * @param callable           $health (string $provider, int $maxAgeSeconds): string
 * @return bool
 */
function humanCheckAllProvidersDown(array $providers, callable $health): bool
{
    if ($providers === []) {
        return false;
    }

    foreach ($providers as $provider) {
        if ($health($provider, CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS) !== 'down') {
            return false;
        }
    }

    return true;
}

/**
 * 🚪 The "let through during an outage" result, written to the activity log.
 *
 * @param array $plan
 * @param array $deps
 * @return array
 */
function humanCheckOutageResult(array $plan, array $deps): array
{
    $deps['outageLogger']('attempt', ['providers' => implode(',', $plan['providers'])]);

    return humanCheckResult('outage_signin', $plan['providers'][0]);
}

/**
 * 📝 Write the outage to the internal activity log, a bounded number of
 *    times (decision 0037: "flag this in our internal activity log").
 *
 *   'attempt' - a sign-in let through without the check: at most ONE row a
 *               minute for the whole site (a flood cannot fill the log).
 *   'success' - that sign-in worked: at most one row per account an hour,
 *               naming only the account number.
 *
 * @param string               $event   attempt | success
 * @param array<string, mixed> $details providers (attempt) or userID (success)
 * @param array<string, mixed> $deps    'logDue', 'activityLog', 'recentlyLogged' (stand-ins in tests)
 * @return void
 */
function humanCheckRecordOutage(string $event, array $details, array $deps = []): void
{
    $logDue = humanCheckOption($deps, 'logDue', 'humanCheckStateLogDue');

    $activityLog = humanCheckOption($deps, 'activityLog', null);
    if ($activityLog === null) {
        $activityLog = static function (string $type, string $detail, ?string $entityType, ?int $entityID, array $extra): void {
            if (function_exists('logActivity')) {
                logActivity($type, $detail, $entityType, $entityID, $extra);
            }
        };
    }

    if ($event === 'attempt') {
        if ($logDue('log:outage_signin', CUERCODE_HUMAN_CHECK_OUTAGE_LOG_SECONDS) === true) {
            $providers = '';
            if (isset($details['providers']) && is_string($details['providers'])) {
                $providers = $details['providers'];
            }
            $activityLog(
                'human_check_outage_signin',
                'Sign-in let through without the human check: every configured provider could not be reached (decision 0037). At most one such row a minute.',
                null,
                null,
                ['providers' => $providers]
            );
        }
        return;
    }

    if ($event === 'success' && isset($details['userID']) && is_int($details['userID']) && $details['userID'] > 0) {
        $recentlyLogged = humanCheckOption($deps, 'recentlyLogged', null);
        if ($recentlyLogged === null) {
            if (!function_exists('limitedRowWrittenRecently')) {
                return;
            }
            $recentlyLogged = 'limitedRowWrittenRecently';
        }
        if ($recentlyLogged($details['userID'], 'human_check_outage_signin_success') !== true) {
            $activityLog(
                'human_check_outage_signin_success',
                'Signed in without the human check while every provider could not be reached (decision 0037). At most one such row per account an hour.',
                'user',
                $details['userID'],
                []
            );
        }
    }
}

/**
 * 📝 After a sign-in attempt: if it was let through by the outage rule and
 *    it worked, note the account (bounded) in the activity log.
 *
 * @param array $check       The humanCheckVerifyRequest() result
 * @param array $loginResult loginUser()'s result (carries 'userID' on success)
 * @return void
 */
function humanCheckNoteOutageSignIn(array $check, array $loginResult): void
{
    if (!isset($check['reason']) || $check['reason'] !== 'outage_signin') {
        return;
    }
    if (empty($loginResult['success']) || !isset($loginResult['userID'])) {
        return;
    }

    humanCheckRecordOutage('success', ['userID' => (int) $loginResult['userID']]);
}

// ==============================================================================
// 🩺 The server's own test call, remembered for a short while (0037)
// ==============================================================================

/**
 * 🩺 Is this provider up, down, or refusing our keys - by the SERVER'S OWN
 *    test call (a fixed dummy token, nothing from any visitor)?
 *
 * A remembered answer no older than $maxAgeSeconds is used as it is. Otherwise
 * ONE request tests the provider (a database lock that is never waited for)
 * and remembers the answer; any request that cannot have the lock, or a site
 * that has nowhere to remember the answer (upgrade 0005 not applied), counts
 * the provider as "up" - the safe direction, which can only refuse, never
 * let anyone through. So the number of test calls is bounded by the
 * remembering time, whatever visitors send. A change of state is logged
 * (once, by the request that saw it change).
 *
 * @param string               $provider
 * @param int                  $maxAgeSeconds
 * @param array<string, mixed> $deps 'active' (humanCheckProviderFacts()), 'timeout',
 *                                   'transport', 'stateRead', 'stateWrite',
 *                                   'probeLock', 'probeUnlock'
 * @return string up | down | misconfigured
 */
function humanCheckProviderHealth(string $provider, int $maxAgeSeconds, #[\SensitiveParameter] array $deps): string
{
    $key    = 'health:' . $provider;
    $states = ['up', 'down', 'misconfigured'];

    $cached = $deps['stateRead']($key);
    if (is_array($cached) && in_array($cached['value'], $states, true) && (int) $cached['ageSeconds'] <= $maxAgeSeconds) {
        return $cached['value'];
    }

    if ($deps['probeLock']($provider) !== true) {
        return 'up';
    }

    try {
        // 🔁 Another request may have just tested it.
        $cached = $deps['stateRead']($key);
        if (is_array($cached) && in_array($cached['value'], $states, true) && (int) $cached['ageSeconds'] <= $maxAgeSeconds) {
            return $cached['value'];
        }

        if (!isset($deps['active']) || !is_array($deps['active'])) {
            return 'up';
        }

        $reply   = humanCheckNormaliseReply($deps['transport']($deps['active']['verifyUrl'], humanCheckVerifyFields($deps['active'], CUERCODE_HUMAN_CHECK_PROBE_RESPONSE), (int) $deps['timeout']));
        $outcome = humanCheckProbeOutcome($reply);

        if ($deps['stateWrite']($key, $outcome) !== true) {
            // 🗄️ Nowhere to remember it: never count a provider as down.
            return 'up';
        }

        $before = 'up';
        if (is_array($cached) && in_array($cached['value'], $states, true)) {
            $before = $cached['value'];
        }
        if ($before !== $outcome) {
            $severity = 'warning';
            if ($outcome === 'misconfigured') {
                $severity = 'error';
            }
            humanCheckLog($severity, 'Human check provider test: ' . $provider . ' is now ' . $outcome, 'before: ' . $before . ' | HTTP ' . $reply['status'] . ' | ' . $reply['error']);
        }

        return $outcome;
    } finally {
        $deps['probeUnlock']($provider);
    }
}

/**
 * 🩺 The remembered test result only (no test call) - for drawing the page.
 *
 * @param string $provider
 * @return string up | down | misconfigured (up when nothing is remembered)
 */
function humanCheckCachedHealth(string $provider): string
{
    $cached = humanCheckStateRead('health:' . $provider);
    if (is_array($cached) && in_array($cached['value'], ['down', 'misconfigured'], true) && (int) $cached['ageSeconds'] <= CUERCODE_HUMAN_CHECK_HEALTH_FRESH_SECONDS * 5) {
        return $cached['value'];
    }

    return 'up';
}

// ==============================================================================
// 🗄️ The small database records (migration 0005)
// ==============================================================================

/**
 * 🗄️ Does tblHumanCheckState exist (migration 0005)? Asked once per request.
 *    If the question fails the answer is "no", which only ever means "never
 *    count a provider as down".
 *
 * @param bool $forget Forget the remembered answer (tests and tools)
 * @return bool
 */
function humanCheckStateTableReady(bool $forget = false): bool
{
    static $ready = null;

    if ($forget) {
        $ready = null;
        return false;
    }
    if ($ready !== null) {
        return $ready;
    }
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `found` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            's',
            ['tblHumanCheckState']
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $ready = (is_array($row) && (int) $row['found'] === 1);
    } catch (\Throwable $e) {
        $ready = false;
    }

    return $ready;
}

/**
 * 🗄️ One remembered value and its age in seconds, or null.
 *
 * @param string $key
 * @return array{value: string, ageSeconds: int}|null
 */
function humanCheckStateRead(string $key): ?array
{
    if (!humanCheckStateTableReady()) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `stateValue`, TIMESTAMPDIFF(SECOND, `checkedAt`, NOW()) AS `ageSeconds` FROM `tblHumanCheckState` WHERE `stateKey` = ? LIMIT 1',
            's',
            [$key]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return ['value' => (string) $row['stateValue'], 'ageSeconds' => max(0, (int) $row['ageSeconds'])];
}

/**
 * 🗄️ Remember a value now.
 *
 * @param string $key
 * @param string $value
 * @return bool True when it was saved
 */
function humanCheckStateWrite(string $key, string $value): bool
{
    if (!humanCheckStateTableReady()) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'INSERT INTO `tblHumanCheckState` (`stateKey`, `stateValue`, `checkedAt`) VALUES (?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `stateValue` = ?, `checkedAt` = NOW()',
            'sss',
            [$key, $value, $value]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        return false;
    }

    return true;
}

/**
 * 🗄️ Is a bounded log line due? True at most once per $seconds for this key,
 *    decided by the database in ONE statement (so requests at the same moment
 *    cannot all say yes). Any problem answers "no" (write nothing).
 *
 * @param string $key
 * @param int    $seconds
 * @return bool
 */
function humanCheckStateLogDue(string $key, int $seconds): bool
{
    if (!humanCheckStateTableReady()) {
        return false;
    }

    try {
        // 📌 mysqli reports 1 for a new row, 2 for a changed row and 0 when
        //    the row was left as it was (the last line is still recent).
        $stmt = dbQuery(
            'INSERT INTO `tblHumanCheckState` (`stateKey`, `stateValue`, `checkedAt`, `loggedAt`) VALUES (?, \'log\', NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE `loggedAt` = IF(`loggedAt` IS NULL OR `loggedAt` <= DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), `loggedAt`)',
            'si',
            [$key, $seconds]
        );
        $changed = (int) $stmt->affected_rows;
        $stmt->close();
    } catch (\Throwable $e) {
        return false;
    }

    return $changed > 0;
}

/**
 * 🔐 Take the "testing this provider" lock without waiting (0 seconds).
 *
 * @param string $provider
 * @return bool True when this request may run the test
 */
function humanCheckProbeLock(string $provider): bool
{
    if (!humanCheckStateTableReady()) {
        return false;
    }

    try {
        $stmt = dbQuery('SELECT GET_LOCK(?, 0) AS `lockResult`', 's', [humanCheckProbeLockName($provider)]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return false;
    }

    return is_array($row) && isset($row['lockResult']) && (int) $row['lockResult'] === 1;
}

/**
 * 🔓 Release the "testing this provider" lock.
 *
 * @param string $provider
 * @return void
 */
function humanCheckProbeUnlock(string $provider): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $stmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [humanCheckProbeLockName($provider)]);
        $stmt->close();
    } catch (\Throwable $e) {
        // The lock belongs to this connection and ends with it anyway.
    }
}

/**
 * 🔐 The lock's name: "cuercode_hc:" + 40 hex characters (limit 64). PURE.
 *
 * @param string $provider
 * @return string
 */
function humanCheckProbeLockName(string $provider): string
{
    $databaseName = '';
    if (defined('DB_NAME')) {
        $databaseName = (string) DB_NAME;
    }

    return 'cuercode_hc:' . substr(hash('sha256', $databaseName . '|' . $provider), 0, 40);
}

/**
 * ♻️ Record an accepted token's SHA-256 hash (review M1). The table's
 *    primary key makes "already recorded" exact even for requests at the
 *    same moment. Old rows (past their 10 minutes) are tidied first.
 *
 * @param string $tokenHash 64 hex characters
 * @param string $provider
 * @return string claimed | reused | error
 */
function humanCheckClaimToken(string $tokenHash, string $provider): string
{
    if (!function_exists('dbQuery')) {
        return 'error';
    }

    try {
        $tidy = dbQuery('DELETE FROM `tblHumanCheckTokens` WHERE `expiresAt` < NOW() LIMIT 100', '', []);
        $tidy->close();

        $stmt = dbQuery(
            'INSERT IGNORE INTO `tblHumanCheckTokens` (`tokenHash`, `provider`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            'ssi',
            [$tokenHash, $provider, CUERCODE_HUMAN_CHECK_REPLAY_SECONDS]
        );
        $inserted = (int) $stmt->affected_rows;
        $stmt->close();
    } catch (\Throwable $e) {
        return 'error';
    }

    if ($inserted === 1) {
        return 'claimed';
    }

    return 'reused';
}

/**
 * ⏱️ The four web forms' per-visitor limit, recorded THEN counted under a
 *    lock (the same helper as the emailed-link forms, so requests at the same
 *    moment are counted exactly).
 *
 * @param int $limit Requests allowed per visitor address per hour
 * @return bool True when this request is within the limit
 */
function humanCheckFormRateAllowed(int $limit): bool
{
    if (!function_exists('authRecordThenCountLinkRequest') || !function_exists('getClientIP')) {
        return true;
    }

    // 🚦 The request log (recordAPIRequest()) lives in fn_api_auth.php, which
    //    the ordinary pages do not load on their own (the same as in
    //    requestNewVerificationLinkWork(), fn_auth.php).
    if (!function_exists('recordAPIRequest')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_api_auth.php';
    }

    return authRecordThenCountLinkRequest(getClientIP(), CUERCODE_HUMAN_CHECK_FORM_ENDPOINT) <= $limit;
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

/**
 * 📝 The same line at most once per CUERCODE_HUMAN_CHECK_LOG_INTERVAL_SECONDS
 *    for this key, so a flood of requests cannot fill the error log.
 *
 * @param array  $deps
 * @param string $key
 * @param string $severity
 * @param string $title
 * @param string $detail
 * @return void
 */
function humanCheckLogBounded(array $deps, string $key, string $severity, string $title, string $detail): void
{
    if ($deps['logDue']($key, CUERCODE_HUMAN_CHECK_LOG_INTERVAL_SECONDS) === true) {
        humanCheckLog($severity, $title, $detail);
    }
}

// ==============================================================================
// 🧱 The widget
// ==============================================================================

/**
 * ✉️ Did the visitor press the "confirm by email instead" button (0038)? PURE.
 *
 * @param array<string, mixed> $post $_POST
 * @return bool
 */
function humanCheckEmailRouteChosen(array $post): bool
{
    return isset($post[CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD]) && $post[CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD] === '1';
}

/**
 * ✉️ The wording of the email-route button for each form (0038). PURE.
 *    'button'/'explain' are for the <noscript> part; 'brokenButton'/
 *    'brokenExplain' for everyone while the saved keys cannot be read
 *    (merged review L4), when "without JavaScript" would be wrong.
 *
 * @param string $purpose login | register | forgot | resend
 * @return array{button: string, explain: string, brokenButton: string, brokenExplain: string}|null
 */
function humanCheckEmailRouteWording(string $purpose): ?array
{
    $wording = [
        'login'    => [
            'button'        => 'Sign in by email confirmation instead',
            'explain'       => 'Without JavaScript you can still sign in: we check your password, then email you a link. Open it and press the button on that page to finish signing in.',
            'brokenButton'  => 'Sign in by email confirmation instead',
            'brokenExplain' => 'You can still sign in: we check your password, then email you a link. Open it and press the button on that page to finish signing in.',
        ],
        'register' => [
            'button'        => 'Create my account without JavaScript',
            'explain'       => 'Without JavaScript you can still create an account: it stays waiting until you use the link we email you.',
            'brokenButton'  => 'Create my account by email confirmation',
            'brokenExplain' => 'You can still create an account: it stays waiting until you use the link we email you.',
        ],
        'forgot'   => [
            'button'        => 'Send the reset link without JavaScript',
            'explain'       => 'Without JavaScript we can still email you a reset link.',
            'brokenButton'  => 'Send the reset link by email',
            'brokenExplain' => 'We can still email you a reset link.',
        ],
        'resend'   => [
            'button'        => 'Send the new link without JavaScript',
            'explain'       => 'Without JavaScript we can still email you a new verification link.',
            'brokenButton'  => 'Send the new link by email',
            'brokenExplain' => 'We can still email you a new verification link.',
        ],
    ];

    if (!isset($wording[$purpose])) {
        return null;
    }

    return $wording[$purpose];
}

/**
 * 🧱 The human-check box to put INSIDE a form, or '' when the check is off.
 *
 * Draws only an empty container with the first provider that is not
 * remembered as down (decision 0037), its public site key and script address,
 * and the other configured providers in data-fallbacks (our script moves to
 * the next one if a provider's script will not load, or the server answers
 * "could not be completed"). With JavaScript off the <noscript> part explains
 * why and, when $purpose is given, offers the email-confirmed route (0038).
 *
 * While the saved keys cannot be read ("broken", review M2) no provider is
 * drawn: a plain note says the form cannot be sent the usual way and, when
 * $purpose is given, offers the email-confirmed route to EVERYONE (merged
 * review L4). The pages honour that route in this state too.
 *
 * @param array|null           $settings From humanCheckReadSettings(); null reads the site's settings
 * @param string               $purpose  login | register | forgot | resend ('' = no email route)
 * @param array<string, mixed> $options  'cachedHealth' (callable(provider): string) for tests
 * @return string HTML
 */
function humanCheckWidgetHtml(#[\SensitiveParameter] ?array $settings = null, string $purpose = '', array $options = []): string
{
    if ($settings === null) {
        $settings = humanCheckReadSettings();
    }

    $plan = humanCheckPlan($settings);
    if ($plan['state'] === 'broken') {
        return humanCheckBrokenWidgetHtml($purpose);
    }
    if ($plan['state'] !== 'on') {
        return '';
    }

    $cachedHealth = humanCheckOption($options, 'cachedHealth', 'humanCheckCachedHealth');

    // 🔁 Providers remembered as down go to the end of the list.
    $first = [];
    $last  = [];
    foreach ($plan['providers'] as $provider) {
        if ($cachedHealth($provider) === 'up') {
            $first[] = $provider;
        } else {
            $last[] = $provider;
        }
    }
    $ordered = array_merge($first, $last);

    $primary   = humanCheckProviderFacts($settings, $ordered[0]);
    $fallbacks = [];
    foreach (array_slice($ordered, 1) as $provider) {
        $facts       = humanCheckProviderFacts($settings, $provider);
        $fallbacks[] = ['provider' => $facts['provider'], 'sitekey' => $facts['siteKey'], 'script' => $facts['scriptUrl']];
    }

    $names = [];
    foreach ($ordered as $provider) {
        $names[] = humanCheckProviders()[$provider]['name'];
    }

    $html = '<div class="mb-3">'
        . '<p class="form-label mb-1" id="human-check-label">Human check</p>'
        . '<div class="cuercode-human-check" role="group" aria-labelledby="human-check-label"'
        . ' data-provider="' . humanCheckEscape($primary['provider']) . '"'
        . ' data-sitekey="' . humanCheckEscape($primary['siteKey']) . '"'
        . ' data-script="' . humanCheckEscape($primary['scriptUrl']) . '"';
    if ($fallbacks !== []) {
        $html .= ' data-fallbacks="' . humanCheckEscape((string) json_encode($fallbacks, JSON_UNESCAPED_SLASHES)) . '"';
    }
    $html .= '></div>'
        . '<noscript><div class="alert alert-warning mt-2 mb-0" role="note">'
        . '<p class="mb-0">This form uses a human check from ' . humanCheckEscape(implode(' or ', $names)) . ', which needs JavaScript.</p>';

    $route = humanCheckEmailRouteWording($purpose);
    if ($route !== null) {
        $html .= '<p class="mt-2 mb-2">' . humanCheckEscape($route['explain']) . ' To prevent abuse, this way is limited to a few emails an hour.</p>'
            . '<button type="submit" class="btn btn-outline-secondary w-100" name="' . humanCheckEscape(CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD) . '" value="1">'
            . humanCheckEscape($route['button'])
            . '</button>';
    } else {
        $html .= '<p class="mt-2 mb-0">Please switch JavaScript on in your browser to use this form.</p>';
    }

    $html .= '</div></noscript>'
        . '</div>';

    return $html;
}

/**
 * 🧱 What a form shows while the saved keys cannot be read (merged review
 *    L4): a note, and the email-confirmed route's button for everyone. PURE.
 *
 * @param string $purpose login | register | forgot | resend ('' = no email route)
 * @return string HTML
 */
function humanCheckBrokenWidgetHtml(string $purpose): string
{
    $html = '<div class="mb-3"><div class="alert alert-warning mb-0" role="note">'
        . '<p class="mb-2">The human check is not working just now, so this form cannot be sent the usual way.</p>';

    $route = humanCheckEmailRouteWording($purpose);
    if ($route !== null) {
        $html .= '<p class="mb-2">' . humanCheckEscape($route['brokenExplain']) . ' To prevent abuse, this way is limited to a few emails an hour.</p>'
            . '<button type="submit" class="btn btn-outline-secondary w-100" name="' . humanCheckEscape(CUERCODE_HUMAN_CHECK_EMAIL_ROUTE_FIELD) . '" value="1">'
            . humanCheckEscape($route['brokenButton'])
            . '</button>';
    } else {
        $html .= '<p class="mb-0">Please try again later.</p>';
    }

    return $html . '</div></div>';
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
 * @param array                 $settings From humanCheckReadSettings()
 * @param array<string, string> $health   Provider => remembered test result (optional)
 * @return array<int, string>
 */
function humanCheckStatusLines(#[\SensitiveParameter] array $settings, array $health = []): array
{
    $providers = humanCheckProviders();
    $plan      = humanCheckPlan($settings);
    $lines     = [];

    if ($plan['state'] === 'on') {
        $names = [];
        foreach ($plan['providers'] as $provider) {
            $names[] = $providers[$provider]['name'];
        }
        $lines[] = 'In use now: ' . implode(', then ', $names) . '.';
        foreach ($plan['providers'] as $provider) {
            $facts = humanCheckProviderFacts($settings, $provider);
            if ($facts['isTestKey'] || $facts['siteKeyIsTest']) {
                $lines[] = 'Warning: the saved ' . $facts['name'] . ' key is the provider\'s published test key, which passes or fails every check by design. Never use it on the live site.';
            }
        }
    } elseif ($plan['state'] === 'broken') {
        $lines[] = 'Refusing the protected forms sent the normal way: the saved keys cannot be read (has ENCRYPTION_KEY in auth_creds.php changed, or is a row damaged?). Visitors can still use the email-confirmed route (decision 0038), with its limits. Enter the keys again below, or switch the check off with php _includes/cli/human_check.php off.';
    } elseif ($settings['choice'] === 'none') {
        $lines[] = 'Off: the human check is switched off (provider setting "none").';
    } elseif (isset($providers[$settings['choice']])) {
        $lines[] = 'Off: ' . $providers[$settings['choice']]['name'] . ' is chosen but its site key and secret key are not both entered.';
    } else {
        $lines[] = 'Off: no provider has both its site key and secret key entered.';
    }

    if ($plan['state'] === 'on') {
        foreach ($plan['unreadable'] as $provider) {
            $lines[] = 'Warning: the saved ' . $providers[$provider]['name'] . ' keys cannot be read, so it is not used. Enter them again below.';
        }
    }

    foreach ($health as $provider => $state) {
        if (isset($providers[$provider]) && $state === 'down') {
            $lines[] = $providers[$provider]['name'] . ': the last test could not reach it.';
        } elseif (isset($providers[$provider]) && $state === 'misconfigured') {
            $lines[] = $providers[$provider]['name'] . ': the last test says the keys are wrong (or this server cannot make a safe request).';
        }
    }

    if ($settings['whenUnreachable'] === 'refuse') {
        $lines[] = 'If every provider cannot be reached: every form is refused, including sign-in (and it is logged).';
    } else {
        $lines[] = 'If every provider cannot be reached: only sign-in is let through, without the check, and it is recorded in the activity log (decision 0037). The other forms are refused.';
    }

    return $lines;
}

/**
 * 📋 The remembered test result for every configured provider (admin screen).
 *
 * @param array $settings
 * @return array<string, string>
 */
function humanCheckCachedStates(#[\SensitiveParameter] array $settings): array
{
    $states = [];
    foreach (humanCheckPlan($settings)['providers'] as $provider) {
        $states[$provider] = humanCheckCachedHealth($provider);
    }

    return $states;
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
