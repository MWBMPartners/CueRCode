<?php
/**
 * ==============================================================================
 * 🗺️ CueRCode - Emoji Index Generator (Issue #67 / #68)
 * ==============================================================================
 *
 * Path: tools/emoji_index.php
 *
 * A small, CLI-only, build-time helper tool. It regenerates
 * `public_html/assets/img/emoji/emoji-index.json` — the searchable,
 * categorised index the emoji centre-icon picker (index.php's Logo tab)
 * reads to render its grid — from the emoji SVG/PNG assets ACTUALLY
 * vendored under `public_html/assets/img/emoji/` (never from a wishlist:
 * an index entry always corresponds to a real, committed, resolvable file
 * pair, so the picker can never offer an emoji that `getLogoPath()` cannot
 * serve).
 *
 * ------------------------------------------------------------------------
 * 📥 TWO NAME/GROUP/KEYWORD SOURCES, IN PRIORITY ORDER
 * ------------------------------------------------------------------------
 *   1. Unicode's own `emoji-test.txt` (the CLDR/Unicode Consortium's
 *      canonical emoji catalogue: codepoint sequence, group, subgroup, and
 *      the "CLDR short name" for every valid emoji). This is the preferred,
 *      most authoritative source WHEN it can be fetched:
 *          https://unicode.org/Public/emoji/latest/emoji-test.txt
 *      Fetched with a short timeout and a hard failure budget - this tool
 *      NEVER blocks indefinitely on a slow/unreachable host.
 *   2. `tools/emoji_curated_map.php` - a hand-curated, COMMITTED fallback
 *      map (codepoint-seq -> name/group/keywords) covering every emoji in
 *      the current starter set. Used whenever (a) emoji-test.txt could not
 *      be fetched at all (this repository's sandboxed dev/CI environment
 *      routes outbound HTTPS through a proxy that blocks unicode.org - this
 *      is EXPECTED and NOT a fatal error, see Step 2 below), or (b) a
 *      vendored codepoint sequence is present on disk but absent from
 *      emoji-test.txt for some reason (defensive - should not normally
 *      happen for standard Unicode emoji).
 *
 * A codepoint sequence with NEITHER source available still gets an index
 * entry (never silently dropped - the picker must be able to show every
 * committed asset) but with a generic placeholder name so the gap is
 * visually obvious and easy to spot for a future curation pass.
 *
 * ------------------------------------------------------------------------
 * 🔁 RELATIONSHIP TO THE #68 SCHEDULED INGEST ACTION
 * ------------------------------------------------------------------------
 * `.github/workflows/emoji-ingest.yml` runs this SAME tool monthly (and on
 * manual `workflow_dispatch`) after comparing the vendored Twemoji tag +
 * Unicode `emoji-test.txt` version against upstream. On CI's network (no
 * proxy block), step 1 above is expected to succeed, giving the index the
 * fully authoritative Unicode names/groups whenever it regenerates there -
 * this local/sandboxed run simply exercises the documented fallback path.
 *
 * USAGE:
 *     php tools/emoji_index.php [--no-fetch] [--quiet]
 *
 * Optional flags:
 *     --no-fetch  Skip the emoji-test.txt fetch attempt entirely and go
 *                 straight to the curated-map fallback (useful in CI when
 *                 network flakiness would otherwise make a run non-deterministic,
 *                 and in this sandboxed environment where the fetch is known
 *                 to always fail).
 *     --quiet     Suppress the per-group summary (still prints the final
 *                 one-line result).
 *
 * IDEMPOTENCY: safe to run repeatedly; it always fully regenerates the
 * index file from the current on-disk asset set + whichever name source
 * was available, rather than incrementally patching the previous JSON.
 *
 * @package     CueRCode
 * @subpackage  Build Tools
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Issue #67 (native emoji centre-icon picker)
 * @see         https://unicode.org/Public/emoji/latest/emoji-test.txt
 * @see         tools/emoji_curated_map.php - the committed fallback name/keyword map
 * @see         web/CueRCode.net/_functions/fn_qr_logo.php - getLogoPath()'s emoji:<seq> case
 * @see         .github/workflows/emoji-ingest.yml - the scheduled Action that runs this tool
 * @see         https://www.php.net/manual/en/features.commandline.php
 */

