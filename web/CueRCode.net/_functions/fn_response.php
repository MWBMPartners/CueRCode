<?php
/**
 * ==============================================================================
 * 📤 CueRCode - Response Formatting
 * ==============================================================================
 *
 * Provides standardised functions for sending HTTP responses in JSON and
 * XML formats. Used by both the REST API and internal AJAX endpoints to
 * ensure consistent response structure and proper HTTP headers.
 *
 * Response structure (JSON):
 *   {
 *       "success": true|false,
 *       "message": "Human-readable message",
 *       "data": { ... },
 *       "error_code": "OPTIONAL_ERROR_CODE",
 *       "timestamp": "2026-02-27T12:00:00+00:00"
 *   }
 *
 * XML responses include an XSLT stylesheet reference for browser rendering
 * and follow the same logical structure as JSON.
 *
 * @package     CueRCode
 * @subpackage  API
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/function.json-encode.php
 * @see         https://www.php.net/manual/en/class.simplexmlelement.php
 * @see         https://www.php.net/manual/en/function.header.php
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
// 📋 jsonResponse() - Send a JSON response
// ==============================================================================

/**
 * 📋 Send a JSON HTTP response with appropriate headers
 *
 * Outputs the provided data array as a JSON-encoded response body with
 * Content-Type, cache control, and CORS headers. Adds a server timestamp
 * to the response. Terminates script execution after output.
 *
 * @param array $data     The response data array to encode as JSON
 * @param int   $httpCode The HTTP status code (default: 200)
 *
 * @return void (script execution terminates via exit)
 *
 * @see https://www.php.net/manual/en/function.json-encode.php
 * @see https://www.php.net/manual/en/function.http-response-code.php
 * @see https://www.php.net/manual/en/function.header.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
 *
 * @example
 *   jsonResponse([
 *       'success' => true,
 *       'message' => 'QR code generated successfully',
 *       'data'    => ['uuid' => 'abc-123', 'format' => 'png']
 *   ], 201);
 */
function jsonResponse(array $data, int $httpCode = 200): void
{
    // 📌 Add server timestamp to the response if not already present
    // Uses ISO 8601 format with timezone for unambiguous parsing.
    // @see https://www.php.net/manual/en/function.date.php
    // @see https://www.php.net/manual/en/class.datetimeinterface.php#datetime.constants.atom
    if (!isset($data['timestamp'])) {
        $data['timestamp'] = gmdate('Y-m-d\TH:i:s+00:00');
    }

    // 📌 Encode the data as JSON
    // JSON_UNESCAPED_UNICODE: Don't escape Unicode characters (e.g., emojis)
    // JSON_UNESCAPED_SLASHES: Don't escape forward slashes in URLs
    // JSON_PRETTY_PRINT: Format with whitespace for readability (minimal overhead)
    // @see https://www.php.net/manual/en/function.json-encode.php
    // @see https://www.php.net/manual/en/json.constants.php
    $jsonOutput = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    // ❌ Handle JSON encoding failure
    if ($jsonOutput === false) {
        // @see https://www.php.net/manual/en/function.json-last-error-msg.php
        $fallback = json_encode([
            'success'   => false,
            'message'   => 'Failed to encode response data as JSON.',
            'error_code' => 'JSON_ENCODE_ERROR',
            'timestamp' => gmdate('Y-m-d\TH:i:s+00:00'),
        ]);

        if (function_exists('logError')) {
            logError(
                'error',
                'JSON encoding failed in jsonResponse()',
                json_last_error_msg()
            );
        }

        $jsonOutput = $fallback;
        $httpCode   = 500;
    }

    // ==================================================================
    // 📤 Send HTTP headers
    // @see https://www.php.net/manual/en/function.header.php
    // @see https://www.php.net/manual/en/function.http-response-code.php
    // ==================================================================
    if (!headers_sent()) {
        // 📌 Set the HTTP response code
        // @see https://www.php.net/manual/en/function.http-response-code.php
        http_response_code($httpCode);

        // 📌 Content-Type: application/json with UTF-8 encoding
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
        header('Content-Type: application/json; charset=utf-8');

        // 📌 Prevent caching of API responses
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // 📌 Security headers
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
        header('X-Content-Type-Options: nosniff');

        // 📌 CORS headers (configurable via settings)
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
        $allowedOrigins = '*';
        if (function_exists('getConfig')) {
            $allowedOrigins = getConfig('api_cors_allowed_origins', '*');
        }
        header('Access-Control-Allow-Origin: ' . $allowedOrigins);
    }

    // 📤 Output the JSON body and terminate
    echo $jsonOutput;
    exit;
}

// ==============================================================================
// 📄 xmlResponse() - Send an XML response
// ==============================================================================

