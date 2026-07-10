<?php
/**
 * ==============================================================================
 * 👤 CueRCode - Own-Account Management API (Epic E3 stage 2b, #52/#12/#14/#23/#24)
 * ==============================================================================
 *
 * Path: _functions/fn_api_account.php
 *
 * The browser account-DASHBOARD's server-side surface: profile (display name +
 * avatar upload), active-session listing/revocation, and self-service password
 * change. Unlike fn_api_accounts.php (register/login/verify/reset - all
 * PRE-authentication or self-contained), every route in THIS file operates on
 * an ALREADY-authenticated caller's OWN account only - there is no code path
 * anywhere below that accepts a client-supplied userID/target account.
 *
 * Endpoints handled:
 *   - PATCH  /api/v1/account/profile          -> handleAccountProfilePatch()
 *   - GET    /api/v1/account/sessions         -> handleAccountSessionsList()
 *   - DELETE /api/v1/account/sessions/{id}    -> handleAccountSessionDelete()
 *   - POST   /api/v1/account/change-password  -> handleAccountChangePassword()
 *
 * Split, like fn_auth.php / fn_api_accounts.php, into:
 *   1. "Engine" functions (updateUserDisplayName(), storeUserAvatarFile(),
 *      deleteUserAvatarFiles(), changeUserPassword(), listUserSessions(),
 *      revokeUserSession()) - framework-agnostic, no jsonResponse()/
 *      errorResponse() calls, plain scalar args, plain result arrays. Reused
 *      by BOTH the JSON API handlers below AND public_html/account/index.php's
 *      own no-JS <form> POST fallback handling, so the two intake paths
 *      (AJAX-JSON and a real browser form submit) share one code path per
 *      concern rather than diverging.
 *   2. HTTP handler functions (handleAccountProfilePatch(), etc.) - read the
 *      router's parsed JSON body / route params, validate shape, call the
 *      engine functions, and emit errorResponse()/successResponse().
 *
 * OWN-SCOPED SECURITY POSTURE (the whole point of this stage - see SECURITY.md):
 *   - Every function/handler resolves the caller's userID from the
 *     SERVER-SIDE session/API-key identity ONLY (resolveUserIdFromAuth(),
 *     fn_features.php) - there is no "user_id"/"target_user" field anywhere
 *     in these request bodies that has any effect.
 *   - Session revocation (revokeUserSession()) re-checks `userID = ?` in the
 *     SAME UPDATE statement that performs the revoke (not a separate
 *     SELECT-then-trust-it check) - a session ID that exists but belongs to
 *     a DIFFERENT user affects zero rows and is reported as 404, never 403
 *     (a 403 would confirm the session ID exists and belongs to someone else -
 *     the same non-disclosure convention fn_api_qrcodes.php established).
 *   - changeUserPassword() re-verifies the CALLER'S current password
 *     server-side before accepting a new one (never trusts "I'm already
 *     logged in" alone for this specific, high-value action) and revokes
 *     every OTHER session for the account on success (a stolen session
 *     cookie must not survive the legitimate owner changing their password).
 *   - Avatar upload never accepts a caller-supplied file PATH - only base64
 *     IMAGE BYTES (validated content, not a path) or (no-JS path) a genuine
 *     $_FILES upload validated by validateImageUpload(); the on-disk
 *     filename is always userID-derived, never caller-supplied.
 *   - SVG is explicitly REJECTED for avatars (unlike the existing QR logo
 *     upload pipeAvatarline, which does accept SVG) - see
 *     decodeAndValidateAvatarBase64()'s docblock for the specific rationale
 *     (an uploaded SVG avatar would later be served back through
 *     public_html/account/avatar/index.php with Content-Type: image/svg+xml;
 *     if a user ever navigates DIRECTLY to that URL rather than viewing it
 *     inside an <img> tag, the browser renders the SVG as a full document and
 *     WOULD execute any embedded <script>/event-handler payload in the site's
 *     own origin - a self-stored-XSS foothold this stage closes by simply
 *     not allowing that file type here, regardless of the general logo
 *     pipeline's more permissive stance for a very different rendering path).
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12/#14/#23/#24)
 * @see         fn_auth.php          - currentUser(), CUERCODE_SESSION_TOKEN_KEY, resolveAvatarUrl()
 * @see         fn_features.php      - resolveUserIdFromAuth()
 * @see         fn_password.php      - verifyPassword(), hashPassword(), validatePasswordPolicy()
 * @see         fn_qr_logo.php       - saveBase64LogoToTemp() (reused for avatar decode/validation)
 * @see         fn_validation.php    - validateImageUpload() (the no-JS $_FILES upload path)
 * @see         public_html/account/avatar/index.php - the session-gated avatar streaming endpoint
 * @see         SECURITY.md          - the IDOR/own-scope audit recorded for this stage
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

/** @var int Maximum display name length (matches tblUsers.displayName VARCHAR(100)). */
if (!defined('CUERCODE_ACCOUNT_DISPLAY_NAME_MAX_LEN')) {
    define('CUERCODE_ACCOUNT_DISPLAY_NAME_MAX_LEN', 100);
}

