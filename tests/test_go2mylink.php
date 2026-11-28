<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the Go2My.Link connection code (WP4b, #165)
 * ==============================================================================
 *
 * Path: tests/test_go2mylink.php
 *
 * Covers _functions/fn_go2mylink.php WITHOUT the real Go2My.Link and without
 * the internet:
 *
 *   1. Loading defines functions only (no site start-up helpers).
 *   2. Short codes CueRCode chooses (lead choice L1): 7 letters and digits,
 *      Go2My.Link's pattern, never one of its reserved words.
 *   3. Reading the settings: defaults, the waiting-time bounds, a missing or
 *      unreadable key.
 *   4. The requests: the key goes ONLY in "Authorization: Bearer", JSON is
 *      asked for, compressed replies are never asked for, the create body
 *      carries exactly the fields Go2My.Link's handler reads.
 *   5. Every answer Go2My.Link can give (from its handlers, see the stand-in
 *      server's header) mapped to a plain CueRCode reason and message,
 *      through a scripted stand-in: 201, 200, 400, 401, three kinds of 403,
 *      404, 409 (code taken / QR already linked), 422, 429, 500, 503, a
 *      redirect, a web page, the wrong shape, a reply too large, and the
 *      network failures (cannot connect, certificate, time limit). The key
 *      never appears in any message, admin message or log line.
 *   6. The safe retry (L1): a create whose answer was lost is looked up by
 *      its code before it is tried again; a lost lookup leaves it "not
 *      known", never "failed".
 *   7. "Test connection": what it checks, that it never creates, changes or
 *      switches off anything (only GET, and a PUT to a code that cannot
 *      exist), and what it says.
 *   8. REAL HTTPS against tests/lib/go2mylink_standin_server.php on
 *      127.0.0.1 with a throwaway certificate: success, 403, 409, 422, 429,
 *      time limit, bad certificate, unexpected reply shape, a web page, a
 *      reply too large, a redirect that is not followed, and the safe retry
 *      when the create answer comes too late.
 *
 * House rules: every variable is prefixed $g2; nothing here defines dbQuery(),
 * getConfig(), logError() or logActivity().
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#165)
 * @see         web/CueRCode.net/_functions/fn_go2mylink.php
 * @see         tests/lib/go2mylink_standin_server.php
 */

requireFn('fn_go2mylink.php');

// ==============================================================================
// ✅ Section 1: loading
// ==============================================================================
foreach (['dbQuery', 'getDB', 'getConfig', 'logError', 'logActivity'] as $g2Forbidden) {
    assertFalse(function_exists($g2Forbidden), 'loading fn_go2mylink.php does not define ' . $g2Forbidden . '()');
}
assertEquals(7, CUERCODE_GO2MYLINK_CODE_LENGTH, 'L1: CueRCode chooses 7-character codes');
assertEquals(65536, CUERCODE_GO2MYLINK_MAX_REPLY_BYTES, 'a reply is never read past 64 KB');
assertEquals([2, 5, 15], [CUERCODE_GO2MYLINK_MIN_TIMEOUT, CUERCODE_GO2MYLINK_DEFAULT_TIMEOUT, CUERCODE_GO2MYLINK_MAX_TIMEOUT], 'waiting time: 2 to 15 seconds, 5 by default');

// ==============================================================================
// ✅ Section 2: short codes (L1)
// ==============================================================================
for ($g2I = 0; $g2I < 200; $g2I++) {
    $g2Code = go2mylinkGenerateShortCode();
    if (preg_match('/^[A-Za-z0-9]{7}$/', $g2Code) !== 1 || !go2mylinkCodeIsAllowed($g2Code)) {
        assertTrue(false, 'a generated code is 7 letters and digits and allowed: ' . $g2Code);
        break;
    }
}
assertTrue($g2I === 200, '200 generated codes are all 7 letters and digits, and allowed by Go2My.Link\'s rules');

foreach (['install', 'INSTALL', 'Sitemap', 'favicon', 'api', 'admin', 'www', 'bulk', 'expired', 'validating', 'vendor', 'robots', '404', 'index'] as $g2Reserved) {
    assertFalse(go2mylinkCodeIsAllowed($g2Reserved), 'Go2My.Link reserves "' . $g2Reserved . '" (any capitals)');
}
foreach (['ab', '', 'a b c', 'abc/de', 'abc.de', 'abcé12', str_repeat('a', 51)] as $g2Bad) {
    assertFalse(go2mylinkCodeIsAllowed($g2Bad), 'outside Go2My.Link\'s pattern ^[A-Za-z0-9_-]{3,50}$: ' . var_export($g2Bad, true));
}
foreach (['Ab3dE9x', 'abc', 'a_b-c', str_repeat('Z', 50)] as $g2Good) {
    assertTrue(go2mylinkCodeIsAllowed($g2Good), 'allowed: ' . $g2Good);
}

// 🎲 A scripted random source: the first draw spells "install" (reserved),
//    so a second code is drawn.
$g2Alphabet = CUERCODE_GO2MYLINK_CODE_ALPHABET;
$g2Script   = [];
foreach (str_split('installAb3dE9x') as $g2Letter) {
    $g2Script[] = strpos($g2Alphabet, $g2Letter);
}
$g2Draws = 0;
$g2Picked = go2mylinkGenerateShortCode(static function (int $g2Max) use (&$g2Script, &$g2Draws): int {
    $g2Draws++;
    return (int) array_shift($g2Script);
});
assertEquals('Ab3dE9x', $g2Picked, 'a draw that spells a reserved word ("install") is thrown away and another is drawn');
assertEquals(14, $g2Draws, '...exactly two codes were drawn');

// ==============================================================================
// ✅ Section 3: reading the settings
// ==============================================================================
$g2Config = static function (array $g2Values): callable {
    return static function (string $g2Key, mixed $g2Default) use ($g2Values): mixed {
        if (array_key_exists($g2Key, $g2Values)) {
            return $g2Values[$g2Key];
        }
        return $g2Default;
    };
};
$g2NoRow = static function (string $g2Key): string {
    return 'missing';
};
$g2Key = 'g2ml_AbCdEfGh_' . str_repeat('K', 43);

$g2Settings = go2mylinkReadSettings($g2Config([]), $g2NoRow);
assertEquals('https://go2my.link/api/v1', $g2Settings['apiBaseUrl'], 'settings: the production API address is the default');
assertEquals(5, $g2Settings['timeout'], 'settings: 5 seconds by default');
assertEquals('not_configured', $g2Settings['problem'], 'settings: no key means "not set up"');

$g2Settings = go2mylinkReadSettings($g2Config(['go2mylink_api_key' => $g2Key, 'go2mylink_api_base_url' => 'https://alpha.go2my.link/api/v1/', 'go2mylink_timeout_seconds' => '9', 'dynamic_redirect_base_url' => 'https://alpha.QRmy.Link']), $g2NoRow);
assertEquals('', $g2Settings['problem'], 'settings: with a key and an address it is set up');
assertEquals('https://alpha.go2my.link/api/v1', $g2Settings['apiBaseUrl'], 'settings: the alpha address can be set (decision 0030); a trailing slash is dropped');
assertEquals('https://alpha.QRmy.Link', $g2Settings['shortLinkBase'], 'settings: the short-link address comes from dynamic_redirect_base_url (alpha.QRmy.Link for alpha, decision 0030)');
assertEquals(9, $g2Settings['timeout'], 'settings: 9 seconds is used');
foreach (['1', '16', 'x', '-3', '', '5.5'] as $g2BadTimeout) {
    $g2Settings = go2mylinkReadSettings($g2Config(['go2mylink_api_key' => $g2Key, 'go2mylink_timeout_seconds' => $g2BadTimeout]), $g2NoRow);
    assertEquals(5, $g2Settings['timeout'], 'settings: a waiting time of ' . var_export($g2BadTimeout, true) . ' means the default 5');
}
foreach (['http://go2my.link/api/v1', 'go2my.link/api/v1', 'https://user:pass@go2my.link/api/v1', 'https://go2my.link/api/v1?x=1', 'https://go2my.link/api/v1#x', 'ftp://go2my.link', ''] as $g2BadUrl) {
    $g2Settings = go2mylinkReadSettings($g2Config(['go2mylink_api_key' => $g2Key, 'go2mylink_api_base_url' => $g2BadUrl]), $g2NoRow);
    assertEquals('not_configured', $g2Settings['problem'], 'settings: the API address ' . var_export($g2BadUrl, true) . ' is not usable (https only, no user name, no ? or #)');
}
// 🔐 A stored key that cannot be read (the reader returns its default, the row is not empty).
$g2Settings = go2mylinkReadSettings($g2Config([]), static function (string $g2Key): string {
    if ($g2Key === 'go2mylink_api_key') {
        return 'present';
    }
    return 'missing';
});
assertEquals('key_unreadable', $g2Settings['problem'], 'settings: a stored key that cannot be decrypted is reported as such, not as "not set"');

// 🧪 L11 (decision 0030): an alpha or beta copy never prints on the live QRmy.Link.
$g2Prod = ['DOCUMENT_ROOT' => '/home/cuercode/CueRCode.net/public_html', 'SCRIPT_FILENAME' => '/home/cuercode/CueRCode.net/public_html/admin/settings/index.php'];
$g2Alpha = ['DOCUMENT_ROOT' => '/home/cuercode/CueRCode.net/public_html_dev_alpha', 'SCRIPT_FILENAME' => '/home/cuercode/CueRCode.net/public_html_dev_alpha/admin/settings/index.php'];
$g2Beta = ['SCRIPT_FILENAME' => '/home/cuercode/CueRCode.net/public_html_dev_beta/index.php'];
assertEquals('', go2mylinkTestCopyName($g2Prod, 'https://cuercode.net', 'https://go2my.link/api/v1'), 'L11: the live copy is not a test copy');
assertEquals('alpha', go2mylinkTestCopyName($g2Alpha, 'https://cuercode.net', 'https://go2my.link/api/v1'), 'L11: an alpha copy is known by its folder (public_html_dev_alpha)');
assertEquals('beta', go2mylinkTestCopyName($g2Beta, '', ''), 'L11: a beta copy is known by its folder (public_html_dev_beta)');
assertEquals('alpha', go2mylinkTestCopyName([], 'https://alpha.cuercode.net', ''), 'L11: ...or by the site address (alpha.), for example on the command line');
assertEquals('beta', go2mylinkTestCopyName([], 'https://cuercode.net', 'https://beta.go2my.link/api/v1'), 'L11: ...or by the Go2My.Link address (beta.)');
assertEquals('', go2mylinkTestCopyName([], 'https://alphabet.example', ''), 'L11: a name that only starts with the letters "alpha" is not a test copy');

$g2Base = ['go2mylink_api_key' => $g2Key, 'dynamic_redirect_base_url' => 'https://QRmy.Link'];
$g2Settings = go2mylinkReadSettings($g2Config($g2Base), $g2NoRow, $g2Alpha);
assertEquals('test_copy_live_domain', $g2Settings['problem'], 'L11: an alpha copy whose short-link address is the live https://QRmy.Link is refused before anything is sent');
assertContains('alpha.QRmy.Link', go2mylinkReasonFacts('test_copy_live_domain')['adminMessage'], 'L11: ...and the admin is told to use https://alpha.QRmy.Link (decision 0030)');
$g2Settings = go2mylinkReadSettings($g2Config(array_merge($g2Base, ['dynamic_redirect_base_url' => 'https://qrmy.link/'])), $g2NoRow, $g2Beta);
assertEquals('test_copy_live_domain', $g2Settings['problem'], 'L11: ...whatever the capitals or a trailing slash (beta copy)');
$g2Settings = go2mylinkReadSettings($g2Config(array_merge($g2Base, ['dynamic_redirect_base_url' => 'https://alpha.QRmy.Link'])), $g2NoRow, $g2Alpha);
assertEquals('', $g2Settings['problem'], 'L11: an alpha copy on https://alpha.QRmy.Link is fine');
$g2Settings = go2mylinkReadSettings($g2Config($g2Base), $g2NoRow, $g2Prod);
assertEquals('', $g2Settings['problem'], 'L11: the live copy prints on https://QRmy.Link');
$g2Settings = go2mylinkReadSettings($g2Config(array_merge($g2Base, ['site_url' => 'https://alpha.cuercode.net'])), $g2NoRow, []);
assertEquals('test_copy_live_domain', $g2Settings['problem'], 'L11: on the command line (no folder) the alpha site address is enough to refuse');
$g2BlockedSeen = [];
$g2Blocked = go2mylinkTestConnection(['settings' => go2mylinkReadSettings($g2Config($g2Base), $g2NoRow, $g2Alpha), 'transport' => static function (array $g2Request, int $g2Timeout) use (&$g2BlockedSeen): array {
    $g2BlockedSeen[] = $g2Request;
    return ['ok' => false, 'errno' => 7];
}]);
assertTrue(!$g2Blocked['ok'] && $g2Blocked['reason'] === 'test_copy_live_domain' && $g2BlockedSeen === [], 'L11: "Test connection" on such a copy says so and sends nothing (so the key goes nowhere)');

// ==============================================================================
// 🧰 A scripted transport: records every request, answers from a list
// ==============================================================================
$g2GoodSettings = [
    'apiBaseUrl'    => 'https://go2my.link/api/v1',
    'apiKey'        => $g2Key,
    'keyUnreadable' => false,
    'timeout'       => 5,
    'shortLinkBase' => 'https://QRmy.Link',
    'problem'       => '',
];
$g2Reply = static function (int $g2Status, mixed $g2Body, array $g2Extra = []): array {
    $g2Text = $g2Body;
    if (!is_string($g2Body)) {
        $g2Text = (string) json_encode($g2Body);
    }
    return array_merge(['ok' => true, 'status' => $g2Status, 'body' => $g2Text, 'retryAfter' => 0, 'errno' => 0, 'error' => '', 'tooLarge' => false], $g2Extra);
};
$g2Fail = static function (int $g2Errno, string $g2Error): array {
    return ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => $g2Errno, 'error' => $g2Error, 'tooLarge' => false];
};
$g2Ok = static function (mixed $g2Data): array {
    return ['status' => 'success', 'data' => $g2Data, 'meta' => ['requestId' => 'x']];
};
$g2Err = static function (int $g2Code, string $g2Message, ?string $g2Field = null): array {
    return ['status' => 'error', 'error' => ['code' => $g2Code, 'message' => $g2Message, 'field' => $g2Field]];
};
$g2Script = static function (array $g2Replies, array &$g2Seen): callable {
    return static function (array $g2Request, int $g2Timeout) use (&$g2Replies, &$g2Seen): array {
        $g2Seen[] = $g2Request;
        if ($g2Replies === []) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'retryAfter' => 0, 'errno' => 7, 'error' => 'no scripted reply left', 'tooLarge' => false];
        }
        return array_shift($g2Replies);
    };
};
$g2Logged = [];
$g2Logger = static function (string $g2Line) use (&$g2Logged): void {
    $g2Logged[] = $g2Line;
};
$g2Link = [
    'redirectID'     => 48213,
    'redirectUUID'   => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
    'destinationUrl' => 'https://example.com/menu',
    'shortCode'      => 'Ab3dE9x',
];
$g2Row = [
    'short_code' => 'Ab3dE9x', 'destination_url' => 'https://example.com/menu', 'title' => null, 'is_active' => true,
    'click_count' => 12, 'created_at' => '2026-11-28 10:00:00', 'updated_at' => '2026-11-28 10:00:00', 'start_date' => null, 'end_date' => null,
];
$g2Created = $g2Ok(['short_code' => 'Ab3dE9x', 'short_url' => 'https://QRmy.Link/Ab3dE9x', 'qr_code_external_uuid' => $g2Link['redirectUUID']]);
$g2Opts = static function (callable $g2Transport) use ($g2GoodSettings, $g2Logger): array {
    return ['settings' => $g2GoodSettings, 'transport' => $g2Transport, 'logger' => $g2Logger];
};
// 📋 The requests a scripted run made, as "METHOD route".
$g2Methods = static function (array $g2Requests): string {
    $g2Out = [];
    foreach ($g2Requests as $g2Request) {
        $g2Out[] = $g2Request['method'] . ' ' . substr($g2Request['url'], strlen('https://go2my.link/api/v1/'));
    }
    return implode(', ', $g2Out);
};

