<?php
/**
 * ==============================================================================
 * 📦 CueRCode - API Bulk QR Generation Handler (FG-003 / #32)
 * ==============================================================================
 *
 * Path: _functions/fn_api_bulk.php
 *
 * Provides the handler and pure helper functions for the synchronous bulk
 * QR code generation API endpoint:
 *
 *   POST /api/v1/bulk-generate  -> handleBulkGenerate()
 *
 * The endpoint accepts a batch of QR code generation requests in a single
 * call and streams back a ZIP archive containing every generated image. It
 * is deliberately SYNCHRONOUS and ANONYMOUS-CAPABLE: there is no job queue,
 * no tblBatchJobs table, and no per-item database persistence. Each item is
 * generated in-memory and written straight into the ZIP, so a caller (or the
 * web UI's bulk panel) gets an immediate download with no follow-up polling.
 *
 * Request body (JSON, Content-Type: application/json). Two shapes are
 * accepted; provide EITHER one:
 *
 *   1. An explicit item list:
 *      {
 *          "items": [
 *              { "type": "url",  "input": { "url": "https://example.com" } },
 *              { "type": "text", "input": { "text": "Hello" },
 *                "customization": { "format": "png", "size": 600 } }
 *          ],
 *          "customization": { "format": "svg" }   // optional shared defaults
 *      }
 *
 *   2. A CSV string (first non-empty line is a header row). The header MUST
 *      contain a "type" column and at least one input column. Any column
 *      whose name starts with "input." (e.g. "input.url") or that is not a
 *      reserved customization column is treated as an input field; reserved
 *      customization columns (format, size, ecc, fg_color, ...) are folded
 *      into that row's customization:
 *      {
 *          "csv": "type,input.url,format\nurl,https://a.com,png\nurl,https://b.com,svg",
 *          "customization": { "ecc": "H" }        // optional shared defaults
 *      }
 *
 * Per-item shared customization precedence (lowest -> highest):
 *   request-level "customization"  <  per-item/per-row "customization".
 *
 * Responses:
 *   - 200 application/zip   The bulk archive (attachment; cuercode-bulk.zip).
 *                           A manifest.txt inside records per-item outcomes,
 *                           so individual failures NEVER abort the whole batch.
 *   - 400 BULK_*            Malformed body / no items / bad item shape.
 *   - 413 PAYLOAD_TOO_LARGE Too many items (see api_bulk_max_items, default 100).
 *   - 415                   Wrong Content-Type (enforced by the router).
 *   - 429 RATE_LIMIT        Rate limited (enforced by the router).
 *   - 501 ZIP_UNAVAILABLE   The PHP zip extension is not installed.
 *
 * The pure helpers parseBulkInput() and validateBulkRequest() do NOT touch the
 * database, the filesystem, or any QR library, so they are unit-testable in
 * isolation (see tests/test_bulk.php).
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (FG-003 bulk generation)
 * @see         fn_api_generate.php - buildCustomizationFromRequest(), validateCustomization()
 * @see         fn_qr_generate.php  - generateQRCode()
 * @see         fn_response.php     - errorResponse()
 * @see         https://www.php.net/manual/en/class.ziparchive.php
 * @see         https://www.php.net/manual/en/function.str-getcsv.php
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
// 📌 Bulk generation limits
// ==============================================================================

/**
 * @var int Default maximum number of items accepted in a single bulk request.
 * Overridable via the 'api_bulk_max_items' setting. Bounds the synchronous
 * work (and ZIP size) a single request can trigger.
 */
if (!defined('CUERCODE_API_BULK_MAX_ITEMS_DEFAULT')) {
    define('CUERCODE_API_BULK_MAX_ITEMS_DEFAULT', 100);
}

/**
 * @var array Reserved customization column names for CSV parsing. A CSV column
 * whose (lower-cased) header matches one of these is folded into the row's
 * customization rather than treated as a QR input field. Mirrors the keys
 * recognised by buildCustomizationFromRequest() in fn_api_generate.php.
 */
