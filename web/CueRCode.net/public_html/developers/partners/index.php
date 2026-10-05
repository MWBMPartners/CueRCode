<?php
/**
 * ==============================================================================
 * 🤝 CueRCode - Developer Portal: Planned Integrations
 * ==============================================================================
 *
 * Path: public_html/developers/partners/index.php
 *
 * Short, customer-facing page listing the integrations that are planned but not
 * available yet: Go2My.Link (short links), SIGNula (sign-in), and dynamic QR
 * codes (coming soon). It deliberately contains no internal project detail -
 * just what is planned and where to ask questions. The URL stays
 * /developers/partners/ so existing links keep working; only the visible label
 * is "Planned integrations". It will be replaced with real integration
 * documentation once an integration is actually released.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         PROJECT_ROADMAP.md - Epic E1 (Dynamic QR / Go2My.Link) and Epic E2 (SIGNula SSO)
 * @see         https://github.com/MWBMPartners/Go2My.Link/issues/137
 * @see         https://github.com/MWBMPartners/SIGNula.id/issues/88
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

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

// 📧 Contact address - same site setting the Terms page uses, with a safe default.
if (function_exists('getConfig')) {
    $result = getConfig('site_email', 'hello@cuercode.net');
    if (isset($result)) {
        $siteEmail = $result;
    } else {
        $siteEmail = 'hello@cuercode.net';
    }
} else {
    $siteEmail = 'hello@cuercode.net';
}

$pageTitle       = 'Planned integrations';
$pageDescription = 'Integrations planned for ' . $siteName . ': Go2My.Link short links, SIGNula sign-in, '
    . 'and dynamic QR codes (coming soon).';
$bodyClass       = 'page-developers page-developers-partners';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'partners';

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'developers_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">
                        Planned integrations
                    </h1>
                    <p class="text-body-secondary mb-4">
                        We are planning a few integrations with other MWBM Partners Ltd products.
                        None of them is available yet.
                    </p>

                    <div class="legal-content">

                        <?php /* ============================================================
                             Go2My.Link
                             ============================================================ */ ?>
                        <h2 class="h5 mt-4 mb-3">Go2My.Link (short links)</h2>
                        <p>
                            An integration with Go2My.Link, our short-link service, is planned. It is
                            intended to support dynamic QR codes, described below.
                        </p>

                        <?php /* ============================================================
                             SIGNula
                             ============================================================ */ ?>
                        <h2 class="h5 mt-4 mb-3">SIGNula (sign-in)</h2>
                        <p>
                            An integration with SIGNula is planned, so that you will be able to sign in to
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> with a SIGNula
                            account. For now, you can
                            <a href="/register/">create a free account</a> directly with us.
                        </p>

                        <?php /* ============================================================
                             Dynamic QR codes
                             ============================================================ */ ?>
                        <h2 class="h5 mt-4 mb-3">Dynamic QR codes (coming soon)</h2>
                        <div class="alert alert-secondary" role="note">
                            <p class="mb-0">
                                <i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i>
                                <strong>Coming soon</strong> - dynamic QR codes, whose destination you can
                                change after the code has been printed. See the
                                <a href="/developers/recipes/#dynamic" class="alert-link">Integration Recipes</a>
                                page for a short note.
                            </p>
                        </div>

                        <p class="mt-4 mb-0 small text-body-secondary">
                            Building something that needs an integration of its own? Email us at
                            <a href="mailto:<?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?></a>
                            and tell us what you have in mind. To see how API keys work today, read
                            <a href="/developers/authentication/">Authentication</a>.
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
