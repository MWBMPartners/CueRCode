<?php
/**
 * ==============================================================================
 * 🔗 CueRCode - Dynamic Redirect: Owner-Facing Destination API (Epic E1a, #27)
 * ==============================================================================
 *
 * Path: _functions/fn_api_redirects.php
 *
 * Stage **S1** of the dynamic-redirect / dual-function QR design
 * (`.dev-team/dynamic_dualfn_design.md`) - "still dark". This file adds:
 *   - `createDynamicRedirectForOwner()` - the create-dynamic-with-quota
 *     helper (F-023 advisory-lock pattern, `limitFor('dynamic_codes')`,
 *     `featureEnabled('dynamic_qr')`). ⚠️ Deliberately **NOT wired to any
 *     live route** this stage - see its own docblock for why.
 *   - `GET`/`PATCH /api/v1/qrcodes/{uuid}/destination` - own-scoped, the
 *     404-not-403 IDOR convention, an audit-trail row on every destination
 *     change. These two routes ARE registered in the router
 *     (public_html/api/v1/index.php) and ARE reachable over HTTP - "still
 *     dark" refers to the wider feature (no public `/r/` redirect endpoint
 *     exists for a `destinationURL` to ever actually be used by), not to
 *     this destination-management API itself, which is real, live, and
 *     exercised by an owner managing a `tblQRRedirects` row created via
 *     `createDynamicRedirectForOwner()` in alpha testing / by a future
 *     Stage-S2 caller.
 *
 * IDOR posture (mirrors fn_api_qrcodes.php's file-header docblock exactly):
 *   - The caller's userID is resolved server-side ONLY
 *     (`resolveUserIdFromAuth()`) - never from a client-supplied field.
 *   - Every route re-fetches the row (`fetchRedirectRowByUuid()`) and checks
 *     ownership via `redirectIsWritableBy()` (fn_redirects.php) BEFORE any
 *     read/mutate - no code path trusts a prior check or a cached result.
 *   - A redirect NOT owned by the caller is reported as `404 NOT_FOUND`,
 *     never `403` - a 403 would confirm the UUID exists and belongs to
 *     someone else (the same existence-disclosure rationale as the
 *     saved-codes API).
 *   - A row suspended for abuse (`disabledReason='abuse'`) rejects EVERY
 *     PATCH with `409 REDIRECT_SUSPENDED` - even from the true owner - so an
 *     owner cannot edit around a takedown; only an admin (not built this
 *     stage) may re-enable it.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 4 (Epic E1a Stage S1, #27)
 * @see         _functions/fn_redirects.php    - validateRedirectDestination(), redirectIsWritableBy(), evaluateDynamicCodeQuota()
 * @see         _functions/fn_api_qrcodes.php  - the sibling saved-codes API this file's IDOR posture + F-023 lock pattern mirrors
 * @see         _functions/fn_features.php     - featureEnabled(), limitFor(), resolveUserIdFromAuth()
 * @see         .dev-team/dynamic_dualfn_design.md §1.2, §1.4
 * @see         SECURITY.md                    - F-023 (the saved_codes TOCTOU fix this file's lock mirrors)
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
 * @var string Prefix for the per-user MySQL advisory lock name used to
 * serialise the `dynamic_codes` plan-limit check + create critical section
 * (mirrors fn_api_qrcodes.php's CUERCODE_SAVE_LOCK_NAME_PREFIX - see
 * SECURITY.md F-023, the TOCTOU race this exact pattern closes). Full lock
 * name is this prefix concatenated with the caller's userID.
 */
if (!defined('CUERCODE_DYNAMIC_LOCK_NAME_PREFIX')) {
    define('CUERCODE_DYNAMIC_LOCK_NAME_PREFIX', 'cuercode_dynamic:');
}

/** @var int Seconds GET_LOCK() will wait for the per-user dynamic-code-limit lock before giving up. */
if (!defined('CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS')) {
    define('CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS', 5);
}

/** @var int Retry-After header value (seconds) for a lock-timeout 429 response. */
if (!defined('CUERCODE_DYNAMIC_LOCK_RETRY_AFTER_SECONDS')) {
    define('CUERCODE_DYNAMIC_LOCK_RETRY_AFTER_SECONDS', 2);
}

// ==============================================================================
// 🗄️ DB-touching helpers
// ==============================================================================

