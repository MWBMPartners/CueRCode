<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the link-safety check (WP4e, #168)
 * ==============================================================================
 *
 * Path: tests/test_url_reputation.php
 *
 * Covers _functions/fn_url_reputation.php and the one hook in fn_redirects.php
 * WITHOUT Google and without the internet:
 *
 *   1. Loading defines functions only.
 *   2. The list of providers (decision 0035): known names only, in order,
 *      never empty (decision 0029).
 *   3. Reading the settings: defaults, the waiting-time bounds, a key that
 *      cannot be read, a list that cannot be read (Web Risk is still used).
 *   4. The request Google's documentation describes: GET uris:search with the
 *      address and the three lists; the key ONLY in the x-goog-api-key header.
 *   5. Every answer mapped: {} safe, a threat unsafe, an empty threat safe,
 *      400 / 401 / 403 / 429 / 5xx / 302 / a web page / a reply too large /
 *      no connection / a time limit / a bad certificate - all "unknown".
 *   6. Google's expiry time read, never in the past, never more than a day.
 *   7. The whole check: unsafe refuses and is recorded (with the occasion,
 *      the code and the person); unreachable, quota exceeded and no key let
 *      it through ("unchecked") and are logged ONCE (bounded); an address
 *      listed earlier is unsafe without asking again (Google's cache rule);
 *      the key never appears in any log line.
 *   8. The hook dynamicQrCheckDestinationSafety(): its answer and message.
 *   9. REAL HTTPS against tests/lib/webrisk_standin_server.php on 127.0.0.1
 *      with a throwaway certificate: safe, unsafe, 403, 429, 500, a time
 *      limit, a bad certificate (nothing reaches the server), a web page, a
 *      reply too large, a redirect that is not followed; the key arrives in
 *      the header and never in the address; nothing compressed is asked for.
 *  10. Source guards.
 *
 * House rules: every variable is prefixed $ur; nothing here defines dbQuery(),
 * getConfig(), logError() or logActivity().
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#168)
 * @see         web/CueRCode.net/_functions/fn_url_reputation.php
 * @see         tests/lib/webrisk_standin_server.php
 */

requireFn('fn_url_reputation.php');
requireFn('fn_redirects.php');

// ==============================================================================
// ✅ Section 1: loading
// ==============================================================================
foreach (['dbQuery', 'getDB', 'getConfig', 'logError', 'logActivity'] as $urForbidden) {
    assertFalse(function_exists($urForbidden), 'loading fn_url_reputation.php does not define ' . $urForbidden . '()');
}
assertEquals('https://webrisk.googleapis.com/v1/uris:search', CUERCODE_URL_REPUTATION_WEBRISK_ENDPOINT, 'the Web Risk Lookup API address from Google\'s reference (uris.search, v1)');
assertEquals(['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE'], CUERCODE_URL_REPUTATION_WEBRISK_THREAT_TYPES, 'the three Web Risk lists asked about');
assertEquals([2, 5, 15], [CUERCODE_URL_REPUTATION_MIN_TIMEOUT, CUERCODE_URL_REPUTATION_DEFAULT_TIMEOUT, CUERCODE_URL_REPUTATION_MAX_TIMEOUT], 'waiting time: 2 to 15 seconds, 5 by default');

// ==============================================================================
// ✅ Section 2: the list of providers
// ==============================================================================
assertEquals(['webrisk'], array_keys(urlReputationProviders()), 'decision 0035: Web Risk is the one provider for now, in a list a second can join');
$urParsed = urlReputationParseProviderList('webrisk');
assertTrue($urParsed['ok'] && $urParsed['providers'] === ['webrisk'], 'the list "webrisk" is read');
$urParsed = urlReputationParseProviderList(' WebRisk , webrisk ');
assertTrue($urParsed['ok'] && $urParsed['providers'] === ['webrisk'], 'capitals and repeats are tidied: "webrisk" once');
$urParsed = urlReputationParseProviderList('webrisk, safebrowsing');
assertTrue(!$urParsed['ok'] && $urParsed['unknown'] === ['safebrowsing'] && str_contains($urParsed['error'], 'safebrowsing'), 'an unknown provider is refused by name (decision 0029 rules out Safe Browsing)');
$urParsed = urlReputationParseProviderList('  ');
assertTrue(!$urParsed['ok'] && str_contains($urParsed['error'], '0029'), 'an empty list is refused: decision 0029 rules out dynamic codes with no automatic check');

// ==============================================================================
// ✅ Section 3: the settings
// ==============================================================================
$urConfig = static function (array $urValues): callable {
    return static function (string $urKey, mixed $urDefault) use ($urValues): mixed {
        if (array_key_exists($urKey, $urValues)) {
            return $urValues[$urKey];
        }
        return $urDefault;
    };
};
$urNoRow = static function (string $urKey): string {
    return 'missing';
};
$urKey = 'AIzaSyTest' . str_repeat('K', 29);

$urSettings = urlReputationReadSettings($urConfig([]), $urNoRow);
assertTrue(array_keys($urSettings['providers']) === ['webrisk'] && $urSettings['providers']['webrisk']['key'] === '' && $urSettings['timeout'] === 5, 'defaults: Web Risk, no key, 5 seconds');
assertFalse(urlReputationHasUsableProvider($urSettings), 'with no key there is no usable provider (the admin switch stays off)');

$urSettings = urlReputationReadSettings($urConfig(['link_safety_webrisk_api_key' => $urKey, 'link_safety_timeout_seconds' => '9']), $urNoRow);
assertTrue($urSettings['providers']['webrisk']['key'] === $urKey && $urSettings['timeout'] === 9 && urlReputationHasUsableProvider($urSettings), 'a key and 9 seconds are read; the provider is usable');
foreach (['1', '16', 'x', '', '5.5', '-3'] as $urBadTimeout) {
    $urSettings = urlReputationReadSettings($urConfig(['link_safety_timeout_seconds' => $urBadTimeout]), $urNoRow);
    assertEquals(5, $urSettings['timeout'], 'a waiting time of ' . var_export($urBadTimeout, true) . ' means the default 5');
}
$urSettings = urlReputationReadSettings($urConfig(['link_safety_providers' => 'nonsense']), $urNoRow);
assertTrue(array_keys($urSettings['providers']) === ['webrisk'] && $urSettings['listProblem'] !== '', 'a list that cannot be read still uses Web Risk (a typo never switches the check off), and says so');
$urSettings = urlReputationReadSettings($urConfig([]), static function (string $urKey): string {
    return 'present';
});
assertTrue($urSettings['providers']['webrisk']['unreadable'] === true && !urlReputationHasUsableProvider($urSettings), 'a saved key that cannot be read (ENCRYPTION_KEY changed) is "unreadable", not usable');
assertFalse(urlReputationKeyIsUsable("abc\r\nX-Evil: 1"), 'a key with a line break could add a header line: never used');

// ==============================================================================
// ✅ Section 4: the request
// ==============================================================================
$urRequest = urlReputationWebRiskRequest($urKey, 'https://example.com/a b?x=1&y=2#frag', CUERCODE_URL_REPUTATION_WEBRISK_ENDPOINT);
assertEquals('GET', $urRequest['method'], 'request: GET (Google: "the request body must be empty")');
assertEquals(null, $urRequest['body'], 'request: no body');
assertEquals('https://webrisk.googleapis.com/v1/uris:search?threatTypes=MALWARE&threatTypes=SOCIAL_ENGINEERING&threatTypes=UNWANTED_SOFTWARE&uri=https%3A%2F%2Fexample.com%2Fa%20b%3Fx%3D1%26y%3D2%23frag', $urRequest['url'], 'request: the three lists repeated, and the address fully URL-encoded (Google: "you must encode GET parameters, like the URI")');
assertFalse(str_contains($urRequest['url'], $urKey) || str_contains($urRequest['url'], 'key='), 'request: the key is NOT in the address (Google: the key= parameter exposes it "to theft through URL scans")');
assertTrue(in_array('X-goog-api-key: ' . $urKey, $urRequest['headers'], true), 'request: the key goes in the x-goog-api-key header');
assertTrue(in_array('Accept: application/json', $urRequest['headers'], true), 'request: JSON is asked for');
$urEncodingHeaders = array_filter($urRequest['headers'], static function (string $urHeader): bool {
    return stripos($urHeader, 'Accept-Encoding') === 0;
});
assertEquals([], array_values($urEncodingHeaders), 'request: nothing compressed is asked for (#150)');

// ==============================================================================
// ✅ Section 5: every answer
// ==============================================================================
$urNow   = 1795000000;
$urReply = static function (int $urStatus, string $urBody, array $urExtra = []): array {
    return go2mylinkNormaliseReply(array_merge(['ok' => true, 'status' => $urStatus, 'body' => $urBody, 'errno' => 0, 'sent' => true], $urExtra));
};
$urVerdict = urlReputationWebRiskVerdict($urReply(200, '{}'), $urNow);
assertTrue($urVerdict['verdict'] === 'safe' && $urVerdict['threatTypes'] === [], '{} is safe (Google: "an empty JSON response ... isn\'t on any threat lists")');
$urVerdict = urlReputationWebRiskVerdict($urReply(200, '{"threat": {"threatTypes": ["MALWARE", "SOCIAL_ENGINEERING"], "expireTime": "2026-11-18T12:00:00.045123456Z"}}'), $urNow);
assertTrue($urVerdict['verdict'] === 'unsafe' && $urVerdict['threatTypes'] === ['MALWARE', 'SOCIAL_ENGINEERING'], 'a threat with lists is unsafe, with its lists');
assertEquals(gmmktime(12, 0, 0, 11, 18, 2026), $urVerdict['expiresAt'], '...kept until Google\'s expireTime (nanoseconds dropped)');
assertTrue(urlReputationWebRiskVerdict($urReply(200, '{"threat": {}}'), $urNow)['verdict'] === 'safe', 'an empty threat is safe (Google: "This might be empty if the URI is on no list")');
assertTrue(urlReputationWebRiskVerdict($urReply(200, '{"threat": {"threatTypes": []}}'), $urNow)['verdict'] === 'safe', 'an empty list of lists is safe');
foreach (['[]', '"x"', '{"threat": "x"}', '{"threat": {"threatTypes": "MALWARE"}}', '{"threat": {"threatTypes": [42]}}', '{"threat": {"threatTypes": ["bad type<"]}}', '<!doctype html><p>Sign in</p>', '', '{not json'] as $urOdd) {
    $urVerdict = urlReputationWebRiskVerdict($urReply(200, $urOdd), $urNow);
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === 'bad_reply', 'an unexpected 200 reply is "unknown", never safe: ' . var_export($urOdd, true));
}
foreach ([400 => 'bad_request', 401 => 'key_refused', 403 => 'key_refused', 429 => 'quota_exceeded', 500 => 'unavailable', 503 => 'unavailable', 302 => 'bad_reply', 404 => 'bad_reply'] as $urStatus => $urReason) {
    $urVerdict = urlReputationWebRiskVerdict($urReply($urStatus, '{"error": {"code": ' . $urStatus . ', "status": "X"}}'), $urNow);
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === $urReason, 'status ' . $urStatus . ' is "unknown" (' . $urReason . ')');
}
$urVerdict = urlReputationWebRiskVerdict($urReply(200, '{}', ['tooLarge' => true]), $urNow);
assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === 'bad_reply', 'a reply too large is "unknown"');
foreach ([[7, false, 'unreachable'], [6, false, 'unreachable'], [28, true, 'timeout'], [28, false, 'unreachable'], [60, false, 'tls_failed'], [35, false, 'tls_failed'], [56, true, 'timeout']] as $urCase) {
    $urVerdict = urlReputationWebRiskVerdict(go2mylinkNormaliseReply(['ok' => false, 'errno' => $urCase[0], 'sent' => $urCase[1]]), $urNow);
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === $urCase[2], 'curl error ' . $urCase[0] . ' is "unknown" (' . $urCase[2] . ')');
}

