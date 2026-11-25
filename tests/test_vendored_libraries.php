<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the vendored-library pin check (decision 0011, #113)
 * ==============================================================================
 *
 * Path: tests/test_vendored_libraries.php
 *
 * Proves tools/check_vendored_libraries.php (a) passes on the real committed
 * libraries, and (b) catches a missing folder, an edited file, an extra file and
 * a wrong version marker, using a throwaway copy so nothing real is touched.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #113
 */

require_once dirname(__DIR__) . '/tools/check_vendored_libraries.php';

$libRoot  = dirname(__DIR__) . '/web/CueRCode.net/_libraries';
$manifest = json_decode((string) file_get_contents($libRoot . '/PINNED_VERSIONS.json'), true);

// ✅ The real committed copies match their pins.
assertTrue(is_array($manifest), 'PINNED_VERSIONS.json decodes');
assertEquals(5, count($manifest['libraries']), 'five libraries are pinned');
assertEquals([], vendoredLibrariesCheck($libRoot, $manifest), 'committed libraries match the pinned versions');
assertEquals('5.0.5', $manifest['libraries']['chillerlan/php-qrcode']['version'], 'php-qrcode pinned at 5.0.5');
assertEquals('1.86', $manifest['libraries']['setasign/fpdf']['version'], 'FPDF pinned at 1.86');

// 🧰 Build a tiny throwaway library tree with one marker-bearing library.
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_vendor_' . bin2hex(random_bytes(4));
mkdir($base . '/demo', 0700, true);
file_put_contents($base . '/demo/VERSION', "1.2.3\n");
file_put_contents($base . '/demo/code.php', "<?php // demo\n");

$demo = [
    'libraries' => [
        'acme/demo' => [
            'version' => '1.2.3',
            'path'    => 'demo',
            'marker'  => ['file' => 'VERSION', 'regex' => '/^\s*(\S+)/'],
            'sha256'  => vendoredLibraryFingerprint($base . '/demo'),
        ],
    ],
];

assertEquals([], vendoredLibrariesCheck($base, $demo), 'untouched demo library passes');

// ✏️ Edited file.
file_put_contents($base . '/demo/code.php', "<?php // changed\n");
assertEquals(1, count(vendoredLibrariesCheck($base, $demo)), 'an edited file is caught');

// ♻️ Restore, then add an extra file.
file_put_contents($base . '/demo/code.php', "<?php // demo\n");
file_put_contents($base . '/demo/extra.php', "<?php\n");
assertEquals(1, count(vendoredLibrariesCheck($base, $demo)), 'an extra file is caught');
unlink($base . '/demo/extra.php');

// 🏷️ Wrong version marker (plus the fingerprint then differs too).
$wrong = $demo;
$wrong['libraries']['acme/demo']['version'] = '9.9.9';
$problems = vendoredLibrariesCheck($base, $wrong);
assertTrue(count($problems) >= 1 && str_contains($problems[0], '9.9.9'), 'a wrong version is caught');

// 📁 Missing folder, and an empty manifest.
$missing = $demo;
$missing['libraries']['acme/demo']['path'] = 'nope';
assertEquals(1, count(vendoredLibrariesCheck($base, $missing)), 'a missing folder is caught');
assertEquals(1, count(vendoredLibrariesCheck($base, ['libraries' => []])), 'an empty manifest is caught');

// 🧹 Clean up the throwaway folder.
unlink($base . '/demo/VERSION');
unlink($base . '/demo/code.php');
rmdir($base . '/demo');
rmdir($base);
