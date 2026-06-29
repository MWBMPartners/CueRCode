<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - API ⇄ UI Parity Tests (WORKSTREAM A)
 * ==============================================================================
 *
 * Path: tests/test_api_parity.php
 *
 * Verifies the customisation-parsing + option-mapping changes that make the
 * REST API a STRICT SUPERSET of what the web UI offers. Per the project brief
 * (§6), the UI is built ON TOP OF the API, so every parameter the UI emits must
 * be ACCEPTED, VALIDATED, and TYPE-COERCED by the API — no untyped pass-through
 * into the QR engine.
 *
 * The audit identified three "api-has-ui-missing" gaps that this cycle closes:
 *   1. quiet_zone   — UI checkbox dropped by the API; now accepted (bool) and
 *                     mapped to the chillerlan addQuietzone QROptions property.
 *   2. marker_border / marker_center — UI finder-shape selectors dropped by the
 *                     API; now accepted + allowlisted and mapped onto keepAsSquare
 *                     when modules are circular.
 *   3. logo='upload' + logo_data/logo_mime — the UI uploads a custom logo as a
 *                     base64 data URI under logo_data; the API now resolves the
 *                     'upload' sentinel to that data URI (so getLogoPath() can
 *                     decode it) and allowlists logo_mime.
 *
 * What this file covers (all DB-independent):
 *   - buildCustomizationFromRequest() (fn_api_generate.php) accepts each new
 *     param, type-coerces it, allowlists bad values back to a safe default, and
 *     resolves the logo='upload' flow. fn_api_generate.php is DB-independent at
 *     include time (it only defines functions), so it loads in-process.
 *   - buildQROptions() (fn_qr_options.php) maps quiet_zone -> addQuietzone and
 *     marker_border/marker_center -> keepAsSquare without throwing. Needs the QR
 *     library autoloader (no DB).
 *   - mapOutputFormat() output-format coverage for ALL six contract formats
 *     (png, jpg, jpeg alias, gif, webp, svg, pdf).
 *
 * ⚠️ Lives under repo-root tests/ — never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (API ⇄ UI parity cycle, Workstream A)
 * @see         tests/bootstrap.php  - requireFn() / requireLibraryAutoloader()
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_api_generate.php - buildCustomizationFromRequest()
 * @see         web/CueRCode.net/_functions/fn_qr_options.php   - buildQROptions()
 */

// ==============================================================================
// 🧰 Load the production function files (NO database connection)
// ==============================================================================
// fn_api_generate.php only DEFINES functions at include time (it touches the DB
// lazily inside handler bodies via generateQRCode()/logActivity()), so including
// it opens no connection. buildCustomizationFromRequest() is a pure transform.
// @see tests/bootstrap.php - requireFn()
// ==============================================================================

requireFn('fn_api_generate.php');

// 📚 buildQROptions() needs the chillerlan\QRCode classes (QROptions, EccLevel,
//    QRMatrix, the output classes). The autoloader opens NO DB connection.
$qrLibraryLoaded = requireLibraryAutoloader();

if ($qrLibraryLoaded) {
    requireFn('fn_qr_options.php');
}

// ==============================================================================
// 🔧 buildCustomizationFromRequest() — quiet_zone (api-has-ui-missing gap #1)
// ==============================================================================
// @see web/CueRCode.net/_functions/fn_api_generate.php - buildCustomizationFromRequest()
// ==============================================================================

// ✅ Default: absent quiet_zone defaults to true (UI default-checked + library default).
$cz = buildCustomizationFromRequest([]);
assertTrue(
    array_key_exists('quiet_zone', $cz),
    'buildCustomizationFromRequest always sets quiet_zone (default present)'
);
assertEquals(
    true,
    $cz['quiet_zone'],
    'buildCustomizationFromRequest defaults quiet_zone to true (bool) when omitted'
);

// ✅ Explicit false (the UI sends a real bool) is preserved as a bool.
$czFalse = buildCustomizationFromRequest(['quiet_zone' => false]);
assertEquals(
    false,
    $czFalse['quiet_zone'],
    'buildCustomizationFromRequest preserves quiet_zone=false as a bool'
);

