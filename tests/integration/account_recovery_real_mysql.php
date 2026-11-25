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
if ((int) $arHas->fetch_assoc()['c'] !== 1) {
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
set_exception_handler(static function (\Throwable $arError): void {
    fwrite(STDERR, 'Integration check crashed: ' . get_class($arError) . ': ' . $arError->getMessage() . PHP_EOL);
    exit(1);
});

$_SERVER['REMOTE_ADDR']     = '203.0.113.50';
$_SERVER['HTTP_USER_AGENT'] = 'integration-check';

$arFailures = 0;

function arCheck(bool $arPassed, string $arWhat): void
{
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

// 🧹 Tidy up.
arReset();

echo PHP_EOL;
if ($arFailures === 0) {
    echo 'All account-recovery and visitor-address checks passed.' . PHP_EOL;
    exit(0);
}
echo $arFailures . ' check(s) FAILED.' . PHP_EOL;
exit(1);
