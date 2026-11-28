<?php
/**
 * ==============================================================================
 * 🔗 CueRCode - Dynamic QR codes: create, change, switch off and on (WP4c, #166)
 * ==============================================================================
 *
 * Path: _functions/fn_api_redirects.php
 *
 * WHAT THIS IS (plain English):
 *   A dynamic QR code prints a short address such as https://QRmy.Link/Ab3dE9x.
 *   Go2My.Link (the owner's sister link service, decision 0027) answers that
 *   address and sends each scan on to the code's destination, which the owner
 *   can change later without reprinting. CueRCode keeps its own record of
 *   every code in tblQRRedirects (who owns it, a copy of the destination, its
 *   state); Go2My.Link holds the live link (lead choice L5). This file holds
 *   the database work and the flows:
 *
 *   - createDynamicRedirectForOwner() - make one code (called by
 *     POST /api/v1/generate with type "dynamic_url", fn_api_generate.php);
 *   - dynamicQrChangeForOwner()       - change where a code goes, switch it
 *     off or on (PATCH /api/v1/qrcodes/{uuid}/destination);
 *   - dynamicQrImageGate()            - may this person download this code's
 *     picture (GET /api/v1/download/{uuid}, fn_api_download.php)?
 *   - dynamicQrTidyPendingRows()      - the cron job's tidy step for codes
 *     whose create answer was lost;
 *   - the two route handlers (GET/PATCH qrcodes/{uuid}/destination).
 *
 * THE RULES (plan .claude/plans/first-launch-plan.md, WP4c):
 *   1. Only a signed-in account that is active AND has a confirmed email may
 *      make or change codes (403 EMAIL_NOT_CONFIRMED otherwise).
 *   2. The master switch dynamic_qr_enabled must be on (503 otherwise). It is
 *      off by default and an admin can only switch it on after a passing
 *      connection test (fn_admin_settings.php).
 *   3. The plan feature dynamic_qr and the limit dynamic_codes (with a
 *      person's own raised number, decisions 0031 and 0045) are checked
 *      UNDER a per-person lock, together with the count and the new row, so
 *      requests sent at the same moment cannot all pass the check.
 *   4. A code COUNTS towards the limit from the moment its row is saved
 *      ("pending", BEFORE Go2My.Link is asked) until Go2My.Link is known to
 *      have made nothing ("failed"). Everything else counts: active,
 *      switched off, suspended, waiting (pending) and held (unconfirmed). So
 *      a failure, a retry or a lost answer can never give anyone more codes
 *      than their limit, and switching codes off does not free a place.
 *   5. "active" is the ONLY state whose picture may be drawn or downloaded,
 *      by any route (dynamicQrRowIsPrintable(), fn_redirects.php).
 *   6. A code Go2My.Link holds but that cannot be confirmed ("unconfirmed")
 *      is recorded, never printed and never tried again by itself; an admin
 *      looks at it. A code whose answer was lost ("pending") is settled by
 *      the cron tidy step (dynamicQrTidyPendingRows()).
 *   7. A change calls Go2My.Link FIRST, and is saved here only once
 *      Go2My.Link has confirmed it. Changes run under the same per-person
 *      lock, so two changes at once cannot leave the two sides different.
 *   8. Other people's codes, and unknown ones, answer 404 NOT_FOUND (never
 *      403: a 403 would confirm the code exists).
 *   9. A suspended code refuses every change by its owner (409
 *      REDIRECT_SUSPENDED); only an admin can lift a suspension (WP4f, #169).
 *  10. Switching off points the Go2My.Link link at CueRCode's /unavailable/
 *      page; switching on points it back at the destination (lead choice
 *      L2: Go2My.Link's API cannot switch a link back on, and its own
 *      switched-off page says "disabled by its owner").
 *  11. The link-safety check is ONE hook, dynamicQrCheckDestinationSafety()
 *      (fn_redirects.php), called on create and on every change of where a
 *      code sends people. Its check is WP4e (#168); until then it allows all.
 *
 * House rules: full multi-line PHP (no ??, ternaries or arrow functions),
 * MySQLi prepared statements through dbQuery(), every SQL name in back-quotes.
 * The two flows never call errorResponse()/successResponse() (both exit, and
 * an exit would skip the lock's release); they return a plain answer that the
 * route handlers turn into a response.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     2.0.0
 * @since       Phase 4 (Epic E1a Stage S1, #27); rewritten 2026-11-28 for WP4c (#166)
 * @see         _functions/fn_redirects.php   - the pure rules this file uses
 * @see         _functions/fn_go2mylink.php   - the only place Go2My.Link is called
 * @see         _functions/fn_features.php    - featureEnabled(), limitFor(), resolveUserIdFromAuth()
 * @see         _functions/fn_api_generate.php - handleGenerateDynamic()
 * @see         SECURITY.md                   - F-023 (the per-person lock pattern)
 * @see         docs/decisions/0027, 0030, 0031, 0045
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $apiRedirectsScriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $apiRedirectsScriptFilename = '';
}
if (basename($apiRedirectsScriptFilename) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
    }
    exit('Access denied.');
}
unset($apiRedirectsScriptFilename);

// ==============================================================================
// 📦 What this file builds on (each is pure on loading)
// ==============================================================================
if (!function_exists('dynamicQrRowIsPrintable')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_redirects.php';
}
if (!function_exists('go2mylinkCreateLinkSafely')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_go2mylink.php';
}

// ==============================================================================
// 🔧 Constants
// ==============================================================================

/**
 * @var string Prefix of the per-person MySQL lock name (SECURITY.md F-023):
 *             the count, the limit check and the new row of a create, and
 *             every change, run while it is held. The full name adds the
 *             person's userID.
 */
if (!defined('CUERCODE_DYNAMIC_LOCK_NAME_PREFIX')) {
    define('CUERCODE_DYNAMIC_LOCK_NAME_PREFIX', 'cuercode_dynamic:');
}

/** @var int Seconds GET_LOCK() waits for the per-person lock before giving up. */
if (!defined('CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS')) {
    define('CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS', 5);
}

/** @var int Retry-After (seconds) when the per-person lock was busy. */
if (!defined('CUERCODE_DYNAMIC_LOCK_RETRY_AFTER_SECONDS')) {
    define('CUERCODE_DYNAMIC_LOCK_RETRY_AFTER_SECONDS', 2);
}

/** @var int Most short codes tried for one new code when Go2My.Link says a code is taken. */
if (!defined('CUERCODE_DYNAMIC_MAX_CODE_ATTEMPTS')) {
    define('CUERCODE_DYNAMIC_MAX_CODE_ATTEMPTS', 3);
}

/** @var int Minutes a "pending" row is left alone before the tidy step looks it up (far longer than any Go2My.Link call can take). */
if (!defined('CUERCODE_DYNAMIC_PENDING_STALE_MINUTES')) {
    define('CUERCODE_DYNAMIC_PENDING_STALE_MINUTES', 10);
}

/** @var int Most "pending" rows the tidy step settles in one run (bounds the calls to Go2My.Link). */
if (!defined('CUERCODE_DYNAMIC_TIDY_BATCH')) {
    define('CUERCODE_DYNAMIC_TIDY_BATCH', 25);
}

