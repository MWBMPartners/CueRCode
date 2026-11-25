<?php
/**
 * ==============================================================================
 * 🔎 CueRCode - Check that the committed third-party libraries match the pins
 * ==============================================================================
 *
 * Path: tools/check_vendored_libraries.php
 *
 * Decision 0011 (#113): the QR, PDF and barcode libraries are COMMITTED under
 * web/CueRCode.net/_libraries/. CI used to clone them; now it runs this check
 * instead, so a hand edit or an accidental upgrade cannot slip in unnoticed.
 *
 * For each library listed in web/CueRCode.net/_libraries/PINNED_VERSIONS.json
 * it checks that:
 *   1. the folder exists and holds files,
 *   2. where the library carries its own version marker (a VERSION file, or the
 *      VERSION constant in fpdf.php) the marker says the pinned version, and
 *   3. a fingerprint (SHA-256 over every file's path and contents) matches.
 *
 * Usage:   php tools/check_vendored_libraries.php            (check, exit 0/1)
 *          php tools/check_vendored_libraries.php --update   (rewrite the
 *          fingerprints after a DELIBERATE upgrade; review the diff, then also
 *          change the pinned versions in the manifest, ci.yml notes and
 *          .claude/CLAUDE.md as needed)
 *
 * @package     CueRCode
 * @subpackage  Tools
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #113
 * @see         docs/decisions/0011-commit-third-party-libraries.md
 */

/**
 * 🧮 Fingerprint a folder: SHA-256 over sorted "relative/path NUL file-hash" lines.
 *
 * Path separators are normalised to "/" so Windows and Unix agree.
 *
 * @param string $dir Absolute folder path.
 *
 * @return string 64-character hex fingerprint, or '' if the folder is missing/empty.
 */
function vendoredLibraryFingerprint(string $dir): string
{
    if (!is_dir($dir)) {
        return '';
    }

    $lines    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        // 🍎 macOS Finder litter is not part of a library (review fix).
        if ($fileInfo->getFilename() === '.DS_Store') {
            continue;
        }
        $relative = substr($fileInfo->getPathname(), strlen(rtrim($dir, '/\\')) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $lines[]  = $relative . "\0" . hash_file('sha256', $fileInfo->getPathname());
    }

    if ($lines === []) {
        return '';
    }
    sort($lines, SORT_STRING);

    return hash('sha256', implode("\n", $lines));
}

/**
 * 🔗 List symbolic links inside a library folder (review fix).
 *
 * A link can point at a file or folder OUTSIDE the library, so the fingerprint
 * would follow it and the pinned copy could change without a visible edit
 * (or, on another machine, the link may not exist at all). Links are
 * reported so a person can replace them with real files.
 *
 * @param string $dir Absolute folder path.
 *
 * @return array<int, string> Relative paths of links ('/' separators).
 */
function vendoredLibrarySymlinks(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $links    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isLink()) {
            $relative = substr($fileInfo->getPathname(), strlen(rtrim($dir, '/\\')) + 1);
            $links[]  = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
    }
    sort($links, SORT_STRING);

    return $links;
}

/**
 * ✅ Check every pinned library and return a list of plain-English problems.
 *
 * @param string               $libraryRoot Absolute path of the _libraries folder.
 * @param array<string, mixed> $manifest    Decoded PINNED_VERSIONS.json.
 *
 * @return array<int, string> Problems found (empty array means all good).
 */
function vendoredLibrariesCheck(string $libraryRoot, array $manifest): array
{
    $problems = [];

    if (!isset($manifest['libraries']) || !is_array($manifest['libraries']) || $manifest['libraries'] === []) {
        return ['The manifest lists no libraries.'];
    }

    foreach ($manifest['libraries'] as $name => $pin) {
        $path = $libraryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($pin['path'] ?? ''));

        if (!is_dir($path)) {
            $problems[] = $name . ': folder ' . ($pin['path'] ?? '(none)') . ' is missing.';
            continue;
        }

        // 🏷️ Version marker inside the library itself (when it has one).
        if (isset($pin['marker']['file'], $pin['marker']['regex'])) {
            $markerFile = $path . DIRECTORY_SEPARATOR . $pin['marker']['file'];
            $contents   = is_file($markerFile) ? (string) file_get_contents($markerFile) : '';
            $found      = '';
            if (preg_match($pin['marker']['regex'], $contents, $m) === 1) {
                $found = trim($m[1]);
            }
            if ($found !== (string) $pin['version']) {
                $problems[] = $name . ': version marker says "' . $found . '" but ' . $pin['version'] . ' is pinned.';
            }
        }

        // 🔗 Symbolic links are not allowed inside a pinned library.
        foreach (vendoredLibrarySymlinks($path) as $link) {
            $problems[] = $name . ': "' . $link . '" is a symbolic link; replace it with the real file.';
        }

        // 🔏 Fingerprint of every file.
        $actual = vendoredLibraryFingerprint($path);
        if ($actual === '') {
            $problems[] = $name . ': folder is empty.';
        } elseif (!hash_equals((string) ($pin['sha256'] ?? ''), $actual)) {
            $problems[] = $name . ': files differ from the pinned ' . $pin['version']
                . ' copy (edited, added or removed files).';
        }
    }

    return $problems;
}

// ==============================================================================
// 🖥️ Command-line entry point (skipped when a test includes this file)
// ==============================================================================
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $libraryRoot  = dirname(__DIR__) . '/web/CueRCode.net/_libraries';
    $manifestPath = $libraryRoot . '/PINNED_VERSIONS.json';
    $manifest     = json_decode((string) file_get_contents($manifestPath), true);

    if (!is_array($manifest)) {
        fwrite(STDERR, 'Cannot read ' . $manifestPath . PHP_EOL);
        exit(1);
    }

    if (in_array('--update', $argv, true)) {
        foreach ($manifest['libraries'] as $name => $pin) {
            $manifest['libraries'][$name]['sha256'] = vendoredLibraryFingerprint(
                $libraryRoot . '/' . $pin['path']
            );
        }
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        echo 'Fingerprints rewritten. Review the diff before committing.' . PHP_EOL;
        exit(0);
    }

    $problems = vendoredLibrariesCheck($libraryRoot, $manifest);
    if ($problems === []) {
        echo 'Vendored libraries OK (' . count($manifest['libraries']) . ' checked).' . PHP_EOL;
        exit(0);
    }
    foreach ($problems as $problem) {
        echo 'PROBLEM: ' . $problem . PHP_EOL;
    }
    exit(1);
}
