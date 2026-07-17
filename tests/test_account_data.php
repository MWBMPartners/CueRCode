<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - GDPR/CCPA Data-Subject-Rights Tests (#37, epic #52)
 * ==============================================================================
 *
 * Path: tests/test_account_data.php
 *
 * Covers the DB-INDEPENDENT surface of the two new own-account endpoints in
 * fn_api_account.php: `GET /api/v1/account/export` (collectAccountExportData())
 * and `POST /api/v1/account/erase` (eraseUserAccount(), buildErasedAccountEmail()).
 * Both engine functions fail closed/return their "service unavailable" or
 * "no confirmation" shape BEFORE ever touching a database connection, so a
 * meaningful slice of their contract IS genuinely testable here with zero DB:
 *   - eraseUserAccount()'s `$confirm === false` short-circuit (400
 *     CONFIRMATION_REQUIRED) runs before any dbQuery()/getDB() call at all.
 *   - Both engine functions' "dbQuery()/getDB() unavailable" fail-safe
 *     branches (503 SERVICE_UNAVAILABLE / an empty export skeleton) are
 *     exercised directly, since this bare harness never defines dbQuery().
 *   - buildErasedAccountEmail() is a pure, deterministic string builder.
 * Section 8 (static, source-text only) pins the shape of the security-
 * critical invariants that CANNOT be proven in a no-DB, single-process
 * harness: both new HTTP handlers call accountManagementAllowedFor($auth)
 * before anything else (mirroring test_api_keys.php's Section 8 convention
 * for its own CSRF-only guard), eraseUserAccount() wraps its mutations in a
 * single transaction, anonymizes every documented PII column, revokes/
 * deactivates/unlinks/soft-deletes every documented linked resource, the
 * router registers both new routes with the session-only shape, and
 * openapi.json documents both paths with CsrfAuth-only security.
 *
 * ⚠️ SKIPPED (DB/HTTP-context-dependent - out of scope for this dependency-
 *    free harness, same convention as test_api_keys.php/test_qrcodes.php's
 *    own "SKIPPED" sections):
 *   - collectAccountExportData()'s actual data-gathering (profile/saved
 *     codes/sessions/subscriptions/linked-providers/API-keys queries),
 *     eraseUserAccount()'s full password-verify + transaction + anonymize
 *     path, and both HTTP handlers all require a live MySQLi connection PLUS
 *     the real HTTP router ($_SERVER/session/$auth context).
 *   - These were exercised end-to-end THIS SESSION against a throwaway
 *     scratch database (`cuercode_scratch_dsr`, dropped after use; no
 *     credentials file ever written - DB_HOST/DB_NAME/DB_USER/DB_PASS/
 *     ENCRYPTION_KEY/ENCRYPTION_SALT defined inline via a `php -S`
 *     `auto_prepend_file`) and a live `php -S` instance of the real router
 *     (public_html/api/v1/index.php) plus public_html/account/export/index.php
 *     and the no-JS "Delete my account" form on public_html/account/index.php.
 *     Two independently-registered accounts, Alice and Bob, each with a
 *     saved QR code and (Alice) a self-service API key:
 *       - `GET /api/v1/account/export` (Alice, session/CSRF) returned 200
 *         with `Content-Disposition: attachment; filename="cuercode-data-
 *         export.json"` and Alice's OWN profile/saved-code/session/api-key
 *         data - Bob's email, his saved code's label, and his QR UUID were
 *         ALL absent from Alice's export (grep-confirmed zero matches); the
 *         export contained no passwordHash/apiKeyHash/tokensEncrypted/raw
 *         session token anywhere.
 *       - The SAME export request via `X-API-Key` (Alice's own, fully-
 *         scoped key) got `403 ACCOUNT_MANAGEMENT_FORBIDDEN` - identical to
 *         every other account/* route (F-026 pattern).
 *       - `POST /api/v1/account/erase`: API-key auth -> 403
 *         ACCOUNT_MANAGEMENT_FORBIDDEN; wrong current_password -> 401
 *         INVALID_PASSWORD with ZERO DB mutation (confirmed by direct
 *         query); missing/false confirm -> 400 CONFIRMATION_REQUIRED with
 *         zero mutation; a request body smuggling `user_id`/`userID`/
 *         `target_user` fields pointing at Bob was completely ignored - the
 *         wrong-password check still ran against ALICE's own account (own-
 *         account-only proven: there is no field anywhere in this request
 *         that can select a different target).
 *       - The correct password + `"confirm": true` -> 200, and a direct DB
 *         check confirmed: `email` -> `deleted-1@deleted.invalid`,
 *         `displayName` -> 'Deleted user', `passwordHash` -> NULL, `status`
 *         -> 'deleted', `erasedAt` populated; Alice's ONE session revoked,
 *         her ONE API key deactivated (`isActive` = 0), her ONE saved QR
 *         code soft-deleted (`deletedAt` populated) - Bob's row/session/
 *         saved code were completely UNCHANGED throughout. The
 *         `tblActivityLog` `account_erased`/`account_data_exported` rows
 *         contained no email/display name anywhere (`entityID` = 1 only).
 *       - Re-logging in with Alice's ORIGINAL email+password afterwards
 *         failed with the generic `401 INVALID_CREDENTIALS` (the anonymized
 *         email no longer matches any credential, and the cleared
 *         passwordHash can never verify) - a re-request with the SAME
 *         cookie+CSRF token that had just succeeded now got `401
 *         AUTH_REQUIRED` (the session was genuinely ended, not merely
 *         revoked-but-still-locally-authenticated).
 *       - The plain-navigable `GET /account/export/` (cookie only, no CSRF
 *         header - Bob) and the no-JS `POST /account/` "Delete my account"
 *         form (Bob: wrong-case typed phrase rejected with zero mutation;
 *         correct phrase + ack + password -> 302 to `/login/?erased=1`,
 *         Bob's row anonymized identically) both independently confirmed
 *         the SAME collectAccountExportData()/eraseUserAccount() engine
 *         functions the JSON API calls.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (#37, epic #52)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_api_account.php
 * @see         SECURITY.md - the own-account-only/session-only/password-confirmed audit for this stage
 */

