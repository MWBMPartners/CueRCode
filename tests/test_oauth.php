<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for SSO / OIDC CLIENT (Epic E2, #55/#19/#20/#28)
 * ==============================================================================
 *
 * Path: tests/test_oauth.php
 *
 * Covers the DB-INDEPENDENT, NETWORK-INDEPENDENT surface of fn_oauth.php:
 *
 *   - PKCE code_verifier/code_challenge (S256) - including the OFFICIAL
 *     RFC 7636 Appendix B worked example as an independent golden vector.
 *   - state/nonce generation (shape + uniqueness) and the single-use,
 *     session-bound pending-attempt store (oauthConsumePendingState()) -
 *     proving the CSRF/replay defence actually burns the entry.
 *   - the open-redirect-proof allowlist (resolveSafeSameSiteRedirect()) -
 *     accepts a relative path, rejects `//evil`, `https://evil`, and
 *     `javascript:`.
 *   - the FULL account-linking decision table (decideOAuthAccountLinkAction())
 *     - new-subject+verified-new-email -> create; subject-exists -> login;
 *     verified-email-collision -> require-manual-link; unverified-email ->
 *     refuse; PLUS the authenticated "link additional provider" branches.
 *   - id_token validation: a REAL RSA keypair is generated in-process
 *     (openssl_pkey_new()), used to sign a real RS256 JWT and build a
 *     matching JWKS, then oauthVerifyJwtSignatureRS256()/
 *     oauthValidateAndDecodeIdToken() are exercised end-to-end - proving a
 *     genuinely valid token passes AND that a bad iss/aud/exp/nonce/
 *     signature/alg is rejected with the correct error code.
 *   - the base64url + hand-rolled ASN.1 DER (JWK -> PEM) helpers.
 *   - fail-SAFE/fail-CLOSED behaviour of every DB-touching or config-
 *     touching function when dbQuery()/getConfig() are unavailable - the
 *     exact state of this bare CLI harness (mirrors test_auth.php's own
 *     convention).
 *
 * ⚠️ SKIPPED (DB-dependent, out of scope for this harness, same convention
 *    as test_auth.php's registerUser()/loginUser()/... exclusions):
 *   - applyOAuthAccountLink()'s actual INSERT/UPDATE side effects,
 *     listLinkedOAuthProvidersForUser()/unlinkOAuthProviderForUser()'s
 *     real query results, and the live callback-route HTTP flow all
 *     require a real MySQLi connection (+ a mock IdP for the full
 *     network round trip) - exercised against a scratch DB, not here.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E2, #55/#19/#20/#28)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_oauth.php
 * @see         https://datatracker.ietf.org/doc/html/rfc7636#appendix-B
 */

// ==============================================================================
// 🧰 Load the production OAuth engine (NO database connection, NO network)
// ==============================================================================

requireFn('fn_oauth.php');

// ==============================================================================
// ✅ Section 1: base64url helpers - round-trip + known vectors
// ==============================================================================

assertEquals(
    'anasonymised-example_data~123',
    oauthBase64UrlDecode(oauthBase64UrlEncode('anasonymised-example_data~123')),
    'oauthBase64UrlEncode()/oauthBase64UrlDecode() round-trip arbitrary text'
);

// 📌 Binary data that WOULD contain '+', '/', and '=' padding under plain
// base64 - proves the url-safe substitution + padding-strip actually fires.
$binaryProbe = random_bytes(37);
assertEquals(
    $binaryProbe,
    oauthBase64UrlDecode(oauthBase64UrlEncode($binaryProbe)),
    'base64url helpers round-trip arbitrary random binary data'
);

assertFalse(
    str_contains(oauthBase64UrlEncode($binaryProbe), '='),
    'oauthBase64UrlEncode() never emits padding characters'
);

// ==============================================================================
// ✅ Section 2: PKCE (RFC 7636) - code_verifier / code_challenge (S256)
// ==============================================================================

$verifierOne = generatePkceCodeVerifier();
$verifierTwo = generatePkceCodeVerifier();

assertTrue(
    strlen($verifierOne) >= 43 && strlen($verifierOne) <= 128,
    'generatePkceCodeVerifier() length is within RFC 7636 §4.1\'s required 43-128 characters'
);

assertMatches(
    '/^[A-Za-z0-9\-_]+$/',
    $verifierOne,
    'generatePkceCodeVerifier() uses only the base64url-safe character set (RFC 7636\'s unreserved subset)'
);

assertFalse(
    $verifierOne === $verifierTwo,
    'generatePkceCodeVerifier() produces a fresh random value on every call'
);

// 🏆 OFFICIAL RFC 7636 Appendix B worked example - a golden vector
// INDEPENDENT of this codebase's own implementation, computed once via a
// standalone sha256+base64url transform and cross-checked against the
// RFC's published output before being pinned here.
assertEquals(
    'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
    generatePkceCodeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
    'generatePkceCodeChallenge() matches the RFC 7636 Appendix B official S256 worked example'
);

// ==============================================================================
// ✅ Section 3: state / nonce generation
// ==============================================================================

$stateOne = generateOAuthState();
$stateTwo = generateOAuthState();
$nonceOne = generateOAuthNonce();

assertMatches('/^[0-9a-f]{64}$/', $stateOne, 'generateOAuthState() returns a 64-character lowercase hex string');
assertMatches('/^[0-9a-f]{64}$/', $nonceOne, 'generateOAuthNonce() returns a 64-character lowercase hex string');
assertFalse($stateOne === $stateTwo, 'generateOAuthState() produces a fresh random value on every call');

// ==============================================================================
// ✅ Section 4: resolveSafeSameSiteRedirect() - open-redirect-proof allowlist
// ==============================================================================

assertEquals(
    '/account/settings',
    resolveSafeSameSiteRedirect('/account/settings', '/account/'),
    'resolveSafeSameSiteRedirect() accepts a normal root-relative path'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect('//evil.example.com/phish', '/account/'),
    'resolveSafeSameSiteRedirect() REJECTS a protocol-relative "//evil" target (falls back to default)'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect('https://evil.example.com', '/account/'),
    'resolveSafeSameSiteRedirect() REJECTS an absolute "https://evil" target (falls back to default)'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect('javascript:alert(1)', '/account/'),
    'resolveSafeSameSiteRedirect() REJECTS a "javascript:" URI (falls back to default)'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect('/x:attacker.example.com', '/account/'),
    'resolveSafeSameSiteRedirect() REJECTS a path containing an embedded scheme separator'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect(null, '/account/'),
    'resolveSafeSameSiteRedirect() falls back to the default for a null input'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect('', '/account/'),
    'resolveSafeSameSiteRedirect() falls back to the default for an empty string'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect('relative/no/leading/slash', '/account/'),
    'resolveSafeSameSiteRedirect() REJECTS a path with no leading slash'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect(str_repeat('/a', 400), '/account/'),
    'resolveSafeSameSiteRedirect() REJECTS an over-length path (defensive cap)'
);

// --- FINDING 5 hardening (Low): explicit CR/LF/control-char rejection ------
// Independent OIDC/SSO security review (E2) FINDING 5: reject any value
// containing `\r`, `\n`, or another ASCII control character BEFORE the
// existing checks - header-injection defence in depth, independent of
// whether a ':' scheme separator co-occurs (currently non-exploitable via
// this codebase's two callers only because PHP's own header() silently
// drops newline-bearing values - a DIFFERENT layer's protection, not this
// function's).

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect("/account/\r\nSet-Cookie: evil=1", '/account/'),
    'FINDING 5 regression: resolveSafeSameSiteRedirect() REJECTS a value containing an embedded CRLF (header-injection attempt)'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect("/account/\nLocation: https://evil.example.com", '/account/'),
    'FINDING 5 regression: resolveSafeSameSiteRedirect() REJECTS a value containing a bare LF'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect("/account/\rX-Injected: 1", '/account/'),
    'FINDING 5 regression: resolveSafeSameSiteRedirect() REJECTS a value containing a bare CR (no co-occurring \':\')'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect("/account/\x00hidden", '/account/'),
    'FINDING 5 regression: resolveSafeSameSiteRedirect() REJECTS a value containing an embedded NUL byte'
);

assertEquals(
    '/account/',
    resolveSafeSameSiteRedirect("/account/\x07\x1B", '/account/'),
    'FINDING 5 regression: resolveSafeSameSiteRedirect() REJECTS a value containing other ASCII control characters (bell/escape)'
);

assertEquals(
    '/account/settings',
    resolveSafeSameSiteRedirect('/account/settings', '/account/'),
    'sanity check: an ordinary CR/LF-free root-relative path is still accepted after the FINDING 5 hardening (no regression on legitimate input)'
);

// ==============================================================================
// ✅ Section 5: decideOAuthAccountLinkAction() - the FULL decision table (PURE)
// ==============================================================================

// --- Unauthenticated ("Sign in with ...") flow ------------------------------

// 5a. Brand-new identity, IdP-verified email, NO local account with that
//     email -> CREATE a new account.
$decisionCreateNew = decideOAuthAccountLinkAction(false, null, true, false, null, null);
assertEquals('create_new', $decisionCreateNew['action'], 'decision table: new subject + verified + new email -> create_new');
assertEquals(201, $decisionCreateNew['httpCode'], 'decision table: create_new returns 201');
assertEquals(null, $decisionCreateNew['errorCode'], 'decision table: create_new has no errorCode');

// 5b. Identity already linked from a prior sign-in -> LOG IN as that user.
$decisionLoginExisting = decideOAuthAccountLinkAction(true, 42, true, false, null, null);
assertEquals('login_existing', $decisionLoginExisting['action'], 'decision table: subject already linked -> login_existing');
assertEquals(42, $decisionLoginExisting['userID'], 'decision table: login_existing resolves the PREVIOUSLY linked userID');

// 5b-repeat. Even if the CURRENT id_token has email_verified=false, an
// ALREADY-linked identity still logs in (verification only gates the FIRST
// link, not every subsequent login).
$decisionLoginExistingUnverifiedNow = decideOAuthAccountLinkAction(true, 42, false, false, null, null);
assertEquals(
    'login_existing',
    $decisionLoginExistingUnverifiedNow['action'],
    'decision table: an ALREADY-linked identity still logs in even if the CURRENT token reports email_verified=false'
);

// 5c. Brand-new identity, UNVERIFIED email -> REFUSE (never link/create on
//     an unverified IdP email).
$decisionRefuseUnverified = decideOAuthAccountLinkAction(false, null, false, false, null, null);
assertEquals('refuse_unverified', $decisionRefuseUnverified['action'], 'decision table: new subject + UNVERIFIED email -> refuse_unverified');
assertEquals('SSO_EMAIL_NOT_VERIFIED', $decisionRefuseUnverified['errorCode'], 'decision table: refuse_unverified error code');
assertEquals(403, $decisionRefuseUnverified['httpCode'], 'decision table: refuse_unverified returns 403');
assertEquals(null, $decisionRefuseUnverified['userID'], 'decision table: refuse_unverified never resolves a userID');

// 5d. Brand-new identity, VERIFIED email, but a LOCAL account ALREADY
//     exists with that same email -> REFUSE auto-link (the account-
//     takeover defence) - require an explicit, authenticated manual link.
$decisionEmailCollision = decideOAuthAccountLinkAction(false, null, true, true, 99, null);
assertEquals('require_manual_link', $decisionEmailCollision['action'], 'decision table: verified email COLLIDING with an existing unlinked account -> require_manual_link');
assertEquals('SSO_EMAIL_COLLISION', $decisionEmailCollision['errorCode'], 'decision table: require_manual_link error code');
assertEquals(409, $decisionEmailCollision['httpCode'], 'decision table: require_manual_link returns 409 Conflict');

// --- Authenticated ("link an additional provider" from account settings) ---

// 5e. Already-authenticated user links a brand-new identity onto their OWN
//     account - email verification is IRRELEVANT on this branch (the
//     session itself already proves ownership).
$decisionLinkToCurrent = decideOAuthAccountLinkAction(false, null, false, false, null, 7);
assertEquals('link_to_current', $decisionLinkToCurrent['action'], 'decision table: authenticated user + brand-new identity -> link_to_current (even with an unverified IdP email)');
assertEquals(7, $decisionLinkToCurrent['userID'], 'decision table: link_to_current resolves to the REQUESTING (authenticated) user');

// 5f. Already-authenticated user re-links an identity ALREADY linked to
//     their OWN account -> idempotent no-op success.
$decisionNoop = decideOAuthAccountLinkAction(true, 7, true, false, null, 7);
assertEquals('noop_already_linked_to_self', $decisionNoop['action'], 'decision table: identity already linked to the SAME authenticated user -> no-op success');

// 5g. Already-authenticated user attempts to link an identity that is
//     ALREADY linked to a DIFFERENT account -> REFUSE (never re-point an
//     existing link onto a different account).
$decisionAlreadyLinkedElsewhere = decideOAuthAccountLinkAction(true, 9, true, false, null, 7);
assertEquals('already_linked_elsewhere', $decisionAlreadyLinkedElsewhere['action'], 'decision table: identity already linked to a DIFFERENT user -> already_linked_elsewhere');
assertEquals('SSO_ALREADY_LINKED_ELSEWHERE', $decisionAlreadyLinkedElsewhere['errorCode'], 'decision table: already_linked_elsewhere error code');
assertEquals(409, $decisionAlreadyLinkedElsewhere['httpCode'], 'decision table: already_linked_elsewhere returns 409 Conflict');
assertEquals(null, $decisionAlreadyLinkedElsewhere['userID'], 'decision table: already_linked_elsewhere never discloses a userID to link onto');

// ==============================================================================
// ✅ Section 6: id_token validation - REAL RS256 signature + claim checks
// ==============================================================================
// A fresh, in-process RSA keypair is generated for THIS TEST RUN ONLY (never
// touches any real credential) via openssl_pkey_new() - used to sign a real
// compact JWT and to build a matching JWKS, exactly like a real IdP would.
// ==============================================================================

/**
 * 🧪 Test-only helper: sign a compact RS256 JWT for a given header/payload.
 *
 * @param array  $header        JWT header (e.g. ['alg' => 'RS256', 'kid' => '...']).
 * @param array  $payload       JWT claims.
 * @param string $privateKeyPem PEM-encoded RSA private key.
 *
 * @return string Compact JWT string.
 */
function testOAuthSignJwt(array $header, array $payload, string $privateKeyPem): string
{
    $headerB64  = oauthBase64UrlEncode(json_encode($header));
    $payloadB64 = oauthBase64UrlEncode(json_encode($payload));
    $signingInput = $headerB64 . '.' . $payloadB64;

    $signature = '';
    openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);

    return $signingInput . '.' . oauthBase64UrlEncode($signature);
}

