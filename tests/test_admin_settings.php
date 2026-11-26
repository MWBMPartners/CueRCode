<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the ADMIN SETTINGS EDITOR (task 7n Phase 1C)
 * ==============================================================================
 *
 * Path: tests/test_admin_settings.php
 *
 * Covers _functions/fn_admin_settings.php, public_html/admin/settings/index.php,
 * pricingVatNote() and the small changes elsewhere, WITHOUT a database:
 *
 *   1. Loading: constants, and no site start-up helpers defined.
 *   2. Every validator at its bounds (whole numbers 0/1/3650/3651, decimals,
 *      GUID-or-domain tenant, GUID client ID, https-only addresses, scopes
 *      that must include openid, emails, time zones, choice lists, plan keys,
 *      trusted-forwarder ranges) and secrets (control characters, line
 *      breaks, length 2048 / 2049, "keep" / "set" / "clear" intent).
 *   3. The registry: every key exists in seed_data.sql or migration 0002
 *      with the SAME type and sensitivity; no locked key is in it; every
 *      seeded secret is either in it or deliberately left out.
 *   4. Locked keys: the three charging switches, dynamic_qr_enabled, every
 *      "*_cache" key and the fixed rows are refused by name.
 *   5. adminSettingsProcessSection(): a hand-made request cannot reach a
 *      locked key or another section's key; missing boxes are left alone;
 *      the email switch needs its tick; idle/max rule; secrets are never
 *      repeated in an error.
 *   6. pricingVatNote() for every mode and unknown values; the pricing page
 *      shows it.
 *   7. The rendered settings HTML for a stored secret contains neither the
 *      plaintext nor the ciphertext (rendered in a CHILD PHP process).
 *   8. The test-email limit (5 in 10 minutes) at its edges.
 *   9. STATIC GUARDS: the page's gate comes first; no inline <script>; no
 *      /admin API route; roleID is not touched; every secret-carrying
 *      parameter has #[\SensitiveParameter]; SQL identifiers are backticked.
 *
 * ⚠️ NOT covered here (needs a real database): saving, the audit rows, the
 *    cache clearing and the failure paths. Those are in
 *    tests/integration/admin_settings_real_mysql.php.
 *
 * ⚠️ House rules for this file (it runs in the SAME global scope as
 *    tests/run.php and every other test file): every variable is prefixed $as,
 *    and it never defines dbQuery(), getDB(), getConfig(), logError() or
 *    logActivity() - other test files assert those do NOT exist.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (task 7n Phase 1C, #31)
 * @see         web/CueRCode.net/_functions/fn_admin_settings.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §7.2, §9 Phase 1C
 */

requireFn('fn_admin_settings.php');
requireFn('fn_email.php');
requireFn('fn_client_ip.php');
requireFn('fn_pricing.php');

$asSiteRoot = CUERCODE_SITE_ROOT;

// ==============================================================================
// ✅ Section 1: loading
// ==============================================================================
assertEquals(2048, CUERCODE_ADMIN_SECRET_MAX_LENGTH, 'a secret may be at most 2048 characters (plan §7.2)');
assertEquals(5, CUERCODE_ADMIN_TEST_EMAIL_MAX, 'at most 5 test emails (plan §7.1)');
assertEquals(600, CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS, '... in 10 minutes');

foreach (['dbQuery', 'getDB', 'getConfig', 'logError', 'logActivity'] as $asForbidden) {
    assertFalse(function_exists($asForbidden), 'loading fn_admin_settings.php does not define ' . $asForbidden . '()');
}

// ==============================================================================
// ✅ Section 2: validators
// ==============================================================================
$asRegistry = adminSettingsRegistry();
$asValidate = static function (string $asKey, string $asRaw, array $asContext = []) use ($asRegistry): array {
    return adminSettingsValidateValue($asRegistry[$asKey], $asRaw, $asContext);
};

// 🔢 Retention periods: 1 to 3650 only.
foreach (['email_queue_retention_days', 'email_queue_failed_retention_days'] as $asKey) {
    foreach (['0', '3651', '-1', '1.5', '1e3', 'thirty', '', ' ', '+5', '00000007x'] as $asBad) {
        assertFalse($asValidate($asKey, $asBad)['ok'], $asKey . ' refuses ' . var_export($asBad, true));
    }
    assertEquals('1', $asValidate($asKey, '1')['value'], $asKey . ' accepts 1');
    assertEquals('3650', $asValidate($asKey, '3650')['value'], $asKey . ' accepts 3650');
    assertEquals('30', $asValidate($asKey, ' 30 ')['value'], $asKey . ' trims and accepts 30');
    // 🔢 Strict, canonical digits only: a leading zero is refused, whatever follows it.
    foreach (['007', '01', '000030', '030', '00'] as $asLeadingZero) {
        $asZeroResult = $asValidate($asKey, $asLeadingZero);
        assertFalse($asZeroResult['ok'], $asKey . ' refuses ' . var_export($asLeadingZero, true) . ' (a leading zero)');
        assertContains('no zero at the start', $asZeroResult['error'], $asKey . ' says why ' . var_export($asLeadingZero, true) . ' is refused');
    }
}

// 🔢 Other whole numbers at their bounds.
$asIntBounds = [
    'billing_refund_window_days' => [0, 365],
    'billing_trial_days'         => [1, 90],
    'admin_reauth_idle_minutes' => [5, 60],
    'admin_reauth_max_minutes'  => [15, 480],
];
foreach ($asIntBounds as $asKey => $asRange) {
    assertTrue($asValidate($asKey, (string) $asRange[0])['ok'], $asKey . ' accepts its lowest (' . $asRange[0] . ')');
    assertTrue($asValidate($asKey, (string) $asRange[1])['ok'], $asKey . ' accepts its highest (' . $asRange[1] . ')');
    assertFalse($asValidate($asKey, (string) ($asRange[0] - 1))['ok'], $asKey . ' refuses one below (' . ($asRange[0] - 1) . ')');
    assertFalse($asValidate($asKey, (string) ($asRange[1] + 1))['ok'], $asKey . ' refuses one above (' . ($asRange[1] + 1) . ')');
}

// 💷 Decimals: 0 to 100, two places.
assertEquals('20.00', $asValidate('billing_vat_rate_percent', '20')['value'], 'VAT rate 20 is stored as 20.00');
assertEquals('17.50', $asValidate('billing_vat_rate_percent', '17.5')['value'], 'VAT rate 17.5 is stored as 17.50');
assertEquals('0.00', $asValidate('billing_vat_rate_percent', '0')['value'], 'VAT rate 0 is accepted');
assertEquals('100.00', $asValidate('billing_vat_rate_percent', '100')['value'], 'VAT rate 100 is accepted');
foreach (['100.01', '101', '-1', '20.123', 'abc', '1e1', '', '.5'] as $asBad) {
    assertFalse($asValidate('billing_vat_rate_percent', $asBad)['ok'], 'VAT rate refuses ' . var_export($asBad, true));
}

// 🔀 Choices.
foreach (['inclusive', 'exclusive', 'none'] as $asMode) {
    assertTrue($asValidate('billing_vat_mode', $asMode)['ok'], 'VAT mode ' . $asMode . ' is accepted');
}
foreach (['', 'Inclusive', 'include', 'inclusive ', "inclusive\n"] as $asBad) {
    $asResult = $asValidate('billing_vat_mode', $asBad);
    // (a trailing space or line break is trimmed, so those two become valid; the rest must fail)
    if (trim($asBad) === 'inclusive') {
        assertTrue($asResult['ok'], 'VAT mode ' . var_export($asBad, true) . ' is trimmed to a valid mode');
    } else {
        assertFalse($asResult['ok'], 'VAT mode refuses ' . var_export($asBad, true));
    }
}
assertTrue($asValidate('paypal_mode', 'live')['ok'] && $asValidate('paypal_mode', 'sandbox')['ok'], 'PayPal mode: live and sandbox');
assertFalse($asValidate('paypal_mode', 'test')['ok'], 'PayPal mode refuses anything else');
foreach (['none', 'turnstile', 'recaptcha', 'hcaptcha'] as $asProvider) {
    assertTrue($asValidate('bot_protection_provider', $asProvider)['ok'], 'bot protection provider ' . $asProvider);
}
assertFalse($asValidate('bot_protection_provider', 'friendlycaptcha')['ok'], 'bot protection provider refuses an unknown name');

// 🔘 Switches.
assertEquals('1', $asValidate('email_enabled', '1')['value'], 'a switch: 1 is on');
assertEquals('0', $asValidate('email_enabled', '0')['value'], 'a switch: 0 is off');
assertEquals('0', $asValidate('email_enabled', '')['value'], 'a switch: empty is off');
assertFalse($asValidate('email_enabled', 'yes')['ok'], 'a switch refuses "yes"');
assertFalse($asValidate('email_enabled', '2')['ok'], 'a switch refuses 2');

// 📧 Emails.
assertTrue($asValidate('graph_sender_mailbox', 'notifications@example.com')['ok'], 'a sender mailbox: a normal address');
assertTrue($asValidate('graph_sender_mailbox', '')['ok'], 'a sender mailbox may be left empty (not set)');
foreach (['notifications', 'a@b', 'a b@example.com', '@example.com', 'a@@example.com', 'x@example.com, y@example.com'] as $asBad) {
    assertFalse($asValidate('graph_sender_mailbox', $asBad)['ok'], 'a sender mailbox refuses ' . var_export($asBad, true));
}
assertTrue($asValidate('email_from_name', str_repeat('a', 100))['ok'], 'a sender name of 100 characters is accepted');
assertFalse($asValidate('email_from_name', str_repeat('a', 101))['ok'], 'a sender name of 101 characters is refused');
assertFalse($asValidate('email_from_name', "Line\nbreak")['ok'], 'a sender name refuses a line break');

// 🌐 https-only addresses.
assertTrue($asValidate('sso_microsoft_discovery_url', 'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration')['ok'], 'a discovery address: https is accepted');
foreach (['http://example.com/x', 'ftp://example.com/x', 'example.com/x', 'https://', 'https://user:pass@example.com/x', 'https://exa mple.com/x', 'javascript:alert(1)', '//example.com', ''] as $asBad) {
    assertFalse($asValidate('sso_microsoft_discovery_url', $asBad)['ok'], 'a discovery address refuses ' . var_export($asBad, true));
}

// 🏠 The website's own address (site_url, #113 B9): the discovery rules plus "nothing after the name".
assertEquals('https://alpha.example.com', $asValidate('site_url', 'https://alpha.example.com')['value'], 'site_url: a plain address is kept as it is');
assertEquals('https://alpha.example.com', $asValidate('site_url', 'https://alpha.example.com/')['value'], 'site_url: one trailing slash is removed');
assertEquals('https://alpha.example.com', $asValidate('site_url', '  HTTPS://Alpha.Example.COM:443/ ')['value'], 'site_url: spaces trimmed, lower-cased, :443 and trailing slash removed');
foreach ([
    'http://alpha.example.com', 'alpha.example.com', 'https://', 'https://alpha.example.com/path', 'https://alpha.example.com/path/',
    'https://alpha.example.com//', 'https://alpha.example.com?x=1', 'https://alpha.example.com/?x=1', 'https://alpha.example.com#top',
    'https://alpha.example.com:8443', 'https://user:pass@alpha.example.com', 'https://127.0.0.1', 'https://[::1]', 'https://localhost',
    'https://intranet', 'https://printer.local', 'https://box.internal', 'https://10.0.0.1.nip.io', 'https://exa mple.com',
    'https://alpha.example.com\\evil', 'javascript:alert(1)', '',
] as $asBad) {
    assertFalse($asValidate('site_url', $asBad)['ok'], 'site_url refuses ' . var_export($asBad, true));
}
assertTrue(isset($asRegistry['site_url']) && $asRegistry['site_url']['section'] === 'website', 'site_url is on the settings screen (website address section)');
assertFalse($asRegistry['site_url']['allowEmpty'], 'site_url cannot be saved empty');
assertTrue(adminSettingsFieldIsRequired($asRegistry['site_url']), 'site_url box is marked required');
$asSiteHtml = adminSettingsRenderField($asRegistry['site_url'], ['state' => ['exists' => true, 'hasValue' => true, 'value' => 'https://cuercode.net', 'updatedAt' => null], 'error' => '', 'timezone' => 'Europe/London', 'planChoices' => []]);
assertTrue(str_contains($asSiteHtml, 'type="url"'), 'site_url box is a web-address input');

// 🧾 Scopes.
assertEquals('openid email profile', $asValidate('sso_google_scopes', 'openid  email profile')['value'], 'scopes: extra spaces are tidied');
assertEquals('openid email', $asValidate('sso_google_scopes', 'openid email email')['value'], 'scopes: duplicates are removed');
assertTrue($asValidate('sso_google_scopes', 'openid https://www.googleapis.com/auth/userinfo.email a_b-c:d')['ok'], 'scopes: letters, numbers and . : / _ - are fine');
foreach (['email profile', 'openid Email', 'openid email;profile', 'openid "x"', 'openid <x>', ''] as $asBad) {
    assertFalse($asValidate('sso_google_scopes', $asBad)['ok'], 'scopes refuse ' . var_export($asBad, true));
}

// 🕰️ Time zones.
assertTrue($asValidate('admin_display_timezone', 'Europe/London')['ok'], 'time zone Europe/London');
assertTrue($asValidate('admin_display_timezone', 'UTC')['ok'], 'time zone UTC');
foreach (['BST', 'EST', 'Europe/Londn', 'GMT+1', '', '../etc/passwd'] as $asBad) {
    assertFalse($asValidate('admin_display_timezone', $asBad)['ok'], 'time zone refuses ' . var_export($asBad, true));
}

// 📋 Trial plan: only the listed plans.
assertTrue($asValidate('billing_trial_plan_key', 'pro', ['planKeys' => ['pro', 'business']])['ok'], 'trial plan: a listed plan');
assertFalse($asValidate('billing_trial_plan_key', 'free', ['planKeys' => ['pro', 'business']])['ok'], 'trial plan: an unlisted plan is refused');
assertFalse($asValidate('billing_trial_plan_key', 'pro', [])['ok'], 'trial plan: with no list, nothing is accepted');

// 🌍 Trusted forwarding servers.
assertEquals('10.0.0.0/8, 2001:db8::/32, 192.0.2.1', $asValidate('trusted_proxy_ips', "10.0.0.0/8,  2001:db8::/32\n192.0.2.1")['value'], 'forwarders: commas, spaces and new lines become ", "');
assertTrue($asValidate('trusted_proxy_ips', '')['ok'], 'forwarders: empty means none');
foreach (['10.0.0.0/33', '999.1.1.1', 'example.com', '10.0.0.0/8, nonsense', '2001:db8::/129', '0.0.0.0/0', '10.0.0.0/7', '::/0', '2001:db8::/31', '10.0.0.0/024'] as $asBad) {
    $asResult = $asValidate('trusted_proxy_ips', $asBad);
    assertFalse($asResult['ok'], 'forwarders refuse ' . var_export($asBad, true));
}
// 📏 Decision 0015: a very wide range is refused with a plain reason.
$asWide = $asValidate('trusted_proxy_ips', '10.0.0.0/8, 0.0.0.0/0');
assertContains('"0.0.0.0/0" is too wide', $asWide['error'], '0015: the editor says which entry is too wide');
assertContains('widest range accepted is /8', $asWide['error'], '0015: ...and names the /8 limit');
assertContains('widest range accepted is /32', $asValidate('trusted_proxy_ips', '2001:db8::/31')['error'], '0015: the IPv6 limit is /32');
assertContains('without the zero', $asValidate('trusted_proxy_ips', '10.0.0.0/024')['error'], '0015: "/024" is refused with a "drop the zero" message');
assertTrue($asValidate('trusted_proxy_ips', '10.0.0.0/8, 2001:db8::/32')['ok'], '0015: exactly /8 and /32 are accepted');
assertContains('more entries have problems', $asValidate('trusted_proxy_ips', 'a b c d e')['error'], '0015: more than three bad entries: the first three are explained and the rest counted');

// 🤫 Secrets: format and length.
$asGuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
$asSecretCheck = static function (string $asValue, string $asFormat): array {
    return adminSettingsCheckSecretValue($asValue, $asFormat);
};
assertTrue($asSecretCheck($asGuid, 'guid')['ok'], 'client ID: a GUID is accepted');
assertTrue($asSecretCheck(strtoupper($asGuid), 'guid')['ok'], 'client ID: capitals are accepted');
foreach (['0f8fad5b-d9cb-469f-a165-70867728950', '0f8fad5bd9cb469fa16570867728950e', 'example.com', 'not a guid', $asGuid . 'x'] as $asBad) {
    assertFalse($asSecretCheck($asBad, 'guid')['ok'], 'client ID refuses ' . var_export($asBad, true));
}
assertTrue($asSecretCheck($asGuid, 'guid_or_domain')['ok'], 'tenant: a GUID is accepted');
assertTrue($asSecretCheck('contoso.onmicrosoft.com', 'guid_or_domain')['ok'], 'tenant: a domain name is accepted');
foreach (['contoso', 'contoso..com', '-contoso.com', 'contoso.c', 'contoso.com/path', 'a b.com', str_repeat('a', 64) . '.com'] as $asBad) {
    assertFalse($asSecretCheck($asBad, 'guid_or_domain')['ok'], 'tenant refuses ' . var_export($asBad, true));
}
assertTrue($asSecretCheck(str_repeat('a', 2048), '')['ok'], 'a secret of exactly 2048 characters is accepted');
assertFalse($asSecretCheck(str_repeat('a', 2049), '')['ok'], 'a secret of 2049 characters is refused');
assertTrue($asSecretCheck(str_repeat('é', 2048), '')['ok'], 'length counts characters, not bytes (2048 accented letters)');
assertFalse($asSecretCheck(str_repeat('é', 2049), '')['ok'], '... and 2049 accented letters is refused');
assertEquals('abc', $asSecretCheck("  abc \n", '')['value'], 'a secret is trimmed (a pasted trailing line break is fine)');
foreach (["ab\ncd", "ab\rcd", "ab\tcd", "ab\0cd", "ab\x7Fcd", "ab\x1Bcd"] as $asBad) {
    assertFalse($asSecretCheck($asBad, '')['ok'], 'a secret refuses a control character inside: ' . bin2hex($asBad));
}
assertFalse($asSecretCheck("ab\xFFcd", '')['ok'], 'a secret refuses invalid text');

// 🤫 Intent: empty keeps, tick clears, text sets.
assertEquals('keep', adminSettingsSecretIntent('', false, '')['action'], 'intent: an empty box keeps the saved value');
assertEquals('keep', adminSettingsSecretIntent('   ', false, '')['action'], 'intent: a box of spaces keeps the saved value');
assertEquals('clear', adminSettingsSecretIntent('', true, '')['action'], 'intent: the Remove tick clears');
assertEquals('invalid', adminSettingsSecretIntent('abc', true, '')['action'], 'intent: Remove AND text is refused');
assertEquals('set', adminSettingsSecretIntent(' abc ', false, '')['action'], 'intent: text sets');
assertEquals('abc', adminSettingsSecretIntent(' abc ', false, '')['value'], 'intent: the set value is trimmed');
assertEquals('invalid', adminSettingsSecretIntent('nope', false, 'guid')['action'], 'intent: a wrong format is refused');

// 🙊 Error messages never repeat what was typed.
$asLeakProbe = 'SuperSecretProbe-' . bin2hex(random_bytes(6));
foreach ([$asLeakProbe . "\n" . $asLeakProbe, str_repeat($asLeakProbe, 200)] as $asProbe) {
    $asIntent = adminSettingsSecretIntent($asProbe, false, 'guid');
    assertFalse(str_contains(json_encode($asIntent), $asLeakProbe), 'a refused secret is not repeated in the intent result');
    $asIntent = adminSettingsSecretIntent($asProbe, false, '');
    assertFalse(str_contains(json_encode($asIntent), $asLeakProbe), 'a refused secret (control character / too long) is not repeated');
}

// ==============================================================================
// ✅ Section 3: the registry against the seeded rows
// ==============================================================================
$asSeedSql   = (string) file_get_contents($asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql');
// 🗂️ EVERY migration is read (not only 0002): a later migration that seeds a row
//    the registry calls "rowOptional" would make that flag wrong, and a later
//    one that adds a row must agree with the registry's group and type too.
$asMigration = '';
$asMigrationFiles = glob($asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php');
if (!is_array($asMigrationFiles)) {
    $asMigrationFiles = [];
}
sort($asMigrationFiles);
foreach ($asMigrationFiles as $asMigrationFile) {
    $asMigration .= "\n" . (string) file_get_contents($asMigrationFile);
}
assertTrue(count($asMigrationFiles) >= 3, 'the test reads every migration file (' . count($asMigrationFiles) . ' found)');

$asSeeded = [];   // key => [group, type, sensitive, editable]
if (preg_match_all("/\\('([a-z_]+)',\\s*'([A-Za-z0-9_]+)',\\s*'(?:[^']|'')*',\\s*'(string|integer|float|boolean|json|color|url|path|email)',\\s*([01]),\\s*([01]),/", $asSeedSql, $asSeedMatches, PREG_SET_ORDER)) {
    foreach ($asSeedMatches as $asMatch) {
        $asSeeded[$asMatch[2]] = [$asMatch[1], $asMatch[3], (int) $asMatch[4], (int) $asMatch[5]];
    }
}
if (preg_match_all("/VALUES \\(\\\\'([a-z_]+)\\\\', \\\\'([A-Za-z0-9_]+)\\\\', \\\\'.*?\\\\', \\\\'(string|integer|float|boolean|json|color|url|path|email)\\\\', ([01]), ([01]),/", $asMigration, $asMigrationMatches, PREG_SET_ORDER)) {
    foreach ($asMigrationMatches as $asMatch) {
        if (!isset($asSeeded[$asMatch[2]])) {
            $asSeeded[$asMatch[2]] = [$asMatch[1], $asMatch[3], (int) $asMatch[4], (int) $asMatch[5]];
        }
    }
}

assertTrue(count($asSeeded) > 80, 'the seed parser found the seeded settings (' . count($asSeeded) . ')');
assertTrue(isset($asSeeded['email_enabled'], $asSeeded['hcaptcha_secret_key'], $asSeeded['billing_vat_mode']), 'the parser found rows from both seed_data.sql and migration 0002');

$asOptionalKeys = [];
foreach ($asRegistry as $asKey => $asField) {
    if ($asField['rowOptional'] === true) {
        $asOptionalKeys[] = $asKey;
        assertFalse(isset($asSeeded[$asKey]), $asKey . ' is the documented exception: it has no seeded row (the screen creates it)');
        // 🔎 Belt and braces: the name must not appear AT ALL in the seed file or any
        //    migration (a differently written INSERT would slip past the pattern above).
        assertFalse(str_contains($asSeedSql . $asMigration, $asKey), $asKey . ' is not mentioned in seed_data.sql or any migration');
        continue;
    }
    assertTrue(isset($asSeeded[$asKey]), $asKey . ' exists in seed_data.sql or migration 0002');
    if (!isset($asSeeded[$asKey])) {
        continue;
    }
    assertEquals($asSeeded[$asKey][0], $asField['group'], $asKey . ': same group as the seeded row');
    assertEquals($asSeeded[$asKey][1], $asField['type'], $asKey . ': same type as the seeded row');
    assertEquals(adminSettingsFieldIsSecret($asField) ? 1 : 0, $asSeeded[$asKey][2], $asKey . ': same sensitivity as the seeded row');
    assertEquals(1, $asSeeded[$asKey][3], $asKey . ': the seeded row is editable');
    assertFalse(adminSettingsKeyIsLocked($asKey), $asKey . ' is not a locked key');
    assertTrue($asField['help'] !== '' && $asField['label'] !== '' && $asField['description'] !== '', $asKey . ' has a label, help text and description');
    assertTrue(isset(adminSettingsSections()[$asField['section']]), $asKey . ' belongs to a real section');
}
assertEquals(
    [
        'trusted_proxy_ips',
        'verification_resend_min_gap_seconds', 'verification_resend_daily_cap', 'verification_resend_ip_per_hour',
        'password_reset_request_min_gap_seconds', 'password_reset_request_daily_cap', 'password_reset_request_ip_per_hour',
    ],
    $asOptionalKeys,
    'only trusted_proxy_ips and the six emailed-link limits may lack a seeded row'
);
assertEquals(9, count(adminSettingsSections()), 'there are nine sections (plan §7.2, plus the website address section, #113 B9)');

// 🔐 Every seeded secret is on the screen, or deliberately not (runtime caches, SIGNula, inert).
$asKnownLeftOut = ['graph_token_cache', 'sso_signula_client_id', 'sso_signula_client_secret'];
$asSecretsLeftOut = [];
foreach ($asSeeded as $asKey => $asRow) {
    if ($asRow[2] === 1 && !isset($asRegistry[$asKey])) {
        $asSecretsLeftOut[] = $asKey;
    }
}
sort($asSecretsLeftOut);
assertEquals($asKnownLeftOut, $asSecretsLeftOut, 'the only seeded secrets not on the screen are the token cache and the inert SIGNula pair');
assertTrue(isset($asRegistry['trusted_proxy_ips']) && $asRegistry['trusted_proxy_ips']['section'] === 'admin-security', 'trusted_proxy_ips is in the registry (admin security section)');
assertEquals('guid_or_domain', $asRegistry['graph_tenant_id']['secretFormat'], 'the tenant ID is a GUID or a domain');
assertEquals('guid', $asRegistry['graph_client_id']['secretFormat'], 'the app ID is a GUID');
foreach (['graph_tenant_id', 'graph_client_id', 'graph_client_secret', 'graph_sender_mailbox'] as $asKey) {
    assertEquals('graph_token_cache', $asRegistry[$asKey]['afterSave'], $asKey . ' clears the saved Microsoft token after a change');
}
foreach (['microsoft', 'google'] as $asProvider) {
    assertEquals('sso_discovery:' . $asProvider, $asRegistry['sso_' . $asProvider . '_discovery_url']['afterSave'], $asProvider . ' discovery address clears that provider\'s caches');
}
assertTrue($asRegistry['email_enabled']['confirmText'] !== '', 'turning email on asks for a confirming tick');

// ==============================================================================
// ✅ Section 4: locked keys
// ==============================================================================
foreach (['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled', 'dynamic_qr_enabled', 'graph_token_cache', 'sso_microsoft_discovery_cache', 'sso_google_jwks_cache', 'anything_cache', 'app_version', 'app_phase', 'api_version', 'encryption_algorithm'] as $asLocked) {
    assertTrue(adminSettingsKeyIsLocked($asLocked), $asLocked . ' is locked');
}
foreach (['email_enabled', 'billing_vat_mode', 'cache', 'cache_ttl_seconds', 'sso_enabled'] as $asOpen) {
    assertFalse(adminSettingsKeyIsLocked($asOpen), $asOpen . ' is not locked');
}
assertEquals(['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled', 'dynamic_qr_enabled'], array_keys(adminSettingsChargingSwitches()), 'the page lists the four locked switches');
foreach (array_keys(adminSettingsChargingSwitches()) as $asSwitch) {
    assertFalse(isset($asRegistry[$asSwitch]), $asSwitch . ' is not in the registry (so it can never be saved)');
}

// ==============================================================================
// ✅ Section 5: adminSettingsProcessSection()
// ==============================================================================
$asState = static function (array $asOverrides = []): array {
    return array_merge(['exists' => true, 'hasValue' => false, 'value' => null, 'updatedAt' => null], $asOverrides);
};

// 🧪 A hand-made request tries to reach other keys.
$asHostile = adminSettingsProcessSection('prices', [
    'f' => [
        'billing_payg_enabled'         => '1',
        'billing_price_points_enabled' => '1',
        'billing_discounts_enabled'    => '1',
        'dynamic_qr_enabled'           => '1',
        'graph_token_cache'            => 'x',
        'email_enabled'                => '1',
        'billing_vat_mode'             => 'exclusive',
    ],
    'confirm' => ['email_enabled' => '1'],
], ['billing_vat_mode' => $asState(['value' => 'inclusive'])]);
assertEquals([], $asHostile['errors'], 'a hand-made request: no errors for the one real change');
assertEquals(1, count($asHostile['changes']), 'a hand-made request: ONLY the VAT mode is a change; locked and other-section keys are never read');
assertEquals('billing_vat_mode', $asHostile['changes'][0]['key'], 'the one change is billing_vat_mode');
foreach ($asHostile['changes'] as $asChange) {
    assertFalse(adminSettingsKeyIsLocked($asChange['key']), 'no change is for a locked key');
}
assertTrue(adminSettingsProcessSection('nonsense', ['f' => ['email_enabled' => '1']], [])['unknownSection'], 'an unknown section is refused');
assertTrue(adminSettingsProcessSection('', [], [])['unknownSection'], 'an empty section name is refused');

// ➖ Missing boxes are left alone; same values change nothing.
$asEmpty = adminSettingsProcessSection('email', ['f' => []], []);
assertEquals([], $asEmpty['changes'] + $asEmpty['errors'], 'a request with no boxes changes nothing');
$asSame = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => '30', 'email_queue_failed_retention_days' => '90']], [
    'email_queue_retention_days'        => $asState(['value' => '30']),
    'email_queue_failed_retention_days' => $asState(['value' => '90']),
]);
assertEquals([], $asSame['changes'], 'same values: no change');
$asRetention = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => '3651']], ['email_queue_retention_days' => $asState(['value' => '30'])]);
assertTrue(isset($asRetention['errors']['email_queue_retention_days']) && $asRetention['changes'] === [], 'a retention period of 3651 is an error and saves nothing');

