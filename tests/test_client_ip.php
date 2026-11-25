<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Visitor address rules (decision 0014)
 * ==============================================================================
 *
 * Path: tests/test_client_ip.php
 *
 * Proves _functions/fn_client_ip.php without a web server or a database:
 *   - one address checked and written the standard way (IPv4, IPv6, IPv4
 *     inside IPv6, ports, junk);
 *   - ranges ("10.0.0.0/8", "2001:db8::/32", exact addresses) and matching,
 *     including ranges that do not end on a whole byte;
 *   - the rule: a faked X-Forwarded-For or CF-Connecting-IP from an ordinary
 *     connection is ignored; Cloudflare's header is used only from a
 *     Cloudflare range, and only when it holds exactly one valid address;
 *     a trusted forwarder's X-Forwarded-For is read from the RIGHT;
 *   - the committed Cloudflare list, the admin/CLI "Visitor address check",
 *     the command-line tool, and that every page uses getClientIP().
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decision 0014, #31)
 * @see         web/CueRCode.net/_functions/fn_client_ip.php
 * @see         docs/decisions/0014-client-address-trust-cloudflare-only-from-its-ranges.md
 */

requireFn('fn_client_ip.php');

$ciSiteRoot = CUERCODE_FUNCTIONS_DIR . '..' . DIRECTORY_SEPARATOR;

// ==============================================================================
// 🧽 One address
// ==============================================================================
assertEquals('192.0.2.1', clientIpNormalise('192.0.2.1', false), 'IPv4 is kept');
assertEquals('192.0.2.1', clientIpNormalise("  192.0.2.1\n", false), 'spaces and a line ending around the address are trimmed');
assertEquals('2001:db8::1', clientIpNormalise('2001:DB8:0:0::1', false), 'IPv6 is written in lower case, shortened');
assertEquals('192.0.2.1', clientIpNormalise('::ffff:192.0.2.1', false), 'IPv4 inside IPv6 becomes plain IPv4');
assertEquals(null, clientIpNormalise('010.0.0.1', false), 'leading zeros are refused');
assertEquals(null, clientIpNormalise('192.0.2.1, 198.51.100.2', false), 'a list is not one address');
assertEquals(null, clientIpNormalise('192.0.2.1 198.51.100.2', false), 'two addresses with a space are not one address');
assertEquals(null, clientIpNormalise('unknown', false), '"unknown" is refused');
assertEquals(null, clientIpNormalise('', false), 'empty is refused');
assertEquals(null, clientIpNormalise(null, false), 'null is refused');
assertEquals(null, clientIpNormalise(['192.0.2.1'], false), 'an array is refused');
assertEquals(null, clientIpNormalise(3232235777, false), 'a number is refused');
assertEquals(null, clientIpNormalise('fe80::1%eth0', false), 'an IPv6 zone is refused');
assertEquals(null, clientIpNormalise(str_repeat('1', 100), false), 'very long text is refused');
assertEquals(null, clientIpNormalise('192.0.2.1:8080', false), 'a port is refused when not allowed');
assertEquals('192.0.2.1', clientIpNormalise('192.0.2.1:8080', true), 'a port is dropped when allowed (IPv4)');
assertEquals('2001:db8::1', clientIpNormalise('[2001:db8::1]:443', true), 'a port is dropped when allowed (IPv6 in brackets)');
assertEquals('2001:db8::1', clientIpNormalise('[2001:db8::1]', true), 'IPv6 in brackets without a port');
assertEquals(null, clientIpNormalise('192.0.2.1:99999999', true), 'a silly port is refused');
assertEquals(null, clientIpNormalise('<script>', true), 'markup is refused');

// ==============================================================================
// 📐 Ranges
// ==============================================================================
$ciRange = clientIpParseRange('10.0.0.0/8');
assertEquals('10.0.0.0/8', $ciRange['text'], 'IPv4 range');
assertEquals('10.0.0.0/8', clientIpParseRange('10.1.2.3/8')['text'], 'bits after the prefix are cleared');
assertEquals('192.0.2.7/32', clientIpParseRange('192.0.2.7')['text'], 'an exact IPv4 address is a /32');
assertEquals('2001:db8::/32', clientIpParseRange('2001:DB8::/32')['text'], 'IPv6 range');
assertEquals('2001:db8::1/128', clientIpParseRange('2001:db8::1')['text'], 'an exact IPv6 address is a /128');
assertEquals('0.0.0.0/0', clientIpParseRange('0.0.0.0/0')['text'], '/0 is allowed (everything)');
assertEquals('173.245.48.0/20', clientIpParseRange('173.245.50.9/20')['text'], 'a range that does not end on a whole byte is cleared correctly');
foreach (['10.0.0.0/33', '2001:db8::/129', '10.0.0.0/-1', '10.0.0.0/', '10.0.0.0/8/9', 'abc/8', '10.0.0.0/ 8', '10.0.0.0/8a', '', 'garbage', '10.0.0.0/0008'] as $ciBadRange) {
    assertEquals(null, clientIpParseRange($ciBadRange), 'refused range: "' . $ciBadRange . '"');
}

