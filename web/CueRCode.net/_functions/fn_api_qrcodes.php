<?php
/**
 * ==============================================================================
 * 📚 CueRCode - Saved-Codes API (Epic E3 stage 2a, #52/#24, B-001/B-002)
 * ==============================================================================
 *
 * Path: _functions/fn_api_qrcodes.php
 *
 * The FIRST ownership-scoped resource surface in CueRCode: lets an
 * authenticated user claim a previously-generated QR code into their account
 * (POST /save), list/inspect their own saved codes (GET /qrcodes,
 * GET /qrcodes/{uuid}), edit them (PATCH /qrcodes/{uuid}), and remove them
 * (DELETE /qrcodes/{uuid}). Every single-object route re-fetches the row and
 * checks ownership BEFORE any read/mutate/delete - see the "IDOR posture"
 * section below and SECURITY.md for the audit this stage's routes underwent.
 *
 * Endpoints handled:
 *   - POST   /api/v1/save              -> handleSaveQRCode()
 *   - GET    /api/v1/qrcodes           -> handleListQRCodes()
 *   - GET    /api/v1/qrcodes/{uuid}    -> handleQrCodeItem() -> handleGetQRCode()
 *   - PATCH  /api/v1/qrcodes/{uuid}    -> handleQrCodeItem() -> handlePatchQRCode()
 *   - DELETE /api/v1/qrcodes/{uuid}    -> handleQrCodeItem() -> handleDeleteQRCode()
 *
 * (The router matches a route PATTERN, not a pattern+method pair - see
 * public_html/api/v1/index.php - so GET/PATCH/DELETE on the same
 * "qrcodes/{uuid}" pattern share ONE route table entry and ONE handler,
 * handleQrCodeItem(), which reads $_SERVER['REQUEST_METHOD'] and delegates to
 * the method-specific function. This mirrors handleBulkGenerate()'s existing
 * convention of a router-facing handler that does its own body/context reads.)
 *
 * IDOR posture (the whole point of this stage - see SECURITY.md):
 *   - EVERY route resolves the caller's userID from the SERVER-SIDE
 *     session/API-key identity ONLY (resolveUserIdFromAuth(), fn_features.php)
 *     - never from a client-supplied field. There is no "userID" the caller
 *       can pass in a request body/query string that has any effect here.
 *   - EVERY single-object route (GET/PATCH/DELETE one uuid, and /save's
 *     claim-or-update branch) re-fetches the row via fetchQrCodeRowByUuid()
 *     and checks ownership via the qrCodeIs*By() pure predicates below BEFORE
 *     any read/mutate/delete - there is no code path that trusts a prior
 *     check or a cached result.
 *   - A code that is NOT owned by the caller AND NOT public is reported as
 *     404 NOT_FOUND, never 403 - a 403 would confirm the UUID exists and
 *     belongs to someone else (existence disclosure / enumeration surface).
 *   - GET /qrcodes (the list) NEVER accepts an "isPublic passthrough" for
 *     other users' rows - the WHERE clause is unconditionally
 *     `userID = <caller>`, full stop; there is no filter that widens it.
 *   - /save is additionally guarded against CLAIM-HIJACKING: a code already
 *     owned by a DIFFERENT user cannot be "re-saved" by the caller (also
 *     404, not a 409/403, for the same non-disclosure reason above).
 *
 * Public-code policy (owner/design decision, see DEV_NOTES.md):
 *   - A newly-saved code defaults to isPublic=0 (private) unless the caller
 *     explicitly passes is_public=true - opt-in, not opt-out.
 *   - isPublic=1 grants READ access (GET /qrcodes/{uuid}, GET /download/{uuid})
 *     to ANY authenticated caller, not just the owner. It NEVER grants write
 *     access - PATCH/DELETE always require true ownership regardless of the
 *     isPublic flag (qrCodeIsWritableBy() ignores isPublic entirely).
 *
 * Soft-delete decision (owner/design decision, see DEV_NOTES.md/SECURITY.md):
 *   - DELETE sets `deletedAt` rather than removing the row, matching this
 *     codebase's existing convention for reversible state (tblUserSessions.
 *     revokedAt, tblEmailVerifications/tblPasswordResets.usedAt) - it
 *     preserves an audit trail and a future recovery/undo path without
 *     re-generating the QR image, at the (accepted) cost of the row/file
 *     living on disk until a later retention-cleanup job (not built this
 *     stage) reaps it. Every query in this file filters `deletedAt IS NULL`,
 *     so a soft-deleted code is invisible to list/get/patch/delete/download
 *     and does NOT count against the caller's saved_codes plan limit.
 *
 * Concurrency posture - the saved_codes plan-limit TOCTOU fix (SECURITY.md
 * F-023, HIGH, closed 2026-07-10):
 *   - `POST /save`'s plan-limit CHECK (countSavedQrCodesForUser() vs.
 *     limitFor('saved_codes', ...)) and the claim UPDATE that follows it are
 *     NOT independent statements any more for a genuinely new claim - they
 *     are serialised, per caller, by a MySQL SESSION advisory lock
 *     (acquireSaveLimitLock()/releaseSaveLimitLock() below). Without this,
 *     N truly-concurrent /save requests for N DIFFERENT unclaimed UUIDs from
 *     the SAME user could each pass the count check before any claim
 *     landed, blowing straight past the caller's plan cap - proven live
 *     against a scratch database: 5 parallel OS processes took a cap-2 user
 *     to 6 saved codes (see SECURITY.md F-023 for the full before/after
 *     evidence). The pre-existing `UPDATE ... WHERE (userID IS NULL OR
 *     userID = ?)` guard only ever stopped two requests racing for the SAME
 *     qrID - it does nothing when the qrIDs differ, which is exactly the
 *     gap this closes.
 *   - A transaction + `SELECT ... FOR UPDATE` would NOT have fixed this: the
 *     row being claimed is, at SELECT time, owned by NOBODY
 *     (`userID IS NULL`), so there is no row yet "belonging" to this user's
 *     future claim for row-level locking to lock against (a phantom-row
 *     problem - InnoDB can only lock rows that already match a WHERE
 *     predicate at lock time, and this user's claim doesn't match any row
 *     until AFTER the race is already lost).
 *   - `GET_LOCK()` returning 0 (timeout) or NULL (error) fails SAFE: the
 *     request is rejected with `429 SAVE_LOCK_BUSY` + a `Retry-After` header
 *     rather than proceeding unserialised, which would silently reopen the
 *     exact race this fix exists to close.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2a, #52/#24)
 * @see         fn_features.php      - limitFor('saved_codes', ...), resolveUserIdFromAuth()
 * @see         fn_api_download.php  - handleDownload() ownership hardening (this stage)
 * @see         fn_api_generate.php  - buildCustomizationFromRequest(), validateCustomization()
 * @see         fn_qr_generate.php   - saveQRToFile(), the original generation pipeline this
 *                                     file's regenerateQrOutputForPatch() mirrors for a PATCH
 * @see         SECURITY.md          - the IDOR audit recorded for this stage
 * @see         https://owasp.org/www-project-top-ten/2017/A5_2017-Broken_Access_Control OWASP IDOR guidance
 * @see         https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/**
 * @var string UUID v4 format regex - IDENTICAL pattern to fn_api_download.php's
 * inline check (kept in sync deliberately; see isValidQrUuid()).
 */
