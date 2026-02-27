<?php
/**
 * ==============================================================================
 * 📜 CueRCode - Terms of Use Page
 * ==============================================================================
 *
 * Displays the Terms of Use / Terms of Service for the CueRCode platform.
 *
 * @package     CueRCode
 * @subpackage  Legal
 * @version     1.0.0
 * @since       Phase 1
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================

require_once __DIR__ . '/../_includes/bootstrap.php';

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

if (function_exists('getConfig')) {
    $result = getConfig('site_url', 'https://cuercode.net');
    if (isset($result)) {
        $siteURL = $result;
    } else {
        $siteURL = 'https://cuercode.net';
    }
} else {
    $siteURL = 'https://cuercode.net';
}

if (function_exists('getConfig')) {
    $result = getConfig('copyright_holder', 'MWBM Partners Ltd');
    if (isset($result)) {
        $copyrightHolder = $result;
    } else {
        $copyrightHolder = 'MWBM Partners Ltd';
    }
} else {
    $copyrightHolder = 'MWBM Partners Ltd';
}

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

$pageTitle       = 'Terms of Use';
$pageDescription = 'Terms of Use for ' . $siteName . ' - QR Code Generator service.';
$bodyClass       = 'page-legal page-terms';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         📜 Terms of Use Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="h2 fw-bold mb-4">Terms of Use</h1>
                    <p class="text-body-secondary mb-4">
                        <strong>Last updated:</strong> <?php echo date('F j, Y'); ?>
                    </p>

                    <div class="legal-content">
                        <h2 class="h5 mt-4 mb-3">1. Acceptance of Terms</h2>
                        <p>
                            By accessing and using <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
                            ("the Service"), operated by <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            ("we", "us", "our"), you accept and agree to be bound by these Terms of Use.
                            If you do not agree to these terms, please do not use the Service.
                        </p>

                        <h2 class="h5 mt-4 mb-3">2. Description of Service</h2>
                        <p>
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> provides an online QR code
                            generation service that allows users to create customised QR codes for various purposes
                            including URLs, text, contact information, and other data formats. The Service includes
                            both a web-based interface and a RESTful API.
                        </p>

                        <h2 class="h5 mt-4 mb-3">3. Acceptable Use</h2>
                        <p>You agree not to use the Service to:</p>
                        <ul>
                            <li>Generate QR codes containing malicious URLs, malware, or phishing content</li>
                            <li>Create content that is illegal, defamatory, obscene, or infringes on intellectual property rights</li>
                            <li>Attempt to overwhelm, disrupt, or impair the Service through excessive automated requests</li>
                            <li>Circumvent any rate limiting, access controls, or security measures</li>
                            <li>Resell or redistribute the Service without prior written consent</li>
                            <li>Upload logos or images that you do not have the right to use</li>
                        </ul>

                        <h2 class="h5 mt-4 mb-3">4. Intellectual Property</h2>
                        <p>
                            QR codes generated using the Service are yours to use freely. However, the Service itself,
                            including its design, code, and branding, remains the intellectual property of
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>.
                        </p>

                        <h2 class="h5 mt-4 mb-3">5. API Usage</h2>
                        <p>
                            Use of the API is subject to rate limits as documented in the API documentation.
                            API keys are issued at our discretion and may be revoked if these Terms are violated.
                            You are responsible for keeping your API key confidential.
                        </p>

                        <h2 class="h5 mt-4 mb-3">6. Disclaimer of Warranties</h2>
                        <p>
                            The Service is provided "as is" and "as available" without any warranties of any kind,
                            either express or implied. We do not guarantee that the Service will be uninterrupted,
                            error-free, or that generated QR codes will be scannable in all environments.
                        </p>

                        <h2 class="h5 mt-4 mb-3">7. Limitation of Liability</h2>
                        <p>
                            To the fullest extent permitted by law,
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            shall not be liable for any indirect, incidental, special, consequential, or punitive
                            damages arising from your use of the Service.
                        </p>

                        <h2 class="h5 mt-4 mb-3">8. Changes to Terms</h2>
                        <p>
                            We reserve the right to modify these Terms at any time. Changes will be posted on this
                            page with an updated revision date. Continued use of the Service after changes constitutes
                            acceptance of the new Terms.
                        </p>

                        <h2 class="h5 mt-4 mb-3">9. Contact</h2>
                        <p>
                            For questions about these Terms, please contact us at
                            <a href="mailto:<?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?>
                            </a>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
