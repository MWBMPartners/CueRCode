<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - OPTIONAL real-database check for decisions 0013 and 0014
 * ==============================================================================
 *
 * Path: tests/integration/account_recovery_real_mysql.php
 *
 * Proves, against a real MySQL/MariaDB server, what tests/test_account_recovery.php
 * and tests/test_client_ip.php cannot:
 *
 *   Decision 0013 (the "someone signed up first with my email" trick):
 *     A  a verification link needs the account's password: nothing typed, a
 *        wrong password and the right one; the link is used up only on success
 *     B  wrong passwords count toward the lock-out; the one that reaches the
 *        limit stops the link; a locked account is refused even with the
 *        right password; the activity row names no address
 *     C  an account with no password (Microsoft/Google only) is activated by
 *        the link alone; emailVerificationTokenNeedsPassword() answers right
 *     D  "Forgot password" now sends a link to a WAITING account; using it
 *        activates the account, sets the new password, and - in one
 *        transaction - ends sessions, switches off API keys, removes linked
 *        sign-ins and passkeys, cancels other unused reset and verification
 *        links, and writes a counts-only activity row; another account is
 *        untouched
 *     E  the same on an ACTIVE account (not "activated", email date kept)
 *     F  ONE transaction: a forced failure part-way changes NOTHING and the
 *        link still works afterwards; a failed activity-log write does NOT
 *        block a reset (deliberate - see DEV_NOTES)
 *     G  refusals: a waiting Global Admin, a suspended account, a used link
 *
 *   Decision 0014 (the visitor's address):
 *     H  with a REAL saved trusted_proxy_ips setting (a range), getClientIP()
 *        reads X-Forwarded-For from the right, ignores it from anyone else,
 *        trusts CF-Connecting-IP only from a Cloudflare range, and the
 *        activity log records the same address
 *
 *   Independent review of 0013/0014 (2026-11-25):
 *     P  before database upgrade 0003 sign-in still works (the old way);
 *        the upgrade is applied and the protection switches on
 *     R  finding 1 - a sign-in that read the OLD password before a reset
 *        never ends with a working session: (a) TWO real processes, the
 *        sign-in paused inside the database (a trigger) while the reset
 *        runs; (b) the same steps called one by one in the bad order; the
 *        reset's own follow-up sign-in, at once, works (the same-second
 *        boundary); two marks in one instant differ; Google/Microsoft
 *        sign-in in the same gap is refused too
 *     L  finding 2 - a Google/Microsoft "link" request started before a reset
 *        is refused afterwards (not signed in; signed in again but the mark
 *        moved; the locked re-check refuses to write the link), and an API
 *        key cannot be made with a session the reset ended
 *     D2 finding 5 - two resets of one account at the same moment (two real
 *        processes, five rounds): one wins, the other is told its link was
 *        used, and there is no deadlock
 *     W  finding 7 - eight wrong passwords at the same moment (eight
 *        processes) all count
 *     V  nit - a verification link for an already-active account says
 *        nothing changed; for a suspended one, the usual invalid answer
 *
 * Not run by tests/run.php (needs a database). CI syntax-checks it.
 *
 * ⚠️ SAFETY: it DELETES rows. It refuses to run (exit 2) unless the database
 *    name starts with "cuercode_it_". It never sends email and uses reserved
 *    ".invalid" addresses. Connection details come only from the
 *    CUERCODE_IT_DB_* environment variables; it never reads auth_creds.php.
 *
 * Run (see tests/README.md for the full Docker commands):
 *     CUERCODE_IT_DB_HOST=127.0.0.1 CUERCODE_IT_DB_PORT=33131 \
 *     CUERCODE_IT_DB_NAME=cuercode_it_p131 CUERCODE_IT_DB_USER=root CUERCODE_IT_DB_PASS= \
 *       php tests/integration/account_recovery_real_mysql.php --load-schema
 *
 * Exit codes: 0 all passed | 1 a check failed or crashed | 2 refused.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decisions 0013 and 0014, #31 / #128)
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}

// 🎫 A PHP session for this command-line process, started BEFORE any output
//    (PHP refuses to start one afterwards), so currentUser() and
//    issueUserSession() can be used exactly as on a web page. Each "browser"
//    is simulated by putting its session token into $_SESSION.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(sys_get_temp_dir());
session_start();

$arSiteRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$arLoad     = in_array('--load-schema', array_slice($argv, 1), true);

$arEnv = [];
foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $arKey) {
    $arValue = getenv('CUERCODE_IT_DB_' . $arKey);
    $arEnv[$arKey] = ($arValue === false) ? '' : $arValue;
}

if (preg_match('/^cuercode_it_[a-z0-9_]+$/', $arEnv['NAME']) !== 1) {
    fwrite(STDERR, 'Refusing to run: CUERCODE_IT_DB_NAME must start with "cuercode_it_".' . PHP_EOL);
    exit(2);
}
if ($arEnv['HOST'] === '' || $arEnv['USER'] === '') {
    fwrite(STDERR, 'Refusing to run: set CUERCODE_IT_DB_HOST and CUERCODE_IT_DB_USER.' . PHP_EOL);
    exit(2);
}
if ($arEnv['PORT'] === '') {
    $arEnv['PORT'] = '3306';
}
$arPort = (int) $arEnv['PORT'];

// ==============================================================================
// 👶 Child mode: one step run by a SEPARATE process (for the "at the same
//    moment" checks). Prints one line "CHILD-RESULT:{json}" and exits.
// ==============================================================================
$arChildMode = '';
foreach (array_slice($argv, 1) as $arArgument) {
    if (str_starts_with($arArgument, '--child=')) {
        $arChildMode = substr($arArgument, 8);
    }
}

if ($arChildMode !== '') {
    define('DB_HOST', $arEnv['HOST']);
    define('DB_PORT', $arPort);
    define('DB_NAME', $arEnv['NAME']);
    define('DB_USER', $arEnv['USER']);
    define('DB_PASS', $arEnv['PASS']);
    define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));
    $_SERVER['REMOTE_ADDR']     = '203.0.113.60';
    $_SERVER['HTTP_USER_AGENT'] = 'integration-child';
    require_once $arSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    restore_error_handler();

    // ⏱️ A shared start moment, so several children really act together.
    $arStartAt = (float) getenv('CUERCODE_IT_CHILD_START');
    while ($arStartAt > 0 && microtime(true) < $arStartAt) {
        usleep(1000);
    }

    $arChildResult = ['mode' => $arChildMode];
    if ($arChildMode === 'login') {
        $arLogin = loginUser((string) getenv('CUERCODE_IT_CHILD_EMAIL'), (string) getenv('CUERCODE_IT_CHILD_PASSWORD'), '203.0.113.60', 'integration-child');
        $arChildResult['loginSuccess']  = $arLogin['success'];
        $arChildResult['currentUserOk'] = (currentUser() !== null);
    } elseif ($arChildMode === 'reset') {
        $arResetResult = resetPasswordWithToken((string) getenv('CUERCODE_IT_CHILD_TOKEN'), (string) getenv('CUERCODE_IT_CHILD_PASSWORD'));
        $arChildResult['success']   = $arResetResult['success'];
        $arChildResult['errorCode'] = $arResetResult['errorCode'];
    } elseif ($arChildMode === 'wrongpw') {
        $arChildResult['count'] = recordFailedLogin((int) getenv('CUERCODE_IT_CHILD_USERID'));
    }
    echo 'CHILD-RESULT:' . json_encode($arChildResult) . PHP_EOL;
    exit(0);
}