$testKeyResource = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

assertTrue($testKeyResource !== false, 'test harness: openssl_pkey_new() successfully generates an in-process RSA test keypair');

openssl_pkey_export($testKeyResource, $testPrivateKeyPem);
$testKeyDetails = openssl_pkey_get_details($testKeyResource);

$testJwks = [
    'keys' => [
        [
            'kty' => 'RSA',
            'kid' => 'test-key-1',
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => oauthBase64UrlEncode($testKeyDetails['rsa']['n']),
            'e'   => oauthBase64UrlEncode($testKeyDetails['rsa']['e']),
        ],
    ],
];

$expectedIssuer   = 'https://login.example-idp.test/tenant-abc';
$expectedAudience = 'test-client-id-0123456789';
$expectedNonce    = generateOAuthNonce();
$fixedNow         = 1_800_000_000; // fixed reference instant for deterministic exp/iat maths

$validClaims = [
    'iss'            => $expectedIssuer,
    'aud'            => $expectedAudience,
    'sub'            => 'subject-abc-123',
    'email'          => 'jane@example.com',
    'email_verified' => true,
    'nonce'          => $expectedNonce,
    'iat'            => $fixedNow - 60,
    'exp'            => $fixedNow + 3600,
];

$validHeader = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-key-1'];
$validJwt    = testOAuthSignJwt($validHeader, $validClaims, $testPrivateKeyPem);

