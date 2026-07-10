<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for Account UI (Epic E3 stage 2b, F-026)
 * ==============================================================================
 *
 * Path: tests/test_account_ui.php
 *
 * Covers the DB-INDEPENDENT, pure/near-pure surface introduced for the
 * browser account UI (#52/#12/#14/#23/#24), PLUS the F-026 session-only
 * account-management fix (#52/#54, 2026-07-10):
 *   - fn_auth.php    : resolveAvatarUrl() - the ProjectBrief §11.4 avatar
 *                      priority chain (local custom > Gravatar > default
 *                      placeholder; the MS365/Google SSO tier is untested
 *                      here because it is not built - Epic E2).
 *   - fn_api_account.php : maskTokenForDisplay() (pure string masking),
 *                      decodeAndValidateAvatarBase64() (data-URI parsing,
 *                      explicit SVG rejection, and a genuine tiny-PNG
 *                      decode round-trip via the REUSED saveBase64LogoToTemp()
 *                      - the only I/O this file performs is writing to
 *                      sys_get_temp_dir(), never the repo's own _uploads/),
 *                      accountManagementAllowedFor() (F-026 - the session-only
 *                      management guard's full truth table, mirroring
 *                      fn_api_keys.php's apiKeyManagementAllowedFor() in
 *                      test_api_keys.php's own "Section 3" exactly).
 *   - Section 6 (static, source-text only) pins the F-026 fix's
 *     security-critical invariants that CANNOT be proven in a no-DB,
 *     single-process harness: every one of the four handleAccount*()
 *     handlers checks accountManagementAllowedFor($auth) FIRST and replies
 *     ACCOUNT_MANAGEMENT_FORBIDDEN on failure; fn_api_account.php's guard and
 *     fn_api_keys.php's guard both delegate to the SAME shared
 *     isInteractiveSessionAuth() primitive (fn_api_auth.php); and
 *     openapi.json documents all four endpoints as CsrfAuth-only (never
 *     ApiKeyAuth) with a 403 response.
 *
 * ⚠️ SKIPPED (DB/HTTP-context-dependent - out of scope for this harness, same
 *    convention as test_auth.php's own "SKIPPED" section):
 *   - updateUserDisplayName()/setUserAvatarPath()/listUserSessions()/
 *     revokeUserSession()/changeUserPassword() all require a live
 *     tblUsers/tblUserSessions connection via dbQuery().
 *   - storeUserAvatarFile()/deleteUserAvatarFiles() touch the real
 *     _uploads/avatars/ directory - exercised against a throwaway scratch
 *     environment instead of this dependency-free harness (see
 *     CHANGELOG.md/DEV_NOTES.md for that evidence), so a test run on a
 *     contributor's machine can never write into the repo's own _uploads/.
 *   - validateAvatarFileUpload() needs a GENUINE HTTP upload (validateImageUpload()
 *     calls is_uploaded_file(), which is always false for a CLI-created temp
 *     file) - cannot be meaningfully unit-tested outside a real request.
 *   - The four handleAccount*() HTTP handlers need $_SERVER/$_SESSION/router
 *     context - covered by the scratch-DB + php -S manual/curl verification,
 *     not unit-tested here. **F-026 (2026-07-10, #52/#54)**: this live pack now
 *     additionally proves, with a REAL registered account and a REAL,
 *     fully-scoped self-service API key: `PATCH /account/profile`,
 *     `GET /account/sessions`, `DELETE /account/sessions/{id}`, and
 *     `POST /account/change-password` ALL return `403
 *     ACCOUNT_MANAGEMENT_FORBIDDEN` when called with `X-API-Key` (with the
 *     DB confirmed unchanged afterward - display name, session, and password
 *     hash all untouched), while the IDENTICAL four calls, made instead with
 *     the account's own session cookie + CSRF token, all succeed (`200`) -
 *     see CHANGELOG.md/SECURITY.md (F-026) for the full pasted evidence.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12/#14/#23/#24; F-026 fix 2026-07-10, #52/#54)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_auth.php
 * @see         web/CueRCode.net/_functions/fn_api_account.php
 * @see         web/CueRCode.net/_functions/fn_api_auth.php - isInteractiveSessionAuth() (F-026 shared primitive)
 * @see         SECURITY.md - F-026
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// fn_qr_logo.php is loaded FIRST so saveBase64LogoToTemp() already exists by
// the time fn_api_account.php's decodeAndValidateAvatarBase64() checks
// function_exists('saveBase64LogoToTemp') - it therefore never attempts the
// loadQRGenerationFunctions() lazy-load fallback (which lives in
// fn_api_generate.php and is not needed here).
// ==============================================================================

requireFn('fn_password.php');
requireFn('fn_auth.php');
requireFn('fn_qr_logo.php');
requireFn('fn_api_account.php');

// ==============================================================================
// ✅ Section 1: fn_auth.php - resolveAvatarUrl() (ProjectBrief §11.4 priority chain)
// ==============================================================================

assertEquals(
    '/account/avatar/',
    resolveAvatarUrl('avatars/42.png', 'user@example.com'),
    'resolveAvatarUrl() tier 1 (local custom upload): a non-empty avatarPath always resolves to the session-gated streaming endpoint'
);

assertEquals(
    '/account/avatar/',
    resolveAvatarUrl('  avatars/7.jpg  ', 'user@example.com'),
    'resolveAvatarUrl() treats a whitespace-padded non-empty avatarPath as "set" (only trim()-empty counts as absent)'
);

// 📌 Gravatar hash is documented as md5(trim(strtolower(email))).
$gravatarUrl = resolveAvatarUrl(null, '  User@Example.COM  ');
assertEquals(
    'https://www.gravatar.com/avatar/' . md5('user@example.com') . '?s=128&d=404',
    $gravatarUrl,
    'resolveAvatarUrl() tier 3 (Gravatar): hashes the trimmed, lower-cased email and requests d=404 (never the wildcard "unknown" identicon) so the caller can fall through to the default placeholder'
);

assertContains(
    's=64',
    resolveAvatarUrl(null, 'user@example.com', 64),
    'resolveAvatarUrl() honours a custom $size argument in the Gravatar URL'
);

assertEquals(
    'https://www.gravatar.com/avatar/' . md5('user@example.com') . '?s=16&d=404',
    resolveAvatarUrl(null, 'user@example.com', 1),
    'resolveAvatarUrl() clamps an unreasonably small $size up to the 16px floor'
);

assertEquals(
    'https://www.gravatar.com/avatar/' . md5('user@example.com') . '?s=2048&d=404',
    resolveAvatarUrl(null, 'user@example.com', 999999),
    'resolveAvatarUrl() clamps an unreasonably large $size down to the 2048px ceiling'
);

assertEquals(
    '/assets/img/branding/avatar-default.svg',
    resolveAvatarUrl(null, ''),
    'resolveAvatarUrl() tier 4 (default placeholder): no avatarPath AND no usable email falls through to the local default icon'
);

assertEquals(
    '/assets/img/branding/avatar-default.svg',
    resolveAvatarUrl('', '   '),
    'resolveAvatarUrl() treats an empty avatarPath AND a whitespace-only email as "nothing usable" -> default placeholder'
);

// ==============================================================================
// ✅ Section 2: fn_api_account.php - maskTokenForDisplay()
// ==============================================================================

$sampleToken = str_repeat('a', 56) . 'b1c2d3e4';

assertEquals(
    str_repeat('•', 56) . 'b1c2d3e4',
    maskTokenForDisplay($sampleToken),
    'maskTokenForDisplay() masks everything except the trailing 8 characters by default'
);

assertEquals(
    'abcd',
    maskTokenForDisplay('abcd'),
    'maskTokenForDisplay() returns a token unchanged when it is no longer than the visible suffix'
);

assertEquals(
    '••cde',
    maskTokenForDisplay('abcde', 3),
    'maskTokenForDisplay() honours a custom, smaller visible-suffix length'
);

assertEquals(
    '•••••',
    maskTokenForDisplay('abcde', -5),
    'maskTokenForDisplay() clamps a negative visibleSuffix to 0 (fully masked - not the unmasked original, a PHP substr(-0) footgun this function guards against)'
);

// ==============================================================================
// ✅ Section 3: fn_api_account.php - decodeAndValidateAvatarBase64()
// ==============================================================================

$notADataUri = decodeAndValidateAvatarBase64('this is not a data uri at all');
assertFalse(
    $notADataUri['valid'],
    'decodeAndValidateAvatarBase64() rejects a string that is not a data: URI at all'
);
assertContains(
    'not a valid image data URI',
    $notADataUri['error'],
    'decodeAndValidateAvatarBase64() explains the malformed-data-URI rejection'
);

// 🔐 The core security property this stage's file-header docblock documents:
// SVG is explicitly rejected for avatars, unlike the general logo pipeline.
$svgResult = decodeAndValidateAvatarBase64('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=');
assertFalse(
    $svgResult['valid'],
    'decodeAndValidateAvatarBase64() REJECTS image/svg+xml (self-stored-XSS mitigation - see fn_api_account.php header docblock)'
);
assertContains(
    'SVG images are not supported',
    $svgResult['error'],
    'decodeAndValidateAvatarBase64() gives an explicit, actionable SVG-rejection message'
);

// 📌 A genuine tiny (1x1) transparent PNG, base64-encoded - proves the
// REUSE of saveBase64LogoToTemp() actually decodes a valid image end to end
// (not just rejecting bad input). Cleans up its own temp file afterward.
$tinyPngBase64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42'
    . 'YAAAAASUVORK5CYII=';
$pngResult = decodeAndValidateAvatarBase64('data:image/png;base64,' . $tinyPngBase64);

assertTrue(
    $pngResult['valid'],
    'decodeAndValidateAvatarBase64() accepts a genuine, validly-encoded 1x1 PNG'
);
assertEquals(
    'png',
    $pngResult['extension'],
    'decodeAndValidateAvatarBase64() resolves the correct destination extension for image/png'
);
assertTrue(
    is_string($pngResult['tempPath']) && is_file($pngResult['tempPath']),
    'decodeAndValidateAvatarBase64() (via the reused saveBase64LogoToTemp()) actually writes a readable temp file'
);

// 🧹 Clean up the temp file this test created (only OS temp dir - never the
// repo's own _uploads/, which this file never touches).
if (is_string($pngResult['tempPath']) && is_file($pngResult['tempPath'])) {
    @unlink($pngResult['tempPath']);
}

$malformedBase64 = decodeAndValidateAvatarBase64('data:image/png;base64,%%%not-base64%%%');
assertFalse(
    $malformedBase64['valid'],
    'decodeAndValidateAvatarBase64() rejects a data URI whose payload is not valid base64/image data'
);

// ==============================================================================
// ✅ Section 4: Static regression guards (source-text, no execution)
// ==============================================================================
// Mirrors test_auth.php's own "Section 7" convention - pins specific
// source-code shapes this stage's security posture depends on, so a future
// edit cannot silently reopen them.
// ==============================================================================

$accountApiSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_account.php');

assertTrue(
    is_string($accountApiSource) && $accountApiSource !== '',
    'fn_api_account.php source is readable for the static guards below'
);

// 🔐 Every mutating query in this file must be a prepared statement - a
// crude but effective guard is that dbQuery() is never called with a
// directly-interpolated variable inside the SQL string literal itself
// (i.e. no "{$" or naked ".$" concatenation immediately inside a dbQuery()
// first argument). This does not prove correctness, but it DOES catch the
// most common regression (someone "quickly" interpolating a value back into
// the query string instead of adding a '?' placeholder).
assertFalse(
    (bool) preg_match('/dbQuery\(\s*[\'"][^\'"]*\{\$/', $accountApiSource),
    'fn_api_account.php never interpolates a variable directly inside a dbQuery() SQL string literal (prepared statements only)'
);

// 🔐 revokeUserSession()'s UPDATE must scope by userID in the SAME statement
// as the sessionID match (the whole point of the own-scoped IDOR posture).
assertContains(
    'WHERE `sessionID` = ? AND `userID` = ? AND `revokedAt` IS NULL',
    $accountApiSource,
    'revokeUserSession() re-checks userID = ? in the SAME UPDATE that performs the revoke (own-scope enforced at the SQL level, not just in PHP)'
);

// 🔐 SVG must be excluded from the avatar extension allowlist that
// storeUserAvatarFile() falls back to / accepts.
assertFalse(
    (bool) preg_match('/allowedExtensions\s*=\s*\[[^\]]*svg/i', $accountApiSource),
    'storeUserAvatarFile()\'s allowed-extensions list never includes svg'
);

// ==============================================================================
// 🔒 Section 5: accountManagementAllowedFor() - session-only management guard (F-026)
// ==============================================================================
// F-026: account management (profile edit, session listing/revocation, and
// change-password) is reserved for an INTERACTIVE (CSRF/session) caller ONLY -
// mirrors fn_api_keys.php's apiKeyManagementAllowedFor() truth table EXACTLY
// (test_api_keys.php's own "Section 3"), since both now delegate to the SAME
// shared primitive, isInteractiveSessionAuth() (fn_api_auth.php). This file
// does NOT load fn_api_auth.php (see this file's own header docblock/loader
// list above), so accountManagementAllowedFor() genuinely exercises its OWN
// function_exists()-guarded fallback branch (`$auth['method'] === 'csrf'`)
// here - the identical comparison isInteractiveSessionAuth() would otherwise
// make, just proven independently of that file.
// ==============================================================================

assertFalse(
    function_exists('isInteractiveSessionAuth'),
    'sanity check: isInteractiveSessionAuth() is NOT defined in this test file (fn_api_auth.php was deliberately not loaded) - the fallback branch below is genuinely exercised'
);

assertTrue(
    accountManagementAllowedFor(['method' => 'csrf']),
    'accountManagementAllowedFor(): a CSRF/session-authenticated caller IS allowed to manage the account'
);
assertFalse(
    accountManagementAllowedFor(['method' => 'api_key']),
    'accountManagementAllowedFor(): an API-key-authenticated caller is NEVER allowed to manage the account (F-026 - the core fix this stage exists for)'
);
assertFalse(
    accountManagementAllowedFor(['method' => 'anonymous']),
    'accountManagementAllowedFor(): an anonymous auth context is not allowed to manage the account'
);
assertFalse(
    accountManagementAllowedFor([]),
    'accountManagementAllowedFor(): a missing "method" key fails closed (not allowed)'
);
assertFalse(
    accountManagementAllowedFor(['method' => 'CSRF']),
    'accountManagementAllowedFor(): the method comparison is case-sensitive (no accidental widening via a differently-cased value)'
);

// 📌 Signature/contract check: exactly 1 parameter - mirrors
// apiKeyManagementAllowedFor()'s own shape (no second "scopes"-style argument
// could ever let a broadly-scoped key talk its way past this guard).
assertTrue(
    (new \ReflectionFunction('accountManagementAllowedFor'))->getNumberOfParameters() === 1,
    'accountManagementAllowedFor() takes exactly 1 parameter ($auth) - no scope/bypass argument exists'
);

// ==============================================================================
// 🔒 Section 6: F-026 static regression guards (source-text only, no execution)
// ==============================================================================
// SECURITY-CRITICAL invariant that a single-process, no-DB harness cannot
// exercise directly (the four HTTP handlers need a real $_SERVER/session/
// router context - covered by the live scratch-DB + real-HTTP evidence in
// CHANGELOG.md/SECURITY.md instead) - pinned here as a regression guard
// against a future "helpful" refactor silently reopening the API-key ->
// account-management gap F-026 closes. Mirrors test_api_keys.php's own
// Section 8 "every handler checks the guard near the top" convention exactly.
// ==============================================================================

foreach (['handleAccountProfilePatch', 'handleAccountSessionsList', 'handleAccountSessionDelete', 'handleAccountChangePassword'] as $handlerName) {
    $handlerStart = strpos($accountApiSource, 'function ' . $handlerName . '(');
    assertTrue($handlerStart !== false, 'sanity check: function ' . $handlerName . '() is found in fn_api_account.php');

    // 📌 Grab a reasonably-sized window right after the function opens - the
    // guard must be one of the FIRST statements, not merely present
    // somewhere later in a long function body (handleAccountSessionDelete()
    // in particular has a route-parameter shape check AFTER this guard, so
    // the window must be generous enough to still land on the guard call
    // itself, not the shape check that follows it). 500 chars (vs.
    // test_api_keys.php's 400) - this file's guard message is longer than
    // fn_api_keys.php's own, so the window is widened to still comfortably
    // include the ACCOUNT_MANAGEMENT_FORBIDDEN error-code argument.
    $handlerOpeningWindow = substr($accountApiSource, $handlerStart, 500);

    assertContains(
        'accountManagementAllowedFor($auth)',
        $handlerOpeningWindow,
        'F-026 regression guard: ' . $handlerName . '() checks accountManagementAllowedFor($auth) near the top of the function body'
    );

    assertContains(
        'ACCOUNT_MANAGEMENT_FORBIDDEN',
        $handlerOpeningWindow,
        'F-026 regression guard: ' . $handlerName . '() replies with the ACCOUNT_MANAGEMENT_FORBIDDEN error code on guard failure'
    );
}

// ------------------------------------------------------------------------
// 🔗 Shared primitive: fn_api_auth.php defines isInteractiveSessionAuth(),
// and BOTH fn_api_account.php's accountManagementAllowedFor() and
// fn_api_keys.php's apiKeyManagementAllowedFor() delegate to it (rather than
// two independently-maintained copies of the same `$auth['method'] === 'csrf'`
// comparison silently drifting apart from each other).
// ------------------------------------------------------------------------
$authSourceForGuard = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_auth.php');
assertTrue(
    is_string($authSourceForGuard) && $authSourceForGuard !== '',
    'sanity check: fn_api_auth.php source was readable for the F-026 shared-primitive regression guard'
);
assertContains(
    'function isInteractiveSessionAuth(',
    $authSourceForGuard,
    'F-026 regression guard: isInteractiveSessionAuth() is defined in fn_api_auth.php (the shared primitive)'
);

assertContains(
    'isInteractiveSessionAuth($auth)',
    $accountApiSource,
    'F-026 regression guard: accountManagementAllowedFor() delegates to the shared isInteractiveSessionAuth() primitive'
);

$keysApiSourceForGuard = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_keys.php');
assertTrue(
    is_string($keysApiSourceForGuard) && $keysApiSourceForGuard !== '',
    'sanity check: fn_api_keys.php source was readable for the F-026 shared-primitive regression guard'
);
assertContains(
    'isInteractiveSessionAuth($auth)',
    $keysApiSourceForGuard,
    'F-026 regression guard: apiKeyManagementAllowedFor() ALSO delegates to the shared isInteractiveSessionAuth() primitive (the two guards cannot silently drift apart)'
);

// ------------------------------------------------------------------------
// 📜 openapi.json: the four account-management endpoints document CsrfAuth
// as their ONLY security scheme (ApiKeyAuth removed - F-026).
// ------------------------------------------------------------------------
$openApiSpecPath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'openapi.json';
$openApiRaw = file_get_contents($openApiSpecPath);
assertTrue(is_string($openApiRaw) && $openApiRaw !== '', 'sanity check: openapi.json source was readable for the F-026 regression guard');

$openApiSpec = json_decode($openApiRaw, true);
assertTrue(is_array($openApiSpec), 'sanity check: openapi.json parses as valid JSON');

$f026Paths = [
    '/account/profile'          => 'patch',
    '/account/sessions'         => 'get',
    '/account/sessions/{id}'    => 'delete',
    '/account/change-password'  => 'post',
];

foreach ($f026Paths as $f026Path => $f026Method) {
    $operation = $openApiSpec['paths'][$f026Path][$f026Method] ?? null;
    assertTrue(
        is_array($operation),
        'sanity check: openapi.json declares ' . strtoupper($f026Method) . ' ' . $f026Path
    );

    $security = $operation['security'] ?? null;
    assertEquals(
        [['CsrfAuth' => []]],
        $security,
        'F-026 regression guard: ' . strtoupper($f026Method) . ' ' . $f026Path . ' declares CsrfAuth as its ONLY security scheme (ApiKeyAuth removed)'
    );

    assertTrue(
        isset($operation['responses']['403']),
        'F-026 regression guard: ' . strtoupper($f026Method) . ' ' . $f026Path . ' documents a 403 response'
    );
}

// ==============================================================================
// 🔒 Section 7: F-028 hardening - accountPagePostCsrfValid() fails CLOSED
// ==============================================================================
// Independent OIDC/SSO security review (E2) FINDING 2 (Low): before this fix,
// account/index.php's own no-JS-form CSRF gate returned `true` (i.e.
// "CSRF check passed") whenever validateCSRFToken() was undefined - a fail-
// OPEN default that would have waved through EVERY state-changing no-JS
// action on the account dashboard (profile update, password change, session
// revoke, SSO provider unlink, API key create/rotate/revoke, ...) with no
// CSRF check at all, had fn_csrf.php somehow failed to load. Fixed to fail
// CLOSED (return false), matching public_html/auth/index.php's own
// equivalent gate (`!function_exists('validateCSRFToken') ||
// !validateCSRFToken(...)`).
//
// accountPagePostCsrfValid() is defined INSIDE public_html/account/index.php
// (a page file that requires bootstrap.php and redirects an anonymous
// visitor before any of its own code runs) rather than in a fn_ file, so it
// cannot be require()'d and called directly inside this dependency-free,
// no-DB, no-$_SESSION harness (same constraint test_account_ui.php's own
// Section "SKIPPED" list already documents for the four handleAccount*()
// HTTP handlers). A static, source-text regression guard - identical
// convention to Section 6's F-026 guards above - pins the fail-CLOSED
// invariant instead.
// ==============================================================================

$accountIndexPagePath   = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'account' . DIRECTORY_SEPARATOR . 'index.php';
$accountIndexPageSource = file_get_contents($accountIndexPagePath);

assertTrue(
    is_string($accountIndexPageSource) && $accountIndexPageSource !== '',
    'sanity check: public_html/account/index.php source was readable for the F-028 CSRF fail-closed regression guard'
);

$csrfGateStart = strpos($accountIndexPageSource, 'function accountPagePostCsrfValid()');
assertTrue(
    $csrfGateStart !== false,
    'F-028 regression guard: accountPagePostCsrfValid() is defined in public_html/account/index.php'
);

// 📌 Isolate just this function's body (up to the next top-level closing
// brace + blank line, mirroring the file's own formatting) so the
// assertions below cannot accidentally match unrelated code elsewhere on
// the page.
$csrfGateEnd = strpos($accountIndexPageSource, "\n}\n", $csrfGateStart);
assertTrue(
    $csrfGateEnd !== false && $csrfGateEnd > $csrfGateStart,
    'sanity check: accountPagePostCsrfValid()\'s closing brace was found after its opening'
);
$csrfGateBody = substr($accountIndexPageSource, $csrfGateStart, $csrfGateEnd - $csrfGateStart);

assertContains(
    '!function_exists(\'validateCSRFToken\')',
    $csrfGateBody,
    'F-028 regression guard: accountPagePostCsrfValid() checks !function_exists(\'validateCSRFToken\') (the fail-closed branch condition)'
);

assertContains(
    'return false;',
    $csrfGateBody,
    'F-028 regression guard: accountPagePostCsrfValid() returns false when validateCSRFToken() is unavailable (fails CLOSED)'
);

assertFalse(
    str_contains($csrfGateBody, 'return true;'),
    'F-028 regression guard: accountPagePostCsrfValid() NEVER unconditionally returns true - the pre-fix fail-OPEN sentinel is gone'
);

// 🔗 Parity check: public_html/auth/index.php's OWN CSRF gate for the
// "link an additional provider" flow already failed closed BEFORE this fix -
// used here as the sibling convention this fix now matches (see FINDING 2's
// remediation note).
$authIndexPagePath   = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'index.php';
$authIndexPageSource = file_get_contents($authIndexPagePath);
assertTrue(
    is_string($authIndexPageSource) && $authIndexPageSource !== '',
    'sanity check: public_html/auth/index.php source was readable for the F-028 CSRF-parity regression guard'
);
assertContains(
    '!function_exists(\'validateCSRFToken\')',
    $authIndexPageSource,
    'F-028 parity check: public_html/auth/index.php\'s own CSRF gate ALSO fails closed when validateCSRFToken() is unavailable (the convention account/index.php now matches)'
);
