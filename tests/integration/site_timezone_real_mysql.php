<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check: expiry is right in every site time zone
 * ==============================================================================
 *
 * Path: tests/integration/site_timezone_real_mysql.php
 *
 * The database writes every expiry time in UTC (the connection is set to
 * '+00:00'). PHP's own clock follows the `site_timezone` setting. Issues #158
 * and #130: PHP read the database's UTC text as local time, so with any zone
 * other than UTC links, sessions, lock-outs and API keys ended hours early or
 * late. This script runs the REAL functions against a REAL database in several
 * zones and checks each limit half an hour before and half an hour after it.
 *
 *   A  an e-mail verification link: usable while it has 30 minutes left,
 *      refused 30 minutes after it ended (including the early check that
 *      stops a wrong password on a dead link being counted)
 *   B  a password-reset link: the same two cases
 *   C  a signed-in session: currentUser() and the locked re-check inside a
 *      transaction
 *   D  a lock-out: still locked with 30 minutes to go, open 30 minutes after;
 *      and the lock the site itself writes lasts the configured time
 *   E  an API key with an expiry date, and one with none
 *
 * Not run by tests/run.php (needs a database). CI syntax-checks it.
 *
 * ⚠️ SAFETY: it DELETES rows. It refuses to run (exit 2) unless the database
 *    name starts with "cuercode_it_". It never sends email and uses reserved
 *    ".invalid" addresses. Connection details come only from the
 *    CUERCODE_IT_DB_* environment variables; it never reads auth_creds.php.
 *
 * Run (see tests/README.md for the full Docker commands):
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33158 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p158 CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/site_timezone_real_mysql.php --load-schema
 *
 * Exit codes: 0 all passed | 1 a check failed or crashed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#158, #130)
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// 🎫 A PHP session for this process, started before any output, so that
//    currentUser() can read the session token as it does on a web page.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(sys_get_temp_dir());
session_start();

$tzSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$tzLoad     = in_array('--load-schema', array_slice($argv, 1), true);

$tzEnv = [];
foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $tzKey) {
    $tzValue = getenv('CUERCODE_IT_DB_' . $tzKey);
    $tzEnv[$tzKey] = ($tzValue === false) ? '' : $tzValue;
}

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $tzEnv['NAME']) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_".' . PHP_EOL);
    exit(2);
}
if ($tzEnv['HOST'] === '' || $tzEnv['USER'] === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}
if ($tzEnv['PORT'] === '') {
    $tzEnv['PORT'] = '3306';
}
$tzPort = (int) $tzEnv['PORT'];

// ⏳ Wait for the server (a database container restarts once while starting).
$tzDeadline = microtime(true) + 120;
$tzConn     = null;
$tzLastErr  = '';
while ($tzConn === null && microtime(true) < $tzDeadline) {
    try {
        $tzConn = @new \mysqli($tzEnv['HOST'], $tzEnv['USER'], $tzEnv['PASS'], '', $tzPort);
    } catch (\Throwable $tzError) {
        $tzLastErr = $tzError->getMessage();
        usleep(500000);
    }
}
if ($tzConn === null) {
    fwrite(STDERR, 'Could not connect within 120 seconds: ' . $tzLastErr . PHP_EOL);
    exit(1);
}
echo 'Server: ' . $tzConn->server_info . PHP_EOL;

$tzConn->query('CREATE DATABASE IF NOT EXISTS `' . $tzEnv['NAME'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$tzConn->select_db($tzEnv['NAME']);

$tzHas = $tzConn->query("SELECT COUNT(*) AS `c` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblPasswordResets'");
if ((int) $tzHas->fetch_assoc()['c'] !== 1) {
    if (!$tzLoad) {
        fwrite(STDERR, 'The tables do not exist. Re-run with --load-schema.' . PHP_EOL);
        exit(2);
    }
    foreach (['install.sql', 'seed_data.sql'] as $tzSqlFile) {
        $tzSql = file_get_contents($tzSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $tzSqlFile);
        $tzConn->multi_query((string) $tzSql);
        do {
            $tzStored = $tzConn->store_result();
            if ($tzStored instanceof \mysqli_result) {
                $tzStored->free();
            }
        } while ($tzConn->more_results() && $tzConn->next_result());
        echo 'Loaded ' . $tzSqlFile . '.' . PHP_EOL;
    }
}

define('DB_HOST', $tzEnv['HOST']);
define('DB_PORT', $tzPort);
define('DB_NAME', $tzEnv['NAME']);
define('DB_USER', $tzEnv['USER']);
define('DB_PASS', $tzEnv['PASS']);
define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));

