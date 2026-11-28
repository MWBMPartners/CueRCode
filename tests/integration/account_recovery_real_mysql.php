<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Real-database check for decisions 0013 and 0014
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
    } elseif ($arChildMode === 'changepw') {
        // 🔑 Final review I-1: a password change from a given session.
        require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_account.php';
        $_SESSION[CUERCODE_SESSION_TOKEN_KEY] = (string) getenv('CUERCODE_IT_CHILD_SESSION');
        $arChange = changeUserPassword((int) getenv('CUERCODE_IT_CHILD_USERID'), (string) getenv('CUERCODE_IT_CHILD_PASSWORD'), (string) getenv('CUERCODE_IT_CHILD_NEWPASSWORD'), (string) getenv('CUERCODE_IT_CHILD_SESSION'));
        $arChildResult['success']   = $arChange['success'];
        $arChildResult['errorCode'] = $arChange['errorCode'];
    } elseif ($arChildMode === 'erase') {
        require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_account.php';
        $arErase = eraseUserAccount((int) getenv('CUERCODE_IT_CHILD_USERID'), (string) getenv('CUERCODE_IT_CHILD_PASSWORD'), true);
        $arChildResult['success']   = $arErase['success'];
        $arChildResult['errorCode'] = $arErase['errorCode'];
    } elseif ($arChildMode === 'verify') {
        $arVerifyResult = verifyEmailToken((string) getenv('CUERCODE_IT_CHILD_TOKEN'), (string) getenv('CUERCODE_IT_CHILD_PASSWORD'));
        $arChildResult['success']   = $arVerifyResult['success'];
        $arChildResult['errorCode'] = $arVerifyResult['errorCode'];
    } elseif ($arChildMode === 'reauth') {
        require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';
        $arChildResult['result'] = adminVerifyReauthPassword((int) getenv('CUERCODE_IT_CHILD_USERID'), (string) getenv('CUERCODE_IT_CHILD_PASSWORD'));
    } elseif ($arChildMode === 'forgot' || $arChildMode === 'resend') {
        // 🚦 Final review M-1: one of many simultaneous emailed-link requests.
        $arT0 = microtime(true);
        if ($arChildMode === 'forgot') {
            $arLinkResult = requestPasswordReset((string) getenv('CUERCODE_IT_CHILD_EMAIL'), (string) getenv('CUERCODE_IT_CHILD_IP'));
        } else {
            $arLinkResult = requestNewVerificationLink((string) getenv('CUERCODE_IT_CHILD_EMAIL'), (string) getenv('CUERCODE_IT_CHILD_IP'));
        }
        $arChildResult['seconds'] = microtime(true) - $arT0;
        $arChildResult['message'] = $arLinkResult['message'];
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
    arExec("DELETE FROM `tblAPIRateLog` WHERE `apiKeyID` IS NULL AND `endpoint` IN ('auth/request-password-reset', 'page/resend-verification')");
    arExec("DELETE FROM `tblUsers` WHERE `email` LIKE 'ar-%@ar.cuercode.invalid' OR `email` LIKE 'deleted-%@deleted.invalid'");
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

    // 🔒 Independent review (Codex C3 finding 4, 2026-11-25): before 0003 a
    //    reset or password change cannot end a sign-in that is already under
    //    way, so both are refused (plainly) until the upgrade is applied.
    //    Sign-in itself - and so /admin/upgrades/ - keeps working, and the
    //    command-line upgrade tool always works.
    $arPLink   = arResetLink($arP);
    $arPReset  = resetPasswordWithToken($arPLink, 'Before-0003-' . $arStrangerPassword);
    arCheck($arPReset['success'] === false && $arPReset['errorCode'] === 'DATABASE_UPGRADE_NEEDED' && $arPReset['httpCode'] === 503, 'before 0003: a password reset is refused with a plain "try again later" (C3-4) (' . (string) $arPReset['errorCode'] . ')');
    arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arP]) === 1, 'before 0003: ...and the reset link is NOT used up (it works once the upgrade is applied)');
    require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_account.php';
    $arPChange = changeUserPassword($arP, $arStrangerPassword, 'Before-0003-' . $arStrangerPassword, null);
    arCheck($arPChange['success'] === false && $arPChange['errorCode'] === 'DATABASE_UPGRADE_NEEDED', 'before 0003: a password change is refused the same way (C3-4)');
    arCheck(verifyPassword($arStrangerPassword, (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arP])['passwordHash']), 'before 0003: ...and the password is unchanged');
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
    $arPResetAfter = resetPasswordWithToken($arPLink, 'After-0003-' . $arStrangerPassword);
    arCheck($arPResetAfter['success'] === true, 'after 0003: the SAME reset link that was refused before now works (C3-4)');
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
arCheck(str_contains(verifyEmailToken($arALink, $arStrangerPassword)['message'], 'replaced by a newer one'), 'using it again: the message also covers a link replaced by a newer one (#113 B9-8)');

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
// V6. #177: every per-address limit counts an IPv6 address as its /64
// ==============================================================================
echo PHP_EOL . 'V6. Per-address limits count an IPv6 address as its /64 network (#177)' . PHP_EOL;
arReset();
$arV6 = arUser('v6', 'active', $arStrangerPassword, true);
$arV6Links = static function () use ($arV6): int {
    return arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arV6]);
};
for ($arTry = 1; $arTry <= 10; $arTry++) {
    requestPasswordReset('ar-v6-unknown-' . $arTry . '@ar.cuercode.invalid', '2001:db8:177:1::' . dechex($arTry * 257));
}
$arR = requestPasswordReset('ar-v6@ar.cuercode.invalid', '2001:db8:177:1:ffff:ffff:ffff:9');
arCheck($arR === $arUnknown && $arV6Links() === 0, '#177: ten forgot-password requests from ten different IPv6 addresses in ONE /64, then an eleventh from yet another in it: refused like one address (the same answer, no link)');
arCheck(arCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `requestIP` = '2001:db8:177:1::/64' AND `endpoint` = 'auth/request-password-reset'") === 11, '#177: the request log counts them under the /64 network (' . arCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `requestIP` = '2001:db8:177:1::/64'") . ')');
$arR = requestPasswordReset('ar-v6@ar.cuercode.invalid', '2001:DB8:177:2::1');
arCheck($arV6Links() === 1, '#177: an address in ANOTHER /64 is another visitor: its link is sent');
arCheck((string) (arOne('SELECT `requestIP` FROM `tblPasswordResets` WHERE `userID` = ? ORDER BY `resetID` DESC LIMIT 1', 'i', [$arV6])['requestIP'] ?? '') === '2001:DB8:177:2::1', '#177: ...and the reset record keeps the REAL address it came from (the audit record is not the counting key)');
for ($arTry = 1; $arTry <= 3; $arTry++) {
    recordAPIRequest(null, '2001:db8:177:3::' . $arTry, 'generate');
}
$arRate = checkRateLimit(null, '2001:db8:177:3:abcd::1', ['perMin' => 3, 'perDay' => 100]);
$arRateOther = checkRateLimit(null, '2001:db8:177:4::1', ['perMin' => 3, 'perDay' => 100]);
arCheck($arRate['allowed'] === false && $arRateOther['allowed'] === true, '#177: the API\'s anonymous limit (3 a minute here): three calls from three addresses in one /64 use it up for the whole /64; another /64 is not affected');
recordAPIRequest(null, '198.51.100.177', 'generate');
arCheck(arCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `requestIP` = '198.51.100.177'") === 1 && arCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `requestIP` = '198.51.100.177/64'") === 0, '#177: an IPv4 address is still recorded as itself');
arExec("DELETE FROM `tblAPIRateLog` WHERE `endpoint` = 'generate'");
arReset();

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

