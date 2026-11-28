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
foreach (['10.0.0.0/33', '2001:db8::/129', '10.0.0.0/-1', '10.0.0.0/', '10.0.0.0/8/9', 'abc/8', '10.0.0.0/ 8', '10.0.0.0/8a', '', 'garbage', '10.0.0.0/0008', '10.0.0.0/024', '10.0.0.0/08', '10.0.0.0/00', '2001:db8::/032'] as $ciBadRange) {
    assertEquals(null, clientIpParseRange($ciBadRange), 'refused range: "' . $ciBadRange . '"');
}

$ciList = clientIpParseRangeList("10.0.0.0/8, 192.0.2.1\n2001:db8::/32 bogus,,  ");
assertEquals(3, count($ciList['ranges']), 'a list split by commas, spaces and new lines gives 3 ranges');
assertEquals(['bogus'], $ciList['invalid'], '...and the bad entry is reported, never trusted');
assertEquals(1, count($ciList['problems']), '...with one plain-English reason');
assertContains('"bogus" is not a valid address or range', $ciList['problems'][0], '...naming the entry');
assertEquals(['ranges' => [], 'invalid' => [], 'problems' => []], clientIpParseRangeList(''), 'an empty setting trusts nothing');
assertEquals(['ranges' => [], 'invalid' => [], 'problems' => []], clientIpParseRangeList(null), 'a missing setting trusts nothing');

// 📏 Decision 0015: the owner's list refuses ranges wider than /8 (IPv4) or /32 (IPv6).
assertEquals(8, CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV4, '0015: the IPv4 limit is /8');
assertEquals(32, CUERCODE_TRUSTED_PROXY_MIN_PREFIX_IPV6, '0015: the IPv6 limit is /32');
foreach (['0.0.0.0/0', '10.0.0.0/7', '128.0.0.0/1', '::/0', '2001:db8::/31', '2000::/3'] as $ciWide) {
    $ciWideList = clientIpParseRangeList($ciWide);
    assertEquals([], $ciWideList['ranges'], '0015: "' . $ciWide . '" is never trusted');
    assertEquals([$ciWide], $ciWideList['invalid'], '0015: "' . $ciWide . '" is reported as refused');
    assertContains('too wide', $ciWideList['problems'][0], '0015: "' . $ciWide . '" says it is too wide');
}
$ciWideV4 = clientIpParseRangeList('0.0.0.0/0')['problems'][0];
assertContains('widest range accepted is /8', $ciWideV4, '0015: the IPv4 message names the /8 limit');
assertContains('choose the address the site records', $ciWideV4, '0015: ...and says why in plain words');
assertContains('widest range accepted is /32', clientIpParseRangeList('::/0')['problems'][0], '0015: the IPv6 message names the /32 limit');
$ciEdge = clientIpParseRangeList('10.0.0.0/8, 2001:db8::/32, 192.0.2.7, 2001:db8::1');
assertEquals(4, count($ciEdge['ranges']), '0015: exactly /8 and /32 (and single addresses) are accepted');
assertEquals([], $ciEdge['problems'], '0015: ...with no problems');
$ciPadded = clientIpParseRangeList('10.0.0.0/024');
assertEquals(['10.0.0.0/024'], $ciPadded['invalid'], '0015: "/024" is refused in the owner\'s list');
assertContains('without the zero', $ciPadded['problems'][0], '0015: ...with a message saying to drop the leading zero');
$ciMixed = clientIpParseRangeList('10.0.0.0/8, 0.0.0.0/0, 192.0.2.10');
assertEquals(2, count($ciMixed['ranges']), '0015: a too-wide entry does not spoil the good ones (they are still read)');
assertTrue(clientIpParseRange('0.0.0.0/0') !== null, '0015: the low-level reader still understands /0 (the WIDTH rule is separate)');
assertEquals(null, clientIpRangeTooWideMessage(clientIpParseRange('10.0.0.0/8'), 8, 32), '0015: clientIpRangeTooWideMessage(): /8 is fine');
assertFalse(str_contains((string) clientIpRangeTooWideMessage(clientIpParseRange('0.0.0.0/0'), 8, 24, false), 'forwarding server'), '0015: the Cloudflare form of the message gives only the facts');

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
// ☁️ A Cloudflare hop in the chain (review finding 4 + final review M-2):
//    the walk STOPS at the Cloudflare address, and the entry to its left is
//    never used on its own. CF-Connecting-IP (which Cloudflare sets itself)
//    is the answer when it holds one valid address; without it, the
//    Cloudflare address that connected to our forwarder is the answer.
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 162.158.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'M-2: a Cloudflare hop in the chain, CF-Connecting-IP agrees with the entry next to it: that address is used');
assertEquals('CF-Connecting-IP', $ciR['source'], 'M-2: ...named as coming from CF-Connecting-IP');
assertContains('agrees with it', $ciR['note'], 'M-2: ...and the note says the two agree');
assertFalse($ciR['fromCloudflare'], 'M-2: ...the connecting address is still the owner\'s forwarder, not Cloudflare');
assertEquals([], $ciR['ignoredHeaders'], 'M-2: ...and neither header is reported as ignored');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 162.158.0.1'], $ciTrusted, $ciCf);
assertEquals('162.158.0.1', $ciR['address'], 'M-2: a Cloudflare hop with NO CF-Connecting-IP: the entry next to it is NOT trusted alone - the Cloudflare address that reached our forwarder is used');
assertEquals('X-Forwarded-For', $ciR['source'], 'M-2: ...(read from X-Forwarded-For)');
assertContains('without one valid address in CF-Connecting-IP', $ciR['note'], 'M-2: ...and the note says why');
foreach (['not-an-ip', '198.51.100.7, 198.51.100.8', '198.51.100.7:80', ''] as $ciBadCf) {
    $ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 162.158.0.1', 'HTTP_CF_CONNECTING_IP' => $ciBadCf], $ciTrusted, $ciCf);
    assertEquals('162.158.0.1', $ciR['address'], 'M-2: a malformed CF-Connecting-IP "' . $ciBadCf . '" counts as missing: the Cloudflare address is used');
}
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 162.158.0.1, 10.0.0.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'F4: visitor junk, Cloudflare-written address, Cloudflare hop, our forwarder: Cloudflare\'s answer');
assertContains('CF-Connecting-IP header is used', $ciR['note'], 'F4: ...and the note says Cloudflare\'s header was used');