if (!defined('CUERCODE_QR_UUID_REGEX')) {
    define('CUERCODE_QR_UUID_REGEX', '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
}

/** @var int Maximum length of a saved code's caller-supplied label (matches tblQRCodes.qrLabel VARCHAR(150)). */
if (!defined('CUERCODE_QRCODES_MAX_LABEL_LEN')) {
    define('CUERCODE_QRCODES_MAX_LABEL_LEN', 150);
}

/** @var int Default page size for GET /qrcodes when ?per_page= is absent/invalid. */
if (!defined('CUERCODE_QRCODES_DEFAULT_PER_PAGE')) {
    define('CUERCODE_QRCODES_DEFAULT_PER_PAGE', 20);
}

/** @var int Hard cap on ?per_page= regardless of what the caller requests. */
if (!defined('CUERCODE_QRCODES_MAX_PER_PAGE')) {
    define('CUERCODE_QRCODES_MAX_PER_PAGE', 100);
}

/** @var int Maximum length of the ?type= filter (matches tblQRCodeTypes.typeName VARCHAR(100)). */
if (!defined('CUERCODE_QRCODES_MAX_TYPE_FILTER_LEN')) {
    define('CUERCODE_QRCODES_MAX_TYPE_FILTER_LEN', 100);
}

/**
 * @var string Prefix for the per-user MySQL advisory lock name used to
 * serialise the `saved_codes` plan-limit check + claim (SECURITY.md F-023 -
 * closes a TOCTOU race; see acquireSaveLimitLock()/releaseSaveLimitLock()
 * and handleSaveQRCode() below). The full lock name is this prefix
 * concatenated with the caller's userID (e.g. "cuercode_save:42") - well
 * under MySQL's 64-character GET_LOCK() name limit for any realistic
 * userID.
 */
if (!defined('CUERCODE_SAVE_LOCK_NAME_PREFIX')) {
    define('CUERCODE_SAVE_LOCK_NAME_PREFIX', 'cuercode_save:');
}

/**
 * @var int Seconds GET_LOCK() will wait for the per-user save-limit lock
 * before giving up (returning 0). Kept short - the critical section it
 * guards is a handful of fast, indexed queries, never anything slow.
 */
if (!defined('CUERCODE_SAVE_LOCK_TIMEOUT_SECONDS')) {
    define('CUERCODE_SAVE_LOCK_TIMEOUT_SECONDS', 5);
}

/**
 * @var int Retry-After header value (seconds) sent with the 429 response
 * when GET_LOCK() times out - a short backoff, since lock contention on
 * this critical section is expected to clear in well under a second.
 */
if (!defined('CUERCODE_SAVE_LOCK_RETRY_AFTER_SECONDS')) {
    define('CUERCODE_SAVE_LOCK_RETRY_AFTER_SECONDS', 2);
}

// ==============================================================================
// 🧮 Pure helpers (no DB, no I/O) - unit-testable in isolation
// ==============================================================================

// ------------------------------------------------------------------------
// 🆔 isValidQrUuid() - UUID v4 shape check
// ------------------------------------------------------------------------

/**
 * 🆔 Validate that a string is a well-formed UUID v4.
 *
 * @param string $uuid The candidate UUID string.
 *
 * @return bool True if it matches the UUID v4 shape.
 */
function isValidQrUuid(string $uuid): bool
{
    return preg_match(CUERCODE_QR_UUID_REGEX, $uuid) === 1;
}

// ------------------------------------------------------------------------
// 🔐 qrCodeIsReadableBy() / qrCodeIsWritableBy() / qrCodeIsDownloadableBy()
//    - the single source of truth for every ownership decision in this file
//    (and, via qrCodeIsDownloadableBy(), fn_api_download.php).
// ------------------------------------------------------------------------

/**
 * 🔐 Is a QR code record readable (GET /qrcodes/{uuid}) by the given caller?
 *
 * @param int|null $ownerUserId  The row's userID (null = never claimed/saved).
 * @param bool     $isPublic     The row's isPublic flag.
 * @param int|null $callerUserId The resolved caller identity (null = could not
 *                                be resolved from the auth context - treated
 *                                as "nobody", never as a wildcard match).
 *
 * @return bool True if readable.
 */
function qrCodeIsReadableBy(?int $ownerUserId, bool $isPublic, ?int $callerUserId): bool
{
    if ($isPublic) {
        return true;
    }

    return $ownerUserId !== null && $callerUserId !== null && $ownerUserId === $callerUserId;
}

/**
 * 🔐 Is a QR code record writable (PATCH/DELETE, and the "claim" branch of
 * /save) by the given caller? isPublic is DELIBERATELY irrelevant here - a
 * public code is readable by anyone but writable ONLY by its true owner.
 *
 * @param int|null $ownerUserId  The row's userID (null = unclaimed - nobody owns it yet).
 * @param int|null $callerUserId The resolved caller identity.
 *
 * @return bool True if writable.
 */
function qrCodeIsWritableBy(?int $ownerUserId, ?int $callerUserId): bool
{
    return $ownerUserId !== null && $callerUserId !== null && $ownerUserId === $callerUserId;
}

/**
 * 🔐 Is a QR code record downloadable (GET /download/{uuid}) by the given caller?
 *
 * An UNCLAIMED code (ownerUserId === null - never passed through POST /save)
 * has no owner whose privacy could be violated, so it remains downloadable by
 * any AUTHENTICATED caller - this preserves the pre-existing generate -> download
 * flow that predates this stage (see fn_api_download.php's docblock amendment).
 * Once a code IS claimed (ownerUserId !== null), the normal
 * owner-or-public rule applies.
 *
 * @param int|null $ownerUserId  The row's userID.
 * @param bool     $isPublic     The row's isPublic flag.
 * @param int|null $callerUserId The resolved caller identity.
 *
 * @return bool True if downloadable.
 */
function qrCodeIsDownloadableBy(?int $ownerUserId, bool $isPublic, ?int $callerUserId): bool
{
    if ($ownerUserId === null) {
        return true;
    }

    if ($isPublic) {
        return true;
    }

    return $callerUserId !== null && $callerUserId === $ownerUserId;
}

// ------------------------------------------------------------------------
// 🏷️ validateQrLabelInput() - Sanitise/validate the "label" request field
// ------------------------------------------------------------------------

/**
 * 🏷️ Validate a caller-supplied "label" field (POST /save, PATCH /qrcodes/{uuid}).
 *
 * An explicit empty string clears the label (treated identically to null).
 * A field that was never present in the request body at all (isProvided=false)
 * means "leave the existing value untouched" - distinguished from an explicit
 * null (which means "clear it").
 *
 * @param mixed $rawLabel   The raw value from the request body (any type).
 * @param bool  $isProvided Whether the "label" key was present in the request
 *                          body at all (array_key_exists(), not isset() - a
 *                          caller sending `"label": null` must still count as
 *                          "provided").
 *
 * @return array{valid: bool, value: string|null, provided: bool, error: string|null}
 */
function validateQrLabelInput(mixed $rawLabel, bool $isProvided): array
{
    if (!$isProvided) {
        return ['valid' => true, 'value' => null, 'provided' => false, 'error' => null];
    }

    if ($rawLabel === null) {
        return ['valid' => true, 'value' => null, 'provided' => true, 'error' => null];
    }

    if (!is_string($rawLabel)) {
        return [
            'valid'    => false,
            'value'    => null,
            'provided' => true,
            'error'    => 'Field "label" must be a string or null.',
        ];
    }

    $trimmed = trim($rawLabel);

    if ($trimmed === '') {
        // 🧹 An explicit empty string clears the label (same as null).
        return ['valid' => true, 'value' => null, 'provided' => true, 'error' => null];
    }

    if (mb_strlen($trimmed) > CUERCODE_QRCODES_MAX_LABEL_LEN) {
        return [
            'valid'    => false,
            'value'    => null,
            'provided' => true,
            'error'    => 'Field "label" is too long. Maximum ' . CUERCODE_QRCODES_MAX_LABEL_LEN . ' characters.',
        ];
    }

    return ['valid' => true, 'value' => $trimmed, 'provided' => true, 'error' => null];
}

// ------------------------------------------------------------------------
// 🔎 sanitizeQrTypeFilter() - Normalise the ?type= list filter
// ------------------------------------------------------------------------

/**
 * 🔎 Normalise the optional "?type=" query filter for GET /qrcodes.
 *
 * @param mixed $rawType The raw query parameter value (any type).
 *
 * @return string|null Lower-cased, trimmed, length-capped filter value, or
 *                      null when absent/blank/non-string (no filter applied).
 */
function sanitizeQrTypeFilter(mixed $rawType): ?string
{
    if (!is_string($rawType)) {
        return null;
    }

    $trimmed = strtolower(trim($rawType));

    if ($trimmed === '') {
        return null;
    }

    return mb_substr($trimmed, 0, CUERCODE_QRCODES_MAX_TYPE_FILTER_LEN);
}

// ------------------------------------------------------------------------
// 📄 clampPaginationParams() - Pure pagination-parameter clamp
// ------------------------------------------------------------------------

/**
 * 📄 Clamp raw page/per_page query values into safe, bounded integers.
 *
 * @param mixed $rawPage    The raw "?page=" value (any type).
 * @param mixed $rawPerPage The raw "?per_page=" value (any type).
 * @param int   $defaultPerPage Fallback per_page when absent/invalid/non-positive.
 * @param int   $maxPerPage     Hard ceiling on per_page.
 *
 * @return array{page: int, per_page: int, offset: int}
 */
function clampPaginationParams(
    mixed $rawPage,
    mixed $rawPerPage,
    int $defaultPerPage = CUERCODE_QRCODES_DEFAULT_PER_PAGE,
    int $maxPerPage = CUERCODE_QRCODES_MAX_PER_PAGE
): array {
    $page = (int) $rawPage;
    if ($page < 1) {
        $page = 1;
    }

    $perPage = (int) $rawPerPage;
    if ($perPage < 1) {
        $perPage = $defaultPerPage;
    }
    if ($perPage > $maxPerPage) {
        $perPage = $maxPerPage;
    }

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
    ];
}