$ciList = clientIpParseRangeList("10.0.0.0/8, 192.0.2.1\n2001:db8::/32 bogus,,  ");
assertEquals(3, count($ciList['ranges']), 'a list split by commas, spaces and new lines gives 3 ranges');
assertEquals(['bogus'], $ciList['invalid'], '...and the bad entry is reported, never trusted');
assertEquals(['ranges' => [], 'invalid' => []], clientIpParseRangeList(''), 'an empty setting trusts nothing');
assertEquals(['ranges' => [], 'invalid' => []], clientIpParseRangeList(null), 'a missing setting trusts nothing');

$ciTen = [clientIpParseRange('10.0.0.0/8')];
assertTrue(clientIpInRanges('10.255.255.255', $ciTen), '10.255.255.255 is inside 10.0.0.0/8');
assertFalse(clientIpInRanges('11.0.0.0', $ciTen), '11.0.0.0 is outside 10.0.0.0/8');
$ciTwenty = [clientIpParseRange('173.245.48.0/20')];
assertTrue(clientIpInRanges('173.245.63.255', $ciTwenty), '/20: last address inside');
assertFalse(clientIpInRanges('173.245.64.0', $ciTwenty), '/20: first address after is outside');
assertFalse(clientIpInRanges('173.245.47.255', $ciTwenty), '/20: last address before is outside');
$ciSix = [clientIpParseRange('2a06:98c0::/29')];
assertTrue(clientIpInRanges('2a06:98c7:ffff::1', $ciSix), 'IPv6 /29: last block inside');
assertFalse(clientIpInRanges('2a06:98c8::1', $ciSix), 'IPv6 /29: next block outside');
assertFalse(clientIpInRanges('10.0.0.1', $ciSix), 'IPv4 never matches an IPv6 range');
assertFalse(clientIpInRanges('2001:db8::1', [clientIpParseRange('0.0.0.0/0')]), 'IPv6 never matches an IPv4 range, even /0');
assertTrue(clientIpInRanges('198.51.100.7', [clientIpParseRange('0.0.0.0/0')]), '0.0.0.0/0 matches any IPv4');
assertTrue(clientIpInRanges('192.0.2.7', [clientIpParseRange('192.0.2.7')]), 'an exact address matches itself');
assertFalse(clientIpInRanges('192.0.2.8', [clientIpParseRange('192.0.2.7')]), '...and nothing else');
assertFalse(clientIpInRanges('not-an-address', $ciTen), 'junk is never inside anything');
assertFalse(clientIpInRanges('10.0.0.1', [['network' => 'x']]), 'a broken range entry is skipped');