if (!defined('CUERCODE_API_BULK_CSV_RESERVED')) {
    // 📌 Stored as a comma-separated string because PHP < 8.x cannot define()
    //    an array as easily across versions; we explode() it where needed.
    define(
        'CUERCODE_API_BULK_CSV_RESERVED',
        'format,size,ecc,fg_color,bg_color,marker_color,pattern,logo,'
        . 'logo_size_pct,frame_style,frame_label,frame_color,transparent'
    );
}

// ==============================================================================
// 🔢 getBulkMaxItems() - Resolve the configured max item count
// ==============================================================================

/**
 * 🔢 Resolve the maximum bulk item count from config (with a safe default).
 *
 * Reads the 'api_bulk_max_items' setting when getConfig() is available,
 * falling back to CUERCODE_API_BULK_MAX_ITEMS_DEFAULT. A non-positive or
 * absurd configured value is clamped to the default so a misconfiguration
 * cannot disable the cap or make it pathologically large.
 *
 * @return int The effective maximum item count (>= 1).
 *
 * @see https://www.php.net/manual/en/function.intval.php
 */
function getBulkMaxItems(): int
{
    $max = CUERCODE_API_BULK_MAX_ITEMS_DEFAULT;

    if (function_exists('getConfig')) {
        $configured = (int) getConfig('api_bulk_max_items', CUERCODE_API_BULK_MAX_ITEMS_DEFAULT);
        // 📌 Clamp to a sane range: at least 1, never more than 1000.
        if ($configured >= 1 && $configured <= 1000) {
            $max = $configured;
        }
    }

    return $max;
}

// ==============================================================================
// 🧹 parseBulkInput() - Normalise a bulk request body into an item list (PURE)
// ==============================================================================

/**
 * 🧹 Parse a bulk request body into a normalised list of items (no I/O).
 *
 * Accepts the decoded JSON request body and produces a flat list of items,
 * each shaped as:
 *   [ 'type' => string, 'input' => array, 'customization' => array ]
 *
 * Supports two input shapes (provide exactly one):
 *   - 'items' : a list of objects, each with type/input and optional
 *               customization.
 *   - 'csv'   : a CSV string whose first non-empty line is the header row.
 *
 * A request-level 'customization' object (when present) supplies SHARED
 * defaults that each item's own customization overrides key-by-key.
 *
 * This function performs NO database, filesystem, or QR-library work, so it is
 * fully unit-testable. It validates only the OUTER shape (that something
 * parseable was supplied); deep per-item validation is validateBulkRequest()'s
 * job.
 *
 * @param array $requestData The decoded JSON request body.
 *
 * @return array On success: [ 'items' => array<int, array> ].
 *               On failure: [ 'error' => string, 'code' => string, 'http' => int ].
 *
 * @see https://www.php.net/manual/en/function.str-getcsv.php
 *
 * @example
 *   $parsed = parseBulkInput(['items' => [['type' => 'url', 'input' => ['url' => 'https://a']]]]);
 *   // $parsed === ['items' => [['type' => 'url', 'input' => ['url' => 'https://a'], 'customization' => []]]]
 */
function parseBulkInput(array $requestData): array
{
    // 📌 Resolve the shared, request-level customization defaults (optional).
    if (isset($requestData['customization']) && is_array($requestData['customization'])) {
        $sharedCustomization = $requestData['customization'];
    } else {
        $sharedCustomization = [];
    }

    // 📌 Determine which shape was supplied. 'items' takes precedence; if it is
    //    present but not an array we report a shape error rather than silently
    //    falling through to CSV.
    $hasItems = array_key_exists('items', $requestData);
    $hasCsv   = array_key_exists('csv', $requestData);

    if (!$hasItems && !$hasCsv) {
        return [
            'error' => 'Bulk request must include either an "items" array or a "csv" string.',
            'code'  => 'BULK_NO_INPUT',
            'http'  => 400,
        ];
    }

    if ($hasItems) {
        return parseBulkItemsArray($requestData['items'], $sharedCustomization);
    }

    return parseBulkCsv($requestData['csv'], $sharedCustomization);
}

