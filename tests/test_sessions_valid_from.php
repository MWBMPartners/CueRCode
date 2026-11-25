<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - "Sessions valid from" and the other 2026-11-25 review fixes
 * ==============================================================================
 *
 * Path: tests/test_sessions_valid_from.php
 *
 * The independent review of decisions 0013/0014 (2026-11-25) found:
 *   1. a sign-in already under way when a password is reset survived it;
 *   2. a Google/Microsoft "link" request started before a reset could be
 *      completed after it (and an API key could be made in the same gap);
 *   5. two resets of one account at the same moment deadlocked;
 *   6. the reset's activity row was written inside its transaction;
 *   7. wrong passwords sent at the same moment did not all count.
 *
 * Without a database this proves the pure decisions and, by reading the
 * source, that every place that makes a session or a link passes and checks
 * the account's "sessions valid from" mark. The real SQL - including the bad
 * interleavings forced step by step and two real processes resetting at the
 * same moment - runs in tests/integration/account_recovery_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (independent review findings 1, 2, 5, 6, 7 - #31)
 */

requireFn('fn_password.php');
requireFn('fn_auth.php');
requireFn('fn_migrations.php');
requireFn('fn_oauth.php');

$svSiteRoot = CUERCODE_FUNCTIONS_DIR . '..' . DIRECTORY_SEPARATOR;
$svAuth     = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_auth.php');
$svOauth    = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_oauth.php');
$svKeys     = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_keys.php');
$svCallback = (string) file_get_contents($svSiteRoot . 'public_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'callback' . DIRECTORY_SEPARATOR . 'index.php');

/**
 * ✂️ The text of one function, from "function NAME(" to the next "\nfunction ".
 */
function svBody(string $svSource, string $svName): string
{
    $svStart = strpos($svSource, 'function ' . $svName . '(');
    if ($svStart === false) {
        return '';
    }
    $svEnd = strpos($svSource, "\nfunction ", $svStart + 10);
    if ($svEnd === false) {
        $svEnd = strlen($svSource);
    }
    return substr($svSource, $svStart, $svEnd - $svStart);
}

// ==============================================================================
// 🧱 Migration 0003
// ==============================================================================
$svMigrationPath = $svSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '0003_sessions_valid_from.php';
assertTrue(is_file($svMigrationPath), 'migration 0003 exists');
$svMigration = migrationLoadFile($svMigrationPath);
assertTrue($svMigration['ok'] === true, 'migration 0003 loads');
assertEquals([], migrationValidateDefinition($svMigration['definition'], '0003_sessions_valid_from'), 'migration 0003 passes every runner check (additive, one column per step, back-quoted)');
$svSteps = $svMigration['definition']['steps'];
assertEquals(2, count($svSteps), 'migration 0003 has two steps');
assertEquals(['add_column', 'tblUsers', 'sessionsValidFrom'], [$svSteps[0]['type'], $svSteps[0]['table'], $svSteps[0]['name']], 'step 1 adds tblUsers.sessionsValidFrom');
assertEquals(['add_column', 'tblUserSessions', 'sessionsValidFromSeen'], [$svSteps[1]['type'], $svSteps[1]['table'], $svSteps[1]['name']], 'step 2 adds tblUserSessions.sessionsValidFromSeen');
foreach ($svSteps as $svStep) {
    assertContains('DATETIME(6) NULL', $svStep['sql'], 'migration 0003: ' . $svStep['name'] . ' is a nullable time to the millionth of a second');
}
$svInstall = (string) file_get_contents($svSiteRoot . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql');
assertFalse(str_contains($svInstall, 'sessionsValidFrom'), 'install.sql (the frozen baseline) is NOT changed: the columns come only from migration 0003');

// ==============================================================================
// 🕐 Finding 1: every session carries, and is checked against, the mark
// ==============================================================================
assertFalse(function_exists('dbQuery'), 'sanity: no database in this test process');
assertFalse(authSessionsValidFromReady(), 'with no database, the mark columns are not "ready" (the old behaviour)');

$svIssue = svBody($svAuth, 'issueUserSession');
assertContains('function issueUserSession(int $userID, string $ipAddress, string $userAgent, ?string $sessionsValidFromSeen): string', $svAuth, 'issueUserSession() REQUIRES the mark the caller read (no default)');
assertContains('`u`.`sessionsValidFrom` <=> ?', $svIssue, 'issueUserSession() writes the session only while the account mark is still the one the caller saw');
assertContains('INSERT INTO `tblUserSessions` (`sessionID`, `userID`, `ipAddress`, `userAgent`, `expiresAt`, `sessionsValidFromSeen`)', $svIssue, '...and stores the mark on the session');
assertContains("if (\$inserted !== 1) {", $svIssue, '...and reports "no session" when nothing was written');
assertContains("return '';", $svIssue, '...as an empty answer');

$svCurrent = svBody($svAuth, 'currentUser');
assertContains('(`s`.`sessionsValidFromSeen` <=> `u`.`sessionsValidFrom`) AS `validFromMatches`', $svCurrent, 'currentUser() compares the session\'s copy with the account mark in the database');
assertContains("if ((int) \$row['validFromMatches'] !== 1) {", $svCurrent, 'currentUser() refuses a session whose copy is not the current mark');
assertTrue(strpos($svCurrent, 'validFromMatches\'] !== 1') < strpos($svCurrent, 'UPDATE `tblUserSessions` SET `lastSeenAt`'), '...before it touches the session');
assertContains("'sessionsValidFrom' => \$sessionsValidFrom,", $svCurrent, 'currentUser() returns the mark (for the Google/Microsoft link check)');

$svLogin = svBody($svAuth, 'loginUser');
assertContains("\$validFromColumn = ', `sessionsValidFrom`';", $svLogin, 'loginUser() reads the mark...');
assertTrue(strpos($svLogin, '`failedLoginCount`, `lockedUntil`\' . $validFromColumn') !== false, '...in the SAME statement as the password hash');
assertContains('issueUserSession($userID, $ipAddress, $userAgent, $sessionsValidFromSeen)', $svLogin, 'loginUser() passes the mark it read');
assertContains("if (\$issuedSession === '') {", $svLogin, 'loginUser() reports a failure when the session was refused');
assertContains('WHERE `userID` = ? AND `passwordHash` = ?', $svLogin, 'F1: the automatic re-hash never writes the OLD password back over a reset one');

$svDoors = svBody($svAuth, 'accountRemoveOtherWaysIn');
assertTrue(strpos($svDoors, 'authSessionsValidFromReady()') < strpos($svDoors, '$statements = ['), 'accountRemoveOtherWaysIn() (reset AND admin grant) moves the mark first');

$svReady = svBody($svAuth, 'authSessionsValidFromReady');
assertContains('`information_schema`.`COLUMNS`', $svReady, 'readiness is asked of INFORMATION_SCHEMA (a missing column is "0", not an error)');
assertContains('$ready = true;', $svReady, '...and a failed question answers "ready" (fail closed)');
$svAdminSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_admin.php');
assertContains('authSessionsValidFromReady(true);', $svAdminSource, 'the admin upgrade page forgets the remembered answer after applying upgrades');

// 🔢 Every place that makes a session passes a mark (the only two today).
$svCallers = [];
foreach ([CUERCODE_FUNCTIONS_DIR, $svSiteRoot . 'public_html' . DIRECTORY_SEPARATOR] as $svDir) {
    $svIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($svDir, \FilesystemIterator::SKIP_DOTS));
    foreach ($svIterator as $svFile) {
        if (!$svFile->isFile() || $svFile->getExtension() !== 'php') {
            continue;
        }
        // 🧽 Comments removed, so only real calls are counted.
        $svText = php_strip_whitespace($svFile->getPathname());
        if (preg_match_all('/(?<!function )issueUserSession\(([^;]*)\);/', $svText, $svMatches) > 0) {
            foreach ($svMatches[1] as $svArgs) {
                $svCallers[] = basename(dirname($svFile->getPathname())) . '/' . $svFile->getFilename() . ': ' . trim($svArgs);
            }
        }
    }
}
sort($svCallers);
assertEquals([
    '_functions/fn_auth.php: $userID, $ipAddress, $userAgent, $sessionsValidFromSeen',
    'callback/index.php: (int) $resolvedUserId, $clientIp, $userAgent, $sessionsValidFromSeen',
], $svCallers, 'exactly two places make a session (password, Google/Microsoft) and both pass the mark (passkeys are not built yet)');

// ==============================================================================
// 🔗 Finding 2: Google/Microsoft link request, and the API-key gap
// ==============================================================================
$svUser = ['userID' => 7, 'sessionsValidFrom' => '2026-11-25 10:00:00.000001'];
assertEquals('ok', oauthLinkRequestCheck($svUser, 7, true, '2026-11-25 10:00:00.000001'), 'link check: same account, same mark -> ok');
assertEquals('not_signed_in', oauthLinkRequestCheck(null, 7, true, '2026-11-25 10:00:00.000001'), 'link check: nobody signed in now -> refused');
assertEquals('different_account', oauthLinkRequestCheck(['userID' => 8, 'sessionsValidFrom' => null], 7, true, null), 'link check: a different account is signed in -> refused');
assertEquals('account_secured', oauthLinkRequestCheck($svUser, 7, true, '2026-11-25 09:59:59.999999'), 'link check: the mark moved (a reset) -> refused');
assertEquals('account_secured', oauthLinkRequestCheck($svUser, 7, true, null), 'link check: the mark moved from "never" -> refused');
assertEquals('ok', oauthLinkRequestCheck(['userID' => 7, 'sessionsValidFrom' => null], 7, true, null), 'link check: never moved, still never moved -> ok');
assertEquals('account_secured', oauthLinkRequestCheck(['userID' => 7, 'sessionsValidFrom' => null], 7, false, null), 'link check: a request saved before this fix (no mark) -> refused (fail closed)');
assertEquals('ok', oauthLinkRequestCheck(['userID' => '7', 'sessionsValidFrom' => null], 7, true, null), 'link check: the account number may arrive as text');
foreach (['not_signed_in', 'different_account', 'account_secured'] as $svReason) {
    assertContains('Nothing was linked', oauthLinkRequestRefusalMessage($svReason), 'link refusal message for ' . $svReason . ' says plainly that nothing was linked');
}

$svBegin = svBody($svOauth, 'beginAuth');
assertContains("'link_valid_from' => \$linkValidFrom,", $svBegin, 'beginAuth() saves the account mark with a link request');
$svComplete = svBody($svOauth, 'completeAuth');
assertContains("'linkValidFromSaved' => \$linkValidFromSaved,", $svComplete, 'completeAuth() hands the saved mark on');
$svApply = svBody($svOauth, 'applyOAuthAccountLink');
assertContains('?string $requestingUserValidFrom = null', $svApply, 'applyOAuthAccountLink() takes the saved mark');
assertContains("(`sessionsValidFrom` <=> ?) AS `markMatches` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE", $svApply, 'the link is re-checked with the account row LOCKED');
assertTrue(strpos($svApply, 'LIMIT 1 FOR UPDATE') < strpos($svApply, "INSERT INTO `tblUserOAuth` (`userID`, `provider`, `providerSubjectID`, `tokensEncrypted`) VALUES (?, ?, ?, ?)',\n                    'isss',\n                    [\$requestingUserID"), '...before the link row is written, in the same transaction');
assertContains("'sessionsValidFromSeen' => \$linkedUserValidFrom,", $svApply, 'a Google/Microsoft SIGN-IN carries the mark read with the linked sign-in');
assertContains('INNER JOIN `tblUsers` AS `u` ON `u`.`userID` = `o`.`userID`', $svApply, '...read in the SAME statement as the link');

assertContains('oauthLinkRequestCheck(', $svCallback, 'callback: a link request is checked against who is signed in NOW');
assertContains('currentUser(),', $svCallback, 'callback: ...using currentUser()');
assertTrue(strpos($svCallback, 'oauthLinkRequestCheck(') < strpos($svCallback, '$linkResult = applyOAuthAccountLink('), 'callback: the check runs before anything is linked');
assertContains('$verifiedIdentity[\'linkValidFrom\']', $svCallback, 'callback: the saved mark is passed to the locked re-check');

$svCreate = svBody($svKeys, 'createApiKeyForUser');
assertTrue(strpos($svCreate, 'begin_transaction()') < strpos($svCreate, 'authLockLiveSessionForUser($userId)') && strpos($svCreate, 'authLockLiveSessionForUser($userId)') < strpos($svCreate, 'INSERT INTO `tblAPIKeys`'), 'API key: the session is re-checked under lock, in the same transaction, before the key is written');
$svLive = svBody($svAuth, 'authLockLiveSessionForUser');
assertTrue(strpos($svLive, 'FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE') < strpos($svLive, 'FROM `tblUserSessions` WHERE `sessionID` = ? LIMIT 1 FOR UPDATE'), 'API key: the account row is locked BEFORE the session row (the same order as a reset)');
assertFalse(authLockLiveSessionForUser(1), 'API key: with no signed-in session, the answer is "no"');

// ==============================================================================
// 🔢 Finding 7: wrong passwords at the same moment all count
// ==============================================================================
$svFailed = svBody($svAuth, 'recordFailedLogin');
assertContains('function recordFailedLogin(int $userID): ?int', $svAuth, 'recordFailedLogin() no longer takes a count worked out by the caller');
assertContains('`failedLoginCount` = LEAST(`failedLoginCount` + 1, 65535)', $svFailed, 'the database adds one itself (capped at the column\'s largest value)');
assertTrue(strpos($svFailed, 'LEAST(`failedLoginCount` + 1') < strpos($svFailed, 'SELECT `failedLoginCount` FROM `tblUsers`'), '...and the new count is read back afterwards');
assertContains('computeLockoutExpiry($newFailedCount,', $svFailed, '...and the lock-out is decided from the read-back count');
assertEquals(null, recordFailedLogin(1), 'with no database it records nothing and says so (null)');
assertFalse(str_contains($svAuth, 'recordFailedLogin($userID, $failedCount + 1)'), 'no caller passes a precomputed count any more');