// ==============================================================================
// ✅ Section 6: the expiry
// ==============================================================================
assertEquals($urNow + 300, urlReputationExpiry(null, $urNow), 'no expiry given: 5 minutes');
assertEquals($urNow + 300, urlReputationExpiry('next week', $urNow), 'an expiry that cannot be read: 5 minutes');
assertEquals($urNow, urlReputationExpiry('2001-01-01T00:00:00Z', $urNow), 'an expiry in the past is "now" (never cached)');
assertEquals($urNow + 86400, urlReputationExpiry(gmdate('Y-m-d\TH:i:s\Z', $urNow + 99999999), $urNow), 'an expiry far ahead is cut to one day');
assertEquals($urNow + 120, urlReputationExpiry(gmdate('Y-m-d\TH:i:s', $urNow + 120) . '.5Z', $urNow), 'a normal expiry is used as given');

// ==============================================================================
// ✅ Section 7: the whole check, with a scripted transport
// ==============================================================================
$urLogged  = [];
$urRecords = [];
$urSent    = [];
$urDueKeys = [];
$urCheckOptions = static function (callable $urTransport, array $urExtra = []) use (&$urLogged, &$urRecords, &$urSent, &$urDueKeys, $urKey): array {
    return array_merge([
        'settings'  => ['providers' => ['webrisk' => ['key' => $urKey, 'unreadable' => false]], 'timeout' => 2, 'listProblem' => ''],
        'transport' => static function (array $urRequest, int $urTimeout) use ($urTransport, &$urSent): array {
            $urSent[] = $urRequest;
            return $urTransport($urRequest, $urTimeout);
        },
        'cache'     => static function (string $urUrl): ?array {
            return null;
        },
        'recorder'  => static function (string $urUrl, array $urVerdict, string $urOccasion, array $urContext) use (&$urRecords): bool {
            $urRecords[] = ['url' => $urUrl, 'occasion' => $urOccasion, 'context' => $urContext, 'types' => $urVerdict['threatTypes']];
            return true;
        },
        // 🧾 Bounded logging: a key is "due" only the first time it is seen.
        'logDue'    => static function (string $urDueKey, int $urSeconds) use (&$urDueKeys): bool {
            if (isset($urDueKeys[$urDueKey])) {
                return false;
            }
            $urDueKeys[$urDueKey] = $urSeconds;
            return true;
        },
        'logger'    => static function (string $urLine) use (&$urLogged): void {
            $urLogged[] = $urLine;
        },
    ], $urExtra);
};
$urAnswer = static function (int $urStatus, string $urBody): callable {
    return static function (array $urRequest, int $urTimeout) use ($urStatus, $urBody): array {
        return ['ok' => true, 'status' => $urStatus, 'body' => $urBody, 'errno' => 0, 'sent' => true];
    };
};
$urDown = static function (array $urRequest, int $urTimeout): array {
    return ['ok' => false, 'status' => 0, 'body' => '', 'errno' => 7, 'sent' => false];
};

