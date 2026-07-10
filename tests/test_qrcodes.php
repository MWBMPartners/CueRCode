<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for the Saved-Codes API (Epic E3 stage 2a)
 * ==============================================================================
 *
 * Path: tests/test_qrcodes.php
 *
 * Covers the DB-INDEPENDENT surface of fn_api_qrcodes.php - and this file is
 * deliberately weighted towards the OWNERSHIP PREDICATES
 * (qrCodeIsReadableBy() / qrCodeIsWritableBy() / qrCodeIsDownloadableBy())
 * because those three pure functions ARE the entire IDOR-enforcement logic
 * for this stage: every route (GET/PATCH/DELETE /qrcodes/{uuid}, POST /save,
 * and the hardened GET /download/{uuid}) calls exactly one of them, with no
 * second/divergent ownership check anywhere else in the codebase. Exhaustively
 * table-testing their truth tables here is the cheapest, fastest, and most
 * exhaustive way to pin the exact access-control contract this whole stage
 * exists to enforce - independent of, and complementary to, the live
 * scratch-DB + real-HTTP-router IDOR pack described below.
 *
 * Units covered (all pure, no DB, no I/O):
 *   - isValidQrUuid()          - UUID v4 shape validation
 *   - qrCodeIsReadableBy()     - GET /qrcodes/{uuid} access rule
 *   - qrCodeIsWritableBy()     - PATCH/DELETE /qrcodes/{uuid} access rule
 *   - qrCodeIsDownloadableBy() - GET /download/{uuid} access rule (this
 *                                stage's hardening of a PRE-EXISTING route)
 *   - validateQrLabelInput()   - "label" field validation (POST /save, PATCH)
 *   - sanitizeQrTypeFilter()   - "?type=" list filter normalisation
 *   - clampPaginationParams()  - "?page=/?per_page=" pagination clamp
 *   - Section 8 (static, source-text only): a REGRESSION GUARD for
 *     SECURITY.md F-023 (the saved_codes plan-limit TOCTOU race fix) - pins
 *     acquireSaveLimitLock()/releaseSaveLimitLock()'s signatures, that they
 *     really call GET_LOCK()/RELEASE_LOCK() keyed per-USER, and that
 *     handleSaveQRCode() never calls errorResponse()/successResponse()
 *     (both exit() internally, which skips PHP's `finally`) between
 *     acquiring and releasing the lock. A genuine concurrent race cannot be
 *     asserted in this no-DB, single-process harness - see SECURITY.md/
 *     CHANGELOG.md for the live scratch-DB + real-multi-process before/
 *     after concurrent-request evidence that IS the actual proof.
 *
 * ⚠️ SKIPPED (DB-dependent - out of scope for this dependency-free harness,
 *    same convention as test_auth.php's registerUser()/loginUser()/etc.
 *    exclusion): fetchQrCodeRowByUuid(), countSavedQrCodesForUser(), and the
 *    five HTTP handlers (handleSaveQRCode(), handleListQRCodes(),
 *    handleGetQRCode(), handlePatchQRCode(), handleDeleteQRCode(),
 *    handleQrCodeItem()) all require a live tblQRCodes/tblUsers/
 *    tblUserSessions table via a real MySQLi connection PLUS the real HTTP
 *    router ($_SERVER/session context) - these are exercised end-to-end
 *    against a throwaway scratch database and a live `php -S` instance of
 *    public_html/api/v1/index.php (mirroring the E3 stage 1 PR evidence
 *    convention - see SECURITY.md / CHANGELOG.md for the pasted IDOR-pack
 *    results), NOT in this in-process harness. That pass specifically
 *    proves, over real HTTP with two independently-registered accounts A and
 *    B, each owning one PRIVATE saved code:
 *      - A can GET/PATCH/DELETE/DOWNLOAD its own code (200s throughout).
 *      - B's GET/PATCH/DELETE/DOWNLOAD of A's PRIVATE code all return 404
 *        (never 403/200) - the core IDOR assertion this stage exists for.
 *      - A PUBLIC code (is_public=true) is GET/downloadable cross-user (B
 *        can read A's public code) but NOT patchable/deletable by B (still 404).
 *      - POST /save returns 402 SAVED_CODES_LIMIT_REACHED once the caller's
 *        plan saved_codes cap is reached, and 201/200 under it.
 *      - GET /qrcodes never returns another user's rows; pagination
 *        (page/per_page) slices correctly.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2a, #52/#24)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_api_qrcodes.php
 * @see         web/CueRCode.net/_functions/fn_api_download.php - the hardened /download/{uuid}
 */

// ==============================================================================
// 🧰 Load the DB-independent production function file (NO database connection)
// ==============================================================================
// fn_api_qrcodes.php only DEFINES functions at include time (every DB touch is
// lazy, inside a function body via dbQuery()), so including it opens no
// connection - identical convention to fn_api_generate.php's inclusion in
// test_api_parity.php.
// ==============================================================================

requireFn('fn_api_qrcodes.php');

// ==============================================================================
// ✅ Section 1: isValidQrUuid() - UUID v4 shape validation
// ==============================================================================

assertTrue(
    isValidQrUuid('a1b2c3d4-e5f6-4890-abcd-ef1234567890'),
    'isValidQrUuid() accepts a well-formed UUID v4'
);
assertTrue(
    isValidQrUuid('A1B2C3D4-E5F6-4890-ABCD-EF1234567890'),
    'isValidQrUuid() is case-insensitive'
);
assertFalse(
    isValidQrUuid('not-a-uuid'),
    'isValidQrUuid() rejects an obviously malformed string'
);
assertFalse(
    isValidQrUuid('a1b2c3d4-e5f6-1890-abcd-ef1234567890'),
    'isValidQrUuid() rejects a non-v4 UUID (wrong version nibble)'
);
assertFalse(
    isValidQrUuid('a1b2c3d4-e5f6-4890-0bcd-ef1234567890'),
    'isValidQrUuid() rejects a UUID with an invalid variant nibble'
);
assertFalse(
    isValidQrUuid(''),
    'isValidQrUuid() rejects an empty string'
);
assertFalse(
    isValidQrUuid('a1b2c3d4-e5f6-4890-abcd-ef1234567890; DROP TABLE tblQRCodes;'),
    'isValidQrUuid() rejects a UUID with SQL-injection-shaped trailing content'
);

// ==============================================================================
// 🔐 Section 2: qrCodeIsReadableBy() - GET /qrcodes/{uuid} access rule
// ==============================================================================
// Truth table: readable iff isPublic OR (ownerUserId !== null AND
// callerUserId !== null AND ownerUserId === callerUserId).
// ==============================================================================

assertTrue(
    qrCodeIsReadableBy(1, false, 1),
    'qrCodeIsReadableBy(): the OWNER can read their own PRIVATE code'
);
assertFalse(
    qrCodeIsReadableBy(1, false, 2),
    'qrCodeIsReadableBy(): a DIFFERENT user cannot read a PRIVATE code (the core GET IDOR assertion)'
);
assertTrue(
    qrCodeIsReadableBy(1, true, 2),
    'qrCodeIsReadableBy(): ANY caller can read a PUBLIC code, even a different user'
);
assertTrue(
    qrCodeIsReadableBy(1, true, null),
    'qrCodeIsReadableBy(): a PUBLIC code is readable even when the caller identity could not be resolved'
);
assertFalse(
    qrCodeIsReadableBy(null, false, 1),
    'qrCodeIsReadableBy(): an UNCLAIMED (no owner), non-public code is NOT readable via this endpoint (it is not "in" anyone\'s library)'
);
assertFalse(
    qrCodeIsReadableBy(1, false, null),
    'qrCodeIsReadableBy(): a private code is not readable when the caller identity is unresolved (never treated as a wildcard match)'
);
assertFalse(
    qrCodeIsReadableBy(null, false, null),
    'qrCodeIsReadableBy(): no owner, not public, no resolved caller -> not readable'
);

// ==============================================================================
// 🔐 Section 3: qrCodeIsWritableBy() - PATCH/DELETE /qrcodes/{uuid} access rule
// ==============================================================================
// Truth table: writable iff ownerUserId !== null AND callerUserId !== null AND
// ownerUserId === callerUserId. isPublic is DELIBERATELY not a parameter here -
// it must never influence write access.
// ==============================================================================

assertTrue(
    qrCodeIsWritableBy(1, 1),
    'qrCodeIsWritableBy(): the OWNER can write their own code'
);
assertFalse(
    qrCodeIsWritableBy(1, 2),
    'qrCodeIsWritableBy(): a DIFFERENT user cannot write a code they do not own (the core PATCH/DELETE IDOR assertion)'
);
assertFalse(
    qrCodeIsWritableBy(null, 1),
    'qrCodeIsWritableBy(): an UNCLAIMED code (no owner) is not writable by anyone via this endpoint'
);
assertFalse(
    qrCodeIsWritableBy(1, null),
    'qrCodeIsWritableBy(): an unresolved caller identity can never write, regardless of ownerUserId'
);
assertFalse(
    qrCodeIsWritableBy(null, null),
    'qrCodeIsWritableBy(): no owner and no resolved caller -> not writable'
);

// 📌 Regression guard: a PUBLIC code must NOT be writable by a non-owner -
// qrCodeIsWritableBy() has no isPublic parameter at all, so this is really a
// signature/contract check (public flag simply cannot leak into this
// decision), pinned here so a future "helpful" refactor cannot accidentally
// wire isPublic into the write check.
assertTrue(
    (new \ReflectionFunction('qrCodeIsWritableBy'))->getNumberOfParameters() === 2,
    'qrCodeIsWritableBy() takes exactly 2 parameters (ownerUserId, callerUserId) - isPublic cannot influence write access even by accident'
);

// ==============================================================================
// 🔐 Section 4: qrCodeIsDownloadableBy() - GET /download/{uuid} hardening rule
// ==============================================================================
// Truth table: downloadable iff ownerUserId === null (UNCLAIMED - preserves
// the pre-existing anonymous generate -> download flow) OR isPublic OR
// (callerUserId !== null AND callerUserId === ownerUserId).
// ==============================================================================

assertTrue(
    qrCodeIsDownloadableBy(null, false, 1),
    'qrCodeIsDownloadableBy(): an UNCLAIMED code remains downloadable by any authenticated caller (no regression to the pre-stage generate -> download flow)'
);
assertTrue(
    qrCodeIsDownloadableBy(null, false, null),
    'qrCodeIsDownloadableBy(): an UNCLAIMED code is downloadable even if the caller identity could not be resolved (still gated by the router\'s own auth=true requirement upstream)'
);
assertTrue(
    qrCodeIsDownloadableBy(1, false, 1),
    'qrCodeIsDownloadableBy(): the OWNER can download their own PRIVATE saved code'
);
assertFalse(
    qrCodeIsDownloadableBy(1, false, 2),
    'qrCodeIsDownloadableBy(): a DIFFERENT user cannot download a PRIVATE saved code (the core download IDOR assertion)'
);
assertTrue(
    qrCodeIsDownloadableBy(1, true, 2),
    'qrCodeIsDownloadableBy(): a PUBLIC saved code is downloadable cross-user'
);
assertFalse(
    qrCodeIsDownloadableBy(1, false, null),
    'qrCodeIsDownloadableBy(): a CLAIMED, private code is not downloadable when the caller identity is unresolved'
);

// ==============================================================================
// 🏷️ Section 5: validateQrLabelInput() - "label" field validation
// ==============================================================================

// 📌 Not provided at all -> "leave unchanged" (provided=false, value=null).
$notProvided = validateQrLabelInput(null, false);
assertTrue($notProvided['valid'], 'validateQrLabelInput(): an absent field is valid (no-op)');
assertFalse($notProvided['provided'], 'validateQrLabelInput(): an absent field reports provided=false');
assertEquals(null, $notProvided['value'], 'validateQrLabelInput(): an absent field has a null value');

// 📌 Explicit null -> "clear it" (provided=true, value=null) - DISTINCT from "absent".
$explicitNull = validateQrLabelInput(null, true);
assertTrue($explicitNull['valid'], 'validateQrLabelInput(): an explicit null is valid (clears the label)');
assertTrue($explicitNull['provided'], 'validateQrLabelInput(): an explicit null reports provided=true (distinct from "absent")');
assertEquals(null, $explicitNull['value'], 'validateQrLabelInput(): an explicit null has a null value');

// 📌 A normal label is trimmed and accepted.
$normal = validateQrLabelInput('  My Homepage QR  ', true);
assertTrue($normal['valid'], 'validateQrLabelInput(): a normal label is valid');
assertEquals('My Homepage QR', $normal['value'], 'validateQrLabelInput(): a normal label is trimmed');

// 📌 An empty-after-trim string clears the label (same as explicit null).
$blank = validateQrLabelInput('   ', true);
assertTrue($blank['valid'], 'validateQrLabelInput(): a whitespace-only label is valid (treated as clearing)');
assertEquals(null, $blank['value'], 'validateQrLabelInput(): a whitespace-only label normalises to null');

// 📌 Exactly at the length cap is accepted; one over is rejected.
$exactly150 = str_repeat('a', 150);
$atCap      = validateQrLabelInput($exactly150, true);
assertTrue($atCap['valid'], 'validateQrLabelInput(): a label at EXACTLY the 150-char cap is valid');

$over150 = str_repeat('a', 151);
$overCap = validateQrLabelInput($over150, true);
assertFalse($overCap['valid'], 'validateQrLabelInput(): a label ONE character over the 150-char cap is rejected');
assertContains('150', (string) $overCap['error'], 'validateQrLabelInput(): the over-length error message names the 150-char limit');

// 📌 A non-string, non-null value is rejected (no untyped pass-through).
$notAString = validateQrLabelInput(['array', 'not', 'a', 'string'], true);
assertFalse($notAString['valid'], 'validateQrLabelInput(): an array value is rejected (must be a string or null)');

$numeric = validateQrLabelInput(12345, true);
assertFalse($numeric['valid'], 'validateQrLabelInput(): a bare integer value is rejected (must be a string or null, no implicit stringification)');

// 📌 A multi-byte (emoji/unicode) label is measured in CHARACTERS (mb_strlen),
// not bytes, matching the house convention used elsewhere (e.g. displayName).
$emojiLabel = str_repeat('🔒', 150); // 150 CHARACTERS, but > 150 bytes (4 bytes/emoji in UTF-8)
$emojiResult = validateQrLabelInput($emojiLabel, true);
assertTrue($emojiResult['valid'], 'validateQrLabelInput(): 150 multi-byte characters are valid (length is measured in characters, not bytes)');

// ==============================================================================
// 🔎 Section 6: sanitizeQrTypeFilter() - "?type=" list filter normalisation
// ==============================================================================

assertEquals(
    'url',
    sanitizeQrTypeFilter('URL'),
    'sanitizeQrTypeFilter() lower-cases the filter value'
);
assertEquals(
    'wifi',
    sanitizeQrTypeFilter('  wifi  '),
    'sanitizeQrTypeFilter() trims surrounding whitespace'
);
assertEquals(
    null,
    sanitizeQrTypeFilter(''),
    'sanitizeQrTypeFilter() treats an empty string as "no filter"'
);
assertEquals(
    null,
    sanitizeQrTypeFilter(null),
    'sanitizeQrTypeFilter() treats a null value as "no filter"'
);
assertEquals(
    null,
    sanitizeQrTypeFilter(['not', 'a', 'string']),
    'sanitizeQrTypeFilter() treats a non-string value as "no filter" (no untyped pass-through into the SQL bind)'
);
assertEquals(
    str_repeat('a', 100),
    sanitizeQrTypeFilter(str_repeat('a', 500)),
    'sanitizeQrTypeFilter() caps the filter length at 100 characters (matches tblQRCodeTypes.typeName column width)'
);

// ==============================================================================
// 📄 Section 7: clampPaginationParams() - pagination clamp
// ==============================================================================

$defaultPagination = clampPaginationParams(null, null);
assertEquals(1, $defaultPagination['page'], 'clampPaginationParams(): a null page defaults to 1');
assertEquals(20, $defaultPagination['per_page'], 'clampPaginationParams(): a null per_page defaults to 20');
assertEquals(0, $defaultPagination['offset'], 'clampPaginationParams(): page 1 has offset 0');

$page3 = clampPaginationParams(3, 10);
assertEquals(3, $page3['page'], 'clampPaginationParams(): an explicit page is honoured');
assertEquals(10, $page3['per_page'], 'clampPaginationParams(): an explicit per_page is honoured');
assertEquals(20, $page3['offset'], 'clampPaginationParams(): offset = (page - 1) * per_page (2 * 10 = 20)');

$zeroPage = clampPaginationParams(0, 20);
assertEquals(1, $zeroPage['page'], 'clampPaginationParams(): page=0 is clamped up to 1');

$negativePage = clampPaginationParams(-5, 20);
assertEquals(1, $negativePage['page'], 'clampPaginationParams(): a negative page is clamped up to 1');

$overCapPerPage = clampPaginationParams(1, 9999);
assertEquals(100, $overCapPerPage['per_page'], 'clampPaginationParams(): per_page is capped at 100 regardless of the requested value');

$zeroPerPage = clampPaginationParams(1, 0);
assertEquals(20, $zeroPerPage['per_page'], 'clampPaginationParams(): per_page=0 falls back to the default (20), not 0 (which would divide-by-zero downstream)');

$negativePerPage = clampPaginationParams(1, -10);
assertEquals(20, $negativePerPage['per_page'], 'clampPaginationParams(): a negative per_page falls back to the default');

$numericStrings = clampPaginationParams('2', '15');
assertEquals(2, $numericStrings['page'], 'clampPaginationParams(): a numeric-string page is coerced to int');
assertEquals(15, $numericStrings['per_page'], 'clampPaginationParams(): a numeric-string per_page is coerced to int');

$nonNumericGarbage = clampPaginationParams('not-a-number', 'also-not-a-number');
assertEquals(1, $nonNumericGarbage['page'], 'clampPaginationParams(): non-numeric page garbage coerces to 0 -> clamped up to 1 (no fatal, no injection surface)');
assertEquals(20, $nonNumericGarbage['per_page'], 'clampPaginationParams(): non-numeric per_page garbage coerces to 0 -> falls back to the default');

// ==============================================================================
// 🔒 Section 8: F-023 - saved_codes plan-limit TOCTOU race regression guard
// ==============================================================================
// SECURITY.md F-023 (High, FIXED 2026-07-10): handleSaveQRCode()'s
// saved_codes plan-limit COUNT check and the claim UPDATE used to be two
// independent statements with no serialisation between them. N
// TRULY-CONCURRENT /save requests for N DIFFERENT unclaimed UUIDs from the
// SAME user could each pass the count check before any claim landed,
// blowing straight past the plan cap - proven live: 5 parallel OS
// processes took a cap-2 user to 6 saved codes (see SECURITY.md/
// CHANGELOG.md for the full before/after concurrent-request evidence).
//
// A genuine RACE cannot be asserted in this dependency-free, single-process,
// no-DB harness (same limitation this repo already accepts for every other
// DB-touching handler in this file - see the file's own docblock). What
// CAN be verified here, cheaply and on every run, is that the SOURCE CODE
// still has the specific shape the fix requires: a per-user advisory lock
// wraps the count-check + claim, and NOTHING that can exit() the script
// (errorResponse()/successResponse()) runs between acquiring the lock and
// releasing it in `finally` - a static regression guard against someone
// innocently "simplifying" this back to the unserialised pre-fix shape.
//
// This reads the production file as plain text (no execution), so it adds
// zero DB dependency and zero runtime risk to the harness.
// ==============================================================================

$qrcodesSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_qrcodes.php');
assertTrue(is_string($qrcodesSource) && $qrcodesSource !== '', 'sanity check: fn_api_qrcodes.php source was readable for the F-023 static regression guards below');

// ------------------------------------------------------------------------
// 🔒 acquireSaveLimitLock()/releaseSaveLimitLock() exist with the expected
// signatures (a future refactor renaming/removing them would silently drop
// the fix - pin the exact contract handleSaveQRCode() depends on).
// ------------------------------------------------------------------------
assertTrue(
    function_exists('acquireSaveLimitLock'),
    'F-023 regression guard: acquireSaveLimitLock() is defined'
);
assertTrue(
    function_exists('releaseSaveLimitLock'),
    'F-023 regression guard: releaseSaveLimitLock() is defined'
);

$acquireLockFn = new \ReflectionFunction('acquireSaveLimitLock');
assertEquals(1, $acquireLockFn->getNumberOfParameters(), 'F-023 regression guard: acquireSaveLimitLock() takes exactly 1 parameter (int $userId)');
assertTrue(
    $acquireLockFn->hasReturnType() && (string) $acquireLockFn->getReturnType() === 'bool',
    'F-023 regression guard: acquireSaveLimitLock() returns bool (true = lock held, false = caller must NOT proceed unserialised)'
);

$releaseLockFn = new \ReflectionFunction('releaseSaveLimitLock');
assertEquals(1, $releaseLockFn->getNumberOfParameters(), 'F-023 regression guard: releaseSaveLimitLock() takes exactly 1 parameter (int $userId)');

// ------------------------------------------------------------------------
// 🔒 The lock is a real MySQL SESSION advisory lock (GET_LOCK()/
// RELEASE_LOCK()), not a placeholder - and it is per-USER, not per-qrID
// (the whole point: the race is across DIFFERENT qrIDs for the SAME user).
// ------------------------------------------------------------------------
$acquireStart = strpos($qrcodesSource, 'function acquireSaveLimitLock(');
$releaseStart = strpos($qrcodesSource, 'function releaseSaveLimitLock(');
assertTrue(
    $acquireStart !== false && $releaseStart !== false && $acquireStart < $releaseStart,
    'sanity check: acquireSaveLimitLock() and releaseSaveLimitLock() are both found in fn_api_qrcodes.php, in the expected order'
);
$acquireLockBody = substr($qrcodesSource, $acquireStart, $releaseStart - $acquireStart);

assertContains(
    'GET_LOCK(',
    $acquireLockBody,
    'F-023 regression guard: acquireSaveLimitLock() actually calls MySQL GET_LOCK() (not a no-op/stub)'
);
assertContains(
    'CUERCODE_SAVE_LOCK_NAME_PREFIX . $userId',
    $acquireLockBody,
    'F-023 regression guard: the advisory lock name is scoped PER-USER (userID), not per-qrID or global - this is what closes the DIFFERENT-qrID race'
);

$releaseLockEnd  = strpos($qrcodesSource, 'function regenerateQrOutputForPatch(');
assertTrue($releaseLockEnd !== false && $releaseLockEnd > $releaseStart, 'sanity check: regenerateQrOutputForPatch() found after releaseSaveLimitLock() in fn_api_qrcodes.php');
$releaseLockBody = substr($qrcodesSource, $releaseStart, $releaseLockEnd - $releaseStart);

assertContains(
    'RELEASE_LOCK(',
    $releaseLockBody,
    'F-023 regression guard: releaseSaveLimitLock() actually calls MySQL RELEASE_LOCK() (not a no-op/stub)'
);

// ------------------------------------------------------------------------
// 🔒 handleSaveQRCode() wires the lock around the count-check + claim, and
// - the critical invariant - never calls errorResponse()/successResponse()
// (both exit() internally, which does NOT run PHP `finally` blocks) between
// acquiring the lock and releasing it. If this ever regresses, a caught
// exception or an early exit() could leave later code believing the lock
// was released when it wasn't, or worse, silently skip releasing it.
// ------------------------------------------------------------------------
$handleSaveStart = strpos($qrcodesSource, 'function handleSaveQRCode(');
$handleSaveEnd   = strpos($qrcodesSource, 'function handleListQRCodes(');
assertTrue(
    $handleSaveStart !== false && $handleSaveEnd !== false && $handleSaveStart < $handleSaveEnd,
    'sanity check: handleSaveQRCode() and handleListQRCodes() are both found in fn_api_qrcodes.php, in the expected order'
);
$handleSaveBody = substr($qrcodesSource, $handleSaveStart, $handleSaveEnd - $handleSaveStart);

assertContains(
    'acquireSaveLimitLock($currentUserId)',
    $handleSaveBody,
    'F-023 regression guard: handleSaveQRCode() acquires the per-user save-limit lock'
);
assertContains(
    'releaseSaveLimitLock($currentUserId)',
    $handleSaveBody,
    'F-023 regression guard: handleSaveQRCode() releases the per-user save-limit lock'
);
assertContains(
    'SAVE_LOCK_BUSY',
    $handleSaveBody,
    'F-023 regression guard: a GET_LOCK() timeout/error fails SAFE with a distinct SAVE_LOCK_BUSY error code (never proceeds unserialised)'
);
assertContains(
    'Retry-After',
    $handleSaveBody,
    'F-023 regression guard: the SAVE_LOCK_BUSY response carries a Retry-After header so well-behaved clients back off'
);

// 📌 Isolate the substring from where $outcome is initialised (the START of
// the try{} that is guaranteed to reach the "} finally {" below - NOT from
// the lock-acquire call itself, since the "GET_LOCK() timed out" branch
// legitimately calls errorResponse() BEFORE the lock is ever held, which is
// fine - there is nothing to release yet on that path) up to the
// "} finally {" that releases the lock. THIS is the section that must NOT
// call errorResponse()/successResponse(), since exit() inside either would
// skip the release.
$outcomeInitPos = strpos($handleSaveBody, '$outcome = null;');
$finallyPos     = strpos($handleSaveBody, '} finally {');
assertTrue(
    $outcomeInitPos !== false && $finallyPos !== false && $outcomeInitPos < $finallyPos,
    'sanity check: handleSaveQRCode() has a "} finally {" block AFTER $outcome is initialised'
);
$criticalSectionBody = substr($handleSaveBody, $outcomeInitPos, $finallyPos - $outcomeInitPos);

assertFalse(
    str_contains($criticalSectionBody, 'errorResponse('),
    'F-023 regression guard: no errorResponse() call (which exit()s, bypassing `finally`) exists between acquiring and releasing the lock'
);
assertFalse(
    str_contains($criticalSectionBody, 'successResponse('),
    'F-023 regression guard: no successResponse() call (which exit()s, bypassing `finally`) exists between acquiring and releasing the lock'
);
assertContains(
    'releaseSaveLimitLock($currentUserId)',
    substr($handleSaveBody, $finallyPos),
    'F-023 regression guard: the lock IS released inside the "finally" block itself (guaranteed on every exit path - normal completion, a caught exception, or the belt-and-braces outer catch)'
);
