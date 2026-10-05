<?php
/**
 * ==============================================================================
 * 🔒 CueRCode - Privacy / Data Protection & Cookies Policy Page
 * ==============================================================================
 *
 * Path: public_html/privacy.php
 *
 * Displays the multi-jurisdiction Privacy, Data Protection & Cookies Policy
 * for the CueRCode platform.
 *
 * ⚠️ ============================================================================
 * ⚠️  TEMPLATE — review by qualified legal counsel before production launch.
 * ⚠️ ----------------------------------------------------------------------------
 * ⚠️  The content below is a comprehensive, good-faith template covering the
 * ⚠️  data-protection regimes named in ProjectBrief §12.2 (EU GDPR; UK GDPR +
 * ⚠️  DPA 2018; USA CCPA/CPRA, VCDPA, CPA, CTDPA, UCPA, HIPAA, COPPA; Canada
 * ⚠️  PIPEDA; Brazil LGPD; Australia Privacy Act 1988 + NDB; New Zealand
 * ⚠️  Privacy Act 2020; India DPDP Act; China PIPL/CSL/DSL; Japan APPI; South
 * ⚠️  Korea PIPA; South Africa POPIA). It is NOT legal advice. Placeholders
 * ⚠️  (DPO contact, representative addresses, retention periods, etc.) MUST be
 * ⚠️  completed and the whole document MUST be reviewed and approved by a
 * ⚠️  qualified lawyer in each relevant jurisdiction before going live.
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

// 📧 Dedicated privacy / data-protection contact address (falls back to the
//    general site email if a privacy-specific mailbox has not been configured).
if (function_exists('getConfig')) {
    $result = getConfig('privacy_email', $siteEmail);
    if (isset($result)) {
        $privacyEmail = $result;
    } else {
        $privacyEmail = $siteEmail;
    }
} else {
    $privacyEmail = $siteEmail;
}

// 📅 Dynamic copyright year for the footer notice on this page.
$currentYear = date('Y');

$pageTitle       = 'Privacy & Data Protection Policy';
$pageDescription = 'Privacy, Data Protection & Cookies Policy for ' . $siteName
    . ' - how we collect, use, protect and share data, and your rights under '
    . 'GDPR, UK GDPR, CCPA/CPRA and other global privacy laws.';
$bodyClass       = 'page-legal page-privacy';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         🔒 Privacy / Data Protection & Cookies Policy Content
         ⚠️ TEMPLATE — review by qualified legal counsel before production launch.
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="h2 fw-bold mb-4">Privacy &amp; Data Protection Policy</h1>
                    <p class="text-body-secondary mb-2">
                        <?php // 📅 Fixed on purpose: change this date ONLY when the wording of this page changes (it used to print today's date) ?>
                        <strong>Last updated:</strong> 4 August 2026
                    </p>
                    <p class="text-body-secondary mb-4">
                        <strong>Applies to:</strong> the
                        <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> website,
                        QR code generator and API (collectively, &ldquo;the Service&rdquo;).
                    </p>

                    <div class="legal-content">

                        <?php /* ============================================================
                             📑 Contents / on-page navigation
                             ============================================================ */ ?>
                        <nav aria-label="Privacy policy contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#intro">1. Who we are &amp; scope</a></li>
                                <li><a href="#data-collected">2. Information we collect</a></li>
                                <li><a href="#purposes">3. Purposes &amp; lawful bases</a></li>
                                <li><a href="#cookies">4. Cookies, localStorage &amp; similar technologies</a></li>
                                <li><a href="#sharing">5. How we share information</a></li>
                                <li><a href="#transfers">6. International data transfers</a></li>
                                <li><a href="#retention">7. Data retention</a></li>
                                <li><a href="#security">8. Data security</a></li>
                                <li><a href="#breach">9. Breach notification</a></li>
                                <li><a href="#children">10. Children&rsquo;s data</a></li>
                                <li><a href="#rights">11. Your rights &amp; how to exercise them</a></li>
                                <li><a href="#regional">12. Region-specific disclosures</a></li>
                                <li><a href="#changes">13. Changes to this policy</a></li>
                                <li><a href="#contact">14. Contact &amp; Data Protection Officer</a></li>
                            </ul>
                        </nav>

                        <?php /* ============================================================
                             1. Introduction & scope
                             ============================================================ */ ?>
                        <h2 id="intro" class="h5 mt-4 mb-3">1. Who We Are &amp; Scope</h2>
                        <p>
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>
                            (&ldquo;we&rdquo;, &ldquo;us&rdquo;, &ldquo;our&rdquo;) operates
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
                            (&ldquo;the Service&rdquo;) and acts as the <em>controller</em> (or, where
                            applicable, the <em>business</em>, <em>personal information handler</em> or
                            <em>responsible party</em>) of the personal data described in this policy.
                            This Privacy, Data Protection &amp; Cookies Policy explains what information we
                            collect, why we collect it, the legal bases on which we rely, how we keep it
                            secure, who we share it with, how long we keep it, and the rights available to
                            you depending on where you live.
                        </p>
                        <p>
                            This policy is global in scope and includes region-specific disclosures
                            (Section&nbsp;12) for the European Union (GDPR), the United Kingdom
                            (UK&nbsp;GDPR and the Data Protection Act&nbsp;2018), the United States
                            (CCPA/CPRA, VCDPA, CPA, CTDPA, UCPA, and, where relevant, HIPAA and COPPA),
                            Canada (PIPEDA), Brazil (LGPD), Australia (Privacy Act&nbsp;1988 and the
                            Notifiable Data Breaches scheme), New Zealand (Privacy Act&nbsp;2020), India
                            (Digital Personal Data Protection Act), China (PIPL, the Cybersecurity Law and
                            the Data Security Law), Japan (APPI), South Korea (PIPA) and South Africa
                            (POPIA). Where a specific regional disclosure conflicts with the general
                            sections of this policy, the regional disclosure controls for residents of that
                            region.
                        </p>

                        <?php /* ============================================================
                             2. Information we collect
                             ============================================================ */ ?>
                        <h2 id="data-collected" class="h5 mt-4 mb-3">2. Information We Collect</h2>

                        <h3 class="h6 mt-3 mb-2">2.1 QR Code Content You Provide</h3>
                        <p>
                            When you generate a QR code, we process the content you supply &mdash; for
                            example URLs, free text, contact details (vCard/MeCard), Wi-Fi credentials,
                            email addresses, phone numbers, calendar events, geo-coordinates and payment
                            references &mdash; in order to encode it into the QR code you requested. Some of
                            this content may itself be personal data, and you are responsible for ensuring
                            you have the right to encode any personal data relating to third parties.
                        </p>

                        <h3 class="h6 mt-3 mb-2">2.2 Technical &amp; Usage Data</h3>
                        <p>We automatically collect limited technical information, including:</p>
                        <ul>
                            <li>IP address (truncated/anonymised for analytics where feasible)</li>
                            <li>Browser type, version and language</li>
                            <li>Device and operating-system information</li>
                            <li>Referring page URL and pages visited within the Service</li>
                            <li>Date, time and duration of visits</li>
                            <li>QR code generation patterns (type, output format, customisation choices)</li>
                            <li>Diagnostic and error-log data needed to operate the Service securely</li>
                        </ul>

                        <h3 class="h6 mt-3 mb-2">2.3 Uploaded Content (Logos &amp; Images)</h3>
                        <p>
                            If you upload a custom logo or image to embed in a QR code, the file is
                            processed server-side and may be stored temporarily for the duration of the
                            generation session. We do not retain uploaded images beyond what is necessary
                            to deliver the requested output, unless you hold an account and have chosen to
                            save them.
                        </p>

                        <h3 class="h6 mt-3 mb-2">2.4 Account &amp; API Data</h3>
                        <p>
                            If you create an account or use the API, we process the credentials and
                            identifiers needed to authenticate you (such as your email address, a hashed
                            password and API keys), together with records of your saved QR codes and your
                            API usage for billing, abuse-prevention and support.
                        </p>

                        <h3 class="h6 mt-3 mb-2">2.5 Sensitive / Special-Category Data</h3>
                        <p>
                            The Service is not designed to collect special-category or sensitive personal
                            data (such as health, biometric, racial or ethnic, political, religious, sexual
                            orientation, or precise geolocation data). Please do not encode such data unless
                            you have a lawful basis and the explicit consent (where required) of the
                            individuals concerned. We do not knowingly act as a HIPAA &ldquo;business
                            associate&rdquo; and the Service should not be used to create, receive, maintain
                            or transmit protected health information (PHI) without a separate written
                            agreement with us (see Section&nbsp;12.3).
                        </p>

                        <?php /* ============================================================
                             3. Purposes & lawful bases
                             ============================================================ */ ?>
                        <h2 id="purposes" class="h5 mt-4 mb-3">3. Purposes &amp; Lawful Bases</h2>
                        <p>We process personal data for the following purposes and, under the GDPR/UK&nbsp;GDPR and similar laws, on the following lawful bases:</p>
                        <ul>
                            <li>
                                <strong>To provide the Service</strong> (generate and deliver QR codes,
                                operate accounts and the API) &mdash; performance of a contract, or our
                                legitimate interest in delivering the functionality you request.
                            </li>
                            <li>
                                <strong>To secure and maintain the Service</strong> (abuse detection,
                                rate-limiting, fraud prevention, debugging) &mdash; legitimate interests and
                                compliance with legal obligations.
                            </li>
                            <li>
                                <strong>To produce anonymised, aggregated analytics</strong> and improve the
                                Service &mdash; legitimate interests; analytics cookies are set only with
                                your consent where consent is required.
                            </li>
                            <li>
                                <strong>To communicate with you</strong> (service notices, support
                                responses) &mdash; performance of a contract or legitimate interests.
                            </li>
                            <li>
                                <strong>To comply with law</strong> and respond to lawful requests &mdash;
                                compliance with a legal obligation.
                            </li>
                            <li>
                                <strong>With your consent</strong> &mdash; for any processing that relies on
                                consent (for example non-essential cookies), which you may withdraw at any
                                time.
                            </li>
                        </ul>

                        <?php /* ============================================================
                             4. Cookies & localStorage
                             ============================================================ */ ?>
                        <h2 id="cookies" class="h5 mt-4 mb-3">4. Cookies, localStorage &amp; Similar Technologies</h2>

                        <h3 class="h6 mt-3 mb-2">4.1 Strictly-Necessary Session Cookie</h3>
                        <p>
                            We use a session cookie (<code>CUERCODE_SID</code>) to maintain your session
                            state, including CSRF protection tokens. This cookie is:
                        </p>
                        <ul>
                            <li>HTTP-only (not accessible to JavaScript)</li>
                            <li>SameSite: Lax (cross-site request-forgery protection)</li>
                            <li>Secure (transmitted only over HTTPS when available)</li>
                            <li>Set to expire when you close your browser or after the session timeout</li>
                        </ul>
                        <p>
                            This cookie is strictly necessary for the Service to function and is exempt from
                            consent requirements under the EU ePrivacy rules and equivalent laws.
                        </p>

                        <h3 class="h6 mt-3 mb-2">4.2 localStorage</h3>
                        <p>
                            We use your browser&rsquo;s <code>localStorage</code> to remember preferences
                            such as your colour theme (dark/light mode). This information stays on your
                            device and is not transmitted to our servers.
                        </p>

                        <h3 class="h6 mt-3 mb-2">4.3 Analytics &amp; Optional Cookies</h3>
                        <p>
                            Where we use analytics or other non-essential cookies, we set them only after
                            you have given consent (in regions where consent is required), and you can change
                            or withdraw your choice at any time via your browser settings or any cookie
                            preference control we provide. We honour recognised opt-out signals such as
                            Global Privacy Control (GPC) where required by law.
                        </p>

                        <?php /* ============================================================
                             5. How we share information
                             ============================================================ */ ?>
                        <h2 id="sharing" class="h5 mt-4 mb-3">5. How We Share Information</h2>
                        <p>
                            We do not sell your personal data, and we do not &ldquo;share&rdquo; it for
                            cross-context behavioural advertising as those terms are defined under the
                            CCPA/CPRA. We may disclose personal data to:
                        </p>
                        <ul>
                            <li>
                                <strong>Service providers / processors</strong> who host, secure and support
                                the Service under contract and act only on our instructions;
                            </li>
                            <li>
                                <strong>Professional advisers</strong> (legal, accounting, security) bound by
                                confidentiality;
                            </li>
                            <li>
                                <strong>Authorities</strong> where required by law, regulation, legal process
                                or to protect our rights, users or the public; and
                            </li>
                            <li>
                                <strong>Successors</strong> in the event of a merger, acquisition or asset
                                sale, subject to this policy.
                            </li>
                        </ul>

                        <?php /* ============================================================
                             6. International transfers
                             ============================================================ */ ?>
                        <h2 id="transfers" class="h5 mt-4 mb-3">6. International Data Transfers</h2>
                        <p>
                            We may process and store data in countries other than your own. Where we
                            transfer personal data across borders, we rely on appropriate safeguards such as
                            the European Commission&rsquo;s Standard Contractual Clauses (SCCs), the UK
                            International Data Transfer Agreement or Addendum, adequacy decisions, or other
                            legally recognised transfer mechanisms applicable to your region (for example the
                            standard contracts and security assessments required under China&rsquo;s PIPL).
                            You may contact us for details of the safeguards in place.
                        </p>

                        <?php /* ============================================================
                             7. Retention
                             ============================================================ */ ?>
                        <h2 id="retention" class="h5 mt-4 mb-3">7. Data Retention</h2>
                        <p>
                            We keep personal data only for as long as necessary for the purposes set out in
                            this policy, after which it is deleted or anonymised:
                        </p>
                        <ul>
                            <li>QR code content submitted by anonymous users is retained only as long as needed to generate and deliver the requested output;</li>
                            <li>Saved QR codes and account data are retained for the life of your account and for a limited period afterwards;</li>
                            <li>Technical and error logs are retained for a limited diagnostic period and then purged;</li>
                            <li>Records we must keep for legal, tax or accounting reasons are retained for the period required by law.</li>
                        </ul>
                        <p>
                            <em>[TEMPLATE: insert specific retention periods per data category, confirmed by counsel.]</em>
                        </p>

                        <h3 id="retention-erasure" class="h6 mt-3 mb-2">7.1 Retention After Account Erasure &mdash; Anonymization, Not Deletion</h3>
                        <p>
                            When you erase your account (Section&nbsp;11.1 below), we remove your personal
                            identifiers immediately &mdash; your email address and display name are replaced
                            with a non-reversible placeholder, your password is cleared so the account can
                            never be signed back into, and your saved QR codes, active sessions, API keys and
                            linked sign-in providers are all revoked or removed. We do <strong>not</strong>,
                            however, delete the underlying account record outright at that moment. Instead it
                            is <strong>anonymized in place</strong>, so that:
                        </p>
                        <ul>
                            <li>
                                subscription and payment records that must be kept for financial, tax, fraud-
                                prevention or accounting reasons remain intact and correctly linked, satisfying
                                our own legal retention obligations, while carrying no personal identifier of
                                yours beyond an anonymous internal reference; and
                            </li>
                            <li>
                                other records that reference your account (for example shared or exported
                                content, or audit/activity logs) do not become broken or orphaned.
                            </li>
                        </ul>
                        <p>
                            The anonymized record itself is permanently and irreversibly removed once the
                            applicable financial/audit retention window has elapsed, via a manual, owner-run
                            purge process &mdash; it is never re-identified, re-activated or restored in the
                            meantime. <em>[TEMPLATE: insert the confirmed retention window in days/years,
                            per counsel and applicable financial-record-keeping law.]</em>
                        </p>

                        <?php /* ============================================================
                             8. Security
                             ============================================================ */ ?>
                        <h2 id="security" class="h5 mt-4 mb-3">8. Data Security</h2>
                        <p>We implement appropriate technical and organisational measures, including:</p>
                        <ul>
                            <li>AES-256-GCM encryption for sensitive configuration data</li>
                            <li>CSRF token protection for all state-changing requests</li>
                            <li>Prepared statements for all database operations (SQL-injection prevention)</li>
                            <li>Input validation and output escaping (cross-site-scripting prevention)</li>
                            <li>Secure session configuration with HTTP-only cookies and HTTPS</li>
                            <li>Access controls, logging and the principle of least privilege</li>
                        </ul>
                        <p>
                            No method of transmission or storage is completely secure; while we strive to
                            protect your data, we cannot guarantee absolute security.
                        </p>

                        <?php /* ============================================================
                             9. Breach notification
                             ============================================================ */ ?>
                        <h2 id="breach" class="h5 mt-4 mb-3">9. Breach Notification</h2>
                        <p>
                            In the event of a personal data breach that is likely to result in a risk to
                            your rights and freedoms, we will notify the relevant supervisory authority and,
                            where required, affected individuals, within the timeframes mandated by
                            applicable law. These include, for example, 72&nbsp;hours to the supervisory
                            authority under the EU/UK&nbsp;GDPR; notification &ldquo;as soon as
                            practicable&rdquo; to the OAIC and affected individuals under Australia&rsquo;s
                            Notifiable Data Breaches scheme; and the specific timelines under LGPD, PIPEDA,
                            POPIA, PIPA, the DPDP Act, PIPL and the various US state laws (which may include
                            notification to state attorneys-general).
                        </p>

                        <?php /* ============================================================
                             10. Children's data
                             ============================================================ */ ?>
                        <h2 id="children" class="h5 mt-4 mb-3">10. Children&rsquo;s Data</h2>
                        <p>
                            The Service is intended for general audiences and is not directed at children. We
                            do not knowingly collect personal data from children below the age of digital
                            consent applicable in their jurisdiction (for example under&nbsp;13 under the US
                            COPPA; between&nbsp;13 and&nbsp;16 under the EU/UK&nbsp;GDPR depending on member
                            state; under&nbsp;18 under India&rsquo;s DPDP Act and under&nbsp;14 under
                            China&rsquo;s PIPL, each of which require verifiable parental consent). If you
                            believe a child has provided us with personal data, please contact us and we will
                            take steps to delete it.
                        </p>

                        <?php /* ============================================================
                             11. Your rights
                             ============================================================ */ ?>
                        <h2 id="rights" class="h5 mt-4 mb-3">11. Your Rights &amp; How to Exercise Them</h2>
                        <p>
                            Depending on where you live, you may have some or all of the following rights in
                            relation to your personal data:
                        </p>
                        <ul>
                            <li><strong>Access</strong> &mdash; obtain confirmation of, and a copy of, the data we hold about you;</li>
                            <li><strong>Rectification / correction</strong> &mdash; have inaccurate or incomplete data corrected;</li>
                            <li><strong>Erasure / deletion</strong> &mdash; have your data deleted (the &ldquo;right to be forgotten&rdquo;);</li>
                            <li><strong>Restriction</strong> &mdash; limit how we process your data in certain circumstances;</li>
                            <li><strong>Objection</strong> &mdash; object to processing based on legitimate interests or to direct marketing;</li>
                            <li><strong>Portability</strong> &mdash; receive your data in a structured, machine-readable format and have it transmitted to another controller;</li>
                            <li><strong>Withdraw consent</strong> &mdash; where processing is based on consent, withdraw it at any time without affecting prior processing;</li>
                            <li><strong>Opt out of sale/sharing &amp; targeted advertising</strong> &mdash; where applicable under US state laws;</li>
                            <li><strong>Non-discrimination</strong> &mdash; not be discriminated against for exercising your rights;</li>
                            <li><strong>Not be subject to solely automated decisions</strong> with legal or similarly significant effects;</li>
                            <li><strong>Lodge a complaint</strong> with a supervisory or regulatory authority, and to nominate (in some regions) an authorised agent or, after death, have a designated person exercise rights on your behalf.</li>
                        </ul>
                        <p>
                            To exercise any of these rights, contact us using the details in
                            Section&nbsp;14. We will verify your identity before responding and will reply
                            within the timeframe required by the law applicable to you (for example one month
                            under the GDPR/UK&nbsp;GDPR, extendable where permitted; 45&nbsp;days under the
                            CCPA/CPRA, extendable by a further 45&nbsp;days). These rights are free to
                            exercise in most cases, subject to limited exceptions for manifestly unfounded or
                            excessive requests.
                        </p>

                        <h3 id="rights-selfservice" class="h6 mt-3 mb-2">11.1 Self-Service Access, Export &amp; Erasure</h3>
                        <p>
                            If you have a CueRCode account, you do not need to email us to access, export or
                            erase your own account data &mdash; two self-service tools are built directly into
                            your account dashboard (<a href="/account/#data-section">Account&nbsp;&rarr;
                            Your&nbsp;data</a>):
                        </p>
                        <ul>
                            <li>
                                <strong>Download my data</strong> (access &amp; portability) &mdash; produces
                                an immediate, complete, machine-readable (JSON) copy of your profile, saved QR
                                codes (including their content), active sessions, subscription and payment
                                history, linked sign-in providers, and API keys. Secrets such as your password
                                hash, session tokens, SSO access/refresh tokens, and API key secrets are never
                                included. Available to API/developer callers as
                                <code>GET /api/v1/account/export</code> (see our
                                <a href="/api/docs/">API documentation</a>).
                            </li>
                            <li>
                                <strong>Delete my account</strong> (erasure) &mdash; requires re-entering your
                                current password and typing a literal confirmation phrase, because it is
                                irreversible. On confirmation we immediately anonymize your email address and
                                display name, permanently clear your password, sign you out of every device,
                                deactivate every API key, unlink every SSO sign-in provider, and remove every
                                saved QR code from your library &mdash; see Section&nbsp;7.1 above for what is
                                retained (anonymized) afterwards and why. Available to API/developer callers
                                as <code>POST /api/v1/account/erase</code> (requires your current password and
                                an explicit confirmation flag in the request).
                            </li>
                        </ul>
                        <p>
                            If you do not have an account, or you would prefer we action a request on your
                            behalf (for example correction, restriction, or objection), contact us using the
                            details in Section&nbsp;14 instead.
                        </p>

                        <?php /* ============================================================
                             12. Region-specific disclosures
                             ============================================================ */ ?>
                        <h2 id="regional" class="h5 mt-4 mb-3">12. Region-Specific Disclosures</h2>

                        <h3 class="h6 mt-3 mb-2">12.1 European Union &mdash; GDPR</h3>
                        <p>
                            If you are in the EU/EEA, we process your data in accordance with the General
                            Data Protection Regulation (Regulation&nbsp;(EU)&nbsp;2016/679). You have the
                            rights set out in Section&nbsp;11 and the right to lodge a complaint with your
                            local Data Protection Authority. Our lawful bases are described in
                            Section&nbsp;3. Where required, our EU representative under Article&nbsp;27 is:
                            <em>[TEMPLATE: insert EU representative name and address.]</em>
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.2 United Kingdom &mdash; UK GDPR &amp; DPA 2018</h3>
                        <p>
                            If you are in the UK, we process your data under the UK&nbsp;GDPR and the Data
                            Protection Act&nbsp;2018. You have the rights set out in Section&nbsp;11 and the
                            right to complain to the Information Commissioner&rsquo;s Office (ICO) at
                            <a href="https://ico.org.uk/" rel="noopener noreferrer" target="_blank">ico.org.uk</a>.
                            Our UK representative under Article&nbsp;27 (where required) is:
                            <em>[TEMPLATE: insert UK representative name and address.]</em>
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.3 United States</h3>
                        <p>
                            <strong>California (CCPA/CPRA):</strong> California residents have the right to
                            know the categories and specific pieces of personal information we collect, the
                            purposes for collection, and the categories of third parties to whom we disclose
                            it; to request deletion and correction; to opt out of the &ldquo;sale&rdquo; or
                            &ldquo;sharing&rdquo; of personal information and limit use of sensitive personal
                            information; and to be free from discrimination for exercising these rights. We
                            do not sell or share personal information as those terms are defined under the
                            CCPA/CPRA, and we honour Global Privacy Control signals.
                        </p>
                        <p>
                            <strong>Virginia (VCDPA), Colorado (CPA), Connecticut (CTDPA) and Utah (UCPA):</strong>
                            residents of these states have rights to access, correct, delete, and obtain a
                            portable copy of their personal data, and to opt out of targeted advertising, the
                            sale of personal data and certain profiling. Where these states provide an appeal
                            mechanism for refused requests, we will honour it.
                        </p>
                        <p>
                            <strong>HIPAA:</strong> the Service is not a covered entity or, absent a separate
                            written Business Associate Agreement, a business associate, and should not be used
                            to process protected health information (PHI). Do not encode PHI into QR codes
                            using the Service without first entering into an appropriate agreement with us.
                        </p>
                        <p>
                            <strong>COPPA:</strong> the Service is not directed to children under 13 and we do
                            not knowingly collect personal information from them. See Section&nbsp;10.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.4 Canada &mdash; PIPEDA</h3>
                        <p>
                            If you are in Canada, we handle personal information in accordance with the
                            Personal Information Protection and Electronic Documents Act (PIPEDA) and
                            applicable provincial laws. You may access and correct your information and
                            complain to the Office of the Privacy Commissioner of Canada. We obtain consent
                            for collection, use and disclosure as required and limit collection to what is
                            necessary.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.5 Brazil &mdash; LGPD</h3>
                        <p>
                            If you are in Brazil, we process personal data under the Lei Geral de Prote&ccedil;&atilde;o
                            de Dados (LGPD). You have rights to confirmation, access, correction,
                            anonymisation, portability, deletion, information about sharing and the right to
                            withdraw consent, and may complain to the Autoridade Nacional de Prote&ccedil;&atilde;o
                            de Dados (ANPD).
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.6 Australia &mdash; Privacy Act 1988 &amp; NDB</h3>
                        <p>
                            If you are in Australia, we handle personal information in accordance with the
                            Australian Privacy Principles under the Privacy Act&nbsp;1988 and comply with the
                            Notifiable Data Breaches (NDB) scheme. You may access and correct your information
                            and complain to the Office of the Australian Information Commissioner (OAIC).
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.7 New Zealand &mdash; Privacy Act 2020</h3>
                        <p>
                            If you are in New Zealand, we comply with the Information Privacy Principles under
                            the Privacy Act&nbsp;2020, including the notifiable-privacy-breach requirements.
                            You may access and correct your information and complain to the Office of the
                            Privacy Commissioner.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.8 India &mdash; DPDP Act</h3>
                        <p>
                            If you are in India, we process digital personal data in accordance with the
                            Digital Personal Data Protection Act. You have the right to access, correction,
                            erasure and grievance redressal, and we obtain verifiable consent (including
                            verifiable parental consent for children) as required.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.9 China &mdash; PIPL, CSL &amp; DSL</h3>
                        <p>
                            If you are in mainland China, we process personal information in accordance with
                            the Personal Information Protection Law (PIPL), the Cybersecurity Law (CSL) and
                            the Data Security Law (DSL). We obtain separate consent where required, provide
                            mechanisms to access, correct, delete and port your information, and apply the
                            required safeguards (such as standard contracts or security assessments) before
                            any cross-border transfer of personal information out of China.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.10 Japan &mdash; APPI</h3>
                        <p>
                            If you are in Japan, we handle personal information in accordance with the Act on
                            the Protection of Personal Information (APPI), including its rules on the use,
                            disclosure and cross-border transfer of personal information and your rights to
                            request disclosure, correction and cessation of use.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.11 South Korea &mdash; PIPA</h3>
                        <p>
                            If you are in South Korea, we comply with the Personal Information Protection Act
                            (PIPA). You have rights to access, correct, delete and suspend processing of your
                            personal information, and we obtain consent and provide the disclosures required
                            by PIPA, including for cross-border transfers.
                        </p>

                        <h3 class="h6 mt-3 mb-2">12.12 South Africa &mdash; POPIA</h3>
                        <p>
                            If you are in South Africa, we process personal information as a responsible
                            party under the Protection of Personal Information Act (POPIA). You have rights to
                            access, correct and delete your information and to object to processing, and may
                            complain to the Information Regulator.
                        </p>

                        <?php /* ============================================================
                             13. Changes
                             ============================================================ */ ?>
                        <h2 id="changes" class="h5 mt-4 mb-3">13. Changes to This Policy</h2>
                        <p>
                            We may update this policy from time to time. Material changes will be highlighted
                            on this page with an updated revision date, and where required by law we will seek
                            your consent or provide advance notice.
                        </p>

                        <?php /* ============================================================
                             14. Contact & DPO
                             ============================================================ */ ?>
                        <h2 id="contact" class="h5 mt-4 mb-3">14. Contact &amp; Data Protection Officer</h2>
                        <p>
                            For privacy enquiries or to exercise your rights, contact our Data Protection
                            Officer / privacy team at
                            <a href="mailto:<?php echo htmlspecialchars($privacyEmail, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($privacyEmail, ENT_QUOTES, 'UTF-8'); ?>
                            </a>.
                        </p>
                        <p>
                            <strong>Controller:</strong>
                            <?php echo htmlspecialchars($copyrightHolder, ENT_QUOTES, 'UTF-8'); ?>.
                            <em>[TEMPLATE: insert registered office address, company number, and the
                            postal/contact details and identity of the Data Protection Officer and any
                            regional representatives.]</em>
                        </p>

                        <p class="text-body-secondary mt-4">
                            &copy; <?php echo htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8'); ?>
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