// ------------------------------------------------------------------------
// 🔎 fetchRedirectRowByUuid() - The ONE lookup every destination route uses
// ------------------------------------------------------------------------

/**
 * 🔎 Fetch a tblQRRedirects row by its redirectUUID.
 *
 * Every route in this file that operates on a single redirect resource
 * (GET/PATCH `qrcodes/{uuid}/destination`) calls this EXACT function - there
 * is no second, divergent lookup path, mirroring fn_api_qrcodes.php's
 * fetchQrCodeRowByUuid() convention.
 *
 * @param string $uuid The redirect's UUID v4 (already format-validated by the caller).
 *
 * @return array|null The row, or null if not found / DB unavailable.
 */
function fetchRedirectRowByUuid(string $uuid): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `redirectID`, `redirectUUID`, `qrID`, `ownerUserID`, `ownerOrgID`, '
            . '`destinationURL`, `previousDestinationURL`, `destinationUpdatedAt`, `updatedByUserID`, '
            . '`isActive`, `disabledReason`, `scheduledStartAt`, `expiresAt`, '
            . '`isDualFunction`, `hitCount`, `createdAt`, `updatedAt` '
            . 'FROM `tblQRRedirects` '
            . 'WHERE `redirectUUID` = ? '
            . 'LIMIT 1',
            's',
            [$uuid]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic redirect lookup by UUID failed', $e->getMessage());
        }
        return null;
    }
}

// ------------------------------------------------------------------------
// 🔢 countActiveDynamicRedirectsForUser() - Live count backing dynamic_codes
// ------------------------------------------------------------------------

/**
 * 🔢 Count a user's currently-active (isActive=1) dynamic redirects.
 *
 * @param int $userId The tblUsers.userID to count for.
 *
 * @return int The live count (0 if the DB is unavailable or the query fails -
 *             fail-open on the COUNT itself, matching
 *             countSavedQrCodesForUser()'s own documented rationale: the
 *             actual create is still gated by evaluateDynamicCodeQuota()
 *             afterwards, so a transient count failure cannot grant an
 *             unbounded create).
 */
function countActiveDynamicRedirectsForUser(int $userId): int
{
    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `activeCount` FROM `tblQRRedirects` WHERE `ownerUserID` = ? AND `isActive` = 1',
            'i',
            [$userId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null && isset($row['activeCount'])) {
            return (int) $row['activeCount'];
        }

        return 0;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic redirect count failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return 0;
    }
}

// ------------------------------------------------------------------------
// 🔒 acquireDynamicCodeLimitLock() / releaseDynamicCodeLimitLock() - Per-user
//    MySQL advisory lock serialising the dynamic_codes count-check + create
//    critical section (mirrors fn_api_qrcodes.php's SECURITY.md F-023 fix).
// ------------------------------------------------------------------------

/**
 * 🔒 Acquire a per-user MySQL SESSION advisory lock guarding the
 * `dynamic_codes` plan-limit count-check + create critical section.
 *
 * Identical rationale to fn_api_qrcodes.php's acquireSaveLimitLock() (see
 * that function's docblock + SECURITY.md F-023 for the full TOCTOU
 * explanation - the same "N concurrent creates from the same user must not
 * all pass the count check before any create lands" race applies here) - a
 * DIFFERENT lock-name prefix keeps the two independent critical sections
 * (saved-codes claims vs. dynamic-redirect creates) from ever contending
 * with each other for the same lock name.
 *
 * @param int $userId The tblUsers.userID to lock (one advisory lock per user).
 *
 * @return bool True if acquired within CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS;
 *              false on timeout or any DB error - FAIL SAFE, the caller must
 *              NOT proceed unserialised when this returns false.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html#function_get-lock
 */
function acquireDynamicCodeLimitLock(int $userId): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    $lockName = CUERCODE_DYNAMIC_LOCK_NAME_PREFIX . $userId;

    try {
        $lockStmt = dbQuery(
            'SELECT GET_LOCK(?, ?) AS `lockResult`',
            'si',
            [$lockName, CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS]
        );
        $lockRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic-code-limit advisory lock acquisition failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return false;
    }

    // 📌 GET_LOCK() returns 1 (acquired), 0 (timeout), or NULL (error) -
    // anything other than a genuine 1 is treated as "not acquired."
    return $lockRow !== null && isset($lockRow['lockResult']) && (int) $lockRow['lockResult'] === 1;
}