// ==============================================================================
// 🗄️ DB-touching helpers
// ==============================================================================

// ------------------------------------------------------------------------
// 🔎 fetchQrCodeRowByUuid() - The ONE lookup every single-object route uses
// ------------------------------------------------------------------------

/**
 * 🔎 Fetch a tblQRCodes row (joined to its type name) by UUID.
 *
 * Every route in this file that operates on a single object
 * (GET/PATCH/DELETE /qrcodes/{uuid}, POST /save) calls this EXACT function -
 * there is no second, divergent lookup path anywhere in this file, so a
 * future change to the ownership/soft-delete filtering only has to happen
 * once, here.
 *
 * @param string $uuid The QR code's UUID v4 (already format-validated by the caller).
 *
 * @return array|null The row (qrID, qrUUID, qrTypeID, typeName, qrPayload,
 *                     qrOptions, qrOutputFormat, qrFilePath, qrFileSize,
 *                     userID, isPublic, qrLabel, downloadCount, createdAt,
 *                     updatedAt), or null if not found / soft-deleted / DB
 *                     unavailable.
 */
function fetchQrCodeRowByUuid(string $uuid): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT q.`qrID`, q.`qrUUID`, q.`qrTypeID`, t.`typeName`, q.`qrPayload`, '
            . 'q.`qrOptions`, q.`qrOutputFormat`, q.`qrFilePath`, q.`qrFileSize`, '
            . 'q.`userID`, q.`isPublic`, q.`qrLabel`, q.`downloadCount`, '
            . 'q.`createdAt`, q.`updatedAt` '
            . 'FROM `tblQRCodes` AS q '
            . 'LEFT JOIN `tblQRCodeTypes` AS t ON q.`qrTypeID` = t.`typeID` '
            . 'WHERE q.`qrUUID` = ? AND q.`deletedAt` IS NULL '
            . 'LIMIT 1',
            's',
            [$uuid]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Saved QR code lookup by UUID failed', $e->getMessage());
        }
        return null;
    }
}

// ------------------------------------------------------------------------
// 🔢 countSavedQrCodesForUser() - Live count backing the saved_codes limit
// ------------------------------------------------------------------------

/**
 * 🔢 Count a user's currently-saved (non-soft-deleted) QR codes.
 *
 * @param int $userId The tblUsers.userID to count for.
 *
 * @return int The live count (0 if the DB is unavailable or the query fails -
 *             fail-open on the COUNT itself; the actual save is still gated
 *             by limitFor() afterwards, so a transient count failure cannot
 *             grant an unbounded save - worst case it under-counts by one
 *             request, not indefinitely).
 */
function countSavedQrCodesForUser(int $userId): int
{
    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `savedCount` FROM `tblQRCodes` WHERE `userID` = ? AND `deletedAt` IS NULL',
            'i',
            [$userId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null && isset($row['savedCount'])) {
            return (int) $row['savedCount'];
        }

        return 0;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Saved QR code count failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return 0;
    }
}

// ------------------------------------------------------------------------
// 🔒 acquireSaveLimitLock() / releaseSaveLimitLock() - Per-user MySQL
//    advisory lock serialising the saved_codes count-check + claim
//    (SECURITY.md F-023 - closes the TOCTOU race documented in this file's
//    header docblock and in handleSaveQRCode() below).
// ------------------------------------------------------------------------

/**
 * 🔒 Acquire a per-user MySQL SESSION advisory lock guarding the
 * `saved_codes` plan-limit count-check + claim critical section.
 *
 * WHY an advisory lock and not a transaction + `SELECT ... FOR UPDATE`: the
 * code being claimed is, at SELECT time, owned by NOBODY (`userID IS
 * NULL`) - row-level locking can only lock rows that already match a WHERE
 * predicate, so there is no row yet "belonging" to this user's future claim
 * to lock against (a phantom-row problem). A per-user advisory lock instead
 * serialises the ENTIRE check+claim critical section for one user,
 * regardless of which qrID(s) are involved - exactly the property needed:
 * N concurrent /save calls for N DIFFERENT unclaimed UUIDs from the SAME
 * user must not all pass the count check before any claim lands.
 *
 * `GET_LOCK()`/`RELEASE_LOCK()` are SESSION- (connection-)scoped in MySQL.
 * `dbQuery()` -> `getDB()` (`_includes/db_connect.php`) returns the SAME
 * cached `mysqli` connection for the lifetime of one PHP request (a static
 * variable, not a fresh connection per call), so the lock acquired here,
 * the count-check, the claim UPDATE, and `releaseSaveLimitLock()` below all
 * run on that ONE connection - never split across two different sessions,
 * which would make the lock meaningless.
 *
 * Nested-safe: MySQL (5.7.5+) allows one session to hold multiple
 * DIFFERENT named locks simultaneously without releasing an existing one,
 * and re-acquiring the SAME name from the SAME session that already holds
 * it succeeds immediately (still released by a single RELEASE_LOCK() call -
 * not reference-counted). This codebase never calls
 * acquireSaveLimitLock() re-entrantly, but the property holds regardless.
 *
 * @param int $userId The tblUsers.userID to lock (one advisory lock per user).
 *
 * @return bool True if the lock was acquired within
 *              CUERCODE_SAVE_LOCK_TIMEOUT_SECONDS; false on timeout
 *              (another /save for this SAME user is already mid-flight) or
 *              on any DB error - FAIL SAFE: the caller must NOT proceed
 *              unserialised when this returns false.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html#function_get-lock
 */
