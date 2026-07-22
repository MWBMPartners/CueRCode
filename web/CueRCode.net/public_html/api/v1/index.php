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
 *   POST   /api/v1/barcode           Generate a full NON-QR barcode (1D/2D, #69)
 *   POST   /api/v1/barcode/preview   Generate a preview NON-QR barcode (#69)
 *   POST   /api/v1/bulk-generate     Generate many QR codes -> ZIP (anonymous)
 *   GET    /api/v1/download/{uuid}   Download a previously generated QR code
 *   GET    /api/v1/types             List available QR code types
 *   GET    /api/v1/formats           List supported output formats
 *   GET    /api/v1/health            Health check endpoint
 *   POST   /api/v1/save              Save a generated QR code to the caller's account (Epic E3 stage 2a)
 *   GET    /api/v1/qrcodes           List the caller's OWN saved QR codes (paginated)
 *   GET    /api/v1/qrcodes/{uuid}    Fetch one saved QR code (owner or public only)
 *   PATCH  /api/v1/qrcodes/{uuid}    Update a saved QR code the caller OWNS
 *   DELETE /api/v1/qrcodes/{uuid}    Soft-delete a saved QR code the caller OWNS
 *   GET    /api/v1/qrcodes/{uuid}/destination    Fetch a dynamic redirect's destination the caller OWNS (Epic E1a Stage S1, #27 - "still dark", no public /r/ endpoint exists yet)
 *   PATCH  /api/v1/qrcodes/{uuid}/destination    Update a dynamic redirect's destination the caller OWNS (Epic E1a Stage S1, #27)
 *   PATCH  /api/v1/account/profile           Update the caller's OWN display name/avatar (Epic E3 stage 2b) [SESSION-ONLY, F-026]
 *   GET    /api/v1/account/sessions          List ONLY the caller's OWN active sessions [SESSION-ONLY, F-026]
 *   DELETE /api/v1/account/sessions/{id}     Revoke ONE of the caller's OWN sessions [SESSION-ONLY, F-026]
 *   POST   /api/v1/account/change-password   Change the caller's OWN password [SESSION-ONLY, F-026]
 *   GET    /api/v1/account/export            Download a complete JSON export of the caller's OWN data (#37/#52) [SESSION-ONLY, F-026]
 *   POST   /api/v1/account/erase             Erase the caller's OWN account by anonymization (#37/#52) [SESSION-ONLY, F-026]
 *   POST   /api/v1/keys                      Create a self-service API key (Epic E7 stage 1, #54) [SESSION-ONLY]
 *   GET    /api/v1/keys                      List ONLY the caller's OWN API keys (never the secret) [SESSION-ONLY]
 *   POST   /api/v1/keys/{id}/rotate          Rotate an API key the caller OWNS [SESSION-ONLY]
 *   DELETE /api/v1/keys/{id}                 Revoke an API key the caller OWNS [SESSION-ONLY]
 *
 * Authentication:
 *   - X-API-Key header: API key authentication for external consumers
 *   - X-CSRF-Token header: Session-based auth for web UI AJAX calls
 *   - Anonymous: Allowed for health, types, formats, and bulk-generate endpoints
 *
 * Scope enforcement (Epic E7 stage 1, #54):
 *   - A route's 'requiredScope' (string, or an array keyed by HTTP method for
 *     a shared multi-method route) is checked ONLY when the request
 *     authenticated via X-API-Key - session/CSRF requests are NEVER
 *     scope-limited (full user rights). A key missing the required scope
 *     gets 403 INSUFFICIENT_SCOPE listing the missing scope.
 *   - See fn_api_keys.php (CUERCODE_API_KEY_SCOPE_ALLOWLIST) for the scope
 *     taxonomy and fn_api_auth.php (apiKeyRequestHasScope()) for the check.
 *
 * Session-only management routes (F-026, fn_api_auth.php - isInteractiveSessionAuth()):
 *   - The ten routes marked [SESSION-ONLY] above stay 'auth' => true /
 *     'requiredScope' => null in THIS route table (any authenticated caller
 *     reaches the handler), but every one of their handlers additionally
 *     REFUSES an X-API-Key-authenticated request with 403
 *     ACCOUNT_MANAGEMENT_FORBIDDEN (account/*) or 403
 *     API_KEY_MANAGEMENT_FORBIDDEN (keys/*) - see fn_api_account.php's
 *     accountManagementAllowedFor() and fn_api_keys.php's
 *     apiKeyManagementAllowedFor(), both of which now delegate to the SAME
 *     shared primitive, isInteractiveSessionAuth(). No 'requiredScope'
 *     value could express this correctly (a key can never be granted
 *     "account management" as a scope) - only a real interactive session
 *     may manage the account or its API keys.
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
    'fn_features.php',       // 💳 Premium tier / feature gating engine (Epic E4/#53) - planForUser(), featureEnabled(), limitFor()
    'fn_api_qrcodes.php',    // 📚 Saved-codes API: /save, /qrcodes CRUD, ownership predicates (Epic E3 stage 2a, #52/#24)
    'fn_api_account.php',    // 👤 Own-account dashboard API: profile/avatar, sessions, change-password (Epic E3 stage 2b, #52/#12/#14/#23/#24)
    'fn_api_keys.php',       // 🔑 Self-service API keys: /keys CRUD, scope taxonomy, ownership predicates (Epic E7 stage 1, #54)
    'fn_redirects.php',      // 🔗 Dynamic-redirect pure core: validateRedirectDestination(), redirectIsWritableBy() (Epic E1a Stage S0, #27/#71/#33 - dark/inert, no public /r/ endpoint exists yet)
    'fn_api_redirects.php',  // 🔗 Dynamic-redirect owner API: GET/PATCH qrcodes/{uuid}/destination, createDynamicRedirectForOwner() (Epic E1a Stage S1, #27 - dark/inert)
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

    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
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
//   - 'pattern'         (string)      Route pattern (with {param} placeholders)
//   - 'methods'         (array)       Allowed HTTP methods
//   - 'handler'         (string)      Handler function name
//   - 'auth'            (bool)        Whether authentication is required
//   - 'rateLimit'       (bool)        Whether rate limiting applies
//   - 'requiredFeature' (string|null) Epic E4/#53 - a tblSubscriptionPlans
//     featuresJSON key (e.g. 'webhooks') that must be enabled on the caller's
//     plan for this route to proceed, or null for no feature gate. The
//     dispatch section below already calls requireFeatureOr402() whenever a
//     route sets this to a non-null key - see "🔐 Feature Gate (Epic E4/#53)"
//     further down - so adding a gated route is a one-line change to that
//     route's definition, zero router-logic changes. First real (non-null)
//     use: 'barcode'/'barcode/preview' below gate on 'barcode_generation'
//     (#69) - seeded TRUE on every current plan (anonymous/free/pro/business,
//     seed_data.sql), so this is a framework-readiness gate for future
//     tiering rather than a live restriction today (barcodes are a core,
//     always-on offering per the owner's brief for this cycle - see the PR
//     body / DEV_NOTES.md for the tier-placement owner-decision note).
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
        'requiredFeature' => null,
        'requiredScope'   => 'generate',
    ],

    // 📌 POST /api/v1/preview - Generate a preview QR code
    [
        'pattern'   => 'preview',
        'methods'   => ['POST'],
        'handler'   => 'handlePreview',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => 'preview',
    ],

    // ==========================================================================
    // 📊 NON-QR barcode routes (1D + 2D symbologies, #69)
    // ==========================================================================
    // Deliberately SEPARATE endpoints rather than overloading /generate and
    // /preview - most QR customisation (patterns, markers, logos, frames)
    // does not apply to barcode symbologies, and the existing QR pipeline is
    // heavily audited; a distinct pair of routes keeps that pipeline
    // untouched. Same auth (API key + CSRF + anonymous-if-no-auth-required -
    // both routes below require auth exactly like /generate and /preview),
    // rate-limit, Content-Type/size guards (enforced by the router body-
    // parsing section further down, unconditionally on every POST route),
    // and JSON/XML response envelope as /generate and /preview. Reuses the
    // SAME 'generate'/'preview' API-key scopes rather than minting new ones
    // (barcode generation is the same CAPABILITY as QR generation from a
    // scope-taxonomy point of view - a key already scoped to "generate" can
    // generate a barcode too), keeping the 7-entry scope allowlist unchanged.
    // ==========================================================================

    // 📌 POST /api/v1/barcode - Generate a full NON-QR barcode
    [
        'pattern'   => 'barcode',
        'methods'   => ['POST'],
        'handler'   => 'handleBarcodeGenerate',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => 'barcode_generation',
        'requiredScope'   => 'generate',
    ],

    // 📌 POST /api/v1/barcode/preview - Generate a preview NON-QR barcode
    [
        'pattern'   => 'barcode/preview',
        'methods'   => ['POST'],
        'handler'   => 'handleBarcodePreview',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => 'barcode_generation',
        'requiredScope'   => 'preview',
    ],

    // 📌 POST /api/v1/bulk-generate - Generate many QR codes and stream a ZIP
    //    Synchronous + anonymous-capable (no auth required, like /types). The
    //    same body-size cap (413) and JSON Content-Type (415) guards as other
    //    POST endpoints apply, plus rate limiting. (FG-003 / #32)
    //    'requiredScope' only bites when a caller VOLUNTARILY supplies an
    //    X-API-Key on this anonymous-capable route - a truly anonymous
    //    request never reaches the scope check at all (see the router's
    //    Scope Enforcement section: it only runs when $auth['method'] === 'api_key').
    [
        'pattern'   => 'bulk-generate',
        'methods'   => ['POST'],
        'handler'   => 'handleBulkGenerate',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => 'bulk',
    ],

    // 📌 GET /api/v1/download/{uuid} - Download a generated QR code
    [
        'pattern'   => 'download/{uuid}',
        'methods'   => ['GET'],
        'handler'   => 'handleDownload',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => 'download',
    ],

    // 📌 GET /api/v1/types - List available QR code types
    [
        'pattern'   => 'types',
        'methods'   => ['GET'],
        'handler'   => 'handleGetTypes',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 GET /api/v1/formats - List supported output formats
    [
        'pattern'   => 'formats',
        'methods'   => ['GET'],
        'handler'   => 'handleGetFormats',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 GET /api/v1/health - Health check endpoint
    [
        'pattern'   => 'health',
        'methods'   => ['GET'],
        'handler'   => 'handleHealthCheck',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
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
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/auth/login - Authenticate and issue a session
    [
        'pattern'   => 'auth/login',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthLogin',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/auth/logout - Revoke the current session
    [
        'pattern'   => 'auth/logout',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthLogout',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/auth/verify-email - Consume an email verification token
    [
        'pattern'   => 'auth/verify-email',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthVerifyEmail',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/auth/request-password-reset - Request a password reset email
    [
        'pattern'   => 'auth/request-password-reset',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthRequestPasswordReset',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/auth/reset-password - Consume a password reset token
    [
        'pattern'   => 'auth/reset-password',
        'methods'   => ['POST'],
        'handler'   => 'handleAuthResetPassword',
        'auth'      => false,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // ==========================================================================
    // 📚 Saved-codes routes (Epic E3 stage 2a, #52/#24, B-001/B-002)
    // ==========================================================================
    // The app's first OWNERSHIP-SCOPED resource surface. All four routes
    // require 'auth' => true (the router's normal 401-on-failure gate, same
    // as /generate and /download/{uuid}) - unlike the pre-authentication
    // account routes above, these operate on an ALREADY-authenticated
    // identity, so the standard 401 (not a bespoke 403) is the correct code.
    // Ownership itself is enforced inside each handler (fn_api_qrcodes.php),
    // never by the router - see that file's IDOR posture docblock.
    // ==========================================================================

    // 📌 POST /api/v1/save - Claim a generated QR code into the caller's account
    [
        'pattern'   => 'save',
        'methods'   => ['POST'],
        'handler'   => 'handleSaveQRCode',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => 'save',
    ],

    // 📌 GET /api/v1/qrcodes - List ONLY the caller's own saved QR codes
    [
        'pattern'   => 'qrcodes',
        'methods'   => ['GET'],
        'handler'   => 'handleListQRCodes',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => 'qrcodes:read',
    ],

    // 📌 GET/PATCH/DELETE /api/v1/qrcodes/{uuid} - Single saved QR code.
    // One route table entry shared by all three methods (the router matches
    // by PATTERN, not pattern+method) - handleQrCodeItem() dispatches by
    // $_SERVER['REQUEST_METHOD'] to the method-specific handler. 'requiredScope'
    // is a PER-METHOD array here (Epic E7 stage 1, #54) - GET needs only
    // read access; PATCH/DELETE need write access - see the router's Scope
    // Enforcement section for how an array value is resolved by HTTP method.
    [
        'pattern'   => 'qrcodes/{uuid}',
        'methods'   => ['GET', 'PATCH', 'DELETE'],
        'handler'   => 'handleQrCodeItem',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => [
            'GET'    => 'qrcodes:read',
            'PATCH'  => 'qrcodes:write',
            'DELETE' => 'qrcodes:write',
        ],
    ],

    // ==========================================================================
    // 🔗 Dynamic-redirect destination routes (Epic E1a Stage S1, #27) - "still
    // dark": these two routes ARE real/reachable over HTTP (own-scoped,
    // 404-not-403 IDOR, exactly like qrcodes/{uuid} above), but there is
    // deliberately NO public `/r/{uuid}` redirect endpoint anywhere in this
    // codebase yet for a managed destination to ever be resolved against -
    // that is Stage S2, gated on owner sign-off G-007 (see
    // .dev-team/dynamic_dualfn_design.md + LAUNCH_DECISIONS.md). {uuid} here
    // is a tblQRRedirects.redirectUUID, NOT a tblQRCodes.qrUUID - a distinct
    // identifier namespace (fn_redirects.php's docblock explains why). Rides
    // the SAME qrcodes:read/qrcodes:write scopes as qrcodes/{uuid} above (no
    // new scope needed - fn_api_keys.php's 7-scope allowlist is unchanged).
    // ==========================================================================

    // 📌 GET/PATCH /api/v1/qrcodes/{uuid}/destination - a dynamic redirect's destination
    [
        'pattern'   => 'qrcodes/{uuid}/destination',
        'methods'   => ['GET', 'PATCH'],
        'handler'   => 'handleQrDestinationItem',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => [
            'GET'   => 'qrcodes:read',
            'PATCH' => 'qrcodes:write',
        ],
    ],

    // ==========================================================================
    // 👤 Own-account dashboard routes (Epic E3 stage 2b, #52/#12/#14/#23/#24)
    // ==========================================================================
    // All four routes require 'auth' => true (the router's normal 401-on-
    // failure gate, same as /save and /qrcodes) - every one of these acts on
    // an ALREADY-authenticated identity, resolved server-side only, never
    // from a request field. Ownership itself is enforced inside each handler
    // (fn_api_account.php) - see that file's header docblock for the full
    // IDOR/own-scope posture (sessions/profile/password are ALL own-account
    // only, never cross-user).
    //
    // 🔐 F-026 (#52/#54): 'requiredScope' stays null here - unlike the
    // scope-enforced routes above, an API-key-authenticated caller cannot
    // reach these handlers AT ALL, regardless of scope. Every one of the
    // four handlers below (fn_api_account.php) additionally requires an
    // INTERACTIVE (CSRF/session) caller via accountManagementAllowedFor()
    // (the FIRST statement in each handler) - an X-API-Key-authenticated
    // request, however broadly scoped, gets 403 ACCOUNT_MANAGEMENT_FORBIDDEN.
    // This mirrors the "🔑 Self-service API key routes" section below
    // exactly (fn_api_keys.php's apiKeyManagementAllowedFor()) - account
    // control, like key management, is reserved for the user's own
    // interactive session/token, never a scoped API key. Both guards now
    // delegate to the SAME shared primitive, isInteractiveSessionAuth()
    // (fn_api_auth.php).
    // ==========================================================================

    // 📌 PATCH /api/v1/account/profile - Update display name / avatar
    [
        'pattern'   => 'account/profile',
        'methods'   => ['PATCH'],
        'handler'   => 'handleAccountProfilePatch',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 GET /api/v1/account/sessions - List ONLY the caller's own active sessions
    [
        'pattern'   => 'account/sessions',
        'methods'   => ['GET'],
        'handler'   => 'handleAccountSessionsList',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 DELETE /api/v1/account/sessions/{id} - Revoke ONE of the caller's own sessions
    [
        'pattern'   => 'account/sessions/{id}',
        'methods'   => ['DELETE'],
        'handler'   => 'handleAccountSessionDelete',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/account/change-password - Own-account password change
    [
        'pattern'   => 'account/change-password',
        'methods'   => ['POST'],
        'handler'   => 'handleAccountChangePassword',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // ==========================================================================
    // 📦 GDPR/CCPA data-subject rights (#37, epic #52) - export + erasure
    // ==========================================================================
    // Session-only exactly like the four routes above (F-026 pattern) -
    // handleAccountDataExport()/handleAccountErase() (fn_api_account.php)
    // both call accountManagementAllowedFor() as their FIRST statement, so an
    // X-API-Key-authenticated caller gets 403 ACCOUNT_MANAGEMENT_FORBIDDEN
    // regardless of scope, same as every other account/* route.
    // ==========================================================================

    // 📌 GET /api/v1/account/export - Download a complete JSON export of the caller's OWN data
    [
        'pattern'   => 'account/export',
        'methods'   => ['GET'],
        'handler'   => 'handleAccountDataExport',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/account/erase - Erase the caller's OWN account by anonymization
    [
        'pattern'   => 'account/erase',
        'methods'   => ['POST'],
        'handler'   => 'handleAccountErase',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // ==========================================================================
    // 🔑 Self-service API key routes (Epic E7 stage 1, #54)
    // ==========================================================================
    // All four routes require 'auth' => true (any authenticated caller reaches
    // the handler), but EVERY handler in fn_api_keys.php additionally requires
    // the caller to be CSRF/session-authenticated specifically
    // (apiKeyManagementAllowedFor()) - an X-API-Key-authenticated request gets
    // 403 API_KEY_MANAGEMENT_FORBIDDEN regardless of scope, closing the
    // self-escalation path where a compromised low-scope key could mint a
    // brand-new, broader-scoped key for itself (see fn_api_keys.php's header
    // docblock "Owner/design decision" for the full rationale). 'requiredScope'
    // therefore stays null here - the CSRF-only guard is a stronger,
    // unconditional restriction than any scope value could express.
    // ==========================================================================

    // 📌 POST /api/v1/keys - Create a self-service API key
    // 📌 GET  /api/v1/keys - List ONLY the caller's own API keys
    [
        'pattern'   => 'keys',
        'methods'   => ['POST', 'GET'],
        'handler'   => 'handleApiKeysCollection',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 POST /api/v1/keys/{id}/rotate - Rotate a key the caller OWNS
    [
        'pattern'   => 'keys/{id}/rotate',
        'methods'   => ['POST'],
        'handler'   => 'handleRotateApiKey',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
    ],

    // 📌 DELETE /api/v1/keys/{id} - Revoke a key the caller OWNS
    [
        'pattern'   => 'keys/{id}',
        'methods'   => ['DELETE'],
        'handler'   => 'handleDeleteApiKey',
        'auth'      => true,
        'rateLimit' => true,
        'requiredFeature' => null,
        'requiredScope'   => null,
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
    'scopes'        => [],
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
// 🔒 Scope Enforcement (Epic E7 stage 1, #54)
// ==============================================================================
// Enforces a route's 'requiredScope' (see the route table comment above), but
// ONLY when the request authenticated via an X-API-Key header
// ($auth['method'] === 'api_key') - a session/CSRF-authenticated request is
// NEVER scope-limited (full user rights) and skips this block entirely,
// regardless of what 'requiredScope' the matched route defines. An anonymous
// request also skips this block (anonymous-capable routes like
// /bulk-generate only reach the check if the caller VOLUNTARILY supplied a
// valid API key).
//
// 'requiredScope' may be either a plain string (applies to every method the
// route allows) or an array keyed by HTTP method (for a route shared across
// multiple methods via one table entry, e.g. "qrcodes/{uuid}" - GET needs
// read, PATCH/DELETE need write). A null/absent 'requiredScope' (or a null
// resolved value for the CURRENT method inside a per-method array) means "no
// scope required" - existing behaviour for every route that predates this
// stage is completely unaffected (every one of them defines 'requiredScope'
// as null).
//
// A key missing the required scope gets 403 INSUFFICIENT_SCOPE, listing the
// missing scope so the caller knows exactly what to grant the key (or which
// key to use instead) - fail-closed: an unset/malformed scopes list on the
// $auth array is treated as "no scopes granted" by apiKeyRequestHasScope(),
// never as "everything allowed".
// @see fn_api_auth.php  - apiKeyRequestHasScope()
// @see fn_api_keys.php  - CUERCODE_API_KEY_SCOPE_ALLOWLIST (the scope taxonomy)
// ==============================================================================

if ($auth['method'] === 'api_key'
    && isset($matchedRoute['requiredScope'])
    && $matchedRoute['requiredScope'] !== null
) {
    $routeRequiredScope = $matchedRoute['requiredScope'];

    // 📌 Per-method scope map (e.g. "qrcodes/{uuid}") - resolve to THIS
    // request's HTTP method; a method with no entry in the map requires no
    // scope (defensive default, though every route defining a per-method map
    // today covers all of its own 'methods').
    if (is_array($routeRequiredScope)) {
        if (isset($routeRequiredScope[$httpMethod])) {
            $routeRequiredScope = $routeRequiredScope[$httpMethod];
        } else {
            $routeRequiredScope = null;
        }
    }

    if ($routeRequiredScope !== null) {
        $hasRequiredScope = function_exists('apiKeyRequestHasScope')
            && apiKeyRequestHasScope($auth, (string) $routeRequiredScope);

        if (!$hasRequiredScope) {
            errorResponse(
                'This API key does not have the required scope: "' . $routeRequiredScope . '". '
                . 'Create or update a key with this scope in your account dashboard to access this endpoint.',
                403,
                'INSUFFICIENT_SCOPE',
                ['required_scope' => $routeRequiredScope]
            );
        }
    }
}

// ==============================================================================
// 🔐 Feature Gate (Epic E4/#53)
// ==============================================================================
// Enforces a route's 'requiredFeature' (see the route table comment above),
// if one is set. UNUSED today - every route above defines it as null, so
// this block never triggers yet - but it is fully wired so a future gated
// route (e.g. a webhooks CRUD route requiring the 'webhooks' feature) is a
// one-line change to that route's 'requiredFeature' value, nothing here.
// Resolves the caller's userID the same way handleBulkGenerate() does
// (resolveUserIdFromAuth()) so a session-authenticated web UI user and an
// API-key request both gate against their OWN plan, not the anonymous plan.
// @see fn_features.php - requireFeatureOr402(), resolveUserIdFromAuth()
// ==============================================================================

if (isset($matchedRoute['requiredFeature']) && $matchedRoute['requiredFeature'] !== null) {
    if (function_exists('resolveUserIdFromAuth')) {
        $featureGateUserId = resolveUserIdFromAuth($auth);
    } else {
        $featureGateUserId = null;
    }

    if (function_exists('requireFeatureOr402')) {
        $featureDenied = requireFeatureOr402((string) $matchedRoute['requiredFeature'], $featureGateUserId);

        if ($featureDenied !== null) {
            errorResponse(
                $featureDenied['message'],
                $featureDenied['httpCode'],
                $featureDenied['errorCode'],
                [
                    'upgrade_url' => $featureDenied['upgradeUrl'],
                    'feature'     => $featureDenied['feature'],
                ]
            );
        }
    }
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

    case 'handleBarcodeGenerate':
        handleBarcodeGenerate($requestBody);
        break;

    case 'handleBarcodePreview':
        handleBarcodePreview($requestBody);
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
        // 🔐 Epic E3 stage 2a: $auth is now passed through so handleDownload()
        // can enforce owner-or-public on a SAVED (claimed) code - see
        // fn_api_download.php's docblock amendment for the full rule.
        handleDownload($uuid, $auth);
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

    case 'handleSaveQRCode':
        handleSaveQRCode($requestBody, $auth);
        break;

    case 'handleListQRCodes':
        handleListQRCodes($auth);
        break;

    case 'handleQrCodeItem':
        if (isset($routeParams['uuid'])) {
            $uuid = $routeParams['uuid'];
        } else {
            $uuid = '';
        }
        handleQrCodeItem($uuid, $requestBody, $auth);
        break;

    case 'handleQrDestinationItem':
        if (isset($routeParams['uuid'])) {
            $uuid = $routeParams['uuid'];
        } else {
            $uuid = '';
        }
        handleQrDestinationItem($uuid, $requestBody, $auth);
        break;

    case 'handleAccountProfilePatch':
        handleAccountProfilePatch($requestBody, $auth);
        break;

    case 'handleAccountSessionsList':
        handleAccountSessionsList($auth);
        break;

    case 'handleAccountSessionDelete':
        if (isset($routeParams['id'])) {
            $sessionId = $routeParams['id'];
        } else {
            $sessionId = '';
        }
        handleAccountSessionDelete($sessionId, $auth);
        break;

    case 'handleAccountChangePassword':
        handleAccountChangePassword($requestBody, $auth);
        break;

    case 'handleAccountDataExport':
        handleAccountDataExport($auth);
        break;

    case 'handleAccountErase':
        handleAccountErase($requestBody, $auth);
        break;

    case 'handleApiKeysCollection':
        handleApiKeysCollection($requestBody, $auth);
        break;

    case 'handleRotateApiKey':
        if (isset($routeParams['id'])) {
            $apiKeyIdParam = $routeParams['id'];
        } else {
            $apiKeyIdParam = '';
        }
        handleRotateApiKey($apiKeyIdParam, $auth);
        break;

    case 'handleDeleteApiKey':
        if (isset($routeParams['id'])) {
            $apiKeyIdParam = $routeParams['id'];
        } else {
            $apiKeyIdParam = '';
        }
        handleDeleteApiKey($apiKeyIdParam, $auth);
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