/**
 * 🔓 Release the per-user advisory lock acquired by acquireDynamicCodeLimitLock().
 *
 * Best-effort by design (identical rationale to releaseSaveLimitLock() -
 * connection-scoped locks are auto-released when the request's MySQLi
 * connection closes, so a release failure here cannot leave the lock stuck).
 *
 * @param int $userId The SAME tblUsers.userID passed to acquireDynamicCodeLimitLock().
 *
 * @return void
 */
function releaseDynamicCodeLimitLock(int $userId): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    $lockName = CUERCODE_DYNAMIC_LOCK_NAME_PREFIX . $userId;

    try {
        $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [$lockName]);
        $releaseStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Dynamic-code-limit advisory lock release failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
    }
}

// ==============================================================================
// ➕ createDynamicRedirectForOwner() - The create-dynamic-with-quota helper
// ==============================================================================

/**
 * ➕ Create a new dynamic redirect resource for an owner, enforcing feature
 * gating, destination validation, and the `dynamic_codes` plan quota under
 * the F-023 advisory-lock pattern.
 *
 * ⚠️ DARK / UNROUTED this stage (Epic E1a Stage S1): no route in
 * `public_html/api/v1/index.php` calls this function yet, and it is never
 * invoked from `handleGenerate()` (fn_api_generate.php) either - the design
 * doc's own Stage S1 row says "codes generated in alpha testing only", and
 * the task this function was built under is explicit that **nothing may go
 * live** this stage. Wiring this helper into a live creation path (either a
 * dedicated `POST /api/v1/qrcodes/dynamic` route, or a `dynamic_url` branch
 * inside `handleGenerate()`) is Stage S2 work, alongside the public `/r/`
 * route the resulting redirects would actually need to be useful. Until
 * then this function exists fully built, fully tested (its constituent pure
 * decision - evaluateDynamicCodeQuota(), fn_redirects.php - is unit-tested
 * DB-independently; this DB-touching wrapper is exercised against a live
 * scratch database the same way fn_api_qrcodes.php's DB-touching helpers
 * are), and ready for Stage S2 to call.
 *
 * @param int         $ownerUserId    The owning user's ID (resolved
 *                                    server-side by the caller - NEVER a
 *                                    client-supplied field).
 * @param string      $destinationUrl The initial destination URL (validated
 *                                    here via validateRedirectDestination()).
 * @param int|null    $qrId           Optional tblQRCodes.qrID to link this
 *                                    redirect to (the generated image record
 *                                    - Stage S2 stamps this after rendering).
 *
 * @return array{
 *     success: bool,
 *     type: string,
 *     redirect_uuid: ?string,
 *     limit: ?int,
 *     error: ?string
 * } `type` is one of: 'success', 'feature_denied', 'invalid_destination',
 *   'service_unavailable', 'lock_busy', 'limit_reached', 'create_failed'.
 *
 * @see _functions/fn_redirects.php - validateRedirectDestination(), evaluateDynamicCodeQuota()
 * @see _functions/fn_features.php  - featureEnabled(), limitFor()
 * @see SECURITY.md                 - F-023 (the TOCTOU pattern this critical section closes)
 */