// ⏳ Wait for the server (the Docker image restarts once while starting).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$arDeadline = microtime(true) + 120;
$arConn     = null;
$arLastErr  = '';
while ($arConn === null && microtime(true) < $arDeadline) {
    try {
        $arConn = @new \mysqli($arEnv['HOST'], $arEnv['USER'], $arEnv['PASS'], '', $arPort);
    } catch (\Throwable $arError) {
        $arLastErr = $arError->getMessage();
        usleep(500000);
    }
}
if ($arConn === null) {
    fwrite(STDERR, 'Could not connect within 120 seconds: ' . $arLastErr . PHP_EOL);
    exit(2);
}
echo 'Server: ' . $arConn->server_info . PHP_EOL;

$arConn->query('CREATE DATABASE IF NOT EXISTS `' . $arEnv['NAME'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$arConn->select_db($arEnv['NAME']);

// 📥 Load the schema if asked and the tables are missing.
$arHas = $arConn->query("SELECT COUNT(*) AS `c` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPasswordResets'");
$arFreshlyLoaded = false;
if ((int) $arHas->fetch_assoc()['c'] !== 1) {
    $arFreshlyLoaded = true;
    if (!$arLoad) {
        fwrite(STDERR, 'The tables do not exist. Re-run with --load-schema.' . PHP_EOL);
        exit(2);
    }
    foreach (['install.sql', 'seed_data.sql'] as $arSqlFile) {
        $arSql = file_get_contents($arSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $arSqlFile);
        $arConn->multi_query((string) $arSql);
        do {
            $arStored = $arConn->store_result();
            if ($arStored instanceof \mysqli_result) {
                $arStored->free();
            }
        } while ($arConn->more_results() && $arConn->next_result());
        echo 'Loaded ' . $arSqlFile . '.' . PHP_EOL;
    }
}

define('DB_HOST', $arEnv['HOST']);
define('DB_PORT', $arPort);
define('DB_NAME', $arEnv['NAME']);
define('DB_USER', $arEnv['USER']);
define('DB_PASS', $arEnv['PASS']);
define('ENCRYPTION_KEY', bin2hex(random_bytes(16)));

require_once $arSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

restore_error_handler();
ini_set('display_errors', 'stderr');
// 🔇 On the command line, PHP cannot give the session a new id once this
//    script has printed something; issueUserSession() asks it to (on a web
//    page it works). That one warning is expected here and says nothing about
//    the code under test, so it alone is not printed.
set_error_handler(static function (int $arErrorNumber, string $arErrorText): bool {
    return str_contains($arErrorText, 'Session ID cannot be regenerated after headers have already been sent');
}, E_WARNING);
set_exception_handler(static function (\Throwable $arError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($arError) . ': ' . $arError->getMessage() . PHP_EOL);
    exit(1);
});

$_SERVER['REMOTE_ADDR']     = '203.0.113.50';
$_SERVER['HTTP_USER_AGENT'] = 'integration-check';

$arFailures = 0;
$arCheckCount = 0;

function arCheck(bool $arPassed, string $arWhat): void
{
    $GLOBALS['arCheckCount']++;
    if (!$arPassed) {
        $GLOBALS['arFailures']++;
    }
    echo ($arPassed ? '    ok   ' : '    FAIL ') . $arWhat . PHP_EOL;
}

function arSelect(string $arSql, string $arTypes = '', array $arParams = []): array
{
    $arStmt = dbQuery($arSql, $arTypes, $arParams);
    $arRes  = $arStmt->get_result();
    $arRows = [];
    while ($arRow = $arRes->fetch_assoc()) {
        $arRows[] = $arRow;
    }
    $arStmt->close();
    return $arRows;
}

function arOne(string $arSql, string $arTypes = '', array $arParams = []): ?array
{
    $arRows = arSelect($arSql, $arTypes, $arParams);
    if ($arRows === []) {
        return null;
    }
    return $arRows[0];
}

function arCount(string $arSql, string $arTypes = '', array $arParams = []): int
{
    $arRow = arOne($arSql, $arTypes, $arParams);
    return (int) array_values((array) $arRow)[0];
}

function arExec(string $arSql, string $arTypes = '', array $arParams = []): void
{
    $arStmt = dbQuery($arSql, $arTypes, $arParams);
    $arStmt->close();
}

/** 🧹 Wipe everything this script touches (the database is throwaway). */
function arReset(): void
{
    foreach (['tblEmailQueue', 'tblEmailVerifications', 'tblPasswordResets', 'tblUserSessions', 'tblUserOAuth', 'tblWebAuthnCredentials', 'tblActivityLog'] as $arTable) {
        arExec('DELETE FROM `' . $arTable . '`');
    }
    arExec("DELETE FROM `tblAPIKeys` WHERE `apiKeyName` LIKE 'ar-%'");
    arExec("DELETE FROM `tblAPIRateLog` WHERE `apiKeyID` IS NULL AND `endpoint` = 'auth/request-password-reset'");
    arExec("DELETE FROM `tblUsers` WHERE `email` LIKE 'ar-%@ar.cuercode.invalid'");
}

/** 👤 Make an account and return its number. $arPassword null = no password. */
function arUser(string $arName, string $arStatus, ?string $arPassword, bool $arVerified): int
{
    $arEmail = 'ar-' . $arName . '@ar.cuercode.invalid';
    $arHash  = null;
    if ($arPassword !== null) {
        $arHash = hashPassword($arPassword);
    }
    $arVerifiedSql = $arVerified ? '2026-01-02 03:04:05' : null;
    arExec(
        'INSERT INTO `tblUsers` (`email`, `passwordHash`, `status`, `emailVerifiedAt`) VALUES (?, ?, ?, ?)',
        'ssss',
        [$arEmail, $arHash, $arStatus, $arVerifiedSql]
    );
    return (int) arOne('SELECT `userID` FROM `tblUsers` WHERE `email` = ?', 's', [$arEmail])['userID'];
}

/** 🎫 A verification link with a known raw token. */
function arVerifyLink(int $arUserID): string
{
    $arRaw = bin2hex(random_bytes(32));
    arExec('INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'is', [$arUserID, hashToken($arRaw)]);
    return $arRaw;
}

/** 🎫 A password-reset link with a known raw token. */
function arResetLink(int $arUserID): string
{
    $arRaw = bin2hex(random_bytes(32));
    arExec('INSERT INTO `tblPasswordResets` (`userID`, `tokenHash`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'is', [$arUserID, hashToken($arRaw)]);
    return $arRaw;
}

/** 🚪 Give an account one of every "way in" (as a stranger could have). */
function arGiveWaysIn(int $arUserID, string $arTag): void
{
    arExec('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'si', [bin2hex(random_bytes(32)), $arUserID]);
    arExec('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'si', [bin2hex(random_bytes(32)), $arUserID]);
    arExec('INSERT INTO `tblAPIKeys` (`apiKeyHash`, `apiKeyPrefix`, `apiKeyName`, `userID`, `isActive`) VALUES (?, ?, ?, ?, 1)', 'sssi', [hash('sha256', random_bytes(16)), 'cuercode_ar', 'ar-' . $arTag, $arUserID]);
    arExec("INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`) VALUES (?, 'google', ?)", 'is', [$arUserID, 'ar-sub-' . $arTag]);
    arExec('INSERT INTO `tblWebAuthnCredentials` (`userID`, `credentialID`, `publicKey`) VALUES (?, ?, ?)', 'iss', [$arUserID, 'ar-cred-' . $arTag, 'ar-key']);
    arResetLink($arUserID);
    arVerifyLink($arUserID);
}

/** 📸 Everything about an account that a reset may change. */
function arSnapshot(int $arUserID): array
{
    $arUser = arOne('SELECT `status`, `emailVerifiedAt`, `passwordHash`, `failedLoginCount`, `lockedUntil` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arUserID]);
    return [
        'user'          => $arUser,
        'liveSessions'  => arCount('SELECT COUNT(*) FROM `tblUserSessions` WHERE `userID` = ? AND `revokedAt` IS NULL', 'i', [$arUserID]),
        'activeKeys'    => arCount('SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ? AND `isActive` = 1', 'i', [$arUserID]),
        'oauth'         => arCount('SELECT COUNT(*) FROM `tblUserOAuth` WHERE `userID` = ?', 'i', [$arUserID]),
        'passkeys'      => arCount('SELECT COUNT(*) FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [$arUserID]),
        'unusedResets'  => arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arUserID]),
        'unusedVerifs'  => arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arUserID]),
        'activityRows'  => arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `entityType` = 'user' AND `entityID` = ?", 'i', [$arUserID]),
    ];
}

arReset();
$arStrangerPassword = 'Stranger-' . bin2hex(random_bytes(6));
$arOwnerPassword    = 'Owner-New-' . bin2hex(random_bytes(6));

// ==============================================================================
// P. Before and after database upgrade 0003
// ==============================================================================
echo PHP_EOL . 'P. Database upgrade 0003 (sessions valid from)' . PHP_EOL;
// 🧪 Guard: without a real session every "signed in?" check below would be
//    trivially "no", and the refusals would pass for the wrong reason.
arCheck(session_status() === PHP_SESSION_ACTIVE, 'sanity: this process has a real PHP session (so "refused" results mean something)');
authSessionsValidFromReady(true);
if ($arFreshlyLoaded) {
    arCheck(authSessionsValidFromReady() === false, 'a database from install.sql alone: the new columns are not there yet');
    $arP = arUser('p', 'active', $arStrangerPassword, true);
    $arPLogin = loginUser('ar-p@ar.cuercode.invalid', $arStrangerPassword, '203.0.113.50', 'integration-check');
    arCheck($arPLogin['success'] === true && currentUser() !== null, '...and sign-in still works the old way (nobody is locked out before the upgrade)');
} else {
    echo '    (skipped: the database already existed, so it may already have upgrade 0003)' . PHP_EOL;
}
require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_migrations.php';
$arUpgrade = migrationApplyPending(getDB(), 'cli:integration', false);
authSessionsValidFromReady(true);
arCheck($arUpgrade['status'] === 'ok', 'the waiting upgrades applied (' . $arUpgrade['status'] . ')');
arCheck(authSessionsValidFromReady() === true, 'after upgrade 0003 the protection is on');
if ($arFreshlyLoaded) {
    arCheck(currentUser() !== null, 'a session made before the upgrade still works after it (account never reset)');
}

// ==============================================================================
// A. A verification link needs the account's password
// ==============================================================================
echo PHP_EOL . 'A. Verification link needs the password' . PHP_EOL;
$arA = arUser('a', 'pending', $arStrangerPassword, false);
$arALink = arVerifyLink($arA);
arCheck(emailVerificationTokenNeedsPassword($arALink) === true, 'the page will show the password box for a password account');
$arR = verifyEmailToken($arALink, null);
arCheck($arR['success'] === false && $arR['errorCode'] === 'VERIFICATION_TOKEN_INVALID' && str_contains($arR['message'], 'Forgot password'), 'nothing typed: refused, pointing to Forgot password');
arCheck(arSnapshot($arA)['user']['failedLoginCount'] === 0, '...and NOT counted as a wrong try');
$arR = verifyEmailToken($arALink, 'not-the-password');
arCheck($arR['success'] === false && $arR['httpCode'] === 400 && str_contains($arR['message'], 'Forgot password'), 'a wrong password: refused with the same kind of message');
$arSnap = arSnapshot($arA);
arCheck($arSnap['user']['status'] === 'pending' && $arSnap['user']['emailVerifiedAt'] === null, '...the account stays waiting');
arCheck($arSnap['user']['failedLoginCount'] === 1, '...the wrong try is counted (lock-out counter 1)');
arCheck($arSnap['unusedVerifs'] === 1, '...and the link still works');
$arBadLink = verifyEmailToken(bin2hex(random_bytes(32)), $arStrangerPassword);
arCheck($arBadLink['message'] === $arR['message'] && $arBadLink['errorCode'] === $arR['errorCode'], 'an invalid link gives exactly the same message and code as a wrong password');
$arR = verifyEmailToken($arALink, $arStrangerPassword);
arCheck($arR['success'] === true, 'the right password: verified');
$arSnap = arSnapshot($arA);
arCheck($arSnap['user']['status'] === 'active' && $arSnap['user']['emailVerifiedAt'] !== null, '...the account is active with a checked email');
arCheck($arSnap['user']['failedLoginCount'] === 0 && $arSnap['user']['lockedUntil'] === null, '...the lock-out counter is cleared');
arCheck($arSnap['unusedVerifs'] === 0, '...the link is used up');
arCheck(verifyEmailToken($arALink, $arStrangerPassword)['errorCode'] === 'VERIFICATION_ALREADY_USED', 'using it again: already used');

// ==============================================================================
// B. Too many wrong passwords
// ==============================================================================
echo PHP_EOL . 'B. Too many wrong passwords' . PHP_EOL;
$arB = arUser('b', 'pending', $arStrangerPassword, false);
$arBLink = arVerifyLink($arB);
for ($arTry = 1; $arTry <= 4; $arTry++) {
    verifyEmailToken($arBLink, 'wrong-' . $arTry);
}
$arSnap = arSnapshot($arB);
arCheck($arSnap['user']['failedLoginCount'] === 4 && $arSnap['unusedVerifs'] === 1 && $arSnap['user']['lockedUntil'] === null, 'four wrong passwords: counted, link still works, not locked');
$arR = verifyEmailToken($arBLink, 'wrong-5');
arCheck($arR['success'] === false && str_contains($arR['message'], 'stopped working'), 'the fifth (the limit): the link stops working, and says so');
$arSnap = arSnapshot($arB);
arCheck($arSnap['unusedVerifs'] === 0, '...the link is used up');
arCheck($arSnap['user']['lockedUntil'] !== null && $arSnap['user']['status'] === 'pending', '...the account is locked out and still waiting');
arCheck(arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'verification_link_stopped' AND `entityID` = ?", 'i', [$arB]) === 1, '...one activity row records it');
arCheck(arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityDetail` LIKE '%@ar.cuercode.invalid%' OR `additionalData` LIKE '%@ar.cuercode.invalid%' OR `activityDetail` LIKE '%wrong-%'", '', []) === 0, '...and no activity row holds the address or a typed password');
$arBLink2 = arVerifyLink($arB);
$arR = verifyEmailToken($arBLink2, $arStrangerPassword);
arCheck($arR['success'] === false && arSnapshot($arB)['user']['status'] === 'pending', 'a NEW link with the RIGHT password while locked: refused, still waiting');
arCheck(arSnapshot($arB)['unusedVerifs'] === 1, '...and that new link is not used up');

// ==============================================================================
// C. An account with no password
// ==============================================================================
echo PHP_EOL . 'C. Account with no password (Microsoft/Google only)' . PHP_EOL;
$arC = arUser('c', 'pending', null, false);
$arCLink = arVerifyLink($arC);
arCheck(emailVerificationTokenNeedsPassword($arCLink) === false, 'the page shows the one-click button (no password box)');
$arR = verifyEmailToken($arCLink, null);
arCheck($arR['success'] === true && arSnapshot($arC)['user']['status'] === 'active', 'the link alone activates it, as before');
arCheck(emailVerificationTokenNeedsPassword($arCLink) === true, 'once used, the page goes back to the safe answer (password box)');

// ==============================================================================
// D. Forgot password on a WAITING account (the stranger's)
// ==============================================================================
echo PHP_EOL . 'D. Forgot password on a waiting account' . PHP_EOL;
$arD = arUser('d', 'pending', $arStrangerPassword, false);
$arOther = arUser('other', 'active', 'Other-' . bin2hex(random_bytes(6)), true);
arGiveWaysIn($arD, 'd');
arGiveWaysIn($arOther, 'other');
$arOtherBefore = arSnapshot($arOther);

// ⏱️ The set-up link above was made a moment ago; age it so the one-minute
//    gap between reset emails (review finding 3, section FP) does not apply.
arExec('UPDATE `tblPasswordResets` SET `createdAt` = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE `userID` = ?', 'i', [$arD]);
$arR = requestPasswordReset('ar-d@ar.cuercode.invalid', '203.0.113.50');
arCheck($arR['success'] === true, 'Forgot password for the waiting account: the usual answer');
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arD]) === 2, '...and a reset link WAS made for it (decision 0013)');
arCheck(arCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `toAddress` = 'ar-d@ar.cuercode.invalid' AND `templateKey` = 'password_reset'", '', []) === 1, '...and one reset email was queued');
$arSuspended = arUser('susp', 'suspended', $arStrangerPassword, true);
requestPasswordReset('ar-susp@ar.cuercode.invalid', '203.0.113.50');
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arSuspended]) === 0, 'a suspended account still gets no link');

$arDLink = arResetLink($arD);
$arDBefore = arSnapshot($arD);
arCheck($arDBefore['liveSessions'] === 2 && $arDBefore['activeKeys'] === 1 && $arDBefore['oauth'] === 1 && $arDBefore['passkeys'] === 1 && $arDBefore['unusedResets'] === 3 && $arDBefore['unusedVerifs'] === 1, 'set-up: 2 sessions, 1 API key, 1 linked sign-in, 1 passkey, 3 unused reset links, 1 unused verification link');
$arR = resetPasswordWithToken($arDLink, $arOwnerPassword);
arCheck($arR['success'] === true, 'the owner resets the password with the emailed link');
arCheck(str_contains($arR['message'], 'account is now active') && str_contains($arR['message'], 'API keys') && str_contains($arR['message'], 'Google or Microsoft'), '...and is told the account is active and what was removed');
$arSnap = arSnapshot($arD);
arCheck($arSnap['user']['status'] === 'active' && $arSnap['user']['emailVerifiedAt'] !== null, 'the waiting account is now ACTIVE with a checked email');
arCheck(verifyPassword($arOwnerPassword, (string) $arSnap['user']['passwordHash']) === true, 'the NEW password works');
arCheck(verifyPassword($arStrangerPassword, (string) $arSnap['user']['passwordHash']) === false, 'the stranger\'s password no longer works');
arCheck($arSnap['liveSessions'] === 0 && $arSnap['activeKeys'] === 0 && $arSnap['oauth'] === 0 && $arSnap['passkeys'] === 0, 'every session ended, API key off, linked sign-in and passkey removed');
arCheck($arSnap['unusedResets'] === 0 && $arSnap['unusedVerifs'] === 0, 'every other unused reset and verification link cancelled');
$arLog = arOne("SELECT `activityDetail`, `additionalData` FROM `tblActivityLog` WHERE `activityType` = 'password_reset_completed' AND `entityID` = ?", 'i', [$arD]);
$arLogData = json_decode((string) $arLog['additionalData'], true);
arCheck(is_array($arLogData) && $arLogData['account_activated'] === true, 'the activity row says the account was activated');
arCheck(is_array($arLogData) && $arLogData['removed'] == ['sessions_ended' => 2, 'api_keys_turned_off' => 1, 'password_reset_links_cancelled' => 2, 'linked_sign_ins_removed' => 1, 'passkeys_removed' => 1, 'verification_links_cancelled' => 1], 'the activity row has the counts (2 other reset links: the one used was already used up)');
$arLogText = (string) $arLog['activityDetail'] . ' ' . (string) $arLog['additionalData'];
arCheck(!str_contains($arLogText, $arOwnerPassword) && !str_contains($arLogText, $arDLink) && !str_contains($arLogText, '@ar.cuercode.invalid') && !str_contains($arLogText, '$argon2'), 'the activity row holds no password, hash, link or address');
arCheck(arSnapshot($arOther) === $arOtherBefore, 'another account\'s sessions, keys, sign-ins, passkeys and links are untouched');

// ==============================================================================
// E. The same on an ACTIVE account
// ==============================================================================
echo PHP_EOL . 'E. Reset on an active account' . PHP_EOL;
$arE = arUser('e', 'active', 'Old-' . bin2hex(random_bytes(6)), true);
arGiveWaysIn($arE, 'e');
$arELink = arResetLink($arE);
$arR = resetPasswordWithToken($arELink, $arOwnerPassword);
$arSnap = arSnapshot($arE);
arCheck($arR['success'] === true && str_starts_with($arR['message'], 'Your password has been reset.'), 'reset succeeds (not described as an activation)');
arCheck($arSnap['user']['emailVerifiedAt'] === '2026-01-02 03:04:05', 'the email-checked date is kept as it was');
arCheck($arSnap['liveSessions'] === 0 && $arSnap['activeKeys'] === 0 && $arSnap['oauth'] === 0 && $arSnap['passkeys'] === 0 && $arSnap['unusedResets'] === 0 && $arSnap['unusedVerifs'] === 0, 'every other way in is removed for an active account too');
$arLogData = json_decode((string) arOne("SELECT `additionalData` FROM `tblActivityLog` WHERE `activityType` = 'password_reset_completed' AND `entityID` = ?", 'i', [$arE])['additionalData'], true);
arCheck(is_array($arLogData) && $arLogData['account_activated'] === false, 'the activity row says it was NOT an activation');

// ==============================================================================
// F. ONE transaction
// ==============================================================================
echo PHP_EOL . 'F. One transaction' . PHP_EOL;
$arF = arUser('f', 'pending', $arStrangerPassword, false);
arGiveWaysIn($arF, 'f');
$arFLink = arResetLink($arF);
$arFBefore = arSnapshot($arF);
$arConn->query('DROP TRIGGER IF EXISTS `arForcedFailure`');
$arConn->query("CREATE TRIGGER `arForcedFailure` BEFORE DELETE ON `tblWebAuthnCredentials` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure for the integration test'");
$arR = resetPasswordWithToken($arFLink, $arOwnerPassword);
$arConn->query('DROP TRIGGER IF EXISTS `arForcedFailure`');
arCheck($arR['success'] === false && $arR['errorCode'] === 'RESET_FAILED' && $arR['httpCode'] === 500, 'a forced failure part-way (removing passkeys): RESET_FAILED');
arCheck(arSnapshot($arF) === $arFBefore, '...and NOTHING changed: password, status, sessions, keys, sign-ins, links, log - and the link is still unused');
$arR = resetPasswordWithToken($arFLink, $arOwnerPassword);
arCheck($arR['success'] === true && arSnapshot($arF)['user']['status'] === 'active', 'the same link works afterwards');

$arF2 = arUser('f2', 'active', 'Old-' . bin2hex(random_bytes(6)), true);
$arF2Link = arResetLink($arF2);
$arConn->query("CREATE TRIGGER `arForcedFailure` BEFORE INSERT ON `tblActivityLog` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure for the integration test'");
$arR = resetPasswordWithToken($arF2Link, $arOwnerPassword);
$arConn->query('DROP TRIGGER IF EXISTS `arForcedFailure`');
arCheck($arR['success'] === true && verifyPassword($arOwnerPassword, (string) arSnapshot($arF2)['user']['passwordHash']), 'DELIBERATE: a failed activity-log write does not block the reset (the person still gets back in)');

// ==============================================================================
// G. Refusals
// ==============================================================================
echo PHP_EOL . 'G. Refusals' . PHP_EOL;
$arG = arUser('g', 'pending', $arStrangerPassword, false);
arExec('UPDATE `tblUsers` SET `roleID` = 1 WHERE `userID` = ?', 'i', [$arG]);
$arGLink = arResetLink($arG);
$arGBefore = arSnapshot($arG);
$arR = resetPasswordWithToken($arGLink, $arOwnerPassword);
arCheck($arR['success'] === false && $arR['errorCode'] === 'RESET_TOKEN_INVALID', 'a waiting Global Admin: the emailed link is refused (review fix I1)');
arCheck(arSnapshot($arG) === $arGBefore, '...nothing changed and the link is not used up');
$arSLink = arResetLink($arSuspended);
$arSBefore = arSnapshot($arSuspended);
$arR = resetPasswordWithToken($arSLink, $arOwnerPassword);
arCheck($arR['success'] === false && arSnapshot($arSuspended) === $arSBefore, 'a suspended account (link made before suspension): refused, nothing changed');
arCheck(resetPasswordWithToken($arDLink, $arOwnerPassword)['errorCode'] === 'RESET_TOKEN_ALREADY_USED', 'a used link: already used');

// ==============================================================================
// H. Decision 0014 with a REAL saved setting
// ==============================================================================
echo PHP_EOL . 'H. Visitor address with a real trusted_proxy_ips setting' . PHP_EOL;
arExec("DELETE FROM `tblSettings` WHERE `settingKey` = 'trusted_proxy_ips'");
arExec("INSERT INTO `tblSettings` (`settingGroup`, `settingKey`, `settingValue`, `settingType`) VALUES ('security', 'trusted_proxy_ips', '10.0.0.0/8, 2001:db8:1::/48, not-valid', 'string')");
if (function_exists('clearConfigCache')) {
    clearConfigCache();
}
clientIpTrustedProxySetting(true);
$arSetting = clientIpTrustedProxySetting();
arCheck(count($arSetting['ranges']) === 2 && $arSetting['invalid'] === ['not-valid'], 'the saved setting is read: 2 ranges, 1 bad entry reported');
$_SERVER['REMOTE_ADDR'] = '10.20.30.40';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7, 10.0.0.9';
arCheck(getClientIP() === '198.51.100.7', 'from a trusted range: the right-most untrusted X-Forwarded-For entry');
logActivity('ar_address_check', 'integration', 'user', 1, []);
arCheck(arOne("SELECT `requestIP` FROM `tblActivityLog` WHERE `activityType` = 'ar_address_check' ORDER BY `activityID` DESC LIMIT 1")['requestIP'] === '198.51.100.7', '...and the activity log records the same address');
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
arCheck(getClientIP() === '198.51.100.99', 'from an untrusted address: X-Forwarded-For is ignored');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '172.64.1.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
arCheck(getClientIP() === '203.0.113.77', 'from a Cloudflare range: CF-Connecting-IP');
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
arCheck(getClientIP() === '203.0.113.50', 'CF-Connecting-IP from outside Cloudflare: ignored');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
arExec("DELETE FROM `tblSettings` WHERE `settingKey` = 'trusted_proxy_ips'");

// ==============================================================================
// 👶 Helpers for separate processes
// ==============================================================================

/**
 * 🚀 Start this script again as a child process in the given mode.
 *
 * @param string               $arMode One of the child modes
 * @param array<string, string> $arVars Extra environment values for the child
 * @return array{process: resource, pipes: array}
 */
function arSpawn(string $arMode, array $arVars): array
{
    $arChildEnv = getenv();
    foreach ($arVars as $arKey => $arValue) {
        $arChildEnv[$arKey] = $arValue;
    }
    $arPipes = [];
    $arProcess = proc_open([PHP_BINARY, __FILE__, '--child=' . $arMode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $arPipes, null, $arChildEnv);
    return ['process' => $arProcess, 'pipes' => $arPipes];
}

/**
 * 📥 Wait for a child and read its result line.
 *
 * @param array $arChild From arSpawn()
 * @return array The decoded result ('crashed' => output when there is none)
 */
function arCollect(array $arChild): array
{
    $arOut = (string) stream_get_contents($arChild['pipes'][1]) . (string) stream_get_contents($arChild['pipes'][2]);
    fclose($arChild['pipes'][1]);
    fclose($arChild['pipes'][2]);
    proc_close($arChild['process']);
    if (preg_match('/^CHILD-RESULT:(\{.*\})$/m', $arOut, $arMatch) === 1) {
        $arDecoded = json_decode($arMatch[1], true);
        if (is_array($arDecoded)) {
            return $arDecoded;
        }
    }
    return ['crashed' => substr($arOut, 0, 500)];
}

/** 🎫 Use a session token as "this browser" (null = signed out). */
function arUseSession(?string $arToken): void
{
    if ($arToken === null) {
        unset($_SESSION[CUERCODE_SESSION_TOKEN_KEY]);
        return;
    }
    $_SESSION[CUERCODE_SESSION_TOKEN_KEY] = $arToken;
}

/** 🕐 The account's current "sessions valid from" mark. */
function arMark(int $arUserID): ?string
{
    $arRow = arOne('SELECT `sessionsValidFrom` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arUserID]);
    return $arRow['sessionsValidFrom'];
}

/** 🔢 Sessions of an account that currentUser() would accept (not ended, not expired, mark matches). */
function arLiveSessions(int $arUserID): int
{
    return arCount(
        'SELECT COUNT(*) FROM `tblUserSessions` AS `s` INNER JOIN `tblUsers` AS `u` ON `u`.`userID` = `s`.`userID` '
        . 'WHERE `s`.`userID` = ? AND `s`.`revokedAt` IS NULL AND `s`.`expiresAt` > NOW() AND (`s`.`sessionsValidFromSeen` <=> `u`.`sessionsValidFrom`)',
        'i',
        [$arUserID]
    );
}

$arItEnv = [
    'CUERCODE_IT_DB_HOST' => $arEnv['HOST'],
    'CUERCODE_IT_DB_PORT' => (string) $arPort,
    'CUERCODE_IT_DB_NAME' => $arEnv['NAME'],
    'CUERCODE_IT_DB_USER' => $arEnv['USER'],
    'CUERCODE_IT_DB_PASS' => $arEnv['PASS'],
];

// ==============================================================================
// R. Finding 1: a sign-in under way when the password is reset
// ==============================================================================
echo PHP_EOL . 'R. A sign-in under way during a password reset (finding 1)' . PHP_EOL;

// (a) TWO real processes. The sign-in is paused INSIDE the database, after it
//     has checked the old password and before it makes its session (a
//     trigger on its "last sign-in" update sleeps 3 seconds). The reset runs
//     in this process during the pause.
$arRace = arUser('race', 'active', $arStrangerPassword, true);
$arConn->query('DROP TRIGGER IF EXISTS `arPauseSignIn`');
$arConn->query(
    "CREATE TRIGGER `arPauseSignIn` BEFORE UPDATE ON `tblUsers` FOR EACH ROW BEGIN "
    . "IF NEW.`email` = 'ar-race@ar.cuercode.invalid' AND NEW.`lastLoginAt` IS NOT NULL "
    . "AND (OLD.`lastLoginAt` IS NULL OR NEW.`lastLoginAt` <> OLD.`lastLoginAt`) THEN SET @arPause = SLEEP(3); END IF; END"
);
$arRaceLink  = arResetLink($arRace);
$arChild     = arSpawn('login', $arItEnv + ['CUERCODE_IT_CHILD_EMAIL' => 'ar-race@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword]);
usleep(1500000);
$arResetAt   = microtime(true);
$arR         = resetPasswordWithToken($arRaceLink, $arOwnerPassword);
$arChildOut  = arCollect($arChild);
$arConn->query('DROP TRIGGER IF EXISTS `arPauseSignIn`');
arCheck($arR['success'] === true, '(a) the owner\'s reset succeeds while the old-password sign-in is paused');
arCheck(arOne('SELECT `lastLoginAt` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arRace])['lastLoginAt'] !== null, '(a) proof of the bad order: the other process DID pass the OLD password check before the reset');
arCheck(isset($arChildOut['loginSuccess']) && $arChildOut['loginSuccess'] === false, '(a) ...yet its sign-in ends in failure (' . json_encode($arChildOut) . ')');
arCheck(isset($arChildOut['currentUserOk']) && $arChildOut['currentUserOk'] === false, '(a) ...and it is not signed in');
arCheck(arLiveSessions($arRace) === 0, '(a) no working session exists for the account');
arCheck(arMark($arRace) !== null, '(a) the account\'s "sessions valid from" mark was set by the reset');
$arR = loginUser('ar-race@ar.cuercode.invalid', $arOwnerPassword, '203.0.113.50', 'integration-check');
arCheck($arR['success'] === true && currentUser() !== null, 'BOUNDARY: the owner signs in with the new password straight after the reset (same second) and IS signed in');

// (b) The same, step by step in the bad order, with the real functions.
$arM = arUser('m', 'active', $arStrangerPassword, true);
$arMRow = arOne('SELECT `userID`, `passwordHash`, `sessionsValidFrom` FROM `tblUsers` WHERE `email` = ?', 's', ['ar-m@ar.cuercode.invalid']);
arCheck(verifyPassword($arStrangerPassword, (string) $arMRow['passwordHash']) === true, '(b) step 1: a sign-in reads the account and the OLD password checks out');
$arMLink = arResetLink($arM);
arCheck(resetPasswordWithToken($arMLink, $arOwnerPassword)['success'] === true, '(b) step 2: the owner resets the password');
$arMToken = issueUserSession($arM, '203.0.113.50', 'integration-check', $arMRow['sessionsValidFrom']);
arCheck($arMToken === '', '(b) step 3: the sign-in now tries to make its session with what it read - refused (empty answer)');
arCheck(arCount('SELECT COUNT(*) FROM `tblUserSessions` WHERE `userID` = ?', 'i', [$arM]) === 0, '(b) ...and no session row was written');
$arForced = bin2hex(random_bytes(32));
arExec('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`, `sessionsValidFromSeen`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)', 'sis', [$arForced, $arM, $arMRow['sessionsValidFrom']]);
arUseSession($arForced);
arCheck(currentUser() === null, '(b) step 4: even a session row that slipped in AFTER the reset with the old mark is refused by currentUser()');
$arForcedNull = bin2hex(random_bytes(32));
arExec('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `expiresAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', 'si', [$arForcedNull, $arM]);
arUseSession($arForcedNull);
arCheck(currentUser() === null, '(b) ...and so is one written the pre-upgrade way (no mark at all)');
$arR = loginUser('ar-m@ar.cuercode.invalid', $arOwnerPassword, '203.0.113.50', 'integration-check');
$arMCreated = arOne('SELECT `createdAt` FROM `tblUserSessions` WHERE `sessionID` = ?', 's', [(string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY]]);
arCheck($arR['success'] === true && currentUser() !== null, 'BOUNDARY: the new-password sign-in right after the reset works (session made ' . (string) $arMCreated['createdAt'] . ', mark ' . (string) arMark($arM) . ')');

// 🕐 Two marks in one instant still differ, and a clock that went back cannot make a mark earlier.
$arMarkBefore = arMark($arM);
$arDb = getDB();
$arDb->begin_transaction();
arOne('SELECT `userID` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE', 'i', [$arM]);
accountRemoveOtherWaysIn($arM, true);
$arMarkOne = arMark($arM);
accountRemoveOtherWaysIn($arM, true);
$arMarkTwo = arMark($arM);
$arDb->commit();
arCheck(strcmp((string) $arMarkOne, (string) $arMarkBefore) > 0 && strcmp((string) $arMarkTwo, (string) $arMarkOne) > 0, 'two marks set in the same instant are still in order and different (' . $arMarkOne . ' < ' . $arMarkTwo . ')');
arExec('UPDATE `tblUsers` SET `sessionsValidFrom` = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR) WHERE `userID` = ?', 'i', [$arM]);
$arFuture = arMark($arM);
$arDb->begin_transaction();
arOne('SELECT `userID` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE', 'i', [$arM]);
accountRemoveOtherWaysIn($arM, true);
$arDb->commit();
arCheck(strcmp((string) arMark($arM), (string) $arFuture) > 0, 'a mark ahead of the clock (clock went back) still moves FORWARD, by one millionth of a second');
$arUtcNow = arOne('SELECT UTC_TIMESTAMP(6) AS `now`')['now'];
$arE2 = arUser('utc', 'active', $arStrangerPassword, true);
$arDb->begin_transaction();
arOne('SELECT `userID` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE', 'i', [$arE2]);
accountRemoveOtherWaysIn($arE2, true);
$arDb->commit();
arCheck(abs(strtotime((string) arMark($arE2)) - strtotime((string) $arUtcNow)) <= 5, 'the mark is written in UTC (within 5 s of the server\'s UTC clock: ' . arMark($arE2) . ')');

// 🔗 Google/Microsoft SIGN-IN in the same gap.
$arG2 = arUser('g2', 'active', $arStrangerPassword, true);
arExec("INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`) VALUES (?, 'google', 'ar-sub-g2')", 'i', [$arG2]);
$arSignIn = applyOAuthAccountLink('google', 'ar-sub-g2', 'ar-g2@ar.cuercode.invalid', true, null, null, null);
arCheck($arSignIn['success'] === true && $arSignIn['data']['action'] === 'login_existing', 'Google sign-in: the linked sign-in is found (step 1)');
$arG2Link = arResetLink($arG2);
arCheck(resetPasswordWithToken($arG2Link, $arOwnerPassword)['success'] === true, 'Google sign-in: the owner resets the password meanwhile (the link is removed)');
arCheck(issueUserSession($arG2, '203.0.113.50', 'integration-check', $arSignIn['data']['sessionsValidFromSeen']) === '', 'Google sign-in: its session is then refused');
arCheck(arLiveSessions($arG2) === 0, 'Google sign-in: ...no working session');

// ==============================================================================
// L. Finding 2: a "link" request and an API key across a reset
// ==============================================================================
echo PHP_EOL . 'L. Google/Microsoft link request and API key across a reset (finding 2)' . PHP_EOL;
require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_keys.php';
$arL = arUser('l', 'active', $arStrangerPassword, true);
arCheck(loginUser('ar-l@ar.cuercode.invalid', $arStrangerPassword, '203.0.113.50', 'integration-check')['success'] === true, 'set-up: the stranger is signed in');
$arStrangerToken = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
$arAtBegin = currentUser();
$arSavedMark = $arAtBegin['sessionsValidFrom'];
arCheck(oauthLinkRequestCheck(currentUser(), $arL, true, $arSavedMark) === 'ok', 'before any reset the link request would be allowed');
arCheck(createApiKeyForUser($arL, 'ar-before', [])['success'] === true, 'before any reset an API key can be made');
$arLLink = arResetLink($arL);
arCheck(resetPasswordWithToken($arLLink, $arOwnerPassword)['success'] === true, 'the owner resets the password');
arUseSession($arStrangerToken);
arCheck(oauthLinkRequestCheck(currentUser(), $arL, true, $arSavedMark) === 'not_signed_in', 'the stranger comes back from Google: nobody is signed in any more - refused');
$arKeysBefore = arCount('SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ?', 'i', [$arL]);
$arKey = createApiKeyForUser($arL, 'ar-after', []);
arCheck($arKey['success'] === false && $arKey['httpCode'] === 401 && $arKey['errorCode'] === 'AUTH_REQUIRED', 'API key with the ended session (checked-then-acted gap): refused');
arCheck(arCount('SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ?', 'i', [$arL]) === $arKeysBefore, '...and no key was written');
arCheck(loginUser('ar-l@ar.cuercode.invalid', $arOwnerPassword, '203.0.113.50', 'integration-check')['success'] === true, 'the owner signs in');
arCheck(oauthLinkRequestCheck(currentUser(), $arL, true, $arSavedMark) === 'account_secured', 'a link request saved BEFORE the reset, completed in the owner\'s new session: refused (the mark moved)');
$arStale = applyOAuthAccountLink('google', 'ar-sub-stale', 'x@ar.cuercode.invalid', true, null, null, $arL, $arSavedMark);
arCheck($arStale['success'] === false && $arStale['errorCode'] === 'SSO_LINK_EXPIRED', 'the locked re-check also refuses the stale request');
arCheck(arCount("SELECT COUNT(*) FROM `tblUserOAuth` WHERE `providerSubjectID` = 'ar-sub-stale'") === 0, '...and no link row was written');
$arFresh = applyOAuthAccountLink('google', 'ar-sub-fresh', 'x@ar.cuercode.invalid', true, null, null, $arL, currentUser()['sessionsValidFrom']);
arCheck($arFresh['success'] === true && $arFresh['data']['action'] === 'link_to_current', 'a link request started in the owner\'s NEW session is linked');
arCheck(arCount("SELECT COUNT(*) FROM `tblUserOAuth` WHERE `providerSubjectID` = 'ar-sub-fresh' AND `userID` = ?", 'i', [$arL]) === 1, '...and the link row exists');
arCheck(createApiKeyForUser($arL, 'ar-owner', [])['success'] === true, 'the owner can make an API key in the new session');

// ==============================================================================
// D2. Finding 5: two resets of one account at the same moment
// ==============================================================================
echo PHP_EOL . 'D2. Two resets of one account at the same moment (finding 5)' . PHP_EOL;
$arErrorsBefore = arCount("SELECT COUNT(*) FROM `tblErrorLog` WHERE `errorTitle` LIKE '%Password reset failed%' OR `errorDetail` LIKE '%eadlock%'");
$arD2 = arUser('d2', 'active', $arStrangerPassword, true);
$arRounds = 5;
$arGoodRounds = 0;
for ($arRound = 1; $arRound <= $arRounds; $arRound++) {
    $arLinkA = arResetLink($arD2);
    $arLinkB = arResetLink($arD2);
    $arStart = (string) (microtime(true) + 1.5);
    $arChildA = arSpawn('reset', $arItEnv + ['CUERCODE_IT_CHILD_TOKEN' => $arLinkA, 'CUERCODE_IT_CHILD_PASSWORD' => 'Round-A-' . $arRound . '-' . $arOwnerPassword, 'CUERCODE_IT_CHILD_START' => $arStart]);
    $arChildB = arSpawn('reset', $arItEnv + ['CUERCODE_IT_CHILD_TOKEN' => $arLinkB, 'CUERCODE_IT_CHILD_PASSWORD' => 'Round-B-' . $arRound . '-' . $arOwnerPassword, 'CUERCODE_IT_CHILD_START' => $arStart]);
    $arOutA = arCollect($arChildA);
    $arOutB = arCollect($arChildB);
    $arWins = 0;
    $arUsed = 0;
    foreach ([$arOutA, $arOutB] as $arOut) {
        if (isset($arOut['success']) && $arOut['success'] === true) {
            $arWins++;
        } elseif (isset($arOut['errorCode']) && $arOut['errorCode'] === 'RESET_TOKEN_ALREADY_USED') {
            $arUsed++;
        }
    }
    if ($arWins === 1 && $arUsed === 1) {
        $arGoodRounds++;
    } else {
        echo '      round ' . $arRound . ': ' . json_encode($arOutA) . ' ' . json_encode($arOutB) . PHP_EOL;
    }
}
arCheck($arGoodRounds === $arRounds, 'in every one of ' . $arRounds . ' rounds: exactly one reset wins and the other is told its link was used (' . $arGoodRounds . '/' . $arRounds . ')');
arCheck(arCount("SELECT COUNT(*) FROM `tblErrorLog` WHERE `errorTitle` LIKE '%Password reset failed%' OR `errorDetail` LIKE '%eadlock%'") === $arErrorsBefore, '...and no reset failed or deadlocked (no new error-log rows)');
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arD2]) === 0, '...and no unused link is left behind');

// ==============================================================================
// W. Finding 7: wrong passwords at the same moment all count
// ==============================================================================
echo PHP_EOL . 'W. Wrong passwords at the same moment (finding 7)' . PHP_EOL;
arExec("UPDATE `tblSettings` SET `settingValue` = '100' WHERE `settingKey` = 'max_login_attempts'");
if (function_exists('clearConfigCache')) {
    clearConfigCache();
}
$arW = arUser('w', 'active', $arStrangerPassword, true);
$arStart = (string) (microtime(true) + 1.5);
$arWChildren = [];
for ($arChildNumber = 1; $arChildNumber <= 8; $arChildNumber++) {
    $arWChildren[] = arSpawn('wrongpw', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arW, 'CUERCODE_IT_CHILD_START' => $arStart]);
}
$arWCounts = [];
foreach ($arWChildren as $arWChild) {
    $arOut = arCollect($arWChild);
    $arWCounts[] = isset($arOut['count']) ? $arOut['count'] : -1;
}
sort($arWCounts);
arCheck((int) arSnapshot($arW)['user']['failedLoginCount'] === 8, 'eight wrong passwords at the same moment: the count is 8 (' . implode(',', $arWCounts) . ')');
arCheck($arWCounts === [1, 2, 3, 4, 5, 6, 7, 8] || max($arWCounts) === 8, '...and each process read back a real count');
arExec("UPDATE `tblSettings` SET `settingValue` = '5' WHERE `settingKey` = 'max_login_attempts'");
if (function_exists('clearConfigCache')) {
    clearConfigCache();
}
$arW2 = arUser('w2', 'active', $arStrangerPassword, true);
for ($arTry = 1; $arTry <= 5; $arTry++) {
    loginUser('ar-w2@ar.cuercode.invalid', 'wrong-' . $arTry, '203.0.113.50', 'integration-check');
}
arCheck(arSnapshot($arW2)['user']['lockedUntil'] !== null, 'five wrong sign-ins one after another still lock the account (limit 5)');

