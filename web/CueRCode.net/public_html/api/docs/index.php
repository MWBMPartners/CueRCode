<?php
/**
 * ==============================================================================
 * 📖 CueRCode - API Documentation Page (Swagger UI)
 * ==============================================================================
 *
 * Path: public_html/api/docs/index.php
 *
 * Renders the interactive API documentation using Swagger UI, pointed at the
 * OpenAPI 3.0 specification file at /api/v1/openapi.json.
 *
 * 🌐 CDN-first with local fallback (Issue #111): the Swagger UI library is
 * loaded from jsDelivr, pinned to version 5.33.0, with a Subresource
 * Integrity (SRI) hash on every file so the browser refuses anything that
 * doesn't match byte-for-byte. If jsDelivr can't be reached (or the file
 * fails the SRI check), the page falls back to a copy of the same 5.33.0
 * files vendored under /assets/vendor/swagger-ui/ - unmodified, straight
 * from the official npm tarball - so the docs page still works with no
 * internet-facing CDN at all (useful on Dreamhost shared hosting, and
 * required because this site's Content-Security-Policy header only
 * allow-lists https://cdn.jsdelivr.net for scripts/styles, NOT unpkg.com -
 * a previous revision of this page loaded Swagger UI from unpkg, which the
 * CSP silently blocked on every live request, leaving visitors stuck on the
 * "Documentation Unavailable" message with no way to reach the docs).
 *
 * This page uses the shared header.php and footer.php templates for consistent
 * site navigation and styling.
 *
 * @package     CueRCode
 * @subpackage  API Documentation
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       Phase 2
 * @see         https://swagger.io/tools/swagger-ui/ Swagger UI
 * @see         https://spec.openapis.org/oas/v3.0.3 OpenAPI 3.0 Specification
 * @see         https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/ jsDelivr CDN copy (pinned, SRI-checked)
 * @see         web/CueRCode.net/public_html/assets/vendor/swagger-ui/ Local fallback copy (vendored, same version)
 */

