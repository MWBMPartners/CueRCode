<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Characterization Tests for ACCESSIBLE QR (E5 / #48)
 * ==============================================================================
 *
 * Path: tests/test_accessibility.php
 *
 * Covers the "Accessible-QR presets, WCAG contrast validation & physical-size
 * calculator" feature (PROJECT_ROADMAP.md - E5 DRAFT ISSUE (a), GitHub #48):
 *
 *   - qrContrastRatio()          (fn_qr_options.php) - WCAG 2.1 relative-
 *                                 luminance contrast ratio between two colours.
 *   - qrRecommendedPrintSizeMm() (fn_qr_options.php) - the 10:1 scan-distance
 *                                 rule with a 20mm practical floor.
 *   - applyAccessiblePreset()    (fn_qr_options.php) - the preset enforcement:
 *                                 forces ecc=H + quiet_zone=true + auto version,
 *                                 classifies contrast as fail/warning/pass, and
 *                                 is a COMPLETE NO-OP for any non-'accessible'
 *                                 preset value (the parity/regression guarantee
 *                                 that existing, non-accessible generations are
 *                                 byte-for-byte unchanged by this feature).
 *   - buildCustomizationFromRequest() (fn_api_generate.php) - accepts/allowlists
 *                                 the new 'preset' and 'scan_distance_cm' params.
 *   - handleGenerate() end-to-end (fn_api_generate.php) - the actual HTTP-level
 *                                 422 rejection when preset=accessible and the
 *                                 chosen fg/bg contrast is below 3:1. Run in a
 *                                 SUBPROCESS because jsonResponse() calls exit()
 *                                 (same reason test_core.php subprocesses its
 *                                 jsonResponse/xmlResponse coverage). Only the
 *                                 REJECTION path is exercised here - it returns
 *                                 before generateQRCode() ever touches the
 *                                 database, so it stays DB-independent. The
 *                                 'warning'/'pass' bands DO reach generateQRCode()
 *                                 (DB-dependent) so they are covered at the
 *                                 applyAccessiblePreset() level only, per this
 *                                 harness's documented DB-independence scope.
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E5 accessibility cycle, #48)
 * @see         tests/bootstrap.php  - requireFn() / requireLibraryAutoloader()
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_qr_options.php   - qrContrastRatio(), qrRecommendedPrintSizeMm(), applyAccessiblePreset()
 * @see         web/CueRCode.net/_functions/fn_api_generate.php - buildCustomizationFromRequest(), handleGenerate()
 * @see         PROJECT_ROADMAP.md - E5 "Accessibility QR systems"
 */

// ==============================================================================
// 🧰 Load the DB-independent production function files (NO database connection)
// ==============================================================================
// fn_qr_options.php references chillerlan\QRCode library constants inside
// buildQROptions()/buildModuleValues() (not called by these tests), but the
// top-level `use` statements alone do not require the autoloader. We load it
// anyway, matching test_core.php's convention, in case a future edit to this
// file adds a top-level reference.
// @see tests/bootstrap.php - requireLibraryAutoloader()
// ==============================================================================

$qrLibraryLoaded = requireLibraryAutoloader();

if ($qrLibraryLoaded) {
    requireFn('fn_qr_options.php');
}

// fn_api_generate.php only DEFINES functions at include time (buildCustomiza-
// tionFromRequest()/validateCustomization() are pure transforms; handleGenerate()
// only touches the DB via generateQRCode(), which the rejection-path test below
// never reaches), so including it opens no connection.
requireFn('fn_api_generate.php');

// fn_response.php provides jsonResponse()/errorResponse()/determineResponseFormat()
// used by the handleGenerate() subprocess test below.
requireFn('fn_response.php');

// ==============================================================================
// 🛰️ runAccessibilitySnippet() - Execute a PHP snippet in a fresh SUBPROCESS
// ==============================================================================

/**
 * 🛰️ Run a self-contained PHP snippet in a fresh child process and return stdout.
 *
 * handleGenerate() ultimately calls errorResponse() -> jsonResponse(), which
 * calls exit() - that would terminate the main test runner process if called
 * in-process. Running the snippet in a child PHP process side-steps this: the
 * child's exit only ends the child, and we capture its STDOUT (the JSON body)
 * back in the parent for assertions.
 *
 * Named distinctly from test_core.php's runPhpSnippet() (both files are
 * `require`d into the SAME runner process by tests/run.php, so two functions
 * with the same name would be a fatal redeclaration - alphabetical file
 * ordering also means test_core.php's helper is not yet defined when this
 * file's snippets run first).
 *
 * @param string $snippet PHP code to run (WITHOUT a leading "<?php" tag). The
 *                        bootstrap is already required before this code runs.
 *
 * @return string The child process's STDOUT (stderr is discarded).
 *
 * @see https://www.php.net/manual/en/function.proc-open.php
 * @see tests/test_core.php - runPhpSnippet() (the sibling this mirrors)
 */
function runAccessibilitySnippet(string $snippet): string
{
    $bootstrapPath = CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    $program = '<?php' . PHP_EOL
        . 'error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);' . PHP_EOL
        . 'require ' . var_export($bootstrapPath, true) . ';' . PHP_EOL
        . $snippet . PHP_EOL;

    $tmpFile = tempnam(sys_get_temp_dir(), 'cuercode_test_a11y_') . '.php';
    file_put_contents($tmpFile, $program);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stdout  = '';
    $command = [PHP_BINARY, $tmpFile];
    $process = proc_open($command, $descriptors, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // 🗑️ Drain and discard STDERR.
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);
    }

    // 🧹 Clean up the temp file.
    if (is_file($tmpFile)) {
        unlink($tmpFile);
    }

    return $stdout;
}

