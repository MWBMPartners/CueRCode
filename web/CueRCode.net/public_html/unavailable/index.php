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
 *   WP4f (#169): a SUSPENDED code (taken down after reports or a link-safety
 *   hit) is pointed here too. On purpose this ONE neutral page serves both
 *   and says nothing about the code: it never shows a destination, never
 *   looks the code up, and gives the same words whether the code exists, was
 *   switched off by its owner or was suspended - so nobody can use it to
 *   learn which codes exist or which were taken down. It shows the takedown
 *   promise (decision 0034) and a link to the report page with the code
 *   filled in (the code's shape is checked; nothing is looked up).
 *
 * @package     CueRCode
 * @subpackage  Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       2026-11-28 (WP4c, #166; completed for suspended codes in WP4f, #169)
 * @see         _functions/fn_redirects.php - dynamicQrUnavailablePageUrl()
 * @see         public_html/report/index.php
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_takedown.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_abuse_reports.php';

// 🔗 The report page, with the code filled in when it has a code's shape.
$unavailableReportUrl = '/report/';
if (isset($_GET['code']) && is_string($_GET['code'])) {
    $unavailableCode = abuseReportCodeFromText($_GET['code']);
    if ($unavailableCode !== '') {
        $unavailableReportUrl = '/report/?code=' . rawurlencode($unavailableCode);
    }
}
$unavailablePromise = dynamicQrTakedownPromise();

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
                    <p class="lead text-body-secondary mb-3">
                        The code you scanned does not lead anywhere at the moment. Its owner may have switched it
                        off, or it may be paused while we look into it. If you were expecting something here,
                        please ask whoever gave you the code.
                    </p>
                    <p class="mb-4">
                        If you think this code was being used to trick or harm people, please tell us.
                        <?php echo htmlspecialchars($unavailablePromise, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars($unavailableReportUrl, ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-solid fa-flag me-1" aria-hidden="true"></i> Report this code
                        </a>
                        <a class="btn btn-primary" href="/">
                            <i class="fa-solid fa-house me-1" aria-hidden="true"></i> Go to the CueRCode home page
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