// ==============================================================================
// 🧰 Load the DB-independent production function file (NO database connection)
// ==============================================================================
// fn_api_account.php only DEFINES functions at include time (every DB touch is
// lazy, inside a function body via dbQuery()/getDB()), so including it opens
// no connection - identical convention to test_api_keys.php's inclusion of
// fn_api_keys.php. Deliberately NOT loading fn_password.php/fn_auth.php/
// fn_features.php here - every branch exercised below returns BEFORE ever
// calling verifyPassword()/resolveUserIdFromAuth()/etc., so none of those
// functions need to exist for this file's assertions to be meaningful.
// ==============================================================================

requireFn('fn_api_account.php');

// ==============================================================================
// 🔤 Section 1: buildErasedAccountEmail() - pure, deterministic placeholder builder
// ==============================================================================

assertEquals(
    'deleted-1@deleted.invalid',
    buildErasedAccountEmail(1),
    'buildErasedAccountEmail(): userID 1 builds the expected placeholder'
);
assertEquals(
    'deleted-42@deleted.invalid',
    buildErasedAccountEmail(42),
    'buildErasedAccountEmail(): userID 42 builds the expected placeholder'
);
assertEquals(
    'deleted-999999@deleted.invalid',
    buildErasedAccountEmail(999999),
    'buildErasedAccountEmail(): a large userID still builds correctly'
);

assertTrue(
    buildErasedAccountEmail(1) !== buildErasedAccountEmail(2),
    'buildErasedAccountEmail(): different userIDs never collide on the same placeholder'
);

