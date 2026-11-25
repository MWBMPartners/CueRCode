<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Installer must load its helper file before using any helper
 * ==============================================================================
 *
 * Path: tests/test_installer_load_order.php
 *
 * The fresh-install dry run (B9, 2026-11-25) found that install/index.php called
 * installLockFilePaths() BEFORE requiring the file that defines it, so every
 * request to /install/ was a fatal error (HTTP 500). The unit tests load the
 * helper file directly, so they could not see this. This test reads the
 * installer as text and checks that the require comes before the first call of
 * any function that fn_install_checks.php defines.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #113
 * @since       2026-11-25 (Codex C1 finding 6) - looks for the real require
 *              line, not the first mention of the file name
 */

$root = dirname(__DIR__) . '/web/CueRCode.net';
$installer = (string) file_get_contents($root . '/public_html/install/index.php');
$helpers = (string) file_get_contents($root . '/_functions/fn_install_checks.php');

assertTrue($installer !== '' && $helpers !== '', 'installer and helper file are readable');

// 🔎 Every function the helper file defines.
preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', $helpers, $defs);
$functionNames = $defs[1];
assertTrue(count($functionNames) > 3, 'helper file defines several functions');

/**
 * 🔎 Where a piece of installer source REALLY loads the helper file: the first
 *    code line (not a comment) that is a require/include naming
 *    fn_install_checks.php. Independent review, Codex C1 finding 6
 *    (2026-11-25): this used to be strpos() of the bare file name, which also
 *    matched a comment mentioning it, so a require moved below the first
 *    helper call could still pass if a comment above named the file.
 *
 * @param string $source Installer source text
 * @return int|false Byte offset of that line, or false when there is none
 */
function loadOrderRequirePosition(string $source): int|false
{
    $matched = preg_match(
        '/^\s*(require|require_once|include|include_once)\b[^;\n]*fn_install_checks\.php[^;\n]*;/m',
        $source,
        $found,
        PREG_OFFSET_CAPTURE
    );
    if ($matched !== 1) {
        return false;
    }
    return $found[0][1];
}

/**
 * 🔎 The first CALL (not a mention in a comment) of any helper function.
 *
 * @param string             $source        Installer source text
 * @param array<int, string> $functionNames Helper function names
 * @return array{0: int, 1: string} Offset (PHP_INT_MAX if none) and name
 */
function loadOrderFirstCall(string $source, array $functionNames): array
{
    $firstCall = PHP_INT_MAX;
    $firstName = '';
    foreach ($functionNames as $name) {
        if (preg_match('/^(?!\s*(\/\/|\*|#)).*\b' . preg_quote($name, '/') . '\s*\(/m', $source, $m, PREG_OFFSET_CAPTURE)) {
            if ($m[0][1] < $firstCall) {
                $firstCall = $m[0][1];
                $firstName = $name;
            }
        }
    }
    return [$firstCall, $firstName];
}

// 🔎 Where the installer loads the helper file.
$requirePos = loadOrderRequirePosition($installer);
assertTrue($requirePos !== false, 'installer has a real require of fn_install_checks.php (a code line, not a comment)');

[$firstCall, $firstName] = loadOrderFirstCall($installer, $functionNames);
assertTrue($firstCall !== PHP_INT_MAX, 'installer calls at least one helper function');
assertTrue(
    $requirePos !== false && $requirePos < $firstCall,
    'the helper file is loaded before the first helper call (' . $firstName . ')'
);

// 🧪 Codex C1 finding 6: prove the check catches the mistake it exists for.
//    A copy of the installer with the require moved BELOW the first helper
//    call, but with a comment naming the file left above it, must FAIL.
$requireLineMatched = preg_match('/^\s*require_once[^;\n]*fn_install_checks\.php[^;\n]*;\R/m', $installer, $requireLine);
assertEquals(1, $requireLineMatched, 'the test can find the installer\'s require line to move it');
if ($requireLineMatched === 1) {
    $brokenCopy = str_replace($requireLine[0], '// see fn_install_checks.php' . PHP_EOL, $installer);
    $brokenCopy = (string) preg_replace('/^.*\binstallIsLocked\s*\(.*$/m', '$0' . PHP_EOL . $requireLine[0], $brokenCopy, 1);
    [$brokenFirstCall] = loadOrderFirstCall($brokenCopy, $functionNames);
    $brokenRequirePos = loadOrderRequirePosition($brokenCopy);
    assertTrue(
        strpos($brokenCopy, 'fn_install_checks.php') < $brokenFirstCall,
        'C1-6 reproduction: in the broken copy the file NAME is still mentioned before the first call (the old check would have passed it)'
    );
    assertFalse(
        $brokenRequirePos !== false && $brokenRequirePos < $brokenFirstCall,
        'C1-6: the check now FAILS the broken copy, because the real require comes after the first helper call'
    );
}

// 🚫 The success page must not tell people to delete install/ (the next deploy
// puts it back without its lock; the lock now lives in _auth_keys/).
assertTrue(
    !str_contains($installer, 'Remove or restrict access to the'),
    'success page does not advise removing the install folder'
);
