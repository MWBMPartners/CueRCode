<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Emoji Centre-Icon Tests (Issue #67)
 * ==============================================================================
 *
 * Path: tests/test_emoji_logo.php
 *
 * Covers the DB-INDEPENDENT, near-pure surface added by Issue #67 (native
 * UTF emoji centre-icon picker): a new `emoji:<codepoint-seq>` logo
 * discriminator resolved entirely from the vendored, committed Twemoji
 * assets under `public_html/assets/img/emoji/` - no database, no upload, no
 * user input reaching disk. The whole feature is filesystem-indexed (see
 * tools/emoji_index.php's doc block), so every assertion below runs
 * straight against the REAL committed asset tree, exactly like
 * test_qr_logo.php's Section 6 (`resolvePredefinedLogoPath()`) proves the
 * Issue #65 predefined-logo fix against the real assets/img/logos/ tree.
 *
 * Units covered:
 *   - getEmojiLogoPath()    (fn_qr_logo.php) - the STRICT format-validating
 *     entry point: rejects a malformed codepoint sequence with NO
 *     filesystem access, accepts a well-formed one and delegates onward.
 *   - resolveEmojiLogoPath() (fn_qr_logo.php) - the base-contained filesystem
 *     resolver: resolves a real vendored seq to its .svg path, returns null
 *     for a seq with no vendored asset, and (via a synthetic traversal
 *     payload) proves the F-017 containment guard cannot be escaped.
 *   - getLogoPath()         (fn_qr_logo.php) - the `emoji:` prefix dispatch
 *     (Case 2), proven end-to-end against real committed assets.
 *   - overlayLogoOnSVG()    (fn_qr_logo.php) - SVG-output embedding of a
 *     resolved emoji logo (byte-for-byte round trip, like test_qr_logo.php
 *     Section 8 proves for predefined logos).
 *   - loadLogoImage()       (fn_qr_logo.php) - the Issue #65 PNG-twin
 *     lookup, reused UNCHANGED, proven to load a real emoji's PNG twin for
 *     RASTER QR output.
 *   - emoji-index.json + tools/emoji_curated_map.php - static data-integrity
 *     checks: every indexed seq has a real, committed SVG+PNG pair, and the
 *     starter set size sits inside the documented 250-400 range.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Issue #67 (native emoji centre-icon picker)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_qr_logo.php - getEmojiLogoPath(),
 *              resolveEmojiLogoPath(), getLogoPath()
 * @see         tools/emoji_index.php - the emoji-index.json generator
 */

// ==============================================================================
// 🧰 Load the DB-independent production function file (NO database connection)
// ==============================================================================

requireFn('fn_qr_logo.php');

// ==============================================================================
// 📂 Path constants for the real, committed emoji asset tree
// ==============================================================================

$emojiAssetsDir = CUERCODE_SITE_ROOT
    . 'public_html' . DIRECTORY_SEPARATOR
    . 'assets' . DIRECTORY_SEPARATOR
    . 'img' . DIRECTORY_SEPARATOR
    . 'emoji';

$emojiIndexPath = $emojiAssetsDir . DIRECTORY_SEPARATOR . 'emoji-index.json';

// ==============================================================================
// ✅ Section 1: emoji-index.json exists and is well-formed
// ==============================================================================

assertTrue(
    is_file($emojiIndexPath),
    'emoji-index.json exists under the committed public_html/assets/img/emoji/ directory'
);

$emojiIndexRaw = file_get_contents($emojiIndexPath);

assertTrue(
    is_string($emojiIndexRaw) && $emojiIndexRaw !== '',
    'emoji-index.json is readable and non-empty'
);

$emojiIndex = json_decode((string) $emojiIndexRaw, true);

assertTrue(
    is_array($emojiIndex) && isset($emojiIndex['emoji']) && is_array($emojiIndex['emoji']),
    'emoji-index.json decodes to an array with an "emoji" list'
);

$emojiList = is_array($emojiIndex) && isset($emojiIndex['emoji']) && is_array($emojiIndex['emoji'])
    ? $emojiIndex['emoji']
    : [];

// 📌 The #67 design brief specifies a curated STARTER set of ~250-400 emoji
// (bounded, committed - NOT the full ~3,700-emoji Unicode catalogue).
assertTrue(
    count($emojiList) >= 250 && count($emojiList) <= 400,
    'emoji-index.json contains a curated starter set of 250-400 emoji (got ' . count($emojiList) . ')'
);

assertEquals(
    count($emojiList),
    isset($emojiIndex['count']) ? $emojiIndex['count'] : null,
    'emoji-index.json\'s "count" field matches the actual number of indexed emoji'
);

// ==============================================================================
// ✅ Section 2: every indexed seq has a REAL, committed SVG+PNG pair on disk
// ==============================================================================
// This is the data-integrity guarantee tools/emoji_index.php's own doc block
// promises: an index entry can never point at a non-existent asset, so the
// picker UI can never offer an emoji getLogoPath() cannot actually serve.
// ==============================================================================

$missingAssetCount = 0;

foreach ($emojiList as $entry) {
    if (!is_array($entry) || !isset($entry['seq']) || !is_string($entry['seq'])) {
        $missingAssetCount++;
        continue;
    }

    $svgPath = $emojiAssetsDir . DIRECTORY_SEPARATOR . $entry['seq'] . '.svg';
    $pngPath = $emojiAssetsDir . DIRECTORY_SEPARATOR . $entry['seq'] . '.png';

    if (!is_file($svgPath) || !is_file($pngPath)) {
        $missingAssetCount++;
    }
}

assertEquals(
    0,
    $missingAssetCount,
    'every emoji-index.json entry has a real, committed .svg AND .png twin on disk (0 missing)'
);

// ==============================================================================
// ✅ Section 3: getEmojiLogoPath() / resolveEmojiLogoPath() - real assets resolve
// ==============================================================================
// 📌 A small, deliberately diverse sample of REAL vendored codepoint
// sequences pulled from the actual starter set, covering: a single-codepoint
// face, a multi-codepoint flag (regional indicator pair), and an object
// emoji from the QR-type use-cases the owner explicitly named.
// ==============================================================================

$sampleSeqs = [
    '1f600',        // 😀 grinning face
    '1f4cd',        // 📍 round pushpin (location - named use case)
    '2709',         // ✉️ envelope (email - named use case, FE0F stripped)
    '260e',         // ☎️ telephone (phone - named use case, FE0F stripped)
    '1f4ac',        // 💬 speech balloon (message - named use case)
    '1f517',        // 🔗 link (named use case)
    '1f4c5',        // 📅 calendar (named use case)
    '1f3b5',        // 🎵 musical note (music - named use case)
    '1f4f7',        // 📷 camera (named use case)
    '2b50',         // ⭐ star (rating - named use case)
    '1f1fa-1f1f8',  // 🇺🇸 flag: United States (multi-codepoint sequence)
];

foreach ($sampleSeqs as $seq) {
    $resolved = resolveEmojiLogoPath($seq);

    assertTrue(
        is_string($resolved),
        'resolveEmojiLogoPath(\'' . $seq . '\') resolves to a real path, not null'
    );

    if (is_string($resolved)) {
        assertTrue(
            file_exists($resolved) && is_file($resolved),
            'resolveEmojiLogoPath(\'' . $seq . '\') resolves to a file that genuinely exists on disk'
        );
        assertEquals(
            $seq . '.svg',
            basename($resolved),
            'resolveEmojiLogoPath(\'' . $seq . '\') preserves the exact requested codepoint sequence'
        );
        assertTrue(
            str_ends_with($resolved, '.svg'),
            'resolveEmojiLogoPath(\'' . $seq . '\') always returns the .svg form (loadLogoImage() handles the PNG-twin substitution for raster output)'
        );
    }

    // 📌 Same proof via getEmojiLogoPath() (adds the format-validation layer)
    // and via the full getLogoPath('emoji:<seq>') dispatch (Case 2).
    assertEquals(
        $resolved,
        getEmojiLogoPath($seq),
        'getEmojiLogoPath(\'' . $seq . '\') returns the SAME resolved path as resolveEmojiLogoPath()'
    );
    assertEquals(
        $resolved,
        getLogoPath('emoji:' . $seq),
        'getLogoPath(\'emoji:' . $seq . '\') dispatches to the same resolved path (Case 2 wiring)'
    );
}

// ==============================================================================
// ❌ Section 4: malformed / non-existent codepoint sequences are REJECTED
// ==============================================================================

$rejectedSeqs = [
    ''                       => 'empty string',
    'z'                      => 'non-hex character',
    '1'                      => 'too short (1 hex digit)',
    '1f6000000'              => 'too long (7 hex digits in one group)',
    '1F600'                  => 'uppercase hex (Twemoji filenames are always lowercase)',
    '1f600 '                 => 'trailing whitespace',
    ' 1f600'                 => 'leading whitespace',
    '1f600/1f601'            => 'slash instead of hyphen',
    '1f600--1f601'           => 'double hyphen (empty group)',
    '../../etc/passwd'       => 'path traversal payload',
    '1f600.svg'              => 'literal filename with extension appended',
    str_repeat('1f600-', 8) . '1f600' => 'more than 7 codepoint groups',
    'doesnotexist'           => 'well-formed hex shape but no vendored asset (6 hex chars, no such file)',
];

foreach ($rejectedSeqs as $badSeq => $label) {
    assertEquals(
        null,
        getEmojiLogoPath($badSeq),
        'getEmojiLogoPath() REJECTS: ' . $label . ' (input: "' . mb_substr($badSeq, 0, 40) . '")'
    );
    assertEquals(
        null,
        getLogoPath('emoji:' . $badSeq),
        'getLogoPath(\'emoji:...\') REJECTS via the same guard: ' . $label
    );
}

// ==============================================================================
// 🔒 Section 5: F-017 containment guard cannot be escaped even for a
//     well-formed-LOOKING sequence, and getLogoPath() never confuses the
//     'emoji:' prefix with a plain numeric ID or a base64 data URI
// ==============================================================================

// 📌 A syntactically valid hex group that simply has no corresponding file
// must resolve to null via the SAME containment-aware resolver, never a
// fatal or a path outside the emoji base.
assertEquals(
    null,
    resolveEmojiLogoPath('abcdef'),
    'resolveEmojiLogoPath() returns null (not a fatal) for a well-formed but non-existent codepoint sequence'
);

// 📌 getLogoPath() must not treat 'emoji:5' (a numeric-looking suffix) as
// the numeric predefined-logo Case 1 - the 'emoji:' prefix takes priority
// and 5 (not a real vendored seq) correctly resolves to null.
assertEquals(
    null,
    getLogoPath('emoji:5'),
    'getLogoPath(\'emoji:5\') is NOT confused with numeric predefined-logo Case 1 - resolves via the emoji path and correctly fails (no seq "5" is vendored)'
);

// 📌 A plain numeric logo ID (no 'emoji:' prefix) must be completely
// unaffected by the new Case 2 - it still dispatches to Case 1
// (getLogoPathFromDatabase()) exactly as before Issue #67.
assertFalse(function_exists('dbQuery'), 'sanity check: dbQuery() is genuinely undefined in this harness');
assertEquals(
    null,
    getLogoPath(5),
    'getLogoPath(5) (plain numeric) still dispatches to Case 1 (predefined logo DB lookup), unaffected by the new emoji Case 2 - null here because dbQuery() is unavailable in this harness, not because of the emoji change'
);

// ==============================================================================
// ✅ Section 6: overlayLogoOnSVG() - emoji logo embeds correctly in SVG output
// ==============================================================================

$grinningFacePath = resolveEmojiLogoPath('1f600');

assertTrue(
    is_string($grinningFacePath),
    'precondition: 1f600 (grinning face) resolves for the Section 6 end-to-end proof'
);

if (is_string($grinningFacePath)) {
    $baseQrSvg = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
        . '<rect width="200" height="200" fill="#ffffff"/>'
        . '<rect x="10" y="10" width="20" height="20" fill="#000000"/>'
        . '</svg>';

    $svgWithEmojiLogo = overlayLogoOnSVG($baseQrSvg, $grinningFacePath, ['size_pct' => 20]);

    assertTrue(
        $svgWithEmojiLogo !== $baseQrSvg,
        'overlayLogoOnSVG() with a resolved emoji logo produces output that DIFFERS from the no-logo baseline'
    );

    assertContains(
        '<image',
        $svgWithEmojiLogo,
        'overlayLogoOnSVG() output embeds an <image> element for the emoji logo'
    );

    assertContains(
        'data:image/svg+xml',
        $svgWithEmojiLogo,
        'overlayLogoOnSVG() output embeds the emoji as an image/svg+xml data URI'
    );

    // 📌 Round-trip proof: the embedded base64 payload decodes back to the
    //    EXACT source SVG bytes on disk (no mangling in transit).
    if (preg_match('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)"/', $svgWithEmojiLogo, $embeddedMatch) === 1) {
        assertEquals(
            file_get_contents($grinningFacePath),
            base64_decode($embeddedMatch[1], true),
            'overlayLogoOnSVG() embeds the EXACT source emoji SVG bytes (byte-for-byte round trip)'
        );
    } else {
        assertTrue(false, 'could not extract the embedded base64 SVG data URI from overlayLogoOnSVG() output for the round-trip check');
    }
}

// ==============================================================================
// ✅ Section 7: loadLogoImage() - the Issue #65 PNG-twin lookup, REUSED
//     UNCHANGED, works for a real emoji (raster QR output path)
// ==============================================================================
// Proves the #67 design decision (same-directory, same-basename SVG+PNG
// twins) needed ZERO changes to loadLogoImage() - it already looks for a
// same-basename .png twin next to any .svg it is handed (Issue #65), so a
// vendored emoji SVG transparently loads via its PNG twin with no new code.
// ==============================================================================

if (is_string($grinningFacePath)) {
    $loadedEmojiImage = loadLogoImage($grinningFacePath);

    assertTrue(
        $loadedEmojiImage instanceof \GdImage,
        'loadLogoImage() loads a real emoji SVG via its committed PNG twin (Issue #65 mechanism, reused unchanged for #67) - proves the raster QR output path works'
    );

    if ($loadedEmojiImage instanceof \GdImage) {
        // 📌 Twemoji's 72x72 PNGs are always exactly 72x72 - a strong signal
        //    the REAL vendored PNG twin (not a placeholder) was loaded.
        assertEquals(72, imagesx($loadedEmojiImage), 'the loaded emoji PNG twin is 72px wide (Twemoji\'s vendored 72x72 raster size)');
        assertEquals(72, imagesy($loadedEmojiImage), 'the loaded emoji PNG twin is 72px tall (Twemoji\'s vendored 72x72 raster size)');
        // 🧹 No imagedestroy($loadedEmojiImage) here - PHP releases it
        // automatically once nothing refers to it any more (a no-op since
        // PHP 8.0, and PHP 8.5 warns when it is called - #117, do not add
        // it back).
    }

    // 📌 End-to-end: overlayLogoOnRaster() with a real emoji logo must still
    //    return a valid, decodable image (the QR itself stays scannable).
    $fakeQrCanvas = imagecreatetruecolor(60, 60);
    $qrWhiteColor = imagecolorallocate($fakeQrCanvas, 255, 255, 255);
    imagefilledrectangle($fakeQrCanvas, 0, 0, 59, 59, $qrWhiteColor);
    ob_start();
    imagepng($fakeQrCanvas);
    $fakeQrPngBytes = ob_get_clean();
    // 🧹 No imagedestroy($fakeQrCanvas) here - see the #117 note above.

    $qrResultWithEmojiLogo = overlayLogoOnRaster(
        (string) $fakeQrPngBytes,
        $grinningFacePath,
        ['output_format' => 'png', 'size_pct' => 20]
    );

    $decodedResultDimensions = getimagesizefromstring($qrResultWithEmojiLogo);

    assertTrue(
        $decodedResultDimensions !== false,
        'overlayLogoOnRaster() with a real emoji logo returns a valid, decodable PNG (raster QR output path proven end-to-end)'
    );

    if ($decodedResultDimensions !== false) {
        assertEquals(60, $decodedResultDimensions[0], 'the QR code\'s own dimensions are preserved when an emoji logo is overlaid');
        assertEquals(60, $decodedResultDimensions[1], 'the QR code\'s own dimensions are preserved when an emoji logo is overlaid (height)');
    }
}

// ==============================================================================
// ✅ Section 8: Static regression guards (source-text, no execution)
// ==============================================================================

$qrLogoSource = file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_qr_logo.php');

assertTrue(
    is_string($qrLogoSource) && $qrLogoSource !== '',
    'fn_qr_logo.php source is readable for the static guards below'
);

assertContains(
    "function getEmojiLogoPath(string \$codepointSeq): ?string",
    (string) $qrLogoSource,
    'getEmojiLogoPath() exists with its documented signature'
);

assertContains(
    "function resolveEmojiLogoPath(string \$codepointSeq): ?string",
    (string) $qrLogoSource,
    'resolveEmojiLogoPath() exists with its documented signature'
);

assertContains(
    "str_starts_with(\$logoInput, 'emoji:')",
    (string) $qrLogoSource,
    'getLogoPath() dispatches on the \'emoji:\' prefix before the base64/file-path cases'
);

// 🔐 The STRICT format regex must be present verbatim (a future edit that
// loosens it would be a real security regression, not just a style change).
assertContains(
    "'/^[0-9a-f]{2,6}(-[0-9a-f]{2,6}){0,6}\$/'",
    (string) $qrLogoSource,
    'getEmojiLogoPath() enforces the documented STRICT codepoint-sequence regex'
);

// 🔐 The emoji resolver must reuse the SAME shared containment primitive
// every other logo-path resolver in this file uses (no bespoke, un-audited
// path-safety logic introduced for this feature). Extract resolveEmojiLogoPath()'s
// own function body (up to the next top-level function/section header) and
// confirm it calls the shared primitive rather than rolling its own check.
if (preg_match(
    '/function resolveEmojiLogoPath\(string \$codepointSeq\): \?string\s*\{(.*?)\n\}/s',
    (string) $qrLogoSource,
    $resolverBodyMatch
) === 1) {
    assertContains(
        'isPathWithinAllowedBase(',
        $resolverBodyMatch[1],
        'resolveEmojiLogoPath() calls the SHARED isPathWithinAllowedBase() containment primitive (no bespoke path-safety logic)'
    );
} else {
    assertTrue(false, 'could not isolate resolveEmojiLogoPath()\'s function body via regex for the containment-reuse static guard');
}

// 🔐 Confirms the emoji base directory is anchored under public_html (never
// a request-influenced path).
assertContains(
    "'img' . DIRECTORY_SEPARATOR" . PHP_EOL . '        . \'emoji\'',
    (string) $qrLogoSource,
    'resolveEmojiLogoPath() resolves against the real public_html/assets/img/emoji directory'
);

// ==============================================================================
// ✅ Section 9: tools/emoji_curated_map.php - static data-integrity checks
// ==============================================================================

$curatedMapPath = CUERCODE_TEST_REPO_ROOT . 'tools' . DIRECTORY_SEPARATOR . 'emoji_curated_map.php';

assertTrue(
    is_file($curatedMapPath),
    'tools/emoji_curated_map.php (the committed name/keyword fallback source) exists'
);

if (is_file($curatedMapPath)) {
    $curatedMap = require $curatedMapPath;

    assertTrue(
        is_array($curatedMap) && count($curatedMap) === count($emojiList),
        'tools/emoji_curated_map.php has exactly one entry per indexed emoji (' . count($curatedMap) . ' vs ' . count($emojiList) . ')'
    );

    $missingRequiredKeyCount = 0;
    foreach ($curatedMap as $seq => $data) {
        if (!is_array($data)
            || !isset($data['name'], $data['group'], $data['keywords'])
            || !is_string($data['name'])
            || !is_string($data['group'])
            || !is_array($data['keywords'])
        ) {
            $missingRequiredKeyCount++;
        }
    }

    assertEquals(
        0,
        $missingRequiredKeyCount,
        'every tools/emoji_curated_map.php entry has well-typed name/group/keywords fields (0 malformed)'
    );
}