function acquireSaveLimitLock(int $userId): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    $lockName = CUERCODE_SAVE_LOCK_NAME_PREFIX . $userId;

    try {
        $lockStmt = dbQuery(
            'SELECT GET_LOCK(?, ?) AS `lockResult`',
            'si',
            [$lockName, CUERCODE_SAVE_LOCK_TIMEOUT_SECONDS]
        );
        $lockRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Save-limit advisory lock acquisition failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return false;
    }

    // 📌 GET_LOCK() returns 1 (acquired), 0 (timeout), or NULL (error) -
    // ANYTHING other than a genuine 1 is treated as "not acquired."
    return $lockRow !== null && isset($lockRow['lockResult']) && (int) $lockRow['lockResult'] === 1;
}

/**
 * 🔓 Release the per-user advisory lock acquired by acquireSaveLimitLock().
 *
 * Best-effort by design: a failure to release is logged but never thrown,
 * and never leaves the lock stuck - the lock is connection-scoped, and
 * MySQL automatically releases ALL of a session's advisory locks the moment
 * that connection closes (i.e. at the end of this PHP request, whether via
 * a normal return OR `exit()` inside errorResponse()/successResponse()), so
 * a release failure here cannot leave the lock held indefinitely for
 * future requests.
 *
 * @param int $userId The SAME tblUsers.userID passed to acquireSaveLimitLock().
 *
 * @return void
 */
function releaseSaveLimitLock(int $userId): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    $lockName = CUERCODE_SAVE_LOCK_NAME_PREFIX . $userId;

    try {
        $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [$lockName]);
        $releaseStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Save-limit advisory lock release failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------------------------------
// 🎨 regenerateQrOutputForPatch() - Re-render an existing payload with new
//    customisation (PATCH /qrcodes/{uuid} when "customization" is supplied)
// ------------------------------------------------------------------------

/**
 * 🎨 Re-render a QR code's image from its EXISTING payload with new customisation.
 *
 * Mirrors generateQRCode()'s render/logo/frame steps (fn_qr_generate.php)
 * for a scenario that function does not support: updating the visual
 * presentation of an ALREADY-SAVED code in place (same UUID, same qrID) -
 * generateQRCode() always creates a brand-new row/UUID, which would break a
 * saved code's stable download_url. Kept as its own function (rather than
 * refactoring generateQRCode()) to avoid touching that already-audited,
 * heavily-exercised pipeline within this stage's scope.
 *
 * ⚠️ Scope note: unlike generateQRCode(), this does NOT apply the E5/#49
 * accessible/tactile/Avery PDF composition step (fn_qr_accessible_pdf.php) -
 * a `pdf_layout` other than 'standard' passed here still renders the
 * STANDARD chillerlan PDF. Regenerating an accessible/tactile/Avery PDF
 * layout in place is deferred; use POST /generate + POST /save for those
 * layouts today.
 *
 * @param string $payload       The QR code's existing, already-encoded payload string.
 * @param array  $customization Sanitised customisation (buildCustomizationFromRequest() shape).
 *
 * @return array{success: bool, output: string, mimeType: string, fileExtension: string, error: string|null}
 *
 * @see fn_qr_generate.php - generateQRCode() (the pipeline this mirrors)
 */