/** @var string 64-lowercase-hex session identifier shape (matches tblUserSessions.sessionID CHAR(64)). */
if (!defined('CUERCODE_ACCOUNT_SESSION_ID_REGEX')) {
    define('CUERCODE_ACCOUNT_SESSION_ID_REGEX', '/^[0-9a-f]{64}$/');
}

/** @var int Minimum new-password length fallback (matches fn_password.php's own default). */
if (!defined('CUERCODE_ACCOUNT_PASSWORD_MIN_LENGTH_FALLBACK')) {
    define('CUERCODE_ACCOUNT_PASSWORD_MIN_LENGTH_FALLBACK', 8);
}

// ==============================================================================
// 🎭 maskTokenForDisplay() - Pure display-masking helper (no I/O)
// ==============================================================================

/**
 * 🎭 Mask all but the trailing N characters of a token/identifier for display.
 *
 * Mirrors the existing convention in _includes/footer.php's debug panel
 * (F-019: never render a full session identifier verbatim in the UI) - reused
 * here for the account "active sessions" list so a shoulder-surfing screenshot
 * cannot capture a full, copy-pasteable session identifier.
 *
 * @param string $token         The raw token/identifier to mask.
 * @param int    $visibleSuffix How many trailing characters remain visible (default 8).
 *
 * @return string The masked string (e.g. '••••••••••••••••••••••••••••••••••••••••••••••••••••••a1b2c3d4').
 */
function maskTokenForDisplay(string $token, int $visibleSuffix = 8): string
{
    $length = strlen($token);

    if ($visibleSuffix < 0) {
        $visibleSuffix = 0;
    }

    if ($length <= $visibleSuffix) {
        return $token;
    }

    // 📌 PHP quirk guard: substr($token, -0) is NOT an empty string - a
    // negative-zero offset is treated as offset 0, which returns the WHOLE
    // remaining string, not nothing. Handled explicitly here so a clamped
    // $visibleSuffix of 0 (e.g. from a caller-supplied negative value) fully
    // masks the token instead of silently appending it back in full.
    if ($visibleSuffix === 0) {
        return str_repeat('•', $length);
    }

    return str_repeat('•', $length - $visibleSuffix) . substr($token, -$visibleSuffix);
}

// ==============================================================================
// 🖼️ Avatar storage helpers (no DB access - pure file I/O)
// ==============================================================================

/**
 * 🗑️ Delete any existing avatar file(s) for a user, regardless of extension.
 *
 * A user's avatar filename is always `{userID}.{ext}` - since the extension
 * can change between uploads (e.g. a PNG replaced by a JPEG), this removes
 * every `{userID}.*` match under `_uploads/avatars/` before a new file is
 * written, so no orphaned old-format file lingers on disk.
 *
 * @param int $userID The tblUsers.userID whose avatar file(s) to remove.
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/function.glob.php
 */
