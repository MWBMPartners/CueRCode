<?php
/**
 * ==============================================================================
 * 📋 CueRCode - API Types, Formats & Health Check Handlers
 * ==============================================================================
 *
 * Path: _functions/fn_api_types.php
 *
 * Provides handler functions for the informational API endpoints:
 *
 *   - GET /api/v1/types    -> handleGetTypes()     List available QR code types
 *   - GET /api/v1/formats  -> handleGetFormats()   List supported output formats
 *   - GET /api/v1/health   -> handleHealthCheck()  Application health check
 *
 * These endpoints are publicly accessible (no authentication required) and
 * provide metadata about the QR code generation capabilities of the API.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/function.json-decode.php
 * @see         https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Status
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
// 📋 handleGetTypes() - List available QR code types
// ==============================================================================

/**
 * 📋 Handle the GET /api/v1/types endpoint
 *
 * Queries the tblQRCodeTypes table for all active QR code types and returns
 * them with their form field schemas parsed from JSON. This allows API
 * consumers to dynamically build forms or validate input data structures
 * for each QR code type.
 *
 * Response includes:
 *   - Type metadata (name, label, icon, description, category)
 *   - Parsed form fields JSON schema (specifying required fields and types)
 *   - Payload template (showing the generated payload format)
 *   - Sort order for UI rendering
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see https://www.php.net/manual/en/function.json-decode.php
 * @see fn_response.php - successResponse(), errorResponse()
 *
 * @example
 *   // Response:
 *   {
 *       "success": true,
 *       "message": "QR code types retrieved successfully.",
 *       "data": {
 *           "types": [
 *               {
 *                   "name": "url",
 *                   "label": "URL / Website",
 *                   "icon": "fa-solid fa-link",
 *                   "description": "Link to a website URL",
 *                   "category": "web",
 *                   "form_fields": [ ... ],
 *                   "payload_template": "{url}",
 *                   "sort_order": 1
 *               },
 *               ...
 *           ],
 *           "count": 15
 *       }
 *   }
 */