assertTrue(
    preg_match('/^deleted-[0-9]+@deleted\.invalid$/', buildErasedAccountEmail(7)) === 1,
    'buildErasedAccountEmail(): output always matches the documented deleted-<userID>@deleted.invalid shape'
);

assertEquals(
    'deleted.invalid',
    CUERCODE_ACCOUNT_ERASED_EMAIL_DOMAIN,
    'CUERCODE_ACCOUNT_ERASED_EMAIL_DOMAIN uses the IANA-reserved .invalid TLD (RFC 2606) - never resolvable, never re-registrable'
);

// ==============================================================================
// 📤 Section 2: collectAccountExportData() - the "dbQuery() unavailable" fail-safe
// ==============================================================================
// Sanity check first: this bare harness genuinely never defines dbQuery(), so
// the branch below is authentically exercised, not accidentally skipped.
// ==============================================================================

assertFalse(
    function_exists('dbQuery'),
    'sanity check: dbQuery() is NOT defined in this test file - the fail-safe branch below is genuinely exercised'
);

$emptyExport = collectAccountExportData(1);

assertTrue(is_array($emptyExport), 'collectAccountExportData(): always returns an array, even with no DB');
assertTrue(isset($emptyExport['exported_at']) && is_string($emptyExport['exported_at']), 'collectAccountExportData(): exported_at is always populated (pure gmdate() call, no DB needed)');
assertEquals(null, $emptyExport['profile'], 'collectAccountExportData(): profile is null when dbQuery() is unavailable (fail-closed, never fabricates data)');
assertEquals([], $emptyExport['saved_qr_codes'], 'collectAccountExportData(): saved_qr_codes is an empty array when dbQuery() is unavailable');
assertEquals([], $emptyExport['sessions'], 'collectAccountExportData(): sessions is an empty array when dbQuery() is unavailable (listUserSessions() itself fails closed to [])');
assertEquals([], $emptyExport['subscriptions'], 'collectAccountExportData(): subscriptions is an empty array when dbQuery() is unavailable');
assertEquals([], $emptyExport['linked_sso_providers'], 'collectAccountExportData(): linked_sso_providers is an empty array (listLinkedOAuthProvidersForUser() is not even loaded in this harness)');
assertEquals([], $emptyExport['api_keys'], 'collectAccountExportData(): api_keys is an empty array (listApiKeysForUser() is not even loaded in this harness)');

assertTrue(
    (new \ReflectionFunction('collectAccountExportData'))->getNumberOfParameters() === 1,
    'collectAccountExportData() takes exactly 1 parameter (userID) - there is no second parameter that could widen the scope to another account'
);

// ==============================================================================
// 🗑️ Section 3: eraseUserAccount() - the two branches reachable with NO database
// ==============================================================================

// ------------------------------------------------------------------------
// 3a. $confirm === false short-circuits BEFORE any dbQuery()/getDB() call.
// ------------------------------------------------------------------------
$noConfirmResult = eraseUserAccount(1, 'irrelevant-password', false);

assertFalse($noConfirmResult['success'], 'eraseUserAccount(): confirm=false is never a success');
assertEquals(400, $noConfirmResult['httpCode'], 'eraseUserAccount(): confirm=false returns 400');
assertEquals('CONFIRMATION_REQUIRED', $noConfirmResult['errorCode'], 'eraseUserAccount(): confirm=false uses the CONFIRMATION_REQUIRED error code');
assertEquals([], $noConfirmResult['data'], 'eraseUserAccount(): confirm=false carries no data payload');

// 📌 The SAME call with an empty password string still short-circuits on
// $confirm first - proving the confirmation gate is checked BEFORE the
// password is ever inspected (order matters: neither a correct nor incorrect
// password can bypass a missing confirmation).
$noConfirmEmptyPassword = eraseUserAccount(1, '', false);
assertEquals('CONFIRMATION_REQUIRED', $noConfirmEmptyPassword['errorCode'], 'eraseUserAccount(): confirm=false wins even with an empty password (confirmation is checked FIRST)');