/**
 * 📄 Send an XML HTTP response with XSLT stylesheet reference
 *
 * Converts the provided data array to XML format using SimpleXMLElement
 * and outputs it with appropriate headers. Includes an XSLT stylesheet
 * processing instruction for browser rendering.
 *
 * @param array $data     The response data array to convert to XML
 * @param int   $httpCode The HTTP status code (default: 200)
 *
 * @return void (script execution terminates via exit)
 *
 * @see https://www.php.net/manual/en/class.simplexmlelement.php
 * @see https://www.php.net/manual/en/function.http-response-code.php
 * @see https://developer.mozilla.org/en-US/docs/Web/XSLT
 *
 * @example
 *   xmlResponse([
 *       'success' => true,
 *       'message' => 'QR code generated',
 *       'data'    => ['uuid' => 'abc-123', 'format' => 'png']
 *   ], 200);
 */
function xmlResponse(array $data, int $httpCode = 200): void
{
    // 📌 Add server timestamp if not present
    if (!isset($data['timestamp'])) {
        $data['timestamp'] = gmdate('Y-m-d\TH:i:s+00:00');
    }

    // 📌 Create the root XML element
    // @see https://www.php.net/manual/en/class.simplexmlelement.php
    $xml = new \SimpleXMLElement(
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<?xml-stylesheet type="text/xsl" href="/assets/xsl/api-response.xsl"?>'
        . '<response/>'
    );

    // 📌 Convert the data array to XML elements recursively
    arrayToXML($data, $xml);

    // ==================================================================
    // 📤 Send HTTP headers
    // ==================================================================
    if (!headers_sent()) {
        http_response_code($httpCode);

        // 📌 Content-Type: application/xml with UTF-8 encoding
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
        header('Content-Type: application/xml; charset=utf-8');

        // 📌 Prevent caching
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // 📌 Security headers
        header('X-Content-Type-Options: nosniff');

        // 📌 CORS headers
        $allowedOrigins = '*';
        if (function_exists('getConfig')) {
            $allowedOrigins = getConfig('api_cors_allowed_origins', '*');
        }
        header('Access-Control-Allow-Origin: ' . $allowedOrigins);
    }

    // 📤 Output the XML and terminate
    // @see https://www.php.net/manual/en/simplexmlelement.asxml.php
    echo $xml->asXML();
    exit;
}

// ==============================================================================
// ❌ errorResponse() - Send a standardised error response
// ==============================================================================

/**
 * ❌ Send a standardised error response
 *
 * Convenience function that wraps jsonResponse() with a consistent error
 * structure. The response format is determined by the current API setting
 * or Accept header.
 *
 * Response body:
 *   {
 *       "success": false,
 *       "message": "Error description",
 *       "error_code": "OPTIONAL_CODE",
 *       "data": {},
 *       "timestamp": "..."
 *   }
 *
 * @param string $message   Human-readable error message
 * @param int    $httpCode  HTTP status code (default: 400)
 * @param string $errorCode Machine-readable error code for programmatic handling (default: '')
 *
 * @return void (script execution terminates via exit)
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status#client_error_responses
 *
 * @example
 *   errorResponse('Missing required field: url', 400, 'MISSING_FIELD');
 *   errorResponse('Invalid API key', 401, 'AUTH_FAILED');
 *   errorResponse('QR code not found', 404, 'NOT_FOUND');
 *   errorResponse('Rate limit exceeded', 429, 'RATE_LIMIT');
 */
function errorResponse(string $message, int $httpCode = 400, string $errorCode = ''): void
{
    // 📋 Build the error response payload
    $payload = [
        'success' => false,
        'message' => $message,
        'data'    => new \stdClass(),  // Empty object (not empty array) for consistent JSON output
    ];

    // 📌 Include error_code only if provided
    if ($errorCode !== '') {
        $payload['error_code'] = $errorCode;
    }

    // 📝 Log errors with severity >= 400 to the error log
    // Client errors (4xx) are logged as warnings; server errors (5xx) as errors.
    if (function_exists('logError')) {
        if ($httpCode >= 500) {
            $severity = 'error';
        } else {
            $severity = 'warning';
        }
        logError(
            $severity,
            'API Error Response: ' . $message,
            'HTTP ' . $httpCode . ' | Code: ' . $errorCode,
            ['httpCode' => $httpCode, 'errorCode' => $errorCode]
        );
    }

    // 📤 Determine response format based on Accept header or config
    $format = determineResponseFormat();

    if ($format === 'xml') {
        xmlResponse($payload, $httpCode);
    } else {
        jsonResponse($payload, $httpCode);
    }
}

// ==============================================================================
// ✅ successResponse() - Send a standardised success response
// ==============================================================================

/**
 * ✅ Send a standardised success response
 *
 * Convenience function that wraps jsonResponse() with a consistent success
 * structure.
 *
 * Response body:
 *   {
 *       "success": true,
 *       "message": "Success",
 *       "data": { ... },
 *       "timestamp": "..."
 *   }
 *
 * @param array  $data    The response data payload
 * @param string $message Human-readable success message (default: 'Success')
 *
 * @return void (script execution terminates via exit)
 *
 * @example
 *   successResponse(
 *       ['uuid' => 'abc-123', 'download_url' => '/download/abc-123.png'],
 *       'QR code generated successfully'
 *   );
 *
 *   successResponse(['settings' => $allSettings]);
 */
