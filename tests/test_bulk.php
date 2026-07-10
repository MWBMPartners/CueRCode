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
 *   - parseBulkInput()      (items-array shape + CSV shape + error shapes)
 *   - parseBulkCsv()        (header detection, column classification)
 *   - validateBulkRequest() (cap enforcement, per-item shape validation)
 *   - bulkBuildFilename()   (safe ZIP entry naming)
 *
 * These helpers perform NO database, filesystem, or QR-library work, so they
 * run fully self-contained under the CLI harness via requireFn(). The handler
 * handleBulkGenerate() itself (which streams a real ZIP over HTTP) is NOT
 * unit-tested here — that full HTTP + ZipArchive path needs live verification.
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
 * @version     1.0.0
 * @since       Phase 2 (FG-003 bulk generation; Epic E4/#53 cap note above)
 * @see         web/CueRCode.net/_functions/fn_api_bulk.php
 * @see         web/CueRCode.net/_functions/fn_features.php
 * @see         tests/test_features.php - the plan-based bulk_batch_size gate coverage
 * @see         https://www.php.net/manual/en/function.str-getcsv.php
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
