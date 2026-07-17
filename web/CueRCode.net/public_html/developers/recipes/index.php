<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Developer Portal: Integration Recipes
 * ==============================================================================
 *
 * Path: public_html/developers/recipes/index.php
 *
 * Task-oriented "how do I..." recipes that combine the endpoints documented
 * elsewhere on the portal into complete workflows: embedding a QR code on a
 * website/CMS, bulk-generating from a spreadsheet (CSV -> ZIP), and generating
 * an accessible QR code via the API (preset=accessible). A "dynamic QR via
 * API" recipe is stubbed as coming soon, pending the in-house redirect layer
 * (Epic E1a) and the Go2My.Link integration (#137).
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         PROJECT_ROADMAP.md - Epic E1 (Dynamic QR codes), Epic E5 (Accessibility QR systems)
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

$pageTitle       = 'Integration Recipes';
$pageDescription = 'Task-oriented ' . $siteName . ' API recipes: QR for your website/CMS, bulk generate '
    . 'from a spreadsheet, and accessible QR via the API.';
$bodyClass       = 'page-developers page-developers-recipes';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'recipes';

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';

$siteURLEscaped = htmlspecialchars($siteURL, ENT_QUOTES, 'UTF-8');
?>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'developers_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">
                        <i class="fa-solid fa-flask me-2" aria-hidden="true"></i>
                        Integration Recipes
                    </h1>
                    <p class="text-body-secondary mb-4">
                        Complete, task-oriented workflows built from the endpoints documented on the rest
                        of this portal.
                    </p>

                    <div class="legal-content">

                        <nav aria-label="Guide contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#website">1. QR for your website or CMS</a></li>
                                <li><a href="#spreadsheet">2. Bulk generate from a spreadsheet (CSV &rarr; ZIP)</a></li>
                                <li><a href="#accessible">3. Accessible QR via the API</a></li>
                                <li><a href="#dynamic">4. Dynamic QR via API - coming soon</a></li>
                            </ul>
                        </nav>

                        <!-- ============================================================
                             1. QR for your website / CMS
                             ============================================================ -->
                        <h2 id="website" class="h5 mt-4 mb-3">1. QR for Your Website or CMS</h2>
                        <p>
                            The lowest-effort way to put a live QR code on a page you control: generate it
                            once via <code>/generate</code> at build/publish time, then embed the returned
                            <code>download_url</code> as a normal <code>&lt;img&gt;</code> - no client-side
                            JavaScript required on the visitor's page, and no per-visit API calls.
                        </p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>&lt;?php
declare(strict_types=1);

// Run this ONCE (e.g. when the page/post is saved in your CMS), not per page view.
$curlHandle = curl_init('<?php echo $siteURLEscaped; ?>/api/v1/generate');
curl_setopt_array($curlHandle, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: cuercode_your_api_key_here',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'type'          => 'url',
        'input'         => ['url' => 'https://your-site.example.com/this-page'],
        'customization' => ['format' => 'svg', 'size' => 400, 'frame_style' => 'bottom-label', 'frame_label' => 'SCAN ME'],
    ], JSON_THROW_ON_ERROR),
]);

$result = json_decode((string) curl_exec($curlHandle), true, 512, JSON_THROW_ON_ERROR);
curl_close($curlHandle);

// Store $result['data']['download_url'] against the page/post in your CMS's own
// database, then render it on every subsequent page view as a normal image tag:
// &lt;img src="https://cuercode.net{$download_url}" alt="Scan to visit this page" width="200" height="200"&gt;</code></pre>
                        <p class="mb-0">
                            Prefer SVG (as above) for crisp scaling at any display size, or PDF for print
                            materials. See <a href="/developers/reference/">Endpoint Reference</a> for the
                            full <code>Customization</code> schema (colours, patterns, logos, frames).
                        </p>

                        <!-- ============================================================
                             2. Bulk from spreadsheet
                             ============================================================ -->
                        <h2 id="spreadsheet" class="h5 mt-4 mb-3">2. Bulk Generate From a Spreadsheet (CSV &rarr; ZIP)</h2>
                        <p>
                            <code>/bulk-generate</code> accepts a raw CSV body directly - export your
                            spreadsheet to CSV and post it as-is, no client-side parsing required. The
                            header row must include a <code>type</code> column and at least one input
                            column; any column NOT recognised as a shared customisation field (see the
                            reserved-column list on <a href="/developers/reference/">Endpoint Reference</a>
                            under <code>BulkGenerateRequest.csv</code>) becomes an <code>input.&lt;field&gt;</code>
                            value for that row.
                        </p>
                        <p>Example spreadsheet, exported as <code>codes.csv</code>:</p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>type,input.url,format