// ==============================================================================
// 🔒 Helpers for "hold the account row while another process is half-way"
// ==============================================================================

/**
 * 🔢 How many OTHER connections to this database are running a statement
 *    whose text matches $arLike right now (a process waiting for a lock
 *    shows its statement here until the lock is granted).
 *
 * @param string $arLike A LIKE pattern for the statement text
 * @return int
 */
function arWaitingStatements(string $arLike): int
{
    $arStmt = $GLOBALS['arConn']->prepare(
        'SELECT COUNT(*) AS `c` FROM `information_schema`.`PROCESSLIST` '
        . 'WHERE `ID` <> CONNECTION_ID() AND `DB` = DATABASE() AND `INFO` LIKE ?'
    );
    $arStmt->bind_param('s', $arLike);
    $arStmt->execute();
    $arCountRow = $arStmt->get_result()->fetch_assoc();
    $arStmt->close();
    return (int) $arCountRow['c'];
}

/**
 * ⏳ Wait (up to $arSeconds) until at least $arHowMany other connections are
 *    waiting on a statement matching $arLike.
 *
 * @return bool True when they are
 */
function arWaitUntilWaiting(string $arLike, int $arHowMany, float $arSeconds = 30.0): bool
{
    $arUntil = microtime(true) + $arSeconds;
    while (microtime(true) < $arUntil) {
        if (arWaitingStatements($arLike) >= $arHowMany) {
            // A short extra pause, so "seen running" really means "waiting".
            usleep(300000);
            return arWaitingStatements($arLike) >= $arHowMany;
        }
        usleep(50000);
    }
    return false;
}

/** 🔐 This script's own separate connection takes the account row lock. */
function arHoldAccount(int $arUserID): void
{
    $GLOBALS['arConn']->begin_transaction();
    $GLOBALS['arConn']->query('SELECT `userID` FROM `tblUsers` WHERE `userID` = ' . $arUserID . ' FOR UPDATE')->free();
}

/** ✍️ On the lock-holding connection: replace the password (and optionally move the mark and end sessions, as a reset does). */
function arChangeWhileHolding(int $arUserID, string $arNewHash, bool $arAsAReset): void
{
    $arStmt = $GLOBALS['arConn']->prepare('UPDATE `tblUsers` SET `passwordHash` = ? WHERE `userID` = ?');
    $arStmt->bind_param('si', $arNewHash, $arUserID);
    $arStmt->execute();
    $arStmt->close();
    if ($arAsAReset) {
        $GLOBALS['arConn']->query(
            'UPDATE `tblUsers` SET `sessionsValidFrom` = CASE WHEN `sessionsValidFrom` IS NOT NULL AND `sessionsValidFrom` >= UTC_TIMESTAMP(6) '
            . 'THEN DATE_ADD(`sessionsValidFrom`, INTERVAL 1 MICROSECOND) ELSE UTC_TIMESTAMP(6) END WHERE `userID` = ' . $arUserID
        );
        $GLOBALS['arConn']->query('UPDATE `tblUserSessions` SET `revokedAt` = NOW() WHERE `userID` = ' . $arUserID . ' AND `revokedAt` IS NULL');
    }
}

