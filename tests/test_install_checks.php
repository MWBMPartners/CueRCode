<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the installer readiness checks (#113 B2, B7)
 * ==============================================================================
 *
 * Path: tests/test_install_checks.php
 *
 * Covers _functions/fn_install_checks.php: the PHP extension list (decision
 * 0012), the database version rule (MySQL 8.0.13+ / MariaDB 10.6+) and the
 * writable-folder check for _uploads and _backups.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #113
 */

require_once dirname(__DIR__) . '/web/CueRCode.net/_functions/fn_install_checks.php';

// 🧩 Extension list: exactly the eight agreed, each with a plain-English purpose.
$required = installRequiredExtensions();
assertEquals(
    ['mysqli', 'mbstring', 'gd', 'json', 'openssl', 'zip', 'bcmath', 'curl'],
    array_keys($required),
    'required extension list matches decision 0012'
);
foreach ($required as $extName => $extPurpose) {
    assertTrue($extPurpose !== '', 'extension ' . $extName . ' has a purpose sentence');
}

$allLoaded = installExtensionChecks(array_keys($required));
$failing   = array_filter($allLoaded, static fn (array $row): bool => !$row['ok']);
assertEquals(0, count($failing), 'every extension passes when all are loaded');

$missing = installExtensionChecks(['mysqli', 'MBSTRING', 'gd', 'json', 'openssl', 'curl']);
$missingNames = [];
foreach ($missing as $row) {
    if (!$row['ok']) {
        $missingNames[] = $row['name'];
    }
}
assertEquals(['zip', 'bcmath'], $missingNames, 'missing zip and bcmath are reported (case-insensitive match)');

// 🗄️ Database version rule.
$cases = [
    ['8.0.36',                       true,  'mysql',   '8.0.36'],
    ['8.0.13',                       true,  'mysql',   '8.0.13'],
    ['8.0.12',                       false, 'mysql',   '8.0.12'],
    ['8.4.13-cloud',                 true,  'mysql',   '8.4.13'],
    ['5.7.44',                       false, 'mysql',   '5.7.44'],
    ['11.4.13-MariaDB',              true,  'mariadb', '11.4.13'],
    ['5.5.5-10.6.12-MariaDB-log',    true,  'mariadb', '10.6.12'],
    ['10.6.0-MariaDB',               true,  'mariadb', '10.6.0'],
    ['5.5.5-10.5.23-MariaDB',        false, 'mariadb', '10.5.23'],
    ['10.3.39-MariaDB-1:10.3.39',    false, 'mariadb', '10.3.39'],
];
foreach ($cases as [$info, $expectOk, $expectFlavour, $expectVersion]) {
    $check = installCheckDatabaseVersion($info);
    assertEquals($expectOk, $check['ok'], 'database "' . $info . '" ok=' . var_export($expectOk, true));
    assertEquals($expectFlavour, $check['flavour'], 'database "' . $info . '" flavour');
    assertEquals($expectVersion, $check['version'], 'database "' . $info . '" version');
}

$tooOld = installCheckDatabaseVersion('5.7.44');
assertTrue(str_contains($tooOld['message'], '8.0.13') && str_contains($tooOld['message'], '10.6'), 'too-old message names both minimums');

$unknown = installCheckDatabaseVersion('banana');
assertEquals(false, $unknown['ok'], 'unreadable version is refused');
assertEquals('unknown', $unknown['flavour'], 'unreadable version flavour is unknown');
assertEquals(false, installCheckDatabaseVersion('')['ok'], 'empty version is refused');

// 📁 Writable folder check, using real throwaway folders.
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_install_' . bin2hex(random_bytes(4));
mkdir($base, 0700, true);

$ok = installCheckWritableFolder($base, '_uploads', 'logos');
assertEquals(true, $ok['ok'], 'existing writable folder passes');

$gone = installCheckWritableFolder($base . DIRECTORY_SEPARATOR . 'nope', '_backups', 'backups');
assertEquals(false, $gone['ok'], 'missing folder fails');
assertTrue(str_contains($gone['message'], '_backups') && str_contains($gone['message'], 'does not exist'), 'missing folder message is plain');

// A read-only folder only fails for a normal user (root can write anywhere).
$readOnly = $base . DIRECTORY_SEPARATOR . 'ro';
mkdir($readOnly, 0500);
if (is_writable($readOnly)) {
    assertTrue(true, 'read-only folder test skipped: running with rights that ignore permissions');
} else {
    $ro = installCheckWritableFolder($readOnly, '_uploads', 'logos');
    assertEquals(false, $ro['ok'], 'read-only folder fails');
    assertTrue(str_contains($ro['message'], 'not allowed to write'), 'read-only message is plain');
}

chmod($readOnly, 0700);
rmdir($readOnly);
rmdir($base);
