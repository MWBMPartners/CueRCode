<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for Self-Service API Keys (Epic E7 stage 1, #54)
 * ==============================================================================
 *
 * Path: tests/test_api_keys.php
 *
 * Covers the DB-INDEPENDENT surface of fn_api_keys.php - the pure validators
 * (validateApiKeyNameInput(), validateApiKeyScopesInput()), the ownership
 * predicate (apiKeyIsOwnedBy() - mirrors fn_api_qrcodes.php's
 * qrCodeIsWritableBy() truth table exactly, the SAME pattern that whole IDOR
 * boundary rests on), the CSRF-only management guard
 * (apiKeyManagementAllowedFor() - the "owner/design decision" this stage
 * makes to close a key self-escalation path), the route-id shape check
 * (isValidApiKeyId()), the scope-label helper (apiKeyScopeLabel()), and the
 * scope taxonomy constant itself (CUERCODE_API_KEY_SCOPE_ALLOWLIST). Section 8
 * (static, source-text only) pins the shape of the security-critical
 * invariants that CANNOT be proven in a no-DB, single-process harness: the
 * CSRF-only guard runs FIRST in every HTTP handler, fetchApiKeyRowById()/
 * listApiKeysForUser() never SELECT the hash column, and the router's
 * Scope Enforcement wiring exists for the routes this stage gates.
 *
 * ⚠️ SKIPPED (DB/HTTP-context-dependent - out of scope for this dependency-
 *    free harness, same convention as test_qrcodes.php's own "SKIPPED"
 *    section):
 *   - fetchApiKeyRowById(), countActiveApiKeysForUser(), listApiKeysForUser(),
 *     createApiKeyForUser(), rotateApiKeyForUser(), revokeApiKeyForUser(), and
 *     the five HTTP handlers all require a live tblAPIKeys/tblUsers
 *     connection via a real MySQLi connection PLUS the real HTTP router
 *     ($_SERVER/session/$auth context).
 *   - resolveClampedApiKeyRateLimits()'s "-1 (unlimited plan) clamps to
 *     1000000/100000000" branch requires a REAL limitFor() resolving a
 *     business-tier (-1) plan via a live database - cannot be safely stubbed
 *     here with a same-named global `limitFor()` function, because
 *     tests/test_features.php ALSO requireFn()s the real fn_features.php
 *     (which defines the real `limitFor()`) later in the SAME PHP process
 *     this runner uses; declaring a colliding global function here would
 *     fatal that file with "Cannot redeclare limitFor()". Only the
 *     "limitFor() does not exist at all -> hard-coded 10/100 fallback" branch
 *     is unit-tested below (Section 7) - it needs no stub.
 *   - These are all exercised end-to-end against a throwaway scratch database
 *     and a live `php -S` instance of public_html/api/v1/index.php (mirroring
 *     the E3 stage 2a/2b PR evidence convention) - see SECURITY.md/
 *     CHANGELOG.md for the pasted IDOR + scope-enforcement pack results. That
 *     pass specifically proves, over real HTTP with two independently-
 *     registered accounts A and B:
 *       - A can create (201), list (never leaking `api_key`/hash), rotate
 *         (200, new secret returned once, OLD raw key immediately 401s), and
 *         revoke (200) their OWN key.
 *       - B's rotate/revoke of A's key both return 404 (never 403, never
 *         200) - the core IDOR assertion this stage exists for - and A's row
 *         is confirmed unaffected (`isActive` unchanged) afterwards.
 *       - The free-tier `api_keys` cap (1) 402s on a SECOND key for the SAME
 *         user (`API_KEY_LIMIT_REACHED`, with `plan_limit`+`upgrade_url`).
 *       - A key scoped `["generate"]` gets 403 `INSUFFICIENT_SCOPE` on
 *         `GET /qrcodes` (needs `qrcodes:read`) but 200 on `POST /generate`;
 *         a key scoped `["qrcodes:read"]` is the exact mirror image.
 *       - ANY X-API-Key-authenticated request to `/keys*` (even with every
 *         scope) gets 403 `API_KEY_MANAGEMENT_FORBIDDEN` - only CSRF/session
 *         auth may manage keys (this stage's self-escalation mitigation).
 *       - An expired key (`expiresAt` in the past) is rejected 401, even
 *         though this stage's own UI never sets one (defence in depth for
 *         the previously-unenforced column).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E7 stage 1, #54)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_api_keys.php
 * @see         web/CueRCode.net/_functions/fn_api_auth.php - apiKeyRequestHasScope()
 */

// ==============================================================================
// 🧰 Load the DB-independent production function file (NO database connection)
// ==============================================================================
// fn_api_keys.php only DEFINES functions at include time (every DB touch is
// lazy, inside a function body via dbQuery()), so including it opens no
// connection - identical convention to fn_api_qrcodes.php's inclusion in
// test_qrcodes.php. Deliberately NOT loading fn_features.php here - see the
// "SKIPPED" note above for why (a same-named global `limitFor()` stub would
// collide with test_features.php's own real requireFn('fn_features.php')
// later in this same test-runner process).
// ==============================================================================

requireFn('fn_api_keys.php');

// ==============================================================================
// ✅ Section 1: isValidApiKeyId() - route-parameter shape check
// ==============================================================================

assertTrue(isValidApiKeyId('1'), 'isValidApiKeyId() accepts "1"');
assertTrue(isValidApiKeyId('42'), 'isValidApiKeyId() accepts a multi-digit id');
assertTrue(isValidApiKeyId('999999999'), 'isValidApiKeyId() accepts a large id');
assertFalse(isValidApiKeyId('0'), 'isValidApiKeyId() rejects "0" (apiKeyID is AUTO_INCREMENT starting at 1)');
assertFalse(isValidApiKeyId('01'), 'isValidApiKeyId() rejects a leading zero');
assertFalse(isValidApiKeyId('-1'), 'isValidApiKeyId() rejects a negative id');
assertFalse(isValidApiKeyId(''), 'isValidApiKeyId() rejects an empty string');
assertFalse(isValidApiKeyId('abc'), 'isValidApiKeyId() rejects non-numeric input');
assertFalse(isValidApiKeyId('1; DROP TABLE tblAPIKeys;'), 'isValidApiKeyId() rejects a SQL-injection-shaped trailing payload');
assertFalse(isValidApiKeyId('1.5'), 'isValidApiKeyId() rejects a decimal');
assertFalse(isValidApiKeyId(' 1'), 'isValidApiKeyId() rejects leading whitespace (route params are not trimmed before this check)');

// ==============================================================================
// 🔐 Section 2: apiKeyIsOwnedBy() - the entire IDOR-enforcement predicate
// ==============================================================================
// Truth table: owned iff ownerUserId !== null AND callerUserId !== null AND
// ownerUserId === callerUserId. Mirrors fn_api_qrcodes.php's
// qrCodeIsWritableBy() EXACTLY (same 2-parameter, no-isPublic-influence shape)
// - every rotate()/revoke() call in fn_api_keys.php calls ONLY this predicate,
// so its truth table below IS the entire ownership-enforcement contract.
// ==============================================================================

assertTrue(apiKeyIsOwnedBy(1, 1), 'apiKeyIsOwnedBy(): the OWNER owns their own key');
assertFalse(apiKeyIsOwnedBy(1, 2), 'apiKeyIsOwnedBy(): a DIFFERENT user does not own the key (the core IDOR assertion)');
assertFalse(apiKeyIsOwnedBy(null, 1), 'apiKeyIsOwnedBy(): a key with no resolvable owner is not owned by anyone');
assertFalse(apiKeyIsOwnedBy(1, null), 'apiKeyIsOwnedBy(): an unresolved caller identity never owns anything, regardless of ownerUserId');
assertFalse(apiKeyIsOwnedBy(null, null), 'apiKeyIsOwnedBy(): no owner and no resolved caller -> not owned');

// 📌 Signature/contract check: exactly 2 parameters - no "isPublic"-style
// third argument can ever leak into this decision (mirrors test_qrcodes.php's
// identical regression guard for qrCodeIsWritableBy()).
assertTrue(
    (new \ReflectionFunction('apiKeyIsOwnedBy'))->getNumberOfParameters() === 2,
    'apiKeyIsOwnedBy() takes exactly 2 parameters (ownerUserId, callerUserId)'
);

// ==============================================================================
// 🎫 Section 3: apiKeyManagementAllowedFor() - CSRF-only management guard
// ==============================================================================
// Owner/design decision (see fn_api_keys.php header docblock): ONLY a
// CSRF/session-authenticated caller may manage keys - closes the
// self-escalation path where a compromised low-scope key could mint itself a
// brand-new, broader-scoped key.
// ==============================================================================

assertTrue(
    apiKeyManagementAllowedFor(['method' => 'csrf']),
    'apiKeyManagementAllowedFor(): a CSRF/session-authenticated caller IS allowed to manage keys'
);
assertFalse(
    apiKeyManagementAllowedFor(['method' => 'api_key']),
    'apiKeyManagementAllowedFor(): an API-key-authenticated caller is NEVER allowed to manage keys (self-escalation mitigation)'
);
assertFalse(
    apiKeyManagementAllowedFor(['method' => 'anonymous']),
    'apiKeyManagementAllowedFor(): an anonymous auth context is not allowed to manage keys'
);
assertFalse(
    apiKeyManagementAllowedFor([]),
    'apiKeyManagementAllowedFor(): a missing "method" key fails closed (not allowed)'
);
assertFalse(
    apiKeyManagementAllowedFor(['method' => 'CSRF']),
    'apiKeyManagementAllowedFor(): the method comparison is case-sensitive (no accidental widening via a differently-cased value)'
);

// ==============================================================================
// 🏷️ Section 4: validateApiKeyNameInput() - "name" field validation
// ==============================================================================
// Unlike fn_api_qrcodes.php's "label" field, a key's name is REQUIRED (not
// optional/clearable) - a key with no name is unmanageable in the dashboard
// list, so this validator has no "absent means leave unchanged" branch.
// ==============================================================================

$missingName = validateApiKeyNameInput(null);
assertFalse($missingName['valid'], 'validateApiKeyNameInput(): null is invalid (name is required)');
assertContains('required', (string) $missingName['error'], 'validateApiKeyNameInput(): the null-name error mentions it is required');

$nonStringName = validateApiKeyNameInput(['not', 'a', 'string']);
assertFalse($nonStringName['valid'], 'validateApiKeyNameInput(): an array value is rejected (must be a string)');

$numericName = validateApiKeyNameInput(12345);
assertFalse($numericName['valid'], 'validateApiKeyNameInput(): a bare integer is rejected (must be a string, no implicit stringification)');

$blankName = validateApiKeyNameInput('   ');
assertFalse($blankName['valid'], 'validateApiKeyNameInput(): a whitespace-only name is invalid (trims to empty)');

$normalName = validateApiKeyNameInput('  My CMS integration  ');
assertTrue($normalName['valid'], 'validateApiKeyNameInput(): a normal name is valid');
assertEquals('My CMS integration', $normalName['value'], 'validateApiKeyNameInput(): the name is trimmed');

$exactly100 = str_repeat('a', 100);
$atCapName  = validateApiKeyNameInput($exactly100);
assertTrue($atCapName['valid'], 'validateApiKeyNameInput(): a name at EXACTLY the 100-char cap is valid');

$over100    = str_repeat('a', 101);
$overCapName = validateApiKeyNameInput($over100);
assertFalse($overCapName['valid'], 'validateApiKeyNameInput(): a name ONE character over the 100-char cap is rejected');
assertContains('100', (string) $overCapName['error'], 'validateApiKeyNameInput(): the over-length error message names the 100-char limit');

$emojiName = str_repeat('🔑', 100); // 100 CHARACTERS, but > 100 bytes in UTF-8
$emojiResult = validateApiKeyNameInput($emojiName);
assertTrue($emojiResult['valid'], 'validateApiKeyNameInput(): 100 multi-byte characters are valid (length measured in characters via mb_strlen, not bytes)');

// ==============================================================================
// 🔒 Section 5: validateApiKeyScopesInput() - "scopes" field validation
// ==============================================================================
// Fail-closed by omission: an absent/null "scopes" field is VALID and
// resolves to an EMPTY array (a key with no scopes can do nothing scoped) -
// this is deliberately different from validateApiKeyNameInput()'s "required"
// posture, matching this file's header docblock.
// ==============================================================================

$noScopes = validateApiKeyScopesInput(null);
assertTrue($noScopes['valid'], 'validateApiKeyScopesInput(): a null "scopes" field is valid');
assertEquals([], $noScopes['value'], 'validateApiKeyScopesInput(): a null "scopes" field resolves to an EMPTY array (fail-closed - grants nothing)');

$notAnArray = validateApiKeyScopesInput('generate');
assertFalse($notAnArray['valid'], 'validateApiKeyScopesInput(): a bare string (not wrapped in an array) is rejected');

$nonStringElement = validateApiKeyScopesInput(['generate', 123]);
assertFalse($nonStringElement['valid'], 'validateApiKeyScopesInput(): an array containing a non-string element is rejected wholesale');

$unknownScope = validateApiKeyScopesInput(['generate', 'sudo']);
assertFalse($unknownScope['valid'], 'validateApiKeyScopesInput(): ANY unrecognised scope rejects the WHOLE request (never silently dropped)');
assertContains('sudo', (string) $unknownScope['error'], 'validateApiKeyScopesInput(): the error names the specific unknown scope');
assertContains('generate', (string) $unknownScope['error'], 'validateApiKeyScopesInput(): the error also lists the full allowlist for guidance');

$validScopes = validateApiKeyScopesInput(['generate', 'download']);
assertTrue($validScopes['valid'], 'validateApiKeyScopesInput(): a request naming only allowlisted scopes is valid');
assertEquals(['generate', 'download'], $validScopes['value'], 'validateApiKeyScopesInput(): valid scopes are returned in the order supplied');

$caseAndWhitespace = validateApiKeyScopesInput(['  GENERATE  ', 'Download']);
assertTrue($caseAndWhitespace['valid'], 'validateApiKeyScopesInput(): scope matching is case-insensitive and whitespace-tolerant');
assertEquals(['generate', 'download'], $caseAndWhitespace['value'], 'validateApiKeyScopesInput(): scopes are normalised to lower-case/trimmed');

$duplicateScopes = validateApiKeyScopesInput(['generate', 'generate', 'GENERATE']);
assertEquals(['generate'], $duplicateScopes['value'], 'validateApiKeyScopesInput(): duplicate scopes (incl. case-variant duplicates) are de-duplicated');

$blankEntrySkipped = validateApiKeyScopesInput(['generate', '', '   ']);
assertTrue($blankEntrySkipped['valid'], 'validateApiKeyScopesInput(): a blank/whitespace-only entry is silently skipped, not an error');
assertEquals(['generate'], $blankEntrySkipped['value'], 'validateApiKeyScopesInput(): the blank entry contributes nothing to the result');

$allScopesValid = validateApiKeyScopesInput(CUERCODE_API_KEY_SCOPE_ALLOWLIST);
assertTrue($allScopesValid['valid'], 'validateApiKeyScopesInput(): the FULL allowlist itself is always a valid request');
assertEquals(
    CUERCODE_API_KEY_SCOPE_ALLOWLIST,
    $allScopesValid['value'],
    'validateApiKeyScopesInput(): requesting every allowlisted scope returns them all'
);

$emptyArray = validateApiKeyScopesInput([]);
assertTrue($emptyArray['valid'], 'validateApiKeyScopesInput(): an explicit empty array is valid (same as omitted)');
assertEquals([], $emptyArray['value'], 'validateApiKeyScopesInput(): an explicit empty array resolves to an empty scope list');

// ==============================================================================
// 🎯 Section 6: CUERCODE_API_KEY_SCOPE_ALLOWLIST + apiKeyScopeLabel()
// ==============================================================================

assertTrue(
    is_array(CUERCODE_API_KEY_SCOPE_ALLOWLIST) && count(CUERCODE_API_KEY_SCOPE_ALLOWLIST) === 7,
    'CUERCODE_API_KEY_SCOPE_ALLOWLIST defines exactly the 7 scopes from PROJECT_ROADMAP.md / the E7 stage 1 task scope'
);

foreach (['generate', 'preview', 'download', 'bulk', 'qrcodes:read', 'qrcodes:write', 'save'] as $expectedScope) {
    assertTrue(
        in_array($expectedScope, CUERCODE_API_KEY_SCOPE_ALLOWLIST, true),
        'CUERCODE_API_KEY_SCOPE_ALLOWLIST includes "' . $expectedScope . '"'
    );
}

foreach (CUERCODE_API_KEY_SCOPE_ALLOWLIST as $allowlistedScope) {
    assertTrue(
        apiKeyScopeLabel($allowlistedScope) !== $allowlistedScope,
        'apiKeyScopeLabel(): "' . $allowlistedScope . '" has a genuine human-readable label (not a raw passthrough)'
    );
}

assertEquals(
    'made_up_scope',
    apiKeyScopeLabel('made_up_scope'),
    'apiKeyScopeLabel(): an unrecognised scope fails safe by returning the raw string, never a fatal/notice'
);

// ==============================================================================
// ⏱️ Section 7: resolveClampedApiKeyRateLimits() - the no-limitFor() fallback
// ==============================================================================
// Only the "limitFor() does not exist at all" branch is safely testable here
// - see this file's header "SKIPPED" note for why the -1/unlimited clamp
// branch is NOT stubbed in this harness.
// ==============================================================================

assertFalse(
    function_exists('limitFor'),
    'sanity check: limitFor() is NOT defined in this test file (fn_features.php was deliberately not loaded) - the fallback branch below is genuinely exercised'
);

$fallbackLimits = resolveClampedApiKeyRateLimits(1);
assertEquals([10, 100], $fallbackLimits, 'resolveClampedApiKeyRateLimits(): falls back to [10, 100] when limitFor() is unavailable (defensive - never reached in normal router operation, where fn_features.php is always loaded)');

// ==============================================================================
// 🔒 Section 8: Static regression guards (source-text only, no execution)
// ==============================================================================
// SECURITY-CRITICAL invariants that a single-process, no-DB harness cannot
// exercise directly (they require real HTTP + a real database) - pinned here
// as a regression guard against a future "helpful" refactor silently
// dropping one of them. Mirrors test_qrcodes.php's own F-023 static-guard
// convention (Section 8 there).
// ==============================================================================

$keysSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_keys.php');
assertTrue(is_string($keysSource) && $keysSource !== '', 'sanity check: fn_api_keys.php source was readable for the static regression guards below');

// ------------------------------------------------------------------------
// 🎫 Every one of the 4 JSON HTTP handlers checks apiKeyManagementAllowedFor()
// BEFORE doing anything else (own-scope resolution, ownership fetch, or any
// mutation) - a future refactor that reorders this would reopen the
// self-escalation path this stage's design decision closes.
// ------------------------------------------------------------------------
foreach (['handleCreateApiKey', 'handleListApiKeys', 'handleRotateApiKey', 'handleDeleteApiKey'] as $handlerName) {
    $handlerStart = strpos($keysSource, 'function ' . $handlerName . '(');
    assertTrue($handlerStart !== false, 'sanity check: function ' . $handlerName . '() is found in fn_api_keys.php');

    // 📌 Grab a reasonably-sized window right after the function opens -
    // the guard must be one of the FIRST statements, not merely present
    // somewhere later in a long function body.
    $handlerOpeningWindow = substr($keysSource, $handlerStart, 400);

    assertContains(
        'apiKeyManagementAllowedFor($auth)',
        $handlerOpeningWindow,
        'F-054 regression guard: ' . $handlerName . '() checks apiKeyManagementAllowedFor($auth) near the top of the function body'
    );
}

// ------------------------------------------------------------------------
// 🔐 fetchApiKeyRowById() and listApiKeysForUser() NEVER select apiKeyHash -
// the hash must never even enter a PHP array where it could later be
// accidentally logged/serialised/returned to a client.
// ------------------------------------------------------------------------
$fetchStart = strpos($keysSource, 'function fetchApiKeyRowById(');
$fetchEnd   = strpos($keysSource, 'function countActiveApiKeysForUser(');
assertTrue($fetchStart !== false && $fetchEnd !== false && $fetchStart < $fetchEnd, 'sanity check: fetchApiKeyRowById() and countActiveApiKeysForUser() both found, in the expected order');
$fetchBody = substr($keysSource, $fetchStart, $fetchEnd - $fetchStart);
assertFalse(str_contains($fetchBody, 'apiKeyHash'), 'F-054 regression guard: fetchApiKeyRowById() never SELECTs apiKeyHash');

$listStart = strpos($keysSource, 'function listApiKeysForUser(');
$listEnd   = strpos($keysSource, 'function rotateApiKeyForUser(');
assertTrue($listStart !== false && $listEnd !== false && $listStart < $listEnd, 'sanity check: listApiKeysForUser() and rotateApiKeyForUser() both found, in the expected order');
$listBody = substr($keysSource, $listStart, $listEnd - $listStart);
assertFalse(str_contains($listBody, 'apiKeyHash'), 'F-054 regression guard: listApiKeysForUser() never SELECTs apiKeyHash - the hash can never reach the list response');

// ------------------------------------------------------------------------
// 🔄 rotateApiKeyForUser()/revokeApiKeyForUser() both re-check ownership
// (apiKeyIsOwnedBy()) AND isActive = 1 in the mutating SQL's WHERE clause
// itself - not merely at the earlier fetch.
// ------------------------------------------------------------------------
$rotateStart = strpos($keysSource, 'function rotateApiKeyForUser(');
$rotateEnd   = strpos($keysSource, 'function revokeApiKeyForUser(');
assertTrue($rotateStart !== false && $rotateEnd !== false && $rotateStart < $rotateEnd, 'sanity check: rotateApiKeyForUser() and revokeApiKeyForUser() both found, in the expected order');
$rotateBody = substr($keysSource, $rotateStart, $rotateEnd - $rotateStart);
assertContains('apiKeyIsOwnedBy(', $rotateBody, 'F-054 regression guard: rotateApiKeyForUser() checks apiKeyIsOwnedBy() before mutating');
assertContains('`userID` = ? AND `isActive` = 1', $rotateBody, 'F-054 regression guard: rotateApiKeyForUser()\'s UPDATE re-checks userID + isActive=1 in the SAME statement as the mutation');

$revokeEnd = strpos($keysSource, '// ==', $rotateEnd + 50);
$revokeBody = $revokeEnd !== false ? substr($keysSource, $rotateEnd, $revokeEnd - $rotateEnd) : substr($keysSource, $rotateEnd);
assertContains('apiKeyIsOwnedBy(', $revokeBody, 'F-054 regression guard: revokeApiKeyForUser() checks apiKeyIsOwnedBy() before mutating');
assertContains('`isActive` = 0', $revokeBody, 'F-054 regression guard: revokeApiKeyForUser() soft-revokes (isActive = 0), never a hard DELETE');

// ------------------------------------------------------------------------
// 🔒 Router wiring: the Scope Enforcement block + the gated routes exist.
// ------------------------------------------------------------------------
$routerSource = file_get_contents(CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php');
assertTrue(is_string($routerSource) && $routerSource !== '', 'sanity check: public_html/api/v1/index.php source was readable');

assertContains('apiKeyRequestHasScope(', $routerSource, 'F-054 regression guard: the router calls apiKeyRequestHasScope() (the scope-enforcement check actually runs)');
assertContains("\$auth['method'] === 'api_key'", $routerSource, 'F-054 regression guard: scope enforcement is gated on api_key auth (never applied to csrf/anonymous requests)');
assertContains('INSUFFICIENT_SCOPE', $routerSource, 'F-054 regression guard: a missing scope produces the documented INSUFFICIENT_SCOPE error code');

foreach (["'requiredScope'   => 'generate'", "'requiredScope'   => 'preview'", "'requiredScope'   => 'bulk'", "'requiredScope'   => 'download'", "'requiredScope'   => 'save'", "'requiredScope'   => 'qrcodes:read'"] as $expectedRouteScope) {
    assertContains($expectedRouteScope, $routerSource, 'F-054 regression guard: the router route table sets ' . $expectedRouteScope);
}

assertContains("'GET'    => 'qrcodes:read',", $routerSource, 'F-054 regression guard: qrcodes/{uuid} maps GET to qrcodes:read');
assertContains("'PATCH'  => 'qrcodes:write',", $routerSource, 'F-054 regression guard: qrcodes/{uuid} maps PATCH to qrcodes:write');
assertContains("'DELETE' => 'qrcodes:write',", $routerSource, 'F-054 regression guard: qrcodes/{uuid} maps DELETE to qrcodes:write');

assertContains("'fn_api_keys.php'", $routerSource, 'F-054 regression guard: the router loads fn_api_keys.php');
assertContains("'pattern'   => 'keys'", $routerSource, 'F-054 regression guard: the router registers the "keys" collection route');
assertContains("'pattern'   => 'keys/{id}/rotate'", $routerSource, 'F-054 regression guard: the router registers the "keys/{id}/rotate" route');
assertContains("'pattern'   => 'keys/{id}'", $routerSource, 'F-054 regression guard: the router registers the "keys/{id}" route');

// ------------------------------------------------------------------------
// 🔐 fn_api_auth.php: validateAPIKey() now selects permissions + expiresAt
// (scope decoding + expiry enforcement, both new in this stage).
// ------------------------------------------------------------------------
$authSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_auth.php');
assertTrue(is_string($authSource) && $authSource !== '', 'sanity check: fn_api_auth.php source was readable');
assertContains('`permissions`', $authSource, 'F-054 regression guard: validateAPIKey() SELECTs the permissions column (scope decoding)');
assertContains('`expiresAt`', $authSource, 'F-054 regression guard: validateAPIKey() SELECTs the expiresAt column (expiry enforcement)');
assertContains('function apiKeyRequestHasScope(', $authSource, 'F-054 regression guard: apiKeyRequestHasScope() is defined in fn_api_auth.php');
