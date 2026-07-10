<?php
/**
 * ==============================================================================
 * 🌐 CueRCode - REST API v1 Router
 * ==============================================================================
 *
 * Path: public_html/api/v1/index.php
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
 *   POST   /api/v1/bulk-generate     Generate many QR codes -> ZIP (anonymous)
 *   GET    /api/v1/download/{uuid}   Download a previously generated QR code
 *   GET    /api/v1/types             List available QR code types
 *   GET    /api/v1/formats           List supported output formats
 *   GET    /api/v1/health            Health check endpoint
 *
 * Authentication:
 *   - X-API-Key header: API key authentication for external consumers
 *   - X-CSRF-Token header: Session-based auth for web UI AJAX calls
 *   - Anonymous: Allowed for health, types, formats, and bulk-generate endpoints
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
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
    'fn_api_bulk.php',       // 📦 Bulk generate handler (ZIP)
    'fn_api_download.php',   // 📥 Download handler
    'fn_api_types.php',      // 📋 Types, formats & health check handlers
    'fn_password.php',       // 🔑 Argon2id password hashing (Epic E3 stage 1) - loaded before fn_auth.php
    'fn_email.php',          // 📧 queueEmail() (Epic E8) - needed by fn_auth.php's verification/reset emails
    'fn_auth.php',           // 🔐 Registration/login/session/reset engine (Epic E3 stage 1)
    'fn_api_accounts.php',   // 🌐 Account HTTP handlers (Epic E3 stage 1)
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
    // 📌 Allow only allowlisted origins (🔐 F-001 - no wildcard default).
    // emitCorsHeaders() is defined in fn_response.php (loaded via bootstrap)
    // and reflects only an exact-match allowed Origin, adding Vary: Origin.
    // Same-origin web UI requests are unaffected (no cross-origin ACAO needed).
    // @see fn_response.php - emitCorsHeaders()
    if (function_exists('emitCorsHeaders')) {
        emitCorsHeaders();
    }

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

    // 📌 POST /api/v1/bulk-generate - Generate many QR codes and stream a ZIP
    //    Synchronous + anonymous-capable (no auth required, like /types). The
    //    same body-size cap (413) and JSON Content-Type (415) guards as other
    //    POST endpoints apply, plus rate limiting. (FG-003 / #32)
    [
        'pattern'   => 'bulk-generate',
        'methods'   => ['POST'],
        'handler'   => 'handleBulkGenerate',
        'auth'      => false,
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

    // ==========================================================================
    // 🔐 Account / authentication routes (Epic E3 stage 1, #11/#12/#14/#15)
    // ==========================================================================
    // 'auth' is deliberately FALSE for all six routes below - the router's
    // generic authenticateAPIRequest() gate always replies 401 on failure,
    // but these account-management endpoints must (a) be reachable by a
    // not-yet-authenticated visitor (register/login/reset) and (b) reply 403
    // - the more semantically correct code for a rejected CSRF token - on a
    // missing/invalid credential. Each handler therefore performs its OWN
    // CSRF-or-API-key check via requireCsrfOrApiKey() (fn_api_accounts.php),
    // reusing the exact same validateCSRFToken()/validateAPIKey() primitives.
    // 'rateLimit' stays TRUE so the existing tblAPIRateLog/checkRateLimit
    // mechanism (per-IP for anonymous callers) still applies to every route.
    // ==========================================================================

    // 📌 POST /api/v1/auth/register - Register a new account
    [
        'pattern'   => 'auth/register',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthRegister',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 POST /api/v1/auth/login - Authenticate and issue a session
    [
        'pattern'   => 'auth/login',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthLogin',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 POST /api/v1/auth/logout - Revoke the current session
    [
        'pattern'   => 'auth/logout',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthLogout',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 POST /api/v1/auth/verify-email - Consume an email verification token
    [
        'pattern'   => 'auth/verify-email',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthVerifyEmail',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 POST /api/v1/auth/request-password-reset - Request a password reset email
    [
        'pattern'   => 'auth/request-password-reset',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthRequestPasswordReset',
        'auth'      => false,
        'rateLimit' => true,
    ],

    // 📌 POST /api/v1/auth/reset-password - Consume a password reset token
    [
        'pattern'   => 'auth/reset-password',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthResetPassword',
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

        // 🔐 F-011: compute the reset time (Unix epoch seconds) at which the
        // limited window frees up. retryAfter is seconds-from-now; 0 means the
        // window is not currently blocking, in which case the next minute
        // window boundary is a sensible reset hint.
        if (isset($rateCheck['retryAfter']) && (int) $rateCheck['retryAfter'] > 0) {
            $rateLimitReset = time() + (int) $rateCheck['retryAfter'];
        } else {
            $rateLimitReset = time() + 60;
        }

        if (!$rateCheck['allowed']) {
            // 📌 Set rate limit headers per RFC conventions
            // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After
            if (!headers_sent()) {
                header('Retry-After: ' . (int) $rateCheck['retryAfter']);
                header('X-RateLimit-Limit: ' . (int) $rateCheck['limitPerMin']);
                header('X-RateLimit-Remaining: ' . (int) $rateCheck['remainingMin']);
                // 🔐 F-011: surface when the limit resets so clients can back off.
                header('X-RateLimit-Reset: ' . $rateLimitReset);
            }

            errorResponse(
                'Rate limit exceeded. Please wait before making another request.',
                429,
                'RATE_LIMIT_EXCEEDED'
            );
        }

        // 📌 Set rate limit headers for successful requests (🔐 F-011)
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . (int) $rateCheck['limitPerMin']);
            header('X-RateLimit-Remaining: ' . (int) $rateCheck['remainingMin']);
            header('X-RateLimit-Reset: ' . $rateLimitReset);
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

// 📌 Parse JSON request body for body-carrying methods (POST/PUT/PATCH).
// @see https://www.php.net/manual/en/function.json-decode.php
// @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.input
$requestBody = [];

// 🔐 F-007: maximum accepted request body size (bytes). Bodies larger than
// this are rejected with 413 BEFORE we read/decode them, so a hostile client
// cannot exhaust memory by streaming a huge JSON document into json_decode().
// Configurable via the 'api_max_request_body_bytes' setting; default 256 KiB.
if (function_exists('getConfig')) {
    $maxBodyBytes = (int) getConfig('api_max_request_body_bytes', 262144);
} else {
    $maxBodyBytes = 262144;
}
// 📌 Guard against a misconfigured non-positive limit (fall back to default).
if ($maxBodyBytes <= 0) {
    $maxBodyBytes = 262144;
}

// 📌 Methods that may legitimately carry a request body in this API.
$bodyMethods = ['POST', 'PUT', 'PATCH'];

if (in_array($httpMethod, $bodyMethods, true)) {
    $rawInput = file_get_contents('php://input');

    if ($rawInput === false) {
        $rawInput = '';
    }

    if ($rawInput !== '') {
        // 🔐 F-007: enforce the body-size cap before any further processing.
        // Prefer the advertised Content-Length when present, but always fall
        // back to the actual byte length so a missing/lying header cannot
        // bypass the cap.
        if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $maxBodyBytes) {
            errorResponse(
                'Request body too large. Maximum allowed size is ' . $maxBodyBytes . ' bytes.',
                413,
                'PAYLOAD_TOO_LARGE'
            );
        }

        if (strlen($rawInput) > $maxBodyBytes) {
            errorResponse(
                'Request body too large. Maximum allowed size is ' . $maxBodyBytes . ' bytes.',
                413,
                'PAYLOAD_TOO_LARGE'
            );
        }

        // 🔐 F-009: require a JSON Content-Type for non-empty request bodies.
        // We accept 'application/json' optionally followed by parameters
        // (e.g. '; charset=utf-8'). Anything else is rejected with 415 BEFORE
        // we attempt to json_decode() the body.
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/415
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $contentType = $_SERVER['CONTENT_TYPE'];
        } else {
            $contentType = '';
        }

        // 📌 Normalise: trim and lowercase the media type for comparison.
        $contentTypeNormalised = strtolower(trim($contentType));

        $isJsonContentType = ($contentTypeNormalised === 'application/json')
            || (strncmp($contentTypeNormalised, 'application/json;', 17) === 0);

        if (!$isJsonContentType) {
            errorResponse(
                'Unsupported Media Type. Request body must be sent as '
                . 'Content-Type: application/json.',
                415,
                'UNSUPPORTED_MEDIA_TYPE'
            );
        }

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

    case 'handleBulkGenerate':
        // 📦 Bulk handler reads php://input itself (it may be a CSV-bearing
        //    JSON body) and needs the auth context for activity logging.
        handleBulkGenerate($auth);
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

    case 'handleAuthRegister':
        handleAuthRegister($requestBody);
        break;

    case 'handleAuthLogin':
        handleAuthLogin($requestBody);
        break;

    case 'handleAuthLogout':
        handleAuthLogout();
        break;

    case 'handleAuthVerifyEmail':
        handleAuthVerifyEmail($requestBody);
        break;

    case 'handleAuthRequestPasswordReset':
        handleAuthRequestPasswordReset($requestBody);
        break;

    case 'handleAuthResetPassword':
        handleAuthResetPassword($requestBody);
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