// ✋ The email switch needs its tick when turning it ON.
$asNoTick = adminSettingsProcessSection('email', ['f' => ['email_enabled' => '1']], ['email_enabled' => $asState(['value' => '0'])]);
assertTrue(isset($asNoTick['errors']['email_enabled']), 'turning email on without the tick is refused');
$asTick = adminSettingsProcessSection('email', ['f' => ['email_enabled' => '1'], 'confirm' => ['email_enabled' => '1']], ['email_enabled' => $asState(['value' => '0'])]);
assertEquals([], $asTick['errors'], 'turning email on with the tick is accepted');
assertEquals('1', $asTick['changes'][0]['value'], '... and the value is 1');
$asOff = adminSettingsProcessSection('email', ['f' => ['email_enabled' => '0']], ['email_enabled' => $asState(['value' => '1'])]);
assertEquals([], $asOff['errors'], 'turning email off needs no tick');
assertEquals('0', $asOff['changes'][0]['value'], '... and the value is 0');

// 🤫 Secrets in a request.
$asSecretRequest = adminSettingsProcessSection('email', ['f' => [
    'graph_tenant_id'     => $asGuid,
    'graph_client_id'     => '',
    'graph_client_secret' => 'new secret value',
], 'rm' => ['graph_client_id' => '1']], [
    'graph_tenant_id'     => $asState(['hasValue' => false]),
    'graph_client_id'     => $asState(['hasValue' => true]),
    'graph_client_secret' => $asState(['hasValue' => true]),
]);
$asActions = [];
foreach ($asSecretRequest['changes'] as $asChange) {
    $asActions[$asChange['key']] = $asChange['action'];
}
assertEquals(['graph_tenant_id' => 'set', 'graph_client_id' => 'clear', 'graph_client_secret' => 'set'], $asActions, 'secrets: typed = set, Remove = clear');
$asEmptyBox = adminSettingsProcessSection('email', ['f' => ['graph_client_secret' => '']], ['graph_client_secret' => $asState(['hasValue' => true])]);
assertEquals([], $asEmptyBox['changes'], 'secrets: an empty box keeps the saved value (no change)');
$asRemoveNothing = adminSettingsProcessSection('email', ['f' => ['graph_client_secret' => ''], 'rm' => ['graph_client_secret' => '1']], ['graph_client_secret' => $asState(['hasValue' => false])]);
assertEquals([], $asRemoveNothing['changes'], 'secrets: removing something that is not set is no change');
$asBadSecret = adminSettingsProcessSection('email', ['f' => ['graph_client_id' => 'not-a-guid ' . $asLeakProbe]], ['graph_client_id' => $asState(['hasValue' => false])]);
assertTrue(isset($asBadSecret['errors']['graph_client_id']), 'secrets: a wrong-looking client ID is an error');
assertFalse(str_contains(json_encode($asBadSecret), $asLeakProbe), 'secrets: the error result does not contain what was typed');
$asArraySecret = adminSettingsProcessSection('email', ['f' => ['graph_client_secret' => ['a']]], []);
assertTrue(isset($asArraySecret['errors']['graph_client_secret']), 'secrets: an array instead of text is refused');

