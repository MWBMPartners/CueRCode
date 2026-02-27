<?php
/**
 * ==============================================================================
 * 📖 CueRCode - API Documentation Page (Swagger UI)
 * ==============================================================================
 *
 * Renders the interactive API documentation using Swagger UI. The Swagger UI
 * library is loaded from the unpkg CDN and pointed at the OpenAPI 3.0
 * specification file at /api/v1/openapi.json.
 *
 * This page uses the shared header.php and footer.php templates for consistent
 * site navigation and styling.
 *
 * @package     CueRCode
 * @subpackage  API Documentation
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://swagger.io/tools/swagger-ui/ Swagger UI
 * @see         https://spec.openapis.org/oas/v3.0.3 OpenAPI 3.0 Specification
 * @see         https://unpkg.com/ unpkg CDN
 */

// ==============================================================================
// 📦 Load Bootstrap
// ==============================================================================
// This file is located at: public_html/api/docs/index.php
// Bootstrap is at:         _includes/bootstrap.php
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

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
         🎨 Swagger UI CSS (from unpkg CDN)
         @see https://unpkg.com/swagger-ui-dist/
         ==================================================================== -->
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" crossorigin="anonymous">

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
         📦 Swagger UI JavaScript (from unpkg CDN)
         @see https://unpkg.com/swagger-ui-dist/
         @see https://swagger.io/docs/open-source-tools/swagger-ui/usage/configuration/
         ==================================================================== -->
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js" crossorigin="anonymous"></script>

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
                /* ❌ Swagger UI failed to load from CDN */
                document.getElementById('swagger-ui').innerHTML =
                    '<div class="alert alert-warning m-4">'
                    + '<i class="fa-solid fa-triangle-exclamation me-2"></i>'
                    + '<strong>Documentation Unavailable</strong><br>'
                    + 'The API documentation library could not be loaded. '
                    + 'Please check your internet connection or try again later.'
                    + '</div>';
            }
        });
    </script>

<?php
// ==============================================================================
// 📄 Load Footer Template
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
?>