// ==============================================================================
// FP. Finding 3: "Forgot password" limits and even timing
// ==============================================================================
echo PHP_EOL . 'FP. Forgot password: limits and even timing (finding 3)' . PHP_EOL;
$arFp = arUser('fp', 'active', $arStrangerPassword, true);
$arFpLinks = static function () use ($arFp): int {
    return arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arFp]);
};
$arFpLimited = static function (int $arUserID): int {
    return arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'password_reset_request_limited' AND `entityID` = ?", 'i', [$arUserID]);
};
$arT0 = microtime(true);
$arKnown = requestPasswordReset('ar-fp@ar.cuercode.invalid', '198.51.100.61');
$arKnownTime = microtime(true) - $arT0;
$arT0 = microtime(true);
$arUnknown = requestPasswordReset('ar-nobody@ar.cuercode.invalid', '198.51.100.61');
$arUnknownTime = microtime(true) - $arT0;
arCheck($arKnown === $arUnknown, 'a registered and an unknown address get exactly the same answer');
arCheck($arKnownTime >= 0.25 && $arUnknownTime >= 0.25, 'both take at least 250 ms (registered ' . round($arKnownTime * 1000) . ' ms, unknown ' . round($arUnknownTime * 1000) . ' ms)');
arCheck(abs($arKnownTime - $arUnknownTime) < 0.1, '...so the time no longer tells them apart (difference ' . round(abs($arKnownTime - $arUnknownTime) * 1000) . ' ms)');
arCheck($arFpLinks() === 1, 'the registered address got one link');
requestPasswordReset('ar-fp@ar.cuercode.invalid', '198.51.100.61');
arCheck($arFpLinks() === 1 && $arFpLimited($arFp) === 0, 'a second request inside the one-minute gap: no new link, and no log row (a double-click)');
for ($arTry = 2; $arTry <= 5; $arTry++) {
    arExec('UPDATE `tblPasswordResets` SET `createdAt` = DATE_SUB(`createdAt`, INTERVAL 2 MINUTE) WHERE `userID` = ?', 'i', [$arFp]);
    requestPasswordReset('ar-fp@ar.cuercode.invalid', '198.51.100.6' . $arTry);
}
arCheck($arFpLinks() === 5, 'with the gap respected, five links in a day are sent');
arExec('UPDATE `tblPasswordResets` SET `createdAt` = DATE_SUB(`createdAt`, INTERVAL 2 MINUTE) WHERE `userID` = ?', 'i', [$arFp]);
$arR = requestPasswordReset('ar-fp@ar.cuercode.invalid', '198.51.100.70');
arCheck($arR === $arUnknown && $arFpLinks() === 5, 'the sixth in a day: the same answer, no link (daily cap)');
arCheck($arFpLimited($arFp) === 1, '...and one "refused by the limit" row');
arExec('UPDATE `tblPasswordResets` SET `createdAt` = DATE_SUB(`createdAt`, INTERVAL 2 MINUTE) WHERE `userID` = ?', 'i', [$arFp]);
requestPasswordReset('ar-fp@ar.cuercode.invalid', '198.51.100.71');
requestPasswordReset('ar-fp@ar.cuercode.invalid', '198.51.100.72');
arCheck($arFpLimited($arFp) === 1, 'more refused requests in the same hour add NO further rows (at most one an hour)');
$arFp2 = arUser('fp2', 'active', $arStrangerPassword, true);
for ($arTry = 1; $arTry <= 10; $arTry++) {
    requestPasswordReset('ar-unknown-' . $arTry . '@ar.cuercode.invalid', '198.51.100.99');
}
arCheck(arCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `requestIP` = '198.51.100.99' AND `endpoint` = 'auth/request-password-reset'") === 10, 'every request from one address is counted, known or not (10)');
$arR = requestPasswordReset('ar-fp2@ar.cuercode.invalid', '198.51.100.99');
arCheck($arR === $arUnknown && arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arFp2]) === 0, 'the eleventh from that address in an hour: the same answer, no link (address cap)');
arCheck($arFpLimited($arFp2) === 1, '...and one "refused by the limit" row for that account');
arCheck(arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'password_reset_request_limited' AND (`activityDetail` LIKE '%@%' OR `additionalData` LIKE '%@%')") === 0, 'no limit row holds an email address');

// ==============================================================================
// V. Nit: what a verification link says when nothing is activated
// ==============================================================================
echo PHP_EOL . 'V. Verification link for an active or suspended account' . PHP_EOL;
$arVA = arUser('va', 'active', $arStrangerPassword, true);
$arVALink = arVerifyLink($arVA);
$arR = verifyEmailToken($arVALink, $arStrangerPassword);
arCheck($arR['success'] === false && $arR['httpCode'] === 409 && str_contains($arR['message'], 'nothing was changed') && !str_contains($arR['message'], 'can now log in'), 'an already-active account: "already confirmed, nothing was changed" (not "verified, you can now log in")');
arCheck(arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arVA]) === 0, '...and that pointless link is used up');
$arVS = arUser('vs', 'suspended', $arStrangerPassword, true);
$arVSLink = arVerifyLink($arVS);
$arR = verifyEmailToken($arVSLink, $arStrangerPassword);
arCheck($arR['success'] === false && $arR['errorCode'] === 'VERIFICATION_TOKEN_INVALID' && arSnapshot($arVS)['user']['status'] === 'suspended', 'a suspended account: the usual invalid answer, still suspended');
arCheck(arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arVS]) === 1, '...and nothing changed (the link is not used up)');

// 🧹 Tidy up.
arUseSession(null);
arReset();

echo PHP_EOL;
if ($arFailures === 0) {
    echo 'All ' . $arCheckCount . ' account-recovery and visitor-address checks passed.' . PHP_EOL;
    exit(0);
}
echo $arFailures . ' of ' . $arCheckCount . ' check(s) FAILED.' . PHP_EOL;
exit(1);