function deleteUserAvatarFiles(int $userID): void
{
    if (!defined('CUERCODE_UPLOADS_DIR')) {
        return;
    }

    $avatarsDir = CUERCODE_UPLOADS_DIR . 'avatars' . DIRECTORY_SEPARATOR;

    if (!is_dir($avatarsDir)) {
        return;
    }

    // 📌 glob() a specific numeric-prefixed pattern only - never a caller-
    // influenced fragment, so this can never be tricked into matching outside
    // this one user's own file(s).
    $matches = glob($avatarsDir . $userID . '.*');

    if ($matches === false) {
        return;
    }

    foreach ($matches as $existingFile) {
        if (is_file($existingFile)) {
            @unlink($existingFile);
        }
    }
}

/**
 * 💾 Move a validated, decoded avatar image into permanent per-user storage.
 *
 * The destination filename is ALWAYS `{userID}.{extension}` under
 * `_uploads/avatars/` - deterministic and never derived from caller input
 * (the extension is resolved server-side from the validated MIME sub-type,
 * never from a client-supplied filename).
 *
 * @param int    $userID         The owning tblUsers.userID.
 * @param string $sourceFilePath An existing, readable source file (a temp file
 *                                 already produced by saveBase64LogoToTemp() or
 *                                 a validated $_FILES['avatar']['tmp_name']).
 * @param string $extension      The destination file extension (e.g. 'png', 'jpg').
 *
 * @return string|null The relative avatarPath to store in tblUsers.avatarPath
 *                      (e.g. 'avatars/42.png'), or null on failure.
 */
function storeUserAvatarFile(int $userID, string $sourceFilePath, string $extension): ?string
{
    if (!defined('CUERCODE_UPLOADS_DIR')) {
        return null;
    }

    if (!is_file($sourceFilePath)) {
        return null;
    }

    $avatarsDir = CUERCODE_UPLOADS_DIR . 'avatars' . DIRECTORY_SEPARATOR;

    if (!is_dir($avatarsDir)) {
        $created = @mkdir($avatarsDir, 0755, true);
        if ($created === false) {
            if (function_exists('logError')) {
                logError('error', 'Failed to create avatars upload directory', 'Path: ' . $avatarsDir);
            }
            return null;
        }
    }

    // 🧹 Remove any prior avatar (possibly a different extension) BEFORE
    // writing the new one, so no stale file of the old format lingers.
    deleteUserAvatarFiles($userID);

    // 📌 Sanitise the extension to a small allowlist regardless of caller
    // input - defence in depth even though callers today only pass a
    // pre-validated value.
    $extension = strtolower(trim($extension));
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $extension = 'png';
    }

    $relativePath   = 'avatars' . '/' . $userID . '.' . $extension;
    $destinationAbs = $avatarsDir . $userID . '.' . $extension;

    // 📌 copy() rather than rename() - the source may be on a temp filesystem
    // mounted separately from _uploads/ on some hosts (rename() across
    // filesystems fails); copy() always works, and we clean up the source
    // ourselves afterward.
    $copied = @copy($sourceFilePath, $destinationAbs);

    if ($copied === false) {
        if (function_exists('logError')) {
            logError('error', 'Failed to store avatar file', 'Destination: ' . $destinationAbs);
        }
        return null;
    }

    @chmod($destinationAbs, 0644);
    @unlink($sourceFilePath);

    return $relativePath;
}

/**
 * 🔎 Decode + validate a base64 avatar data URI, rejecting SVG explicitly.
 *
 * Reuses saveBase64LogoToTemp() (fn_qr_logo.php) for the actual decode/size/
 * pixel-dimension validation (the "reuse the validated image path" instruction
 * from the E3 stage 2b scope) - this wrapper's ONLY additional job is to
 * reject `image/svg+xml` BEFORE that reused function ever runs, for the
 * self-stored-XSS reason documented in this file's header docblock.
 *
 * @param string $dataUri The raw 'data:image/...;base64,...' string from the request body.
 *
 * @return array{valid: bool, tempPath: string|null, extension: string|null, error: string|null}
 *
 * @see fn_qr_logo.php - saveBase64LogoToTemp()
 */
