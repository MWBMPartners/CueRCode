<?php
/**
 * ==============================================================================
 * ⚠️ CueRCode - Developer Portal: Errors & Rate-Limit Reference
 * ==============================================================================
 *
 * Path: public_html/developers/errors/index.php
 *
 * Documents the machine-readable error envelope (success/message/data/
 * error_code/timestamp), the HTTP status codes the API returns (plus two
 * reserved-for-future codes), and the X-RateLimit-* / Retry-After headers.
 *
 * Where the underlying facts already live in openapi.json (the ErrorResponse
 * schema's property descriptions, the shared error response definitions, and
 * the X-RateLimit-* header descriptions), this page RESOLVES and renders them
 * from the spec at request time via fn_openapi_render.php helpers rather than
 * retyping them - the same anti-drift approach as the main Endpoint Reference
 * page. The HTTP-status-code table and the numeric default rate limits (which
 * are operational facts, not part of the OpenAPI document) are documented
 * narratively.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         web/CueRCode.net/_functions/fn_api_auth.php - rate-limit constants/logic
 * @see         public_html/api/v1/openapi.json - ErrorResponse schema + shared responses
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// 📦 Load the OpenAPI render helpers
// ==============================================================================
$openApiRenderFile = CUERCODE_FUNCTIONS_DIR . 'fn_openapi_render.php';
if (file_exists($openApiRenderFile)) {
    require_once $openApiRenderFile;
} else {
    error_log('CueRCode WARNING: fn_openapi_render.php not found at ' . $openApiRenderFile);
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================
if (function_exists('getConfig')) {
    $result = getConfig('site_name', 'CueRCode');
    if (isset($result)) {
        $siteName = $result;
    } else {
        $siteName = 'CueRCode';
    }
} else {
    $siteName = 'CueRCode';
}

$pageTitle       = 'Errors & Rate Limits';
$pageDescription = 'The ' . $siteName . ' API error model (error_code + message), HTTP status codes, '
    . 'and the X-RateLimit-*/Retry-After headers.';
$bodyClass       = 'page-developers page-developers-errors';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'errors';

// ==============================================================================
// 📂 Load the OpenAPI specification (for the resolved sections below)
// ==============================================================================
$openApiSpecPath = __DIR__ . '/../../api/v1/openapi.json';
$openApiSpec     = null;