/** 🎫 Sign an account in (in THIS process) and return the new session token, leaving "this browser" signed out. */
function arSignIn(string $arName, string $arPassword): string
{
    $arLogin = loginUser('ar-' . $arName . '@ar.cuercode.invalid', $arPassword, '203.0.113.50', 'integration-check');
    $arToken = '';
    if ($arLogin['success'] === true && isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
        $arToken = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
    }
    arUseSession(null);
    return $arToken;
}

/** ✅ Is this session one currentUser() accepts right now? */
function arSessionWorks(string $arToken): bool
{
    arUseSession($arToken);
    $arWorks = (currentUser() !== null);
    arUseSession(null);
    return $arWorks;
}

require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_account.php';
require_once $arSiteRoot . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php';
$arAttackerPassword = 'Attacker-' . bin2hex(random_bytes(6));
$arWaitForUsersLock = '%FROM `tblUsers` WHERE `userID` = % FOR UPDATE%';

// ==============================================================================
// CP. Final review I-1 / decision 0016: a voluntary password change
// ==============================================================================
echo PHP_EOL . 'CP. Voluntary password change (final review I-1, decision 0016)' . PHP_EOL;

// (1) What it does when nothing else is happening.
$arCp = arUser('cp', 'active', $arStrangerPassword, true);
$arCpKeep  = arSignIn('cp', $arStrangerPassword);
$arCpOther = arSignIn('cp', $arStrangerPassword);
arExec('INSERT INTO `tblAPIKeys` (`apiKeyHash`, `apiKeyPrefix`, `apiKeyName`, `userID`, `isActive`) VALUES (?, ?, ?, ?, 1)', 'sssi', [hash('sha256', random_bytes(16)), 'cuercode_ar', 'ar-cp', $arCp]);
arExec("INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`) VALUES (?, 'google', 'ar-sub-cp')", 'i', [$arCp]);
arCheck($arCpKeep !== '' && $arCpOther !== '' && arSessionWorks($arCpKeep) && arSessionWorks($arCpOther), 'set-up: two signed-in sessions, one API key, one linked Google sign-in');
$arCpUnderWay = arOne('SELECT `sessionsValidFrom` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arCp]);
$arR = changeUserPassword($arCp, $arStrangerPassword, $arOwnerPassword, $arCpKeep);
arCheck($arR['success'] === true, 'the change succeeds');
arCheck(arSessionWorks($arCpKeep) === true, 'the session that made the change STAYS signed in (it was given the new mark)');
arCheck(arSessionWorks($arCpOther) === false, 'the other session is signed out');
arCheck(issueUserSession($arCp, '203.0.113.50', 'integration-check', $arCpUnderWay['sessionsValidFrom']) === '', 'a sign-in that had checked the OLD password just before cannot make a session afterwards (the mark moved)');
arUseSession(null);
arCheck(arCount('SELECT COUNT(*) FROM `tblAPIKeys` WHERE `userID` = ? AND `isActive` = 1', 'i', [$arCp]) === 1, 'decision 0016: the API key is still on');
arCheck(arCount('SELECT COUNT(*) FROM `tblUserOAuth` WHERE `userID` = ?', 'i', [$arCp]) === 1, 'decision 0016: the linked Google sign-in is still there');
$arCpHash = (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arCp])['passwordHash'];
arCheck(verifyPassword($arOwnerPassword, $arCpHash) && !verifyPassword($arStrangerPassword, $arCpHash), 'the new password works and the old one does not');
$arR = changeUserPassword($arCp, 'not-the-password', $arAttackerPassword, $arCpKeep);
arCheck($arR['errorCode'] === 'INVALID_CURRENT_PASSWORD' && (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arCp])['passwordHash'] === $arCpHash, 'a wrong current password: refused, nothing changed');
$arR = changeUserPassword($arCp, $arOwnerPassword, $arAttackerPassword, $arCpOther);
arCheck($arR['errorCode'] === 'AUTH_REQUIRED' && (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arCp])['passwordHash'] === $arCpHash, 'from a session that was signed out: refused (AUTH_REQUIRED), nothing changed - even with the right password');