// ✅ Truthy/falsey scalars are coerced to a strict bool (no untyped pass-through).
assertEquals(
    true,
    buildCustomizationFromRequest(['quiet_zone' => 1])['quiet_zone'],
    'buildCustomizationFromRequest coerces quiet_zone=1 (int) to true (bool)'
);
assertEquals(
    false,
    buildCustomizationFromRequest(['quiet_zone' => 0])['quiet_zone'],
    'buildCustomizationFromRequest coerces quiet_zone=0 (int) to false (bool)'
);
assertEquals(
    false,
    buildCustomizationFromRequest(['quiet_zone' => ''])['quiet_zone'],
    'buildCustomizationFromRequest coerces quiet_zone="" to false (bool)'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() — marker_border (api-has-ui-missing gap #2)
// ==============================================================================

// ✅ Each allowlisted value is accepted verbatim (lower-cased + trimmed).
foreach (['square', 'round', 'circle', 'extra-rounded'] as $mb) {
    assertEquals(
        $mb,
        buildCustomizationFromRequest(['marker_border' => $mb])['marker_border'],
        'buildCustomizationFromRequest accepts marker_border="' . $mb . '"'
    );
}

// ✅ Mixed-case + whitespace is normalised to the canonical lower-case value.
assertEquals(
    'extra-rounded',
    buildCustomizationFromRequest(['marker_border' => '  Extra-Rounded '])['marker_border'],
    'buildCustomizationFromRequest trims + lower-cases marker_border'
);

// ✅ Default + bad values fall back to 'square' (no untyped pass-through).
assertEquals(
    'square',
    buildCustomizationFromRequest([])['marker_border'],
    'buildCustomizationFromRequest defaults marker_border to "square" when omitted'
);
assertEquals(
    'square',
    buildCustomizationFromRequest(['marker_border' => 'hexagon'])['marker_border'],
    'buildCustomizationFromRequest rejects an out-of-allowlist marker_border -> "square"'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() — marker_center (api-has-ui-missing gap #2)
// ==============================================================================

// ✅ Each allowlisted value is accepted.
foreach (['square', 'round', 'circle', 'diamond'] as $mc) {
    assertEquals(
        $mc,
        buildCustomizationFromRequest(['marker_center' => $mc])['marker_center'],
        'buildCustomizationFromRequest accepts marker_center="' . $mc . '"'
    );
}

// ✅ Default + bad values fall back to 'square'.
assertEquals(
    'square',
    buildCustomizationFromRequest([])['marker_center'],
    'buildCustomizationFromRequest defaults marker_center to "square" when omitted'
);
assertEquals(
    'square',
    buildCustomizationFromRequest(['marker_center' => 'star'])['marker_center'],
    'buildCustomizationFromRequest rejects an out-of-allowlist marker_center -> "square"'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() — logo upload flow (api-has-ui-missing #3)
// ==============================================================================
// A 1x1 transparent PNG as a data URI — the exact shape the UI's FileReader
// produces (data:image/png;base64,...). We do NOT decode it here; we only assert
// the API substitutes it for the 'upload' sentinel and allowlists the MIME.
// ==============================================================================

$pngDataUri = 'data:image/png;base64,'
    . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

// ✅ logo='upload' + valid logo_data => the data URI replaces 'upload' as the
//    effective logo value (getLogoPath() understands a data: URI but not 'upload').
$up = buildCustomizationFromRequest([
    'logo'      => 'upload',
    'logo_data' => $pngDataUri,
    'logo_mime' => 'image/png',
]);
assertEquals(
    $pngDataUri,
    $up['logo'] ?? null,
    'buildCustomizationFromRequest resolves logo="upload" to the logo_data data URI'
);
assertEquals(
    $pngDataUri,
    $up['logo_data'] ?? null,
    'buildCustomizationFromRequest retains the raw logo_data data URI'
);
assertEquals(
    'image/png',
    $up['logo_mime'] ?? null,
    'buildCustomizationFromRequest allowlists logo_mime=image/png'
);

// ✅ logo='upload' WITHOUT valid logo_data => the meaningless sentinel is dropped
//    (an 'upload' string alone resolves to null in getLogoPath()).
$upNoData = buildCustomizationFromRequest(['logo' => 'upload']);
assertFalse(
    array_key_exists('logo', $upNoData),
    'buildCustomizationFromRequest drops logo="upload" when no logo_data is supplied'
);

// ✅ A malformed logo_data (not an image data URI) is NOT accepted and the
//    sentinel is dropped — nothing untyped reaches the decoder.
$upBadData = buildCustomizationFromRequest([
    'logo'      => 'upload',
    'logo_data' => 'not-a-data-uri',
]);
assertFalse(
    array_key_exists('logo', $upBadData),
    'buildCustomizationFromRequest drops logo="upload" with a non-data-URI logo_data'
);
assertFalse(
    array_key_exists('logo_data', $upBadData),
    'buildCustomizationFromRequest does not retain a malformed logo_data value'
);

// ✅ An out-of-allowlist logo_mime is dropped (text/html etc. never pass through).
$upBadMime = buildCustomizationFromRequest([
    'logo'      => 'upload',
    'logo_data' => $pngDataUri,
    'logo_mime' => 'text/html',
]);
assertFalse(
    array_key_exists('logo_mime', $upBadMime),
    'buildCustomizationFromRequest drops an out-of-allowlist logo_mime (text/html)'
);

// ✅ A numeric predefined-logo ID is left untouched (not treated as an upload).
$predef = buildCustomizationFromRequest(['logo' => 5]);
assertEquals(
    5,
    $predef['logo'] ?? null,
    'buildCustomizationFromRequest leaves a numeric predefined logo ID untouched'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() — logo_size_pct clamp (contract 5-40)
// ==============================================================================

assertEquals(
    5,
    buildCustomizationFromRequest(['logo_size_pct' => 1])['logo_size_pct'],
    'buildCustomizationFromRequest clamps logo_size_pct up to the 5 lower bound'
);
assertEquals(
    40,
    buildCustomizationFromRequest(['logo_size_pct' => 999])['logo_size_pct'],
    'buildCustomizationFromRequest clamps logo_size_pct down to the 40 upper bound'
);
assertEquals(
    25,
    buildCustomizationFromRequest(['logo_size_pct' => '25'])['logo_size_pct'],
    'buildCustomizationFromRequest coerces a numeric-string logo_size_pct to int'
);

// ==============================================================================
// 🔁 Regression guard — pre-existing params still parse as before
// ==============================================================================
// The new code must not have disturbed the format / size / ecc / pattern /
// colour normalisation the API already did.
// ==============================================================================

$base = buildCustomizationFromRequest([
    'format'   => 'WEBP',
    'size'     => 99999,
    'ecc'      => 'h',
    'pattern'  => 'ROUND',
    'fg_color' => '#abc',
]);
assertEquals('webp', $base['format'], 'regression: format is lower-cased + allowlisted (webp)');
assertEquals(4000, $base['size'], 'regression: size is clamped to the 4000 upper bound');
assertEquals('H', $base['ecc'], 'regression: ecc is upper-cased + allowlisted (H)');
assertEquals('round', $base['pattern'], 'regression: pattern is lower-cased + allowlisted (round)');
assertEquals('#abc', $base['fg_color'], 'regression: fg_color is passed through for downstream validateColor()');
assertEquals(
    'png',
    buildCustomizationFromRequest(['format' => 'tiff'])['format'],
    'regression: an unsupported format falls back to png'
);

// ==============================================================================
// ⚙️ buildQROptions() — quiet_zone + marker shapes actually reach QROptions
// ==============================================================================
// Needs the QR library autoloader (no DB). We read the QROptions properties back
// to PROVE the mapping took effect, rather than just trusting the call returns.
// @see web/CueRCode.net/_functions/fn_qr_options.php - buildQROptions()
// ==============================================================================

if (!$qrLibraryLoaded) {
    // ⚠️ Make the missing library a visible FAIL so coverage is not silently lost.
    assertTrue(
        false,
        'QR library autoloader not found at _libraries/autoload.php — buildQROptions parity tests SKIPPED'
    );
} else {
    // 📌 quiet_zone=true (default) -> addQuietzone === true.
    $optQzOn = buildQROptions(['format' => 'png', 'quiet_zone' => true, 'payload' => 'x']);
    assertEquals(
        true,
        $optQzOn->addQuietzone,
        'buildQROptions maps quiet_zone=true -> QROptions::$addQuietzone === true'
    );

    // 📌 quiet_zone=false -> addQuietzone === false (the toggle actually fires).
    $optQzOff = buildQROptions(['format' => 'png', 'quiet_zone' => false, 'payload' => 'x']);
    assertEquals(
        false,
        $optQzOff->addQuietzone,
        'buildQROptions maps quiet_zone=false -> QROptions::$addQuietzone === false'
    );

    // 📌 Default (no quiet_zone key) keeps the library/UI default of true.
    $optQzDefault = buildQROptions(['format' => 'png', 'payload' => 'x']);
    assertEquals(
        true,
        $optQzDefault->addQuietzone,
        'buildQROptions defaults addQuietzone to true when quiet_zone is omitted'
    );

    // 📌 Square markers + circular modules => all three finder module types are
    //    kept square (the scanner-safe default).
    $optSquareMarkers = buildQROptions([
        'format'        => 'png',
        'pattern'       => 'round',
        'marker_border' => 'square',
        'marker_center' => 'square',
        'payload'       => 'x',
    ]);
    assertEquals(
        true,
        $optSquareMarkers->drawCircularModules,
        'buildQROptions enables drawCircularModules for pattern=round'
    );
    $keepSquare = $optSquareMarkers->keepAsSquare;
    assertTrue(
        is_array($keepSquare)
            && in_array(\chillerlan\QRCode\Data\QRMatrix::M_FINDER_DARK, $keepSquare, true)
            && in_array(\chillerlan\QRCode\Data\QRMatrix::M_FINDER, $keepSquare, true)
            && in_array(\chillerlan\QRCode\Data\QRMatrix::M_FINDER_DOT, $keepSquare, true),
        'buildQROptions keeps ALL finder module types square when both marker shapes are "square"'
    );

    // 📌 Rounded border + rounded centre + circular modules => keepAsSquare is
    //    empty (the finders are allowed to round along with the data modules).
    $optRoundMarkers = buildQROptions([
        'format'        => 'png',
        'pattern'       => 'round',
        'marker_border' => 'circle',
        'marker_center' => 'round',
        'payload'       => 'x',
    ]);
    assertEquals(
        [],
        $optRoundMarkers->keepAsSquare,
        'buildQROptions leaves keepAsSquare empty when both marker shapes are non-square'
    );

    // 📌 Rounded border but SQUARE centre => only the centre dot stays square.
    $optMixedMarkers = buildQROptions([
        'format'        => 'png',
        'pattern'       => 'round',
        'marker_border' => 'round',
        'marker_center' => 'square',
        'payload'       => 'x',
    ]);
    assertEquals(
        [\chillerlan\QRCode\Data\QRMatrix::M_FINDER_DOT],
        $optMixedMarkers->keepAsSquare,
        'buildQROptions keeps ONLY the finder centre dot square when border=round, center=square'
    );

    // 📌 Square modules (pattern=square): drawCircularModules off; marker shapes
    //    are inert (no keepAsSquare needed) but the call must still succeed.
    $optSquarePattern = buildQROptions([
        'format'        => 'png',
        'pattern'       => 'square',
        'marker_border' => 'circle',
        'marker_center' => 'diamond',
        'payload'       => 'x',
    ]);
    assertEquals(
        false,
        $optSquarePattern->drawCircularModules,
        'buildQROptions keeps drawCircularModules=false for pattern=square regardless of marker shapes'
    );

    // ==========================================================================
    // 📋 mapOutputFormat() — every contract output format is covered
    // ==========================================================================
    // The contract lists 6 logical formats (png, jpg, gif, webp, svg, pdf) plus
    // the 'jpeg' alias. Each must map to a non-empty MIME type + interface.
    // ==========================================================================
    $formatMimeExpectations = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
    ];

    foreach ($formatMimeExpectations as $fmt => $expectedMime) {
        $map = mapOutputFormat($fmt);
        assertEquals(
            $expectedMime,
            $map['mimeType'] ?? null,
            'mapOutputFormat("' . $fmt . '") -> ' . $expectedMime . ' MIME type'
        );
        assertTrue(
            isset($map['outputInterface']) && $map['outputInterface'] !== '',
            'mapOutputFormat("' . $fmt . '") returns a non-empty outputInterface class'
        );
    }
}
