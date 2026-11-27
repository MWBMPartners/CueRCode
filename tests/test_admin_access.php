<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for GLOBAL ADMIN ACCESS (task 7n Phase 1B)
 * ==============================================================================
 *
 * Path: tests/test_admin_access.php
 *
 * Covers _functions/fn_admin.php, _includes/cli/admin_access.php, the admin
 * pages in public_html/admin/ and the small changes elsewhere (currentUser()'s
 * roleID, the header's "Admin" link, the erasure guard), WITHOUT a database:
 *
 *   1. Loading: constants, and no site start-up helpers defined.
 *   2. Who is a Global Admin: adminRoleIsGlobalAdmin() and
 *      adminAccessDecision() for every role / status / verification mix.
 *   3. Password re-confirmation: limits, adminReauthIsFresh() at the exact
 *      idle and absolute boundaries (-1 s / +1 s), wrong user, wrong session,
 *      tampered states; seconds left; the 5-failures sign-out rule.
 *   4. adminSafeNextPath() against //evil, /\evil, /admin:x, https://...,
 *      encoded variants, dot segments, line breaks, over-long values.
 *   5. adminOriginIsAllowed() (same-site form check).
 *   6. The audit trail: action names exactly as plan §6.7, adminAuditChanges()
 *      NEVER contains a secret, the 60 KB cap, adminAuditBuildRow() refusals.
 *   7. Dates and time zones (plan §8.4), including both clock changes.
 *   8. Command-line options, email masking, the grant and revoke decisions.
 *      Decision 0010: when a grant resets the password, the account details
 *      shown first (and safe against a hostile display name), the new-
 *      password checks (typed twice, the site's own rules), no password
 *      option, only a hash accepted, and the order of the steps.
 *   9. The HTML helpers escape everything.
 *  10. STATIC GUARDS (plan §9 Phase 1B):
 *      (a) every public_html/admin/** /index.php calls requireGlobalAdminPage(
 *          before any other application call;
 *      (b) roleID is WRITTEN only in fn_admin.php and admin_access.php;
 *      (c) no /admin route in the API router or openapi.json;
 *      (d) no inline <script> (or on...= handlers) in admin pages;
 *      plus the admin .htaccess headers, the command-line guard, the header
 *      link, currentUser()'s roleID and the erasure guard.
 *  11. The real admin_access.php as a child process with --help and bad
 *      options ONLY (those exit before any database code is loaded), plus a
 *      database-free child that proves hidden typing refuses a pipe.
 *
 * ⚠️ NOT covered here (needs a real database): the real grant / revoke /
 *    list runs, the audit rows, the first-seen row, the erasure guard against
 *    real rows and /admin/upgrades/ before the audit table exists. Those are
 *    in tests/integration/admin_access_real_mysql.php.
 *
 * ⚠️ House rules for this file (it runs in the SAME global scope as
 *    tests/run.php and every other test file):
 *    - every variable is prefixed $aa, so nothing clashes;
 *    - it never defines dbQuery(), getDB(), getConfig(), logError() or
 *      logActivity() - other test files assert those do NOT exist;
 *    - it never starts admin_access.php except with --help or an invalid
 *      option. A real run could change a real database.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_admin.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §6, §8.4, §9 Phase 1B
 */

requireFn('fn_admin.php');

// ==============================================================================
// ✅ Section 1: loading
// ==============================================================================

assertEquals(1, CUERCODE_ROLE_GLOBAL_ADMIN, 'Global Admin role is roleID 1 (install.sql tblUsers comment)');
assertEquals(5, CUERCODE_ADMIN_REAUTH_MAX_FAILURES, '5 wrong passwords sign the admin out (plan §6.4)');
assertEquals(0, CUERCODE_ADMIN_CLI_EXIT_OK, 'CLI exit 0 = done');
assertEquals(1, CUERCODE_ADMIN_CLI_EXIT_FAILED, 'CLI exit 1 = failed');
assertEquals(2, CUERCODE_ADMIN_CLI_EXIT_REFUSED, 'CLI exit 2 = refused');
assertEquals(61440, CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES, 'audit changes are capped at 60 KB (plan §6.7)');

foreach (['dbQuery', 'getDB', 'getConfig', 'logError', 'logActivity'] as $aaForbidden) {
    assertFalse(function_exists($aaForbidden), 'loading fn_admin.php does not define ' . $aaForbidden . '()');
}

// ==============================================================================
// ✅ Section 2: who is a Global Admin
// ==============================================================================

assertTrue(adminRoleIsGlobalAdmin(1), 'role 1 (int) is Global Admin');
assertTrue(adminRoleIsGlobalAdmin('1'), "role '1' (what MySQLi returns) is Global Admin");
foreach ([null, 0, 2, 3, '2', '', 'admin', '1 ', ' 1', '1.0', 1.0, true, [1], -1] as $aaNotAdminRole) {
    assertFalse(adminRoleIsGlobalAdmin($aaNotAdminRole), 'not the Global Admin role: ' . var_export($aaNotAdminRole, true));
}

$aaUser = ['userID' => 7, 'email' => 'owner@example.com'];
$aaRow  = static function (array $aaOverrides): array {
    return array_merge([
        'userID'          => 7,
        'roleID'          => 1,
        'status'          => 'active',
        'emailVerifiedAt' => '2026-11-24 10:00:00',
        'hasPassword'     => 1,
        'email'           => 'owner@example.com',
        'displayName'     => 'Owner',
    ], $aaOverrides);
};

assertEquals('not_signed_in', adminAccessDecision(null, $aaRow([])), 'nobody signed in → not_signed_in');
assertEquals('not_signed_in', adminAccessDecision([], $aaRow([])), 'a user array without a userID → not_signed_in');
assertEquals('not_admin', adminAccessDecision($aaUser, null), 'account row could not be read → not_admin (fail closed)');
assertEquals('admin', adminAccessDecision($aaUser, $aaRow([])), 'role 1 + active + verified → admin');
assertEquals('admin', adminAccessDecision($aaUser, $aaRow(['roleID' => '1', 'userID' => '7'])), 'string values from MySQLi → admin');
assertEquals('not_admin', adminAccessDecision($aaUser, $aaRow(['userID' => 8])), 'row about a different user → not_admin');

// 🧮 Every role × status × verification combination: admin ONLY for 1/active/verified.
foreach ([null, 1, 2, 3] as $aaRole) {
    foreach (['active', 'pending', 'suspended', 'deleted'] as $aaStatus) {
        foreach ([null, '', '2026-11-24 10:00:00'] as $aaVerified) {
            $aaExpected = 'not_admin';
            if ($aaRole === 1 && $aaStatus === 'active' && $aaVerified === '2026-11-24 10:00:00') {
                $aaExpected = 'admin';
            }
            assertEquals(
                $aaExpected,
                adminAccessDecision($aaUser, $aaRow(['roleID' => $aaRole, 'status' => $aaStatus, 'emailVerifiedAt' => $aaVerified])),
                'access decision: role ' . var_export($aaRole, true) . ', ' . $aaStatus . ', verified ' . var_export($aaVerified, true)
            );
        }
    }
}
$aaNoRoleKey = $aaRow([]);
unset($aaNoRoleKey['roleID']);
assertEquals('not_admin', adminAccessDecision($aaUser, $aaNoRoleKey), 'row with no roleID column at all → not_admin');

// ==============================================================================
// ✅ Section 3: password re-confirmation (plan §6.4)
// ==============================================================================

assertEquals(['idle' => 15, 'max' => 60], adminReauthLimits(15, 60), 'default limits kept');
assertEquals(['idle' => 5, 'max' => 15], adminReauthLimits(1, 1), 'limits below the range are raised (idle 5, max 15)');
assertEquals(['idle' => 60, 'max' => 480], adminReauthLimits(999, 9999), 'limits above the range are lowered (idle 60, max 480)');
assertEquals(['idle' => 60, 'max' => 60], adminReauthLimits(60, 20), 'the absolute limit is never below the idle one');
assertEquals(15, adminWholeMinutes('15', 99), "whole minutes from '15'");
assertEquals(99, adminWholeMinutes('15.5', 99), 'a fraction falls back to the default');
assertEquals(99, adminWholeMinutes('abc', 99), 'text falls back to the default');
assertEquals(99, adminWholeMinutes(null, 99), 'null falls back to the default');
assertEquals(20, adminWholeMinutes(20, 99), 'an int is kept');

$aaNow  = 1800000000;
$aaHash = hash('sha256', 'session-token-A');
$aaState = static function (int $aaConfirmedAgo, int $aaUsedAgo) use ($aaNow, $aaHash): array {
    return ['userID' => 7, 'sessionHash' => $aaHash, 'confirmedAt' => $aaNow - $aaConfirmedAgo, 'lastUsedAt' => $aaNow - $aaUsedAgo];
};

assertTrue(adminReauthIsFresh($aaState(10, 10), 7, $aaHash, $aaNow, 15, 60), 'fresh just after confirming');
assertTrue(adminReauthIsFresh($aaState(15 * 60 - 1, 15 * 60 - 1), 7, $aaHash, $aaNow, 15, 60), 'idle limit - 1 s: still fresh');
assertTrue(adminReauthIsFresh($aaState(15 * 60, 15 * 60), 7, $aaHash, $aaNow, 15, 60), 'exactly on the idle limit: still fresh (inclusive)');
assertFalse(adminReauthIsFresh($aaState(15 * 60 + 1, 15 * 60 + 1), 7, $aaHash, $aaNow, 15, 60), 'idle limit + 1 s: locked');
assertTrue(adminReauthIsFresh($aaState(60 * 60, 0), 7, $aaHash, $aaNow, 15, 60), 'exactly on the absolute limit (kept busy): still fresh');
assertTrue(adminReauthIsFresh($aaState(60 * 60 - 1, 0), 7, $aaHash, $aaNow, 15, 60), 'absolute limit - 1 s: still fresh');
assertFalse(adminReauthIsFresh($aaState(60 * 60 + 1, 0), 7, $aaHash, $aaNow, 15, 60), 'absolute limit + 1 s: locked, however busy');
assertFalse(adminReauthIsFresh($aaState(10, 10), 8, $aaHash, $aaNow, 15, 60), 'wrong user: locked');
assertFalse(adminReauthIsFresh($aaState(10, 10), 7, hash('sha256', 'session-token-B'), $aaNow, 15, 60), 'a different database session (new sign-in, copied cookie): locked');
assertFalse(adminReauthIsFresh($aaState(10, 10), 7, '', $aaNow, 15, 60), 'no session hash: locked');
assertFalse(adminReauthIsFresh(null, 7, $aaHash, $aaNow, 15, 60), 'no state: locked');
assertFalse(adminReauthIsFresh([], 7, $aaHash, $aaNow, 15, 60), 'empty state: locked');
assertFalse(adminReauthIsFresh(array_merge($aaState(10, 10), ['sessionHash' => '']), 7, '', $aaNow, 15, 60), 'stored and current hash both empty: locked');
assertFalse(adminReauthIsFresh(array_merge($aaState(10, 10), ['userID' => '7']), 7, $aaHash, $aaNow, 15, 60), 'a tampered (string) userID: locked');
assertFalse(adminReauthIsFresh(array_merge($aaState(10, 10), ['confirmedAt' => $aaNow + 5]), 7, $aaHash, $aaNow, 15, 60), 'confirmed in the future: locked');
assertFalse(adminReauthIsFresh(array_merge($aaState(10, 10), ['lastUsedAt' => $aaNow + 5]), 7, $aaHash, $aaNow, 15, 60), 'last used in the future: locked');
assertFalse(adminReauthIsFresh($aaState(10, 20), 7, $aaHash, $aaNow, 15, 60), 'last used before it was confirmed: locked');
assertFalse(adminReauthIsFresh($aaState(10, 10), 0, $aaHash, $aaNow, 15, 60), 'userID 0: locked');
assertFalse(adminReauthIsFresh($aaState(5 * 60 + 1, 5 * 60 + 1), 7, $aaHash, $aaNow, 1, 60), 'an idle limit set below 5 is treated as 5 (5 min + 1 s → locked)');
assertTrue(adminReauthIsFresh($aaState(5 * 60, 5 * 60), 7, $aaHash, $aaNow, 1, 60), 'an idle limit set below 5 is treated as 5 (exactly 5 min → fresh)');
$aaMissingKey = $aaState(10, 10);
unset($aaMissingKey['lastUsedAt']);
assertFalse(adminReauthIsFresh($aaMissingKey, 7, $aaHash, $aaNow, 15, 60), 'a state missing lastUsedAt: locked');

assertEquals(15 * 60 - 10, adminReauthSecondsLeft($aaState(10, 10), 7, $aaHash, $aaNow, 15, 60), 'seconds left = idle time left when that is shorter');
assertEquals(60, adminReauthSecondsLeft($aaState(59 * 60, 0), 7, $aaHash, $aaNow, 15, 60), 'seconds left = absolute time left when that is shorter');
assertEquals(0, adminReauthSecondsLeft($aaState(10, 10), 8, $aaHash, $aaNow, 15, 60), 'seconds left = 0 when locked');

foreach ([1 => 'retry', 2 => 'retry', 3 => 'retry', 4 => 'retry', 5 => 'lockout', 6 => 'lockout'] as $aaFailures => $aaOutcome) {
    assertEquals($aaOutcome, adminReauthFailureOutcome($aaFailures), 'wrong password number ' . $aaFailures . ' → ' . $aaOutcome);
}

// ==============================================================================
// ✅ Section 4: safe next path (plan §6.4, S10)
// ==============================================================================

foreach (['/admin/', '/admin/upgrades/', '/admin/settings/?section=email', '/admin/plans/edit/?id=3', '/admin/a.b/'] as $aaGoodPath) {
    assertEquals($aaGoodPath, adminSafeNextPath($aaGoodPath), 'safe admin path kept: ' . $aaGoodPath);
}
foreach ([
    '//evil.example'                  => 'protocol-relative',
    '/\\evil.example'                 => 'backslash trick',
    '/admin:x'                        => 'colon',
    '/admin/:x'                       => 'colon after /admin/',
    'https://evil.example/admin/'     => 'absolute URL',
    'javascript:alert(1)'             => 'script scheme',
    '/admin'                          => 'no trailing slash (not inside the area)',
    '/administrator/'                 => 'a different folder that only starts the same',
    '/account/'                       => 'outside the admin area',
    '/admin//evil.example'            => 'double slash inside',
    '/admin/\\evil'                   => 'backslash inside',
    '/admin/%2F%2Fevil.example'       => 'encoded double slash',
    '/admin/%5Cevil'                  => 'encoded backslash',
    '/admin/%3Aevil'                  => 'encoded colon',
    '%2Fadmin%2F'                     => 'fully encoded (does not start with /admin/ as given)',
    "/admin/\r\nSet-Cookie: x=1"      => 'line break (header injection)',
    '/admin/%0d%0aSet-Cookie:x'       => 'encoded line break',
    '/admin/../account/'              => 'dot-dot segment',
    '/admin/./x'                      => 'dot segment',
    '/admin/%2e%2e/account/'          => 'encoded dot-dot segment',
    ''                                => 'empty',
    '/admin/' . str_repeat('a', 520)  => 'over 512 characters',
] as $aaBadPath => $aaWhy) {
    assertEquals('/admin/', adminSafeNextPath($aaBadPath), 'unsafe next path refused (' . $aaWhy . ')');
}
assertEquals('/admin/x/', adminSafeNextPath(null, '/admin/x/'), 'null → the given default');
assertEquals('/admin/', adminSafeNextPath(['/admin/']), 'an array (?next[]=) → default');
assertEquals('/admin/?q=..', adminSafeNextPath('/admin/?q=..'), 'dots in the query part are harmless and kept');

// ==============================================================================
// ✅ Section 5: same-site Origin check (plan §6.3)
// ==============================================================================

assertTrue(adminOriginIsAllowed(null, 'cuercode.net'), 'no Origin header → allowed (the CSRF token still protects)');
assertTrue(adminOriginIsAllowed('', 'cuercode.net'), 'empty Origin header → allowed');
assertTrue(adminOriginIsAllowed('https://cuercode.net', 'cuercode.net'), 'own origin → allowed');
assertTrue(adminOriginIsAllowed('https://CueRCode.net', 'cuercode.net'), 'own origin, other letter case → allowed');
assertTrue(adminOriginIsAllowed('http://cuercode.net', 'cuercode.net'), 'own host over http (behind a proxy) → allowed');
assertTrue(adminOriginIsAllowed('https://cuercode.net:443', 'cuercode.net'), 'own origin with the default port → allowed');
assertTrue(adminOriginIsAllowed('https://cuercode.net', 'cuercode.net:443'), 'Host header with the default port → allowed');
assertTrue(adminOriginIsAllowed('http://127.0.0.1:8080', '127.0.0.1:8080'), 'same non-default port → allowed');
assertFalse(adminOriginIsAllowed('http://127.0.0.1:9090', '127.0.0.1:8080'), 'different port → refused');
assertFalse(adminOriginIsAllowed('https://evil.example', 'cuercode.net'), 'another site → refused');
assertFalse(adminOriginIsAllowed('https://cuercode.net.evil.example', 'cuercode.net'), 'look-alike host → refused');
assertFalse(adminOriginIsAllowed('null', 'cuercode.net'), 'the "null" origin → refused');
assertFalse(adminOriginIsAllowed('ftp://cuercode.net', 'cuercode.net'), 'a non-web scheme → refused');
assertFalse(adminOriginIsAllowed('not a url', 'cuercode.net'), 'garbage → refused');
assertFalse(adminOriginIsAllowed('https://cuercode.net', ''), 'Origin present but no Host header → refused');

// 🔒 Independent review (Codex C2 finding 3, 2026-11-25): when the SERVER knows
//    this request arrived over HTTPS, an http:// Origin is a different origin
//    (a page served over plain http) and is refused. When the server cannot
//    tell (behind a proxy that ends HTTPS), the scheme is still not compared.
assertFalse(adminOriginIsAllowed('http://cuercode.net', 'cuercode.net', true), 'C2-3: an HTTPS request with an http:// Origin → refused');
assertTrue(adminOriginIsAllowed('https://cuercode.net', 'cuercode.net', true), 'C2-3: an HTTPS request with its own https:// Origin → allowed');
assertTrue(adminOriginIsAllowed(null, 'cuercode.net', true), 'C2-3: an HTTPS request with no Origin → allowed (the CSRF token still protects)');
assertFalse(adminOriginIsAllowed('http://cuercode.net:443', 'cuercode.net', true), 'C2-3: http:// on port 443 → refused');
assertTrue(adminOriginIsAllowed('http://cuercode.net', 'cuercode.net', false), 'C2-3: when the server cannot tell (behind a proxy), http:// is still allowed, as before');
$aaAdminSource = (string) file_get_contents(dirname(__DIR__) . '/web/CueRCode.net/_functions/fn_admin.php');
assertContains('adminOriginIsAllowed($origin, $host, $requestIsHttps)', $aaAdminSource, 'C2-3: the real admin form check passes whether the request is HTTPS');

// ==============================================================================
// ✅ Section 6: the audit trail (plan §6.7)
// ==============================================================================

assertEquals(
    [
        'reauth.success', 'reauth.failed', 'reauth.lockout', 'admin.grant', 'admin.revoke', 'admin.first_seen', 'schema.migrate',
        'schema.migrate.started',
        'setting.update', 'setting.secret_set', 'setting.secret_replace', 'setting.secret_clear', 'setting.test_graph', 'setting.test_email',
        'setting.test_graph.started', 'setting.test_email.started',
        'plan.create', 'plan.update', 'plan.update_reduction', 'plan.new_version', 'plan.retire', 'plan.restore', 'plan.delete',
        'price.create', 'price.update', 'price.retire', 'price.delete', 'entitlement.update', 'apikeys.resync',
        'discount.create', 'discount.update', 'discount.disable', 'discount.eligibility_grant', 'discount.eligibility_revoke',
        'customer.lookup', 'deal.create', 'deal.start', 'deal.update', 'deal.end', 'payg.pack_create', 'payg.pack_update', 'payg.cap_update',
    ],
    adminAuditActionNames(),
    'audit action names are exactly the plan §6.7 list, plus the two ".started" rows (final review N-2) and "schema.migrate.started" (Codex C2-2)'
);

$aaSecret = 'S3cr3t-Value-Do-Not-Log-7f2a';
$aaChanges = adminAuditChanges(
    ['graph_client_secret' => 'old-' . $aaSecret, 'paypal_client_secret' => '', 'hcaptcha_secret_key' => $aaSecret, 'turnstile_secret_key' => $aaSecret, 'email_from_name' => 'Old', 'email_enabled' => 1, 'site_name' => 'Same'],
    ['graph_client_secret' => $aaSecret, 'paypal_client_secret' => $aaSecret, 'hcaptcha_secret_key' => '', 'turnstile_secret_key' => $aaSecret, 'email_from_name' => 'New', 'email_enabled' => '1', 'site_name' => 'Same'],
    ['graph_client_secret', 'paypal_client_secret', 'hcaptcha_secret_key', 'turnstile_secret_key']
);
$aaChangesJson = (string) json_encode($aaChanges);
assertFalse(str_contains($aaChangesJson, 'S3cr3t'), 'audit changes NEVER contain a secret value (old or new)');
assertEquals(['secret' => 'replaced'], $aaChanges['graph_client_secret'], 'secret changed → {"secret":"replaced"}');
assertEquals(['secret' => 'set'], $aaChanges['paypal_client_secret'], 'secret added → {"secret":"set"}');
assertEquals(['secret' => 'cleared'], $aaChanges['hcaptcha_secret_key'], 'secret removed → {"secret":"cleared"}');
assertFalse(array_key_exists('turnstile_secret_key', $aaChanges), 'secret unchanged → not listed');
assertEquals(['old' => 'Old', 'new' => 'New'], $aaChanges['email_from_name'], 'ordinary field → old and new');
assertFalse(array_key_exists('email_enabled', $aaChanges), "1 and '1' count as unchanged");
assertFalse(array_key_exists('site_name', $aaChanges), 'unchanged field → not listed');
assertEquals(['added' => ['old' => null, 'new' => 'x']], adminAuditChanges([], ['added' => 'x'], []), 'a field only on the after side is listed');
assertEquals(['removed' => ['old' => 'x', 'new' => null]], adminAuditChanges(['removed' => 'x'], [], []), 'a field only on the before side is listed');
assertEquals(null, adminAuditSecretMarker(null, ''), 'secret marker: empty → empty is no change');
assertEquals('set', adminAuditSecretMarker(null, 'x'), 'secret marker: nothing → value is set');

assertEquals(null, adminAuditEncodeChanges([]), 'no changes → NULL changesJSON');
$aaBig = ['huge' => ['old' => str_repeat('a', 70000), 'new' => 'b'], 'other' => ['old' => 1, 'new' => 2]];
$aaBigJson = (string) adminAuditEncodeChanges($aaBig);
assertTrue(strlen($aaBigJson) <= CUERCODE_ADMIN_AUDIT_CHANGES_MAX_BYTES, 'over 60 KB → truncated to fit');
$aaBigDecoded = json_decode($aaBigJson, true);
assertTrue(is_array($aaBigDecoded) && $aaBigDecoded['_truncated'] === true, 'truncated record is valid JSON with a marker');
assertEquals(['huge', 'other'], $aaBigDecoded['fields'], 'truncated record still names the changed fields');

$aaGoodEntry = [
    'actorType' => 'admin', 'actorUserID' => 7, 'actorLabel' => 'owner@example.com', 'action' => 'reauth.success',
    'entityType' => 'user', 'entityKey' => '7', 'outcome' => 'success',
];
$aaBuilt = adminAuditBuildRow($aaGoodEntry);
assertTrue(is_array($aaBuilt), 'a well-formed audit entry is accepted');
assertEquals(7, $aaBuilt['actorUserID'], 'audit row keeps the actor userID');
assertEquals(null, $aaBuilt['changesJSON'], 'audit row with no changes → NULL changesJSON');
assertEquals(null, adminAuditBuildRow(array_merge($aaGoodEntry, ['action' => 'reauth.sucess'])), 'a misspelt action is refused');
assertEquals(null, adminAuditBuildRow(array_merge($aaGoodEntry, ['actorType' => 'robot'])), 'an unknown actor type is refused');
assertEquals(null, adminAuditBuildRow(array_merge($aaGoodEntry, ['outcome' => 'maybe'])), 'an unknown outcome is refused');
assertEquals(null, adminAuditBuildRow(array_merge($aaGoodEntry, ['actorLabel' => '   '])), 'a blank actor label is refused');
assertEquals(null, adminAuditBuildRow(array_merge($aaGoodEntry, ['entityType' => ''])), 'a blank entity type is refused');
$aaLong = adminAuditBuildRow(array_merge($aaGoodEntry, ['actorLabel' => str_repeat('x', 300), 'userAgent' => str_repeat('u', 900), 'reason' => "line1\nline2", 'actorUserID' => '7']));
assertEquals(255, mb_strlen((string) $aaLong['actorLabel']), 'actor label trimmed to its 255-character column');
assertEquals(500, mb_strlen((string) $aaLong['userAgent']), 'user agent trimmed to 500 characters (plan §6.7)');
assertEquals('line1 line2', $aaLong['reason'], 'line breaks removed from logged text');
assertEquals(null, $aaLong['actorUserID'], 'a string actorUserID is not trusted (NULL)');

// ==============================================================================
// ✅ Section 7: dates and time zones (plan §8.4)
// ==============================================================================

assertEquals('2026-12-31 23:59:59', adminLocalToUtc('2026-12-31', 'Europe/London', true), 'end 31 Dec 2026 (GMT) → 2026-12-31 23:59:59 UTC');
assertEquals('2027-06-30 22:59:59', adminLocalToUtc('2027-06-30', 'Europe/London', true), 'end 30 Jun 2027 (BST) → 2027-06-30 22:59:59 UTC');
assertEquals('2027-06-29 23:00:00', adminLocalToUtc('2027-06-30', 'Europe/London', false), 'start 30 Jun 2027 (BST) → 2027-06-29 23:00:00 UTC');
assertEquals('2026-01-15 00:00:00', adminLocalToUtc('2026-01-15', 'Europe/London', false), 'start 15 Jan 2026 (GMT) → midnight UTC');
assertEquals('2026-10-24 23:59:00', adminLocalToUtc('2026-10-25T00:59', 'Europe/London', false), 'datetime-local "T" form accepted');
assertEquals('2026-07-01 13:30:15', adminLocalToUtc('2026-07-01 14:30:15', 'Europe/London', false), 'time with seconds accepted');
assertEquals('2026-12-31 23:59:59', adminLocalToUtc('2026-12-31', 'UTC', true), 'UTC zone: no shift');

$aaGap = adminLocalTimeCheck('2027-03-28 01:30', 'Europe/London', false);
assertEquals('nonexistent', $aaGap['problem'], 'clocks go forward: 01:30 on 28 Mar 2027 does not exist');
assertEquals('2027-03-28 02:00:00', $aaGap['suggestion'], 'clocks go forward: the first valid time (02:00) is suggested');
assertEquals(null, adminLocalToUtc('2027-03-28 01:30', 'Europe/London', false), 'a time that does not exist is refused');
$aaFold = adminLocalTimeCheck('2026-10-25 01:30', 'Europe/London', false);
assertEquals('ambiguous', $aaFold['problem'], 'clocks go back: 01:30 on 25 Oct 2026 happens twice');
assertEquals('2026-10-25 02:00:00', $aaFold['suggestion'], 'clocks go back: the first unrepeated time (02:00) is suggested');
assertEquals(null, adminLocalToUtc('2026-10-25 01:30', 'Europe/London', false), 'a time that happens twice is refused');
assertEquals('2026-10-25 02:00:00', adminLocalToUtc('2026-10-25 02:00', 'Europe/London', false), 'just after the repeated hour is fine');
assertEquals('2027-03-28 00:59:59', adminLocalToUtc('2027-03-28 00:59:59', 'Europe/London', false), 'just before the gap is fine');

foreach (['2026-02-30', '2026-13-01', '31/12/2026', '2026-12-31 24:00', '2026-12-31 23:60', '2026-1-5', 'tomorrow', '', '1969-12-31'] as $aaBadDate) {
    assertEquals('invalid', adminLocalTimeCheck($aaBadDate, 'Europe/London', false)['problem'], 'not a usable date: ' . var_export($aaBadDate, true));
}
assertEquals('timezone', adminLocalTimeCheck('2026-12-31', 'Mars/Base', false)['problem'], 'unknown time zone refused');
assertTrue(adminTimezoneIsValid('Europe/London') && adminTimezoneIsValid('UTC'), 'Europe/London and UTC are valid zones');
assertFalse(adminTimezoneIsValid('') || adminTimezoneIsValid('GMT+1') || adminTimezoneIsValid('Europe/Londonx'), 'made-up zones are not valid');
assertEquals('UK time', adminTimezoneLabel('Europe/London'), 'Europe/London is shown as "UK time"');

assertEquals('2027-06-30 23:59:59', adminUtcToLocal('2027-06-30 22:59:59', 'Europe/London'), 'UTC → UK summer time');
assertEquals('2026-12-31 23:59:59', adminUtcToLocal('2026-12-31 23:59:59', 'Europe/London'), 'UTC → UK winter time (no shift)');
assertEquals('', adminUtcToLocal('2026-02-30 00:00:00', 'Europe/London'), 'an impossible stored date → empty, never a guess');
assertEquals('', adminUtcToLocal('soon', 'Europe/London'), 'garbage → empty');
assertEquals('', adminUtcToLocal('2026-01-01 00:00:00', 'Nowhere/Zone'), 'unknown zone → empty');
assertEquals('5 Oct 2026, 14:02', adminFormatUtcForDisplay('2026-10-05 13:02:00', 'Europe/London'), 'display format "5 Oct 2026, 14:02" (BST)');
assertEquals('', adminFormatUtcForDisplay(null, 'Europe/London'), 'nothing to show → empty');
assertEquals('14:02', adminFormatClockTime((new \DateTimeImmutable('2026-10-05 13:02:00', new \DateTimeZone('UTC')))->getTimestamp(), 'Europe/London'), 'clock time in UK summer time');
assertEquals('13:02', adminFormatClockTime((new \DateTimeImmutable('2026-10-05 13:02:00', new \DateTimeZone('UTC')))->getTimestamp(), 'Not/AZone'), 'clock time falls back to UTC for an unknown zone');

// ==============================================================================
// ✅ Section 8: command line - options, masking and the decisions (plan §6.2)
// ==============================================================================

$aaParse = adminCliParseArgs(['admin_access.php', 'grant', ' Owner@Example.COM ', '--activate']);
assertTrue($aaParse['ok'], 'grant <email> --activate parses');
assertEquals('grant', $aaParse['command'], 'command is grant');
assertEquals('owner@example.com', $aaParse['email'], 'email is trimmed and lower-cased (as registration stores it)');
assertTrue($aaParse['activate'], '--activate is read');
assertFalse($aaParse['yes'], '--yes is off unless given');
$aaParse = adminCliParseArgs(['admin_access.php', '--yes', 'revoke', 'a@b.co']);
assertTrue($aaParse['ok'] && $aaParse['command'] === 'revoke' && $aaParse['yes'], 'options may come before the command');
assertEquals('list', adminCliParseArgs(['x', 'list'])['command'], 'list parses');
assertEquals('help', adminCliParseArgs(['x', '--help'])['command'], '--help parses');
assertEquals('help', adminCliParseArgs(['x', 'grant', '-h'])['command'], '-h anywhere means help');
assertEquals('help', adminCliParseArgs(['x', 'help'])['command'], 'help parses');
foreach ([
    'nothing at all'                => ['x'],
    'unknown command'               => ['x', 'promote', 'a@b.co'],
    'unknown option'                => ['x', 'grant', 'a@b.co', '--force'],
    'grant without email'           => ['x', 'grant'],
    'grant with two emails'         => ['x', 'grant', 'a@b.co', 'c@d.co'],
    'not an email'                  => ['x', 'grant', 'not-an-email'],
    'revoke with --activate'        => ['x', 'revoke', 'a@b.co', '--activate'],
    'list with an email'            => ['x', 'list', 'a@b.co'],
    'list with --yes'               => ['x', 'list', '--yes'],
    'over-long email'               => ['x', 'grant', str_repeat('a', 250) . '@b.co'],
] as $aaWhy => $aaArgv) {
    $aaParse = adminCliParseArgs($aaArgv);
    assertFalse($aaParse['ok'], 'command line refused: ' . $aaWhy);
    assertTrue($aaParse['error'] !== '', 'command line refusal explains itself: ' . $aaWhy);
}
assertFalse(str_contains(adminCliParseArgs(['x', "--bad\noption"])['error'], "\n"), 'an echoed bad option cannot add a line break');

assertTrue(adminCliConfirmationMatches(" Owner@Example.com\n", 'owner@example.com'), 'confirmation: case and spaces do not matter');
assertFalse(adminCliConfirmationMatches("owner@example.co\n", 'owner@example.com'), 'confirmation: a different email does not match');
assertFalse(adminCliConfirmationMatches(false, 'owner@example.com'), 'confirmation: nothing typed (no terminal) does not match');
assertFalse(adminCliConfirmationMatches('', ''), 'confirmation: empty never matches');

assertEquals('o***r@example.com', adminMaskEmail('owner@example.com'), 'masked email keeps first and last letter and the domain');
assertEquals('a***@example.com', adminMaskEmail('ab@example.com'), 'short local part is masked after the first letter');
assertEquals('***', adminMaskEmail('nonsense'), 'not an email → fully hidden');
assertEquals('', adminNormaliseEmail('not an email'), 'bad email → empty');

$aaAccount = static function (array $aaOverrides): array {
    return array_merge(['userID' => 7, 'roleID' => null, 'status' => 'pending', 'emailVerifiedAt' => null, 'hasPassword' => 1], $aaOverrides);
};
assertEquals('unknown_email', adminGrantDecision([], true)['code'], 'grant: unknown email refused');
assertEquals('refuse', adminGrantDecision([], true)['outcome'], 'grant: unknown email is a refusal');
assertEquals('ambiguous_email', adminGrantDecision([$aaAccount([]), $aaAccount(['userID' => 8])], true)['code'], 'grant: two accounts with the email refused');
assertEquals('bad_status', adminGrantDecision([$aaAccount(['status' => 'suspended'])], true)['code'], 'grant: suspended account refused');
assertEquals('bad_status', adminGrantDecision([$aaAccount(['status' => 'deleted'])], true)['code'], 'grant: deleted account refused');
assertEquals('no_password', adminGrantDecision([$aaAccount(['hasPassword' => 0])], true)['code'], 'grant: account with no password refused');
assertEquals('no_password', adminGrantDecision([$aaAccount(['hasPassword' => '0'])], true)['code'], "grant: hasPassword '0' from MySQLi refused");
$aaGrant = adminGrantDecision([$aaAccount([])], true);
assertTrue($aaGrant['outcome'] === 'grant' && $aaGrant['activates'] === true, 'grant --activate on a pending account: grant and activate');
$aaGrant = adminGrantDecision([$aaAccount([])], false);
assertTrue($aaGrant['outcome'] === 'refuse' && $aaGrant['code'] === 'needs_activation' && $aaGrant['activates'] === false, 'I1: grant on a pending account without --activate is REFUSED (the new password of decision 0010 must happen)');
assertContains('--activate', $aaGrant['message'], 'I1: the refusal tells the operator to add --activate');
$aaGrant = adminGrantDecision([$aaAccount(['status' => 'active', 'emailVerifiedAt' => null])], false);
assertEquals('needs_activation', $aaGrant['code'], 'I1: grant on an active account whose email was never checked, without --activate, is refused');
$aaGrant = adminGrantDecision([$aaAccount(['status' => 'active', 'emailVerifiedAt' => '2026-01-01 00:00:00'])], true);
assertTrue($aaGrant['outcome'] === 'grant' && $aaGrant['activates'] === false && $aaGrant['warnings'] === [], 'grant on an active, verified account: nothing to activate, no warning');
assertEquals('nothing', adminGrantDecision([$aaAccount(['roleID' => '1', 'status' => 'active', 'emailVerifiedAt' => '2026-01-01 00:00:00'])], true)['outcome'], 'grant on an existing working admin: nothing to do');
$aaGrant = adminGrantDecision([$aaAccount(['roleID' => 1])], true);
assertTrue($aaGrant['outcome'] === 'grant' && $aaGrant['activates'], 'grant --activate on an admin that is still pending: activates');
assertEquals('needs_activation', adminGrantDecision([$aaAccount(['roleID' => 1])], false)['code'], 'I1: grant without --activate on a pending admin is refused too');

// ------------------------------------------------------------------------------
// 🔐 Decision 0010: activating an account that has not proved its email
//    address sets a NEW password (and removes every earlier way in).
// ------------------------------------------------------------------------------
requireFn('fn_password.php');

$aaGrant = adminGrantDecision([$aaAccount([])], true);
assertTrue($aaGrant['resetsPassword'] === true, '0010: pending + --activate → the password is reset');
assertContains('decision 0010', $aaGrant['message'], '0010: the preview message says a new password will be set');
assertTrue(adminGrantDecision([$aaAccount(['roleID' => 1])], true)['resetsPassword'] === true, '0010: a pending account that already has role 1 + --activate → reset too');
assertTrue(adminGrantDecision([$aaAccount(['status' => 'active', 'emailVerifiedAt' => null])], true)['resetsPassword'] === true, '0010: active but email never checked + --activate → reset (the email is being vouched for)');
assertTrue(adminGrantDecision([$aaAccount([])], false)['resetsPassword'] === false, '0010: pending WITHOUT --activate → no reset (it is not activated, so it still cannot use the admin area)');
$aaActiveVerified = adminGrantDecision([$aaAccount(['status' => 'active', 'emailVerifiedAt' => '2026-01-01 00:00:00'])], true);
assertTrue($aaActiveVerified['resetsPassword'] === false && $aaActiveVerified['activates'] === false, '0010: an already active, verified account is NOT reset, even with --activate');
assertFalse(str_contains($aaActiveVerified['message'], 'decision 0010'), '0010: ...and its message does not mention a new password');
foreach ([[], [$aaAccount(['status' => 'suspended'])], [$aaAccount(['hasPassword' => 0])]] as $aaRefusedRows) {
    assertTrue(adminGrantDecision($aaRefusedRows, true)['resetsPassword'] === false, '0010: a refusal never says resetsPassword');
}
foreach ([[$aaAccount([])], [$aaAccount(['status' => 'active', 'emailVerifiedAt' => '2026-01-01 00:00:00'])], [$aaAccount(['roleID' => 1])]] as $aaSameRows) {
    $aaSame = adminGrantDecision($aaSameRows, true);
    assertTrue($aaSame['resetsPassword'] === $aaSame['activates'], '0010: resetsPassword always equals activates');
}

// 🪪 What the operator is shown before confirming.
$aaPreviewRow = ['userID' => 7, 'email' => 'owner@example.com', 'displayName' => 'Lance', 'createdAt' => '2026-11-20 08:15:02', 'status' => 'pending', 'emailVerifiedAt' => null];
$aaPreview = implode("\n", adminGrantPreviewLines($aaPreviewRow, adminGrantDecision([$aaAccount([])], true)));
assertContains('Email address:  owner@example.com', $aaPreview, '0010 preview: shows the email address');
assertContains('Display name:   Lance', $aaPreview, '0010 preview: shows the display name');
assertContains('Registered at:  2026-11-20 08:15:02 UTC', $aaPreview, '0010 preview: shows when the account was registered, with its time zone');
assertContains('email address NOT checked yet', $aaPreview, '0010 preview: says the email is not checked');
assertContains('WARNING: if you did not register this account at that time, stop', $aaPreview, '0010 preview: the "if you did not register it then, stop" warning');
assertContains('NEW password', $aaPreview, '0010 preview: says a new password will be typed');
$aaPreviewActive = implode("\n", adminGrantPreviewLines(array_merge($aaPreviewRow, ['status' => 'active', 'emailVerifiedAt' => '2026-11-21 10:00:00']), adminGrantDecision([$aaAccount(['status' => 'active', 'emailVerifiedAt' => '2026-11-21 10:00:00'])], true)));
assertContains('Registered at:  2026-11-20 08:15:02 UTC', $aaPreviewActive, '0010 preview: the details are shown for an active account too');
assertFalse(str_contains($aaPreviewActive, 'WARNING'), '0010 preview: no warning when nothing will be reset');
assertContains('Display name:   (none given)', implode("\n", adminGrantPreviewLines(array_merge($aaPreviewRow, ['displayName' => null]), $aaGrant)), '0010 preview: an empty display name says "(none given)"');
assertContains('Registered at:  (unknown)', implode("\n", adminGrantPreviewLines(array_merge($aaPreviewRow, ['createdAt' => null]), $aaGrant)), '0010 preview: a missing registration time says "(unknown)"');

// 🧽 A stranger chooses the display name: it must not be able to drive the terminal.
$aaHostile = adminGrantPreviewLines(array_merge($aaPreviewRow, ['displayName' => "Evil\x1b[2J\x1b]0;x\x07\nWARNING: all fine\u{202E}gnp.exe\u{009B}31m"]), $aaGrant);
$aaHostileText = implode("\n", $aaHostile);
assertFalse((bool) preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $aaHostileText), '0010 preview: no control characters from a display name reach the terminal');
assertFalse(str_contains($aaHostileText, "\u{202E}") || str_contains($aaHostileText, "\u{009B}"), '0010 preview: no right-to-left override or C1 control character either');
assertEquals(count(adminGrantPreviewLines($aaPreviewRow, $aaGrant)), count($aaHostile), '0010 preview: a line break in the display name cannot add a (fake) line');
assertEquals('a?[b?c', adminCliSafeDisplay("a\x1b[b\u{200B}c"), 'adminCliSafeDisplay(): the escape character and invisible characters become "?" (a bare "[" is harmless)');
assertEquals('?', adminCliSafeDisplay("\xff\xfe"), 'adminCliSafeDisplay(): invalid UTF-8 keeps plain ASCII only');
assertEquals('', adminCliSafeDisplay(null), 'adminCliSafeDisplay(): null → empty');
assertEquals('', adminCliSafeDisplay(['x']), 'adminCliSafeDisplay(): an array → empty');
assertEquals('abcde...', adminCliSafeDisplay('abcdefghij', 5), 'adminCliSafeDisplay(): long text is cut with "..."');
assertEquals('Zoë Ünïcode', adminCliSafeDisplay('Zoë Ünïcode'), 'adminCliSafeDisplay(): ordinary accented letters are kept');

// ✂️ Line endings off, spaces kept (sign-up does not trim passwords).
assertEquals(' pass word ', adminCliStripLineEnding(" pass word \n"), 'adminCliStripLineEnding(): spaces kept, newline removed');
assertEquals('abc', adminCliStripLineEnding("abc\r\n"), 'adminCliStripLineEnding(): Windows line ending removed');
assertEquals('abc', adminCliStripLineEnding('abc'), 'adminCliStripLineEnding(): no line ending (last line) is fine');
assertEquals('', adminCliStripLineEnding("\n"), 'adminCliStripLineEnding(): just Enter → empty');
assertEquals(null, adminCliStripLineEnding(false), 'adminCliStripLineEnding(): end of input → null');

// ✅ The new password: typed twice, the site's own rules.
$aaGood = 'Correct-Horse-' . bin2hex(random_bytes(4));
assertTrue(adminCliNewPasswordCheck($aaGood, $aaGood, 8)['ok'], 'new password: a strong password typed twice the same is accepted');
$aaMismatch = adminCliNewPasswordCheck($aaGood, $aaGood . 'x', 8);
assertTrue($aaMismatch['ok'] === false && str_contains($aaMismatch['error'], 'did not match'), 'new password: two different typings are refused');
foreach (['short' => 'Ab1!', 'common' => 'Password123', 'repeated' => 'aaaaaaaaaa', 'empty' => ''] as $aaWhy => $aaWeak) {
    $aaWeakCheck = adminCliNewPasswordCheck($aaWeak, $aaWeak, 8);
    assertTrue($aaWeakCheck['ok'] === false && $aaWeakCheck['error'] !== '', 'new password: a ' . $aaWhy . ' password is refused by the site rules');
    assertTrue($aaWeak === '' || !str_contains($aaWeakCheck['error'], $aaWeak), 'new password: the refusal does not repeat the ' . $aaWhy . ' password');
}
assertEquals(validatePasswordPolicy('Ab1!', 8)['error'], adminCliNewPasswordCheck('Ab1!', 'Ab1!', 8)['error'], 'new password: the SAME rule and wording as sign-up (validatePasswordPolicy)');
assertFalse(adminCliNewPasswordCheck('Exactly9!', 'Exactly9!', 12)['ok'], 'new password: the password_min_length setting is used (12 → 9 characters refused)');
assertFalse(adminCliNewPasswordCheck(null, null, 8)['ok'], 'new password: nothing read → refused');
assertFalse(adminCliNewPasswordCheck($aaGood, null, 8)['ok'], 'new password: second typing missing → refused');
assertContains('at least 10 characters', adminCliPasswordRulesText(10), 'the rules text names the minimum length');
assertContains('will not be shown', adminCliPasswordRulesText(10), 'the rules text says typing is hidden');

assertEquals(
    '2 sign-in session(s) ended, 1 API key(s) switched off, 0 unused password-reset link(s) cancelled, 1 linked Microsoft/Google sign-in(s) removed, 0 passkey(s) removed.',
    adminCliRemovedSummary(['sessions_ended' => 2, 'api_keys_turned_off' => 1, 'password_reset_links_cancelled' => 0, 'linked_sign_ins_removed' => 1, 'passkeys_removed' => 0]),
    'adminCliRemovedSummary(): every kind of access, in plain words'
);
assertContains('0 sign-in session(s) ended', adminCliRemovedSummary([]), 'adminCliRemovedSummary(): nothing removed → zeros');

// 🛡️ Only a password HASH is ever stored: plain text handed to adminCliGrant()
//    is refused before the database is touched.
$aaPlainAsHash = adminCliGrant('owner@example.com', true, 'cli:test', 7, 'not-a-hash-' . bin2hex(random_bytes(4)));
assertTrue($aaPlainAsHash['exitCode'] === CUERCODE_ADMIN_CLI_EXIT_FAILED && str_contains($aaPlainAsHash['message'], 'stored (hashed) form'), 'adminCliGrant(): plain text instead of a hash is refused (exit 1, nothing touched)');

// 🚫 There is no option that carries a password.
foreach (['--password', '--password=Secret123!', '--new-password=Secret123!', '-p'] as $aaPasswordOption) {
    $aaParse = adminCliParseArgs(['x', 'grant', 'owner@example.com', '--activate', $aaPasswordOption]);
    assertTrue($aaParse['ok'] === false && str_contains($aaParse['error'], 'Unknown option'), '0010: ' . strtok($aaPasswordOption, '=') . ' is not an option (a password is never taken from the command line)');
    assertFalse(str_contains($aaParse['error'], 'Secret123!'), '0010: the refusal does not print the value given after "=" (' . strtok($aaPasswordOption, '=') . ')');
}
assertContains('Unknown option: --force', adminCliParseArgs(['x', 'grant', 'a@b.co', '--force'])['error'], 'an unknown option without "=" is still named in full');

$aaAdminAccount = $aaAccount(['roleID' => '1', 'status' => 'active', 'emailVerifiedAt' => '2026-01-01 00:00:00']);
assertEquals('unknown_email', adminRevokeDecision([], 2)['code'], 'revoke: unknown email refused');
assertEquals('nothing', adminRevokeDecision([$aaAccount([])], 2)['outcome'], 'revoke: not an admin → nothing to do');
assertEquals('last_admin', adminRevokeDecision([$aaAdminAccount], 1)['code'], 'revoke: the last working admin is refused');
assertEquals('last_admin', adminRevokeDecision([$aaAdminAccount], -1)['code'], 'revoke: an unknown admin count is treated as "last" (fail closed)');
assertEquals('revoke', adminRevokeDecision([$aaAdminAccount], 2)['outcome'], 'revoke: with another working admin → allowed');
assertEquals('revoke', adminRevokeDecision([$aaAccount(['roleID' => 1, 'status' => 'suspended'])], 1)['outcome'], 'revoke: an admin who cannot use the area anyway may be removed even when one working admin is left');
assertEquals('ambiguous_email', adminRevokeDecision([$aaAdminAccount, $aaAdminAccount], 3)['code'], 'revoke: two accounts with the email refused');

// ==============================================================================
// ✅ Section 9: HTML helpers escape everything
// ==============================================================================

assertEquals('&lt;b&gt;&quot;x&#039;', adminEscape('<b>"x\''), 'adminEscape escapes < > " and \'');
assertEquals('', adminEscape(null), 'adminEscape(null) is empty');
$aaStatusHtml = adminStatusHtml('good', '<script>Set');
assertContains('&lt;script&gt;Set', $aaStatusHtml, 'status text is escaped');
assertContains('fa-circle-check', $aaStatusHtml, 'a good status has a tick icon (not colour alone)');
assertContains('aria-hidden="true"', $aaStatusHtml, 'status icons are hidden from screen readers (the words carry the meaning)');
$aaMessagesHtml = adminRenderMessages([
    ['type' => 'danger', 'text' => 'Bad <thing>', 'lines' => ['line <1>']],
    ['type' => 'danger', 'text' => 'Second'],
    ['type' => 'success', 'text' => 'Done'],
    ['type' => 'evil" onclick="x', 'text' => 'Odd type'],
]);
assertContains('role="alert" tabindex="-1" data-admin-focus="1"', $aaMessagesHtml, 'the first error is an alert and gets focus');
assertEquals(1, substr_count($aaMessagesHtml, 'data-admin-focus'), 'only ONE message gets focus');
assertContains('Bad &lt;thing&gt;', $aaMessagesHtml, 'message text is escaped');
assertContains('<li>line &lt;1&gt;</li>', $aaMessagesHtml, 'message lines are escaped');
assertContains('alert alert-success" role="status"', $aaMessagesHtml, 'success uses role="status"');
assertFalse(str_contains($aaMessagesHtml, 'onclick'), 'an unknown message type cannot inject attributes');
assertEquals('', adminRenderMessages([]), 'no messages → no HTML');

// ==============================================================================
// ✅ Section 10: STATIC GUARDS
// ==============================================================================

$aaSiteRoot   = CUERCODE_SITE_ROOT;
$aaAdminRoot  = $aaSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR;
$aaAdminPages = [];
$aaIterator   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($aaAdminRoot, \FilesystemIterator::SKIP_DOTS));
foreach ($aaIterator as $aaFile) {
    if ($aaFile->isFile() && $aaFile->getFilename() === 'index.php') {
        $aaAdminPages[] = $aaFile->getPathname();
    }
}
sort($aaAdminPages);
assertTrue(count($aaAdminPages) >= 3, 'the admin area has its pages (/admin/, /admin/reauth/, /admin/upgrades/)');