function decodeAndValidateAvatarBase64(string $dataUri): array
{
    $result = [
        'valid'     => false,
        'tempPath'  => null,
        'extension' => null,
        'error'     => null,
    ];

    if (preg_match('/^data:image\/([a-zA-Z0-9+.-]+);base64,/i', $dataUri, $matches) !== 1) {
        $result['error'] = 'Field "avatar_base64" is not a valid image data URI.';
        return $result;
    }

    $imageType = strtolower($matches[1]);

    // 🔐 Avatars explicitly EXCLUDE svg+xml - see this file's header docblock
    // for the self-stored-XSS rationale (the logo pipeline's SVG support does
    // not carry the same risk, since a logo is composited into a raster/SVG
    // QR canvas server-side, never served back as a directly-navigable
    // Content-Type: image/svg+xml document the way an avatar is).
    if ($imageType === 'svg+xml' || $imageType === 'svg') {
        $result['error'] = 'SVG images are not supported for avatars. Please upload a PNG, JPEG, GIF, or WebP image.';
        return $result;
    }

    if (!function_exists('saveBase64LogoToTemp')) {
        if (function_exists('loadQRGenerationFunctions')) {
            loadQRGenerationFunctions();
        }
    }

    if (!function_exists('saveBase64LogoToTemp')) {
        $result['error'] = 'The image upload service is temporarily unavailable. Please try again later.';
        return $result;
    }

    $tempPath = saveBase64LogoToTemp($dataUri);

    if ($tempPath === null) {
        $result['error'] = 'The uploaded image is invalid, too large, or exceeds the maximum pixel dimensions.';
        return $result;
    }

    $extensionMap = [
        'png'  => 'png',
        'jpeg' => 'jpg',
        'jpg'  => 'jpg',
        'gif'  => 'gif',
        'webp' => 'webp',
    ];

    if (isset($extensionMap[$imageType])) {
        $extension = $extensionMap[$imageType];
    } else {
        $extension = 'png';
    }

    $result['valid']     = true;
    $result['tempPath']  = $tempPath;
    $result['extension'] = $extension;

    return $result;
}

/**
 * 🔎 Validate a genuine $_FILES['avatar'] upload (the no-JS <form> path).
 *
 * Reuses validateImageUpload() (fn_validation.php - the same validator the
 * QR logo $_FILES intake path uses) for the actual upload-shape/size/MIME/
 * pixel-dimension checks, then applies the SAME avatar-specific SVG
 * rejection as decodeAndValidateAvatarBase64() (see that function's
 * docblock for the self-stored-XSS rationale) before accepting the file.
 *
 * @param array $file The raw $_FILES['avatar'] array.
 *
 * @return array{valid: bool, tmpPath: string|null, extension: string|null, error: string|null}
 *
 * @see fn_validation.php - validateImageUpload()
 */
function validateAvatarFileUpload(array $file): array
{
    $result = [
        'valid'     => false,
        'tmpPath'   => null,
        'extension' => null,
        'error'     => null,
    ];

    if (!function_exists('validateImageUpload')) {
        $result['error'] = 'The image upload service is temporarily unavailable. Please try again later.';
        return $result;
    }

    // 📏 A dedicated, smaller cap than the generic 5 MB default - an avatar
    // has no legitimate reason to be that large.
    $uploadValidation = validateImageUpload($file, 2 * 1024 * 1024);

    if (!$uploadValidation['valid']) {
        $result['error'] = (string) $uploadValidation['error'];
        return $result;
    }

    // 🔐 Re-detect the MIME type ourselves (validateImageUpload() already did
    // this internally but does not return it) so avatars can explicitly
    // reject svg+xml regardless of the general upload validator's more
    // permissive allowlist.
    $detectedMime = '';
    if (function_exists('mime_content_type')) {
        $detectedMime = (string) mime_content_type($file['tmp_name']);
    } elseif (class_exists('finfo')) {
        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($file['tmp_name']);
    }
    $detectedMime = strtolower(trim($detectedMime));

    if ($detectedMime === 'image/svg+xml') {
        $result['error'] = 'SVG images are not supported for avatars. Please upload a PNG, JPEG, GIF, or WebP image.';
        return $result;
    }

    $mimeExtensionMap = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (isset($mimeExtensionMap[$detectedMime])) {
        $extension = $mimeExtensionMap[$detectedMime];
    } else {
        $result['error'] = 'File type not allowed for avatars. Accepted types: PNG, JPEG, GIF, WebP.';
        return $result;
    }

    $result['valid']     = true;
    $result['tmpPath']   = $file['tmp_name'];
    $result['extension'] = $extension;

    return $result;
}