// ------------------------------------------------------------------------
// 3b. confirm=true but dbQuery()/getDB() unavailable -> fails safe, 503.
// ------------------------------------------------------------------------
assertFalse(
    function_exists('getDB'),
    'sanity check: getDB() is NOT defined in this test file either - the SERVICE_UNAVAILABLE branch below is genuinely exercised'
);

$noDbResult = eraseUserAccount(1, 'some-password', true);

assertFalse($noDbResult['success'], 'eraseUserAccount(): confirm=true with no DB is never a success');
assertEquals(503, $noDbResult['httpCode'], 'eraseUserAccount(): confirm=true with no DB returns 503');
assertEquals('SERVICE_UNAVAILABLE', $noDbResult['errorCode'], 'eraseUserAccount(): confirm=true with no DB uses SERVICE_UNAVAILABLE');

// 📌 Own-account-only by construction: exactly 3 parameters
// (userID, currentPassword, confirm) - no "target user" parameter exists
// anywhere in this function's signature.
assertTrue(
    (new \ReflectionFunction('eraseUserAccount'))->getNumberOfParameters() === 3,
    'eraseUserAccount() takes exactly 3 parameters (userID, currentPassword, confirm) - there is no 4th "target user" parameter'
);

$eraseParams = (new \ReflectionFunction('eraseUserAccount'))->getParameters();
assertEquals('userID', $eraseParams[0]->getName(), 'eraseUserAccount(): 1st parameter is userID (the CALLER\'s own, server-resolved identity)');
assertEquals('currentPassword', $eraseParams[1]->getName(), 'eraseUserAccount(): 2nd parameter is currentPassword');
assertEquals('confirm', $eraseParams[2]->getName(), 'eraseUserAccount(): 3rd parameter is confirm');

// ==============================================================================
// 🔒 Section 4: every result shape from eraseUserAccount() carries the same keys
// ==============================================================================
foreach ([$noConfirmResult, $noDbResult] as $index => $shapeResult) {
    foreach (['success', 'httpCode', 'errorCode', 'message', 'data'] as $expectedKey) {
        assertTrue(
            array_key_exists($expectedKey, $shapeResult),
            'eraseUserAccount() result #' . $index . ' contains the "' . $expectedKey . '" key (consistent with every other engine function in this codebase)'
        );
    }
}

// ==============================================================================
// 🔒 Section 5: static regression guards (source-text only, no execution)
// ==============================================================================
// SECURITY-CRITICAL invariants that a single-process, no-DB harness cannot
// exercise directly (they require real HTTP + a real database) - pinned here
// as a regression guard against a future "helpful" refactor silently
// dropping one of them. Mirrors test_api_keys.php's own Section 8 convention.
// ==============================================================================

$accountSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_account.php');
assertTrue(is_string($accountSource) && $accountSource !== '', 'sanity check: fn_api_account.php source was readable for the static regression guards below');

// ------------------------------------------------------------------------
// 🔐 F-026 pattern: both new HTTP handlers check accountManagementAllowedFor()
// BEFORE doing anything else (own-scope resolution, DB read, or any
// mutation) - a future refactor that reorders this would reopen the same
// API-key self-escalation path F-026 originally closed.
// ------------------------------------------------------------------------
foreach (['handleAccountDataExport', 'handleAccountErase'] as $handlerName) {
    $handlerStart = strpos($accountSource, 'function ' . $handlerName . '(');
    assertTrue($handlerStart !== false, 'sanity check: function ' . $handlerName . '() is found in fn_api_account.php');

    $handlerOpeningWindow = substr($accountSource, $handlerStart, 400);

    assertContains(
        'accountManagementAllowedFor($auth)',
        $handlerOpeningWindow,
        'F-026 pattern regression guard: ' . $handlerName . '() checks accountManagementAllowedFor($auth) near the top of the function body'
    );
}

