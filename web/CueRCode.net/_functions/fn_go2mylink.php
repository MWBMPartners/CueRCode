<?php
/**
 * ==============================================================================
 * 🔗 CueRCode - The connection to Go2My.Link (dynamic QR, WP4b, #165)
 * ==============================================================================
 *
 * Path: _functions/fn_go2mylink.php
 *
 * WHAT THIS IS (plain English):
 *   A dynamic QR code prints a short address such as https://QRmy.Link/Ab3dE9x.
 *   Go2My.Link, the owner's sister link service, answers that address and
 *   sends the person on to the code's destination (decision 0027, plan
 *   .claude/plans/first-launch-plan.md section 0). This file is the ONLY
 *   place CueRCode talks to Go2My.Link's API. It can:
 *     - make a new link for a dynamic code (go2mylinkCreateLink(), and the
 *       safe version go2mylinkCreateLinkSafely());
 *     - change where a link goes (go2mylinkUpdateDestination());
 *     - look a link up (go2mylinkLookupLink()) and read its total number of
 *       scans (go2mylinkScanTotal(), decision 0033: totals only);
 *     - check the connection without changing anything
 *       (go2mylinkTestConnection(), the admin "Test connection" button).
 *   Nothing calls it yet: the create and edit flows are WP4c (#166), and the
 *   feature stays switched off (dynamic_qr_enabled is locked at 0).
 *
 * WHAT GO2MY.LINK EXPECTS (read from its code, not only its documents, on
 * 2026-11-28: branch feat/2026-09-21-linkspage-and-sweep, files
 * web/Go2My.Link/public_html/api/v1/index.php, handlers/urls.php,
 * web/_functions/api_response.php, api_auth.php, shorturl_create.php):
 *   - the key in "Authorization: Bearer g2ml_<8>_<43>";
 *   - "Accept: application/json" (it answers XML to some other Accept values);
 *   - replies {"status":"success","data":...} or
 *     {"status":"error","error":{"code","message","field"}};
 *   - POST /urls with qr_external_id + qr_external_uuid needs the scope
 *     qr:link and its setting cuercode.integration_enabled (403 otherwise);
 *     answers 201 {short_code, short_url, qr_code_external_uuid};
 *     409 field qr_external_uuid = this QR is already linked; 409 field
 *     custom_code = the code is taken; 403 also when its link limit is reached;
 *   - custom_code is honoured only when its setting
 *     cuercode.allow_external_shortcode is on; otherwise it is SILENTLY
 *     ignored and Go2My.Link picks the code (so the reply's code is the one
 *     that counts, and codeKept says whether ours was kept);
 *   - GET /urls/{code} answers the link with click_count (every redirect
 *     adds one, whether or not ?src=qr is on the address); PUT /urls/{code}
 *     changes destination_url, title or tags only (it cannot switch a link
 *     back on);
 *   - 429 with Retry-After (seconds) when the key's limit is reached.
 *
 * SAFE RETRY (lead choice L1): CueRCode chooses each short code itself and
 * stores it BEFORE asking, so a create whose answer was lost (time limit,
 * dropped connection, a 500) can be looked up by that code before it is ever
 * tried again. Go2My.Link's 409 does not say which code an already-linked QR
 * id has, and its look-up does not return the QR id, so without our own code
 * a lost answer could never be found.
 *
 * A LINK COUNTS ONLY ON A CONFIRMED SHORT DOMAIN (review H1, 2026-11-28):
 * Go2My.Link may make a link on another short domain (its fallback g2my.link
 * when the organisation's own domain is not verified), and then the printed
 * QRmy.Link/<code> would not work. Only a reply that carries the short
 * address can show the domain: the create reply does; Go2My.Link's look-up
 * (GET /urls/{code}) does NOT today (asked for on Go2My.Link issue #276). So
 * a link found by a look-up is never "working" until the look-up gives its
 * short address. Until then it is "domain_unconfirmed": the link exists on
 * Go2My.Link (remoteExists), it is never printed, never tried again, and an
 * admin must look. go2mylinkSyncStateFor() turns every result into the one
 * tblQRRedirects.syncState word to store.
 *
 * SAFETY RULES KEPT HERE:
 *   - The API key goes only in the Authorization header. It is never logged,
 *     never put in a message, an address or a body, and never returned.
 *   - Certificates are checked, redirects are never followed and only https
 *     is spoken (oauthApplyCurlSafety(), shared with the sign-in and human
 *     check code). Compressed replies are never asked for (#150).
 *   - Short time limits (2 to 15 seconds, 5 by default; an admin setting).
 *   - A reply is never read past 64 KB.
 *   - Every Go2My.Link answer becomes one of a fixed list of plain CueRCode
 *     reasons (go2mylinkReasonFacts()). Go2My.Link's own wording is never
 *     shown to a member and never logged.
 *
 * House rules: full multi-line PHP (no ??, ternaries, arrow functions or
 * match), #[\SensitiveParameter] on everything that can hold the key. Loading
 * this file defines constants and functions only (the tests rely on that).
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4b, #165; part of #27)
 * @see         .claude/plans/first-launch-plan.md - sections 0.1, 0.6, WP4b, lead choices L1-L5
 * @see         docs/decisions/0030 (alpha uses alpha.QRmy.Link), 0033 (scan totals only)
 * @see         _functions/fn_oauth.php - oauthApplyCurlSafety()
 * @see         tests/test_go2mylink.php - the tests, with a stand-in HTTPS server
 * @see         https://curl.se/libcurl/c/libcurl-errors.html
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $go2mylinkScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $go2mylinkScriptFilename = '';
}
if (basename($go2mylinkScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($go2mylinkScriptFilename);

// ==============================================================================
// 📦 What this file builds on (each is pure on loading)
// ==============================================================================

// 🔒 oauthApplyCurlSafety(): certificates checked, no redirects, https only.
if (!function_exists('oauthApplyCurlSafety')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_oauth.php';
}

// 🔑 humanCheckReadKey() and friends: read a stored secret and tell "never
//    entered" apart from "entered but cannot be decrypted" (review M2 of #163).
if (!function_exists('humanCheckReadKey')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_human_check.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var string Go2My.Link's production API address (decision 0030: alpha sets its own). */
if (!defined('CUERCODE_GO2MYLINK_DEFAULT_API_URL')) {
    define('CUERCODE_GO2MYLINK_DEFAULT_API_URL', 'https://go2my.link/api/v1');
}

/** @var string The short-link address printed in codes when none is set (dynamic_redirect_base_url). */
if (!defined('CUERCODE_GO2MYLINK_DEFAULT_SHORT_LINK_BASE')) {
    define('CUERCODE_GO2MYLINK_DEFAULT_SHORT_LINK_BASE', 'https://QRmy.Link');
}

/** @var int Seconds to wait for Go2My.Link by default, and the bounds an admin may set. */
if (!defined('CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT')) {
    define('CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT', 5);
}
if (!defined('CUERCODE_GO2MYLINK_MIN_TIMEOUT')) {
    define('CUERCODE_GO2MYLINK_MIN_TIMEOUT', 2);
}
if (!defined('CUERCODE_GO2MYLINK_MAX_TIMEOUT')) {
    define('CUERCODE_GO2MYLINK_MAX_TIMEOUT', 15);
}

/** @var int A reply is never read past this many bytes (the largest real one is well under 2 KB). */
if (!defined('CUERCODE_GO2MYLINK_MAX_REPLY_BYTES')) {
    define('CUERCODE_GO2MYLINK_MAX_REPLY_BYTES', 65536);
}

/** @var int Reply headers are never read past this many bytes. */
if (!defined('CUERCODE_GO2MYLINK_MAX_HEADER_BYTES')) {
    define('CUERCODE_GO2MYLINK_MAX_HEADER_BYTES', 16384);
}