function regenerateQrOutputForPatch(string $payload, array $customization): array
{
    $result = [
        'success'       => false,
        'output'        => '',
        'mimeType'      => '',
        'fileExtension' => 'png',
        'error'         => null,
    ];

    if (isset($customization['format'])) {
        $format = strtolower(trim((string) $customization['format']));
    } else {
        $format = 'png';
    }

    try {
        if (!function_exists('buildQROptions')) {
            throw new \RuntimeException('Options builder not available. Ensure fn_qr_options.php is loaded.');
        }

        $customization['payload'] = $payload;

        // 📌 Logo overlays require high error correction for reliable scanning
        // (same rule generateQRCode() applies).
        if (!empty($customization['logo'])) {
            if (isset($customization['ecc'])) {
                $currentEcc = strtoupper(trim((string) $customization['ecc']));
            } else {
                $currentEcc = 'M';
            }
            if ($currentEcc !== 'H') {
                $customization['ecc'] = 'H';
            }
        }

        $qrOptions = buildQROptions($customization);
        $qrcode    = new \chillerlan\QRCode\QRCode($qrOptions);
        $qrOutput  = $qrcode->render($payload);
    } catch (\Throwable $e) {
        $result['error'] = 'Failed to regenerate QR code image: ' . $e->getMessage();
        if (function_exists('logError')) {
            logError('error', 'PATCH QR code regeneration failed', $e->getMessage());
        }
        return $result;
    }

    if (function_exists('mapOutputFormat')) {
        $formatMapping = mapOutputFormat($format);
    } else {
        $formatMapping = ['mimeType' => 'image/png', 'fileExtension' => 'png'];
    }

    // 📌 Post-process: logo overlay (mirrors generateQRCode() Step 5).
    if (!empty($customization['logo'])) {
        try {
            $logoPath = null;
            if (function_exists('getLogoPath')) {
                $logoPath = getLogoPath($customization['logo']);
            }

            if ($logoPath !== null) {
                $logoConfig = [
                    'size_pct'      => isset($customization['logo_size_pct']) ? (int) $customization['logo_size_pct'] : 20,
                    'padding'       => isset($customization['logo_padding']) ? (bool) $customization['logo_padding'] : true,
                    'padding_pct'   => isset($customization['logo_padding_pct']) ? (int) $customization['logo_padding_pct'] : 15,
                    'padding_shape' => $customization['logo_padding_shape'] ?? 'circle',
                    'output_format' => $format,
                ];

                if ($format === 'svg' && function_exists('overlayLogoOnSVG')) {
                    $qrOutput = overlayLogoOnSVG($qrOutput, $logoPath, $logoConfig);
                } elseif ($format !== 'pdf' && function_exists('overlayLogoOnRaster')) {
                    $qrOutput = overlayLogoOnRaster($qrOutput, $logoPath, $logoConfig);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Logo overlay failed during PATCH regeneration', $e->getMessage());
            }
        }
    }

    // 📌 Post-process: frame rendering (mirrors generateQRCode() Step 6).
    if (isset($customization['frame_style'])) {
        $frameStyle = strtolower(trim((string) $customization['frame_style']));
    } else {
        $frameStyle = 'none';
    }

    if ($frameStyle !== 'none' && $frameStyle !== '') {
        try {
            $frameConfig = [];
            if (function_exists('getFrameConfig')) {
                $frameConfig = getFrameConfig($customization);
            }

            if (isset($frameConfig['style'])) {
                $frameConfigStyle = $frameConfig['style'];
            } else {
                $frameConfigStyle = 'none';
            }

            if (!empty($frameConfig) && $frameConfigStyle !== 'none') {
                if ($format === 'svg' && function_exists('addFrameToSVG')) {
                    $qrOutput = addFrameToSVG($qrOutput, $frameConfig);
                } elseif ($format !== 'pdf' && function_exists('addFrameToRaster')) {
                    $qrOutput = addFrameToRaster($qrOutput, $frameConfig, $format);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Frame rendering failed during PATCH regeneration', $e->getMessage());
            }
        }
    }

    $result['success']       = true;
    $result['output']        = $qrOutput;
    $result['mimeType']      = $formatMapping['mimeType'];
    $result['fileExtension'] = $formatMapping['fileExtension'];

    return $result;
}

// ==============================================================================
// 💾 handleSaveQRCode() - POST /api/v1/save
// ==============================================================================

/**
 * 💾 Handle POST /api/v1/save
 *
 * Claims an existing (previously-generated) QR code into the authenticated
 * caller's account, or - if the caller already owns it - updates its
 * label/visibility. Enforces the caller's plan `saved_codes` limit (-1 =
 * unlimited) ONLY when this is a genuinely NEW claim (an already-owned code
 * being re-saved does not consume a fresh slot).
 *
 * Request body: {"uuid": "...", "label": "..." (optional), "is_public": bool (optional)}
 *
 * @param array $requestBody The parsed JSON request body.
 * @param array $auth        The router's authentication context (fn_api_auth.php).
 *
 * @return void (sends a JSON/XML response and exits)
 *
 * @see resolveUserIdFromAuth() (fn_features.php), limitFor() (fn_features.php)
 */
function handleSaveQRCode(array $requestBody, array $auth): void
{
    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return; // @codeCoverageIgnore - errorResponse exits
    }

    if (!isset($requestBody['uuid']) || !is_string($requestBody['uuid']) || trim($requestBody['uuid']) === '') {
        errorResponse('Missing required field: uuid. Provide the UUID returned by /generate.', 400, 'MISSING_FIELD');
        return;
    }

    $uuid = trim($requestBody['uuid']);

    if (!isValidQrUuid($uuid)) {
        errorResponse('Invalid QR code identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $labelProvided   = array_key_exists('label', $requestBody);
    $labelValidation = validateQrLabelInput($requestBody['label'] ?? null, $labelProvided);

    if (!$labelValidation['valid']) {
        errorResponse((string) $labelValidation['error'], 400, 'INVALID_LABEL');
        return;
    }

    $isPublicProvided = array_key_exists('is_public', $requestBody);

    if (!function_exists('dbQuery')) {
        errorResponse('The saved-codes service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchQrCodeRowByUuid($uuid);

    if ($row === null) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $existingOwnerId = $row['userID'] !== null ? (int) $row['userID'] : null;

    // 🔐 IDOR guard: a code already claimed by ANOTHER user is reported
    // identically to "not found" - prevents claim-hijacking of someone else's
    // saved code and avoids confirming the UUID belongs to somebody else.
    if ($existingOwnerId !== null && $existingOwnerId !== $currentUserId) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $isNewClaim = ($existingOwnerId === null);

    // 📌 Resolve the effective label/is_public to persist - an omitted field
    // preserves the row's EXISTING value (so calling /save a second time on an
    // already-owned code without repeating every field is a harmless no-op
    // for that field). Pure computation - deliberately resolved BEFORE the
    // locked critical section below so nothing but real DB work happens
    // while the per-user advisory lock is held.
    if ($labelValidation['provided']) {
        $effectiveLabel = $labelValidation['value'];
    } else {
        $effectiveLabel = $row['qrLabel'];
    }

    if ($isPublicProvided) {
        $effectiveIsPublic = ((bool) $requestBody['is_public']) ? 1 : 0;
    } else {
        // 🔐 Public-code policy: a BRAND NEW claim defaults to private (0)
        // unless the caller explicitly opts in; an already-owned code being
        // re-saved keeps its current visibility when is_public is omitted.
        $effectiveIsPublic = $isNewClaim ? 0 : (int) $row['isPublic'];
    }

    // ==================================================================
    // 🔒 F-023 FIX: atomic saved_codes count-check + claim critical section
    // ==================================================================
    // TOCTOU (High, SECURITY.md F-023): the saved_codes plan-limit COUNT
    // check and the claim UPDATE used to be two independent statements with
    // no serialisation between them. N TRULY-CONCURRENT /save requests for N
    // DIFFERENT unclaimed UUIDs from the SAME user could each pass the count
    // check before any claim landed, blowing straight past the plan cap
    // (proven live: 5 parallel OS processes took a cap-2 user to 6 saved
    // codes - see SECURITY.md F-023 for the full before/after evidence).
    // The `UPDATE ... WHERE (userID IS NULL OR userID = ?)` guard a few
    // lines below only ever stopped two requests racing for the SAME qrID -
    // it does nothing when the qrIDs differ, which is exactly the gap this
    // closes.
    //
    // Fixed with acquireSaveLimitLock()/releaseSaveLimitLock() (defined
    // above) - a per-user MySQL SESSION advisory lock - rather than a
    // transaction + `SELECT ... FOR UPDATE`: the code being claimed is, at
    // SELECT time, owned by NOBODY (`userID IS NULL`), so there is no row
    // yet "belonging" to this user's future claim for row-level locking to
    // lock against (a phantom-row problem). The advisory lock instead
    // serialises the WHOLE check+claim section per user, which is the
    // property actually needed. Only a genuinely NEW claim needs this lock -
    // an already-owned code being re-saved has no plan-limit check to race
    // ($isNewClaim above), so $lockAcquired stays false and that path runs
    // exactly as it did before this fix.
    //
    // ⚠️ errorResponse()/successResponse() call exit() internally, and
    // PHP's `finally` block does NOT run past an exit() call (only a normal
    // return or a thrown exception triggers `finally` - verified: exit()
    // bypasses it entirely). So NEITHER function may be called from inside
    // the try{} below - the critical section only ever populates $outcome,
    // the lock is released in `finally` (guaranteed - normal completion,
    // any caught exception, AND the belt-and-braces outer catch all funnel
    // through it), and errorResponse()/successResponse() are called
    // AFTERWARDS, once the lock is provably already free.
    // ==================================================================

    $lockAcquired = false;

    if ($isNewClaim) {
        $lockAcquired = acquireSaveLimitLock($currentUserId);

        if (!$lockAcquired) {
            // ⏱️ GET_LOCK() timed out (another /save for this SAME user is
            // already mid-flight) or errored. FAIL SAFE - do NOT proceed
            // unserialised; that would silently reopen the exact race this
            // fix exists to close.
            if (!headers_sent()) {
                header('Retry-After: ' . CUERCODE_SAVE_LOCK_RETRY_AFTER_SECONDS);
            }

            errorResponse(
                'The saved-codes service is busy processing another request for your account. '
                . 'Please try again shortly.',
                429,
                'SAVE_LOCK_BUSY'
            );
            return; // @codeCoverageIgnore - errorResponse() exits
        }
    }

    // 📌 $outcome is populated ONLY inside the try/finally below and
    // consumed ONLY after it - see the "why" comment block above.
    $outcome = null;

    try {
        if ($isNewClaim) {
            $savedCount = countSavedQrCodesForUser($currentUserId);
            $limit      = function_exists('limitFor') ? limitFor('saved_codes', $currentUserId) : 0;

            // 🔢 -1 = unlimited (see fn_features.php's limitFor() convention).
            if ($limit !== -1 && $savedCount >= $limit) {
                $outcome = ['type' => 'limit_reached', 'limit' => $limit];
            }
        }

        if ($outcome === null) {
            try {
                // 🔒 Atomic claim: the WHERE guard re-checks ownership at
                // UPDATE time (not merely at the SELECT above) so two
                // concurrent /save calls for the SAME anonymous code cannot
                // both "win" the claim - defence in depth alongside the
                // per-user advisory lock above, which closes the
                // DIFFERENT-qrID race the WHERE guard alone cannot.
                $updateStmt = dbQuery(
                    'UPDATE `tblQRCodes` SET `userID` = ?, `qrLabel` = ?, `isPublic` = ?, `updatedAt` = NOW() '
                    . 'WHERE `qrID` = ? AND (`userID` IS NULL OR `userID` = ?) AND `deletedAt` IS NULL',
                    'isiii',
                    [$currentUserId, $effectiveLabel, $effectiveIsPublic, (int) $row['qrID'], $currentUserId]
                );
                $updateStmt->close();
            } catch (\Throwable $e) {
                if (function_exists('logError')) {
                    logError('error', 'Save QR code failed', 'uuid: ' . $uuid . ' | ' . $e->getMessage());
                }
                $outcome = ['type' => 'save_failed'];
            }
        }

        if ($outcome === null) {
            // ✅ Re-fetch to confirm the caller now genuinely owns the row -
            // sidesteps mysqli affected_rows' "0 rows" ambiguity
            // (matched-but-unchanged vs. matched-nothing) with a definitive
            // read instead.
            $confirmedRow = fetchQrCodeRowByUuid($uuid);

            if ($confirmedRow === null || $confirmedRow['userID'] === null || (int) $confirmedRow['userID'] !== $currentUserId) {
                $outcome = ['type' => 'conflict'];
            } else {
                $outcome = ['type' => 'success', 'row' => $confirmedRow];
            }
        }
    } catch (\Throwable $e) {
        // 🛡️ Belt-and-braces: every DB call above already has its own
        // catch, so this should never trigger in practice - but if some
        // OTHER unexpected throwable escaped, it still cannot leave the
        // lock held (the finally below still runs for a caught-here
        // exception; only exit() would skip it, and nothing in this try
        // calls exit()).
        if (function_exists('logError')) {
            logError('error', 'Unexpected error in save critical section', 'uuid: ' . $uuid . ' | ' . $e->getMessage());
        }
        $outcome = ['type' => 'save_failed'];
    } finally {
        if ($lockAcquired) {
            releaseSaveLimitLock($currentUserId);
        }
    }

    // ==================================================================
    // 📤 React to the outcome - the per-user lock (if any was acquired) is
    // now guaranteed released, so it is safe to call errorResponse()/
    // successResponse() (both call exit()) from here on.
    // ==================================================================

    if ($outcome['type'] === 'limit_reached') {
        $extraData = ['plan_limit' => $outcome['limit']];
        if (function_exists('resolveUpgradeUrl')) {
            $extraData['upgrade_url'] = resolveUpgradeUrl();
        }

        errorResponse(
            'You have reached your plan\'s saved-codes limit (' . $outcome['limit'] . '). '
            . 'Upgrade your plan to save more codes.',
            402,
            'SAVED_CODES_LIMIT_REACHED',
            $extraData
        );
        return; // @codeCoverageIgnore - errorResponse() exits
    }

    if ($outcome['type'] === 'save_failed') {
        errorResponse('This QR code could not be saved. Please try again.', 500, 'SAVE_FAILED');
        return; // @codeCoverageIgnore - errorResponse() exits
    }

    if ($outcome['type'] === 'conflict') {
        errorResponse(
            'This QR code could not be saved (it may have just been claimed by another request).',
            409,
            'SAVE_CONFLICT'
        );
        return; // @codeCoverageIgnore - errorResponse() exits
    }

    // 🎉 Only 'success' remains at this point.
    $confirmedRow = $outcome['row'];

    if (function_exists('logActivity')) {
        logActivity(
            'qr_saved',
            'Saved QR code to account: ' . $uuid,
            'qr_code',
            (int) $confirmedRow['qrID'],
            ['uuid' => $uuid, 'isNewClaim' => $isNewClaim]
        );
    }

    if ($isNewClaim) {
        $message  = 'QR code saved to your account.';
        $httpCode = 201;
    } else {
        $message  = 'QR code updated.';
        $httpCode = 200;
    }

    successResponse(
        [
            'uuid'      => $uuid,
            'label'     => $confirmedRow['qrLabel'],
            'is_public' => (bool) $confirmedRow['isPublic'],
        ],
        $message,
        $httpCode
    );
}

// ==============================================================================
// 📋 handleListQRCodes() - GET /api/v1/qrcodes
// ==============================================================================

/**
 * 📋 Handle GET /api/v1/qrcodes
 *
 * Lists ONLY the caller's own saved codes - `WHERE userID = ?` is
 * unconditional; there is no code path that widens this to another user's
 * rows or to public codes belonging to someone else. Supports pagination
 * (?page=, ?per_page=, capped) and an optional ?type= filter.
 *
 * @param array $auth The router's authentication context.
 *
 * @return void (sends a JSON/XML response and exits)
 */
function handleListQRCodes(array $auth): void
{
    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The saved-codes service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    if (isset($_GET['page'])) {
        $rawPage = $_GET['page'];
    } else {
        $rawPage = 1;
    }
    if (isset($_GET['per_page'])) {
        $rawPerPage = $_GET['per_page'];
    } else {
        $rawPerPage = CUERCODE_QRCODES_DEFAULT_PER_PAGE;
    }

    $pagination = clampPaginationParams($rawPage, $rawPerPage);

    if (isset($_GET['type'])) {
        $typeFilter = sanitizeQrTypeFilter($_GET['type']);
    } else {
        $typeFilter = null;
    }

    try {
        if ($typeFilter !== null) {
            $countStmt = dbQuery(
                'SELECT COUNT(*) AS `total` FROM `tblQRCodes` q '
                . 'LEFT JOIN `tblQRCodeTypes` t ON q.`qrTypeID` = t.`typeID` '
                . 'WHERE q.`userID` = ? AND q.`deletedAt` IS NULL AND LOWER(t.`typeName`) = ?',
                'is',
                [$currentUserId, $typeFilter]
            );
        } else {
            $countStmt = dbQuery(
                'SELECT COUNT(*) AS `total` FROM `tblQRCodes` q WHERE q.`userID` = ? AND q.`deletedAt` IS NULL',
                'i',
                [$currentUserId]
            );
        }

        $totalRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        if ($totalRow !== null && isset($totalRow['total'])) {
            $total = (int) $totalRow['total'];
        } else {
            $total = 0;
        }

        if ($typeFilter !== null) {
            $listStmt = dbQuery(
                'SELECT q.`qrUUID`, t.`typeName`, q.`qrLabel`, q.`qrOutputFormat`, q.`isPublic`, '
                . 'q.`downloadCount`, q.`createdAt`, q.`updatedAt` '
                . 'FROM `tblQRCodes` q '
                . 'LEFT JOIN `tblQRCodeTypes` t ON q.`qrTypeID` = t.`typeID` '
                . 'WHERE q.`userID` = ? AND q.`deletedAt` IS NULL AND LOWER(t.`typeName`) = ? '
                . 'ORDER BY q.`createdAt` DESC '
                . 'LIMIT ? OFFSET ?',
                'isii',
                [$currentUserId, $typeFilter, $pagination['per_page'], $pagination['offset']]
            );
        } else {
            $listStmt = dbQuery(
                'SELECT q.`qrUUID`, t.`typeName`, q.`qrLabel`, q.`qrOutputFormat`, q.`isPublic`, '
                . 'q.`downloadCount`, q.`createdAt`, q.`updatedAt` '
                . 'FROM `tblQRCodes` q '
                . 'LEFT JOIN `tblQRCodeTypes` t ON q.`qrTypeID` = t.`typeID` '
                . 'WHERE q.`userID` = ? AND q.`deletedAt` IS NULL '
                . 'ORDER BY q.`createdAt` DESC '
                . 'LIMIT ? OFFSET ?',
                'iii',
                [$currentUserId, $pagination['per_page'], $pagination['offset']]
            );
        }

        $listResult = $listStmt->get_result();
        $rows       = [];
        while ($row = $listResult->fetch_assoc()) {
            $rows[] = $row;
        }
        $listStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'List saved QR codes failed', 'userID: ' . $currentUserId . ' | ' . $e->getMessage());
        }
        errorResponse('An error occurred while listing your saved QR codes.', 500, 'LIST_FAILED');
        return;
    }

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'uuid'           => (string) $row['qrUUID'],
            'type'           => $row['typeName'] !== null ? (string) $row['typeName'] : null,
            'label'          => $row['qrLabel'],
            'format'         => (string) $row['qrOutputFormat'],
            'is_public'      => (bool) $row['isPublic'],
            'download_count' => (int) $row['downloadCount'],
            'download_url'   => '/api/v1/download/' . $row['qrUUID'],
            'created_at'     => (string) $row['createdAt'],
            'updated_at'     => (string) $row['updatedAt'],
        ];
    }

    if ($pagination['per_page'] > 0) {
        $totalPages = (int) ceil($total / $pagination['per_page']);
    } else {
        $totalPages = 0;
    }

    successResponse(
        [
            'items'      => $items,
            'pagination' => [
                'page'        => $pagination['page'],
                'per_page'    => $pagination['per_page'],
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ],
        'Saved QR codes retrieved successfully.'
    );
}

// ==============================================================================
// 🔎 handleGetQRCode() - GET /api/v1/qrcodes/{uuid}
// ==============================================================================

/**
 * 🔎 Handle GET /api/v1/qrcodes/{uuid}
 *
 * Allowed only if the caller owns the code OR it is public - otherwise 404
 * (never 403, to avoid disclosing that a private UUID belonging to someone
 * else exists).
 *
 * @param string $uuid The QR code's UUID v4.
 * @param array  $auth The router's authentication context.
 *
 * @return void (sends a JSON/XML response and exits)
 */
function handleGetQRCode(string $uuid, array $auth): void
{
    if (!isValidQrUuid($uuid)) {
        errorResponse('Invalid QR code identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if (!function_exists('dbQuery')) {
        errorResponse('The saved-codes service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchQrCodeRowByUuid($uuid);

    if ($row === null) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $ownerUserId = $row['userID'] !== null ? (int) $row['userID'] : null;
    $isPublic    = (bool) $row['isPublic'];

    // 🔐 IDOR guard: re-checked HERE, on THIS row, right before the read -
    // not inferred from any earlier request/cache.
    if (!qrCodeIsReadableBy($ownerUserId, $isPublic, $currentUserId)) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    successResponse(
        [
            'uuid'           => (string) $row['qrUUID'],
            'type'           => $row['typeName'] !== null ? (string) $row['typeName'] : null,
            'label'          => $row['qrLabel'],
            'format'         => (string) $row['qrOutputFormat'],
            'is_public'      => $isPublic,
            'download_count' => (int) $row['downloadCount'],
            'download_url'   => '/api/v1/download/' . $row['qrUUID'],
            'created_at'     => (string) $row['createdAt'],
            'updated_at'     => (string) $row['updatedAt'],
        ],
        'QR code retrieved successfully.'
    );
}

// ==============================================================================
// ✏️ handlePatchQRCode() - PATCH /api/v1/qrcodes/{uuid}
// ==============================================================================

/**
 * ✏️ Handle PATCH /api/v1/qrcodes/{uuid}
 *
 * Ownership is checked FIRST, before any other field of the request body is
 * even read - a non-owned code (including a public one, since isPublic never
 * grants write access) is reported as 404. Updatable fields: "label",
 * "is_public", and "customization" (visual re-render only - content/
 * destination changes are out of this stage's scope).
 *
 * @param string $uuid        The QR code's UUID v4.
 * @param array  $requestBody The parsed JSON request body.
 * @param array  $auth        The router's authentication context.
 *
 * @return void (sends a JSON/XML response and exits)
 */
function handlePatchQRCode(string $uuid, array $requestBody, array $auth): void
{
    if (!isValidQrUuid($uuid)) {
        errorResponse('Invalid QR code identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The saved-codes service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchQrCodeRowByUuid($uuid);

    if ($row === null) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $ownerUserId = $row['userID'] !== null ? (int) $row['userID'] : null;

    // 🔐 IDOR guard FIRST - before validating/reading anything else in the
    // request body. Not owned -> 404 (never 403 - see file header).
    if (!qrCodeIsWritableBy($ownerUserId, $currentUserId)) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $labelProvided         = array_key_exists('label', $requestBody);
    $isPublicProvided      = array_key_exists('is_public', $requestBody);
    $customizationProvided = isset($requestBody['customization']) && is_array($requestBody['customization']);

    if (!$labelProvided && !$isPublicProvided && !$customizationProvided) {
        errorResponse(
            'No updatable fields were supplied. Provide "label", "is_public", and/or "customization".',
            400,
            'NO_FIELDS'
        );
        return;
    }

    $labelValidation = validateQrLabelInput($requestBody['label'] ?? null, $labelProvided);

    if (!$labelValidation['valid']) {
        errorResponse((string) $labelValidation['error'], 400, 'INVALID_LABEL');
        return;
    }

    // ------------------------------------------------------------------
    // 🎨 Regenerate the image ONLY when a customisation payload is present.
    // ------------------------------------------------------------------
    $newQrOptionsJson = null;
    $newFormat        = null;
    $newFilePath      = null;
    $newFileSize      = null;

    if ($customizationProvided) {
        if (function_exists('loadQRGenerationFunctions')) {
            loadQRGenerationFunctions();
        }

        if (!function_exists('buildCustomizationFromRequest') || !function_exists('validateCustomization')) {
            errorResponse('The QR customisation service is unavailable. Please try again later.', 503, 'SERVICE_UNAVAILABLE');
            return;
        }

        $storedOptions = json_decode((string) $row['qrOptions'], true);
        if (!is_array($storedOptions)) {
            $storedOptions = [];
        }

        // 📌 Merge semantics: fields OMITTED from this PATCH's customisation
        // object inherit the STORED value; only explicitly-supplied fields
        // change. Raw patch values take precedence over the stored ones.
        $mergedRaw     = array_merge($storedOptions, $requestBody['customization']);
        $customization = buildCustomizationFromRequest($mergedRaw);

        if (function_exists('applyAccessiblePreset')) {
            $customization = applyAccessiblePreset($customization);
        }

        $accessibleMeta = null;
        if (isset($customization['_accessible_meta']) && is_array($customization['_accessible_meta'])) {
            $accessibleMeta = $customization['_accessible_meta'];
            unset($customization['_accessible_meta']);

            if (isset($accessibleMeta['contrast_level']) && $accessibleMeta['contrast_level'] === 'fail') {
                errorResponse(
                    'Accessible preset rejected: the foreground/background contrast ratio ('
                        . $accessibleMeta['contrast_ratio']
                        . ':1) is below the WCAG minimum of 3:1 required for the accessible preset. '
                        . 'Choose a higher-contrast foreground/background colour pair.',
                    422,
                    'ACCESSIBLE_CONTRAST_TOO_LOW'
                );
                return;
            }
        }

        $validationError = validateCustomization($customization);

        if ($validationError !== null) {
            errorResponse($validationError, 400, 'INVALID_CUSTOMIZATION');
            return;
        }

        $renderResult = regenerateQrOutputForPatch((string) $row['qrPayload'], $customization);

        if (!$renderResult['success']) {
            $regenError = $renderResult['error'] ?? 'Unknown error.';
            errorResponse('QR code regeneration failed: ' . $regenError, 422, 'REGENERATION_FAILED');
            return;
        }

        $savedPath = null;
        if (function_exists('saveQRToFile')) {
            $savedPath = saveQRToFile($renderResult['output'], (string) $row['qrUUID'], $renderResult['fileExtension']);
        }

        if ($savedPath === null) {
            errorResponse('The regenerated QR code image could not be saved. Please try again.', 500, 'FILE_SAVE_FAILED');
            return;
        }

        // 🧹 Best-effort cleanup of a stale file at the OLD path when the
        // output format (and therefore file extension) changed.
        $oldPath = $row['qrFilePath'];
        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $savedPath && is_file($oldPath)) {
            @unlink($oldPath);
        }

        $newQrOptionsJson = json_encode($customization, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newFormat        = $renderResult['fileExtension'];
        $newFilePath      = $savedPath;
        $newFileSize      = strlen($renderResult['output']);
    }

    // ------------------------------------------------------------------
    // 💾 Persist the update - the WHERE clause re-guards ownership (defence
    // in depth even though it was already checked above).
    // ------------------------------------------------------------------
    $setClauses = [];
    $bindTypes  = '';
    $bindValues = [];

    if ($labelProvided) {
        $setClauses[] = '`qrLabel` = ?';
        $bindTypes   .= 's';
        $bindValues[] = $labelValidation['value'];
    }

    if ($isPublicProvided) {
        $setClauses[] = '`isPublic` = ?';
        $bindTypes   .= 'i';
        $bindValues[] = ((bool) $requestBody['is_public']) ? 1 : 0;
    }

    if ($customizationProvided) {
        $setClauses[] = '`qrOptions` = ?';
        $bindTypes   .= 's';
        $bindValues[] = $newQrOptionsJson;

        $setClauses[] = '`qrOutputFormat` = ?';
        $bindTypes   .= 's';
        $bindValues[] = $newFormat;

        $setClauses[] = '`qrFilePath` = ?';
        $bindTypes   .= 's';
        $bindValues[] = $newFilePath;

        $setClauses[] = '`qrFileSize` = ?';
        $bindTypes   .= 'i';
        $bindValues[] = $newFileSize;
    }

    $setClauses[] = '`updatedAt` = NOW()';

    $bindTypes   .= 'ii';
    $bindValues[] = (int) $row['qrID'];
    $bindValues[] = $currentUserId;

    try {
        $updateStmt = dbQuery(
            'UPDATE `tblQRCodes` SET ' . implode(', ', $setClauses) . ' WHERE `qrID` = ? AND `userID` = ? AND `deletedAt` IS NULL',
            $bindTypes,
            $bindValues
        );
        $updateStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Update saved QR code failed', 'uuid: ' . $uuid . ' | ' . $e->getMessage());
        }
        errorResponse('The QR code could not be updated. Please try again.', 500, 'UPDATE_FAILED');
        return;
    }

    if (function_exists('logActivity')) {
        logActivity(
            'qr_updated',
            'Updated saved QR code: ' . $uuid,
            'qr_code',
            (int) $row['qrID'],
            [
                'uuid'                 => $uuid,
                'labelChanged'         => $labelProvided,
                'visibilityChanged'    => $isPublicProvided,
                'customizationChanged' => $customizationProvided,
            ]
        );
    }

    $refreshed = fetchQrCodeRowByUuid($uuid);

    successResponse(
        [
            'uuid'       => $uuid,
            'label'      => $refreshed['qrLabel'] ?? null,
            'is_public'  => (bool) ($refreshed['isPublic'] ?? 0),
            'format'     => $refreshed['qrOutputFormat'] ?? null,
            'updated_at' => $refreshed['updatedAt'] ?? null,
        ],
        'QR code updated successfully.'
    );
}

// ==============================================================================
// 🗑️ handleDeleteQRCode() - DELETE /api/v1/qrcodes/{uuid}
// ==============================================================================

/**
 * 🗑️ Handle DELETE /api/v1/qrcodes/{uuid}
 *
 * Soft-deletes a code the caller OWNS (sets `deletedAt`; see the file header
 * for the soft-vs-hard-delete rationale). Ownership is checked before any
 * mutation; not owned -> 404.
 *
 * @param string $uuid The QR code's UUID v4.
 * @param array  $auth The router's authentication context.
 *
 * @return void (sends a JSON/XML response and exits)
 */
function handleDeleteQRCode(string $uuid, array $auth): void
{
    if (!isValidQrUuid($uuid)) {
        errorResponse('Invalid QR code identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The saved-codes service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchQrCodeRowByUuid($uuid);

    if ($row === null) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $ownerUserId = $row['userID'] !== null ? (int) $row['userID'] : null;

    // 🔐 IDOR guard - not owned -> 404 (never 403).
    if (!qrCodeIsWritableBy($ownerUserId, $currentUserId)) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    try {
        $deleteStmt = dbQuery(
            'UPDATE `tblQRCodes` SET `deletedAt` = NOW() WHERE `qrID` = ? AND `userID` = ? AND `deletedAt` IS NULL',
            'ii',
            [(int) $row['qrID'], $currentUserId]
        );
        $deleteStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Delete saved QR code failed', 'uuid: ' . $uuid . ' | ' . $e->getMessage());
        }
        errorResponse('The QR code could not be deleted. Please try again.', 500, 'DELETE_FAILED');
        return;
    }

    if (function_exists('logActivity')) {
        logActivity('qr_deleted', 'Deleted saved QR code: ' . $uuid, 'qr_code', (int) $row['qrID'], ['uuid' => $uuid]);
    }

    successResponse([], 'QR code deleted successfully.');
}

// ==============================================================================
// 🔀 handleQrCodeItem() - Router-facing dispatcher for GET/PATCH/DELETE /qrcodes/{uuid}
// ==============================================================================

/**
 * 🔀 Dispatch a "/qrcodes/{uuid}" request to the method-specific handler.
 *
 * The router (public_html/api/v1/index.php) matches a route by PATTERN, not
 * by pattern+method - GET/PATCH/DELETE on "qrcodes/{uuid}" therefore share
 * ONE route table entry and ONE handler. This function reads
 * $_SERVER['REQUEST_METHOD'] (already validated against the route's allowed
 * methods list by the router before dispatch) and calls the appropriate
 * method-specific function above.
 *
 * @param string $uuid        The QR code's UUID v4 (from the route parameter).
 * @param array  $requestBody The parsed JSON request body (only meaningful for PATCH).
 * @param array  $auth        The router's authentication context.
 *
 * @return void (delegates to a handler that sends the response and exits)
 */
function handleQrCodeItem(string $uuid, array $requestBody, array $auth): void
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
    } else {
        $method = 'GET';
    }

    switch ($method) {
        case 'PATCH':
            handlePatchQRCode($uuid, $requestBody, $auth);
            return;

        case 'DELETE':
            handleDeleteQRCode($uuid, $auth);
            return;

        case 'GET':
        default:
            handleGetQRCode($uuid, $auth);
            return;
    }
}