// ==============================================================================
// ✅ Section 4: the requests
// ==============================================================================
$g2Seen = [];
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Created)], $g2Seen)));
assertTrue($g2Result['ok'], 'create: 201 is success');
assertEquals(1, count($g2Seen), 'create: one request');
assertEquals('POST', $g2Seen[0]['method'], 'create: POST');
assertEquals('https://go2my.link/api/v1/urls', $g2Seen[0]['url'], 'create: POST /api/v1/urls');
assertTrue(in_array('Authorization: Bearer ' . $g2Key, $g2Seen[0]['headers'], true), 'create: the key is sent as "Authorization: Bearer <key>"');
assertTrue(in_array('Accept: application/json', $g2Seen[0]['headers'], true), 'create: JSON is asked for (Go2My.Link answers XML to some Accept headers)');
assertTrue(in_array('Content-Type: application/json', $g2Seen[0]['headers'], true), 'create: the body is JSON');
$g2KeyCount = 0;
foreach ($g2Seen[0]['headers'] as $g2Header) {
    if (str_contains($g2Header, $g2Key)) {
        $g2KeyCount++;
    }
    assertFalse(stripos($g2Header, 'Accept-Encoding') === 0, 'create: compressed replies are never asked for (#150)');
}
assertEquals(1, $g2KeyCount, 'create: the key is in exactly one header');
assertFalse(str_contains((string) $g2Seen[0]['body'], $g2Key), 'create: the key is never in the body');
$g2Body = json_decode((string) $g2Seen[0]['body'], true);
assertEquals(['destination_url' => 'https://example.com/menu', 'qr_external_id' => 48213, 'qr_external_uuid' => '3fa85f64-5717-4562-b3fc-2c963f66afa6', 'custom_code' => 'Ab3dE9x'], $g2Body, 'create: the body is exactly destination_url, qr_external_id (a number), qr_external_uuid and custom_code (L1)');
assertEquals('Ab3dE9x', $g2Result['data']['shortCode'], 'create: the short code from the reply');
assertEquals('https://QRmy.Link/Ab3dE9x', $g2Result['data']['shortUrl'], 'create: the short address from the reply');
assertTrue($g2Result['data']['codeKept'], 'create: Go2My.Link kept the code CueRCode chose');

