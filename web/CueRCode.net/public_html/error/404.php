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
    // 📌 The bootstrap is not available, so show the small self-contained page
    // (inline CSS only, no database, no config). The 404 status stays as before.
    http_response_code(404);

    $fallbackHelper = __DIR__ . '/../../_includes/fallback_page.php';
    if (is_file($fallbackHelper)) {
        require_once $fallbackHelper;
    }

    if (function_exists('cuercodeFallbackPageHtml')) {
        echo cuercodeFallbackPageHtml(
            'Page not found',
            'We can\'t find that page',
            'The address may be wrong, or the page may have moved. The home page is a good place to start again.'
        );
    } else {
        // 🧯 The helper itself could not load - last-resort minimal page
        echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Page not found | CueRCode</title></head>'
            . '<body><h1>We can\'t find that page</h1>'
            . '<p>The address may be wrong, or the page may have moved.</p>'
            . '<p><a href="/">Go to the CueRCode home page</a></p>'
            . '<p>If this keeps happening, email <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>.</p>'
            . '</body></html>';
    }
    exit;
}

// ==============================================================================
// 📄 Page Variables
// ==============================================================================

http_response_code(404);

$pageTitle       = 'Page not found';
$pageDescription = 'We could not find the page you asked for. Try the QR code generator, the plans or the developer guides instead.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-error page-404';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         🚫 404 Error Content
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
                    <p class="display-4 fw-bold text-body-secondary mb-2" aria-hidden="true">404</p>
                    <h1 class="h3 mb-3">We can't find that page</h1>
                    <p class="text-body-secondary mb-4">
                        The address may have been typed slightly wrong, or the page may have moved
                        or been removed. These pages might be what you were after:
                    </p>

                    <?php /* 🧭 Helpful next steps: the three places visitors most often want.
                             A Bootstrap list-group of real links (keyboard and screen reader friendly). */ ?>
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

                    <?php /* ⬅️ "Go back" is only useful if there is somewhere to go back to.
                             It starts hidden and the script below reveals it when the visitor
                             arrived from another page. Without JavaScript it simply stays hidden. */ ?>
                    <button type="button" class="btn btn-outline-secondary" id="error-go-back" hidden>
                        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Go back
                    </button>
                </div>
            </div>
        </div>
    </section>

    <script>
        (function () {
            <?php /* ⬅️ Show "Go back" only when the visitor has a page to return to: */ ?>
            <?php /* a non-empty referrer (they followed a link) and more than one */ ?>
            <?php /* history entry. Direct visits (typed address, scanned QR code, */ ?>
            <?php /* new tab) get no dead button. */ ?>
            var backButton = document.getElementById('error-go-back');
            if (!backButton) {
                return;
            }
            if (document.referrer !== '' && window.history.length > 1) {
                backButton.hidden = false;
                backButton.addEventListener('click', function () {
                    window.history.back();
                });
            }
        }());
    </script>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
