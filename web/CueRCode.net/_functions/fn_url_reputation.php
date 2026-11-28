<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - Link-safety check: is a destination a known harmful page? (WP4e, #168)
 * ==============================================================================
 *
 * Path: _functions/fn_url_reputation.php
 *
 * WHAT THIS IS (plain English):
 *   A dynamic QR code sends people on to a web address its owner chose (the
 *   "destination"). Before a destination goes live - when a code is made, when
 *   its destination changes, when it is switched back on, when an admin lifts
 *   a suspension - and once a day for every code in use, this file asks an
 *   outside "link-safety" service whether the address is a known phishing,
 *   malware or unwanted-software page.
 *
 *   Decisions 0029 and 0035: Google Web Risk now, a second paid provider later.
 *   So the providers are a LIST (urlReputationProviders(); the admin setting
 *   link_safety_providers says which are asked, in order). Adding a provider
 *   later is one entry in that list plus one "ask" function.
 *
 * WHAT EACH ANSWER MEANS:
 *   - "unsafe"    : a provider lists the address. The create or change is
 *                   REFUSED (422 DESTINATION_UNSAFE); the daily re-check
 *                   SUSPENDS the code (fn_dynamic_qr_takedown.php). Each such
 *                   answer is written to tblLinkSafetyHits.
 *   - "safe"      : every provider asked said it is not listed.
 *   - "unchecked" : a provider could not answer (unreachable, too slow, its
 *                   quota used up, the key refused, an odd reply) or none is
 *                   set up. The destination is LET THROUGH ("fail-open",
 *                   design section 2.3), the reason is logged - at most once
 *                   every 10 minutes per provider and reason, so a long
 *                   outage cannot fill the error log - and the daily job
 *                   checks the code again.
 *
 * GOOGLE WEB RISK, AS ITS OWN PAGES DESCRIBE IT (read 2026-11-28, ordinary
 * page reads only; nothing was sent to the API itself):
 *   - https://cloud.google.com/web-risk/docs/lookup-api and
 *     https://cloud.google.com/web-risk/docs/reference/rest/v1/uris/search:
 *     GET https://webrisk.googleapis.com/v1/uris:search with "uri" (the
 *     address, URL-encoded) and "threatTypes" repeated, an empty body. The
 *     reply is {} when the address is on no list, otherwise
 *     {"threat": {"threatTypes": [...], "expireTime": "2019-07-17T15:01:23.045123456Z"}}.
 *   - https://cloud.google.com/web-risk/docs/reference/rest/v1/ThreatType:
 *     MALWARE, SOCIAL_ENGINEERING, UNWANTED_SOFTWARE (asked here) and
 *     SOCIAL_ENGINEERING_EXTENDED_COVERAGE (not asked: an extra list).
 *   - https://cloud.google.com/docs/authentication/api-keys-use: send the key
 *     in the "x-goog-api-key" header; the "key=" query parameter puts the key
 *     in the address, "exposing your key to theft through URL scans". So the
 *     key goes ONLY in that header here, never in the address.
 *   - https://cloud.google.com/web-risk/docs/caching: a Lookup API client
 *     must keep each "unsafe" answer until its expireTime and treat the
 *     address as still unsafe until then - tblLinkSafetyHits does that.
 *   - https://cloud.google.com/apis/design/errors: errors come back as
 *     {"error": {"code": 429, "status": "RESOURCE_EXHAUSTED", ...}} with the
 *     HTTP status carrying the meaning (400, 403, 429, 500, 503).
 *   - https://cloud.google.com/web-risk/quotas: 6000 SearchUris requests a
 *     minute per Google Cloud project.
 *
 * SAFETY RULES KEPT HERE:
 *   - The key is never logged, never put in a message, an address or a body,
 *     and never returned. #[\SensitiveParameter] on everything that holds it.
 *   - The request goes through the SAME transport as the Go2My.Link calls
 *     (go2mylinkHttpTransport()): certificates checked, redirects never
 *     followed, https only (oauthApplyCurlSafety()), no compressed replies,
 *     a reply never read past 64 KB, a time limit of 2 to 15 seconds (an
 *     admin setting, 5 by default).
 *   - Nothing about the visitor is sent: only the destination address.
 *
 * House rules: full multi-line PHP (no ??, ternaries, arrow functions or
 * match); every SQL name back-quoted; prepared statements. Loading this file
 * defines constants and functions only (no database call, no output).
 *
 * @package     CueRCode
 * @subpackage  DynamicQR
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4e, #168)
 * @see         docs/decisions/0029-link-safety-uses-web-risk-plus-a-second-provider.md
 * @see         docs/decisions/0035-web-risk-at-launch-second-provider-later.md
 * @see         _functions/fn_redirects.php - dynamicQrCheckDestinationSafety(), the one hook that calls this
 * @see         _functions/fn_go2mylink.php - go2mylinkHttpTransport(), the shared safe transport
 * @see         tests/test_url_reputation.php - the tests, with a stand-in HTTPS server
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $urlReputationScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $urlReputationScriptFilename = '';
}
if (basename($urlReputationScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($urlReputationScriptFilename);

// ==============================================================================
// 📦 What this file builds on (each is pure on loading)
// ==============================================================================

// 🌐 go2mylinkHttpTransport(), go2mylinkNormaliseReply(), go2mylinkTransportReason():
//    the one safe outbound transport (and, through it, fn_oauth.php and
//    fn_human_check.php: humanCheckReadKey(), humanCheckStateLogDue()).
if (!function_exists('go2mylinkHttpTransport')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_go2mylink.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/** @var string Google Web Risk's Lookup API address (uris.search, v1). */
if (!defined('CUERCODE_URL_REPUTATION_WEBRISK_ENDPOINT')) {
    define('CUERCODE_URL_REPUTATION_WEBRISK_ENDPOINT', 'https://webrisk.googleapis.com/v1/uris:search');
}

/** @var array<int, string> The Web Risk lists asked about (ThreatType values). */
if (!defined('CUERCODE_URL_REPUTATION_WEBRISK_THREAT_TYPES')) {
    define('CUERCODE_URL_REPUTATION_WEBRISK_THREAT_TYPES', ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE']);
}

/** @var int Seconds to wait for a provider: default, and the bounds an admin may set. */
if (!defined('CUERCODE_URL_REPUTATION_DEFAULT_TIMEOUT')) {
    define('CUERCODE_URL_REPUTATION_DEFAULT_TIMEOUT', 5);
}
if (!defined('CUERCODE_URL_REPUTATION_MIN_TIMEOUT')) {
    define('CUERCODE_URL_REPUTATION_MIN_TIMEOUT', 2);
}
if (!defined('CUERCODE_URL_REPUTATION_MAX_TIMEOUT')) {
    define('CUERCODE_URL_REPUTATION_MAX_TIMEOUT', 15);
}

/** @var int The same log line (provider + reason) is written at most once in this many seconds. */
if (!defined('CUERCODE_URL_REPUTATION_LOG_INTERVAL_SECONDS')) {
    define('CUERCODE_URL_REPUTATION_LOG_INTERVAL_SECONDS', 600);
}

/** @var int Seconds an "unsafe" answer is kept when the provider gave no usable expiry. */
if (!defined('CUERCODE_URL_REPUTATION_DEFAULT_CACHE_SECONDS')) {
    define('CUERCODE_URL_REPUTATION_DEFAULT_CACHE_SECONDS', 300);
}

/** @var int Longest an "unsafe" answer is kept without asking again, whatever the provider says. */
if (!defined('CUERCODE_URL_REPUTATION_MAX_CACHE_SECONDS')) {
    define('CUERCODE_URL_REPUTATION_MAX_CACHE_SECONDS', 86400);
}

/** @var string tblSettings key - seconds to wait for a provider (no seeded row: the default applies until saved). */
if (!defined('CUERCODE_SETTING_LINK_SAFETY_TIMEOUT')) {
    define('CUERCODE_SETTING_LINK_SAFETY_TIMEOUT', 'link_safety_timeout_seconds');
}

/** @var string tblSettings key - days a tblLinkSafetyHits row is kept (no seeded row). */
if (!defined('CUERCODE_SETTING_LINK_SAFETY_HITS_RETENTION_DAYS')) {
    define('CUERCODE_SETTING_LINK_SAFETY_HITS_RETENTION_DAYS', 'link_safety_hits_retention_days');
}

/** @var int Days a tblLinkSafetyHits row is kept by default (the same as the error log, decision 0043). */
if (!defined('CUERCODE_URL_REPUTATION_HITS_RETENTION_DAYS_DEFAULT')) {
    define('CUERCODE_URL_REPUTATION_HITS_RETENTION_DAYS_DEFAULT', 90);
}

// ==============================================================================
// 📋 PURE: the providers
// ==============================================================================

/**
 * 📋 Every link-safety provider CueRCode knows, in no particular order (the
 *    setting link_safety_providers gives the order). A second provider
 *    (decision 0035) is one more entry here and one more "ask" function with
 *    the same parameters and answer as urlReputationAskWebRisk(). PURE.
 *
 * @return array<string, array{name: string, keySetting: string, ask: string}>
 */
function urlReputationProviders(): array
{
    return [
        'webrisk' => [
            'name'       => 'Google Web Risk',
            'keySetting' => 'link_safety_webrisk_api_key',
            'ask'        => 'urlReputationAskWebRisk',
        ],
    ];
}

/**
 * 📋 Read the admin's list of providers ("webrisk", later "webrisk, other").
 *    Known names only, each once, in the order given; lower case; commas or
 *    spaces between them. An empty list is refused: decision 0029 rules out
 *    dynamic codes with no automatic check. PURE.
 *
 * @param string $text
 * @return array{ok: bool, providers: array<int, string>, unknown: array<int, string>, error: string}
 */
function urlReputationParseProviderList(string $text): array
{
    $known   = urlReputationProviders();
    $chosen  = [];
    $unknown = [];

    $words = preg_split('/[\s,]+/', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words)) {
        $words = [];
    }

    foreach ($words as $word) {
        if (!array_key_exists($word, $known)) {
            $unknown[] = $word;
            continue;
        }
        if (!in_array($word, $chosen, true)) {
            $chosen[] = $word;
        }
    }

    if ($unknown !== []) {
        return ['ok' => false, 'providers' => $chosen, 'unknown' => $unknown, 'error' => 'Not a known link-safety provider: ' . implode(', ', array_slice($unknown, 0, 3)) . '. Known so far: ' . implode(', ', array_keys($known)) . '.'];
    }

    if ($chosen === []) {
        return ['ok' => false, 'providers' => [], 'unknown' => [], 'error' => 'Name at least one link-safety provider (known so far: ' . implode(', ', array_keys($known)) . '). Dynamic codes may not run with no automatic check (decision 0029).'];
    }

    return ['ok' => true, 'providers' => $chosen, 'unknown' => [], 'error' => ''];
}

/**
 * 🔑 Could this key go into a request header? Visible ASCII only, at most 256
 *    characters, so it can never add a header line. PURE.
 *
 * @param string $key
 * @return bool
 */
function urlReputationKeyIsUsable(#[\SensitiveParameter] string $key): bool
{
    return preg_match('/^[\x21-\x7E]{1,256}$/D', $key) === 1;
}

// ==============================================================================
// ⚙️ The settings
// ==============================================================================

/**
 * ⚙️ Read the link-safety settings (Global Admin settings, /admin/settings/):
 *
 *   link_safety_providers          - which providers, in order ("webrisk")
 *   link_safety_webrisk_api_key    - the Google key (stored encrypted)
 *   link_safety_timeout_seconds    - 2 to 15, anything else means 5
 *
 * A list the code cannot read counts as Web Risk alone (the seeded value):
 * a typo must never switch the check off.
 *
 * @param callable|null $configGetter Stand-in for getConfig() (tests); null = the site's
 * @param callable|null $storedState  Stand-in for configStoredValueState() (tests); null = the site's
 * @return array{providers: array<string, array{key: string, unreadable: bool}>, timeout: int, listProblem: string}
 */
function urlReputationReadSettings(?callable $configGetter = null, ?callable $storedState = null): array
{
    if ($configGetter === null) {
        $configGetter = 'humanCheckSiteConfig';
    }
    if ($storedState === null) {
        $storedState = 'humanCheckSiteStoredState';
    }

    $parsed      = urlReputationParseProviderList(humanCheckText($configGetter('link_safety_providers', 'webrisk')));
    $names       = $parsed['providers'];
    $listProblem = '';
    if ($parsed['ok'] !== true) {
        $listProblem = $parsed['error'];
        $names       = ['webrisk'];
    }

    $known     = urlReputationProviders();
    $providers = [];
    foreach ($names as $name) {
        $key = humanCheckReadKey($configGetter, $storedState, $known[$name]['keySetting']);
        $providers[$name] = ['key' => $key['value'], 'unreadable' => $key['unreadable']];
    }

    // ⏱️ A whole number of seconds within the bounds, otherwise the default.
    $timeout    = CUERCODE_URL_REPUTATION_DEFAULT_TIMEOUT;
    $timeoutRaw = trim(humanCheckText($configGetter(CUERCODE_SETTING_LINK_SAFETY_TIMEOUT, (string) CUERCODE_URL_REPUTATION_DEFAULT_TIMEOUT)));
    if (preg_match('/^[0-9]{1,3}$/D', $timeoutRaw) === 1) {
        $timeoutNumber = (int) $timeoutRaw;
        if ($timeoutNumber >= CUERCODE_URL_REPUTATION_MIN_TIMEOUT && $timeoutNumber <= CUERCODE_URL_REPUTATION_MAX_TIMEOUT) {
            $timeout = $timeoutNumber;
        }
    }

    return ['providers' => $providers, 'timeout' => $timeout, 'listProblem' => $listProblem];
}

/**
 * ✅ Does at least one provider in the list have a key it can use? (The admin
 *    switch for dynamic codes needs this, decision 0029.) PURE given settings.
 *
 * @param array $settings From urlReputationReadSettings()
 * @return bool
 */
function urlReputationHasUsableProvider(#[\SensitiveParameter] array $settings): bool
{
    if (!isset($settings['providers']) || !is_array($settings['providers'])) {
        return false;
    }

    foreach ($settings['providers'] as $provider) {
        if ($provider['unreadable'] !== true && urlReputationKeyIsUsable((string) $provider['key'])) {
            return true;
        }
    }

    return false;
}

// ==============================================================================
// 🧠 PURE: Google Web Risk's request and answer
// ==============================================================================

/**
 * 🧱 The uris.search request. The key goes ONLY in the x-goog-api-key header
 *    (never in the address, Google's own advice). PURE.
 *
 * @param string $key      The Web Risk key
 * @param string $url      The destination asked about
 * @param string $endpoint The API address (the tests' stand-in, or the real one)
 * @return array{method: string, url: string, headers: array<int, string>, body: null}
 */
function urlReputationWebRiskRequest(#[\SensitiveParameter] string $key, string $url, string $endpoint): array
{
    $query = [];
    foreach (CUERCODE_URL_REPUTATION_WEBRISK_THREAT_TYPES as $threatType) {
        $query[] = 'threatTypes=' . rawurlencode($threatType);
    }
    $query[] = 'uri=' . rawurlencode($url);

    return [
        'method'  => 'GET',
        'url'     => $endpoint . '?' . implode('&', $query),
        'headers' => [
            'X-goog-api-key: ' . $key,
            'Accept: application/json',
            'User-Agent: CueRCode-link-safety/1.0',
        ],
        'body'    => null,
    ];
}

/**
 * ⏳ Until when an "unsafe" answer is kept: the provider's expireTime (RFC
 *    3339, UTC, "Z", up to nine decimals), never in the past and never more
 *    than a day ahead; 5 minutes when it gave none that can be read. PURE.
 *
 * @param mixed $expireTime
 * @param int   $now        Unix time
 * @return int Unix time
 */
function urlReputationExpiry(mixed $expireTime, int $now): int
{
    $fallback = $now + CUERCODE_URL_REPUTATION_DEFAULT_CACHE_SECONDS;

    if (!is_string($expireTime)) {
        return $fallback;
    }

    $parts = [];
    if (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})T([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.[0-9]{1,9})?Z$/D', $expireTime, $parts) !== 1) {
        return $fallback;
    }

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $parts[1] . ' ' . $parts[2], new \DateTimeZone('UTC'));
    if ($parsed === false) {
        return $fallback;
    }

    $until = $parsed->getTimestamp();
    if ($until <= $now) {
        return $now;
    }

    return min($until, $now + CUERCODE_URL_REPUTATION_MAX_CACHE_SECONDS);
}

/**
 * 🧠 What one Web Risk reply means. PURE.
 *
 *   verdict: safe | unsafe | unknown
 *   reason (unknown only): unreachable, timeout, tls_failed, key_refused
 *     (401/403: a wrong key, the API not switched on, billing), quota_exceeded
 *     (429), bad_request (400), unavailable (5xx), bad_reply (anything else,
 *     including a redirect, which is never followed, and a reply too large)
 *
 * @param array $reply A transport reply, already through go2mylinkNormaliseReply()
 * @param int   $now   Unix time
 * @return array{verdict: string, reason: string, threatTypes: array<int, string>, expiresAt: int}
 */
function urlReputationWebRiskVerdict(array $reply, int $now): array
{
    $unknown = static function (string $reason): array {
        return ['verdict' => 'unknown', 'reason' => $reason, 'threatTypes' => [], 'expiresAt' => 0];
    };

    if ($reply['ok'] !== true) {
        if ($reply['tooLarge'] === true) {
            return $unknown('bad_reply');
        }
        $transport = go2mylinkTransportReason((int) $reply['errno'], (bool) $reply['sent']);
        if (in_array($transport['reason'], ['unreachable', 'timeout', 'tls_failed'], true)) {
            return $unknown($transport['reason']);
        }
        return $unknown('bad_reply');
    }

    if ($reply['tooLarge'] === true) {
        return $unknown('bad_reply');
    }

    $status = (int) $reply['status'];
    if ($status === 400) {
        return $unknown('bad_request');
    }
    if ($status === 401 || $status === 403) {
        return $unknown('key_refused');
    }
    if ($status === 429) {
        return $unknown('quota_exceeded');
    }
    if ($status >= 500 && $status <= 599) {
        return $unknown('unavailable');
    }
    if ($status !== 200) {
        return $unknown('bad_reply');
    }

    // 🧾 A JSON object: {} or {"threat": {...}}. (json_decode() turns both
    //    {} and [] into an empty array, so the first character is checked.)
    $body = trim((string) $reply['body']);
    if (!str_starts_with($body, '{')) {
        return $unknown('bad_reply');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $unknown('bad_reply');
    }

    if (!array_key_exists('threat', $decoded)) {
        return ['verdict' => 'safe', 'reason' => '', 'threatTypes' => [], 'expiresAt' => 0];
    }
    if (!is_array($decoded['threat'])) {
        return $unknown('bad_reply');
    }

    // 📌 "This might be empty if the URI is on no list" (Google's reference).
    if (!array_key_exists('threatTypes', $decoded['threat'])) {
        return ['verdict' => 'safe', 'reason' => '', 'threatTypes' => [], 'expiresAt' => 0];
    }
    if (!is_array($decoded['threat']['threatTypes'])) {
        return $unknown('bad_reply');
    }

    $types = [];
    foreach ($decoded['threat']['threatTypes'] as $type) {
        if (!is_string($type) || preg_match('/^[A-Z_]{1,64}$/D', $type) !== 1) {
            return $unknown('bad_reply');
        }
        if (!in_array($type, $types, true)) {
            $types[] = $type;
        }
    }

    if ($types === []) {
        return ['verdict' => 'safe', 'reason' => '', 'threatTypes' => [], 'expiresAt' => 0];
    }

    $expireTime = null;
    if (array_key_exists('expireTime', $decoded['threat'])) {
        $expireTime = $decoded['threat']['expireTime'];
    }

    return ['verdict' => 'unsafe', 'reason' => 'listed', 'threatTypes' => $types, 'expiresAt' => urlReputationExpiry($expireTime, $now)];
}

/**
 * 🗣️ A reason word in plain words for an admin (the error log, the settings
 *    page, the daily email). Never the provider's own text. PURE.
 *
 * @param string $reason
 * @return string
 */
function urlReputationReasonText(string $reason): string
{
    $texts = [
        'not_configured' => 'no link-safety provider has a key saved, so destinations are not being checked',
        'key_unreadable' => 'the saved key cannot be read (the site\'s encryption key may have changed); enter it again',
        'list_unreadable'=> 'the list of providers could not be read, so Google Web Risk alone was used',
        'unreachable'    => 'it could not be reached',
        'timeout'        => 'it did not answer in time, or the connection dropped',
        'tls_failed'     => 'its security certificate could not be checked, so nothing was sent',
        'key_refused'    => 'it refused the key (a wrong key, the API not switched on in the Google Cloud project, or billing)',
        'quota_exceeded' => 'its quota for this key is used up for now',
        'bad_request'    => 'it refused the request as not valid',
        'unavailable'    => 'it answered that it is not working just now',
        'bad_reply'      => 'it gave an answer CueRCode could not read (not the usual shape, too large, or a redirect)',
    ];

    if (isset($texts[$reason])) {
        return $texts[$reason];
    }

    return 'it could not answer';
}

// ==============================================================================
// 🌐 Asking a provider
// ==============================================================================

/**
 * 🌐 Ask Google Web Risk about one address. Never throws.
 *
 * @param string               $url     The destination
 * @param string               $key     The Web Risk key
 * @param int                  $timeout Seconds
 * @param array<string, mixed> $options 'transport' (callable(request, timeout): reply), 'caFile',
 *                                      'endpoint' (tests only: the stand-in server's https address), 'now'
 * @return array{verdict: string, reason: string, threatTypes: array<int, string>, expiresAt: int}
 */
function urlReputationAskWebRisk(string $url, #[\SensitiveParameter] string $key, int $timeout, #[\SensitiveParameter] array $options = []): array
{
    $endpoint = CUERCODE_URL_REPUTATION_WEBRISK_ENDPOINT;
    if (isset($options['endpoint']) && is_string($options['endpoint']) && str_starts_with($options['endpoint'], 'https://')) {
        $endpoint = $options['endpoint'];
    }
    $caFile = '';
    if (isset($options['caFile']) && is_string($options['caFile'])) {
        $caFile = $options['caFile'];
    }
    $now = time();
    if (isset($options['now']) && is_int($options['now'])) {
        $now = $options['now'];
    }

    $request = urlReputationWebRiskRequest($key, $url, $endpoint);

    try {
        if (isset($options['transport']) && is_callable($options['transport'])) {
            $reply = $options['transport']($request, $timeout);
        } else {
            $reply = go2mylinkHttpTransport($request, $timeout, $caFile);
        }
    } catch (\Throwable $e) {
        $reply = ['ok' => false, 'errno' => 7, 'sent' => false];
    }

    // 🧹 The request (with the key in it) is not kept.
    $request = [];

    return urlReputationWebRiskVerdict(go2mylinkNormaliseReply($reply), $now);
}

// ==============================================================================
// 🛡️ urlReputationCheck() - the whole check
// ==============================================================================

/**
 * 🛡️ Is this destination safe to send people to? Asks every provider in the
 *    list that has a key, in order (see the file header for what each answer
 *    means). Never throws.
 *
 * @param string               $url      The destination, already through validateRedirectDestination()
 * @param string               $occasion create, change, restore or recheck (recorded with a hit)
 * @param array<string, mixed> $options  For tests and tools: 'settings' (from urlReputationReadSettings()),
 *                                       'transport', 'caFile', 'endpoint', 'now', 'logger' (callable(string)),
 *                                       'logDue' (callable(key, seconds): bool), 'cache' (callable(url): ?array),
 *                                       'recorder' (callable(url, verdict, occasion, context): bool);
 *                                       and 'redirectID' / 'userID' (recorded with a hit)
 * @return array{allowed: bool, verdict: string, reason: string, provider: string, threatTypes: array<int, string>, expiresAt: int, fromCache: bool}
 */
function urlReputationCheck(string $url, string $occasion, #[\SensitiveParameter] array $options = []): array
{
    $result = ['allowed' => true, 'verdict' => 'unchecked', 'reason' => '', 'provider' => '', 'threatTypes' => [], 'expiresAt' => 0, 'fromCache' => false];

    if (isset($options['settings']) && is_array($options['settings'])) {
        $settings = array_merge(['providers' => [], 'timeout' => CUERCODE_URL_REPUTATION_DEFAULT_TIMEOUT, 'listProblem' => ''], $options['settings']);
    } else {
        $settings = urlReputationReadSettings();
    }

    // 🗄️ 1. Google's caching rule: an address listed earlier stays unsafe
    //    until its expiry, without asking again (and even with no key now).
    $cache = 'urlReputationCachedHit';
    if (isset($options['cache']) && is_callable($options['cache'])) {
        $cache = $options['cache'];
    }
    $cached = null;
    try {
        $cached = $cache($url);
    } catch (\Throwable $e) {
        $cached = null;
    }
    if (is_array($cached)) {
        $result['allowed']     = false;
        $result['verdict']     = 'unsafe';
        $result['reason']      = 'listed';
        $result['provider']    = (string) $cached['provider'];
        $result['threatTypes'] = $cached['threatTypes'];
        $result['expiresAt']   = (int) $cached['expiresAt'];
        $result['fromCache']   = true;
        return $result;
    }

    if ($settings['listProblem'] !== '') {
        urlReputationLogBounded($options, 'list', 'list_unreadable', $occasion);
    }

    // 🔑 2. Only providers with a key that can be used are asked.
    $known  = urlReputationProviders();
    $usable = [];
    $unreadable = false;
    foreach ($settings['providers'] as $name => $provider) {
        if (!isset($known[$name])) {
            continue;
        }
        if ($provider['unreadable'] === true) {
            $unreadable = true;
            continue;
        }
        if (urlReputationKeyIsUsable((string) $provider['key'])) {
            $usable[$name] = (string) $provider['key'];
        }
    }

    if ($usable === []) {
        $result['reason'] = 'not_configured';
        if ($unreadable) {
            $result['reason'] = 'key_unreadable';
        }
        urlReputationLogBounded($options, 'all', $result['reason'], $occasion);
        return $result;
    }

    // 🌐 3. Ask each, in order. One "unsafe" is enough to refuse.
    $problems = [];
    foreach ($usable as $name => $key) {
        $ask = $known[$name]['ask'];
        $answer = $ask($url, $key, (int) $settings['timeout'], $options);

        if ($answer['verdict'] === 'unsafe') {
            $result['allowed']     = false;
            $result['verdict']     = 'unsafe';
            $result['reason']      = 'listed';
            $result['provider']    = $name;
            $result['threatTypes'] = $answer['threatTypes'];
            $result['expiresAt']   = $answer['expiresAt'];
            urlReputationRecord($options, $url, $result, $occasion);
            return $result;
        }

        if ($answer['verdict'] !== 'safe') {
            $problems[$name] = $answer['reason'];
        }
    }

    // ✅ 4. Safe only when EVERY provider asked said so.
    if ($problems === []) {
        $result['verdict'] = 'safe';
        return $result;
    }

    $result['provider'] = (string) array_key_first($problems);
    $result['reason']   = (string) $problems[$result['provider']];
    foreach ($problems as $name => $reason) {
        urlReputationLogBounded($options, $name, $reason, $occasion);
    }

    return $result;
}

/**
 * 🧾 Write one plain log line about a provider that could not answer, at most
 *    once every 10 minutes per provider and reason (bounded: a long outage
 *    cannot fill the error log). Never the key, never the address asked about.
 *
 * @param array<string, mixed> $options  'logger' and 'logDue' (tests)
 * @param string               $provider A provider name, 'all' or 'list'
 * @param string               $reason   A reason word
 * @param string               $occasion create, change, restore or recheck
 * @return void
 */
function urlReputationLogBounded(#[\SensitiveParameter] array $options, string $provider, string $reason, string $occasion): void
{
    $logDue = 'humanCheckStateLogDue';
    if (isset($options['logDue']) && is_callable($options['logDue'])) {
        $logDue = $options['logDue'];
    }

    $due = false;
    try {
        $due = ($logDue('log:linksafety:' . substr($provider . ':' . $reason, 0, 48), CUERCODE_URL_REPUTATION_LOG_INTERVAL_SECONDS) === true);
    } catch (\Throwable $e) {
        $due = false;
    }
    if (!$due) {
        return;
    }

    $name = 'the link-safety check';
    $known = urlReputationProviders();
    if (isset($known[$provider])) {
        $name = $known[$provider]['name'];
    }

    $line = 'Link-safety check (' . $occasion . '): ' . $name . ' - ' . urlReputationReasonText($reason)
        . '. The destination was let through unchecked; the daily job checks every active code again. (Written at most once every 10 minutes.)';

    if (isset($options['logger']) && is_callable($options['logger'])) {
        $options['logger']($line);
        return;
    }

    if (function_exists('logError')) {
        logError('warning', 'Link-safety check could not answer', $line);
    }
}

/**
 * 📋 Record an "unsafe" answer (tblLinkSafetyHits), through a test's stand-in
 *    when given. Never throws; a failure is logged, never stops the refusal.
 *
 * @param array<string, mixed> $options
 * @param string               $url
 * @param array                $verdict From urlReputationCheck()
 * @param string               $occasion
 * @return void
 */
function urlReputationRecord(#[\SensitiveParameter] array $options, string $url, array $verdict, string $occasion): void
{
    $context = ['redirectID' => null, 'userID' => null];
    foreach (['redirectID', 'userID'] as $contextKey) {
        if (isset($options[$contextKey]) && is_int($options[$contextKey]) && $options[$contextKey] > 0) {
            $context[$contextKey] = $options[$contextKey];
        }
    }

    try {
        if (isset($options['recorder']) && is_callable($options['recorder'])) {
            $options['recorder']($url, $verdict, $occasion, $context);
            return;
        }
        urlReputationRecordHit($url, $verdict, $occasion, $context['redirectID'], $context['userID']);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'A link-safety hit could not be recorded', get_class($e));
        }
    }
}

// ==============================================================================
// 🗄️ tblLinkSafetyHits (upgrade 0008): the record and Google's cache
// ==============================================================================

/**
 * 🔎 Does tblLinkSafetyHits exist yet (upgrade 0008)? Remembered for the
 *    rest of the request once it does.
 *
 * @return bool
 */
function urlReputationHitsTableReady(): bool
{
    static $ready = false;

    if ($ready) {
        return true;
    }
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `found` FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            's',
            ['tblLinkSafetyHits']
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return false;
    }

    $ready = (is_array($row) && (int) $row['found'] === 1);

    return $ready;
}

/**
 * 📋 Write one "unsafe" answer.
 *
 * @param string   $url
 * @param array    $verdict    From urlReputationCheck() (provider, threatTypes, expiresAt)
 * @param string   $occasion   create, change, restore or recheck
 * @param int|null $redirectId The code, when there is one
 * @param int|null $userId     Who asked, when known
 * @return bool True when the row was written
 */
function urlReputationRecordHit(string $url, array $verdict, string $occasion, ?int $redirectId, ?int $userId): bool
{
    if (!urlReputationHitsTableReady()) {
        return false;
    }

    $expiresAt = (int) $verdict['expiresAt'];
    if ($expiresAt <= 0) {
        $expiresAt = time() + CUERCODE_URL_REPUTATION_DEFAULT_CACHE_SECONDS;
    }

    $stmt = dbQuery(
        'INSERT INTO `tblLinkSafetyHits` (`urlHash`, `destinationURL`, `provider`, `threatTypes`, `occasion`, `redirectID`, `userID`, `expiresAt`, `createdAt`) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        'sssssiis',
        [
            hash('sha256', $url),
            substr($url, 0, 2048),
            substr((string) $verdict['provider'], 0, 32),
            substr(implode(',', $verdict['threatTypes']), 0, 255),
            substr($occasion, 0, 16),
            $redirectId,
            $userId,
            gmdate('Y-m-d H:i:s', $expiresAt),
        ]
    );
    $written = ($stmt->affected_rows === 1);
    $stmt->close();

    return $written;
}

/**
 * 🗄️ An unexpired "unsafe" answer for exactly this address, or null.
 *
 * @param string $url
 * @return array{provider: string, threatTypes: array<int, string>, expiresAt: int}|null
 */
function urlReputationCachedHit(string $url): ?array
{
    if (!urlReputationHitsTableReady()) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `provider`, `threatTypes`, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), `expiresAt`) AS `secondsLeft` FROM `tblLinkSafetyHits` '
            . 'WHERE `urlHash` = ? AND `expiresAt` > UTC_TIMESTAMP() ORDER BY `expiresAt` DESC LIMIT 1',
            's',
            [hash('sha256', $url)]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    $types = preg_split('/,/', (string) $row['threatTypes'], -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($types)) {
        $types = [];
    }

    return ['provider' => (string) $row['provider'], 'threatTypes' => $types, 'expiresAt' => time() + max(0, (int) $row['secondsLeft'])];
}

/**
 * 🧹 Delete hits older than the keeping period (an admin setting, 90 days by
 *    default; never one that is still unexpired), at most $limit a run.
 *
 * @param int $olderThanDays
 * @param int $limit
 * @return int|null How many were deleted; null on a database problem
 */
function urlReputationPurgeOldHits(int $olderThanDays, int $limit): ?int
{
    if (!urlReputationHitsTableReady()) {
        return 0;
    }

    try {
        $stmt = dbQuery(
            'DELETE FROM `tblLinkSafetyHits` WHERE `createdAt` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) AND `expiresAt` < UTC_TIMESTAMP() ORDER BY `hitID` LIMIT ?',
            'ii',
            [$olderThanDays, $limit]
        );
        $deleted = (int) $stmt->affected_rows;
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Old link-safety hits could not be deleted', get_class($e));
        }
        return null;
    }

    return $deleted;
}

/**
 * 📅 The keeping period for hits: the admin setting when it is a whole number
 *    1-3650, otherwise 90 days.
 *
 * @param callable|null $configGetter Stand-in for getConfig() (tests)
 * @return int
 */
function urlReputationHitsRetentionDays(?callable $configGetter = null): int
{
    if ($configGetter === null) {
        $configGetter = 'humanCheckSiteConfig';
    }

    $raw = trim(humanCheckText($configGetter(CUERCODE_SETTING_LINK_SAFETY_HITS_RETENTION_DAYS, (string) CUERCODE_URL_REPUTATION_HITS_RETENTION_DAYS_DEFAULT)));
    if (preg_match('/^[1-9][0-9]{0,3}$/D', $raw) === 1 && (int) $raw <= 3650) {
        return (int) $raw;
    }

    return CUERCODE_URL_REPUTATION_HITS_RETENTION_DAYS_DEFAULT;
}