// ==============================================================================
// 🧩 parseBulkItemsArray() - Normalise an explicit items array (PURE)
// ==============================================================================

/**
 * 🧩 Normalise an explicit 'items' array into the canonical item list.
 *
 * @param mixed $items               The raw 'items' value from the request.
 * @param array $sharedCustomization Request-level shared customization defaults.
 *
 * @return array On success: [ 'items' => array ]. On failure: structured error.
 */
function parseBulkItemsArray(mixed $items, array $sharedCustomization): array
{
    // ❌ 'items' must be an array (and a non-empty, list-like one).
    if (!is_array($items)) {
        return [
            'error' => 'Field "items" must be an array of QR code requests.',
            'code'  => 'BULK_INVALID_ITEMS',
            'http'  => 400,
        ];
    }

    if (count($items) === 0) {
        return [
            'error' => 'Field "items" must contain at least one QR code request.',
            'code'  => 'BULK_EMPTY',
            'http'  => 400,
        ];
    }

    $normalised = [];

    foreach ($items as $index => $item) {
        // ❌ Each item must be an object/associative array.
        if (!is_array($item)) {
            return [
                'error' => 'Item at index ' . (int) $index . ' must be an object with "type" and "input".',
                'code'  => 'BULK_INVALID_ITEM',
                'http'  => 400,
            ];
        }

        // 📌 Type (string, required - deep-checked by validateBulkRequest()).
        if (isset($item['type'])) {
            $type = $item['type'];
        } else {
            $type = '';
        }

        // 📌 Input (object/array, required - deep-checked downstream).
        if (isset($item['input']) && is_array($item['input'])) {
            $input = $item['input'];
        } else {
            $input = [];
        }

        // 📌 Per-item customization overrides the shared request-level defaults.
        if (isset($item['customization']) && is_array($item['customization'])) {
            $itemCustomization = array_merge($sharedCustomization, $item['customization']);
        } else {
            $itemCustomization = $sharedCustomization;
        }

        $normalised[] = [
            'type'          => $type,
            'input'         => $input,
            'customization' => $itemCustomization,
        ];
    }

    return ['items' => $normalised];
}

// ==============================================================================
// 📄 parseBulkCsv() - Normalise a CSV string into the canonical item list (PURE)
// ==============================================================================

/**
 * 📄 Parse a CSV string into the canonical bulk item list.
 *
 * The first non-empty line is the header row. It MUST contain a "type" column
 * and at least one input column. Columns are classified as:
 *   - "type"                         -> the item type
 *   - a reserved customization name  -> folded into the row's customization
 *     (format, size, ecc, fg_color, bg_color, marker_color, pattern, logo,
 *      logo_size_pct, frame_style, frame_label, frame_color, transparent)
 *   - "input.<field>"                -> input field <field> (explicit prefix)
 *   - anything else                  -> input field (bare name)
 *
 * Empty cells are skipped (so an item only carries the input fields it
 * actually populates). Blank lines are ignored.
 *
 * @param mixed $csv                 The raw 'csv' value from the request.
 * @param array $sharedCustomization Request-level shared customization defaults.
 *
 * @return array On success: [ 'items' => array ]. On failure: structured error.
 *
 * @see https://www.php.net/manual/en/function.str-getcsv.php
 */
