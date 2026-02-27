<?php
/**
 * ==============================================================================
 * 🌐 CueRCode - REST API v1 Router
 * ==============================================================================
 *
 * Central entry point for all REST API v1 requests. All requests are routed
 * here via .htaccess RewriteRule, which passes the request path as the
 * 'route' query parameter.
 *
 * This router handles:
 *   - Route parsing and matching
 *   - HTTP method validation
 *   - CORS preflight (OPTIONS) requests
 *   - Authentication (API key or CSRF token)
 *   - Rate limiting
 *   - Loading appropriate handler function files
 *   - Dispatching to handler functions
 *   - 404 (not found) and 405 (method not allowed) error responses
 *
 * Registered routes:
 *   POST   /api/v1/generate          Generate a full QR code
 *   POST   /api/v1/preview           Generate a preview QR code
 *   GET    /api/v1/download/{uuid}   Download a previously generated QR code
 *   GET    /api/v1/types             List available QR code types
 *   GET    /api/v1/formats           List supported output formats
 *   GET    /api/v1/health            Health check endpoint
 *
 * Authentication:
 *   - X-API-Key header: API key authentication for external consumers
 *   - X-CSRF-Token header: Session-based auth for web UI AJAX calls
 *   - Anonymous: Allowed for health, types, and formats endpoints
 *
 * @package     CueRCode
 * @subpackage  API
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/reserved.variables.server.php
 * @see         https://www.php.net/manual/en/function.preg-match.php
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods
 */

// ==============================================================================
// 📦 Load Bootstrap
// ==============================================================================
// This file is located at: public_html/api/v1/index.php
// Bootstrap is at:         _includes/bootstrap.php
// Path: ../../ goes to public_html/, then ../../ goes to site root, then _includes/
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// 📦 Load API Function Files
// ==============================================================================
// These are not loaded by bootstrap.php since they are API-specific.
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================
$apiFunctionFiles = [
    'fn_api_auth.php',       // 🔐 Authentication & rate limiting
    'fn_api_generate.php',   // 🎯 Generate & preview handlers
    'fn_api_download.php',   // 📥 Download handler
    'fn_api_types.php',      // 📋 Types, formats & health check handlers
];

foreach ($apiFunctionFiles as $apiFile) {
    $apiFilePath = CUERCODE_FUNCTIONS_DIR . $apiFile;
    if (file_exists($apiFilePath)) {
        require_once $apiFilePath;
    } else {
        error_log('CueRCode API WARNING: Function file not found: ' . $apiFilePath);
    }
}

// ==============================================================================
// 🌐 CORS Headers
// ==============================================================================
// Set CORS headers for all API responses, including error responses.
// The specific Access-Control-Allow-Origin is also set by jsonResponse()
// and xmlResponse(), but we set it here to cover edge cases where those
// functions are not reached (e.g., early error exits).
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
// ==============================================================================

