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
    strpos($arVerify, 'begin_transaction()') < strpos($arVerify, "SELECT `status` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE")
    && strpos($arVerify, "SELECT `status` FROM `tblUsers` WHERE `userID` = ? LIMIT 1 FOR UPDATE") < strpos($arVerify, 'UPDATE `tblEmailVerifications` SET `usedAt` = NOW() \''),
    'F5: verify locks the account row first, then claims the link'
);
// 📣 Review nit: only a real activation says "verified, you can now log in".
assertContains('$activatedRows = $activateStmt->affected_rows;', $arVerify, 'nit: the activation UPDATE\'s changed-row count is checked');
assertContains('if ($activatedRows !== 1) {', $arVerify, 'nit: no change -> no "verified" message (rolled back)');
assertContains('had already been confirmed, so nothing was changed', $arVerify, 'nit: an already-active account is told nothing changed');
assertContains("if (\$accountStatus !== 'pending' && \$accountStatus !== 'active') {", $arVerify, 'nit: a suspended or deleted account gets the usual invalid answer');
assertContains("`status` = 'active', `failedLoginCount` = 0, `lockedUntil` = NULL", $arVerify, 'activation also clears the lock-out counter');
assertFalse(str_contains($arVerify, 'logActivity(\'verification_link_stopped\', $password'), 'the password never goes into a log call');

$arRequest = arFunctionBody($arAuth, 'requestPasswordReset');
assertContains("if (\$status === 'active' || \$status === 'pending') {", $arRequest, 'Forgot password also sends a link to a waiting (pending) account');

$arReset = arFunctionBody($arAuth, 'resetPasswordWithToken');
assertContains('#[\SensitiveParameter] string $newPassword', $arReset, 'the new password is hidden from stack traces');
$arOrder = [
    'hashPassword($newPassword)',
    'begin_transaction()',
    'FOR UPDATE',
    "'UPDATE `tblPasswordResets` SET `usedAt` = NOW() WHERE `resetID` = ? AND `usedAt` IS NULL'",
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
assertTrue(strpos($arDoors, '`sessionsValidFrom` = CASE') !== false && strpos($arDoors, '`sessionsValidFrom` = CASE') < strpos($arDoors, '`tblUserSessions`'), 'F1: accountRemoveOtherWaysIn() moves the mark forward before ending sessions');
assertContains('UTC_TIMESTAMP(6)', $arDoors, 'F1: the mark is UTC, to the millionth of a second');
assertContains('DATE_ADD(`sessionsValidFrom`, INTERVAL 1 MICROSECOND)', $arDoors, 'F1: the new mark is always later than the old one (two resets in one instant still differ)');
assertContains("throw new \RuntimeException('the sign-in mark could not be moved')", $arDoors, 'F1: a mark that did not move rolls everything back');
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