// (2) THE REVIEWER'S RACE, with the real functions in two processes, in the
//     bad order: the change reads and checks the OLD password, then a
//     password reset finishes, then the change reaches its transaction.
//     This script holds the account row, starts the reset (it waits for the
//     row), then starts the change (it checks the old password and hashes
//     the new one, then waits too), then lets go. The reset asked first.
$arRc = arUser('rc', 'active', $arStrangerPassword, true);
$arRcAttacker = arSignIn('rc', $arStrangerPassword);
$arRcLink = arResetLink($arRc);
arHoldAccount($arRc);
$arRcReset = arSpawn('reset', $arItEnv + ['CUERCODE_IT_CHILD_TOKEN' => $arRcLink, 'CUERCODE_IT_CHILD_PASSWORD' => $arOwnerPassword]);
$arRcWaiting1 = arWaitUntilWaiting($arWaitForUsersLock, 1);
$arRcChange = arSpawn('changepw', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arRc, 'CUERCODE_IT_CHILD_SESSION' => $arRcAttacker, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword, 'CUERCODE_IT_CHILD_NEWPASSWORD' => $arAttackerPassword]);
$arRcWaiting2 = arWaitUntilWaiting($arWaitForUsersLock, 2);
$arConn->commit();
$arRcResetOut  = arCollect($arRcReset);
$arRcChangeOut = arCollect($arRcChange);
arCheck($arRcWaiting1 && $arRcWaiting2, '(race) both processes were caught waiting for the account row: the change had ALREADY checked the old password');
$arRcHash = (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arRc])['passwordHash'];
arCheck(isset($arRcResetOut['success']) && $arRcResetOut['success'] === true, '(race) the owner\'s reset succeeds (' . json_encode($arRcResetOut) . ')');
arCheck(isset($arRcChangeOut['success']) && $arRcChangeOut['success'] === false && in_array($arRcChangeOut['errorCode'], ['AUTH_REQUIRED', 'PASSWORD_CHANGED_MEANWHILE'], true), '(race) the late password change is REFUSED (' . json_encode($arRcChangeOut) . ')');
arCheck(verifyPassword($arOwnerPassword, $arRcHash) === true && verifyPassword($arAttackerPassword, $arRcHash) === false, '(race) the owner\'s new password stands; the other person\'s did NOT overwrite it (the reported fault)');
arCheck(arSessionWorks($arRcAttacker) === false && arLiveSessions($arRc) === 0, '(race) the other person\'s session is ended');
arCheck(loginUser('ar-rc@ar.cuercode.invalid', $arAttackerPassword, '203.0.113.50', 'integration-check')['success'] === false, '(race) and they cannot sign in with the password they tried to set');
arUseSession(null);

// (3) The same gap, forced: the password is replaced (as a reset does: new
//     password, mark moved, sessions ended) while the change waits.
$arF1 = arUser('cpf1', 'active', $arStrangerPassword, true);
$arF1Session = arSignIn('cpf1', $arStrangerPassword);
arHoldAccount($arF1);
$arF1Child = arSpawn('changepw', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arF1, 'CUERCODE_IT_CHILD_SESSION' => $arF1Session, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword, 'CUERCODE_IT_CHILD_NEWPASSWORD' => $arAttackerPassword]);
$arF1Waiting = arWaitUntilWaiting($arWaitForUsersLock, 1);
$arOwnerHash = hashPassword($arOwnerPassword);
arChangeWhileHolding($arF1, $arOwnerHash, true);
$arConn->commit();
$arF1Out = arCollect($arF1Child);
arCheck($arF1Waiting && isset($arF1Out['errorCode']) && $arF1Out['errorCode'] === 'AUTH_REQUIRED', '(forced) a reset lands while the change waits: refused as "signed out" (' . json_encode($arF1Out) . ')');
arCheck((string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arF1])['passwordHash'] === $arOwnerHash, '(forced) ...and the reset\'s password is untouched');

// (4) Two tabs of the SAME session change the password at the same moment:
//     both check the old password first; one wins, the other is told the
//     password changed meanwhile (the session stays good, so this is the
//     byte-for-byte check, not the session check, doing the work).
$arT2 = arUser('cpt2', 'active', $arStrangerPassword, true);
$arT2Session = arSignIn('cpt2', $arStrangerPassword);
arHoldAccount($arT2);
$arT2A = arSpawn('changepw', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arT2, 'CUERCODE_IT_CHILD_SESSION' => $arT2Session, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword, 'CUERCODE_IT_CHILD_NEWPASSWORD' => 'Tab-A-' . $arOwnerPassword]);
$arT2B = arSpawn('changepw', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arT2, 'CUERCODE_IT_CHILD_SESSION' => $arT2Session, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword, 'CUERCODE_IT_CHILD_NEWPASSWORD' => 'Tab-B-' . $arOwnerPassword]);
$arT2Waiting = arWaitUntilWaiting($arWaitForUsersLock, 2);
$arConn->commit();
$arT2Outs = [arCollect($arT2A), arCollect($arT2B)];
$arT2Codes = [];
foreach ($arT2Outs as $arOut) {
    $arT2Codes[] = isset($arOut['success']) && $arOut['success'] === true ? 'ok' : (string) ($arOut['errorCode'] ?? 'crashed');
}
sort($arT2Codes);
arCheck($arT2Waiting && $arT2Codes === ['PASSWORD_CHANGED_MEANWHILE', 'ok'], '(two tabs) one change wins, the other is refused as "changed meanwhile" (' . implode(', ', $arT2Codes) . ')');
$arT2Hash = (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arT2])['passwordHash'];
arCheck(verifyPassword('Tab-A-' . $arOwnerPassword, $arT2Hash) !== verifyPassword('Tab-B-' . $arOwnerPassword, $arT2Hash), '(two tabs) exactly one of the two new passwords is in place');
arCheck(arSessionWorks($arT2Session) === true, '(two tabs) and the session that made the change is still signed in');