if (function_exists('loadOpenApiSpecFromFile')) {
    $openApiSpec = loadOpenApiSpecFromFile($openApiSpecPath);
}

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'developers_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">
                        Errors &amp; Rate Limits
                    </h1>
                    <p class="text-body-secondary mb-4">
                        Every error response - regardless of HTTP status code - shares one predictable JSON
                        (or XML) shape, so client code can handle errors generically and only branch on
                        <code>error_code</code> when it needs to.
                    </p>

                    <div class="legal-content">

                        <nav aria-label="Guide contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#envelope">1. The error envelope</a></li>
                                <li><a href="#status-codes">2. HTTP status codes</a></li>
                                <li><a href="#status-details">3. Status code details (from openapi.json)</a></li>
                                <li><a href="#rate-limits">4. Rate limit headers &amp; defaults</a></li>
                            </ul>
                        </nav>

                        <?php /* ============================================================
                             1. Error envelope
                             ============================================================ */ ?>
                        <h2 id="envelope" class="h5 mt-4 mb-3">1. The Error Envelope</h2>
                        <p>Every error response looks like this (JSON; add <code>?format=xml</code> or an
                            <code>Accept: application/xml</code> header for the XML equivalent):</p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>{
    "success": false,
    "message": "Missing required field: type. Specify the QR code type (e.g., \"url\", \"vcard\", \"wifi\").",
    "data": {},
    "error_code": "MISSING_FIELD",
    "timestamp": "2026-02-27T12:00:00+00:00"
}</code></pre>

                        <?php if ($openApiSpec !== null
                            && isset($openApiSpec['components']['schemas']['ErrorResponse'])
                            && is_array($openApiSpec['components']['schemas']['ErrorResponse'])
                        ) { ?>
                        <p class="small text-body-secondary">
                            Field-by-field detail below comes from the <code>ErrorResponse</code> schema
                            in the API specification, including the full <code>error_code</code>
                            catalogue:
                        </p>
                        <?php echo oapiRenderSchemaProperties($openApiSpec['components']['schemas']['ErrorResponse'], $openApiSpec); ?>
                        <?php } ?>

                        <?php /* ============================================================
                             2. HTTP status codes (narrative table)
                             ============================================================ */ ?>
                        <h2 id="status-codes" class="h5 mt-4 mb-3">2. HTTP Status Codes</h2>
                        <div class="table-responsive">
                            <div class="d-grid gap-2">
                                <?php
                                    // 📌 Full associative rows (no list() / array-destructuring shorthand,
                                    // per house style) - each row names its own fields explicitly.
                                    $statusRows = [
                                        ['code' => '200', 'label' => 'OK', 'kind' => 'success', 'note' => 'Request succeeded.'],
                                        ['code' => '400', 'label' => 'Bad Request', 'kind' => 'success', 'note' => 'Malformed/missing fields, invalid JSON, an over-long field, or an invalid customisation value.'],
                                        ['code' => '401', 'label' => 'Unauthorized', 'kind' => 'success', 'note' => 'Authentication required, or the supplied X-API-Key/X-CSRF-Token is missing, invalid, or expired.'],
                                        ['code' => '402', 'label' => 'Payment Required', 'kind' => 'success', 'note' => 'Plan-based feature/limit gating: FEATURE_NOT_AVAILABLE, SAVED_CODES_LIMIT_REACHED, or API_KEY_LIMIT_REACHED. The response carries upgrade_url and plan_limit alongside the standard error shape.'],
                                        ['code' => '403', 'label' => 'Forbidden', 'kind' => 'success', 'note' => 'Authenticated-but-unauthorised: INSUFFICIENT_SCOPE (an API key\'s granted scopes do not cover this action), ACCOUNT_MANAGEMENT_FORBIDDEN (an API key attempted session-only account management), API_KEY_MANAGEMENT_FORBIDDEN (an API key attempted to manage API keys), or CSRF_INVALID (missing/invalid CSRF token or API key).'],
                                        ['code' => '404', 'label' => 'Not Found', 'kind' => 'success', 'note' => 'Unknown route (ENDPOINT_NOT_FOUND), or an unrecognised QR code UUID on /download (NOT_FOUND).'],
                                        ['code' => '405', 'label' => 'Method Not Allowed', 'kind' => 'success', 'note' => 'The HTTP method used is not supported for that route (e.g. GET on /generate). The response includes an Allow header listing the accepted methods.'],
                                        ['code' => '409', 'label' => 'Conflict', 'kind' => 'success', 'note' => 'A concurrent save collided with an in-flight save for the same QR code (SAVE_CONFLICT), or a single-use token was already consumed (VERIFICATION_ALREADY_USED / RESET_TOKEN_ALREADY_USED).'],
                                        ['code' => '413', 'label' => 'Payload Too Large', 'kind' => 'success', 'note' => 'Request body exceeds the configured maximum (256 KiB by default), or a bulk request exceeds the maximum item count (100 by default).'],
                                        ['code' => '415', 'label' => 'Unsupported Media Type', 'kind' => 'success', 'note' => 'A non-empty request body was sent without Content-Type: application/json (an optional ; charset=... parameter is tolerated).'],
                                        ['code' => '422', 'label' => 'Unprocessable Entity', 'kind' => 'success', 'note' => 'The request was well-formed but QR code generation failed for the given payload, or the preset=accessible contrast check rejected the fg/bg colour pair.'],
                                        ['code' => '429', 'label' => 'Too Many Requests', 'kind' => 'success', 'note' => 'Rate limit exceeded - see Section 4 below for the Retry-After/X-RateLimit-* headers.'],
                                        ['code' => '500', 'label' => 'Internal Server Error', 'kind' => 'success', 'note' => 'An unexpected server-side failure.'],
                                        ['code' => '501', 'label' => 'Not Implemented', 'kind' => 'success', 'note' => 'Bulk ZIP generation is unavailable because the server is missing the PHP zip extension.'],
                                        ['code' => '503', 'label' => 'Service Unavailable', 'kind' => 'success', 'note' => '/health reports "degraded" when the database is unreachable.'],
                                    ];
                                    foreach ($statusRows as $statusRow) {
                                        $code  = $statusRow['code'];
                                        $label = $statusRow['label'];
                                        $kind  = $statusRow['kind'];
                                        $note  = $statusRow['note'];
                                        if ($kind === 'warning') {
                                            $badge = 'text-bg-warning';
                                        } else {
                                            $badge = oapiStatusBadgeClass($code);
                                        }
                                ?>
                                <div class="d-flex gap-3 align-items-start pb-2 border-bottom">
                                    <div style="min-width: 4.5rem;">
                                        <span class="badge <?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <?php /* ↩️ text-break (#137): long codes such as BULK_TOO_MANY_ITEMS
                                             wrap instead of pushing the page sideways on small phones. */ ?>
                                    <div class="text-break">
                                        <strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div class="small text-body-secondary"><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>

                        <?php /* ============================================================
                             3. Status details resolved from openapi.json
                             ============================================================ */ ?>
                        <?php if ($openApiSpec !== null) {
                            $namedResponseRefs = [
                                '400' => '#/components/responses/BadRequest',
                                '401' => '#/components/responses/Unauthorized',
                                '402' => '#/components/responses/PaymentRequired',
                                '403' => '#/components/responses/Forbidden',
                                '409' => '#/components/responses/Conflict',
                                '413' => '#/components/responses/PayloadTooLarge',
                                '415' => '#/components/responses/UnsupportedMediaType',
                                '422' => '#/components/responses/UnprocessableEntity',
                                '429' => '#/components/responses/RateLimited',
                                '500' => '#/components/responses/ServerError',
                            ];

                            $statusCodesFromSpec = [];
                            foreach ($namedResponseRefs as $statusCode => $ref) {
                                $statusCodesFromSpec[$statusCode] = ['$ref' => $ref];
                            }

                            // 📌 Pull the genuinely-declared INLINE responses (404/501/503)
                            // straight out of their own operations - not hand-copied.
                            if (isset($openApiSpec['paths']['download/{uuid}']['get']['responses']['404'])) {
                                $statusCodesFromSpec['404'] = $openApiSpec['paths']['download/{uuid}']['get']['responses']['404'];
                            }
                            if (isset($openApiSpec['paths']['bulk-generate']['post']['responses']['501'])) {
                                $statusCodesFromSpec['501'] = $openApiSpec['paths']['bulk-generate']['post']['responses']['501'];
                            }
                            if (isset($openApiSpec['paths']['health']['get']['responses']['503'])) {
                                $statusCodesFromSpec['503'] = $openApiSpec['paths']['health']['get']['responses']['503'];
                            }

                            ksort($statusCodesFromSpec, SORT_STRING);
                        ?>
                        <h2 id="status-details" class="h5 mt-4 mb-3">3. Status Code Details (from openapi.json)</h2>
                        <p class="small text-body-secondary">
                            Descriptions and examples below come from the response definitions in the
                            API specification.
                        </p>
                        <?php echo oapiRenderResponses($statusCodesFromSpec, $openApiSpec); ?>
                        <?php } ?>

                        <?php /* ============================================================
                             4. Rate limit headers
                             ============================================================ */ ?>
                        <h2 id="rate-limits" class="h5 mt-4 mb-3">4. Rate Limit Headers &amp; Defaults</h2>
                        <p>Every rate-limited response (success or error) carries these headers:</p>
                        <?php if ($openApiSpec !== null
                            && isset($openApiSpec['components']['headers']) && is_array($openApiSpec['components']['headers'])
                        ) { ?>
                        <dl class="row">
                            <?php foreach ($openApiSpec['components']['headers'] as $headerName => $headerDef) {
                                if (!is_array($headerDef)) {
                                    continue;
                                }
                                $headerDescription = (isset($headerDef['description']) && is_string($headerDef['description'])) ? $headerDef['description'] : '';
                            ?>
                            <dt class="col-sm-3"><code><?php echo htmlspecialchars((string) $headerName, ENT_QUOTES, 'UTF-8'); ?></code></dt>
                            <dd class="col-sm-9 mb-2"><?php echo htmlspecialchars($headerDescription, ENT_QUOTES, 'UTF-8'); ?></dd>
                            <?php } ?>
                            <?php
                                $retryAfterDescription = '';
                                if (isset($openApiSpec['components']['responses']['RateLimited']['headers']['Retry-After']['description'])
                                    && is_string($openApiSpec['components']['responses']['RateLimited']['headers']['Retry-After']['description'])
                                ) {
                                    $retryAfterDescription = $openApiSpec['components']['responses']['RateLimited']['headers']['Retry-After']['description'];
                                }
                            ?>
                            <dt class="col-sm-3"><code>Retry-After</code></dt>
                            <dd class="col-sm-9 mb-2">
                                <?php echo htmlspecialchars(
                                    ($retryAfterDescription !== '') ? $retryAfterDescription : 'Seconds to wait before retrying (present only on 429 responses).',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                                - present only on <code>429</code> responses.
                            </dd>
                        </dl>
                        <?php } ?>

                        <h3 class="h6 mt-3">Default limits by authentication mode</h3>
                        <p class="small text-body-secondary">
                            These are the default limits. They are not part of the API specification
                            itself, and they may be adjusted over time.
                        </p>
                        <ul class="mb-0">
                            <li><strong>Anonymous</strong> (by IP address): 10 requests/minute, 100 requests/day.</li>
                            <li><strong>Session CSRF</strong> (by session): 30 requests/minute, 1,000 requests/day.</li>
                            <li><strong>API key</strong>: each key carries its own per-minute and per-day limits
                                (set when the key is issued - see
                                <a href="/developers/authentication/">Authentication</a>), independent of the
                                anonymous/CSRF defaults above.</li>
                        </ul>

                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
// ==============================================================================
// 📄 Load Footer Template
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