/** @var string The columns every read of a code uses. */
if (!defined('CUERCODE_DYNAMIC_ROW_COLUMNS')) {
    define(
        'CUERCODE_DYNAMIC_ROW_COLUMNS',
        '`redirectID`, `redirectUUID`, `shortCode`, `syncState`, `lastSyncError`, `qrID`, `ownerUserID`, `ownerOrgID`, '
        . '`destinationURL`, `previousDestinationURL`, `destinationUpdatedAt`, `updatedByUserID`, '
        . '`isActive`, `disabledReason`, `suspendedAt`, `suspendedByUserID`, `scheduledStartAt`, `expiresAt`, '
        . '`isDualFunction`, `hitCount`, `createdAt`, `updatedAt`'
    );
}

// ==============================================================================
// 🧱 Small shared pieces
// ==============================================================================

/**
 * 🧾 One flow answer, in the shape the route handlers turn into a response.
 *
 * @param bool                 $ok
 * @param array                $facts      httpCode, errorCode and message (written as 'errorCode' => 'X'
 *                                         so tests/test_error_catalogue.php finds every code)
 * @param array<string, mixed> $extra      Extra fields for the error response
 * @param int                  $retryAfter Seconds for a Retry-After header (0 = none)
 * @param array|null           $row        The code, on success
 * @return array{ok: bool, httpCode: int, errorCode: string, message: string, extra: array, retryAfter: int, row: array|null}
 */
function dynamicQrOutcome(bool $ok, array $facts, array $extra = [], int $retryAfter = 0, ?array $row = null): array
{
    return [
        'ok'         => $ok,
        'httpCode'   => (int) $facts['httpCode'],
        'errorCode'  => (string) $facts['errorCode'],
        'message'    => (string) $facts['message'],
        'extra'      => $extra,
        'retryAfter' => $retryAfter,
        'row'        => $row,
    ];
}

/**
 * ⚙️ The options a flow hands to fn_go2mylink.php: a test's stand-in
 *    ('settings', 'transport', 'caFile', 'logger') under 'go2mylink', or none
 *    (the site's own settings and the real connection).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function dynamicQrGo2Options(#[\SensitiveParameter] array $options): array
{
    if (isset($options['go2mylink']) && is_array($options['go2mylink'])) {
        return $options['go2mylink'];
    }

    return [];
}

/**
 * 🚦 Is the master switch (dynamic_qr_enabled) on? Off when it cannot be read.
 *
 * @return bool
 */
function dynamicQrMasterSwitchOn(): bool
{
    if (!function_exists('getConfig')) {
        return false;
    }

    $value = getConfig('dynamic_qr_enabled', false);

    return ($value === true || $value === 1 || $value === '1');
}

/**
 * 🔓 May a destination be plain http? (redirect_allow_http, off by default.)
 *
 * @return bool
 */
function dynamicQrAllowHttp(): bool
{
    if (!function_exists('getConfig')) {
        return false;
    }

    $value = getConfig('redirect_allow_http', false);

    return ($value === true || $value === 1 || $value === '1');
}

/**
 * 🆔 A new random UUID v4 for a code (the generator every other record uses).
 *
 * @return string
 */
function dynamicQrNewUuid(): string
{
    if (!function_exists('generateUUIDv4')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'fn_qr_generate.php';
    }

    return strtolower(generateUUIDv4());
}

/**
 * 🔁 Was this database error a duplicate key (the short code or the UUID is
 *    already used)?
 *
 * @param \Throwable $error
 * @return bool
 */
function dynamicQrIsDuplicateKey(\Throwable $error): bool
{
    if ($error instanceof \mysqli_sql_exception && (int) $error->getCode() === 1062) {
        return true;
    }

    return str_contains($error->getMessage(), 'Duplicate entry');
}

// ==============================================================================
// 🗄️ Database reads
// ==============================================================================

/**
 * 🔎 Fetch a code by its redirectUUID - the ONE look-up every route uses.
 *
 * @param string $uuid The code's UUID v4 (already checked by the caller)
 * @return array|null The row, or null when not found or the database could not be read
 */
function fetchRedirectRowByUuid(string $uuid): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT ' . CUERCODE_DYNAMIC_ROW_COLUMNS . ' FROM `tblQRRedirects` WHERE `redirectUUID` = ? LIMIT 1',
            's',
            [strtolower($uuid)]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code look-up by UUID failed', get_class($e));
        }
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return $row;
}

/**
 * 🔎 Fetch a code by its row number.
 *
 * @param int $redirectId
 * @return array|null
 */
function fetchRedirectRowById(int $redirectId): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT ' . CUERCODE_DYNAMIC_ROW_COLUMNS . ' FROM `tblQRRedirects` WHERE `redirectID` = ? LIMIT 1',
            'i',
            [$redirectId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code look-up by number failed', get_class($e));
        }
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return $row;
}

/**
 * 👤 The account's status and email confirmation time, read fresh.
 *
 * @param int $userId
 * @return array{status: string, emailVerifiedAt: string|null}|null Null when not found or unreadable
 */
function dynamicQrLoadAccount(int $userId): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery('SELECT `status`, `emailVerifiedAt` FROM `tblUsers` WHERE `userID` = ? LIMIT 1', 'i', [$userId]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return $row;
}

/**
 * 🔢 How many codes count towards this person's limit: every code of theirs
 *    except those Go2My.Link is known to have made nothing for ("failed").
 *    Pending, active, switched off, suspended and unconfirmed codes all
 *    count (rule 4 at the top of this file).
 *
 *    Unlike the older count, a database failure answers null and the create
 *    is REFUSED (a count that silently said 0 could hand out codes past the
 *    limit).
 *
 * @param int $userId
 * @return int|null
 */
function dynamicQrCountHeldCodes(int $userId): ?int
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            "SELECT COUNT(*) AS `heldCount` FROM `tblQRRedirects` WHERE `ownerUserID` = ? AND `syncState` <> 'failed'",
            'i',
            [$userId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code count failed', 'userID: ' . $userId . ' | ' . get_class($e));
        }
        return null;
    }

    if (!is_array($row) || !isset($row['heldCount'])) {
        return null;
    }

    return (int) $row['heldCount'];
}

// ==============================================================================
// 🔒 The per-person lock (SECURITY.md F-023)
// ==============================================================================

/**
 * 🔒 Take this person's dynamic-code lock (a MySQL session lock). Fails safe:
 *    false on a time-out or any error, and the caller must then stop.
 *
 * @param int $userId
 * @return bool
 * @see https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html#function_get-lock
 */
function acquireDynamicCodeLimitLock(int $userId): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $lockStmt = dbQuery(
            'SELECT GET_LOCK(?, ?) AS `lockResult`',
            'si',
            [CUERCODE_DYNAMIC_LOCK_NAME_PREFIX . $userId, CUERCODE_DYNAMIC_LOCK_TIMEOUT_SECONDS]
        );
        $lockRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic-code lock could not be taken', 'userID: ' . $userId . ' | ' . get_class($e));
        }
        return false;
    }

    // 📌 1 = taken, 0 = timed out, NULL = error: only a real 1 counts.
    return is_array($lockRow) && isset($lockRow['lockResult']) && (int) $lockRow['lockResult'] === 1;
}