// ==============================================================================
// 📦 Load Bootstrap
// ==============================================================================
// This file is located at: public_html/api/docs/index.php
// Bootstrap is at:         _includes/bootstrap.php
// Path: ../../ goes to public_html/, then ../ goes to site root, then _includes/
// (public_html/api/docs/ is TWO directories deep under public_html/ - "api" and
// "docs" - so escaping to site root needs THREE '../' segments, exactly like the
// other two-deep entry points public_html/api/v1/index.php and
// public_html/developers/reference/index.php. A prior revision of this file
// used only two '../' segments, which resolved to public_html/_includes/
// bootstrap.php - a path that does not exist - fataling this page 100% of the
// time (2026-07-10 OpenAPI refresh: caught by driving this page live via
// php -S during Swagger UI render verification).
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// ⚙️ Page Configuration
// ==============================================================================
$pageTitle       = 'API Documentation';
$pageDescription = 'Interactive REST API documentation for CueRCode. Explore endpoints, schemas, and try out API requests.';
$bodyClass       = 'page-api-docs';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Load Header Template
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         📖 API Documentation Content
         ==================================================================== -->
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">

                <!-- 📌 Page heading -->
                <div class="mb-4">
                    <h1 class="h2">
                        <i class="fa-solid fa-book me-2"></i>
                        <?php echo htmlspecialchars('API Documentation', ENT_QUOTES, 'UTF-8'); ?>
                    </h1>
                    <p class="text-body-secondary">
                        <?php echo htmlspecialchars('Interactive documentation for the CueRCode REST API v1. Explore endpoints, view request/response schemas, and test API calls directly from this page.', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <!-- ================================================================
                     📌 Quick Start Guide
                     ================================================================ -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h5 mb-0">
                            <i class="fa-solid fa-rocket me-2"></i>
                            <?php echo htmlspecialchars('Quick Start', ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Authentication -->
                            <div class="col-md-4 mb-3 mb-md-0">
                                <h3 class="h6">
                                    <i class="fa-solid fa-key me-1"></i>
                                    <?php echo htmlspecialchars('Authentication', ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <p class="small text-body-secondary mb-2">
                                    <?php echo htmlspecialchars('Include your API key in the X-API-Key header:', ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <pre class="bg-body-tertiary p-2 rounded small"><code>X-API-Key: cuercode_your_api_key_here</code></pre>
                            </div>

                            <!-- Generate a QR Code -->
                            <div class="col-md-4 mb-3 mb-md-0">
                                <h3 class="h6">
                                    <i class="fa-solid fa-qrcode me-1"></i>
                                    <?php echo htmlspecialchars('Generate a QR Code', ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <p class="small text-body-secondary mb-2">
                                    <?php echo htmlspecialchars('POST to /api/v1/generate with JSON body:', ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <pre class="bg-body-tertiary p-2 rounded small"><code>{
  "type": "url",
  "input": { "url": "https://example.com" }
}</code></pre>
                            </div>

                            <!-- Rate Limits -->
                            <div class="col-md-4">
                                <h3 class="h6">
                                    <i class="fa-solid fa-gauge-high me-1"></i>
                                    <?php echo htmlspecialchars('Rate Limits', ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <p class="small text-body-secondary mb-2">
                                    <?php echo htmlspecialchars('Check rate limit headers in responses:', ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <pre class="bg-body-tertiary p-2 rounded small"><code>X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58</code></pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================================================================
                     📌 Swagger UI Container
                     ================================================================ -->
                <div class="card">
                    <div class="card-body p-0">
                        <div id="swagger-ui"></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ====================================================================
         🎨 Swagger UI CSS (jsDelivr CDN, pinned 5.33.0, SRI-checked, with
         local vendored fallback)
         @see https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/
         The inline onerror handler below is an inline event handler, so
         it is governed by the CSP's script-src directive - which includes
         'unsafe-inline' - and is therefore allowed. It fires only if the CSS
         request fails outright (network error, or an SRI mismatch causes
         the browser to refuse the resource) - it clears the error handler
         first (so a second failure on the fallback path can't loop), drops
         the now-irrelevant integrity attribute (the local file is served
         same-origin so SRI isn't needed there), and repoints href at the
         vendored copy under /assets/vendor/swagger-ui/.
         ==================================================================== -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/swagger-ui.css"
          integrity="sha384-Ov4/wv3j2bmct8cDc5X4ngJZohVPzEmc6uDPH8WeljUxO5vtoykvMEfbu9Vh6RaW"
          crossorigin="anonymous"
          onerror="this.onerror=null;this.removeAttribute('integrity');this.href='/assets/vendor/swagger-ui/swagger-ui.css';">

    <!-- 🎨 Custom overrides for Swagger UI to match CueRCode theme -->
    <style>
        /* ==================================================================
           📌 Override Swagger UI styles for site integration
           ================================================================== */

        /* Remove Swagger UI's default top bar (we have our own navbar) */
        .swagger-ui .topbar {
            display: none !important;
        }

        /* Match font to Bootstrap's native font stack */
        .swagger-ui {
            font-family: var(--bs-body-font-family, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif) !important;
        }

        /* Adjust headings */
        .swagger-ui .info .title {
            font-size: 1.5rem !important;
        }

        .swagger-ui .info {
            margin: 20px 0 !important;
        }

        /* Match links to CueRCode primary colour */
        .swagger-ui a.nostyle,
        .swagger-ui a {
            color: #2c7a7b !important;
        }

        /* Button styling to match theme */
        .swagger-ui .btn.authorize {
            border-color: #2c7a7b !important;
            color: #2c7a7b !important;
        }

        .swagger-ui .btn.authorize svg {
            fill: #2c7a7b !important;
        }

        /* Operation method badges */
        .swagger-ui .opblock.opblock-post .opblock-summary-method {
            background: #2c7a7b !important;
        }

        .swagger-ui .opblock.opblock-get .opblock-summary-method {
            background: #38a89d !important;
        }

        /* Ensure proper padding within the card */
        #swagger-ui {
            padding: 0 15px;
        }

        /* Dark mode support */
        [data-bs-theme="dark"] .swagger-ui {
            background-color: var(--bs-body-bg) !important;
            color: var(--bs-body-color) !important;
        }

        [data-bs-theme="dark"] .swagger-ui .opblock .opblock-summary-description,
        [data-bs-theme="dark"] .swagger-ui .opblock .opblock-summary-path,
        [data-bs-theme="dark"] .swagger-ui .info .title,
        [data-bs-theme="dark"] .swagger-ui .info p,
        [data-bs-theme="dark"] .swagger-ui .info li,
        [data-bs-theme="dark"] .swagger-ui table thead tr th,
        [data-bs-theme="dark"] .swagger-ui table tbody tr td,
        [data-bs-theme="dark"] .swagger-ui .model-title,
        [data-bs-theme="dark"] .swagger-ui .model,
        [data-bs-theme="dark"] .swagger-ui section.models h4,
        [data-bs-theme="dark"] .swagger-ui .parameter__name,
        [data-bs-theme="dark"] .swagger-ui .parameter__type,
        [data-bs-theme="dark"] .swagger-ui .response-col_status,
        [data-bs-theme="dark"] .swagger-ui .response-col_description {
            color: var(--bs-body-color) !important;
        }

        [data-bs-theme="dark"] .swagger-ui .opblock .opblock-section-header {
            background: rgba(255, 255, 255, 0.05) !important;
        }

        [data-bs-theme="dark"] .swagger-ui section.models {
            border-color: var(--bs-border-color) !important;
        }

        [data-bs-theme="dark"] .swagger-ui section.models .model-container {
            background: rgba(255, 255, 255, 0.02) !important;
        }
    </style>

    <!-- ====================================================================
         📦 Swagger UI JavaScript (jsDelivr CDN, pinned 5.33.0, SRI-checked,
         with local vendored fallback)
         @see https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/
         @see https://swagger.io/docs/open-source-tools/swagger-ui/usage/configuration/
         Same fallback pattern used site-wide for jQuery/Bootstrap in
         _includes/footer.php: each CDN <script> is followed by an inline
         <script> that checks whether the expected global made it onto the
         page. If the CDN request failed (network error, SRI mismatch, or
         CSP block), document.write() pulls in the vendored local copy
         instead, before the DOM finishes parsing.
         ==================================================================== -->
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/swagger-ui-bundle.js"
            integrity="sha384-YDALVcy8kj8yltLBVi1vBiBAUqdxvus673gM8XKwiy6aDUJFXivF/KCufekjYbVf"
            crossorigin="anonymous"></script>
    <script>
        /* 🔄 Swagger UI bundle CDN fallback to local vendored copy */
        if (typeof SwaggerUIBundle === 'undefined') {
            document.write('<script src="/assets/vendor/swagger-ui/swagger-ui-bundle.js"><\/script>');
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/swagger-ui-standalone-preset.js"
            integrity="sha384-My2aDM4r2Mbm3ybHcubKm9O9U8FEjvF/O5nGvE9YK5dzqOTbWEKa79RPJ1krdMaF"
            crossorigin="anonymous"></script>
    <script>
        /* 🔄 Swagger UI standalone preset CDN fallback to local vendored copy */
        if (typeof SwaggerUIStandalonePreset === 'undefined') {
            document.write('<script src="/assets/vendor/swagger-ui/swagger-ui-standalone-preset.js"><\/script>');
        }
    </script>

    <script>
        /**
         * 📌 Initialise Swagger UI
         * @see https://swagger.io/docs/open-source-tools/swagger-ui/usage/configuration/
         */
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof SwaggerUIBundle !== 'undefined') {
                var ui = SwaggerUIBundle({
                    /* 📌 URL to the OpenAPI specification */
                    url: '/api/v1/openapi.json',

                    /* 📌 DOM element to render into */
                    dom_id: '#swagger-ui',

                    /* 📌 Layout and plugins */
                    presets: [
                        SwaggerUIBundle.presets.apis,
                        SwaggerUIStandalonePreset
                    ],
                    plugins: [
                        SwaggerUIBundle.plugins.DownloadUrl
                    ],

                    /* 📌 Layout configuration */
                    layout: 'BaseLayout',

                    /* 📌 Deep linking: URL fragments for specific operations */
                    deepLinking: true,

                    /* 📌 Show request duration in responses */
                    displayRequestDuration: true,

                    /* 📌 Default expansion level (list = collapsed, full = expanded) */
                    docExpansion: 'list',

                    /* 📌 Default models expansion depth */
                    defaultModelsExpandDepth: 1,
                    defaultModelExpandDepth: 2,

                    /* 📌 Sort operations alphabetically by path */
                    operationsSorter: 'alpha',

                    /* 📌 Show the "Try it out" button by default */
                    tryItOutEnabled: false,

                    /* 📌 Validate responses against the spec */
                    validatorUrl: null,

                    /* 📌 Request interceptor: auto-add API key from Authorize dialog */
                    requestInterceptor: function(req) {
                        /* If a CSRF token meta tag exists, add it as a header */
                        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                        if (csrfMeta && csrfMeta.content) {
                            req.headers['X-CSRF-Token'] = csrfMeta.content;
                        }
                        return req;
                    }
                });

                /* 📌 Store reference globally for debugging */
                window.swaggerUI = ui;
            } else {
                /* ❌ Swagger UI failed to load - this should be rare, since
                   both the jsDelivr CDN copy AND a vendored local fallback
                   copy are loaded above (see the <script> tags near the
                   bottom of this page). Point visitors at the raw
                   machine-readable spec so they aren't left stuck. The
                   openapi.json path is a fixed string, not user input, but
                   it's still emitted via json_encode() with the JSON_HEX_*
                   flags (safe inside a <script> block and an HTML
                   attribute) to stay consistent with this project's
                   "always escape output" house rule. */
                var specPath = <?php echo json_encode('/api/v1/openapi.json', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                document.getElementById('swagger-ui').innerHTML =
                    '<div class="alert alert-warning m-4">'
                    + '<i class="fa-solid fa-triangle-exclamation me-2"></i>'
                    + '<strong>Documentation Unavailable</strong><br>'
                    + 'The interactive API documentation library could not be loaded. '
                    + 'You can still view the raw API specification directly: '
                    + '<a href="' + specPath + '">' + specPath + '</a>'
                    + '</div>';
            }
        });
    </script>

<?php
// ==============================================================================
// 📄 Load Footer Template
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