// ⏱️ Idle / max rule.
$asLimits = adminSettingsProcessSection('admin-security', ['f' => ['admin_reauth_idle_minutes' => '40', 'admin_reauth_max_minutes' => '30']], [
    'admin_reauth_idle_minutes' => $asState(['value' => '15']),
    'admin_reauth_max_minutes'  => $asState(['value' => '60']),
]);
assertTrue(isset($asLimits['errors']['admin_reauth_max_minutes']), 'the longest confirmation (30) cannot be shorter than the idle time (40)');
$asLimitsOk = adminSettingsProcessSection('admin-security', ['f' => ['admin_reauth_idle_minutes' => '30', 'admin_reauth_max_minutes' => '30']], [
    'admin_reauth_idle_minutes' => $asState(['value' => '15']),
    'admin_reauth_max_minutes'  => $asState(['value' => '60']),
]);
assertEquals([], $asLimitsOk['errors'], 'equal idle and longest times are allowed');
$asLimitsLone = adminSettingsProcessSection('admin-security', ['f' => ['admin_reauth_idle_minutes' => '60']], [
    'admin_reauth_idle_minutes' => $asState(['value' => '15']),
    'admin_reauth_max_minutes'  => $asState(['value' => '30']),
]);
assertTrue(isset($asLimitsLone['errors']['admin_reauth_max_minutes']), 'raising only the idle time above the saved longest time is refused');