// 🚫 Refused before anything is sent.
$g2Seen = [];
$g2Result = go2mylinkCreateLink(array_merge($g2Link, ['shortCode' => 'install']), $g2Opts($g2Script([], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_request' && $g2Seen === [], 'create: a reserved code is refused before anything is sent');
$g2Result = go2mylinkCreateLink(array_merge($g2Link, ['redirectUUID' => 'not-a-uuid']), $g2Opts($g2Script([], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_request' && $g2Seen === [], 'create: a malformed UUID is refused before anything is sent');
$g2Result = go2mylinkCreateLink(array_merge($g2Link, ['redirectID' => 0]), $g2Opts($g2Script([], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_request' && $g2Seen === [], 'create: a redirect id that is not a positive number is refused before anything is sent');
$g2Result = go2mylinkCreateLink($g2Link, ['settings' => array_merge($g2GoodSettings, ['apiKey' => '', 'problem' => 'not_configured']), 'transport' => $g2Script([], $g2Seen)]);
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'not_configured' && $g2Seen === [], 'create: with no key nothing is sent');
$g2Result = go2mylinkLookupLink('Ab3dE9x', ['settings' => array_merge($g2GoodSettings, ['apiKey' => $g2Key . "\r\nX-Injected: 1"]), 'transport' => $g2Script([], $g2Seen)]);
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'not_configured' && $g2Seen === [], 'a stored key with a line break in it is never sent (it could add a header line)');

// ✏️ Change destination, look up, scan total.
$g2Seen = [];
$g2Result = go2mylinkUpdateDestination('Ab3dE9x', 'https://example.com/new', $g2Opts($g2Script([$g2Reply(200, $g2Ok(array_merge($g2Row, ['destination_url' => 'https://example.com/new'])))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Seen[0]['method'] === 'PUT' && $g2Seen[0]['url'] === 'https://go2my.link/api/v1/urls/Ab3dE9x', 'change destination: PUT /api/v1/urls/{code}');
assertEquals(['destination_url' => 'https://example.com/new'], json_decode((string) $g2Seen[0]['body'], true), 'change destination: only destination_url is sent');
assertEquals('https://example.com/new', $g2Result['data']['destinationUrl'], 'change destination: the new destination as Go2My.Link stored it');

$g2Seen = [];
$g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Opts($g2Script([$g2Reply(200, $g2Ok($g2Row))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Seen[0]['method'] === 'GET' && $g2Seen[0]['url'] === 'https://go2my.link/api/v1/urls/Ab3dE9x' && $g2Seen[0]['body'] === null, 'look up: GET /api/v1/urls/{code} with no body');
assertEquals(['shortCode' => 'Ab3dE9x', 'destinationUrl' => 'https://example.com/menu', 'isActive' => true, 'clickCount' => 12, 'shortUrl' => ''], $g2Result['data'], 'look up: code, destination, switched on, scans; no short address, because Go2My.Link\'s look-up does not send one (asked for on its issue #276)');
$g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Opts($g2Script([$g2Reply(200, $g2Ok(array_merge($g2Row, ['short_url' => 'https://QRmy.Link/Ab3dE9x'])))], $g2Seen)));
assertEquals('https://QRmy.Link/Ab3dE9x', $g2Result['data']['shortUrl'], 'look up: a short address in the reply (once Go2My.Link sends one) is passed on');

$g2Seen = [];
$g2Result = go2mylinkScanTotal('Ab3dE9x', $g2Opts($g2Script([$g2Reply(200, $g2Ok($g2Row))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Result['data']['total'] === 12, 'scan total (decision 0033): Go2My.Link\'s click_count for the code');
$g2Result = go2mylinkScanTotal('Ab3dE9x', $g2Opts($g2Script([$g2Reply(200, $g2Ok(array_merge($g2Row, ['click_count' => -1])))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'scan total: a negative count is an unexpected reply');
$g2Result = go2mylinkScanTotal('Ab3dE9x', $g2Opts($g2Script([$g2Reply(200, $g2Ok(array_merge($g2Row, ['click_count' => '12'])))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'scan total: a count that is not a number is an unexpected reply');
$g2Seen = [];
$g2Result = go2mylinkLookupLink('bad code', $g2Opts($g2Script([], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_request' && $g2Seen === [], 'look up: a code outside the pattern is never put in an address');

// ==============================================================================
// ✅ Section 5: every answer mapped to a plain reason
// ==============================================================================
$g2Cases = [
    // [label, reply, expected reason, outcome unknown?, member message contains]
    ['400 bad JSON',            $g2Reply(400, $g2Err(400, 'Request body must be valid JSON.')), 'bad_request', false, 'not available just now'],
    ['401 wrong key',           $g2Reply(401, $g2Err(401, 'Invalid or expired API key.')), 'auth_failed', false, 'not available just now'],
    ['403 integration off',     $g2Reply(403, $g2Err(403, 'The CueRCode integration is currently disabled.')), 'integration_off', false, 'not available just now'],
    ['403 missing qr:link',     $g2Reply(403, $g2Err(403, 'This API key does not have the required qr:link scope.')), 'missing_permission', false, 'not available just now'],
    ['403 missing scope',       $g2Reply(403, $g2Err(403, 'This API key does not have the required scope.')), 'missing_permission', false, 'not available just now'],
    ['403 link limit',          $g2Reply(403, $g2Err(403, 'You have reached your plan\'s link limit. Please upgrade to create more short links.', 'destination_url')), 'link_limit', false, 'not available just now'],
    ['403 something else',      $g2Reply(403, $g2Err(403, 'HTTPS is required.')), 'forbidden', false, 'not available just now'],
    ['404',                     $g2Reply(404, $g2Err(404, 'No such short URL in this account.')), 'not_found', false, 'could not be found'],
    ['409 code taken',          $g2Reply(409, $g2Err(409, 'That alias is already taken. Please choose another.', 'custom_code')), 'code_taken', false, 'try again'],
    ['409 QR already linked',   $g2Reply(409, $g2Err(409, 'That QR code is already linked to a short URL.', 'qr_external_uuid')), 'already_linked', false, 'not available just now'],
    ['422 destination refused', $g2Reply(422, $g2Err(422, 'That destination is not permitted. Please enter a public HTTP or HTTPS URL.', 'destination_url')), 'rejected', false, 'could not accept'],
    ['429 rate limit',          $g2Reply(429, $g2Err(429, 'Rate limit exceeded.'), ['retryAfter' => 60]), 'rate_limited', false, 'busy'],
    ['500',                     $g2Reply(500, $g2Err(500, 'An unexpected error occurred.')), 'unavailable', true, 'could not be reached'],
    ['503',                     $g2Reply(503, $g2Err(503, 'The public API is temporarily unavailable.')), 'unavailable', false, 'could not be reached'],
    ['302 redirect',            $g2Reply(302, ''), 'bad_reply', false, 'not available just now'],
    ['200 web page',            $g2Reply(200, '<!doctype html><title>Coming soon</title>'), 'bad_reply', true, 'not available just now'],
    ['201 wrong shape',         $g2Reply(201, $g2Ok(['unexpected' => true])), 'bad_reply', true, 'not available just now'],
    ['201 error envelope',      $g2Reply(201, $g2Err(201, 'odd')), 'bad_reply', true, 'not available just now'],
    ['201 too large',           $g2Reply(201, '', ['ok' => false, 'tooLarge' => true, 'errno' => 23]), 'bad_reply', true, 'not available just now'],
    ['cannot connect',          $g2Fail(7, 'Failed to connect to go2my.link port 443'), 'unreachable', false, 'could not be reached'],
    ['name not found',          $g2Fail(6, 'Could not resolve host: go2my.link'), 'unreachable', false, 'could not be reached'],
    ['bad certificate',         $g2Fail(60, 'SSL certificate problem: self-signed certificate'), 'tls_failed', false, 'not available just now'],
    ['TLS handshake',           $g2Fail(35, 'TLS connect error'), 'tls_failed', false, 'not available just now'],
    ['time limit',              $g2Fail(28, 'Operation timed out after 5001 milliseconds'), 'timeout', true, 'could not be reached'],
    ['connection dropped',      $g2Fail(56, 'Recv failure: Connection reset by peer'), 'timeout', true, 'could not be reached'],
    // 🧾 M1: the request was SENT, then the answer could not be read.
    ['garbled status line',     $g2Fail(1, 'Unsupported protocol'), 'bad_reply', true, 'not available just now'],
    ['broken length header',    $g2Fail(8, 'Weird server reply'), 'bad_reply', true, 'not available just now'],
    ['headers too large',       $g2Reply(201, '', ['ok' => false, 'tooLarge' => true, 'errno' => 23]), 'bad_reply', true, 'not available just now'],
];
foreach ($g2Cases as $g2Case) {
    $g2Seen   = [];
    $g2Logged = [];
    $g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Case[1]], $g2Seen)));
    assertFalse($g2Result['ok'], $g2Case[0] . ': not a success');
    assertEquals($g2Case[2], $g2Result['reason'], $g2Case[0] . ': reason ' . $g2Case[2]);
    assertEquals($g2Case[3], $g2Result['outcomeUnknown'], $g2Case[0] . ': "was it saved?" is ' . var_export($g2Case[3], true));
    assertContains($g2Case[4], $g2Result['message'], $g2Case[0] . ': plain message for the member');
    assertTrue($g2Result['adminMessage'] !== '' && $g2Result['adminMessage'] !== $g2Result['message'], $g2Case[0] . ': a separate, more detailed message for an admin');
    assertTrue($g2Result['httpCode'] >= 400, $g2Case[0] . ': a CueRCode status to answer with (' . $g2Result['httpCode'] . ')');
    $g2Everything = json_encode($g2Result) . implode("\n", $g2Logged);
    assertFalse(str_contains($g2Everything, $g2Key), $g2Case[0] . ': the key is not in the result or the log');
    assertFalse(str_contains($g2Everything, 'Bearer'), $g2Case[0] . ': no Authorization header text in the result or the log');
    // 🙊 Go2My.Link's own words are never shown to a member.
    if (isset($g2Case[1]['body']) && is_string($g2Case[1]['body']) && str_contains($g2Case[1]['body'], 'message')) {
        $g2Remote = json_decode($g2Case[1]['body'], true);
        if (is_array($g2Remote) && isset($g2Remote['error']['message'])) {
            assertFalse(str_contains($g2Result['message'], (string) $g2Remote['error']['message']), $g2Case[0] . ': Go2My.Link\'s own wording is not passed to the member');
        }
    }
}
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(429, $g2Err(429, 'Rate limit exceeded.'), ['retryAfter' => 60])], $g2Seen)));
assertEquals(60, $g2Result['retryAfter'], '429: Go2My.Link\'s Retry-After (60) is passed on');
assertEquals(429, $g2Result['httpCode'], '429: CueRCode answers 429 too');
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(422, $g2Err(422, 'x', 'destination_url'))], $g2Seen)));
assertTrue($g2Result['httpCode'] === 422 && $g2Result['field'] === 'destination_url', '422: CueRCode answers 422 and names the field');
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out')], $g2Seen)));
assertEquals(503, $g2Result['httpCode'], 'a time limit: CueRCode answers 503 (try again later)');
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(422, $g2Err(422, 'x', 'destination_url'))], $g2Seen)));
assertFalse(str_contains($g2Result['message'], 'cannot be used'), 'L10: a 422 does not tell the member the web address is bad (Go2My.Link\'s 422 also means a reserved code, no code could be made, or its own database fault)');
assertContains('several', $g2Result['adminMessage'], 'L10: ...and the admin is told a 422 can mean several things');

// 🧾 M1: an unknown curl failure BEFORE anything was sent is still our own fault, with nothing done.
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([array_merge($g2Fail(1, 'Unsupported protocol'), ['sent' => false])], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_request' && $g2Result['outcomeUnknown'] === false, 'M1: an unknown failure before anything was sent: bad_request, nothing done');
assertEquals(['reason' => 'bad_reply', 'mayHaveRun' => true], go2mylinkTransportReason(1, true), 'M1: an unknown curl failure after the request was sent: an unreadable answer that may have been carried out');
assertEquals(['reason' => 'bad_request', 'mayHaveRun' => false], go2mylinkTransportReason(1, false), 'M1: ...the same failure before sending: our own fault, nothing done');
$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(8, 'Weird server reply'), $g2Reply(404, $g2Err(404, 'No such short URL in this account.')), $g2Reply(201, $g2Created)], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x, POST urls', 'M1: an unreadable answer to a create leads to the look-up and the safe retry, not to "a fault in CueRCode" (' . $g2Methods($g2Seen) . ')');

// 🔎 L3: the reply must be for OUR QR id.
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Ok(['short_code' => 'Ab3dE9x', 'short_url' => 'https://QRmy.Link/Ab3dE9x', 'qr_code_external_uuid' => '00000000-0000-4000-8000-000000000000']))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply' && $g2Result['outcomeUnknown'] === true, 'L3: a 201 naming another QR id is an unexpected reply, never a success');

// 🌐 201 but Go2My.Link chose its own code (allow_external_shortcode off).
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Ok(['short_code' => 'Zz99abc', 'short_url' => 'https://QRmy.Link/Zz99abc', 'qr_code_external_uuid' => $g2Link['redirectUUID']]))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Result['data']['shortCode'] === 'Zz99abc' && $g2Result['data']['codeKept'] === false, 'create: when Go2My.Link ignores the chosen code, its own code is returned and codeKept is false');
// 🌐 201 but on another short domain: never printed.
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Ok(['short_code' => 'Ab3dE9x', 'short_url' => 'https://g2my.link/Ab3dE9x', 'qr_code_external_uuid' => $g2Link['redirectUUID']]))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'wrong_short_domain' && $g2Result['outcomeUnknown'] === false && $g2Result['data']['shortCode'] === 'Ab3dE9x', 'create: a link made on another short domain (not dynamic_redirect_base_url) is reported, with its code, and never treated as success');
assertTrue($g2Result['remoteExists'] === true, 'H1: ...and it says the link DOES exist on Go2My.Link (so the caller records it and never tries again)');
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Ok(['short_code' => 'Ab3dE9x', 'short_url' => 'https://qrmy.link/Ab3dE9x', 'qr_code_external_uuid' => $g2Link['redirectUUID']]))], $g2Seen)));
assertTrue($g2Result['ok'], 'create: the short domain is compared without regard to capitals (qrmy.link = QRmy.Link)');
$g2Result = go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Ok(['short_code' => 'Ab3dE9x', 'short_url' => 'https://QRmy.Link/Other12', 'qr_code_external_uuid' => $g2Link['redirectUUID']]))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'create: a short address that does not end in the short code is an unexpected reply');