/**
 * 🧩 Split a PHP file's code into statements of meaningful tokens (no
 *    comments, no whitespace, no open/close tags), ending at each ';' that is
 *    not inside ( ) or [ ], and at every { or }.
 *
 * @param string $aaSource PHP source
 * @return array<int, array<int, array|string>>
 */
function aaStatements(string $aaSource): array
{
    $aaStatementsFound = [];
    $aaCurrent = [];
    $aaDepth = 0;

    foreach (token_get_all($aaSource) as $aaToken) {
        if (is_array($aaToken) && in_array($aaToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
            if ($aaToken[0] === T_INLINE_HTML && $aaCurrent !== []) {
                $aaStatementsFound[] = $aaCurrent;
                $aaCurrent = [];
            }
            continue;
        }

        // 🧱 Braces end a statement too (so a function body is many
        //    statements, not one); only ( ) and [ ] count as nesting.
        if ($aaToken === '{' || $aaToken === '}' || (is_array($aaToken) && $aaToken[0] === T_CURLY_OPEN)) {
            if ($aaCurrent !== []) {
                $aaStatementsFound[] = $aaCurrent;
                $aaCurrent = [];
            }
            continue;
        }

        if ($aaToken === '(' || $aaToken === '[') {
            $aaDepth++;
        } elseif ($aaToken === ')' || $aaToken === ']') {
            $aaDepth--;
        }

        if ($aaToken === ';' && $aaDepth === 0) {
            $aaStatementsFound[] = $aaCurrent;
            $aaCurrent = [];
            continue;
        }

        $aaCurrent[] = $aaToken;
    }

    if ($aaCurrent !== []) {
        $aaStatementsFound[] = $aaCurrent;
    }

    return $aaStatementsFound;
}

/**
 * 📞 The names of the functions a statement calls, in order (a name token
 *    followed by "(").
 *
 * @param array $aaStatement Tokens from aaStatements()
 * @return array<int, string>
 */
function aaCalledFunctions(array $aaStatement): array
{
    $aaCalls = [];
    $aaCount = count($aaStatement);

    for ($aaIndex = 0; $aaIndex < $aaCount - 1; $aaIndex++) {
        $aaToken = $aaStatement[$aaIndex];
        if (is_array($aaToken) && in_array($aaToken[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_VARIABLE], true) && $aaStatement[$aaIndex + 1] === '(') {
            $aaCalls[] = ltrim((string) $aaToken[1], '\\');
        }
    }

    return $aaCalls;
}

/**
 * 🚪 Guard (a) for one page's source: how many plain requires come first,
 *    and whether the first statement after them starts by calling
 *    requireGlobalAdminPage(). A require that calls a function, or any other
 *    statement before the gate, makes gateFirst false.
 *
 * @param string $aaSource PHP source of a page
 * @return array{requires: int, gateFirst: bool}
 */
function aaGateCheck(string $aaSource): array
{
    $aaRequires = 0;

    foreach (aaStatements($aaSource) as $aaStatement) {
        $aaFirst = $aaStatement[0];
        if (is_array($aaFirst) && in_array($aaFirst[0], [T_REQUIRE, T_REQUIRE_ONCE], true)) {
            if (aaCalledFunctions($aaStatement) !== []) {
                return ['requires' => $aaRequires, 'gateFirst' => false];
            }
            $aaRequires++;
            continue;
        }

        $aaCalls = aaCalledFunctions($aaStatement);
        return ['requires' => $aaRequires, 'gateFirst' => (isset($aaCalls[0]) && $aaCalls[0] === 'requireGlobalAdminPage')];
    }

    return ['requires' => $aaRequires, 'gateFirst' => false];
}

/**
 * 🎭 Guard (b) for one file's source: does any statement's SQL text both
 *    mention roleID and write (UPDATE / INSERT / REPLACE)? Joins every string
 *    piece of a statement first, so SQL split across '...' . '...' is seen.
 *
 * @param string $aaSource PHP source
 * @return bool
 */
function aaWritesRole(string $aaSource): bool
{
    foreach (aaStatements($aaSource) as $aaStatement) {
        $aaStrings = '';
        foreach ($aaStatement as $aaToken) {
            if (is_array($aaToken) && in_array($aaToken[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $aaStrings .= ' ' . $aaToken[1];
            }
        }
        if (stripos($aaStrings, 'roleID') !== false && preg_match('/\b(UPDATE|INSERT|REPLACE)\b/i', $aaStrings) === 1) {
            return true;
        }
    }

    return false;
}

// 🧪 The guards themselves catch what they should (deliberately bad samples).
$aaGoodPage = "<?php\nrequire_once __DIR__ . '/../../_includes/bootstrap.php';\nrequire_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';\n\$u = requireGlobalAdminPage('/admin/');\n\$x = \$_GET['a'];\n";
assertEquals(['requires' => 2, 'gateFirst' => true], aaGateCheck($aaGoodPage), 'guard (a) sample: a page with the gate first passes');
assertFalse(aaGateCheck("<?php\nrequire_once __DIR__ . '/b.php';\n\$x = \$_GET['a'];\n\$u = requireGlobalAdminPage('/admin/');\n")['gateFirst'], 'guard (a) sample: reading input before the gate is caught');
assertFalse(aaGateCheck("<?php\nrequire_once __DIR__ . '/b.php';\nlogActivity('x');\n\$u = requireGlobalAdminPage('/admin/');\n")['gateFirst'], 'guard (a) sample: another call before the gate is caught');
assertFalse(aaGateCheck("<?php\nrequire_once dirname(__DIR__) . '/b.php';\n\$u = requireGlobalAdminPage('/admin/');\n")['gateFirst'], 'guard (a) sample: a function call hidden in a require is caught');
assertFalse(aaGateCheck("<?php\nrequire_once __DIR__ . '/b.php';\nif (true) {\n\$u = requireGlobalAdminPage('/admin/');\n}\n")['gateFirst'], 'guard (a) sample: a gate inside an if is caught');
assertTrue(aaWritesRole("<?php function f() { if (1) { dbQuery('UPDATE `tblUsers` SET ' . '`roleID` = 1 WHERE `userID` = ?', 'i', [1]); } }"), 'guard (b) sample: an UPDATE of roleID split across strings is caught');
assertTrue(aaWritesRole("<?php \$sql = \"INSERT INTO `tblUsers` (`email`, `roleID`) VALUES (?, 1)\";"), 'guard (b) sample: an INSERT with roleID is caught');
assertFalse(aaWritesRole("<?php function f() { dbQuery('SELECT `roleID` FROM `tblUsers` WHERE `userID` = ?', 'i', [1]); dbQuery('UPDATE `tblUsers` SET `status` = 1', '', []); }"), 'guard (b) sample: reading roleID next to an unrelated UPDATE is fine');

// 🅰️ (a) requireGlobalAdminPage( comes before any other application call.
foreach ($aaAdminPages as $aaPage) {
    $aaRelative = str_replace($aaSiteRoot, '', $aaPage);
    $aaGate     = aaGateCheck((string) file_get_contents($aaPage));

    assertEquals(2, $aaGate['requires'], $aaRelative . ': loads exactly bootstrap.php and fn_admin.php (plain paths) before the gate');
    assertTrue($aaGate['gateFirst'], $aaRelative . ': requireGlobalAdminPage( is the first application call (plan §9 guard a)');

    $aaPageSource = (string) file_get_contents($aaPage);
    assertContains('bootstrap.php', $aaPageSource, $aaRelative . ': loads bootstrap.php');
    assertContains("'fn_admin.php'", $aaPageSource, $aaRelative . ': loads fn_admin.php');
    assertMatches('/^\$pageNoIndex\s*=\s*true;/m', $aaPageSource, $aaRelative . ': $pageNoIndex = true (kept out of search results)');
    assertContains("'/assets/js/cuercode-admin.js'", $aaPageSource, $aaRelative . ': uses the one admin script file');

    // 🅳 (d) no inline scripts or inline event handlers in admin pages.
    assertFalse((bool) preg_match('/<script\b/i', $aaPageSource), $aaRelative . ': no inline <script> (plan §9 guard d)');
    assertFalse((bool) preg_match('/\son[a-z]+\s*=\s*["\']/i', $aaPageSource), $aaRelative . ': no inline on...= event handlers');

    // 🧾 Every POST form carries the CSRF field.
    assertEquals(
        substr_count(strtolower($aaPageSource), 'method="post"'),
        substr_count($aaPageSource, 'csrfField()'),
        $aaRelative . ': every POST form carries csrfField()'
    );

    // 🔐 Every change handler checks the POST through adminCheckPost() first.
    if (str_contains($aaPageSource, "\$_SERVER['REQUEST_METHOD'] === 'POST'")) {
        assertContains('adminCheckPost(', $aaPageSource, $aaRelative . ': POST handling goes through adminCheckPost() (CSRF, same site, fresh password)');
    }
}

// 🅱️ (b) roleID is written ONLY in fn_admin.php and admin_access.php.
$aaAllowedWriters = [
    '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php',
    '_includes' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'admin_access.php',
];
$aaWriters  = [];
$aaPhpFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($aaSiteRoot, \FilesystemIterator::SKIP_DOTS));
foreach ($aaPhpFiles as $aaFile) {
    if (!$aaFile->isFile() || $aaFile->getExtension() !== 'php') {
        continue;
    }
    $aaRelative = str_replace($aaSiteRoot, '', $aaFile->getPathname());
    if (str_starts_with($aaRelative, '_libraries' . DIRECTORY_SEPARATOR) || in_array($aaRelative, $aaAllowedWriters, true)) {
        continue;
    }

    if (aaWritesRole((string) file_get_contents($aaFile->getPathname()))) {
        $aaWriters[] = $aaRelative;
    }
}
assertEquals([], $aaWriters, 'roleID is written only in fn_admin.php and admin_access.php (plan §9 guard b, S3)');
assertTrue(aaWritesRole((string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php')), 'guard (b) is not vacuous: fn_admin.php (an allowed writer) is detected as writing roleID');
assertFalse(
    (bool) preg_match('/roleID/i', (string) file_get_contents($aaSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql')),
    'seed_data.sql never sets a roleID (nobody is an admin out of the box)'
);

// 🅲 (c) no /admin route in the API router or the OpenAPI description.
$aaRouterSource = (string) file_get_contents($aaSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php');
assertFalse((bool) preg_match("/'pattern'\s*=>\s*'\/?admin/i", $aaRouterSource), 'no API route pattern starts with admin (plan §7.3, guard c)');
$aaOpenApi = json_decode((string) file_get_contents($aaSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'openapi.json'), true);
$aaAdminPaths = [];
foreach (array_keys((array) $aaOpenApi['paths']) as $aaPath) {
    if (stripos((string) $aaPath, '/admin') === 0) {
        $aaAdminPaths[] = $aaPath;
    }
}
assertEquals([], $aaAdminPaths, 'no openapi.json path starts with /admin (guard c)');

// 🧱 The admin .htaccess sends the §7.4 headers.
$aaHtaccess = (string) file_get_contents($aaAdminRoot . '.htaccess');
assertContains('Header always set X-Frame-Options "DENY"', $aaHtaccess, 'admin .htaccess: X-Frame-Options DENY');
assertFalse(str_contains($aaHtaccess, 'set with "Header always set" (also on error pages)'), 'review finding 10: the comment no longer claims Apache\'s own error pages get the admin headers');
assertContains('NO: Apache\'s OWN error page for a file under /admin/', $aaHtaccess, 'review finding 10: the comment says plainly which error pages do NOT get them (measured)');
assertContains('Header always set Cache-Control "no-store"', $aaHtaccess, 'admin .htaccess: Cache-Control no-store');
assertContains('Header always set X-Robots-Tag "noindex, nofollow"', $aaHtaccess, 'admin .htaccess: X-Robots-Tag noindex, nofollow');
assertContains("frame-ancestors 'none'", $aaHtaccess, "admin .htaccess: CSP frame-ancestors 'none'");
assertContains("script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;", $aaHtaccess, 'admin .htaccess: no new script sources');
$aaSiteHtaccess = (string) file_get_contents($aaSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . '.htaccess');
preg_match('/Header set Content-Security-Policy "([^"]+)"/', $aaSiteHtaccess, $aaSiteCsp);
preg_match('/Header always set Content-Security-Policy "([^"]+)"/', $aaHtaccess, $aaAdminCsp);
// 🤖 #163: the site policy also allows the three human-check providers; the
//    admin area shows no human check, so its policy leaves them out. Every
//    provider address is taken out of the site policy (and a directive left
//    as only 'self' is dropped, as it was before), then the two must match.
$aaSiteCspForAdmin = str_replace(
    [
        ' https://challenges.cloudflare.com', ' https://www.google.com/recaptcha/', ' https://www.gstatic.com/recaptcha/',
        ' https://recaptcha.google.com/recaptcha/', ' https://hcaptcha.com', ' https://*.hcaptcha.com',
        " frame-src 'self';",
    ],
    '',
    (string) $aaSiteCsp[1]
);
assertFalse(str_contains((string) $aaAdminCsp[1], 'hcaptcha') || str_contains((string) $aaAdminCsp[1], 'recaptcha') || str_contains((string) $aaAdminCsp[1], 'challenges.cloudflare.com'), 'admin CSP: no human-check provider is allowed in the admin area (#163)');
assertEquals(
    str_replace("frame-ancestors 'self'", "frame-ancestors 'none'", $aaSiteCspForAdmin),
    (string) $aaAdminCsp[1],
    "admin CSP = the site CSP with frame-ancestors 'none' and without the human-check providers (the two stay in step)"
);
assertFalse(str_contains((string) file_get_contents($aaSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'robots.txt'), 'admin'), 'robots.txt does not advertise /admin/');

// 🧪 1B review fixes (#31): wording and ordering guards.
assertFalse((bool) preg_match('/^\s*Header (set|append)/m', $aaHtaccess), 'admin .htaccess: no plain "Header set"/"Header append" (review finding I); only "Header always set" and unset lines');
// 🔁 Review fix M1: on a real Apache the site's plain "Header set" and the admin's "Header always set"
//    BOTH reach the browser unless the site value is removed first. Each admin header must be unset
//    (plain and always) BEFORE it is set. (Checked on a real Apache 2.4.71 in Docker, 2026-11-25.)
foreach (['X-Frame-Options', 'Cache-Control', 'X-Robots-Tag', 'Content-Security-Policy'] as $aaHeaderName) {
    $aaUnsetPos  = strpos($aaHtaccess, 'Header unset ' . $aaHeaderName);
    $aaAlwaysPos = strpos($aaHtaccess, 'Header always unset ' . $aaHeaderName);
    $aaSetPos    = strpos($aaHtaccess, 'Header always set ' . $aaHeaderName);
    assertTrue($aaUnsetPos !== false && $aaAlwaysPos !== false && $aaSetPos !== false && $aaUnsetPos < $aaSetPos && $aaAlwaysPos < $aaSetPos, 'M1: admin .htaccess removes ' . $aaHeaderName . ' (plain and always) before setting it, so it is sent once');
}
assertContains('ExpiresActive Off', $aaHtaccess, 'M1: admin .htaccess switches mod_expires off so static files get no second Cache-Control');

$aaGateSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php');
// 🔒 Review fix M2: the first-seen check locks the admin's own row, never a server-wide named lock.
$aaFirstSeenStart = strpos($aaGateSource, 'function adminRecordFirstSeen(');
$aaFirstSeenBody  = substr($aaGateSource, (int) $aaFirstSeenStart, 6000);
$aaFirstSeenBody  = substr($aaFirstSeenBody, 0, (int) strpos($aaFirstSeenBody, "// 🗄️ DATABASE"));
assertTrue($aaFirstSeenStart !== false && $aaFirstSeenBody !== '', 'M2 sanity: adminRecordFirstSeen() found');
assertFalse(str_contains($aaFirstSeenBody, 'GET_LOCK') || str_contains($aaFirstSeenBody, 'RELEASE_LOCK'), 'M2: adminRecordFirstSeen() uses no server-wide GET_LOCK()');
assertContains('FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE', $aaFirstSeenBody, 'M2: it locks the admin\'s own tblUsers row (SELECT ... FOR UPDATE)');
assertContains('begin_transaction()', $aaFirstSeenBody, 'M2: inside a short transaction');
assertFalse(str_contains($aaGateSource, 'CUERCODE_ADMIN_FIRST_SEEN_LOCK_PREFIX'), 'M2: the named-lock prefix constant is gone');
// 🛑 Review fix I1: the helper that verifyEmailToken() relies on.
assertTrue(function_exists('adminUserHoldsGlobalAdminRole'), 'I1: adminUserHoldsGlobalAdminRole() exists');
assertTrue(adminUserHoldsGlobalAdminRole(1) === true, 'I1: with no database, the helper answers "treat as admin" (a fault can only refuse, never allow)');
$aaGateStart  = strpos($aaGateSource, 'function requireGlobalAdminPage(');
$aaGateBody   = substr($aaGateSource, (int) $aaGateStart, 4500);
$aaHeadersAt  = strpos($aaGateBody, 'adminSendSecurityHeaders();');
$aaNotFoundAt = strpos($aaGateBody, 'adminRenderNotFoundAndExit();');
assertTrue($aaHeadersAt !== false && $aaNotFoundAt !== false && $aaHeadersAt > $aaNotFoundAt, 'the admin headers are sent only AFTER the not-an-admin 404 branch (review finding E)');

$aaAccountSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_account.php');
assertContains("adminSqlNotGlobalAdminCondition()", $aaAccountSource, 'the erase UPDATE itself refuses a Global Admin (check and erase are one statement, review finding G)');
assertContains('GLOBAL_ADMIN_CANNOT_ERASE', substr($aaAccountSource, (int) strpos($aaAccountSource, '$adminRefusedDuringErase)')), 'zero rows changed for a Global Admin gives the same refusal');

assertContains('#[\\SensitiveParameter] string $plainPassword', (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_password.php'), 'verifyPassword() hides the password from stack traces (review finding J)');

$aaCliSource  = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'cli' . DIRECTORY_SEPARATOR . 'admin_access.php');
$aaWarnAt     = strpos($aaCliSource, "'WARNING: '");
$aaRetypeAt   = strpos($aaCliSource, 'Type the email address again');
assertTrue($aaWarnAt !== false && $aaRetypeAt !== false && $aaWarnAt < $aaRetypeAt, 'the command line prints its warnings BEFORE asking for the email to be retyped (review finding D)');

// 🔐 Decision 0010, in the order the operator meets it.
$aaPreviewAt  = strpos($aaCliSource, 'foreach (adminGrantPreviewLines(');
$aaProblemAt  = strpos($aaCliSource, '$adminCliInputProblem = adminCliHiddenInputProblem();');
$aaAskAt      = strpos($aaCliSource, '$adminCliAsked = adminCliAskNewPasswordHash();');
$aaGrantAt    = strpos($aaCliSource, 'adminCliFinish(adminCliGrant(');
assertTrue($aaPreviewAt !== false && $aaPreviewAt < $aaRetypeAt, '0010: the account details (email, name, registration time) are shown BEFORE the typed confirmation');
assertTrue($aaProblemAt !== false && $aaProblemAt < $aaRetypeAt, '0010: "can the password be typed hidden here?" is checked BEFORE anything is asked');
assertTrue($aaAskAt !== false && $aaAskAt > $aaRetypeAt && $aaAskAt < $aaGrantAt, '0010: the new password is asked AFTER the typed confirmation and BEFORE the change');
assertContains('$adminCliShownUserID, $adminCliNewPasswordHash)', $aaCliSource, '0010: the grant gets the account number that was shown, and only the HASH');
foreach (['getenv(', '$_ENV', 'STDIN_PASSWORD', "'--password'"] as $aaNoSource) {
    assertFalse(str_contains($aaCliSource, $aaNoSource), '0010: admin_access.php never reads a password from ' . $aaNoSource);
}
$aaAdminSourceForCli = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php');
foreach (['shell_exec(', 'passthru(', 'system(', 'popen(', 'exec(\''] as $aaNoShell) {
    assertFalse(str_contains($aaAdminSourceForCli, $aaNoShell), 'fn_admin.php never starts a shell (' . $aaNoShell . ') - stty runs with an argument list');
}
assertContains("proc_open(array_merge(['stty'], array_values(\$arguments))", $aaAdminSourceForCli, '0010: stty is run with proc_open() and an argument list (no shell)');
assertContains("[0 => STDIN,", $aaAdminSourceForCli, '0010: stty is handed the operator\'s own terminal');
assertContains("preg_match('/(^|\\s)-echo(\\s|\$)/', \$nowSettings)", $aaAdminSourceForCli, '0010: the typing is read only after the terminal CONFIRMS echo is off');
assertContains('#[\SensitiveParameter] ?string $newPasswordHash', $aaAdminSourceForCli, '0010: the new password hash is hidden from stack traces');
assertContains('#[\SensitiveParameter] ?string $first', $aaAdminSourceForCli, '0010: the typed password is hidden from stack traces');
$aaPasswordSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_password.php');
assertContains('function hashPassword(#[\SensitiveParameter] string $plainPassword)', $aaPasswordSource, 'hashPassword() hides the password from stack traces');
assertContains('function validatePasswordPolicy(#[\SensitiveParameter] string $plainPassword', $aaPasswordSource, 'validatePasswordPolicy() hides the password from stack traces');
// 🚪 Decision 0013 moved the list of "doors" into ONE shared function,
//    accountRemoveOtherWaysIn() in fn_auth.php, used by this grant AND by the
//    password reset - so these pins now read that function's body, and check
//    that fn_admin.php no longer carries its own copy of the SQL.
$aaAuthSourceForDoors = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_auth.php');
$aaDoorsBody = substr($aaAuthSourceForDoors, (int) strpos($aaAuthSourceForDoors, 'function accountRemoveOtherWaysIn('), 3000);
foreach (['tblUserSessions', 'tblAPIKeys', 'tblPasswordResets', 'tblUserOAuth', 'tblWebAuthnCredentials'] as $aaAccessTable) {
    assertContains('`' . $aaAccessTable . '`', $aaDoorsBody, '0010: earlier access in ' . $aaAccessTable . ' is removed (shared accountRemoveOtherWaysIn())');
}
assertFalse(str_contains($aaAdminSourceForCli, 'function adminCliRemoveEarlierAccess('), '0013: fn_admin.php no longer has its own copy of the "remove every way in" SQL');
assertFalse(str_contains($aaAdminSourceForCli, 'DELETE FROM `tblUserOAuth`'), '0013: the linked sign-in removal SQL is written once, in fn_auth.php');
$aaGrantBody = substr($aaAdminSourceForCli, (int) strpos($aaAdminSourceForCli, 'function adminCliGrant('), 12000);
assertTrue(
    strpos($aaGrantBody, 'begin_transaction()') < strpos($aaGrantBody, 'accountRemoveOtherWaysIn($userID, false)')
    && strpos($aaGrantBody, 'accountRemoveOtherWaysIn($userID, false)') < strpos($aaGrantBody, 'adminAuditWrite(')
    && strpos($aaGrantBody, 'adminAuditWrite(') < strpos($aaGrantBody, '$db->commit()'),
    '0010: password, earlier access and the audit row all change inside ONE transaction'
);

assertEquals('(`roleID` IS NULL OR `roleID` <> 1)', adminSqlNotGlobalAdminCondition(), 'the erase UPDATE condition keeps accounts with no role or a non-admin role only');

// 🔁 One admin_denied row per session, not per request (finding F).
assertFalse(adminDenialAlreadyLogged([], 7), 'denied: nothing in the session yet → write one');
assertTrue(adminDenialAlreadyLogged([CUERCODE_ADMIN_DENIED_KEY => 7], 7), 'denied: already written for this user → do not write again');
assertFalse(adminDenialAlreadyLogged([CUERCODE_ADMIN_DENIED_KEY => 8], 7), 'denied: flag belongs to another user → write one');
assertFalse(adminDenialAlreadyLogged([CUERCODE_ADMIN_DENIED_KEY => 7], 0), 'denied: user 0 → write');

// ⚠️ An upgrade whose change-log entry could not be saved must warn (finding C).
assertEquals('', adminUpgradeAuditWarning(['ok' => true, 'auditWritten' => true]), 'upgrade audit saved → no warning');
assertEquals('', adminUpgradeAuditWarning(['ok' => false, 'auditWritten' => null]), 'nothing attempted → no warning');
assertEquals('', adminUpgradeAuditWarning([]), 'no key → no warning');
assertContains('could not be saved', adminUpgradeAuditWarning(['ok' => true, 'auditWritten' => false]), 'upgrade audit NOT saved → clear warning');

// 🧱 fn_admin.php source rules.
$aaAdminSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php');
assertFalse(str_contains($aaAdminSource, '->query('), 'fn_admin.php never uses ->query() - prepared statements (dbQuery) only');
assertFalse(str_contains($aaAdminSource, 'multi_query'), 'fn_admin.php never runs several statements at once');
assertContains('@copyright   2025–2026 MWBM Partners Ltd', $aaAdminSource, 'fn_admin.php has the house copyright header');
assertContains('#[\SensitiveParameter] string $password', $aaAdminSource, 'the re-typed password is marked #[\SensitiveParameter] (plan S14)');
assertFalse((bool) preg_match('/\bfn\s*\(/', $aaAdminSource), 'fn_admin.php has no arrow functions (house rule)');
assertFalse((bool) preg_match('/\bmatch\s*\(/', $aaAdminSource), 'fn_admin.php has no match() (house rule)');
assertFalse(str_contains(preg_replace('#/\*.*?\*/#s', '', $aaAdminSource), '??'), 'fn_admin.php code has no ?? (house rule)');
assertEquals(1, substr_count($aaAdminSource, "define('CUERCODE_ROLE_GLOBAL_ADMIN'"), 'the Global Admin role number is defined in one place');
foreach (glob($aaSiteRoot . '_functions' . DIRECTORY_SEPARATOR . '*.php') as $aaFunctionFile) {
    if (basename($aaFunctionFile) !== 'fn_admin.php') {
        assertFalse(str_contains((string) file_get_contents($aaFunctionFile), "define('CUERCODE_ROLE_GLOBAL_ADMIN'"), basename($aaFunctionFile) . ' does not define the role number again');
    }
}

// 🧱 The command-line guard is the first statement of admin_access.php.
$aaCliPath   = CUERCODE_INCLUDES_DIR . 'cli' . DIRECTORY_SEPARATOR . 'admin_access.php';
$aaCliSource = (string) file_get_contents($aaCliPath);
$aaCliFirst  = aaStatements($aaCliSource);
assertTrue(
    is_array($aaCliFirst[0][0]) && $aaCliFirst[0][0][0] === T_IF && is_array($aaCliFirst[0][2]) && $aaCliFirst[0][2][1] === 'PHP_SAPI',
    'admin_access.php: the first statement is the "command line only" check'
);
assertContains("if (PHP_SAPI !== 'cli') {", $aaCliSource, 'admin_access.php: refuses anything but the command line');
assertTrue(
    strpos($aaCliSource, 'adminCliParseArgs(') < strpos($aaCliSource, "'bootstrap.php'"),
    'admin_access.php: options are checked before the site start-up code (and the database) is loaded'
);
assertContains("adminTableExists('tblAdminAuditLog')", $aaCliSource, 'admin_access.php: refuses to change roles without the audit table');
assertTrue(is_file(CUERCODE_INCLUDES_DIR . '.htaccess') && str_contains((string) file_get_contents(CUERCODE_INCLUDES_DIR . '.htaccess'), 'Require all denied'), '_includes/.htaccess denies web access (covers cli/admin_access.php)');

// 🧱 currentUser() returns roleID; the header link is only for Global Admins.
$aaAuthSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_auth.php');
assertContains('`u`.`status`, `u`.`roleID`', $aaAuthSource, 'currentUser() selects u.roleID');
assertContains("'roleID'            => \$roleID,", $aaAuthSource, 'currentUser() returns roleID');
$aaHeaderSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'header.php');
assertContains('adminRoleIsGlobalAdmin($headerCurrentUser[\'roleID\'])', $aaHeaderSource, 'header.php asks adminRoleIsGlobalAdmin() before showing "Admin"');
assertMatches('/<\?php if \(\$headerShowAdminLink\) \{ \?>\s*<li role="presentation">\s*<a class="dropdown-item" role="menuitem" href="\/admin\/">/', $aaHeaderSource, 'header.php shows the /admin/ link only inside if ($headerShowAdminLink)');
assertEquals(1, substr_count($aaHeaderSource, 'href="/admin/"'), 'header.php links to /admin/ exactly once');

// 🧱 The erasure guard (plan §6.5) runs before the password is checked.
$aaAccountSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_account.php');
$aaEraseStart = strpos($aaAccountSource, 'function eraseUserAccount(');
$aaEraseBody  = substr($aaAccountSource, (int) $aaEraseStart, 6000);
assertContains('SELECT `passwordHash`, `roleID` FROM `tblUsers`', $aaEraseBody, 'eraseUserAccount() reads roleID');
assertTrue(
    strpos($aaEraseBody, "'GLOBAL_ADMIN_CANNOT_ERASE'") !== false
    && strpos($aaEraseBody, "'GLOBAL_ADMIN_CANNOT_ERASE'") < strpos($aaEraseBody, 'verifyPassword($currentPassword'),
    'eraseUserAccount() refuses a Global Admin (409) before checking the password'
);
assertContains("'httpCode'  => 409,\n                'errorCode' => 'GLOBAL_ADMIN_CANNOT_ERASE'", $aaEraseBody, 'the erasure refusal is a 409');
assertContains('$isGlobalAdmin = true;', $aaEraseBody, 'erasure guard fails closed if fn_admin.php is missing (any role is refused)');
assertTrue(
    strpos($aaEraseBody, "'GLOBAL_ADMIN_CANNOT_ERASE'") < strpos($aaEraseBody, 'begin_transaction()'),
    'the erasure refusal happens before anything is changed'
);

// 🧱 The admin JavaScript file exists, is local, and has no debugging leftovers.
$aaAdminJs = (string) file_get_contents($aaSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'cuercode-admin.js');
assertContains('data-admin-focus', $aaAdminJs, 'cuercode-admin.js focuses the first error message');
assertFalse((bool) preg_match('/console\.(log|debug)|debugger/', $aaAdminJs), 'cuercode-admin.js has no console.log / debugger');
assertFalse((bool) preg_match('/https?:\/\//', preg_replace('#/\*.*?\*/#s', '', $aaAdminJs)), 'cuercode-admin.js loads nothing from another site');

// ==============================================================================
// ✅ Section 11: the real admin_access.php as a child process (--help / bad options ONLY)
// ==============================================================================
// ⚠️ NEVER add a case here that reaches the database: a real run on a machine
//    with a real _auth_keys/auth_creds.php could change a real database. The
//    command is an ARRAY, so no shell is involved.
// ==============================================================================

$aaRunCli = static function (array $aaCliArguments) use ($aaCliPath): array {
    $aaDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $aaProcess = proc_open(array_merge([PHP_BINARY, $aaCliPath], $aaCliArguments), $aaDescriptors, $aaPipes);

    if (!is_resource($aaProcess)) {
        return ['exit' => -1, 'out' => '', 'err' => ''];
    }

    fclose($aaPipes[0]);
    $aaOut = (string) stream_get_contents($aaPipes[1]);
    fclose($aaPipes[1]);
    $aaErr = (string) stream_get_contents($aaPipes[2]);
    fclose($aaPipes[2]);

    return ['exit' => proc_close($aaProcess), 'out' => $aaOut, 'err' => $aaErr];
};

$aaCli = $aaRunCli(['--help']);
assertEquals(0, $aaCli['exit'], 'real admin_access.php --help: exit 0');
assertContains('grant <email> [--activate] [--yes]', $aaCli['out'], 'real admin_access.php --help: usage on stdout');
assertEquals('', $aaCli['err'], 'real admin_access.php --help: stderr is empty');

$aaCli = $aaRunCli([]);
assertEquals(2, $aaCli['exit'], 'real admin_access.php with nothing: exit 2 (refused, nothing runs)');

$aaCli = $aaRunCli(['grant', 'owner@example.com', '--force']);
assertEquals(2, $aaCli['exit'], 'real admin_access.php with an unknown option: exit 2');
assertContains('Unknown option', $aaCli['err'], 'real admin_access.php: the reason is on stderr');

$aaCli = $aaRunCli(['grant', 'not-an-email']);
assertEquals(2, $aaCli['exit'], 'real admin_access.php grant with a bad email: exit 2');

$aaCli = $aaRunCli(['revoke', 'owner@example.com', '--activate']);
assertEquals(2, $aaCli['exit'], 'real admin_access.php revoke --activate: exit 2');

$aaCli = $aaRunCli(['grant', 'owner@example.com', '--activate', '--password=Secret123!']);
assertEquals(2, $aaCli['exit'], 'real admin_access.php grant --password=...: exit 2 (there is no such option)');
assertFalse(str_contains($aaCli['out'] . $aaCli['err'], 'Secret123!'), 'real admin_access.php: the value after --password= is not printed back');

// ⌨️ Decision 0010: with its input coming from a PIPE, the hidden-typing
//    helpers refuse and read nothing. A separate PHP process that loads
//    fn_admin.php ONLY (no database, no site start-up) is used, because this
//    test runner's own input may be a terminal.
$aaHiddenProbe = 'require ' . var_export(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php', true) . ';'
    . ' echo json_encode(["problem" => adminCliHiddenInputProblem(), "read" => adminCliReadHiddenLine("New password: ")]);';
$aaProbeProcess = proc_open([PHP_BINARY, '-r', $aaHiddenProbe], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $aaProbePipes);
if (is_resource($aaProbeProcess)) {
    fwrite($aaProbePipes[0], "Piped-Password-1!\nPiped-Password-1!\n");
    fclose($aaProbePipes[0]);
    $aaProbeOut = (string) stream_get_contents($aaProbePipes[1]);
    fclose($aaProbePipes[1]);
    stream_get_contents($aaProbePipes[2]);
    fclose($aaProbePipes[2]);
    proc_close($aaProbeProcess);
    $aaProbe = json_decode($aaProbeOut, true);
    assertTrue(is_array($aaProbe) && str_contains((string) $aaProbe['problem'], 'not a terminal'), '0010: input from a pipe → "its input is not a terminal"');
    assertTrue(is_array($aaProbe) && $aaProbe['read']['ok'] === false && $aaProbe['read']['line'] === null, '0010: input from a pipe → no line is read as a password');
    assertFalse(str_contains($aaProbeOut, 'Piped-Password-1!'), '0010: the piped text never comes back out');
} else {
    assertTrue(false, '0010: the pipe probe process could not be started');
}

// 📭 #113 B9 (findings 4 and 6b): `grant --activate` cancels the account's waiting verification and reset emails.
$aaFnAdminSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php');
$aaCancelStart   = strpos($aaFnAdminSource, 'function adminCliCancelUnsentAccountEmails(');
assertTrue($aaCancelStart !== false, 'B9: the email-cancelling function exists');
$aaCancelBody = substr($aaFnAdminSource, (int) $aaCancelStart, 1800);
assertTrue(str_contains($aaCancelBody, "SET `status` = 'skipped'") && str_contains($aaCancelBody, '`paramsJSON` = NULL'), 'B9: waiting emails are marked skipped and their stored details cleared');
assertTrue(str_contains($aaCancelBody, "`status` IN ('queued', 'failed') AND `attempts` < `maxAttempts`"), 'B9: only WAITING rows (queued, or failed with attempts left) are touched - never sending, sent or given-up rows');
assertTrue(str_contains($aaCancelBody, "['verification', 'password_reset']"), 'B9: only the verification and password-reset templates');
assertTrue(str_contains($aaCancelBody, 'WHERE `toAddress` = ? AND `templateKey` = ?'), 'B9: matched by recipient address and template, with a prepared statement');
$aaGrantStart = strpos($aaFnAdminSource, 'function adminCliGrant(');
$aaGrantBody  = substr($aaFnAdminSource, (int) $aaGrantStart, (int) strpos($aaFnAdminSource, 'function adminCliRevoke(') - (int) $aaGrantStart);
assertTrue(str_contains($aaGrantBody, "if (\$decision['activates']) {\n            \$emailsCancelled = adminCliCancelUnsentAccountEmails("), 'B9: the grant cancels them only when it activates the account');
assertTrue(strpos($aaGrantBody, 'adminCliCancelUnsentAccountEmails(') < strpos($aaGrantBody, 'adminAuditWrite(') && strpos($aaGrantBody, 'adminCliCancelUnsentAccountEmails(') < strpos($aaGrantBody, '$db->commit()'), 'B9: ...inside the same transaction, before the audit row and the commit');
assertTrue(str_contains($aaGrantBody, "'verification_emails_cancelled'") && str_contains($aaGrantBody, "'password_reset_emails_cancelled'"), 'B9: the audit row gets counts only');
assertTrue(str_contains(implode("\n", adminCliUsage()), 'cancelled'), 'B9: --help says waiting emails are cancelled');

// ==============================================================================
// 🧾 Independent review (Codex C2 findings 1 and 2, 2026-11-25) - the order of
//    work (the behaviour is proven on a real database, with the change log
//    forced to refuse rows, in tests/integration/admin_access_real_mysql.php L).
// ==============================================================================
$aaReauthPage = (string) file_get_contents(dirname(__DIR__) . '/web/CueRCode.net/public_html/admin/reauth/index.php');
assertContains('if (adminReauthRecordSuccess($adminUser)) {', $aaReauthPage, 'C2-1: the re-confirm page unlocks only through adminReauthRecordSuccess()');
assertFalse((bool) preg_match('/^(?!\s*(\/\/|\*|#)).*adminReauthStore\(/m', $aaReauthPage), 'C2-1: no code line of the page unlocks directly');
$aaRecordStart = strpos($aaAdminSource, 'function adminReauthRecordSuccess(');
$aaRecordBody  = substr($aaAdminSource, $aaRecordStart, strpos($aaAdminSource, "\nfunction ", $aaRecordStart + 10) - $aaRecordStart);
assertTrue(strpos($aaRecordBody, "'reauth.success'") < strpos($aaRecordBody, 'adminReauthStore($admin)') && str_contains($aaRecordBody, 'if (!$recorded) {'), 'C2-1: the change-log row is written first, and nothing is unlocked if it fails');
$aaRunStart = strpos($aaAdminSource, 'function adminRunUpgrades(');
$aaRunBody  = substr($aaAdminSource, $aaRunStart, strpos($aaAdminSource, "\nfunction ", $aaRunStart + 10) - $aaRunStart);
assertTrue(strpos($aaRunBody, "'schema.migrate.started'") < strpos($aaRunBody, 'migrationApplyPending('), 'C2-2: the "started" row is written before any upgrade runs');
assertTrue(strpos($aaRunBody, 'if (!$startedWritten) {') < strpos($aaRunBody, 'migrationApplyPending('), 'C2-2: ...and nothing runs if it could not be written');

// ==============================================================================
// 🧾 Independent review (Codex round 2 finding R2-1, 2026-11-25): BEFORE the
//    admin change-log table exists, the stand-in activity-log row must be a
//    CHECKED write, and "could not tell whether the table exists" must refuse,
//    not fall back. Each case runs in its own child process with a stand-in
//    dbQuery() (never defined in this process - other test files check it is
//    absent). The real-database version is in
//    tests/integration/admin_access_real_mysql.php, scenario B.
// ==============================================================================
/**
 * 🧪 Run adminAuditOrFallback() and adminReauthRecordSuccess() in a child
 *    process with a stand-in database. $aaMode picks the stand-in:
 *      none           - no dbQuery() at all (database not loaded);
 *      check-fails    - the "does the table exist?" question throws a plain
 *                       RuntimeException (no error number);
 *      missing-refuse - table confirmed missing; the activity-log insert throws;
 *      missing-zero   - table confirmed missing; the insert reports 0 rows;
 *      missing-works  - table confirmed missing; the insert writes 1 row;
 *      no-privilege   - the account may not read the table (error 1142);
 *      connection-lost - the connection drops (error 2006);
 *      table-exists   - the probe works (the table is there).
 *
 * @return array<string, mixed> {fallback, unlocked, inserts, schemaQueries} or {crashed}
 */
$aaAuditChild = static function (string $aaMode): array {
    $aaProgram = tempnam(sys_get_temp_dir(), 'cuercode_aa_r21_');
    $aaLog     = tempnam(sys_get_temp_dir(), 'cuercode_aa_r21_');
    if ($aaProgram === false || $aaLog === false) {
        return ['crashed' => 'tempnam failed'];
    }
    $aaBody = '<?php' . PHP_EOL
        . 'require ' . var_export(CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php', true) . ';' . PHP_EOL
        . '$GLOBALS["aaMode"] = ' . var_export($aaMode, true) . ';' . PHP_EOL
        . '$GLOBALS["aaInserts"] = 0;' . PHP_EOL
        . '$GLOBALS["aaSchemaQueries"] = 0;' . PHP_EOL
        . <<<'PHP'
if ($GLOBALS['aaMode'] !== 'none') {
    function dbQuery(string $sql, string $types = '', array $params = [])
    {
        // 🔎 Codex round 3 finding 3: the table check is now a cheap SELECT on
        //    the table itself, and the error NUMBER decides the answer.
        if (str_contains($sql, 'SELECT 1 FROM `tblAdminAuditLog`')) {
            $GLOBALS['aaSchemaQueries'] = $GLOBALS['aaSchemaQueries'] + 1;
            if ($GLOBALS['aaMode'] === 'check-fails') {
                throw new \RuntimeException('stand-in: the table check failed');
            }
            if ($GLOBALS['aaMode'] === 'no-privilege') {
                throw new \mysqli_sql_exception('stand-in: SELECT command denied to user', 1142);
            }
            if ($GLOBALS['aaMode'] === 'connection-lost') {
                throw new \mysqli_sql_exception('stand-in: MySQL server has gone away', 2006);
            }
            if ($GLOBALS['aaMode'] === 'table-exists') {
                return new class {
                    public function close(): bool
                    {
                        return true;
                    }
                };
            }
            // missing-refuse / missing-zero / missing-works: 1146 = no such table
            throw new \mysqli_sql_exception('stand-in: Table does not exist', 1146);
        }
        if (str_contains($sql, 'INSERT INTO `tblActivityLog`')) {
            $GLOBALS['aaInserts'] = $GLOBALS['aaInserts'] + 1;
            if ($GLOBALS['aaMode'] === 'missing-refuse') {
                throw new \RuntimeException('stand-in: the activity log refused the row');
            }
            $rows = 1;
            if ($GLOBALS['aaMode'] === 'missing-zero') {
                $rows = 0;
            }
            return new class ($rows) {
                public int $affected_rows;
                public function __construct(int $rows)
                {
                    $this->affected_rows = $rows;
                }
                public function close(): bool
                {
                    return true;
                }
            };
        }
        throw new \RuntimeException('stand-in: unexpected query');
    }
}
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.save_path', sys_get_temp_dir());
session_start();
requireFn('fn_activity_log.php');
requireFn('fn_admin.php');
$admin = ['userID' => 7, 'email' => 'it@admin.cuercode.invalid', 'displayName' => 'IT', 'sessionHash' => hash('sha256', 'x'), 'hasPassword' => true];
$fallback = adminAuditOrFallback(adminAuditEntryForAdmin($admin, 'reauth.success', 'user', '7', 'success'));
$unlocked = adminReauthRecordSuccess($admin);
echo 'AA-R21:' . json_encode(['fallback' => $fallback, 'unlocked' => $unlocked, 'stored' => adminReauthStoredState() !== null, 'inserts' => $GLOBALS['aaInserts'], 'schemaQueries' => $GLOBALS['aaSchemaQueries'], 'session' => session_status() === PHP_SESSION_ACTIVE]) . PHP_EOL;
session_destroy();
PHP;
    file_put_contents($aaProgram, $aaBody);
    $aaOut  = [];
    $aaCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d error_log=' . escapeshellarg($aaLog) . ' -d log_errors=1 ' . escapeshellarg($aaProgram) . ' 2>&1', $aaOut, $aaCode);
    @unlink($aaProgram);
    @unlink($aaLog);
    foreach ($aaOut as $aaLine) {
        if (str_starts_with($aaLine, 'AA-R21:')) {
            $aaDecoded = json_decode(substr($aaLine, strlen('AA-R21:')), true);
            if (is_array($aaDecoded)) {
                return $aaDecoded;
            }
        }
    }
    return ['crashed' => substr(implode(' | ', $aaOut), 0, 400)];
};

$aaR21 = $aaAuditChild('check-fails');
assertTrue(isset($aaR21['session']) && $aaR21['session'] === true, 'R2-1 sanity: the child has a PHP session, so "not unlocked" means something ' . json_encode($aaR21));
assertTrue(isset($aaR21['fallback']) && $aaR21['fallback'] === false, 'R2-1: when the "does the change-log table exist?" check FAILS, nothing is recorded as written ' . json_encode($aaR21));
assertTrue(isset($aaR21['inserts']) && $aaR21['inserts'] === 0, 'R2-1: ...the activity log is NOT used as a stand-in (the table may well exist)');
assertTrue(isset($aaR21['unlocked'], $aaR21['stored']) && $aaR21['unlocked'] === false && $aaR21['stored'] === false, 'R2-1: ...and editing is NOT unlocked');
assertTrue(isset($aaR21['schemaQueries']) && $aaR21['schemaQueries'] >= 2, 'R2-1: a failed check is asked again next time, not remembered as "missing"');

$aaR21 = $aaAuditChild('missing-refuse');
assertTrue(isset($aaR21['fallback'], $aaR21['inserts']) && $aaR21['fallback'] === false && $aaR21['inserts'] >= 1, 'R2-1: table confirmed missing and the activity log refuses the row: reported as NOT written ' . json_encode($aaR21));
assertTrue(isset($aaR21['unlocked'], $aaR21['stored']) && $aaR21['unlocked'] === false && $aaR21['stored'] === false, 'R2-1: ...and editing is NOT unlocked');

$aaR21 = $aaAuditChild('missing-zero');
assertTrue(isset($aaR21['fallback'], $aaR21['unlocked']) && $aaR21['fallback'] === false && $aaR21['unlocked'] === false, 'R2-1: an insert that writes no row counts as NOT written ' . json_encode($aaR21));

// 🔒 Codex round 3 finding 3: only error 1146 means "confirmed missing". A
//    refused permission (1142), a dropped connection (2006) or a table that
//    exists must NEVER reach the activity-log stand-in.
foreach (['no-privilege' => 'the account has no privilege on the table (1142)', 'connection-lost' => 'the connection is lost (2006)', 'table-exists' => 'the table exists'] as $aaR3Mode => $aaR3Why) {
    $aaR21 = $aaAuditChild($aaR3Mode);
    assertTrue(isset($aaR21['inserts']) && $aaR21['inserts'] === 0, 'round 3 (3): ' . $aaR3Why . ' - the activity log is NOT used as a stand-in ' . json_encode($aaR21));
    assertTrue(isset($aaR21['fallback'], $aaR21['unlocked']) && $aaR21['fallback'] === false && $aaR21['unlocked'] === false, 'round 3 (3): ' . $aaR3Why . ' - nothing is recorded as written and editing is NOT unlocked');
}
$aaR21 = $aaAuditChild('no-privilege');
assertTrue(isset($aaR21['schemaQueries']) && $aaR21['schemaQueries'] >= 2, 'round 3 (3): "could not tell" is asked again next time, not remembered as "missing"');

$aaR21 = $aaAuditChild('none');
assertTrue(isset($aaR21['fallback'], $aaR21['unlocked']) && $aaR21['fallback'] === false && $aaR21['unlocked'] === false, 'R2-1: with no database at all, nothing is recorded as written and nothing unlocks ' . json_encode($aaR21));

$aaR21 = $aaAuditChild('missing-works');
assertTrue(isset($aaR21['fallback'], $aaR21['inserts']) && $aaR21['fallback'] === true && $aaR21['inserts'] >= 1, 'R2-1: first install (table confirmed missing, activity log works): the stand-in row is written and reported as written ' . json_encode($aaR21));
assertTrue(isset($aaR21['unlocked'], $aaR21['stored']) && $aaR21['unlocked'] === true && $aaR21['stored'] === true, 'R2-1: ...and the password confirmation unlocks editing as before');

// 🔒 R2-1, by source: the fallback asks the three-answer table check and uses the CHECKED activity write.
$aaR21Source = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php');
$aaR21Start  = strpos($aaR21Source, 'function adminAuditOrFallback(');
$aaR21Body   = substr($aaR21Source, (int) $aaR21Start, (int) strpos($aaR21Source, "\nfunction ", (int) $aaR21Start + 10) - (int) $aaR21Start);
assertContains("adminTableExistsState('tblAdminAuditLog')", $aaR21Body, 'R2-1: the fallback tells "confirmed missing" from "could not tell"');
$aaR3Start = strpos($aaR21Source, 'function adminTableExistsState(');
$aaR3Body  = substr($aaR21Source, (int) $aaR3Start, (int) strpos($aaR21Source, "\nfunction ", (int) $aaR3Start + 10) - (int) $aaR3Start);
assertFalse(str_contains($aaR3Body, 'INFORMATION_SCHEMA\'') || str_contains($aaR3Body, 'INFORMATION_SCHEMA`.'), 'round 3 (3): the table check no longer asks INFORMATION_SCHEMA (it only lists tables the account can see)');
assertContains("'SELECT 1 FROM `' . \$table . '` LIMIT 1'", $aaR3Body, 'round 3 (3): the table check is a cheap SELECT on the table itself');
assertContains('=== 1146', $aaR3Body, 'round 3 (3): only error 1146 (no such table) means "confirmed missing"');
assertContains('\\mysqli_sql_exception', $aaR3Body, 'round 3 (3): the error number is read from mysqli_sql_exception::getCode()');
assertContains('return logActivityChecked(', $aaR21Body, 'R2-1: the stand-in row is a checked write, and its answer is returned');
assertFalse((bool) preg_match('/^\s*logActivity\(/m', $aaR21Body), 'R2-1: the unchecked logActivity() is no longer used by the fallback');
$aaR21Log = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_activity_log.php');
assertContains('$written = ($stmt->affected_rows === 1);', $aaR21Log, 'R2-1: logActivityChecked() is true only when exactly one row was written');