// ==============================================================================
// ☁️ The committed Cloudflare list
// ==============================================================================
$ciCloudflare = clientIpCloudflareData();
assertTrue($ciCloudflare['ok'], 'the Cloudflare list loads');
assertEquals(22, count($ciCloudflare['ranges']), 'the Cloudflare list has 15 IPv4 + 7 IPv6 ranges (2026-11-25)');
assertEquals([], $ciCloudflare['invalid'], 'every Cloudflare entry is a valid range');
assertMatches('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $ciCloudflare['fetchedOn'], 'the list carries the date it was fetched');
assertTrue(in_array('https://www.cloudflare.com/ips-v4', $ciCloudflare['sources'], true) && in_array('https://www.cloudflare.com/ips-v6', $ciCloudflare['sources'], true), 'the list names Cloudflare\'s own sources');
$ciCfTexts = [];
foreach ($ciCloudflare['ranges'] as $ciCfRange) {
    $ciCfTexts[] = $ciCfRange['text'];
}
foreach (['173.245.48.0/20', '162.158.0.0/15', '104.16.0.0/13', '2606:4700::/32', '2a06:98c0::/29'] as $ciKnown) {
    assertTrue(in_array($ciKnown, $ciCfTexts, true), 'the Cloudflare list includes ' . $ciKnown);
}
$ciDataSource = (string) file_get_contents($ciSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cloudflare_ip_ranges.php');
assertContains('https://www.cloudflare.com/ips/', $ciDataSource, 'the data file tells the owner where to check it');
assertContains('tools/refresh_cloudflare_ranges.php', $ciDataSource, 'the data file says how to refresh it');
$ciMissing = clientIpCloudflareData(CUERCODE_FUNCTIONS_DIR . 'no-such-file.php');
assertFalse($ciMissing['ok'], 'a missing list is reported as missing');
assertEquals([], $ciMissing['ranges'], '...and then no Cloudflare address is trusted');
assertTrue(clientIpCloudflareData()['ok'], 'asking for another file does not spoil the remembered real list');
$ciCf = $ciCloudflare['ranges'];

// ==============================================================================
// 🧭 The rule
// ==============================================================================
$ciTrusted = clientIpParseRangeList('10.0.0.0/8, 192.0.2.10, 2001:db8:1::/48')['ranges'];

// 🚪 Ordinary connections: every forwarded header is ignored.
$ciR = resolveClientAddress(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99'], $ciTrusted, $ciCf);
assertEquals('198.51.100.10', $ciR['address'], 'SPOOF: X-Forwarded-For from an ordinary connection is ignored');
assertEquals('connection', $ciR['source'], '...the connecting address is the source');
assertEquals(['X-Forwarded-For'], $ciR['ignoredHeaders'], '...and the ignored header is named');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.99'], $ciTrusted, $ciCf);
assertEquals('198.51.100.10', $ciR['address'], 'SPOOF: CF-Connecting-IP from a connection outside Cloudflare is ignored');
assertFalse($ciR['fromCloudflare'], '...it is not counted as Cloudflare');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_REAL_IP' => '203.0.113.99', 'HTTP_TRUE_CLIENT_IP' => '203.0.113.98', 'HTTP_FORWARDED' => 'for=203.0.113.97'], $ciTrusted, $ciCf);
assertEquals('198.51.100.10', $ciR['address'], 'SPOOF: X-Real-IP / True-Client-IP / Forwarded from an ordinary connection are ignored');
assertEquals(3, count($ciR['ignoredHeaders']), '...and all three are listed as ignored');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '198.51.100.10'], $ciTrusted, $ciCf);
assertEquals('A direct connection: the connecting address is used.', $ciR['note'], 'a plain direct connection says so');

// ☁️ Cloudflare.
$ciR = resolveClientAddress(['REMOTE_ADDR' => '162.158.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], [], $ciCf);
assertEquals('203.0.113.9', $ciR['address'], 'CLOUDFLARE: from a Cloudflare range, CF-Connecting-IP is used');
assertEquals('CF-Connecting-IP', $ciR['source'], '...and named as the source');
assertTrue($ciR['fromCloudflare'], '...and the connection counts as Cloudflare');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '2001:DB8::5'], [], $ciCf);
assertEquals('2001:db8::5', $ciR['address'], 'CLOUDFLARE over IPv6, with an IPv6 visitor (written the standard way)');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '::ffff:162.158.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], [], $ciCf);
assertEquals('203.0.113.9', $ciR['address'], 'CLOUDFLARE reported as IPv4-inside-IPv6 still counts');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '162.158.0.5'], [], $ciCf);
assertEquals('162.158.0.5', $ciR['address'], 'CLOUDFLARE without the header: Cloudflare\'s own address is used');
assertEquals('connection', $ciR['source'], '...from the connection');
foreach (['203.0.113.9, 198.51.100.1', 'unknown', '203.0.113.9:80', '', '   ', '999.1.1.1'] as $ciBadHeader) {
    $ciR = resolveClientAddress(['REMOTE_ADDR' => '162.158.0.5', 'HTTP_CF_CONNECTING_IP' => $ciBadHeader], [], $ciCf);
    assertEquals('162.158.0.5', $ciR['address'], 'CLOUDFLARE with a malformed header "' . $ciBadHeader . '": Cloudflare\'s own address is used');
}
$ciR = resolveClientAddress(['REMOTE_ADDR' => '162.158.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], [], $ciCf);
assertEquals('162.158.0.5', $ciR['address'], 'CLOUDFLARE: X-Forwarded-For alone is not used (only CF-Connecting-IP)');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '162.158.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], [], []);
assertEquals('162.158.0.5', $ciR['address'], 'with NO Cloudflare list, the header is not trusted');

// 🏢 A trusted forwarder: X-Forwarded-For read from the right.
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.9'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'MULTI-HOP: the right-most entry that is not a trusted forwarder is used (not the faked first one)');
assertEquals('X-Forwarded-For', $ciR['source'], '...from X-Forwarded-For');
assertTrue($ciR['fromTrustedProxy'], '...and the connection counts as a trusted forwarder');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'garbage, 198.51.100.7'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'junk the visitor wrote further left is never reached');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 162.158.0.1'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'a Cloudflare hop in the chain is skipped like a trusted forwarder');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, not-an-ip'], $ciTrusted, $ciCf);
assertEquals('10.0.0.2', $ciR['address'], 'MALFORMED right-most entry: the forwarder\'s own address is used');
assertEquals('connection', $ciR['source'], '...from the connection');
assertTrue(in_array('X-Forwarded-For', $ciR['ignoredHeaders'], true), '...and the header is reported as ignored');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'bad, 10.0.0.9'], $ciTrusted, $ciCf);
assertEquals('10.0.0.9', $ciR['address'], 'MALFORMED entry after a trusted hop: the nearest trusted forwarder is used');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 10.0.0.9'], $ciTrusted, $ciCf);
assertEquals('10.0.0.5', $ciR['address'], 'every entry trusted: the furthest one is used');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '[2001:db8:9::7]:443, 10.0.0.9'], $ciTrusted, $ciCf);
assertEquals('2001:db8:9::7', $ciR['address'], 'an IPv6 entry with brackets and a port');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:5555'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'an IPv4 entry with a port');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2'], $ciTrusted, $ciCf);
assertEquals('10.0.0.2', $ciR['address'], 'a trusted forwarder with no X-Forwarded-For: its own address');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => str_repeat('198.51.100.7, ', 200)], $ciTrusted, $ciCf);
assertEquals('10.0.0.2', $ciR['address'], 'an over-long X-Forwarded-For is treated as missing');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REAL_IP' => '203.0.113.99'], $ciTrusted, $ciCf);
assertEquals('10.0.0.2', $ciR['address'], 'X-Real-IP is never used, even from a trusted forwarder');
$ciHops = '198.51.100.7';
for ($ciHop = 1; $ciHop <= 25; $ciHop++) {
    $ciHops .= ', 10.0.0.' . $ciHop;
}
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => $ciHops], $ciTrusted, $ciCf);
assertEquals('10.0.0.6', $ciR['address'], 'only the right-most ' . CUERCODE_CLIENT_IP_MAX_FORWARDED_HOPS . ' entries are looked at');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'an EXACT trusted address (no range) works');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '192.0.2.11', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], $ciTrusted, $ciCf);
assertEquals('192.0.2.11', $ciR['address'], '...its neighbour is not trusted');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '2001:db8:1::5', 'HTTP_X_FORWARDED_FOR' => '2001:db8:2::9'], $ciTrusted, $ciCf);
assertEquals('2001:db8:2::9', $ciR['address'], 'an IPv6 trusted range (/48)');

