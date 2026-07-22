<?php
/**
 * ==============================================================================
 * 🎯 CueRCode - API Generate & Preview Handlers
 * ==============================================================================
 *
 * Path: _functions/fn_api_generate.php
 *
 * Provides handler functions for the QR code generation and preview API
 * endpoints. These functions process incoming JSON request data, validate
 * inputs, delegate to the core QR generation pipeline, and return
 * structured API responses.
 *
 * Endpoints handled:
 *   - POST /api/v1/generate         -> handleGenerate()         Full QR code generation
 *   - POST /api/v1/preview          -> handlePreview()          Quick preview generation
 *   - POST /api/v1/barcode          -> handleBarcodeGenerate()  Full NON-QR barcode generation (#69)
 *   - POST /api/v1/barcode/preview  -> handleBarcodePreview()   Quick barcode preview (#69)
 *
 * The barcode handlers are the API-layer counterpart to
 * fn_barcode_generate.php's generateBarcode()/generateBarcodePreview() - this
 * file validates the HTTP request SHAPE (required fields, lengths, option
 * ranges) exactly like handleGenerate()/handlePreview() do for QR codes, then
 * delegates the actual symbology/checksum validation and rendering to that
 * file, matching the existing fn_api_generate.php <-> fn_qr_generate.php split.
 *
 * Both QR handlers expect a JSON request body with the following structure:
 *   {
 *       "type": "url",
 *       "input": { "url": "https://example.com" },
 *       "customization": { "format": "png", "size": 800, ... }
 *   }
 *
 * The generate endpoint saves QR codes to the database and filesystem,
 * returning a UUID for later download. The preview endpoint creates a
 * smaller, transient PNG that is not saved.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2
 * @see         fn_qr_generate.php - generateQRCode(), generatePreview()
 * @see         fn_qr_payloads.php - buildQRPayload()
 * @see         fn_qr_options.php - buildQROptions()
 * @see         https://www.php.net/manual/en/function.json-decode.php
 * @see         https://www.php.net/manual/en/function.file-get-contents.php
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
// 📏 API request field-length limits (🔐 F-007)
// ==============================================================================
// Per-field maximum lengths (in characters/bytes) for user-supplied request
// fields. These bound the work done downstream (payload building, QR encoding,
// frame text rendering) and stop a caller from sending pathologically large
// individual values even when the overall body is under the router's size cap.
// A QR code's own data capacity tops out well under 3 KB, so these limits are
// generous for legitimate use while still defensive.
// @see https://www.qrcode.com/en/about/version.html (max ~2953 bytes binary)
// ==============================================================================

/**
 * @var int Maximum length of the 'type' field (a short type identifier slug).
 */
if (!defined('CUERCODE_API_MAX_TYPE_LEN')) {
    define('CUERCODE_API_MAX_TYPE_LEN', 64);
}

/**
 * @var int Maximum length of any single scalar value inside the 'input' object.
 * 4096 comfortably exceeds the QR data ceiling while bounding per-field size.
 */
if (!defined('CUERCODE_API_MAX_INPUT_FIELD_LEN')) {
    define('CUERCODE_API_MAX_INPUT_FIELD_LEN', 4096);
}

/**
 * @var int Maximum length of a frame label (short marketing text e.g. "SCAN ME").
 */
if (!defined('CUERCODE_API_MAX_FRAME_LABEL_LEN')) {
    define('CUERCODE_API_MAX_FRAME_LABEL_LEN', 120);
}

/**
 * @var int Maximum length of the barcode 'symbology' field (a short slug, e.g. "code128").
 */
if (!defined('CUERCODE_API_MAX_SYMBOLOGY_LEN')) {
    define('CUERCODE_API_MAX_SYMBOLOGY_LEN', 32);
}

// ==============================================================================
// 📏 validateRequestFieldLengths() - Enforce per-field max lengths (🔐 F-007)
// ==============================================================================

/**
 * 📏 Validate that user-supplied request fields are within length limits
 *
 * 🔐 F-007: complements the router-level total-body-size cap with per-field
 * bounds so an individual value cannot be pathologically large even within an
 * otherwise small body. Checks:
 *   - 'type'                  (≤ CUERCODE_API_MAX_TYPE_LEN)
 *   - each scalar in 'input'  (≤ CUERCODE_API_MAX_INPUT_FIELD_LEN)
 *   - 'customization.frame_label' (≤ CUERCODE_API_MAX_FRAME_LABEL_LEN)
 *
 * Only scalar input values are length-checked; nested arrays are walked one
 * level (sufficient for the flat input objects this API accepts) and their
 * scalar leaves are checked too.
 *
 * @param array $requestData The parsed JSON request body
 *
 * @return string|null Null if all fields are within limits, otherwise a
 *                     human-readable error message describing the first
 *                     field that exceeded its limit.
 *
 * @see https://www.php.net/manual/en/function.mb-strlen.php
 */
function validateRequestFieldLengths(array $requestData): ?string
{
    // 📌 'type' length (only when present and a string).
    if (isset($requestData['type']) && is_string($requestData['type'])) {
        if (mb_strlen($requestData['type']) > CUERCODE_API_MAX_TYPE_LEN) {
            return 'Field "type" is too long. Maximum '
                . CUERCODE_API_MAX_TYPE_LEN . ' characters.';
        }
    }

    // 📌 'input' scalar values (one level of nesting walked).
    if (isset($requestData['input']) && is_array($requestData['input'])) {
        foreach ($requestData['input'] as $inputKey => $inputValue) {
            // 🔢 Walk a single level of nested arrays (e.g. structured types).
            if (is_array($inputValue)) {
                foreach ($inputValue as $nestedKey => $nestedValue) {
                    if (is_string($nestedValue)
                        && mb_strlen($nestedValue) > CUERCODE_API_MAX_INPUT_FIELD_LEN
                    ) {
                        return 'Input field "'
                            . htmlspecialchars((string) $inputKey . '.' . (string) $nestedKey, ENT_QUOTES, 'UTF-8')
                            . '" is too long. Maximum '
                            . CUERCODE_API_MAX_INPUT_FIELD_LEN . ' characters.';
                    }
                }
                continue;
            }

            if (is_string($inputValue)
                && mb_strlen($inputValue) > CUERCODE_API_MAX_INPUT_FIELD_LEN
            ) {
                return 'Input field "'
                    . htmlspecialchars((string) $inputKey, ENT_QUOTES, 'UTF-8')
                    . '" is too long. Maximum '
                    . CUERCODE_API_MAX_INPUT_FIELD_LEN . ' characters.';
            }
        }
    }

    // 📌 'customization.frame_label' length (free-text rendered into a frame).
    if (isset($requestData['customization']) && is_array($requestData['customization'])) {
        $customization = $requestData['customization'];
        if (isset($customization['frame_label'])
            && is_string($customization['frame_label'])
            && mb_strlen($customization['frame_label']) > CUERCODE_API_MAX_FRAME_LABEL_LEN
        ) {
            return 'Field "frame_label" is too long. Maximum '
                . CUERCODE_API_MAX_FRAME_LABEL_LEN . ' characters.';
        }
    }

    // ✅ All fields within limits.
    return null;
}