// ------------------------------------------------------------------------
// 🔒 handleAccountErase() requires an explicit boolean true for "confirm" -
// never a truthy-but-wrong-type value like the string "true" or 1.
// ------------------------------------------------------------------------
$eraseHandlerStart = strpos($accountSource, 'function handleAccountErase(');
assertTrue($eraseHandlerStart !== false, 'sanity check: function handleAccountErase() is found');
$eraseHandlerEnd  = strlen($accountSource);
$eraseHandlerBody = substr($accountSource, $eraseHandlerStart, $eraseHandlerEnd - $eraseHandlerStart);

assertContains(
    "\$requestBody['confirm'] === true",
    $eraseHandlerBody,
    'F-37 regression guard: handleAccountErase() requires a STRICT boolean true for "confirm" (=== , not a loose truthy check)'
);
assertContains(
    'CONFIRMATION_REQUIRED',
    $eraseHandlerBody,
    'F-37 regression guard: handleAccountErase() replies CONFIRMATION_REQUIRED when confirm is not explicitly true'
);
assertContains(
    'logoutCurrentUser()',
    $eraseHandlerBody,
    'F-37 regression guard: handleAccountErase() ends the session (logoutCurrentUser()) after a successful erasure'
);

// ------------------------------------------------------------------------
// 🔒 eraseUserAccount() itself: transaction wraps every mutation, and every
// documented anonymize/revoke/deactivate/unlink/soft-delete statement is
// genuinely present - a future refactor cannot silently drop one of these
// without this guard failing.
// ------------------------------------------------------------------------
$eraseFnStart = strpos($accountSource, 'function eraseUserAccount(');
$eraseFnEnd   = strpos($accountSource, 'function handleAccountErase(');
assertTrue($eraseFnStart !== false && $eraseFnEnd !== false && $eraseFnStart < $eraseFnEnd, 'sanity check: eraseUserAccount() and handleAccountErase() both found, in the expected order');
$eraseFnBody = substr($accountSource, $eraseFnStart, $eraseFnEnd - $eraseFnStart);

assertContains('begin_transaction()', $eraseFnBody, 'eraseUserAccount() regression guard: wraps its mutations in a transaction (begin_transaction())');
assertContains('->commit()', $eraseFnBody, 'eraseUserAccount() regression guard: commits the transaction on success');
assertContains('->rollback()', $eraseFnBody, 'eraseUserAccount() regression guard: rolls back the transaction on any failure');

assertContains("`status` != 'deleted'", $eraseFnBody, 'eraseUserAccount() regression guard: re-checks status != \'deleted\' in the SAME statement as the anonymizing UPDATE (double-erase race guard)');
assertContains("`status` = 'deleted'", $eraseFnBody, 'eraseUserAccount() regression guard: sets status = \'deleted\'');
assertContains('`erasedAt` = NOW()', $eraseFnBody, 'eraseUserAccount() regression guard: sets erasedAt = NOW()');
assertContains("`displayName` = 'Deleted user'", $eraseFnBody, 'eraseUserAccount() regression guard: clears displayName to the documented placeholder');
assertContains('`firstName` = NULL', $eraseFnBody, 'eraseUserAccount() regression guard: clears firstName');
assertContains('`lastName` = NULL', $eraseFnBody, 'eraseUserAccount() regression guard: clears lastName');
assertContains('`avatarPath` = NULL', $eraseFnBody, 'eraseUserAccount() regression guard: clears avatarPath');
assertContains('`passwordHash` = NULL', $eraseFnBody, 'eraseUserAccount() regression guard: clears passwordHash (login becomes impossible)');
assertContains('deleteUserAvatarFiles($userID)', $eraseFnBody, 'eraseUserAccount() regression guard: deletes the avatar FILE from disk, not just the DB pointer');

