<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for the SVG Logo Screener (F-024, #52)
 * ==============================================================================
 *
 * Path: tests/test_qr_logo.php
 *
 * Covers the DB-INDEPENDENT, pure/near-pure surface added/changed to close
 * SECURITY.md finding F-024's flagged (not-yet-fixed) companion gap: the
 * JSON API's base64 `data:image/svg+xml;base64,...` logo intake path
 * (fn_qr_logo.php - saveBase64LogoToTemp()) previously enforced ONLY the
 * F-005 size/pixel caps and never screened SVG content for active/script
 * vectors the way the multipart `$_FILES` upload path
 * (fn_validation.php - validateImageUpload()) always did. Both paths now
 * call ONE shared screener - screenSvgLogoContent() (fn_validation.php) -
 * so they enforce identical rules instead of drifting.
 *
 * Units covered (all pure or near-pure - the only I/O is writing to the OS
 * temp dir via saveBase64LogoToTemp(), exactly like test_account_ui.php's
 * existing "genuine tiny-PNG" round-trip convention):
 *   - screenSvgLogoContent()    (fn_validation.php) - the shared screener:
 *     each individual dangerous-construct vector is proven rejected in
 *     isolation, and a clean SVG is proven accepted (no false positive).
 *   - saveBase64LogoToTemp()    (fn_qr_logo.php) - proves the base64 write
 *     path now REJECTS a malicious SVG logo (the actual F-024 fix) while a
 *     clean SVG and a genuine raster (PNG) logo are both still accepted
 *     (raster logos are completely unaffected by this change).
 *   - screenBase64LogoDataUri() (fn_qr_logo.php) - the pure, no-disk-I/O
 *     pre-check the API layer uses for its 422 rejection.
 *   - rejectUnsafeBase64SvgLogo() (fn_qr_logo.php) - the customization-array
 *     shaped wrapper the three JSON API call sites (handleGenerate(),
 *     handlePreview(), and the PATCH /qrcodes/{uuid} handler) invoke.
 *   - Section 5 (static, source-text only): regression guards proving the
 *     multipart upload path and the base64 path both call the SAME shared
 *     screener, and that all three API call sites are wired to the new
 *     LOGO_SVG_REJECTED / 422 response.
 *
 * ⚠️ SKIPPED (environment-dependent - out of scope for this harness, same
 *    convention as test_core.php's validateImageUpload() exclusion):
 *   - validateImageUpload() itself needs a GENUINE HTTP upload
 *     (is_uploaded_file() is always false for a CLI-created temp file) -
 *     the static regression guard in Section 5 below is the parity proof
 *     for that path instead.
 *   - The live HTTP 422 LOGO_SVG_REJECTED response from handleGenerate()/
 *     handlePreview()/the PATCH handler needs the real router + a scratch
 *     DB (for the PATCH case) - see SECURITY.md/CHANGELOG.md for that
 *     evidence; this file proves the underlying pure functions those
 *     handlers call are correct.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52 - F-024 close-out)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_validation.php - screenSvgLogoContent()
 * @see         web/CueRCode.net/_functions/fn_qr_logo.php    - saveBase64LogoToTemp(),
 *              screenBase64LogoDataUri(), rejectUnsafeBase64SvgLogo()
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// fn_validation.php MUST load first so screenSvgLogoContent() already exists
// by the time fn_qr_logo.php's saveBase64LogoToTemp() checks
// function_exists('screenSvgLogoContent') - this mirrors the REAL production
// load order (bootstrap.php loads fn_validation.php globally, well before any
// page loads fn_qr_logo.php - see bootstrap.php's $functionFiles list).
// ==============================================================================

requireFn('fn_validation.php');
requireFn('fn_qr_logo.php');

// ==============================================================================
// ✅ Section 1: screenSvgLogoContent() - clean SVG accepted (no false positive)
// ==============================================================================

$cleanSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
    . '<path d="M12 2L2 7l10 5 10-5-10-5z" fill="#000000"/>'
    . '</svg>';

$cleanResult = screenSvgLogoContent($cleanSvg);

assertTrue(
    $cleanResult['valid'],
    'screenSvgLogoContent() accepts a genuine, inert SVG icon with no active content'
);
assertEquals(
    null,
    $cleanResult['error'],
    'screenSvgLogoContent() returns a null error for a clean SVG'
);

// 📌 A same-document fragment href (the ONLY accepted href shape) must not
// be flagged - proves the href check is not simply "reject any href".
$internalHrefSvg = '<svg xmlns="http://www.w3.org/2000/svg">'
    . '<defs><path id="p1" d="M0 0h10v10H0z"/></defs>'
    . '<use href="#p1"/>'
    . '</svg>';

assertTrue(
    screenSvgLogoContent($internalHrefSvg)['valid'],
    'screenSvgLogoContent() accepts a same-document "#fragment" href/xlink:href (internal <use> reuse is legitimate)'
);

// ==============================================================================
// ❌ Section 2: screenSvgLogoContent() - each dangerous vector rejected
// ==============================================================================
// Each fixture isolates ONE vector so a future edit that accidentally removes
// a single pattern is caught precisely, not masked by another pattern firing
// first.
// ==============================================================================

$dangerousFixtures = [
    'embedded <script> element'
        => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
    'inline event-handler attribute (onload=)'
        => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
    'inline event-handler attribute (onclick= on a child element)'
        => '<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)" width="1" height="1"/></svg>',
    'javascript: URI scheme'
        => '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>',
    'vbscript: URI scheme'
        => '<svg xmlns="http://www.w3.org/2000/svg"><a href="vbscript:alert(1)"><rect width="1" height="1"/></a></svg>',
    'data: URI'
        => '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/svg+xml;base64,QUFB"/></svg>',
    '<foreignObject> element'
        => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">'
            . '<script>alert(1)</script></body></foreignObject></svg>',
    'DOCTYPE declaration (XXE)'
        => '<!DOCTYPE svg [<!ELEMENT svg ANY>]><svg xmlns="http://www.w3.org/2000/svg"></svg>',
    'ENTITY declaration (billion-laughs / XXE)'
        => '<?xml version="1.0"?><!DOCTYPE lol ['
            . '<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">'
            . ']><svg xmlns="http://www.w3.org/2000/svg">&lol2;</svg>',
    'CSS @import'
        => '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(evil.css);</style></svg>',
    'CSS expression()'
        => '<svg xmlns="http://www.w3.org/2000/svg"><rect style="width:expression(alert(1))"/></svg>',
    'SMIL <animate> retargeting an event-handler attribute'
        => '<svg xmlns="http://www.w3.org/2000/svg"><rect>'
            . '<animate attributeName="onmouseover" from="0" to="alert(1)"/></rect></svg>',
    'external href/xlink:href reference'
        => '<svg xmlns="http://www.w3.org/2000/svg"><use xlink:href="http://evil.example/x.svg#y"/></svg>',
];

foreach ($dangerousFixtures as $label => $maliciousSvg) {
    $screenResult = screenSvgLogoContent($maliciousSvg);

    assertFalse(
        $screenResult['valid'],
        'screenSvgLogoContent() REJECTS: ' . $label
    );
    assertTrue(
        is_string($screenResult['error']) && $screenResult['error'] !== '',
        'screenSvgLogoContent() gives a non-empty rejection reason for: ' . $label
    );
}

// ==============================================================================
// ✅❌ Section 3: saveBase64LogoToTemp() - the ACTUAL F-024 fix (base64 path)
// ==============================================================================
// This is the write-path enforcement: a malicious SVG must NEVER reach disk
// (and therefore never reach overlayLogoOnSVG()'s embedded QR output).
// ==============================================================================

// ❌ A malicious base64 SVG logo (the exact shape the JSON API's
//    customization.logo_data / customization.logo accepts) must be rejected.
$maliciousSvgDataUri = 'data:image/svg+xml;base64,'
    . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
        . '<script>alert(document.cookie)</script></svg>');

