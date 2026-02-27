<?php
/**
 * ==============================================================================
 * 💥 CueRCode - 500 Internal Server Error Page
 * ==============================================================================
 *
 * Displayed when an unhandled server error occurs.
 * Referenced in public_html/.htaccess as the custom ErrorDocument 500.
 *
 * @package     CueRCode
 * @subpackage  Error Pages
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/500
 */

// ==============================================================================
// 🚀 Load Application Bootstrap (with error handling)
// ==============================================================================

$bootstrapPath = __DIR__ . '/../../_includes/bootstrap.php';

if (file_exists($bootstrapPath)) {
    try {
        require_once $bootstrapPath;
    } catch (\Throwable $e) {
        // 📌 Bootstrap itself failed - show minimal page
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><title>500 Server Error</title></head>';
        echo '<body><h1>500 - Internal Server Error</h1>';
        echo '<p>Something went wrong on our end. Please try again later.</p>';
        echo '<p><a href="/">Return to Home</a></p></body></html>';
        exit;
    }
} else {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><title>500 Server Error</title></head>';
    echo '<body><h1>500 - Internal Server Error</h1>';
    echo '<p>Something went wrong on our end. Please try again later.</p>';
    echo '<p><a href="/">Return to Home</a></p></body></html>';
    exit;
}

// ==============================================================================
// 📄 Page Variables
// ==============================================================================

http_response_code(500);

$pageTitle       = '500 - Server Error';
$pageDescription = 'An internal server error has occurred.';
$bodyClass       = 'page-error page-500';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         💥 500 Error Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6 text-center">
                    <div class="mb-4">
                        <i class="fa-solid fa-screwdriver-wrench fa-5x" style="color: var(--cuercode-primary, #2c7a7b); opacity: 0.6;" aria-hidden="true"></i>
                    </div>
                    <p class="display-4 fw-bold text-body-secondary" aria-hidden="true">500</p>
                    <h1 class="h4 mb-3">Internal Server Error</h1>
                    <p class="text-body-secondary mb-4">
                        Something went wrong on our end. Our team has been notified
                        and we're working to fix the issue. Please try again in a few moments.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="/" class="btn btn-primary">
                            <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> QR Generator
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload();">
                            <i class="fa-solid fa-rotate-right me-1" aria-hidden="true"></i> Try Again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