// 🧱 A key from another section is never read.
$asOtherSection = adminSettingsProcessSection('email-records', ['f' => ['email_enabled' => '1', 'email_queue_retention_days' => '45']], ['email_queue_retention_days' => $asState(['value' => '30'])]);
assertEquals(['email_queue_retention_days'], array_column($asOtherSection['changes'], 'key'), 'only the section\'s own keys are read');

// 🔄 Canonical comparison.
assertEquals('1', adminSettingsCanonicalCurrent($asRegistry['email_enabled'], 'true'), 'a stored "true" counts as on');
assertEquals('0', adminSettingsCanonicalCurrent($asRegistry['email_enabled'], null), 'a missing value counts as off');
assertEquals('20.00', adminSettingsCanonicalCurrent($asRegistry['billing_vat_rate_percent'], '20'), 'a stored 20 equals 20.00');
assertEquals('30', adminSettingsCanonicalCurrent($asRegistry['email_queue_retention_days'], ' 030 '), 'a stored " 030 " equals 30');

// ==============================================================================
// ✅ Section 6: pricingVatNote() and the pricing page
// ==============================================================================
assertEquals('Prices include VAT', pricingVatNote('inclusive'), 'VAT note: inclusive');
assertEquals('Prices exclude VAT', pricingVatNote('exclusive'), 'VAT note: exclusive');
assertEquals('', pricingVatNote('none'), 'VAT note: none shows no wording');
foreach (['', 'INCLUSIVE', 'unknown', ' none', 'exclusive '] as $asUnknown) {
    assertEquals('Prices include VAT', pricingVatNote($asUnknown), 'VAT note: unknown value ' . var_export($asUnknown, true) . ' uses the seeded default');
}
$asPricingSource = (string) file_get_contents($asSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'pricing' . DIRECTORY_SEPARATOR . 'index.php');
assertContains("getConfig('billing_vat_mode'", $asPricingSource, 'the pricing page reads billing_vat_mode');
assertContains('pricingVatNote(', $asPricingSource, 'the pricing page uses pricingVatNote()');
$asGridSource = (string) file_get_contents($asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'pricing_grid.php');
assertContains("'pricing_grid.php'", $asPricingSource, 'the pricing page draws its cards with pricing_grid.php');
assertContains('htmlspecialchars($pricingVatNote', $asGridSource, 'the pricing cards escape the VAT note');

// ==============================================================================
// ✅ Section 7: the rendered HTML never holds a secret (CHILD process)
// ==============================================================================
$asChildPlain = 'PlainSecret-' . bin2hex(random_bytes(8));
$asChildScript = tempnam(sys_get_temp_dir(), 'cuercode_settings_render_');
$asChildCode = <<<'CHILD'
<?php
// Child process: render every registry field with a stored (encrypted) secret and print the HTML.
require $argv[1] . 'tests/bootstrap.php';
requireFn('fn_encryption.php');
requireFn('fn_admin_settings.php');
$plain = $argv[2];
$out = '';
$cipherSample = '';
foreach (adminSettingsRegistry() as $key => $field) {
    $sensitive = ($field['kind'] === 'secret');
    $value = 'harmless';
    if ($sensitive) {
        $value = encryptValue($plain);
        if ($cipherSample === '') {
            $cipherSample = $value;
        }
    }
    $row = [
        'settingKey' => $key, 'settingType' => $field['type'], 'isSensitive' => $sensitive ? 1 : 0, 'isEditable' => 1,
        'settingValue' => $value, 'updatedAt' => '2026-10-05 13:02:00',
    ];
    $state = adminSettingsStateFromRow($row);
    $out .= adminSettingsRenderField($field, ['state' => $state, 'error' => '', 'timezone' => 'Europe/London', 'planChoices' => ['pro']]);
    // Also with an error shown.
    $out .= adminSettingsRenderField($field, ['state' => $state, 'error' => 'Something is wrong.', 'timezone' => 'Europe/London', 'planChoices' => ['pro']]);
    $json = json_encode($state);
    if ($sensitive && (str_contains($json, $plain) || str_contains($json, $value))) {
        $out .= 'STATE-LEAK ' . $key;
    }
}
echo json_encode(['html' => $out, 'cipher' => $cipherSample]);
CHILD;
file_put_contents($asChildScript, $asChildCode);

$asChildProcess = proc_open(
    [PHP_BINARY, $asChildScript, CUERCODE_TEST_REPO_ROOT, $asChildPlain],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $asChildPipes
);
$asChildOut = '';
$asChildErr = '';
$asChildExit = -1;
if (is_resource($asChildProcess)) {
    fclose($asChildPipes[0]);
    $asChildOut = (string) stream_get_contents($asChildPipes[1]);
    fclose($asChildPipes[1]);
    $asChildErr = (string) stream_get_contents($asChildPipes[2]);
    fclose($asChildPipes[2]);
    $asChildExit = proc_close($asChildProcess);
}
unlink($asChildScript);

assertEquals(0, $asChildExit, 'the render child process ran cleanly' . ($asChildErr !== '' ? ' (' . substr($asChildErr, 0, 200) . ')' : ''));
$asChildData = json_decode($asChildOut, true);
assertTrue(is_array($asChildData) && isset($asChildData['html'], $asChildData['cipher']), 'the render child printed its result');
if (is_array($asChildData) && isset($asChildData['html'], $asChildData['cipher'])) {
    $asHtml = (string) $asChildData['html'];
    assertTrue(strlen($asHtml) > 5000, 'the child rendered every registry field (' . strlen($asHtml) . ' bytes)');
    assertFalse(str_contains($asHtml, $asChildPlain), 'the rendered settings HTML does not contain the secret plaintext');
    assertTrue(strlen((string) $asChildData['cipher']) > 40, 'the child really produced ciphertext to look for');
    assertFalse(str_contains($asHtml, (string) $asChildData['cipher']), 'the rendered settings HTML does not contain the ciphertext');
    assertFalse(str_contains($asHtml, substr((string) $asChildData['cipher'], 0, 24)), 'nor even the start of the ciphertext');
    assertFalse(str_contains($asHtml, 'STATE-LEAK'), 'the state kept for a secret holds neither the plaintext nor the ciphertext');
    assertContains('Set - last changed 5 Oct 2026, 14:02 (UK time)', $asHtml, 'a stored secret reads "Set - last changed ... (UK time)"');
    assertContains('Remove this secret', $asHtml, 'a stored secret offers a Remove tick');
    assertContains('autocomplete="new-password"', $asHtml, 'secret boxes ask the browser not to fill them');
    assertContains('data-1p-ignore', $asHtml, 'secret boxes ask password managers to ignore them');
    assertContains('data-lpignore="true"', $asHtml, '... and LastPass');
    assertMatches('/type="password"[^>]*value=""/', $asHtml, 'secret boxes are always drawn empty');
    assertFalse((bool) preg_match('/type="password"[^>]*value="[^"]/', $asHtml), 'no secret box ever has a value');
}

// 🔤 A box with nothing stored says so, and offers no Remove tick.
$asNotSetHtml = adminSettingsRenderField($asRegistry['graph_client_secret'], ['state' => ['exists' => true, 'hasValue' => false, 'value' => null, 'updatedAt' => null], 'error' => '']);
assertContains('Not set', $asNotSetHtml, 'an empty secret reads "Not set"');
assertFalse(str_contains($asNotSetHtml, 'Remove this secret'), '... and offers no Remove tick');
$asErrorHtml = adminSettingsRenderField($asRegistry['email_queue_retention_days'], ['state' => ['exists' => true, 'hasValue' => true, 'value' => '30', 'updatedAt' => null], 'error' => 'Type a whole number', 'posted' => '99999']);
assertContains('aria-invalid="true"', $asErrorHtml, 'a box with a problem is marked aria-invalid');
assertContains('aria-describedby="help-email_queue_retention_days err-email_queue_retention_days"', $asErrorHtml, 'help and error text are tied to the box');
assertContains('value="99999"', $asErrorHtml, 'a mistyped non-secret value is shown again');

// ==============================================================================
// ✅ Section 8: the test-email limit
// ==============================================================================
$asNow = 1800000000;
assertTrue(adminSettingsTestEmailWindow([], $asNow)['allowed'], 'test email: the first is allowed');
assertTrue(adminSettingsTestEmailWindow([$asNow - 10, $asNow - 20, $asNow - 30, $asNow - 40], $asNow)['allowed'], 'test email: the fifth is allowed');
$asFive = [$asNow - 500, $asNow - 400, $asNow - 300, $asNow - 200, $asNow - 100];
assertFalse(adminSettingsTestEmailWindow($asFive, $asNow)['allowed'], 'test email: the sixth within 10 minutes is refused');
assertEquals(100, adminSettingsTestEmailWindow($asFive, $asNow)['waitSeconds'], 'test email: it says how long to wait (until the oldest leaves the window)');
assertTrue(adminSettingsTestEmailWindow($asFive, $asNow + 100)['allowed'], 'test email: allowed again when the oldest is exactly 10 minutes old');
assertFalse(adminSettingsTestEmailWindow($asFive, $asNow + 99)['allowed'], 'test email: still refused one second earlier');
assertEquals(0, count(adminSettingsTestEmailWindow([$asNow - 600, $asNow - 9999], $asNow)['times']), 'test email: old times are forgotten');
assertEquals(1, count(adminSettingsTestEmailWindow([$asNow + 50, $asNow - 1], $asNow)['times']), 'test email: a time in the future (a clock fault) is ignored');

