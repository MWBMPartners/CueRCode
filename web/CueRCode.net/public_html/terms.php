<?php
/**
 * ==============================================================================
 * 📜 CueRCode - Terms of Use Page
 * ==============================================================================
 *
 * Path: public_html/terms.php
 *
 * Displays the Terms of Use / Terms of Service for the CueRCode platform.
 *
 * ⚠️ ============================================================================
 * ⚠️  TEMPLATE — review by qualified legal counsel before production launch.
 * ⚠️ ----------------------------------------------------------------------------
 * ⚠️  The content below is a comprehensive, good-faith template covering
 * ⚠️  acceptance, acceptable use, intellectual property, API terms, disclaimers,
 * ⚠️  limitation of liability, indemnity, governing law and changes. It is NOT
 * ⚠️  legal advice. Placeholders (governing-law jurisdiction, dispute venue,
 * ⚠️  company registration details, etc.) MUST be completed and the whole
 * ⚠️  document MUST be reviewed and approved by a qualified lawyer before
 * ⚠️  going live.
 * ⚠️ ============================================================================
 *
 * @package     CueRCode
 * @subpackage  Legal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     2.0.0
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

// 📅 Dynamic copyright year, with the brand's establishment year for the
//    intellectual-property notice range (e.g. "2025–2026").
$establishedYear = '2025';
$currentYear     = date('Y');
if ($currentYear === $establishedYear) {
    $copyrightRange = $establishedYear;
} else {
    $copyrightRange = $establishedYear . '–' . $currentYear;
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
         ⚠️ TEMPLATE — review by qualified legal counsel before production launch.
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

                        <!-- ============================================================
                             📑 Contents / on-page navigation
                             ============================================================ -->
                        <nav aria-label="Terms of use contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#acceptance">1. Acceptance of terms</a></li>
                                <li><a href="#description">2. Description of the Service</a></li>
                                <li><a href="#eligibility">3. Eligibility &amp; accounts</a></li>
                                <li><a href="#acceptable-use">4. Acceptable use</a></li>
                                <li><a href="#user-content">5. Your content &amp; responsibilities</a></li>
                                <li><a href="#ip">6. Intellectual property &amp; copyright</a></li>
                                <li><a href="#api">7. API usage terms</a></li>
                                <li><a href="#availability">8. Availability, changes &amp; termination</a></li>
                                <li><a href="#warranties">9. Disclaimer of warranties</a></li>
                                <li><a href="#liability">10. Limitation of liability</a></li>
                                <li><a href="#indemnity">11. Indemnification</a></li>
                                <li><a href="#governing-law">12. Governing law &amp; disputes</a></li>
                                <li><a href="#general">13. General provisions</a></li>
                                <li><a href="#changes">14. Changes to these terms</a></li>
                                <li><a href="#contact">15. Contact</a></li>
                            </ul>
                        </nav>

                        <!-- ============================================================
                             1. Acceptance
                             ============================================================ -->
                        <h2 id="acceptance" class="h5 mt-4 mb-3">1. Acceptance of Terms</h2>
                        <p>
                            By accessing or using
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
                            (&ldquo;the Service&rdquo;), operated by
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            (&ldquo;we&rdquo;, &ldquo;us&rdquo;, &ldquo;our&rdquo;), you accept and agree to
                            be bound by these Terms of Use and our Privacy Policy. If you are using the
                            Service on behalf of an organisation, you represent that you have authority to
                            bind that organisation. If you do not agree to these terms, do not use the
                            Service.
                        </p>

                        <!-- ============================================================
                             2. Description of the Service
                             ============================================================ -->
                        <h2 id="description" class="h5 mt-4 mb-3">2. Description of the Service</h2>
                        <p>
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> provides an
                            online QR code generation service that allows users to create customised QR
                            codes for purposes including URLs, text, contact information, Wi-Fi credentials
                            and other supported data formats. The Service includes both a web-based interface
                            and a RESTful API.
                        </p>

                        <!-- ============================================================
                             3. Eligibility & accounts
                             ============================================================ -->
                        <h2 id="eligibility" class="h5 mt-4 mb-3">3. Eligibility &amp; Accounts</h2>
                        <p>
                            You must be of the age of majority in your jurisdiction (or have parental/guardian
                            consent where permitted) to use the Service. Where the Service offers accounts,
                            you are responsible for providing accurate information, for maintaining the
                            confidentiality of your credentials, and for all activity that occurs under your
                            account. Notify us promptly of any unauthorised use.
                        </p>

                        <!-- ============================================================
                             4. Acceptable use
                             ============================================================ -->
                        <h2 id="acceptable-use" class="h5 mt-4 mb-3">4. Acceptable Use</h2>
                        <p>You agree not to use the Service to:</p>
                        <ul>
                            <li>Generate QR codes containing malicious URLs, malware, spyware or phishing content;</li>
                            <li>Create or distribute content that is illegal, defamatory, harassing, obscene, deceptive or that infringes intellectual property, privacy or other rights;</li>
                            <li>Attempt to overwhelm, disrupt, degrade or impair the Service through excessive or automated requests;</li>
                            <li>Circumvent, disable or interfere with any rate limiting, access controls, authentication or security measures;</li>
                            <li>Reverse engineer, decompile or attempt to extract source code except to the extent permitted by law;</li>
                            <li>Resell, sublicense or redistribute the Service without our prior written consent;</li>
                            <li>Upload logos or images you do not have the right to use, or encode personal data of others without a lawful basis; or</li>
                            <li>Use the Service in violation of any applicable law, regulation, or sanction.</li>
                        </ul>
                        <p>
                            We may investigate and take appropriate action, including suspending or
                            terminating access, for any suspected violation.
                        </p>

                        <!-- ============================================================
                             5. Your content & responsibilities
                             ============================================================ -->
                        <h2 id="user-content" class="h5 mt-4 mb-3">5. Your Content &amp; Responsibilities</h2>
                        <p>
                            You retain ownership of the content you submit (such as the data you encode and
                            any logos you upload). You grant us a limited, non-exclusive licence to process
                            that content solely to provide the Service to you. You are solely responsible for
                            your content and for ensuring you have all rights, consents and permissions
                            necessary to submit it and to encode any third-party or personal data it contains.
                        </p>

                        <!-- ============================================================
                             6. Intellectual property & copyright
                             ============================================================ -->
                        <h2 id="ip" class="h5 mt-4 mb-3">6. Intellectual Property &amp; Copyright</h2>
                        <p>
                            QR codes you generate using the Service are yours to use freely, subject to these
                            terms. The Service itself &mdash; including its software, design, layout, text,
                            graphics, logos, trademarks and branding &mdash; is owned by or licensed to
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            and is protected by copyright, trademark and other intellectual-property laws.
                        </p>
                        <p>
                            &copy; <?php echo htmlspecialchars($copyrightRange, ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>.
                            All rights reserved. The
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> name and logo are
                            trademarks of
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>. Except as
                            expressly permitted, you may not copy, modify, distribute, frame, scrape or create
                            derivative works from the Service without our prior written consent.
                        </p>

                        <!-- ============================================================
                             7. API usage
                             ============================================================ -->
                        <h2 id="api" class="h5 mt-4 mb-3">7. API Usage Terms</h2>
                        <p>
                            Use of the API is subject to the following, in addition to the rest of these
                            terms:
                        </p>
                        <ul>
                            <li>API access is subject to the rate limits and quotas published in the API documentation;</li>
                            <li>API keys are issued at our discretion, are personal to you or your organisation, and must be kept confidential;</li>
                            <li>You are responsible for all activity carried out using your API key, including any usage by third parties to whom you grant access;</li>
                            <li>We may suspend or revoke API keys, throttle requests, or change, deprecate or discontinue API endpoints, with or without notice, for security, abuse, non-payment or operational reasons;</li>
                            <li>You must not use the API to build a product that substantially replicates or competes with the Service, or to circumvent usage limits; and</li>
                            <li>Where paid API tiers are offered, the applicable fees, billing and refund terms will be set out at the point of purchase and form part of these terms.</li>
                        </ul>

                        <!-- ============================================================
                             8. Availability, changes & termination
                             ============================================================ -->
                        <h2 id="availability" class="h5 mt-4 mb-3">8. Availability, Changes &amp; Termination</h2>
                        <p>
                            We may modify, suspend or discontinue all or part of the Service at any time, and
                            we may impose limits on certain features. We may suspend or terminate your access
                            if you breach these terms or where required by law. You may stop using the Service
                            at any time. Provisions that by their nature should survive termination
                            (including intellectual property, disclaimers, limitation of liability, indemnity
                            and governing law) will survive.
                        </p>

                        <!-- ============================================================
                             9. Disclaimer of warranties
                             ============================================================ -->
                        <h2 id="warranties" class="h5 mt-4 mb-3">9. Disclaimer of Warranties</h2>
                        <p>
                            The Service is provided &ldquo;as is&rdquo; and &ldquo;as available&rdquo; without
                            warranties of any kind, whether express, implied or statutory, including implied
                            warranties of merchantability, fitness for a particular purpose,
                            non-infringement and any warranties arising from course of dealing or usage of
                            trade. We do not warrant that the Service will be uninterrupted, secure or
                            error-free, or that generated QR codes will be scannable in all environments or by
                            all devices. Some jurisdictions do not allow the exclusion of certain warranties,
                            so some of the above may not apply to you; nothing in these terms limits rights
                            that cannot be limited under applicable consumer-protection law.
                        </p>

                        <!-- ============================================================
                             10. Limitation of liability
                             ============================================================ -->
                        <h2 id="liability" class="h5 mt-4 mb-3">10. Limitation of Liability</h2>
                        <p>
                            To the fullest extent permitted by law,
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            and its officers, employees and agents shall not be liable for any indirect,
                            incidental, special, consequential, exemplary or punitive damages, or for any loss
                            of profits, revenue, data, goodwill or business, arising out of or relating to your
                            use of (or inability to use) the Service, whether based in contract, tort
                            (including negligence) or any other legal theory, even if advised of the
                            possibility of such damages. To the extent we are found liable, our total
                            aggregate liability shall not exceed the greater of the amount you paid us for the
                            Service in the twelve months preceding the claim or
                            <em>[TEMPLATE: insert capped amount]</em>. Nothing in these terms excludes or
                            limits liability that cannot be excluded or limited under applicable law (such as
                            for death or personal injury caused by negligence, or fraud).
                        </p>

                        <!-- ============================================================
                             11. Indemnification
                             ============================================================ -->
                        <h2 id="indemnity" class="h5 mt-4 mb-3">11. Indemnification</h2>
                        <p>
                            You agree to indemnify and hold harmless
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            and its officers, employees and agents from and against any claims, damages,
                            liabilities, costs and expenses (including reasonable legal fees) arising out of or
                            related to your content, your use of the Service, or your breach of these terms or
                            of any law or third-party right.
                        </p>

                        <!-- ============================================================
                             12. Governing law & disputes
                             ============================================================ -->
                        <h2 id="governing-law" class="h5 mt-4 mb-3">12. Governing Law &amp; Disputes</h2>
                        <p>
                            These terms are governed by, and construed in accordance with, the laws of
                            <em>[TEMPLATE: insert governing-law jurisdiction]</em>, without regard to its
                            conflict-of-laws rules. You agree that the courts of
                            <em>[TEMPLATE: insert venue]</em> shall have exclusive jurisdiction over any
                            dispute, except that we may seek injunctive relief in any competent court.
                            Mandatory consumer-protection rights in your country of residence are not affected
                            by this clause.
                        </p>

                        <!-- ============================================================
                             13. General provisions
                             ============================================================ -->
                        <h2 id="general" class="h5 mt-4 mb-3">13. General Provisions</h2>
                        <p>
                            These terms, together with the Privacy Policy and any terms presented at the point
                            of purchase, constitute the entire agreement between you and us regarding the
                            Service. If any provision is found unenforceable, the remaining provisions remain
                            in effect. Our failure to enforce any right is not a waiver. You may not assign
                            these terms without our consent; we may assign them in connection with a merger,
                            acquisition or sale of assets. Headings are for convenience only.
                        </p>

                        <!-- ============================================================
                             14. Changes to terms
                             ============================================================ -->
                        <h2 id="changes" class="h5 mt-4 mb-3">14. Changes to These Terms</h2>
                        <p>
                            We may modify these terms at any time. Changes will be posted on this page with an
                            updated revision date, and where required we will provide additional notice.
                            Continued use of the Service after changes take effect constitutes acceptance of
                            the revised terms.
                        </p>

                        <!-- ============================================================
                             15. Contact
                             ============================================================ -->
                        <h2 id="contact" class="h5 mt-4 mb-3">15. Contact</h2>
                        <p>
                            For questions about these terms, contact us at
                            <a href="mailto:<?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?>
                            </a>.
                            <em>[TEMPLATE: insert registered office address and company registration
                            details.]</em>
                        </p>

                        <p class="text-body-secondary mt-4">
                            &copy; <?php echo htmlspecialchars($copyrightRange, ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>.
                            All rights reserved.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