/** @var int Characters in a short code CueRCode chooses (lead choice L1). */
if (!defined('CUERCODE_GO2MYLINK_CODE_LENGTH')) {
    define('CUERCODE_GO2MYLINK_CODE_LENGTH', 7);
}

/** @var string The letters and digits a chosen code is drawn from. */
if (!defined('CUERCODE_GO2MYLINK_CODE_ALPHABET')) {
    define('CUERCODE_GO2MYLINK_CODE_ALPHABET', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');
}

/** @var string Go2My.Link's own rule for a chosen code (G2ML_CUSTOM_CODE_PATTERN). */
if (!defined('CUERCODE_GO2MYLINK_CODE_PATTERN')) {
    define('CUERCODE_GO2MYLINK_CODE_PATTERN', '/^[A-Za-z0-9_-]{3,50}$/D');
}

/**
 * @var string The code "Test connection" tries to change: one Go2My.Link
 *             reserves (g2ml_isReservedShortCode()), so no link with it can
 *             ever exist, and its change handler looks the link up (404)
 *             before reading anything it was sent.
 */
if (!defined('CUERCODE_GO2MYLINK_PROBE_CODE')) {
    define('CUERCODE_GO2MYLINK_PROBE_CODE', 'bulk');
}

/** @var string Go2My.Link's change handler's own 404 wording (handlers/urls.php), review L8. */
if (!defined('CUERCODE_GO2MYLINK_NO_SUCH_LINK')) {
    define('CUERCODE_GO2MYLINK_NO_SUCH_LINK', 'No such short URL in this account.');
}

/** @var string Shown to a member whenever the cause is on our side or Go2My.Link's set-up. */
if (!defined('CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE')) {
    define('CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE', 'Dynamic codes are not available just now. Please try again later.');
}

/** @var string Shown to a member when Go2My.Link cannot be reached. */
if (!defined('CUERCODE_GO2MYLINK_MEMBER_UNREACHABLE')) {
    define('CUERCODE_GO2MYLINK_MEMBER_UNREACHABLE', 'The link service could not be reached just now. Please try again in a few minutes.');
}

// ==============================================================================
// 🎲 PURE: short codes (lead choice L1)
// ==============================================================================

/**
 * 🚫 The codes Go2My.Link refuses because they would hide one of its own
 *    pages. A COPY of g2ml_isReservedShortCode() in Go2My.Link's
 *    shorturl_create.php (read 2026-11-28); keep it in step when that list
 *    grows. Lower case; compared without regard to capitals. PURE.
 *
 * @return array<int, string>
 */
function go2mylinkReservedCodes(): array
{
    return [
        'robots', 'robots.txt', 'favicon', 'favicon.ico', 'index', 'sitemap', 'sitemap.xml',
        'validating', 'expired', '404', 'api', 'admin', 'install', 'vendor', 'www', 'bulk',
    ];
}

/**
 * ✅ Would Go2My.Link accept this as a chosen code? Its pattern
 *    ^[A-Za-z0-9_-]{3,50}$ and not a reserved word. PURE.
 *
 * @param string $code
 * @return bool
 */
function go2mylinkCodeIsAllowed(string $code): bool
{
    if (preg_match(CUERCODE_GO2MYLINK_CODE_PATTERN, $code) !== 1) {
        return false;
    }

    return !in_array(strtolower($code), go2mylinkReservedCodes(), true);
}

/**
 * 🎲 Choose a new short code: 7 letters and digits, drawn again if it spells a
 *    reserved word (for example "install" or "sitemap").
 *
 * Go2My.Link compares codes without regard to capitals (its column uses a
 * case-insensitive collation), so "Ab3dE9x" and "AB3DE9X" are the same code
 * there; the 36 ^ 7 (about 78 thousand million) different codes that leaves
 * are plenty, and a clash is answered "code taken" and a new code is drawn.
 *
 * @param callable|null $randomIndex Stand-in for tests: function (int $max): int, 0 to $max; null = random_int()
 * @return string
 * @throws \RuntimeException When no allowed code came out of 20 draws (only possible with a broken random source)
 */
function go2mylinkGenerateShortCode(?callable $randomIndex = null): string
{
    $alphabet = CUERCODE_GO2MYLINK_CODE_ALPHABET;
    $highest  = strlen($alphabet) - 1;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = '';
        for ($position = 0; $position < CUERCODE_GO2MYLINK_CODE_LENGTH; $position++) {
            if ($randomIndex === null) {
                $index = random_int(0, $highest);
            } else {
                $index = (int) $randomIndex($highest);
            }
            if ($index < 0 || $index > $highest) {
                $index = 0;
            }
            $code .= $alphabet[$index];
        }
        if (go2mylinkCodeIsAllowed($code)) {
            return $code;
        }
    }

    throw new \RuntimeException('No usable short code could be drawn.');
}

// ==============================================================================
// ⚙️ Settings
// ==============================================================================

/**
 * 🌐 The API address in its one usable form, or '' when it cannot be used.
 *    https only; a name (and port); no user name or password; no "?" or "#"
 *    part; one trailing slash removed. PURE.
 *
 *    (The admin screen is stricter - a public website name, port 443 only.
 *    This is the last check before a call, and the tests point it at
 *    127.0.0.1 on another port.)
 *
 * @param string $text
 * @return string
 */
function go2mylinkNormaliseApiBaseUrl(string $text): string
{
    $text = trim($text);

    if ($text === '' || preg_match('/[\s\\\\]/', $text) === 1 || stripos($text, 'https://') !== 0) {
        return '';
    }

    $parts = parse_url($text);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https' || $parts['host'] === '') {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    if (str_contains($text, '?') || str_contains($text, '#') || str_contains($text, '@')) {
        return '';
    }

    return rtrim($text, '/');
}

/**
 * 🏷️ The lower-case host name of an address, or '' when it has none. PURE.
 *
 * @param string $url
 * @return string
 */
function go2mylinkHostOf(string $url): string
{
    $host = parse_url(trim($url), PHP_URL_HOST);

    if (!is_string($host)) {
        return '';
    }

    return strtolower($host);
}

/**
 * ⚙️ Read the Go2My.Link settings (Global Admin settings, /admin/settings/).
 *
 *   go2mylink_api_base_url    - the API address (production by default; an
 *                               alpha database sets its own, decision 0030)
 *   go2mylink_api_key         - the key (stored encrypted)
 *   go2mylink_timeout_seconds - 2 to 15, anything else means 5
 *   dynamic_redirect_base_url - the short-link address printed in codes
 *                               (https://QRmy.Link; alpha: https://alpha.QRmy.Link)
 *
 * @param callable|null $configGetter Stand-in for getConfig() (tests); null = the site's
 * @param callable|null $storedState  Stand-in for configStoredValueState() (tests); null = the site's
 * @return array{apiBaseUrl: string, apiKey: string, keyUnreadable: bool, timeout: int, shortLinkBase: string, problem: string}
 *         problem: '' (ready), 'not_configured' or 'key_unreadable'
 */