// 🤖 THE DIRECT-WORKER CASE (final review M-2): a Cloudflare Worker on
//    somebody else's Cloudflare account sends a request straight to our
//    forwarder. The Worker writes X-Forwarded-For itself ("6.6.6.6", its
//    chosen address), our forwarder adds the Cloudflare address that
//    connected to it. The old rule used "6.6.6.6". Now: Cloudflare's own
//    CF-Connecting-IP (for a Worker, an address of Cloudflare's - here the
//    one Cloudflare documents for Workers) or, without it, the Cloudflare hop.
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 104.16.5.5', 'HTTP_CF_CONNECTING_IP' => '2a06:98c0:3600::103'], $ciTrusted, $ciCf);
assertEquals('2a06:98c0:3600::103', $ciR['address'], 'M-2 WORKER: direct Worker to our forwarder, with Cloudflare\'s header: Cloudflare\'s answer is used');
assertFalse($ciR['address'] === '6.6.6.6', 'M-2 WORKER: ...NOT the address the Worker wrote');
assertContains('does not match it and is NOT used', $ciR['note'], 'M-2 WORKER: ...and the note says the forwarded entry disagreed and was not used');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 104.16.5.5'], $ciTrusted, $ciCf);
assertEquals('104.16.5.5', $ciR['address'], 'M-2 WORKER: direct Worker to our forwarder, no Cloudflare header: the Cloudflare address is used, NOT the Worker\'s choice');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 104.16.9.9, 162.158.0.1'], $ciTrusted, $ciCf);
assertEquals('162.158.0.1', $ciR['address'], 'F4 WORKERS: Cloudflare-looking addresses written by a Worker are never used on their own');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 172.64.0.9, 104.16.9.9, 162.158.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.44'], $ciTrusted, $ciCf);
assertEquals('203.0.113.44', $ciR['address'], 'F4 WORKERS: a whole run of Cloudflare-looking addresses: Cloudflare\'s header decides, not the run');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 10.0.0.77, 162.158.0.1', 'HTTP_CF_CONNECTING_IP' => '10.0.0.77'], $ciTrusted, $ciCf);
assertEquals('10.0.0.77', $ciR['address'], 'F4: even an address in the owner\'s own list is used when Cloudflare\'s header says so (not skipped)');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '162.158.0.1'], $ciTrusted, $ciCf);
assertEquals('162.158.0.1', $ciR['address'], 'F4: a Cloudflare hop with nothing to its left and no header: Cloudflare\'s address is used');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '162.158.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.45'], $ciTrusted, $ciCf);
assertEquals('203.0.113.45', $ciR['address'], 'M-2: a Cloudflare hop with nothing to its left but a valid header: the header is used');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'garbage, 162.158.0.1'], $ciTrusted, $ciCf);
assertEquals('162.158.0.1', $ciR['address'], 'F4: a Cloudflare hop with junk to its left: Cloudflare\'s address is used, the junk is not');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 104.16.5.5', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6'], $ciTrusted, $ciCf);
assertEquals('198.51.100.10', $ciR['address'], 'M-2: the same headers from a connection that is NOT a trusted forwarder: all ignored (rule 4)');
$ciR = resolveClientAddress(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.8, 10.0.0.9'], $ciTrusted, $ciCf);
assertEquals('198.51.100.7', $ciR['address'], 'F4: the owner\'s own forwarders are still skipped (two in a row)');
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
assertEquals(['raw' => '', 'ranges' => [], 'invalid' => [], 'problems' => []], clientIpTrustedProxySetting(), 'without the settings reader, no forwarder is trusted');

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
$ciWideSetting = clientIpParseRangeList('0.0.0.0/0, 10.0.0.0/8');
$ciCheck = clientAddressCheck(['REMOTE_ADDR' => '198.51.100.10'], $ciWideSetting, $ciCloudflare);
assertEquals(1, count($ciCheck['warnings']), '0015 check: one warning for the refused wide entry');
assertContains('too wide', $ciCheck['warnings'][0], '0015 check: ...saying it is too wide');
assertEquals('10.0.0.0/8', $ciCheck['rows'][6]['value'], '0015 check: only the accepted range is listed as trusted');

// ☁️ Review nit: Cloudflare data may not hold a very wide range either.
$ciCfGood = clientIpCheckCloudflareList(['173.245.48.0/20', '104.16.0.0/13'], 4);
assertEquals(['173.245.48.0/20', '104.16.0.0/13'], $ciCfGood['texts'], 'Cloudflare check: real ranges are accepted');
assertEquals('', $ciCfGood['problem'], 'Cloudflare check: ...with no problem');
assertEquals(['2a06:98c0::/29'], clientIpCheckCloudflareList(['2a06:98c0::/29'], 6)['texts'], 'Cloudflare check: their widest real IPv6 range (/29) is accepted');
foreach ([[['0.0.0.0/0'], 4], [['173.245.48.0/20', '0.0.0.0/7'], 4], [['::/0'], 6], [['2000::/23'], 6]] as $ciCfCase) {
    $ciCfBad = clientIpCheckCloudflareList($ciCfCase[0], $ciCfCase[1]);
    assertEquals(null, $ciCfBad['texts'], 'Cloudflare check: a list holding ' . end($ciCfCase[0]) . ' is refused as a whole');
    assertContains('too wide', $ciCfBad['problem'], 'Cloudflare check: ...saying it is too wide');
}
assertEquals(null, clientIpCheckCloudflareList(['2606:4700::/32'], 4)['texts'], 'Cloudflare check: an IPv6 range in the IPv4 list is refused');
assertEquals(null, clientIpCheckCloudflareList([], 4)['texts'], 'Cloudflare check: an empty list is refused');
assertEquals(null, clientIpCheckCloudflareList(['104.16.0.0/013'], 4)['texts'], 'Cloudflare check: a zero-padded "/013" is refused');
$ciCfTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_cf_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($ciCfTemp, "<?php\nreturn ['fetchedOn' => '2026-11-25', 'sources' => [], 'ipv4' => ['0.0.0.0/0', '173.245.48.0/20'], 'ipv6' => ['::/0']];\n");
$ciCfTampered = clientIpCloudflareData($ciCfTemp);
unlink($ciCfTemp);
assertEquals(['0.0.0.0/0', '::/0'], $ciCfTampered['invalid'], 'Cloudflare file: a /0 in a tampered file is listed as invalid');
assertEquals(1, count($ciCfTampered['ranges']), 'Cloudflare file: ...and only the real range is trusted');
assertFalse(clientIpInRanges('198.51.100.7', $ciCfTampered['ranges']), 'Cloudflare file: an ordinary address is NOT treated as Cloudflare');
$ciRefreshSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'refresh_cloudflare_ranges.php');
assertContains('clientIpCheckCloudflareList($entries, $family)', $ciRefreshSource, 'the refresh tool checks every fetched list with clientIpCheckCloudflareList()');

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
assertContains('Ignored: "bogus" is not a valid address or range', $ciRun['out'], 'CLI: bad setting entries are named, with the reason');
$ciRun = ciRunCli($ciCliPath, ['--trusted=0.0.0.0/0, 10.0.0.0/8']);
assertContains('Ignored: "0.0.0.0/0" is too wide', $ciRun['out'], '0015 CLI: a /0 entry is refused and the reason printed');
assertContains('Trusted forwarders (given with --trusted): 10.0.0.0/8', $ciRun['out'], '0015 CLI: only the accepted range is trusted');
$ciRun = ciRunCli($ciCliPath, ['--trusted=0.0.0.0/0', '--remote=198.51.100.1', '--xff=6.6.6.6']);
assertContains('Address the site will use: 198.51.100.1', $ciRun['out'], '0015 CLI: with only a /0 entry, nobody is a trusted forwarder (the faked header is ignored)');
$ciRun = ciRunCli($ciCliPath, ['--nonsense']);
assertEquals(2, $ciRun['exit'], 'CLI: an unknown option exits 2');

