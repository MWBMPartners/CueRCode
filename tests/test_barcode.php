<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for Non-QR Barcode Generation (#69)
 * ==============================================================================
 *
 * Path: tests/test_barcode.php
 *
 * Exercises _functions/fn_barcode_generate.php - the non-QR 1D/2D barcode
 * generation engine added alongside the QR pipeline (#69):
 *
 *   - gtinCheckDigit()          - the universal EAN/UPC check-digit algorithm
 *   - upcEExpandToUpcA11()      - UPC-E -> UPC-A expansion (check-digit verification)
 *   - normalizeGtinLikeData()   - shared EAN-13/EAN-8/UPC-A strict validator
 *   - normalizeBarcodeData()    - the full per-symbology STRICT dispatcher
 *     (accept/reject cases for all 11 supported symbologies)
 *   - resolveBarcodeSymbology() - unknown-symbology rejection
 *   - renderBarcodeToOutput()   - real tc-lib-barcode SVG/PNG rendering for
 *     EVERY supported symbology (magic-byte/`<svg` assertions)
 *   - generateBarcode()/generateBarcodePreview() - the full pipeline, called
 *     with `save_file: false` (or via the preview path, which never touches
 *     the filesystem/DB) so this file stays DB-INDEPENDENT and does not write
 *     to disk, mirroring test_qr_logo.php's/test_bulk.php's conventions.
 *     saveQRCodeRecord() itself no-ops gracefully when dbQuery() does not
 *     exist (the CLI harness never loads db_connect.php), so `success` is
 *     still `true` - only the DB row is skipped, exactly like every other
 *     DB-independent generation test in this suite.
 *
 * Requires the REAL vendored tecnickcom/tc-lib-barcode + tc-lib-color source
 * trees under _libraries/ (gitignored, installed by CI's "Install vendored
 * libraries" step / a local `git clone`, mirroring chillerlan/php-qrcode's
 * existing test-time dependency) - skipped gracefully (recorded as a single
 * informational pass, not a hard failure) when the autoloader/library is
 * absent, exactly like test_core.php's $qrLibraryLoaded pattern for
 * chillerlan/php-qrcode.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (#69)
 * @see         tests/bootstrap.php  - requireFn() / requireLibraryAutoloader()
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_barcode_generate.php
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// fn_qr_generate.php MUST load first so generateBarcode()/generateBarcodePreview()
// find saveQRCodeRecord()/saveQRToFile()/updateQRCodeFilePath()/generateUUIDv4()
// already defined (function_exists checks) - mirrors the REAL production load
// order (loadQRGenerationFunctions(), fn_api_generate.php).
// ==============================================================================

requireFn('fn_qr_generate.php');
requireFn('fn_barcode_generate.php');

// 📚 tc-lib-barcode/tc-lib-color classes (Com\Tecnick\Barcode\Barcode etc.) are
// needed for renderBarcodeToOutput()/generateBarcode()/generateBarcodePreview().
// Loading the autoloader opens NO database connection - it only registers the
// class autoloader (same convention as test_core.php's chillerlan check).
// @see tests/bootstrap.php - requireLibraryAutoloader()
$barcodeLibraryLoaded = requireLibraryAutoloader() && class_exists('Com\\Tecnick\\Barcode\\Barcode');

// ==============================================================================
// ✅ Section 1: gtinCheckDigit() - the universal GTIN check-digit algorithm
// ==============================================================================
// Verified against known-good real-world GTIN examples.
// ==============================================================================

assertEquals(1, gtinCheckDigit('400638133393'), 'gtinCheckDigit: EAN-13 body "400638133393" -> check digit 1 (known-good product code)');
assertEquals(2, gtinCheckDigit('03600029145'), 'gtinCheckDigit: UPC-A body "03600029145" -> check digit 2 (known-good product code)');
assertEquals(0, gtinCheckDigit('0000000'), 'gtinCheckDigit: all-zero body -> check digit 0');

// ==============================================================================
// ✅ Section 2: upcEExpandToUpcA11() - UPC-E -> UPC-A(11) expansion
// ==============================================================================
// Exercises all 4 branches of the convertUpceToUpca()-mirroring logic
// (last digit 0-2, 3, 4, 5-9). The first case is a REAL, publicly documented
// UPC-E/UPC-A pair (Cheerios cereal: UPC-A 042100005264 <-> UPC-E 04252614)
// - an independent correctness check, not merely self-consistency.
// ==============================================================================

assertEquals('04210000526', upcEExpandToUpcA11('425261'), 'upcEExpandToUpcA11: last-digit-1 (0-2) branch - matches the real, publicly documented Cheerios UPC-E/UPC-A pair (UPC-A 042100005264 <-> UPC-E 04252614)');
assertEquals('01230000045', upcEExpandToUpcA11('123453'), 'upcEExpandToUpcA11: last-digit-3 branch');
assertEquals('01234000005', upcEExpandToUpcA11('123454'), 'upcEExpandToUpcA11: last-digit-4 branch');
assertEquals('01234500006', upcEExpandToUpcA11('123456'), 'upcEExpandToUpcA11: last-digit-6 (5-9) branch');

// ==============================================================================
// ✅ Section 3: normalizeGtinLikeData() - EAN-13/EAN-8/UPC-A shared validator
// ==============================================================================

// 📌 12-digit EAN-13 body (no check digit supplied) - computed and appended.
assertEquals('4006381333931', normalizeGtinLikeData('400638133393', 12, 'EAN-13'), 'normalizeGtinLikeData: 12-digit EAN-13 body gets the correct check digit appended');

// 📌 13-digit EAN-13 WITH a correct check digit - accepted unchanged.
assertEquals('4006381333931', normalizeGtinLikeData('4006381333931', 12, 'EAN-13'), 'normalizeGtinLikeData: 13-digit EAN-13 with a CORRECT check digit is accepted unchanged');

// ❌ 13-digit EAN-13 with a WRONG check digit - rejected.
assertThrows(
    function (): void {
        normalizeGtinLikeData('4006381333930', 12, 'EAN-13');
    },
    'normalizeGtinLikeData: 13-digit EAN-13 with an INCORRECT check digit is rejected',
    \InvalidArgumentException::class
);

// ❌ Non-digit characters - rejected.
assertThrows(
    function (): void {
        normalizeGtinLikeData('40063813339A', 12, 'EAN-13');
    },
    'normalizeGtinLikeData: non-digit characters are rejected',
    \InvalidArgumentException::class
);

// ❌ Wrong length (neither 12 nor 13 digits) - rejected.
assertThrows(
    function (): void {
        normalizeGtinLikeData('123', 12, 'EAN-13');
    },
    'normalizeGtinLikeData: wrong-length input is rejected',
    \InvalidArgumentException::class
);

// ==============================================================================
// ✅ Section 4: resolveBarcodeSymbology() - unknown symbology rejection
// ==============================================================================

$resolvedCode128 = resolveBarcodeSymbology('CODE128');
assertEquals('C128', $resolvedCode128['tclbType'], 'resolveBarcodeSymbology: case-insensitive lookup resolves "CODE128" to tclbType C128');
assertEquals('1d', $resolvedCode128['category'], 'resolveBarcodeSymbology: code128 category is 1d');

$resolvedDatamatrix = resolveBarcodeSymbology('datamatrix');
assertEquals('2d', $resolvedDatamatrix['category'], 'resolveBarcodeSymbology: datamatrix category is 2d');

assertThrows(
    function (): void {
        resolveBarcodeSymbology('not_a_real_symbology');
    },
    'resolveBarcodeSymbology: an unknown symbology key is rejected',
    \InvalidArgumentException::class
);

assertEquals(11, count(barcodeSymbologyList()), 'barcodeSymbologyList: exactly 11 supported symbologies');

// ==============================================================================
// ✅ Section 5: normalizeBarcodeData() - STRICT per-symbology ACCEPT cases
// ==============================================================================
// One genuinely valid fixture per symbology - also reused by Section 7's
// real-rendering pass below.
// ==============================================================================

$validFixtures = [
    'code128'    => 'HELLO123',
    'code39'     => 'CODE 39-TEST',
    'ean13'      => '400638133393',    // 12 digits -> check digit computed
    'ean8'       => '9638507',         // 7 digits -> check digit computed
    'upca'       => '03600029145',     // 11 digits -> check digit computed
    'upce'       => '425261',          // 6-digit compact form
    'itf'        => '12345678',        // even length
    'codabar'    => '123-456:7',       // digits + allowed symbols (start/stop A-D guards are added automatically by the renderer, NOT supplied here)
    'datamatrix' => 'https://example.com/asset/42',
    'pdf417'     => 'CueRCode PDF417 test payload',
    'aztec'      => 'CueRCode Aztec test payload',
];

assertEquals(11, count($validFixtures), 'test fixture map covers all 11 symbologies (sanity check on the test itself)');

foreach ($validFixtures as $symbologyKey => $fixtureData) {
    $normalized = normalizeBarcodeData($symbologyKey, $fixtureData);
    assertTrue(
        is_string($normalized) && $normalized !== '',
        'normalizeBarcodeData: valid fixture for "' . $symbologyKey . '" is accepted and returns a non-empty string'
    );
}

// ==============================================================================
// ❌ Section 6: normalizeBarcodeData() - STRICT per-symbology REJECT cases
// ==============================================================================
// One deliberately INVALID payload per symbology, chosen to exercise the
// symbology's specific validation rule (checksum / charset / even-length).
// ==============================================================================

$invalidFixtures = [
    'code128'    => ['reason' => 'empty string',                'data' => ''],
    'code39'     => ['reason' => 'lowercase letters (invalid charset)', 'data' => 'lowercase'],
    'ean13'      => ['reason' => 'wrong check digit',            'data' => '4006381333930'],
    'ean8'       => ['reason' => 'wrong length (6 digits)',      'data' => '123456'],
    'upca'       => ['reason' => 'wrong check digit',            'data' => '036000291459'],
    'upce'       => ['reason' => 'wrong length (5 digits)',      'data' => '12345'],
    'itf'        => ['reason' => 'odd number of digits',         'data' => '1234567'],
    'codabar'    => ['reason' => 'invalid character (letter Z is not in the Codabar payload charset)', 'data' => 'Z12345Z'],
    'datamatrix' => ['reason' => 'empty string',                 'data' => ''],
    'pdf417'     => ['reason' => 'empty string',                 'data' => ''],
    'aztec'      => ['reason' => 'empty string',                 'data' => ''],
];

assertEquals(11, count($invalidFixtures), 'invalid-fixture map covers all 11 symbologies (sanity check on the test itself)');

foreach ($invalidFixtures as $symbologyKey => $invalidFixture) {
    assertThrows(
        function () use ($symbologyKey, $invalidFixture): void {
            normalizeBarcodeData($symbologyKey, $invalidFixture['data']);
        },
        'normalizeBarcodeData: "' . $symbologyKey . '" rejects invalid data (' . $invalidFixture['reason'] . ')',
        \InvalidArgumentException::class
    );
}

// ❌ A blanket over-length payload is rejected regardless of symbology.
assertThrows(
    function (): void {
        normalizeBarcodeData('datamatrix', str_repeat('A', 3000));
    },
    'normalizeBarcodeData: a payload over CUERCODE_BARCODE_MAX_DATA_LEN is rejected',
    \InvalidArgumentException::class
);

// ❌ UPC-E: an 8-digit form with an incorrect check digit is rejected.
assertThrows(
    function (): void {
        normalizeBarcodeData('upce', '04252619');
    },
    'normalizeBarcodeData: UPC-E 8-digit form with an INCORRECT check digit is rejected',
    \InvalidArgumentException::class
);

// ✅ UPC-E: an 8-digit form with the CORRECT check digit is accepted and
// normalised down to the 6-digit compact form (what the renderer consumes).
$upcE8Digit          = '0' . '425261' . (string) gtinCheckDigit(upcEExpandToUpcA11('425261'));
$normalizedUpcECompact = normalizeBarcodeData('upce', $upcE8Digit);
assertEquals('425261', $normalizedUpcECompact, 'normalizeBarcodeData: UPC-E 8-digit form with a CORRECT check digit normalises to its 6-digit compact form');

// ==============================================================================
// 🖨️ Section 7: renderBarcodeToOutput() - REAL rendering, every symbology,
// both formats (requires the vendored tc-lib-barcode library)
// ==============================================================================

if ($barcodeLibraryLoaded) {
    foreach ($validFixtures as $symbologyKey => $fixtureData) {
        $normalized = normalizeBarcodeData($symbologyKey, $fixtureData);

        // 🖼️ SVG
        $svgRendered = renderBarcodeToOutput($symbologyKey, $normalized, 'svg', []);
        assertEquals('image/svg+xml', $svgRendered['mimeType'], 'renderBarcodeToOutput: "' . $symbologyKey . '" SVG mimeType is image/svg+xml');
        assertTrue(
            str_contains($svgRendered['output'], '<svg'),
            'renderBarcodeToOutput: "' . $symbologyKey . '" SVG output contains a <svg element'
        );
        assertTrue(strlen($svgRendered['output']) > 0, 'renderBarcodeToOutput: "' . $symbologyKey . '" SVG output is non-empty');

        // 🖼️ PNG - assert the 8-byte PNG magic signature.
        $pngRendered = renderBarcodeToOutput($symbologyKey, $normalized, 'png', []);
        assertEquals('image/png', $pngRendered['mimeType'], 'renderBarcodeToOutput: "' . $symbologyKey . '" PNG mimeType is image/png');
        $pngMagicBytes = "\x89PNG\x0D\x0A\x1A\x0A";
        assertEquals(
            $pngMagicBytes,
            substr($pngRendered['output'], 0, 8),
            'renderBarcodeToOutput: "' . $symbologyKey . '" PNG output starts with the correct 8-byte PNG magic signature'
        );
    }

    // ==============================================================================
    // 🖨️ Section 8: show_text option - SVG gains a <text> element, PNG grows taller
    // ==============================================================================

    $code128Normalized = normalizeBarcodeData('code128', 'HELLO123');

    $svgNoText   = renderBarcodeToOutput('code128', $code128Normalized, 'svg', ['show_text' => false]);
    $svgWithText = renderBarcodeToOutput('code128', $code128Normalized, 'svg', ['show_text' => true]);
    assertTrue(!str_contains($svgNoText['output'], '<text'), 'renderBarcodeToOutput: show_text=false SVG has no <text> element');
    assertTrue(str_contains($svgWithText['output'], '<text'), 'renderBarcodeToOutput: show_text=true SVG gains a <text> element');
    assertContains('HELLO123', $svgWithText['output'], 'renderBarcodeToOutput: show_text=true SVG caption contains the encoded payload');

    $pngNoText   = renderBarcodeToOutput('code128', $code128Normalized, 'png', ['show_text' => false]);
    $pngWithText = renderBarcodeToOutput('code128', $code128Normalized, 'png', ['show_text' => true]);
    $sizeNoText   = getimagesizefromstring($pngNoText['output']);
    $sizeWithText = getimagesizefromstring($pngWithText['output']);
    assertTrue(
        $sizeWithText[1] > $sizeNoText[1],
        'renderBarcodeToOutput: show_text=true PNG is TALLER than show_text=false (a text band was appended)'
    );
    assertEquals(
        $sizeWithText[0],
        $sizeNoText[0],
        'renderBarcodeToOutput: show_text=true PNG has the SAME width as show_text=false (only height grows)'
    );

    // 📌 show_text is a documented no-op for 2D symbologies - SVG output is
    // identical whether requested or not (renderBarcodeToOutput() only wraps
    // for 1D categories).
    $datamatrixNormalized = normalizeBarcodeData('datamatrix', 'https://example.com/asset/42');
    $dmSvgNoText   = renderBarcodeToOutput('datamatrix', $datamatrixNormalized, 'svg', ['show_text' => false]);
    $dmSvgWithText = renderBarcodeToOutput('datamatrix', $datamatrixNormalized, 'svg', ['show_text' => true]);
    assertEquals(
        $dmSvgNoText['output'],
        $dmSvgWithText['output'],
        'renderBarcodeToOutput: show_text is a documented no-op for a 2D symbology (datamatrix)'
    );

    // ==============================================================================
    // 🎯 Section 9: generateBarcode()/generateBarcodePreview() - full pipeline,
    // DB-independent (save_file: false / preview never persists)
    // ==============================================================================

    $fullResult = generateBarcode('code128', 'HELLO123', ['format' => 'svg', 'save_file' => false]);
    assertTrue($fullResult['success'], 'generateBarcode: a valid code128 request succeeds end-to-end (DB-independent - saveQRCodeRecord() no-ops without dbQuery())');
    assertTrue($fullResult['uuid'] !== '', 'generateBarcode: a real UUID v4 is generated');
    assertMatches(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $fullResult['uuid'],
        'generateBarcode: the generated uuid is a well-formed UUID v4'
    );
    assertEquals('image/svg+xml', $fullResult['mimeType'], 'generateBarcode: mimeType matches the requested svg format');
    assertTrue(str_contains($fullResult['data'], '<svg'), 'generateBarcode: returned data contains the rendered SVG markup');
    assertEquals('HELLO123', $fullResult['payload'], 'generateBarcode: payload echoes the normalised barcode value');

    $pngResult = generateBarcode('ean13', '400638133393', ['format' => 'png', 'save_file' => false]);
    assertTrue($pngResult['success'], 'generateBarcode: a valid ean13 PNG request succeeds');
    assertMatches(
        '#^data:image/png;base64,#',
        $pngResult['data'],
        'generateBarcode: PNG output is returned as a base64 data URI'
    );

    $failedResult = generateBarcode('ean13', 'not-a-valid-ean', []);
    assertFalse($failedResult['success'], 'generateBarcode: an invalid payload fails cleanly (no exception escapes)');
    assertTrue($failedResult['error'] !== null && $failedResult['error'] !== '', 'generateBarcode: a failed result carries a human-readable error message');

    $unknownSymbologyResult = generateBarcode('not_a_symbology', 'anything', []);
    assertFalse($unknownSymbologyResult['success'], 'generateBarcode: an unknown symbology fails cleanly');

    $previewResult = generateBarcodePreview('aztec', 'preview payload', ['format' => 'png']);
    assertTrue($previewResult['success'], 'generateBarcodePreview: succeeds regardless of a requested png format (previews are always SVG)');
    assertEquals('', $previewResult['uuid'], 'generateBarcodePreview: never assigns a uuid (not persisted)');
    assertEquals('image/svg+xml', $previewResult['mimeType'], 'generateBarcodePreview: ALWAYS renders SVG even when format=png was requested');
    assertTrue(str_contains($previewResult['data'], '<svg'), 'generateBarcodePreview: returned data contains SVG markup');
} else {
    // 🟡 Vendored tc-lib-barcode not present in this environment - record one
    // informational pass so the suite does not silently show "0 tests" for
    // this file (mirrors test_core.php's chillerlan-absent posture).
    assertTrue(
        true,
        'tc-lib-barcode library not vendored in this environment - real-rendering assertions skipped (Sections 7-9)'
    );
}
