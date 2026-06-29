<?php
/**
 * ==============================================================================
 * 🎯 CueRCode - API Generate & Preview Handlers
 * ==============================================================================
 *
 * Provides handler functions for the QR code generation and preview API
 * endpoints. These functions process incoming JSON request data, validate
 * inputs, delegate to the core QR generation pipeline, and return
 * structured API responses.
 *
 * Endpoints handled:
 *   - POST /api/v1/generate  -> handleGenerate()   Full QR code generation
 *   - POST /api/v1/preview   -> handlePreview()     Quick preview generation
 *
 * Both handlers expect a JSON request body with the following structure:
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
 *           "pattern": "square",
 *           "logo": 5,
 *           "logo_size_pct": 20,
 *           "frame_style": "none",
 *           "frame_label": "",
 *           "frame_color": "",
 *           "transparent": false
 *       }
 *   }
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
    // 📌 Step 3: Validate customization values
    // ==================================================================
    $validationError = validateCustomization($customization);

    if ($validationError !== null) {
        errorResponse($validationError, 400, 'INVALID_CUSTOMIZATION');
        return;
    }

    // ==================================================================
    // 📌 Step 4: Load QR generation functions if not already loaded
    // The QR generation files are not loaded by bootstrap.php by default
    // since they are only needed for QR code operations.
    // @see fn_qr_payloads.php, fn_qr_options.php, fn_qr_generate.php
    // ==================================================================
    loadQRGenerationFunctions();

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
    // 📌 Step 3: Validate customization values
    // ==================================================================
    $validationError = validateCustomization($customization);

    if ($validationError !== null) {
        errorResponse($validationError, 400, 'INVALID_CUSTOMIZATION');
        return;
    }

    // ==================================================================
    // 📌 Step 4: Load QR generation functions
    // ==================================================================
    loadQRGenerationFunctions();

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

    successResponse($responseData, 'QR code preview generated successfully.');
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

    // 📌 Logo (mixed: int ID, string path, or null)
    if (isset($raw['logo']) && $raw['logo'] !== '' && $raw['logo'] !== null) {
        $customization['logo'] = $raw['logo'];
    }

    // 📌 Logo size percentage (int, 10-40)
    if (isset($raw['logo_size_pct'])) {
        $logoSizePct = (int) $raw['logo_size_pct'];
        $customization['logo_size_pct'] = max(10, min(40, $logoSizePct));
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

    // 📌 Gradient (array, SVG only)
    if (isset($raw['gradient']) && is_array($raw['gradient'])) {
        $customization['gradient'] = $raw['gradient'];
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

    // ✅ All validations passed
    return null;
}

// ==============================================================================
// 📦 loadQRGenerationFunctions() - Load QR generation function files
// ==============================================================================

/**
 * 📦 Load the QR code generation function files
 *
 * Loads the four QR generation function files that are not included by
 * the bootstrap (they are only needed for QR generation operations):
 *   - fn_qr_payloads.php  (payload builders)
 *   - fn_qr_options.php   (options/customization mapping)
 *   - fn_qr_generate.php  (main generation orchestrator)
 *   - fn_qr_logo.php      (logo overlay functions)
 *   - fn_qr_frames.php    (frame rendering functions)
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
        'fn_qr_generate.php',
        'fn_qr_logo.php',
        'fn_qr_frames.php',
    ];

    foreach ($qrFiles as $qrFile) {
        $filePath = $functionsDir . $qrFile;
        if (file_exists($filePath)) {
            require_once $filePath;
        }
    }
}