// ==============================================================================
// 🎯 handleGenerate() - Handle QR code generation API request
// ==============================================================================

/**
 * 🎯 Handle a QR code generation API request
 *
 * Processes a POST request to generate a full QR code. The generated code
 * is saved to the database and filesystem, and the response includes a
 * base64 data URI, UUID, and download URL.
 *
 * Expected request body (JSON):
 *   {
 *       "type": "url",
 *       "input": { "url": "https://example.com" },
 *       "customization": {
 *           "format": "png",
 *           "size": 800,
 *           "ecc": "H",
 *           "fg_color": "#000000",
 *           "bg_color": "#ffffff",
 *           "marker_color": "",
 *           "pattern": "square",
 *           "marker_border": "square",
 *           "marker_center": "square",
 *           "logo": 5,
 *           "logo_size_pct": 20,
 *           "frame_style": "none",
 *           "frame_label": "",
 *           "frame_color": "",
 *           "transparent": false,
 *           "quiet_zone": true,
 *           "preset": "none",
 *           "scan_distance_cm": 100,
 *           "pdf_layout": "standard"
 *       }
 *   }
 *
 * 🔧 preset="accessible" (E5 / #48) forces ecc=H + quiet_zone=true + an
 *    auto-selected version, rejects the request with 422 when fg_color/
 *    bg_color contrast is below WCAG 3:1, and adds a `metadata` object
 *    (contrast_ratio, contrast_warning, recommended_print_size_mm) to the
 *    success response. scan_distance_cm (optional) drives the print-size
 *    recommendation via the 10:1 rule. @see fn_qr_options.php - applyAccessiblePreset()
 *
 * 🔧 pdf_layout (E5 / #49, format=pdf only): 'standard' (default) is today's
 *    unchanged PDF output. 'accessible_caption' adds a large-print + Grade-1
 *    Braille caption; 'tactile' adds a second page with a tactile locator
 *    frame overlay guide; 'avery_5160'/'avery_l7160' render an Avery label
 *    sheet with this code filling one label. @see fn_qr_accessible_pdf.php
 *
 * @param array $requestData The parsed JSON request body
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see fn_qr_generate.php - generateQRCode()
 * @see fn_response.php - successResponse(), errorResponse()
 *
 * @example
 *   // Called from the API router:
 *   $body = json_decode(file_get_contents('php://input'), true);
 *   handleGenerate($body);
 */
