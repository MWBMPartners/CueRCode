<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for Bulk QR Generation Helpers (FG-003 / #32)
 * ==============================================================================
 *
 * Path: tests/test_bulk.php
 *
 * Exercises the DB-INDEPENDENT, PURE helper functions of the bulk generation
 * endpoint in web/CueRCode.net/_functions/fn_api_bulk.php:
 *
 *   - parseBulkInput()        (items-array shape + CSV shape + error shapes)
 *   - parseBulkCsv()          (header detection, column classification,
 *                              boolean-column coercion)
 *   - bulkCsvCoerceBoolean()  (the 'transparent'/'quiet_zone' CSV boolean fold)
 *   - validateBulkRequest()   (cap enforcement, per-item shape validation)
 *   - bulkBuildFilename()     (safe ZIP entry naming)
 *
 * These helpers perform NO database, filesystem, or QR-library work, so they
 * run fully self-contained under the CLI harness via requireFn().
 *
 * 🔧 FG-033 / #57 (bulk-pipeline accessibility parity close-out) added
 * end-to-end SUBPROCESS coverage of handleBulkGenerate() itself (both the ZIP
 * pipeline and the handleBulkAverySheet() fork) - see the section near the
 * bottom of this file. This is possible without a live HTTP server because
 * handleBulkGenerate() now accepts an optional pre-decoded $requestData
 * parameter (php://input cannot be seeded from CLI PHP), mirroring how
 * fn_api_generate.php's handleGenerate(array $requestData) already receives
 * its body. The real ZIP bytes are captured from a child process's stdout,
 * written to a temp file, and inspected in-process with ZipArchive - the
 * "subprocess capture-then-assert" + "ZipArchive-in-process inspection"
 * conventions this file borrows from tests/test_accessibility.php and
 * tests/test_braille.php respectively.
 *
 * ⚠️ Epic E4/#53 note: the item-count cap used to be a fixed global default
 * (`getBulkMaxItems()`/`api_bulk_max_items`, removed this stage) - it is now
 * the caller's PLAN limit (`bulk_batch_size`), resolved by
 * `fn_features.php`'s `limitFor()` inside `handleBulkGenerate()` itself, NOT
 * by a pure helper in this file any more. `validateBulkRequest()` below still
 * takes `$maxItems` as a plain int parameter (unchanged, still pure/testable
 * in isolation) - only WHERE that int comes from changed. The plan-resolution
 * side of the new mechanism (anonymous/free/pro/business `bulk_batch_size`
 * values, past_due downgrade) is covered by `tests/test_features.php`'s
 * scratch-DB integration section instead, since it is inherently
 * DB-dependent (a real tblSubscriptionPlans/tblSubscriptions read).
 *
 * ⚠️ Lives under repo-root tests/ - never deployed.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       Phase 2 (FG-003 bulk generation; Epic E4/#53 cap note above;
 *              FG-033/#57 bulk-pipeline accessibility parity close-out)
 * @see         web/CueRCode.net/_functions/fn_api_bulk.php
 * @see         web/CueRCode.net/_functions/fn_qr_options.php - applyAccessiblePreset()
 * @see         web/CueRCode.net/_functions/fn_features.php
 * @see         tests/test_features.php - the plan-based bulk_batch_size gate coverage
 * @see         tests/test_accessibility.php - runAccessibilitySnippet() (reused here)
 * @see         https://www.php.net/manual/en/function.str-getcsv.php
 * @see         https://www.php.net/manual/en/class.ziparchive.php
 */

// ==============================================================================
// 📦 Load the production bulk helpers (NO database connection)
// ==============================================================================
// requireFn() (from tests/bootstrap.php) includes the fn_ file, which only
// REGISTERS the standalone functions. parseBulkInput()/validateBulkRequest()
// and the other helpers tested here never open a DB connection, never touch
// the filesystem, and never call the QR library — so this is self-contained.
// ==============================================================================

requireFn('fn_api_bulk.php');

// ==============================================================================
// 🧹 parseBulkInput() - valid items-array shape
// ==============================================================================

$validItems = parseBulkInput([
    'items' => [
        ['type' => 'url',  'input' => ['url' => 'https://example.com']],
        ['type' => 'text', 'input' => ['text' => 'Hello'],
         'customization' => ['format' => 'png', 'size' => 600]],
    ],
]);

assertTrue(
    isset($validItems['items']) && is_array($validItems['items']),
    'parseBulkInput(items): returns an items list on a valid array'
);

assertEquals(
    2,
    count($validItems['items']),
    'parseBulkInput(items): preserves the item count'
);

assertEquals(
    'url',
    $validItems['items'][0]['type'],
    'parseBulkInput(items): first item type is normalised through'
);

assertEquals(
    'https://example.com',
    $validItems['items'][0]['input']['url'],
    'parseBulkInput(items): first item input passes through'
);

// 📌 An item with no customization gets an (empty) customization array.
assertEquals(
    [],
    $validItems['items'][0]['customization'],
    'parseBulkInput(items): missing per-item customization defaults to []'
);

// 📌 An item WITH customization keeps it.
assertEquals(
    'png',
    $validItems['items'][1]['customization']['format'],
    'parseBulkInput(items): per-item customization is preserved'
);

// ==============================================================================
// 🧹 parseBulkInput() - shared customization merge precedence
// ==============================================================================

$mergedShared = parseBulkInput([
    'customization' => ['format' => 'svg', 'ecc' => 'H'],
    'items' => [
        // 📌 No per-item customization -> inherits the shared defaults.
        ['type' => 'url', 'input' => ['url' => 'https://a.com']],
        // 📌 Per-item 'format' overrides shared 'format'; shared 'ecc' remains.
        ['type' => 'url', 'input' => ['url' => 'https://b.com'],
         'customization' => ['format' => 'png']],
    ],
]);

assertEquals(
    'svg',
    $mergedShared['items'][0]['customization']['format'],
    'parseBulkInput(items): item inherits shared customization when it has none'
);

assertEquals(
    'png',
    $mergedShared['items'][1]['customization']['format'],
    'parseBulkInput(items): per-item customization overrides shared format'
);

assertEquals(
    'H',
    $mergedShared['items'][1]['customization']['ecc'],
    'parseBulkInput(items): unrelated shared keys survive the per-item merge'
);

// ==============================================================================
// 🧹 parseBulkInput() - malformed / missing-input error shapes
// ==============================================================================

// 📌 Neither 'items' nor 'csv' present.
$noInput = parseBulkInput(['customization' => ['format' => 'png']]);
assertEquals(
    'BULK_NO_INPUT',
    $noInput['code'],
    'parseBulkInput: missing both items and csv -> BULK_NO_INPUT'
);
assertEquals(
    400,
    $noInput['http'],
    'parseBulkInput: BULK_NO_INPUT is HTTP 400'
);

// 📌 'items' present but not an array.
$itemsNotArray = parseBulkInput(['items' => 'nope']);
assertEquals(
    'BULK_INVALID_ITEMS',
    $itemsNotArray['code'],
    'parseBulkInput: non-array items -> BULK_INVALID_ITEMS'
);

// 📌 'items' present but empty.
$itemsEmpty = parseBulkInput(['items' => []]);
assertEquals(
    'BULK_EMPTY',
    $itemsEmpty['code'],
    'parseBulkInput: empty items array -> BULK_EMPTY'
);

// 📌 An item that is a scalar, not an object.
$itemScalar = parseBulkInput(['items' => ['just a string']]);
assertEquals(
    'BULK_INVALID_ITEM',
    $itemScalar['code'],
    'parseBulkInput: scalar item -> BULK_INVALID_ITEM'
);

// ==============================================================================
// 📄 parseBulkInput() - CSV shape (bare input column)
// ==============================================================================

$csvBasic = parseBulkInput([
    'csv' => "type,url\nurl,https://example.com\ntext,Hello World",
]);

assertTrue(
    isset($csvBasic['items']) && is_array($csvBasic['items']),
    'parseBulkInput(csv): returns an items list on a valid CSV'
);

assertEquals(
    2,
    count($csvBasic['items']),
    'parseBulkInput(csv): one item per data row (header excluded)'
);

assertEquals(
    'url',
    $csvBasic['items'][0]['type'],
    'parseBulkInput(csv): type column maps to item type'
);

assertEquals(
    'https://example.com',
    $csvBasic['items'][0]['input']['url'],
    'parseBulkInput(csv): bare non-reserved column maps to an input field'
);

assertEquals(
    'Hello World',
    $csvBasic['items'][1]['input']['url'],
    'parseBulkInput(csv): second data row parsed (column named url here holds text)'
);

// ==============================================================================
// 📄 parseBulkInput() - CSV with input.* prefix + reserved customization column
// ==============================================================================

$csvPrefixed = parseBulkInput([
    'csv' => "type,input.url,format,size\nurl,https://a.com,png,600\nurl,https://b.com,svg,",
]);

assertEquals(
    'https://a.com',
    $csvPrefixed['items'][0]['input']['url'],
    'parseBulkInput(csv): input.<field> prefix strips to the bare field name'
);

assertEquals(
    'png',
    $csvPrefixed['items'][0]['customization']['format'],
    'parseBulkInput(csv): reserved column "format" folds into customization'
);

assertEquals(
    '600',
    $csvPrefixed['items'][0]['customization']['size'],
    'parseBulkInput(csv): reserved column "size" folds into customization'
);

// 📌 Empty reserved cell (size in row 2) is skipped, not stored as ''.
assertFalse(
    isset($csvPrefixed['items'][1]['customization']['size']),
    'parseBulkInput(csv): empty reserved cell is skipped (not stored)'
);

assertEquals(
    'svg',
    $csvPrefixed['items'][1]['customization']['format'],
    'parseBulkInput(csv): second row format folds into customization'
);

// ==============================================================================
// 📄 parseBulkInput() - CSV quoting + CRLF line endings + blank lines
// ==============================================================================

$csvQuoted = parseBulkInput([
    // 📌 CRLF endings, a leading blank line, and a quoted value with a comma.
    'csv' => "\r\ntype,input.text\r\ntext,\"Hello, World\"\r\n",
]);

assertEquals(
    1,
    count($csvQuoted['items']),
    'parseBulkInput(csv): CRLF + leading blank line handled (one data row)'
);

assertEquals(
    'Hello, World',
    $csvQuoted['items'][0]['input']['text'],
    'parseBulkInput(csv): quoted field with an embedded comma is preserved'
);

// ==============================================================================
// 📄 parseBulkInput() - CSV error shapes
// ==============================================================================

// 📌 Non-string csv value.
$csvNotString = parseBulkInput(['csv' => 12345]);
assertEquals(
    'BULK_INVALID_CSV',
    $csvNotString['code'],
    'parseBulkInput(csv): non-string csv -> BULK_INVALID_CSV'
);

// 📌 Header without a "type" column.
$csvNoType = parseBulkInput(['csv' => "url,name\nhttps://a.com,foo"]);
assertEquals(
    'BULK_CSV_NO_TYPE',
    $csvNoType['code'],
    'parseBulkInput(csv): header missing "type" -> BULK_CSV_NO_TYPE'
);

// 📌 Header with only a "type" column (no input column).
$csvNoInput = parseBulkInput(['csv' => "type\nurl"]);
assertEquals(
    'BULK_CSV_NO_INPUT',
    $csvNoInput['code'],
    'parseBulkInput(csv): header with only "type" -> BULK_CSV_NO_INPUT'
);

// 📌 Header + a reserved-only second column still counts as no input column.
$csvReservedOnly = parseBulkInput(['csv' => "type,format\nurl,png"]);
assertEquals(
    'BULK_CSV_NO_INPUT',
    $csvReservedOnly['code'],
    'parseBulkInput(csv): "type" + reserved-only column -> BULK_CSV_NO_INPUT'
);

// 📌 Valid header but no data rows.
$csvHeaderOnly = parseBulkInput(['csv' => "type,url"]);
assertEquals(
    'BULK_EMPTY',
    $csvHeaderOnly['code'],
    'parseBulkInput(csv): header but no data rows -> BULK_EMPTY'
);

// ==============================================================================
// ✅ validateBulkRequest() - valid list passes
// ==============================================================================

$okList = [
    ['type' => 'url',  'input' => ['url' => 'https://a.com'],   'customization' => []],
    ['type' => 'text', 'input' => ['text' => 'Hello'],          'customization' => []],
];

assertEquals(
    null,
    validateBulkRequest($okList, 100),
    'validateBulkRequest: a well-formed list under the cap returns null'
);

// ==============================================================================
// ✅ validateBulkRequest() - over-cap rejected with 413
// ==============================================================================

// 📌 Build a list of 3 items and validate against a cap of 2.
$threeItems = [
    ['type' => 'url', 'input' => ['url' => 'https://a.com'], 'customization' => []],
    ['type' => 'url', 'input' => ['url' => 'https://b.com'], 'customization' => []],
    ['type' => 'url', 'input' => ['url' => 'https://c.com'], 'customization' => []],
];

$overCap = validateBulkRequest($threeItems, 2);

assertTrue(
    is_array($overCap) && isset($overCap['code']),
    'validateBulkRequest: over-cap returns a structured error array'
);

assertEquals(
    'BULK_TOO_MANY_ITEMS',
    $overCap['code'],
    'validateBulkRequest: over-cap error code is BULK_TOO_MANY_ITEMS'
);

assertEquals(
    413,
    $overCap['http'],
    'validateBulkRequest: over-cap maps to HTTP 413'
);

// 📌 Exactly at the cap is allowed (boundary check).
assertEquals(
    null,
    validateBulkRequest($threeItems, 3),
    'validateBulkRequest: a list exactly at the cap is allowed'
);

// ==============================================================================
// ✅ validateBulkRequest() - empty list rejected
// ==============================================================================

$emptyValidation = validateBulkRequest([], 100);
assertEquals(
    'BULK_EMPTY',
    $emptyValidation['code'],
    'validateBulkRequest: empty list -> BULK_EMPTY'
);

// ==============================================================================
// ✅ validateBulkRequest() - malformed item (missing type) rejected
// ==============================================================================

$missingType = validateBulkRequest([
    ['type' => '', 'input' => ['url' => 'https://a.com'], 'customization' => []],
], 100);

assertEquals(
    'BULK_ITEM_MISSING_TYPE',
    $missingType['code'],
    'validateBulkRequest: empty type -> BULK_ITEM_MISSING_TYPE'
);

assertEquals(
    400,
    $missingType['http'],
    'validateBulkRequest: missing-type error is HTTP 400'
);

// ==============================================================================
// ✅ validateBulkRequest() - malformed item (missing input) rejected
// ==============================================================================

$missingInput = validateBulkRequest([
    ['type' => 'url', 'input' => [], 'customization' => []],
], 100);

assertEquals(
    'BULK_ITEM_MISSING_INPUT',
    $missingInput['code'],
    'validateBulkRequest: empty input -> BULK_ITEM_MISSING_INPUT'
);

// 📌 Non-array input (wrong type) is also rejected as missing input.
$inputNotArray = validateBulkRequest([
    ['type' => 'url', 'input' => 'https://a.com', 'customization' => []],
], 100);

assertEquals(
    'BULK_ITEM_MISSING_INPUT',
    $inputNotArray['code'],
    'validateBulkRequest: non-array input -> BULK_ITEM_MISSING_INPUT'
);

// ==============================================================================
// 🔡 bulkBuildFilename() - safe, indexed ZIP entry names
// ==============================================================================

assertEquals(
    '001-url.png',
    bulkBuildFilename(1, 'url', 'png'),
    'bulkBuildFilename: zero-pads the index and lower-cases the type'
);

assertEquals(
    '042-vcard.svg',
    bulkBuildFilename(42, 'vCard', 'svg'),
    'bulkBuildFilename: pads to 3 digits and slugs a mixed-case type'
);

// 📌 jpeg extension is passed through as given by the caller (the handler maps
//    jpeg->jpg before calling this; bulkBuildFilename itself just sanitises).
assertEquals(
    '003-my-type.jpg',
    bulkBuildFilename(3, 'my type!', 'jpg'),
    'bulkBuildFilename: non-slug characters in the type collapse to hyphens'
);

// 📌 An empty / all-symbol type falls back to "qr".
assertEquals(
    '007-qr.png',
    bulkBuildFilename(7, '!!!', 'png'),
    'bulkBuildFilename: all-symbol type falls back to "qr"'
);

// 📌 An empty extension falls back to "png".
assertEquals(
    '009-url.png',
    bulkBuildFilename(9, 'url', ''),
    'bulkBuildFilename: empty extension falls back to "png"'
);

// ==============================================================================
// ♿ FG-033 / #57 - Bulk-pipeline accessibility parity (close-out)
// ==============================================================================
// The bulk endpoint previously left the "Accessible QR" preset (E5 / #48)
// completely unenforced: buildCustomizationFromRequest() accepted
// preset=accessible per item, but neither handleBulkGenerate()'s ZIP loop nor
// handleBulkAverySheet()'s per-item loop ever called applyAccessiblePreset(),
// so a bulk caller could silently ship a WCAG-failing (sub-3:1 contrast) QR
// code that /generate would have rejected with 422. This section closes that
// gap AND completes a related documentation-vs-implementation drift: the CSV
// reserved-column list was missing 7 of buildCustomizationFromRequest()'s
// recognised keys (marker_border, marker_center, quiet_zone, version, preset,
// scan_distance_cm, pdf_layout), so a CSV-only caller had NO way to reach the
// accessible preset (or any PDF layout) at all.
//
// 🩹 Post-#57 review fix: "version" was then REVERTED back out of
// CUERCODE_API_BULK_CSV_RESERVED (see that constant's docblock in
// fn_api_bulk.php) - reserving it collided with buildPayload_vcard()'s OWN
// "version" INPUT field (the vCard spec version, '3.0'/'4.0'), silently
// turning every vCard CSV row with a "version" column into a manifest FAILED
// item (the string "4.0" was folded into customization and rejected by
// validateCustomization() as an invalid QR-symbol-version). Only SIX of the
// original seven newly-reserved names remain reserved; "version" now folds
// into the row's INPUT object like any other bare/non-reserved column, and
// is covered separately below by the vCard-collision regression test.
//
// Coverage below:
//   (a) pure parseBulkCsv() reserved-column fold - the 6 still-reserved names
//       (post-revert), plus a dedicated regression pair proving "version"
//       folds into INPUT (not customization) for a vCard row, while other
//       reserved columns (e.g. "preset") still fold into customization for a
//       non-vcard row on the SAME CSV.
//   (b) bulkCsvCoerceBoolean() - both directly and via the CSV fold, closing
//       the pre-existing string-"false"->true trap for transparent/quiet_zone.
//   (c) handleBulkGenerate() END-TO-END (SUBPROCESS): a real ZIP is streamed,
//       captured, and inspected in-process with ZipArchive - the manifest's
//       accessible detail line, the per-item FAILED line, and the ZIP entry
//       set are all asserted against REAL captured output (this repo's
//       "capture real output, then assert" convention), not a guess.
//   (d) handleBulkAverySheet() fork - same enforcement, blank-slot-not-422.
//
// Both (c) and (d) rely on Change 1's testable body injection
// (handleBulkGenerate(array $auth, ?array $requestData = null)) - php://input
// cannot be seeded from CLI PHP, so the request body is passed directly.
// ==============================================================================

// ------------------------------------------------------------------------------
// (a) parseBulkCsv() - the 6 still-reserved columns fold correctly (post
//     "version" revert - see the section header comment above)
// ------------------------------------------------------------------------------

$csvFullReserved = parseBulkInput([
    'csv' => "type,input.url,marker_border,marker_center,quiet_zone,preset,scan_distance_cm,pdf_layout\n"
        . "url,https://a.com,round,diamond,false,accessible,100,avery_5160",
]);

assertEquals(
    'round',
    $csvFullReserved['items'][0]['customization']['marker_border'],
    'parseBulkInput(csv): reserved column "marker_border" folds into customization'
);

assertEquals(
    'diamond',
    $csvFullReserved['items'][0]['customization']['marker_center'],
    'parseBulkInput(csv): reserved column "marker_center" folds into customization'
);

assertEquals(
    false,
    $csvFullReserved['items'][0]['customization']['quiet_zone'],
    'parseBulkInput(csv): reserved column "quiet_zone" folds into customization AND is boolean-coerced'
);

assertEquals(
    'accessible',
    $csvFullReserved['items'][0]['customization']['preset'],
    'parseBulkInput(csv): reserved column "preset" folds into customization'
);

assertEquals(
    '100',
    $csvFullReserved['items'][0]['customization']['scan_distance_cm'],
    'parseBulkInput(csv): reserved column "scan_distance_cm" folds into customization'
);

assertEquals(
    'avery_5160',
    $csvFullReserved['items'][0]['customization']['pdf_layout'],
    'parseBulkInput(csv): reserved column "pdf_layout" folds into customization'
);

assertFalse(
    isset($csvFullReserved['items'][0]['input']['marker_border']),
    'parseBulkInput(csv): the newly-reserved columns never leak into the input object'
);

// 📌 A header made up ENTIRELY of the still-reserved columns (plus "type")
//    still has no input column, so it must still be rejected exactly like the
//    original reserved-only case. "version" is deliberately EXCLUDED from
//    this header (it is no longer reserved - see the vCard regression pair
//    below), since including it here would supply an input column and
//    invalidate the very case this assertion is checking.
$csvReservedOnlyExpanded = parseBulkInput([
    'csv' => "type,preset,scan_distance_cm,quiet_zone,pdf_layout,marker_border\n"
        . "url,accessible,100,false,avery_5160,round",
]);

assertEquals(
    'BULK_CSV_NO_INPUT',
    $csvReservedOnlyExpanded['code'],
    'parseBulkInput(csv): header of ONLY the still-reserved columns (no input column) -> BULK_CSV_NO_INPUT'
);

// ------------------------------------------------------------------------------
// (a2) Post-#57 review fix regression: "version" un-reserved - vCard/input
//      collision. Real output captured via `php -r` before writing these
//      assertions (this repo's testing convention), per the fix task.
// ------------------------------------------------------------------------------

// 📌 A vCard row supplying a "version" column (the vCard spec version consumed
//    by buildPayload_vcard()'s $inputData['version'], '3.0'/'4.0') must have
//    that value land in the item's INPUT object, NOT customization - the
//    exact regression the pre-fix behaviour introduced (folding "4.0" into
//    customization made validateCustomization() reject it as an invalid
//    QR-symbol-version, turning a working vCard CSV row into a manifest
//    FAILED item).
$csvVcardVersion = parseBulkInput([
    'csv' => "type,first_name,last_name,version\nvcard,Ada,Lovelace,4.0",
]);

assertEquals(
    '4.0',
    $csvVcardVersion['items'][0]['input']['version'],
    'parseBulkInput(csv): vCard row\'s "version" column folds into INPUT, not customization (post-#57 review fix)'
);

assertFalse(
    isset($csvVcardVersion['items'][0]['customization']['version']),
    'parseBulkInput(csv): vCard row\'s "version" column never leaks into customization'
);

// 📌 A NON-vcard row's "version" column ALSO folds into input (it is simply a
//    bare/non-reserved column now for every type, not special-cased by type),
//    while another still-reserved column ("preset") on the SAME row continues
//    to fold into customization exactly as before - proving the revert of
//    "version" did not disturb the other reserved columns' routing.
$csvNonVcardVersion = parseBulkInput([
    'csv' => "type,input.url,preset,version\nurl,https://a.com,accessible,7",
]);

assertEquals(
    '7',
    $csvNonVcardVersion['items'][0]['input']['version'],
    'parseBulkInput(csv): a non-vcard row\'s "version" column also folds into input (no longer reserved for any type)'
);

assertEquals(
    'accessible',
    $csvNonVcardVersion['items'][0]['customization']['preset'],
    'parseBulkInput(csv): a non-vcard row\'s "preset" column still folds into customization (other reserved columns unaffected)'
);

// ------------------------------------------------------------------------------
// (b) bulkCsvCoerceBoolean() - closes the string-"false"->true trap
// ------------------------------------------------------------------------------

// 📌 Via the full CSV fold (transparent + quiet_zone together, mixed casing).
$csvBoolCoercion = parseBulkInput([
    'csv' => "type,input.url,transparent,quiet_zone\n"
        . "url,https://a.com,false,TRUE\n"
        . "url,https://b.com,0,yes",
]);

assertEquals(
    false,
    $csvBoolCoercion['items'][0]['customization']['transparent'],
    'parseBulkInput(csv): transparent="false" coerces to boolean false (closes the bare-(bool)-cast trap)'
);

assertEquals(
    true,
    $csvBoolCoercion['items'][0]['customization']['quiet_zone'],
    'parseBulkInput(csv): quiet_zone="TRUE" (mixed case) coerces to boolean true'
);

assertEquals(
    false,
    $csvBoolCoercion['items'][1]['customization']['transparent'],
    'parseBulkInput(csv): transparent="0" coerces to boolean false'
);

assertEquals(
    true,
    $csvBoolCoercion['items'][1]['customization']['quiet_zone'],
    'parseBulkInput(csv): quiet_zone="yes" coerces to boolean true'
);

// 📌 Direct unit coverage of bulkCsvCoerceBoolean() itself (all recognised
//    falsy spellings, case-insensitively, plus the truthy default).
assertEquals(false, bulkCsvCoerceBoolean('false'), 'bulkCsvCoerceBoolean("false") -> false');
assertEquals(false, bulkCsvCoerceBoolean('0'), 'bulkCsvCoerceBoolean("0") -> false');
assertEquals(false, bulkCsvCoerceBoolean('No'), 'bulkCsvCoerceBoolean("No") (case-insensitive) -> false');
assertEquals(false, bulkCsvCoerceBoolean('OFF'), 'bulkCsvCoerceBoolean("OFF") (case-insensitive) -> false');
assertEquals(true, bulkCsvCoerceBoolean('true'), 'bulkCsvCoerceBoolean("true") -> true');
assertEquals(true, bulkCsvCoerceBoolean('1'), 'bulkCsvCoerceBoolean("1") -> true');
assertEquals(true, bulkCsvCoerceBoolean('yes'), 'bulkCsvCoerceBoolean("yes") -> true');
assertEquals(
    true,
    bulkCsvCoerceBoolean('anything-else'),
    'bulkCsvCoerceBoolean(unrecognised value) -> true (matches every other boolean field\'s truthy default)'
);

// ------------------------------------------------------------------------------
// (c) handleBulkGenerate() END-TO-END (SUBPROCESS) - real ZIP + manifest
// ------------------------------------------------------------------------------
// jsonResponse()/errorResponse() call exit(), and handleBulkGenerate() itself
// echoes raw ZIP bytes then exit()s on success - both would terminate the main
// test runner if invoked in-process, so this runs in a fresh child process via
// runAccessibilitySnippet() (defined in tests/test_accessibility.php, loaded
// first by alphabetical order - same reuse convention tests/test_braille.php
// already relies on). Three items: [1] plain/preset=none (regression guard),
// [2] accessible + scan_distance_cm=100 with the default black/white contrast
// (21:1 - a 'pass'), [3] accessible with a real sub-3:1 pair (#777777 on
// #888888, captured as 1.26:1 - see tests/test_accessibility.php's own
// captured-value note for the source of this exact pair).
// ==============================================================================

$bulkZipOutput = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_response.php');
requireFn('fn_api_generate.php');
requireFn('fn_api_bulk.php');

$requestData = [
    'items' => [
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/one']],
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/two'],
         'customization' => ['preset' => 'accessible', 'scan_distance_cm' => 100]],
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/three'],
         'customization' => ['preset' => 'accessible', 'fg_color' => '#777777', 'bg_color' => '#888888']],
    ],
];

handleBulkGenerate(['method' => 'anonymous'], $requestData);
PHP
);