require_once $tzSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $tzSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_auth.php';

restore_error_handler();
ini_set('display_errors', 'stderr');
set_error_handler(static function (int $tzErrorNumber, string $tzErrorText): bool {
    return str_contains($tzErrorText, 'Session ID cannot be regenerated after headers have already been sent');
}, E_WARNING);
set_exception_handler(static function (\Throwable $tzError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($tzError) . ': ' . $tzError->getMessage() . PHP_EOL);
    exit(1);
});

// 🏗️ Sign-in protections need database upgrade 0003 (and the rest).
require_once $tzSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
$tzUpgrade = migrationApplyPending(getDB(), 'cli:integration', false);
authSessionsValidFromReady(true);
if (!authSessionsValidFromReady()) {
    fwrite(STDERR, 'The database upgrades could not be applied (' . $tzUpgrade['status'] . '): ' . $tzUpgrade['error'] . PHP_EOL);
    exit(1);
}

$_SERVER['REMOTE_ADDR']     = '203.0.113.58';
$_SERVER['HTTP_USER_AGENT'] = 'integration-check';

$tzFailures   = 0;
$tzCheckCount = 0;

function tzCheck(bool $tzPassed, string $tzWhat): void
{
    $GLOBALS['tzCheckCount']++;
    if (!$tzPassed) {
        $GLOBALS['tzFailures']++;
    }
    echo ($tzPassed ? '    ok   ' : '    FAIL ') . $tzWhat . PHP_EOL;
}

function tzOne(string $tzSql, string $tzTypes = '', array $tzParams = []): ?array
{
    $tzStmt = dbQuery($tzSql, $tzTypes, $tzParams);
    $tzRow  = $tzStmt->get_result()->fetch_assoc();
    $tzStmt->close();
    return $tzRow;
}

function tzExec(string $tzSql, string $tzTypes = '', array $tzParams = []): void
{
    $tzStmt = dbQuery($tzSql, $tzTypes, $tzParams);
    $tzStmt->close();
}

/** 🧹 Wipe everything this script makes (the database is throwaway). */
function tzReset(): void
{
    foreach (['tblEmailQueue', 'tblEmailVerifications', 'tblPasswordResets', 'tblUserSessions', 'tblActivityLog'] as $tzTable) {
        tzExec('DELETE FROM `' . $tzTable . '`');
    }
    tzExec("DELETE FROM `tblAPIKeys` WHERE `apiKeyName` LIKE 'tz-%'");
    tzExec("DELETE FROM `tblUsers` WHERE `email` LIKE 'tz-%@tz.cuercode.invalid'");
}