function createDynamicRedirectForOwner(int $ownerUserId, string $destinationUrl, ?int $qrId = null): array
{
    $outcome = [
        'success'       => false,
        'type'          => 'create_failed',
        'redirect_uuid' => null,
        'limit'         => null,
        'error'         => null,
    ];

    // ==================================================================
    // 🚩 Feature gate FIRST - a caller whose plan does not include
    // `dynamic_qr` at all is rejected before any DB work happens.
    // ==================================================================
    if (!function_exists('featureEnabled') || !featureEnabled('dynamic_qr', $ownerUserId)) {
        $outcome['type'] = 'feature_denied';
        return $outcome;
    }

    // ==================================================================
    // 🎯 Validate the destination BEFORE touching the database - a
    // rejected destination should never cost a quota slot or a lock cycle.
    // ==================================================================
    $destinationValidation = validateRedirectDestination($destinationUrl);

    if (!$destinationValidation['valid']) {
        $outcome['type']  = 'invalid_destination';
        $outcome['error'] = $destinationValidation['error'];
        return $outcome;
    }

    if (!function_exists('dbQuery')) {
        $outcome['type'] = 'service_unavailable';
        return $outcome;
    }

    // ==================================================================
    // 🔒 F-023-style advisory lock: serialises the dynamic_codes plan-limit
    // count-check + INSERT for this user, closing the same TOCTOU race
    // fn_api_qrcodes.php's acquireSaveLimitLock() closes for saved codes
    // (SECURITY.md F-023) - N concurrent creates from the SAME user must
    // not all pass the count check before any create lands.
    // ==================================================================
    $lockAcquired = acquireDynamicCodeLimitLock($ownerUserId);

    if (!$lockAcquired) {
        $outcome['type'] = 'lock_busy';
        return $outcome;
    }

    try {
        $currentActiveCount = countActiveDynamicRedirectsForUser($ownerUserId);
        $limit               = function_exists('limitFor') ? limitFor('dynamic_codes', $ownerUserId) : 0;
        $quotaDecision        = evaluateDynamicCodeQuota($currentActiveCount, $limit);

        if (!$quotaDecision['allowed']) {
            $outcome['type']  = 'limit_reached';
            $outcome['limit'] = $limit;
            return $outcome;
        }

        // 📌 Mint the redirect UUID via the SAME generator every other
        // UUID-bearing record in this codebase uses (fn_qr_generate.php),
        // loaded on demand so this file has no hard include-order dependency.
        if (!function_exists('generateUUIDv4')) {
            if (function_exists('loadQRGenerationFunctions')) {
                loadQRGenerationFunctions();
            }
        }

        if (function_exists('generateUUIDv4')) {
            $redirectUuid = generateUUIDv4();
        } else {
            // 🔕 Should be unreachable outside of a misconfigured include
            // path - defensive fallback only, never exercised in production.
            $redirectUuid = bin2hex(random_bytes(16));
        }

        $insertStmt = dbQuery(
            'INSERT INTO `tblQRRedirects` (`redirectUUID`, `qrID`, `ownerUserID`, `destinationURL`) '
            . 'VALUES (?, ?, ?, ?)',
            'siis',
            [$redirectUuid, $qrId, $ownerUserId, $destinationValidation['normalized']]
        );
        $insertStmt->close();

        $outcome['success']       = true;
        $outcome['type']          = 'success';
        $outcome['redirect_uuid'] = $redirectUuid;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic redirect creation failed', 'userID: ' . $ownerUserId . ' | ' . $e->getMessage());
        }
        $outcome['type'] = 'create_failed';
    } finally {
        // 📌 Unlike fn_api_qrcodes.php's handleSaveQRCode() (which must defer
        // errorResponse()/successResponse() calls until AFTER this finally,
        // because those two functions call exit() and PHP's finally never
        // runs past exit()), this function never calls exit() anywhere -
        // it only returns a plain array - so a `return` from inside the try
        // block above still runs this finally correctly, every time.
        releaseDynamicCodeLimitLock($ownerUserId);
    }

    return $outcome;
}

// ==============================================================================
// 🔎 handleGetRedirectDestination() - GET /api/v1/qrcodes/{uuid}/destination
// ==============================================================================

/**
 * 🔎 Handle GET /api/v1/qrcodes/{uuid}/destination
 *
 * Own-scoped only - there is no "isPublic" concept for a redirect
 * destination (unlike a saved QR code's GET), so a redirect not owned by the
 * caller is reported as 404 (never 403), identically to an unknown UUID.
 *
 * @param string $uuid The redirect's UUID v4 (its redirectUUID, NOT a
 *                      tblQRCodes.qrUUID - see fn_redirects.php's docblock).
 * @param array  $auth The router's authentication context.
 *
 * @return void (sends a JSON/XML response and exits)
 */
