<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for database upgrade 0007 and its helpers (WP4a, #164)
 * ==============================================================================
 *
 * Path: tests/test_dynamic_qr_schema.php
 *
 * WITHOUT a database (the real-database runs are in
 * tests/integration/migrations_real_mysql.php, scenario Q, and
 * tests/integration/admin_settings_real_mysql.php, section Q):
 *
 *   1. Upgrade 0007 is valid, adds exactly the planned columns, tables and
 *      settings, with the owner's starting values (decisions 0029-0031,
 *      0034, 0035), never switches dynamic QR on, never writes to the tables
 *      only seed_data.sql fills, and back-quotes every name.
 *   2. The reporter-address hash: an IPv6 address counts as its /64, an IPv4
 *      address written as IPv6 counts as itself, and the hash is keyed.
 *   3. A person's own limit (decision 0031): replaces the plan's number;
 *      what an admin may type.
 *
 * House rules: every variable is prefixed $dq; nothing here defines dbQuery(),
 * getConfig(), logError() or logActivity().
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#164)
 * @see         web/CueRCode.net/_includes/sql/migrations/0007_dynamic_qr_go2mylink.php
 */

requireFn('fn_migrations.php');
requireFn('fn_abuse_reports.php');
requireFn('fn_features.php');
requireFn('fn_admin_limits.php');
requireFn('fn_no_script_route.php');

// ==============================================================================
// ✅ Section 1: upgrade 0007
// ==============================================================================
$dqPath = migrationDefaultDirectory() . '0007_dynamic_qr_go2mylink.php';
assertTrue(is_file($dqPath), 'upgrade 0007 exists (0005 is the human check, 0006 three-decimal money)');
$dqDefinition = require $dqPath;
assertEquals([], migrationValidateDefinition($dqDefinition, '0007_dynamic_qr_go2mylink'), 'upgrade 0007 passes the upgrade runner\'s own checks');

$dqSteps = [];
foreach ($dqDefinition['steps'] as $dqStep) {
    $dqName = '';
    if (isset($dqStep['name'])) {
        $dqName = $dqStep['name'];
    } elseif (isset($dqStep['label'])) {
        $dqName = $dqStep['label'];
    }
    $dqSteps[] = $dqStep['type'] . ' ' . $dqStep['table'] . ' ' . $dqName;
}
assertEquals([
    'add_column tblQRRedirects shortCode',
    'add_index tblQRRedirects uq_redirect_short_code',
    'add_column tblQRRedirects syncState',
    'add_column tblQRRedirects lastSyncError',
    'add_index tblQRRedirects idx_redirect_sync',
    'add_column tblQRRedirects suspendedAt',
    'add_column tblQRRedirects suspendedByUserID',
    'add_column tblQRRedirects suspensionNote',
    'add_foreign_key tblQRRedirects fk_redirect_suspended_by',
    'create_table tblAbuseReports ',
    'create_table tblUserLimitOverrides ',
    'insert_if_missing tblSettings setting go2mylink_api_base_url',
    'insert_if_missing tblSettings setting go2mylink_api_key',
    'insert_if_missing tblSettings setting go2mylink_timeout_seconds',
    'insert_if_missing tblSettings setting link_safety_providers',
    'insert_if_missing tblSettings setting link_safety_webrisk_api_key',
    'insert_if_missing tblSettings setting abuse_auto_suspend_reports',
    'insert_if_missing tblSettings setting abuse_auto_suspend_window_hours',
    'insert_if_missing tblSettings setting abuse_takedown_promise',
    'insert_if_missing tblSettings setting abuse_mailbox',
], $dqSteps, 'upgrade 0007: exactly the planned steps, in order (plan WP4a)');