// ==============================================================================
// ER. Final review I-1: erase, verification link and admin re-confirm
// ==============================================================================
echo PHP_EOL . 'ER. Other "check the password, then act" places (final review I-1)' . PHP_EOL;
$arEr = arUser('er', 'active', $arStrangerPassword, true);
arHoldAccount($arEr);
$arErChild = arSpawn('erase', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arEr, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword]);
$arErWaiting = arWaitUntilWaiting($arWaitForUsersLock, 1);
arChangeWhileHolding($arEr, $arOwnerHash, true);
$arConn->commit();
$arErOut = arCollect($arErChild);
arCheck($arErWaiting && isset($arErOut['errorCode']) && $arErOut['errorCode'] === 'PASSWORD_CHANGED_MEANWHILE', 'erase: the password is reset while the erase waits - refused (' . json_encode($arErOut) . ')');
$arErRow = arOne('SELECT `status`, `email` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arEr]);
arCheck($arErRow['status'] === 'active' && $arErRow['email'] === 'ar-er@ar.cuercode.invalid', 'erase: ...and the account is NOT erased');
$arR = eraseUserAccount($arEr, $arOwnerPassword, true);
arCheck($arR['success'] === true && arOne('SELECT `status` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arEr])['status'] === 'deleted', 'erase: with the current password and nothing happening meanwhile, it still works');

$arVe = arUser('ve', 'pending', $arStrangerPassword, false);
$arVeLink = arVerifyLink($arVe);
arHoldAccount($arVe);
$arVeChild = arSpawn('verify', $arItEnv + ['CUERCODE_IT_CHILD_TOKEN' => $arVeLink, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword]);
$arVeWaiting = arWaitUntilWaiting($arWaitForUsersLock, 1);
arChangeWhileHolding($arVe, $arOwnerHash, false);
$arConn->commit();
$arVeOut = arCollect($arVeChild);
arCheck($arVeWaiting && isset($arVeOut['success']) && $arVeOut['success'] === false && $arVeOut['errorCode'] === 'VERIFICATION_TOKEN_INVALID', 'verification link: the password changes while the link waits - the OLD password activates nothing (' . json_encode($arVeOut) . ')');
arCheck(arSnapshot($arVe)['user']['status'] === 'pending' && arSnapshot($arVe)['unusedVerifs'] === 1, 'verification link: ...the account is still waiting and the link still works');

$arRa = arUser('ra', 'active', $arStrangerPassword, true);
arExec('UPDATE `tblUsers` SET `failedLoginCount` = 2 WHERE `userID` = ?', 'i', [$arRa]);
arHoldAccount($arRa);
$arRaChild = arSpawn('reauth', $arItEnv + ['CUERCODE_IT_CHILD_USERID' => (string) $arRa, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword]);
$arRaWaiting = arWaitUntilWaiting('%UPDATE `tblUsers` SET `failedLoginCount` = 0%', 1);
arChangeWhileHolding($arRa, $arOwnerHash, false);
$arConn->commit();
$arRaOut = arCollect($arRaChild);
arCheck($arRaWaiting && isset($arRaOut['result']['ok']) && $arRaOut['result']['ok'] === false, 'admin re-confirm: the password changes while it waits - the OLD password confirms nothing (' . json_encode($arRaOut) . ')');
arCheck((int) arSnapshot($arRa)['user']['failedLoginCount'] === 2, 'admin re-confirm: ...and the failure counter was not cleared');
arCheck(adminVerifyReauthPassword($arRa, $arOwnerPassword)['ok'] === true, 'admin re-confirm: the current password still confirms normally');

// ==============================================================================
// SO. Final review I-1: "Sign out everywhere" also stops a sign-in under way
// ==============================================================================
echo PHP_EOL . 'SO. Sign out everywhere (final review I-1)' . PHP_EOL;
$arSo = arUser('so', 'active', $arStrangerPassword, true);
$arSoA = arSignIn('so', $arStrangerPassword);
$arSoB = arSignIn('so', $arStrangerPassword);
$arSoUnderWay = arOne('SELECT `sessionsValidFrom` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arSo]);
arCheck(accountSignOutEverywhere($arSo) === true, 'sign out everywhere: done');
arCheck(arSessionWorks($arSoA) === false && arSessionWorks($arSoB) === false, '...both sessions are signed out');
arCheck(issueUserSession($arSo, '203.0.113.50', 'integration-check', $arSoUnderWay['sessionsValidFrom']) === '', '...and a sign-in that had already checked the password cannot make its session afterwards');
arUseSession(null);
arCheck(arSignIn('so', $arStrangerPassword) !== '', '...while a fresh sign-in afterwards works');

// ==============================================================================
// M1. Final review M-1: many simultaneous emailed-link requests
// ==============================================================================
echo PHP_EOL . 'M1. Twenty simultaneous requests (final review M-1)' . PHP_EOL;

/**
 * 🚀 Start $arHowMany children of one mode at the same moment and collect them.
 *
 * @param string   $arMode   'forgot' or 'resend'
 * @param callable $arVarsOf Called with the child number (1..N), returns its extra environment
 * @return array<int, array> The children's results
 */
function arManyAtOnce(string $arMode, int $arHowMany, callable $arVarsOf): array
{
    $arStartAt = (string) (microtime(true) + 3.0);
    $arKids = [];
    for ($arNumber = 1; $arNumber <= $arHowMany; $arNumber++) {
        $arKids[] = arSpawn($arMode, $GLOBALS['arItEnv'] + $arVarsOf($arNumber) + ['CUERCODE_IT_CHILD_START' => $arStartAt]);
    }
    $arOuts = [];
    foreach ($arKids as $arKid) {
        $arOuts[] = arCollect($arKid);
    }
    return $arOuts;
}

/** ⏱️ The shortest answer time among the children (seconds), and whether every one answered. */
function arShortest(array $arOuts): array
{
    $arMin = 99.0;
    $arAll = true;
    foreach ($arOuts as $arOut) {
        if (!isset($arOut['seconds'])) {
            $arAll = false;
            continue;
        }
        $arMin = min($arMin, (float) $arOut['seconds']);
    }
    return ['min' => $arMin, 'all' => $arAll];
}

arExec("DELETE FROM `tblAPIRateLog` WHERE `apiKeyID` IS NULL AND `endpoint` IN ('auth/request-password-reset', 'page/resend-verification')");
$arM1 = arUser('m1', 'active', $arStrangerPassword, true);
$arOuts = arManyAtOnce('forgot', 20, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-m1@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.150'];
});
$arShort = arShortest($arOuts);
arCheck($arShort['all'], 'Forgot password: all 20 answered');
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arM1]) === 1, 'Forgot password, 20 at once for one account from one address: ONE link (was 20)');
arCheck(arCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `toAddress` = 'ar-m1@ar.cuercode.invalid'") === 1, '...and one email queued');
arCheck($arShort['min'] >= 0.25, '...and every answer still took at least 250 ms (shortest ' . round($arShort['min'] * 1000) . ' ms)');

$arM2 = arUser('m2', 'active', $arStrangerPassword, true);
arManyAtOnce('forgot', 20, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-m2@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.' . (160 + $arN)];
});
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arM2]) === 1, 'Forgot password, 20 at once for one account from 20 addresses: ONE link');

