<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The "someone signed up first with my email" fix (decision 0013)
 * ==============================================================================
 *
 * Path: tests/test_account_recovery.php
 *
 * Without a database this proves:
 *   - emailVerificationPasswordOutcome(): every case of "may this link
 *     activate the account?" (password typed, missing, wrong, locked,
 *     too many wrong tries, no password at all);
 *   - the no-database answers of verifyEmailToken(), resetPasswordWithToken()
 *     and emailVerificationTokenNeedsPassword();
 *   - by reading the source: the order of the steps inside the ONE reset
 *     transaction, the shared "remove every other way in" function, the
 *     activity row holding counts only, the pages, the script and the API.
 * The real SQL runs in tests/integration/account_recovery_real_mysql.php.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (decision 0013, #31 / #128)
 * @see         docs/decisions/0013-signup-first-trick-verification-needs-password-reset-removes-all.md
 */

requireFn('fn_password.php');
requireFn('fn_auth.php');

$arSiteRoot = CUERCODE_FUNCTIONS_DIR . '..' . DIRECTORY_SEPARATOR;
$arPublic   = $arSiteRoot . 'public_html' . DIRECTORY_SEPARATOR;

// ==============================================================================
// 🔑 The decision (pure)
// ==============================================================================
// Arguments: has password, typed, locked, correct, failed count if wrong, limit.
assertEquals('activate', emailVerificationPasswordOutcome(false, false, false, false, 1, 5), 'no password on the account (Microsoft/Google only): the link alone activates it, as before');
assertEquals('activate', emailVerificationPasswordOutcome(false, true, true, false, 9, 5), '...even with something typed or a lock-out');
assertEquals('password_needed', emailVerificationPasswordOutcome(true, false, false, false, 1, 5), 'a password account with nothing typed: ask for it (not counted as a wrong try)');
assertEquals('password_needed', emailVerificationPasswordOutcome(true, false, true, false, 9, 5), '...even while locked');
assertEquals('locked', emailVerificationPasswordOutcome(true, true, true, true, 1, 5), 'locked out: refused even with the right password');
assertEquals('activate', emailVerificationPasswordOutcome(true, true, false, true, 1, 5), 'the right password: activate');
assertEquals('wrong_password', emailVerificationPasswordOutcome(true, true, false, false, 4, 5), 'a wrong password below the limit: refused, link still works');
assertEquals('wrong_password_link_stopped', emailVerificationPasswordOutcome(true, true, false, false, 5, 5), 'the wrong password that reaches the limit: the link stops working');
assertEquals('wrong_password_link_stopped', emailVerificationPasswordOutcome(true, true, false, false, 7, 5), '...and any past it');
assertEquals('wrong_password', emailVerificationPasswordOutcome(true, true, false, false, 99, 0), 'a limit of 0 means no limit (the link keeps working)');

// ==============================================================================
// 🗄️ No database
// ==============================================================================
assertFalse(function_exists('dbQuery'), 'sanity: no database in this test process');
$arNoDb = verifyEmailToken('abc', 'pw');
assertEquals(503, $arNoDb['httpCode'], 'verifyEmailToken() with no database: 503');
$arNoDb = resetPasswordWithToken('abc', 'A-long-enough-password-1');
assertEquals(503, $arNoDb['httpCode'], 'resetPasswordWithToken() with no database: 503');
assertTrue(emailVerificationTokenNeedsPassword('abc'), 'with no database, the page asks for a password (the safe answer)');
assertTrue(emailVerificationTokenNeedsPassword(''), 'with no link, the page asks for a password');

// ==============================================================================
// 🧱 The engine, read from the source
// ==============================================================================
$arAuth = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_auth.php');

/**
 * ✂️ The text of one function, from "function NAME(" to the next "\nfunction ".
 */
function arFunctionBody(string $arSource, string $arName): string
{
    $arStart = strpos($arSource, 'function ' . $arName . '(');
    if ($arStart === false) {
        return '';
    }
    $arEnd = strpos($arSource, "\nfunction ", $arStart + 10);
    if ($arEnd === false) {
        $arEnd = strlen($arSource);
    }
    return substr($arSource, $arStart, $arEnd - $arStart);
}

$arVerify = arFunctionBody($arAuth, 'verifyEmailToken');
assertContains('function verifyEmailToken(string $rawToken, #[\SensitiveParameter] ?string $password = null): array', $arAuth, 'verifyEmailToken() takes the password and hides it from stack traces');
assertContains('emailVerificationPasswordOutcome(', $arVerify, 'verifyEmailToken() asks the pure decision');
assertTrue(strpos($arVerify, 'adminUserHoldsGlobalAdminRole($userID)') < strpos($arVerify, 'emailVerificationPasswordOutcome('), 'the Global Admin refusal (review fix I1) still comes first');
assertContains('$recordedCount = recordFailedLogin($userID);', $arVerify, 'a wrong password counts toward the normal sign-in lock-out');
assertContains('emailVerificationPasswordOutcome(true, true, false, false, $recordedCount, $maxAttempts)', $arVerify, 'F7: the "stop the link" decision is taken again with the REAL count the database returned');
assertContains("logActivity('verification_link_stopped'", $arVerify, 'a link stopped after too many wrong passwords is logged (account number only)');
assertTrue(
    strpos($arVerify, 'begin_transaction()') < strpos($arVerify, 'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() \'')
    && strpos($arVerify, '`failedLoginCount` = 0') < strpos($arVerify, '$db->commit();' . "\n" . '    } catch'),
    'the link is used up and the account activated in ONE transaction (a failure leaves the link usable)'
);
// 🔐 Review finding 5: the account row is locked BEFORE the link is claimed (same order as a reset).
assertTrue(
    strpos($arVerify, 'begin_transaction()') < strpos($arVerify, "SELECT `status`, `passwordHash` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE")
    && strpos($arVerify, "SELECT `status`, `passwordHash` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE") < strpos($arVerify, 'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() \''),
    'F5: verify locks the account row first, then claims the link'
);
// 📣 Review nit: only a real activation says "verified, you can now log in".
assertContains('$activatedRows = $activateStmt->affected_rows;', $arVerify, 'nit: the activation UPDATE\'s changed-row count is checked');
assertContains('if ($activatedRows !== 1) {', $arVerify, 'nit: no change -> no "verified" message (rolled back)');
assertContains('had already been confirmed, so nothing was changed', $arVerify, 'nit: an already-active account is told nothing changed');
assertContains("if (\$accountStatus !== 'pending' && \$accountStatus !== 'active') {", $arVerify, 'nit: a suspended or deleted account gets the usual invalid answer');
assertContains("`status` = 'active', `failedLoginCount` = 0, `lockedUntil` = NULL", $arVerify, 'activation also clears the lock-out counter');
assertFalse(str_contains($arVerify, 'logActivity(\'verification_link_stopped\', $password'), 'the password never goes into a log call');

$arRequest = arFunctionBody($arAuth, 'requestPasswordResetWork');
assertContains("if (\$status !== 'active' && \$status !== 'pending') {", $arRequest, 'Forgot password also sends a link to a waiting (pending) account');

// ==============================================================================
// ⏱️ Review finding 3: limits and even timing for "Forgot password"
// ==============================================================================
$arFpDefaults = passwordResetRequestSettings(static function (string $key, mixed $default): mixed {
    return $default;
});
assertEquals(['minGapSeconds' => 60, 'dailyCap' => 5, 'ipPerHour' => 10], $arFpDefaults, 'F3: defaults are 60 s gap, 5 a day, 10 an hour per address (the same as "send me a new link")');
$arFpAsked = [];
passwordResetRequestSettings(static function (string $key, mixed $default) use (&$arFpAsked): mixed {
    $arFpAsked[] = $key;
    return $default;
});
assertEquals(['password_reset_request_min_gap_seconds', 'password_reset_request_daily_cap', 'password_reset_request_ip_per_hour'], $arFpAsked, 'F3: its own three settings are read (not the resend ones)');
$arFpClamped = passwordResetRequestSettings(static function (string $key, mixed $default): mixed {
    $values = ['password_reset_request_min_gap_seconds' => '1', 'password_reset_request_daily_cap' => '999', 'password_reset_request_ip_per_hour' => 'lots'];
    return $values[$key];
});
assertEquals(['minGapSeconds' => 10, 'dailyCap' => 50, 'ipPerHour' => 10], $arFpClamped, 'F3: silly values are pulled into the safe range; junk means the default');
$arFpThrows = passwordResetRequestSettings(static function (string $key, mixed $default): mixed {
    throw new \RuntimeException('settings unavailable');
});
assertEquals($arFpDefaults, $arFpThrows, 'F3: a settings reader that fails gives the defaults');
assertEquals(verificationResendSettings(static function (string $key, mixed $default): mixed {
    return $default;
}), ['minGapSeconds' => 60, 'dailyCap' => 5, 'ipPerHour' => 10], 'F3: the resend limits are unchanged by sharing the reader');

$arFpOk = ['ipRequestsInLastHour' => 1, 'accountIsEligible' => true, 'secondsSinceLastLink' => null, 'linksInLast24Hours' => 0];
assertEquals(['send' => true, 'reason' => 'send'], decidePasswordResetRequest($arFpOk, $arFpDefaults), 'F3: an eligible account with room to spare gets a link');
assertEquals('no_eligible_account', decidePasswordResetRequest(array_merge($arFpOk, ['accountIsEligible' => false]), $arFpDefaults)['reason'], 'F3: no account that can be reset -> nothing sent');
assertEquals('too_soon', decidePasswordResetRequest(array_merge($arFpOk, ['secondsSinceLastLink' => 59]), $arFpDefaults)['reason'], 'F3: inside the minimum gap -> nothing sent');
assertTrue(decidePasswordResetRequest(array_merge($arFpOk, ['secondsSinceLastLink' => 60]), $arFpDefaults)['send'], 'F3: exactly at the gap -> sent');
assertEquals('daily_cap', decidePasswordResetRequest(array_merge($arFpOk, ['linksInLast24Hours' => 5]), $arFpDefaults)['reason'], 'F3: the fifth link in a day was the last');
assertEquals('ip_limited', decidePasswordResetRequest(array_merge($arFpOk, ['ipRequestsInLastHour' => 11]), $arFpDefaults)['reason'], 'F3: the eleventh request from one address in an hour is refused');
assertEquals('ip_limited', decidePasswordResetRequest(array_merge($arFpOk, ['ipRequestsInLastHour' => 11, 'accountIsEligible' => false]), $arFpDefaults)['reason'], 'F3: the address limit is checked FIRST, whatever the account');

$arFpWrapper = arFunctionBody($arAuth, 'requestPasswordReset');
assertContains('$startedAtNanos = hrtime(true);', $arFpWrapper, 'F3: the request notes when it began');
assertContains('padResponseToMinimumDuration($startedAtNanos, CUERCODE_PASSWORD_RESET_MIN_RESPONSE_MICROSECONDS);', $arFpWrapper, 'F3: ...and waits out the fixed minimum time');
assertEquals(250000, CUERCODE_PASSWORD_RESET_MIN_RESPONSE_MICROSECONDS, 'F3: the minimum is 250 ms (as for "send me a new link")');
assertContains('authRecordThenCountLinkRequest($ipAddress, CUERCODE_PASSWORD_RESET_REQUEST_ENDPOINT)', $arRequest, 'M-1: the address allowance records THIS request first, then counts including it (every address uses it up alike, and simultaneous requests cannot all slip under it)');
$arRecordCount = arFunctionBody($arAuth, 'authRecordThenCountLinkRequest');
assertTrue(strpos($arRecordCount, 'recordAPIRequest(null, $ipAddress, $endpoint)') < strpos($arRecordCount, 'countLinkRequestsFromIp($ipAddress, $endpoint)'), 'M-1: ...record, THEN count');
assertTrue(
    strpos($arRecordCount, "SELECT GET_LOCK(?, ?)") < strpos($arRecordCount, 'recordAPIRequest(null, $ipAddress, $endpoint)')
    && strpos($arRecordCount, 'countLinkRequestsFromIp($ipAddress, $endpoint)') < strpos($arRecordCount, "SELECT RELEASE_LOCK(?)"),
    'M-1: ...one request at a time per address (a named lock around the two steps), so the count is exact'
);
assertContains('hash(\'sha256\', $databaseName . \'|\' . $endpoint . \'|\'', $arRecordCount, 'M-1: the lock name is a hash (never the address itself), per database and per form');
assertTrue(
    strpos($arRequest, 'begin_transaction()') < strpos($arRequest, "authLockAccountRowNoWait(\$userID, '`status`')")
    && strpos($arRequest, 'authLockAccountRowNoWait(') < strpos($arRequest, 'authLinkHistory(\'tblPasswordResets\', $userID)')
    && strpos($arRequest, 'authLinkHistory(\'tblPasswordResets\', $userID)') < strpos($arRequest, 'INSERT INTO `tblPasswordResets`')
    && strpos($arRequest, 'INSERT INTO `tblPasswordResets`') < strrpos($arRequest, '$db->commit()'),
    'M-1: Forgot password locks the account row, then reads the history, decides and writes the link - one transaction'
);
assertTrue(strpos($arRequest, "if (\$status !== 'active' && \$status !== 'pending') {") < strpos($arRequest, 'begin_transaction()'), 'M-1: an unknown address takes no lock at all (no gap lock on the address index)');
assertFalse(str_contains($arRequest, '`email` = ? LIMIT 1 FOR UPDATE') || str_contains($arRequest, "emailExactMatchSql('email') . ' LIMIT 1 FOR UPDATE"), 'M-1: the address itself is never read with a lock');
$arHistory = arFunctionBody($arAuth, 'authLinkHistory');
assertContains("if (\$table !== 'tblPasswordResets' && \$table !== 'tblEmailVerifications') {", $arHistory, 'M-1: the shared history reader accepts only the two link tables');
assertTrue(strpos($arRequest, 'recordAPIRequest(') < strpos($arRequest, "FROM `tblUsers` WHERE ' . emailExactMatchSql('email')"), 'F3: ...before the account is even looked up (by its exact address, fourth-round review Medium 1)');
assertContains("limitedRowWrittenRecently((int) \$row['userID'], 'password_reset_request_limited')", $arRequest, 'F3: at most one "refused by the limit" row per account per hour');
assertContains("\$decision['reason'] !== 'too_soon'", $arRequest, 'F3: a double-click inside the gap writes no row at all');
assertFalse(str_contains($arRequest, "'httpCode'  => 429"), 'F3: a limited request gets the SAME 200 answer (never a different code)');
$arApiAccounts = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_accounts.php');
assertContains('$result = requestPasswordReset($email, $clientIP);', $arApiAccounts, 'F3: the API route calls the same padded, limited function');
assertContains('$clientIP = getClientIP();', $arApiAccounts, 'F3: ...with the visitor address from getClientIP() (decision 0014)');

$arReset = arFunctionBody($arAuth, 'resetPasswordWithToken');
assertContains('#[\SensitiveParameter] string $newPassword', $arReset, 'the new password is hidden from stack traces');
$arOrder = [
    'hashPassword($newPassword)',
    'begin_transaction()',
    'FOR UPDATE',
    // Codex C3 finding 3 (2026-11-25): the claim also requires "not expired".
    "'UPDATE `tblPasswordResets` SET `usedAt` = NOW() WHERE `resetID` = ? AND `usedAt` IS NULL AND `expiresAt` > NOW()'",
    'adminUserHoldsGlobalAdminRole($userID)',
    '`passwordHash` = ?',
    'accountRemoveOtherWaysIn($userID, true)',
    '$db->commit()',
    "'password_reset_completed'",
];
$arLast = -1;
$arInOrder = true;
foreach ($arOrder as $arStep) {
    $arAt = strpos($arReset, $arStep);
    if ($arAt === false || $arAt < $arLast) {
        $arInOrder = false;
    }
    $arLast = (int) $arAt;
}
assertTrue($arInOrder, 'reset: hash first (outside), then ONE transaction: lock the account FIRST (F5), use the link, admin check, new password, remove every other way in, commit; the activity row AFTER the commit (F6)');
assertFalse(str_contains($arReset, '5. writes an activity row'), 'F6: the docblock no longer calls the activity row a step of the transaction');
assertContains("`status` = 'active', `emailVerifiedAt` = COALESCE(`emailVerifiedAt`, NOW())", $arReset, 'reset on a waiting account activates it and marks the email checked');
assertContains("`status` = 'pending' AND \" . adminSqlNotGlobalAdminCondition()", $arReset, '...never for a Global Admin (the UPDATE itself refuses one)');
assertContains("if (\$status !== 'active' && \$status !== 'pending') {", $arReset, 'a suspended or deleted account cannot be reset');
assertContains("['account_activated' => \$activated, 'removed' => \$removed]", $arReset, 'the activity row holds counts only');
foreach (['$newPassword', '$newHash', '$rawToken', '$tokenHash'] as $arSecret) {
    $arLogAt = strpos($arReset, "'password_reset_completed'");
    $arLogCall = substr($arReset, (int) $arLogAt, 300);
    assertFalse(str_contains($arLogCall, $arSecret), 'the activity row never carries ' . $arSecret);
}
assertContains('signed out everywhere', $arReset, 'success message: says the account was signed out everywhere');
assertContains('API keys', $arReset, 'success message: says the API keys were switched off');
assertContains('Google or Microsoft', $arReset, 'success message: says linked Google/Microsoft sign-ins were removed');
assertContains('make new API keys and link Google or Microsoft again', $arReset, 'success message: says they must be set up again');

$arDoors = arFunctionBody($arAuth, 'accountRemoveOtherWaysIn');
foreach (['tblUserSessions', 'tblAPIKeys', 'tblPasswordResets', 'tblUserOAuth', 'tblWebAuthnCredentials', 'tblEmailVerifications'] as $arTable) {
    assertContains('`' . $arTable . '`', $arDoors, 'accountRemoveOtherWaysIn() covers ' . $arTable);
}
assertContains('if ($alsoCancelVerificationLinks) {', $arDoors, 'verification links are cancelled only when asked (the reset asks; the admin grant does not)');
assertContains('throw new \RuntimeException', $arDoors, 'any failure is thrown so the caller rolls everything back');
// 🕐 Review finding 1: the shared function moves the "sessions valid from" mark first.
//    Since the final review (I-1) the mark move is the shared helper
//    authMoveSessionsValidFrom(), also used by the voluntary password change
//    and "Sign out everywhere".
assertTrue(strpos($arDoors, 'authMoveSessionsValidFrom($userID);') !== false && strpos($arDoors, 'authMoveSessionsValidFrom($userID);') < strpos($arDoors, '`tblUserSessions`'), 'F1: accountRemoveOtherWaysIn() moves the mark forward before ending sessions');
$arMove = arFunctionBody($arAuth, 'authMoveSessionsValidFrom');
assertContains('`sessionsValidFrom` = CASE', $arMove, 'F1: the shared helper moves the mark');
assertContains('UTC_TIMESTAMP(6)', $arMove, 'F1: the mark is UTC, to the millionth of a second');
assertContains('DATE_ADD(`sessionsValidFrom`, INTERVAL 1 MICROSECOND)', $arMove, 'F1: the new mark is always later than the old one (two resets in one instant still differ)');
assertContains("throw new \RuntimeException('the sign-in mark could not be moved')", $arMove, 'F1: a mark that did not move rolls everything back');
assertContains("throw new \RuntimeException('the sign-in mark could not be read back')", $arMove, 'I-1: the new mark is read back (so a kept session can be given it), and a failure rolls back');
assertFalse(str_contains($arAuth, 'CUERCODE_ROLE_GLOBAL_ADMIN'), 'fn_auth.php still never names the role number');

// ==============================================================================
// 🖥️ Pages, script and API
// ==============================================================================
$arVerifyPage = (string) file_get_contents($arPublic . 'verify-email' . DIRECTORY_SEPARATOR . 'index.php');
assertContains('verifyEmailToken($postedToken, $postedPassword)', $arVerifyPage, 'verify page: passes the typed password');
assertContains('emailVerificationTokenNeedsPassword($rawToken)', $arVerifyPage, 'verify page: asks (read-only) whether the password box is needed');
assertContains('name="password"', $arVerifyPage, 'verify page: has a password box');
assertContains('autocomplete="current-password"', $arVerifyPage, 'verify page: the box is marked as the current password');
assertContains('<label for="verify-password"', $arVerifyPage, 'verify page: the box has a label');
assertContains('href="/forgot-password/"', $arVerifyPage, 'verify page: points to Forgot password');
assertContains('csrfField()', $arVerifyPage, 'verify page: the form carries the CSRF field');
assertContains('validateCSRFToken(', $arVerifyPage, 'verify page: the POST checks the CSRF token');
assertFalse(str_contains($arVerifyPage, 'echo $postedPassword') || str_contains($arVerifyPage, 'htmlspecialchars($postedPassword'), 'verify page: never writes the password back into the page');
assertFalse(str_contains($arVerifyPage, '<table'), 'verify page: no layout table');

$arResetPage = (string) file_get_contents($arPublic . 'reset-password' . DIRECTORY_SEPARATOR . 'index.php');
assertContains('id="reset-what-happens"', $arResetPage, 'reset page: says what a reset does before the button');
assertContains('switches off', $arResetPage, 'reset page: mentions API keys being switched off');
$arForgotPage = (string) file_get_contents($arPublic . 'forgot-password' . DIRECTORY_SEPARATOR . 'index.php');
assertContains('setting a new password activates it', $arForgotPage, 'forgot page: says it also works for a waiting account');

$arScript = (string) file_get_contents($arPublic . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'cuercode-account.js');
assertContains('payload.password = $password.val();', $arScript, 'script: sends the typed password to the API');
assertContains("\$password.val('');", $arScript, 'script: clears a rejected password');

$arApi = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_accounts.php');
assertContains('$result = verifyEmailToken($token, $password);', $arApi, 'API: POST /auth/verify-email passes the password');

$arSpec = json_decode((string) file_get_contents($arPublic . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'openapi.json'), true);
assertTrue(isset($arSpec['components']['schemas']['VerifyEmailRequest']['properties']['password']), 'OpenAPI: the verify-email request documents "password"');
assertEquals(['token'], $arSpec['components']['schemas']['VerifyEmailRequest']['required'], 'OpenAPI: "password" is not marked required (accounts with no password do not send one)');
assertContains('decision 0013', $arSpec['paths']['/auth/reset-password']['post']['description'], 'OpenAPI: the reset route describes what a reset also does');
assertContains('waiting for email verification', $arSpec['paths']['/auth/request-password-reset']['post']['description'], 'OpenAPI: request-password-reset says waiting accounts get a link');

// ==============================================================================
// 🔁 Independent review (Codex C3 finding 1, 2026-11-25): after a mistake
//    (passwords that do not match, or too weak), the reset form is shown
//    again. Its hidden link token used to come only from the web address,
//    which the form does not post back to, so it was EMPTY and the next try
//    failed with "invalid or expired". The rule is now one function, used by
//    both the reset page and the verify page.
// ==============================================================================
assertEquals('fromlink', accountLinkTokenForForm('fromlink', 'posted'), 'C3-1: the token in the web address wins');
assertEquals('posted', accountLinkTokenForForm('', 'posted'), 'C3-1: with no token in the address, the posted one is kept for the next try');
assertEquals('', accountLinkTokenForForm('', ''), 'C3-1: no token anywhere gives none');
assertEquals('', accountLinkTokenForForm('', str_repeat('a', 129)), 'C3-1: an over-long posted token is not put back');
assertEquals(str_repeat('a', 128), accountLinkTokenForForm('', str_repeat('a', 128)), 'C3-1: a 128-character posted token is kept');
$arResetPostPos   = strpos($arResetPage, '$postedToken = trim((string) $_POST[\'token\']);');
$arResetKeepPos   = strpos($arResetPage, '$rawToken = accountLinkTokenForForm($rawToken, $postedToken);');
$arResetRenderPos = strpos($arResetPage, 'name="token" id="reset-token"');
assertTrue($arResetPostPos !== false && $arResetKeepPos !== false && $arResetPostPos < $arResetKeepPos && $arResetKeepPos < $arResetRenderPos, 'C3-1: the reset page keeps the posted token for the redisplayed form (after reading it, before drawing the form)');
assertContains('$rawToken = accountLinkTokenForForm($rawToken, $postedToken);', $arVerifyPage, 'C3-1: the verify page uses the same rule');

// ⏱️ Codex C3 finding 5 (2026-11-25): the two emailed-link request forms lock
//    the account row WITHOUT waiting (the timing itself is measured on a real
//    database in tests/integration/account_recovery_real_mysql.php, CX).
$arNoWait = arFunctionBody($arAuth, 'authLockAccountRowNoWait');
assertContains('FOR UPDATE NOWAIT', $arNoWait, 'C3-5: the account row is locked with NOWAIT');
assertContains('[3572, 1205]', $arNoWait, 'C3-5: both engines\' "row is busy" codes are recognised (MySQL 3572, MariaDB 1205)');
assertContains('throw $lockError;', $arNoWait, 'C3-5: any other database error is still raised, not treated as "busy"');
