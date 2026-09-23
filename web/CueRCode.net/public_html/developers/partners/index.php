<?php
/**
 * ==============================================================================
 * 🤝 CueRCode - Developer Portal: Partner Guide
 * ==============================================================================
 *
 * Path: public_html/developers/partners/index.php
 *
 * Status page for the two named partner/integration contracts that are being
 * finalised upstream in sibling repositories: Go2My.Link (dynamic short-link
 * backend for QR codes) and SIGNula.id (suite-wide SSO identity provider).
 * Neither integration is implemented in CueRCode today - this page exists so
 * partners/integrators have one authoritative place to check status, rather
 * than the status living only in internal planning documents. It will be
 * replaced with the actual integration contract documentation (auth flow,
 * endpoints, field mappings) once each upstream issue is resolved and frozen.
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

$pageTitle       = 'Partner Guide';
$pageDescription = 'Status of the Go2My.Link and SIGNula partner integration contracts for the '
    . $siteName . ' API.';
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
                        <i class="fa-solid fa-handshake me-2" aria-hidden="true"></i>
                        Partner Guide
                    </h1>
                    <p class="text-body-secondary mb-4">
                        <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> is designed to
                        interoperate with two sibling MWBM Partners Ltd products. Both integration
                        contracts are being finalised upstream, in THEIR repositories, not this one -
                        this page will be replaced with the real integration documentation (auth flow,
                        endpoints, field mappings, code samples) once each is frozen.
                    </p>

                    <div class="legal-content">

                        <!-- ============================================================
                             Go2My.Link
                             ============================================================ -->
                        <h2 class="h5 mt-4 mb-3">Go2My.Link - Dynamic Short-Link Backend</h2>
                        <p>
                            Go2My.Link is planned as the backend for <strong>dynamic QR codes</strong> -
                            codes whose printed image never changes while the destination stays editable.
                            Their repository already has CueRCode-aware schema hooks in place
                            (<code>tblShortURLs.createdVia = 'cuercode'</code>,
                            <code>qrCodeExternalUUID</code>/<code>qrCodeExternalID</code> columns, a
                            <code>scanSource</code> attribution field, and pre-seeded
                            <code>cuercode.*</code> integration settings), but their PUBLIC REST API for
                            creating/updating short links server-to-server is still design-only.
                        </p>
                        <div class="alert alert-warning" role="note">
                            <p class="mb-1">
                                <strong>Status: blocked upstream.</strong> Tracked as
                                <a href="https://github.com/MWBMPartners/Go2My.Link/issues/137" class="alert-link" rel="noopener noreferrer">
                                    Go2My.Link&nbsp;#137 - "CueRCode integration - public REST API contract
                                    needed for QR-backed dynamic short links"</a>.
                            </p>
                            <p class="mb-0">
                                CueRCode's own in-house redirect foundation (Epic E1a, Stage S0/S1, #82) is
                                already BUILT - the database tables, destination-check rules, and an
                                owner-only destination API all exist - but it is switched OFF, pending a
                                separate owner approval gate (G-007) for the abuse-surface implications of
                                hosting redirects. Once their API contract (endpoints, <code>X-API-Key</code>
                                auth scopes, request/response shapes) is frozen, CueRCode will add a
                                Go2My.Link-backed driver (Epic E1b) as an alternative backend. See the
                                stubbed <a href="/developers/recipes/#dynamic">"Dynamic QR via API"</a>
                                recipe.
                            </p>
                        </div>

                        <!-- ============================================================
                             SIGNula.id
                             ============================================================ -->
                        <h2 class="h5 mt-4 mb-3">SIGNula.id - Suite-Wide SSO</h2>
                        <p>
                            SIGNula.id is planned to provide "Sign in with SIGNula" across the MWBM Partners
                            product suite, including <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>.
                            Their platform has a real, documented REST API today, but it is a
                            <strong>credential-proxy</strong> (opaque bearer tokens via
                            <code>POST /api/v1/auth/login</code>) rather than a standards-based OIDC
                            identity provider - there is no <code>/authorize</code>, <code>/token</code>,
                            discovery document, or JWKS endpoint yet, so a standard browser SSO integration
                            cannot be built against it as-is.
                        </p>
                        <div class="alert alert-warning" role="note">
                            <p class="mb-1">
                                <strong>Status: blocked upstream.</strong> Tracked as
                                <a href="https://github.com/MWBMPartners/SIGNula.id/issues/88" class="alert-link" rel="noopener noreferrer">
                                    SIGNula.id&nbsp;#88 - "IdP capability request - OIDC authorization-code+PKCE
                                    surface for suite SSO (CueRCode + future apps)"</a>.
                            </p>
                            <p class="mb-0">
                                <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> local accounts
                                now exist, and standard Microsoft 365 and Google OIDC sign-in are now BUILT
                                (Epic E2, #55/#19/#20) - both are switched OFF until the owner registers the
                                respective apps and provides the credentials. A SIGNula provider will slot in
                                behind a feature flag once their IdP surface (or an agreed lighter
                                hosted-login redirect) lands. Every SSO identity - whichever provider - links
                                to a local CueRCode account.
                            </p>
                        </div>

                        <p class="mt-4 mb-0 small text-body-secondary">
                            Building something else against the CueRCode API and want to coordinate an
                            integration contract of your own? See
                            <a href="/developers/authentication/">Authentication</a> for how API keys work
                            today, and reach out with your use case.
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