// ==============================================================================
// 🧩 Engine functions - framework-agnostic, DB-touching, no HTTP shaping
// ==============================================================================

/**
 * 📝 Update a user's display name.
 *
 * @param int    $userID      The caller's own tblUsers.userID.
 * @param string $displayName The new display name (already trimmed/length-checked by the caller).
 *
 * @return bool True on success, false on a database error.
 */
function updateUserDisplayName(int $userID, string $displayName): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'UPDATE `tblUsers` SET `displayName` = ? WHERE `userID` = ?',
            'si',
            [$displayName, $userID]
        );
        $stmt->close();
        return true;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Display name update failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * 🖼️ Persist a resolved avatarPath (or clear it) for a user.
 *
 * @param int         $userID     The caller's own tblUsers.userID.
 * @param string|null $avatarPath The relative avatarPath to store, or null to clear it.
 *
 * @return bool True on success, false on a database error.
 */
function setUserAvatarPath(int $userID, ?string $avatarPath): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'UPDATE `tblUsers` SET `avatarPath` = ? WHERE `userID` = ?',
            'si',
            [$avatarPath, $userID]
        );
        $stmt->close();
        return true;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Avatar path update failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * 📋 List a user's own currently-active sessions.
 *
 * Unconditionally scoped to `WHERE userID = ?` - there is no parameter that
 * widens this to another user's sessions. Only non-revoked, non-expired
 * sessions are returned (a revoked/expired session is not "active").
 *
 * @param int         $userID           The caller's own tblUsers.userID.
 * @param string|null $currentSessionId The CURRENT request's own session token
 *                                       (from $_SESSION[CUERCODE_SESSION_TOKEN_KEY]),
 *                                       used only to flag which row is "this device".
 *
 * @return array List of session rows: id, masked_id, ip, user_agent, created_at,
 *               last_seen_at, is_current. Empty array on DB error (fail-closed -
 *               never fabricates a session).
 */
function listUserSessions(int $userID, ?string $currentSessionId): array
{
    if (!function_exists('dbQuery')) {
        return [];
    }

    try {
        $stmt = dbQuery(
            'SELECT `sessionID`, `ipAddress`, `userAgent`, `createdAt`, `lastSeenAt` '
            . 'FROM `tblUserSessions` '
            . 'WHERE `userID` = ? AND `revokedAt` IS NULL AND `expiresAt` > NOW() '
            . 'ORDER BY `lastSeenAt` DESC',
            'i',
            [$userID]
        );
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'List user sessions failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return [];
    }

    $sessions = [];

    foreach ($rows as $row) {
        $sessionId = (string) $row['sessionID'];

        $sessions[] = [
            'id'           => $sessionId,
            'masked_id'    => maskTokenForDisplay($sessionId),
            'ip'           => $row['ipAddress'],
            'user_agent'   => $row['userAgent'],
            'created_at'   => (string) $row['createdAt'],
            'last_seen_at' => (string) $row['lastSeenAt'],
            'is_current'   => ($currentSessionId !== null && hash_equals($sessionId, $currentSessionId)),
        ];
    }

    return $sessions;
}

/**
 * 🚫 Revoke ONE of the caller's own sessions.
 *
 * The UPDATE's WHERE clause checks `userID = ?` in the SAME statement that
 * performs the revoke - a sessionID that exists but belongs to a DIFFERENT
 * user (or does not exist at all, or is already revoked) affects zero rows,
 * which the caller reports as 404 (never 403 - see this file's header
 * docblock for the non-disclosure rationale).
 *
 * @param int    $userID    The caller's own tblUsers.userID.
 * @param string $sessionId The tblUserSessions.sessionID to revoke (already
 *                            format-validated by the caller).
 *
 * @return bool True if a row was actually revoked, false if not found/not
 *              owned/already revoked, or on a database error.
 */