// ==============================================================================
// ✅ Section 9: static guards
// ==============================================================================
$asPagePath   = $asSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'index.php';
$asPage       = (string) file_get_contents($asPagePath);
$asFunctions  = (string) file_get_contents($asSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin_settings.php');

// 🚪 Gate first: the page loads exactly bootstrap.php and fn_admin.php, then the gate, then anything else.
assertTrue(is_file($asPagePath), 'the settings page exists');
$asStatements = [];
$asTokens = token_get_all($asPage);
$asBefore = '';
foreach ($asTokens as $asToken) {
    if (is_array($asToken) && $asToken[0] === T_STRING && $asToken[1] === 'requireGlobalAdminPage') {
        break;
    }
    if (is_array($asToken)) {
        $asBefore .= $asToken[1];
    } else {
        $asBefore .= $asToken;
    }
}
assertEquals(2, preg_match_all('/require_once\b/', $asBefore), 'only the two plain requires come before the gate');
assertFalse((bool) preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/', preg_replace('/\/\*.*?\*\//s', '', $asBefore)), 'nothing reads the request before the gate');
assertTrue(strpos($asPage, "requireGlobalAdminPage('/admin/settings/')") < strpos($asPage, 'fn_admin_settings.php\';'), 'the settings functions are loaded only after the gate');

// 🚫 No inline script, no inline handlers.
assertFalse((bool) preg_match('/<script\b/i', $asPage . $asFunctions), 'no inline <script> on the settings page');
assertFalse((bool) preg_match('/\son[a-z]+\s*=\s*["\']/i', $asPage . $asFunctions), 'no inline on...= handlers');
assertContains("'/assets/js/cuercode-admin.js'", $asPage, 'the page uses the one admin script file');

// 🔐 Every POST goes through adminCheckPost() (CSRF, same site, fresh password) with the password required.
assertContains('adminCheckPost($adminUser, true)', $asPage, 'the settings POST requires a fresh password confirmation');
assertEquals(substr_count(strtolower($asPage), 'method="post"'), substr_count($asPage, 'csrfField()'), 'every settings form carries the CSRF field');
assertTrue(strpos($asPage, 'adminCheckPost(') < strpos($asPage, 'adminSettingsProcessSection('), 'the POST is checked BEFORE anything is validated or saved');
assertContains('adminDatabaseReadyForChanges()', $asPage, 'the page refuses to save while upgrades are waiting');
assertContains('adminRedirect(', $asPage, 'a successful save redirects (a reload cannot re-send the form)');

// 🔒 The page and functions never echo a secret value.
assertFalse((bool) preg_match('/getConfig\(\s*[\'"](graph_|sso_[a-z]+_client|paypal_client|paypal_webhook|turnstile_|recaptcha_|hcaptcha_)/', $asPage . $asFunctions), 'the settings code never reads a secret with getConfig()');

// 🧬 No /admin route in the API (the 1B guard already covers openapi.json; check the new file adds none).
assertFalse(str_contains($asFunctions, "'pattern'"), 'fn_admin_settings.php defines no route');

// 🎭 roleID is not touched by the settings code.
assertFalse(str_contains($asFunctions . $asPage, 'roleID'), 'the settings code never mentions roleID');

// 🤫 #[\SensitiveParameter] on every parameter that can hold a secret.
$asSensitiveChecks = [
    ['fn_config.php', 'function setConfig(string $key, #[\SensitiveParameter] mixed $value'],
    ['fn_encryption.php', 'function encryptValue(#[\SensitiveParameter] string $plaintext'],
    ['fn_admin_settings.php', 'function adminSaveSetting(array $spec, #[\SensitiveParameter] mixed $newValue'],
    ['fn_admin_settings.php', 'function adminSettingsCheckSecretValue(#[\SensitiveParameter] string $typed'],
    ['fn_admin_settings.php', 'function adminSettingsSecretIntent(#[\SensitiveParameter] string $typed'],
    ['fn_admin_settings.php', 'function adminSettingsValidateValue(array $field, #[\SensitiveParameter] string $raw'],
    ['fn_admin_settings.php', 'function adminSettingsIsGuid(#[\SensitiveParameter] string $text'],
    ['fn_admin_settings.php', 'function adminSettingsIsDomainName(#[\SensitiveParameter] string $text'],
    ['fn_admin_settings.php', 'function adminSettingsProcessSection(string $sectionId, #[\SensitiveParameter] array $post'],
    ['fn_admin_settings.php', '#[\SensitiveParameter] array $changes, ?string $reason = null): array'],
    ['fn_config.php', 'function convertValueForStorage(#[\SensitiveParameter] mixed $value'],
    ['fn_oauth.php', '#[\SensitiveParameter] string $clientSecret,'],
    ['fn_password.php', 'function hashPassword(#[\SensitiveParameter] string $plainPassword'],
    ['fn_password.php', 'function verifyPassword(#[\SensitiveParameter] string $plainPassword'],
    ['fn_password.php', 'function validatePasswordPolicy(#[\SensitiveParameter] string $plainPassword'],
    ['fn_admin.php', 'function adminVerifyReauthPassword(int $userID, #[\SensitiveParameter] string $password'],
    ['fn_admin.php', 'function adminCliStripLineEnding(#[\SensitiveParameter] string|false $line'],
    ['fn_auth.php', 'function registerUser(string $email, #[\SensitiveParameter] string $plainPassword'],
    ['fn_auth.php', 'function loginUser(string $email, #[\SensitiveParameter] string $plainPassword'],
    ['fn_api_account.php', '#[\SensitiveParameter] string $currentPassword'],
];
foreach ($asSensitiveChecks as $asCheck) {
    $asSource = (string) file_get_contents($asSiteRoot . '_functions' . DIRECTORY_SEPARATOR . $asCheck[0]);
    assertContains($asCheck[1], $asSource, $asCheck[0] . ': ' . $asCheck[1]);
}

// 🔤 Hand-written SQL in the settings code: every table and column is back-quoted.
$asSqlStrings = [];
foreach (token_get_all($asFunctions) as $asToken) {
    if (is_array($asToken) && $asToken[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/tbl[A-Z]/', $asToken[1]) === 1 && str_contains($asToken[1], ' ')) {
        $asSqlStrings[] = $asToken[1];
    }
}
assertTrue(count($asSqlStrings) >= 3, 'the settings code has SQL to check (' . count($asSqlStrings) . ' strings)');
foreach ($asSqlStrings as $asSql) {
    assertTrue((bool) preg_match('/`tbl[A-Za-z]+`/', $asSql), 'SQL back-quotes its table: ' . substr($asSql, 0, 60));
    assertFalse((bool) preg_match('/(FROM|INTO|UPDATE|JOIN)\s+tbl/', $asSql), 'SQL has no bare table name: ' . substr($asSql, 0, 60));
}

// 🧭 The nav links to the page, and the overview points at it.
$asNav = (string) file_get_contents($asSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'admin_nav.php');
assertContains("'/admin/settings/'", $asNav, 'the admin menu links to the settings page');
$asOverview = (string) file_get_contents($asSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.php');
assertFalse(str_contains($asOverview, 'which is the next part of the admin area to be built'), 'the overview no longer says the settings screen is still to be built');


// ==============================================================================
// ✅ Section 10: second independent review (I1, I2, M1-M6, nits, extra)
// ==============================================================================

// 📏 I2 / decision 0015: the editor uses fn_client_ip.php's range check (not a copy).
foreach (['0.0.0.0/0', '::/0', '0.0.0.0/1', '10.0.0.0/7', '2000::/16'] as $asWideRange) {
    $asWideResult = $asValidate('trusted_proxy_ips', $asWideRange);
    assertFalse($asWideResult['ok'], '0015: ' . $asWideRange . ' is refused');
    assertContains('is too wide', $asWideResult['error'], '0015: ' . $asWideRange . ' is refused with the plain "too wide" message');
    assertContains('"' . $asWideRange . '"', $asWideResult['error'], '0015: the message names ' . $asWideRange);
}
foreach (['10.0.0.0/8', '2606:4700::/32'] as $asFineRange) {
    assertTrue($asValidate('trusted_proxy_ips', $asFineRange)['ok'], '0015: ' . $asFineRange . ' is accepted');
}
assertFalse(str_contains($asFunctions, 'inet_pton'), 'the settings code does not parse ranges itself (it calls clientIpParseRangeList)');
assertContains('clientIpParseRangeList(', $asFunctions, 'the settings code calls clientIpParseRangeList()');

// 🏷️ M1: every section has its own Save button wording.
$asSaveLabels = [];
foreach (adminSettingsSections() as $asSectionKey => $asSection) {
    assertTrue(isset($asSection['saveLabel']) && $asSection['saveLabel'] !== '', $asSectionKey . ' has its own save button label');
    assertTrue(str_starts_with($asSection['saveLabel'], 'Save '), $asSectionKey . ' label starts with "Save "');
    assertTrue($asSection['saveLabel'] === ucfirst($asSection['saveLabel']) && !str_contains($asSection['saveLabel'], 'paypal') && !str_contains($asSection['saveLabel'], '(microsoft'), $asSectionKey . ' label is not a lower-cased section title');
    $asSaveLabels[] = $asSection['saveLabel'];
}
assertEquals(count($asSaveLabels), count(array_unique($asSaveLabels)), 'no two sections share a save button label');
assertTrue(in_array('Save PayPal settings', $asSaveLabels, true), 'the PayPal button says "Save PayPal settings"');
assertTrue(in_array('Save prices, VAT and refunds', $asSaveLabels, true), 'the prices button says "Save prices, VAT and refunds"');
assertTrue(in_array('Save email settings', $asSaveLabels, true), 'the email button says "Save email settings"');
assertFalse(str_contains($asPage, 'strtolower($settingsSection'), 'the page no longer lower-cases a section title for its button');
assertContains("\$settingsSection['saveLabel']", $asPage, 'the page uses the section\'s own save label');

// 💬 M2: where the detail really is written.
assertFalse(str_contains($asFunctions, 'server error log'), 'no message sends the admin to the "server error log" (the detail is in the activity log)');
assertEquals(2, substr_count($asFunctions, 'More detail is in the activity log'), 'both test actions point at the activity log');

// 🌐 M3: discovery addresses are kept narrow.
foreach ([
    'https://127.0.0.1/x', 'https://[::1]/x', 'https://[2001:db8::1]/x', 'https://10.0.0.1/x', 'https://2130706433/x', 'https://127.1/x', 'https://0x7f.0.0.1/x',
    'https://login.example.com:8443/x', 'https://login.example.com:80/x', 'https://login.example.com:/x', 'https://login.example.com:0443/x',
    'https://user@login.example.com/x', 'https://user:pw@login.example.com/x', 'https://login.example.com@evil.example.org/x',
    "https://b\xC3\xBCcher.example/x", "https://\xE4\xBE\x8B\xE3\x81\x88.jp/x", 'https://localhost/x', 'https://intranet/x', 'https://exa_mple.com/x',
    'https://login.example.com\\@evil.example/x', 'HTTP://login.example.com/x',
] as $asBadAddress) {
    assertFalse($asValidate('sso_google_discovery_url', $asBadAddress)['ok'], 'discovery address refuses ' . var_export($asBadAddress, true));
}
foreach ([
    'https://accounts.google.com/.well-known/openid-configuration', 'HTTPS://Login.Example.com/x?y=1#z', 'https://login.example.com:443/x', 'https://xn--bcher-kva.example/x',
    'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration',
] as $asGoodAddress) {
    assertTrue($asValidate('sso_google_discovery_url', $asGoodAddress)['ok'], 'discovery address accepts ' . var_export($asGoodAddress, true));
}
assertContains('IP number', $asValidate('sso_google_discovery_url', 'https://127.0.0.1/x')['error'], 'an IP address is refused with a plain reason');
assertContains('port', $asValidate('sso_google_discovery_url', 'https://login.example.com:8443/x')['error'], 'a port is refused with a plain reason');

// 🚦 M4: the connection check has its own limit, counted from the audit log.
assertEquals(10, CUERCODE_ADMIN_TEST_GRAPH_MAX, 'at most 10 connection checks per 10 minutes');
$asGraphTimes = [];
for ($asI = 0; $asI < 10; $asI++) {
    $asGraphTimes[] = $asNow - 100 - $asI;
}
assertFalse(adminSettingsTestEmailWindow($asGraphTimes, $asNow, CUERCODE_ADMIN_TEST_GRAPH_MAX)['allowed'], 'the 11th connection check inside 10 minutes is refused');
assertTrue(adminSettingsTestEmailWindow(array_slice($asGraphTimes, 1), $asNow, CUERCODE_ADMIN_TEST_GRAPH_MAX)['allowed'], 'the 10th connection check is allowed');
assertContains("adminSettingsAttemptWithinLimit(\$admin, 'setting.test_graph', 'graph_connection', \$now, CUERCODE_ADMIN_TEST_GRAPH_MAX)", $asFunctions, 'the connection check counts the admin\'s own setting.test_graph audit rows');
assertContains("adminSettingsAttemptWithinLimit(\$admin, 'setting.test_email', 'email_enabled', \$now, CUERCODE_ADMIN_TEST_EMAIL_MAX)", $asFunctions, 'the test email also counts the admin\'s own setting.test_email audit rows');
assertContains('adminSettingsRecentAttemptTimes((int) $admin[\'userID\'], $action, $now, CUERCODE_ADMIN_TEST_EMAIL_WINDOW_SECONDS)', $asFunctions, '...from the audit log');
// 🚦 Final review N-1: the attempt row is written FIRST, then the limit counted including it.
foreach (['function adminSettingsTestGraph(' => '$tokenFetcher()', 'function adminSettingsSendTestEmail(' => '$sender($address'] as $asFnStart => $asRunsAt) {
    $asFnBody = (string) substr($asFunctions, (int) strpos($asFunctions, $asFnStart), 6000);
    assertTrue(
        strpos($asFnBody, 'adminSettingsWriteTestAttempt(') < strpos($asFnBody, 'adminSettingsAttemptWithinLimit(')
        && strpos($asFnBody, 'adminSettingsAttemptWithinLimit(') < strpos($asFnBody, $asRunsAt),
        'N-1 ' . $asFnStart . ': the attempt row is written first, then the limit is counted (including it), then the test runs'
    );
}
$asWithin = (string) substr($asFunctions, (int) strpos($asFunctions, 'function adminSettingsAttemptWithinLimit('), 2500);
assertContains('adminSettingsTestEmailWindow($times, $now, $max + 1)', $asWithin, 'N-1: this attempt is already counted, so up to $max in the window are allowed');
assertContains("'refused', [], CUERCODE_ADMIN_TEST_LIMITED_REASON", $asWithin, 'N-1: a refused press is recorded as refused');
// ⏱️ N-1 arithmetic: with this attempt included, exactly MAX in the window are allowed.
$asIncl = [];
for ($asI = 0; $asI < CUERCODE_ADMIN_TEST_GRAPH_MAX; $asI++) {
    $asIncl[] = $asNow - $asI;
}
assertTrue(adminSettingsTestEmailWindow($asIncl, $asNow, CUERCODE_ADMIN_TEST_GRAPH_MAX + 1)['allowed'], 'N-1: the 10th press (10 rows including itself) is allowed');
$asIncl[] = $asNow;
assertFalse(adminSettingsTestEmailWindow($asIncl, $asNow, CUERCODE_ADMIN_TEST_GRAPH_MAX + 1)['allowed'], 'N-1: the 11th (11 rows including itself) is refused');
// 🏷️ Final review N-2: the "about to run" row has its own action name.
assertEquals('.started', CUERCODE_ADMIN_TEST_STARTED_SUFFIX, 'N-2: the about-to-run row is ACTION.started');
assertContains('$action . CUERCODE_ADMIN_TEST_STARTED_SUFFIX', (string) substr($asFunctions, (int) strpos($asFunctions, 'function adminSettingsWriteTestAttempt('), 800), 'N-2: the attempt row is written under ACTION.started');
assertTrue(in_array('setting.test_graph.started', adminAuditActionNames(), true) && in_array('setting.test_email.started', adminAuditActionNames(), true), 'N-2: the audit log accepts the two new names');
$asGraphBody = (string) substr($asFunctions, (int) strpos($asFunctions, 'function adminSettingsTestGraph('), 4200);
assertTrue(strpos($asGraphBody, 'adminSettingsWriteTestAttempt(') < strpos($asGraphBody, '$tokenFetcher()'), 'the connection check writes its audit row BEFORE it runs');
$asEmailBody = (string) substr($asFunctions, (int) strpos($asFunctions, 'function adminSettingsSendTestEmail('), 6000);
assertTrue(strpos($asEmailBody, 'adminSettingsWriteTestAttempt(') < strpos($asEmailBody, '$sender($address'), 'the test email writes its audit row BEFORE it is sent');
assertContains('so no email was sent', $asEmailBody, 'a test email that cannot be recorded is not sent, and the message says so');

// 📋 M5: a drop-down never silently changes a saved value.
$asDrop = static function (string $asKey, ?string $asSaved, array $asView = []) use ($asRegistry): string {
    $asStateForDrop = ['exists' => true, 'hasValue' => $asSaved !== null && $asSaved !== '', 'value' => $asSaved, 'updatedAt' => null];
    return adminSettingsRenderField($asRegistry[$asKey], array_merge(['state' => $asStateForDrop, 'error' => ''], $asView));
};
$asOdd = $asDrop('billing_vat_mode', 'half<b>');
assertContains('<option value="half&lt;b&gt;" selected>Current value (not in list): half&lt;b&gt;</option>', $asOdd, 'a saved value not in the list is shown, escaped, and selected');
assertEquals(1, substr_count($asOdd, ' selected'), 'only that option is selected');
assertFalse(str_contains($asOdd, 'half<b>'), 'the odd value is escaped everywhere');
$asNormal = $asDrop('billing_vat_mode', 'exclusive');
assertFalse(str_contains($asNormal, 'not in list'), 'a saved value that IS in the list adds no extra option');
assertContains('<option value="exclusive" selected>', $asNormal, 'the saved option is the selected one');
$asEmptyChoice = $asDrop('paypal_mode', '');
assertContains('<option value="" selected>Choose...</option>', $asEmptyChoice, 'an empty saved choice leads with "Choose..." (empty is not allowed)');
$asPlanEmpty = adminSettingsRenderField($asRegistry['billing_trial_plan_key'], ['state' => ['exists' => true, 'hasValue' => false, 'value' => '', 'updatedAt' => null], 'error' => '', 'planChoices' => ['pro']]);
assertContains('<option value="" selected>Choose...</option>', $asPlanEmpty, 'an empty trial plan leads with "Choose..."');
$asPlanOdd = adminSettingsRenderField($asRegistry['billing_trial_plan_key'], ['state' => ['exists' => true, 'hasValue' => true, 'value' => 'old_plan', 'updatedAt' => null], 'error' => '', 'planChoices' => ['pro']]);
assertContains('old_plan (not an active paid plan)', $asPlanOdd, 'an unknown saved plan is still shown (as before)');
// ...and saving the untouched form changes nothing (the odd value posts back as itself).
$asOddSave = adminSettingsProcessSection('prices', ['f' => ['billing_vat_mode' => 'half<b>']], ['billing_vat_mode' => $asState(['value' => 'half<b>'])]);
assertEquals([], $asOddSave['changes'] + $asOddSave['errors'], 'saving the untouched form with an odd saved value changes nothing and raises no error');
assertContains('Choose one of the options listed.', adminSettingsProcessSection('prices', ['f' => ['billing_vat_mode' => 'other']], ['billing_vat_mode' => $asState(['value' => 'half<b>'])])['errors']['billing_vat_mode'], 'but choosing an unlisted value by hand is still refused');

// ==============================================================================
// 🔧 Final review M-3 / M-4 (2026-11-25)
// ==============================================================================
// M-3: a limit with no saved row is using the code's default: the box is not
//      "(required)", it shows the default, and an empty box saves nothing.
$asGap = $asRegistry['password_reset_request_min_gap_seconds'];
assertEquals(60, $asGap['default'], 'M-3: the registry carries the default the code uses (60)');
$asNoRow = ['exists' => false, 'hasValue' => false, 'value' => null, 'updatedAt' => null];
$asGapBox = adminSettingsRenderField($asGap, ['state' => $asNoRow, 'error' => '']);
assertFalse(str_contains($asGapBox, '(required)'), 'M-3: no saved row: the label does not say "(required)"');
assertFalse(str_contains($asGapBox, 'aria-required="true"'), 'M-3: ...and screen readers are not told it is required');
assertContains('placeholder="60 (the default)"', $asGapBox, 'M-3: ...the box shows the default (faintly, as a placeholder)');
assertContains('Nothing is saved yet, so the default (60) is in use.', $asGapBox, 'M-3: ...and the help says the default is in use');
assertFalse(adminSettingsFieldIsRequired($asGap, $asNoRow), 'M-3: adminSettingsFieldIsRequired() says no for a missing optional row');
assertTrue(adminSettingsFieldIsRequired($asGap, $asState(['value' => '60'])), 'M-3: ...and yes once the row exists');
assertTrue(adminSettingsFieldIsRequired($asGap), 'M-3: ...and yes when the state is not known');
$asGapSaved = adminSettingsRenderField($asGap, ['state' => $asState(['value' => '120']), 'error' => '']);
assertContains('(required)', $asGapSaved, 'M-3: with a saved row the box is required as before');
assertFalse(str_contains($asGapSaved, 'placeholder='), 'M-3: ...and shows no default placeholder');
$asEmptySave = adminSettingsProcessSection('admin-security', ['f' => ['password_reset_request_min_gap_seconds' => '', 'verification_resend_daily_cap' => '  ']], []);
assertEquals([], $asEmptySave['errors'], 'M-3: saving the section with the empty default boxes untouched raises no error');
assertEquals([], $asEmptySave['changes'], 'M-3: ...and saves nothing (the default stays the code\'s)');
$asTyped = adminSettingsProcessSection('admin-security', ['f' => ['password_reset_request_min_gap_seconds' => '90']], []);
assertEquals([['key' => 'password_reset_request_min_gap_seconds', 'action' => 'update', 'value' => '90']], $asTyped['changes'], 'M-3: typing a number still creates the row');
$asClearSaved = adminSettingsProcessSection('admin-security', ['f' => ['password_reset_request_min_gap_seconds' => '']], ['password_reset_request_min_gap_seconds' => $asState(['value' => '120'])]);
assertEquals('This cannot be empty.', $asClearSaved['errors']['password_reset_request_min_gap_seconds'] ?? '', 'M-3: emptying a box that HAS a saved row is still refused');
$asNotOptional = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => '']], []);
assertEquals('This cannot be empty.', $asNotOptional['errors']['email_queue_retention_days'] ?? '', 'M-3: an ordinary (seeded) number left empty is still refused');

// M-4: a stored number that is not written the allowed way.
foreach (['-5', '30.0', '1e3', ' 2e1 ', '+7'] as $asOdd) {
    $asOddBox = adminSettingsRenderField($asRegistry['email_queue_retention_days'], ['state' => $asState(['value' => $asOdd]), 'error' => '']);
    assertContains('value="' . htmlspecialchars(trim($asOdd), ENT_QUOTES) . '"', $asOddBox, 'M-4: a stored ' . var_export($asOdd, true) . ' is shown as stored');
    $asOddResult = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => trim($asOdd), 'email_queue_failed_retention_days' => '91']], ['email_queue_retention_days' => $asState(['value' => $asOdd]), 'email_queue_failed_retention_days' => $asState(['value' => '90'])]);
    assertEquals([], $asOddResult['errors'], 'M-4: saving with a stored ' . var_export($asOdd, true) . ' left untouched raises no error');
    assertEquals([['key' => 'email_queue_failed_retention_days', 'action' => 'update', 'value' => '91']], $asOddResult['changes'], 'M-4: ...so the OTHER change in the section is saved, and the odd value is left alone');
}
$asOddEdited = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => '-6']], ['email_queue_retention_days' => $asState(['value' => '-5'])]);
assertEquals('Type a whole number from 1 to 3650.', $asOddEdited['errors']['email_queue_retention_days'] ?? '', 'M-4: changing it to another bad value: the plain range message, for that box only');
foreach (['-5', '30.0', '1e3', '+5'] as $asNotNumber) {
    assertEquals('Type a whole number from 1 to 3650.', $asValidate('email_queue_retention_days', $asNotNumber)['error'], 'M-4: ' . var_export($asNotNumber, true) . ' gets the range message (no mention of a leading zero)');
}
assertEquals('Type a whole number from 1 to 3650, with no zero at the start.', $asValidate('email_queue_retention_days', '030')['error'], 'M-4: "030" gets the range message AND the leading-zero reason');
$asZeroAgain = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => '030']], ['email_queue_retention_days' => $asState(['value' => '30'])]);
assertTrue(isset($asZeroAgain['errors']['email_queue_retention_days']), 'M-4: "030" typed over a stored 30 is still refused (not waved through as "the same")');
$asDecimalOdd = adminSettingsValidateValue($asRegistry['billing_vat_rate_percent'], '1e1');
assertContains('with up to two decimal places', $asDecimalOdd['error'], 'M-4: a decimal box names its range and the two decimal places');
assertFalse(str_contains($asDecimalOdd['error'], 'no zero at the start'), 'M-4: ...without a leading-zero reason that does not apply');