$urCheck = urlReputationCheck('https://example.com/menu', 'create', $urCheckOptions($urAnswer(200, '{}')));
assertTrue($urCheck['allowed'] === true && $urCheck['verdict'] === 'safe' && count($urSent) === 1, 'safe: allowed, one request');

$urSent = [];
$urCheck = urlReputationCheck('https://evil.example/login', 'change', $urCheckOptions($urAnswer(200, '{"threat": {"threatTypes": ["SOCIAL_ENGINEERING"]}}'), ['redirectID' => 42, 'userID' => 7]));
assertTrue($urCheck['allowed'] === false && $urCheck['verdict'] === 'unsafe' && $urCheck['provider'] === 'webrisk' && $urCheck['threatTypes'] === ['SOCIAL_ENGINEERING'], 'unsafe: REFUSED, with the provider and the list');
assertTrue(count($urRecords) === 1 && $urRecords[0]['url'] === 'https://evil.example/login' && $urRecords[0]['occasion'] === 'change' && $urRecords[0]['context'] === ['redirectID' => 42, 'userID' => 7], 'unsafe: recorded once, with the occasion, the code and the person');
assertEquals([], $urLogged, 'unsafe: nothing is logged as a problem (it is a hit, recorded instead)');

foreach ([['down', $urDown, 'unreachable'], ['quota', $urAnswer(429, '{"error": {"code": 429, "status": "RESOURCE_EXHAUSTED"}}'), 'quota_exceeded'], ['denied', $urAnswer(403, '{}'), 'key_refused'], ['broken', $urAnswer(500, '{}'), 'unavailable'], ['odd', $urAnswer(200, '<html>'), 'bad_reply']] as $urCase) {
    $urLogged = [];
    $urCheck = urlReputationCheck('https://example.com/menu', 'create', $urCheckOptions($urCase[1]));
    assertTrue($urCheck['allowed'] === true && $urCheck['verdict'] === 'unchecked' && $urCheck['reason'] === $urCase[2], $urCase[0] . ': LET THROUGH unchecked (design 2.3, fail-open): ' . $urCheck['reason']);
    assertTrue(count($urLogged) === 1 && str_contains($urLogged[0], 'let through unchecked'), $urCase[0] . ': logged once, in plain words');
    $urLogged = [];
    urlReputationCheck('https://example.com/other', 'create', $urCheckOptions($urCase[1]));
    assertEquals([], $urLogged, $urCase[0] . ': the same problem again is NOT logged again (bounded: once every ' . CUERCODE_URL_REPUTATION_LOG_INTERVAL_SECONDS . ' seconds per provider and reason)');
}
assertTrue(in_array(CUERCODE_URL_REPUTATION_LOG_INTERVAL_SECONDS, $urDueKeys, true) && isset($urDueKeys['log:linksafety:webrisk:unreachable']), 'the bound is asked for per provider and reason, for 10 minutes');

