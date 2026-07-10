<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for Account UI (Epic E3 stage 2b)
 * ==============================================================================
 *
 * Path: tests/test_account_ui.php
 *
 * Covers the DB-INDEPENDENT, pure/near-pure surface introduced for the
 * browser account UI (#52/#12/#14/#23/#24):
 *   - fn_auth.php    : resolveAvatarUrl() - the ProjectBrief §11.4 avatar
 *                      priority chain (local custom > Gravatar > default
 *                      placeholder; the MS365/Google SSO tier is untested
 *                      here because it is not built - Epic E2).
 *   - fn_api_account.php : maskTokenForDisplay() (pure string masking),
 *                      decodeAndValidateAvatarBase64() (data-URI parsing,
 *                      explicit SVG rejection, and a genuine tiny-PNG
 *                      decode round-trip via the REUSED saveBase64LogoToTemp()
 *                      - the only I/O this file performs is writing to
 *                      sys_get_temp_dir(), never the repo's own _uploads/).
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
 *     not unit-tested here.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12/#14/#23/#24)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_auth.php
 * @see         web/CueRCode.net/_functions/fn_api_account.php
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