function go2mylinkReadSettings(?callable $configGetter = null, ?callable $storedState = null): array
{
    if ($configGetter === null) {
        $configGetter = 'humanCheckSiteConfig';
    }
    if ($storedState === null) {
        $storedState = 'humanCheckSiteStoredState';
    }

    $apiBaseUrl = go2mylinkNormaliseApiBaseUrl(humanCheckText($configGetter('go2mylink_api_base_url', CUERCODE_GO2MYLINK_DEFAULT_API_URL)));
    $key        = humanCheckReadKey($configGetter, $storedState, 'go2mylink_api_key');

    // ⏱️ A whole number of seconds within the bounds, otherwise the default.
    $timeout    = CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT;
    $timeoutRaw = trim(humanCheckText($configGetter('go2mylink_timeout_seconds', (string) CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT)));
    if (preg_match('/^[0-9]{1,3}$/D', $timeoutRaw) === 1) {
        $timeoutNumber = (int) $timeoutRaw;
        if ($timeoutNumber >= CUERCODE_GO2MYLINK_MIN_TIMEOUT && $timeoutNumber <= CUERCODE_GO2MYLINK_MAX_TIMEOUT) {
            $timeout = $timeoutNumber;
        }
    }

    $shortLinkBase = rtrim(trim(humanCheckText($configGetter('dynamic_redirect_base_url', CUERCODE_GO2MYLINK_DEFAULT_SHORT_LINK_BASE))), '/');

    $problem = '';
    if ($key['unreadable']) {
        $problem = 'key_unreadable';
    } elseif ($key['value'] === '' || $apiBaseUrl === '' || go2mylinkHostOf($shortLinkBase) === '') {
        $problem = 'not_configured';
    }

    return [
        'apiBaseUrl'    => $apiBaseUrl,
        'apiKey'        => $key['value'],
        'keyUnreadable' => $key['unreadable'],
        'timeout'       => $timeout,
        'shortLinkBase' => $shortLinkBase,
        'problem'       => $problem,
    ];
}

/**
 * ⚙️ The settings a call uses: the ones a test handed in, or the site's own.
 *
 * @param array<string, mixed> $options
 * @return array{apiBaseUrl: string, apiKey: string, keyUnreadable: bool, timeout: int, shortLinkBase: string, problem: string}
 */
function go2mylinkSettingsFromOptions(#[\SensitiveParameter] array $options): array
{
    if (isset($options['settings']) && is_array($options['settings'])) {
        $settings = array_merge(
            ['apiBaseUrl' => '', 'apiKey' => '', 'keyUnreadable' => false, 'timeout' => CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT, 'shortLinkBase' => '', 'problem' => 'not_configured'],
            $options['settings']
        );
    } else {
        $settings = go2mylinkReadSettings();
    }

    // 🛡️ The key goes into a header: only visible ASCII characters, at most
    //    128 (Go2My.Link's own limit), so it can never add a header line.
    if ($settings['problem'] === '' && preg_match('/^[\x21-\x7E]{1,128}$/D', (string) $settings['apiKey']) !== 1) {
        $settings['problem'] = 'not_configured';
    }

    return $settings;
}

// ==============================================================================
// 🧾 PURE: what each reason means
// ==============================================================================

/**
 * 🧾 Every reason a call can fail, with the member's words, an admin's words
 *    and the HTTP status CueRCode should answer with. PURE.
 *
 * @param string $reason
 * @return array{message: string, adminMessage: string, httpCode: int}
 */
function go2mylinkReasonFacts(string $reason): array
{
    $facts = [
        'not_configured'     => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'The Go2My.Link connection is not set up: enter the API address and the API key in the "Dynamic QR codes" section of the settings page.', 503],
        'key_unreadable'     => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'The saved Go2My.Link API key cannot be read (the site\'s encryption key may have changed). Enter the key again.', 503],
        'bad_request'        => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'CueRCode could not make a request Go2My.Link would accept. This is a fault in CueRCode; the error log has the details.', 500],
        'auth_failed'        => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link did not accept the API key (it is wrong, has expired or was switched off). Make a new key on the Go2My.Link dashboard and enter it here.', 503],
        'integration_off'    => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link has its CueRCode connection switched off (its setting cuercode.integration_enabled).', 503],
        'missing_permission' => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'The Go2My.Link API key is missing a permission CueRCode needs (qr:link, urls:read and urls:write).', 503],
        'link_limit'         => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link\'s link limit for the CueRCode organisation has been reached. Raise the organisation\'s limit on Go2My.Link.', 503],
        'forbidden'          => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link refused the request for a reason CueRCode does not recognise (status 403).', 503],
        'not_found'          => ['That dynamic code could not be found on the link service.', 'Go2My.Link has no link with this code in the CueRCode organisation.', 404],
        'code_taken'         => ['That did not work just now. Please try again.', 'Go2My.Link already has a link with this short code, so a new code has to be chosen.', 409],
        'already_linked'     => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link already has a link for this QR code, under a short code CueRCode does not know. An admin needs to look at it before it is tried again.', 409],
        'rejected'           => ['The link service could not accept this code just now. Please check the web address, or try again later.', 'Go2My.Link refused the request (status 422). Its 422 means one of several things it does not tell apart: the destination address failed its check, the code is one it reserves, it could not make a code, or its own database refused the row.', 422],
        'rate_limited'       => ['The link service is busy just now. Please try again in a few minutes.', 'Go2My.Link\'s request limit for this API key has been reached.', 429],
        'unavailable'        => [CUERCODE_GO2MYLINK_MEMBER_UNREACHABLE, 'Go2My.Link answered that it is not working just now.', 503],
        'unreachable'        => [CUERCODE_GO2MYLINK_MEMBER_UNREACHABLE, 'Go2My.Link could not be reached: its address was not found, or it did not accept the connection. Nothing was sent.', 503],
        'timeout'            => [CUERCODE_GO2MYLINK_MEMBER_UNREACHABLE, 'Go2My.Link did not answer in time, or the connection dropped part-way.', 503],
        'tls_failed'         => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link\'s security certificate could not be checked, so nothing was sent. Check the certificate on the API address.', 503],
        'bad_reply'          => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link gave an answer CueRCode did not expect or could not read (not the usual shape, broken, too large, or a redirect).', 502],
        'wrong_short_domain' => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link made the link on a different short domain from the one this site prints in codes. The link exists on Go2My.Link, but it is not printed and is not tried again. Make the short-link address on the settings page the CueRCode organisation\'s default short domain on Go2My.Link (and verify that domain there), then deal with this code by hand.', 503],
        'domain_unconfirmed' => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link has a link with this code, but its look-up does not say which short domain the link is on, so CueRCode cannot confirm that the printed address would work. The code is held: it is not printed and is not tried again. Check the link on the Go2My.Link dashboard. (Go2My.Link has been asked to include the short address in its look-up: Go2My.Link issue #276.)', 503],
        'link_switched_off'  => [CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE, 'Go2My.Link has a link with this code, but it is switched off there, so it is not printed. CueRCode cannot switch a link back on; look at it on the Go2My.Link dashboard.', 409],
    ];

    if (!isset($facts[$reason])) {
        $reason = 'bad_reply';
    }

    return [
        'message'      => $facts[$reason][0],
        'adminMessage' => $facts[$reason][1],
        'httpCode'     => $facts[$reason][2],
    ];
}

/**
 * ❌ A failed result in the one shape every call returns. PURE.
 *
 * @param string               $reason         One of go2mylinkReasonFacts()'s keys
 * @param bool                 $outcomeUnknown True when Go2My.Link may have done what was asked
 * @param array<string, mixed> $extra          httpStatus, retryAfter, field, data, remoteExists
 *                                             (true = Go2My.Link is KNOWN to hold a link for this code or QR id)
 * @return array{ok: bool, reason: string, message: string, adminMessage: string, httpCode: int, httpStatus: int, retryAfter: int, field: string, outcomeUnknown: bool, remoteExists: bool, data: array}
 */