// 🧹 Final review N-3: discovery addresses - hygiene on the name (the real
//    controls are in fn_oauth.php: TLS, no redirects, https only).
foreach ([
    'https://login.localhost/x', 'https://LOCALHOST/x', 'https://printer.local/x', 'https://idp.corp.internal/x',
    'https://sso.localdomain/x', 'https://router.home.arpa/x', 'https://10.0.0.1.nip.io/x', 'https://127-0-0-1.sslip.io/x',
    'https://169.254.169.254.xip.io/x', 'https://nip.io/x', 'https://intranet/x', 'https://idp/x',
] as $asInternal) {
    assertFalse($asValidate('sso_microsoft_discovery_url', $asInternal)['ok'], 'N-3: a discovery address ' . $asInternal . ' is refused');
}
assertContains('Names ending in .nip.io are not accepted.', $asValidate('sso_microsoft_discovery_url', 'https://10.0.0.1.nip.io/x')['error'], 'N-3: ...with a plain reason naming the ending');
foreach (['https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration', 'https://accounts.google.com/.well-known/openid-configuration', 'https://local.example.com/x', 'https://notlocalhost.example/x', 'https://nip.io.example.com/x'] as $asPublic) {
    assertTrue($asValidate('sso_microsoft_discovery_url', $asPublic)['ok'], 'N-3: a public address ' . $asPublic . ' is still accepted');
}
assertContains('HYGIENE, not protection', $asFunctions, 'N-3: the code says plainly that the name list is hygiene, not the real control');
requireFn('fn_oauth.php');
$asOauth = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_oauth.php');
$asSafety = (string) substr($asOauth, (int) strpos($asOauth, 'function oauthApplyCurlSafety('), 1500);
assertContains('CURLOPT_FOLLOWLOCATION => false', $asSafety, 'N-3: sign-in provider calls never follow a redirect (stated, not left to the default)');
assertContains("CURLOPT_PROTOCOLS_STR, 'https'", $asSafety, 'N-3: ...and speak https only');
assertContains('CURLOPT_PROTOCOLS, CURLPROTO_HTTPS', $asSafety, 'N-3: ...with the older form for an older libcurl');
assertEquals(3, substr_count($asOauth, 'if (!oauthApplyCurlSafety($curlHandle)) {'), 'N-3: all three provider calls (discovery/keys, token, user details) use it, and give up if it fails');
if (function_exists('curl_init')) {
    $asHandle = curl_init();
    assertTrue(oauthApplyCurlSafety($asHandle), 'N-3: the settings are accepted by this PHP\'s cURL');
}

