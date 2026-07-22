<?php
/**
 * ==============================================================================
 * 🖼️ CueRCode - Saved QR Code Preview/Download Streamer (Epic E3 stage 2b, #24)
 * ==============================================================================
 *
 * Path: public_html/account/qr-preview/index.php
 *
 * Streams a saved QR code's image file for the account dashboard's library
 * grid (public_html/account/index.php). Like public_html/account/avatar/,
 * this is deliberately OUTSIDE the JSON API router: it is a plain,
 * side-effect-free GET meant to be used directly as an <img src> (thumbnail
 * preview) or a plain download <a href> - neither can attach the
 * X-CSRF-Token header the JSON API's 'auth' => true routes require, and a
 * read of an already-OWNED-or-public resource has no state-changing side
 * effect for CSRF to protect against.
 *
 * ?uuid={uuid}         Stream inline (used for the <img> thumbnail).
 * ?uuid={uuid}&download=1   Stream with Content-Disposition: attachment.
 *
 * OWN-SCOPED SECURITY POSTURE: applies the EXACT SAME ownership rule the
 * JSON API uses (qrCodeIsReadableBy() from fn_api_qrcodes.php - owner OR
 * public) via currentUser() (this endpoint's own session), never a
 * client-supplied identity. A UUID that does not exist, is soft-deleted, or
 * is neither owned nor public is reported as 404 - never 403, matching the
 * non-disclosure convention documented in fn_api_qrcodes.php's header.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#24)
 * @see         _functions/fn_api_qrcodes.php - fetchQrCodeRowByUuid(), qrCodeIsReadableBy(), isValidQrUuid()
 * @see         _functions/fn_auth.php        - currentUser()
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

$qrFunctionFile = CUERCODE_FUNCTIONS_DIR . 'fn_api_qrcodes.php';
if (file_exists($qrFunctionFile)) {
    require_once $qrFunctionFile;
}

// ==============================================================================
// ❌ notFound() - A single, indistinguishable 404 exit path
// ==============================================================================

/**
 * ❌ Emit a plain 404 and stop - deliberately not JSON (this is an image
 * endpoint), and deliberately identical whether the UUID is malformed,
 * missing, soft-deleted, or exists but belongs to someone else.
 *
 * @return never
 */
function qrPreviewNotFound(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found.';
    exit;
}

// ==============================================================================
// 🔎 Resolve + authorise
// ==============================================================================

if (isset($_GET['uuid']) && is_string($_GET['uuid'])) {
    $uuid = trim($_GET['uuid']);
} else {
    $uuid = '';
}

if (!function_exists('isValidQrUuid') || !isValidQrUuid($uuid)) {
    qrPreviewNotFound();
}

$callerUserId = null;
if (function_exists('currentUser')) {
    $currentUserRow = currentUser();
    if ($currentUserRow !== null) {
        $callerUserId = (int) $currentUserRow['userID'];
    }
}

if (!function_exists('fetchQrCodeRowByUuid')) {
    qrPreviewNotFound();
}

$row = fetchQrCodeRowByUuid($uuid);

if ($row === null) {
    qrPreviewNotFound();
}

$ownerUserId = $row['userID'] !== null ? (int) $row['userID'] : null;
$isPublic    = (bool) $row['isPublic'];

if (!function_exists('qrCodeIsReadableBy') || !qrCodeIsReadableBy($ownerUserId, $isPublic, $callerUserId)) {
    qrPreviewNotFound();
}

$filePath = (string) $row['qrFilePath'];

if ($filePath === '' || !is_file($filePath)) {
    qrPreviewNotFound();
}

// 🛡️ Containment check (defence in depth): the stored path must resolve
// within the qrcodes uploads base directory.
$qrcodesBaseReal = defined('CUERCODE_UPLOADS_DIR') ? realpath(CUERCODE_UPLOADS_DIR . 'qrcodes') : false;
$filePathReal    = realpath($filePath);

if ($qrcodesBaseReal === false || $filePathReal === false
    || !str_starts_with($filePathReal, $qrcodesBaseReal . DIRECTORY_SEPARATOR)
) {
    qrPreviewNotFound();
}

// ==============================================================================
// 📤 Stream
// ==============================================================================

$extension = strtolower((string) pathinfo($filePathReal, PATHINFO_EXTENSION));

$mimeMap = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'pdf'  => 'application/pdf',
];

if (isset($mimeMap[$extension])) {
    $mimeType = $mimeMap[$extension];
} else {
    $mimeType = 'application/octet-stream';
}

$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
$fileSize   = filesize($filePathReal);

if (!headers_sent()) {
    header('Content-Type: ' . $mimeType);
    if ($fileSize !== false) {
        header('Content-Length: ' . $fileSize);
    }
    if ($isDownload) {
        $downloadName = 'cuercode-' . $uuid . '.' . $extension;
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    } else {
        header('Content-Disposition: inline');
        header('Cache-Control: private, max-age=300');

        // 🛡️ Defence-in-depth (Epic E3, #52, SECURITY.md F-024): image/svg+xml
        // served INLINE is rendered by the browser (unlike an attachment
        // download), so a malicious SVG that ever slipped past the upload-
        // time logo screening (fn_validation.php validateImageUpload()'s
        // dangerous-pattern checks) could execute script when this endpoint
        // is used as an <img src>. NOTE (see SECURITY.md F-024's companion
        // finding for the full write-up): those checks cover the multipart
        // file-upload logo intake path only - the base64 logo_data intake
        // path (fn_qr_logo.php saveBase64LogoToTemp()) does NOT run the same
        // screening, so this CSP is not purely theoretical belt-and-braces.
        // The `sandbox` directive with no allow-tokens blocks script
        // execution, forms, popups, top-navigation, and plugins for the
        // rendered document, while the image itself still displays
        // normally. PNG/JPG/GIF/WEBP/PDF are inert raster/binary formats and
        // are NOT given this header - only image/svg+xml is scriptable when
        // inlined.
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/sandbox
        if ($mimeType === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
    }
    header('X-Content-Type-Options: nosniff');
}

readfile($filePathReal);
exit;