// ==============================================================================
// #177: every per-address limit counts an IPv6 address as its /64 network
// ==============================================================================

assertTrue(function_exists('clientAddressCountingKey'), '#177: there is ONE rule for the address a per-address limit counts under, next to getClientIP()');
if (function_exists('clientAddressCountingKey')) {
    assertEquals(clientAddressCountingKey('2001:db8:1:2:3:4:5:6'), clientAddressCountingKey('2001:db8:1:2:ffff:ffff:ffff:1'), '#177: two IPv6 addresses in one /64 network count as one visitor');
    assertEquals('2001:db8:1:2::/64', clientAddressCountingKey('2001:DB8:1:2::99'), '#177: the key is the /64 network, written the short way');
    assertTrue(clientAddressCountingKey('2001:db8:1:2::1') !== clientAddressCountingKey('2001:db8:1:3::1'), '#177: different /64 networks count apart');
    assertEquals('198.51.100.7', clientAddressCountingKey('198.51.100.7'), '#177: an IPv4 address is counted as it is');
    assertEquals('198.51.100.7', clientAddressCountingKey('::ffff:198.51.100.7'), '#177: an IPv4 address written in IPv6 form is the same IPv4 visitor');
    assertEquals('unknown', clientAddressCountingKey(' unknown '), '#177: anything else is counted as it is (trimmed)');
    assertTrue(mb_strlen(clientAddressCountingKey('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')) <= 45, '#177: the key always fits the 45-character columns');
}
$ciFunctionsDir = CUERCODE_SITE_ROOT . '_functions' . DIRECTORY_SEPARATOR;
$ciApiAuth = (string) file_get_contents($ciFunctionsDir . 'fn_api_auth.php');
$ciAuth    = (string) file_get_contents($ciFunctionsDir . 'fn_auth.php');
$ciRoute   = (string) file_get_contents($ciFunctionsDir . 'fn_no_script_route.php');
/**
 * 🔎 The body of one function in a file's text ('' when it is not there).
 */