function parseBulkCsv(mixed $csv, array $sharedCustomization): array
{
    // ❌ CSV must be a string.
    if (!is_string($csv) || trim($csv) === '') {
        return [
            'error' => 'Field "csv" must be a non-empty CSV string.',
            'code'  => 'BULK_INVALID_CSV',
            'http'  => 400,
        ];
    }

    // 📌 Split into lines, normalising CRLF / CR to LF first.
    $normalised = str_replace(["\r\n", "\r"], "\n", $csv);
    $lines      = explode("\n", $normalised);

    // 📌 Reserved customization column names (lower-cased) for classification.
    $reserved = explode(',', CUERCODE_API_BULK_CSV_RESERVED);

    // 📌 Locate the header row (first non-blank line) and parse its columns.
    $headers   = null;
    $dataLines = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        if ($headers === null) {
            // 📌 str_getcsv() handles quoted fields / embedded commas correctly.
            $rawHeaders = str_getcsv($line, ',', '"', '\\');
            $headers    = [];
            foreach ($rawHeaders as $h) {
                $headers[] = strtolower(trim((string) $h));
            }
            continue;
        }

        $dataLines[] = $line;
    }

    // ❌ A header row is required.
    if ($headers === null || count($headers) === 0) {
        return [
            'error' => 'CSV must contain a header row with a "type" column.',
            'code'  => 'BULK_CSV_NO_HEADER',
            'http'  => 400,
        ];
    }

    // ❌ A "type" column is required.
    $typeIndex = array_search('type', $headers, true);
    if ($typeIndex === false) {
        return [
            'error' => 'CSV header must include a "type" column.',
            'code'  => 'BULK_CSV_NO_TYPE',
            'http'  => 400,
        ];
    }

    // ❌ At least one input column is required (a non-type, non-reserved column,
    //    or any column explicitly prefixed with "input.").
    $hasInputColumn = false;
    foreach ($headers as $colIndex => $colName) {
        if ($colIndex === $typeIndex) {
            continue;
        }
        if (str_starts_with($colName, 'input.')) {
            $hasInputColumn = true;
            break;
        }
        if (!in_array($colName, $reserved, true)) {
            $hasInputColumn = true;
            break;
        }
    }

    if (!$hasInputColumn) {
        return [
            'error' => 'CSV header must include at least one input column besides "type".',
            'code'  => 'BULK_CSV_NO_INPUT',
            'http'  => 400,
        ];
    }

    // ❌ At least one data row is required.
    if (count($dataLines) === 0) {
        return [
            'error' => 'CSV must contain at least one data row beneath the header.',
            'code'  => 'BULK_EMPTY',
            'http'  => 400,
        ];
    }

    // 📌 Build the item list, row by row.
    $items = [];

    foreach ($dataLines as $line) {
        $cells = str_getcsv($line, ',', '"', '\\');

        $type              = '';
        $input             = [];
        $rowCustomization  = [];

        foreach ($headers as $colIndex => $colName) {
            // 📌 Tolerate short rows (missing trailing cells -> empty value).
            if (isset($cells[$colIndex])) {
                $value = trim((string) $cells[$colIndex]);
            } else {
                $value = '';
            }

            if ($colIndex === $typeIndex) {
                $type = $value;
                continue;
            }

            // 📌 Skip empty cells so we do not inject blank input fields.
            if ($value === '') {
                continue;
            }

            if (str_starts_with($colName, 'input.')) {
                // 📌 Explicit input field, prefix stripped.
                $fieldName = substr($colName, strlen('input.'));
                if ($fieldName !== '') {
                    $input[$fieldName] = $value;
                }
                continue;
            }

            if (in_array($colName, $reserved, true)) {
                // 📌 Reserved customization column.
                $rowCustomization[$colName] = $value;
                continue;
            }

            // 📌 Otherwise treat as a bare input field.
            $input[$colName] = $value;
        }

        // 📌 Per-row customization overrides shared request-level defaults.
        $items[] = [
            'type'          => $type,
            'input'         => $input,
            'customization' => array_merge($sharedCustomization, $rowCustomization),
        ];
    }

    return ['items' => $items];
}

// ==============================================================================
// ✅ validateBulkRequest() - Validate a normalised item list (PURE)
// ==============================================================================