// ==============================================================================
// ♿ qrContrastRatio() - known colour pairs
// ==============================================================================
// Values below were captured from a real run of the production function
// (per this repo's "capture real output, then assert" test convention) before
// being hardcoded here.
// ==============================================================================

// ✅ Pure black on pure white - the maximum possible WCAG contrast, exactly 21:1.
assertEquals(
    21.0,
    qrContrastRatio('#000000', '#ffffff'),
    'qrContrastRatio(#000000, #ffffff) is the maximum contrast, exactly 21.0'
);

// ✅ Order-independence: swapping the two colours must give the same ratio.
assertEquals(
    21.0,
    qrContrastRatio('#ffffff', '#000000'),
    'qrContrastRatio(#ffffff, #000000) is order-independent (same as #000000, #ffffff)'
);

// ✅ Identical colours - zero contrast, exactly 1:1 (the WCAG minimum floor).
assertEquals(
    1.0,
    qrContrastRatio('#123456', '#123456'),
    'qrContrastRatio() of a colour against itself is exactly 1.0 (no contrast)'
);

// ✅ Mid-grey (#777777) on white - a real borderline AA case (~4.48:1), just
//    under the 4.5:1 AA text threshold used by the accessible preset's
//    warning band. Rounded to 2dp to avoid float-precision brittleness.
assertEquals(
    4.48,
    round(qrContrastRatio('#777777', '#ffffff'), 2),
    'qrContrastRatio(#777777, #ffffff) is ~4.48 (borderline AA, captured real output)'
);

// ✅ 3-digit hex shorthand resolves identically to the 6-digit form.
assertEquals(
    4.48,
    round(qrContrastRatio('#777', '#fff'), 2),
    'qrContrastRatio() resolves 3-digit hex shorthand identically to 6-digit'
);

// ==============================================================================
// ♿ qrRecommendedPrintSizeMm() - 10:1 scan-distance rule + 20mm floor
// ==============================================================================
// Values captured from a real run before assertion (per this repo's convention).
// ==============================================================================

// ✅ 100cm (1m) scan distance -> 100mm (10cm) recommended print size.
assertEquals(
    100,
    qrRecommendedPrintSizeMm(100),
    'qrRecommendedPrintSizeMm(100) recommends 100mm for a 1m scan distance (10:1 rule)'
);

// ✅ 50cm scan distance -> 50mm recommended print size.
assertEquals(
    50,
    qrRecommendedPrintSizeMm(50),
    'qrRecommendedPrintSizeMm(50) recommends 50mm for a 50cm scan distance'
);

// ✅ 199cm/200cm - exact 10:1 division, no rounding artefacts.
assertEquals(199, qrRecommendedPrintSizeMm(199), 'qrRecommendedPrintSizeMm(199) recommends 199mm');
assertEquals(200, qrRecommendedPrintSizeMm(200), 'qrRecommendedPrintSizeMm(200) recommends 200mm');

// ✅ Short scan distances (10:1 rule alone would give < 20mm) hit the floor.
assertEquals(
    20,
    qrRecommendedPrintSizeMm(10),
    'qrRecommendedPrintSizeMm(10) is floored to 20mm (10:1 rule alone would give 10mm)'
);
assertEquals(
    20,
    qrRecommendedPrintSizeMm(1),
    'qrRecommendedPrintSizeMm(1) is floored to 20mm'
);

// ✅ Zero and negative distances are clamped to zero, then the floor applies.
assertEquals(
    20,
    qrRecommendedPrintSizeMm(0),
    'qrRecommendedPrintSizeMm(0) is floored to 20mm'
);
assertEquals(
    20,
    qrRecommendedPrintSizeMm(-5),
    'qrRecommendedPrintSizeMm(-5) is clamped to 0 then floored to 20mm (no negative sizes)'
);

