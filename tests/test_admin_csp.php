<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The admin area allows no inline scripts (#154)
 * ==============================================================================
 *
 * Path: tests/test_admin_csp.php
 *
 * The admin security policy (public_html/admin/.htaccess) may only run scripts
 * from this site and the two exact jsDelivr folders of the pinned jQuery and
 * Bootstrap (fourth-round review, Low 2: jsDelivr serves ANY public package or
 * GitHub file, so the whole host would let an injected <script src> run
 * anyone's code). Every outside script the shared header and footer load
 * must sit in one of those folders and carry an integrity hash. So nothing an admin address can show -
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
$acAllowedScripts = "'self' https://cdn.jsdelivr.net/npm/jquery@3.7.1/ https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/";
assertEquals($acAllowedScripts, $acScripts[1] ?? '', '#154 and fourth-round review Low 2: admin scripts come only from this site and the pinned jQuery and Bootstrap folders on jsDelivr (no inline, no eval, not any jsDelivr file)');
assertContains(
    "header(\"Content-Security-Policy: frame-ancestors 'none'; script-src " . $acAllowedScripts . "\");",
    (string) file_get_contents($acSite . '_functions' . DIRECTORY_SEPARATOR . 'fn_admin.php'),
    '#154 and Low 2: the PHP admin headers send the same script rule, in case a host ignores .htaccess'
);

// 🔐 Low 2: every outside script the shared parts load is inside an allowed
//    folder (or the admin pages would break) and has an integrity hash (so a
//    changed file on the CDN is refused; the local copy is used instead).
$acSharedParts = (string) file_get_contents($acSite . '_includes' . DIRECTORY_SEPARATOR . 'header.php')
    . (string) file_get_contents($acSite . '_includes' . DIRECTORY_SEPARATOR . 'footer.php');
preg_match_all('/<script\b[^>]*\bsrc="(https:[^"]+)"[^>]*>/i', $acSharedParts, $acOutsideScripts, PREG_SET_ORDER);
assertTrue(count($acOutsideScripts) >= 2, 'Low 2: the shared parts load jQuery and Bootstrap from the CDN (' . count($acOutsideScripts) . ' outside scripts)');
foreach ($acOutsideScripts as $acOutside) {
    $acInsideAllowed = false;
    foreach (array_slice(explode(' ', $acAllowedScripts), 1) as $acAllowedFolder) {
        if (str_starts_with($acOutside[1], $acAllowedFolder)) {
            $acInsideAllowed = true;
        }
    }
    assertTrue($acInsideAllowed, 'Low 2: ' . $acOutside[1] . ' is inside a folder the admin policy allows');
    assertTrue(preg_match('/\bintegrity="sha(256|384|512)-[A-Za-z0-9+\/=]{40,}"/', $acOutside[0]) === 1 && str_contains($acOutside[0], 'crossorigin="anonymous"'), 'Low 2 (F-004): ' . $acOutside[1] . ' carries an integrity hash');
}

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
    // 🖱️ Fourth-round review, Info 2: ANY on...= attribute (ondblclick,
    //    onpointerdown, onanimationstart, oncontextmenu ...), not a list.
    if (preg_match('/<[a-z][^<>]*\s(on[a-z]+)\s*=\s*["\']?/i', $code, $m) === 1) {
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
foreach (['ondblclick', 'onpointerdown', 'onanimationstart', 'oncontextmenu'] as $acRareEvent) {
    assertEquals([$acRareEvent . '= attribute'], $acFaults('<button type="button" ' . $acRareEvent . '="x()">Go</button>'), 'Info 2: the less common ' . $acRareEvent . '= attribute is found too');
}
assertEquals([], $acFaults('<button type="button" class="btn" data-online="1">Go</button>'), 'Info 2: an ordinary attribute that merely contains "on" is not mistaken for one');
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