// 🔢 The starting settings: key => [group, value, type, sensitive].
$dqSettings = [];
foreach ($dqDefinition['steps'] as $dqStep) {
    if ($dqStep['type'] !== 'insert_if_missing') {
        continue;
    }
    if (preg_match("/VALUES \\('([a-z_]+)', '([a-z0-9_]+)', '((?:[^']|'')*)', '([a-z]+)', ([01]), ([01]),/", $dqStep['sql'], $dqMatch) === 1) {
        $dqSettings[$dqMatch[2]] = [$dqMatch[1], str_replace("''", "'", $dqMatch[3]), $dqMatch[4], (int) $dqMatch[5], (int) $dqMatch[6]];
    }
}
assertEquals([
    'go2mylink_api_base_url'          => ['redirects', 'https://go2my.link/api/v1', 'url', 0, 1],
    'go2mylink_api_key'               => ['redirects', '', 'string', 1, 1],
    'go2mylink_timeout_seconds'       => ['redirects', '5', 'integer', 0, 1],
    'link_safety_providers'           => ['link_safety', 'webrisk', 'string', 0, 1],
    'link_safety_webrisk_api_key'     => ['link_safety', '', 'string', 1, 1],
    'abuse_auto_suspend_reports'      => ['abuse', '3', 'integer', 0, 1],
    'abuse_auto_suspend_window_hours' => ['abuse', '24', 'integer', 0, 1],
    'abuse_takedown_promise'          => ['abuse', 'We act on reports within one working day.', 'string', 0, 1],
    'abuse_mailbox'                   => ['abuse', 'abuse@cuercode.net', 'email', 0, 1],
], $dqSettings, 'upgrade 0007: the starting settings - production Go2My.Link address (0030), both keys encrypted and empty, link safety as a list with Web Risk first (0029, 0035), 3 reports in 24 hours (O-4), the one-working-day promise and abuse@cuercode.net (0034); all editable');

$dqAllSql = '';
foreach ($dqDefinition['steps'] as $dqStep) {
    $dqAllSql .= "\n" . $dqStep['sql'];
}
assertFalse(str_contains($dqAllSql, 'dynamic_qr_enabled'), 'upgrade 0007 never touches dynamic_qr_enabled (dynamic QR stays switched off)');
foreach (migrationSeedProtectionTables() as $dqSeedTable) {
    assertFalse(str_contains($dqAllSql, '`' . $dqSeedTable . '`'), 'upgrade 0007 never writes to ' . $dqSeedTable . ' (only seed_data.sql fills it)');
}
assertContains('`reporterEmailEncrypted` TEXT NULL', $dqAllSql, 'tblAbuseReports keeps the reporter email only encrypted (TEXT, for encryptValue()\'s output)');
assertContains('`reporterAddressHash` CHAR(64) NOT NULL', $dqAllSql, 'tblAbuseReports keeps only a 64-character hash of the reporter address');
assertFalse((bool) preg_match('/`(reporterIP|requestIP|reporterEmail)`/', $dqAllSql), 'tblAbuseReports has no column for a plain address or a plain email');
assertContains('PRIMARY KEY (`userID`, `limitKey`)', $dqAllSql, 'tblUserLimitOverrides: one number per person per limit');
assertContains("ENUM('pending','active','failed') NOT NULL DEFAULT 'pending'", $dqAllSql, 'syncState: pending, active or failed');

// 🔤 Every name back-quoted inside the two new tables (the runner checks the
//    table and step names itself; this checks every column and key part).
foreach ($dqDefinition['steps'] as $dqStep) {
    if ($dqStep['type'] !== 'create_table') {
        continue;
    }
    $dqBlank = migrationBlankQuotedText($dqStep['sql']);
    $dqInner = substr($dqBlank['text'], strpos($dqBlank['text'], '(') + 1, strrpos($dqBlank['text'], ')') - strpos($dqBlank['text'], '(') - 1);
    $dqDepth = 0;
    $dqPart  = '';
    $dqParts = [];
    foreach (str_split($dqInner) as $dqChar) {
        if ($dqChar === '(') {
            $dqDepth++;
        } elseif ($dqChar === ')') {
            $dqDepth--;
        }
        if ($dqChar === ',' && $dqDepth === 0) {
            $dqParts[] = trim($dqPart);
            $dqPart = '';
            continue;
        }
        $dqPart .= $dqChar;
    }
    $dqParts[] = trim($dqPart);
    foreach ($dqParts as $dqPiece) {
        $dqStarts = (str_starts_with($dqPiece, '`') || preg_match('/^(PRIMARY KEY|INDEX `|UNIQUE KEY `|CONSTRAINT `)/', $dqPiece) === 1);
        assertTrue($dqStarts, $dqStep['table'] . ': every column and key starts with a back-quoted name: ' . substr($dqPiece, 0, 40));
        if (preg_match_all('/\(([^()]*)\)/', $dqPiece, $dqLists) > 0 && !str_starts_with($dqPiece, '`')) {
            foreach ($dqLists[1] as $dqList) {
                foreach (explode(',', $dqList) as $dqListName) {
                    assertTrue(preg_match('/^\s*`[A-Za-z0-9_]+`\s*$/', $dqListName) === 1, $dqStep['table'] . ': key part back-quoted: ' . trim($dqListName));
                }
            }
        }
    }
}