/**
 * 🔓 Let the lock go. Best effort: a session lock also ends with the
 *    database connection, so it can never stay stuck.
 *
 * @param int $userId
 * @return void
 */
function releaseDynamicCodeLimitLock(int $userId): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $releaseStmt = dbQuery('SELECT RELEASE_LOCK(?) AS `releaseResult`', 's', [CUERCODE_DYNAMIC_LOCK_NAME_PREFIX . $userId]);
        $releaseStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Dynamic-code lock could not be let go', 'userID: ' . $userId . ' | ' . get_class($e));
        }
    }
}

// ==============================================================================
// 🗄️ Database writes
// ==============================================================================

/**
 * 📝 Save the outcome of a Go2My.Link create (or of the tidy step) on a row
 *    that is still "pending": its new state, the reason word on failure,
 *    and the short code Go2My.Link confirmed.
 *
 * @param int    $redirectId
 * @param string $state     go2mylinkSyncStateFor()
 * @param string $reason    '' on success, otherwise the reason word
 * @param string $shortCode The code to keep
 * @return bool True when the row was changed
 */
function dynamicQrSaveCreateState(int $redirectId, string $state, string $reason, string $shortCode): bool
{
    $lastError = null;
    if ($reason !== '') {
        $lastError = substr($reason, 0, 64);
    }

    try {
        $stmt = dbQuery(
            "UPDATE `tblQRRedirects` SET `syncState` = ?, `lastSyncError` = ?, `shortCode` = ?, `updatedAt` = NOW() "
            . "WHERE `redirectID` = ? AND `syncState` = 'pending'",
            'sssi',
            [$state, $lastError, $shortCode, $redirectId]
        );
        $changed = ($stmt->affected_rows === 1);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code state could not be saved', 'redirectID: ' . $redirectId . ' | ' . get_class($e));
        }
        return false;
    }

    return $changed;
}

/**
 * ⚠️ Note why the last call about a code failed (a reason word, never
 *    Go2My.Link's own text), without changing its state.
 *
 * @param int    $redirectId
 * @param string $reason
 * @return void
 */
function dynamicQrNoteSyncError(int $redirectId, string $reason): void
{
    try {
        $stmt = dbQuery(
            'UPDATE `tblQRRedirects` SET `lastSyncError` = ? WHERE `redirectID` = ?',
            'si',
            [substr($reason, 0, 64), $redirectId]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Dynamic code note could not be saved', 'redirectID: ' . $redirectId . ' | ' . get_class($e));
        }
    }
}

/**
 * 🖼️ Link a code to the picture just drawn for it (tblQRCodes), so the
 *    download gate can find the code from the picture.
 *
 * @param int    $redirectId
 * @param string $qrUuid The picture's tblQRCodes.qrUUID
 * @return bool
 */
function dynamicQrLinkImage(int $redirectId, string $qrUuid): bool
{
    try {
        $stmt = dbQuery(
            'UPDATE `tblQRRedirects` SET `qrID` = (SELECT `q`.`qrID` FROM `tblQRCodes` AS `q` WHERE `q`.`qrUUID` = ? LIMIT 1) WHERE `redirectID` = ?',
            'si',
            [$qrUuid, $redirectId]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code picture could not be linked', 'redirectID: ' . $redirectId . ' | ' . get_class($e));
        }
        return false;
    }

    return true;
}

// ==============================================================================
// 👁️ What a member is shown about a code
// ==============================================================================

/**
 * 👁️ A code as the API shows it to its owner. short_url is given only for a
 *    code Go2My.Link has confirmed (never for one that is waiting or held).
 *
 *   state: active | switched_off | suspended | not_ready (waiting for the
 *   tidy step) | held (an admin must look) | failed
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function dynamicQrPublicView(array $row): array
{
    $syncState = (string) $row['syncState'];

    $state = 'failed';
    if (dynamicQrRowIsSuspended($row)) {
        $state = 'suspended';
    } elseif ($syncState === CUERCODE_DYNAMIC_QR_STATE_ACTIVE && (int) $row['isActive'] === 1) {
        $state = 'active';
    } elseif ($syncState === CUERCODE_DYNAMIC_QR_STATE_ACTIVE) {
        $state = 'switched_off';
    } elseif ($syncState === 'pending' || $syncState === 'unlinked') {
        $state = 'not_ready';
    } elseif ($syncState === 'unconfirmed') {
        $state = 'held';
    }

    $shortCode = null;
    $shortUrl  = null;
    if ($syncState === CUERCODE_DYNAMIC_QR_STATE_ACTIVE && isset($row['shortCode']) && is_string($row['shortCode'])) {
        $shortCode = $row['shortCode'];
        $base = 'https://QRmy.Link';
        if (function_exists('getConfig')) {
            $configured = getConfig('dynamic_redirect_base_url', 'https://QRmy.Link');
            if (is_string($configured) && trim($configured) !== '') {
                $base = trim($configured);
            }
        }
        $shortUrl = rtrim($base, '/') . '/' . $shortCode;
    }

    return [
        'uuid'                     => (string) $row['redirectUUID'],
        'state'                    => $state,
        'short_code'               => $shortCode,
        'short_url'                => $shortUrl,
        'destination_url'          => (string) $row['destinationURL'],
        'previous_destination_url' => $row['previousDestinationURL'],
        'destination_updated_at'   => $row['destinationUpdatedAt'],
        'is_active'                => ((int) $row['isActive'] === 1),
        'disabled_reason'          => $row['disabledReason'],
        'created_at'               => (string) $row['createdAt'],
        'updated_at'               => (string) $row['updatedAt'],
    ];
}

// ==============================================================================
// ➕ createDynamicRedirectForOwner() - make one dynamic code
// ==============================================================================

/**
 * ➕ Make one dynamic code for an owner, in this order (a source test checks
 *    the order):
 *
 *   1. the account is active with a confirmed email (403 EMAIL_NOT_CONFIRMED);
 *   2. the master switch is on and Go2My.Link is set up (503 DYNAMIC_QR_UNAVAILABLE);
 *   3. the destination passes validateRedirectDestination() (400 INVALID_DESTINATION);
 *   4. the link-safety hook (422 DESTINATION_UNSAFE; #168 fills it in);
 *   5. UNDER THE PER-PERSON LOCK: the plan feature dynamic_qr (402
 *      FEATURE_NOT_AVAILABLE), the limit dynamic_codes with the person's own
 *      number (402 DYNAMIC_CODES_LIMIT_REACHED), the count of codes held
 *      (503 if it cannot be read), and the new row saved as "pending" with a
 *      short code CueRCode chose (lead choice L1) - then the lock is let go
 *      (429 DYNAMIC_LOCK_BUSY if another request holds it);
 *   6. Go2My.Link is asked (go2mylinkCreateLinkSafely(): a lost answer is
 *      looked up before it is ever tried again); a taken code is replaced by
 *      a new one and tried again, at most 3 codes in all;
 *   7. the outcome is saved as the row's state (go2mylinkSyncStateFor()).
 *
 * It never calls errorResponse()/successResponse() (both exit, which would
 * skip the lock's release).
 *
 * @param int                  $ownerUserId    Resolved by the caller from the sign-in (never from the request)
 * @param string               $destinationUrl As typed
 * @param array<string, mixed> $options        'go2mylink' => options for fn_go2mylink.php (tests)
 * @return array Outcome (dynamicQrOutcome()); row = the code on success
 */