$maliciousSvgTempPath = saveBase64LogoToTemp($maliciousSvgDataUri);

assertEquals(
    null,
    $maliciousSvgTempPath,
    'saveBase64LogoToTemp() REJECTS a base64 SVG logo containing <script>/onload (F-024 fix - the base64 path now screens SVG content, closing the parity gap with the multipart upload path)'
);

// ✅ A clean base64 SVG logo must still be ACCEPTED (no regression - SVG
//    logos remain usable via the API, screening-with-parity was chosen over
//    a blanket SVG ban).
$cleanSvgDataUri = 'data:image/svg+xml;base64,' . base64_encode($cleanSvg);
$cleanSvgTempPath = saveBase64LogoToTemp($cleanSvgDataUri);

assertTrue(
    is_string($cleanSvgTempPath) && is_file($cleanSvgTempPath),
    'saveBase64LogoToTemp() still ACCEPTS a clean, inert base64 SVG logo and writes it to a temp file'
);

if (is_string($cleanSvgTempPath)) {
    assertEquals(
        $cleanSvg,
        file_get_contents($cleanSvgTempPath),
        'saveBase64LogoToTemp() writes the exact decoded SVG bytes for a clean logo (no mangling)'
    );

    // 🧹 Clean up the temp file this test created (OS temp dir only).
    @unlink($cleanSvgTempPath);
}