if (!headers_sent()) {
    // 📌 Allow configured origins (default: all origins)
    if (function_exists('getConfig')) {
        $corsOrigin = getConfig('api_cors_allowed_origins', '*');
    } else {
        $corsOrigin = '*';
    }

    header('Access-Control-Allow-Origin: ' . $corsOrigin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-CSRF-Token, Accept');
    header('Access-Control-Max-Age: 86400');

    // 📌 Security headers
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Content-Type-Options
    header('X-Content-Type-Options: nosniff');

    // 📌 Remove any X-Powered-By header to prevent information disclosure
    header_remove('X-Powered-By');
}

// ==============================================================================
// ✈️ Handle CORS Preflight (OPTIONS) Requests
// ==============================================================================
// Browsers send an OPTIONS request before making cross-origin requests
// with custom headers or non-simple methods. We respond with the allowed
// methods and headers, then exit with HTTP 204 (No Content).
// @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS#preflighted_requests
// ==============================================================================

if (isset($_SERVER['REQUEST_METHOD'])) {
    $httpMethod = strtoupper($_SERVER['REQUEST_METHOD']);
} else {
    $httpMethod = 'GET';
}

if ($httpMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ==============================================================================
// 🔀 Parse the Route
// ==============================================================================
// The .htaccess RewriteRule passes the request path as ?route=...
// For example:
//   /api/v1/generate       -> route=generate
//   /api/v1/download/abc   -> route=download/abc
//   /api/v1/types          -> route=types
//   /api/v1/               -> route= (empty)
// @see https://www.php.net/manual/en/function.trim.php
// ==============================================================================

if (isset($_GET['route'])) {
    $route = $_GET['route'];
} else {
    $route = '';
}
$route = trim($route, '/');
$route = strtolower($route);

// 📌 Split the route into segments for pattern matching
// @see https://www.php.net/manual/en/function.explode.php
if ($route !== '') {
    $segments = explode('/', $route);
} else {
    $segments = [];
}

// ==============================================================================
// 📋 Route Definitions
// ==============================================================================
// Each route is defined with:
//   - 'pattern'   (string)    Route pattern (with {param} placeholders)
//   - 'methods'   (array)     Allowed HTTP methods
//   - 'handler'   (string)    Handler function name
//   - 'auth'      (bool)      Whether authentication is required
//   - 'rateLimit' (bool)      Whether rate limiting applies
// ==============================================================================

/**
 * @var array Route definition table
 * Routes are checked in order; the first match wins.
 */
$routes = [
    // 📌 POST /api/v1/generate - Generate a full QR code
    [
        'pattern'   => 'generate',
        'methods'   => ['POST'],
        'handler'   => 'handleGenerate',
        'auth'      => true,
        'rateLimit' => true,
    ],

    // 📌 POST /api/v1/preview - Generate a preview QR code
    [
        'pattern'   => 'preview',
        'methods'   => ['POST'],
        'handler'   => 'handlePreview',
        'auth'      => true,
        'rateLimit' => true,
    ],

    // 📌 GET /api/v1/download/{uuid} - Download a generated QR code
    [
        'pattern'   => 'download/{uuid}',
        'methods'   => ['GET'],
        'handler'   => 'handleDownload',
        'auth'      => true,
        'rateLimit' => true,
    ],

    // 📌 GET /api/v1/types - List available QR code types
    [
        'pattern'   => 'types',
        'methods'   => ['GET'],
        'handler'   => 'handleGetTypes',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 GET /api/v1/formats - List supported output formats
    [
        'pattern'   => 'formats',
        'methods'   => ['GET'],
        'handler'   => 'handleGetFormats',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 GET /api/v1/health - Health check endpoint
    [
        'pattern'   => 'health',
        'methods'   => ['GET'],
        'handler'   => 'handleHealthCheck',
        'auth'      => false,
        'rateLimit' => true,
    ],
];

// ==============================================================================
// 🔍 Route Matching
// ==============================================================================
// Iterate through the route definitions and find the first pattern that
// matches the current request path. Supports parameter placeholders
// (e.g., {uuid}) which are extracted and passed to the handler.
// @see https://www.php.net/manual/en/function.preg-match.php
// ==============================================================================

$matchedRoute  = null;
$routeParams   = [];

foreach ($routes as $routeDef) {
    $pattern = $routeDef['pattern'];

    // 📌 Convert the route pattern to a regex
    // Replace {param} placeholders with named capture groups.
    // @see https://www.php.net/manual/en/function.preg-replace.php
    $regex = preg_replace(
        '/\{([a-zA-Z_]+)\}/',   // Match {paramName}
        '(?P<$1>[^/]+)',         // Replace with named capture group
        $pattern
    );

    // 📌 Anchor the regex and make it case-insensitive
    $regex = '#^' . $regex . '$#i';

    // 📌 Test the route against the current request path
    if (preg_match($regex, $route, $matches) === 1) {
        $matchedRoute = $routeDef;

        // 📌 Extract named parameters from the regex matches
        // Remove numeric keys (full match and numeric groups) and keep
        // only named parameters (e.g., 'uuid').
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $routeParams[$key] = $value;
            }
        }

        break;
    }
}

// ==============================================================================
// ❌ Handle 404 - Route Not Found
// ==============================================================================
if ($matchedRoute === null) {
    errorResponse(
        'API endpoint not found: /api/v1/' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8'),
        404,
        'ENDPOINT_NOT_FOUND'
    );
    // errorResponse() calls exit, so this is unreachable
}

// ==============================================================================
// ❌ Handle 405 - Method Not Allowed
// ==============================================================================
if (!in_array($httpMethod, $matchedRoute['methods'], true)) {
    if (!headers_sent()) {
        // 📌 The Allow header is required for 405 responses per RFC 7231
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/405
        header('Allow: ' . implode(', ', $matchedRoute['methods']));
    }

    errorResponse(
        'HTTP method ' . htmlspecialchars($httpMethod, ENT_QUOTES, 'UTF-8')
        . ' is not allowed for this endpoint. Allowed: '
        . implode(', ', $matchedRoute['methods']) . '.',
        405,
        'METHOD_NOT_ALLOWED'
    );
}

// ==============================================================================
// 🔐 Authentication
// ==============================================================================
// Check authentication if the route requires it. Public endpoints
// (types, formats, health) skip this step.
// @see fn_api_auth.php - authenticateAPIRequest()
// ==============================================================================

$auth = [
    'authenticated' => false,
    'method'        => 'anonymous',
    'apiKeyId'      => null,
    'rateLimits'    => [
        'perMin' => CUERCODE_API_ANON_RATE_PER_MIN,
        'perDay' => CUERCODE_API_ANON_RATE_PER_DAY,
    ],
];

if (function_exists('authenticateAPIRequest')) {
    $auth = authenticateAPIRequest();
}

// 📌 Enforce authentication for protected endpoints
if ($matchedRoute['auth'] === true && $auth['authenticated'] === false) {
    // 📌 Determine the appropriate error message based on auth method
    if ($auth['method'] === 'api_key') {
        $authErrorMsg  = 'Invalid or inactive API key.';
        $authErrorCode = 'INVALID_API_KEY';
    } elseif ($auth['method'] === 'csrf') {
        $authErrorMsg  = 'Invalid or expired CSRF token.';
        $authErrorCode = 'INVALID_CSRF_TOKEN';
    } else {
        $authErrorMsg  = 'Authentication required. Provide an X-API-Key header or X-CSRF-Token header.';
        $authErrorCode = 'AUTH_REQUIRED';
    }

    errorResponse($authErrorMsg, 401, $authErrorCode);
}

// ==============================================================================
// ⏱️ Rate Limiting
// ==============================================================================
// Check and enforce rate limits if the route requires it. The health
// endpoint is exempt from rate limiting.
// @see fn_api_auth.php - checkRateLimit(), recordAPIRequest(), getClientIP()
// ==============================================================================

if ($matchedRoute['rateLimit'] === true) {
    if (function_exists('getClientIP')) {
        $clientIP = getClientIP();
    } else {
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $clientIP = $_SERVER['REMOTE_ADDR'];
        } else {
            $clientIP = 'unknown';
        }
    }

    // 📌 Check rate limits
    if (function_exists('checkRateLimit')) {
        $rateCheck = checkRateLimit($auth['apiKeyId'], $clientIP, $auth['rateLimits']);

        if (!$rateCheck['allowed']) {
            // 📌 Set rate limit headers per RFC conventions
            // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After
            if (!headers_sent()) {
                header('Retry-After: ' . $rateCheck['retryAfter']);
                header('X-RateLimit-Limit: ' . $rateCheck['limitPerMin']);
                header('X-RateLimit-Remaining: ' . $rateCheck['remainingMin']);
            }

            errorResponse(
                'Rate limit exceeded. Please wait before making another request.',
                429,
                'RATE_LIMIT_EXCEEDED'
            );
        }

        // 📌 Set rate limit headers for successful requests
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $rateCheck['limitPerMin']);
            header('X-RateLimit-Remaining: ' . $rateCheck['remainingMin']);
        }
    }

    // 📌 Record the API request in the rate log
    if (function_exists('recordAPIRequest')) {
        if (isset($segments[0])) {
            $routeSegment = $segments[0];
        } else {
            $routeSegment = 'unknown';
        }
        recordAPIRequest($auth['apiKeyId'], $clientIP, $routeSegment);
    }
}

