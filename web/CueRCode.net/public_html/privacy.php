<?php
/**
 * ==============================================================================
 * 🔒 CueRCode - Privacy Policy Page
 * ==============================================================================
 *
 * Displays the Privacy Policy for the CueRCode platform.
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

$pageTitle       = 'Privacy Policy';
$pageDescription = 'Privacy Policy for ' . $siteName . ' - How we handle your data.';
$bodyClass       = 'page-legal page-privacy';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         🔒 Privacy Policy Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="h2 fw-bold mb-4">Privacy Policy</h1>
                    <p class="text-body-secondary mb-4">
                        <strong>Last updated:</strong> <?php echo date('F j, Y'); ?>
                    </p>

                    <div class="legal-content">
                        <h2 class="h5 mt-4 mb-3">1. Introduction</h2>
                        <p>
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?> ("we", "us", "our")
                            operates <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> ("the Service").
                            This Privacy Policy explains how we collect, use, and protect information when you
                            use our Service.
                        </p>

                        <h2 class="h5 mt-4 mb-3">2. Information We Collect</h2>
                        <h3 class="h6 mt-3 mb-2">2.1 QR Code Data</h3>
                        <p>
                            When you generate a QR code, we temporarily process the content you provide (URLs, text,
                            contact details, etc.) to create the QR code. Generated QR codes and their associated
                            metadata may be stored for service functionality and analytics purposes.
                        </p>

                        <h3 class="h6 mt-3 mb-2">2.2 Technical Data</h3>
                        <p>We automatically collect:</p>
                        <ul>
                            <li>IP address (anonymised for analytics)</li>
                            <li>Browser type and version</li>
                            <li>Operating system</li>
                            <li>Referring page URL</li>
                            <li>Pages visited and time spent</li>
                            <li>QR code generation patterns (type, format, customisation choices)</li>
                        </ul>

                        <h3 class="h6 mt-3 mb-2">2.3 Uploaded Content</h3>
                        <p>
                            If you upload a custom logo for your QR code, the image is processed server-side
                            and may be temporarily stored. We do not retain uploaded logos beyond the generation
                            session unless you create an account (Phase 2).
                        </p>

                        <h2 class="h5 mt-4 mb-3">3. How We Use Information</h2>
                        <p>We use collected information to:</p>
                        <ul>
                            <li>Generate and deliver QR codes as requested</li>
                            <li>Maintain and improve the Service</li>
                            <li>Monitor for abuse and enforce our Terms of Use</li>
                            <li>Generate anonymised usage statistics</li>
                            <li>Debug and resolve technical issues</li>
                        </ul>

                        <h2 class="h5 mt-4 mb-3">4. Cookies and Session Data</h2>
                        <p>
                            We use a session cookie (<code>CUERCODE_SID</code>) to maintain your session state,
                            including CSRF protection tokens and theme preferences. This cookie is:
                        </p>
                        <ul>
                            <li>HTTP-only (not accessible to JavaScript)</li>
                            <li>SameSite: Lax (CSRF protection)</li>
                            <li>Secure (transmitted only over HTTPS when available)</li>
                            <li>Expires when you close your browser or after the session timeout</li>
                        </ul>
                        <p>
                            We also use <code>localStorage</code> to remember your colour theme preference
                            (dark/light mode). This data stays on your device and is not transmitted to our servers.
                        </p>

                        <h2 class="h5 mt-4 mb-3">5. Data Sharing</h2>
                        <p>
                            We do not sell, rent, or share your personal information with third parties,
                            except as required by law or to protect our rights. We may share anonymised,
                            aggregated data for analytics purposes.
                        </p>

                        <h2 class="h5 mt-4 mb-3">6. Data Security</h2>
                        <p>
                            We implement appropriate technical measures to protect your data, including:
                        </p>
                        <ul>
                            <li>AES-256-GCM encryption for sensitive configuration data</li>
                            <li>CSRF token protection for all state-changing requests</li>
                            <li>Prepared statements for all database operations (SQL injection prevention)</li>
                            <li>Input validation and output escaping (XSS prevention)</li>
                            <li>Secure session configuration with HTTP-only cookies</li>
                        </ul>

                        <h2 class="h5 mt-4 mb-3">7. Data Retention</h2>
                        <p>
                            Generated QR code records are retained for service functionality. Error logs
                            are retained for debugging purposes and periodically purged. You may request
                            deletion of your data by contacting us.
                        </p>

                        <h2 class="h5 mt-4 mb-3">8. Your Rights</h2>
                        <p>
                            Depending on your jurisdiction, you may have the right to:
                        </p>
                        <ul>
                            <li>Access the personal data we hold about you</li>
                            <li>Request correction of inaccurate data</li>
                            <li>Request deletion of your data</li>
                            <li>Object to processing of your data</li>
                            <li>Data portability</li>
                        </ul>

                        <h2 class="h5 mt-4 mb-3">9. Changes to This Policy</h2>
                        <p>
                            We may update this Privacy Policy from time to time. Changes will be posted
                            on this page with an updated revision date.
                        </p>

                        <h2 class="h5 mt-4 mb-3">10. Contact</h2>
                        <p>
                            For privacy-related enquiries, please contact us at
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