$arM3 = arUser('m3', 'active', $arStrangerPassword, true);
for ($arOld = 1; $arOld <= 5; $arOld++) {
    arExec('INSERT INTO `tblPasswordResets` (`userID`, `tokenHash`, `expiresAt`, `createdAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL ? MINUTE))', 'isi', [$arM3, hash('sha256', random_bytes(16)), 10 + $arOld]);
}
arManyAtOnce('forgot', 20, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-m3@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.' . (190 + $arN)];
});
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arM3]) === 5, 'Forgot password, an account already at the daily cap of 5, 20 at once: no sixth link');
arCheck(arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'password_reset_request_limited' AND `entityID` = ?", 'i', [$arM3]) === 1, '...and exactly ONE "refused by the limit" row, not twenty');

arExec("DELETE FROM `tblAPIRateLog` WHERE `apiKeyID` IS NULL AND `endpoint` = 'auth/request-password-reset'");
$arIpUsers = [];
for ($arN = 1; $arN <= 20; $arN++) {
    $arIpUsers[] = arUser('ip' . $arN, 'active', null, true);
}
arManyAtOnce('forgot', 20, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-ip' . $arN . '@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.250'];
});
$arIpLinks = arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` IN (' . implode(',', array_map('intval', $arIpUsers)) . ')');
arCheck(arCount("SELECT COUNT(*) FROM `tblAPIRateLog` WHERE `requestIP` = '198.51.100.250' AND `endpoint` = 'auth/request-password-reset'") === 20, 'Forgot password, 20 at once from ONE address for 20 accounts: all 20 recorded');
arCheck($arIpLinks === 10, '...and exactly the hourly allowance of 10 links went out, no more and no fewer (' . $arIpLinks . ')');

$arM4 = arUser('m4', 'pending', $arStrangerPassword, false);
$arOuts = arManyAtOnce('resend', 20, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-m4@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.151'];
});
$arShort = arShortest($arOuts);
arCheck(arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ?', 'i', [$arM4]) === 1, 'Send me a new link, 20 at once for one waiting account: ONE link');
arCheck(arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arM4]) === 1, '...and it still works');
arCheck($arShort['all'] && $arShort['min'] >= 0.25, '...and every answer took at least 250 ms (shortest ' . round($arShort['min'] * 1000) . ' ms)');

$arM5 = arUser('m5', 'pending', $arStrangerPassword, false);
for ($arOld = 1; $arOld <= 5; $arOld++) {
    arExec('INSERT INTO `tblEmailVerifications` (`userID`, `tokenHash`, `expiresAt`, `createdAt`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL ? MINUTE))', 'isi', [$arM5, hash('sha256', random_bytes(16)), 10 + $arOld]);
}
arManyAtOnce('resend', 20, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-m5@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.' . (100 + $arN)];
});
arCheck(arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ?', 'i', [$arM5]) === 5, 'Send me a new link, an account at the daily cap, 20 at once: no sixth link');
arCheck(arCount("SELECT COUNT(*) FROM `tblActivityLog` WHERE `activityType` = 'verification_link_resend_limited' AND `entityID` = ?", 'i', [$arM5]) === 1, '...and exactly ONE "refused by the limit" row');

$arUnknownOuts = arManyAtOnce('forgot', 5, static function (int $arN): array {
    return ['CUERCODE_IT_CHILD_EMAIL' => 'ar-nobody-' . $arN . '@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.' . (50 + $arN)];
});
arCheck(arShortest($arUnknownOuts)['min'] >= 0.25, 'unknown addresses (no account, no lock taken) still take at least 250 ms');

// ==============================================================================
// CX. Independent review (Codex C3 findings 2 and 3, 2026-11-25)
// ==============================================================================
echo PHP_EOL . 'CX. Codex review: erase removes passkeys and links; a link that expires while waiting is refused' . PHP_EOL;

// 🗑️ C3-2: erasing an account removes its passkeys, its unused emailed links
//    (they also hold the requesting address) and any email still waiting to
//    go to it. #129: sent records and the activity log lose the address too.
$arCx = arUser('cx', 'active', $arStrangerPassword, true);
arGiveWaysIn($arCx, 'cx');
arExec("INSERT INTO `tblEmailQueue` (`toAddress`, `toName`, `templateKey`, `paramsJSON`, `subject`, `status`) VALUES ('ar-cx@ar.cuercode.invalid', 'Cx', 'password_reset', '{\"resetUrl\":\"https://x.invalid/r?token=abc\"}', 'Reset', 'queued')");
arExec("INSERT INTO `tblEmailQueue` (`toAddress`, `templateKey`, `subject`, `status`, `sentAt`) VALUES ('ar-cx@ar.cuercode.invalid', 'verification', 'Verify', 'sent', NOW())");
// 📋 #129: email entries in the activity log, written by the real function
//    (one with different capitals), one whose error text repeats the address,
//    and one for a different person whose address only differs by an accent
//    or is longer.
arExec("DELETE FROM `tblActivityLog` WHERE `entityType` = 'email'");
logEmailAttempt('email_queued', 'ar-cx@ar.cuercode.invalid', 'Verify', 'Queued (emailID 1)');
logEmailAttempt('email_sent', 'AR-CX@ar.cuercode.invalid', 'Verify', 'Graph sendMail accepted (HTTP 202)');
logEmailAttempt('email_failed', 'ar-cx@ar.cuercode.invalid', 'Reset', 'Template render failed for ar-cx@ar.cuercode.invalid');
logEmailAttempt('email_sent', "ar-c\u{00E9}x@ar.cuercode.invalid", 'Verify', 'other person (accent)');
logEmailAttempt('email_sent', 'not-ar-cx@ar.cuercode.invalid', 'Verify', 'other person (longer)');
arCheck(arCount('SELECT COUNT(*) FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [$arCx]) === 1, 'erase (C3-2): the account starts with a passkey');
$arCxErase = eraseUserAccount($arCx, $arStrangerPassword, true);
arCheck($arCxErase['success'] === true, 'erase (C3-2): the erase succeeds');
arCheck(arCount('SELECT COUNT(*) FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [$arCx]) === 0, 'erase (C3-2): its passkeys are deleted');
arCheck(arCount('SELECT COUNT(*) FROM `tblPasswordResets` WHERE `userID` = ?', 'i', [$arCx]) === 0, 'erase (C3-2): its password-reset links (and their request addresses) are deleted');
arCheck(arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ?', 'i', [$arCx]) === 0, 'erase (C3-2): its verification links are deleted');
arCheck(arCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `toAddress` = 'ar-cx@ar.cuercode.invalid' AND `status` IN ('queued', 'failed')") === 0, 'erase (C3-2): no email is still waiting to go to the erased address');
arCheck(arCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `toAddress` = ? AND `status` = 'skipped' AND `paramsJSON` IS NULL AND `toName` IS NULL", 's', [buildErasedAccountEmail($arCx)]) === 1, 'erase (C3-2): ...it is marked skipped, with its link, name and address removed');
arCheck(arCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `toAddress` = 'ar-cx@ar.cuercode.invalid'") === 0, 'erase (#129): no email record, sent ones included, still holds the address');
arCheck(arCount("SELECT COUNT(*) FROM `tblEmailQueue` WHERE `toAddress` = ? AND `status` = 'sent' AND `paramsJSON` IS NULL AND `toName` IS NULL", 's', [buildErasedAccountEmail($arCx)]) === 1, 'erase (#129): ...the sent record is kept as sent, with the placeholder address');
$arCxLog = arSelect("SELECT `activityDetail`, `additionalData` FROM `tblActivityLog` WHERE `entityType` = 'email' ORDER BY `activityID`");
arCheck(count($arCxLog) === 5, 'erase (#129): the activity-log entries are kept (' . count($arCxLog) . ')');
$arCxText = '';
foreach (array_slice($arCxLog, 0, 3) as $arCxRow) {
    $arCxText .= $arCxRow['activityDetail'] . ' ' . $arCxRow['additionalData'] . ' ';
}
arCheck(stripos($arCxText, 'ar-cx@') === false, 'erase (#129): ...without the erased address anywhere in them (' . $arCxText . ')');
arCheck(substr_count($arCxText, '[address removed]') >= 7, 'erase (#129): ...it is replaced by a placeholder, in the text, the extra data and the error detail');
arCheck(str_contains((string) $arCxLog[3]['activityDetail'], "ar-c\u{00E9}x@ar.cuercode.invalid") && str_contains((string) $arCxLog[4]['activityDetail'], 'not-ar-cx@ar.cuercode.invalid'), 'erase (#129): other people\'s addresses are untouched');

// ⏰ C3-3: a reset link checked as valid, then held at the account lock while
//    it expires, must be refused when the lock is released.
$arCr = arUser('cr', 'active', $arStrangerPassword, true);
$arCrLink = arResetLink($arCr);
$arCrHashBefore = (string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arCr])['passwordHash'];
arHoldAccount($arCr);
$arCrChild = arSpawn('reset', $arItEnv + ['CUERCODE_IT_CHILD_TOKEN' => $arCrLink, 'CUERCODE_IT_CHILD_PASSWORD' => 'Expired-' . $arOwnerPassword]);
$arCrWaiting = arWaitUntilWaiting($arWaitForUsersLock, 1);
arExec('UPDATE `tblPasswordResets` SET `expiresAt` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `userID` = ?', 'i', [$arCr]);
$arConn->commit();
$arCrOut = arCollect($arCrChild);
arCheck($arCrWaiting && isset($arCrOut['success']) && $arCrOut['success'] === false && $arCrOut['errorCode'] === 'RESET_TOKEN_INVALID', 'reset (C3-3): a link that expires while the reset waits for the account is refused (' . json_encode($arCrOut) . ')');
arCheck((string) arOne('SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ?', 'i', [$arCr])['passwordHash'] === $arCrHashBefore, 'reset (C3-3): ...and the password is unchanged');

// ⏰ C3-3: the same for a verification link.
$arCv = arUser('cv', 'pending', $arStrangerPassword, false);
$arCvLink = arVerifyLink($arCv);
arHoldAccount($arCv);
$arCvChild = arSpawn('verify', $arItEnv + ['CUERCODE_IT_CHILD_TOKEN' => $arCvLink, 'CUERCODE_IT_CHILD_PASSWORD' => $arStrangerPassword]);
$arCvWaiting = arWaitUntilWaiting($arWaitForUsersLock, 1);
arExec('UPDATE `tblEmailVerifications` SET `expiresAt` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `userID` = ?', 'i', [$arCv]);
$arConn->commit();
$arCvOut = arCollect($arCvChild);
arCheck($arCvWaiting && isset($arCvOut['success']) && $arCvOut['success'] === false && $arCvOut['errorCode'] === 'VERIFICATION_TOKEN_INVALID', 'verification (C3-3): a link that expires while it waits for the account is refused (' . json_encode($arCvOut) . ')');
arCheck(arSnapshot($arCv)['user']['status'] === 'pending', 'verification (C3-3): ...and the account is still waiting');

// ⏱️ C3-5: while a KNOWN account's row is busy, "Forgot password" and "Send me
//    a new link" must not wait for it - waiting made the answer slower than
//    for an address with no account, which tells a prober the address exists.
//    The row is held for 1.5 seconds; the answer must still come within the
//    usual 250 ms floor (plus a little), and say the usual thing.
arExec("DELETE FROM `tblAPIRateLog` WHERE `apiKeyID` IS NULL AND `endpoint` IN ('auth/request-password-reset', 'page/resend-verification')");
$arCk = arUser('ck', 'active', $arStrangerPassword, true);
arHoldAccount($arCk);
$arCkChild = arSpawn('forgot', $arItEnv + ['CUERCODE_IT_CHILD_EMAIL' => 'ar-ck@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.71']);
usleep(1500000);
$arConn->commit();
$arCkOut = arCollect($arCkChild);
arCheck(isset($arCkOut['seconds']) && $arCkOut['seconds'] >= 0.25 && $arCkOut['seconds'] < 0.6, 'forgot password (C3-5): a known account whose row is busy answers in the usual time, not after the wait (' . json_encode($arCkOut['seconds'] ?? $arCkOut) . ' s)');
$arCuOut = arCollect(arSpawn('forgot', $arItEnv + ['CUERCODE_IT_CHILD_EMAIL' => 'ar-nobody-ck@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.72']));
arCheck(isset($arCkOut['message'], $arCuOut['message']) && $arCkOut['message'] === $arCuOut['message'], 'forgot password (C3-5): ...with exactly the message an unknown address gets');

$arCw = arUser('cw', 'pending', $arStrangerPassword, false);
arHoldAccount($arCw);
$arCwChild = arSpawn('resend', $arItEnv + ['CUERCODE_IT_CHILD_EMAIL' => 'ar-cw@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.73']);
usleep(1500000);
$arConn->commit();
$arCwOut = arCollect($arCwChild);
arCheck(isset($arCwOut['seconds']) && $arCwOut['seconds'] >= 0.25 && $arCwOut['seconds'] < 0.6, 'send me a new link (C3-5): a waiting account whose row is busy answers in the usual time (' . json_encode($arCwOut['seconds'] ?? $arCwOut) . ' s)');
$arCwAgain = arCollect(arSpawn('resend', $arItEnv + ['CUERCODE_IT_CHILD_EMAIL' => 'ar-cw@ar.cuercode.invalid', 'CUERCODE_IT_CHILD_IP' => '198.51.100.74']));
arCheck(isset($arCwAgain['seconds']) && arCount('SELECT COUNT(*) FROM `tblEmailVerifications` WHERE `userID` = ? AND `usedAt` IS NULL', 'i', [$arCw]) === 1, 'send me a new link (C3-5): once the row is free again, asking again sends the link as usual');

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