function handleGetTypes(): void
{
    if (!function_exists('dbQuery')) {
        errorResponse(
            'Types service is temporarily unavailable.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return;
    }

    try {
        // 📌 Query all active QR code types, ordered by sortOrder
        // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
        $stmt = dbQuery(
            "SELECT "
            . "`typeID`, `typeName`, `typeLabel`, `typeIcon`, `typeDescription`, "
            . "`typeCategory`, `payloadTemplate`, `formFieldsJSON`, `sortOrder` "
            . "FROM `tblQRCodeTypes` "
            . "WHERE `isActive` = 1 "
            . "ORDER BY `sortOrder` ASC, `typeLabel` ASC",
            '',
            []
        );

        $result = $stmt->get_result();
        $types  = [];

        // 🔄 Process each row
        while ($row = $result->fetch_assoc()) {
            // 📦 Parse the formFieldsJSON column into a PHP array
            // This JSON defines the form schema for each QR code type:
            // field names, types, labels, validation rules, etc.
            // @see https://www.php.net/manual/en/function.json-decode.php
            $formFields = [];
            if (!empty($row['formFieldsJSON'])) {
                $decoded = json_decode($row['formFieldsJSON'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $formFields = $decoded;
                }
            }

            $types[] = [
                'name'             => $row['typeName'],
                'label'            => $row['typeLabel'],
                'icon'             => $row['typeIcon'],
                'description'      => $row['typeDescription'],
                'category'         => $row['typeCategory'],
                'form_fields'      => $formFields,
                'payload_template' => $row['payloadTemplate'],
                'sort_order'       => (int) $row['sortOrder'],
            ];
        }

        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Failed to fetch QR code types', $e->getMessage());
        }

        errorResponse(
            'An error occurred while fetching QR code types.',
            500,
            'FETCH_ERROR'
        );
        return;
    }

    // ✅ Send success response
    successResponse(
        [
            'types' => $types,
            'count' => count($types),
        ],
        'QR code types retrieved successfully.'
    );
}

// ==============================================================================
// 📄 handleGetFormats() - List available output formats
// ==============================================================================

/**
 * 📄 Handle the GET /api/v1/formats endpoint
 *
 * Returns a static list of supported QR code output formats with their
 * MIME types, file extensions, and human-readable descriptions, PLUS a
 * separate `barcode_formats` list for the non-QR barcode API (#69) - the
 * two are intentionally kept as distinct arrays rather than merged into one:
 * `formats` documents POST /api/v1/generate's `customization.format` value
 * space (6 formats, driven by chillerlan/php-qrcode's output classes),
 * while `barcode_formats` documents POST /api/v1/barcode's SEPARATE
 * `options.format` value space (2 formats, driven by tc-lib-barcode's SVG/
 * PNG output only - no JPEG/GIF/WebP/PDF barcode renderer exists here).
 * Merging them would incorrectly imply a barcode could be requested as PDF,
 * or a QR code could skip logo/frame support the way a barcode does.
 *
 * This endpoint does not query the database; both format lists are defined
 * statically since they are determined by the respective generation
 * libraries' capabilities and do not change at runtime.
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see fn_qr_options.php - mapOutputFormat()
 * @see fn_barcode_generate.php - renderBarcodeToOutput() (#69)
 * @see fn_response.php - successResponse()
 *
 * @example
 *   // Response:
 *   {
 *       "success": true,
 *       "message": "Supported output formats retrieved successfully.",
 *       "data": {
 *           "formats": [
 *               {
 *                   "format": "png",
 *                   "mime_type": "image/png",
 *                   "extension": "png",
 *                   "description": "PNG image (lossless, transparent support)",
 *                   "supports_transparency": true,
 *                   "supports_logo": true,
 *                   "supports_frame": true
 *               },
 *               ...
 *           ],
 *           "count": 6,
 *           "default": "png",
 *           "barcode_formats": [
 *               {
 *                   "format": "svg",
 *                   "mime_type": "image/svg+xml",
 *                   "extension": "svg",
 *                   "description": "SVG vector graphic (scalable, ideal for print)"
 *               },
 *               ...
 *           ],
 *           "barcode_count": 2,
 *           "barcode_default": "svg"
 *       }
 *   }
 */
function handleGetFormats(): void
{
    // 📋 Static list of supported output formats
    // These correspond to the output classes in chillerlan/php-qrcode.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Output.html
    $formats = [
        [
            'format'                 => 'png',
            'mime_type'              => 'image/png',
            'extension'              => 'png',
            'description'            => 'PNG image (lossless compression, transparent background support)',
            'supports_transparency'  => true,
            'supports_logo'          => true,
            'supports_frame'         => true,
        ],
        [
            'format'                 => 'jpg',
            'mime_type'              => 'image/jpeg',
            'extension'              => 'jpg',
            'description'            => 'JPEG image (lossy compression, smaller file size, no transparency)',
            'supports_transparency'  => false,
            'supports_logo'          => true,
            'supports_frame'         => true,
        ],
        [
            'format'                 => 'gif',
            'mime_type'              => 'image/gif',
            'extension'              => 'gif',
            'description'            => 'GIF image (lossless, limited colour palette)',
            'supports_transparency'  => true,
            'supports_logo'          => true,
            'supports_frame'         => true,
        ],
        [
            'format'                 => 'webp',
            'mime_type'              => 'image/webp',
            'extension'              => 'webp',
            'description'            => 'WebP image (modern format, excellent compression)',
            'supports_transparency'  => true,
            'supports_logo'          => true,
            'supports_frame'         => true,
        ],
        [
            'format'                 => 'svg',
            'mime_type'              => 'image/svg+xml',
            'extension'              => 'svg',
            'description'            => 'SVG vector graphic (scalable, ideal for print)',
            'supports_transparency'  => true,
            'supports_logo'          => true,
            'supports_frame'         => true,
        ],
        [
            'format'                 => 'pdf',
            'mime_type'              => 'application/pdf',
            'extension'              => 'pdf',
            'description'            => 'PDF document (vector-based, ideal for print)',
            'supports_transparency'  => false,
            'supports_logo'          => false,
            'supports_frame'         => false,
        ],
    ];

    // 📋 Static list of supported BARCODE output formats (#69) - a small,
    // DELIBERATELY separate list from $formats above; see this function's
    // docblock for why the two are not merged.
    // @see fn_barcode_generate.php - renderBarcodeToOutput()
    $barcodeFormats = [
        [
            'format'      => 'svg',
            'mime_type'   => 'image/svg+xml',
            'extension'   => 'svg',
            'description' => 'SVG vector graphic (scalable, ideal for print) - the barcode API default',
        ],
        [
            'format'      => 'png',
            'mime_type'   => 'image/png',
            'extension'   => 'png',
            'description' => 'PNG raster image (fixed resolution, white background)',
        ],
    ];

    // ✅ Send success response
    successResponse(
        [
            'formats'         => $formats,
            'count'           => count($formats),
            'default'         => 'png',
            'barcode_formats' => $barcodeFormats,
            'barcode_count'   => count($barcodeFormats),
            'barcode_default' => 'svg',
        ],
        'Supported output formats retrieved successfully.'
    );
}

// ==============================================================================
// 🏥 handleHealthCheck() - Application health check
// ==============================================================================

/**
 * 🏥 Handle the GET /api/v1/health endpoint
 *
 * Performs a health check of the application by verifying:
 *   1. Database connectivity (via a simple SELECT 1 query)
 *   2. Application version (from config or constant)
 *   3. PHP version
 *
 * This endpoint is used by monitoring systems, load balancers, and
 * deployment pipelines to verify that the application is operational.
 *
 * Returns HTTP 200 if healthy, HTTP 503 if the database is unreachable.
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see https://www.php.net/manual/en/function.phpversion.php
 * @see https://www.php.net/manual/en/reserved.constants.php
 * @see fn_response.php - jsonResponse()
 *
 * @example
 *   // Healthy response (200):
 *   {
 *       "success": true,
 *       "message": "Service is healthy.",
 *       "data": {
 *           "status": "healthy",
 *           "version": "1.0.0",
 *           "php_version": "8.3.6",
 *           "database": "connected",
 *           "uptime_seconds": 0.015
 *       }
 *   }
 *
 *   // Unhealthy response (503):
 *   {
 *       "success": false,
 *       "message": "Service is degraded.",
 *       "data": {
 *           "status": "degraded",
 *           "version": "1.0.0",
 *           "php_version": "8.3.6",
 *           "database": "disconnected"
 *       }
 *   }
 */
function handleHealthCheck(): void
{
    // 📌 Determine application version
    // Try config first, then fall back to a constant or default.
    $version = '1.0.0';
    if (function_exists('getConfig')) {
        $version = getConfig('app_version', '1.0.0');
    }

    // 📌 Check database connectivity
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    $dbStatus = 'disconnected';

    if (function_exists('dbQuery')) {
        try {
            $stmt = dbQuery("SELECT 1 AS `ping`", '', []);
            $result = $stmt->get_result();
            $row    = $result->fetch_assoc();
            $stmt->close();

            if ($row !== null && isset($row['ping'])) {
                $dbStatus = 'connected';
            }
        } catch (\Throwable $e) {
            $dbStatus = 'error';
            if (function_exists('logError')) {
                logError('warning', 'Health check: database ping failed', $e->getMessage());
            }
        }
    }

    // 📌 Calculate uptime (time since request start)
    $uptimeSeconds = 0.0;
    if (defined('CUERCODE_REQUEST_START')) {
        $uptimeSeconds = round(microtime(true) - CUERCODE_REQUEST_START, 4);
    }

    // 📌 Build health check response
    $isHealthy = ($dbStatus === 'connected');
    if ($isHealthy) {
        $status = 'healthy';
    } else {
        $status = 'degraded';
    }
    if ($isHealthy) {
        $message = 'Service is healthy.';
    } else {
        $message = 'Service is degraded.';
    }

    $healthData = [
        'status'          => $status,
        'version'         => $version,
        'php_version'     => PHP_VERSION,
        'database'        => $dbStatus,
        'uptime_seconds'  => $uptimeSeconds,
    ];

    // 📤 Send the response
    // Use the appropriate HTTP status code: 200 for healthy, 503 for degraded.
    if (function_exists('determineResponseFormat')) {
        $format = determineResponseFormat();
    } else {
        $format = 'json';
    }

    $payload = [
        'success' => $isHealthy,
        'message' => $message,
        'data'    => $healthData,
    ];

    if ($isHealthy) {
        $httpCode = 200;
    } else {
        $httpCode = 503;
    }

    if ($format === 'xml') {
        xmlResponse($payload, $httpCode);
    } else {
        jsonResponse($payload, $httpCode);
    }
}