// 🧾 The log line for a failure names the reason and status, never the key or Go2My.Link's text.
$g2Logged = [];
go2mylinkCreateLink($g2Link, $g2Opts($g2Script([$g2Reply(401, $g2Err(401, 'Invalid or expired API key.'))], $g2Seen)));
assertTrue(count($g2Logged) === 1 && str_contains($g2Logged[0], 'auth_failed') && str_contains($g2Logged[0], '401'), 'a failure writes one log line with the reason and status: ' . implode(' | ', $g2Logged));

// ==============================================================================
// ✅ Section 6: the safe retry (lead choice L1)
// ==============================================================================

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Created)], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Methods($g2Seen) === 'POST urls', 'safe create: a normal create is one call');

// 🔗 What Go2My.Link's look-up would send once it gives the short address
//    (asked for on Go2My.Link issue #276): the same row with short_url.
$g2RowUrl = array_merge($g2Row, ['short_url' => 'https://QRmy.Link/Ab3dE9x']);

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(200, $g2Ok($g2Row))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'domain_unconfirmed' && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x', 'H1: the answer was lost and the code is found, but the look-up does not say which short domain it is on: NOT a success, and nothing made twice (' . $g2Result['reason'] . ', ' . $g2Methods($g2Seen) . ')');
assertTrue($g2Result['remoteExists'] === true && $g2Result['outcomeUnknown'] === false, 'H1: ...it says the link exists on Go2My.Link, so it is never tried again');
assertEquals('unconfirmed', go2mylinkSyncStateFor($g2Result), 'H1: ...and it is held as "unconfirmed" (never printed, an admin must look)');
assertContains('#276', $g2Result['adminMessage'], 'H1: the admin message says why (Go2My.Link issue #276)');
assertFalse(isset($g2Result['data']['shortUrl']), 'H1: no short address is offered for printing');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(200, $g2Ok($g2RowUrl))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Result['data']['recovered'] === true && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x', 'safe create: lost answer, found, and the look-up confirms the short domain: success, nothing made twice (' . $g2Methods($g2Seen) . ')');
assertEquals('https://QRmy.Link/Ab3dE9x', $g2Result['data']['shortUrl'], 'safe create: a recovered link\'s address is the one Go2My.Link confirmed');
assertEquals('active', go2mylinkSyncStateFor($g2Result), 'safe create: a confirmed link is "active"');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(200, $g2Ok(array_merge($g2Row, ['short_url' => 'https://g2my.link/Ab3dE9x'])))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'wrong_short_domain' && $g2Result['remoteExists'] === true, 'H1: a found link the look-up places on another short domain is wrong_short_domain, never a success');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(404, $g2Err(404, 'No such short URL in this account.')), $g2Reply(201, $g2Created)], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x, POST urls', 'safe create: lost answer, not found, so it is tried once more with the SAME code and QR id (' . $g2Methods($g2Seen) . ')');
assertEquals($g2Seen[0]['body'], $g2Seen[2]['body'], 'safe create: the second try sends exactly the same body');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Fail(28, 'timed out')], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['outcomeUnknown'] === true && $g2Result['reason'] === 'timeout' && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x', 'safe create: lost answer AND lost look-up: "not known yet" (outcomeUnknown), not "failed", and no blind second create');
assertEquals('pending', go2mylinkSyncStateFor($g2Result), 'safe create: "not known yet" is "pending"');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(404, $g2Err(404, 'No such short URL in this account.')), $g2Fail(28, 'timed out')], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['outcomeUnknown'] === true && count($g2Seen) === 3, 'safe create: the second try is also lost: still "not known yet", at most one retry');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(409, $g2Err(409, 'That QR code is already linked to a short URL.', 'qr_external_uuid')), $g2Reply(200, $g2Ok($g2RowUrl))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Result['data']['recovered'] === true && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x', 'safe create: "QR already linked" and our code exists on the confirmed domain: it is ours from an earlier try - success');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(409, $g2Err(409, 'That QR code is already linked to a short URL.', 'qr_external_uuid')), $g2Reply(404, $g2Err(404, 'No such short URL in this account.'))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'already_linked' && $g2Result['outcomeUnknown'] === false && $g2Result['remoteExists'] === true, 'safe create: "QR already linked" under a code we do not know: needs an admin (already_linked; a link does exist on Go2My.Link)');
assertEquals('unconfirmed', go2mylinkSyncStateFor($g2Result), 'safe create: ...held as "unconfirmed", never retried');