// ❗ M6: required boxes say so; the unsaved-changes warning is on.
foreach ($asRegistry as $asKey => $asField) {
    $asBox = adminSettingsRenderField($asField, ['state' => ['exists' => true, 'hasValue' => false, 'value' => '', 'updatedAt' => null], 'error' => '', 'planChoices' => ['pro']]);
    // 💱 'currency_list' (billing_currencies, task 7n Phase 2) is required too.
    $asKindIsRequired = in_array($asField['kind'], ['int', 'decimal', 'choice', 'timezone', 'plan_key', 'https_url', 'site_url', 'scopes', 'currency_list'], true);
    if ($asKindIsRequired) {
        assertTrue(adminSettingsFieldIsRequired($asField), $asKey . ' is required');
        assertContains('(required)', $asBox, $asKey . ' says "(required)" in its label');
        assertContains('aria-required="true"', $asBox, $asKey . ' is marked aria-required');
    } else {
        assertFalse(adminSettingsFieldIsRequired($asField), $asKey . ' is not required');
        assertFalse(str_contains($asBox, '(required)'), $asKey . ' does not say "(required)"');
    }
}
assertContains('data-admin-dirty-check', $asPage, 'every settings form turns on the unsaved-changes warning');
assertFalse(strpos($asPage, '<form method="POST" action="/admin/settings/" novalidate>') !== false, 'no settings form is left without the warning');
$asAdminJs = (string) file_get_contents($asSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'cuercode-admin.js');
assertContains("form[data-admin-dirty-check]", $asAdminJs, 'the admin script supports the unsaved-changes warning');
assertContains("data-admin-confirm-check", $asAdminJs, 'the admin script supports a confirmation on a tick box');
assertFalse((bool) preg_match('/<script\b[^>]*>[^<]/i', $asPage), 'still no inline script on the settings page');

// ☑️ Remove tick: confirmation text, with the form still working without JavaScript.
$asRemoveBox = adminSettingsRenderField($asRegistry['graph_client_secret'], ['state' => ['exists' => true, 'hasValue' => true, 'value' => null, 'updatedAt' => null], 'error' => '']);
assertContains('data-admin-confirm-check="Remove this stored secret?"', $asRemoveBox, 'the Remove tick asks "Remove this stored secret?"');
assertContains('name="rm[graph_client_secret]" value="1"', $asRemoveBox, 'the Remove tick is still a plain tick box that works without JavaScript');

// 🙈 Hidden characters: control, invisible formatting and line/paragraph separators.
foreach (["a\u{200B}b", "a\u{200D}b", "a\u{FEFF}b", "a\u{202E}b", "a\u{2028}b", "a\u{2029}b", "a\u{00AD}b", "a\u{0085}b"] as $asHidden) {
    assertFalse($asValidate('email_from_name', $asHidden)['ok'], 'a text box refuses hidden character ' . bin2hex($asHidden));
    assertFalse($asSecretCheck($asHidden, '')['ok'], 'a secret refuses hidden character ' . bin2hex($asHidden));
    assertFalse($asValidate('trusted_proxy_ips', '10.0.0.0/8 ' . $asHidden)['ok'], 'the forwarding list refuses hidden character ' . bin2hex($asHidden));
}
assertFalse($asValidate('email_from_name', "ab\xFFcd")['ok'], 'a text box refuses invalid UTF-8');
assertFalse($asValidate('trusted_proxy_ips', "10.0.0.0/8\xFF")['ok'], 'the forwarding list refuses invalid UTF-8');
assertTrue($asValidate('email_from_name', 'CueRCode Café Ünïcode 日本')['ok'], 'ordinary accented and non-Latin letters are fine');
assertTrue(adminSettingsHasHiddenCharacter("a\nb", false), 'a line break is hidden when line breaks are not allowed');
assertFalse(adminSettingsHasHiddenCharacter("a\nb\tc\r", true), 'line breaks and tabs are fine in the multi-line box');

// 🔢 Numbers are written one way only.
assertTrue(adminSettingsNumberTextIsCanonical('int', '0') && adminSettingsNumberTextIsCanonical('int', '30') && adminSettingsNumberTextIsCanonical('int', '999999'), 'whole numbers: 0, 30 and 999999 are canonical');
foreach (['01', '000030', '', '-1', '+1', '1.0', '1e3', ' 1', '1234567'] as $asNotCanonical) {
    assertFalse(adminSettingsNumberTextIsCanonical('int', $asNotCanonical), 'whole numbers: ' . var_export($asNotCanonical, true) . ' is not canonical');
}
foreach (['20', '0', '17.5', '0.5', '100', '99.99'] as $asDecimalOk) {
    assertTrue(adminSettingsNumberTextIsCanonical('decimal', $asDecimalOk), 'decimals: ' . $asDecimalOk . ' is canonical');
}
foreach (['020', '00.5', '.5', '1.', '1.234', '1000', '1e1'] as $asDecimalBad) {
    assertFalse(adminSettingsNumberTextIsCanonical('decimal', $asDecimalBad), 'decimals: ' . var_export($asDecimalBad, true) . ' is not canonical');
}
assertFalse($asValidate('billing_vat_rate_percent', '020')['ok'], 'VAT rate refuses 020');
assertFalse($asValidate('billing_refund_window_days', '01')['ok'], 'the money-back period refuses 01');
// "030" posted when 30 is saved is refused, not waved through as "unchanged" (and "000030" the same way).
foreach (['030', '000030', '01'] as $asZeroPosted) {
    $asZeroSave = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => $asZeroPosted]], ['email_queue_retention_days' => $asState(['value' => '30'])]);
    assertTrue(isset($asZeroSave['errors']['email_queue_retention_days']) && $asZeroSave['changes'] === [], 'posting ' . $asZeroPosted . ' is an error, never a quiet "no change"');
}
$asSame30 = adminSettingsProcessSection('email-records', ['f' => ['email_queue_retention_days' => '30']], ['email_queue_retention_days' => $asState(['value' => '30'])]);
assertEquals([], $asSame30['changes'] + $asSame30['errors'], 'posting 30 when 30 is saved is no change');
// An older saved spelling is drawn the one allowed way, so the untouched form does not trip the rule.
assertContains('value="30"', adminSettingsRenderField($asRegistry['email_queue_retention_days'], ['state' => ['exists' => true, 'hasValue' => true, 'value' => '030', 'updatedAt' => null], 'error' => '']), 'a stored 030 is drawn as 30');

// 📧 Sender addresses: no IP-number domains.
foreach (['a@[127.0.0.1]', 'a@[IPv6:::1]', 'notifications@[10.0.0.1]'] as $asLiteral) {
    assertFalse($asValidate('graph_sender_mailbox', $asLiteral)['ok'], 'a sender address refuses ' . $asLiteral);
}