assertContains('`tblUserSessions` SET `revokedAt` = NOW()', $eraseFnBody, 'eraseUserAccount() regression guard: revokes ALL sessions');
assertContains('`tblAPIKeys` SET `isActive` = 0', $eraseFnBody, 'eraseUserAccount() regression guard: deactivates ALL API keys (soft, not a hard delete - matches revokeApiKeyForUser()\'s own convention)');
assertContains('DELETE FROM `tblUserOAuth`', $eraseFnBody, 'eraseUserAccount() regression guard: unlinks ALL SSO providers');
assertContains('`tblQRCodes` SET `deletedAt` = NOW()', $eraseFnBody, 'eraseUserAccount() regression guard: soft-deletes ALL saved QR codes (same mechanism as DELETE /api/v1/qrcodes/{uuid})');

assertFalse(str_contains($eraseFnBody, 'tblSubscriptions` SET'), 'eraseUserAccount() regression guard: NEVER mutates tblSubscriptions - financial/audit rows are retained, not touched');
assertFalse(str_contains($eraseFnBody, 'tblPayments` SET'), 'eraseUserAccount() regression guard: NEVER mutates tblPayments - financial/audit rows are retained, not touched');
assertFalse(str_contains($eraseFnBody, 'DELETE FROM `tblUsers`'), 'eraseUserAccount() regression guard: NEVER a hard DELETE of the tblUsers row (anonymize-in-place, not a real delete)');

assertContains('verifyPassword(', $eraseFnBody, 'eraseUserAccount() regression guard: re-verifies the current password before mutating anything');
assertContains("'INVALID_PASSWORD'", $eraseFnBody, 'eraseUserAccount() regression guard: a wrong password uses the documented INVALID_PASSWORD error code');

// ------------------------------------------------------------------------
// 📤 collectAccountExportData(): NEVER selects a secret/hash/token column,
// and reuses the SAME already-hardened list*() helpers the rest of the
// codebase uses (never a second, divergent query for sessions/keys/oauth).
// ------------------------------------------------------------------------
$exportFnStart = strpos($accountSource, 'function collectAccountExportData(');
$exportFnEnd   = strpos($accountSource, 'function handleAccountDataExport(');
assertTrue($exportFnStart !== false && $exportFnEnd !== false && $exportFnStart < $exportFnEnd, 'sanity check: collectAccountExportData() and handleAccountDataExport() both found, in the expected order');
$exportFnBody = substr($accountSource, $exportFnStart, $exportFnEnd - $exportFnStart);

// 📌 Backtick-quoted forms specifically - this function's OWN docblock
// legitimately mentions these column names in PROSE (explaining what other
// functions it reuses do NOT return), which would false-positive a plain
// substring search; a backtick-quoted column reference, by contrast, can
// only ever appear inside a real SQL statement.
foreach (['`passwordHash`', '`apiKeyHash`', '`tokensEncrypted`', '`sessionID`'] as $forbiddenColumn) {
    assertFalse(
        str_contains($exportFnBody, $forbiddenColumn),
        'collectAccountExportData() regression guard: never references ' . $forbiddenColumn . ' - a secret/hash/token/raw-session-id column must never enter the export'
    );
}

assertContains('listUserSessions(', $exportFnBody, 'collectAccountExportData() regression guard: reuses listUserSessions() (masked identifiers) rather than a second, divergent session query');
assertContains('listLinkedOAuthProvidersForUser(', $exportFnBody, 'collectAccountExportData() regression guard: reuses listLinkedOAuthProvidersForUser() (masked subject, never tokens)');
assertContains('listApiKeysForUser(', $exportFnBody, 'collectAccountExportData() regression guard: reuses listApiKeysForUser() (never the secret or its hash)');
assertContains('WHERE `userID` = ?', $exportFnBody, 'collectAccountExportData() regression guard: the profile lookup is scoped to WHERE userID = ? (own-account-only)');
assertContains('WHERE q.`userID` = ? AND q.`deletedAt` IS NULL', $exportFnBody, 'collectAccountExportData() regression guard: the saved-codes lookup is own-scoped AND excludes soft-deleted rows');
assertContains('WHERE s.`userID` = ?', $exportFnBody, 'collectAccountExportData() regression guard: the subscriptions lookup is scoped to WHERE userID = ? (own-account-only)');