// ==============================================================================
// ✅ Section 2: the reporter-address hash (/64 rule)
// ==============================================================================
$dqKey = 'test-key-for-abuse-hash';
$dqA   = abuseReporterAddressHash('2001:db8:1:2:aaaa::1', $dqKey);
assertTrue(preg_match('/^[0-9a-f]{64}$/', $dqA) === 1, 'the hash is 64 hex characters');
assertEquals($dqA, abuseReporterAddressHash('2001:db8:1:2:ffff:ffff:ffff:ffff', $dqKey), 'two addresses in the same IPv6 /64 count as ONE person');
assertFalse($dqA === abuseReporterAddressHash('2001:db8:1:3::1', $dqKey), 'the next /64 counts as a different person');
assertEquals(abuseReporterAddressHash('198.51.100.7', $dqKey), abuseReporterAddressHash('::ffff:198.51.100.7', $dqKey), 'an IPv4 address written as IPv6 counts as that IPv4 address');
assertFalse(abuseReporterAddressHash('198.51.100.7', $dqKey) === abuseReporterAddressHash('198.51.100.8', $dqKey), 'two IPv4 addresses are two people');
assertFalse(abuseReporterAddressHash('198.51.100.7', $dqKey) === hash('sha256', '198.51.100.7'), 'the hash is keyed (not a plain SHA-256 anyone could recompute)');
assertFalse(abuseReporterAddressHash('198.51.100.7', $dqKey) === abuseReporterAddressHash('198.51.100.7', 'another-key'), 'another key gives another hash');
assertEquals(abuseReporterAddressHash('198.51.100.7'), abuseReporterAddressHash('198.51.100.7', abuseReporterHashKey()), 'with no key given, the site\'s own key (from ENCRYPTION_KEY) is used');
assertFalse(abuseReporterHashKey() === noScriptHashKey(),'the reporter key is not the no-JavaScript route\'s key (each hash has its own)');

// ==============================================================================
// ✅ Section 3: a person's own limit (decision 0031)
// ==============================================================================
assertEquals(3, resolveLimitWithOverride(3, null), 'no own number: the plan\'s 3 stands');
assertEquals(10, resolveLimitWithOverride(3, 10), 'a Global Admin approved 10 for this person: 10');
assertEquals(-1, resolveLimitWithOverride(3, -1), '-1: no limit for this person');
assertEquals(1, resolveLimitWithOverride(3, 1), 'the own number replaces the plan\'s, whichever is larger (the admin\'s explicit choice)');
assertEquals(0, limitFor('dynamic_codes', null), 'without a database an anonymous visitor still has the fail-safe 0');

$dqGood = adminUserLimitOverrideCheck('dynamic_codes', '10', 'Approved for the pilot shop');
assertTrue($dqGood['ok'] && $dqGood['value'] === 10 && $dqGood['reason'] === 'Approved for the pilot shop', 'own limit: 10 with a reason is accepted');
assertTrue(adminUserLimitOverrideCheck('dynamic_codes', '-1', 'Unlimited for testing')['value'] === -1, 'own limit: -1 (no limit) is accepted');
$dqClear = adminUserLimitOverrideCheck('dynamic_codes', '  ', 'Back to the plan');
assertTrue($dqClear['ok'] && $dqClear['value'] === null, 'own limit: empty means "use the plan\'s number again"');
assertTrue(adminUserLimitOverrideCheck('dynamic_codes', '1000000', 'x')['ok'], 'own limit: 1,000,000 is the most');
foreach (['1000001', '-2', '1.5', '010', '1e3', 'ten', '+5'] as $dqBadNumber) {
    assertFalse(adminUserLimitOverrideCheck('dynamic_codes', $dqBadNumber, 'reason')['ok'], 'own limit refuses ' . var_export($dqBadNumber, true));
}
foreach (['', '   ', str_repeat('a', 501), "two\nlines"] as $dqBadReason) {
    assertFalse(adminUserLimitOverrideCheck('dynamic_codes', '10', $dqBadReason)['ok'], 'own limit needs a one-line reason of up to 500 characters: ' . var_export(substr($dqBadReason, 0, 12), true));
}
foreach (['Dynamic_codes', 'dynamic-codes', '', 'x; DROP', str_repeat('a', 65)] as $dqBadKey) {
    assertFalse(adminUserLimitOverrideCheck($dqBadKey, '10', 'reason')['ok'], 'own limit refuses the limit name ' . var_export($dqBadKey, true));
}
