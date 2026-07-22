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

// ==============================================================================
// 🖼️ PART 2 - Issue #65: "predefined centre-logos are dead at runtime"
// ==============================================================================
// Two independent bugs, both fixed WITHOUT any DB connection needed by the
// tests below (mirroring this whole file's/harness's DB-independent
// convention - dbQuery() is never defined anywhere in this test suite):
//
//   BUG 1 (path mismatch) - getLogoPathFromDatabase() only ever checked
//   CUERCODE_UPLOADS_DIR . 'logos' for a predefined logo's stored filename,
//   but every predefined logo (seed_data.sql's 26-row tblPredefinedLogos
//   INSERT) actually ships as a static asset under
//   public_html/assets/img/logos/ - so the containment guard rejected every
//   lookup and the logo was silently dropped. FIXED by extracting the
//   resolution algorithm into resolvePredefinedLogoPath() (fn_qr_logo.php)
//   and adding the real assets directory as an allowed base. Because that
//   extraction is a PURE, DB-free function, Section 6 below tests it
//   directly - this IS the same exact logic getLogoPathFromDatabase() now
//   delegates to (see that function's own doc comment), so this is a
//   faithful, direct proof of the fix, not a workaround for the DB-free
//   harness.
//
//   BUG 2 (GD cannot rasterise SVG) - loadLogoImage() only handled
//   PNG/JPEG/GIF/WEBP; all 26 predefined logos are SVG, so raster
//   (PNG/JPG/GIF/WEBP) QR output silently dropped every predefined logo even
//   after bug 1 is fixed (the SVG-OUTPUT path, overlayLogoOnSVG(), was
//   already working and is unaffected/unchanged by either fix). FIXED with a
//   PNG-twin lookup + optional Imagick rasterisation + a NON-SILENT logged
//   warning as the last resort (never a silent drop with no trace). Sections
//   8-9 below cover this.
// ==============================================================================

// ==============================================================================
// ✅ Section 6: resolvePredefinedLogoPath() - Issue #65 bug 1 fix (path resolution)
// ==============================================================================

// 📌 Every predefined logo filename actually seeded by
// _includes/sql/seed_data.sql's tblPredefinedLogos INSERT (26 rows total -
// kept as an explicit, literal list rather than re-parsing the SQL file, so
// this test pins the REAL seeded set and a future edit to either list is
// caught by a mismatch). Order does not matter for this assertion loop.
$seededPredefinedLogoFilenames = [
    'facebook.svg', 'instagram.svg', 'twitter.svg', 'youtube.svg', 'linkedin.svg',
    'tiktok.svg', 'pinterest.svg', 'snapchat.svg',
    'whatsapp.svg', 'telegram.svg', 'skype.svg',
    'paypal.svg', 'bitcoin.svg',
    'apple.svg', 'android.svg', 'appstore.svg', 'playstore.svg',
    'globe.svg', 'email.svg', 'phone.svg', 'location.svg', 'calendar.svg',
    'wifi.svg', 'document.svg', 'camera.svg', 'qrcode.svg',
];

assertEquals(
    26,
    count($seededPredefinedLogoFilenames),
    'sanity check: exactly 26 predefined logo filenames are listed here, matching seed_data.sql'
);

// 📌 The real assets directory, resolved the SAME way resolvePredefinedLogoPath()
// resolves it, so the assertions below compare against the actual expected
// location rather than a hardcoded/duplicated path string.
$predefinedLogosAssetsDir = realpath(
    CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR
    . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logos'
);

assertTrue(
    is_string($predefinedLogosAssetsDir) && is_dir($predefinedLogosAssetsDir),
    'sanity check: the real public_html/assets/img/logos/ directory exists on disk'
);

