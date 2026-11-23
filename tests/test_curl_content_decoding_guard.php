<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Outbound curl "no automatic decompression" guard (#150)
 * ==============================================================================
 *
 * Path: tests/test_curl_content_decoding_guard.php
 *
 * WHY THIS TEST EXISTS (plain English):
 *   CVE-2026-111497 is a flaw in the Brotli decompression library. When a PHP
 *   curl request is allowed to "ask for and unpack compressed replies"
 *   (CURLOPT_ENCODING / CURLOPT_ACCEPT_ENCODING), libcurl will unpack ANY
 *   compression it knows - including Brotli - even if the other server sends
 *   it without being asked. When those options are LEFT OFF (as our code does
 *   today), libcurl hands the reply back untouched and never runs a
 *   decompressor at all.
 *
 *   This was checked by hand on 2026-11-23 with PHP 8.3 + libcurl 8.5.0 (built
 *   with Brotli): a test server that claimed "Content-Encoding: br" was
 *   passed through untouched with the option unset, but libcurl tried to
 *   unpack it (and failed) with the option set to "gzip, deflate" or to "".
 *   So setting the option to "gzip, deflate" is NOT a safe guard - leaving it
 *   unset is.
 *
 *   This test keeps it that way: it fails if any PHP file we ship starts using
 *   those two curl options. If you genuinely need compressed replies from some
 *   service, do not just add the option - read #150 first, and update this
 *   test with the reason.
 *
 * HOW IT WORKS:
 *   It reads every PHP file under web/ (except the third-party _libraries
 *   folder) and tools/, turns each into PHP tokens, and looks for the two
 *   constant names used as real code. Words inside comments, such as this
 *   very file header, are ignored.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #150
 * @see         https://www.php.net/manual/en/function.token-get-all.php
 * @see         https://curl.se/libcurl/c/CURLOPT_ACCEPT_ENCODING.html
 */

$curlGuardRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;

// The two options that switch libcurl's automatic decompression on.
$curlGuardForbidden = [
    'CURLOPT_ENCODING',
    'CURLOPT_ACCEPT_ENCODING',
];

// ==============================================================================
// 📂 Collect the PHP files to scan
// ==============================================================================

$curlGuardFolders = [
    $curlGuardRoot . 'web',
    $curlGuardRoot . 'tools',
];
$curlGuardFiles = [];

foreach ($curlGuardFolders as $curlGuardFolder) {
    if (!is_dir($curlGuardFolder)) {
        continue;
    }
    $curlGuardIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($curlGuardFolder, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($curlGuardIterator as $curlGuardItem) {
        if (!$curlGuardItem->isFile()) {
            continue;
        }
        if ($curlGuardItem->getExtension() !== 'php') {
            continue;
        }
        $curlGuardPath = $curlGuardItem->getPathname();
        // Third-party libraries are downloaded separately and are not our code.
        if (strpos($curlGuardPath, DIRECTORY_SEPARATOR . '_libraries' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        $curlGuardFiles[] = $curlGuardPath;
    }
}
sort($curlGuardFiles);

assertTrue(count($curlGuardFiles) > 20, 'the scan found our PHP files (sanity check, so an empty scan cannot pass)');

// ==============================================================================
// 🔎 Look for the forbidden constants used as real code
// ==============================================================================

$curlGuardHits      = [];
$curlGuardCurlFiles = 0;

foreach ($curlGuardFiles as $curlGuardFile) {
    $curlGuardSource = (string) file_get_contents($curlGuardFile);
    if (stripos($curlGuardSource, 'curl_') !== false) {
        $curlGuardCurlFiles++;
    }
    $curlGuardTokens = token_get_all($curlGuardSource);
    foreach ($curlGuardTokens as $curlGuardToken) {
        if (!is_array($curlGuardToken)) {
            continue;
        }
        if ($curlGuardToken[0] !== T_STRING) {
            continue;
        }
        if (in_array($curlGuardToken[1], $curlGuardForbidden, true)) {
            $curlGuardHits[] = substr($curlGuardFile, strlen($curlGuardRoot)) . ' line ' . $curlGuardToken[2]
                . ' uses ' . $curlGuardToken[1];
        }
    }
}

// 🛡️ Sanity: the site really does make outbound curl calls (email + sign-in),
//    so the scan is looking at something. If this ever drops to 0 the test is
//    no longer proving anything.
assertTrue($curlGuardCurlFiles >= 2, 'the scan sees at least two files that make curl calls');

assertEquals(
    [],
    $curlGuardHits,
    'no shipped PHP file turns on curl automatic decompression (CURLOPT_ENCODING / CURLOPT_ACCEPT_ENCODING) - see #150'
);
