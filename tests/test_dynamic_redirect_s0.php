<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Dynamic Redirect Stage S0 Tests (Epic E1a, #27/#71/#33)
 * ==============================================================================
 *
 * Path: tests/test_dynamic_redirect_s0.php
 *
 * Covers Stage **S0** of the dynamic-redirect / dual-function QR design
 * (`.dev-team/dynamic_dualfn_design.md`) - the pure, DB-independent core:
 *
 *   1. `validateRedirectDestination()` (fn_redirects.php) - the FULL
 *      accept/reject matrix: scheme allowlist (https ok / http gated behind
 *      $opts / javascript+data+file rejected), userinfo rejection, the
 *      SSRF/open-redirect IP-literal matrix (loopback/private/link-local/
 *      reserved for BOTH IPv4 and IPv6, incl. the bracketed-IPv6 parse_url()
 *      quirk - see the inline note below - PLUS a positive control proving
 *      a genuine PUBLIC IP literal is correctly accepted, not blanket-
 *      rejected), CR/LF/control-char injection, length cap, IDN/punycode
 *      normalisation, and the self-referential `/r/` redirect-loop guard.
 *   2. `buildPayload_dynamic_url()` (fn_qr_payloads.php) - UUID v4
 *      accept/reject and the base-host composition (default fallback, since
 *      this DB-independent harness never loads fn_config.php's getConfig()).
 *   3. `isValidRedirectUuid()` / `cuercodeIpInCidr()` (fn_redirects.php) -
 *      the smaller pure helpers these two functions rest on.
 *   4. A STRUCTURAL-SQL-ONLY guard (source-text inspection, no live MySQL,
 *      mirroring tests/test_pricing_flex.php's #70 structural style) over
 *      install.sql/seed_data.sql: exact new CREATE TABLE count (29, was 26),
 *      the ALTER TABLE count staying UNCHANGED at 2 (proving this delta adds
 *      no ALTER at all - pure new tables only), balanced parentheses and
 *      backticks within each of the 3 new tables' CREATE blocks, the
 *      `dynamic_url` type's isActive=0 seed, and the `dynamic_qr_enabled`
 *      kill-switch seeded '0'.
 *
 * ⚠️ SKIPPED (DB-dependent - out of scope for this dependency-free harness,
 *    same convention as every other test file's exclusion note):
 *    fetchRedirectRowByUuid(), countActiveDynamicRedirectsForUser(),
 *    acquireDynamicCodeLimitLock()/releaseDynamicCodeLimitLock(),
 *    createDynamicRedirectForOwner(), and the two HTTP handlers - all live
 *    in fn_api_redirects.php and are covered (their pure/predicate halves)
 *    by tests/test_dynamic_redirect_s1.php instead.
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 4 (Epic E1a Stage S0, #27/#71/#33)
 * @see         web/CueRCode.net/_functions/fn_redirects.php
 * @see         web/CueRCode.net/_functions/fn_qr_payloads.php - buildPayload_dynamic_url()
 * @see         web/CueRCode.net/_includes/sql/install.sql     - Tables 27-29
 * @see         web/CueRCode.net/_includes/sql/seed_data.sql   - dynamic_url type row + redirects settings group
 * @see         .dev-team/dynamic_dualfn_design.md §1.4, §7
 * @see         tests/test_pricing_flex.php - the sibling #70 structural-SQL-guard style this mirrors
 */

// ==============================================================================
// 🧰 Load the production function files (NO database connection - both files
// only DEFINE functions at include time, every DB touch is lazy).
// ==============================================================================

requireFn('fn_redirects.php');
requireFn('fn_qr_payloads.php');

// ==============================================================================
// ✅ Section 1: isValidRedirectUuid() - UUID v4 shape validation
// ==============================================================================

assertTrue(
    isValidRedirectUuid('a1b2c3d4-e5f6-4890-abcd-ef1234567890'),
    'isValidRedirectUuid() accepts a well-formed UUID v4'
);
assertTrue(
    isValidRedirectUuid('A1B2C3D4-E5F6-4890-ABCD-EF1234567890'),
    'isValidRedirectUuid() is case-insensitive'
);
assertFalse(
    isValidRedirectUuid('not-a-uuid'),
    'isValidRedirectUuid() rejects an obviously malformed string'
);
assertFalse(
    isValidRedirectUuid('a1b2c3d4-e5f6-1890-abcd-ef1234567890'),
    'isValidRedirectUuid() rejects a non-v4 UUID (wrong version nibble)'
);
assertFalse(
    isValidRedirectUuid(''),
    'isValidRedirectUuid() rejects an empty string'
);
assertFalse(
    isValidRedirectUuid('a1b2c3d4-e5f6-4890-abcd-ef1234567890; DROP TABLE tblQRRedirects;'),
    'isValidRedirectUuid() rejects a UUID with SQL-injection-shaped trailing content'
);

// ==============================================================================
// 🔢 Section 2: cuercodeIpInCidr() - the CIDR-membership primitive backing
// the destination validator's IP-literal rejection
// ==============================================================================

assertTrue(cuercodeIpInCidr('10.1.2.3', '10.0.0.0/8'), 'cuercodeIpInCidr(): 10.1.2.3 is within 10.0.0.0/8');
assertFalse(cuercodeIpInCidr('11.1.2.3', '10.0.0.0/8'), 'cuercodeIpInCidr(): 11.1.2.3 is NOT within 10.0.0.0/8');
assertTrue(cuercodeIpInCidr('192.168.255.255', '192.168.0.0/16'), 'cuercodeIpInCidr(): a /16 boundary address matches');
assertTrue(cuercodeIpInCidr('127.0.0.1', '127.0.0.0/8'), 'cuercodeIpInCidr(): loopback matches 127.0.0.0/8');
assertTrue(cuercodeIpInCidr('fe80::1', 'fe80::/10'), 'cuercodeIpInCidr(): an IPv6 link-local address matches fe80::/10 (non-byte-aligned prefix)');
assertFalse(cuercodeIpInCidr('fec0::1', 'fe80::/10'), 'cuercodeIpInCidr(): fec0:: is just OUTSIDE fe80::/10 (exercises the partial-byte mask boundary)');
assertFalse(cuercodeIpInCidr('10.0.0.1', 'fe80::/10'), 'cuercodeIpInCidr(): an IPv4 address never matches an IPv6 CIDR (different address families)');
assertFalse(cuercodeIpInCidr('not-an-ip', '10.0.0.0/8'), 'cuercodeIpInCidr(): a malformed IP fails closed (false), never throws');
assertFalse(cuercodeIpInCidr('10.0.0.1', 'not-a-cidr'), 'cuercodeIpInCidr(): a malformed CIDR fails closed (false), never throws');

// ==============================================================================
// 🎯 Section 3: validateRedirectDestination() - ACCEPT matrix
// ==============================================================================

$plainHttps = validateRedirectDestination('https://example.com/path');
assertTrue($plainHttps['valid'], 'validateRedirectDestination(): a plain https URL is accepted');
assertEquals('https://example.com/path', $plainHttps['normalized'], 'validateRedirectDestination(): a plain https URL normalizes unchanged');
assertEquals(null, $plainHttps['error'], 'validateRedirectDestination(): a valid result carries a null error');

$withPortQueryFragment = validateRedirectDestination('https://example.com:8443/path?a=1#frag');
assertTrue($withPortQueryFragment['valid'], 'validateRedirectDestination(): port + query + fragment are all accepted');
assertEquals(
    'https://example.com:8443/path?a=1#frag',
    $withPortQueryFragment['normalized'],
    'validateRedirectDestination(): port + query + fragment round-trip into the normalized form'
);

$mixedCase = validateRedirectDestination('HTTPS://Example.COM/Path');
assertTrue($mixedCase['valid'], 'validateRedirectDestination(): mixed-case scheme/host/path is accepted');
assertEquals(
    'https://example.com/Path',
    $mixedCase['normalized'],
    'validateRedirectDestination(): scheme and host are lower-cased for canonical storage; the PATH case is preserved'
);

$idnHost = validateRedirectDestination('https://münchen.de/');
assertTrue($idnHost['valid'], 'validateRedirectDestination(): a non-ASCII (IDN) hostname is accepted when ext-intl is available');
assertEquals(
    'https://xn--mnchen-3ya.de/',
    $idnHost['normalized'],
    'validateRedirectDestination(): an IDN hostname normalizes to its exact Punycode form'
);

$publicIpv4 = validateRedirectDestination('https://8.8.8.8/');
assertTrue($publicIpv4['valid'], 'validateRedirectDestination(): POSITIVE CONTROL - a genuine PUBLIC IPv4 literal is accepted, proving this is a private/reserved-range denylist, not a blanket IP-literal ban');

$publicIpv6 = validateRedirectDestination('https://[2606:4700:4700::1111]/');
assertTrue($publicIpv6['valid'], 'validateRedirectDestination(): POSITIVE CONTROL - a genuine PUBLIC IPv6 literal (bracketed) is accepted');
assertEquals(
    'https://[2606:4700:4700::1111]/',
    $publicIpv6['normalized'],
    'validateRedirectDestination(): a public IPv6 literal keeps its brackets in the normalized form (bare IPv6 without brackets is not valid URL syntax)'
);

$httpRejectedByDefault = validateRedirectDestination('http://example.com/');
assertFalse($httpRejectedByDefault['valid'], 'validateRedirectDestination(): http is REJECTED by default (https-only, O-2 recommended default)');

$httpAllowedViaOpts = validateRedirectDestination('http://example.com/', ['allow_http' => true]);
assertTrue($httpAllowedViaOpts['valid'], 'validateRedirectDestination(): http IS accepted when $opts[\'allow_http\']=true (the O-2 configurable path)');

$nonRLoopPath = validateRedirectDestination('https://cuercode.net/somepage');
assertTrue($nonRLoopPath['valid'], 'validateRedirectDestination(): a cuercode.net destination WITHOUT a /r/ path prefix is accepted (only /r/ paths are a loop risk)');

// ==============================================================================
// 🚫 Section 4: validateRedirectDestination() - REJECT matrix
// ==============================================================================

foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'file:///etc/passwd', 'vbscript:msgbox(1)'] as $dangerousScheme) {
    $result = validateRedirectDestination($dangerousScheme);
    assertFalse($result['valid'], 'validateRedirectDestination(): rejects dangerous scheme by omission (allowlist, never a denylist): ' . $dangerousScheme);
}

$userinfoResult = validateRedirectDestination('https://user:pass@example.com/');
assertFalse($userinfoResult['valid'], 'validateRedirectDestination(): rejects embedded userinfo (user:pass@host) - phishing/spoofing obfuscation');

// --- IPv4 private/loopback/link-local/reserved matrix -----------------------
foreach ([
    'https://127.0.0.1/'                          => 'IPv4 loopback',
    'https://10.0.0.5/'                           => 'IPv4 RFC1918 private (10/8)',
    'https://192.168.1.1/admin'                   => 'IPv4 RFC1918 private (192.168/16) - the exact intranet-pivot example from the design doc §1.4-d',
    'https://172.16.0.1/'                         => 'IPv4 RFC1918 private (172.16/12)',
    'https://169.254.169.254/latest/meta-data/'   => 'IPv4 link-local (cloud metadata endpoint SSRF target)',
    'https://0.0.0.0/'                            => 'IPv4 "this network"',
    'https://255.255.255.255/'                    => 'IPv4 limited broadcast',
] as $dangerousUrl => $label) {
    $result = validateRedirectDestination($dangerousUrl);
    assertFalse($result['valid'], 'validateRedirectDestination(): rejects ' . $label . ' (' . $dangerousUrl . ')');
}

// --- IPv6 private/loopback/link-local/reserved matrix, INCLUDING the
// bracketed-host parse_url() quirk (parse_url() keeps "[...]" verbatim in
// the host component - a naive filter_var($host, FILTER_VALIDATE_IP) would
// silently miss every one of these without unwrapping the brackets first).
foreach ([
    'https://[::1]/'          => 'IPv6 loopback',
    'https://[fe80::1]/'      => 'IPv6 link-local (fe80::/10)',
    'https://[fc00::1]/'      => 'IPv6 unique-local/private (fc00::/7)',
    'https://[fd12:3456::1]/' => 'IPv6 unique-local, upper half of fc00::/7 (fd00::/8)',
    'https://[2001:db8::1]/'  => 'IPv6 documentation range (2001:db8::/32)',
    'https://[::ffff:10.0.0.5]/' => 'IPv4-mapped IPv6 disguising a private IPv4 address',
] as $dangerousUrl => $label) {
    $result = validateRedirectDestination($dangerousUrl);
    assertFalse($result['valid'], 'validateRedirectDestination(): rejects ' . $label . ' (' . $dangerousUrl . ')');
}

// --- Control characters / header-injection belt-and-braces ------------------
$crlfResult = validateRedirectDestination("https://example.com/\r\nSet-Cookie: evil");
assertFalse($crlfResult['valid'], 'validateRedirectDestination(): rejects a destination containing CR/LF (Location-header-injection belt-and-braces)');

$nullByteResult = validateRedirectDestination("https://example.com/\x00hidden");
assertFalse($nullByteResult['valid'], 'validateRedirectDestination(): rejects a destination containing a NUL byte');

// --- Length cap ---------------------------------------------------------
$tooLongResult = validateRedirectDestination('https://' . str_repeat('a', 2100) . '.com/');
assertFalse($tooLongResult['valid'], 'validateRedirectDestination(): rejects a destination exceeding the 2048-character cap');

// --- Empty / malformed ----------------------------------------------------
assertFalse(validateRedirectDestination('')['valid'], 'validateRedirectDestination(): rejects an empty string');
assertFalse(validateRedirectDestination('   ')['valid'], 'validateRedirectDestination(): rejects a whitespace-only string');
assertFalse(validateRedirectDestination('not a url at all')['valid'], 'validateRedirectDestination(): rejects a string with no scheme/host at all');
assertFalse(validateRedirectDestination('https:///no-host')['valid'], 'validateRedirectDestination(): rejects a URL with an empty host');

// --- Self-referential / redirect-loop rejection (design doc §1.4-e) --------
foreach ([
    'https://cuercode.net/r/11111111-1111-4111-8111-111111111111',
    'https://cuercode.me/r/11111111-1111-4111-8111-111111111111',
    'https://qrmy.link/r/11111111-1111-4111-8111-111111111111',
    'https://www.cuercode.net/r/11111111-1111-4111-8111-111111111111',
    'https://WWW.CUERCODE.NET/r/11111111-1111-4111-8111-111111111111',
] as $selfReferentialUrl) {
    $result = validateRedirectDestination($selfReferentialUrl);
    assertFalse($result['valid'], 'validateRedirectDestination(): rejects self-referential /r/ loop: ' . $selfReferentialUrl);
}

// 📌 A caller-supplied $opts['self_hosts'] overrides the default list -
// proving the self-referential check is genuinely configurable/testable,
// not a hardcoded string comparison.
$customSelfHostResult = validateRedirectDestination(
    'https://myinternalportal.example/r/abc',
    ['self_hosts' => ['myinternalportal.example']]
);
assertFalse(
    $customSelfHostResult['valid'],
    'validateRedirectDestination(): $opts[\'self_hosts\'] overrides the default self-referential host list'
);

// 📌 The default self-host list does NOT reject an arbitrary third-party
// domain's own /r/ path - only the platform's own three domains are special.
$thirdPartyRPath = validateRedirectDestination('https://unrelated-service.example/r/something');
assertTrue(
    $thirdPartyRPath['valid'],
    'validateRedirectDestination(): a /r/ path on an UNRELATED third-party domain is NOT treated as self-referential'
);

// ==============================================================================
// 📦 Section 5: buildPayload_dynamic_url() - UUID accept/reject + base-host
// ==============================================================================

assertEquals(
    'https://QRmy.Link/r/a1b2c3d4-e5f6-4890-abcd-ef1234567890',
    buildPayload_dynamic_url(['redirect_uuid' => 'a1b2c3d4-e5f6-4890-abcd-ef1234567890']),
    'buildPayload_dynamic_url(): composes "{default_base}/r/{uuid}" when no tblSettings override is loaded (getConfig() unavailable in this DB-independent harness -> falls back to the hard-coded default \'https://QRmy.Link\')'
);

assertThrows(
    function () {
        buildPayload_dynamic_url(['redirect_uuid' => 'not-a-uuid']);
    },
    'buildPayload_dynamic_url(): throws InvalidArgumentException for a malformed redirect_uuid (strict-reject, same posture as buildPayload_gs1_product()\'s GTIN check)',
    \InvalidArgumentException::class
);

assertThrows(
    function () {
        buildPayload_dynamic_url([]);
    },
    'buildPayload_dynamic_url(): throws InvalidArgumentException when redirect_uuid is entirely missing',
    \InvalidArgumentException::class
);

assertThrows(
    function () {
        // 📌 A v1 (time-based) UUID must be rejected - only v4 is accepted.
        buildPayload_dynamic_url(['redirect_uuid' => 'a1b2c3d4-e5f6-1890-abcd-ef1234567890']);
    },
    'buildPayload_dynamic_url(): throws InvalidArgumentException for a non-v4 UUID (wrong version nibble)',
    \InvalidArgumentException::class
);

// 📌 A trailing/leading-whitespace UUID is trimmed and accepted (mirrors
// buildPayload_url()'s own trim()-first convention throughout this file).
assertEquals(
    'https://QRmy.Link/r/a1b2c3d4-e5f6-4890-abcd-ef1234567890',
    buildPayload_dynamic_url(['redirect_uuid' => '  a1b2c3d4-e5f6-4890-abcd-ef1234567890  ']),
    'buildPayload_dynamic_url(): trims surrounding whitespace from redirect_uuid before validating/encoding'
);

// ==============================================================================
// 🧬 STRUCTURAL-SQL-ONLY guard (source-text inspection, no live MySQL - mirrors
// tests/test_pricing_flex.php's #70 structural style).
// ==============================================================================

$installSqlSource  = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql');
$seedDataSqlSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql');

assertTrue(is_string($installSqlSource) && $installSqlSource !== '', 'structural guard: install.sql was readable for static inspection');
assertTrue(is_string($seedDataSqlSource) && $seedDataSqlSource !== '', 'structural guard: seed_data.sql was readable for static inspection');

// 🆕 The 3 new tables (install.sql Tables 27-29) all exist.
foreach (['tblQRRedirects', 'tblQRScans', 'tblResolveNonces'] as $newTableName) {
    assertContains(
        'CREATE TABLE IF NOT EXISTS `' . $newTableName . '`',
        $installSqlSource,
        'install.sql: the new Stage S0 table ' . $newTableName . ' is present'
    );
}

// 🔢 Exact new CREATE TABLE count: 26 pre-existing (Tables 1-26, incl. #70's
// 22-26) + 3 new (Tables 27-29) = 29.
assertEquals(
    29,
    substr_count($installSqlSource, 'CREATE TABLE IF NOT EXISTS'),
    'install.sql: exactly 29 CREATE TABLE IF NOT EXISTS statements (26 pre-existing + the 3 new Stage S0 tables)'
);

// 🔢 The ALTER TABLE count stays UNCHANGED at 2 (both from #70) - this
// delta adds three brand-new tables and NOT ONE ALTER TABLE statement,
// confirming no existing table was touched.
assertEquals(
    2,
    substr_count($installSqlSource, 'ALTER TABLE `'),
    'install.sql: ALTER TABLE count is UNCHANGED at 2 (both pre-existing #70 ALTERs) - this Stage S0 delta adds pure new tables only, no ALTER'
);

// 🧮 Balanced-parentheses + balanced-backticks guard over EACH new table's
// own CREATE TABLE block (from its "CREATE TABLE IF NOT EXISTS `name`"
// marker through its closing ") ENGINE=InnoDB" - a malformed/truncated
// block would show an odd backtick count or mismatched paren count).
foreach (['tblQRRedirects', 'tblQRScans', 'tblResolveNonces'] as $newTableName) {
    $startMarker = 'CREATE TABLE IF NOT EXISTS `' . $newTableName . '`';
    $startPos    = strpos($installSqlSource, $startMarker);

    assertTrue($startPos !== false, 'structural guard: located the CREATE TABLE block start for ' . $newTableName);

    if ($startPos === false) {
        continue;
    }

    $endMarker = ') ENGINE=InnoDB';
    $endPos    = strpos($installSqlSource, $endMarker, $startPos);

    assertTrue($endPos !== false, 'structural guard: located the CREATE TABLE block end for ' . $newTableName);

    if ($endPos === false) {
        continue;
    }

    // 📌 Include the closing paren itself (+1) so the paren-count is fair.
    $block = substr($installSqlSource, $startPos, ($endPos + 1) - $startPos);

    $openParenCount  = substr_count($block, '(');
    $closeParenCount = substr_count($block, ')');
    assertEquals(
        $openParenCount,
        $closeParenCount,
        'structural guard: ' . $newTableName . '\'s CREATE TABLE block has balanced parentheses (open=' . $openParenCount . ', close=' . $closeParenCount . ')'
    );

    $backtickCount = substr_count($block, '`');
    assertEquals(
        0,
        $backtickCount % 2,
        'structural guard: ' . $newTableName . '\'s CREATE TABLE block has an EVEN (balanced) backtick count (found ' . $backtickCount . ')'
    );
}

// 🚦 The dynamic_url type row is seeded isActive=0 (dark/inert) via its OWN
// separate INSERT statement (not folded into the 37-row general VALUES list,
// which never names the isActive column at all).
assertContains(
    "('dynamic_url', 'Dynamic URL (Editable Redirect)',",
    $seedDataSqlSource,
    'seed_data.sql: the dynamic_url QR type row is present'
);
assertMatches(
    '/INSERT INTO `tblQRCodeTypes`[^;]*isActive[^;]*VALUES\s*\n\(\'dynamic_url\',[^;]*0, 38\);/s',
    $seedDataSqlSource,
    'seed_data.sql: dynamic_url is seeded via its OWN INSERT statement with isActive=0 (dark/inert), sortOrder 38'
);

// 🚦 The redirects settings group kill-switches are seeded correctly.
assertMatches(
    '/\(\'redirects\', \'dynamic_qr_enabled\', \'0\', \'boolean\'/',
    $seedDataSqlSource,
    'seed_data.sql: dynamic_qr_enabled is seeded 0/boolean/inert'
);
assertMatches(
    '/\(\'redirects\', \'dynamic_redirect_base_url\', \'https:\/\/QRmy\.Link\', \'url\'/',
    $seedDataSqlSource,
    'seed_data.sql: dynamic_redirect_base_url is seeded to the tertiary domain https://QRmy.Link (O-11 default)'
);
assertMatches(
    '/\(\'redirects\', \'redirect_allow_http\', \'0\', \'boolean\'/',
    $seedDataSqlSource,
    'seed_data.sql: redirect_allow_http is seeded 0/boolean (https-only default, O-2)'
);

// 🚦 The dynamic_qr / dual_function_qr featuresJSON keys are seeded on all 4
// plans with the correct fail-closed/tiered values (design doc §5): anonymous
// both false, free/pro dynamic_qr=true but dual_function_qr=false, business
// both true.
assertContains(
    '"barcode_generation": true, "dynamic_qr": false, "dual_function_qr": false}\'',
    $seedDataSqlSource,
    'seed_data.sql: the "anonymous" plan has dynamic_qr=false AND dual_function_qr=false (doubly excluded alongside its pre-existing dynamic_codes=0 limit)'
);
assertContains(
    '"barcode_generation": true, "dynamic_qr": true, "dual_function_qr": true}\'',
    $seedDataSqlSource,
    'seed_data.sql: the "business" plan has BOTH dynamic_qr=true AND dual_function_qr=true (the only plan with dual_function_qr on, per Pricing_Strategy.md tiers)'
);

// 🛡️ The pre-existing tblQRCodeTypes/tblSettings structure is untouched -
// spot-check a handful of exact, pre-existing rows still appear verbatim.
assertContains(
    "('url', 'URL / Web Link',",
    $seedDataSqlSource,
    'regression guard: seed_data.sql\'s pre-existing "url" QR type row is byte-for-byte unchanged'
);
assertContains(
    "'sso_signula_scopes'",
    $seedDataSqlSource,
    'regression guard: seed_data.sql\'s pre-existing sso settings group is still present (unedited)'
);

// ==============================================================================
// 🧬 DYNAMIC guard: every OTHER test file that has ALREADY RUN by this point
// recorded zero failures (mirrors test_pricing_flex.php's own dynamic guard -
// tests/run.php discovers test_*.php files sorted by filename, and this file
// sorts after most of the alphabet, so a prior failure anywhere would show up
// here too).
// ==============================================================================

$priorResults      = $GLOBALS['__CUERCODE_TEST_RESULTS'] ?? [];
$priorFailureCount = 0;
$priorFailureFiles = [];

foreach ($priorResults as $priorResult) {
    if ($priorResult['pass'] === false) {
        $priorFailureCount++;
        if (!in_array($priorResult['file'], $priorFailureFiles, true)) {
            $priorFailureFiles[] = $priorResult['file'];
        }
    }
}

// 📌 This file sorts alphabetically right after test_core.php (before
// test_email.php onwards), so - unlike test_pricing_flex.php's own ">1000"
// floor, written when it sorted near the END of the suite - a lower floor is
// the correct "isn't checking an empty collector" sanity bar here.
assertTrue(
    count($priorResults) > 500,
    'sanity: a substantial number of assertions from OTHER test files already ran before this file (found: ' . count($priorResults) . ')'
);

assertEquals(
    0,
    $priorFailureCount,
    'DYNAMIC REGRESSION GUARD: zero failures recorded by any test file that ran before this one in the suite (failing files if any: ' . implode(', ', $priorFailureFiles) . ')'
);
