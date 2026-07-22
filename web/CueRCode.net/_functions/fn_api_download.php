<?php
/**
 * ==============================================================================
 * 📥 CueRCode - API Download Handler
 * ==============================================================================
 *
 * Path: _functions/fn_api_download.php
 *
 * Provides the handler function for downloading previously generated QR codes
 * via their UUID. Looks up the QR code record in tblQRCodes, locates the
 * file on the filesystem, and streams it to the client with the correct
 * Content-Type and Content-Disposition headers.
 *
 * Endpoint:
 *   GET /api/v1/download/{uuid}
 *
 * The UUID is a version 4 UUID assigned during QR code generation. It maps
 * to a file in the _uploads/qrcodes/ directory.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/function.readfile.php
 * @see         https://www.php.net/manual/en/function.header.php
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
 * @see         https://datatracker.ietf.org/doc/html/rfc4122 UUID v4 specification
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
// 📥 handleDownload() - Handle QR code download by UUID
// ==============================================================================

/**
 * 📥 Handle a QR code download request
 *
 * Looks up a previously generated QR code by its UUID in the tblQRCodes
 * table, locates the corresponding file on the filesystem, and streams
 * it to the client with appropriate headers for browser download.
 *
 * If the file is not found on disk but the record exists in the database,
 * the function attempts to serve the QR code by re-reading the payload
 * from the database. However, this is a fallback; normally the file
 * should exist on disk.
 *
 * UUID format validation: must match the UUID v4 pattern
 * (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx) to prevent path traversal
 * or injection attacks.
 *
 * 🔐 OWNERSHIP HARDENING (Epic E3 stage 2a, #52/#24): this route already
 * required SOME authenticated identity ('auth' => true in the router table)
 * before this stage, but performed no ownership check at all - ANY
 * authenticated caller could download ANY UUID's file. Now that saved codes
 * carry real ownership (userID set via POST /api/v1/save), a code that HAS
 * been claimed by a specific user is only downloadable by that owner or by
 * anyone (when it is marked isPublic=1) - see qrCodeIsDownloadableBy()
 * (fn_api_qrcodes.php) for the exact rule, including why an UNCLAIMED code
 * (userID IS NULL - the pre-existing anonymous generate -> download flow)
 * deliberately remains downloadable by any authenticated caller: nobody's
 * saved-library privacy is at stake for a code nobody has claimed yet. A
 * non-public, claimed code requested by a non-owner is reported as 404 (the
 * same code as a genuinely unknown UUID) - never 403 - to avoid confirming
 * the UUID exists and belongs to someone else.
 *
 * @param string $uuid The UUID v4 identifier of the QR code to download
 * @param array  $auth The router's authentication context (fn_api_auth.php) -
 *                     used to resolve the caller's identity for the
 *                     ownership check above. Defaults to an empty array so
 *                     any (hypothetical) other caller of this function
 *                     without an $auth context still degrades to "no
 *                     resolved identity" rather than a fatal argument error.
 *
 * @return void (streams file to client and exits, or sends error response)
 *
 * @see https://www.php.net/manual/en/function.readfile.php
 * @see https://www.php.net/manual/en/function.filesize.php
 * @see https://www.php.net/manual/en/function.header.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
 * @see fn_api_qrcodes.php - qrCodeIsDownloadableBy(), resolveUserIdFromAuth()
 *
 * @example
 *   // Called from the API router:
 *   handleDownload('a1b2c3d4-e5f6-4890-abcd-ef1234567890', $auth);
 */
