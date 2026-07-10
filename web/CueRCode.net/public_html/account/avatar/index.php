<?php
/**
 * ==============================================================================
 * 🖼️ CueRCode - Own-Avatar Streaming Endpoint (Epic E3 stage 2b, #23)
 * ==============================================================================
 *
 * Path: public_html/account/avatar/index.php
 *
 * Streams the CURRENTLY AUTHENTICATED SESSION'S OWN avatar image file. This is
 * deliberately NOT part of the JSON API router (public_html/api/v1/) - it is a
 * plain GET-only image resource meant to be used directly as an <img src>,
 * which cannot attach the X-CSRF-Token header the JSON API's 'auth' => true
 * routes require. Reading one's OWN avatar has no state-changing side effect,
 * so a CSRF token is not a meaningful protection here (the worst a forged
 * cross-site request could achieve is re-fetching an image the requesting
 * browser could already display) - the PHP session cookie (HttpOnly,
 * SameSite=Lax) alone is the correct and sufficient gate for this resource.
 *
 * OWN-SCOPED SECURITY POSTURE:
 *   - The target user is ALWAYS resolved from currentUser() (the caller's own
 *     session) - there is no userID/uuid/path query parameter accepted here
 *     that could select another user's avatar. This endpoint can NEVER
 *     disclose anyone else's uploaded avatar file.
 *   - avatarPath is a server-written database value (only ever set by
 *     fn_api_account.php's storeUserAvatarFile()/setUserAvatarPath()), never
 *     directly caller-controlled - but the resolved absolute path is still
 *     defensively verified to remain WITHIN the avatars upload directory via
 *     realpath() containment (mirrors fn_qr_logo.php's
 *     isPathWithinAllowedBase() convention) before the file is streamed, so a
 *     future bug that ever wrote a bad avatarPath value cannot be leveraged
 *     into an arbitrary-file-read.
 *   - Not logged in, no avatar set, or the file is missing all degrade to a
 *     302 redirect to the local default placeholder image - never a hard
 *     error, since this is only ever loaded as a passive <img src>.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#23)
 * @see         _functions/fn_auth.php         - currentUser(), resolveAvatarUrl()
 * @see         _functions/fn_api_account.php  - storeUserAvatarFile(), setUserAvatarPath()
 * @see         https://www.php.net/manual/en/function.realpath.php
 * @see         https://www.php.net/manual/en/function.readfile.php
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// 🔀 defaultAvatarRedirect() - Fall through to the local placeholder image
// ==============================================================================

/**
 * 🔀 Redirect the browser to the local default avatar placeholder.
 *
 * A 302 (not 301) is used deliberately - this is a per-request fallback
 * decision (no avatar today does not mean no avatar forever), never a
 * permanent redirect a browser/CDN should cache indefinitely.
 *
 * @return never
 */
function defaultAvatarRedirect(): never
{
    header('Location: /assets/img/branding/avatar-default.svg', true, 302);
    exit;
}

// ==============================================================================
// 👤 Resolve the caller's OWN account and avatar path
// ==============================================================================

$user = function_exists('currentUser') ? currentUser() : null;

if ($user === null) {
    defaultAvatarRedirect();
}

$avatarPath = $user['avatarPath'] ?? null;

if (!is_string($avatarPath) || trim($avatarPath) === '') {
    defaultAvatarRedirect();
}

// ==============================================================================
// 🔒 Resolve + contain the absolute file path
// ==============================================================================

if (!defined('CUERCODE_UPLOADS_DIR')) {
    defaultAvatarRedirect();
}

$avatarsBaseReal = realpath(CUERCODE_UPLOADS_DIR . 'avatars');

if ($avatarsBaseReal === false) {
    defaultAvatarRedirect();
}

$candidateAbs = CUERCODE_UPLOADS_DIR . $avatarPath;
$candidateReal = realpath($candidateAbs);

if ($candidateReal === false) {
    defaultAvatarRedirect();
}

// 🛡️ Containment check: the resolved real path must live INSIDE the
// avatars base directory (defence in depth against a future bad avatarPath
// value ever containing '../' segments).
// @see https://www.php.net/manual/en/function.str-starts-with.php
if (!str_starts_with($candidateReal, $avatarsBaseReal . DIRECTORY_SEPARATOR)) {
    if (function_exists('logError')) {
        logError('warning', 'Avatar path containment check failed', 'userID: ' . $user['userID']);
    }
    defaultAvatarRedirect();
}

if (!is_file($candidateReal)) {
    defaultAvatarRedirect();
}

// ==============================================================================
// 📤 Stream the file
// ==============================================================================

$extension = strtolower((string) pathinfo($candidateReal, PATHINFO_EXTENSION));

$mimeMap = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

if (isset($mimeMap[$extension])) {
    $mimeType = $mimeMap[$extension];
} else {
    $mimeType = 'application/octet-stream';
}

$fileSize = filesize($candidateReal);

if (!headers_sent()) {
    header('Content-Type: ' . $mimeType);
    if ($fileSize !== false) {
        header('Content-Length: ' . $fileSize);
    }
    // 📌 Short, PRIVATE caching only - this is one specific user's own
    // avatar, never a shared/public cache entry.
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
}

readfile($candidateReal);
exit;
