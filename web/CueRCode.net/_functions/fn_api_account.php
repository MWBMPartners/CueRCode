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
 *   - GET    /api/v1/account/export           -> handleAccountDataExport()   (#37/#52, GDPR/CCPA export)
 *   - POST   /api/v1/account/erase            -> handleAccountErase()       (#37/#52, GDPR/CCPA erasure)
 *
 * GDPR/CCPA DATA-SUBJECT RIGHTS (#37, epic #52 - see DEV_NOTES.md/SECURITY.md
 * for the full write-up and privacy.php Section 11 for the public-facing
 * rights disclosure):
 *   - **collectAccountExportData()/handleAccountDataExport()**: a complete,
 *     machine-readable JSON export of ONLY the caller's own data - profile,
 *     saved QR codes (metadata + payload, never the rendered image blob),
 *     sessions (masked identifier + ip/ua/times, never a replayable raw
 *     session token), subscriptions + payment ledger (own rows only), linked
 *     SSO providers (provider name + masked subject + linkedAt, never
 *     tokensEncrypted), and API keys (prefix/name/scopes only, reusing
 *     listApiKeysForUser() - never the secret or its hash). Session-only
 *     (accountManagementAllowedFor()) and unconditionally scoped to the
 *     caller's own resolved userID - there is no field anywhere in this
 *     request that can target another account.
 *   - **eraseUserAccount()/handleAccountErase()**: erases the caller's OWN
 *     account by ANONYMIZATION, never a hard DELETE of the tblUsers row -
 *     see eraseUserAccount()'s own docblock for the exact anonymize-vs-
 *     retain scope. Requires the caller's CURRENT password (verified
 *     timing-safely via verifyPassword() - wrong password -> 401
 *     INVALID_PASSWORD) and an explicit `confirm: true` in the request body
 *     (missing/false -> 400 CONFIRMATION_REQUIRED) on top of the same
 *     session-only guard. A single MySQL transaction wraps every mutating
 *     statement so the anonymization is atomic - either all of it lands, or
 *     none of it does.
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
 *   - **Session-only management (F-026, #52/#54)**: all four HTTP handlers
 *     below (handleAccountProfilePatch(), handleAccountSessionsList(),
 *     handleAccountSessionDelete(), handleAccountChangePassword()) require
 *     an INTERACTIVE (CSRF/session) caller - accountManagementAllowedFor()
 *     is the FIRST statement in every one of them, refusing an
 *     X-API-Key-authenticated request (however broadly scoped) with 403
 *     ACCOUNT_MANAGEMENT_FORBIDDEN. API keys exist for programmatic QR
 *     operations only; account control (profile/sessions/password) is
 *     reserved for the user's own interactive session/token, mirroring
 *     fn_api_keys.php's identical apiKeyManagementAllowedFor() guard - see
 *     accountManagementAllowedFor()'s own docblock for the full rationale.
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

/**
 * @var string Domain used for the non-reversible placeholder email address an
 * erased account's tblUsers.email is replaced with (#37/#52 - see
 * buildErasedAccountEmail()). '.invalid' is the IANA-reserved TLD for exactly
 * this purpose (RFC 2606) - it can never resolve or be re-registered, so a
 * placeholder address built from it can never accidentally collide with, or
 * later be reassigned to, a genuine mailbox.
 * @see https://www.rfc-editor.org/rfc/rfc2606
 */
if (!defined('CUERCODE_ACCOUNT_ERASED_EMAIL_DOMAIN')) {
    define('CUERCODE_ACCOUNT_ERASED_EMAIL_DOMAIN', 'deleted.invalid');
}

// ==============================================================================
// 🔒 accountManagementAllowedFor() - session-only management guard (F-026)
// ==============================================================================

/**
 * 🔒 Is this authentication context allowed to manage the account?
 *
 * **F-026 fix (#52/#54)**: account management - editing the profile
 * (display name/avatar), listing/revoking sessions, and changing the
 * password - is reserved for an INTERACTIVE (CSRF/session) caller ONLY.
 * Before this fix, every route in this file was wired `auth => true,
 * requiredScope => null` in the router with no additional guard inside the
 * handler, so ANY authenticated API key - even one scoped ONLY for
 * `generate`/`preview` QR operations - could edit the profile, enumerate
 * every active session (including their masked identifiers), revoke a
 * session out from under the legitimate owner, or drive the change-password
 * flow. That violates least privilege and was inconsistent with this exact
 * file's sibling, fn_api_keys.php, whose apiKeyManagementAllowedFor() has
 * required an interactive session since Epic E7 stage 1.
 *
 * Mirrors fn_api_keys.php's apiKeyManagementAllowedFor() EXACTLY - both now
 * delegate to the ONE shared primitive, isInteractiveSessionAuth()
 * (fn_api_auth.php), so the two guards can never silently drift apart.
 * An X-API-Key-authenticated caller - even one holding EVERY scope the
 * allowlist defines - is refused (403 ACCOUNT_MANAGEMENT_FORBIDDEN):
 * API keys exist for programmatic QR operations, NEVER for account
 * control. A native app (or any other programmatic client) manages the
 * account via the user's own interactive session/token, never via a
 * scoped API key.
 *
 * @param array $auth The router's authentication context (fn_api_auth.php).
 *
 * @return bool True only when $auth['method'] === 'csrf'.
 *
 * @see fn_api_auth.php - isInteractiveSessionAuth() (the shared primitive)
 * @see fn_api_keys.php - apiKeyManagementAllowedFor() (the identical pattern this mirrors)
 * @see SECURITY.md     - F-026
 */
function accountManagementAllowedFor(array $auth): bool
{
    // 📌 fn_api_auth.php is always loaded before this file by the router
    // (public_html/api/v1/index.php's $apiFunctionFiles order) - defensive
    // function_exists() fallback keeps this file independently includable
    // (e.g. by tests/test_account_ui.php, which never loads fn_api_auth.php).
    if (function_exists('isInteractiveSessionAuth')) {
        return isInteractiveSessionAuth($auth);
    }

    return isset($auth['method']) && $auth['method'] === 'csrf';
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
    // 🔐 F-026: account management is session-only - refuse an
    // API-key-authenticated caller BEFORE resolving/touching anything else.
    if (!accountManagementAllowedFor($auth)) {
        errorResponse(
            'Account management requires an interactive session; API keys cannot manage the account.',
            403,
            'ACCOUNT_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

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
    // 🔐 F-026: account management is session-only - refuse an
    // API-key-authenticated caller BEFORE resolving/touching anything else.
    if (!accountManagementAllowedFor($auth)) {
        errorResponse(
            'Account management requires an interactive session; API keys cannot manage the account.',
            403,
            'ACCOUNT_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

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
    // 🔐 F-026: account management is session-only - refuse an
    // API-key-authenticated caller BEFORE resolving/touching anything else.
    if (!accountManagementAllowedFor($auth)) {
        errorResponse(
            'Account management requires an interactive session; API keys cannot manage the account.',
            403,
            'ACCOUNT_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

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
    // 🔐 F-026: account management is session-only - refuse an
    // API-key-authenticated caller BEFORE resolving/touching anything else.
    if (!accountManagementAllowedFor($auth)) {
        errorResponse(
            'Account management requires an interactive session; API keys cannot manage the account.',
            403,
            'ACCOUNT_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

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

// ==============================================================================
// 📦 GDPR/CCPA data-subject rights (#37, epic #52) - export + erasure
// ==============================================================================

// ------------------------------------------------------------------------
// 📤 collectAccountExportData() - gather the caller's OWN data, own-scoped
// ------------------------------------------------------------------------

/**
 * 📤 Collect a complete, machine-readable snapshot of ONE user's own data.
 *
 * Every query below is unconditionally scoped to `WHERE userID = ?` (or, for
 * saved-code payments, to a subscriptionID that itself was just fetched
 * scoped to that same userID) - there is no parameter here that could ever
 * widen the result to another account. Deliberately excludes anything the
 * account-management own-scope security posture treats as a secret:
 *   - Sessions: only maskTokenForDisplay()'d identifiers (via
 *     listUserSessions()) - never the raw, replayable session token.
 *   - Linked SSO providers: only the provider name + masked subject +
 *     linkedAt (via listLinkedOAuthProvidersForUser()) - NEVER
 *     tblUserOAuth.tokensEncrypted.
 *   - API keys: only prefix/name/scopes/rate-limits/timestamps (via
 *     listApiKeysForUser(), which never SELECTs apiKeyHash in the first
 *     place - see that function's own docblock).
 *   - Saved QR codes: the payload/customisation/metadata are included, but
 *     NOT qrFilePath (an internal server filesystem path) and not the
 *     rendered image bytes themselves - the payload is enough to
 *     regenerate an identical image on request.
 *
 * @param int $userID The caller's own tblUsers.userID (already resolved
 *                     server-side by the handler - never a client-supplied value).
 *
 * @return array{
 *   exported_at: string,
 *   profile: array|null,
 *   saved_qr_codes: array,
 *   sessions: array,
 *   subscriptions: array,
 *   linked_sso_providers: array,
 *   api_keys: array
 * }
 *
 * @see handleAccountDataExport() - the HTTP handler that calls this
 * @see fn_oauth.php   - listLinkedOAuthProvidersForUser()
 * @see fn_api_keys.php - listApiKeysForUser()
 */
function collectAccountExportData(int $userID): array
{
    $export = [
        // 📌 gmdate('c')-equivalent (ISO 8601, UTC) - matches the rest of this
        // codebase's response 'timestamp' convention (fn_response.php).
        'exported_at'          => gmdate('Y-m-d\TH:i:s+00:00'),
        'profile'              => null,
        'saved_qr_codes'       => [],
        'sessions'             => [],
        'subscriptions'        => [],
        'linked_sso_providers' => [],
        'api_keys'             => [],
    ];

    if (!function_exists('dbQuery')) {
        return $export;
    }

    // ------------------------------------------------------------------
    // 👤 Profile
    // ------------------------------------------------------------------
    try {
        $stmt = dbQuery(
            'SELECT `userID`, `email`, `emailVerifiedAt`, `displayName`, `firstName`, `lastName`, '
            . '`avatarPath`, `status`, `lastLoginAt`, `createdAt`, `updatedAt` '
            . 'FROM `tblUsers` WHERE `userID` = ? LIMIT 1',
            'i',
            [$userID]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            $hasAvatar = ($row['avatarPath'] !== null && trim((string) $row['avatarPath']) !== '');

            $export['profile'] = [
                'email'             => (string) $row['email'],
                'email_verified_at' => $row['emailVerifiedAt'],
                'display_name'      => $row['displayName'],
                'first_name'        => $row['firstName'],
                'last_name'         => $row['lastName'],
                // 📌 A boolean flag, never the raw file path/bytes - the
                // avatar image itself is fetched separately via the
                // session-gated /account/avatar/ endpoint if wanted.
                'has_custom_avatar' => $hasAvatar,
                'status'            => (string) $row['status'],
                'last_login_at'     => $row['lastLoginAt'],
                'created_at'        => (string) $row['createdAt'],
                'updated_at'        => (string) $row['updatedAt'],
            ];
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Account export: profile lookup failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 📚 Saved QR codes (metadata + payload, NEVER the rendered image blob)
    // ------------------------------------------------------------------
    try {
        $stmt = dbQuery(
            'SELECT q.`qrUUID`, t.`typeName`, q.`qrLabel`, q.`qrPayload`, q.`qrInputData`, '
            . 'q.`qrOptions`, q.`qrOutputFormat`, q.`isPublic`, q.`downloadCount`, '
            . 'q.`createdAt`, q.`updatedAt` '
            . 'FROM `tblQRCodes` q '
            . 'LEFT JOIN `tblQRCodeTypes` t ON q.`qrTypeID` = t.`typeID` '
            . 'WHERE q.`userID` = ? AND q.`deletedAt` IS NULL '
            . 'ORDER BY q.`createdAt` ASC',
            'i',
            [$userID]
        );
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $inputData = null;
            if ($row['qrInputData'] !== null) {
                $decodedInput = json_decode((string) $row['qrInputData'], true);
                if (is_array($decodedInput)) {
                    $inputData = $decodedInput;
                }
            }

            $customization = null;
            if ($row['qrOptions'] !== null) {
                $decodedOptions = json_decode((string) $row['qrOptions'], true);
                if (is_array($decodedOptions)) {
                    $customization = $decodedOptions;
                }
            }

            $export['saved_qr_codes'][] = [
                'uuid'           => (string) $row['qrUUID'],
                'type'           => $row['typeName'] !== null ? (string) $row['typeName'] : null,
                'label'          => $row['qrLabel'],
                'payload'        => (string) $row['qrPayload'],
                'input_data'     => $inputData,
                'customization'  => $customization,
                'format'         => (string) $row['qrOutputFormat'],
                'is_public'      => (bool) $row['isPublic'],
                'download_count' => (int) $row['downloadCount'],
                'created_at'     => (string) $row['createdAt'],
                'updated_at'     => (string) $row['updatedAt'],
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Account export: saved QR codes lookup failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 🎫 Sessions - masked identifier + ip/ua/times ONLY, never a raw,
    // replayable session token (reuses listUserSessions(), then strips its
    // 'id'/'is_current' fields - this export is a data snapshot, not a
    // session-management surface).
    // ------------------------------------------------------------------
    if (function_exists('listUserSessions')) {
        foreach (listUserSessions($userID, null) as $sessionRow) {
            $export['sessions'][] = [
                'masked_id'    => $sessionRow['masked_id'],
                'ip'           => $sessionRow['ip'],
                'user_agent'   => $sessionRow['user_agent'],
                'created_at'   => $sessionRow['created_at'],
                'last_seen_at' => $sessionRow['last_seen_at'],
            ];
        }
    }

    // ------------------------------------------------------------------
    // 💳 Subscriptions + their payment ledger (Epic E4) - own rows only.
    // ------------------------------------------------------------------
    try {
        $stmt = dbQuery(
            'SELECT s.`subscriptionID`, p.`planKey`, p.`planName`, s.`status`, '
            . 's.`currentPeriodStart`, s.`currentPeriodEnd`, s.`provider`, '
            . 's.`providerSubscriptionRef`, s.`createdAt`, s.`updatedAt` '
            . 'FROM `tblSubscriptions` s '
            . 'LEFT JOIN `tblSubscriptionPlans` p ON s.`planID` = p.`planID` '
            . 'WHERE s.`userID` = ? '
            . 'ORDER BY s.`createdAt` ASC',
            'i',
            [$userID]
        );
        $result = $stmt->get_result();
        $subscriptionRows = [];
        while ($row = $result->fetch_assoc()) {
            $subscriptionRows[] = $row;
        }
        $stmt->close();

        foreach ($subscriptionRows as $subRow) {
            $subscriptionId = (int) $subRow['subscriptionID'];
            $payments       = [];

            try {
                $payStmt = dbQuery(
                    'SELECT `provider`, `providerTxnRef`, `amount`, `currency`, `status`, `createdAt` '
                    . 'FROM `tblPayments` WHERE `subscriptionID` = ? ORDER BY `createdAt` ASC',
                    'i',
                    [$subscriptionId]
                );
                $payResult = $payStmt->get_result();
                while ($payRow = $payResult->fetch_assoc()) {
                    $payments[] = [
                        'provider'         => (string) $payRow['provider'],
                        'provider_txn_ref' => (string) $payRow['providerTxnRef'],
                        'amount'           => (string) $payRow['amount'],
                        'currency'         => (string) $payRow['currency'],
                        'status'           => (string) $payRow['status'],
                        'created_at'       => (string) $payRow['createdAt'],
                    ];
                }
                $payStmt->close();
            } catch (\Throwable $e) {
                if (function_exists('logError')) {
                    logError('error', 'Account export: payments lookup failed', 'subscriptionID: ' . $subscriptionId . ' | ' . $e->getMessage());
                }
            }

            $export['subscriptions'][] = [
                'plan_key'                  => $subRow['planKey'],
                'plan_name'                 => $subRow['planName'],
                'status'                    => (string) $subRow['status'],
                'current_period_start'      => $subRow['currentPeriodStart'],
                'current_period_end'        => $subRow['currentPeriodEnd'],
                'provider'                  => $subRow['provider'],
                'provider_subscription_ref' => $subRow['providerSubscriptionRef'],
                'created_at'                => (string) $subRow['createdAt'],
                'updated_at'                => (string) $subRow['updatedAt'],
                'payments'                  => $payments,
            ];
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Account export: subscriptions lookup failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 🔗 Linked SSO providers - reuses listLinkedOAuthProvidersForUser()
    // (fn_oauth.php), which already masks the subject identifier and never
    // returns tokensEncrypted.
    // ------------------------------------------------------------------
    if (function_exists('listLinkedOAuthProvidersForUser')) {
        foreach (listLinkedOAuthProvidersForUser($userID) as $linkedProvider) {
            $export['linked_sso_providers'][] = [
                'provider'       => $linkedProvider['provider'],
                'display_name'   => $linkedProvider['display_name'],
                'masked_subject' => $linkedProvider['masked_subject'],
                'linked_at'      => $linkedProvider['linked_at'],
            ];
        }
    }

    // ------------------------------------------------------------------
    // 🔑 API keys - reuses listApiKeysForUser() (fn_api_keys.php), which
    // never SELECTs apiKeyHash and never returns the raw secret.
    // ------------------------------------------------------------------
    if (function_exists('listApiKeysForUser')) {
        $export['api_keys'] = listApiKeysForUser($userID);
    }

    return $export;
}

// ------------------------------------------------------------------------
// 🌐 handleAccountDataExport() - GET /api/v1/account/export
// ------------------------------------------------------------------------

/**
 * 🌐 Handle GET /api/v1/account/export
 *
 * Session-only (F-026 pattern) - see accountManagementAllowedFor(). Streams
 * the JSON body with a Content-Disposition: attachment header so a browser
 * (or a JS `fetch()` reading the response) downloads it as a file rather than
 * rendering it inline. Rate-limited via the router's normal per-route
 * 'rateLimit' => true (the same tblAPIRateLog mechanism as every other
 * account/* route - no bespoke limiter needed here).
 *
 * @param array $auth The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleAccountDataExport(array $auth): void
{
    // 🔐 F-026 pattern: account data export is session-only - refuse an
    // API-key-authenticated caller BEFORE touching anything else.
    if (!accountManagementAllowedFor($auth)) {
        errorResponse(
            'Account management requires an interactive session; API keys cannot manage the account.',
            403,
            'ACCOUNT_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The data-export service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $exportData = collectAccountExportData($currentUserId);

    if (function_exists('logActivity')) {
        // 📌 PII-free by design: only the userID (entityID) is logged, never
        // the email/display name/payloads that were just exported.
        logActivity('account_data_exported', 'User exported their own account data', 'user', $currentUserId, []);
    }

    // 📥 Content-Disposition: attachment - mirrors fn_api_download.php's
    // established convention for a caller-triggered file download.
    if (!headers_sent()) {
        header('Content-Disposition: attachment; filename="cuercode-data-export.json"');
    }

    successResponse($exportData, 'Your account data export is ready.');
}

// ------------------------------------------------------------------------
// 🗑️ eraseUserAccount() - own-account erasure by ANONYMIZATION
// ------------------------------------------------------------------------

/**
 * 🔤 Build the non-reversible placeholder email address an erased account's
 * tblUsers.email is replaced with.
 *
 * Deterministic per userID (not random) so a hard-purge job (see
 * DEV_NOTES.md) can still find/verify the right row by pattern if ever
 * needed, while remaining completely non-reversible - there is no way to
 * recover the original email address from this placeholder.
 *
 * @param int $userID The tblUsers.userID being erased.
 *
 * @return string e.g. 'deleted-42@deleted.invalid'
 */
function buildErasedAccountEmail(int $userID): string
{
    return 'deleted-' . $userID . '@' . CUERCODE_ACCOUNT_ERASED_EMAIL_DOMAIN;
}

/**
 * 🗑️ Erase the caller's OWN account by ANONYMIZATION (#37, epic #52).
 *
 * **ANONYMIZE, NOT HARD-DELETE** (owner/design decision - see DEV_NOTES.md
 * "Account erasure: anonymize now, hard-purge later" for the full model and
 * the separate owner-run hard-purge runbook):
 *   - tblUsers PII is cleared/replaced in place: `email` -> a non-reversible
 *     `deleted-<userID>@deleted.invalid` placeholder (buildErasedAccountEmail()),
 *     `displayName` -> 'Deleted user', `firstName`/`lastName` -> NULL,
 *     `avatarPath` -> NULL (and the avatar FILE is deleted from disk),
 *     `passwordHash` -> NULL (login is no longer possible for this row under
 *     ANY credential), `status` -> 'deleted', `erasedAt` -> NOW().
 *   - ALL sessions are revoked (tblUserSessions.revokedAt = NOW()).
 *   - ALL API keys are deactivated (tblAPIKeys.isActive = 0) - never deleted,
 *     so a future admin audit can still see how many keys the account had.
 *   - ALL linked SSO identities are removed (`DELETE FROM tblUserOAuth`) -
 *     these rows carry no independent audit value once the local account
 *     they point at is anonymized.
 *   - ALL saved QR codes are soft-deleted (tblQRCodes.deletedAt = NOW()) -
 *     the SAME reversible mechanism DELETE /api/v1/qrcodes/{uuid} already
 *     uses elsewhere in this codebase, not a hard delete.
 *   - tblSubscriptions/tblPayments rows are DELIBERATELY left untouched:
 *     they carry no PII of their own beyond the (now-anonymized) userID
 *     foreign key, and this codebase's existing FK posture already treats
 *     financial/audit records as retain-don't-cascade (tblPayments'
 *     `fk_payment_subscription` is `ON DELETE RESTRICT`, not `CASCADE` -
 *     see install.sql's own comment on that table). Anonymizing the parent
 *     tblUsers row in place - rather than deleting it - is what keeps this
 *     referential integrity intact automatically, with zero extra code.
 *
 * ATOMICITY: every mutating statement above runs inside ONE MySQL
 * transaction (`getDB()->begin_transaction()`/`commit()`/`rollback()`) - the
 * anonymization either lands completely, or (on any failure) not at all.
 *
 * SECURITY GATES (own-account-only, password-confirmed, confirmation-gated -
 * see this file's header docblock and SECURITY.md):
 *   - `$confirm` must be explicitly `true` - the caller (handleAccountErase())
 *     already required a literal `"confirm": true` in the request body
 *     before calling this; a missing/false value here still fails closed.
 *   - The CALLER'S current password is re-verified via `verifyPassword()`
 *     (timing-safe, same primitive `changeUserPassword()` uses) - a wrong
 *     password returns 401 INVALID_PASSWORD and NOTHING is mutated.
 *   - `$userID` is always resolved server-side from the caller's own session
 *     identity by `handleAccountErase()` - there is no "target user"
 *     parameter anywhere in this function or its caller that could ever
 *     erase a DIFFERENT account.
 *
 * @param int    $userID          The caller's own tblUsers.userID (already
 *                                resolved server-side - never client-supplied).
 * @param string $currentPassword The candidate CURRENT plaintext password.
 * @param bool   $confirm         Must be true (the caller already enforces
 *                                this at the request-shape level too).
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see handleAccountErase()   - the HTTP handler that calls this
 * @see buildErasedAccountEmail()
 * @see deleteUserAvatarFiles() - the avatar-file removal helper (this file, above)
 * @see https://www.php.net/manual/en/mysqli.begin-transaction.php
 */
function eraseUserAccount(int $userID, string $currentPassword, bool $confirm): array
{
    if (!$confirm) {
        return [
            'success'   => false,
            'httpCode'  => 400,
            'errorCode' => 'CONFIRMATION_REQUIRED',
            'message'   => 'You must explicitly confirm this irreversible action.',
            'data'      => [],
        ];
    }

    if (!function_exists('dbQuery') || !function_exists('getDB')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'Account erasure is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    // ------------------------------------------------------------------
    // 🔑 Step 1: verify the CALLER'S current password (timing-safe;
    // password_verify() is inherently constant-time relative to the hash).
    // A password-less (SSO-only) account has passwordHash === NULL/'' -
    // verifyPassword() treats an empty stored hash as "never matches", so
    // such an account cannot self-erase via THIS endpoint at all (an
    // acceptable, documented limitation - see DEV_NOTES.md).
    // ------------------------------------------------------------------
    try {
        $lookupStmt = dbQuery(
            "SELECT `passwordHash` FROM `tblUsers` WHERE `userID` = ? AND `status` != 'deleted' LIMIT 1",
            'i',
            [$userID]
        );
        $row = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Account erasure: password lookup failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'LOOKUP_FAILED',
            'message'   => 'Your account could not be erased. Please try again later.',
            'data'      => [],
        ];
    }

    if ($row === null) {
        // 📌 Already anonymized (or never existed) - since currentUser()
        // requires status = 'active' to authenticate at all, a genuinely
        // authenticated caller reaching this point should never hit this
        // branch in normal operation; kept as a defensive, non-mutating
        // fail-safe against an unexpected race.
        return [
            'success'   => false,
            'httpCode'  => 409,
            'errorCode' => 'ALREADY_ERASED',
            'message'   => 'This account has already been erased.',
            'data'      => [],
        ];
    }

    $storedHash = (string) $row['passwordHash'];

    if (!verifyPassword($currentPassword, $storedHash)) {
        return [
            'success'   => false,
            'httpCode'  => 401,
            'errorCode' => 'INVALID_PASSWORD',
            'message'   => 'Your current password is incorrect.',
            'data'      => [],
        ];
    }

    $anonymizedEmail = buildErasedAccountEmail($userID);

    // ------------------------------------------------------------------
    // 🔒 Step 2: anonymize + revoke + deactivate + unlink + soft-delete, all
    // inside ONE transaction. dbQuery() always reuses the SAME cached mysqli
    // connection for the lifetime of this request (see fn_api_qrcodes.php's
    // acquireSaveLimitLock() docblock for the detailed rationale this
    // mirrors), so every statement below genuinely participates in the same
    // transaction as $db->begin_transaction()/commit()/rollback().
    // ------------------------------------------------------------------
    $db                  = getDB();
    $transactionStarted  = false;
    $conflictDuringErase = false;

    try {
        $db->begin_transaction();
        $transactionStarted = true;

        // 👤 Anonymize tblUsers PII in place (re-checks status != 'deleted'
        // in the SAME statement - a concurrent double-erase affects 0 rows).
        $anonymizeStmt = dbQuery(
            "UPDATE `tblUsers` SET "
            . "`email` = ?, "
            . "`displayName` = 'Deleted user', "
            . "`firstName` = NULL, "
            . "`lastName` = NULL, "
            . "`avatarPath` = NULL, "
            . "`passwordHash` = NULL, "
            . "`status` = 'deleted', "
            . "`erasedAt` = NOW(), "
            . "`failedLoginCount` = 0, "
            . "`lockedUntil` = NULL "
            . "WHERE `userID` = ? AND `status` != 'deleted'",
            'si',
            [$anonymizedEmail, $userID]
        );
        $anonymized = ($anonymizeStmt->affected_rows === 1);
        $anonymizeStmt->close();

        if (!$anonymized) {
            $conflictDuringErase = true;
            throw new \RuntimeException('cuercode_erase_conflict');
        }

        // 🎫 Revoke ALL sessions (not merely the current one).
        $sessionsStmt = dbQuery(
            'UPDATE `tblUserSessions` SET `revokedAt` = NOW() WHERE `userID` = ? AND `revokedAt` IS NULL',
            'i',
            [$userID]
        );
        $sessionsStmt->close();

        // 🔑 Deactivate ALL API keys (soft-revoke, matching
        // revokeApiKeyForUser()'s own convention - never a hard delete).
        $keysStmt = dbQuery(
            'UPDATE `tblAPIKeys` SET `isActive` = 0 WHERE `userID` = ? AND `isActive` = 1',
            'i',
            [$userID]
        );
        $keysStmt->close();

        // 🔗 Unlink ALL SSO providers.
        $oauthStmt = dbQuery('DELETE FROM `tblUserOAuth` WHERE `userID` = ?', 'i', [$userID]);
        $oauthStmt->close();

        // 📚 Soft-delete ALL saved QR codes (same mechanism as
        // DELETE /api/v1/qrcodes/{uuid} - tblQRCodes.deletedAt).
        $qrCodesStmt = dbQuery(
            'UPDATE `tblQRCodes` SET `deletedAt` = NOW() WHERE `userID` = ? AND `deletedAt` IS NULL',
            'i',
            [$userID]
        );
        $qrCodesStmt->close();

        $db->commit();
    } catch (\Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                // 🛡️ Best-effort - a failed rollback cannot make the
                // already-caught failure any worse; logged below either way.
            }
        }

        if ($conflictDuringErase) {
            return [
                'success'   => false,
                'httpCode'  => 409,
                'errorCode' => 'ALREADY_ERASED',
                'message'   => 'This account has already been erased.',
                'data'      => [],
            ];
        }

        if (function_exists('logError')) {
            logError('error', 'Account erasure transaction failed', 'userID: ' . $userID . ' | ' . $e->getMessage());
        }

        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'ERASE_FAILED',
            'message'   => 'Your account could not be erased. No changes were made - please try again or contact support.',
            'data'      => [],
        ];
    }

    // ------------------------------------------------------------------
    // 🖼️ Step 3: delete the avatar FILE from disk (file I/O only - never
    // part of the DB transaction above, best-effort/non-fatal exactly like
    // every other avatar-removal call site in this file).
    // ------------------------------------------------------------------
    deleteUserAvatarFiles($userID);

    // ------------------------------------------------------------------
    // 📝 Step 4: PII-free audit log entry - userID (entityID) ONLY, never
    // the email/name that was just anonymized.
    // ------------------------------------------------------------------
    if (function_exists('logActivity')) {
        logActivity('account_erased', 'User erased their own account (anonymization)', 'user', $userID, []);
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'Your account has been erased. You have been signed out.',
        'data'      => [
            'erased_at' => gmdate('Y-m-d H:i:s'),
        ],
    ];
}

// ------------------------------------------------------------------------
// 🌐 handleAccountErase() - POST /api/v1/account/erase
// ------------------------------------------------------------------------

/**
 * 🌐 Handle POST /api/v1/account/erase
 *
 * Session-only (F-026 pattern), own-account-only (userID resolved server-
 * side, never from the request body), password-confirmed, and
 * confirmation-gated (`"confirm": true` required in the body on top of the
 * password). Ends the session on success via logoutCurrentUser().
 *
 * Request body: {"current_password": "...", "confirm": true}
 *
 * @param array $requestBody The parsed JSON request body.
 * @param array $auth        The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleAccountErase(array $requestBody, array $auth): void
{
    // 🔐 F-026 pattern: account erasure is session-only - refuse an
    // API-key-authenticated caller BEFORE touching anything else.
    if (!accountManagementAllowedFor($auth)) {
        errorResponse(
            'Account management requires an interactive session; API keys cannot manage the account.',
            403,
            'ACCOUNT_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!isset($requestBody['current_password']) || !is_string($requestBody['current_password']) || trim($requestBody['current_password']) === '') {
        errorResponse('Field "current_password" is required.', 400, 'INVALID_REQUEST');
        return;
    }

    $currentPassword = $requestBody['current_password'];

    if (mb_strlen($currentPassword) > CUERCODE_PASSWORD_MAX_LENGTH) {
        errorResponse('Password field exceeds the maximum allowed length.', 400, 'INVALID_REQUEST');
        return;
    }

    // 🔐 Confirmation gate: an explicit boolean `true` is required - anything
    // else (absent, "true" string, 1, etc.) fails closed.
    $confirm = isset($requestBody['confirm']) && $requestBody['confirm'] === true;

    if (!$confirm) {
        errorResponse(
            'You must explicitly confirm this irreversible action by sending "confirm": true.',
            400,
            'CONFIRMATION_REQUIRED'
        );
        return;
    }

    $result = eraseUserAccount($currentUserId, $currentPassword, $confirm);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode']);
        return;
    }

    // 🚪 End the session now that the account has been anonymized - every
    // session row was already revoked inside eraseUserAccount()'s
    // transaction, so this call's own UPDATE affects 0 rows; its real job
    // here is clearing THIS request's $_SESSION token and regenerating the
    // PHP session id so the browser is left in a genuinely logged-out state.
    if (function_exists('logoutCurrentUser')) {
        logoutCurrentUser();
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}