/**
 * ✅ Validate a normalised bulk item list against the cap and per-item shape.
 *
 * Enforces:
 *   1. The total item count does not exceed $maxItems (-> 413).
 *   2. Each item has a non-empty string "type".
 *   3. Each item has a non-empty "input" object/array.
 *
 * Returns null when every item is valid, or a structured error array
 * (matching the parse helpers' shape) describing the FIRST problem found. This
 * function performs NO database / filesystem / QR-library work.
 *
 * @param array $items    The normalised item list from parseBulkInput().
 * @param int   $maxItems The maximum number of items permitted.
 *
 * @return array|null Null if valid; otherwise [ 'error', 'code', 'http' ].
 */
function validateBulkRequest(array $items, int $maxItems): ?array
{
    // ❌ Must contain at least one item.
    if (count($items) === 0) {
        return [
            'error' => 'Bulk request contains no items to generate.',
            'code'  => 'BULK_EMPTY',
            'http'  => 400,
        ];
    }

    // ❌ Enforce the maximum item count (413 Payload Too Large).
    if (count($items) > $maxItems) {
        return [
            'error' => 'Too many items in bulk request: ' . count($items)
                . '. Maximum allowed is ' . $maxItems . '.',
            'code'  => 'BULK_TOO_MANY_ITEMS',
            'http'  => 413,
        ];
    }

    // ❌ Validate each item's shape.
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            return [
                'error' => 'Item at index ' . (int) $index . ' is malformed.',
                'code'  => 'BULK_INVALID_ITEM',
                'http'  => 400,
            ];
        }

        // 📌 Type must be a non-empty string.
        if (!isset($item['type']) || !is_string($item['type']) || trim($item['type']) === '') {
            return [
                'error' => 'Item at index ' . (int) $index
                    . ' is missing a valid "type".',
                'code'  => 'BULK_ITEM_MISSING_TYPE',
                'http'  => 400,
            ];
        }

        // 📌 Input must be a non-empty array.
        if (!isset($item['input']) || !is_array($item['input']) || count($item['input']) === 0) {
            return [
                'error' => 'Item at index ' . (int) $index
                    . ' (type "' . $item['type'] . '") is missing "input" data.',
                'code'  => 'BULK_ITEM_MISSING_INPUT',
                'http'  => 400,
            ];
        }
    }

    // ✅ All items are well-formed.
    return null;
}

// ==============================================================================
// 🔡 bulkBuildFilename() - Build a safe per-item filename inside the ZIP (PURE)
// ==============================================================================

/**
 * 🔡 Build a safe, unique filename for an item's image inside the ZIP.
 *
 * Produces "{NNN}-{type}.{ext}" where NNN is the 1-based, zero-padded item
 * index and {type} is sanitised to [a-z0-9_-]. The leading index guarantees
 * uniqueness even when many items share a type.
 *
 * @param int    $oneBasedIndex The 1-based item index.
 * @param string $type          The item type (used in the name).
 * @param string $extension     The file extension WITHOUT a leading dot.
 *
 * @return string The ZIP entry filename.
 *
 * @see https://www.php.net/manual/en/function.preg-replace.php
 */
function bulkBuildFilename(int $oneBasedIndex, string $type, string $extension): string
{
    // 📌 Sanitise the type into a filesystem/zip-safe slug.
    $slug = strtolower(trim($type));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
    if ($slug === null || $slug === '') {
        $slug = 'qr';
    }
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'qr';
    }

    // 📌 Sanitise the extension similarly (default png).
    $ext = strtolower(trim($extension));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    if ($ext === null || $ext === '') {
        $ext = 'png';
    }

    return sprintf('%03d-%s.%s', $oneBasedIndex, $slug, $ext);
}

// ==============================================================================
// 🎯 handleBulkGenerate() - Handle a bulk QR generation API request
// ==============================================================================