// ==============================================================================
// ♿ applyAccessiblePreset() - non-accessible parity guarantee (regression)
// ==============================================================================
// preset='none' (or absent) MUST return the customisation array COMPLETELY
// UNCHANGED - this is the guarantee that existing, non-accessible behaviour
// stays byte-for-byte identical now that this feature exists.
// ==============================================================================

$noneInput = [
    'preset'     => 'none',
    'ecc'        => 'L',
    'quiet_zone' => false,
    'version'    => 5,
];
assertEquals(
    $noneInput,
    applyAccessiblePreset($noneInput),
    'applyAccessiblePreset() with preset=none returns the array completely unchanged'
);

$absentPresetInput = ['ecc' => 'L'];
assertEquals(
    $absentPresetInput,
    applyAccessiblePreset($absentPresetInput),
    'applyAccessiblePreset() with no preset key at all returns the array completely unchanged'
);

// ==============================================================================
// ♿ applyAccessiblePreset() - accessible preset forces ecc=H + quiet_zone + auto version
// ==============================================================================

$forcedResult = applyAccessiblePreset([
    'preset'     => 'accessible',
    'ecc'        => 'L',          // caller asked for Low - must be forced to H
    'quiet_zone' => false,        // caller asked it off - must be forced on
    'version'    => 10,           // caller pinned a version - must be dropped
    'fg_color'   => '#777777',
    'bg_color'   => '#888888',
]);

assertEquals('H', $forcedResult['ecc'] ?? null, 'accessible preset forces ecc to H regardless of the request');
assertEquals(true, $forcedResult['quiet_zone'] ?? null, 'accessible preset forces quiet_zone to true');
assertFalse(
    array_key_exists('version', $forcedResult),
    'accessible preset drops any pinned version so the smallest auto-fit version is used'
);

// ==============================================================================
// ♿ applyAccessiblePreset() - contrast classification: fail / warning / pass
// ==============================================================================

// ❌ #777777 on #888888 - low contrast, MUST classify as 'fail' (< 3:1).
assertEquals(
    'fail',
    $forcedResult['_accessible_meta']['contrast_level'] ?? null,
    'accessible preset classifies #777777/#888888 (low contrast) as "fail" (< 3:1)'
);
assertEquals(
    1.26,
    $forcedResult['_accessible_meta']['contrast_ratio'] ?? null,
    'accessible preset records the rounded contrast_ratio (1.26) for the fail case'
);
assertTrue(
    array_key_exists('recommended_print_size_mm', $forcedResult['_accessible_meta']),
    'accessible preset always includes the recommended_print_size_mm key in _accessible_meta'
);
assertEquals(
    null,
    $forcedResult['_accessible_meta']['recommended_print_size_mm'],
    'accessible preset reports recommended_print_size_mm as null when no scan_distance_cm was supplied'
);

// ⚠️ #777777 on #ffffff - borderline AA, MUST classify as 'warning' (3:1-4.5:1).
$warningResult = applyAccessiblePreset([
    'preset'   => 'accessible',
    'fg_color' => '#777777',
    'bg_color' => '#ffffff',
]);
assertEquals(
    'warning',
    $warningResult['_accessible_meta']['contrast_level'] ?? null,
    'accessible preset classifies #777777/#ffffff (~4.48:1) as "warning" (3:1 <= ratio < 4.5:1)'
);
assertEquals(
    4.48,
    $warningResult['_accessible_meta']['contrast_ratio'] ?? null,
    'accessible preset records the rounded contrast_ratio (4.48) for the warning case'
);

// ✅ #000000 on #ffffff - maximum contrast, MUST classify as 'pass' (>= 4.5:1),
//    and the physical print-size recommendation is included when a scan
//    distance was supplied alongside the preset.
$passResult = applyAccessiblePreset([
    'preset'           => 'accessible',
    'fg_color'         => '#000000',
    'bg_color'         => '#ffffff',
    'scan_distance_cm' => 100,
]);
assertEquals(
    'pass',
    $passResult['_accessible_meta']['contrast_level'] ?? null,
    'accessible preset classifies #000000/#ffffff (21:1) as "pass" (>= 4.5:1)'
);
assertEquals(
    21.0,
    $passResult['_accessible_meta']['contrast_ratio'] ?? null,
    'accessible preset records the rounded contrast_ratio (21.0) for the pass case'
);
assertEquals(
    100,
    $passResult['_accessible_meta']['recommended_print_size_mm'] ?? null,
    'accessible preset surfaces recommended_print_size_mm (100) when scan_distance_cm=100 was supplied'
);