$urLogged = [];
$urSent   = [];
$urCheck = urlReputationCheck('https://example.com/menu', 'create', $urCheckOptions($urAnswer(200, '{}'), ['settings' => ['providers' => ['webrisk' => ['key' => '', 'unreadable' => false]], 'timeout' => 2, 'listProblem' => '']]));
assertTrue($urCheck['allowed'] === true && $urCheck['verdict'] === 'unchecked' && $urCheck['reason'] === 'not_configured' && $urSent === [], 'no key: let through unchecked, nothing sent');
assertTrue(count($urLogged) === 1 && str_contains($urLogged[0], 'no link-safety provider has a key'), 'no key: logged (bounded) so an admin sees destinations are not checked');
$urCheck = urlReputationCheck('https://example.com/menu', 'create', $urCheckOptions($urAnswer(200, '{}'), ['settings' => ['providers' => ['webrisk' => ['key' => '', 'unreadable' => true]], 'timeout' => 2, 'listProblem' => '']]));
assertTrue($urCheck['allowed'] === true && $urCheck['reason'] === 'key_unreadable' && $urSent === [], 'a key that cannot be read: let through, nothing sent, reason key_unreadable');

$urSent = [];
$urCheck = urlReputationCheck('https://evil.example/login', 'create', $urCheckOptions($urAnswer(200, '{}'), ['cache' => static function (string $urUrl): ?array {
    return ['provider' => 'webrisk', 'threatTypes' => ['MALWARE'], 'expiresAt' => 1795000300];
}]));
assertTrue($urCheck['allowed'] === false && $urCheck['fromCache'] === true && $urSent === [], 'Google\'s caching rule: an address listed earlier is unsafe until its expiry, WITHOUT asking again');
$urCheck = urlReputationCheck('https://evil.example/login', 'create', $urCheckOptions($urAnswer(200, '{}'), ['cache' => static function (string $urUrl): ?array {
    return ['provider' => 'webrisk', 'threatTypes' => ['MALWARE'], 'expiresAt' => 1795000300];
}, 'settings' => ['providers' => ['webrisk' => ['key' => '', 'unreadable' => false]], 'timeout' => 2, 'listProblem' => '']]));
assertTrue($urCheck['allowed'] === false, '...even when no key is saved any more');