// ✅ THE #65 BUG 1 FIX, PROVEN: every seeded predefined logo filename now
// resolves to an existing, real file living under the assets directory
// (not null - this was the exact silent failure #65 reported).
foreach ($seededPredefinedLogoFilenames as $seededFilename) {
    $resolved = resolvePredefinedLogoPath($seededFilename);

    assertTrue(
        is_string($resolved),
        'resolvePredefinedLogoPath(\'' . $seededFilename . '\') resolves to a real path, not null (#65 fix)'
    );

    if (is_string($resolved)) {
        assertTrue(
            is_string($predefinedLogosAssetsDir) && str_starts_with($resolved, $predefinedLogosAssetsDir . DIRECTORY_SEPARATOR),
            'resolvePredefinedLogoPath(\'' . $seededFilename . '\') resolves UNDER the real assets/img/logos directory'
        );
        assertTrue(
            file_exists($resolved) && is_file($resolved),
            'resolvePredefinedLogoPath(\'' . $seededFilename . '\') resolves to a file that genuinely exists on disk'
        );
        assertEquals(
            $seededFilename,
            basename($resolved),
            'resolvePredefinedLogoPath(\'' . $seededFilename . '\') preserves the exact requested filename'
        );
    }
}

// ❌ Edge cases: still correctly return null (never a fatal, never an escape).
assertEquals(
    null,
    resolvePredefinedLogoPath(''),
    'resolvePredefinedLogoPath() returns null for an empty filename'
);

assertEquals(
    null,
    resolvePredefinedLogoPath('this-logo-does-not-exist.svg'),
    'resolvePredefinedLogoPath() returns null for a well-formed but nonexistent filename'
);