function go2mylinkFailure(string $reason, bool $outcomeUnknown, array $extra = []): array
{
    $facts = go2mylinkReasonFacts($reason);

    $result = [
        'ok'             => false,
        'reason'         => $reason,
        'message'        => $facts['message'],
        'adminMessage'   => $facts['adminMessage'],
        'httpCode'       => $facts['httpCode'],
        'httpStatus'     => 0,
        'retryAfter'     => 0,
        'field'          => '',
        'outcomeUnknown' => $outcomeUnknown,
        'remoteExists'   => false,
        'data'           => [],
    ];

    foreach (['httpStatus', 'retryAfter'] as $number) {
        if (isset($extra[$number]) && is_int($extra[$number])) {
            $result[$number] = $extra[$number];
        }
    }
    if (isset($extra['field']) && is_string($extra['field']) && preg_match('/^[a-z_]{1,40}$/D', $extra['field']) === 1) {
        $result['field'] = $extra['field'];
    }
    if (isset($extra['data']) && is_array($extra['data'])) {
        $result['data'] = $extra['data'];
    }
    if (isset($extra['remoteExists']) && $extra['remoteExists'] === true) {
        $result['remoteExists'] = true;
    }
    if ($reason === 'rate_limited' && $result['retryAfter'] > 0) {
        $result['adminMessage'] .= ' It asks for a wait of ' . $result['retryAfter'] . ' seconds.';
    }
    if ($result['httpStatus'] > 0) {
        $result['adminMessage'] .= ' (Go2My.Link status ' . $result['httpStatus'] . '.)';
    }
    if ($outcomeUnknown) {
        $result['adminMessage'] .= ' It is not known whether Go2My.Link carried out the request.';
    }

    return $result;
}

/**
 * ✅ A successful result in the same shape. PURE.
 *
 * @param array<string, mixed> $data
 * @param int                  $httpStatus
 * @return array{ok: bool, reason: string, message: string, adminMessage: string, httpCode: int, httpStatus: int, retryAfter: int, field: string, outcomeUnknown: bool, remoteExists: bool, data: array}
 */
function go2mylinkSuccess(array $data, int $httpStatus): array
{
    return [
        'ok'             => true,
        'reason'         => '',
        'message'        => '',
        'adminMessage'   => '',
        'httpCode'       => 200,
        'httpStatus'     => $httpStatus,
        'retryAfter'     => 0,
        'field'          => '',
        'outcomeUnknown' => false,
        'remoteExists'   => true,
        'data'           => $data,
    ];
}

/**
 * 🔄 The tblQRRedirects.syncState word for a create result (review H1, L6).
 *    PURE. WP4c stores this, with the reason word in lastSyncError:
 *
 *   active      - Go2My.Link has the link AND its short domain is confirmed:
 *                 the only state whose code may be printed;
 *   unconfirmed - Go2My.Link holds a link that must NOT be printed (another
 *                 short domain, a domain the look-up could not confirm, a
 *                 link switched off there, or this QR id linked under a code
 *                 CueRCode does not know). Never retried automatically; an
 *                 admin must look;
 *   pending     - not known whether Go2My.Link made it (the tidy step looks
 *                 it up later);
 *   failed      - nothing was made (a new try, perhaps with a new code, may
 *                 follow).
 *
 *   (A fifth word, unlinked, is the column's starting value: Go2My.Link not
 *   asked yet, including every code from before upgrade 0007.)
 *
 * @param array $result A result from go2mylinkCreateLink() or go2mylinkCreateLinkSafely()
 * @return string
 */
function go2mylinkSyncStateFor(array $result): string
{
    if (isset($result['ok']) && $result['ok'] === true) {
        return 'active';
    }
    if (isset($result['remoteExists']) && $result['remoteExists'] === true) {
        return 'unconfirmed';
    }
    if (isset($result['outcomeUnknown']) && $result['outcomeUnknown'] === true) {
        return 'pending';
    }

    return 'failed';
}

// ==============================================================================
// 🌐 The request and the transport
// ==============================================================================

/**
 * 🧱 One request: method, full address, headers and JSON body. PURE.
 *
 * @param array                     $settings From go2mylinkReadSettings()
 * @param string                    $method   GET, POST or PUT
 * @param string                    $route    After /api/v1/, for example "urls/Ab3dE9x"
 * @param array<string, mixed>|null $body     Sent as JSON; null = no body
 * @return array{method: string, url: string, headers: array<int, string>, body: string|null}
 */
function go2mylinkBuildRequest(#[\SensitiveParameter] array $settings, string $method, string $route, ?array $body): array
{
    $headers = [
        'Authorization: Bearer ' . (string) $settings['apiKey'],
        'Accept: application/json',
        'User-Agent: CueRCode-Go2MyLink-client/1.0',
    ];

    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[]   = 'Content-Type: application/json';
    }

    return [
        'method'  => $method,
        'url'     => (string) $settings['apiBaseUrl'] . '/' . $route,
        'headers' => $headers,
        'body'    => $encodedBody,
    ];
}

/**
 * 🌐 Send one request with curl and return what came back. TLS checked, no
 *    redirects, https only (oauthApplyCurlSafety()), never unpacks compressed
 *    replies (#150), a time limit, a reply cut off past 64 KB. Nothing sent
 *    is ever logged.
 *
 * @param array  $request From go2mylinkBuildRequest()
 * @param int    $timeout Seconds
 * @param string $caFile  The tests' own authority, used INSTEAD of the system bundle; '' = the system's (the site)
 * @return array{ok: bool, status: int, body: string, retryAfter: int, errno: int, error: string, tooLarge: bool, sent: bool}
 */
function go2mylinkHttpTransport(#[\SensitiveParameter] array $request, int $timeout, string $caFile = ''): array
{
    $failed = ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 2, 'error' => '', 'tooLarge' => false, 'sent' => false];

    if (!function_exists('curl_init')) {
        return $failed;
    }

    $curlHandle = curl_init();
    if ($curlHandle === false) {
        return $failed;
    }

    $body        = '';
    $tooLarge    = false;
    $retryAfter  = 0;
    $headerBytes = 0;

    $options = [
        CURLOPT_URL            => (string) $request['url'],
        CURLOPT_CUSTOMREQUEST  => (string) $request['method'],
        CURLOPT_HTTPHEADER     => $request['headers'],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_RETURNTRANSFER => false,
        // 📏 The reply is kept only up to the limit; past it the transfer is stopped.
        CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > CUERCODE_GO2MYLINK_MAX_REPLY_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        // ⏳ Only Retry-After is read from the headers, and only so much of
        //    them. Too many headers counts as a reply too large (review M1),
        //    so a create is treated as "may have been made".
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter, &$headerBytes, &$tooLarge): int {
            $headerBytes += strlen($line);
            if ($headerBytes > CUERCODE_GO2MYLINK_MAX_HEADER_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $match = [];
            if (preg_match('/^Retry-After:\s*([0-9]{1,6})\s*$/i', $line, $match) === 1) {
                $retryAfter = (int) $match[1];
            }
            return strlen($line);
        },
    ];
    if ($request['body'] !== null) {
        $options[CURLOPT_POSTFIELDS] = (string) $request['body'];
    } elseif ($request['method'] === 'PUT' || $request['method'] === 'POST') {
        // An empty body is still sent with "Content-Length: 0".
        $options[CURLOPT_POSTFIELDS] = '';
    }

    if (curl_setopt_array($curlHandle, $options) !== true) {
        return $failed;
    }

    // 🔒 No redirects, https only, certificates checked.
    if (!oauthApplyCurlSafety($curlHandle)) {
        return $failed;
    }

    // 🧪 Only the tests pass $caFile: their own throwaway authority. It
    //    REPLACES the default certificate bundle for this one request (curl's
    //    CURLOPT_CAINFO names the bundle used to check the server); checking
    //    stays on. The site never passes it, so real calls use the system's
    //    bundle.
    if ($caFile !== '' && curl_setopt($curlHandle, CURLOPT_CAINFO, $caFile) !== true) {
        return $failed;
    }

    $finished    = curl_exec($curlHandle);
    $errno       = curl_errno($curlHandle);
    $status      = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
    $requestSize = (int) curl_getinfo($curlHandle, CURLINFO_REQUEST_SIZE);

    // 🧹 No curl_close(): a no-op since PHP 8.0, and PHP 8.5 warns (#117).

    return [
        'ok'         => ($errno === 0 && $finished !== false),
        'status'     => $status,
        'body'       => $body,
        'retryAfter' => $retryAfter,
        'errno'      => $errno,
        'error'      => '',
        'tooLarge'   => $tooLarge,
        // 📨 Did any of the request leave? (0 bytes = it never got that far.)
        'sent'       => ($requestSize > 0),
    ];
}