function handleDownload(string $uuid, array $auth = []): void
{
    // ==================================================================
    // 📌 Step 1: Validate UUID format
    // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
    // This prevents path traversal attacks and SQL injection via the UUID.
    // @see https://www.php.net/manual/en/function.preg-match.php
    // @see https://datatracker.ietf.org/doc/html/rfc4122#section-4.4
    // ==================================================================
    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    if (preg_match($uuidPattern, $uuid) !== 1) {
        errorResponse(
            'Invalid QR code identifier format.',
            400,
            'INVALID_UUID'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 2: Look up the QR code record in the database
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // ==================================================================
    if (!function_exists('dbQuery')) {
        errorResponse(
            'Download service is temporarily unavailable.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return;
    }

    try {
        // 📌 typeName lives in tblQRCodeTypes; tblQRCodes only holds the
        //    qrTypeID FK, so we JOIN to retrieve the human/machine type name.
        //    🔐 Epic E3 stage 2a: userID/isPublic/deletedAt added so the
        //    ownership check below (ONE query, re-derived every request - no
        //    cached/trusted prior lookup) has everything it needs.
        // @see install.sql tblQRCodes.qrTypeID -> tblQRCodeTypes.typeID
        $stmt = dbQuery(
            "SELECT `q`.`qrID`, `q`.`qrUUID`, `t`.`typeName`, "
            . "`q`.`qrOutputFormat`, `q`.`qrFilePath`, "
            . "`q`.`userID`, `q`.`isPublic`, `q`.`deletedAt` "
            . "FROM `tblQRCodes` AS `q` "
            . "LEFT JOIN `tblQRCodeTypes` AS `t` ON `q`.`qrTypeID` = `t`.`typeID` "
            . "WHERE `q`.`qrUUID` = ? "
            . "LIMIT 1",
            's',
            [$uuid]
        );

        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'QR code download lookup failed',
                'UUID: ' . $uuid . ' | ' . $e->getMessage()
            );
        }

        errorResponse(
            'An error occurred while looking up the QR code.',
            500,
            'LOOKUP_ERROR'
        );
        return;
    }

    // ❌ QR code record not found, OR soft-deleted (Epic E3 stage 2a) - a
    // deleted code is reported identically to a never-existing one.
    if ($row === null || $row['deletedAt'] !== null) {
        errorResponse(
            'QR code not found. The identifier may be invalid or the code may have been deleted.',
            404,
            'NOT_FOUND'
        );
        return;
    }

    // ==================================================================
    // 🔐 Step 2a: Ownership check (Epic E3 stage 2a, #52/#24) - re-derived
    // from THIS row, right here, before any file access below. A non-public
    // code already CLAIMED by a specific user (userID IS NOT NULL) is only
    // downloadable by that owner; an UNCLAIMED code (userID IS NULL) remains
    // downloadable by any authenticated caller (see qrCodeIsDownloadableBy()'s
    // docblock for the full rationale). Never 403 - identical 404 either way.
    // @see fn_api_qrcodes.php - qrCodeIsDownloadableBy(), resolveUserIdFromAuth()
    // ==================================================================
    $downloadOwnerId = $row['userID'] !== null ? (int) $row['userID'] : null;
    $downloadIsPublic = (bool) $row['isPublic'];

    if (function_exists('qrCodeIsDownloadableBy')) {
        $currentDownloaderId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

        if (!qrCodeIsDownloadableBy($downloadOwnerId, $downloadIsPublic, $currentDownloaderId)) {
            errorResponse(
                'QR code not found. The identifier may be invalid or the code may have been deleted.',
                404,
                'NOT_FOUND'
            );
            return;
        }
    }

    // ==================================================================
    // 📌 Step 3: Locate the file on the filesystem
    // ==================================================================
    if (isset($row['qrFilePath'])) {
        $filePath = $row['qrFilePath'];
    } else {
        $filePath = '';
    }
    if (isset($row['qrOutputFormat'])) {
        $format = strtolower(trim($row['qrOutputFormat']));
    } else {
        $format = 'png';
    }

    // 📌 If no file path is stored, try to construct it from the UUID
    if ($filePath === '' || $filePath === null) {
        $uploadsDir = '';

        if (defined('CUERCODE_UPLOADS_DIR')) {
            $uploadsDir = CUERCODE_UPLOADS_DIR . 'qrcodes' . DIRECTORY_SEPARATOR;
        } else {
            $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR
                . '_uploads' . DIRECTORY_SEPARATOR
                . 'qrcodes' . DIRECTORY_SEPARATOR;
        }

        $filePath = $uploadsDir . $uuid . '.' . $format;
    }

    // 📌 Resolve the real path to prevent symlink attacks and path traversal
    // @see https://www.php.net/manual/en/function.realpath.php
    $realPath = realpath($filePath);

    if ($realPath === false) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'QR code file not found on disk',
                'UUID: ' . $uuid . ' | Expected path: ' . $filePath
            );
        }

        errorResponse(
            'QR code file is no longer available for download.',
            404,
            'FILE_NOT_FOUND'
        );
        return;
    }

    // 📌 Verify the resolved path is within the expected uploads directory
    // This prevents directory traversal and symlink attacks
    $uploadsBase = '';
    if (defined('CUERCODE_UPLOADS_DIR')) {
        $uploadsBase = realpath(CUERCODE_UPLOADS_DIR . 'qrcodes');
    } else {
        $uploadsBase = realpath(
            dirname(__DIR__) . DIRECTORY_SEPARATOR
            . '_uploads' . DIRECTORY_SEPARATOR
            . 'qrcodes'
        );
    }

    if ($uploadsBase === false
        || strpos($realPath, $uploadsBase . DIRECTORY_SEPARATOR) !== 0
    ) {
        if (function_exists('logError')) {
            logError(
                'error',
                'QR code download path traversal attempt blocked',
                'UUID: ' . $uuid . ' | Resolved: ' . $realPath
                . ' | Expected base: ' . ($uploadsBase !== false ? $uploadsBase : 'N/A')
            );
        }

        errorResponse(
            'QR code file is no longer available for download.',
            404,
            'FILE_NOT_FOUND'
        );
        return;
    }

    // 📌 Use the resolved real path for all subsequent file operations
    $filePath = $realPath;

    // ❌ File is not readable
    if (!is_readable($filePath)) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'QR code file not readable',
                'UUID: ' . $uuid . ' | Path: ' . $filePath
            );
        }

        errorResponse(
            'QR code file is no longer available for download.',
            404,
            'FILE_NOT_FOUND'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 4: Determine MIME type and file name
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types
    // ==================================================================
    $mimeTypes = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
    ];

    if (isset($mimeTypes[$format])) {
        $mimeType = $mimeTypes[$format];
    } else {
        $mimeType = 'application/octet-stream';
    }

    // 📌 Build a descriptive download filename
    // Format: cuercode-{type}-{short-uuid}.{ext}
    if (isset($row['typeName'])) {
        $typeName = strtolower(trim($row['typeName']));
    } else {
        $typeName = 'qr';
    }
    $shortUuid = substr($uuid, 0, 8);
    $fileName  = 'cuercode-' . preg_replace('/[^a-z0-9]/', '-', $typeName)
        . '-' . $shortUuid . '.' . $format;

    // ==================================================================
    // 📌 Step 5: Stream the file to the client
    // @see https://www.php.net/manual/en/function.header.php
    // @see https://www.php.net/manual/en/function.readfile.php
    // @see https://www.php.net/manual/en/function.filesize.php
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
    // ==================================================================

    // 📌 Get the file size for Content-Length header
    // @see https://www.php.net/manual/en/function.filesize.php
    $fileSize = filesize($filePath);

    // 📌 Send HTTP headers
    if (!headers_sent()) {
        // 📌 HTTP status 200 OK
        http_response_code(200);

        // 📌 Content-Type with correct MIME type
        header('Content-Type: ' . $mimeType);

        // 📌 Content-Disposition: attachment triggers browser download dialog
        // 🛡️ SECURITY REVIEW (Epic E3, #52): this handler ALWAYS serves
        // attachment, never inline - unlike public_html/account/qr-preview/
        // index.php, there is no code path here that renders SVG inline in
        // the browser, so an image/svg+xml response from this endpoint is
        // downloaded (inert) rather than executed and does NOT need the
        // inline-SVG Content-Security-Policy sandbox hardening added there.
        // Confirmed during the #52 audit; keep this header as attachment if
        // this handler is ever extended to support an inline preview mode.
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
        header(
            'Content-Disposition: attachment; filename="'
            . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '"'
        );

        // 📌 Content-Length for progress indicators
        if ($fileSize !== false) {
            header('Content-Length: ' . $fileSize);
        }

        // 📌 Cache control: allow caching for downloads (they're immutable)
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
        header('Cache-Control: public, max-age=86400');

        // 📌 Prevent MIME type sniffing
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
        header('X-Content-Type-Options: nosniff');

        // 📌 CORS headers
        $allowedOrigins = '*';
        if (function_exists('getConfig')) {
            $allowedOrigins = getConfig('api_cors_allowed_origins', '*');
        }
        header('Access-Control-Allow-Origin: ' . $allowedOrigins);
    }

    // 📌 Flush any output buffers before streaming the file
    // @see https://www.php.net/manual/en/function.ob-end-clean.php
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // 📌 Stream the file content to the client
    // readfile() reads the file and writes it directly to the output buffer,
    // which is more memory-efficient than file_get_contents() + echo.
    // @see https://www.php.net/manual/en/function.readfile.php
    readfile($filePath);

    // 📝 Log the download activity
    if (function_exists('logActivity')) {
        logActivity(
            'api_download',
            'API: Downloaded QR code: ' . $uuid . ' (' . strtoupper($format) . ')',
            'qr_code',
            (int) $row['qrID'],
            [
                'uuid'     => $uuid,
                'format'   => $format,
                'fileSize' => $fileSize,
            ]
        );
    }

    exit;
}
