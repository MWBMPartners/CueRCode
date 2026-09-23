<?php
/**
 * ==============================================================================
 * ⚡ CueRCode - Developer Portal: Quickstart Guide
 * ==============================================================================
 *
 * Path: public_html/developers/quickstart/index.php
 *
 * Gets a brand-new integrator from zero to a real, downloaded QR code PNG in
 * under a minute using nothing but curl and no account/API key - then repeats
 * the same task in Python. Explains why the very fastest path is anonymous
 * `/bulk-generate` (the only generation endpoint that allows anonymous
 * access) rather than `/generate` (which always requires an API key or a
 * session CSRF token - see the Authentication guide).
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         public_html/api/v1/openapi.json - /bulk-generate and /generate contracts
 * @see         web/CueRCode.net/_functions/fn_api_bulk.php - handleBulkGenerate()
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

$pageTitle       = 'Quickstart';
$pageDescription = 'Generate a real QR code PNG from the ' . $siteName . ' API in under a minute using '
    . 'only curl - no account or API key required.';
$bodyClass       = 'page-developers page-developers-quickstart';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'quickstart';

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
                        <i class="fa-solid fa-bolt me-2" aria-hidden="true"></i>
                        Quickstart
                    </h1>
                    <p class="text-body-secondary mb-4">
                        No signup, no API key - just curl. This generates a real QR code image and saves
                        it to disk in one command.
                    </p>

                    <div class="legal-content">

                        <!-- ============================================================
                             1. The fastest anonymous path
                             ============================================================ -->
                        <h2 class="h5 mt-4 mb-3">1. Generate a PNG anonymously (curl)</h2>
                        <p>
                            <code>POST /api/v1/generate</code> (the "single code" endpoint) always requires
                            authentication - see <a href="/developers/authentication/">Authentication</a>.
                            The one generation endpoint that <strong>does</strong> allow fully anonymous
                            access is <code>POST /api/v1/bulk-generate</code> - it accepts a list of one (or
                            many) QR code requests and always returns a ZIP archive, even for a single item.
                            That makes it the genuinely fastest zero-setup path to a real PNG:
                        </p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo htmlspecialchars($siteURL, ENT_QUOTES, 'UTF-8'); ?>/api/v1/bulk-generate \
  -H "Content-Type: application/json" \
  -d '{
        "items": [
          { "type": "url", "input": { "url": "https://example.com" } }
        ]
      }' \
  -o qr.zip

unzip -o qr.zip -d qr-output
open qr-output/001-url.png   # macOS; use "xdg-open" on Linux, "start" on Windows</code></pre>
                        <p>
                            The ZIP always contains a <code>manifest.txt</code> plus one image per item,
                            named <code>NNN-&lt;type&gt;.&lt;ext&gt;</code> - for a single <code>url</code>
                            item at the default PNG format, that is <code>001-url.png</code>.
                        </p>

                        <!-- ============================================================
                             2. Same thing in Python
                             ============================================================ -->
                        <h2 class="h5 mt-4 mb-3">2. The same request in Python</h2>
                        <pre class="bg-body-tertiary p-3 rounded"><code>import io
import zipfile
import requests

response = requests.post(
    "<?php echo htmlspecialchars($siteURL, ENT_QUOTES, 'UTF-8'); ?>/api/v1/bulk-generate",
    json={"items": [{"type": "url", "input": {"url": "https://example.com"}}]},
    timeout=10,
)
response.raise_for_status()

with zipfile.ZipFile(io.BytesIO(response.content)) as archive:
    archive.extractall("qr-output")

print("Saved qr-output/001-url.png")</code></pre>

                        <!-- ============================================================
                             3. Anonymous vs API key
                             ============================================================ -->
                        <h2 class="h5 mt-4 mb-3">3. Anonymous vs. API key - what changes</h2>
                        <p>
                            The request above ran with <strong>zero credentials</strong>. That works because
                            <code>/bulk-generate</code>, <code>/types</code>, <code>/formats</code>, and
                            <code>/health</code> are the only endpoints that permit anonymous access - and
                            even then, anonymous requests are rate limited more strictly (by IP address) than
                            authenticated ones. Everything else
                            (<code>/generate</code>, <code>/preview</code>, <code>/download/{uuid}</code>)
                            requires either:
                        </p>
                        <ul>
                            <li>an <code>X-API-Key</code> header (server-to-server integrations), or</li>
                            <li>an <code>X-CSRF-Token</code> header tied to a logged-in browser session (how
                                the <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> web UI's
                                own AJAX calls authenticate).</li>
                        </ul>
                        <p>
                            Once you have an API key, a single-code request becomes simpler - one HTTP call,
                            one JSON response with a base64 image and a download URL, no ZIP/unzip step:
                        </p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo htmlspecialchars($siteURL, ENT_QUOTES, 'UTF-8'); ?>/api/v1/generate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{"type": "url", "input": {"url": "https://example.com"}}'</code></pre>
                        <p class="mb-0">
                            See <a href="/developers/authentication/">Authentication</a> for the full
                            picture (including how to create your own key from your account in under a
                            minute), and <a href="/developers/samples/">Code Samples</a> for the equivalent
                            PHP, JavaScript, and Python calls to every core endpoint.
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