// ✅ The captured stdout is the raw ZIP archive - it must start with the ZIP
//    local-file-header magic bytes.
assertTrue(
    str_starts_with($bulkZipOutput, "PK\x03\x04"),
    'handleBulkGenerate() end-to-end (subprocess): captured stdout starts with the ZIP magic bytes (PK\\x03\\x04)'
);

// 📦 Write the captured bytes to a real file so ZipArchive (which needs a
//    path, not a string) can open and inspect it in-process.
$bulkZipTmpFile = tempnam(sys_get_temp_dir(), 'cuercode_test_bulk_zip_') . '.zip';
file_put_contents($bulkZipTmpFile, $bulkZipOutput);

$bulkZip       = new \ZipArchive();
$bulkZipOpened = ($bulkZip->open($bulkZipTmpFile) === true);

assertTrue(
    $bulkZipOpened,
    'handleBulkGenerate() end-to-end (subprocess): the captured ZIP bytes open successfully with ZipArchive'
);

if ($bulkZipOpened) {
    $bulkManifest = (string) $bulkZip->getFromName('manifest.txt');

    // 📌 Exactly 3 entries: item 1's + item 2's images, plus manifest.txt.
    //    Item 3 (the contrast failure) contributes NO ZIP entry.
    assertEquals(
        3,
        $bulkZip->numFiles,
        'handleBulkGenerate() end-to-end: ZIP has exactly 3 entries (2 succeeded images + manifest.txt)'
    );

    assertContains(
        'Succeeded: 2  Failed: 1',
        $bulkManifest,
        'handleBulkGenerate() end-to-end: manifest summary line reflects 2 succeeded, 1 failed'
    );

    // 📌 Real captured value (see this file's docblock note on the "capture
    //    real output, then assert" convention): round(qrContrastRatio(
    //    '#000000', '#ffffff'), 2) === 21.0, which (string) casts to "21" (no
    //    trailing ".0" - PHP does not retain it for a whole-number float).
    assertContains(
        '      accessible: contrast=21:1 (pass)  recommended_print_size_mm=100',
        $bulkManifest,
        'handleBulkGenerate() end-to-end: item 2\'s OK line is followed by the accessible detail line '
            . '(contrast + recommended_print_size_mm from scan_distance_cm=100)'
    );

    // 📌 Real captured value: qrContrastRatio('#777777', '#888888') ≈
    //    1.2632533688247014, rounded to 1.26.
    assertContains(
        '[003] FAILED  type=url  reason=Accessible preset rejected: contrast ratio 1.26:1 is below the WCAG minimum of 3:1.',
        $bulkManifest,
        'handleBulkGenerate() end-to-end: item 3 (low-contrast accessible) is a manifest FAILED line naming the real 1.26:1 ratio'
    );

    assertTrue(
        $bulkZip->locateName('001-url.png') !== false,
        'handleBulkGenerate() end-to-end: item 1\'s image entry (001-url.png) exists in the ZIP'
    );

    assertTrue(
        $bulkZip->locateName('002-url.png') !== false,
        'handleBulkGenerate() end-to-end: item 2\'s image entry (002-url.png) exists in the ZIP'
    );

    assertFalse(
        $bulkZip->locateName('003-url.png') !== false,
        'handleBulkGenerate() end-to-end: item 3\'s image entry (003-url.png) does NOT exist (contrast failure)'
    );

    // 🛡️ Regression guard: item 1 (preset=none, a complete no-op per
    //    applyAccessiblePreset()'s documented contract) must NOT gain an
    //    "accessible:" detail line - confirm none appears between item 1's
    //    and item 2's OK lines.
    $item1LinePos = strpos($bulkManifest, '[001] OK');
    $item2LinePos = strpos($bulkManifest, '[002] OK');
    if ($item1LinePos !== false && $item2LinePos !== false) {
        $betweenItem1And2 = substr($bulkManifest, $item1LinePos, $item2LinePos - $item1LinePos);
    } else {
        $betweenItem1And2 = '';
    }

    assertFalse(
        str_contains($betweenItem1And2, 'accessible:'),
        'handleBulkGenerate() end-to-end: item 1 (preset=none) OK line has NO "accessible:" detail line (default-path regression guard)'
    );

    $bulkZip->close();
}

