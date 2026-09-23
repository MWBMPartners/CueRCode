<?php
/**
 * ==============================================================================
 * 🔑 CueRCode - Developer Portal: Authentication Guide
 * ==============================================================================
 *
 * Path: public_html/developers/authentication/index.php
 *
 * Explains the two supported authentication modes (X-API-Key header for
 * server-to-server integrations; session CSRF token for the web UI's own
 * AJAX calls), the anonymous fallback and its limits, and how self-service
 * API keys work now that accounts and key management have shipped (Epic
 * E7 stage 1, #54) - a signed-in user creates, names, scopes, rotates, and
 * revokes their own keys from the account dashboard, no team involvement
 * needed.
 *
 * Content here is narrative/explanatory by design; the exact per-endpoint
 * auth requirement is also rendered LIVE from openapi.json on the
 * /developers/reference/ page, so this guide and that page can never
 * silently disagree about which endpoints need what.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       Phase 2 (E6 developer portal cycle, #50); self-service key
 *              management section rewritten for Epic E7 stage 1, #54
 * @see         web/CueRCode.net/_functions/fn_api_auth.php - authenticateAPIRequest(), validateAPIKey()
 * @see         PROJECT_ROADMAP.md - Epic E7 (Self-Service API Keys) and Epic E6 (this portal)
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

$pageTitle       = 'Authentication';
$pageDescription = 'The two ' . $siteName . ' API authentication modes (API key and session CSRF token), '
    . 'the anonymous fallback and its limits, and how self-service API keys will work.';
$bodyClass       = 'page-developers page-developers-authentication';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'authentication';

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
                        <i class="fa-solid fa-key me-2" aria-hidden="true"></i>
                        Authentication
                    </h1>
                    <p class="text-body-secondary mb-4">
                        Every protected endpoint accepts EITHER of two credential types - there is no
                        "primary" mode, both are first-class.
                    </p>

                    <div class="legal-content">

                        <nav aria-label="Guide contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#modes">1. The two authentication modes</a></li>
                                <li><a href="#anonymous">2. Anonymous access and its limits</a></li>
                                <li><a href="#getting-a-key">3. Getting an API key</a></li>
                                <li><a href="#self-service">4. Managing your keys (rotate / revoke / scopes)</a></li>
                                <li><a href="#which-mode">5. Which mode should I use?</a></li>
                            </ul>
                        </nav>

                        <!-- ============================================================
                             1. The two modes
                             ============================================================ -->
                        <h2 id="modes" class="h5 mt-4 mb-3">1. The Two Authentication Modes</h2>

                        <h3 class="h6 mt-3">Mode A - API key (<code>X-API-Key</code> header)</h3>
                        <p>
                            For server-to-server integrations: partner backends, scripts, native/mobile
                            apps, CI pipelines. Send your key in a request header on every call:
                        </p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>X-API-Key: cuercode_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2</code></pre>
                        <p>
                            Format: the literal prefix <code>cuercode_</code> followed by exactly 40
                            lowercase hexadecimal characters (49 characters total). The server never stores
                            your raw key - only an HMAC-SHA256 hash of it - so a leaked database backup does
                            not expose usable keys, and a lost key cannot be recovered (only reissued).
                            An invalid or inactive key is rejected with <code>401 INVALID_API_KEY</code>
                            immediately - it does NOT silently fall back to anonymous access.
                        </p>
                        <p>
                            Each key carries its own per-minute and per-day rate limits (independent of the
                            anonymous/CSRF defaults - see <a href="/developers/errors/">Errors &amp; Rate
                            Limits</a>), and every successful use updates a <code>lastUsedAt</code> timestamp
                            for the key.
                        </p>

                        <h3 class="h6 mt-3">Mode B - Session CSRF token (<code>X-CSRF-Token</code> header)</h3>
                        <p>
                            For the <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> web UI's
                            own same-origin AJAX calls (the generator page's live preview and download
                            actions). A per-session CSRF token is embedded in the page as a
                            <code>&lt;meta name="csrf-token"&gt;</code> tag; the front-end JavaScript reads it
                            and sends it back on every API call it makes. It can also be sent as a
                            <code>csrf_token</code> POST field for non-AJAX form fallbacks.
                        </p>
                        <p class="mb-0">
                            This mode is tied to a live, same-origin browser session - it is <strong>not</strong>
                            a substitute for an API key in a third-party integration (there is no session to
                            attach it to outside a browser that has loaded a
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> page).
                        </p>

                        <!-- ============================================================
                             2. Anonymous access
                             ============================================================ -->
                        <h2 id="anonymous" class="h5 mt-4 mb-3">2. Anonymous Access and Its Limits</h2>
                        <p>
                            Four endpoints work with <strong>no credentials at all</strong>:
                            <code>POST /bulk-generate</code>, <code>GET /types</code>,
                            <code>GET /formats</code>, and <code>GET /health</code>. This is deliberate - it
                            lets the <a href="/developers/quickstart/">Quickstart</a> guide get you to a real
                            generated PNG with zero setup, and lets tooling introspect the available QR types
                            and output formats without a key.
                        </p>
                        <p>
                            <code>POST /generate</code>, <code>POST /preview</code>,
                            <code>POST /barcode</code>, <code>POST /barcode/preview</code>, and
                            <code>GET /download/{uuid}</code> are NEVER anonymous - one of the two modes
                            above is always required, returning <code>401 AUTH_REQUIRED</code> otherwise.
                            The same is true of the saved-codes routes -
                            <code>POST /save</code>, <code>GET /qrcodes</code>,
                            <code>GET/PATCH/DELETE /qrcodes/{uuid}</code>, and
                            <code>GET/PATCH /qrcodes/{uuid}/destination</code>. The routes that act on a single
                            saved code (<code>/qrcodes/{uuid}</code> and its <code>/destination</code>) also
                            re-check that the caller owns that code, and the list only ever shows the
                            caller's own codes.
                        </p>
                        <p class="mb-0">
                            Anonymous requests are rate limited by IP address, more strictly than
                            authenticated requests (defaults: 10 requests/minute and 100 requests/day per IP,
                            configurable server-side). See the live per-endpoint auth requirement on the
                            <a href="/developers/reference/">Endpoint Reference</a> page, and the full rate
                            limit picture on <a href="/developers/errors/">Errors &amp; Rate Limits</a>.
                        </p>

                        <!-- ============================================================
                             3. Getting a key
                             ============================================================ -->
                        <h2 id="getting-a-key" class="h5 mt-4 mb-3">3. Getting an API Key</h2>
                        <p>
                            API keys are self-service - there is no waiting on the
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> team. Follow these
                            steps:
                        </p>
                        <ol class="mb-0">
                            <li>Register a free account, or sign in if you already have one.</li>
                            <li>Open the API keys section of your account dashboard
                                (<code>/account/#api-keys-section</code>).</li>
                            <li>Give the new key a name so you can recognise it later (e.g. "Staging
                                server").</li>
                            <li>Choose which scopes it needs (see Section 4 below) - grant only what the
                                integration actually uses.</li>
                            <li>Copy the key shown on screen straight away. It is displayed
                                <strong>exactly once</strong>; after that, only its prefix is visible, and a
                                lost key cannot be recovered - only rotated for a new one.</li>
                        </ol>
                        <p class="mb-0 mt-3">
                            In the meantime, the <a href="/developers/quickstart/">Quickstart</a> guide's
                            anonymous <code>/bulk-generate</code> path works for evaluation and
                            light/experimental use without a key at all.
                        </p>

                        <!-- ============================================================
                             4. Managing your keys
                             ============================================================ -->
                        <h2 id="self-service" class="h5 mt-4 mb-3">4. Managing Your Keys (Rotate / Revoke / Scopes)</h2>
                        <p>
                            The account dashboard's API keys section lists every key you own (name, prefix,
                            scopes, rate limits, last-used timestamp) - never the secret itself, only its
                            HMAC-SHA256 hash is stored server-side. From there you can:
                        </p>
                        <ul>
                            <li><strong>Rotate</strong> a key to issue it a brand-new secret. The old secret
                                stops working <strong>immediately</strong>, so update any integration using it
                                before rotating.</li>
                            <li><strong>Revoke</strong> a key you no longer need. This is a soft-revoke - the
                                key stops authenticating straight away.</li>
                        </ul>
                        <p>
                            Each key is granted one or more scopes from a fixed allowlist:
                            <code>generate</code>, <code>preview</code>, <code>download</code>,
                            <code>bulk</code>, <code>qrcodes:read</code>, <code>qrcodes:write</code>, and
                            <code>save</code>. A request made with a key that lacks the scope an endpoint
                            needs is rejected with <code>403 INSUFFICIENT_SCOPE</code>, naming the missing
                            scope. Scope checks only apply to <code>X-API-Key</code> requests - a signed-in
                            session (Mode B) is never scope-limited.
                        </p>
                        <p>
                            For safety, a key can never manage other keys - any <code>/keys*</code> call made
                            with an <code>X-API-Key</code> header is refused with
                            <code>403 API_KEY_MANAGEMENT_FORBIDDEN</code>, even if that key holds every
                            scope. Key creation, listing, rotation, and revocation are session/CSRF only
                            (the account dashboard, or an equivalent signed-in AJAX call).
                        </p>
                        <p class="mb-0">
                            Each plan also caps how many keys an account may hold at once (seeded caps:
                            free 1, pro 5, business 20). Creating another key once you are at the cap
                            returns <code>402 API_KEY_LIMIT_REACHED</code> - rotate or revoke an existing
                            key, or upgrade your plan, to make room.
                        </p>

                        <!-- ============================================================
                             5. Which mode?
                             ============================================================ -->
                        <h2 id="which-mode" class="h5 mt-4 mb-3">5. Which Mode Should I Use?</h2>
                        <ul class="mb-0">
                            <li><strong>Building a server-side integration, script, or app?</strong> Use an
                                API key (Mode A).</li>
                            <li><strong>Embedding the generator inside your own web page via an
                                <code>&lt;iframe&gt;</code> to <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>,
                                or just evaluating the API quickly?</strong> Start with anonymous
                                <code>/bulk-generate</code> (Section 2), then move to an API key once you
                                need <code>/generate</code>, <code>/preview</code>, or higher rate limits.</li>
                            <li><strong>Contributing to the <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
                                web UI itself?</strong> You're already using Mode B (session CSRF) - see
                                <code>_functions/fn_csrf.php</code> and <code>_includes/header.php</code>'s
                                <code>csrfMeta()</code> call in the source tree.</li>
                        </ul>

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