/**
 * 📦 Any transport's answer in one shape (a stand-in may leave keys out). A
 *    reply that does not say whether the request was sent counts as sent
 *    (the safe direction). PURE.
 *
 * @param mixed $reply
 * @return array{ok: bool, status: int, body: string, retryAfter: int, errno: int, tooLarge: bool, sent: bool}
 */
function go2mylinkNormaliseReply(mixed $reply): array
{
    $normal = ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 0, 'tooLarge' => false, 'sent' => true];

    if (!is_array($reply)) {
        return $normal;
    }
    if (isset($reply['ok']) && $reply['ok'] === true) {
        $normal['ok'] = true;
    }
    foreach (['status', 'retryAfter', 'errno'] as $number) {
        if (isset($reply[$number]) && is_int($reply[$number])) {
            $normal[$number] = $reply[$number];
        }
    }
    if (isset($reply['body']) && is_string($reply['body'])) {
        $normal['body'] = substr($reply['body'], 0, CUERCODE_GO2MYLINK_MAX_REPLY_BYTES);
    }
    if (isset($reply['tooLarge']) && $reply['tooLarge'] === true) {
        $normal['tooLarge'] = true;
    }
    if (isset($reply['sent']) && $reply['sent'] === false) {
        $normal['sent'] = false;
    }

    return $normal;
}

/**
 * 📨 Send one request through the transport (the real one, or a test's) and
 *    return the normalised reply. Never throws.
 *
 * @param array                     $settings
 * @param string                    $method
 * @param string                    $route
 * @param array<string, mixed>|null $body
 * @param array<string, mixed>      $options 'transport' (callable), 'caFile' (string)
 * @return array{ok: bool, status: int, body: string, retryAfter: int, errno: int, tooLarge: bool, sent: bool}
 */
function go2mylinkSend(#[\SensitiveParameter] array $settings, string $method, string $route, ?array $body, #[\SensitiveParameter] array $options): array
{
    $request = go2mylinkBuildRequest($settings, $method, $route, $body);

    $caFile = '';
    if (isset($options['caFile']) && is_string($options['caFile'])) {
        $caFile = $options['caFile'];
    }

    try {
        if (isset($options['transport']) && is_callable($options['transport'])) {
            $reply = $options['transport']($request, (int) $settings['timeout']);
        } else {
            $reply = go2mylinkHttpTransport($request, (int) $settings['timeout'], $caFile);
        }
    } catch (\Throwable $e) {
        $reply = ['ok' => false, 'errno' => 2, 'sent' => false];
    }

    // 🧹 The request (with the key in it) is not kept.
    $request = [];

    return go2mylinkNormaliseReply($reply);
}

// ==============================================================================
// 🧠 PURE: reading an answer
// ==============================================================================

/**
 * 🔌 The reason for a curl failure, and whether the request may have been
 *    carried out. PURE.
 *
 * @param int  $errno A curl error number
 * @param bool $sent  Whether any of the request left
 * @return array{reason: string, mayHaveRun: bool}
 */
function go2mylinkTransportReason(int $errno, bool $sent): array
{
    // 🌍 Never got a connection: nothing was sent.
    if (in_array($errno, [5, 6, 7], true)) {
        return ['reason' => 'unreachable', 'mayHaveRun' => false];
    }

    // 🔐 The certificate or the secure connection failed: nothing was sent.
    if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) {
        return ['reason' => 'tls_failed', 'mayHaveRun' => false];
    }

    // ⏱️ Ran out of time, or the connection dropped. If none of the request
    //    had left yet (a time limit while connecting), nothing ran.
    if (in_array($errno, [16, 18, 28, 52, 55, 56, 92], true)) {
        if (!$sent) {
            return ['reason' => 'unreachable', 'mayHaveRun' => false];
        }
        return ['reason' => 'timeout', 'mayHaveRun' => true];
    }

    // 🧾 Review M1: anything else AFTER the request left (a garbled or
    //    missing status line, a broken length header, ...) is an answer that
    //    could not be read: Go2My.Link may have done what was asked.
    if ($sent) {
        return ['reason' => 'bad_reply', 'mayHaveRun' => true];
    }

    // 🔧 Anything else before sending is our own set-up (no curl, a bad option, ...).
    return ['reason' => 'bad_request', 'mayHaveRun' => false];
}

/**
 * 💬 Go2My.Link's own error wording from a reply body, or '' when there is
 *    none. Only ever compared with fixed text; never shown or logged. PURE.
 *
 * @param string $body
 * @return string
 */
function go2mylinkErrorMessageOf(string $body): string
{
    $decoded = json_decode($body, true, 16);
    if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
        return $decoded['error']['message'];
    }

    return '';
}

/**
 * 🚫 Which kind of 403, from Go2My.Link's fixed wording (urls.php, index.php,
 *    shorturl_create.php). Only used to choose a reason; the wording itself is
 *    never shown or logged. PURE.
 *
 * @param string $remoteMessage
 * @return string
 */
function go2mylinkForbiddenReason(string $remoteMessage): string
{
    $lower = strtolower($remoteMessage);

    if (str_contains($lower, 'integration is currently disabled')) {
        return 'integration_off';
    }
    if (str_contains($lower, 'required qr:link scope') || str_contains($lower, 'required scope')) {
        return 'missing_permission';
    }
    if (str_contains($lower, 'link limit')) {
        return 'link_limit';
    }

    return 'forbidden';
}

/**
 * 🧠 Turn a normalised reply into a result. On success, data is Go2My.Link's
 *    "data" object (still to be checked by the caller). PURE.
 *
 * @param array            $reply      From go2mylinkNormaliseReply()
 * @param array<int, int>  $expected   The success statuses (200 or 201)
 * @param bool             $mayChange  True for a call that changes something (POST, PUT)
 * @return array Result shape (go2mylinkFailure() / go2mylinkSuccess())
 */
function go2mylinkResultFromReply(array $reply, array $expected, bool $mayChange): array
{
    // 🔌 1. No answer at all.
    if (!$reply['ok']) {
        if ($reply['tooLarge']) {
            return go2mylinkFailure('bad_reply', $mayChange, ['httpStatus' => $reply['status']]);
        }
        $transport = go2mylinkTransportReason($reply['errno'], $reply['sent']);
        return go2mylinkFailure($transport['reason'], ($mayChange && $transport['mayHaveRun']));
    }

    $status  = $reply['status'];
    $decoded = json_decode($reply['body'], true, 16);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    // ✅ 2. One of the expected success statuses, with the success envelope.
    if (in_array($status, $expected, true)) {
        if (!isset($decoded['status'], $decoded['data']) || $decoded['status'] !== 'success' || !is_array($decoded['data'])) {
            return go2mylinkFailure('bad_reply', $mayChange, ['httpStatus' => $status]);
        }
        return go2mylinkSuccess($decoded['data'], $status);
    }

    // ❌ 3. An error answer.
    $remoteMessage = '';
    $remoteField   = '';
    if (isset($decoded['error']) && is_array($decoded['error'])) {
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            $remoteMessage = $decoded['error']['message'];
        }
        if (isset($decoded['error']['field']) && is_string($decoded['error']['field'])) {
            $remoteField = $decoded['error']['field'];
        }
    }
    $extra = ['httpStatus' => $status, 'field' => $remoteField];

    if ($status === 400) {
        return go2mylinkFailure('bad_request', false, $extra);
    }
    if ($status === 401) {
        return go2mylinkFailure('auth_failed', false, $extra);
    }
    if ($status === 403) {
        return go2mylinkFailure(go2mylinkForbiddenReason($remoteMessage), false, $extra);
    }
    if ($status === 404) {
        return go2mylinkFailure('not_found', false, $extra);
    }
    if ($status === 409) {
        if ($remoteField === 'qr_external_uuid') {
            // 🔗 Go2My.Link holds a link for this QR id (under some code).
            $extra['remoteExists'] = true;
            return go2mylinkFailure('already_linked', false, $extra);
        }
        return go2mylinkFailure('code_taken', false, $extra);
    }
    if ($status === 422) {
        return go2mylinkFailure('rejected', false, $extra);
    }
    if ($status === 429) {
        $extra['retryAfter'] = $reply['retryAfter'];
        return go2mylinkFailure('rate_limited', false, $extra);
    }
    if ($status === 503) {
        // Go2My.Link's own "API switched off" answer comes before any work.
        return go2mylinkFailure('unavailable', false, $extra);
    }
    if ($status >= 500) {
        // 500 is its handler failing part-way; 502, 504 and 52x come from a
        // gateway in front of it. Either way the work may have been done.
        return go2mylinkFailure('unavailable', $mayChange, $extra);
    }
    if ($status >= 300 && $status < 400) {
        // ↪️ A redirect is never followed, and never a success.
        return go2mylinkFailure('bad_reply', false, $extra);
    }
    if ($status >= 200 && $status < 300) {
        // A success status we did not ask for: something may have been done.
        return go2mylinkFailure('bad_reply', $mayChange, $extra);
    }

    return go2mylinkFailure('bad_reply', false, $extra);
}