// --- 6a. A genuinely valid token passes every check -------------------------
$validResult = oauthValidateAndDecodeIdToken($validJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertTrue($validResult['valid'], 'oauthValidateAndDecodeIdToken() ACCEPTS a genuinely valid RS256 id_token (correct iss/aud/exp/nonce/signature)');
assertEquals('subject-abc-123', $validResult['claims']['sub'] ?? null, 'oauthValidateAndDecodeIdToken() returns the decoded claims on success');

// --- 6b. Bad issuer ----------------------------------------------------------
$badIssuerClaims = $validClaims;
$badIssuerClaims['iss'] = 'https://attacker.example.test';
$badIssuerJwt = testOAuthSignJwt($validHeader, $badIssuerClaims, $testPrivateKeyPem);
$badIssuerResult = oauthValidateAndDecodeIdToken($badIssuerJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($badIssuerResult['valid'], 'oauthValidateAndDecodeIdToken() REJECTS a token with the WRONG issuer');
assertEquals('ID_TOKEN_INVALID_ISSUER', $badIssuerResult['errorCode'], 'wrong issuer -> ID_TOKEN_INVALID_ISSUER');

// --- 6c. Bad audience ---------------------------------------------------------
$badAudienceClaims = $validClaims;
$badAudienceClaims['aud'] = 'some-other-app-client-id';
$badAudienceJwt = testOAuthSignJwt($validHeader, $badAudienceClaims, $testPrivateKeyPem);
$badAudienceResult = oauthValidateAndDecodeIdToken($badAudienceJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($badAudienceResult['valid'], 'oauthValidateAndDecodeIdToken() REJECTS a token with the WRONG audience');
assertEquals('ID_TOKEN_INVALID_AUDIENCE', $badAudienceResult['errorCode'], 'wrong audience -> ID_TOKEN_INVALID_AUDIENCE');

// 📌 aud as an ARRAY containing the expected value, WITH a matching `azp`,
// is still accepted (some IdPs emit a multi-value aud claim - FINDING 4
// requires `azp` alongside a multi-value `aud`, see 6c-azp below for the
// cases this hardening REJECTS).
$arrayAudienceClaims = $validClaims;
$arrayAudienceClaims['aud'] = ['some-other-app', $expectedAudience];
$arrayAudienceClaims['azp'] = $expectedAudience;
$arrayAudienceJwt = testOAuthSignJwt($validHeader, $arrayAudienceClaims, $testPrivateKeyPem);
$arrayAudienceResult = oauthValidateAndDecodeIdToken($arrayAudienceJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertTrue($arrayAudienceResult['valid'], 'oauthValidateAndDecodeIdToken() ACCEPTS an array `aud` claim that CONTAINS the expected audience AND carries a matching `azp`');

// --- 6c-azp. FINDING 4 hardening (Low, OIDC Core §3.1.3.7 pts 4-5): a
// multi-value `aud` WITHOUT a matching `azp` must be REJECTED, even though
// the expected audience is present in the array - `azp` identifies which
// party the IdP actually authorized, and we must be that party, not merely
// a bystander in a shared `aud` list.

// 6c-azp-i: multi-value aud, NO azp claim at all -> rejected.
$multiAudNoAzpClaims = $validClaims;
$multiAudNoAzpClaims['aud'] = ['some-other-app', $expectedAudience];
$multiAudNoAzpJwt = testOAuthSignJwt($validHeader, $multiAudNoAzpClaims, $testPrivateKeyPem);
$multiAudNoAzpResult = oauthValidateAndDecodeIdToken($multiAudNoAzpJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($multiAudNoAzpResult['valid'], 'FINDING 4 regression: oauthValidateAndDecodeIdToken() REJECTS a multi-value `aud` with NO `azp` claim');
assertEquals('ID_TOKEN_INVALID_AUDIENCE', $multiAudNoAzpResult['errorCode'], 'multi-value aud, missing azp -> ID_TOKEN_INVALID_AUDIENCE');

// 6c-azp-ii: multi-value aud, `azp` present but points at a DIFFERENT party -> rejected.
$multiAudWrongAzpClaims = $validClaims;
$multiAudWrongAzpClaims['aud'] = ['some-other-app', $expectedAudience];
$multiAudWrongAzpClaims['azp'] = 'some-other-app';
$multiAudWrongAzpJwt = testOAuthSignJwt($validHeader, $multiAudWrongAzpClaims, $testPrivateKeyPem);
$multiAudWrongAzpResult = oauthValidateAndDecodeIdToken($multiAudWrongAzpJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($multiAudWrongAzpResult['valid'], 'FINDING 4 regression: oauthValidateAndDecodeIdToken() REJECTS a multi-value `aud` whose `azp` does NOT match this app\'s client_id');
assertEquals('ID_TOKEN_INVALID_AUDIENCE', $multiAudWrongAzpResult['errorCode'], 'multi-value aud, wrong azp -> ID_TOKEN_INVALID_AUDIENCE');

// 6c-azp-iii: single-string aud has NO azp requirement (unaffected by FINDING 4).
$singleAudNoAzpClaims = $validClaims;
unset($singleAudNoAzpClaims['azp']);
$singleAudNoAzpJwt = testOAuthSignJwt($validHeader, $singleAudNoAzpClaims, $testPrivateKeyPem);
$singleAudNoAzpResult = oauthValidateAndDecodeIdToken($singleAudNoAzpJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertTrue($singleAudNoAzpResult['valid'], 'sanity check: a single-string `aud` claim (the common case) needs NO `azp` claim - FINDING 4 only applies to a multi-value `aud`');

// --- 6d. Expired token ---------------------------------------------------------
$expiredClaims = $validClaims;
$expiredClaims['exp'] = $fixedNow - 1000;
$expiredJwt = testOAuthSignJwt($validHeader, $expiredClaims, $testPrivateKeyPem);
$expiredResult = oauthValidateAndDecodeIdToken($expiredJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($expiredResult['valid'], 'oauthValidateAndDecodeIdToken() REJECTS an EXPIRED token');
assertEquals('ID_TOKEN_EXPIRED', $expiredResult['errorCode'], 'expired token -> ID_TOKEN_EXPIRED');

// --- 6e. Bad nonce -------------------------------------------------------------
$badNonceClaims = $validClaims;
$badNonceClaims['nonce'] = 'this-is-not-the-session-bound-nonce';
$badNonceJwt = testOAuthSignJwt($validHeader, $badNonceClaims, $testPrivateKeyPem);
$badNonceResult = oauthValidateAndDecodeIdToken($badNonceJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($badNonceResult['valid'], 'oauthValidateAndDecodeIdToken() REJECTS a MISMATCHED nonce (id_token replay defence)');
assertEquals('ID_TOKEN_INVALID_NONCE', $badNonceResult['errorCode'], 'mismatched nonce -> ID_TOKEN_INVALID_NONCE');

// --- 6f. Tampered signature ------------------------------------------------------
// 🐛 Test-correctness note: an RSA-2048 signature is always exactly 256
// bytes, and 256 mod 3 == 1, so base64url's TRAILING character encodes only
// discarded padding bits for a 1-byte-leftover group - flipping THAT
// specific character can silently decode to the IDENTICAL byte string (a
// no-op "tamper" that would make this assertion flaky ~25% of the time,
// depending on the random test keypair). Tampering a character within the
// first FULL 4-char/3-byte group instead (position 5, well before the
// trailing partial group) guarantees the decoded bytes actually change,
// deterministically, on every run.
$tamperedJwtParts  = explode('.', $validJwt);
$signatureSegment  = $tamperedJwtParts[2];
$base64UrlAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
$tamperPosition    = 5;
$originalCharIndex = strpos($base64UrlAlphabet, $signatureSegment[$tamperPosition]);
$signatureSegment[$tamperPosition] = $base64UrlAlphabet[($originalCharIndex + 1) % 64];
$tamperedJwtParts[2] = $signatureSegment;
$tamperedJwt = implode('.', $tamperedJwtParts);
$tamperedResult = oauthValidateAndDecodeIdToken($tamperedJwt, $testJwks, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow);
assertFalse($tamperedResult['valid'], 'oauthValidateAndDecodeIdToken() REJECTS a token with a TAMPERED signature');
assertEquals('ID_TOKEN_INVALID_SIGNATURE', $tamperedResult['errorCode'], 'tampered signature -> ID_TOKEN_INVALID_SIGNATURE');

// --- 6g. Alg-confusion defence: 'none' and 'HS256' are both rejected -----------
$noneAlgJwt = testOAuthSignJwt(['alg' => 'none', 'typ' => 'JWT'], $validClaims, $testPrivateKeyPem);
// 'none' has no real signature in practice, but even signing it (our test
// helper always signs) must still be rejected purely on the declared `alg`.
$noneAlgResult = oauthVerifyJwtSignatureRS256($noneAlgJwt, $testJwks);
assertFalse($noneAlgResult['valid'], 'oauthVerifyJwtSignatureRS256() REJECTS alg="none" (alg-confusion defence)');
assertEquals('ID_TOKEN_UNSUPPORTED_ALG', $noneAlgResult['errorCode'], 'alg="none" -> ID_TOKEN_UNSUPPORTED_ALG');

$hs256AlgJwt = testOAuthSignJwt(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'test-key-1'], $validClaims, $testPrivateKeyPem);
$hs256AlgResult = oauthVerifyJwtSignatureRS256($hs256AlgJwt, $testJwks);
assertFalse($hs256AlgResult['valid'], 'oauthVerifyJwtSignatureRS256() REJECTS alg="HS256" (symmetric-key alg-confusion attack using the public RSA key as an HMAC secret)');
assertEquals('ID_TOKEN_UNSUPPORTED_ALG', $hs256AlgResult['errorCode'], 'alg="HS256" -> ID_TOKEN_UNSUPPORTED_ALG');

// --- 6h. Malformed token ----------------------------------------------------------
$malformedResult = oauthVerifyJwtSignatureRS256('not-a-real-jwt', $testJwks);
assertFalse($malformedResult['valid'], 'oauthVerifyJwtSignatureRS256() REJECTS a malformed (non-3-segment) token');
assertEquals('ID_TOKEN_MALFORMED', $malformedResult['errorCode'], 'malformed token -> ID_TOKEN_MALFORMED');

// --- 6i. Unknown key ID -----------------------------------------------------------
$unknownKidHeader = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'a-key-id-not-in-the-jwks'];
$unknownKidJwt    = testOAuthSignJwt($unknownKidHeader, $validClaims, $testPrivateKeyPem);
$unknownKidResult = oauthVerifyJwtSignatureRS256($unknownKidJwt, $testJwks);
assertFalse($unknownKidResult['valid'], 'oauthVerifyJwtSignatureRS256() REJECTS a token whose kid matches NO key in the JWKS');
assertEquals('ID_TOKEN_KEY_NOT_FOUND', $unknownKidResult['errorCode'], 'unknown kid -> ID_TOKEN_KEY_NOT_FOUND');

// --- 6i-kty. FINDING 3 hardening (Low): a JWK's `kty` MUST be "RSA" ------------
// A mixed/compromised JWKS could contain a non-RSA entry (e.g. an EC or oct
// key) that STILL happens to carry `n`/`e`-named members - this must be
// rejected purely on `kty`, before `n`/`e` are ever read as an RSA
// modulus/exponent pair.

// 6i-kty-i: kty="EC" (a plausible real-world JWKS entry shape) with n/e
// present anyway -> rejected.
$ecKtyJwks = [
    'keys' => [
        [
            'kty' => 'EC',
            'kid' => 'test-key-1',
            'use' => 'sig',
            'crv' => 'P-256',
            'n'   => oauthBase64UrlEncode($testKeyDetails['rsa']['n']),
            'e'   => oauthBase64UrlEncode($testKeyDetails['rsa']['e']),
        ],
    ],
];
$ecKtyResult = oauthVerifyJwtSignatureRS256($validJwt, $ecKtyJwks);
assertFalse($ecKtyResult['valid'], 'FINDING 3 regression: oauthVerifyJwtSignatureRS256() REJECTS a JWK with kty="EC" even when n/e are present');
assertEquals('ID_TOKEN_KEY_NOT_FOUND', $ecKtyResult['errorCode'], 'kty="EC" -> ID_TOKEN_KEY_NOT_FOUND (treated as no matching signing key)');

// 6i-kty-ii: kty="oct" (a raw HMAC secret - must NEVER be treated as RSA
// public key material) -> rejected.
$octKtyJwks = [
    'keys' => [
        [
            'kty' => 'oct',
            'kid' => 'test-key-1',
            'n'   => oauthBase64UrlEncode($testKeyDetails['rsa']['n']),
            'e'   => oauthBase64UrlEncode($testKeyDetails['rsa']['e']),
        ],
    ],
];
$octKtyResult = oauthVerifyJwtSignatureRS256($validJwt, $octKtyJwks);
assertFalse($octKtyResult['valid'], 'FINDING 3 regression: oauthVerifyJwtSignatureRS256() REJECTS a JWK with kty="oct" (a raw HMAC secret, never valid RSA key material)');
assertEquals('ID_TOKEN_KEY_NOT_FOUND', $octKtyResult['errorCode'], 'kty="oct" -> ID_TOKEN_KEY_NOT_FOUND');

// 6i-kty-iii: kty missing entirely -> rejected (fail closed on absence, not just wrong value).
$missingKtyJwks = [
    'keys' => [
        [
            'kid' => 'test-key-1',
            'n'   => oauthBase64UrlEncode($testKeyDetails['rsa']['n']),
            'e'   => oauthBase64UrlEncode($testKeyDetails['rsa']['e']),
        ],
    ],
];
$missingKtyResult = oauthVerifyJwtSignatureRS256($validJwt, $missingKtyJwks);
assertFalse($missingKtyResult['valid'], 'FINDING 3 regression: oauthVerifyJwtSignatureRS256() REJECTS a JWK with NO kty member at all');
assertEquals('ID_TOKEN_KEY_NOT_FOUND', $missingKtyResult['errorCode'], 'missing kty -> ID_TOKEN_KEY_NOT_FOUND');

// 6i-kty-iv: sanity check - kty="RSA" (the genuine shape) still verifies
// correctly after the hardening (no regression on the legitimate path).
$rsaKtyResult = oauthVerifyJwtSignatureRS256($validJwt, $testJwks);
assertTrue($rsaKtyResult['valid'], 'sanity check: a genuine kty="RSA" JWK still verifies successfully after the FINDING 3 hardening');

// --- 6j. Missing sub/exp claims -----------------------------------------------
$noSubClaims = $validClaims;
unset($noSubClaims['sub']);
$noSubResult = oauthValidateIdTokenClaims($noSubClaims, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow, 60);
assertFalse($noSubResult['valid'], 'oauthValidateIdTokenClaims() REJECTS a token missing the sub claim');
assertEquals('ID_TOKEN_MISSING_SUB', $noSubResult['errorCode'], 'missing sub -> ID_TOKEN_MISSING_SUB');

$noExpClaims = $validClaims;
unset($noExpClaims['exp']);
$noExpResult = oauthValidateIdTokenClaims($noExpClaims, $expectedIssuer, $expectedAudience, $expectedNonce, $fixedNow, 60);
assertFalse($noExpResult['valid'], 'oauthValidateIdTokenClaims() REJECTS a token missing the exp claim');
assertEquals('ID_TOKEN_MISSING_EXP', $noExpResult['errorCode'], 'missing exp -> ID_TOKEN_MISSING_EXP');

// ==============================================================================
// 🛰️ oauthTestRunPhpSnippet() - Execute a PHP snippet in a clean SUBPROCESS
// ==============================================================================

/**
 * 🛰️ Run a self-contained PHP snippet in a fresh child process and return stdout.
 *
 * oauthConsumePendingState()/completeAuth() need a genuinely ACTIVE PHP
 * session ($_SESSION does not even exist until session_start() succeeds).
 * The in-process runner has ALREADY produced output (its startup banner)
 * before this file is even required, so a real session_start() call here
 * would fail ("headers already sent") - EXACTLY the same constraint
 * test_core.php's own runPhpSnippet() documents for fn_csrf.php. This is a
 * SEPARATELY NAMED copy of that same technique (not a shared function) so
 * this file remains fully self-contained and independently runnable via
 * `php tests/run.php --filter=oauth` without depending on load order.
 *
 * @param string $snippet PHP code to run (WITHOUT a leading "<?php" tag). The
 *                        test bootstrap (requireFn(), CUERCODE_* constants)
 *                        is already required before this code runs.
 *
 * @return string The child process's STDOUT (stderr is discarded).
 *
 * @see https://www.php.net/manual/en/function.proc-open.php
 * @see tests/test_core.php - runPhpSnippet() (the original of this pattern)
 */
function oauthTestRunPhpSnippet(string $snippet): string
{
    $bootstrapPath = CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    $program = '<?php' . PHP_EOL
        . 'error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);' . PHP_EOL
        . 'require ' . var_export($bootstrapPath, true) . ';' . PHP_EOL
        . $snippet . PHP_EOL;

    $tmpFile = tempnam(sys_get_temp_dir(), 'cuercode_oauth_test_') . '.php';
    file_put_contents($tmpFile, $program);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stdout  = '';
    $command = [PHP_BINARY, $tmpFile];
    $process = proc_open($command, $descriptors, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
    }

    @unlink($tmpFile);

    if ($stdout === false) {
        return '';
    }

    return $stdout;
}

// ==============================================================================
// ✅ Section 7: oauthConsumePendingState() - single-use state/replay defence
// ==============================================================================
// Run entirely in a subprocess (see oauthTestRunPhpSnippet() above) so a
// genuine PHP session can be started cleanly.
// ==============================================================================

$sessionSnippet = <<<'PHP'
session_start();
requireFn('fn_oauth.php');

$results = [];

// 7a. No pending attempt in the session -> null.
unset($_SESSION[CUERCODE_OAUTH_SESSION_KEY]);
$results['noPending'] = (oauthConsumePendingState('microsoft', 'any-state-value') === null);

// 7b. A valid, matching, unexpired pending attempt is returned - AND
//     single-use: consuming it a SECOND time (replay) returns null.
$pendingState = generateOAuthState();
$_SESSION[CUERCODE_OAUTH_SESSION_KEY] = [
    'provider'     => 'google',
    'state'        => $pendingState,
    'nonce'        => 'test-nonce-value',
    'verifier'     => 'test-verifier-value',
    'next'         => '/account/',
    'link_user_id' => null,
    'expires_at'   => time() + 600,
];

$firstConsume = oauthConsumePendingState('google', $pendingState);
$results['firstConsumeIsArray'] = is_array($firstConsume);
$results['firstConsumeNonce']   = $firstConsume['nonce'] ?? null;

$replayConsume = oauthConsumePendingState('google', $pendingState);
$results['replayRejected'] = ($replayConsume === null);

// 7c. Provider mismatch -> rejected (and burned).
$pendingState2 = generateOAuthState();
$_SESSION[CUERCODE_OAUTH_SESSION_KEY] = [
    'provider'     => 'microsoft',
    'state'        => $pendingState2,
    'nonce'        => 'n',
    'verifier'     => 'v',
    'next'         => '/account/',
    'link_user_id' => null,
    'expires_at'   => time() + 600,
];
$results['providerMismatchRejected'] = (oauthConsumePendingState('google', $pendingState2) === null);

// 7d. State mismatch (tampered/forged state) -> rejected.
$pendingState3 = generateOAuthState();
$_SESSION[CUERCODE_OAUTH_SESSION_KEY] = [
    'provider'     => 'microsoft',
    'state'        => $pendingState3,
    'nonce'        => 'n',
    'verifier'     => 'v',
    'next'         => '/account/',
    'link_user_id' => null,
    'expires_at'   => time() + 600,
];
$results['stateMismatchRejected'] = (oauthConsumePendingState('microsoft', 'a-completely-different-forged-state') === null);

// 7e. Expired attempt -> rejected even with a perfectly matching state.
$pendingState4 = generateOAuthState();
$_SESSION[CUERCODE_OAUTH_SESSION_KEY] = [
    'provider'     => 'microsoft',
    'state'        => $pendingState4,
    'nonce'        => 'n',
    'verifier'     => 'v',
    'next'         => '/account/',
    'link_user_id' => null,
    'expires_at'   => time() - 10,
];
$results['expiredRejected'] = (oauthConsumePendingState('microsoft', $pendingState4) === null);

// 7f. completeAuth() with genuinely no pending state for this session.
$completeResult = completeAuth('microsoft', 'some-code', 'some-state');
$results['completeNoPendingSuccess']   = $completeResult['success'];
$results['completeNoPendingErrorCode'] = $completeResult['errorCode'];

echo json_encode($results);
PHP;

$sessionRaw  = oauthTestRunPhpSnippet($sessionSnippet);
$sessionData = json_decode($sessionRaw, true);

assertTrue(
    is_array($sessionData),
    'OAuth session subprocess returned decodable JSON (sanity check on the subprocess)'
);

if (is_array($sessionData)) {
    assertTrue($sessionData['noPending'] ?? false, 'oauthConsumePendingState() returns null when no attempt is pending');

    assertTrue($sessionData['firstConsumeIsArray'] ?? false, 'oauthConsumePendingState() returns the pending attempt on a valid FIRST consumption');
    assertEquals('test-nonce-value', $sessionData['firstConsumeNonce'] ?? null, 'oauthConsumePendingState() returns the correct bound nonce');
    assertTrue($sessionData['replayRejected'] ?? false, 'oauthConsumePendingState() REJECTS a REPLAY of an already-consumed state (single-use enforcement)');

    assertTrue($sessionData['providerMismatchRejected'] ?? false, 'oauthConsumePendingState() REJECTS a callback for a DIFFERENT provider than the one that began the flow');
    assertTrue($sessionData['stateMismatchRejected'] ?? false, 'oauthConsumePendingState() REJECTS a MISMATCHED state (CSRF/replay defence)');
    assertTrue($sessionData['expiredRejected'] ?? false, 'oauthConsumePendingState() REJECTS an EXPIRED pending attempt');

    assertFalse($sessionData['completeNoPendingSuccess'] ?? true, 'completeAuth() fails when no pending state exists for this session');
    assertEquals('SSO_STATE_INVALID', $sessionData['completeNoPendingErrorCode'] ?? null, 'completeAuth() invalid-state error code');
}

// ==============================================================================
// ✅ Section 8: fail-safe/fail-closed behaviour without getConfig()/dbQuery()
// ==============================================================================
// This bare CLI harness never loads fn_config.php or a database connection -
// every function below MUST fail closed/safe rather than fatal or leak.
// ==============================================================================

assertFalse(ssoMasterEnabled(), 'ssoMasterEnabled() fails CLOSED (false) when getConfig() is unavailable');
assertFalse(ssoProviderIsUsable('microsoft'), 'ssoProviderIsUsable() fails CLOSED without a config subsystem, even for a real provider key');
assertFalse(ssoProviderIsUsable('not-a-real-provider'), 'ssoProviderIsUsable() fails CLOSED for an unknown provider key');

$beginUnknownProvider = beginAuth('not-a-real-provider');
assertFalse($beginUnknownProvider['success'], 'beginAuth() rejects an unknown provider');
assertEquals('SSO_PROVIDER_UNKNOWN', $beginUnknownProvider['errorCode'], 'beginAuth() unknown-provider error code');

// 📌 beginAuth() with a REAL provider key fails at the ssoProviderIsUsable()
// gate BEFORE ever touching the session, so this assertion is safe to run
// in-process (no session_start() is reached on this path).
$beginDisabledProvider = beginAuth('microsoft');
assertFalse($beginDisabledProvider['success'], 'beginAuth() refuses to start a flow when the provider is not usable (no config subsystem loaded)');
assertEquals('SSO_PROVIDER_DISABLED', $beginDisabledProvider['errorCode'], 'beginAuth() disabled-provider error code');

assertEquals([], listLinkedOAuthProvidersForUser(1), 'listLinkedOAuthProvidersForUser() returns an empty array when dbQuery() is unavailable');
assertTrue(userHasPasswordSet(1), 'userHasPasswordSet() fails SAFE (assumes true) when dbQuery() is unavailable, so unlink stays blocked rather than permitted');
assertEquals(0, countLinkedOAuthProvidersForUser(1), 'countLinkedOAuthProvidersForUser() returns 0 when dbQuery() is unavailable');

$unlinkNoDb = unlinkOAuthProviderForUser(1, 1);
assertFalse($unlinkNoDb['success'], 'unlinkOAuthProviderForUser() fails safely when dbQuery() is unavailable');

$applyLinkNoDb = applyOAuthAccountLink('microsoft', 'subject-1', 'user@example.com', true, 'User', null, null);
assertFalse($applyLinkNoDb['success'], 'applyOAuthAccountLink() fails safely when dbQuery() is unavailable');
assertEquals('SERVICE_UNAVAILABLE', $applyLinkNoDb['errorCode'], 'applyOAuthAccountLink() without dbQuery() returns SERVICE_UNAVAILABLE');

$acquireUnlinkLockNoDb = acquireOAuthUnlinkLock(1);
assertFalse($acquireUnlinkLockNoDb, 'FINDING 1 (F-027) regression: acquireOAuthUnlinkLock() fails safe (false) when dbQuery() is unavailable - the caller must NOT proceed unserialised');

// releaseOAuthUnlinkLock() is a void best-effort no-op without dbQuery() -
// this call must not throw/fatal even though nothing was ever locked.
releaseOAuthUnlinkLock(1);
assertTrue(true, 'FINDING 1 (F-027) regression: releaseOAuthUnlinkLock() does not throw when dbQuery() is unavailable (best-effort no-op)');

// ==============================================================================
// 🔒 Section 8b: FINDING 1 (F-027) static regression guards - the OAuth
//    unlink TOCTOU-race fix (source-text only, no execution)
// ==============================================================================
// SECURITY-CRITICAL invariant that a single-process, no-DB harness cannot
// exercise as a genuine concurrent race (that requires a real MySQLi
// connection + multiple OS processes - see SECURITY.md F-027 for the full
// live before/after scratch-DB proof). Mirrors test_qrcodes.php's own F-023
// static-regression-guard convention exactly: pins the lock's shape, that it
// is genuinely per-USER, and that unlinkOAuthProviderForUser() actually
// wires acquire/release around the guard-check + DELETE, so a future
// "helpful" refactor cannot silently reopen this race.
// ==============================================================================

$oauthSourceForLockGuard = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_oauth.php');
assertTrue(
    is_string($oauthSourceForLockGuard) && $oauthSourceForLockGuard !== '',
    'sanity check: fn_oauth.php source was readable for the F-027 static regression guards below'
);

assertTrue(function_exists('acquireOAuthUnlinkLock'), 'F-027 regression guard: acquireOAuthUnlinkLock() is defined');
assertTrue(function_exists('releaseOAuthUnlinkLock'), 'F-027 regression guard: releaseOAuthUnlinkLock() is defined');

$acquireUnlinkLockFn = new \ReflectionFunction('acquireOAuthUnlinkLock');
assertEquals(1, $acquireUnlinkLockFn->getNumberOfParameters(), 'F-027 regression guard: acquireOAuthUnlinkLock() takes exactly 1 parameter (int $userID)');
assertTrue(
    $acquireUnlinkLockFn->hasReturnType() && (string) $acquireUnlinkLockFn->getReturnType() === 'bool',
    'F-027 regression guard: acquireOAuthUnlinkLock() returns bool (true = lock held, false = caller must NOT proceed unserialised)'
);

$releaseUnlinkLockFn = new \ReflectionFunction('releaseOAuthUnlinkLock');
assertEquals(1, $releaseUnlinkLockFn->getNumberOfParameters(), 'F-027 regression guard: releaseOAuthUnlinkLock() takes exactly 1 parameter (int $userID)');

// ------------------------------------------------------------------------
// 🔒 The lock is a real MySQL SESSION advisory lock (GET_LOCK()/
// RELEASE_LOCK()), scoped per-USER, and under its OWN lock namespace -
// distinct from fn_api_qrcodes.php's CUERCODE_SAVE_LOCK_NAME_PREFIX (an
// in-flight /save and an in-flight unlink for the SAME user must not
// contend with each other - they guard unrelated invariants).
// ------------------------------------------------------------------------
$acquireUnlinkStart = strpos($oauthSourceForLockGuard, 'function acquireOAuthUnlinkLock(');
$releaseUnlinkStart = strpos($oauthSourceForLockGuard, 'function releaseOAuthUnlinkLock(');
assertTrue(
    $acquireUnlinkStart !== false && $releaseUnlinkStart !== false && $acquireUnlinkStart < $releaseUnlinkStart,
    'sanity check: acquireOAuthUnlinkLock() and releaseOAuthUnlinkLock() are both found in fn_oauth.php, in the expected order'
);
$acquireUnlinkLockBody = substr($oauthSourceForLockGuard, $acquireUnlinkStart, $releaseUnlinkStart - $acquireUnlinkStart);

assertContains(
    'GET_LOCK(',
    $acquireUnlinkLockBody,
    'F-027 regression guard: acquireOAuthUnlinkLock() actually calls MySQL GET_LOCK() (not a no-op/stub)'
);
assertContains(
    'CUERCODE_OAUTH_UNLINK_LOCK_NAME_PREFIX . $userID',
    $acquireUnlinkLockBody,
    'F-027 regression guard: the advisory lock name is scoped PER-USER (userID) under its OWN namespace'
);
assertFalse(
    str_contains($acquireUnlinkLockBody, 'CUERCODE_SAVE_LOCK_NAME_PREFIX'),
    'F-027 regression guard: the OAuth-unlink lock does NOT reuse the F-023 save-limit lock name (they must not contend with each other)'
);

$releaseUnlinkEnd = strpos($oauthSourceForLockGuard, 'function unlinkOAuthProviderForUser(');
assertTrue(
    $releaseUnlinkEnd !== false && $releaseUnlinkEnd > $releaseUnlinkStart,
    'sanity check: unlinkOAuthProviderForUser() found after releaseOAuthUnlinkLock() in fn_oauth.php'
);
$releaseUnlinkLockBody = substr($oauthSourceForLockGuard, $releaseUnlinkStart, $releaseUnlinkEnd - $releaseUnlinkStart);

assertContains(
    'RELEASE_LOCK(',
    $releaseUnlinkLockBody,
    'F-027 regression guard: releaseOAuthUnlinkLock() actually calls MySQL RELEASE_LOCK() (not a no-op/stub)'
);

// ------------------------------------------------------------------------
// 🔒 unlinkOAuthProviderForUser() wires the lock around the guard-check +
// DELETE, and releases it inside a `finally` block covering every exit path.
// ------------------------------------------------------------------------
$unlinkFnBody = substr($oauthSourceForLockGuard, $releaseUnlinkEnd);

assertContains(
    'acquireOAuthUnlinkLock($userID)',
    $unlinkFnBody,
    'F-027 regression guard: unlinkOAuthProviderForUser() acquires the per-user OAuth-unlink lock'
);
assertContains(
    'releaseOAuthUnlinkLock($userID)',
    $unlinkFnBody,
    'F-027 regression guard: unlinkOAuthProviderForUser() releases the per-user OAuth-unlink lock'
);

$unlinkFinallyPos = strpos($unlinkFnBody, '} finally {');
assertTrue($unlinkFinallyPos !== false, 'sanity check: unlinkOAuthProviderForUser() has a "} finally {" block');
assertContains(
    'releaseOAuthUnlinkLock($userID)',
    substr($unlinkFnBody, $unlinkFinallyPos),
    'F-027 regression guard: the lock IS released inside the "finally" block itself (guaranteed on every exit path)'
);

// 📌 The guard-check (userHasPasswordSet()/countLinkedOAuthProvidersForUser())
// and the DELETE must BOTH sit strictly between the acquire and the
// "} finally {" that releases the lock - i.e. genuinely INSIDE the locked
// section, not merely present somewhere in the function.
$unlinkAcquirePos = strpos($unlinkFnBody, 'acquireOAuthUnlinkLock($userID)');
$unlinkLockedSection = substr($unlinkFnBody, $unlinkAcquirePos, $unlinkFinallyPos - $unlinkAcquirePos);

assertContains(
    'userHasPasswordSet($userID)',
    $unlinkLockedSection,
    'F-027 regression guard: the "last sign-in method" guard-check runs INSIDE the locked section (between acquire and finally)'
);
assertContains(
    'countLinkedOAuthProvidersForUser($userID)',
    $unlinkLockedSection,
    'F-027 regression guard: the linked-provider count check runs INSIDE the locked section (between acquire and finally)'
);
assertContains(
    'DELETE FROM `tblUserOAuth`',
    $unlinkLockedSection,
    'F-027 regression guard: the DELETE runs INSIDE the locked section (between acquire and finally) - this is what closes the TOCTOU race'
);

// ==============================================================================
// ✅ Section 9: provider registry / display metadata
// ==============================================================================

assertEquals(
    ['microsoft', 'google', 'signula'],
    ssoSupportedProviders(),
    'ssoSupportedProviders() returns the full registry in the expected order'
);

assertEquals('Microsoft 365', ssoProviderDisplayName('microsoft'), 'ssoProviderDisplayName() for microsoft');
assertEquals('Google', ssoProviderDisplayName('google'), 'ssoProviderDisplayName() for google');
assertEquals('SIGNula', ssoProviderDisplayName('signula'), 'ssoProviderDisplayName() for signula');

assertTrue(ssoProviderIsExperimental('signula'), 'ssoProviderIsExperimental() flags signula as experimental (pending upstream OIDC support)');
assertFalse(ssoProviderIsExperimental('microsoft'), 'ssoProviderIsExperimental() does NOT flag microsoft as experimental');
assertFalse(ssoProviderIsExperimental('google'), 'ssoProviderIsExperimental() does NOT flag google as experimental');

$signulaMeta = ssoProviderUiMeta('signula');
assertFalse($signulaMeta['usable'], 'ssoProviderUiMeta() reports signula as NOT usable (structurally inert pending upstream, even without a config subsystem)');