assertFalse(str_contains(implode("\n", $urLogged), $urKey), 'no log line contains the key');
foreach ($urSent as $urRequest) {
    assertFalse(str_contains((string) $urRequest['url'], $urKey), 'no request address contains the key');
}

// ==============================================================================
// ✅ Section 8: the hook
// ==============================================================================
$urRecords = [];
$urHook = dynamicQrCheckDestinationSafety('https://evil.example/login', 'create', $urCheckOptions($urAnswer(200, '{"threat": {"threatTypes": ["MALWARE"]}}')));
assertTrue($urHook['allowed'] === false && $urHook['verdict'] === 'unsafe' && str_contains($urHook['message'], 'Google Web Risk'), 'the hook: unsafe is not allowed, and says which service listed it');
$urHook = dynamicQrCheckDestinationSafety('https://example.com/menu', 'change', $urCheckOptions($urDown));
assertTrue($urHook['allowed'] === true && $urHook['verdict'] === 'unchecked' && $urHook['message'] === '', 'the hook: a provider that cannot answer lets it through');
$urHook = dynamicQrCheckDestinationSafety('https://example.com/menu', 'create');
assertTrue($urHook['allowed'] === true && $urHook['reason'] === 'not_configured', 'the hook with the site\'s own settings and no key saved: let through, "not_configured" (never a call to Google from a test)');