// 🔁 H1, the reviewer's case: Go2My.Link made the link on another short
//    domain; the same row is tried again later.
$g2Wrong = $g2Ok(['short_code' => 'Ab3dE9x', 'short_url' => 'https://g2my.link/Ab3dE9x', 'qr_code_external_uuid' => $g2Link['redirectUUID']]);
$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(201, $g2Wrong)], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'wrong_short_domain' && $g2Result['remoteExists'] === true && count($g2Seen) === 1, 'H1: first try on another short domain: refused, and it says the link exists on Go2My.Link');
assertEquals('unconfirmed', go2mylinkSyncStateFor($g2Result), 'H1: ...held as "unconfirmed"');
$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(409, $g2Err(409, 'That QR code is already linked to a short URL.', 'qr_external_uuid')), $g2Reply(200, $g2Ok($g2Row))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['remoteExists'] === true && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x', 'H1: tried again, the same row is NOT "recovered" onto QRmy.Link: ' . $g2Result['reason']);

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(409, $g2Err(409, 'That alias is already taken. Please choose another.', 'custom_code'))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'code_taken' && count($g2Seen) === 1, 'safe create: code taken: handed back at once so the caller stores a NEW code before trying again');
assertEquals('failed', go2mylinkSyncStateFor($g2Result), 'safe create: code taken is "failed" (nothing was made)');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(7, 'Failed to connect')], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'unreachable' && $g2Result['outcomeUnknown'] === false && count($g2Seen) === 1, 'safe create: could not connect at all: nothing was sent, so no look-up is needed');

