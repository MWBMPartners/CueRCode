<?php
/**
 * ==============================================================================
 * ⏸️ CueRCode - "This code is not in use" page (dynamic codes, lead choice L2)
 * ==============================================================================
 *
 * Path: public_html/unavailable/index.php
 *
 * WHAT IT IS (plain English):
 *   When the owner of a dynamic QR code switches it off, CueRCode points the
 *   code's Go2My.Link link here (https://cuercode.net/unavailable/?code=...)
 *   instead of switching the link off on Go2My.Link, whose own page says
 *   "disabled by its owner" and which its API cannot switch back on (plan
 *   .claude/plans/first-launch-plan.md, lead choice L2; WP4c, #166).
 *
 *   ⏳ MINIMAL PLACEHOLDER: WP4f (#169) completes this page for suspended
 *   codes (the takedown promise and a link to the report page). On purpose
 *   it says nothing about the code: it never shows a destination, never
 *   looks the code up, and gives the same words whether the code exists,
 *   was switched off by its owner or was suspended.
 *
 * @package     CueRCode
 * @subpackage  Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4c, #166)
 * @see         _functions/fn_redirects.php - dynamicQrUnavailablePageUrl()
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================
// 🔒 Never cached by a browser or a shared cache: a code switched back on
//    must not keep showing this page.
if (!headers_sent()) {
    header('Cache-Control: no-store');
}

$pageTitle       = 'This QR code is not in use';
$pageDescription = 'This QR code is not in use at the moment.';
$pageNoIndex     = true;
$bodyClass       = 'page-legal page-unavailable';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">
                    <p class="display-6 mb-3" aria-hidden="true"><i class="fa-solid fa-circle-pause"></i></p>
                    <h1 class="h2 fw-bold mb-3">This QR code is not in use</h1>
                    <p class="lead text-body-secondary mb-4">
                        The code you scanned does not lead anywhere at the moment. If you were expecting
                        something here, please ask whoever gave you the code.
                    </p>
                    <a class="btn btn-primary" href="/">
                        <i class="fa-solid fa-house me-1" aria-hidden="true"></i> Go to the CueRCode home page
                    </a>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