function ciFunctionBody(string $ciText, string $ciName): string
{
    $ciStart = strpos($ciText, 'function ' . $ciName . '(');
    if ($ciStart === false) {
        return '';
    }
    $ciEnd = strpos($ciText, "\nfunction ", $ciStart + 10);
    if ($ciEnd === false) {
        $ciEnd = strlen($ciText);
    }
    return substr($ciText, $ciStart, $ciEnd - $ciStart);
}
foreach ([
    'checkRateLimit (the API\'s anonymous limit)'                     => ciFunctionBody($ciApiAuth, 'checkRateLimit'),
    'recordAPIRequest (the shared request log)'                       => ciFunctionBody($ciApiAuth, 'recordAPIRequest'),
    'countLinkRequestsFromIp (reset and verification links, form limit)' => ciFunctionBody($ciAuth, 'countLinkRequestsFromIp'),
    'authRecordThenCountLinkRequest (its lock)'                       => ciFunctionBody($ciAuth, 'authRecordThenCountLinkRequest'),
    'noScriptRouteGate (the no-JavaScript route)'                     => ciFunctionBody($ciRoute, 'noScriptRouteGate'),
] as $ciWhere => $ciBody) {
    assertContains('clientAddressCountingKey(', $ciBody, '#177: ' . $ciWhere . ' counts under the shared key');
}
assertFalse(str_contains($ciRoute, 'function noScriptAddressKey('), '#177: the route has no rule of its own any more (one rule for every limit)');