$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Reply(500, $g2Err(500, 'An unexpected error occurred.')), $g2Reply(200, $g2Ok($g2RowUrl))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x', 'safe create: a 500 may have saved the link, so it is looked up');

// 🧭 L1: a found link that is switched off, or points somewhere else.
$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(200, $g2Ok(array_merge($g2RowUrl, ['is_active' => false])))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'link_switched_off' && $g2Result['remoteExists'] === true, 'L1: a found link that is switched off on Go2My.Link is not a success: ' . $g2Result['reason']);
$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(200, $g2Ok(array_merge($g2RowUrl, ['destination_url' => 'https://example.com/other']))), $g2Reply(200, $g2Ok($g2RowUrl))], $g2Seen)));
assertTrue($g2Result['ok'] && $g2Methods($g2Seen) === 'POST urls, GET urls/Ab3dE9x, PUT urls/Ab3dE9x' && $g2Result['data']['destinationMatches'] === true && $g2Result['data']['destinationResent'] === true, 'L1: a found link that points elsewhere gets our destination sent again before it counts (' . $g2Methods($g2Seen) . ')');
assertEquals(['destination_url' => 'https://example.com/menu'], json_decode((string) $g2Seen[2]['body'], true), 'L1: ...the destination sent is ours');
$g2Seen = [];
$g2Result = go2mylinkCreateLinkSafely($g2Link, $g2Opts($g2Script([$g2Fail(28, 'timed out'), $g2Reply(200, $g2Ok(array_merge($g2RowUrl, ['destination_url' => 'https://example.com/other']))), $g2Reply(422, $g2Err(422, 'x', 'destination_url'))], $g2Seen)));
assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'rejected' && $g2Result['remoteExists'] === true, 'L1: ...and if that change is refused, it is not a success (the link exists, pointing elsewhere)');

// ==============================================================================
// ✅ Section 7: "Test connection" creates and changes no link
// ==============================================================================
$g2Account = $g2Ok(['userUID' => 7, 'email' => 'owner@example.com', 'displayName' => 'Owner', 'orgHandle' => 'cuercode', 'keyName' => 'CueRCode', 'scopes' => ['qr:link', 'urls:read', 'urls:write', 'urls:delete', 'analytics:read', 'account:read']]);
$g2AllGood = [
    $g2Reply(200, $g2Ok(['pong' => true])),
    $g2Reply(200, $g2Ok(['items' => [], 'nextCursor' => null])),
    $g2Reply(404, $g2Err(404, 'No such short URL in this account.')),
    $g2Reply(200, $g2Account),
];
$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script($g2AllGood, $g2Seen)));
assertTrue($g2Test['ok'], 'test connection: everything answers: passed');
assertEquals('GET ping, GET urls?limit=1, PUT urls/bulk, GET account', $g2Methods($g2Seen), 'test connection: exactly ping, a one-link list, a change to "bulk" (a code Go2My.Link reserves, so it can never exist) and the key\'s own details');
foreach ($g2Seen as $g2Request) {
    assertTrue(in_array($g2Request['method'], ['GET', 'PUT'], true), 'test connection never creates or switches off anything (' . $g2Request['method'] . ')');
}
assertTrue($g2Seen[2]['body'] === null || $g2Seen[2]['body'] === '', 'test connection: the PUT carries no body, so even a code that did exist would not change');
$g2Lines = implode(' ', $g2Test['lines']);
assertContains('Connected', $g2Lines, 'test connection: says it connected');
assertContains('not checked', $g2Lines, 'test connection: says plainly what it cannot check without making a real code');
assertFalse(str_contains($g2Lines, 'owner@example.com'), 'test connection: the Go2My.Link account email is not repeated');
assertFalse(str_contains(json_encode($g2Test), $g2Key), 'test connection: the key is not in the result');

$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script([$g2Reply(401, $g2Err(401, 'Invalid or expired API key.'))], $g2Seen)));
assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'auth_failed' && count($g2Seen) === 1, 'test connection: a wrong key stops at the first call');
assertContains('key', implode(' ', $g2Test['lines']), 'test connection: a wrong key is explained');

$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script([$g2Reply(200, $g2Ok(['pong' => true])), $g2Reply(200, $g2Ok(['items' => [], 'nextCursor' => null])), $g2Reply(403, $g2Err(403, 'This API key does not have the required scope.'))], $g2Seen)));
assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'missing_permission', 'test connection: no permission to change links: failed');

$g2Seen = [];
$g2NoQr = $g2Ok(['userUID' => 7, 'email' => 'x@example.com', 'displayName' => 'x', 'orgHandle' => 'x', 'keyName' => 'x', 'scopes' => ['urls:read', 'urls:write', 'account:read']]);
$g2Test = go2mylinkTestConnection($g2Opts($g2Script([$g2AllGood[0], $g2AllGood[1], $g2AllGood[2], $g2Reply(200, $g2NoQr)], $g2Seen)));
assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'missing_permission', 'test connection: a key without qr:link: failed');
assertContains('qr:link', implode(' ', $g2Test['lines']), 'test connection: names the missing permission');

$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script([$g2AllGood[0], $g2AllGood[1], $g2AllGood[2], $g2Reply(403, $g2Err(403, 'This API key does not have the required scope.'))], $g2Seen)));
assertTrue($g2Test['ok'], 'test connection: a key that may not read its own details still passes (account:read is optional)');
assertContains('could not be listed', implode(' ', $g2Test['lines']), 'test connection: ...and says the permissions could not be listed');

// 🏓 L3: the ping must really say pong.
$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script([$g2Reply(200, $g2Ok(['pong' => false])), $g2AllGood[1], $g2AllGood[2], $g2AllGood[3]], $g2Seen)));
assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'bad_reply' && count($g2Seen) === 1, 'L3: a ping answer without pong = true is not Go2My.Link: the check fails at once');

// 🚧 L8: Go2My.Link's "no such endpoint" 404 comes BEFORE its permission
//    check, so only the change handler's own "no such link" 404 proves urls:write.
$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script([$g2AllGood[0], $g2AllGood[1], $g2Reply(404, $g2Err(404, 'The requested endpoint does not exist.')), $g2AllGood[3]], $g2Seen)));
assertTrue(!$g2Test['ok'] && $g2Methods($g2Seen) === 'GET ping, GET urls?limit=1, PUT urls/bulk', 'L8: a 404 that is not the change handler\'s own "no such link" does not count as permission to change links (' . $g2Test['reason'] . ')');
assertFalse(str_contains(implode(' ', $g2Test['lines']), 'may change links'), 'L8: ...and it does not say the key may change links');

// 📝 L7: it does not claim to change nothing at all on Go2My.Link.
$g2Seen = [];
$g2Test = go2mylinkTestConnection($g2Opts($g2Script($g2AllGood, $g2Seen)));
assertContains('No link was created or changed', implode(' ', $g2Test['lines']), 'L7: says plainly that no link was created or changed');
assertContains('request log', implode(' ', $g2Test['lines']), 'L7: ...and that the calls are still recorded in Go2My.Link\'s request log and count towards the key\'s limit');

$g2Seen = [];
$g2Test = go2mylinkTestConnection(['settings' => array_merge($g2GoodSettings, ['apiKey' => '', 'problem' => 'not_configured']), 'transport' => $g2Script([], $g2Seen)]);
assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'not_configured' && $g2Seen === [], 'test connection: with no key nothing is sent');

// ==============================================================================
// ✅ Section 8: REAL HTTPS against the stand-in server
// ==============================================================================
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'test_tls.php';

