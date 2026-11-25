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
    // 🧹 Review fix: MariaDB prefix WITHOUT the word MariaDB is still MariaDB.
    ['5.5.5-10.6.12',                true,  'mariadb', '10.6.12'],
    ['5.5.5-10.5.23',                false, 'mariadb', '10.5.23'],
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

// ☁️ Review fix: Amazon Aurora reports a MySQL-looking number but is unsupported, with its own message.
$aurora = installCheckDatabaseVersion('8.0.mysql_aurora.3.04.0');
assertEquals(false, $aurora['ok'], 'Aurora is refused');
assertEquals('unsupported', $aurora['flavour'], 'Aurora flavour is unsupported');
assertTrue(str_contains($aurora['message'], 'Aurora') && str_contains($aurora['message'], 'does not support'), 'Aurora message says plainly that it is not supported');
assertTrue(str_contains(installCheckDatabaseVersion('banana')['message'], 'unsupported'), 'unknown strings are said to be treated as unsupported');

// 🚦 M7: the server-side prerequisite re-check (the POST handler uses it, so a hand-made form post is refused too).
$allExtensions = array_keys(installRequiredExtensions());
$goodFolders   = [['ok' => true, 'message' => 'fine'], ['ok' => true, 'message' => 'fine']];
assertEquals([], installPreflightProblems('8.4.0', $allExtensions, $goodFolders), 'M7: nothing missing -> no problems');
$withoutGd = array_values(array_diff($allExtensions, ['gd']));
$problems  = installPreflightProblems('8.5.1', $withoutGd, $goodFolders);
assertEquals(1, count($problems), 'M7: one missing extension -> one problem');
assertTrue(str_contains($problems[0], '"gd"'), 'M7: ...and it names the extension in the same plain words as the page');
$problems = installPreflightProblems('8.5.1', $allExtensions, [['ok' => false, 'message' => 'The folder "_uploads" exists but the website is not allowed to write to it.'], $goodFolders[0]]);
assertEquals(['The folder "_uploads" exists but the website is not allowed to write to it.'], $problems, 'M7: an unwritable folder is refused with the page\'s own message');
assertEquals(1, count(installPreflightProblems('8.3.9', $allExtensions, $goodFolders)), 'M7: PHP older than 8.4 is refused');
assertEquals(3, count(installPreflightProblems('8.4.0', array_values(array_diff($allExtensions, ['gd', 'zip'])), [['ok' => false, 'message' => 'x'], $goodFolders[0]])), 'M7: every problem is listed');
$installerSource = (string) file_get_contents(dirname(__DIR__) . '/web/CueRCode.net/public_html/install/index.php');
assertTrue(str_contains($installerSource, 'installPreflightProblems(PHP_VERSION, get_loaded_extensions(), $postFolderChecks)'), 'M7: the POST handler itself calls the prerequisite check');
assertTrue(strpos($installerSource, 'installPreflightProblems(PHP_VERSION') < strpos($installerSource, 'installOpenDatabase(DB_HOST'), 'M7: ...before the database is touched');

