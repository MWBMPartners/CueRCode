<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Sign-in page "next" address check (#153)
 * ==============================================================================
 *
 * Path: tests/test_login_next_url.php
 *
 * resolveSafeNextUrl() lives inside public_html/login/index.php (a page that
 * cannot be included without running it), so this test cuts the function's
 * source text out of the file and defines it here. Nothing else from the page
 * is run.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @since       2026-11-24 (#153)
 */

$lnPagePath   = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'login' . DIRECTORY_SEPARATOR . 'index.php';
$lnPageSource = (string) file_get_contents($lnPagePath);

$lnFound = preg_match('/^function resolveSafeNextUrl\(.*?^}\s*$/ms', $lnPageSource, $lnMatch);
assertEquals(1, $lnFound, 'resolveSafeNextUrl() can be found in the sign-in page');

if ($lnFound === 1 && !function_exists('resolveSafeNextUrl')) {
    // 🧪 Test-only: define the function from the page's own source text.
    eval($lnMatch[0]);
}

$lnDefault = '/account/';

// ✅ Normal paths and query strings still work.
foreach (['/account/', '/dashboard/', '/qr/create/?type=url', '/account/?tab=keys&x=1', '/a%20b/'] as $lnGood) {
    assertEquals($lnGood, resolveSafeNextUrl($lnGood, $lnDefault), 'good value kept: ' . $lnGood);
}

// ❌ Everything that could end up pointing at another site.
foreach ([
    "/\t/evil.example"      => 'tab (browser strips it, leaving //evil.example)',
    "/\r/evil.example"      => 'carriage return',
    "/\n/evil.example"      => 'line feed',
    "/\0/evil.example"      => 'NUL byte',
    "/account/\x7F"         => 'DEL character',
    "/account/\r\nSet-Cookie: x=1" => 'header injection',
    '/\\evil.example'       => 'backslash at the start',
    '/account/\\evil'       => 'backslash later on',
    '//evil.example'        => 'protocol-relative',
    'https://evil.example/' => 'absolute address',
    'javascript:alert(1)'   => 'script scheme',
    'account/'              => 'no leading slash',
    ''                      => 'empty',
] as $lnBad => $lnWhy) {
    assertEquals($lnDefault, resolveSafeNextUrl($lnBad, $lnDefault), 'refused: ' . $lnWhy);
}

assertEquals($lnDefault, resolveSafeNextUrl(null, $lnDefault), 'null falls back to the default');
assertEquals($lnDefault, resolveSafeNextUrl(['/x/'], $lnDefault), 'an array (?next[]=) falls back to the default');
