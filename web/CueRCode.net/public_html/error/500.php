<?php
/**
 * ==============================================================================
 * 💥 CueRCode - 500 Internal Server Error Page
 * ==============================================================================
 *
 * Path: public_html/error/500.php
 *
 * Displayed when an unhandled server error occurs.
 * Referenced in public_html/.htaccess as the custom ErrorDocument 500.
 *
 * @package     CueRCode
 * @subpackage  Error Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/500
 */

// ==============================================================================
// 🚀 Load Application Bootstrap (with error handling)
// ==============================================================================

$bootstrapPath = __DIR__ . '/../../_includes/bootstrap.php';

// 🛟 The small self-contained page is used if the bootstrap cannot be loaded.
// It lives in _includes/fallback_page.php (inline CSS only, no database, no
// config). If even that file is missing, a tiny inline page is printed instead.
// The 500 status code stays exactly as before.
$fallbackHelper = __DIR__ . '/../../_includes/fallback_page.php';

/**
 * 🛟 Print the bare 500 page and stop.
 *
 * Declared as a closure so both failure paths below share one copy of the
 * markup, and it never touches the database, config or other includes.
 */
$sendBareServerErrorPage = function () use ($fallbackHelper): void {
    http_response_code(500);

    if (is_file($fallbackHelper)) {
        require_once $fallbackHelper;
    }

    if (function_exists('cuercodeFallbackPageHtml')) {
        echo cuercodeFallbackPageHtml(
            'Technical problem',
            'We\'re having a technical problem',
            'Please try again in a few minutes.'
        );
    } else {
        // 🧯 The helper itself could not load - last-resort minimal page
        echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Technical problem | CueRCode</title></head>'
            . '<body><h1>We\'re having a technical problem</h1>'
            . '<p>Please try again in a few minutes.</p>'
            . '<p><a href="/">Go to the CueRCode home page</a></p>'
            . '<p>If this keeps happening, email <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>.</p>'
            . '</body></html>';
    }
    exit;
};

if (file_exists($bootstrapPath)) {
    try {
        require_once $bootstrapPath;
    } catch (\Throwable $e) {
        // 📌 Bootstrap itself failed - show the small self-contained page
        $sendBareServerErrorPage();
    }
} else {
    $sendBareServerErrorPage();
}

// ==============================================================================
// 📄 Page Variables
// ==============================================================================

http_response_code(500);

$pageTitle       = 'Something went wrong';
$pageDescription = 'Something went wrong on our side and the error has been logged. Please try again in a few minutes.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-error page-500';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         💥 500 Error Content
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6 text-center">
                    <div class="mb-4">
                        <i class="fa-solid fa-screwdriver-wrench fa-5x text-cuercode-primary opacity-75" aria-hidden="true"></i>
                    </div>
                    <p class="display-4 fw-bold text-body-secondary mb-2" aria-hidden="true">500</p>
                    <h1 class="h3 mb-3">Something went wrong on our side</h1>
                    <p class="text-body-secondary mb-3">
                        The error has been logged. Please try again in a few minutes.
                    </p>
                    <p class="text-body-secondary mb-4">
                        If it keeps happening, email
                        <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>
                        and tell us what you were doing when it went wrong.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-primary" onclick="location.reload();">
                            <i class="fa-solid fa-rotate-right me-1" aria-hidden="true"></i> Try again
                        </button>
                        <a href="/" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> Go to the generator
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