// 🔖 I1: the form fingerprint (pure).
$asFpStates = [
    'email_queue_retention_days'        => ['exists' => true, 'hasValue' => true, 'value' => '30', 'marker' => 'aaaa'],
    'email_queue_failed_retention_days' => ['exists' => true, 'hasValue' => true, 'value' => '90', 'marker' => 'bbbb'],
];
$asFpBase = adminSettingsSectionFingerprint('email-records', $asFpStates);
assertEquals(64, strlen($asFpBase), 'a fingerprint is 64 hexadecimal characters');
assertEquals($asFpBase, adminSettingsSectionFingerprint('email-records', $asFpStates), 'the same state gives the same fingerprint');
$asFpChanged = $asFpStates;
$asFpChanged['email_queue_retention_days']['value'] = '40';
assertTrue($asFpBase !== adminSettingsSectionFingerprint('email-records', $asFpChanged), 'a changed value changes the fingerprint');
$asFpMarker = $asFpStates;
$asFpMarker['email_queue_retention_days']['marker'] = 'cccc';
assertTrue($asFpBase !== adminSettingsSectionFingerprint('email-records', $asFpMarker), 'a changed change-marker (same value, saved again) changes the fingerprint');
$asFpOldSpelling = $asFpStates;
$asFpOldSpelling['email_queue_retention_days']['value'] = ' 030 ';
assertEquals($asFpBase, adminSettingsSectionFingerprint('email-records', $asFpOldSpelling), 'an older spelling of the same number does not change it');
assertTrue($asFpBase !== adminSettingsSectionFingerprint('prices', $asFpStates), 'the section is part of the fingerprint');
$asFpMissing = $asFpStates;
$asFpMissing['email_queue_retention_days'] = ['exists' => false, 'hasValue' => false, 'value' => null, 'marker' => ''];
assertTrue($asFpBase !== adminSettingsSectionFingerprint('email-records', $asFpMissing), 'a row that does not exist differs from one that does');
$asFpSecret = ['graph_client_secret' => ['exists' => true, 'hasValue' => true, 'value' => null, 'marker' => 'm1']];
$asFpSecretA = adminSettingsSectionFingerprint('email', $asFpSecret);
$asFpSecret['graph_client_secret']['marker'] = 'm2';
assertTrue($asFpSecretA !== adminSettingsSectionFingerprint('email', $asFpSecret), 'a secret replaced by another secret (new marker) changes the fingerprint');
$asFpSecret['graph_client_secret'] = ['exists' => true, 'hasValue' => false, 'value' => null, 'marker' => 'm2'];
assertTrue($asFpSecretA !== adminSettingsSectionFingerprint('email', $asFpSecret), 'a secret set versus not set changes the fingerprint');
// The marker comes from a hash; a stored secret's text never appears in the state or the fingerprint.
$asRowState = adminSettingsStateFromRow(['settingKey' => 'graph_client_secret', 'settingType' => 'string', 'isSensitive' => 1, 'isEditable' => 1, 'settingValue' => 'CIPHER-PROBE-xyz', 'updatedAt' => '2026-11-25 10:00:00']);
assertFalse(str_contains(json_encode($asRowState), 'CIPHER-PROBE'), 'the state kept for a secret holds neither its text nor its ciphertext');
assertEquals(24, strlen((string) $asRowState['marker']), 'the change marker is a short hash');
$asRowState2 = adminSettingsStateFromRow(['settingKey' => 'graph_client_secret', 'settingType' => 'string', 'isSensitive' => 1, 'isEditable' => 1, 'settingValue' => 'CIPHER-PROBE-xyz', 'updatedAt' => '2026-11-25 10:00:00']);
$asRowState3 = adminSettingsStateFromRow(['settingKey' => 'graph_client_secret', 'settingType' => 'string', 'isSensitive' => 1, 'isEditable' => 1, 'settingValue' => 'CIPHER-PROBE-xy2', 'updatedAt' => '2026-11-25 10:00:00']);
assertEquals($asRowState['marker'], $asRowState2['marker'], 'the same row gives the same marker');
assertTrue($asRowState['marker'] !== $asRowState3['marker'], 'a different stored value gives a different marker even within the same second');
assertContains('Someone changed these settings while you were editing. Nothing was saved. Reload the page and try again.', adminSettingsStaleMessage(), 'the stale-form message is the agreed plain English');
assertContains('name="fp"', $asPage, 'every section form carries its fingerprint');
assertContains('adminSettingsSaveSection($adminUser, $settingsSectionID, $settingsPostedFingerprint,', $asPage, 'the page hands the posted fingerprint to the save');
assertContains('hash_equals(', $asFunctions, 'the fingerprint is compared in constant time');
assertContains('FOR UPDATE', $asFunctions, 'the section rows are locked while the fingerprint is checked');

// 🔒 Rows are locked while a secret is set or replaced.
assertEquals(1, substr_count($asFunctions, '$rows = adminSettingsLoadRows([$key], true);'), 'adminSaveSetting() reads its row with a lock');
assertTrue(strpos($asFunctions, 'getDB()->begin_transaction();') < strpos($asFunctions, '$rows = adminSettingsLoadRows([$key], true);'), '... after opening its transaction');

// ⏱️ EXTRA: the emailed-link limits are editable, with the code's own clamps.
$asBounds = authLinkRequestLimitBounds();
assertEquals(['min_gap_seconds', 'daily_cap', 'ip_per_hour'], array_keys($asBounds), 'the shared bounds list the three limits');
assertEquals(['min' => 10, 'max' => 86400, 'default' => 60], $asBounds['min_gap_seconds'], 'shared bounds: the gap is 10 to 86400, default 60');
assertEquals(['min' => 1, 'max' => 50, 'default' => 5], $asBounds['daily_cap'], 'shared bounds: the daily cap is 1 to 50, default 5');
assertEquals(['min' => 1, 'max' => 1000, 'default' => 10], $asBounds['ip_per_hour'], 'shared bounds: the hourly limit is 1 to 1000, default 10');
foreach (['verification_resend', 'password_reset_request'] as $asPrefix) {
    foreach ($asBounds as $asSuffix => $asBound) {
        $asLimitKey = $asPrefix . '_' . $asSuffix;
        assertTrue(isset($asRegistry[$asLimitKey]), $asLimitKey . ' is in the settings editor');
        assertEquals('admin-security', $asRegistry[$asLimitKey]['section'], $asLimitKey . ' is in the Admin security section');
        assertEquals('int', $asRegistry[$asLimitKey]['kind'], $asLimitKey . ' is a whole number');
        assertTrue($asRegistry[$asLimitKey]['rowOptional'] === true, $asLimitKey . ' has no seeded row (created on first save)');
        assertEquals($asBound['min'], $asRegistry[$asLimitKey]['min'], $asLimitKey . ': the editor\'s lowest value is the code\'s lowest');
        assertEquals($asBound['max'], $asRegistry[$asLimitKey]['max'], $asLimitKey . ': the editor\'s highest value is the code\'s highest');
        assertTrue($asValidate($asLimitKey, (string) $asBound['min'])['ok'] && $asValidate($asLimitKey, (string) $asBound['max'])['ok'], $asLimitKey . ' accepts its lowest and highest');
        assertFalse($asValidate($asLimitKey, (string) ($asBound['min'] - 1))['ok'] || $asValidate($asLimitKey, (string) ($asBound['max'] + 1))['ok'], $asLimitKey . ' refuses one below and one above');
        assertEquals('security', $asRegistry[$asLimitKey]['group'], $asLimitKey . ' is in the "security" settings group, as the code reads it');
        assertEquals('integer', $asRegistry[$asLimitKey]['type'], $asLimitKey . ' is stored as an integer');
        assertContains('The default is ' . $asBound['default'] . '.', $asRegistry[$asLimitKey]['help'], $asLimitKey . ' help names the default');
        // What the code does with the saved number: the same range.
        $asCodeLimits = authLinkRequestLimitSettings($asPrefix, static function (string $asK, mixed $asD) use ($asLimitKey, $asBound): mixed {
            if ($asK === $asLimitKey) {
                return (string) ($asBound['max'] + 5000);
            }
            return $asD;
        });
        $asKeyMap = ['min_gap_seconds' => 'minGapSeconds', 'daily_cap' => 'dailyCap', 'ip_per_hour' => 'ipPerHour'];
        assertEquals($asBound['max'], $asCodeLimits[$asKeyMap[$asSuffix]], $asLimitKey . ': the code pulls a too-big saved number back to the same top value the editor allows');
    }
}
assertEquals(['verification_resend', 'password_reset_request'], array_values(array_unique(array_map(static function (string $asK): string {
    return (string) preg_replace('/_(min_gap_seconds|daily_cap|ip_per_hour)$/', '', $asK);
}, array_filter(array_keys($asRegistry), static function (string $asK): bool {
    return (bool) preg_match('/_(min_gap_seconds|daily_cap|ip_per_hour)$/', $asK);
})))), 'exactly the two forms\' limits are in the editor');

// ==============================================================================
// ✅ Section 10: Website address - wording guard and warning logic
// ==============================================================================
// 📝 Wording guard: alpha, beta and live all share ONE database and so ONE
//    site_url. No file may again tell people a test site "must use its own address".
$asRepoRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;
foreach ([
    'web/CueRCode.net/_functions/fn_admin_settings.php',
    'docs/INSTALL.md',
    'CHANGELOG.md',
    'DEV_NOTES.md',
    'web/CueRCode.net/public_html/admin/settings/index.php',
] as $asGuardFile) {
    $asGuardText = (string) file_get_contents($asRepoRoot . $asGuardFile);
    assertFalse(stripos($asGuardText, 'must use its own address') !== false, 'wording guard: ' . $asGuardFile . ' does not say a test site must use its own address');
}
assertContains('shares this one value', $asFunctions, 'wording guard: the section help says copies sharing a database share one value');
assertContains('a trailing slash is removed for you', $asFunctions, 'wording guard: the help says a trailing slash is removed for you');
assertContains('register the new callback addresses', $asFunctions, 'the help warns to register new sign-in callback addresses first');

// ⚠️ Warning logic (stand-in validator-free paths first, then the real validator).
assertTrue(emailQueueSiteAddressWarning('') !== null, 'site address warning: empty -> warns');
assertTrue(emailQueueSiteAddressWarning(null) !== null, 'site address warning: not a string -> warns');
assertContains('does not start with https://', (string) emailQueueSiteAddressWarning('http://www.example.com'), 'site address warning: http -> plain-English warning');
assertTrue(emailQueueSiteAddressWarning('https://localhost') !== null, 'site address warning: localhost fails the validator -> warns');
assertTrue(emailQueueSiteAddressWarning('https://www.example.com/path') !== null, 'site address warning: a path -> warns');
assertContains('tidy form', (string) emailQueueSiteAddressWarning('https://www.example.com/'), 'site address warning: trailing slash -> tidy-form warning');
assertEquals(null, emailQueueSiteAddressWarning('https://www.example.com'), 'site address warning: a good address -> no warning');
$asOwnValidator = static function (string $asText): array {
    return ['ok' => false, 'value' => '', 'error' => 'stand-in refusal'];
};
assertContains('stand-in refusal', (string) emailQueueSiteAddressWarning('https://www.example.com', $asOwnValidator), 'site address warning: the validator reason is included');

// The runner's settings check carries the warning, and does NOT change its state or problem list.
$asRunnerGood = checkEmailQueueRunnerSettings(static function (string $asK, mixed $asD): mixed {
    return $asK === 'site_url' ? 'https://www.example.com' : $asD;
});
assertEquals(null, $asRunnerGood['siteAddressWarning'], 'runner check: good site_url -> no warning');
$asRunnerBad = checkEmailQueueRunnerSettings(static function (string $asK, mixed $asD): mixed {
    return $asK === 'site_url' ? 'http://x' : $asD;
});
assertTrue(is_string($asRunnerBad['siteAddressWarning']), 'runner check: bad site_url -> warning text');
assertEquals('disabled', $asRunnerBad['state'], 'runner check: a bad site_url does not change the state');
$asRunnerSource = (string) file_get_contents($asSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_email.php');
assertContains("'Website address: WARNING - '", $asRunnerSource, 'the --check output prints a website address warning line');
$asOverview = (string) file_get_contents($asSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'index.php');
assertContains('emailQueueSiteAddressWarning(', $asOverview, 'the admin overview shows the website address warning');
