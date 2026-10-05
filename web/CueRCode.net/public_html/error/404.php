<?php
/**
 * ==============================================================================
 * 🚫 CueRCode - 404 Not Found Error Page
 * ==============================================================================
 *
 * Path: public_html/error/404.php
 *
 * Displayed when a requested page or resource cannot be found.
 * Referenced in public_html/.htaccess as the custom ErrorDocument 404.
 *
 * @package     CueRCode
 * @subpackage  Error Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/404
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================

$bootstrapPath = __DIR__ . '/../../_includes/bootstrap.php';

if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    // 📌 Minimal fallback if bootstrap is not available
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><title>404 Not Found</title></head>';
    echo '<body><h1>404 - Page Not Found</h1>';
    echo '<p>The page you requested could not be found.</p>';
    echo '<p><a href="/">Return to Home</a></p></body></html>';
    exit;
}

// ==============================================================================
// 📄 Page Variables
// ==============================================================================

http_response_code(404);

$pageTitle       = '404 - Page Not Found';
$pageDescription = 'The requested page could not be found.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-error page-404';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         🚫 404 Error Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6 text-center">
                    <div class="mb-4">
                        <i class="fa-solid fa-ghost fa-5x" style="color: var(--cuercode-primary, #2c7a7b); opacity: 0.6;" aria-hidden="true"></i>
                    </div>
                    <p class="display-4 fw-bold text-body-secondary" aria-hidden="true">404</p>
                    <h1 class="h4 mb-3">Page Not Found</h1>
                    <p class="text-body-secondary mb-4">
                        The page you're looking for doesn't exist or has been moved.
                        It might have been a broken link, or perhaps the URL was typed incorrectly.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="/" class="btn btn-primary">
                            <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> QR Generator
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="history.back();">
                            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Go Back
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