/**
 * 🎯 Handle a synchronous bulk QR generation request and stream a ZIP.
 *
 * Pipeline:
 *   1. Require the PHP zip extension (else 501).
 *   2. Read + decode the JSON request body (size/Content-Type guards are the
 *      router's responsibility, mirroring the other POST endpoints).
 *   3. parseBulkInput() -> normalised item list (or structured error).
 *   4. validateBulkRequest() -> enforce cap + per-item shape (or error).
 *   5. Load the QR generation functions and loop generateQRCode() IN-MEMORY
 *      for each item (save_file = false so nothing is written to disk; the DB
 *      record save inside generateQRCode() is best-effort and never required).
 *   6. Assemble a ZIP: one image per successful item, plus a manifest.txt that
 *      records every item's outcome. Per-item failures are recorded in the
 *      manifest rather than aborting the batch.
 *   7. Stream the ZIP with application/zip + attachment Content-Disposition.
 *
 * @param array $auth The authentication context from the router (unused for
 *                    generation itself, but accepted so the handler signature
 *                    matches the router's auth-aware dispatch and future
 *                    per-key behaviour can hook in).
 *
 * @return void (streams the ZIP and exits, or sends an error response)
 *
 * @see fn_qr_generate.php  - generateQRCode()
 * @see fn_api_generate.php - buildCustomizationFromRequest(), validateCustomization()
 * @see https://www.php.net/manual/en/class.ziparchive.php
 */