// 🧹 Clean up the temp ZIP file.
if (is_file($bulkZipTmpFile)) {
    unlink($bulkZipTmpFile);
}

// ------------------------------------------------------------------------------
// (d) handleBulkAverySheet() fork - same enforcement, blank-slot-not-422
// ------------------------------------------------------------------------------
// Shared request-level customization forces the Avery fork (format=pdf +
// pdf_layout=avery_5160). One low-contrast accessible item alongside a plain
// item must still produce a PDF (per-item skip, not a batch abort); a request
// where EVERY item is a low-contrast accessible item must trip the
// pre-existing "nothing to render" 422 (BULK_AVERY_ALL_FAILED).
// ==============================================================================

$bulkAveryOkOutput = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_response.php');
requireFn('fn_api_generate.php');
requireFn('fn_api_bulk.php');

$requestData = [
    'customization' => ['format' => 'pdf', 'pdf_layout' => 'avery_5160'],
    'items' => [
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/avery-ok'],
         'customization' => ['preset' => 'accessible', 'fg_color' => '#777777', 'bg_color' => '#888888']],
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/avery-ok-2']],
    ],
];

handleBulkGenerate(['method' => 'anonymous'], $requestData);
PHP
);

assertTrue(
    str_starts_with($bulkAveryOkOutput, '%PDF'),
    'handleBulkAverySheet() (subprocess): one low-contrast accessible item alongside a plain item still yields a %PDF-prefixed sheet (blank slot, not batch abort)'
);

$bulkAveryAllFailedOutput = runAccessibilitySnippet(<<<'PHP'
requireLibraryAutoloader();
requireFn('fn_response.php');
requireFn('fn_api_generate.php');
requireFn('fn_api_bulk.php');

$requestData = [
    'customization' => ['format' => 'pdf', 'pdf_layout' => 'avery_5160'],
    'items' => [
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/avery-fail-1'],
         'customization' => ['preset' => 'accessible', 'fg_color' => '#777777', 'bg_color' => '#888888']],
        ['type' => 'url', 'input' => ['url' => 'https://cuercode.net/avery-fail-2'],
         'customization' => ['preset' => 'accessible', 'fg_color' => '#777777', 'bg_color' => '#888888']],
    ],
];

handleBulkGenerate(['method' => 'anonymous'], $requestData);
PHP
);

assertContains(
    'BULK_AVERY_ALL_FAILED',
    $bulkAveryAllFailedOutput,
    'handleBulkAverySheet() (subprocess): every item failing the accessible preset -> 422 BULK_AVERY_ALL_FAILED (unchanged pre-existing gate)'
);