// ==============================================================================
// 🚀 Dispatch to Handler
// ==============================================================================
// Call the matched handler function with the appropriate arguments.
// POST endpoints receive the parsed JSON request body.
// GET endpoints with URL parameters receive those parameters.
// ==============================================================================

$handlerFunction = $matchedRoute['handler'];

// 📌 Verify the handler function exists
if (!function_exists($handlerFunction)) {
    if (function_exists('logError')) {
        logError(
            'error',
            'API handler function not found',
            'Handler: ' . $handlerFunction . ' | Route: ' . $route
        );
    }

    errorResponse(
        'Internal server error: handler not available.',
        500,
        'HANDLER_NOT_FOUND'
    );
}

// 📌 Parse JSON request body for POST requests
// @see https://www.php.net/manual/en/function.json-decode.php
// @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input
$requestBody = [];

if ($httpMethod === 'POST') {
    $rawInput = file_get_contents('php://input');

    if ($rawInput !== false && $rawInput !== '') {
        $requestBody = json_decode($rawInput, true);

        // ❌ Invalid JSON body
        if ($requestBody === null && json_last_error() !== JSON_ERROR_NONE) {
            errorResponse(
                'Invalid JSON in request body: ' . json_last_error_msg(),
                400,
                'INVALID_JSON'
            );
        }

        // 📌 Ensure requestBody is an array
        if (!is_array($requestBody)) {
            $requestBody = [];
        }
    }
}

// ==============================================================================
// 📌 Call the handler function based on its signature
// ==============================================================================

// 📝 Log the API call activity
if (function_exists('logActivity')) {
    logActivity(
        'api_call',
        $httpMethod . ' /api/v1/' . $route,
        'api',
        null,
        [
            'method'    => $httpMethod,
            'route'     => $route,
            'authType'  => $auth['method'],
            'apiKeyId'  => $auth['apiKeyId'],
        ]
    );
}

// 📌 Dispatch based on handler type
switch ($handlerFunction) {
    case 'handleGenerate':
        handleGenerate($requestBody);
        break;

    case 'handlePreview':
        handlePreview($requestBody);
        break;

    case 'handleDownload':
        if (isset($routeParams['uuid'])) {
            $uuid = $routeParams['uuid'];
        } else {
            $uuid = '';
        }
        handleDownload($uuid);
        break;

    case 'handleGetTypes':
        handleGetTypes();
        break;

    case 'handleGetFormats':
        handleGetFormats();
        break;

    case 'handleHealthCheck':
        handleHealthCheck();
        break;

    default:
        // 📌 This should not be reached due to the function_exists check above
        errorResponse(
            'Internal server error: unknown handler.',
            500,
            'UNKNOWN_HANDLER'
        );
        break;
}