/**
 * 🔗 One link from Go2My.Link's link shape (_g2ml_apiUrlsFormatRow()), or
 *    null when it is not that shape. PURE.
 *
 *    shortUrl is the link's full short address when the reply carries one
 *    (short_url), otherwise ''. Go2My.Link's look-up does not send it today
 *    (asked for on Go2My.Link issue #276); without it the short domain
 *    cannot be confirmed (review H1).
 *
 * @param array<string, mixed> $data
 * @return array{shortCode: string, destinationUrl: string, isActive: bool, clickCount: int, shortUrl: string}|null
 */
function go2mylinkLinkFromData(array $data): ?array
{
    if (!isset($data['short_code'], $data['destination_url'], $data['is_active'], $data['click_count'])) {
        return null;
    }
    if (!is_string($data['short_code']) || preg_match(CUERCODE_GO2MYLINK_CODE_PATTERN, $data['short_code']) !== 1) {
        return null;
    }
    if (!is_string($data['destination_url']) || $data['destination_url'] === '' || !is_bool($data['is_active'])) {
        return null;
    }
    if (!is_int($data['click_count']) || $data['click_count'] < 0) {
        return null;
    }

    $shortUrl = '';
    if (isset($data['short_url']) && is_string($data['short_url'])) {
        $shortUrl = $data['short_url'];
    }

    return [
        'shortCode'      => $data['short_code'],
        'destinationUrl' => $data['destination_url'],
        'isActive'       => $data['is_active'],
        'clickCount'     => $data['click_count'],
        'shortUrl'       => $shortUrl,
    ];
}

/**
 * 🌐 Is this short address exactly the short-link address this site prints
 *    plus the code? https, the same host (without regard to capitals), and
 *    the path "/<code>". PURE.
 *
 * @param string $shortUrl      From Go2My.Link
 * @param string $shortCode     The code it should end in
 * @param string $shortLinkBase dynamic_redirect_base_url
 * @return bool
 */
function go2mylinkShortUrlIsOurs(string $shortUrl, string $shortCode, string $shortLinkBase): bool
{
    if (stripos($shortUrl, 'https://') !== 0 || parse_url($shortUrl, PHP_URL_PATH) !== '/' . $shortCode) {
        return false;
    }
    $host = go2mylinkHostOf($shortUrl);

    return ($host !== '' && $host === go2mylinkHostOf($shortLinkBase));
}

/**
 * 🧾 Write one plain log line for a failed call: the operation, the reason,
 *    Go2My.Link's status and curl's number. Never the key, the address's
 *    query, Go2My.Link's wording or anything that was sent.
 *
 * @param array<string, mixed> $options   'logger' (callable) for tests; otherwise logError()
 * @param string               $operation create, update, lookup, test
 * @param array                $result    A failed result
 * @return void
 */