// 🔒 Regression guard: the B-051/F-017 containment guard still fully applies
// to the NEW assets-directory base too - a traversal payload must NOT be
// able to escape it, even though the target it points at (/etc/passwd)
// genuinely exists on disk. The '../' depth is computed dynamically from the
// REAL resolved assets directory so this assertion is portable across
// checkout locations rather than hardcoding a directory depth.
if (is_string($predefinedLogosAssetsDir) && file_exists('/etc/passwd')) {
    $traversalDepth   = substr_count(rtrim($predefinedLogosAssetsDir, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    $traversalPayload = str_repeat('..' . DIRECTORY_SEPARATOR, $traversalDepth) . 'etc' . DIRECTORY_SEPARATOR . 'passwd';

    assertEquals(
        null,
        resolvePredefinedLogoPath($traversalPayload),
        'resolvePredefinedLogoPath() BLOCKS a path-traversal filename that would otherwise escape to an existing file outside every allowed base (B-051/F-017 containment preserved)'
    );
}

// ==============================================================================
// ✅ Section 7: getLogoPathFromDatabase() - DB-unavailable fail-safe (unchanged)
// ==============================================================================
// This test harness NEVER defines dbQuery() (mirrors test_auth.php/
// test_features.php/test_pricing.php's own sanity checks) - proving the #65
// fix did not accidentally introduce a fatal when the DB layer is absent.
// The actual DB-lookup row-fetch itself remains DB-dependent and out of
// scope for this harness, exactly like every other dbQuery()-touching
// function in this suite; resolvePredefinedLogoPath() (Section 6, above) is
// what proves the fix at the unit level.
// ==============================================================================

assertFalse(function_exists('dbQuery'), 'sanity check: dbQuery() is genuinely undefined in this harness');

assertEquals(
    null,
    getLogoPathFromDatabase(1),
    'getLogoPathFromDatabase() gracefully returns null (not a fatal) when dbQuery() is unavailable'
);

// ==============================================================================
// ✅ Section 8: overlayLogoOnSVG() - Issue #65 fix proven end-to-end (SVG output)
// ==============================================================================
// This is "the path that works today" per the #65 brief: overlayLogoOnSVG()
// never touches GD, so it is unaffected by bug 2 - it is PURELY bug 1 (path
// resolution) standing between a predefined logo and a working SVG QR
// output. Resolving via resolvePredefinedLogoPath() (the real #65 fix) and
// feeding the result straight into overlayLogoOnSVG() proves the fix
// end-to-end on this already-working overlay path.
// ==============================================================================

$facebookLogoPath = resolvePredefinedLogoPath('facebook.svg');

assertTrue(
    is_string($facebookLogoPath),
    'precondition: facebook.svg resolves via resolvePredefinedLogoPath() for the Section 8 end-to-end proof'
);

if (is_string($facebookLogoPath)) {
    // 📌 A minimal, self-contained fake "QR code" SVG - the real QR renderer
    //    (chillerlan\QRCode) is not needed to prove the overlay step itself.
    $baseQrSvg = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
        . '<rect width="200" height="200" fill="#ffffff"/>'
        . '<rect x="10" y="10" width="20" height="20" fill="#000000"/>'
        . '</svg>';

    $svgWithPredefinedLogo = overlayLogoOnSVG($baseQrSvg, $facebookLogoPath, ['size_pct' => 20]);

    // ✅ THE #65 FIX, PROVEN END-TO-END: the output actually CHANGED (the
    // logo was embedded) rather than being silently dropped/unchanged.
    assertTrue(
        $svgWithPredefinedLogo !== $baseQrSvg,
        'overlayLogoOnSVG() with a resolved predefined logo produces output that DIFFERS from the no-logo baseline (#65 fix, end-to-end)'
    );

    assertContains(
        '<image',
        $svgWithPredefinedLogo,
        'overlayLogoOnSVG() output embeds an <image> element for the predefined logo'
    );

    assertContains(
        'data:image/svg+xml',
        $svgWithPredefinedLogo,
        'overlayLogoOnSVG() output embeds the predefined logo as an image/svg+xml data URI (it is itself an SVG)'
    );

    // 📌 Round-trip proof: the embedded base64 payload decodes back to the
    //    EXACT source SVG bytes on disk (no mangling in transit).
    if (preg_match('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)"/', $svgWithPredefinedLogo, $embeddedMatch) === 1) {
        assertEquals(
            file_get_contents($facebookLogoPath),
            base64_decode($embeddedMatch[1], true),
            'overlayLogoOnSVG() embeds the EXACT source logo bytes (byte-for-byte round trip)'
        );
    } else {
        assertTrue(false, 'could not extract the embedded base64 SVG data URI from overlayLogoOnSVG() output for the round-trip check');
    }
}

// ==============================================================================
// ✅❌ Section 9: loadLogoImage() - Issue #65 bug 2 fix (raster SVG handling)
// ==============================================================================
// Uses SELF-CONTAINED fixtures written to the OS temp dir (never the real
// repo assets tree) so these assertions are independent of whether PNG twins
// have (or have not) been generated for the real predefined logos by
// tools/render_logo_pngs.php on THIS machine.
// ==============================================================================

// 📌 A scratch fixture directory under the OS temp dir, cleaned up at the end
//    of this section.
$logoFixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_logo_fixtures_' . uniqid('', true);
$fixtureMkdirOk = mkdir($logoFixtureDir, 0755, true);

assertTrue($fixtureMkdirOk, 'precondition: scratch fixture directory for Section 9 was created');

if ($fixtureMkdirOk) {
    // --------------------------------------------------------------------
    // ✅ 9a. PNG-twin priority: an SVG WITH a same-basename PNG twin present
    //     loads successfully via the twin, with ZERO Imagick dependency.
    // --------------------------------------------------------------------
    $twinSvgPath = $logoFixtureDir . DIRECTORY_SEPARATOR . 'icon-with-twin.svg';
    $twinPngPath = $logoFixtureDir . DIRECTORY_SEPARATOR . 'icon-with-twin.png';

    file_put_contents(
        $twinSvgPath,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<rect width="10" height="10" fill="#123456"/></svg>'
    );

    // 📌 A tiny, genuine 6x9 GD-rendered PNG (dimensions chosen to be easily
    //    distinguishable from any other fixture size in this section).
    $twinCanvas = imagecreatetruecolor(6, 9);
    imagepng($twinCanvas, $twinPngPath);
    imagedestroy($twinCanvas);

    $loadedViaTwin = loadLogoImage($twinSvgPath);

    assertTrue(
        $loadedViaTwin instanceof \GdImage,
        'loadLogoImage() on an SVG WITH a PNG twin present returns a loaded GdImage (PNG-twin fallback, #65 fix)'
    );

    if ($loadedViaTwin instanceof \GdImage) {
        assertEquals(6, imagesx($loadedViaTwin), 'loadLogoImage() PNG-twin fallback loads the TWIN\'s pixel width (6px), proving the twin (not a broken SVG decode) was used');
        assertEquals(9, imagesy($loadedViaTwin), 'loadLogoImage() PNG-twin fallback loads the TWIN\'s pixel height (9px)');
        imagedestroy($loadedViaTwin);
    }

    // --------------------------------------------------------------------
    // ❌✅ 9b. NO PNG twin + NO Imagick -> NON-SILENT null, never a broken
    //     image and never a fatal. This is the exact "do not silently drop"
    //     requirement from the #65 fix brief. Guarded so this environment's
    //     genuine "no imagick installed" state is what is actually
    //     exercised, while still degrading sensibly (not failing outright)
    //     on some future machine that DOES have imagick loaded.
    // --------------------------------------------------------------------
    $noTwinSvgPath = $logoFixtureDir . DIRECTORY_SEPARATOR . 'icon-no-twin.svg';
    file_put_contents(
        $noTwinSvgPath,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<circle cx="5" cy="5" r="5" fill="#abcdef"/></svg>'
    );

    if (!extension_loaded('imagick')) {
        // ✅ This is the branch this repository's own dev/test sandbox
        //    genuinely exercises (no rasteriser installed here by design).
        assertEquals(
            null,
            loadLogoImage($noTwinSvgPath),
            'loadLogoImage() returns null (NON-SILENT - a logged warning fires, not a broken image) for an SVG with no PNG twin and no Imagick extension (#65 fix)'
        );
    } else {
        // 📌 Imagick IS loaded — but whether it can actually rasterise SVG
        //    depends on the ImageMagick build + policy.xml. The SVG coder is
        //    DISABLED by default on many distros (incl. GitHub's runners, for
        //    security), so the branch legitimately yields EITHER a valid
        //    GdImage (SVG supported) OR a NON-SILENT null (SVG blocked) — but
        //    never a broken image, a bare false, or a fatal. Accept both real
        //    outcomes and forbid anything else. (We deliberately do NOT assert
        //    successful rasterisation, because a hardened Imagick policy makes
        //    that environment-dependent — the #65 contract is only that the
        //    logo path degrades gracefully, never silently or destructively.)
        $imagickLogoResult = loadLogoImage($noTwinSvgPath);
        assertTrue(
            ($imagickLogoResult instanceof \GdImage) || ($imagickLogoResult === null),
            'loadLogoImage() with Imagick loaded returns a valid GdImage (SVG supported) OR a NON-SILENT null (SVG blocked by build/policy) — never a broken image (#65 fix, Imagick branch)'
        );
        if ($imagickLogoResult instanceof \GdImage) {
            imagedestroy($imagickLogoResult);
        }
    }

    // --------------------------------------------------------------------
    // ✅ 9c. The QR code itself STAYS VALID when the logo overlay is skipped
    //     - overlayLogoOnRaster() must still return a well-formed, decodable
    //     PNG, never a corrupted/partial image, when loadLogoImage()
    //     returns null for the reason above.
    // --------------------------------------------------------------------
    if (!extension_loaded('imagick')) {
        $fakeQrCanvas = imagecreatetruecolor(50, 50);
        $qrWhite      = imagecolorallocate($fakeQrCanvas, 255, 255, 255);
        imagefilledrectangle($fakeQrCanvas, 0, 0, 49, 49, $qrWhite);
        ob_start();
        imagepng($fakeQrCanvas);
        $fakeQrPngBytes = ob_get_clean();
        imagedestroy($fakeQrCanvas);

        $qrResultWithSkippedLogo = overlayLogoOnRaster(
            (string) $fakeQrPngBytes,
            $noTwinSvgPath,
            ['output_format' => 'png']
        );

        $decodedResultDimensions = getimagesizefromstring($qrResultWithSkippedLogo);

        assertTrue(
            $decodedResultDimensions !== false,
            'overlayLogoOnRaster() still returns a valid, decodable PNG when the SVG logo overlay is skipped (QR stays valid, #65 fix)'
        );

        if ($decodedResultDimensions !== false) {
            assertEquals(50, $decodedResultDimensions[0], 'the QR code\'s own dimensions are preserved unchanged when the logo overlay is skipped');
            assertEquals(50, $decodedResultDimensions[1], 'the QR code\'s own dimensions are preserved unchanged when the logo overlay is skipped (height)');
        }
    }

    // 🧹 Clean up every fixture file + the scratch directory this section created.
    @unlink($twinSvgPath);
    @unlink($twinPngPath);
    @unlink($noTwinSvgPath);
    @rmdir($logoFixtureDir);
}

// ==============================================================================
// ✅ Section 10: Static regression guards (source-text, no execution) - #65
// ==============================================================================
// Mirrors Section 5's own convention - pins the exact wiring the #65 fix
// depends on, so a future refactor cannot silently reintroduce either bug.
// $qrLogoSource was already loaded + confirmed readable in Section 5, above.
// ==============================================================================

// 🔐 Bug 1 fix: the predefined-logo resolver must check the REAL assets
// directory, not just uploads/logos. The production source spreads this
// concatenation across several lines (house style), so the check tolerates
// whitespace/newlines between each segment rather than requiring one exact
// single-line string.
assertMatches(
    "/'public_html'\s*\.\s*DIRECTORY_SEPARATOR\s*.*?'assets'\s*\.\s*DIRECTORY_SEPARATOR\s*.*?'img'\s*\.\s*DIRECTORY_SEPARATOR\s*.*?'logos'/s",
    (string) $qrLogoSource,
    'resolvePredefinedLogoPath() (fn_qr_logo.php) resolves against the real public_html/assets/img/logos directory (#65 bug 1 fix wiring)'
);

assertContains(
    'function getLogoPathFromDatabase(int $logoId): ?string',
    (string) $qrLogoSource,
    'getLogoPathFromDatabase() still exists with its original signature (no breaking API change)'
);

// 🔐 Bug 2 fix: loadLogoImage() must check for a PNG twin AND guard Imagick
// behind extension_loaded(), AND must log (not silently swallow) the
// no-fallback-available case.
assertContains(
    '.png',
    (string) $qrLogoSource,
    'loadLogoImage() (fn_qr_logo.php) references a .png PNG-twin path (#65 bug 2 fix wiring)'
);

assertContains(
    "extension_loaded('imagick')",
    (string) $qrLogoSource,
    'loadLogoImage() (fn_qr_logo.php) guards its Imagick fallback behind extension_loaded(\'imagick\') (never a hard dependency)'
);

assertContains(
    'Raster SVG logo overlay skipped: no PNG twin and no Imagick extension',
    (string) $qrLogoSource,
    'loadLogoImage() logs a clear, NON-SILENT warning when neither fallback is available (#65 bug 2 fix - no more silent drop)'
);

// 🔐 overlayLogoOnSVG() itself must remain completely untouched by either
// fix (it never needed one) - confirms the SVG-output path's own function
// signature is unchanged.
assertContains(
    'function overlayLogoOnSVG(string $svgContent, string $logoPath, array $config = []): string',
    (string) $qrLogoSource,
    'overlayLogoOnSVG() signature is unchanged by the #65 fix (it was already working, untouched)'
);