function revokeUserSession(int $userID, string $sessionId): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    try {
        $stmt = dbQuery(
            'UPDATE `tblUserSessions` SET `revokedAt` = NOW() '
            . 'WHERE `sessionID` = ? AND `userID` = ? AND `revokedAt` IS NULL',
            'si',
            [$sessionId, $userID]
        );
        $revoked = ($stmt->affected_rows === 1);
        $stmt->close();
        return $revoked;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Session revoke failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * 🔑 Change the caller's own password after verifying their CURRENT password.
 *
 * On success, every OTHER active session for the account is revoked (a
 * possibly-compromised session must not survive a deliberate password
 * change) - the CURRENT session (if its ID is supplied) is deliberately
 * preserved so the user is not immediately logged out of the device they
 * just used to change their password.
 *
 * @param int         $userID             The caller's own tblUsers.userID.
 * @param string      $currentPassword    The candidate CURRENT plaintext password.
 * @param string      $newPassword        The candidate NEW plaintext password.
 * @param string|null $keepSessionId      The CURRENT session's own token - preserved,
 *                                          not revoked. Null revokes ALL sessions.
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see fn_password.php - verifyPassword(), validatePasswordPolicy(), hashPassword()
 */
function changeUserPassword(int $userID, string $currentPassword, string $newPassword, ?string $keepSessionId): array
{
    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Password change is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    try {
        $stmt = dbQuery(
            'SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ? LIMIT 1',
            'i',
            [$userID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Change-password lookup failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'LOOKUP_FAILED',
            'message'   => 'Password could not be changed. Please try again later.',
            'data'      => [],
        ];
    }

    if ($row === null) {
        // 📌 The caller's own session resolved to a userID that no longer has
        // a tblUsers row (should not normally happen) - fail closed.
        return [
            'success'   => false,
            'httpCode'  => 404,
            'errorCode' => 'NOT_FOUND',
            'message'   => 'Account not found.',
            'data'      => [],
        ];
    }

    $storedHash = (string) $row['passwordHash'];

    if (!verifyPassword($currentPassword, $storedHash)) {
        return [
            'success'   => false,
            'httpCode'  => 401,
            'errorCode' => 'INVALID_CURRENT_PASSWORD',
            'message'   => 'Your current password is incorrect.',
            'data'      => [],
        ];
    }

    $minLength = CUERCODE_ACCOUNT_PASSWORD_MIN_LENGTH_FALLBACK;
    if (function_exists('getConfig')) {
        $minLength = (int) getConfig('password_min_length', $minLength);
    }

    $policy = validatePasswordPolicy($newPassword, $minLength);

    if (!$policy['valid']) {
        return [
            'success'   => false,
            'httpCode'  => 422,
            'errorCode' => 'WEAK_PASSWORD',
            'message'   => (string) $policy['error'],
            'data'      => [],
        ];
    }

    try {
        $newHash = hashPassword($newPassword);
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password hashing failed during change-password', $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'HASH_FAILED',
            'message'   => 'Password could not be changed. Please try again later.',
            'data'      => [],
        ];
    }

    try {
        $updateStmt = dbQuery(
            'UPDATE `tblUsers` SET `passwordHash` = ? WHERE `userID` = ?',
            'si',
            [$newHash, $userID]
        );
        $updateStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Password update failed during change-password', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'UPDATE_FAILED',
            'message'   => 'Password could not be changed. Please try again later.',
            'data'      => [],
        ];
    }

    // 🔐 Revoke every OTHER active session - the current one (if supplied) is
    // preserved so the caller stays logged in on the device they used.
    try {
        if ($keepSessionId !== null && $keepSessionId !== '') {
            $revokeStmt = dbQuery(
                'UPDATE `tblUserSessions` SET `revokedAt` = NOW() '
                . 'WHERE `userID` = ? AND `revokedAt` IS NULL AND `sessionID` != ?',
                'is',
                [$userID, $keepSessionId]
            );
        } else {
            $revokeStmt = dbQuery(
                'UPDATE `tblUserSessions` SET `revokedAt` = NOW() WHERE `userID` = ? AND `revokedAt` IS NULL',
                'i',
                [$userID]
            );
        }
        $revokeStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Other-session revoke failed after password change', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
    }

    if (function_exists('logActivity')) {
        logActivity('password_changed', 'User changed their own password', 'user', $userID, []);
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Your password has been changed. You have been signed out of all other devices.',
        'data'      => [],
    ];
}

