<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for Braille/Tactile/Avery PDF (E5 / #49)
 * ==============================================================================
 *
 * Path: tests/test_braille.php
 *
 * Covers the "Braille-caption & tactile-frame print templates (FPDF) + Avery
 * label sheets" feature (PROJECT_ROADMAP.md - E5 DRAFT ISSUE (b), GitHub #49):
 *
 *   - brailleTransliterateGrade1()   (fn_qr_accessible_pdf.php) - the Grade-1
 *                                     (uncontracted) transliteration table:
 *                                     letters, digits (+ number sign), a
 *                                     capital letter, punctuation, and the
 *                                     documented "unsupported char -> blank
 *                                     cell" behaviour. Deterministic dot sets.
 *   - brailleCellDotPositionsMm()    (fn_qr_accessible_pdf.php) - the per-dot
 *                                     (x,y) geometry within a cell.
 *   - brailleLineWidthMm()           (fn_qr_accessible_pdf.php) - total width
 *                                     spanned by N cells.
 *   - circleStripHalfWidthMm()       (fn_qr_accessible_pdf.php) - the pure
 *                                     circle-equation maths behind the
 *                                     strip-rasterised "filled dot" helper.
 *   - pdfLayoutOptions() / normalisePdfLayout() (fn_qr_accessible_pdf.php) -
 *                                     the pdf_layout enum.
 *   - averySheetSpec()               (fn_qr_accessible_pdf.php) - Avery 5160 /
 *                                     L7160 sheet geometry.
 *   - accessiblePdfTruncateCaption() / deriveAccessiblePdfCaptionText()
 *                                     (fn_qr_accessible_pdf.php) - caption
 *                                     derivation/truncation logic.
 *   - buildCustomizationFromRequest() (fn_api_generate.php) - accepts/
 *                                     allowlists the new 'pdf_layout' param.
 *   - bulkRequestWantsAveryLayout()   (fn_api_bulk.php) - detects when a bulk
 *                                     request's SHARED customisation asks for
 *                                     an Avery-sheet PDF instead of the
 *                                     default ZIP.
 *   - generateQRCode() end-to-end (fn_qr_generate.php) - run in SUBPROCESSES
 *                                     (via test_accessibility.php's
 *                                     runAccessibilitySnippet() helper - see
 *                                     the note below) because the FPDF
 *                                     composition needs the chillerlan/FPDF
 *                                     library autoloader, which this
 *                                     DB-independent harness only loads
 *                                     opt-in per-snippet:
 *                                       (1) pdf_layout='standard' (absent vs
 *                                           explicit) produces BYTE-IDENTICAL
 *                                           output - the parity guarantee.
 *                                       (2) 'accessible_caption'/'tactile'/
 *                                           'avery_5160' each produce a
 *                                           non-trivial, valid ("%PDF"-
 *                                           prefixed) PDF. Full byte-level
 *                                           PDF assertions are impractical
 *                                           (FPDF embeds a CreationDate
 *                                           timestamp), so only the
 *                                           %PDF-prefix + minimum-size checks
 *                                           are asserted, per this task's
 *                                           documented scope.
 *
 * 🔗 Reuses runAccessibilitySnippet() from test_accessibility.php rather than
 *    redefining an identical subprocess helper. This relies on tests/run.php's
 *    documented alphabetical file-discovery order ('test_accessibility.php'
 *    sorts before 'test_braille.php', so the helper is already defined) - the
 *    exact same convention test_accessibility.php itself documents relying on
 *    for test_core.php's sibling helper.
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E5 accessibility cycle, #49)
 * @see         tests/bootstrap.php          - requireFn() / requireLibraryAutoloader()
 * @see         tests/lib/assert.php         - the assert* functions used below
 * @see         tests/test_accessibility.php - runAccessibilitySnippet() (reused here)
 * @see         web/CueRCode.net/_functions/fn_qr_accessible_pdf.php
 * @see         web/CueRCode.net/_functions/fn_api_generate.php - buildCustomizationFromRequest()
 * @see         web/CueRCode.net/_functions/fn_api_bulk.php     - bulkRequestWantsAveryLayout()
 * @see         PROJECT_ROADMAP.md - E5 "Accessibility QR systems", DRAFT ISSUE (b)
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// fn_qr_accessible_pdf.php's top-level code only defines functions/constants
// (no class-extends, no eager class instantiation), so it is safe to load
// WITHOUT the chillerlan/FPDF library autoloader for the PURE function tests
// below. Only the subprocess snippets that actually generate a PDF need
// requireLibraryAutoloader() - each snippet loads it itself.
// ==============================================================================

requireFn('fn_qr_accessible_pdf.php');
requireFn('fn_api_generate.php');
requireFn('fn_api_bulk.php');
requireFn('fn_response.php');

// ==============================================================================
// ⠿ brailleGrade1LetterDots() / brailleGrade1DigitToLetter() - spot checks
// ==============================================================================

$letterDots = brailleGrade1LetterDots();
assertEquals([1], $letterDots['a'], 'brailleGrade1LetterDots()["a"] is dot 1 (the simplest Braille letter)');
assertEquals([1, 3, 5, 6], $letterDots['z'], 'brailleGrade1LetterDots()["z"] is dots 1-3-5-6');
assertEquals([1, 2, 3, 4, 5], $letterDots['q'], 'brailleGrade1LetterDots()["q"] is all of dots 1-2-3-4-5');

$digitToLetter = brailleGrade1DigitToLetter();
assertEquals('a', $digitToLetter['1'], 'brailleGrade1DigitToLetter()["1"] maps to letter "a" (dot 1 shape)');
assertEquals('j', $digitToLetter['0'], 'brailleGrade1DigitToLetter()["0"] maps to letter "j" (Braille "0" convention)');

assertEquals([3, 4, 5, 6], brailleNumberSignDots(), 'brailleNumberSignDots() is dots 3-4-5-6');
assertEquals([6], brailleCapitalSignDots(), 'brailleCapitalSignDots() is dot 6 alone');

// ==============================================================================
// ⠿ brailleTransliterateGrade1() - deterministic dot-set transliteration
// ==============================================================================

// ✅ A single lowercase letter.
assertEquals(
    [[1]],
    brailleTransliterateGrade1('a'),
    'brailleTransliterateGrade1("a") is a single [1] cell'
);

// ✅ "Hi 5" - capital sign + H, then i, blank (space), number sign + 5(=e's shape).
//    Captured/derived directly from the source tables above (H=[1,2,5], i=[2,4],
//    digit 5 -> letter 'e' -> [1,5]).
assertEquals(
    [[6], [1, 2, 5], [2, 4], [], [3, 4, 5, 6], [1, 5]],
    brailleTransliterateGrade1('Hi 5'),
    'brailleTransliterateGrade1("Hi 5") = capital-sign, H, i, blank(space), number-sign, 5(=e shape)'
);

// ✅ A digit RUN only gets ONE number sign, not one per digit.
assertEquals(
    [[3, 4, 5, 6], [1], [1, 2], [1, 4]],
    brailleTransliterateGrade1('123'),
    'brailleTransliterateGrade1("123") has exactly ONE leading number sign for the whole run (1=a,2=b,3=c shapes)'
);

// ✅ A non-digit character ends a digit run - a SECOND run gets its OWN number sign.
assertEquals(
    [[3, 4, 5, 6], [1], [], [3, 4, 5, 6], [1, 2]],
    brailleTransliterateGrade1('1 2'),
    'brailleTransliterateGrade1("1 2") - the space ends the digit run, so "2" gets a FRESH number sign'
);

// ✅ Recognised punctuation.
assertEquals(
    [[1], [2, 5, 6]],
    brailleTransliterateGrade1('a.'),
    'brailleTransliterateGrade1("a.") = letter a, then the period dots 2-5-6'
);

// ✅ Space is always a blank cell.
assertEquals([[]], brailleTransliterateGrade1(' '), 'brailleTransliterateGrade1(" ") is a single blank cell');

// ✅ An unsupported character (outside the documented Grade-1 subset) renders
//    as a blank cell rather than being silently dropped or throwing.
assertEquals(
    [[1], []],
    brailleTransliterateGrade1('aé'),
    'brailleTransliterateGrade1("aé") - the unsupported "é" renders as a blank cell (documented scope limit)'
);

// ✅ Empty string produces an empty cell list.
assertEquals([], brailleTransliterateGrade1(''), 'brailleTransliterateGrade1("") returns an empty cell list');

// ==============================================================================
// 📐 brailleCellDotPositionsMm() - per-dot geometry
// ==============================================================================

assertEquals(
    [[0.0, 0.0]],
    brailleCellDotPositionsMm([1], 0.0, 0.0),
    'brailleCellDotPositionsMm([1], 0, 0) - dot 1 sits at the cell origin (top-left)'
);

assertEquals(
    [[0.0, 0.0], [2.5, 2.5]],
    brailleCellDotPositionsMm([1, 5], 0.0, 0.0),
    'brailleCellDotPositionsMm([1,5], 0, 0) - dot 1 top-left, dot 5 mid-right at the 2.5mm intra-cell pitch'
);

assertEquals(
    [[12.5, 15.0], [12.5, 20.0]],
    brailleCellDotPositionsMm([4, 6], 10.0, 15.0),
    'brailleCellDotPositionsMm([4,6], 10, 15) - dot 4 top-right (one pitch right of origin), dot 6 bottom-right'
);

assertEquals(
    [],
    brailleCellDotPositionsMm([], 5.0, 5.0),
    'brailleCellDotPositionsMm([], ...) - a blank cell (no dots) returns an empty position list'
);

// ==============================================================================
// 📐 brailleLineWidthMm() - total width for N cells
// ==============================================================================

assertEquals(0.0, brailleLineWidthMm(0), 'brailleLineWidthMm(0) is 0.0');
assertEquals(2.5, brailleLineWidthMm(1), 'brailleLineWidthMm(1) is just the dot-pitch width (2.5mm) - no inter-cell gaps yet');
assertEquals(8.5, brailleLineWidthMm(2), 'brailleLineWidthMm(2) is one 6.0mm inter-cell pitch + the 2.5mm trailing-cell width');
assertEquals(14.5, brailleLineWidthMm(3), 'brailleLineWidthMm(3) is two 6.0mm pitches + the 2.5mm trailing-cell width');

// ==============================================================================
// ⭕ circleStripHalfWidthMm() - pure circle-equation maths
// ==============================================================================

assertEquals(1.0, circleStripHalfWidthMm(1.0, 0.0), 'circleStripHalfWidthMm(1, 0) - through the centre, full radius');
assertEquals(0.0, circleStripHalfWidthMm(1.0, 1.0), 'circleStripHalfWidthMm(1, 1) - exactly at the edge, zero width');
assertEquals(4.0, circleStripHalfWidthMm(5.0, 3.0), 'circleStripHalfWidthMm(5, 3) - the 3-4-5 right-triangle case');
assertEquals(0.0, circleStripHalfWidthMm(5.0, 6.0), 'circleStripHalfWidthMm(5, 6) - beyond the radius clamps to 0.0, not NAN');
assertEquals(0.0, circleStripHalfWidthMm(0.0, 0.0), 'circleStripHalfWidthMm(0, 0) - a zero radius has zero width');

// ==============================================================================
// 📋 pdfLayoutOptions() / normalisePdfLayout() - the pdf_layout enum
// ==============================================================================

assertEquals(
    ['standard', 'accessible_caption', 'tactile', 'avery_5160', 'avery_l7160'],
    pdfLayoutOptions(),
    'pdfLayoutOptions() returns the full 5-value enum, "standard" first/default'
);

assertEquals('standard', normalisePdfLayout(null), 'normalisePdfLayout(null) defaults to "standard"');
assertEquals('standard', normalisePdfLayout(''), 'normalisePdfLayout("") defaults to "standard" (empty string not in the enum)');
assertEquals('standard', normalisePdfLayout('bogus'), 'normalisePdfLayout("bogus") falls back to "standard"');
assertEquals('standard', normalisePdfLayout(42), 'normalisePdfLayout(42) (non-string) falls back to "standard"');
assertEquals(
    'accessible_caption',
    normalisePdfLayout('accessible_caption'),
    'normalisePdfLayout("accessible_caption") passes through unchanged'
);
assertEquals(
    'avery_5160',
    normalisePdfLayout('  AVERY_5160  '),
    'normalisePdfLayout() trims + lower-cases (mixed case/whitespace tolerated)'
);

// ==============================================================================
// 📐 averySheetSpec() - Avery 5160 / L7160 geometry
// ==============================================================================

$avery5160 = averySheetSpec('avery_5160');
assertEquals(3, $avery5160['columns'], 'averySheetSpec("avery_5160") has 3 columns');
assertEquals(10, $avery5160['rows'], 'averySheetSpec("avery_5160") has 10 rows (30 labels/sheet)');
assertEquals(215.9, $avery5160['page_width_mm'], 'averySheetSpec("avery_5160") page width is US Letter (215.9mm)');

$averyL7160 = averySheetSpec('avery_l7160');
assertEquals(3, $averyL7160['columns'], 'averySheetSpec("avery_l7160") has 3 columns');
assertEquals(7, $averyL7160['rows'], 'averySheetSpec("avery_l7160") has 7 rows (21 labels/sheet)');
assertEquals(210.0, $averyL7160['page_width_mm'], 'averySheetSpec("avery_l7160") page width is A4 (210mm)');

// ✅ An unrecognised layout name defensively falls back to avery_5160's geometry.
assertEquals(
    $avery5160,
    averySheetSpec('not-a-real-layout'),
    'averySheetSpec() with an unrecognised name defensively falls back to avery_5160 geometry'
);

// ==============================================================================
// ✂️ accessiblePdfTruncateCaption() - length bounding
// ==============================================================================

assertEquals('short', accessiblePdfTruncateCaption('short'), 'accessiblePdfTruncateCaption() leaves a short string untouched');
assertEquals(
    str_repeat('x', 140),
    accessiblePdfTruncateCaption(str_repeat('x', 140)),
    'accessiblePdfTruncateCaption() leaves a string AT exactly the default 140-char limit untouched'
);
$longCaption = str_repeat('x', 200);
$truncated   = accessiblePdfTruncateCaption($longCaption);
assertEquals(140, mb_strlen($truncated), 'accessiblePdfTruncateCaption() truncates a long string to exactly 140 chars (incl. ellipsis)');
assertTrue(str_ends_with($truncated, '…'), 'accessiblePdfTruncateCaption() appends an ellipsis when truncating');
assertEquals('ab…', accessiblePdfTruncateCaption('abcdef', 3), 'accessiblePdfTruncateCaption() honours a custom $maxLength (3 total chars: "ab" + the ellipsis)');

// ==============================================================================
// 📝 deriveAccessiblePdfCaptionText() - caption source preference order
// ==============================================================================

assertEquals(
    'https://example.com',
    deriveAccessiblePdfCaptionText('url', ['url' => 'https://example.com'], 'https://example.com'),
    'deriveAccessiblePdfCaptionText() prefers the "url" input field'
);
assertEquals(
    'Hello world',
    deriveAccessiblePdfCaptionText('text', ['text' => 'Hello world'], 'Hello world'),
    'deriveAccessiblePdfCaptionText() prefers the "text" input field when no "url" is present'
);
assertEquals(
    '51.5',
    deriveAccessiblePdfCaptionText('geo', ['lat' => 51.5, 'lon' => -0.1], 'fallback-payload'),
    'deriveAccessiblePdfCaptionText() falls back to the FIRST SCALAR input value (numeric lat, cast to string) when no preferred key ("url"/"text"/"label"/"phone"/"email") matches'
);
assertEquals(
    'raw-payload-fallback',
    deriveAccessiblePdfCaptionText('unknown', [], 'raw-payload-fallback'),
    'deriveAccessiblePdfCaptionText() falls back to the raw payload when input data is empty'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() - 'pdf_layout' parsing (E5 / #49)
// ==============================================================================

assertEquals(
    'standard',
    buildCustomizationFromRequest([])['pdf_layout'] ?? null,
    'buildCustomizationFromRequest() defaults pdf_layout to "standard" when omitted'
);
assertEquals(
    'tactile',
    buildCustomizationFromRequest(['pdf_layout' => 'tactile'])['pdf_layout'] ?? null,
    'buildCustomizationFromRequest() accepts pdf_layout=tactile'
);
assertEquals(
    'avery_l7160',
    buildCustomizationFromRequest(['pdf_layout' => '  Avery_L7160 '])['pdf_layout'] ?? null,
    'buildCustomizationFromRequest() trims + lower-cases pdf_layout (mixed case/whitespace)'
);
assertEquals(
    'standard',
    buildCustomizationFromRequest(['pdf_layout' => 'not-a-real-layout'])['pdf_layout'] ?? null,
    'buildCustomizationFromRequest() rejects an out-of-allowlist pdf_layout -> falls back to "standard"'
);

// ==============================================================================
// 📄 bulkRequestWantsAveryLayout() - bulk Avery-sheet detection (E5 / #49)
// ==============================================================================

assertEquals(
    'avery_5160',
    bulkRequestWantsAveryLayout(['customization' => ['format' => 'pdf', 'pdf_layout' => 'avery_5160']]),
    'bulkRequestWantsAveryLayout() detects format=pdf + pdf_layout=avery_5160 at the request level'
);
assertEquals(
    'avery_l7160',
    bulkRequestWantsAveryLayout(['customization' => ['format' => 'PDF', 'pdf_layout' => ' Avery_L7160 ']]),
    'bulkRequestWantsAveryLayout() is case/whitespace-tolerant on both format and pdf_layout'
);
assertEquals(
    null,
    bulkRequestWantsAveryLayout(['customization' => ['format' => 'pdf', 'pdf_layout' => 'standard']]),
    'bulkRequestWantsAveryLayout() returns null for pdf_layout=standard (the default ZIP path applies)'
);
assertEquals(
    null,
    bulkRequestWantsAveryLayout(['customization' => ['format' => 'png', 'pdf_layout' => 'avery_5160']]),
    'bulkRequestWantsAveryLayout() returns null when format is not pdf, even with an avery pdf_layout value'
);
assertEquals(
    null,
    bulkRequestWantsAveryLayout(['customization' => ['format' => 'pdf']]),
    'bulkRequestWantsAveryLayout() returns null when pdf_layout is absent entirely'
);
assertEquals(
    null,
    bulkRequestWantsAveryLayout([]),
    'bulkRequestWantsAveryLayout() returns null when there is no request-level "customization" at all'
);

// ==============================================================================
// 🌐 generateQRCode() end-to-end - pdf_layout wiring (SUBPROCESS)
// ==============================================================================
// Each snippet loads the chillerlan/FPDF autoloader + the QR generation
// function files itself, then calls generateQRCode() directly with
// save_file=false (no DB, no filesystem writes - dbQuery()/logActivity()
// are absent in this harness and generateQRCode() defensively no-ops via
// function_exists() guards around every DB/activity-log call, exactly as
// test_accessibility.php's own generateQRCode()-adjacent coverage relies on).
// ==============================================================================

// ✅ Parity: pdf_layout ABSENT and pdf_layout='standard' produce BYTE-IDENTICAL
//    PDF output - the "standard stays byte-compatible" acceptance criterion.
$parityOutput = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_qr_payloads.php');
requireFn('fn_qr_options.php');
requireFn('fn_qr_accessible_pdf.php');
requireFn('fn_qr_generate.php');

$base = ['format' => 'pdf', 'size' => 500, 'ecc' => 'M', 'save_file' => false];

$withoutKey = generateQRCode('url', ['url' => 'https://cuercode.net/parity'], $base);
$withStandard = generateQRCode('url', ['url' => 'https://cuercode.net/parity'], $base + ['pdf_layout' => 'standard']);

if ($withoutKey['success'] && $withStandard['success'] && $withoutKey['data'] === $withStandard['data']) {
    echo 'PARITY_OK';
} else {
    echo 'PARITY_FAIL';
}
PHP
);

assertContains(
    'PARITY_OK',
    $parityOutput,
    'generateQRCode(): pdf_layout absent vs explicit "standard" produce BYTE-IDENTICAL PDF output'
);

// ✅ 'accessible_caption' produces a non-trivial, valid PDF.
$accessibleCaptionOutput = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_qr_payloads.php');
requireFn('fn_qr_options.php');
requireFn('fn_qr_accessible_pdf.php');
requireFn('fn_qr_generate.php');

$customization = ['format' => 'pdf', 'size' => 500, 'ecc' => 'M', 'save_file' => false, 'pdf_layout' => 'accessible_caption'];
$result = generateQRCode('url', ['url' => 'https://cuercode.net/braille-test'], $customization);

if ($result['success'] && str_starts_with($result['data'], '%PDF') && strlen($result['data']) > 2000) {
    echo 'PDF_OK';
} else {
    echo 'PDF_FAIL';
}
PHP
);

assertContains(
    'PDF_OK',
    $accessibleCaptionOutput,
    'generateQRCode() with pdf_layout=accessible_caption produces a valid (%PDF-prefixed), non-trivial-size PDF'
);

// ✅ 'tactile' produces a non-trivial, valid PDF (2 pages internally).
$tactileOutput = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_qr_payloads.php');
requireFn('fn_qr_options.php');
requireFn('fn_qr_accessible_pdf.php');
requireFn('fn_qr_generate.php');

$customization = ['format' => 'pdf', 'size' => 500, 'ecc' => 'M', 'save_file' => false, 'pdf_layout' => 'tactile'];
$result = generateQRCode('url', ['url' => 'https://cuercode.net/tactile-test'], $customization);

if ($result['success'] && str_starts_with($result['data'], '%PDF') && strlen($result['data']) > 2000) {
    echo 'PDF_OK';
} else {
    echo 'PDF_FAIL';
}
PHP
);

assertContains(
    'PDF_OK',
    $tactileOutput,
    'generateQRCode() with pdf_layout=tactile produces a valid (%PDF-prefixed), non-trivial-size PDF'
);

// ✅ 'avery_5160' (single-code generation fills one label on a full sheet template).
$avery5160Output = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_qr_payloads.php');
requireFn('fn_qr_options.php');
requireFn('fn_qr_accessible_pdf.php');
requireFn('fn_qr_generate.php');

$customization = ['format' => 'pdf', 'size' => 500, 'ecc' => 'M', 'save_file' => false, 'pdf_layout' => 'avery_5160'];
$result = generateQRCode('url', ['url' => 'https://cuercode.net/avery-test'], $customization);

if ($result['success'] && str_starts_with($result['data'], '%PDF') && strlen($result['data']) > 1000) {
    echo 'PDF_OK';
} else {
    echo 'PDF_FAIL';
}
PHP
);

assertContains(
    'PDF_OK',
    $avery5160Output,
    'generateQRCode() with pdf_layout=avery_5160 produces a valid (%PDF-prefixed), non-trivial-size PDF'
);
