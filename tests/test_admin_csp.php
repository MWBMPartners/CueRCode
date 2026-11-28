<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The admin area allows no inline scripts (#154)
 * ==============================================================================
 *
 * Path: tests/test_admin_csp.php
 *
 * The admin security policy (public_html/admin/.htaccess) may only run scripts
 * from this site and cdn.jsdelivr.net. So nothing an admin address can show -
 * the admin pages, the shared header, footer and menu, the "not found" page a
 * non-admin gets, and the HTML built by the admin functions - may contain an
 * inline <script>, an on...= attribute or a javascript: address; the browser
 * would silently refuse to run it. Reads the source only (no database).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (#154)
 */

$acSite = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net' . DIRECTORY_SEPARATOR;
$acWeb  = $acSite . 'public_html' . DIRECTORY_SEPARATOR;

// 📜 The policy itself: both the .htaccess line and the PHP backstop.
$acHtaccess = (string) file_get_contents($acWeb . 'admin' . DIRECTORY_SEPARATOR . '.htaccess');
preg_match('/Header always set Content-Security-Policy "([^"]+)"/', $acHtaccess, $acPolicy);
preg_match("/script-src ([^;]+);/", $acPolicy[1] ?? '', $acScripts);
assertEquals("'self' https://cdn.jsdelivr.net", $acScripts[1] ?? '', '#154: admin scripts come only from this site and jsDelivr (no inline, no eval)');
assertContains(
    "header(\"Content-Security-Policy: frame-ancestors 'none'; script-src 'self' https://cdn.jsdelivr.net\");",
    (string) file_get_contents($acSite . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php'),
    '#154: the PHP admin headers refuse inline scripts too, in case a host ignores .htaccess'
);

// 🔎 What a browser would treat as inline script. A <script> tag must name a
//    file (src=); data blocks (type="application/json") are not run.
$acFaults = static function (string $source): array {
    // 💬 PHP comments are not sent to the browser.
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $code .= $token[1];
            }
        } else {
            $code .= $token;
        }
    }
    $found = [];
    if (preg_match_all('/<script\b(?![^>]*\bsrc\s*=)(?![^>]*type\s*=\s*["\']application\/(?:ld\+)?json)[^>]*>/i', $code, $m) > 0) {
        $found[] = count($m[0]) . ' inline <script>';
    }
    if (preg_match('/<[a-z][^<>]*\s(on(?:click|change|submit|load|error|input|key\w+|mouse\w+|focus|blur))\s*=\s*["\']/i', $code, $m) === 1) {
        $found[] = $m[1] . '= attribute';
    }
    if (preg_match('/(?:href|src|action)\s*=\s*["\']\s*javascript:/i', $code) === 1) {
        $found[] = 'javascript: address';
    }
    return $found;
};

// 🧪 The check itself, on samples.
assertEquals(['1 inline <script>'], $acFaults('<p>x</p><script>alert(1)</script>'), 'sanity: an inline script is found');
assertEquals(['onerror= attribute'], $acFaults('<img src="a" onerror="x()">'), 'sanity: an event attribute is found');
assertEquals([], $acFaults("<?php // <script> in a comment\n?><script src=\"/a.js\"></script>"), 'sanity: a file script and a comment are allowed');

$acFiles = [
    $acSite . '_includes' . DIRECTORY_SEPARATOR . 'header.php',
    $acSite . '_includes' . DIRECTORY_SEPARATOR . 'footer.php',
    $acSite . '_includes' . DIRECTORY_SEPARATOR . 'admin_nav.php',
    $acSite . '_includes' . DIRECTORY_SEPARATOR . 'pricing_grid.php',
    $acWeb . 'error' . DIRECTORY_SEPARATOR . '404.php',
];
$acAdminPages = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($acWeb . 'admin', FilesystemIterator::SKIP_DOTS));
foreach ($acAdminPages as $acPage) {
    if (str_ends_with((string) $acPage, '.php')) {
        $acFiles[] = (string) $acPage;
    }
}
foreach ((array) glob($acSite . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin*.php') as $acFunctionFile) {
    $acFiles[] = (string) $acFunctionFile;
}
assertTrue(count($acFiles) >= 16, 'sanity: the admin pages and shared parts were found (' . count($acFiles) . ')');

foreach ($acFiles as $acFile) {
    assertEquals([], $acFaults((string) file_get_contents($acFile)), '#154: no inline script in ' . str_replace($acSite, '', $acFile));
}

// 📦 The moved scripts exist, are loaded, and are kept for offline use.
$acShared = (string) file_get_contents($acSite . '_includes' . DIRECTORY_SEPARATOR . 'header.php')
    . (string) file_get_contents($acSite . '_includes' . DIRECTORY_SEPARATOR . 'footer.php');
$acWorker = (string) file_get_contents($acWeb . 'sw.js');
foreach (['cuercode-head.js', 'cuercode-fallback-jquery.js', 'cuercode-fallback-bootstrap.js', 'cuercode-page.js', 'cuercode-error.js'] as $acScript) {
    assertTrue(is_file($acWeb . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . $acScript), '#154: assets/js/' . $acScript . ' exists');
    $acWhere = $acShared;
    if ($acScript === 'cuercode-error.js') {
        $acWhere = (string) file_get_contents($acWeb . 'error' . DIRECTORY_SEPARATOR . '404.php');
    }
    assertContains('/assets/js/' . $acScript, $acWhere, '#154: ' . $acScript . ' is loaded by the page that needs it');
    assertContains("'/assets/js/" . $acScript . "'", $acWorker, '#154: ' . $acScript . ' is precached by the service worker');
}