function handleGenerate(array $requestData): void
{
    // ==================================================================
    // 🔐 F-007: enforce per-field length limits before any processing
    // ==================================================================
    $lengthError = validateRequestFieldLengths($requestData);
    if ($lengthError !== null) {
        errorResponse($lengthError, 400, 'FIELD_TOO_LONG');
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 1: Validate required fields
    // ==================================================================

    // ❌ Validate 'type' field
    if (isset($requestData['type'])) {
        $typeName = $requestData['type'];
    } else {
        $typeName = '';
    }

    if (!is_string($typeName) || trim($typeName) === '') {
        errorResponse(
            'Missing required field: type. Specify the QR code type (e.g., "url", "vcard", "wifi").',
            400,
            'MISSING_FIELD'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    $typeName = trim($typeName);

    // ❌ Validate 'input' field
    if (isset($requestData['input'])) {
        $inputData = $requestData['input'];
    } else {
        $inputData = null;
    }

    if (!is_array($inputData) || empty($inputData)) {
        errorResponse(
            'Missing required field: input. Provide an object with the data for the QR code type.',
            400,
            'MISSING_FIELD'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 2: Build customization array
    // Merge request customization with sensible defaults.
    // ==================================================================
    if (isset($requestData['customization'])) {
        $rawCustomization = $requestData['customization'];
    } else {
        $rawCustomization = [];
    }

    if (!is_array($rawCustomization)) {
        $rawCustomization = [];
    }

    $customization = buildCustomizationFromRequest($rawCustomization);

    // ==================================================================
    // 📌 Step 3: Load QR generation functions if not already loaded
    // The QR generation files are not loaded by bootstrap.php by default
    // since they are only needed for QR code operations. Moved ahead of
    // validation (was Step 4) because the accessible-preset enforcement
    // immediately below lives in fn_qr_options.php and must run before the
    // customisation is validated/generated.
    // @see fn_qr_payloads.php, fn_qr_options.php, fn_qr_generate.php
    // ==================================================================
    loadQRGenerationFunctions();

    // ==================================================================
    // ♿ Step 3a: Apply the "Accessible QR" preset (E5 / #48)
    // A complete no-op unless customization['preset'] === 'accessible'. When
    // active it forces ecc=H + quiet_zone=true + auto version, and evaluates
    // the chosen fg/bg contrast against WCAG thresholds. A sub-3:1 ratio is
    // rejected here with a 422 (distinct from the 400s validateCustomization()
    // returns for malformed values); a 3:1-4.5:1 ratio is allowed through but
    // recorded as a warning surfaced in the success response metadata below.
    // @see fn_qr_options.php - applyAccessiblePreset()
    // ==================================================================
    if (function_exists('applyAccessiblePreset')) {
        $customization = applyAccessiblePreset($customization);
    }

    $accessibleMeta = null;
    if (isset($customization['_accessible_meta']) && is_array($customization['_accessible_meta'])) {
        $accessibleMeta = $customization['_accessible_meta'];
        // 🧹 Never let the internal bookkeeping key reach buildQROptions()/
        //    generateQRCode() - it is meaningless to the QR engine.
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
            return; // @codeCoverageIgnore - errorResponse exits
        }
    }

    // ==================================================================
    // 🛡️ Step 3b: Reject an unsafe base64 SVG logo (F-024, #52)
    // Checked BEFORE validateCustomization() so a malicious SVG logo gets a
    // distinct, actionable 422 rather than falling through to a generic 400
    // INVALID_CUSTOMIZATION or — worse — silently generating a logo-less QR
    // code (saveBase64LogoToTemp() rejecting the logo further downstream is
    // a silent no-logo fallback, not a visible error). The ACTUAL security
    // enforcement (never writing unscreened SVG bytes to disk) lives in
    // saveBase64LogoToTemp() itself (fn_qr_logo.php) — this is the
    // API-contract layer on top of that, for a clear caller-facing error.
    // @see fn_qr_logo.php - rejectUnsafeBase64SvgLogo(), screenBase64LogoDataUri()
    // ==================================================================
    if (function_exists('rejectUnsafeBase64SvgLogo')) {
        $logoSvgRejection = rejectUnsafeBase64SvgLogo($customization);

        if ($logoSvgRejection !== null) {
            errorResponse($logoSvgRejection, 422, 'LOGO_SVG_REJECTED');
            return; // @codeCoverageIgnore - errorResponse exits
        }
    }

    // ==================================================================
    // 📌 Step 4: Validate customization values
    // ==================================================================
    $validationError = validateCustomization($customization);

    if ($validationError !== null) {
        errorResponse($validationError, 400, 'INVALID_CUSTOMIZATION');
        return;
    }

    // ==================================================================
    // 📌 Step 5: Call the core generation function
    // @see fn_qr_generate.php - generateQRCode()
    // ==================================================================
    if (!function_exists('generateQRCode')) {
        errorResponse(
            'QR code generation service is unavailable. Please try again later.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return;
    }

    $result = generateQRCode($typeName, $inputData, $customization);

    // ==================================================================
    // 📌 Step 6: Handle result
    // ==================================================================
    if (!$result['success']) {
        if (isset($result['error'])) {
            $generationError = $result['error'];
        } else {
            $generationError = 'Unknown error.';
        }
        errorResponse(
            'QR code generation failed: ' . $generationError,
            422,
            'GENERATION_FAILED'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 7: Build and send success response
    // ==================================================================
    if (isset($customization['format'])) {
        $format = strtolower(trim($customization['format']));
    } else {
        $format = 'png';
    }

    $responseData = [
        'uuid'         => $result['uuid'],
        'image'        => $result['data'],
        'download_url' => '/api/v1/download/' . $result['uuid'],
        'mime_type'    => $result['mimeType'],
        'format'       => $format,
    ];

    // ♿ Accessible-preset metadata (E5 / #48): only present when the request
    //    used preset=accessible. Surfaces the computed WCAG contrast ratio,
    //    a sub-AA warning (when 3:1 <= ratio < 4.5:1), and the physical
    //    print-size recommendation (when scan_distance_cm was supplied), so
    //    the UI/API caller never needs to recompute any of this itself.
    // @see fn_qr_options.php - applyAccessiblePreset(), qrRecommendedPrintSizeMm()
    if ($accessibleMeta !== null) {
        $responseData['metadata'] = [
            'accessible'                => true,
            'contrast_ratio'            => $accessibleMeta['contrast_ratio'],
            'recommended_print_size_mm' => $accessibleMeta['recommended_print_size_mm'],
        ];

        if (isset($accessibleMeta['contrast_level']) && $accessibleMeta['contrast_level'] === 'warning') {
            $responseData['metadata']['contrast_warning'] =
                'Contrast ratio ' . $accessibleMeta['contrast_ratio']
                . ':1 is below the recommended WCAG AA level of 4.5:1. Consider a higher-contrast '
                . 'colour pair for maximum readability.';
        }
    }

    // 📝 Log the API generation activity
    if (function_exists('logActivity')) {
        logActivity(
            'api_generate',
            'API: Generated ' . strtoupper($format) . ' QR code for type: ' . $typeName,
            'qr_code',
            null,
            [
                'uuid'     => $result['uuid'],
                'typeName' => $typeName,
                'format'   => $format,
                'endpoint' => 'generate',
            ]
        );
    }

    successResponse($responseData, 'QR code generated successfully.');
}

// ==============================================================================
// 👁️ handlePreview() - Handle QR code preview API request
// ==============================================================================

/**
 * 👁️ Handle a QR code preview API request
 *
 * Processes a POST request to generate a quick preview QR code. The preview
 * is always PNG format, 300px, and is NOT saved to the database or filesystem.
 *
 * Expects the same request body structure as handleGenerate(), but the
 * customization format and size are overridden for preview purposes.
 *
 * @param array $requestData The parsed JSON request body
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see fn_qr_generate.php - generatePreview()
 * @see fn_response.php - successResponse(), errorResponse()
 *
 * @example
 *   // Called from the API router:
 *   $body = json_decode(file_get_contents('php://input'), true);
 *   handlePreview($body);
 */
function handlePreview(array $requestData): void
{
    // ==================================================================
    // 🔐 F-007: enforce per-field length limits before any processing
    // ==================================================================
    $lengthError = validateRequestFieldLengths($requestData);
    if ($lengthError !== null) {
        errorResponse($lengthError, 400, 'FIELD_TOO_LONG');
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 1: Validate required fields
    // ==================================================================

    // ❌ Validate 'type' field
    if (isset($requestData['type'])) {
        $typeName = $requestData['type'];
    } else {
        $typeName = '';
    }

    if (!is_string($typeName) || trim($typeName) === '') {
        errorResponse(
            'Missing required field: type. Specify the QR code type (e.g., "url", "vcard", "wifi").',
            400,
            'MISSING_FIELD'
        );
        return;
    }

    $typeName = trim($typeName);

    // ❌ Validate 'input' field
    if (isset($requestData['input'])) {
        $inputData = $requestData['input'];
    } else {
        $inputData = null;
    }

    if (!is_array($inputData) || empty($inputData)) {
        errorResponse(
            'Missing required field: input. Provide an object with the data for the QR code type.',
            400,
            'MISSING_FIELD'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 2: Build customization array (preview overrides)
    // ==================================================================
    if (isset($requestData['customization'])) {
        $rawCustomization = $requestData['customization'];
    } else {
        $rawCustomization = [];
    }

    if (!is_array($rawCustomization)) {
        $rawCustomization = [];
    }

    $customization = buildCustomizationFromRequest($rawCustomization);

    // 📌 Override format and size for preview
    $customization['format']    = 'png';
    $customization['size']      = 300;
    $customization['save_file'] = false;

    // ==================================================================
    // 📌 Step 3: Load QR generation functions
    // Moved ahead of validation (was Step 4) - the accessible-preset
    // enforcement immediately below lives in fn_qr_options.php and must run
    // before the customisation is validated/generated.
    // ==================================================================
    loadQRGenerationFunctions();

    // ==================================================================
    // ♿ Step 3a: Apply the "Accessible QR" preset (E5 / #48)
    // A complete no-op unless customization['preset'] === 'accessible'. See
    // handleGenerate() for the full rationale; the preview endpoint mirrors
    // the same enforcement + contrast-rejection + metadata behaviour so the
    // live UI preview and the final /generate call never disagree.
    // @see fn_qr_options.php - applyAccessiblePreset()
    // @see handleGenerate() - identical logic, documented in full there
    // ==================================================================
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
            return; // @codeCoverageIgnore - errorResponse exits
        }
    }

    // ==================================================================
    // 🛡️ Step 3b: Reject an unsafe base64 SVG logo (F-024, #52)
    // Mirrors handleGenerate()'s identical check so the live preview and the
    // final /generate call never disagree about a rejected logo.
    // @see fn_qr_logo.php - rejectUnsafeBase64SvgLogo(), screenBase64LogoDataUri()
    // @see handleGenerate() - identical logic, documented in full there
    // ==================================================================
    if (function_exists('rejectUnsafeBase64SvgLogo')) {
        $logoSvgRejection = rejectUnsafeBase64SvgLogo($customization);

        if ($logoSvgRejection !== null) {
            errorResponse($logoSvgRejection, 422, 'LOGO_SVG_REJECTED');
            return; // @codeCoverageIgnore - errorResponse exits
        }
    }

    // ==================================================================
    // 📌 Step 4: Validate customization values
    // ==================================================================
    $validationError = validateCustomization($customization);

    if ($validationError !== null) {
        errorResponse($validationError, 400, 'INVALID_CUSTOMIZATION');
        return;
    }

    // ==================================================================
    // 📌 Step 5: Call the core preview function
    // @see fn_qr_generate.php - generatePreview()
    // ==================================================================
    if (!function_exists('generatePreview')) {
        errorResponse(
            'QR code preview service is unavailable. Please try again later.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return;
    }

    $result = generatePreview($typeName, $inputData, $customization);

    // ==================================================================
    // 📌 Step 6: Handle result
    // ==================================================================
    if (!$result['success']) {
        if (isset($result['error'])) {
            $previewError = $result['error'];
        } else {
            $previewError = 'Unknown error.';
        }
        errorResponse(
            'QR code preview failed: ' . $previewError,
            422,
            'GENERATION_FAILED'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 7: Build and send success response
    // ==================================================================
    $responseData = [
        'image'     => $result['data'],
        'mime_type' => $result['mimeType'],
    ];

    // ♿ Accessible-preset metadata (E5 / #48) - identical shape to
    // handleGenerate() so the live preview and the final download agree.
    // @see handleGenerate() - full rationale documented there
    if ($accessibleMeta !== null) {
        $responseData['metadata'] = [
            'accessible'                => true,
            'contrast_ratio'            => $accessibleMeta['contrast_ratio'],
            'recommended_print_size_mm' => $accessibleMeta['recommended_print_size_mm'],
        ];

        if (isset($accessibleMeta['contrast_level']) && $accessibleMeta['contrast_level'] === 'warning') {
            $responseData['metadata']['contrast_warning'] =
                'Contrast ratio ' . $accessibleMeta['contrast_ratio']
                . ':1 is below the recommended WCAG AA level of 4.5:1. Consider a higher-contrast '
                . 'colour pair for maximum readability.';
        }
    }

    successResponse($responseData, 'QR code preview generated successfully.');
}

// ==============================================================================
// 📊 handleBarcodeGenerate() - Handle NON-QR barcode generation API request (#69)
// ==============================================================================

/**
 * 📊 Handle a POST /api/v1/barcode request - generate + persist a barcode.
 *
 * Expected request body (JSON):
 *   {
 *       "symbology": "code128",
 *       "data": "HELLO123",
 *       "options": {
 *           "format": "svg",
 *           "scale": 2,
 *           "height": 50,
 *           "show_text": false,
 *           "ecc": null
 *       }
 *   }
 *
 * Mirrors handleGenerate()'s shape: validate the request SHAPE here (missing/
 * too-long fields), then delegate to fn_barcode_generate.php's
 * generateBarcode() for the STRICT per-symbology data validation (check
 * digits, charset, even-length, etc.) and rendering. Any validation OR
 * rendering failure inside generateBarcode() is reported the SAME way
 * buildPayload_*() failures are for QR codes - a generic 422 GENERATION_FAILED
 * carrying the specific reason in the message, not a bespoke error code per
 * symbology (see fn_qr_generate.php's Step 2/handleGenerate()'s Step 6 for the
 * precedent this mirrors).
 *
 * @param array $requestData The parsed JSON request body
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see fn_barcode_generate.php - generateBarcode()
 * @see fn_response.php - successResponse(), errorResponse()
 */
function handleBarcodeGenerate(array $requestData): void
{
    // ==================================================================
    // 📌 Step 1: Validate required fields
    // ==================================================================
    if (isset($requestData['symbology'])) {
        $symbology = $requestData['symbology'];
    } else {
        $symbology = '';
    }

    if (!is_string($symbology) || trim($symbology) === '') {
        errorResponse(
            'Missing required field: symbology. Specify the barcode symbology '
            . '(e.g., "code128", "ean13", "datamatrix"). See GET /api/v1/types for the full list.',
            400,
            'MISSING_FIELD'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    $symbology = trim($symbology);

    if (mb_strlen($symbology) > CUERCODE_API_MAX_SYMBOLOGY_LEN) {
        errorResponse(
            'Field "symbology" is too long. Maximum ' . CUERCODE_API_MAX_SYMBOLOGY_LEN . ' characters.',
            400,
            'FIELD_TOO_LONG'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    if (isset($requestData['data'])) {
        $barcodeData = $requestData['data'];
    } else {
        $barcodeData = null;
    }

    if (!is_string($barcodeData) || trim($barcodeData) === '') {
        errorResponse(
            'Missing required field: data. Provide the content to encode in the barcode.',
            400,
            'MISSING_FIELD'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    if (mb_strlen($barcodeData) > CUERCODE_API_MAX_INPUT_FIELD_LEN) {
        errorResponse(
            'Field "data" is too long. Maximum ' . CUERCODE_API_MAX_INPUT_FIELD_LEN . ' characters.',
            400,
            'FIELD_TOO_LONG'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 2: Build options
    // ==================================================================
    if (isset($requestData['options']) && is_array($requestData['options'])) {
        $rawOptions = $requestData['options'];
    } else {
        $rawOptions = [];
    }

    $options = buildBarcodeOptionsFromRequest($rawOptions);

    // ==================================================================
    // 📌 Step 3: Load barcode generation functions (shares
    // loadQRGenerationFunctions() with the QR pipeline - see that
    // function's docblock for why fn_barcode_generate.php is loaded here)
    // ==================================================================
    loadQRGenerationFunctions();

    if (!function_exists('generateBarcode')) {
        errorResponse(
            'Barcode generation service is unavailable. Please try again later.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 4: Generate
    // ==================================================================
    $result = generateBarcode($symbology, $barcodeData, $options);

    if (!$result['success']) {
        if (isset($result['error'])) {
            $generationError = $result['error'];
        } else {
            $generationError = 'Unknown error.';
        }
        errorResponse(
            'Barcode generation failed: ' . $generationError,
            422,
            'GENERATION_FAILED'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 5: Build and send success response
    // ==================================================================
    $symbologyKey = strtolower($symbology);

    $responseData = [
        'uuid'         => $result['uuid'],
        'image'        => $result['data'],
        'download_url' => '/api/v1/download/' . $result['uuid'],
        'mime_type'    => $result['mimeType'],
        'format'       => $options['format'],
        'symbology'    => $symbologyKey,
    ];

    if (function_exists('logActivity')) {
        logActivity(
            'api_barcode_generate',
            'API: Generated ' . strtoupper($options['format']) . ' barcode for symbology: ' . $symbologyKey,
            'qr_code',
            null,
            [
                'uuid'      => $result['uuid'],
                'symbology' => $symbologyKey,
                'format'    => $options['format'],
                'endpoint'  => 'barcode',
            ]
        );
    }

    successResponse($responseData, 'Barcode generated successfully.');
}

// ==============================================================================
// 👁️ handleBarcodePreview() - Handle barcode preview API request (#69)
// ==============================================================================

/**
 * 👁️ Handle a POST /api/v1/barcode/preview request - a quick, unsaved barcode.
 *
 * Expects the SAME request body shape as handleBarcodeGenerate(). The
 * preview is always SVG (vector - no size/quality trade-off the way a
 * raster PNG preview would have) and is NOT saved to the database or
 * filesystem.
 *
 * @param array $requestData The parsed JSON request body
 *
 * @return void (sends JSON/XML response and exits)
 *
 * @see fn_barcode_generate.php - generateBarcodePreview()
 */
function handleBarcodePreview(array $requestData): void
{
    // ==================================================================
    // 📌 Step 1: Validate required fields (identical to handleBarcodeGenerate())
    // ==================================================================
    if (isset($requestData['symbology'])) {
        $symbology = $requestData['symbology'];
    } else {
        $symbology = '';
    }

    if (!is_string($symbology) || trim($symbology) === '') {
        errorResponse(
            'Missing required field: symbology. Specify the barcode symbology '
            . '(e.g., "code128", "ean13", "datamatrix"). See GET /api/v1/types for the full list.',
            400,
            'MISSING_FIELD'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    $symbology = trim($symbology);

    if (mb_strlen($symbology) > CUERCODE_API_MAX_SYMBOLOGY_LEN) {
        errorResponse(
            'Field "symbology" is too long. Maximum ' . CUERCODE_API_MAX_SYMBOLOGY_LEN . ' characters.',
            400,
            'FIELD_TOO_LONG'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    if (isset($requestData['data'])) {
        $barcodeData = $requestData['data'];
    } else {
        $barcodeData = null;
    }

    if (!is_string($barcodeData) || trim($barcodeData) === '') {
        errorResponse(
            'Missing required field: data. Provide the content to encode in the barcode.',
            400,
            'MISSING_FIELD'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    if (mb_strlen($barcodeData) > CUERCODE_API_MAX_INPUT_FIELD_LEN) {
        errorResponse(
            'Field "data" is too long. Maximum ' . CUERCODE_API_MAX_INPUT_FIELD_LEN . ' characters.',
            400,
            'FIELD_TOO_LONG'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 2: Build options + load functions
    // ==================================================================
    if (isset($requestData['options']) && is_array($requestData['options'])) {
        $rawOptions = $requestData['options'];
    } else {
        $rawOptions = [];
    }

    $options = buildBarcodeOptionsFromRequest($rawOptions);

    loadQRGenerationFunctions();

    if (!function_exists('generateBarcodePreview')) {
        errorResponse(
            'Barcode preview service is unavailable. Please try again later.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 3: Generate the preview
    // ==================================================================
    $result = generateBarcodePreview($symbology, $barcodeData, $options);

    if (!$result['success']) {
        if (isset($result['error'])) {
            $previewError = $result['error'];
        } else {
            $previewError = 'Unknown error.';
        }
        errorResponse(
            'Barcode preview failed: ' . $previewError,
            422,
            'GENERATION_FAILED'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 4: Build and send success response
    // ==================================================================
    $responseData = [
        'image'     => $result['data'],
        'mime_type' => $result['mimeType'],
        'symbology' => strtolower($symbology),
    ];

    successResponse($responseData, 'Barcode preview generated successfully.');
}

// ==============================================================================
// 🔧 buildBarcodeOptionsFromRequest() - Build a sanitised barcode options array
// ==============================================================================

/**
 * 🔧 Build a sanitised options array from a barcode API request's 'options' object.
 *
 * Mirrors buildCustomizationFromRequest()'s "sanitise + clamp to a sane
 * range, never reject a purely numeric/range field" convention (the QR
 * pipeline's 'size' field is clamped the same way, not rejected) - the
 * REJECTING validation this feature promises is reserved for the barcode
 * DATA itself (fn_barcode_generate.php's normalizeBarcodeData(), which
 * throws on an invalid check digit/charset/length), not these rendering
 * knobs.
 *
 * @param array $raw The raw 'options' object from the request
 *
 * @return array Sanitised options array ready for generateBarcode()/generateBarcodePreview()
 *
 * @see fn_barcode_generate.php - renderBarcodeToOutput() (consumes this shape)
 */
function buildBarcodeOptionsFromRequest(array $raw): array
{
    $options = [];

    // 📌 Output format: svg (default) or png. Any other value silently
    // falls back to 'svg', matching buildCustomizationFromRequest()'s
    // "unknown value -> default" convention for 'format'.
    if (isset($raw['format'])) {
        $format = strtolower(trim((string) $raw['format']));
    } else {
        $format = 'svg';
    }
    if ($format === 'svg' || $format === 'png') {
        $options['format'] = $format;
    } else {
        $options['format'] = 'svg';
    }

    // 📌 Module/bar width multiplier (int, 1-10).
    if (isset($raw['scale'])) {
        $scale = (int) $raw['scale'];
    } else {
        $scale = 2;
    }
    $options['scale'] = max(1, min(10, $scale));

    // 📌 Height: 1D bar height in px (10-300) OR 2D row multiplier (1-10) -
    // renderBarcodeToOutput() applies the category-specific interpretation
    // and default; absent here means "use that function's own default".
    if (isset($raw['height']) && $raw['height'] !== '' && $raw['height'] !== null) {
        $height = (int) $raw['height'];
        $options['height'] = max(1, min(300, $height));
    }

    // 📌 Render the human-readable payload text below the symbol (bool,
    // 1D symbologies only - a documented no-op for 2D).
    if (isset($raw['show_text'])) {
        $options['show_text'] = (bool) $raw['show_text'];
    } else {
        $options['show_text'] = false;
    }

    // 📌 Error-correction hint: PDF417 level (0-8) / Aztec percentage
    // (1-100) - a documented no-op for every other symbology.
    if (isset($raw['ecc']) && $raw['ecc'] !== '' && $raw['ecc'] !== null) {
        $options['ecc'] = (int) $raw['ecc'];
    }

    return $options;
}

// ==============================================================================
// 🔧 buildCustomizationFromRequest() - Build customization array from request
// ==============================================================================

/**
 * 🔧 Build a sanitised customization array from API request data
 *
 * Takes the raw customization object from the API request body and
 * produces a clean array with validated types and sensible defaults.
 * Unknown keys are ignored.
 *
 * @param array $raw The raw customization data from the request
 *
 * @return array Sanitised customization array ready for generateQRCode()
 *
 * @see fn_qr_generate.php - generateQRCode()
 * @see fn_qr_options.php - buildQROptions()
 */
function buildCustomizationFromRequest(array $raw): array
{
    // 📌 Build the customization array with defaults
    // Only recognised keys are transferred; unknown keys are discarded
    // to prevent injection of unexpected parameters.
    $customization = [];

    // 📌 Output format (string)
    // Supported: png, jpg, gif, webp, svg, pdf
    if (isset($raw['format'])) {
        $format = strtolower(trim($raw['format']));
    } else {
        $format = 'png';
    }
    $validFormats = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf'];
    if (in_array($format, $validFormats, true)) {
        $customization['format'] = $format;
    } else {
        $customization['format'] = 'png';
    }

    // 📌 Target size in pixels (int, 100-4000)
    if (isset($raw['size'])) {
        $size = (int) $raw['size'];
    } else {
        $size = 800;
    }
    $customization['size'] = max(100, min(4000, $size));

    // 📌 Error correction level (string: L, M, Q, H)
    if (isset($raw['ecc'])) {
        $ecc = strtoupper(trim($raw['ecc']));
    } else {
        $ecc = 'M';
    }
    $validEcc = ['L', 'M', 'Q', 'H'];
    if (in_array($ecc, $validEcc, true)) {
        $customization['ecc'] = $ecc;
    } else {
        $customization['ecc'] = 'M';
    }

    // 📌 Foreground colour (hex string)
    if (isset($raw['fg_color'])) {
        $customization['fg_color'] = $raw['fg_color'];
    } else {
        $customization['fg_color'] = '#000000';
    }

    // 📌 Background colour (hex string)
    if (isset($raw['bg_color'])) {
        $customization['bg_color'] = $raw['bg_color'];
    } else {
        $customization['bg_color'] = '#ffffff';
    }

    // 📌 Marker (finder pattern) colour (hex string, optional)
    if (isset($raw['marker_color']) && $raw['marker_color'] !== '') {
        $customization['marker_color'] = $raw['marker_color'];
    }

    // 📌 Module pattern/shape (string)
    if (isset($raw['pattern'])) {
        $pattern = strtolower(trim($raw['pattern']));
    } else {
        $pattern = 'square';
    }
    $validPatterns = ['square', 'round', 'dot'];
    if (in_array($pattern, $validPatterns, true)) {
        $customization['pattern'] = $pattern;
    } else {
        $customization['pattern'] = 'square';
    }

    // 📌 Marker (finder pattern) OUTER BORDER shape (string)
    // 🔧 PARITY (api-has-ui-missing): the UI exposes a finder-pattern outer
    //    border shape selector (#opt-marker-border) but the API previously
    //    dropped the value. We now accept + allowlist it so the API is a strict
    //    superset of the UI. Allowlist mirrors index.php: square|round|circle|
    //    extra-rounded. Unknown values fall back to the 'square' default rather
    //    than passing an untyped string through to the engine.
    if (isset($raw['marker_border'])) {
        $markerBorder = strtolower(trim((string) $raw['marker_border']));
    } else {
        $markerBorder = 'square';
    }
    $validMarkerBorders = ['square', 'round', 'circle', 'extra-rounded'];
    if (in_array($markerBorder, $validMarkerBorders, true)) {
        $customization['marker_border'] = $markerBorder;
    } else {
        $customization['marker_border'] = 'square';
    }

    // 📌 Marker (finder pattern) CENTER DOT shape (string)
    // 🔧 PARITY (api-has-ui-missing): the UI exposes a finder-pattern centre dot
    //    shape selector (#opt-marker-center); the API previously dropped it.
    //    Allowlist mirrors index.php: square|round|circle|diamond. Unknown ->
    //    'square'.
    if (isset($raw['marker_center'])) {
        $markerCenter = strtolower(trim((string) $raw['marker_center']));
    } else {
        $markerCenter = 'square';
    }
    $validMarkerCenters = ['square', 'round', 'circle', 'diamond'];
    if (in_array($markerCenter, $validMarkerCenters, true)) {
        $customization['marker_center'] = $markerCenter;
    } else {
        $customization['marker_center'] = 'square';
    }

    // 📌 Logo (mixed: int ID, string path, 'upload', or null)
    if (isset($raw['logo']) && $raw['logo'] !== '' && $raw['logo'] !== null) {
        $customization['logo'] = $raw['logo'];
    }

    // 📌 Custom logo upload data (base64 data URI) + its MIME type
    // 🔧 PARITY (api-has-ui-missing): when the UI uploads a custom logo it sends
    //    logo='upload' plus logo_data (a data:image/...;base64,... URI) and
    //    logo_mime. The generation pipeline resolves a logo via getLogoPath(),
    //    which understands a base64 data URI directly but NOT the sentinel
    //    string 'upload'. So when logo === 'upload' we substitute the data URI
    //    as the effective logo value (after a strict shape + MIME check), making
    //    the API accept the exact payload the UI emits.
    // @see fn_qr_logo.php - getLogoPath() / saveBase64LogoToTemp()
    // @see cuercode-generator.js - collectCustomization() (logo_data / logo_mime)
    $logoData = null;
    if (isset($raw['logo_data']) && is_string($raw['logo_data'])) {
        $logoDataCandidate = trim($raw['logo_data']);
        // 🔒 Only accept a well-formed image data URI; anything else is dropped
        //    rather than handed to the decoder. saveBase64LogoToTemp() applies
        //    the F-005 size/pixel caps after this point.
        if ($logoDataCandidate !== ''
            && preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $logoDataCandidate) === 1
        ) {
            $logoData = $logoDataCandidate;
            $customization['logo_data'] = $logoDataCandidate;
        }
    }

    // 📌 Logo MIME type (string, allowlisted image/* types)
    if (isset($raw['logo_mime']) && is_string($raw['logo_mime'])) {
        $logoMime = strtolower(trim($raw['logo_mime']));
        $validLogoMimes = [
            'image/png',
            'image/jpeg',
            'image/jpg',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ];
        if (in_array($logoMime, $validLogoMimes, true)) {
            $customization['logo_mime'] = $logoMime;
        }
    }

    // 📌 Resolve the 'upload' sentinel to the actual base64 data URI so the
    //    downstream getLogoPath() can decode it. If logo='upload' but no valid
    //    logo_data was supplied, drop the logo entirely (an 'upload' string on
    //    its own is meaningless to the engine and would resolve to null).
    if (isset($customization['logo'])
        && is_string($customization['logo'])
        && strtolower(trim($customization['logo'])) === 'upload'
    ) {
        if ($logoData !== null) {
            $customization['logo'] = $logoData;
        } else {
            unset($customization['logo']);
        }
    }

    // 📌 Logo size percentage (int, 5-40 to match overlayLogoOnRaster + UI)
    // 🔧 Lower bound aligned to 5 (was 10) so it matches the engine clamp in
    //    fn_qr_logo.php / fn_qr_options.php and the contract (5-40).
    if (isset($raw['logo_size_pct'])) {
        $logoSizePct = (int) $raw['logo_size_pct'];
        $customization['logo_size_pct'] = max(5, min(40, $logoSizePct));
    }

    // 📌 Frame style (string)
    if (isset($raw['frame_style']) && $raw['frame_style'] !== '') {
        $customization['frame_style'] = strtolower(trim($raw['frame_style']));
    }

    // 📌 Frame label text (string)
    if (isset($raw['frame_label']) && $raw['frame_label'] !== '') {
        $customization['frame_label'] = $raw['frame_label'];
    }

    // 📌 Frame colour (hex string)
    if (isset($raw['frame_color']) && $raw['frame_color'] !== '') {
        $customization['frame_color'] = $raw['frame_color'];
    }

    // 📌 Transparent background (bool)
    if (isset($raw['transparent'])) {
        $customization['transparent'] = (bool) $raw['transparent'];
    } else {
        $customization['transparent'] = false;
    }

    // 📌 Quiet zone toggle (bool, default true)
    // 🔧 PARITY (api-has-ui-missing): the UI sends quiet_zone (a checkbox,
    //    checked by default) but the API previously dropped it. We now accept +
    //    type-coerce it to a bool. buildQROptions() maps it to the chillerlan
    //    addQuietzone option so disabling the quiet zone in the UI takes effect
    //    through the API too. Default true mirrors both the UI default-checked
    //    state and the library default ($addQuietzone = true).
    // @see fn_qr_options.php - buildQROptions() (addQuietzone mapping)
    // @see cuercode-generator.js - collectCustomization() (quiet_zone)
    if (isset($raw['quiet_zone'])) {
        $customization['quiet_zone'] = (bool) $raw['quiet_zone'];
    } else {
        $customization['quiet_zone'] = true;
    }

    // 📌 QR code version (int 1-40, or 'auto'/absent => library auto-select)
    // 🔧 B-055 (ProjectBrief §4.5 — version/precision selection): the openapi
    //    spec documents an optional 'version' customisation param; we now accept
    //    it for real so the doc matches the impl (cycle-9 doc-vs-impl mismatch).
    //    Behaviour:
    //      - absent           => key omitted => buildQROptions() leaves the
    //                            library default (Version::AUTO) so the smallest
    //                            version that fits the payload at the chosen ECC
    //                            level is chosen automatically.
    //      - 'auto' (string)  => same as absent (explicit auto request).
    //      - integer 1..40    => coerced to int and passed through; buildQROptions()
    //                            sets QROptions->version to pin the module grid.
    //    Any out-of-range integer or non-numeric/non-'auto' value is treated as a
    //    hard validation error (surfaced by validateCustomization()), rather than
    //    being silently coerced — a caller asking for version 99 should be told
    //    it is invalid, not quietly given an auto-sized code. We store a sentinel
    //    so validateCustomization() can distinguish "absent/auto" (fine) from
    //    "present but invalid" (rejected).
    // @see fn_qr_options.php - buildQROptions() (version mapping)
    // @see web/CueRCode.net/_libraries/chillerlan/php-qrcode/src/Common/Version.php (AUTO = -1)
    if (isset($raw['version'])) {
        $versionRaw = $raw['version'];

        // 📌 Normalise an 'auto' string (any case / whitespace) to "no pin".
        if (is_string($versionRaw) && strtolower(trim($versionRaw)) === 'auto') {
            // 🟢 Explicit auto => leave the key unset (library auto-selects).
            $versionResolved = null;
        } elseif (is_int($versionRaw) || (is_string($versionRaw) && preg_match('/^-?\d+$/', trim($versionRaw)) === 1)) {
            // 🔢 An integer (or a clean integer string from JSON/form). Coerce to
            //    int; range is validated by validateCustomization() so an out-of
            //    -range value becomes a 400 rather than a clamp.
            $versionResolved = (int) $versionRaw;
        } else {
            // ❌ Neither 'auto' nor an integer — record it verbatim so
            //    validateCustomization() rejects it (no untyped pass-through).
            $versionResolved = $versionRaw;
        }

        if ($versionResolved !== null) {
            $customization['version'] = $versionResolved;
        }
    }

    // 📌 Gradient (array, SVG only)
    if (isset($raw['gradient']) && is_array($raw['gradient'])) {
        $customization['gradient'] = $raw['gradient'];
    }

    // 📌 Accessibility preset (string enum: none, accessible) - E5 (#48)
    // 🔧 PARITY: the UI's "Accessible QR" toggle sends preset=accessible; the
    //    API accepts + allowlists it so it is a strict superset of the UI.
    //    'none' (the default) preserves EXACTLY today's behaviour - the value
    //    is only ever inspected by applyAccessiblePreset() (fn_qr_options.php),
    //    which is a complete no-op for anything other than 'accessible'.
    // @see fn_qr_options.php - applyAccessiblePreset()
    // @see cuercode-generator.js - collectCustomization() (preset)
    if (isset($raw['preset'])) {
        $preset = strtolower(trim((string) $raw['preset']));
    } else {
        $preset = 'none';
    }
    $validPresets = ['none', 'accessible'];
    if (in_array($preset, $validPresets, true)) {
        $customization['preset'] = $preset;
    } else {
        $customization['preset'] = 'none';
    }

    // 📌 Intended scan distance in centimetres (int, optional) - E5 (#48)
    // 🔧 Used only to compute the physical print-size recommendation (the
    //    10:1 rule, @see qrRecommendedPrintSizeMm()). Absent/blank means "no
    //    recommendation requested". Clamped to a sane positive range (1cm to
    //    100m) - a defensive bound, not a UX limit; the calculator itself
    //    still applies its own 20mm floor at the small end.
    // @see fn_qr_options.php - qrRecommendedPrintSizeMm(), applyAccessiblePreset()
    // @see cuercode-generator.js - collectCustomization() (scan_distance_cm)
    if (isset($raw['scan_distance_cm']) && $raw['scan_distance_cm'] !== '' && $raw['scan_distance_cm'] !== null) {
        $scanDistanceCm = (int) $raw['scan_distance_cm'];
        $customization['scan_distance_cm'] = max(1, min(10000, $scanDistanceCm));
    }

    // 📌 PDF print layout (string enum) - E5 (#49). Only meaningful when
    //    format=pdf; harmless/ignored for any other format (mirrors how
    //    frame_style documents "not applied to PDF output" in reverse).
    //    'standard' (default) preserves today's PDF output byte-for-byte -
    //    fn_qr_generate.php only calls into fn_qr_accessible_pdf.php for any
    //    OTHER value. Kept as an inline allowlist (not a call into
    //    fn_qr_accessible_pdf.php's own normalisePdfLayout()) because this
    //    function can run BEFORE loadQRGenerationFunctions() has loaded that
    //    file (@see handleGenerate() Step 2 vs Step 3 ordering).
    // @see fn_qr_accessible_pdf.php - pdfLayoutOptions(), renderAccessiblePdfLayout()
    // @see cuercode-generator.js - collectCustomization() (pdf_layout)
    if (isset($raw['pdf_layout'])) {
        $pdfLayout = strtolower(trim((string) $raw['pdf_layout']));
    } else {
        $pdfLayout = 'standard';
    }
    $validPdfLayouts = ['standard', 'accessible_caption', 'tactile', 'avery_5160', 'avery_l7160'];
    if (in_array($pdfLayout, $validPdfLayouts, true)) {
        $customization['pdf_layout'] = $pdfLayout;
    } else {
        $customization['pdf_layout'] = 'standard';
    }

    return $customization;
}

// ==============================================================================
// ✅ validateCustomization() - Validate customization values
// ==============================================================================

/**
 * ✅ Validate customization values for correctness
 *
 * Checks that colour values are valid hex codes and other parameters
 * are within acceptable ranges. Returns null if all values are valid,
 * or a string error message describing the first validation failure.
 *
 * @param array $customization The sanitised customization array
 *
 * @return string|null Null if valid, or an error message string
 *
 * @see fn_validation.php - validateColor()
 */
function validateCustomization(array $customization): ?string
{
    // 📌 Validate foreground colour
    if (isset($customization['fg_color']) && $customization['fg_color'] !== '') {
        if (function_exists('validateColor') && !validateColor($customization['fg_color'])) {
            return 'Invalid foreground colour: '
                . htmlspecialchars($customization['fg_color'], ENT_QUOTES, 'UTF-8')
                . '. Must be a valid hex colour code (e.g., #000000).';
        }
    }

    // 📌 Validate background colour
    if (isset($customization['bg_color']) && $customization['bg_color'] !== '') {
        if (function_exists('validateColor') && !validateColor($customization['bg_color'])) {
            return 'Invalid background colour: '
                . htmlspecialchars($customization['bg_color'], ENT_QUOTES, 'UTF-8')
                . '. Must be a valid hex colour code (e.g., #ffffff).';
        }
    }

    // 📌 Validate marker colour (if provided)
    if (isset($customization['marker_color']) && $customization['marker_color'] !== '') {
        if (function_exists('validateColor') && !validateColor($customization['marker_color'])) {
            return 'Invalid marker colour: '
                . htmlspecialchars($customization['marker_color'], ENT_QUOTES, 'UTF-8')
                . '. Must be a valid hex colour code.';
        }
    }

    // 📌 Validate frame colour (if provided)
    if (isset($customization['frame_color']) && $customization['frame_color'] !== '') {
        if (function_exists('validateColor') && !validateColor($customization['frame_color'])) {
            return 'Invalid frame colour: '
                . htmlspecialchars($customization['frame_color'], ENT_QUOTES, 'UTF-8')
                . '. Must be a valid hex colour code.';
        }
    }

    // 📌 Validate size range
    if (isset($customization['size'])) {
        $size = $customization['size'];
    } else {
        $size = 800;
    }
    if ($size < 100 || $size > 4000) {
        return 'Invalid size: ' . (int) $size
            . '. Must be between 100 and 4000 pixels.';
    }

    // 📌 Validate QR version (B-055). Only present when the caller pinned a
    //    version (absent/'auto' was normalised away in buildCustomizationFromRequest()).
    //    A present value MUST be an integer in 1..40; anything else is a 400.
    // @see buildCustomizationFromRequest() - 'version' handling
    if (isset($customization['version'])) {
        $version = $customization['version'];
        if (!is_int($version) || $version < 1 || $version > 40) {
            if (is_scalar($version)) {
                $versionDisplay = (string) $version;
            } else {
                $versionDisplay = gettype($version);
            }
            return 'Invalid version: '
                . htmlspecialchars($versionDisplay, ENT_QUOTES, 'UTF-8')
                . '. Must be an integer between 1 and 40, or "auto".';
        }
    }

    // ✅ All validations passed
    return null;
}

// ==============================================================================
// 📦 loadQRGenerationFunctions() - Load QR generation function files
// ==============================================================================

/**
 * 📦 Load the QR code generation function files
 *
 * Loads the QR generation function files that are not included by
 * the bootstrap (they are only needed for QR generation operations):
 *   - fn_qr_payloads.php        (payload builders)
 *   - fn_qr_options.php         (options/customization mapping)
 *   - fn_qr_accessible_pdf.php  (Braille/tactile/Avery PDF print layouts - E5/#49)
 *   - fn_qr_generate.php        (main generation orchestrator)
 *   - fn_qr_logo.php            (logo overlay functions)
 *   - fn_qr_frames.php          (frame rendering functions)
 *   - fn_barcode_generate.php   (non-QR 1D/2D barcode generation - #69; reuses
 *                                fn_qr_generate.php's saveQRCodeRecord()/
 *                                saveQRToFile()/updateQRCodeFilePath()/
 *                                generateUUIDv4() helpers, so it is loaded
 *                                here alongside them rather than lazily)
 *
 * Uses require_once so multiple calls are safe.
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/function.require-once.php
 */
function loadQRGenerationFunctions(): void
{
    // 📌 Determine the functions directory path
    // @see bootstrap.php - CUERCODE_FUNCTIONS_DIR constant
    $functionsDir = '';

    if (defined('CUERCODE_FUNCTIONS_DIR')) {
        $functionsDir = CUERCODE_FUNCTIONS_DIR;
    } else {
        // 📌 Fallback: determine path relative to this file
        $functionsDir = dirname(__FILE__) . DIRECTORY_SEPARATOR;
    }

    // 📌 Load each QR generation file
    // @see https://www.php.net/manual/en/function.require-once.php
    $qrFiles = [
        'fn_qr_payloads.php',
        'fn_qr_options.php',
        'fn_qr_accessible_pdf.php',
        'fn_qr_generate.php',
        'fn_qr_logo.php',
        'fn_qr_frames.php',
        'fn_barcode_generate.php',
    ];

    foreach ($qrFiles as $qrFile) {
        $filePath = $functionsDir . $qrFile;
        if (file_exists($filePath)) {
            require_once $filePath;
        }
    }
}