// ✅ Missing fg_color/bg_color falls back to the black-on-white default pair
//    (matching buildQROptions()'s own #000000/#ffffff defaults) -> 'pass'.
$defaultColourResult = applyAccessiblePreset(['preset' => 'accessible']);
assertEquals(
    'pass',
    $defaultColourResult['_accessible_meta']['contrast_level'] ?? null,
    'accessible preset with no fg/bg supplied falls back to black-on-white (21:1, "pass")'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() - preset parsing (E5 / #48)
// ==============================================================================

assertEquals(
    'none',
    buildCustomizationFromRequest([])['preset'] ?? null,
    'buildCustomizationFromRequest defaults preset to "none" when omitted'
);
assertEquals(
    'accessible',
    buildCustomizationFromRequest(['preset' => 'accessible'])['preset'] ?? null,
    'buildCustomizationFromRequest accepts preset=accessible'
);
assertEquals(
    'accessible',
    buildCustomizationFromRequest(['preset' => '  Accessible '])['preset'] ?? null,
    'buildCustomizationFromRequest trims + lower-cases preset (mixed case/whitespace)'
);
assertEquals(
    'none',
    buildCustomizationFromRequest(['preset' => 'bogus-value'])['preset'] ?? null,
    'buildCustomizationFromRequest rejects an out-of-allowlist preset -> falls back to "none"'
);

// ==============================================================================
// 🔧 buildCustomizationFromRequest() - scan_distance_cm parsing (E5 / #48)
// ==============================================================================

assertFalse(
    array_key_exists('scan_distance_cm', buildCustomizationFromRequest([])),
    'buildCustomizationFromRequest omits scan_distance_cm entirely when not supplied'
);
assertEquals(
    100,
    buildCustomizationFromRequest(['scan_distance_cm' => 100])['scan_distance_cm'] ?? null,
    'buildCustomizationFromRequest accepts a valid scan_distance_cm (100)'
);
assertEquals(
    50,
    buildCustomizationFromRequest(['scan_distance_cm' => '50'])['scan_distance_cm'] ?? null,
    'buildCustomizationFromRequest coerces a numeric-string scan_distance_cm to int'
);
assertEquals(
    1,
    buildCustomizationFromRequest(['scan_distance_cm' => -5])['scan_distance_cm'] ?? null,
    'buildCustomizationFromRequest clamps a negative scan_distance_cm up to the 1 lower bound'
);
assertEquals(
    10000,
    buildCustomizationFromRequest(['scan_distance_cm' => 999999])['scan_distance_cm'] ?? null,
    'buildCustomizationFromRequest clamps an oversized scan_distance_cm down to the 10000 upper bound'
);

// ==============================================================================
// 🌐 handleGenerate() end-to-end - 422 contrast rejection (SUBPROCESS)
// ==============================================================================
// Exercises the ACTUAL HTTP handler (not just applyAccessiblePreset() in
// isolation) to prove the 422/ACCESSIBLE_CONTRAST_TOO_LOW wiring in
// fn_api_generate.php works end-to-end. This path returns via errorResponse()
// BEFORE generateQRCode() is ever called, so it never touches the database -
// safe for this DB-independent harness. Run in a subprocess because
// jsonResponse() calls exit().
// ==============================================================================

$rejectionSnippet = <<<'PHP'
requireFn('fn_response.php');
requireFn('fn_api_generate.php');

handleGenerate([
    'type'  => 'url',
    'input' => ['url' => 'https://example.com'],
    'customization' => [
        'preset'   => 'accessible',
        'fg_color' => '#777777',
        'bg_color' => '#888888',
    ],
]);
PHP;

$rejectionOutput = runAccessibilitySnippet($rejectionSnippet);

assertContains(
    '"error_code": "ACCESSIBLE_CONTRAST_TOO_LOW"',
    $rejectionOutput,
    'handleGenerate() end-to-end: preset=accessible + <3:1 contrast responds with error_code ACCESSIBLE_CONTRAST_TOO_LOW'
);
assertContains(
    '"success": false',
    $rejectionOutput,
    'handleGenerate() end-to-end: the contrast-rejection response has success=false'
);
assertContains(
    '1.26',
    $rejectionOutput,
    'handleGenerate() end-to-end: the rejection message includes the computed contrast ratio (1.26)'
);

// ✅ Regression guard: preset='none' (the default/absent case) must NOT be
//    affected by any of the accessible-preset wiring - the missing 'type'
//    validation (which runs BEFORE the preset logic) still fires exactly as
//    before this feature existed.
$regressionSnippet = <<<'PHP'
requireFn('fn_response.php');
requireFn('fn_api_generate.php');

handleGenerate([
    'input' => ['url' => 'https://example.com'],
]);
PHP;

$regressionOutput = runAccessibilitySnippet($regressionSnippet);

assertContains(
    '"error_code": "MISSING_FIELD"',
    $regressionOutput,
    'handleGenerate() regression: a request with no "type" and no preset still fails with MISSING_FIELD (unaffected by this feature)'
);