// ==============================================================================
// ✅ Section 9: REAL HTTPS against the stand-in server
// ==============================================================================
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'test_tls.php';

$urDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_wr_' . bin2hex(random_bytes(6));
mkdir($urDir, 0700);
$urTls = testTlsMakeCertificates($urDir);
assertTrue($urTls['ok'], 'a throwaway certificate authority and server certificate were made: ' . $urTls['error']);

$urLogFile = $urDir . DIRECTORY_SEPARATOR . 'requests.log';
touch($urLogFile);
$urPipes   = [];
$urProcess = proc_open(
    [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'webrisk_standin_server.php', $urTls['certFile'], $urTls['keyFile'], $urLogFile, $urKey],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $urPipes
);
$urPort = 0;
if (is_resource($urProcess)) {
    stream_set_timeout($urPipes[1], 10);
    $urFirstLine = (string) fgets($urPipes[1]);
    if (preg_match('/^PORT (\d+)/', $urFirstLine, $urPortMatch) === 1) {
        $urPort = (int) $urPortMatch[1];
    }
}
assertTrue($urPort > 0, 'the stand-in Web Risk server is listening on 127.0.0.1 (port ' . $urPort . ')');

$urServerSaw = static function () use ($urLogFile): array {
    $urOut = [];
    foreach (file($urLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $urLine) {
        $urOut[] = json_decode($urLine, true);
    }
    file_put_contents($urLogFile, '');
    return $urOut;
};
$urReal = static function (string $urScenario, array $urExtra = []) use ($urPort, $urTls): array {
    return array_merge(['endpoint' => 'https://127.0.0.1:' . $urPort . '/' . $urScenario . '/v1/uris:search', 'caFile' => $urTls['caFile']], $urExtra);
};

if ($urPort > 0) {
    $urServerSaw();
    $urVerdict = urlReputationAskWebRisk('https://example.com/menu?a=1&b=2', $urKey, 2, $urReal('safe'));
    assertTrue($urVerdict['verdict'] === 'safe', 'real HTTPS: {} is safe: ' . $urVerdict['reason']);
    $urSaw = $urServerSaw();
    assertTrue(count($urSaw) === 1 && $urSaw[0]['method'] === 'GET' && $urSaw[0]['route'] === 'v1/uris:search', 'real HTTPS: one GET to /v1/uris:search');
    assertEquals('ok', $urSaw[0]['keyHeader'], 'real HTTPS: the key arrived in the x-goog-api-key header');
    assertFalse($urSaw[0]['keyInUrl'], 'real HTTPS: the key was NOT in the address');
    assertEquals('https://example.com/menu?a=1&b=2', $urSaw[0]['uri'], 'real HTTPS: the address arrived whole (its & and ? encoded)');
    assertEquals(['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE'], $urSaw[0]['threatTypes'], 'real HTTPS: the three lists arrived as repeated threatTypes');
    assertTrue($urSaw[0]['hasBody'] === false && $urSaw[0]['acceptEncoding'] === 'none' && $urSaw[0]['accept'] === 'application/json', 'real HTTPS: no body, nothing compressed asked for, JSON asked for');

    $urVerdict = urlReputationAskWebRisk('https://evil.example/', $urKey, 2, $urReal('unsafe'));
    assertTrue($urVerdict['verdict'] === 'unsafe' && $urVerdict['threatTypes'] === ['MALWARE', 'SOCIAL_ENGINEERING'] && $urVerdict['expiresAt'] > time(), 'real HTTPS: a threat is unsafe, with its lists and a future expiry');
    $urVerdict = urlReputationAskWebRisk('https://example.com/', $urKey, 2, $urReal('emptythreat'));
    assertTrue($urVerdict['verdict'] === 'safe', 'real HTTPS: an empty threat is safe');
    foreach (['denied' => 'key_refused', 'quota' => 'quota_exceeded', 'broken' => 'unavailable', 'badrequest' => 'bad_request', 'html' => 'bad_reply', 'big' => 'bad_reply'] as $urScenario => $urReason) {
        $urVerdict = urlReputationAskWebRisk('https://example.com/', $urKey, 2, $urReal($urScenario));
        assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === $urReason, 'real HTTPS: ' . $urScenario . ' is "unknown" (' . $urReason . '): ' . $urVerdict['reason']);
    }
    $urVerdict = urlReputationAskWebRisk('https://example.com/', 'AIzaWrongKey' . str_repeat('w', 27), 2, $urReal('safe'));
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === 'key_refused', 'real HTTPS: a wrong key is refused (key_refused), never "safe"');
    $urServerSaw();

    // ⏱️ Time limit: the server waits 3 seconds, the client gives up at 2.
    $urStart   = microtime(true);
    $urVerdict = urlReputationAskWebRisk('https://example.com/', $urKey, 2, $urReal('slow'));
    $urTook    = microtime(true) - $urStart;
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === 'timeout', 'real HTTPS: a server slower than the time limit is a timeout: ' . $urVerdict['reason']);
    assertTrue($urTook < 2.9, 'real HTTPS: ...given up after about 2 seconds, not 3 (' . round($urTook, 2) . ' s)');
    sleep(2);
    $urServerSaw();

    // 🔐 Bad certificate: the client is not given the test authority.
    $urVerdict = urlReputationAskWebRisk('https://example.com/', $urKey, 2, $urReal('safe', ['caFile' => '']));
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === 'tls_failed', 'real HTTPS: an untrusted certificate is refused (tls_failed): ' . $urVerdict['reason']);
    assertEquals([], $urServerSaw(), 'real HTTPS: ...and no request (so no key) reached the server');

    // ↪️ A redirect is never followed.
    $urVerdict = urlReputationAskWebRisk('https://example.com/', $urKey, 2, $urReal('redirect'));
    $urSaw = $urServerSaw();
    assertTrue($urVerdict['verdict'] === 'unknown' && $urVerdict['reason'] === 'bad_reply', 'real HTTPS: a redirect is not an answer');
    assertTrue(count($urSaw) === 1 && $urSaw[0]['scenario'] === 'redirect', 'real HTTPS: ...and it was NOT followed (the server saw one request; the redirect pointed at "unsafe")');

    // 🛡️ The whole check through the real transport.
    $urLogged  = [];
    $urRecords = [];
    $urCheck = urlReputationCheck('https://evil.example/', 'recheck', array_merge($urCheckOptions($urDown), $urReal('unsafe'), ['transport' => null]));
    assertTrue($urCheck['allowed'] === false && count($urRecords) === 1 && $urRecords[0]['occasion'] === 'recheck', 'real HTTPS: the whole check refuses an unsafe destination and records it');
    $urServerSaw();
}

// 🧹 Stop the server (closing its input stops it) and remove the files.
if (is_resource($urProcess)) {
    fclose($urPipes[0]);
    fclose($urPipes[1]);
    fclose($urPipes[2]);
    proc_close($urProcess);
}
testTlsRemove($urDir);
assertFalse(is_dir($urDir), 'the temporary certificate folder was removed');

// ==============================================================================
// ✅ Section 10: source guards
// ==============================================================================
$urSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_url_reputation.php');
foreach (['error_log(', 'var_dump(', 'print_r(', 'var_export(', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_FOLLOWLOCATION', "'key='", '&key=', '?key='] as $urNever) {
    assertFalse(str_contains($urSource, $urNever), 'fn_url_reputation.php never uses ' . $urNever);
}
assertContains('go2mylinkHttpTransport(', $urSource, 'fn_url_reputation.php uses the one shared safe transport (certificates checked, no redirects, https only, 64 KB)');
assertContains('#[\SensitiveParameter] string $key', $urSource, 'the key is a sensitive parameter (hidden from stack traces)');
$urRedirectsSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_redirects.php');
assertContains('urlReputationCheck(', $urRedirectsSource, 'the hook asks the link-safety check');