function go2mylinkLogFailure(#[\SensitiveParameter] array $options, string $operation, array $result): void
{
    $line = 'Go2My.Link ' . $operation . ' failed: reason=' . (string) $result['reason']
        . ' status=' . (int) $result['httpStatus'];
    if ($result['outcomeUnknown'] === true) {
        $line .= ' (it may have been carried out)';
    }

    if (isset($options['logger']) && is_callable($options['logger'])) {
        $options['logger']($line);
        return;
    }

    if (function_exists('logError')) {
        logError('warning', 'Go2My.Link call failed', $line);
    }
}

// ==============================================================================
// 🔗 The calls
// ==============================================================================

/**
 * ✨ Make the Go2My.Link link for one dynamic code (POST /api/v1/urls).
 *
 * @param array{redirectID: int, redirectUUID: string, destinationUrl: string, shortCode: string} $link
 *        From tblQRRedirects: redirectID becomes qr_external_id, redirectUUID
 *        qr_external_uuid, shortCode custom_code (L1). The destination must
 *        already have passed validateRedirectDestination().
 * @param array<string, mixed> $options 'settings', 'transport', 'caFile', 'logger' (tests and tools)
 * @return array Result; data on success: shortCode, shortUrl, codeKept, recovered
 */
function go2mylinkCreateLink(array $link, #[\SensitiveParameter] array $options = []): array
{
    $settings = go2mylinkSettingsFromOptions($options);
    if ($settings['problem'] !== '') {
        return go2mylinkFailure((string) $settings['problem'], false);
    }

    // 🧾 Refused here, before anything is sent: a request Go2My.Link would refuse anyway.
    $valid = isset($link['redirectID'], $link['redirectUUID'], $link['destinationUrl'], $link['shortCode'])
        && is_int($link['redirectID']) && $link['redirectID'] > 0
        && is_string($link['redirectUUID']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $link['redirectUUID']) === 1
        && is_string($link['destinationUrl']) && $link['destinationUrl'] !== '' && strlen($link['destinationUrl']) <= 2048
        && is_string($link['shortCode']) && go2mylinkCodeIsAllowed($link['shortCode']);
    if (!$valid) {
        $refused = go2mylinkFailure('bad_request', false);
        go2mylinkLogFailure($options, 'create', $refused);
        return $refused;
    }

    $reply  = go2mylinkSend($settings, 'POST', 'urls', [
        'destination_url'  => $link['destinationUrl'],
        'qr_external_id'   => $link['redirectID'],
        'qr_external_uuid' => $link['redirectUUID'],
        'custom_code'      => $link['shortCode'],
    ], $options);
    $result = go2mylinkResultFromReply($reply, [201], true);
    if (!$result['ok']) {
        go2mylinkLogFailure($options, 'create', $result);
        return $result;
    }

    // 🔎 The reply must be the create shape, for OUR QR id, and its address
    //    must be the short-link address plus its code.
    $data = $result['data'];
    $shapeOk = isset($data['short_code'], $data['short_url'], $data['qr_code_external_uuid'])
        && is_string($data['short_code']) && preg_match(CUERCODE_GO2MYLINK_CODE_PATTERN, $data['short_code']) === 1
        && is_string($data['short_url']) && is_string($data['qr_code_external_uuid'])
        && strcasecmp($data['qr_code_external_uuid'], $link['redirectUUID']) === 0
        && stripos($data['short_url'], 'https://') === 0
        && parse_url($data['short_url'], PHP_URL_PATH) === '/' . $data['short_code'];
    if (!$shapeOk) {
        $odd = go2mylinkFailure('bad_reply', true, ['httpStatus' => $result['httpStatus']]);
        go2mylinkLogFailure($options, 'create', $odd);
        return $odd;
    }

    $made = [
        'shortCode' => $data['short_code'],
        'shortUrl'  => $data['short_url'],
        'codeKept'  => ($data['short_code'] === $link['shortCode']),
        'recovered' => false,
    ];

    // 🌐 Decision 0030: a code is only ever printed on the address this site
    //    prints (QRmy.Link, or alpha.QRmy.Link on alpha). A link made on any
    //    other short domain is reported, never used.
    if (go2mylinkHostOf($data['short_url']) !== go2mylinkHostOf((string) $settings['shortLinkBase'])) {
        $elsewhere = go2mylinkFailure('wrong_short_domain', false, ['httpStatus' => $result['httpStatus'], 'data' => $made, 'remoteExists' => true]);
        go2mylinkLogFailure($options, 'create', $elsewhere);
        return $elsewhere;
    }

    return go2mylinkSuccess($made, $result['httpStatus']);
}

/**
 * 🔍 Look one link up (GET /api/v1/urls/{code}).
 *
 * @param string               $shortCode
 * @param array<string, mixed> $options
 * @return array Result; data on success: shortCode, destinationUrl, isActive, clickCount
 */
function go2mylinkLookupLink(string $shortCode, #[\SensitiveParameter] array $options = []): array
{
    $settings = go2mylinkSettingsFromOptions($options);
    if ($settings['problem'] !== '') {
        return go2mylinkFailure((string) $settings['problem'], false);
    }
    if (!go2mylinkCodeIsAllowed($shortCode)) {
        $refused = go2mylinkFailure('bad_request', false);
        go2mylinkLogFailure($options, 'lookup', $refused);
        return $refused;
    }

    $result = go2mylinkResultFromReply(go2mylinkSend($settings, 'GET', 'urls/' . rawurlencode($shortCode), null, $options), [200], false);
    if (!$result['ok']) {
        if ($result['reason'] !== 'not_found') {
            go2mylinkLogFailure($options, 'lookup', $result);
        }
        return $result;
    }

    $found = go2mylinkLinkFromData($result['data']);
    if ($found === null) {
        $odd = go2mylinkFailure('bad_reply', false, ['httpStatus' => $result['httpStatus']]);
        go2mylinkLogFailure($options, 'lookup', $odd);
        return $odd;
    }

    return go2mylinkSuccess($found, $result['httpStatus']);
}

/**
 * 📊 The total number of scans of one code (decision 0033: totals only).
 *    Go2My.Link adds one to click_count on every redirect.
 *
 * @param string               $shortCode
 * @param array<string, mixed> $options
 * @return array Result; data on success: total
 */
function go2mylinkScanTotal(string $shortCode, #[\SensitiveParameter] array $options = []): array
{
    $found = go2mylinkLookupLink($shortCode, $options);
    if (!$found['ok']) {
        return $found;
    }

    return go2mylinkSuccess(['total' => $found['data']['clickCount']], $found['httpStatus']);
}

/**
 * ✏️ Change where a link goes (PUT /api/v1/urls/{code}, destination_url only).
 *
 * @param string               $shortCode
 * @param string               $destinationUrl Already passed validateRedirectDestination()
 * @param array<string, mixed> $options
 * @return array Result; data on success: shortCode, destinationUrl, isActive, clickCount
 */
function go2mylinkUpdateDestination(string $shortCode, string $destinationUrl, #[\SensitiveParameter] array $options = []): array
{
    $settings = go2mylinkSettingsFromOptions($options);
    if ($settings['problem'] !== '') {
        return go2mylinkFailure((string) $settings['problem'], false);
    }
    if (!go2mylinkCodeIsAllowed($shortCode) || $destinationUrl === '' || strlen($destinationUrl) > 2048) {
        $refused = go2mylinkFailure('bad_request', false);
        go2mylinkLogFailure($options, 'update', $refused);
        return $refused;
    }

    $result = go2mylinkResultFromReply(go2mylinkSend($settings, 'PUT', 'urls/' . rawurlencode($shortCode), ['destination_url' => $destinationUrl], $options), [200], true);
    if (!$result['ok']) {
        go2mylinkLogFailure($options, 'update', $result);
        return $result;
    }

    $changed = go2mylinkLinkFromData($result['data']);
    if ($changed === null) {
        $odd = go2mylinkFailure('bad_reply', true, ['httpStatus' => $result['httpStatus']]);
        go2mylinkLogFailure($options, 'update', $odd);
        return $odd;
    }

    return go2mylinkSuccess($changed, $result['httpStatus']);
}

/**
 * 🔁 Make the link, safely (lead choice L1). The caller has already stored
 *    the chosen code on its tblQRRedirects row, so:
 *
 *   - created                         -> success;
 *   - created on another short domain -> wrong_short_domain (remoteExists):
 *                                        never printed, never tried again;
 *   - the answer was lost (time limit, dropped connection, a 500, an odd
 *     success reply)                  -> look the code up: found = see
 *                                        below; not found = try ONCE more
 *                                        with the same code and QR id;
 *                                        look-up lost too = "not known yet"
 *                                        (outcomeUnknown), for the tidy step;
 *   - "this QR is already linked"     -> look the code up: found = see
 *                                        below; not found = already_linked
 *                                        (remoteExists; an admin must look);
 *   - "code taken"                    -> handed straight back: the caller
 *                                        stores a NEW code, then calls again;
 *   - anything else                   -> handed back as it is.
 *
 * A FOUND link (recovery) is a success only when ALL of these hold (review
 * H1 and L1); otherwise the result says why and remoteExists is true:
 *   - it is switched on (else link_switched_off);
 *   - the look-up gave its short address (else domain_unconfirmed: true of
 *     every look-up today, Go2My.Link issue #276) and that address is this
 *     site's short-link address plus the code (else wrong_short_domain);
 *   - it goes to our destination; if not, our destination is sent again
 *     (PUT) and only a confirmed change counts (destinationResent).
 * A found link is ours: the code is unique in tblQRRedirects and was stored
 * before it was sent.
 *
 * @param array{redirectID: int, redirectUUID: string, destinationUrl: string, shortCode: string} $link
 * @param array<string, mixed> $options
 * @return array Result; data on success: shortCode, shortUrl, codeKept, recovered (and, when recovered, remoteDestination, destinationMatches, destinationResent)
 */
function go2mylinkCreateLinkSafely(array $link, #[\SensitiveParameter] array $options = []): array
{
    $first = go2mylinkCreateLink($link, $options);
    if ($first['ok']) {
        return $first;
    }

    if ($first['reason'] !== 'already_linked' && $first['outcomeUnknown'] !== true) {
        return $first;
    }

    $shortCode = (string) $link['shortCode'];
    $settings  = go2mylinkSettingsFromOptions($options);

    // 🧩 What a found link means (review H1, L1): only a switched-on link
    //    on the CONFIRMED short domain, going to our destination, counts.
    $recovered = static function (array $found) use ($link, $shortCode, $settings, $options): array {
        $foundLink = $found['data'];
        $held      = ['httpStatus' => $found['httpStatus'], 'remoteExists' => true, 'data' => ['shortCode' => $shortCode, 'remoteDestination' => $foundLink['destinationUrl']]];

        $reason = '';
        if ($foundLink['isActive'] !== true) {
            $reason = 'link_switched_off';
        } elseif ($foundLink['shortUrl'] === '') {
            // 🚧 Go2My.Link's look-up does not give the short address today
            //    (Go2My.Link issue #276), so the domain cannot be confirmed.
            $reason = 'domain_unconfirmed';
        } elseif (!go2mylinkShortUrlIsOurs($foundLink['shortUrl'], $foundLink['shortCode'], (string) $settings['shortLinkBase'])) {
            $reason = 'wrong_short_domain';
        }
        if ($reason !== '') {
            $notUsed = go2mylinkFailure($reason, false, $held);
            go2mylinkLogFailure($options, 'create', $notUsed);
            return $notUsed;
        }

        // ✏️ Pointing somewhere else: send our destination again; only a
        //    confirmed change counts.
        $resent = false;
        if ($foundLink['destinationUrl'] !== $link['destinationUrl']) {
            $change = go2mylinkUpdateDestination($foundLink['shortCode'], (string) $link['destinationUrl'], $options);
            if (!$change['ok'] || $change['data']['destinationUrl'] !== $link['destinationUrl']) {
                if ($change['ok']) {
                    $change = go2mylinkFailure('bad_reply', true, ['httpStatus' => $change['httpStatus']]);
                }
                $change['remoteExists'] = true;
                return $change;
            }
            $resent = true;
        }

        return go2mylinkSuccess([
            'shortCode'          => $foundLink['shortCode'],
            'shortUrl'           => $foundLink['shortUrl'],
            'codeKept'           => true,
            'recovered'          => true,
            'remoteDestination'  => $foundLink['destinationUrl'],
            'destinationMatches' => true,
            'destinationResent'  => $resent,
        ], $found['httpStatus']);
    };

    $lookup = go2mylinkLookupLink($shortCode, $options);
    if ($lookup['ok']) {
        return $recovered($lookup);
    }

    if ($lookup['reason'] !== 'not_found') {
        // 🤷 Cannot tell: leave it "not known yet" for the tidy step.
        if ($first['reason'] === 'already_linked') {
            return go2mylinkFailure($lookup['reason'], true, ['httpStatus' => $lookup['httpStatus'], 'remoteExists' => true]);
        }
        return $first;
    }

    if ($first['reason'] === 'already_linked') {
        // The QR id is linked under a code we do not know.
        return $first;
    }

    // 🔁 Not there: nothing was made, so one more try, exactly the same.
    $second = go2mylinkCreateLink($link, $options);
    if ($second['ok'] || $second['reason'] !== 'already_linked') {
        return $second;
    }

    // ⏳ The first try landed after our look-up: look once more.
    $again = go2mylinkLookupLink($shortCode, $options);
    if ($again['ok']) {
        return $recovered($again);
    }

    return $second;
}

/**
 * 🔌 "Test connection" (the admin settings page). Creates and changes no
 *    link on Go2My.Link. (Go2My.Link still records each call in its request
 *    log, counts it towards the key's request limit and notes when the key
 *    was last used - review L7.) In order:
 *
 *   1. GET /ping            - the address answers, the certificate is good,
 *                             the key is accepted;
 *   2. GET /urls?limit=1    - the key may read links (urls:read);
 *   3. PUT /urls/bulk       - the key may change links (urls:write): Go2My.Link
 *                             checks the permission (403) BEFORE it looks for
 *                             the link, and "bulk" is a code it reserves, so
 *                             the answer is "no such link" (404) and nothing
 *                             changes; no body is sent either. Only that
 *                             handler's own wording counts (review L8);
 *   4. GET /account         - the key's permissions, to see qr:link. Only
 *                             possible when the key also has the optional
 *                             account:read; otherwise this is said plainly.
 *
 * It cannot check, without making a real code: Go2My.Link's CueRCode switch,
 * its "CueRCode chooses the code" setting, or which short domain new links
 * get. The result says so.
 *
 * @param array<string, mixed> $options
 * @return array{ok: bool, reason: string, lines: array<int, string>}
 */
function go2mylinkTestConnection(#[\SensitiveParameter] array $options = []): array
{
    $settings = go2mylinkSettingsFromOptions($options);
    if ($settings['problem'] !== '') {
        $facts = go2mylinkReasonFacts((string) $settings['problem']);
        return ['ok' => false, 'reason' => (string) $settings['problem'], 'lines' => [$facts['adminMessage']]];
    }

    $host  = go2mylinkHostOf((string) $settings['apiBaseUrl']);
    $lines = [];

    $stop = static function (array $result, string $prefix) use ($options, &$lines): array {
        go2mylinkLogFailure($options, 'test', $result);
        $lines[] = $prefix . ' ' . $result['adminMessage'];
        return ['ok' => false, 'reason' => (string) $result['reason'], 'lines' => $lines];
    };

    // 1️⃣ Reachable, certificate good, key accepted.
    $ping = go2mylinkResultFromReply(go2mylinkSend($settings, 'GET', 'ping', null, $options), [200], false);
    if ($ping['ok'] && (!isset($ping['data']['pong']) || $ping['data']['pong'] !== true)) {
        $ping = go2mylinkFailure('bad_reply', false, ['httpStatus' => $ping['httpStatus']]);
    }
    if (!$ping['ok']) {
        return $stop($ping, 'Could not connect to ' . $host . '.');
    }
    $lines[] = 'Connected to ' . $host . ': the certificate was checked and the API key was accepted.';

    // 2️⃣ May read links.
    $list = go2mylinkResultFromReply(go2mylinkSend($settings, 'GET', 'urls?limit=1', null, $options), [200], false);
    if ($list['ok'] && (!isset($list['data']['items']) || !is_array($list['data']['items']))) {
        $list = go2mylinkFailure('bad_reply', false, ['httpStatus' => $list['httpStatus']]);
    }
    if (!$list['ok']) {
        return $stop($list, 'Reading links did not work (permission urls:read).');
    }
    $lines[] = 'The key may read links (urls:read).';

    // 3️⃣ May change links: a change to a code that can never exist. Only
    //    the change handler's own "no such link" 404 counts (review L8):
    //    Go2My.Link's "no such endpoint" 404 comes BEFORE its permission
    //    check, so it would prove nothing.
    $probeReply = go2mylinkSend($settings, 'PUT', 'urls/' . CUERCODE_GO2MYLINK_PROBE_CODE, null, $options);
    if ($probeReply['ok'] && $probeReply['status'] === 404 && go2mylinkErrorMessageOf($probeReply['body']) !== CUERCODE_GO2MYLINK_NO_SUCH_LINK) {
        return $stop(go2mylinkFailure('bad_reply', false, ['httpStatus' => 404]), 'Changing links could not be checked (permission urls:write): Go2My.Link did not answer as its change handler does.');
    }
    if (!($probeReply['ok'] && $probeReply['status'] === 404)) {
        $probe = go2mylinkResultFromReply($probeReply, [], false);
        return $stop($probe, 'Changing links did not work (permission urls:write).');
    }
    $lines[] = 'The key may change links (urls:write).';

    // 4️⃣ The key's permissions, for qr:link (needs the optional account:read).
    $account = go2mylinkResultFromReply(go2mylinkSend($settings, 'GET', 'account', null, $options), [200], false);
    $ok      = true;
    $reason  = '';
    if ($account['ok'] && isset($account['data']['scopes']) && is_array($account['data']['scopes'])) {
        $missing = array_values(array_diff(['qr:link', 'urls:read', 'urls:write'], $account['data']['scopes']));
        $later   = array_values(array_diff(['urls:delete', 'analytics:read'], $account['data']['scopes']));
        if ($missing !== []) {
            $ok      = false;
            $reason  = 'missing_permission';
            $lines[] = 'The key is missing a permission CueRCode needs: ' . implode(', ', $missing) . '. Make a key with it on the Go2My.Link dashboard.';
        } else {
            $lines[] = 'The key has every permission CueRCode needs (qr:link, urls:read, urls:write).';
        }
        if ($later !== []) {
            $lines[] = 'Not needed yet, but planned: ' . implode(', ', $later) . '.';
        }
    } else {
        $lines[] = 'The key\'s permissions could not be listed (that needs the optional permission account:read), so qr:link was not checked.';
    }

    $lines[] = 'These are not checked here, because only a real code can show them: that Go2My.Link has its CueRCode switch and its "CueRCode chooses the code" setting on, and that new links are made on '
        . go2mylinkHostOf((string) $settings['shortLinkBase']) . '. No link was created or changed on Go2My.Link (it still records these calls in its request log, and they count towards the key\'s request limit).';

    return ['ok' => $ok, 'reason' => $reason, 'lines' => $lines];
}
