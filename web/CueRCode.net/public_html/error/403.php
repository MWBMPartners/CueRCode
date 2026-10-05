<?php
/**
 * ==============================================================================
 * 🔒 CueRCode - 403 Forbidden Error Page
 * ==============================================================================
 *
 * Path: public_html/error/403.php
 *
 * Displayed when a visitor asks for something the site deliberately keeps
 * private: hidden files, blocked file types, the installer folder, or a folder
 * that has no index page (directory listing is switched off).
 * Referenced in public_html/.htaccess as the custom ErrorDocument 403.
 *
 * Modelled on error/404.php so all the error pages look like one family.
 *
 * @package     CueRCode
 * @subpackage  Error Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/403
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================

$bootstrapPath = __DIR__ . '/../../_includes/bootstrap.php';

if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    // 📌 The bootstrap is not available, so show the small self-contained page
    // (inline CSS only, no database, no config). The 403 status is kept.
    http_response_code(403);

    $fallbackHelper = __DIR__ . '/../../_includes/fallback_page.php';
    if (is_file($fallbackHelper)) {
        require_once $fallbackHelper;
    }

    if (function_exists('cuercodeFallbackPageHtml')) {
        echo cuercodeFallbackPageHtml(
            'No access',
            'You don\'t have access to this page',
            'This page is private. The home page is a good place to start again.'
        );
    } else {
        // 🧯 The helper itself could not load - last-resort minimal page
        echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>No access | CueRCode</title></head>'
            . '<body><h1>You don\'t have access to this page</h1>'
            . '<p>This page is private.</p>'
            . '<p><a href="/">Go to the CueRCode home page</a></p>'
            . '<p>If you think this is a mistake, email <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>.</p>'
            . '</body></html>';
    }
    exit;
}

// ==============================================================================
// 📄 Page Variables
// ==============================================================================

http_response_code(403);

$pageTitle       = 'No access';
$pageDescription = 'You don\'t have access to this page. Try the QR code generator, the plans or the developer guides instead.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-error page-403';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
              🔒 403 Error Content
              ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6 text-center">
                    <?php /* 🖼️ The CueRCode logo mark (decorative, so alt is empty). It sits on a
                             white tile so the dark squares stay visible in dark mode too. */ ?>
                    <div class="mb-4">
                        <span class="d-inline-block bg-white rounded-4 p-2 shadow-sm">
                            <img src="/assets/img/branding/logo-mark.svg" alt="" width="96" height="96">
                        </span>
                    </div>
                    <p class="display-4 fw-bold text-body-secondary mb-2" aria-hidden="true">403</p>
                    <h1 class="h3 mb-3">You don't have access to this page</h1>
                    <p class="text-body-secondary mb-4">
                        This page is private, or it isn't meant to be opened directly.
                        These pages might be what you were after instead:
                    </p>

                    <?php /* 🧭 Helpful next steps (same three links as the 404 page) */ ?>
                    <nav aria-label="Pages you might be looking for">
                        <div class="list-group text-start mb-4">
                            <a href="/" class="list-group-item list-group-item-action">
                                <span class="fw-semibold d-block">QR Code Generator</span>
                                <span class="small text-body-secondary">Make a QR code or barcode, free and without an account.</span>
                            </a>
                            <a href="/pricing/" class="list-group-item list-group-item-action">
                                <span class="fw-semibold d-block">Pricing</span>
                                <span class="small text-body-secondary">See what each plan includes.</span>
                            </a>
                            <a href="/developers/" class="list-group-item list-group-item-action">
                                <span class="fw-semibold d-block">Developers</span>
                                <span class="small text-body-secondary">Guides and reference for the CueRCode API.</span>
                            </a>
                        </div>
                    </nav>

                    <p class="small text-body-secondary mb-0">
                        Think this is a mistake? Email
                        <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>
                        and tell us which page you were trying to open.
                    </p>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