// ------------------------------------------------------------------------
// 📥 handleAccountDataExport() sets the Content-Disposition attachment header.
// ------------------------------------------------------------------------
$exportHandlerStart = strpos($accountSource, 'function handleAccountDataExport(');
assertTrue($exportHandlerStart !== false, 'sanity check: function handleAccountDataExport() is found');
$exportHandlerBody = substr($accountSource, $exportHandlerStart, 1600);
assertContains(
    'Content-Disposition: attachment; filename="cuercode-data-export.json"',
    $exportHandlerBody,
    'handleAccountDataExport() regression guard: sends the documented Content-Disposition attachment header'
);
assertContains(
    'account_data_exported',
    $exportHandlerBody,
    'handleAccountDataExport() regression guard: logs a PII-free activity entry (activityType only, no email/name in the call)'
);

// ------------------------------------------------------------------------
// 🔒 Router wiring: both new routes exist, are session-only shaped
// (no requiredScope value could express "session only" - see the F-026
// pattern), and both dispatcher cases exist.
// ------------------------------------------------------------------------
$routerSource = file_get_contents(CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php');
assertTrue(is_string($routerSource) && $routerSource !== '', 'sanity check: public_html/api/v1/index.php source was readable');

assertContains("'pattern'   => 'account/export'", $routerSource, 'regression guard: the router registers the "account/export" GET route');
assertContains("'pattern'   => 'account/erase'", $routerSource, 'regression guard: the router registers the "account/erase" POST route');
assertContains("'handler'   => 'handleAccountDataExport'", $routerSource, 'regression guard: account/export routes to handleAccountDataExport');
assertContains("'handler'   => 'handleAccountErase'", $routerSource, 'regression guard: account/erase routes to handleAccountErase');
assertContains('handleAccountDataExport($auth);', $routerSource, 'regression guard: the dispatch switch calls handleAccountDataExport($auth)');
assertContains('handleAccountErase($requestBody, $auth);', $routerSource, 'regression guard: the dispatch switch calls handleAccountErase($requestBody, $auth)');
assertContains("'fn_api_account.php'", $routerSource, 'regression guard: the router loads fn_api_account.php (already required by the pre-existing account/* routes)');

// ------------------------------------------------------------------------
// 📄 OpenAPI: both paths exist, CsrfAuth-only (session-only), with the
// documented error codes present.
// ------------------------------------------------------------------------
$openApiPath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'openapi.json';
$openApiRaw  = file_get_contents($openApiPath);
assertTrue(is_string($openApiRaw) && $openApiRaw !== '', 'sanity check: openapi.json source was readable');

$openApiSpec = json_decode($openApiRaw, true);
assertTrue(is_array($openApiSpec), 'sanity check: openapi.json parses as valid JSON');

assertTrue(isset($openApiSpec['paths']['/account/export']), 'openapi.json regression guard: declares the /account/export path');
assertTrue(isset($openApiSpec['paths']['/account/erase']), 'openapi.json regression guard: declares the /account/erase path');

assertEquals(
    [['CsrfAuth' => []]],
    $openApiSpec['paths']['/account/export']['get']['security'],
    'openapi.json regression guard: /account/export declares CsrfAuth-only security (session-only, matches the F-026 pattern)'
);
assertEquals(
    [['CsrfAuth' => []]],
    $openApiSpec['paths']['/account/erase']['post']['security'],
    'openapi.json regression guard: /account/erase declares CsrfAuth-only security (session-only, matches the F-026 pattern)'
);

assertTrue(isset($openApiSpec['paths']['/account/erase']['post']['requestBody']), '/account/erase declares a request body');
assertContains('CONFIRMATION_REQUIRED', $openApiRaw, 'openapi.json regression guard: documents the CONFIRMATION_REQUIRED error code');
assertContains('INVALID_PASSWORD', $openApiRaw, 'openapi.json regression guard: documents the INVALID_PASSWORD error code (wrong password -> 401)');
assertContains('ACCOUNT_MANAGEMENT_FORBIDDEN', $openApiRaw, 'openapi.json regression guard: /account/export and /account/erase both document the session-only 403');

// ------------------------------------------------------------------------
// 🔒 install.sql: the schema change backing 'deleted' status + erasedAt.
// ------------------------------------------------------------------------
$installSqlPath = CUERCODE_SITE_ROOT . '_includes' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql';
$installSqlSource = file_get_contents($installSqlPath);
assertTrue(is_string($installSqlSource) && $installSqlSource !== '', 'sanity check: install.sql source was readable');

assertContains("'deleted'", $installSqlSource, 'install.sql regression guard: tblUsers.status ENUM includes \'deleted\'');
assertContains('`erasedAt`', $installSqlSource, 'install.sql regression guard: tblUsers gained the erasedAt column');

// ------------------------------------------------------------------------
// 📤 The plain-navigable download companion (public_html/account/export/index.php)
// reuses the SAME collectAccountExportData() engine function - never a second,
// divergent implementation of what data an export contains.
// ------------------------------------------------------------------------
$exportPagePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . 'index.php';
assertTrue(is_file($exportPagePath), 'sanity check: public_html/account/export/index.php exists');
$exportPageSource = file_get_contents($exportPagePath);
assertContains('collectAccountExportData(', $exportPageSource, 'public_html/account/export/index.php regression guard: reuses collectAccountExportData() (ONE code path decides export contents)');
assertContains('currentUser()', $exportPageSource, 'public_html/account/export/index.php regression guard: resolves the caller from currentUser() (own session only, no userID parameter)');
assertContains('Content-Disposition: attachment; filename="cuercode-data-export.json"', $exportPageSource, 'public_html/account/export/index.php regression guard: sends the same attachment filename as the JSON API');

// ------------------------------------------------------------------------
// 🗑️ The no-JS "Delete my account" form on the account dashboard requires
// the typed DELETE phrase AND the acknowledgement checkbox, independently
// of eraseUserAccount()'s own password check.
// ------------------------------------------------------------------------
$accountPagePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'index.php';
$accountPageSource = file_get_contents($accountPagePath);
assertTrue(is_string($accountPageSource) && $accountPageSource !== '', 'sanity check: public_html/account/index.php source was readable');

assertContains("erase_account_submit", $accountPageSource, 'public_html/account/index.php regression guard: the "Delete my account" no-JS form marker field exists');
assertContains("erase_acknowledge", $accountPageSource, 'public_html/account/index.php regression guard: the irreversibility acknowledgement checkbox field exists');
assertContains("erase_confirm_phrase", $accountPageSource, 'public_html/account/index.php regression guard: the typed-confirmation phrase field exists');
assertContains("\$eraseTypedPhrase !== 'DELETE'", $accountPageSource, 'public_html/account/index.php regression guard: the typed phrase is compared case-sensitively against the literal word DELETE');
assertContains('eraseUserAccount(', $accountPageSource, 'public_html/account/index.php regression guard: the no-JS handler calls the SAME eraseUserAccount() engine function the JSON API calls');
assertContains("header('Location: /login/?erased=1')", $accountPageSource, 'public_html/account/index.php regression guard: a successful no-JS erasure redirects to the /login/?erased=1 landing flag');
