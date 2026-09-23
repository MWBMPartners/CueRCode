<?php
/**
 * ==============================================================================
 * 📚 CueRCode - Developer Portal Landing Page
 * ==============================================================================
 *
 * Path: public_html/developers/index.php
 *
 * The developer portal's front door: explains what the CueRCode API is, the
 * "API-first / API-UI parity" principle that governs the whole project (the
 * web UI is just one client of the same API every integrator uses), links to
 * the interactive Swagger UI, and a left-nav into the narrative guides below
 * (quickstart, authentication, live endpoint reference, errors/rate-limits,
 * code samples, integration recipes, partner guide).
 *
 * Scope note (issue #50, E6 "Developer portal"): this pass covers items 1, 2,
 * and 4 of the epic draft - the public docs, samples, and the openapi.json-
 * rendered reference. Item 3, self-service API-key management (create/rotate/
 * revoke in an account area), is now BUILT via Epic E7 stage 1 (#54) - see the
 * "Get your own API key" box below and the Authentication guide for the
 * step-by-step walkthrough.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         PROJECT_ROADMAP.md - "E6 - Partner & user API integration guides + developer portal"
 * @see         public_html/api/v1/openapi.json - the API contract this whole section documents
 * @see         public_html/api/docs/index.php - the existing interactive Swagger UI
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

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

$pageTitle       = 'Developer Portal';
$pageDescription = 'Build on the ' . $siteName . ' API: quickstart, authentication, a live '
    . 'endpoint reference generated from openapi.json, code samples, and integration recipes.';
$bodyClass       = 'page-developers page-developers-overview';
$pageCSS         = [];
$pageJS          = [];

// 🧭 Tell the shared sidebar which section is active on this page.
$developersActive = 'overview';

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         📚 Developer Portal Landing Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">

            <!-- 📌 Hero -->
            <div class="row justify-content-center mb-4">
                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">
                        <i class="fa-solid fa-code me-2" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> Developer Portal
                    </h1>
                    <p class="lead text-body-secondary">
                        <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> is API-first: the
                        web generator you can use at
                        <a href="/">/</a> is built <strong>entirely on top of</strong> the same public
                        REST API documented here. Every option the UI exposes - every QR code type,
                        every colour/pattern/logo/frame customisation, every output format - is
                        available to you as a direct API call. There is no "UI-only" feature held back
                        from integrators.
                    </p>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a href="/developers/quickstart/" class="btn btn-primary">
                            <i class="fa-solid fa-bolt me-1" aria-hidden="true"></i>
                            Quickstart (PNG in under a minute)
                        </a>
                        <a href="/api/docs/" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>
                            Open interactive Swagger UI
                        </a>
                    </div>
                </div>
            </div>

            <div class="row justify-content-center">
                <!-- 🧭 Left-nav to the sub-pages -->
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'developers_nav.php'; ?>
                </div>

                <!-- 📖 Main content -->
                <div class="col-lg-6">

                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="h5 mb-0">
                                <i class="fa-solid fa-diagram-project me-2" aria-hidden="true"></i>
                                The API-first / UI-parity principle
                            </h2>
                        </div>
                        <div class="card-body">
                            <p>
                                Per the project brief, <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>'s
                                web UI is just <em>one client</em> of the public API - not a privileged
                                first-party surface with extra capabilities. That means:
                            </p>
                            <ul class="mb-0">
                                <li>Anything you can build in the web generator, you can automate via
                                    <code>POST /api/v1/generate</code> - same 26 QR code types, same colours/
                                    patterns/marker shapes/logos/frames/gradients, same 6 output formats.</li>
                                <li>The API is never a "reduced" surface: it is a strict <strong>superset</strong>
                                    of what the UI exposes, including advanced options the UI doesn't have a
                                    control for yet (e.g. SVG gradients).</li>
                                <li>The OpenAPI 3.0.3 specification at
                                    <code>/api/v1/openapi.json</code> is the single source of truth for the
                                    API surface. The <a href="/developers/reference/">Endpoint Reference</a>
                                    page on this portal, and the <a href="/api/docs/">Swagger UI</a>, are both
                                    generated FROM that file at request time - nothing here is a hand-copied,
                                    driftable duplicate.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="h5 mb-0">
                                <i class="fa-solid fa-compass me-2" aria-hidden="true"></i>
                                Where to start
                            </h2>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0 d-grid gap-3">
                                <li>
                                    <a href="/developers/quickstart/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-bolt me-1" aria-hidden="true"></i>Quickstart</a>
                                    <div class="small text-body-secondary">Copy-paste curl (and Python) to
                                        generate a real QR code PNG in under a minute - no account needed.</div>
                                </li>
                                <li>
                                    <a href="/developers/authentication/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-key me-1" aria-hidden="true"></i>Authentication</a>
                                    <div class="small text-body-secondary">API keys vs. session CSRF tokens vs.
                                        anonymous access, and how to create your own self-service key.</div>
                                </li>
                                <li>
                                    <a href="/developers/reference/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-list-check me-1" aria-hidden="true"></i>Endpoint Reference</a>
                                    <div class="small text-body-secondary">Every path, method, parameter,
                                        request/response schema, and error - rendered LIVE from openapi.json.</div>
                                </li>
                                <li>
                                    <a href="/developers/errors/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>Errors &amp; Rate Limits</a>
                                    <div class="small text-body-secondary">The machine-readable error model
                                        (<code>error_code</code>), HTTP status codes, and the
                                        <code>X-RateLimit-*</code>/<code>Retry-After</code> headers.</div>
                                </li>
                                <li>
                                    <a href="/developers/samples/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-code me-1" aria-hidden="true"></i>Code Samples</a>
                                    <div class="small text-body-secondary">Working curl, PHP, JavaScript
                                        (fetch), and Python (requests) samples for every core endpoint.</div>
                                </li>
                                <li>
                                    <a href="/developers/recipes/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-flask me-1" aria-hidden="true"></i>Integration Recipes</a>
                                    <div class="small text-body-secondary">QR-for-your-website, bulk-from-a-
                                        spreadsheet, accessible QR via API, and a "coming soon" dynamic QR recipe.</div>
                                </li>
                                <li>
                                    <a href="/developers/partners/" class="fw-semibold text-decoration-none">
                                        <i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Partner Guide</a>
                                    <div class="small text-body-secondary">Status of the Go2My.Link and
                                        SIGNula partner integration contracts.</div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- ========================================================
                         ✅ Self-service API keys (Epic E7 stage 1, #54)
                         ======================================================== -->
                    <div class="alert alert-success" role="note">
                        <h2 class="h6 alert-heading">
                            <i class="fa-solid fa-key me-1" aria-hidden="true"></i>
                            Get your own API key
                        </h2>
                        <p class="mb-0">
                            Create a free account at <a href="/register/" class="alert-link">/register/</a>,
                            then create, name, scope, rotate, and revoke your own keys from
                            <a href="/account/#api-keys-section" class="alert-link">Account &gt; API Keys</a>.
                            The free plan includes 1 API key. See
                            <a href="/developers/authentication/#getting-a-key" class="alert-link">Authentication
                            &gt; Getting an API key</a> for the step-by-step walkthrough.
                        </p>
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