// ==============================================================================
// 🌐 HTTP handler functions - JSON API router-facing
// ==============================================================================

// ------------------------------------------------------------------------
// ✏️ handleAccountProfilePatch() - PATCH /api/v1/account/profile
// ------------------------------------------------------------------------

/**
 * ✏️ Handle PATCH /api/v1/account/profile
 *
 * Own-account only: the caller's userID is resolved server-side via
 * resolveUserIdFromAuth() - there is no request field that targets any other
 * account. Accepts any combination of:
 *   - "display_name"  (string)       - new display name.
 *   - "avatar_base64" (string)       - a 'data:image/...;base64,...' data URI.
 *   - "remove_avatar" (bool true)    - clears the custom avatar (falls back to
 *                                       Gravatar/default placeholder).
 *
 * @param array $requestBody The parsed JSON request body.
 * @param array $auth        The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleAccountProfilePatch(array $requestBody, array $auth): void
{
    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    $displayNameProvided = array_key_exists('display_name', $requestBody);
    $avatarProvided       = isset($requestBody['avatar_base64']) && is_string($requestBody['avatar_base64']) && trim($requestBody['avatar_base64']) !== '';
    $removeAvatar         = isset($requestBody['remove_avatar']) && $requestBody['remove_avatar'] === true;

    if (!$displayNameProvided && !$avatarProvided && !$removeAvatar) {
        errorResponse(
            'No updatable fields were supplied. Provide "display_name", "avatar_base64", and/or "remove_avatar".',
            400,
            'NO_FIELDS'
        );
        return;
    }

    // ------------------------------------------------------------------
    // 📝 Display name
    // ------------------------------------------------------------------
    if ($displayNameProvided) {
        $rawDisplayName = $requestBody['display_name'];

        if ($rawDisplayName !== null && !is_string($rawDisplayName)) {
            errorResponse('Field "display_name" must be a string or null.', 400, 'INVALID_REQUEST');
            return;
        }

        $displayName = $rawDisplayName !== null ? trim($rawDisplayName) : '';

        if (mb_strlen($displayName) > CUERCODE_ACCOUNT_DISPLAY_NAME_MAX_LEN) {
            errorResponse(
                'Field "display_name" is too long. Maximum ' . CUERCODE_ACCOUNT_DISPLAY_NAME_MAX_LEN . ' characters.',
                400,
                'INVALID_REQUEST'
            );
            return;
        }

        if (!updateUserDisplayName($currentUserId, $displayName)) {
            errorResponse('Your display name could not be updated. Please try again.', 500, 'UPDATE_FAILED');
            return;
        }
    }

    // ------------------------------------------------------------------
    // 🖼️ Avatar (upload takes precedence over an explicit remove flag if
    // both are somehow sent together).
    // ------------------------------------------------------------------
    if ($avatarProvided) {
        $decoded = decodeAndValidateAvatarBase64((string) $requestBody['avatar_base64']);

        if (!$decoded['valid']) {
            errorResponse((string) $decoded['error'], 422, 'INVALID_AVATAR');
            return;
        }

        $storedPath = storeUserAvatarFile($currentUserId, (string) $decoded['tempPath'], (string) $decoded['extension']);

        if ($storedPath === null) {
            errorResponse('Your avatar could not be saved. Please try again.', 500, 'AVATAR_SAVE_FAILED');
            return;
        }

        if (!setUserAvatarPath($currentUserId, $storedPath)) {
            errorResponse('Your avatar could not be saved. Please try again.', 500, 'AVATAR_SAVE_FAILED');
            return;
        }
    } elseif ($removeAvatar) {
        deleteUserAvatarFiles($currentUserId);

        if (!setUserAvatarPath($currentUserId, null)) {
            errorResponse('Your avatar could not be removed. Please try again.', 500, 'AVATAR_REMOVE_FAILED');
            return;
        }
    }

    if (function_exists('logActivity')) {
        logActivity(
            'profile_updated',
            'User updated their own profile',
            'user',
            $currentUserId,
            [
                'displayNameChanged' => $displayNameProvided,
                'avatarChanged'      => $avatarProvided,
                'avatarRemoved'      => $removeAvatar && !$avatarProvided,
            ]
        );
    }

    successResponse([], 'Profile updated successfully.');
}

// ------------------------------------------------------------------------
// 📋 handleAccountSessionsList() - GET /api/v1/account/sessions
// ------------------------------------------------------------------------

/**
 * 📋 Handle GET /api/v1/account/sessions
 *
 * Lists ONLY the caller's own active sessions - see listUserSessions().
 *
 * @param array $auth The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleAccountSessionsList(array $auth): void
{
    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
        $currentSessionId = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
    } else {
        $currentSessionId = null;
    }

    $sessions = listUserSessions($currentUserId, $currentSessionId);

    successResponse(['sessions' => $sessions], 'Sessions retrieved successfully.');
}

// ------------------------------------------------------------------------
// 🚫 handleAccountSessionDelete() - DELETE /api/v1/account/sessions/{id}
// ------------------------------------------------------------------------

/**
 * 🚫 Handle DELETE /api/v1/account/sessions/{id}
 *
 * Own-account only - see revokeUserSession()'s docblock for the IDOR
 * posture (a session ID that is not the caller's own is reported as 404).
 *
 * @param string $sessionId The tblUserSessions.sessionID route parameter.
 * @param array  $auth      The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleAccountSessionDelete(string $sessionId, array $auth): void
{
    if (preg_match(CUERCODE_ACCOUNT_SESSION_ID_REGEX, $sessionId) !== 1) {
        errorResponse('Invalid session identifier format.', 400, 'INVALID_REQUEST');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    $revoked = revokeUserSession($currentUserId, $sessionId);

    if (!$revoked) {
        // 🔐 Not found, not owned, or already revoked - all reported
        // identically as 404 (never 403 - see this file's header docblock).
        errorResponse('Session not found.', 404, 'NOT_FOUND');
        return;
    }

    if (function_exists('logActivity')) {
        logActivity('session_revoked', 'User revoked one of their own sessions', 'user_session', null, []);
    }

    successResponse([], 'Session revoked successfully.');
}

// ------------------------------------------------------------------------
// 🔑 handleAccountChangePassword() - POST /api/v1/account/change-password
// ------------------------------------------------------------------------

/**
 * 🔑 Handle POST /api/v1/account/change-password
 *
 * Own-account only. Requires "current_password" and "new_password" - see
 * changeUserPassword() for the full behaviour (current-password
 * re-verification, new-password policy check, other-session revocation).
 *
 * @param array $requestBody The parsed JSON request body.
 * @param array $auth        The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleAccountChangePassword(array $requestBody, array $auth): void
{
    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!isset($requestBody['current_password']) || !is_string($requestBody['current_password'])
        || !isset($requestBody['new_password']) || !is_string($requestBody['new_password'])
    ) {
        errorResponse('Both "current_password" and "new_password" fields are required.', 400, 'INVALID_REQUEST');
        return;
    }

    $currentPassword = $requestBody['current_password'];
    $newPassword     = $requestBody['new_password'];

    if ($currentPassword === '' || $newPassword === '') {
        errorResponse('Both "current_password" and "new_password" fields are required.', 400, 'INVALID_REQUEST');
        return;
    }

    if (mb_strlen($currentPassword) > CUERCODE_PASSWORD_MAX_LENGTH || mb_strlen($newPassword) > CUERCODE_PASSWORD_MAX_LENGTH) {
        errorResponse('Password field exceeds the maximum allowed length.', 400, 'INVALID_REQUEST');
        return;
    }

    if (isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
        $currentSessionId = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
    } else {
        $currentSessionId = null;
    }

    $result = changeUserPassword($currentUserId, $currentPassword, $newPassword, $currentSessionId);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode']);
        return;
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}