$g2Dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_g2_' . bin2hex(random_bytes(6));
mkdir($g2Dir, 0700);
$g2Tls = testTlsMakeCertificates($g2Dir);
assertTrue($g2Tls['ok'], 'a throwaway certificate authority and server certificate were made: ' . $g2Tls['error']);

$g2LogFile = $g2Dir . DIRECTORY_SEPARATOR . 'requests.log';
touch($g2LogFile);
$g2Pipes   = [];
$g2Process = proc_open(
    [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'go2mylink_standin_server.php', $g2Tls['certFile'], $g2Tls['keyFile'], $g2LogFile, $g2Key],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $g2Pipes
);
$g2Port = 0;
if (is_resource($g2Process)) {
    stream_set_timeout($g2Pipes[1], 10);
    $g2FirstLine = (string) fgets($g2Pipes[1]);
    if (preg_match('/^PORT (\d+)/', $g2FirstLine, $g2PortMatch) === 1) {
        $g2Port = (int) $g2PortMatch[1];
    }
}
assertTrue($g2Port > 0, 'the stand-in Go2My.Link server is listening on 127.0.0.1 (port ' . $g2Port . ')');

$g2Requests = static function () use ($g2LogFile): array {
    $g2Out = [];
    foreach (file($g2LogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $g2Line) {
        $g2Out[] = json_decode($g2Line, true);
    }
    return $g2Out;
};
$g2ClearLog = static function () use ($g2LogFile): void {
    file_put_contents($g2LogFile, '');
};
$g2Real = static function (string $g2Scenario, array $g2Extra = []) use ($g2Port, $g2Key, $g2Tls, $g2Logger): array {
    return array_merge([
        'settings' => [
            'apiBaseUrl'    => 'https://127.0.0.1:' . $g2Port . '/' . $g2Scenario . '/api/v1',
            'apiKey'        => $g2Key,
            'keyUnreadable' => false,
            'timeout'       => 2,
            'shortLinkBase' => 'https://QRmy.Link',
            'problem'       => '',
        ],
        'caFile' => $g2Tls['caFile'],
        'logger' => $g2Logger,
    ], $g2Extra);
};

if ($g2Port > 0) {
    // ✅ Success: create, look up, change destination, scan total.
    $g2ClearLog();
    $g2Result = go2mylinkCreateLink($g2Link, $g2Real('ok'));
    assertTrue($g2Result['ok'] && $g2Result['data']['shortCode'] === 'Ab3dE9x' && $g2Result['data']['codeKept'] === true, 'real HTTPS: create succeeds with the chosen code: ' . json_encode([$g2Result['reason'], $g2Result['adminMessage']]));
    $g2Log = $g2Requests();
    assertTrue(count($g2Log) === 1 && $g2Log[0]['method'] === 'POST' && $g2Log[0]['route'] === 'urls' && $g2Log[0]['auth'] === 'ok', 'real HTTPS: the server received one POST /urls with the right Bearer key');
    assertEquals('none', $g2Log[0]['acceptEncoding'], 'real HTTPS: no Accept-Encoding header was sent (curl unpacks nothing, #150)');
    assertEquals('application/json', $g2Log[0]['accept'], 'real HTTPS: Accept: application/json was sent');
    $g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Real('ok'));
    assertTrue($g2Result['ok'] && $g2Result['data']['destinationUrl'] === 'https://example.com/menu', 'real HTTPS: look up finds it');
    $g2Result = go2mylinkUpdateDestination('Ab3dE9x', 'https://example.com/new', $g2Real('ok'));
    assertTrue($g2Result['ok'] && $g2Result['data']['destinationUrl'] === 'https://example.com/new', 'real HTTPS: the destination is changed');
    $g2Result = go2mylinkScanTotal('Ab3dE9x', $g2Real('ok'));
    assertTrue($g2Result['ok'] && $g2Result['data']['total'] === 41, 'real HTTPS: the scan total is read (41)');
    $g2Result = go2mylinkLookupLink('Nope123', $g2Real('ok'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'not_found', 'real HTTPS: an unknown code is not_found');

    // ❌ The error answers.
    $g2Result = go2mylinkCreateLink(array_merge($g2Link, ['redirectUUID' => '11111111-2222-4333-8444-555555555555', 'shortCode' => 'Forb123']), $g2Real('forbidden'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'integration_off', 'real HTTPS: 403 integration off');
    $g2Result = go2mylinkCreateLink($g2Link, $g2Real('conflict'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'code_taken', 'real HTTPS: 409 code taken');
    $g2Result = go2mylinkCreateLink($g2Link, $g2Real('ok'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'already_linked', 'real HTTPS: 409 the same QR id twice');
    $g2Result = go2mylinkCreateLink($g2Link, $g2Real('reject'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'rejected' && $g2Result['field'] === 'destination_url', 'real HTTPS: 422 destination refused');
    $g2Result = go2mylinkCreateLink($g2Link, $g2Real('ratelimit'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'rate_limited' && $g2Result['retryAfter'] === 60, 'real HTTPS: 429 with Retry-After 60 read from the header');
    $g2Result = go2mylinkCreateLink($g2Link, $g2Real('unavailable'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'unavailable', 'real HTTPS: 503');
    $g2Result = go2mylinkLookupLink('Ab3dE9x', array_merge($g2Real('ok'), ['settings' => array_merge($g2Real('ok')['settings'], ['apiKey' => 'g2ml_WrongKey_' . str_repeat('w', 43)])]));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'auth_failed', 'real HTTPS: 401 wrong key');

    // ⏱️ Time limit: the server waits 3 seconds, the client gives up at 2.
    $g2Start  = microtime(true);
    $g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Real('slow'));
    $g2Took   = microtime(true) - $g2Start;
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'timeout' && $g2Result['outcomeUnknown'] === false, 'real HTTPS: a server slower than the time limit is a timeout (a look-up changes nothing, so nothing is left "not known"): ' . $g2Result['reason']);
    assertTrue($g2Took < 2.9, 'real HTTPS: ...given up after about 2 seconds, not 3 (' . round($g2Took, 2) . ' s)');
    sleep(2);

    // 🔐 Bad certificate: the same server, but the client is not given the
    //    test authority, so the certificate is not trusted.
    $g2ClearLog();
    $g2Result = go2mylinkLookupLink('Ab3dE9x', array_merge($g2Real('ok'), ['caFile' => '']));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'tls_failed' && $g2Result['outcomeUnknown'] === false, 'real HTTPS: an untrusted certificate is refused (tls_failed): ' . $g2Result['reason']);
    assertEquals([], $g2Requests(), 'real HTTPS: ...and no request (so no key) reached the server');
    // 🔐 With the test authority, a name the certificate carries ("localhost") works too.
    $g2Result = go2mylinkLookupLink('Ab3dE9x', array_merge($g2Real('ok'), ['settings' => array_merge($g2Real('ok')['settings'], ['apiBaseUrl' => 'https://localhost:' . $g2Port . '/ok/api/v1'])]));
    assertTrue($g2Result['ok'], 'real HTTPS: a name the certificate does carry (localhost) is accepted');

    // 🧾 Unexpected replies.
    $g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Real('html'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'real HTTPS: a web page instead of JSON is an unexpected reply');
    $g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Real('shape'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'real HTTPS: JSON of the wrong shape is an unexpected reply');
    $g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Real('big'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'real HTTPS: a 200 KB reply is cut off at 64 KB and treated as unexpected');
    // 📏 L3: the 64 KB limit holds WHILE reading (memory), not only afterwards.
    $g2Raw = go2mylinkHttpTransport(go2mylinkBuildRequest($g2Real('big')['settings'], 'GET', 'urls/Ab3dE9x', null), 2, $g2Tls['caFile']);
    assertTrue($g2Raw['tooLarge'] === true && strlen($g2Raw['body']) <= CUERCODE_GO2MYLINK_MAX_REPLY_BYTES, 'L3: reading a 200 KB reply stops at 64 KB (' . strlen($g2Raw['body']) . ' bytes kept)');

    $g2Raw = go2mylinkHttpTransport(go2mylinkBuildRequest($g2Real('bigheaders')['settings'], 'GET', 'ping', null), 2, $g2Tls['caFile']);
    assertTrue($g2Raw['tooLarge'] === true && $g2Raw['sent'] === true, 'M1: over 16 KB of reply headers is reported as a reply too large (after the request was sent)');

    // 🧾 M1: Go2My.Link received the whole create, then answered in a broken way.
    foreach (['garbled' => 'a garbled status line', 'nostatus' => 'no status line at all', 'bigheaders' => 'a 201 with over 16 KB of headers', 'badlength' => 'a 201 with a broken Content-Length'] as $g2Broken => $g2BrokenLabel) {
        $g2ClearLog();
        $g2Result = go2mylinkCreateLink(array_merge($g2Link, ['redirectUUID' => '2fa85f64-5717-4562-b3fc-2c963f66afa2', 'shortCode' => 'Brk7777']), $g2Real($g2Broken));
        $g2Log = $g2Requests();
        assertTrue(count($g2Log) === 1 && $g2Log[0]['method'] === 'POST' && is_array($g2Log[0]['body']), 'real HTTPS, M1 (' . $g2BrokenLabel . '): the server received the whole create');
        assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply' && $g2Result['outcomeUnknown'] === true, 'real HTTPS, M1 (' . $g2BrokenLabel . '): an unreadable answer that may have been carried out, never "nothing happened": ' . $g2Result['reason'] . ' / ' . var_export($g2Result['outcomeUnknown'], true));
    }

    $g2ClearLog();
    $g2Result = go2mylinkLookupLink('Ab3dE9x', $g2Real('redirect'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'bad_reply', 'real HTTPS: a redirect is not a success');
    $g2Log = $g2Requests();
    assertTrue(count($g2Log) === 1 && $g2Log[0]['scenario'] === 'redirect', 'real HTTPS: ...and it was NOT followed (the server saw one request only)');

    // 🔁 The safe retry for real: the create is saved, but the answer comes too late.
    $g2ClearLog();
    $g2Slow   = array_merge($g2Link, ['redirectUUID' => '9fa85f64-5717-4562-b3fc-2c963f66afa9', 'shortCode' => 'Slow777']);
    $g2Result = go2mylinkCreateLinkSafely($g2Slow, $g2Real('slowcreate'));
    sleep(2);
    $g2Log = $g2Requests();
    $g2Seq = [];
    foreach ($g2Log as $g2Entry) {
        $g2Seq[] = $g2Entry['method'] . ' ' . $g2Entry['route'];
    }
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'domain_unconfirmed' && $g2Result['remoteExists'] === true, 'real HTTPS, H1: a create whose answer was too late is found by its code, but Go2My.Link\'s look-up does not give its short domain, so it is held, never printed (' . $g2Result['reason'] . ')');
    assertEquals(['POST urls', 'GET urls/Slow777'], $g2Seq, 'real HTTPS: ...one create and one look-up, never a second create');

    // 🔁 The same once Go2My.Link's look-up gives the short address (issue #276).
    $g2ClearLog();
    $g2Result = go2mylinkCreateLinkSafely(array_merge($g2Link, ['redirectUUID' => '8fa85f64-5717-4562-b3fc-2c963f66afa8', 'shortCode' => 'Slow888']), $g2Real('slowurl'));
    sleep(2);
    assertTrue($g2Result['ok'] && $g2Result['data']['recovered'] === true && $g2Result['data']['shortUrl'] === 'https://QRmy.Link/Slow888', 'real HTTPS: with the short address in the look-up, a late create is confirmed and recovered (' . $g2Result['reason'] . ')');

    // ⚙️ Go2My.Link ignores the chosen code / makes it on the wrong domain.
    $g2Result = go2mylinkCreateLink(array_merge($g2Link, ['redirectUUID' => '7fa85f64-5717-4562-b3fc-2c963f66afa7', 'shortCode' => 'Keep777']), $g2Real('notkept'));
    assertTrue($g2Result['ok'] && $g2Result['data']['codeKept'] === false && $g2Result['data']['shortCode'] !== 'Keep777', 'real HTTPS: Go2My.Link\'s own code is reported when it ignores ours');
    $g2Result = go2mylinkCreateLink(array_merge($g2Link, ['redirectUUID' => '6fa85f64-5717-4562-b3fc-2c963f66afa6', 'shortCode' => 'Dom7777']), $g2Real('wrongdomain'));
    assertTrue(!$g2Result['ok'] && $g2Result['reason'] === 'wrong_short_domain' && $g2Result['remoteExists'] === true, 'real HTTPS: a link made on another short domain is reported, never used');
    // 🔁 H1, the reviewer's reproduction: the same row tried again.
    $g2Result = go2mylinkCreateLinkSafely(array_merge($g2Link, ['redirectUUID' => '6fa85f64-5717-4562-b3fc-2c963f66afa6', 'shortCode' => 'Dom7777']), $g2Real('wrongdomain'));
    assertTrue(!$g2Result['ok'] && $g2Result['remoteExists'] === true, 'real HTTPS, H1: the same row tried again is NOT accepted as a QRmy.Link link: ' . $g2Result['reason']);
    assertFalse(isset($g2Result['data']['shortUrl']) && str_contains((string) $g2Result['data']['shortUrl'], 'QRmy.Link'), 'real HTTPS, H1: ...and no QRmy.Link address is offered for printing');

    // 🔌 Test connection for real: it passes, and nothing was created or switched off.
    $g2ClearLog();
    $g2Test = go2mylinkTestConnection($g2Real('ok'));
    assertTrue($g2Test['ok'], 'real HTTPS: test connection passes: ' . implode(' ', $g2Test['lines']));
    $g2Methods = [];
    foreach ($g2Requests() as $g2Entry) {
        $g2Methods[] = $g2Entry['method'] . ' ' . $g2Entry['route'];
    }
    assertEquals(['GET ping', 'GET urls', 'PUT urls/bulk', 'GET account'], $g2Methods, 'real HTTPS: test connection only read, and tried to change a code that cannot exist');
    $g2Test = go2mylinkTestConnection($g2Real('noqrscope'));
    assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'missing_permission', 'real HTTPS: test connection fails for a key without qr:link');
    $g2Test = go2mylinkTestConnection($g2Real('nowrite'));
    assertTrue(!$g2Test['ok'] && $g2Test['reason'] === 'missing_permission', 'real HTTPS: test connection fails for a key that may not change links');

    // 🙊 Nothing logged anywhere contains the key.
    assertFalse(str_contains(implode("\n", $g2Logged), $g2Key), 'real HTTPS: no log line contains the key');
}

// 🧹 Stop the server (closing its input stops it) and remove the files.
if (is_resource($g2Process)) {
    fclose($g2Pipes[0]);
    fclose($g2Pipes[1]);
    fclose($g2Pipes[2]);
    proc_close($g2Process);
}
testTlsRemove($g2Dir);
assertFalse(is_dir($g2Dir), 'the temporary certificate folder was removed');

// ==============================================================================
// ✅ Section 9: static guards on the source
// ==============================================================================
$g2Source = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_go2mylink.php');
foreach (['error_log(', 'var_dump(', 'print_r(', 'var_export(', 'CURLOPT_SSL_VERIFYPEER => false', 'CURLOPT_FOLLOWLOCATION => true', 'CURLOPT_VERBOSE'] as $g2Never) {
    assertFalse(str_contains($g2Source, $g2Never), 'fn_go2mylink.php never uses ' . $g2Never);
}
assertContains('oauthApplyCurlSafety(', $g2Source, 'fn_go2mylink.php uses the shared safe curl settings (certificates checked, no redirects, https only)');
