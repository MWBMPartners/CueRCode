<?php
/**
 * ==============================================================================
 * 🙏 CueRCode - Third-Party Attributions
 * ==============================================================================
 *
 * Path: public_html/attributions/index.php
 *
 * Consolidated attribution/credits page for third-party assets and libraries
 * vendored into CueRCode. Created primarily to satisfy the Twemoji (CC-BY-4.0)
 * attribution REQUIREMENT (Issue #67 - the emoji centre-icon picker uses
 * Twemoji SVG/PNG graphics) - CC-BY licences legally require attribution
 * wherever the licensed work is used, unlike the permissive MIT/Apache/OFL
 * licences of this project's other vendored dependencies (listed below for
 * completeness/goodwill, not because they mandate an attribution page).
 * Linked from the site footer.
 *
 * @package     CueRCode
 * @subpackage  Pages / Legal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Issue #67 (native emoji centre-icon picker)
 * @see         web/CueRCode.net/public_html/assets/img/emoji/ - the vendored Twemoji assets
 * @see         https://creativecommons.org/licenses/by/4.0/
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

// 📅 Dynamic copyright year for the footer notice on this page.
$currentYear = date('Y');

$pageTitle       = 'Third-Party Attributions';
$pageDescription = 'Attribution and licensing information for third-party assets and '
    . 'open-source libraries used by ' . $siteName . ', including the Twemoji '
    . '(CC-BY-4.0) emoji graphics used in the emoji centre-icon picker.';
$bodyClass       = 'page-legal page-attributions';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         🙏 Third-Party Attributions Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="h2 fw-bold mb-4">
                        <i class="fa-solid fa-hands-holding-heart me-2" aria-hidden="true"></i>
                        Third-Party Attributions
                    </h1>
                    <p class="text-body-secondary mb-4">
                        <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> is built with the
                        help of several open-source projects and asset sets. This page credits each of
                        them and links to their licences.
                    </p>

                    <div class="legal-content">

                        <!-- ============================================================
                             😀 Twemoji (required attribution - CC-BY-4.0)
                             ============================================================ -->
                        <h2 id="twemoji" class="h5 mt-4 mb-3">Emoji Graphics: Twemoji</h2>
                        <p>
                            The emoji centre-icon picker (Logo tab) uses graphics from
                            <a href="https://github.com/jdecked/twemoji" target="_blank" rel="noopener noreferrer">Twemoji</a>
                            (the community-maintained continuation of Twitter's original open-source emoji
                            set), copyright &copy; Twitter, Inc and other contributors, licensed under
                            <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener noreferrer">
                                Creative Commons Attribution 4.0 International (CC-BY-4.0)
                            </a>.
                        </p>
                        <p>
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> vendors a
                            <strong>curated starter set</strong> of the Twemoji SVG and 72&times;72 PNG
                            graphics (a subset of the full ~4,000-emoji catalogue) under
                            <code>/assets/img/emoji/</code>. Graphics are unmodified from their upstream
                            form.
                        </p>

                        <!-- ============================================================
                             📚 Other vendored open-source libraries
                             ============================================================ -->
                        <h2 id="libraries" class="h5 mt-4 mb-3">Open-Source Libraries</h2>
                        <p class="text-body-secondary">
                            Listed for completeness and goodwill - the permissive licences below (MIT,
                            LGPL-3.0-or-later) do not mandate a dedicated attribution page, unlike
                            Twemoji's CC-BY-4.0 licence above.
                        </p>
                        <ul>
                            <li>
                                <strong><a href="https://github.com/chillerlan/php-qrcode" target="_blank" rel="noopener noreferrer">chillerlan/php-qrcode</a></strong>
                                - the core QR code generation engine. MIT licence.
                            </li>
                            <li>
                                <strong><a href="https://github.com/chillerlan/php-settings-container" target="_blank" rel="noopener noreferrer">chillerlan/php-settings-container</a></strong>
                                - a dependency of php-qrcode. MIT licence.
                            </li>
                            <li>
                                <strong><a href="http://www.fpdf.org/" target="_blank" rel="noopener noreferrer">FPDF</a></strong>
                                - used for accessible/tactile PDF print layouts. Free/permissive licence.
                            </li>
                            <li>
                                <strong><a href="https://github.com/tecnickcom/tc-lib-barcode" target="_blank" rel="noopener noreferrer">tecnickcom/tc-lib-barcode</a></strong>
                                (with <a href="https://github.com/tecnickcom/tc-lib-color" target="_blank" rel="noopener noreferrer">tc-lib-color</a>)
                                - the non-QR (1D/2D) barcode generation engine. LGPL-3.0-or-later.
                            </li>
                            <li>
                                <strong><a href="https://getbootstrap.com/" target="_blank" rel="noopener noreferrer">Bootstrap</a></strong>
                                - responsive UI framework. MIT licence.
                            </li>
                            <li>
                                <strong><a href="https://fontawesome.com/" target="_blank" rel="noopener noreferrer">Font Awesome</a></strong>
                                - icon set (Free tier). Font Awesome Free licence (icons: CC-BY-4.0; code: MIT).
                            </li>
                            <li>
                                <strong><a href="https://jquery.com/" target="_blank" rel="noopener noreferrer">jQuery</a></strong>
                                - JavaScript utility library. MIT licence.
                            </li>
                        </ul>

                        <p class="text-body-secondary mt-4">
                            &copy; <?php echo htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