function successResponse(array $data, string $message = 'Success'): void
{
    // 📋 Build the success response payload
    $payload = [
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ];

    // 📤 Determine response format and send
    $format = determineResponseFormat();

    if ($format === 'xml') {
        xmlResponse($payload, 200);
    } else {
        jsonResponse($payload, 200);
    }
}

// ==============================================================================
// 🔄 arrayToXML() - Convert an array to XML elements
// ==============================================================================

/**
 * 🔄 Recursively convert a PHP array to SimpleXMLElement children
 *
 * Handles nested arrays, numeric keys, booleans, and null values.
 * Numeric array keys are replaced with 'item' elements to produce
 * valid XML (XML element names cannot start with a number).
 *
 * @param array             $data The array to convert
 * @param \SimpleXMLElement &$xml The parent XML element to append children to
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/simplexmlelement.addchild.php
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 *
 * @example
 *   $xml = new \SimpleXMLElement('<root/>');
 *   arrayToXML(['name' => 'CueRCode', 'version' => '1.0'], $xml);
 *   echo $xml->asXML();
 *   // <root><name>CueRCode</name><version>1.0</version></root>
 */
function arrayToXML(array $data, \SimpleXMLElement &$xml): void
{
    foreach ($data as $key => $value) {
        // 📌 Handle numeric keys: XML element names cannot start with a digit
        // Replace numeric keys with 'item' and add an 'index' attribute.
        // @see https://www.w3.org/TR/xml/#NT-Name
        if (is_numeric($key)) {
            $key = 'item';
        }

        // 📌 Sanitise the element name to ensure valid XML
        // Replace any non-alphanumeric characters (except underscore and hyphen) with underscore.
        // @see https://www.php.net/manual/en/function.preg-replace.php
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);

        // 📌 Ensure the key starts with a letter or underscore (XML requirement)
        if (preg_match('/^[0-9\-]/', $key) === 1) {
            $key = '_' . $key;
        }

        if (is_array($value)) {
            // 🔄 Recursive: create a child element and process the nested array
            $child = $xml->addChild($key);
            arrayToXML($value, $child);
        } elseif (is_bool($value)) {
            // ✅ Boolean: convert to 'true'/'false' string
            if ($value) {
                $boolString = 'true';
            } else {
                $boolString = 'false';
            }
            $xml->addChild($key, $boolString);
        } elseif ($value === null) {
            // 📌 Null: add an empty element
            $xml->addChild($key);
        } elseif (is_object($value)) {
            // 📦 Object: convert to array and recurse
            $child = $xml->addChild($key);
            arrayToXML((array) $value, $child);
        } else {
            // 🔤 Scalar value: add as text content (HTML-encoded for XML safety)
            // @see https://www.php.net/manual/en/function.htmlspecialchars.php
            $xml->addChild($key, htmlspecialchars((string) $value, ENT_XML1, 'UTF-8'));
        }
    }
}

// ==============================================================================
// 🔍 determineResponseFormat() - Determine the preferred response format
// ==============================================================================

/**
 * 🔍 Determine the preferred response format based on request context
 *
 * Checks the following sources in priority order:
 *   1. URL query parameter: ?format=json or ?format=xml
 *   2. HTTP Accept header: application/xml or application/json
 *   3. Database setting: api_default_response_format
 *   4. Default: 'json'
 *
 * @return string The preferred format ('json' or 'xml')
 *
 * @see https://www.php.net/manual/en/reserved.variables.get.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Accept
 */
function determineResponseFormat(): string
{
    // 📌 Priority 1: Explicit URL parameter
    // @see https://www.php.net/manual/en/reserved.variables.get.php
    if (isset($_GET['format'])) {
        $formatParam = $_GET['format'];
    } else {
        $formatParam = '';
    }
    $formatParam = strtolower(trim($formatParam));

    if ($formatParam === 'xml') {
        return 'xml';
    }

    if ($formatParam === 'json') {
        return 'json';
    }

    // 📌 Priority 2: HTTP Accept header
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Accept
    if (isset($_SERVER['HTTP_ACCEPT'])) {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'];
    } else {
        $acceptHeader = '';
    }

    if (strpos($acceptHeader, 'application/xml') !== false
        && strpos($acceptHeader, 'application/json') === false
    ) {
        return 'xml';
    }

    // 📌 Priority 3: Database default setting
    if (function_exists('getConfig')) {
        $defaultFormat = getConfig('api_default_response_format', 'json');
        if (strtolower($defaultFormat) === 'xml') {
            return 'xml';
        }
    }

    // 📌 Priority 4: Default to JSON
    return 'json';
}