// ✅ A genuine raster (PNG) logo is completely UNAFFECTED by the new SVG
//    screen - proves the fix is scoped to SVG only, per the task brief
//    ("keep raster logos unaffected"). Reuses the same tiny 1x1 transparent
//    PNG fixture test_account_ui.php uses for its own saveBase64LogoToTemp()
//    round-trip proof.
$tinyPngBase64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42'
    . 'YAAAAASUVORK5CYII=';
$pngTempPath = saveBase64LogoToTemp('data:image/png;base64,' . $tinyPngBase64);

assertTrue(
    is_string($pngTempPath) && is_file($pngTempPath),
    'saveBase64LogoToTemp() still accepts a genuine raster (PNG) logo unchanged (the SVG screen never runs for raster content)'
);

if (is_string($pngTempPath) && is_file($pngTempPath)) {
    @unlink($pngTempPath);
}

// ==============================================================================
// ✅❌ Section 4: screenBase64LogoDataUri() + rejectUnsafeBase64SvgLogo()
// ==============================================================================
// These are the PURE, no-disk-I/O helpers the JSON API handlers call BEFORE
// validateCustomization() to surface a distinct, actionable 422
// LOGO_SVG_REJECTED instead of a silent logo-less fallback.
// ==============================================================================

assertTrue(
    is_string(screenBase64LogoDataUri($maliciousSvgDataUri)),
    'screenBase64LogoDataUri() flags the malicious base64 SVG data URI with a non-null reason'
);

assertEquals(
    null,
    screenBase64LogoDataUri($cleanSvgDataUri),
    'screenBase64LogoDataUri() returns null (safe) for a clean base64 SVG data URI'
);

assertEquals(
    null,
    screenBase64LogoDataUri('data:image/png;base64,' . $tinyPngBase64),
    'screenBase64LogoDataUri() returns null (not applicable) for a raster PNG data URI - only SVG content is screened'
);

assertEquals(
    null,
    screenBase64LogoDataUri('not a data uri at all'),
    'screenBase64LogoDataUri() returns null for a string that is not a data: URI (leaves it to saveBase64LogoToTemp()\'s own decode-failure handling)'
);

// 📌 rejectUnsafeBase64SvgLogo() - the customization["logo_data"]-shaped entry point.
$maliciousViaLogoData = rejectUnsafeBase64SvgLogo(['logo_data' => $maliciousSvgDataUri]);
assertTrue(
    is_string($maliciousViaLogoData) && $maliciousViaLogoData !== '',
    'rejectUnsafeBase64SvgLogo() rejects a malicious SVG supplied via customization["logo_data"]'
);