function createDynamicRedirectForOwner(int $ownerUserId, string $destinationUrl, #[\SensitiveParameter] array $options = []): array
{
    // 👤 1. Signed in, active, with a confirmed email.
    if (!dynamicQrAccountMayChangeCodes(dynamicQrLoadAccount($ownerUserId))) {
        return dynamicQrOutcome(false, ['httpCode' => 403, 'errorCode' => 'EMAIL_NOT_CONFIRMED', 'message' => 'Dynamic codes need an active account with a confirmed email address. Confirm your email address, then try again.']);
    }

    // 🚦 2. Switched on, and Go2My.Link set up.
    if (!dynamicQrMasterSwitchOn()) {
        return dynamicQrOutcome(false, ['httpCode' => 503, 'errorCode' => 'DYNAMIC_QR_UNAVAILABLE', 'message' => 'Dynamic codes are not available yet.']);
    }
    $go2Options = dynamicQrGo2Options($options);
    $settings   = go2mylinkSettingsFromOptions($go2Options);
    if ($settings['problem'] !== '') {
        if (function_exists('logError')) {
            logError('warning', 'Dynamic code refused: Go2My.Link is not set up', 'reason=' . (string) $settings['problem']);
        }
        return dynamicQrOutcome(false, ['httpCode' => 503, 'errorCode' => 'DYNAMIC_QR_UNAVAILABLE', 'message' => CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE]);
    }

    // 🎯 3. The destination.
    $checked = validateRedirectDestination(dynamicQrPrepareDestination($destinationUrl), ['allow_http' => dynamicQrAllowHttp()]);
    if (!$checked['valid']) {
        return dynamicQrOutcome(false, ['httpCode' => 400, 'errorCode' => 'INVALID_DESTINATION', 'message' => (string) $checked['error']]);
    }
    // 🔤 Stored and sent in the form Go2My.Link stores (#181 Low 3).
    $destination = go2mylinkEncodeDestination($checked['normalized']);

    // 🛡️ 4. The link-safety hook (WP4e, #168).
    $safety = dynamicQrCheckDestinationSafety($destination, 'create');
    if ($safety['allowed'] !== true) {
        return dynamicQrOutcome(false, ['httpCode' => 422, 'errorCode' => 'DESTINATION_UNSAFE', 'message' => 'This destination was refused by the link-safety check, so no dynamic code was made.']);
    }

    // 🔒 5. Feature, limit, count and the new "pending" row, under the lock.
    if (!acquireDynamicCodeLimitLock($ownerUserId)) {
        return dynamicQrOutcome(false, ['httpCode' => 429, 'errorCode' => 'DYNAMIC_LOCK_BUSY', 'message' => 'Another request for your account is being dealt with. Please try again in a moment.'], [], CUERCODE_DYNAMIC_LOCK_RETRY_AFTER_SECONDS);
    }

    $reserved = null;
    try {
        $reserved = dynamicQrReserveRow($ownerUserId, $destination);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code could not be reserved', 'userID: ' . $ownerUserId . ' | ' . get_class($e));
        }
        $reserved = dynamicQrOutcome(false, ['httpCode' => 500, 'errorCode' => 'CREATE_FAILED', 'message' => 'The dynamic code could not be made. Please try again.']);
    } finally {
        releaseDynamicCodeLimitLock($ownerUserId);
    }

    if ($reserved['ok'] !== true) {
        return $reserved;
    }
    $row = $reserved['row'];

    // 🌐 6. Ask Go2My.Link; a taken code is replaced (at most 3 codes in all).
    $shortCode = (string) $row['shortCode'];
    $result    = [];
    for ($attempt = 1; $attempt <= CUERCODE_DYNAMIC_MAX_CODE_ATTEMPTS; $attempt++) {
        $result = go2mylinkCreateLinkSafely([
            'redirectID'     => (int) $row['redirectID'],
            'redirectUUID'   => (string) $row['redirectUUID'],
            'destinationUrl' => $destination,
            'shortCode'      => $shortCode,
        ], $go2Options);

        if ($result['ok'] === true || $result['reason'] !== 'code_taken' || $attempt === CUERCODE_DYNAMIC_MAX_CODE_ATTEMPTS) {
            break;
        }

        // 🎲 Taken on Go2My.Link: store a NEW code first, then ask again.
        $newCode = dynamicQrReplaceShortCode((int) $row['redirectID']);
        if ($newCode === '') {
            break;
        }
        $shortCode = $newCode;
    }

    // 📝 7. The outcome becomes the row's state.
    $state  = go2mylinkSyncStateFor($result);
    $reason = '';
    if ($result['ok'] !== true) {
        $reason = (string) $result['reason'];
    } else {
        $confirmedCode = (string) $result['data']['shortCode'];
        if ($confirmedCode !== $shortCode && function_exists('logError')) {
            // Go2My.Link chose its own code: its "CueRCode chooses the code"
            // setting is off (G2), so a lost answer could not be found again.
            logError('warning', 'Go2My.Link did not keep the short code CueRCode chose', 'redirectID: ' . (int) $row['redirectID']);
        }
        $shortCode = $confirmedCode;
    }

    $saved = dynamicQrSaveCreateState((int) $row['redirectID'], $state, $reason, $shortCode);

    if (function_exists('logActivity')) {
        logActivity(
            'dynamic_code_created',
            'Dynamic code ' . (string) $row['redirectUUID'] . ': ' . $state,
            'redirect',
            (int) $row['redirectID'],
            ['uuid' => (string) $row['redirectUUID'], 'state' => $state, 'reason' => $reason, 'stateSaved' => $saved]
        );
    }

    if ($result['ok'] === true && $saved) {
        $fresh = fetchRedirectRowById((int) $row['redirectID']);
        if ($fresh !== null && dynamicQrRowIsPrintable($fresh)) {
            return dynamicQrOutcome(true, ['httpCode' => 200, 'errorCode' => '', 'message' => 'Dynamic QR code created successfully.'], [], 0, $fresh);
        }
    }

    if ($result['ok'] === true) {
        // ✅ Made on Go2My.Link, but the state could not be saved here: it
        //    stays "pending" (counted, never printed) for the tidy step.
        return dynamicQrOutcome(false, ['httpCode' => 503, 'errorCode' => 'LINK_NOT_CONFIRMED', 'message' => 'The new dynamic code could not be finished just now. It is checked again automatically and counts towards your limit until then. Please try again later.'], ['redirect_uuid' => (string) $row['redirectUUID']]);
    }

    $facts = dynamicQrServiceErrorFacts($result, 'create');

    return dynamicQrOutcome(false, $facts, ['redirect_uuid' => (string) $row['redirectUUID'], 'state' => $state], $facts['retryAfter']);
}

/**
 * 🧮 Step 5 of the create, called ONLY while the per-person lock is held:
 *    the feature, the limit, the count, and the new "pending" row.
 *
 * @param int    $ownerUserId
 * @param string $destination Already checked and normalised
 * @return array Outcome; row = the new row on success
 */