// 🔒 M8: the lock lives outside install/, and the old place still counts.
$lockBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_lock_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
mkdir($lockBase . '_auth_keys', 0700, true);
mkdir($lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install', 0700, true);
$lockPaths = installLockFilePaths($lockBase, $lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR);
assertTrue(str_contains($lockPaths['primary'], '_auth_keys') && !str_contains($lockPaths['primary'], DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR), 'M8: the main lock is in _auth_keys, not in install/');
assertTrue(str_ends_with($lockPaths['legacy'], 'install' . DIRECTORY_SEPARATOR . '.installed.lock'), 'M8: the old lock place is still known');
assertFalse(installIsLocked($lockPaths), 'M8: no lock file -> not locked');
file_put_contents($lockPaths['legacy'], 'x');
assertTrue(installIsLocked($lockPaths), 'M8: an old install/.installed.lock still locks');
unlink($lockPaths['legacy']);
file_put_contents($lockPaths['primary'], 'x');
assertTrue(installIsLocked($lockPaths), 'M8: the _auth_keys lock locks');
// 🗑️ The point of the fix: deleting install/ (as the README once advised) does not unlock.
rmdir($lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install');
assertTrue(installIsLocked($lockPaths), 'M8: with install/ deleted, the lock still holds');
unlink($lockPaths['primary']);

// 🔒 Review finding 9: the installer refuses to start if it could not write its lock afterwards.
$lockCheck = installLockFolderCheck($lockPaths);
assertTrue($lockCheck['ok'] && str_contains($lockCheck['message'], '_auth_keys'), 'F9: _auth_keys writable -> fine, the lock goes there');
rmdir($lockBase . '_auth_keys');
mkdir($lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install', 0700, true);
$lockCheck = installLockFolderCheck($lockPaths);
assertTrue($lockCheck['ok'] && str_contains($lockCheck['message'], 'only inside install/'), 'F9: no _auth_keys but install/ writable -> fine, with the "keep install/" note');
chmod($lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install', 0500);
$lockCheck = installLockFolderCheck($lockPaths);
assertFalse($lockCheck['ok'], 'F9: neither folder writable -> refused');
assertContains('cannot write its "already installed" lock file', $lockCheck['message'], 'F9: ...with a plain message saying why');
assertEquals([$lockCheck['message']], installPreflightProblems('8.5.1', $allExtensions, [$lockCheck]), 'F9: ...and it is one of the problems that stop the install before anything is changed');
chmod($lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install', 0700);
rmdir($lockBase . 'public_html' . DIRECTORY_SEPARATOR . 'install');
rmdir($lockBase . 'public_html');
rmdir($lockBase);
assertContains('$postFolderChecks[] = installLockFolderCheck($lockPaths);', $installerSource, 'F9: the POST handler adds the lock-folder check to the prerequisites it re-checks');
assertTrue(strpos($installerSource, 'installLockFolderCheck($lockPaths)') < strpos($installerSource, 'installOpenDatabase(DB_HOST'), 'F9: ...before the database is touched');
assertContains("'name' => 'Folder for the install lock (_auth_keys, or install as a fallback)'", $installerSource, 'F9: the page shows it in the prerequisites list (so the Install button is hidden too)');
assertFalse(str_contains($installerSource, "'Could not create lock file. Please manually restrict access to /install/'"), 'F9: the old "finish with a warning" message is gone');
assertContains("'message' => 'The database is installed, but the installer could not write its lock file, so it is NOT switched off. '", $installerSource, 'F9: an unexpected lock failure afterwards is an ERROR saying what to do');
$gitignore = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.gitignore');
assertContains("\n**/_auth_keys/.installed.lock\n", $gitignore, 'F9: .gitignore keeps the lock file out of the repository');

// ==============================================================================
// 🔌 Independent review, Codex C1 finding 5 (2026-11-25): a failed database
//    connection must give the plain message, not crash the page.
// ==============================================================================
// Reproduction on THIS PHP: "@" does not stop the exception PHP 8.1+ throws
// (measured on 8.4.27 and 8.5.12). Port 1 on this machine refuses at once.
assertThrows(
    static function (): void {
        $unused = @new \mysqli('127.0.0.1', 'nobody', 'wrong', 'nodb', 1);
        unset($unused);
    },
    'C1-5 reproduction: @new mysqli() still throws when the connection fails',
    \mysqli_sql_exception::class
);
$openFailed = installOpenDatabase('127.0.0.1', 'nobody', 'wrong', 'nodb', 1);
assertFalse($openFailed['ok'], 'C1-5: installOpenDatabase() reports a failed connection as ok = false');
assertTrue($openFailed['db'] === null, 'C1-5: ...with no connection object');
assertTrue($openFailed['error'] !== '', 'C1-5: ...and the technical detail kept for the server log');
assertFalse((bool) preg_match('/^(?!\s*(\/\/|\*|#)).*@new \\\\mysqli\(/m', $installerSource), 'C1-5: no code line of the installer uses @new mysqli() any more (comments may still mention it)');
assertContains('installOpenDatabase(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) $dbPort)', $installerSource, 'C1-5: the installer connects through installOpenDatabase()');
assertContains('set_exception_handler(', $installerSource, 'C1-5: anything unexpected after connecting shows a plain page, not raw database text');

// ==============================================================================
// 🔒 Codex C1 findings 1-3: the installer's order of work (the database
//    behaviour itself is proven on real MySQL/MariaDB in
//    tests/integration/migrations_real_mysql.php Scenario L).
// ==============================================================================
$posLock      = strpos($installerSource, 'migrationDbAcquireInstallerLock($mysqli)');
$posRecheck   = strpos($installerSource, '} elseif (installIsLocked($lockPaths)) {');
$posInstall   = strpos($installerSource, 'migrationDbRunInstallSql($mysqli, $installSQL)');
$posSeedState = strpos($installerSource, '$seedState = migrationDbSeedDataState($mysqli);');
$posSeedRun   = strpos($installerSource, 'migrationDbRunSeedSql($mysqli, $seedSQL)');
$posFinal     = strpos($installerSource, '$finalSeedState = migrationDbSeedDataState($mysqli);');
$posLockFile  = strpos($installerSource, '@file_put_contents($lockFile, $lockContent)');
assertTrue($posLock !== false && $posInstall !== false && $posLock < $posInstall, 'C1-2: the installer takes its database lock before creating any table');
assertTrue($posRecheck !== false && $posLock < $posRecheck && $posRecheck < $posInstall, 'C1-2: ...and checks the lock FILE again once it has the lock');
assertTrue($posSeedState !== false && $posSeedRun !== false && $posSeedState < $posSeedRun, 'C1-1: it asks how much starting data is there before loading any');
assertFalse(str_contains($installerSource, '$mysqli->multi_query($seedSQL)'), 'C1-1: the starting data is no longer loaded with a bare multi_query() (it goes through the all-or-nothing loader)');
assertTrue($posFinal !== false && $posLockFile !== false && $posFinal < $posLockFile, 'C1-1: the starting data is checked complete before the lock file is written');
assertMatches("/\\\$seedState\\['state'\\] === 'partial'\\) \\{.*?\\\$seedStepFailed = true;/s", $installerSource, 'C1-1: a PART-loaded database stops the install (no lock)');
assertContains('delete the seed_data_loaded row from the', $installerSource, 'decision 0018: the skip message tells the owner how to force a clean re-seed (remove the seed_data_loaded setting first)');
assertMatches("/\\\$seedState\\['marker'\\] === true\\) \\{\\s*\\\$results\\[\\] = \\[\\s*'status' => 'info'/s", $installerSource, 'decision 0018: a present marker is reported as an information message, not an error');
assertMatches("/elseif \\(!file_exists\\(\\\$seedSQLPath\\)\\) \\{\\s*\\\$results\\[\\] = \\[\\s*'status' => 'error',.*?\\\$seedStepFailed = true;/s", $installerSource, 'C1-3: a missing seed file is an error that stops the install (no lock)');

