<?php
/**
 * ==============================================================================
 * 💻 CueRCode - Developer Portal: Code Samples
 * ==============================================================================
 *
 * Path: public_html/developers/samples/index.php
 *
 * Working, runnable request samples for the core API endpoints
 * (/generate, /preview, /download/{uuid}, /bulk-generate, /barcode) in curl,
 * PHP, JavaScript (fetch), and Python (requests) - matching the CURRENT API
 * contract (auth requirements, request/response shapes, field names) as
 * declared in openapi.json. Samples are shown sequentially (not behind
 * JS-only tabs) so every language is readable/searchable even with
 * JavaScript disabled, per the site's "graceful JS-disabled fallback" rule.
 *
 * @package     CueRCode
 * @subpackage  Developer Portal
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E6 developer portal cycle, #50)
 * @see         public_html/api/v1/openapi.json - the contract these samples follow
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================
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

$pageTitle       = 'Code Samples';
$pageDescription = 'Working curl, PHP, JavaScript (fetch), and Python (requests) samples for '
    . 'generate, preview, download, bulk-generate, and non-QR barcodes.';
$bodyClass       = 'page-developers page-developers-samples';
$pageCSS         = [];
$pageJS          = [];

$developersActive = 'samples';

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
                        <i class="fa-solid fa-code me-2" aria-hidden="true"></i>
                        Code Samples
                    </h1>
                    <p class="text-body-secondary mb-4">
                        Every sample below replaces <code>cuercode_your_api_key_here</code> with a real
                        API key - see <a href="/developers/authentication/">Authentication</a>. Samples
                        marked "anonymous OK" work with no credentials at all.
                    </p>

                    <div class="legal-content">

                        <nav aria-label="Guide contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#generate">1. Generate a QR code (POST /generate)</a></li>
                                <li><a href="#preview">2. Live preview (POST /preview)</a></li>
                                <li><a href="#download">3. Download by UUID (GET /download/{uuid})</a></li>
                                <li><a href="#bulk">4. Bulk generate (POST /bulk-generate) - anonymous OK</a></li>
                                <li><a href="#barcode">5. Non-QR barcode (POST /barcode)</a></li>
                            </ul>
                        </nav>

                        <!-- ============================================================
                             1. Generate
                             ============================================================ -->
                        <h2 id="generate" class="h5 mt-4 mb-3">1. Generate a QR Code</h2>
                        <p><code>POST /api/v1/generate</code> - requires an API key or CSRF token; returns
                            a base64 data-URI image, a <code>uuid</code>, and a <code>download_url</code>.</p>

                        <h3 class="h6 mt-3">curl</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo $siteURLEscaped; ?>/api/v1/generate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{
        "type": "url",
        "input": { "url": "https://example.com" },
        "customization": { "format": "png", "size": 512, "ecc": "H" }
      }'</code></pre>

                        <h3 class="h6 mt-3">PHP</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>&lt;?php
declare(strict_types=1);

$apiKey  = 'cuercode_your_api_key_here';
$payload = [
    'type'          => 'url',
    'input'         => ['url' => 'https://example.com'],
    'customization' => ['format' => 'png', 'size' => 512, 'ecc' => 'H'],
];

$curlHandle = curl_init('<?php echo $siteURLEscaped; ?>/api/v1/generate');
curl_setopt_array($curlHandle, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
]);

$responseBody = curl_exec($curlHandle);
$httpStatus   = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
// No curl_close() needed: PHP 8.0+ frees the handle automatically (and PHP 8.5 warns if you call it).

$result = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);

if ($httpStatus === 200 && $result['success'] === true) {
    // $result['data']['image'] is a base64 data URI; download_url fetches the saved file.
    echo 'Download URL: ' . $result['data']['download_url'] . PHP_EOL;
} else {
    echo 'Error [' . $result['error_code'] . ']: ' . $result['message'] . PHP_EOL;
}</code></pre>

                        <h3 class="h6 mt-3">JavaScript (fetch)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>const response = await fetch('<?php echo $siteURLEscaped; ?>/api/v1/generate', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-API-Key': 'cuercode_your_api_key_here',
        // Same-origin browser session instead? Swap the header above for:
        // 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({
        type: 'url',
        input: { url: 'https://example.com' },
        customization: { format: 'png', size: 512, ecc: 'H' },
    }),
});

const result = await response.json();

if (response.ok && result.success) {
    document.querySelector('#qr-preview').src = result.data.image; // base64 data URI
} else {
    console.error(`Error [${result.error_code}]: ${result.message}`);
}</code></pre>

                        <h3 class="h6 mt-3">Python (requests)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>import requests

API_KEY = "cuercode_your_api_key_here"

response = requests.post(
    "<?php echo $siteURLEscaped; ?>/api/v1/generate",
    headers={"X-API-Key": API_KEY},
    json={
        "type": "url",
        "input": {"url": "https://example.com"},
        "customization": {"format": "png", "size": 512, "ecc": "H"},
    },
    timeout=10,
)
result = response.json()

if response.ok and result["success"]:
    print("Download URL:", result["data"]["download_url"])
else:
    print(f"Error [{result.get('error_code')}]: {result.get('message')}")</code></pre>

                        <!-- ============================================================
                             2. Preview
                             ============================================================ -->
                        <h2 id="preview" class="h5 mt-4 mb-3">2. Live Preview</h2>
                        <p><code>POST /api/v1/preview</code> - same auth and request shape as
                            <code>/generate</code>, but always returns a 300px PNG and is NOT saved to the
                            database or filesystem (no <code>uuid</code>/<code>download_url</code> in the
                            response). Ideal for a live "as-you-type" preview widget.</p>

                        <h3 class="h6 mt-3">curl</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo $siteURLEscaped; ?>/api/v1/preview \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{"type": "url", "input": {"url": "https://example.com"}, "customization": {"fg_color": "#333333", "pattern": "dot"}}'</code></pre>

                        <h3 class="h6 mt-3">PHP</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>&lt;?php
declare(strict_types=1);

// Identical curl setup to the /generate sample above, but targeting /preview
// and only reading $result['data']['image'] (no uuid/download_url is returned).
$curlHandle = curl_init('<?php echo $siteURLEscaped; ?>/api/v1/preview');
curl_setopt_array($curlHandle, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: cuercode_your_api_key_here',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'type'          => 'url',
        'input'         => ['url' => 'https://example.com'],
        'customization' => ['fg_color' => '#333333', 'pattern' => 'dot'],
    ], JSON_THROW_ON_ERROR),
]);

$result = json_decode((string) curl_exec($curlHandle), true, 512, JSON_THROW_ON_ERROR);
// No curl_close() needed: PHP 8.0+ frees the handle automatically (and PHP 8.5 warns if you call it).

if ($result['success'] === true) {
    echo $result['data']['image']; // base64 data URI - use directly as an <img src>
}</code></pre>

                        <h3 class="h6 mt-3">JavaScript (fetch)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>async function updatePreview(qrInput, customization) {
    const response = await fetch('<?php echo $siteURLEscaped; ?>/api/v1/preview', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ type: 'url', input: qrInput, customization }),
    });

    const result = await response.json();

    if (response.ok && result.success) {
        document.querySelector('#qr-preview').src = result.data.image;
    }
}

// e.g. call updatePreview({ url: 'https://example.com' }, { pattern: 'dot' })
// on every keystroke/option change, debounced.</code></pre>

                        <h3 class="h6 mt-3">Python (requests)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>import requests

response = requests.post(
    "<?php echo $siteURLEscaped; ?>/api/v1/preview",
    headers={"X-API-Key": "cuercode_your_api_key_here"},
    json={
        "type": "url",
        "input": {"url": "https://example.com"},
        "customization": {"fg_color": "#333333", "pattern": "dot"},
    },
    timeout=10,
)
result = response.json()

if result["success"]:
    data_uri = result["data"]["image"]  # base64 data URI (always PNG, 300px)</code></pre>

                        <!-- ============================================================
                             3. Download
                             ============================================================ -->
                        <h2 id="download" class="h5 mt-4 mb-3">3. Download a Generated QR Code by UUID</h2>
                        <p><code>GET /api/v1/download/{uuid}</code> - requires an API key or CSRF token;
                            returns the RAW image/PDF bytes (not JSON) with a
                            <code>Content-Disposition: attachment</code> header, using the <code>uuid</code>
                            returned by a prior <code>/generate</code> call.</p>

                        <h3 class="h6 mt-3">curl</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X GET "<?php echo $siteURLEscaped; ?>/api/v1/download/a1b2c3d4-e5f6-4890-abcd-ef1234567890" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -o downloaded-qr.png</code></pre>

                        <h3 class="h6 mt-3">PHP</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>&lt;?php
declare(strict_types=1);

$uuid = 'a1b2c3d4-e5f6-4890-abcd-ef1234567890';

$curlHandle = curl_init('<?php echo $siteURLEscaped; ?>/api/v1/download/' . $uuid);
curl_setopt_array($curlHandle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['X-API-Key: cuercode_your_api_key_here'],
]);

$fileBytes  = curl_exec($curlHandle);
$httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
// No curl_close() needed: PHP 8.0+ frees the handle automatically (and PHP 8.5 warns if you call it).

if ($httpStatus === 200) {
    file_put_contents('downloaded-qr.png', (string) $fileBytes);
} else {
    // Non-200 responses ARE JSON error envelopes, not image bytes.
    echo 'Download failed: ' . $fileBytes . PHP_EOL;
}</code></pre>

                        <h3 class="h6 mt-3">JavaScript (fetch)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>const response = await fetch(
    `<?php echo $siteURLEscaped; ?>/api/v1/download/${uuid}`,
    { headers: { 'X-API-Key': 'cuercode_your_api_key_here' } },
);

if (response.ok) {
    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = 'cuercode-qr.png';
    link.click();
    URL.revokeObjectURL(objectUrl);
} else {
    const error = await response.json();
    console.error(`Error [${error.error_code}]: ${error.message}`);
}</code></pre>

                        <h3 class="h6 mt-3">Python (requests)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>import requests

uuid = "a1b2c3d4-e5f6-4890-abcd-ef1234567890"

response = requests.get(
    f"<?php echo $siteURLEscaped; ?>/api/v1/download/{uuid}",
    headers={"X-API-Key": "cuercode_your_api_key_here"},
    timeout=10,
    stream=True,
)
response.raise_for_status()

with open("downloaded-qr.png", "wb") as output_file:
    for chunk in response.iter_content(chunk_size=8192):
        output_file.write(chunk)</code></pre>

                        <!-- ============================================================
                             4. Bulk generate
                             ============================================================ -->
                        <h2 id="bulk" class="h5 mt-4 mb-3">4. Bulk Generate (Anonymous OK)</h2>
                        <p><code>POST /api/v1/bulk-generate</code> - the only generation endpoint that
                            allows anonymous access (still rate limited). Provide EITHER an
                            <code>items</code> array OR a <code>csv</code> string (see
                            <a href="/developers/recipes/">Integration Recipes</a> for the CSV/spreadsheet
                            workflow). Returns a ZIP archive by default, UNLESS the shared
                            <code>customization</code> requests <code>format: pdf</code> with
                            <code>pdf_layout: avery_5160</code>/<code>avery_l7160</code>, in which case it
                            returns a single combined <code>application/pdf</code> label sheet instead - always
                            check the response <code>Content-Type</code> before assuming which shape you got,
                            and check the HTTP status before assuming the body is a file at all (a non-200
                            response is a JSON error envelope, not a ZIP/PDF).</p>

                        <h3 class="h6 mt-3">curl</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo $siteURLEscaped; ?>/api/v1/bulk-generate \
  -H "Content-Type: application/json" \
  -d '{
        "items": [
          { "type": "url", "input": { "url": "https://a.example.com" } },
          { "type": "url", "input": { "url": "https://b.example.com" } }
        ],
        "customization": { "format": "png", "ecc": "H" }
      }' \
  -o qr-batch.zip</code></pre>

                        <h3 class="h6 mt-3">PHP</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>&lt;?php
declare(strict_types=1);

$payload = [
    'items' => [
        ['type' => 'url', 'input' => ['url' => 'https://a.example.com']],
        ['type' => 'url', 'input' => ['url' => 'https://b.example.com']],
    ],
    'customization' => ['format' => 'png', 'ecc' => 'H'],
];

$curlHandle = curl_init('<?php echo $siteURLEscaped; ?>/api/v1/bulk-generate');
curl_setopt_array($curlHandle, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
]);

$responseBody = curl_exec($curlHandle);
$httpStatus   = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
$contentType  = (string) curl_getinfo($curlHandle, CURLINFO_CONTENT_TYPE);
// No curl_close() needed: PHP 8.0+ frees the handle automatically (and PHP 8.5 warns if you call it).

if ($httpStatus === 200 && str_starts_with($contentType, 'application/zip')) {
    file_put_contents('qr-batch.zip', (string) $responseBody);
} elseif ($httpStatus === 200 && str_starts_with($contentType, 'application/pdf')) {
    file_put_contents('qr-batch.pdf', (string) $responseBody); // Avery label-sheet mode
} else {
    $error = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);
    echo 'Error [' . $error['error_code'] . ']: ' . $error['message'] . PHP_EOL;
}</code></pre>

                        <h3 class="h6 mt-3">JavaScript (fetch)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>const response = await fetch('<?php echo $siteURLEscaped; ?>/api/v1/bulk-generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        items: [
            { type: 'url', input: { url: 'https://a.example.com' } },
            { type: 'url', input: { url: 'https://b.example.com' } },
        ],
        customization: { format: 'png', ecc: 'H' },
    }),
});

if (response.ok) {
    const blob = await response.blob(); // application/zip (or application/pdf for Avery sheets)
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = 'qr-batch.zip';
    link.click();
    URL.revokeObjectURL(objectUrl);
} else {
    const error = await response.json();
    console.error(`Error [${error.error_code}]: ${error.message}`);
}</code></pre>

                        <h3 class="h6 mt-3">Python (requests)</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>import requests

response = requests.post(
    "<?php echo $siteURLEscaped; ?>/api/v1/bulk-generate",
    json={
        "items": [
            {"type": "url", "input": {"url": "https://a.example.com"}},
            {"type": "url", "input": {"url": "https://b.example.com"}},
        ],
        "customization": {"format": "png", "ecc": "H"},
    },
    timeout=30,
)

content_type = response.headers.get("Content-Type", "")

if response.ok and content_type.startswith("application/zip"):
    with open("qr-batch.zip", "wb") as output_file:
        output_file.write(response.content)
elif response.ok and content_type.startswith("application/pdf"):
    with open("qr-batch.pdf", "wb") as output_file:  # Avery label-sheet mode
        output_file.write(response.content)
else:
    error = response.json()
    print(f"Error [{error.get('error_code')}]: {error.get('message')}")</code></pre>

                        <!-- ============================================================
                             5. Non-QR barcode
                             ============================================================ -->
                        <h2 id="barcode" class="h5 mt-4 mb-3">5. Non-QR Barcode</h2>
                        <p><code>POST /api/v1/barcode</code> - requires an API key or CSRF token (same
                            <code>generate</code> scope as <code>/generate</code>); generates a non-QR
                            barcode symbology (8 linear types, 3 matrix types - see
                            <a href="/developers/recipes/#barcode">Integration Recipes</a> for the full
                            list) and returns a <code>uuid</code> and <code>download_url</code>, exactly
                            like <code>/generate</code>.</p>

                        <h3 class="h6 mt-3">curl</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>curl -X POST <?php echo $siteURLEscaped; ?>/api/v1/barcode \
  -H "Content-Type: application/json" \
  -H "X-API-Key: cuercode_your_api_key_here" \
  -d '{
        "symbology": "ean13",
        "data": "400638133393",
        "options": { "format": "svg" }
      }'</code></pre>

                        <h3 class="h6 mt-3">PHP</h3>
                        <pre class="bg-body-tertiary p-3 rounded"><code>&lt;?php
declare(strict_types=1);

$apiKey  = 'cuercode_your_api_key_here';
$payload = [
    'symbology' => 'ean13',
    'data'      => '400638133393',
    'options'   => ['format' => 'svg'],
];

$curlHandle = curl_init('<?php echo $siteURLEscaped; ?>/api/v1/barcode');
curl_setopt_array($curlHandle, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
]);

$responseBody = curl_exec($curlHandle);
$httpStatus   = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
// No curl_close() needed: PHP 8.0+ frees the handle automatically (and PHP 8.5 warns if you call it).

$result = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);

if ($httpStatus === 200 && $result['success'] === true) {
    // A wrong check digit (e.g. a mistyped EAN-13) comes back as 422 GENERATION_FAILED.
    echo 'Download URL: ' . $result['data']['download_url'] . PHP_EOL;
} else {
    echo 'Error [' . $result['error_code'] . ']: ' . $result['message'] . PHP_EOL;
}</code></pre>

                        <p class="mt-4 mb-0">
                            See <a href="/developers/errors/">Errors &amp; Rate Limits</a> for the full
                            error-code catalogue and rate-limit defaults referenced above, and
                            <a href="/developers/reference/">Endpoint Reference</a> for the full parameter/
                            schema detail (rendered live from openapi.json) behind every field used here.
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