// 📌 rejectUnsafeBase64SvgLogo() - the customization["logo"]-shaped entry point
// (a client MAY supply the data URI directly as "logo" without "logo_data" -
// getLogoPath() Case 2 accepts that shape too, so both must be screened).
$maliciousViaLogo = rejectUnsafeBase64SvgLogo(['logo' => $maliciousSvgDataUri]);
assertTrue(
    is_string($maliciousViaLogo) && $maliciousViaLogo !== '',
    'rejectUnsafeBase64SvgLogo() rejects a malicious SVG supplied directly via customization["logo"] (not just "logo_data")'
);

assertEquals(
    null,
    rejectUnsafeBase64SvgLogo(['logo_data' => $cleanSvgDataUri]),
    'rejectUnsafeBase64SvgLogo() returns null (accepted) for a clean SVG logo'
);

assertEquals(
    null,
    rejectUnsafeBase64SvgLogo(['logo' => 5]),
    'rejectUnsafeBase64SvgLogo() is a no-op for a numeric predefined-logo ID (not a string, never screened as SVG)'
);

assertEquals(
    null,
    rejectUnsafeBase64SvgLogo([]),
    'rejectUnsafeBase64SvgLogo() returns null when no logo/logo_data key is present at all'
);

// ==============================================================================
// ✅ Section 5: Static regression guards (source-text, no execution)
// ==============================================================================
// Mirrors test_account_ui.php's own "Section 4" convention - pins the exact
// wiring this fix depends on, so a future refactor cannot silently reintroduce
// the F-024 parity gap (e.g. by inlining a DIFFERENT pattern list in one path
// while the other still calls the shared helper).
// ==============================================================================

$validationSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_validation.php');
$qrLogoSource      = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_qr_logo.php');
$apiGenerateSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_generate.php');
$apiQrcodesSource  = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_api_qrcodes.php');

assertTrue(
    is_string($validationSource) && is_string($qrLogoSource)
        && is_string($apiGenerateSource) && is_string($apiQrcodesSource),
    'all four source files touched by the F-024 fix are readable for the static guards below'
);

// 🔐 The multipart upload path (validateImageUpload()) must call the SHARED
// screener rather than its own inline pattern list (the original code before
// this fix hard-coded 5 patterns directly in validateImageUpload() itself).
assertContains(
    'screenSvgLogoContent($svgContent)',
    (string) $validationSource,
    'validateImageUpload() (fn_validation.php) calls the SHARED screenSvgLogoContent() helper for its SVG branch'
);

// 🔐 The base64 write-path (saveBase64LogoToTemp()) must call the SAME
// shared screener - this IS the F-024 fix; its absence would mean the base64
// path is unscreened again.
assertContains(
    'screenSvgLogoContent($imageData)',
    (string) $qrLogoSource,
    'saveBase64LogoToTemp() (fn_qr_logo.php) calls the SAME shared screenSvgLogoContent() helper (F-024 parity)'
);

// 🔐 All three JSON API call sites that reach validateCustomization() must be
// wired to reject an unsafe base64 SVG logo with a distinct 422
// LOGO_SVG_REJECTED BEFORE validateCustomization() runs.
assertEquals(
    2,
    substr_count((string) $apiGenerateSource, "errorResponse(\$logoSvgRejection, 422, 'LOGO_SVG_REJECTED')"),
    'fn_api_generate.php wires the LOGO_SVG_REJECTED 422 into BOTH handleGenerate() and handlePreview()'
);

assertContains(
    "errorResponse(\$logoSvgRejection, 422, 'LOGO_SVG_REJECTED')",
    (string) $apiQrcodesSource,
    'fn_api_qrcodes.php\'s PATCH /qrcodes/{uuid} handler wires the SAME LOGO_SVG_REJECTED 422'
);

// 🔐 Every LOGO_SVG_REJECTED call site must run BEFORE validateCustomization()
// so a malicious logo never reaches the generic 400 INVALID_CUSTOMIZATION
// path (order-of-checks regression guard).
assertTrue(
    strpos((string) $apiGenerateSource, 'LOGO_SVG_REJECTED')
        < strpos((string) $apiGenerateSource, 'validateCustomization($customization)'),
    'fn_api_generate.php\'s LOGO_SVG_REJECTED check runs BEFORE the first validateCustomization() call (handleGenerate())'
);