function handleBulkGenerate(array $auth): void
{
    // ==================================================================
    // 📌 Step 1: Require the zip extension (else 501 Not Implemented).
    // @see https://www.php.net/manual/en/function.extension-loaded.php
    // ==================================================================
    if (!extension_loaded('zip')) {
        errorResponse(
            'Bulk ZIP generation is unavailable: the server is missing the PHP "zip" extension.',
            501,
            'ZIP_UNAVAILABLE'
        );
        return; // @codeCoverageIgnore - errorResponse exits
    }

    // ==================================================================
    // 📌 Step 2: Read + decode the JSON request body.
    // The router already enforces the body-size cap (413) and JSON
    // Content-Type (415) for POST bodies; we re-read here so the handler
    // is self-contained and testable, and decode defensively.
    // @see https://www.php.net/manual/en/function.json-decode.php
    // ==================================================================
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false) {
        $rawInput = '';
    }

    if (trim($rawInput) === '') {
        errorResponse(
            'Bulk request body is empty. Provide an "items" array or a "csv" string.',
            400,
            'BULK_NO_INPUT'
        );
        return;
    }

    $requestData = json_decode($rawInput, true);
    if ($requestData === null && json_last_error() !== JSON_ERROR_NONE) {
        errorResponse(
            'Invalid JSON in request body: ' . json_last_error_msg(),
            400,
            'INVALID_JSON'
        );
        return;
    }
    if (!is_array($requestData)) {
        errorResponse(
            'Bulk request body must be a JSON object.',
            400,
            'BULK_INVALID_BODY'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 3: Parse the body into a normalised item list.
    // ==================================================================
    $parsed = parseBulkInput($requestData);

    if (isset($parsed['error'])) {
        if (isset($parsed['http'])) {
            $httpCode = (int) $parsed['http'];
        } else {
            $httpCode = 400;
        }
        if (isset($parsed['code'])) {
            $errorCode = (string) $parsed['code'];
        } else {
            $errorCode = 'BULK_PARSE_ERROR';
        }
        errorResponse((string) $parsed['error'], $httpCode, $errorCode);
        return;
    }

    if (isset($parsed['items']) && is_array($parsed['items'])) {
        $items = $parsed['items'];
    } else {
        $items = [];
    }

    // ==================================================================
    // 📌 Step 4: Validate the item list (cap + per-item shape).
    // ==================================================================
    $maxItems       = getBulkMaxItems();
    $validationError = validateBulkRequest($items, $maxItems);

    if ($validationError !== null) {
        if (isset($validationError['http'])) {
            $httpCode = (int) $validationError['http'];
        } else {
            $httpCode = 400;
        }
        if (isset($validationError['code'])) {
            $errorCode = (string) $validationError['code'];
        } else {
            $errorCode = 'BULK_VALIDATION_ERROR';
        }
        errorResponse((string) $validationError['error'], $httpCode, $errorCode);
        return;
    }

    // ==================================================================
    // 📌 Step 5: Load the QR generation functions.
    // loadQRGenerationFunctions() lives in fn_api_generate.php (loaded by the
    // router alongside this file). Guard in case of an unexpected load order.
    // ==================================================================
    if (function_exists('loadQRGenerationFunctions')) {
        loadQRGenerationFunctions();
    }

    if (!function_exists('generateQRCode')) {
        errorResponse(
            'QR code generation service is unavailable. Please try again later.',
            503,
            'SERVICE_UNAVAILABLE'
        );
        return;
    }

    // ==================================================================
    // 📌 Step 6: Generate each item in-memory and build the ZIP.
    // ==================================================================

    // 📌 Create a unique temp file for the archive. ZipArchive needs a path;
    //    we stream + unlink it afterwards.
    // @see https://www.php.net/manual/en/function.tempnam.php
    $tmpDir  = sys_get_temp_dir();
    $tmpFile = tempnam($tmpDir, 'cuercode-bulk-');

    if ($tmpFile === false) {
        errorResponse(
            'Failed to allocate a temporary file for the bulk archive.',
            500,
            'BULK_TEMP_FAILED'
        );
        return;
    }

    $zip = new \ZipArchive();
    // @see https://www.php.net/manual/en/ziparchive.open.php
    if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        errorResponse(
            'Failed to create the bulk ZIP archive.',
            500,
            'BULK_ZIP_FAILED'
        );
        return;
    }

    // 📌 Manifest lines (one per item) recording the per-item outcome.
    $manifestLines = [];
    $manifestLines[] = 'CueRCode bulk generation manifest';
    $manifestLines[] = 'Generated: ' . gmdate('Y-m-d\TH:i:s+00:00');
    $manifestLines[] = 'Items requested: ' . count($items);
    $manifestLines[] = str_repeat('-', 60);

    $successCount = 0;
    $failureCount = 0;

    foreach ($items as $index => $item) {
        $oneBased = (int) $index + 1;
        $type     = (string) $item['type'];
        $input    = $item['input'];

        // 📌 Build a sanitised customization for this item, forcing save_file
        //    off so the in-memory loop never writes to disk.
        if (isset($item['customization']) && is_array($item['customization'])) {
            $rawCustomization = $item['customization'];
        } else {
            $rawCustomization = [];
        }

        if (function_exists('buildCustomizationFromRequest')) {
            $customization = buildCustomizationFromRequest($rawCustomization);
        } else {
            $customization = $rawCustomization;
        }
        $customization['save_file'] = false;

        // 📌 Validate this item's customization (colours, size range).
        if (function_exists('validateCustomization')) {
            $customizationError = validateCustomization($customization);
        } else {
            $customizationError = null;
        }

        if ($customizationError !== null) {
            $failureCount++;
            $manifestLines[] = sprintf(
                '[%03d] FAILED  type=%s  reason=%s',
                $oneBased,
                $type,
                $customizationError
            );
            continue;
        }

        // 📌 Generate the QR code in-memory. generateQRCode() returns the image
        //    as a base64 data URI for raster formats, or raw markup for SVG/PDF.
        try {
            $result = generateQRCode($type, $input, $customization);
        } catch (\Throwable $e) {
            $failureCount++;
            $manifestLines[] = sprintf(
                '[%03d] FAILED  type=%s  reason=%s',
                $oneBased,
                $type,
                'Exception: ' . $e->getMessage()
            );
            continue;
        }

        if (!is_array($result) || empty($result['success'])) {
            if (is_array($result) && isset($result['error'])) {
                $reason = (string) $result['error'];
            } else {
                $reason = 'Unknown generation error.';
            }
            $failureCount++;
            $manifestLines[] = sprintf(
                '[%03d] FAILED  type=%s  reason=%s',
                $oneBased,
                $type,
                $reason
            );
            continue;
        }

        // 📌 Resolve the output format/extension to name the ZIP entry and to
        //    decide whether the data is base64 or raw.
        if (isset($customization['format'])) {
            $format = strtolower(trim((string) $customization['format']));
        } else {
            $format = 'png';
        }

        $extension = $format;
        if ($format === 'jpeg') {
            $extension = 'jpg';
        }

        // 📌 Extract the raw bytes from the result's 'data' field.
        $data = (string) $result['data'];
        if (str_starts_with($data, 'data:')) {
            // 📌 base64 data URI: strip the "data:<mime>;base64," prefix and decode.
            $commaPos = strpos($data, ',');
            if ($commaPos !== false) {
                $base64 = substr($data, $commaPos + 1);
                $bytes  = base64_decode($base64, true);
                if ($bytes === false) {
                    $bytes = '';
                }
            } else {
                $bytes = '';
            }
        } else {
            // 📌 Raw SVG / PDF markup is already the file content.
            $bytes = $data;
        }

        if ($bytes === '') {
            $failureCount++;
            $manifestLines[] = sprintf(
                '[%03d] FAILED  type=%s  reason=%s',
                $oneBased,
                $type,
                'Generated image was empty.'
            );
            continue;
        }

        $entryName = bulkBuildFilename($oneBased, $type, $extension);
        $zip->addFromString($entryName, $bytes);

        $successCount++;
        $manifestLines[] = sprintf(
            '[%03d] OK      type=%-10s file=%s',
            $oneBased,
            $type,
            $entryName
        );
    }

    // 📌 Append the summary + manifest to the ZIP.
    $manifestLines[] = str_repeat('-', 60);
    $manifestLines[] = 'Succeeded: ' . $successCount . '  Failed: ' . $failureCount;

    $zip->addFromString('manifest.txt', implode("\n", $manifestLines) . "\n");

    $zip->close();

    // ==================================================================
    // 📌 Step 7: Log the activity (best-effort) and stream the ZIP.
    // ==================================================================
    if (function_exists('logActivity')) {
        if (isset($auth['method'])) {
            $authMethod = (string) $auth['method'];
        } else {
            $authMethod = 'anonymous';
        }
        logActivity(
            'api_bulk_generate',
            'API: Bulk generated ' . $successCount . ' QR code(s) ('
                . $failureCount . ' failed) in a ZIP.',
            'qr_code',
            null,
            [
                'requested' => count($items),
                'succeeded' => $successCount,
                'failed'    => $failureCount,
                'authType'  => $authMethod,
                'endpoint'  => 'bulk-generate',
            ]
        );
    }

    // 📌 Read the assembled archive back into memory for streaming.
    $zipBytes = @file_get_contents($tmpFile);
    @unlink($tmpFile);

    if ($zipBytes === false) {
        errorResponse(
            'Failed to read the assembled bulk archive.',
            500,
            'BULK_READ_FAILED'
        );
        return;
    }

    // 📌 Stream the ZIP with download headers. We send these directly (the
    //    response is binary, not JSON, so jsonResponse() is not used).
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="cuercode-bulk.zip"');
        header('Content-Length: ' . strlen($zipBytes));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        // 📌 Surface batch counts in headers for non-UI consumers.
        header('X-Bulk-Succeeded: ' . $successCount);
        header('X-Bulk-Failed: ' . $failureCount);
        if (function_exists('emitCorsHeaders')) {
            emitCorsHeaders();
        }
    }

    echo $zipBytes;
    exit;
}
