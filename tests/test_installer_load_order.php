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
 */

$root = dirname(__DIR__) . '/web/CueRCode.net';
$installer = (string) file_get_contents($root . '/public_html/install/index.php');
$helpers = (string) file_get_contents($root . '/_functions/fn_install_checks.php');

assertTrue($installer !== '' && $helpers !== '', 'installer and helper file are readable');

// 🔎 Every function the helper file defines.
preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', $helpers, $defs);
$functionNames = $defs[1];
assertTrue(count($functionNames) > 3, 'helper file defines several functions');

// 🔎 Where the installer loads the helper file.
$requirePos = strpos($installer, "fn_install_checks.php");
assertTrue($requirePos !== false, 'installer requires fn_install_checks.php');

// 🔎 The first CALL (not a mention in a comment) of any helper function.
$firstCall = PHP_INT_MAX;
$firstName = '';
foreach ($functionNames as $name) {
    if (preg_match('/^(?!\s*(\/\/|\*|#)).*\b' . preg_quote($name, '/') . '\s*\(/m', $installer, $m, PREG_OFFSET_CAPTURE)) {
        if ($m[0][1] < $firstCall) {
            $firstCall = $m[0][1];
            $firstName = $name;
        }
    }
}
assertTrue($firstCall !== PHP_INT_MAX, 'installer calls at least one helper function');
assertTrue(
    $requirePos !== false && $requirePos < $firstCall,
    'the helper file is loaded before the first helper call (' . $firstName . ')'
);

// 🚫 The success page must not tell people to delete install/ (the next deploy
// puts it back without its lock; the lock now lives in _auth_keys/).
assertTrue(
    !str_contains($installer, 'Remove or restrict access to the'),
    'success page does not advise removing the install folder'
);