url,https://example.com/product/1,png
url,https://example.com/product/2,png
url,https://example.com/product/3,svg</code></pre>
                        <p>Post the file straight from disk with curl's <code>--data-binary</code> (so
                            newlines survive intact), wrapped in the required JSON envelope:</p>
                        <pre class="bg-body-tertiary p-3 rounded"><code># Wrap the CSV file's contents as the JSON "csv" string using jq, then post it:
jq -Rs '{csv: .}' codes.csv | curl -X POST <?php echo $siteURLEscaped; ?>/api/v1/bulk-generate \
  -H "Content-Type: application/json" \
  -d @- \
  -o codes-batch.zip

unzip -o codes-batch.zip -d codes-output
cat codes-output/manifest.txt   # per-row success/failure detail</code></pre>
                        <p class="mb-0">
                            Prefer to build the request programmatically instead of shelling out to
                            <code>jq</code>? Read the CSV with your language's standard CSV reader, convert
                            each row into an <code>items[]</code> entry (as shown in the
                            <a href="/developers/samples/">Code Samples</a> bulk-generate examples), and post
                            an <code>items</code> array instead of a <code>csv</code> string - functionally
                            equivalent, and easier to validate client-side before sending.
                        </p>

                        <!-- ============================================================
                             3. Accessible QR via API
                             ============================================================ -->
                        <h2 id="accessible" class="h5 mt-4 mb-3">3. Accessible QR via the API (<code>preset=accessible</code>)</h2>
                        <p>
                            Set <code>customization.preset</code> to <code>accessible</code> to
                            automatically enforce ECC level H, force the quiet zone on, and drop any pinned
                            QR version so the smallest auto-fit version is used (ISO/IEC 18004 + WCAG 2.1
                            practice). The request is validated server-side: a foreground/background pair
                            below WCAG's 3:1 minimum contrast is REJECTED with <code>422
                            ACCESSIBLE_CONTRAST_TOO_LOW</code>; a pair between 3:1 and 4.5:1 is allowed but
                            flagged as a non-blocking <code>contrast_warning</code> in the response.
                        </p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo $siteURLEscaped; ?>/api/v1/generate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{
        "type": "url",
        "input": { "url": "https://example.com" },
        "customization": {
          "preset": "accessible",
          "fg_color": "#000000",
          "bg_color": "#ffffff",
          "scan_distance_cm": 100
        }
      }'</code></pre>
                        <p>
                            Add <code>scan_distance_cm</code> (the intended scanning distance, in
                            centimetres) to get a recommended physical print size back, computed via the
                            10:1 scan-distance rule:
                        </p>
                        <pre class="bg-body-tertiary p-3 rounded"><code>{
    "success": true,
    "data": {
        "uuid": "...",
        "image": "data:image/png;base64,...",
        "metadata": {
            "accessible": true,
            "contrast_ratio": 21.0,
            "recommended_print_size_mm": 100
        }
    }
}</code></pre>
                        <p class="mb-0">
                            For PRINT output, pair this with <code>customization.format = "pdf"</code> and
                            <code>pdf_layout = "accessible_caption"</code> or <code>"tactile"</code> to add a
                            large-print + Braille caption and a tactile locator-frame page - see the
                            <a href="/accessibility/">Accessible &amp; Tactile QR Printing Guide</a> for the
                            full print/embossing workflow those layouts are designed for.
                        </p>

                        <!-- ============================================================
                             4. Dynamic QR - coming soon
                             ============================================================ -->
                        <h2 id="dynamic" class="h5 mt-4 mb-3">4. Dynamic QR via API</h2>
                        <div class="alert alert-secondary" role="note">
                            <p>
                                <i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i>
                                <strong>Coming soon</strong> - a QR code whose PRINTED image never changes
                                while its destination stays editable requires an indirection layer that does
                                not exist in the API yet.
                            </p>
                            <p class="mb-0">
                                Per PROJECT_ROADMAP.md Epic E1, this ships in two stages: an in-house
                                redirect layer (<code>/r/{uuid}</code> + a new <code>dynamic_url</code> QR
                                type + a destination-update endpoint), pending an owner approval gate, and
                                then an optional Go2My.Link-backed driver once their public short-link API
                                ships (tracked upstream as
                                <a href="https://github.com/MWBMPartners/Go2My.Link/issues/137" class="alert-link" rel="noopener noreferrer">Go2My.Link&nbsp;#137</a>).
                                This recipe will be filled in once either lands.
                            </p>
                        </div>

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