// ==============================================================================
// 🚫 Step 0: CLI-only guard
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This tool is CLI-only.');
}

// ==============================================================================
// 🧩 Step 1: Parse CLI arguments
// ==============================================================================
$skipFetch = false;
$quiet     = false;

foreach ($argv as $argumentIndex => $argumentValue) {
    if ($argumentIndex === 0) {
        continue;
    }

    if ($argumentValue === '--no-fetch') {
        $skipFetch = true;
        continue;
    }

    if ($argumentValue === '--quiet') {
        $quiet = true;
        continue;
    }

    fwrite(STDERR, 'ERROR: Unrecognised argument: ' . $argumentValue . PHP_EOL);
    fwrite(STDERR, 'Usage: php tools/emoji_index.php [--no-fetch] [--quiet]' . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 📂 Step 2: Resolve paths (anchored relative to THIS script, mirroring
//     tools/render_logo_pngs.php's own portable-path convention)
// ==============================================================================
$repoRoot   = dirname(__DIR__);
$emojiDir   = $repoRoot
    . DIRECTORY_SEPARATOR . 'web'
    . DIRECTORY_SEPARATOR . 'CueRCode.net'
    . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'img'
    . DIRECTORY_SEPARATOR . 'emoji';
$indexPath  = $emojiDir . DIRECTORY_SEPARATOR . 'emoji-index.json';
$curatedMapPath = __DIR__ . DIRECTORY_SEPARATOR . 'emoji_curated_map.php';

if (!is_dir($emojiDir)) {
    fwrite(STDERR, 'ERROR: Emoji assets directory not found: ' . $emojiDir . PHP_EOL);
    exit(1);
}

if (!is_file($curatedMapPath)) {
    fwrite(STDERR, 'ERROR: Curated fallback map not found: ' . $curatedMapPath . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🔎 Step 3: Discover every vendored emoji (a codepoint sequence needs BOTH
//     a .svg and a .png twin present to be indexed - mirrors what
//     getLogoPath()'s emoji:<seq> case + loadLogoImage()'s PNG-twin lookup
//     actually require to serve BOTH raster and SVG QR output).
// ==============================================================================
$svgFiles = glob($emojiDir . DIRECTORY_SEPARATOR . '*.svg');
if ($svgFiles === false) {
    $svgFiles = [];
}

$vendoredSeqs = [];
foreach ($svgFiles as $svgPath) {
    $seq     = pathinfo($svgPath, PATHINFO_FILENAME);
    $pngPath = $emojiDir . DIRECTORY_SEPARATOR . $seq . '.png';

    if (is_file($pngPath)) {
        $vendoredSeqs[] = $seq;
    } else {
        fwrite(STDERR, 'WARNING: ' . $seq . '.svg has no PNG twin - skipped from index.' . PHP_EOL);
    }
}

sort($vendoredSeqs);

if (count($vendoredSeqs) === 0) {
    fwrite(STDERR, 'ERROR: No vendored emoji (SVG+PNG pairs) found in: ' . $emojiDir . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🌐 Step 4: Best-effort fetch of Unicode's emoji-test.txt (authoritative
//     names/groups). NEVER fatal on failure - this is exactly the documented
//     fallback scenario, not an error condition.
// ==============================================================================
$unicodeEntries = [];   // seq => ['name' => ..., 'group' => ...]
$fetchedUnicode = false;

if (!$skipFetch) {
    $context = stream_context_create([
        'http' => [
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    // @see https://www.php.net/manual/en/function.file-get-contents.php
    $rawEmojiTest = @file_get_contents(
        'https://unicode.org/Public/emoji/latest/emoji-test.txt',
        false,
        $context
    );

    if ($rawEmojiTest !== false && $rawEmojiTest !== '') {
        $unicodeEntries = parseEmojiTestTxt($rawEmojiTest);
        $fetchedUnicode = count($unicodeEntries) > 0;
    }
}

if ($fetchedUnicode) {
    echo 'Fetched emoji-test.txt: ' . count($unicodeEntries) . ' emoji entries parsed.' . PHP_EOL;
} else {
    echo 'emoji-test.txt unavailable (network-blocked sandbox or --no-fetch) - '
        . 'using the committed curated fallback map only.' . PHP_EOL;
}

// ==============================================================================
// 📖 Step 5: Load the curated fallback map
// ==============================================================================
$curatedMap = require $curatedMapPath;

if (!is_array($curatedMap)) {
    fwrite(STDERR, 'ERROR: Curated fallback map did not return an array.' . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🧮 Step 6: Build the index - ONE entry per vendored, on-disk seq
// ==============================================================================
$indexEntries    = [];
$unresolvedCount = 0;

foreach ($vendoredSeqs as $seq) {
    if (isset($unicodeEntries[$seq])) {
        $name     = $unicodeEntries[$seq]['name'];
        $group    = $unicodeEntries[$seq]['group'];
        $keywords = isset($curatedMap[$seq]['keywords']) ? $curatedMap[$seq]['keywords'] : [];
    } elseif (isset($curatedMap[$seq])) {
        $name     = $curatedMap[$seq]['name'];
        $group    = $curatedMap[$seq]['group'];
        $keywords = $curatedMap[$seq]['keywords'];
    } else {
        // ⚠️ Neither source covers this seq - still index it (never hide a
        //    committed asset from the picker) with an obvious placeholder.
        $name            = 'emoji ' . $seq;
        $group           = 'Uncategorised';
        $keywords        = [];
        $unresolvedCount++;
        fwrite(STDERR, 'WARNING: no name/group found for vendored seq "' . $seq . '" (placeholder used).' . PHP_EOL);
    }

    $indexEntries[] = [
        'seq'      => $seq,
        'name'     => $name,
        'group'    => $group,
        'keywords' => array_values($keywords),
    ];
}

// 📌 Stable sort: group (in the canonical Unicode group order where known,
//    else alphabetical) then name, so the picker's category tabs render in
//    a sensible, consistent order every time this tool runs.
$groupOrder = [
    'Smileys & Emotion' => 0,
    'People & Body'     => 1,
    'Animals & Nature'  => 2,
    'Food & Drink'      => 3,
    'Travel & Places'   => 4,
    'Activities'        => 5,
    'Objects'           => 6,
    'Symbols'           => 7,
    'Flags'             => 8,
];

usort($indexEntries, function (array $a, array $b) use ($groupOrder): int {
    $orderA = $groupOrder[$a['group']] ?? 99;
    $orderB = $groupOrder[$b['group']] ?? 99;

    if ($orderA !== $orderB) {
        return $orderA <=> $orderB;
    }

    return strcmp($a['name'], $b['name']);
});

// ==============================================================================
// 📦 Step 7: Write the index file
// ==============================================================================
$groupsPresent = [];
foreach ($indexEntries as $entry) {
    if (!in_array($entry['group'], $groupsPresent, true)) {
        $groupsPresent[] = $entry['group'];
    }
}

$output = [
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'source'      => $fetchedUnicode
        ? 'unicode-emoji-test.txt + tools/emoji_curated_map.php (keywords)'
        : 'tools/emoji_curated_map.php (fallback - emoji-test.txt unreachable)',
    'assetSet'    => 'Twemoji (jdecked/twemoji), CC-BY-4.0',
    'count'       => count($indexEntries),
    'groups'      => $groupsPresent,
    'emoji'       => $indexEntries,
];

$json = json_encode(
    $output,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($json === false) {
    fwrite(STDERR, 'ERROR: Failed to JSON-encode the emoji index: ' . json_last_error_msg() . PHP_EOL);
    exit(1);
}

$bytesWritten = file_put_contents($indexPath, $json . PHP_EOL);

if ($bytesWritten === false) {
    fwrite(STDERR, 'ERROR: Failed to write: ' . $indexPath . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🧮 Step 8: Summary
// ==============================================================================
if (!$quiet) {
    echo PHP_EOL . 'By group:' . PHP_EOL;
    $counts = [];
    foreach ($indexEntries as $entry) {
        $counts[$entry['group']] = ($counts[$entry['group']] ?? 0) + 1;
    }
    foreach ($counts as $group => $count) {
        echo '  ' . $group . ': ' . $count . PHP_EOL;
    }
}

echo PHP_EOL . sprintf(
    'Wrote %s: %d emoji indexed (%d unresolved/placeholder).',
    $indexPath,
    count($indexEntries),
    $unresolvedCount
) . PHP_EOL;

exit(0);

// ==============================================================================
// 🧰 parseEmojiTestTxt() - Parse Unicode's emoji-test.txt into a seq => data map
// ==============================================================================

/**
 * 🧰 Parse the Unicode `emoji-test.txt` format into codepoint-seq => name/group.
 *
 * The file format (comment-heavy, one data line per emoji) looks like:
 *
 *   1F600                                      ; fully-qualified     # 😀 E1.0 grinning face
 *   1F1E6 1F1E8                                 ; fully-qualified     # 🇦🇨 E2.0 flag: Ascension Island
 *
 * with `# group: Smileys & Emotion` / `# subgroup: face-smiling` marker
 * comment lines interspersed that establish the CURRENT group for every
 * subsequent data line, until the next marker changes it.
 *
 * Only `fully-qualified` lines are used (Unicode also lists
 * `minimally-qualified`/`unqualified` variants of the same emoji - the
 * fully-qualified form is the canonical one, and is what Twemoji's own
 * filenames are derived from once FE0F is stripped).
 *
 * @param string $raw The raw emoji-test.txt file contents.
 *
 * @return array<string, array{name: string, group: string}> Keyed by the
 *         Twemoji-style codepoint sequence (lowercase hex, hyphen-joined,
 *         FE0F stripped - matching this tool's filename convention).
 *
 * @see https://unicode.org/Public/emoji/latest/emoji-test.txt
 */
function parseEmojiTestTxt(string $raw): array
{
    $entries     = [];
    $currentGroup = '';

    $lines = preg_split('/\r\n|\r|\n/', $raw);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        // 📌 Group marker comment: "# group: Smileys & Emotion"
        if (preg_match('/^#\s*group:\s*(.+)$/', $line, $groupMatch) === 1) {
            $currentGroup = trim($groupMatch[1]);
            continue;
        }

        // 📌 Skip all other comments/blank lines.
        $trimmedLine = trim($line);
        if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
            continue;
        }

        // 📌 Data line: "<codepoints> ; <qualification> # <emoji> E<version> <name>"
        if (preg_match(
            '/^([0-9A-Fa-f ]+)\s*;\s*fully-qualified\s*#.*?\s+E[0-9.]+\s+(.+)$/',
            $trimmedLine,
            $dataMatch
        ) !== 1) {
            continue;
        }

        $codepointParts = preg_split('/\s+/', trim($dataMatch[1]));
        if ($codepointParts === false || count($codepointParts) === 0) {
            continue;
        }

        // 📌 Lowercase hex, strip FE0F (matches Twemoji's own filename convention).
        $seqParts = [];
        foreach ($codepointParts as $part) {
            $lowerHex = strtolower($part);
            if ($lowerHex === 'fe0f') {
                continue;
            }
            $seqParts[] = $lowerHex;
        }

        if (count($seqParts) === 0) {
            continue;
        }

        $seq  = implode('-', $seqParts);
        $name = trim($dataMatch[2]);

        $entries[$seq] = [
            'name'  => $name,
            'group' => $currentGroup !== '' ? $currentGroup : 'Uncategorised',
        ];
    }

    return $entries;
}