function dynamicQrReserveRow(int $ownerUserId, string $destination): array
{
    if (!function_exists('featureEnabled') || !featureEnabled('dynamic_qr', $ownerUserId)) {
        $upgrade = [];
        if (function_exists('resolveUpgradeUrl')) {
            $upgrade['upgrade_url'] = resolveUpgradeUrl();
        }
        $upgrade['feature'] = 'dynamic_qr';
        return dynamicQrOutcome(false, ['httpCode' => 402, 'errorCode' => 'FEATURE_NOT_AVAILABLE', 'message' => 'Dynamic codes are not included in your plan.'], $upgrade);
    }

    $limit = 0;
    if (function_exists('limitFor')) {
        $limit = limitFor('dynamic_codes', $ownerUserId);
    }

    $held = dynamicQrCountHeldCodes($ownerUserId);
    if ($held === null) {
        return dynamicQrOutcome(false, ['httpCode' => 503, 'errorCode' => 'SERVICE_UNAVAILABLE', 'message' => 'The dynamic-code service is temporarily unavailable. Please try again later.']);
    }

    $quota = evaluateDynamicCodeQuota($held, $limit);
    if ($quota['allowed'] !== true) {
        $extra = ['plan_limit' => $limit];
        if (function_exists('resolveUpgradeUrl')) {
            $extra['upgrade_url'] = resolveUpgradeUrl();
        }
        if ($quota['reason'] === 'not_permitted') {
            return dynamicQrOutcome(false, ['httpCode' => 402, 'errorCode' => 'FEATURE_NOT_AVAILABLE', 'message' => 'Dynamic codes are not included in your plan.'], $extra);
        }
        return dynamicQrOutcome(false, ['httpCode' => 402, 'errorCode' => 'DYNAMIC_CODES_LIMIT_REACHED', 'message' => 'You have reached your limit of ' . $limit . ' dynamic code(s). Switched-off codes still count. Change the destination of an existing code instead, or ask us for more.'], $extra);
    }

    // 💾 The row, "pending", with a short code CueRCode chose (L1). A clash
    //    with another row's code or UUID (vanishingly rare) draws again.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $uuid      = dynamicQrNewUuid();
        $shortCode = go2mylinkGenerateShortCode();

        try {
            $insert = dbQuery(
                "INSERT INTO `tblQRRedirects` (`redirectUUID`, `shortCode`, `syncState`, `ownerUserID`, `destinationURL`, `isActive`) "
                . "VALUES (?, ?, 'pending', ?, ?, 1)",
                'ssis',
                [$uuid, $shortCode, $ownerUserId, $destination]
            );
            $newId = (int) $insert->insert_id;
            $insert->close();
        } catch (\Throwable $e) {
            if (dynamicQrIsDuplicateKey($e)) {
                continue;
            }
            throw $e;
        }

        $row = fetchRedirectRowById($newId);
        if ($row === null) {
            return dynamicQrOutcome(false, ['httpCode' => 500, 'errorCode' => 'CREATE_FAILED', 'message' => 'The dynamic code could not be made. Please try again.']);
        }
        return dynamicQrOutcome(true, ['httpCode' => 200, 'errorCode' => '', 'message' => ''], [], 0, $row);
    }

    return dynamicQrOutcome(false, ['httpCode' => 500, 'errorCode' => 'CREATE_FAILED', 'message' => 'The dynamic code could not be made. Please try again.']);
}

/**
 * 🎲 Store a NEW short code on a "pending" row whose code Go2My.Link said was
 *    taken (stored before it is sent, as lead choice L1 needs).
 *
 * @param int $redirectId
 * @return string The new code, or '' when none could be stored
 */
function dynamicQrReplaceShortCode(int $redirectId): string
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $newCode = go2mylinkGenerateShortCode();
        try {
            $stmt = dbQuery(
                "UPDATE `tblQRRedirects` SET `shortCode` = ?, `lastSyncError` = 'code_taken' WHERE `redirectID` = ? AND `syncState` = 'pending'",
                'si',
                [$newCode, $redirectId]
            );
            $changed = ($stmt->affected_rows === 1);
            $stmt->close();
        } catch (\Throwable $e) {
            if (dynamicQrIsDuplicateKey($e)) {
                continue;
            }
            return '';
        }
        if ($changed) {
            return $newCode;
        }
        return '';
    }

    return '';
}

// ==============================================================================
// ✏️ dynamicQrChangeForOwner() - change, switch off, switch on
// ==============================================================================

/**
 * ✏️ Change one code for its owner: a new destination and/or switching it
 *    off or on (lead choice L2). In this order (a source test checks it):
 *
 *   1. the code exists AND belongs to the caller - otherwise 404 NOT_FOUND,
 *      before anything in the request is read;
 *   2. the account is active with a confirmed email (403 EMAIL_NOT_CONFIRMED);
 *   3. the master switch is on (503 DYNAMIC_QR_UNAVAILABLE);
 *   4. UNDER THE PER-PERSON LOCK: the code is read again, the change is
 *      planned (dynamicQrPlanChange(): suspended 409, not ready 409, bad
 *      fields 400), the link-safety hook runs on a destination about to go
 *      live, Go2My.Link is changed FIRST (and confirmed, by its answer or by
 *      looking the link up), and only then is the row here changed.
 *
 * @param int                  $callerUserId Resolved from the sign-in
 * @param string               $uuid         The code's redirectUUID
 * @param array<string, mixed> $body         destination_url and/or active
 * @param array<string, mixed> $options      'go2mylink' (tests)
 * @return array Outcome; row = the code after the change
 */
function dynamicQrChangeForOwner(int $callerUserId, string $uuid, array $body, #[\SensitiveParameter] array $options = []): array
{
    if (!isValidRedirectUuid($uuid)) {
        return dynamicQrOutcome(false, ['httpCode' => 400, 'errorCode' => 'INVALID_UUID', 'message' => 'Invalid redirect identifier format.']);
    }

    // 🔐 1. Ownership FIRST. Not yours, or unknown: the same 404.
    $row = fetchRedirectRowByUuid($uuid);
    if ($row === null || !redirectIsWritableBy(dynamicQrOwnerOf($row), null, $callerUserId)) {
        return dynamicQrOutcome(false, ['httpCode' => 404, 'errorCode' => 'NOT_FOUND', 'message' => 'QR code not found.']);
    }

    // 👤 2. Active account, confirmed email.
    if (!dynamicQrAccountMayChangeCodes(dynamicQrLoadAccount($callerUserId))) {
        return dynamicQrOutcome(false, ['httpCode' => 403, 'errorCode' => 'EMAIL_NOT_CONFIRMED', 'message' => 'Changing dynamic codes needs an active account with a confirmed email address.']);
    }

    // 🚦 3. Switched on.
    if (!dynamicQrMasterSwitchOn()) {
        return dynamicQrOutcome(false, ['httpCode' => 503, 'errorCode' => 'DYNAMIC_QR_UNAVAILABLE', 'message' => 'Dynamic codes cannot be changed just now. Please try again later.']);
    }

    // 🔒 4. Everything else under the lock.
    if (!acquireDynamicCodeLimitLock($callerUserId)) {
        return dynamicQrOutcome(false, ['httpCode' => 429, 'errorCode' => 'DYNAMIC_LOCK_BUSY', 'message' => 'Another request for your account is being dealt with. Please try again in a moment.'], [], CUERCODE_DYNAMIC_LOCK_RETRY_AFTER_SECONDS);
    }

    $outcome = null;
    try {
        $outcome = dynamicQrApplyChange($callerUserId, (int) $row['redirectID'], $body, dynamicQrGo2Options($options));
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code change failed', 'redirectID: ' . (int) $row['redirectID'] . ' | ' . get_class($e));
        }
        $outcome = dynamicQrOutcome(false, ['httpCode' => 500, 'errorCode' => 'UPDATE_FAILED', 'message' => 'The dynamic code could not be changed. Please try again.']);
    } finally {
        releaseDynamicCodeLimitLock($callerUserId);
    }

    return $outcome;
}