/** 👤 Make an account and return its number. */
function tzUser(string $tzName, string $tzStatus, ?string $tzPassword): int
{
    $tzEmail = 'tz-' . $tzName . '@tz.cuercode.invalid';
    $tzHash  = null;
    if ($tzPassword !== null) {
        $tzHash = hashPassword($tzPassword);
    }
    tzExec('INSERT INTO `tblUsers` (`email`, `passwordHash`, `status`) VALUES (?, ?, ?)', 'sss', [$tzEmail, $tzHash, $tzStatus]);
    return (int) tzOne('SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$tzEmail])['userID'];
}

/** 🎫 A link whose expiry is $tzMinutes from now on the DATABASE clock (may be negative). */
function tzLink(string $tzTable, int $tzUserID, int $tzMinutes): string
{
    $tzRaw = bin2hex(random_bytes(32));
    tzExec(
        'INSERT INTO `' . $tzTable . '` (`userID`, `tokenHash`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        'isi',
        [$tzUserID, hashToken($tzRaw), $tzMinutes]
    );
    return $tzRaw;
}

/** 🍪 A session row ending $tzMinutes from now; this "browser" holds its token. */
function tzSession(int $tzUserID, int $tzMinutes): string
{
    $tzID = bin2hex(random_bytes(32));
    tzExec(
        'INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        'sii',
        [$tzID, $tzUserID, $tzMinutes]
    );
    $_SESSION[CUERCODE_SESSION_TOKEN_KEY] = $tzID;
    return $tzID;
}

$tzPassword = 'Correct-Horse-Battery-9!';
$tzZones    = ['UTC', 'America/New_York', 'Europe/London', 'Pacific/Kiritimati', 'Pacific/Pago_Pago'];

foreach ($tzZones as $tzIndex => $tzZone) {
    // 🌍 What bootstrap.php does with the site_timezone setting.
    date_default_timezone_set($tzZone);
    tzReset();
    echo PHP_EOL . 'Site time zone: ' . $tzZone . PHP_EOL;
    $tzTag = 'z' . $tzIndex;

    // 🅰️ Verification links.
    $tzUser = tzUser($tzTag . '-va', 'pending', null);
    $tzRaw  = tzLink('tblEmailVerifications', $tzUser, 30);
    tzCheck(emailVerificationTokenNeedsPassword($tzRaw) === false, 'A: a live link (30 minutes left) is seen as live by the early check');
    $tzResult = verifyEmailToken($tzRaw);
    tzCheck($tzResult['success'] === true, 'A: a live verification link works');

    $tzUser = tzUser($tzTag . '-vb', 'pending', $tzPassword);
    $tzRaw  = tzLink('tblEmailVerifications', $tzUser, -30);
    $tzResult = verifyEmailToken($tzRaw, 'not-the-password');
    tzCheck($tzResult['success'] === false && $tzResult['errorCode'] !== 'RESET_TOKEN_ALREADY_USED' && $tzResult['httpCode'] === 400, 'A: a link that ended 30 minutes ago is refused as invalid');
    tzCheck((int) tzOne('SELECT `failedLoginCount` FROM `tblUsers` WHERE `userID` = ?', 'i', [$tzUser])['failedLoginCount'] === 0, 'A: ...before the password is looked at (no failed attempt counted)');
    $tzUser = tzUser($tzTag . '-vc', 'pending', null);
    tzCheck(emailVerificationTokenNeedsPassword(tzLink('tblEmailVerifications', $tzUser, -30)) === true, 'A: the early check treats an ended link as dead');

    // 🅱️ Password-reset links.
    $tzUser   = tzUser($tzTag . '-ra', 'active', $tzPassword);
    $tzResult = resetPasswordWithToken(tzLink('tblPasswordResets', $tzUser, 30), 'Another-Strong-Passw0rd!');
    tzCheck($tzResult['success'] === true, 'B: a live reset link (30 minutes left) works');
    $tzUser   = tzUser($tzTag . '-rb', 'active', $tzPassword);
    $tzResult = resetPasswordWithToken(tzLink('tblPasswordResets', $tzUser, -30), 'Another-Strong-Passw0rd!');
    tzCheck($tzResult['success'] === false && $tzResult['errorCode'] === 'RESET_TOKEN_INVALID', 'B: a reset link that ended 30 minutes ago is refused');

    // 🅲 Sessions.
    $tzUser = tzUser($tzTag . '-sa', 'active', $tzPassword);
    $tzID   = tzSession($tzUser, 30);
    $tzWho  = currentUser();
    tzCheck(is_array($tzWho) && (int) $tzWho['userID'] === $tzUser, 'C: a session with 30 minutes left is signed in');
    getDB()->begin_transaction();
    $tzLive = authLockLiveSessionForUser($tzUser, $tzID);
    getDB()->rollback();
    tzCheck($tzLive === true, 'C: ...and passes the locked re-check');
    $tzID = tzSession($tzUser, -30);
    tzCheck(currentUser() === null, 'C: a session that ended 30 minutes ago is signed out');
    getDB()->begin_transaction();
    $tzLive = authLockLiveSessionForUser($tzUser, $tzID);
    getDB()->rollback();
    tzCheck($tzLive === false, 'C: ...and fails the locked re-check');
    unset($_SESSION[CUERCODE_SESSION_TOKEN_KEY]);

    // 🅳 Lock-outs.
    $tzUser = tzUser($tzTag . '-la', 'active', $tzPassword);
    $tzMail = 'tz-' . $tzTag . '-la@tz.cuercode.invalid';
    tzExec('UPDATE `tblUsers` SET `lockedUntil` = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE `userID` = ?', 'i', [$tzUser]);
    tzCheck(loginUserCheckPassword($tzMail, $tzPassword)['ok'] === false, 'D: an account locked for 30 more minutes is refused with the right password');
    tzExec('UPDATE `tblUsers` SET `lockedUntil` = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE `userID` = ?', 'i', [$tzUser]);
    tzCheck(loginUserCheckPassword($tzMail, $tzPassword)['ok'] === true, 'D: a lock that ended 30 minutes ago no longer refuses');

    $tzUser = tzUser($tzTag . '-lb', 'pending', $tzPassword);
    $tzRaw  = tzLink('tblEmailVerifications', $tzUser, 30);
    tzExec('UPDATE `tblUsers` SET `lockedUntil` = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE `userID` = ?', 'i', [$tzUser]);
    tzCheck(verifyEmailToken($tzRaw, $tzPassword)['success'] === false, 'D: a locked account cannot verify, even with the right password');
    tzExec('UPDATE `tblUsers` SET `lockedUntil` = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE `userID` = ?', 'i', [$tzUser]);
    tzCheck(verifyEmailToken($tzRaw, $tzPassword)['success'] === true, 'D: ...and can once the lock has ended');

    $tzUser = tzUser($tzTag . '-lc', 'active', $tzPassword);
    $tzMail = 'tz-' . $tzTag . '-lc@tz.cuercode.invalid';
    for ($tzTry = 0; $tzTry < 5; $tzTry++) {
        recordFailedLogin($tzUser);
    }
    $tzLeft = (int) tzOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), `lockedUntil`) AS `s` FROM `tblUsers` WHERE `userID` = ?', 'i', [$tzUser])['s'];
    $tzWant = (int) getConfig('lockout_duration', 900);
    tzCheck($tzLeft > $tzWant - 60 && $tzLeft <= $tzWant, 'D: the lock the site writes lasts the configured time on the database clock (' . $tzLeft . ' of ' . $tzWant . ' seconds)');
    tzCheck(loginUserCheckPassword($tzMail, $tzPassword)['ok'] === false, 'D: ...and refuses at once');

    // 🅴 API keys.
    foreach ([['live', 30, true], ['ended', -30, false], ['none', null, true]] as $tzCase) {
        $tzKeyText = 'cuercode_' . bin2hex(random_bytes(20));
        $tzUser    = tzUser($tzTag . '-k' . $tzCase[0], 'active', $tzPassword);
        tzExec(
            'INSERT INTO `tblAPIKeys` (`apiKeyHash`, `apiKeyPrefix`, `apiKeyName`, `userID`, `isActive`, `expiresAt`) '
            . 'VALUES (?, ?, ?, ?, 1, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            'sssii',
            [hashAPIKey($tzKeyText), 'cuercode_tz', 'tz-' . $tzTag . $tzCase[0], $tzUser, $tzCase[1]]
        );
        tzCheck(validateAPIKey($tzKeyText)['valid'] === $tzCase[2], 'E: API key (' . $tzCase[0] . ') is ' . ($tzCase[2] ? 'accepted' : 'refused'));
    }
}

date_default_timezone_set('UTC');
tzReset();

echo PHP_EOL . $tzCheckCount . ' checks, ' . $tzFailures . ' failed.' . PHP_EOL;
exit($tzFailures === 0 ? 0 : 1);