function handleGetRedirectDestination(string $uuid, array $auth): void
{
    if (!isValidRedirectUuid($uuid)) {
        errorResponse('Invalid redirect identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The dynamic-redirect service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchRedirectRowByUuid($uuid);

    if ($row === null) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $ownerUserId = $row['ownerUserID'] !== null ? (int) $row['ownerUserID'] : null;
    $ownerOrgId  = $row['ownerOrgID'] !== null ? (int) $row['ownerOrgID'] : null;

    // 🔐 IDOR guard: re-checked HERE, on THIS row, right before the read.
    if (!redirectIsWritableBy($ownerUserId, $ownerOrgId, $currentUserId)) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    successResponse(
        [
            'uuid'                      => (string) $row['redirectUUID'],
            'destination_url'           => (string) $row['destinationURL'],
            'previous_destination_url'  => $row['previousDestinationURL'],
            'destination_updated_at'    => $row['destinationUpdatedAt'],
            'is_active'                 => (bool) $row['isActive'],
            'disabled_reason'           => $row['disabledReason'],
            'created_at'                => (string) $row['createdAt'],
            'updated_at'                => (string) $row['updatedAt'],
        ],
        'Dynamic redirect destination retrieved successfully.'
    );
}

// ==============================================================================
// ✏️ handlePatchRedirectDestination() - PATCH /api/v1/qrcodes/{uuid}/destination
// ==============================================================================

/**
 * ✏️ Handle PATCH /api/v1/qrcodes/{uuid}/destination
 *
 * Ownership is checked FIRST, before any other field of the request body is
 * read. A row suspended for abuse (`disabledReason='abuse'`) rejects the
 * WHOLE patch with 409 REDIRECT_SUSPENDED, even from the true owner - an
 * owner cannot edit around a takedown. Accepts "destination_url" (re-run
 * through validateRedirectDestination()) and/or "active" (the owner
 * enable/disable off-switch - design doc §1.4: no hard DELETE this epic).
 * Feature gating is NOT re-checked here (editing an existing code is not a
 * new allocation - matches handlePatchQRCode()'s own documented posture).
 *
 * @param string $uuid        The redirect's UUID v4.
 * @param array  $requestBody The parsed JSON request body.
 * @param array  $auth        The router's authentication context.
 *
 * @return void (sends a JSON/XML response and exits)
 */
function handlePatchRedirectDestination(string $uuid, array $requestBody, array $auth): void
{
    if (!isValidRedirectUuid($uuid)) {
        errorResponse('Invalid redirect identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The dynamic-redirect service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchRedirectRowByUuid($uuid);

    if ($row === null) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    $ownerUserId = $row['ownerUserID'] !== null ? (int) $row['ownerUserID'] : null;
    $ownerOrgId  = $row['ownerOrgID'] !== null ? (int) $row['ownerOrgID'] : null;

    // 🔐 IDOR guard FIRST - before validating/reading anything else in the
    // request body. Not owned -> 404 (never 403 - see file header).
    if (!redirectIsWritableBy($ownerUserId, $ownerOrgId, $currentUserId)) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    // 🚫 An abuse-suspended row cannot be edited around a takedown -
    // admin-only re-enable (not built this stage).
    if (isset($row['disabledReason']) && $row['disabledReason'] === 'abuse') {
        errorResponse(
            'This redirect has been suspended for a policy violation and cannot be edited. '
            . 'Contact support if you believe this is in error.',
            409,
            'REDIRECT_SUSPENDED'
        );
        return;
    }

    $destinationProvided = array_key_exists('destination_url', $requestBody);
    $activeProvided       = array_key_exists('active', $requestBody);

    if (!$destinationProvided && !$activeProvided) {
        errorResponse(
            'No updatable fields were supplied. Provide "destination_url" and/or "active".',
            400,
            'NO_FIELDS'
        );
        return;
    }

    $setClauses = [];
    $bindTypes  = '';
    $bindValues = [];

    $newDestinationUrl = null;

    if ($destinationProvided) {
        if (!is_string($requestBody['destination_url'])) {
            errorResponse('Field "destination_url" must be a string.', 400, 'INVALID_DESTINATION');
            return;
        }

        // 🎯 PATCH re-runs validateRedirectDestination() - a destination
        // once accepted at create time is not assumed to still be valid
        // forever; every write is independently checked.
        $destinationValidation = validateRedirectDestination($requestBody['destination_url']);

        if (!$destinationValidation['valid']) {
            errorResponse((string) $destinationValidation['error'], 400, 'INVALID_DESTINATION');
            return;
        }

        $newDestinationUrl = $destinationValidation['normalized'];

        $setClauses[] = '`previousDestinationURL` = `destinationURL`';
        $setClauses[] = '`destinationURL` = ?';
        $bindTypes   .= 's';
        $bindValues[] = $newDestinationUrl;

        $setClauses[] = '`destinationUpdatedAt` = NOW()';

        $setClauses[] = '`updatedByUserID` = ?';
        $bindTypes   .= 'i';
        $bindValues[] = $currentUserId;
    }

    $newIsActive = null;

    if ($activeProvided) {
        $newIsActive = ((bool) $requestBody['active']) ? 1 : 0;

        $setClauses[] = '`isActive` = ?';
        $bindTypes   .= 'i';
        $bindValues[] = $newIsActive;

        // 📌 An owner-initiated re-enable clears a prior 'owner' disable
        // reason; an owner-initiated disable stamps 'owner' (the 'abuse'
        // reason is unreachable here - blocked above). mysqli binds a PHP
        // null correctly as SQL NULL regardless of the declared bind type.
        $setClauses[] = '`disabledReason` = ?';
        $bindTypes   .= 's';
        if ($newIsActive === 1) {
            $bindValues[] = null;
        } else {
            $bindValues[] = 'owner';
        }
    }

    $setClauses[] = '`updatedAt` = NOW()';

    $bindTypes   .= 'ii';
    $bindValues[] = (int) $row['redirectID'];
    $bindValues[] = $currentUserId;

    try {
        $updateStmt = dbQuery(
            'UPDATE `tblQRRedirects` SET ' . implode(', ', $setClauses)
                . ' WHERE `redirectID` = ? AND `ownerUserID` = ?',
            $bindTypes,
            $bindValues
        );
        $updateStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Update dynamic redirect destination failed', 'uuid: ' . $uuid . ' | ' . $e->getMessage());
        }
        errorResponse('The redirect destination could not be updated. Please try again.', 500, 'UPDATE_FAILED');
        return;
    }

    // 📝 Audit trail - one tblActivityLog row per change, capturing old+new
    // (design doc §1.4: "log tblActivityLog activityType=
    // 'redirect_destination_changed' with old+new").
    if (function_exists('logActivity')) {
        logActivity(
            'redirect_destination_changed',
            'Updated dynamic redirect destination: ' . $uuid,
            'redirect',
            (int) $row['redirectID'],
            [
                'uuid'                => $uuid,
                'destinationChanged'  => $destinationProvided,
                'activeChanged'       => $activeProvided,
                'previousDestination' => $destinationProvided ? (string) $row['destinationURL'] : null,
                'newDestination'      => $destinationProvided ? $newDestinationUrl : null,
                'previousIsActive'    => $activeProvided ? (bool) $row['isActive'] : null,
                'newIsActive'         => $activeProvided ? (bool) $newIsActive : null,
            ]
        );
    }

    $refreshed = fetchRedirectRowByUuid($uuid);

    successResponse(
        [
            'uuid'                     => $uuid,
            'destination_url'          => $refreshed['destinationURL'] ?? null,
            'previous_destination_url' => $refreshed['previousDestinationURL'] ?? null,
            'destination_updated_at'   => $refreshed['destinationUpdatedAt'] ?? null,
            'is_active'                => (bool) ($refreshed['isActive'] ?? 0),
            'disabled_reason'          => $refreshed['disabledReason'] ?? null,
            'updated_at'               => $refreshed['updatedAt'] ?? null,
        ],
        'Dynamic redirect destination updated successfully.'
    );
}

// ==============================================================================
// 🔀 handleQrDestinationItem() - Router-facing dispatcher for GET/PATCH
//    /qrcodes/{uuid}/destination
// ==============================================================================

/**
 * 🔀 Dispatch a "/qrcodes/{uuid}/destination" request to the method-specific handler.
 *
 * The router matches a route by PATTERN, not by pattern+method - GET/PATCH
 * on "qrcodes/{uuid}/destination" therefore share ONE route table entry and
 * ONE handler, mirroring fn_api_qrcodes.php's handleQrCodeItem() convention
 * exactly.
 *
 * @param string $uuid        The redirect's UUID v4 (from the route parameter).
 * @param array  $requestBody The parsed JSON request body (only meaningful for PATCH).
 * @param array  $auth        The router's authentication context.
 *
 * @return void (delegates to a handler that sends the response and exits)
 */
function handleQrDestinationItem(string $uuid, array $requestBody, array $auth): void
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
    } else {
        $method = 'GET';
    }

    switch ($method) {
        case 'PATCH':
            handlePatchRedirectDestination($uuid, $requestBody, $auth);
            return;

        case 'GET':
        default:
            handleGetRedirectDestination($uuid, $auth);
            return;
    }
}