/**
 * 👤 The owner of a row as a number (null when it has none).
 *
 * @param array<string, mixed> $row
 * @return int|null
 */
function dynamicQrOwnerOf(array $row): ?int
{
    if (!isset($row['ownerUserID'])) {
        return null;
    }

    return (int) $row['ownerUserID'];
}

/**
 * ✏️ Step 4 of a change, called ONLY while the per-person lock is held.
 *
 * @param int                  $callerUserId
 * @param int                  $redirectId
 * @param array<string, mixed> $body
 * @param array<string, mixed> $go2Options
 * @return array Outcome
 */
function dynamicQrApplyChange(int $callerUserId, int $redirectId, array $body, #[\SensitiveParameter] array $go2Options): array
{
    // 🔁 Read again under the lock (it may have been suspended meanwhile).
    $row = fetchRedirectRowById($redirectId);
    if ($row === null || dynamicQrOwnerOf($row) !== $callerUserId) {
        return dynamicQrOutcome(false, ['httpCode' => 404, 'errorCode' => 'NOT_FOUND', 'message' => 'QR code not found.']);
    }

    $siteUrl = '';
    if (function_exists('getConfig')) {
        $siteUrl = (string) getConfig('site_url', '');
    }
    $shortCode = '';
    if (isset($row['shortCode']) && is_string($row['shortCode'])) {
        $shortCode = $row['shortCode'];
    }

    $plan = dynamicQrPlanChange($row, $body, [
        'allowHttp'  => dynamicQrAllowHttp(),
        'offPageUrl' => dynamicQrUnavailablePageUrl($siteUrl, $shortCode),
    ]);
    if ($plan['ok'] !== true) {
        return dynamicQrOutcome(false, $plan);
    }
    if ($plan['nothing'] === true) {
        return dynamicQrOutcome(true, ['httpCode' => 200, 'errorCode' => '', 'message' => 'Nothing was different, so nothing was changed.'], [], 0, $row);
    }

    // 🛡️ The link-safety hook, on the destination about to go live (#168).
    if ($plan['checkDestination'] !== '') {
        $safety = dynamicQrCheckDestinationSafety($plan['checkDestination'], 'change');
        if ($safety['allowed'] !== true) {
            return dynamicQrOutcome(false, ['httpCode' => 422, 'errorCode' => 'DESTINATION_UNSAFE', 'message' => 'This destination was refused by the link-safety check, so nothing was changed.']);
        }
    }

    // 🌐 Go2My.Link FIRST, and only a confirmed change counts.
    if ($plan['remoteTarget'] !== null) {
        $settings = go2mylinkSettingsFromOptions($go2Options);
        if ($settings['problem'] !== '') {
            return dynamicQrOutcome(false, ['httpCode' => 503, 'errorCode' => 'DYNAMIC_QR_UNAVAILABLE', 'message' => CUERCODE_GO2MYLINK_MEMBER_UNAVAILABLE]);
        }

        $target    = (string) $plan['remoteTarget'];
        $result    = go2mylinkUpdateDestination($shortCode, $target, $go2Options);
        $confirmed = ($result['ok'] === true && $result['data']['destinationUrl'] === $target);

        if (!$confirmed && ($result['ok'] === true || $result['outcomeUnknown'] === true)) {
            // 🤷 The answer was lost or odd: look the link up to see where it points.
            $lookup = go2mylinkLookupLink($shortCode, $go2Options);
            if ($lookup['ok'] === true && $lookup['data']['destinationUrl'] === $target) {
                $confirmed = true;
            } elseif ($result['ok'] === true) {
                $result = go2mylinkFailure('bad_reply', true);
            }
        }

        if (!$confirmed) {
            dynamicQrNoteSyncError($redirectId, (string) $result['reason']);
            $facts = dynamicQrServiceErrorFacts($result, 'change');
            return dynamicQrOutcome(false, $facts, [], $facts['retryAfter']);
        }
    }

    // 💾 Then the row here (previousDestinationURL before destinationURL:
    //    MySQL and MariaDB apply SET left to right).
    $setClauses = [];
    $types      = '';
    $values     = [];
    if ($plan['destinationChanged'] === true) {
        $setClauses[] = '`previousDestinationURL` = `destinationURL`';
        $setClauses[] = '`destinationURL` = ?';
        $types       .= 's';
        $values[]     = $plan['newDestination'];
        $setClauses[] = '`destinationUpdatedAt` = NOW()';
        $setClauses[] = '`updatedByUserID` = ?';
        $types       .= 'i';
        $values[]     = $callerUserId;
    }
    if ($plan['activeChanged'] === true) {
        $setClauses[] = '`isActive` = ?';
        $types       .= 'i';
        $disabledReason = 'owner';
        if ($plan['newActive'] === true) {
            $values[]       = 1;
            $disabledReason = null;
        } else {
            $values[] = 0;
        }
        $setClauses[] = '`disabledReason` = ?';
        $types       .= 's';
        $values[]     = $disabledReason;
    }
    $setClauses[] = '`lastSyncError` = NULL';
    $setClauses[] = '`updatedAt` = NOW()';

    $types   .= 'ii';
    $values[] = $redirectId;
    $values[] = $callerUserId;

    $stmt = dbQuery(
        'UPDATE `tblQRRedirects` SET ' . implode(', ', $setClauses)
        . " WHERE `redirectID` = ? AND `ownerUserID` = ? AND `syncState` = 'active' AND `suspendedAt` IS NULL"
        . " AND (`disabledReason` IS NULL OR `disabledReason` = 'owner')",
        $types,
        $values
    );
    $stmt->close();

    $fresh = fetchRedirectRowById($redirectId);
    if ($fresh === null) {
        return dynamicQrOutcome(false, ['httpCode' => 500, 'errorCode' => 'UPDATE_FAILED', 'message' => 'The dynamic code could not be changed. Please try again.']);
    }

    // 📝 The change, with the old and new values (design section 1.4).
    if (function_exists('logActivity')) {
        $previousDestination = null;
        $newDestination      = null;
        if ($plan['destinationChanged'] === true) {
            $previousDestination = (string) $row['destinationURL'];
            $newDestination      = $plan['newDestination'];
        }
        logActivity(
            'redirect_destination_changed',
            'Changed dynamic code ' . (string) $row['redirectUUID'],
            'redirect',
            $redirectId,
            [
                'uuid'                => (string) $row['redirectUUID'],
                'destinationChanged'  => $plan['destinationChanged'],
                'activeChanged'       => $plan['activeChanged'],
                'previousDestination' => $previousDestination,
                'newDestination'      => $newDestination,
                'newIsActive'         => $plan['newActive'],
                'sentToGo2MyLink'     => ($plan['remoteTarget'] !== null),
            ]
        );
    }

    return dynamicQrOutcome(true, ['httpCode' => 200, 'errorCode' => '', 'message' => 'Dynamic redirect destination updated successfully.'], [], 0, $fresh);
}

// ==============================================================================
// 🖼️ dynamicQrImageGate() - may this person have this code's picture?
// ==============================================================================

/**
 * 🖼️ For a picture (tblQRCodes row) of type dynamic_url: only its owner, and
 *    only while the code is "active" (rule 5). Every other case is refused:
 *    no code linked to the picture or not the owner -> 404 NOT_FOUND; a
 *    suspended code -> 409 REDIRECT_SUSPENDED; switched off, waiting, held or
 *    failed -> 409 DYNAMIC_CODE_NOT_ACTIVE.
 *
 * @param int      $qrId         tblQRCodes.qrID of the picture
 * @param int|null $callerUserId
 * @return array{allowed: bool, httpCode: int, errorCode: string, message: string, ownerUserID: int|null}
 */
function dynamicQrImageGate(int $qrId, ?int $callerUserId): array
{
    $hidden = ['allowed' => false, 'httpCode' => 404, 'errorCode' => 'NOT_FOUND', 'message' => 'QR code not found. The identifier may be invalid or the code may have been deleted.', 'ownerUserID' => null];

    if (!function_exists('dbQuery')) {
        return $hidden;
    }

    try {
        $stmt = dbQuery('SELECT ' . CUERCODE_DYNAMIC_ROW_COLUMNS . ' FROM `tblQRRedirects` WHERE `qrID` = ? LIMIT 1', 'i', [$qrId]);
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        return $hidden;
    }

    if (!is_array($row) || !redirectIsWritableBy(dynamicQrOwnerOf($row), null, $callerUserId)) {
        return $hidden;
    }

    if (dynamicQrRowIsSuspended($row)) {
        return ['allowed' => false, 'httpCode' => 409, 'errorCode' => 'REDIRECT_SUSPENDED', 'message' => 'This dynamic code has been suspended, so its picture cannot be downloaded.', 'ownerUserID' => dynamicQrOwnerOf($row)];
    }

    if (!dynamicQrRowIsPrintable($row)) {
        return ['allowed' => false, 'httpCode' => 409, 'errorCode' => 'DYNAMIC_CODE_NOT_ACTIVE', 'message' => 'This dynamic code is switched off or not ready, so its picture cannot be downloaded.', 'ownerUserID' => dynamicQrOwnerOf($row)];
    }

    return ['allowed' => true, 'httpCode' => 200, 'errorCode' => '', 'message' => '', 'ownerUserID' => dynamicQrOwnerOf($row)];
}

// ==============================================================================
// 🧹 dynamicQrTidyPendingRows() - the cron job's tidy step
// ==============================================================================

/**
 * 🧹 Settle codes left "pending" (their create answer was lost) for more than
 *    10 minutes, at most 25 a run. For each, the short code is looked up on
 *    Go2My.Link (dynamicQrTidyActionFor()):
 *      - no such link: "failed" (nothing was made; it stops counting);
 *      - a link: settled by the safe create (go2mylinkCreateLinkSafely(),
 *        same code, same QR id - Go2My.Link refuses a second link for it, so
 *        nothing is made twice), which marks it active only on a confirmed
 *        short domain and otherwise "unconfirmed" for an admin;
 *      - no answer: left pending (still counted), tried on a later run.
 *    Nothing is sent when Go2My.Link is not set up, or when a command-line
 *    run has not declared which copy of the site it is (CUERCODE_SITE_COPY,
 *    #181 Low 4: skipped = 'copy_unknown').
 *
 * @param array<string, mixed> $options 'go2mylink' (tests), 'staleMinutes', 'batch', 'server' and 'commandLine' (tests)
 * @return array{ok: bool, skipped: string, looked: int, active: int, unconfirmed: int, failed: int, waiting: int}
 */
function dynamicQrTidyPendingRows(#[\SensitiveParameter] array $options = []): array
{
    $summary = ['ok' => false, 'skipped' => '', 'looked' => 0, 'active' => 0, 'unconfirmed' => 0, 'failed' => 0, 'waiting' => 0];

    if (!function_exists('dbQuery')) {
        return $summary;
    }

    $staleMinutes = CUERCODE_DYNAMIC_PENDING_STALE_MINUTES;
    if (isset($options['staleMinutes']) && is_int($options['staleMinutes']) && $options['staleMinutes'] >= 0) {
        $staleMinutes = $options['staleMinutes'];
    }
    $batch = CUERCODE_DYNAMIC_TIDY_BATCH;
    if (isset($options['batch']) && is_int($options['batch']) && $options['batch'] > 0) {
        $batch = $options['batch'];
    }

    // 🧱 Before upgrade 0007 there is nothing to tidy (and no column to read).
    try {
        $columnStmt = dbQuery(
            'SELECT COUNT(*) AS `found` FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
            'ss',
            ['tblQRRedirects', 'syncState']
        );
        $columnRow = $columnStmt->get_result()->fetch_assoc();
        $columnStmt->close();
    } catch (\Throwable $e) {
        return $summary;
    }
    if (!is_array($columnRow) || (int) $columnRow['found'] !== 1) {
        $summary['ok']      = true;
        $summary['skipped'] = 'upgrade_waiting';
        return $summary;
    }

    try {
        $stmt = dbQuery(
            'SELECT ' . CUERCODE_DYNAMIC_ROW_COLUMNS . " FROM `tblQRRedirects` WHERE `syncState` = 'pending' "
            . 'AND `updatedAt` <= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY `redirectID` LIMIT ?',
            'ii',
            [$staleMinutes, $batch]
        );
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Dynamic code tidy step could not read waiting codes', get_class($e));
        }
        return $summary;
    }

    $summary['ok'] = true;
    if ($rows === []) {
        return $summary;
    }

    // 🏷️ Round-six review, Low 4 (#181): on the command line (cron) nothing
    //    shows which copy of the site this is - alpha, beta and live share
    //    the same files and settings - so Go2My.Link is only called when the
    //    copy is declared (go2mylinkSiteCopyMarker()). An alpha or beta copy
    //    with the live QRmy.Link address is then refused by the settings
    //    check below (decision 0030).
    $server = $_SERVER;
    if (isset($options['server']) && is_array($options['server'])) {
        $server = $options['server'];
    }
    $commandLine = (PHP_SAPI === 'cli');
    if (isset($options['commandLine']) && is_bool($options['commandLine'])) {
        $commandLine = $options['commandLine'];
    }
    if ($commandLine && go2mylinkSiteCopyMarker($server) === '') {
        $summary['skipped'] = 'copy_unknown';
        $summary['waiting'] = count($rows);
        return $summary;
    }

    $go2Options = dynamicQrGo2Options($options);
    $settings   = go2mylinkSettingsFromOptions($go2Options);
    if ($settings['problem'] !== '') {
        $summary['skipped'] = (string) $settings['problem'];
        $summary['waiting'] = count($rows);
        return $summary;
    }

    foreach ($rows as $row) {
        $summary['looked']++;
        $redirectId = (int) $row['redirectID'];
        $shortCode  = (string) $row['shortCode'];

        $lookup = go2mylinkLookupLink($shortCode, $go2Options);
        $action = dynamicQrTidyActionFor($lookup);

        if ($action === 'wait') {
            dynamicQrNoteSyncError($redirectId, (string) $lookup['reason']);
            $summary['waiting']++;
            continue;
        }

        if ($action === 'failed') {
            dynamicQrSaveCreateState($redirectId, 'failed', 'not_found', $shortCode);
            $summary['failed']++;
            continue;
        }

        $result = go2mylinkCreateLinkSafely([
            'redirectID'     => $redirectId,
            'redirectUUID'   => (string) $row['redirectUUID'],
            'destinationUrl' => (string) $row['destinationURL'],
            'shortCode'      => $shortCode,
        ], $go2Options);
        $state  = go2mylinkSyncStateFor($result);
        $reason = '';
        if ($result['ok'] === true) {
            $shortCode = (string) $result['data']['shortCode'];
        } else {
            $reason = (string) $result['reason'];
        }

        if ($state === 'pending') {
            dynamicQrNoteSyncError($redirectId, $reason);
            $summary['waiting']++;
            continue;
        }

        dynamicQrSaveCreateState($redirectId, $state, $reason, $shortCode);
        if ($state === CUERCODE_DYNAMIC_QR_STATE_ACTIVE) {
            $summary['active']++;
        } elseif ($state === 'unconfirmed') {
            $summary['unconfirmed']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}

/**
 * 🗒️ The tidy step's summary as plain lines for the cron output (counts and
 *    reason words only - never a code, an address or a destination). PURE.
 *
 * @param array<string, mixed> $summary From dynamicQrTidyPendingRows()
 * @return array<int, string> Empty when there was nothing to say
 */
function dynamicQrTidyLines(array $summary): array
{
    $lines = [];

    if ($summary['ok'] !== true) {
        $lines[] = 'CueRCode dynamic codes: the tidy step could not read the waiting codes (database problem, see the error log).';
        return $lines;
    }

    if ($summary['skipped'] === 'copy_unknown') {
        $lines[] = 'CueRCode dynamic codes: ' . (int) $summary['waiting'] . ' waiting code(s) were not checked, because this run did not say which copy of the site it is.'
            . ' Add CUERCODE_SITE_COPY=live (or alpha, or beta) in front of the cron command.';
        return $lines;
    }

    if ($summary['skipped'] !== '' && $summary['skipped'] !== 'upgrade_waiting') {
        $lines[] = 'CueRCode dynamic codes: ' . (int) $summary['waiting'] . ' waiting code(s) were not checked (Go2My.Link: ' . $summary['skipped'] . ').';
        return $lines;
    }

    if ($summary['looked'] > 0) {
        $lines[] = 'CueRCode dynamic codes: checked ' . (int) $summary['looked'] . ' waiting code(s): '
            . (int) $summary['active'] . ' working, ' . (int) $summary['unconfirmed'] . ' held for an admin, '
            . (int) $summary['failed'] . ' not made (no longer counted), ' . (int) $summary['waiting'] . ' still waiting.';
    }

    return $lines;
}

// ==============================================================================
// 🔎 handleGetRedirectDestination() - GET /api/v1/qrcodes/{uuid}/destination
// ==============================================================================

/**
 * 🔎 GET /api/v1/qrcodes/{uuid}/destination - one code, for its owner only.
 *    Not yours or unknown: the same 404. {uuid} is the code's redirectUUID
 *    (the "dynamic.uuid" in the generate answer), not a picture's UUID.
 *
 * @param string $uuid
 * @param array  $auth The router's sign-in details
 * @return void (sends the response and exits)
 */
function handleGetRedirectDestination(string $uuid, array $auth): void
{
    if (!isValidRedirectUuid($uuid)) {
        errorResponse('Invalid redirect identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = null;
    if (function_exists('resolveUserIdFromAuth')) {
        $currentUserId = resolveUserIdFromAuth($auth);
    }
    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The dynamic-redirect service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $row = fetchRedirectRowByUuid($uuid);

    // 🔐 Checked HERE, on THIS row, right before it is shown.
    if ($row === null || !redirectIsWritableBy(dynamicQrOwnerOf($row), null, $currentUserId)) {
        errorResponse('QR code not found.', 404, 'NOT_FOUND');
        return;
    }

    successResponse(dynamicQrPublicView($row), 'Dynamic redirect destination retrieved successfully.');
}

// ==============================================================================
// ✏️ handlePatchRedirectDestination() - PATCH /api/v1/qrcodes/{uuid}/destination
// ==============================================================================

/**
 * ✏️ PATCH /api/v1/qrcodes/{uuid}/destination - change where a code goes
 *    ("destination_url") and/or switch it off or on ("active": true/false).
 *    The work is dynamicQrChangeForOwner(); this turns its answer into the
 *    response.
 *
 * @param string $uuid
 * @param array  $requestBody
 * @param array  $auth
 * @return void (sends the response and exits)
 */
function handlePatchRedirectDestination(string $uuid, array $requestBody, array $auth): void
{
    if (!isValidRedirectUuid($uuid)) {
        errorResponse('Invalid redirect identifier format.', 400, 'INVALID_UUID');
        return;
    }

    $currentUserId = null;
    if (function_exists('resolveUserIdFromAuth')) {
        $currentUserId = resolveUserIdFromAuth($auth);
    }
    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The dynamic-redirect service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $outcome = dynamicQrChangeForOwner($currentUserId, $uuid, $requestBody);

    if ($outcome['ok'] !== true) {
        dynamicQrSendErrorResponse($outcome);
        return;
    }

    successResponse(dynamicQrPublicView($outcome['row']), $outcome['message']);
}

/**
 * ❌ Send a failed flow answer as the error response (with Retry-After when
 *    it has one). Never called while the per-person lock is held.
 *
 * @param array $outcome From dynamicQrOutcome()
 * @return void (exits)
 */
function dynamicQrSendErrorResponse(array $outcome): void
{
    if ($outcome['retryAfter'] > 0 && !headers_sent()) {
        header('Retry-After: ' . (int) $outcome['retryAfter']);
    }

    errorResponse((string) $outcome['message'], (int) $outcome['httpCode'], (string) $outcome['errorCode'], $outcome['extra']);
}

// ==============================================================================
// 🔀 handleQrDestinationItem() - the router's entry for GET/PATCH
// ==============================================================================

/**
 * 🔀 GET and PATCH on "qrcodes/{uuid}/destination" share one route entry
 *    (the router matches by pattern), so this picks by method.
 *
 * @param string $uuid
 * @param array  $requestBody
 * @param array  $auth
 * @return void
 */
function handleQrDestinationItem(string $uuid, array $requestBody, array $auth): void
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
    } else {
        $method = 'GET';
    }

    if ($method === 'PATCH') {
        handlePatchRedirectDestination($uuid, $requestBody, $auth);
        return;
    }

    handleGetRedirectDestination($uuid, $auth);
}