// ❓ Bad connecting addresses.
assertEquals('unknown', resolveClientAddress([], $ciTrusted, $ciCf)['address'], 'no REMOTE_ADDR: unknown');
assertEquals('unknown', resolveClientAddress(['REMOTE_ADDR' => 'nonsense', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], $ciTrusted, $ciCf)['address'], 'an invalid REMOTE_ADDR: unknown (headers not used)');

// ==============================================================================
// 🌍 getClientIP() - the real request
// ==============================================================================
$ciSaved = [];
foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP'] as $ciKey) {
    $ciSaved[$ciKey] = array_key_exists($ciKey, $_SERVER) ? [$_SERVER[$ciKey]] : null;
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1';
assertEquals('198.51.100.20', getClientIP(), 'getClientIP(): a faked X-Forwarded-For is ignored');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '104.16.1.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.2';
assertEquals('203.0.113.2', getClientIP(), 'getClientIP(): Cloudflare\'s header from a Cloudflare address');
unset($_SERVER['REMOTE_ADDR']);
assertEquals('unknown', getClientIP(), 'getClientIP(): no connecting address gives "unknown"');
foreach ($ciSaved as $ciKey => $ciValue) {
    if ($ciValue === null) {
        unset($_SERVER[$ciKey]);
    } else {
        $_SERVER[$ciKey] = $ciValue[0];
    }
}
assertEquals(['raw' => '', 'ranges' => [], 'invalid' => []], clientIpTrustedProxySetting(), 'without the settings reader, no forwarder is trusted');

