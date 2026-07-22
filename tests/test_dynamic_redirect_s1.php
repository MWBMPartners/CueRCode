<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Dynamic Redirect Stage S1 Tests (Epic E1a, #27)
 * ==============================================================================
 *
 * Path: tests/test_dynamic_redirect_s1.php
 *
 * Covers Stage **S1** of the dynamic-redirect / dual-function QR design
 * (`.dev-team/dynamic_dualfn_design.md`) - the owner-facing destination API
 * (`fn_api_redirects.php`), "still dark" (no public `/r/` endpoint exists).
 *
 * Units covered (all pure, no DB, no I/O):
 *   - `redirectIsWritableBy()`     - the IDOR-enforcement predicate: exactly
 *     mirrors test_qrcodes.php's own truth-table-first approach for
 *     `qrCodeIsWritableBy()`, since this pure predicate IS the entire
 *     ownership-enforcement logic both `GET`/`PATCH
 *     /qrcodes/{uuid}/destination` handlers call, with no second/divergent
 *     ownership check anywhere else in the codebase.
 *   - `evaluateDynamicCodeQuota()` - the quota decision, exercised BOTH as
 *     an abstract truth table AND against the REAL seeded `dynamic_codes`
 *     limits (anonymous 0 / free 3 / pro 100 / business 1000,
 *     seed_data.sql) so the test proves the actual production numbers
 *     behave correctly, not just the algorithm in isolation.
 *   - A static (source-text, no execution) regression guard over
 *     `fn_api_redirects.php` pinning: the 404-not-403 IDOR convention (no
 *     literal `403` response is ever emitted by this file itself - only the
 *     router's own scope-enforcement layer can 403), that PATCH re-runs
 *     `validateRedirectDestination()`, the abuse-suspension 409
 *     `REDIRECT_SUSPENDED` block, and the F-023-style advisory-lock pattern
 *     in `createDynamicRedirectForOwner()` (mirrors test_qrcodes.php's own
 *     Section 8 F-023 regression guard for `handleSaveQRCode()`).
 *   - A static guard over the router (`public_html/api/v1/index.php`)
 *     confirming `qrcodes/{uuid}/destination` is registered own-scoped on
 *     the existing `qrcodes:read`/`qrcodes:write` scopes (no new scope was
 *     minted) and requires auth.
 *
 * ⚠️ SKIPPED (DB-dependent - out of scope for this dependency-free harness,
 *    IDENTICAL convention/rationale to test_qrcodes.php's own exclusion
 *    note): `fetchRedirectRowByUuid()`, `countActiveDynamicRedirectsForUser()`,
 *    `acquireDynamicCodeLimitLock()`/`releaseDynamicCodeLimitLock()`,
 *    `createDynamicRedirectForOwner()`, and the two HTTP handlers
 *    (`handleGetRedirectDestination()`, `handlePatchRedirectDestination()`,
 *    `handleQrDestinationItem()`) all require a live `tblQRRedirects`/
 *    `tblUsers` table via a real MySQLi connection PLUS the real HTTP router
 *    context - a live-scratch-DB + real-HTTP pass (own-scoped 404-on-others'
 *    IDOR pack, validation-on-PATCH, quota-enforcement-at-the-cap) is the
 *    Stage-S2-adjacent verification these DB-independent tests substitute
 *    for here, exactly as documented at the top of test_qrcodes.php for the
 *    saved-codes API this file's own conventions mirror throughout.
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 4 (Epic E1a Stage S1, #27)
 * @see         web/CueRCode.net/_functions/fn_redirects.php     - redirectIsWritableBy(), evaluateDynamicCodeQuota()
 * @see         web/CueRCode.net/_functions/fn_api_redirects.php - the S1 handlers this file's static guard inspects
 * @see         web/CueRCode.net/_includes/sql/seed_data.sql     - the real seeded dynamic_codes limits
 * @see         tests/test_qrcodes.php - the sibling saved-codes test file this file's conventions mirror throughout
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================

requireFn('fn_redirects.php');

// ==============================================================================
// ✅ Section 1: redirectIsWritableBy() - the IDOR-enforcement truth table
// ==============================================================================

assertTrue(
    redirectIsWritableBy(42, null, 42),
    'redirectIsWritableBy(): the true owner (matching userID) CAN write'
);
assertFalse(
    redirectIsWritableBy(42, null, 99),
    'redirectIsWritableBy(): a DIFFERENT user CANNOT write another user\'s redirect (the core IDOR assertion)'
);
assertFalse(
    redirectIsWritableBy(null, null, 42),
    'redirectIsWritableBy(): a redirect with NO owner set (ownerUserID null) is writable by NOBODY'
);
assertFalse(
    redirectIsWritableBy(42, null, null),
    'redirectIsWritableBy(): a caller identity that could not be resolved (null) is NEVER treated as a wildcard match'
);
assertFalse(
    redirectIsWritableBy(null, 7, 42),
    'redirectIsWritableBy(): an ORG-owned redirect (ownerOrgID set, ownerUserID null) fails CLOSED for any user caller - no org-auth context exists in this codebase yet'
);
assertFalse(
    redirectIsWritableBy(null, 7, null),
    'redirectIsWritableBy(): an org-owned redirect with an unresolved caller ALSO fails closed'
);
assertFalse(
    redirectIsWritableBy(null, null, null),
    'redirectIsWritableBy(): an entirely unowned row with an unresolved caller is not writable (defensive floor)'
);

// ==============================================================================
// 🔢 Section 2: evaluateDynamicCodeQuota() - abstract truth table
// ==============================================================================

assertEquals(
    ['allowed' => true, 'reason' => null],
    evaluateDynamicCodeQuota(0, -1),
    'evaluateDynamicCodeQuota(): limit=-1 (unlimited) always allows, regardless of current count'
);
assertEquals(
    ['allowed' => true, 'reason' => null],
    evaluateDynamicCodeQuota(999999, -1),
    'evaluateDynamicCodeQuota(): limit=-1 (unlimited) allows even a very large current count'
);
assertEquals(
    ['allowed' => false, 'reason' => 'not_permitted'],
    evaluateDynamicCodeQuota(0, 0),
    'evaluateDynamicCodeQuota(): limit=0 denies outright ("not_permitted"), even with a fresh (0) count'
);
assertEquals(
    ['allowed' => true, 'reason' => null],
    evaluateDynamicCodeQuota(2, 3),
    'evaluateDynamicCodeQuota(): a count strictly BELOW a positive limit is allowed'
);
assertEquals(
    ['allowed' => false, 'reason' => 'limit_reached'],
    evaluateDynamicCodeQuota(3, 3),
    'evaluateDynamicCodeQuota(): a count AT a positive limit is denied ("limit_reached")'
);
assertEquals(
    ['allowed' => false, 'reason' => 'limit_reached'],
    evaluateDynamicCodeQuota(4, 3),
    'evaluateDynamicCodeQuota(): a count OVER a positive limit is denied ("limit_reached")'
);

// ==============================================================================
// 💷 Section 3: evaluateDynamicCodeQuota() against the REAL seeded
// dynamic_codes limits (seed_data.sql - anonymous 0 / free 3 / pro 100 /
// business 1000, Pricing_Strategy.md §3) - proves the actual production
// tier numbers behave correctly through the pure decision function, not
// merely an abstract algorithm.
// ==============================================================================

$seededDynamicCodesLimits = [
    'anonymous' => 0,
    'free'      => 3,
    'pro'       => 100,
    'business'  => 1000,
];

assertEquals(
    ['allowed' => false, 'reason' => 'not_permitted'],
    evaluateDynamicCodeQuota(0, $seededDynamicCodesLimits['anonymous']),
    'evaluateDynamicCodeQuota(): the seeded "anonymous" dynamic_codes limit (0) denies outright, matching "dynamic codes are never anonymous"'
);
assertEquals(
    ['allowed' => true, 'reason' => null],
    evaluateDynamicCodeQuota(2, $seededDynamicCodesLimits['free']),
    'evaluateDynamicCodeQuota(): the seeded "free" limit (3) allows a 3rd create when the caller currently has 2 active'
);
assertEquals(
    ['allowed' => false, 'reason' => 'limit_reached'],
    evaluateDynamicCodeQuota(3, $seededDynamicCodesLimits['free']),
    'evaluateDynamicCodeQuota(): the seeded "free" limit (3) denies a 4th create once the caller already has 3 active'
);
assertEquals(
    ['allowed' => true, 'reason' => null],
    evaluateDynamicCodeQuota(99, $seededDynamicCodesLimits['pro']),
    'evaluateDynamicCodeQuota(): the seeded "pro" limit (100) allows the 100th create'
);
assertEquals(
    ['allowed' => false, 'reason' => 'limit_reached'],
    evaluateDynamicCodeQuota(1000, $seededDynamicCodesLimits['business']),
    'evaluateDynamicCodeQuota(): the seeded "business" limit (1000) denies the 1001st create'
);

// ==============================================================================
// 🧬 Section 4: STATIC regression guard over fn_api_redirects.php - the
// 404-not-403 IDOR convention, PATCH re-validation, abuse-suspension block,
// and the F-023-style advisory-lock pattern (source-text inspection only,
// mirrors test_qrcodes.php's own Section 8 F-023 static guard).
// ==============================================================================

$redirectsApiSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_redirects.php');

assertTrue(
    is_string($redirectsApiSource) && $redirectsApiSource !== '',
    'structural guard: fn_api_redirects.php was readable for static inspection'
);

// 🔐 404-not-403 IDOR convention: this file NEVER manufactures a literal 403
// response itself - the ONLY 403 a caller of these two routes can ever see
// comes from the ROUTER's own scope-enforcement layer (INSUFFICIENT_SCOPE),
// never from an ownership check inside this file.
assertFalse(
    str_contains($redirectsApiSource, ', 403,'),
    'structural guard: fn_api_redirects.php never emits a literal 403 response itself (404-not-403 IDOR convention - a 403 would confirm a UUID exists and belongs to someone else)'
);

// 🔐 Both handlers report a non-owned/missing row as 404 NOT_FOUND.
assertTrue(
    substr_count($redirectsApiSource, "404, 'NOT_FOUND'") >= 2,
    'structural guard: fn_api_redirects.php reports "not found or not owned" as 404 NOT_FOUND at least twice (GET + PATCH)'
);

// 🎯 PATCH re-runs validateRedirectDestination() - not merely trusting the
// create-time validation forever.
assertContains(
    'validateRedirectDestination(',
    $redirectsApiSource,
    'structural guard: fn_api_redirects.php calls validateRedirectDestination() (PATCH re-validates every destination write)'
);

// 🚫 The abuse-suspension block: disabledReason==='abuse' rejects the WHOLE
// PATCH with 409 REDIRECT_SUSPENDED, checked BEFORE any field is read.
assertContains(
    "\$row['disabledReason'] === 'abuse'",
    $redirectsApiSource,
    'structural guard: fn_api_redirects.php checks disabledReason===\'abuse\' before allowing any PATCH'
);
assertContains(
    "409,\n            'REDIRECT_SUSPENDED'",
    $redirectsApiSource,
    'structural guard: fn_api_redirects.php rejects an abuse-suspended PATCH with 409 REDIRECT_SUSPENDED'
);

// 🔒 F-023-style advisory lock: acquireDynamicCodeLimitLock()/
// releaseDynamicCodeLimitLock() are the ONLY lock functions in this file,
// they GET_LOCK()/RELEASE_LOCK() keyed per-user (matching the SAME pattern
// SECURITY.md F-023 established for the saved-codes API), and
// createDynamicRedirectForOwner() never calls errorResponse()/
// successResponse() (both call exit(), which skips PHP's `finally`) inside
// its own try/finally critical section - only a plain `return` of a
// structured array, which correctly runs `finally` every time.
assertContains(
    'function acquireDynamicCodeLimitLock(int $userId): bool',
    $redirectsApiSource,
    'structural guard: acquireDynamicCodeLimitLock() has the expected per-user signature'
);
assertContains(
    'function releaseDynamicCodeLimitLock(int $userId): void',
    $redirectsApiSource,
    'structural guard: releaseDynamicCodeLimitLock() has the expected per-user signature'
);
assertContains(
    "GET_LOCK(?, ?)",
    $redirectsApiSource,
    'structural guard: the advisory lock is acquired via GET_LOCK() (MySQL session advisory lock, not a row-level lock - see this file\'s own docblock for the phantom-row rationale)'
);
assertContains(
    'RELEASE_LOCK(?)',
    $redirectsApiSource,
    'structural guard: the advisory lock is released via RELEASE_LOCK()'
);

// 📌 Extract createDynamicRedirectForOwner()'s own function body (from its
// signature through the next top-level "// ====..." section banner) and
// confirm it contains NEITHER errorResponse( NOR successResponse( - both
// call exit() internally, which would skip the `finally` releasing the lock
// (the exact hazard fn_api_qrcodes.php's handleSaveQRCode() docblock
// documents and deliberately avoids the same way).
$createFunctionStart = strpos($redirectsApiSource, 'function createDynamicRedirectForOwner(');
assertTrue($createFunctionStart !== false, 'structural guard: located createDynamicRedirectForOwner() in fn_api_redirects.php');

if ($createFunctionStart !== false) {
    $nextBannerPos = strpos($redirectsApiSource, '// ==============================================================================', $createFunctionStart + 40);
    if ($nextBannerPos === false) {
        $nextBannerPos = strlen($redirectsApiSource);
    }
    $createFunctionBody = substr($redirectsApiSource, $createFunctionStart, $nextBannerPos - $createFunctionStart);

    // 🧹 Strip comments (// line comments and /** */ / /* */ block comments)
    // before searching for a REAL call - this function's own docblock/inline
    // comments deliberately DISCUSS why it avoids errorResponse()/
    // successResponse() in prose, which would otherwise false-positive a
    // naive raw-source substring search.
    $createFunctionCodeOnly = preg_replace('#/\*.*?\*/#s', '', $createFunctionBody);
    $createFunctionCodeOnly = preg_replace('#//[^\n]*#', '', (string) $createFunctionCodeOnly);

    assertFalse(
        str_contains((string) $createFunctionCodeOnly, 'errorResponse('),
        'structural guard: createDynamicRedirectForOwner() never CALLS errorResponse() in real code (it returns a structured array only - it is not itself an HTTP-response-emitting handler, and an exit()-calling function would skip its finally{} lock release)'
    );
    assertFalse(
        str_contains((string) $createFunctionCodeOnly, 'successResponse('),
        'structural guard: createDynamicRedirectForOwner() never CALLS successResponse() in real code either, for the same exit()-vs-finally reason'
    );
}

// ==============================================================================
// 🧬 Section 5: STATIC guard over the router - qrcodes/{uuid}/destination is
// registered own-scoped on the EXISTING qrcodes:read/qrcodes:write scopes
// (no new scope was minted for this stage) and requires authentication.
// ==============================================================================

$routerSourcePath = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'api' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'index.php';
$routerSource = (string) file_get_contents($routerSourcePath);

assertTrue(
    is_string($routerSource) && $routerSource !== '',
    'structural guard: the router source was readable for static inspection'
);

assertContains(
    "'pattern'   => 'qrcodes/{uuid}/destination',",
    $routerSource,
    'router: the qrcodes/{uuid}/destination route is registered'
);

// 📌 Extract the route table entry for this pattern - a fixed-size window
// starting at the 'pattern' line is simplest/most robust here (route table
// entries are short and single-purpose; searching for the first "]," would
// incorrectly stop at the 'methods' => ['GET', 'PATCH'] array's OWN closing
// bracket, several lines before the entry actually ends).
$destinationRoutePos = strpos($routerSource, "'pattern'   => 'qrcodes/{uuid}/destination',");
assertTrue($destinationRoutePos !== false, 'structural guard: located the destination route table entry');

if ($destinationRoutePos !== false) {
    $routeEntryText = substr($routerSource, $destinationRoutePos, 700);

    assertContains(
        "'auth'      => true",
        $routeEntryText,
        'router: qrcodes/{uuid}/destination requires authentication (auth => true)'
    );
    assertContains(
        "'GET'   => 'qrcodes:read',",
        $routeEntryText,
        'router: GET qrcodes/{uuid}/destination reuses the EXISTING qrcodes:read scope (no new scope minted)'
    );
    assertContains(
        "'PATCH' => 'qrcodes:write',",
        $routeEntryText,
        'router: PATCH qrcodes/{uuid}/destination reuses the EXISTING qrcodes:write scope (no new scope minted)'
    );
}

// 📌 The scope allowlist itself is UNCHANGED at 7 entries - confirms this
// stage genuinely added zero new scopes (mirrors the equivalent assertion in
// tests/test_openapi_render.php, re-affirmed here for this file's own
// self-containment).
requireFn('fn_api_keys.php');

assertTrue(
    defined('CUERCODE_API_KEY_SCOPE_ALLOWLIST'),
    'fn_api_keys.php defines CUERCODE_API_KEY_SCOPE_ALLOWLIST'
);
assertEquals(
    7,
    count(defined('CUERCODE_API_KEY_SCOPE_ALLOWLIST') ? CUERCODE_API_KEY_SCOPE_ALLOWLIST : []),
    'the API-key scope allowlist stays at exactly 7 entries - Epic E1a Stage S1 (#27) added NO new scope, riding the existing qrcodes:read/qrcodes:write pair'
);

// ==============================================================================
// 🧬 DYNAMIC guard: every OTHER test file that has ALREADY RUN by this point
// recorded zero failures (mirrors the sibling S0 file's own dynamic guard).
// ==============================================================================

$priorResults      = $GLOBALS['__CUERCODE_TEST_RESULTS'] ?? [];
$priorFailureCount = 0;
$priorFailureFiles = [];

foreach ($priorResults as $priorResult) {
    if ($priorResult['pass'] === false) {
        $priorFailureCount++;
        if (!in_array($priorResult['file'], $priorFailureFiles, true)) {
            $priorFailureFiles[] = $priorResult['file'];
        }
    }
}

assertTrue(
    count($priorResults) > 1000,
    'sanity: a substantial number of assertions from OTHER test files already ran before this file (found: ' . count($priorResults) . ')'
);

assertEquals(
    0,
    $priorFailureCount,
    'DYNAMIC REGRESSION GUARD: zero failures recorded by any test file that ran before this one in the suite (failing files if any: ' . implode(', ', $priorFailureFiles) . ')'
);