// ==============================================================================
// 🩺 The "Visitor address check"
// ==============================================================================
$ciCheck = clientAddressCheck(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.99', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], ['ranges' => [], 'invalid' => ['oops']], $ciCloudflare);
$ciLabels = [];
foreach ($ciCheck['rows'] as $ciRow) {
    $ciLabels[$ciRow['label']] = $ciRow['value'];
}
assertEquals('198.51.100.10', $ciLabels['Connecting address'], 'check: shows the connecting address');
assertEquals('None - the connecting address itself', $ciLabels['Header used'], 'check: says no header was used');
assertEquals('198.51.100.10', $ciLabels['Address the site will use'], 'check: shows the address the site will use');
assertEquals('No', $ciLabels['Connecting address inside a Cloudflare range'], 'check: says whether it is inside a Cloudflare range');
assertContains('fetched on', $ciLabels['Cloudflare address list'], 'check: shows the Cloudflare list date');
assertEquals(3, count($ciCheck['warnings']), 'check: warns about the bad setting entry, the ignored CF header and the ignored X-Forwarded-For');
assertContains('oops', $ciCheck['warnings'][0], 'check: names the bad setting entry');
$ciCheck = clientAddressCheck(['REMOTE_ADDR' => '162.158.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], ['ranges' => [], 'invalid' => []], $ciCloudflare);
assertEquals([], $ciCheck['warnings'], 'check: a correct Cloudflare request gives no warning');
assertEquals('203.0.113.9', $ciCheck['resolved']['address'], 'check: the Cloudflare visitor address');
$ciCheck = clientAddressCheck(['REMOTE_ADDR' => '162.158.0.5'], ['ranges' => [], 'invalid' => []], ['ok' => false, 'ranges' => [], 'invalid' => []]);
assertContains('Missing', $ciCheck['rows'][7]['value'], 'check: a missing Cloudflare list is shown as missing');

// ==============================================================================
// 🧱 Wiring: every page uses the same answer
// ==============================================================================
$ciBootstrap = (string) file_get_contents($ciSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php');
assertTrue(strpos($ciBootstrap, "'fn_client_ip.php'") !== false && strpos($ciBootstrap, "'fn_client_ip.php'") < strpos($ciBootstrap, "'fn_error_handler.php'"), 'bootstrap.php loads fn_client_ip.php on every page, before the error handler');
assertFalse(str_contains($ciBootstrap, "'fn_api_auth.php'"), 'bootstrap.php still does not load fn_api_auth.php globally');
$ciApiAuth = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_auth.php');
assertContains("require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_client_ip.php';", $ciApiAuth, 'fn_api_auth.php loads fn_client_ip.php for the API');
assertFalse(str_contains($ciApiAuth, 'function getClientIP('), 'fn_api_auth.php no longer has its own getClientIP()');
$ciClientSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_client_ip.php');
assertFalse(str_contains($ciClientSource, "explode(',', \$ip)[0]"), 'the old "first X-Forwarded-For entry" code is gone');
assertFalse(str_contains($ciClientSource, 'function isInteractiveSessionAuth('), 'fn_client_ip.php does not pull API-only functions into every page');
assertContains('array_reverse(explode(', $ciClientSource, 'X-Forwarded-For is read from the right');
assertFalse((bool) preg_match('/\bfn\s*\(/', $ciClientSource), 'fn_client_ip.php has no arrow functions (house rule)');
assertFalse((bool) preg_match('/\bmatch\s*\(/', $ciClientSource), 'fn_client_ip.php has no match() (house rule)');
assertFalse(str_contains((string) preg_replace('#/\*.*?\*/#s', '', $ciClientSource), '??'), 'fn_client_ip.php code has no ?? (house rule)');
assertContains('@copyright   2025–2026 MWBM Partners Ltd', $ciClientSource, 'fn_client_ip.php has the house copyright header');

foreach (['login', 'forgot-password', 'resend-verification'] as $ciPage) {
    $ciPageSource = (string) file_get_contents($ciSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . $ciPage . DIRECTORY_SEPARATOR . 'index.php');
    assertContains('$clientIp = getClientIP();', $ciPageSource, $ciPage . ': uses getClientIP()');
    assertFalse(str_contains($ciPageSource, "\$_SERVER['REMOTE_ADDR'] ?? 'unknown'"), $ciPage . ': no longer falls back to REMOTE_ADDR on its own');
}
$ciResend = (string) file_get_contents($ciSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'resend-verification' . DIRECTORY_SEPARATOR . 'index.php');
assertFalse(str_contains($ciResend, "'fn_api_auth.php'"), 'resend-verification no longer loads the API file just for the address');
$ciAdmin = (string) file_get_contents($ciSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.php');
assertContains('clientAddressCheck($_SERVER', $ciAdmin, 'the admin overview runs the visitor address check');
assertContains('Visitor address check', $ciAdmin, 'the admin overview shows the "Visitor address check" card');
assertContains('adminEscape($adminAddressRow[\'value\'])', $ciAdmin, 'the check\'s values are escaped before display');

// ==============================================================================
// 💻 The command-line check (run for real, with --trusted so no database is needed)
// ==============================================================================
$ciCliPath   = $ciSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'visitor_address.php';
$ciCliSource = (string) file_get_contents($ciCliPath);
$ciCode = [];
foreach (token_get_all($ciCliSource) as $ciToken) {
    if (is_array($ciToken) && in_array($ciToken[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $ciCode[] = is_array($ciToken) ? $ciToken[1] : $ciToken;
    if (count($ciCode) === 4) {
        break;
    }
}
assertEquals(['if', '(', 'PHP_SAPI', '!=='], $ciCode, 'visitor_address.php: the first statement refuses anything but the command line');

/**
 * 🧪 Run the CLI tool as a child process.
 *
 * @param array<int, string> $ciArgs Arguments
 * @return array{exit: int, out: string}
 */
function ciRunCli(string $ciPath, array $ciArgs): array
{
    $ciCommand = array_merge([PHP_BINARY, $ciPath], $ciArgs);
    $ciPipes   = [];
    $ciProcess = proc_open($ciCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ciPipes);
    if (!is_resource($ciProcess)) {
        return ['exit' => -1, 'out' => ''];
    }
    $ciOut = (string) stream_get_contents($ciPipes[1]) . (string) stream_get_contents($ciPipes[2]);
    fclose($ciPipes[1]);
    fclose($ciPipes[2]);
    return ['exit' => proc_close($ciProcess), 'out' => $ciOut];
}

$ciRun = ciRunCli($ciCliPath, ['--trusted=10.0.0.0/8', '--remote=162.158.1.1', '--cf=203.0.113.9']);
assertEquals(0, $ciRun['exit'], 'CLI: a pretend Cloudflare request exits 0');
assertContains('Address the site will use: 203.0.113.9', $ciRun['out'], 'CLI: shows the address the site will use');
assertContains('Connecting address inside a Cloudflare range: Yes', $ciRun['out'], 'CLI: shows it is inside a Cloudflare range');
assertContains('Header used: CF-Connecting-IP', $ciRun['out'], 'CLI: shows which header was used');
$ciRun = ciRunCli($ciCliPath, ['--trusted=10.0.0.0/8', '--remote=10.1.2.3', '--xff=6.6.6.6, 198.51.100.7, 10.0.0.9']);
assertContains('Address the site will use: 198.51.100.7', $ciRun['out'], 'CLI: a trusted forwarder\'s X-Forwarded-For is read from the right');
$ciRun = ciRunCli($ciCliPath, ['--trusted=', '--remote=198.51.100.1', '--xff=1.2.3.4']);
assertContains('Address the site will use: 198.51.100.1', $ciRun['out'], 'CLI: a faked header from an ordinary connection is ignored');
$ciRun = ciRunCli($ciCliPath, ['--trusted=10.0.0.0/8, bogus']);
assertEquals(0, $ciRun['exit'], 'CLI: the settings summary exits 0');
assertContains('Ignored (not valid addresses or ranges): bogus', $ciRun['out'], 'CLI: bad setting entries are named');
$ciRun = ciRunCli($ciCliPath, ['--nonsense']);
assertEquals(2, $ciRun['exit'], 'CLI: an unknown option exits 2');
